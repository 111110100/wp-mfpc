<?php
/**
 * @package index-cached.php
 * @author erwin lomibao/Gemini Code Assist
 * @version 1.7.1
 * @license GPLv2
 * @website https://github.com/111110100/memblaze-full-page-cache
 */

// Prevent direct access if not via expected front-controller entry
if ( ! defined( 'ABSPATH' ) ) {
    if ( ! defined( 'MFPC_CACHE_LOADED' ) ) {
        define( 'MFPC_CACHE_LOADED', true );
    }
}

$mfpc_start = microtime(true);

// --- Configuration Loading ---
$mfpc_config_file = __DIR__ . '/wp-content/memcached-fp-config.php'; // Adjust path if needed

$mfpc_config = [];
if ( file_exists( $mfpc_config_file ) ) {
    $mfpc_config = @include $mfpc_config_file;
    if ($mfpc_config === false) {
        $mfpc_config = []; 
    }
}

// --- Enterprise: Emergency Bypass ---
if (file_exists(__DIR__ . '/.mfpc-bypass')) {
    $mfpc_config['debug'] = true; 
    $mfpc_bypass_reason = "Emergency bypass file found";
    $mfpc_default_cache_time = 0;
}

// Set defaults from config or hardcoded fallbacks
$mfpc_debug = ( defined( 'WP_DEBUG' ) && WP_DEBUG ) || ( isset( $mfpc_config['debug'] ) ? (bool) $mfpc_config['debug'] : false );

if ( $mfpc_debug ) {
    // phpcs:ignore Squiz.PHP.DiscouragedFunctions.Discouraged
    ini_set('display_errors', 1);
    // phpcs:ignore WordPress.PHP.DevelopmentFunctions.prevent_path_disclosure_error_reporting
    error_reporting(E_ALL);
    echo "<!-- MFPC DEBUG: Script Start. Config Loaded. Servers: " . (isset($mfpc_config['servers']) ? (int) count($mfpc_config['servers']) : '0') . " -->\n";
}

$mfpc_servers = isset( $mfpc_config['servers'] ) && is_array( $mfpc_config['servers'] ) && !empty($mfpc_config['servers'])
           ? $mfpc_config['servers']
           : [['host' => '127.0.0.1', 'port' => '11211']]; // Default server
$mfpc_rules = isset( $mfpc_config['rules'] ) && is_array( $mfpc_config['rules'] ) ? $mfpc_config['rules'] : [];
$mfpc_content_type_rules = isset( $mfpc_config['content_type_rules'] ) && is_array( $mfpc_config['content_type_rules'] ) ? $mfpc_config['content_type_rules'] : [];
$mfpc_default_cache_time = isset( $mfpc_config['default_cache_time'] ) ? (int) $mfpc_config['default_cache_time'] : 0; 
$mfpc_lazy_load_enabled = isset( $mfpc_config['lazy_load'] ) ? (bool) $mfpc_config['lazy_load'] : false;
$mfpc_minify_assets_enabled = isset( $mfpc_config['minify_assets'] ) ? (bool) $mfpc_config['minify_assets'] : false;

// --- Probabilistic Early Expiration ---
$mfpc_probabilistic_beta = 10.0; 

// --- Bypass Cookie Configuration ---
$mfpc_bypass_cookie_prefixes = isset( $mfpc_config['bypass_cookies'] ) && is_array( $mfpc_config['bypass_cookies'] ) ? $mfpc_config['bypass_cookies'] : [];

/**
 * Checks if any cookie in the current request starts with one of the defined prefixes.
 */
function mfpc_should_bypass_cache(array $bypass_prefixes, bool $debug_mode = false): bool {
    if (empty($bypass_prefixes) || empty($_COOKIE)) {
        return false;
    }
    foreach ($_COOKIE as $cookie_name => $cookie_value) {
        foreach ($bypass_prefixes as $prefix) {
            if (strpos((string)$cookie_name, (string)$prefix) === 0) {
                if ($debug_mode) {
                    mfpc_log("Memcached Bypass: Cookie '" . $cookie_name . "' matched prefix '" . $prefix . "'. Bypassing cache.");
                }
                return true;
            }
        }
    }
    return false;
}

/**
 * Minifies HTML, inline CSS, and inline JS.
 */
function mfpc_minify_output($buffer) {
    if ( empty( $buffer ) ) {
        return $buffer;
    }

    // Minify inline CSS
    $buffer = preg_replace_callback('/<style\b[^>]*>(.*?)<\/style>/is', function($matches) {
        $css = $matches[1];
        $css = preg_replace('/\/\*.*?\*\//s', '', $css); 
        $css = preg_replace('/\s*([\{\}:;,])\s*/', '$1', $css); 
        $css = str_replace(';}', '}', $css); 
        return '<style>' . trim($css) . '</style>';
    }, $buffer);

    // Minify inline JS (Basic comment removal)
    $buffer = preg_replace_callback('/<script\b[^>]*>(.*?)<\/script>/is', function($matches) {
        $js = $matches[1];
        $js = preg_replace('/\/\*.*?\*\//s', '', $js); 
        return '<script>' . trim($js) . '</script>';
    }, $buffer);

    $buffer = preg_replace('/<!--(?!\s*(?:\[if [^\]]+]|<!|>))(?:(?!-->).)*-->/s', '', $buffer);
    $buffer = preg_replace('/\s+/S', ' ', $buffer);
    $buffer = str_replace( "> <", "><", $buffer );

    return trim($buffer);
}

// --- Cache Time & Content Type Determination ---
$mfpc_contentType = "Content-Type: text/html";
$mfpc_cacheTime = $mfpc_default_cache_time;

// --- CLI Testing Support ---
if ( php_sapi_name() === 'cli' ) {
    if ( ! isset( $_SERVER['HTTP_HOST'] ) ) {
        $_SERVER['HTTP_HOST'] = 'localhost';
    }
    if ( ! isset( $_SERVER['REQUEST_URI'] ) ) {
        $_SERVER['REQUEST_URI'] = '/';
    }
}

// --- Sanitization ---
$mfpc_request_uri = filter_var( wp_unslash( $_SERVER['REQUEST_URI'] ), FILTER_SANITIZE_URL );
$mfpc_http_host = filter_var( wp_unslash( $_SERVER['HTTP_HOST'] ), FILTER_SANITIZE_FULL_SPECIAL_CHARS );

/**
 * Placeholder for wp_unslash if WP is not loaded.
 */
if ( ! function_exists( 'wp_unslash' ) ) {
    // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound
    function wp_unslash( $data ) {
        return stripslashes_deep( $data );
    }
}
if ( ! function_exists( 'stripslashes_deep' ) ) {
    // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound
    function stripslashes_deep( $value ) {
        if ( is_array( $value ) ) {
            $value = array_map( 'stripslashes_deep', $value );
        } elseif ( is_object( $value ) ) {
            $vars = get_object_vars( $value );
            foreach ( $vars as $key => $data ) {
                $value->{$key} = stripslashes_deep( $data );
            }
        } elseif ( is_string( $value ) ) {
            $value = stripslashes( $value );
        }
        return $value;
    }
}
$mfpc_matched_rule = false;

// Check rules from config
if (!empty($mfpc_rules)) {
    foreach ( $mfpc_rules as $mfpc_rule ) {
        if ( isset($mfpc_rule['path'], $mfpc_rule['time']) && is_string($mfpc_rule['path']) && $mfpc_rule['path'] !== '' ) {
            if ( strstr( $mfpc_request_uri, $mfpc_rule['path'] ) !== false ) {
                $mfpc_cacheTime = (int) $mfpc_rule['time'];
                $mfpc_matched_rule = true;
                break; 
            }
        }
    }
}

// Apply configured Content-Type rules
if (!empty($mfpc_content_type_rules)) {
    foreach ($mfpc_content_type_rules as $mfpc_rule) {
        if ( isset($mfpc_rule['path'], $mfpc_rule['content_type']) && is_string($mfpc_rule['path']) && $mfpc_rule['path'] !== '' ) {
             if ( strstr( $mfpc_request_uri, $mfpc_rule['path'] ) !== false ) {
                 $mfpc_contentType = "Content-Type: " . $mfpc_rule['content_type'];
                 break; 
             }
        }
    }
}

// Set Content-Type header
header( $mfpc_contentType );

if ( $mfpc_debug ) {
    echo "<!-- MFPC DEBUG: Headers sent. Cache Time: " . (int) $mfpc_cacheTime . " -->\n";
}

// --- Memcached Connection ---
$mfpc_memcached = null; 
$mfpc_connection_success = false;

if ($mfpc_cacheTime > 0 || $mfpc_debug) {
    if (class_exists('Memcached')) {
        $mfpc_memcached = new \Memcached();
        if (!empty($mfpc_servers)) {
            foreach ( $mfpc_servers as $mfpc_server ) {
                if ( isset($mfpc_server['host'], $mfpc_server['port']) ) {
                    $mfpc_h = $mfpc_server['host'];
                    $mfpc_p = $mfpc_server['port']; 

                    if (strpos($mfpc_h, '/') === 0) {
                        if (file_exists($mfpc_h)) {
                            if ($mfpc_memcached->addServer($mfpc_h, 0)) { 
                                $mfpc_connection_success = true;
                            }
                        }
                    } else {
                        $mfpc_int_port = intval($mfpc_p);
                        if ($mfpc_int_port > 0) {
                            if ($mfpc_memcached->addServer($mfpc_h, $mfpc_int_port)) {
                                $mfpc_connection_success = true;
                            }
                        }
                    }
                }
            }
        }

        if ($mfpc_connection_success) {
            $mfpc_memcached->setOption( \Memcached::OPT_COMPRESSION, false );
            $mfpc_memcached->setOption( \Memcached::OPT_BUFFER_WRITES, true );
            $mfpc_memcached->setOption( \Memcached::OPT_BINARY_PROTOCOL, true );
        } else {
            $mfpc_memcached = null; 
        }
    }
}

// --- Cache Retrieval ---
$mfpc_cacheKey = "fullpage:" . $mfpc_http_host . $mfpc_request_uri;
$mfpc_html = false;
$mfpc_debugMessage = '';
$mfpc_cache_bypassed_by_cookie = false;
$mfpc_cache_age = 0;

if (!empty($mfpc_bypass_cookie_prefixes)) {
    $mfpc_cache_bypassed_by_cookie = mfpc_should_bypass_cache($mfpc_bypass_cookie_prefixes, $mfpc_debug);
}

if ($mfpc_cache_bypassed_by_cookie) {
    $mfpc_html = false; 
    $mfpc_debugMessage = 'Page generated (cache bypassed by cookie) in %f seconds.';
} elseif ( isset($mfpc_bypass_reason) ) {
    $mfpc_html = false;
    $mfpc_debugMessage = 'Page generated (' . $mfpc_bypass_reason . ') in %f seconds.';
} elseif ( $mfpc_memcached && $mfpc_cacheTime > 0 ) {
    if ( $mfpc_debug ) {
        // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
        echo "<!-- MFPC DEBUG: Cache Key: " . htmlspecialchars($mfpc_cacheKey) . " -->\n";
    }
    $mfpc_cached_item_raw = $mfpc_memcached->get( $mfpc_cacheKey );
    if ($mfpc_cached_item_raw !== false) {
        $mfpc_generated_at = 0;

        if (strpos($mfpc_cached_item_raw, 'a:') === 0) {
            $mfpc_cached_item = @unserialize($mfpc_cached_item_raw);
            if (is_array($mfpc_cached_item) && isset($mfpc_cached_item['html'], $mfpc_cached_item['generated_at'])) {
                $mfpc_html = $mfpc_cached_item['html'];
                $mfpc_generated_at = $mfpc_cached_item['generated_at'];
            }
        } else {
            $mfpc_html = $mfpc_cached_item_raw;
            if (preg_match('/<!-- MFPC_META: (\d+) -->/', $mfpc_html, $mfpc_matches)) {
                $mfpc_generated_at = (int)$mfpc_matches[1];
            }
        }

        if ($mfpc_html !== false) {
            $mfpc_cache_age = ($mfpc_generated_at > 0) ? (time() - $mfpc_generated_at) : 0;

            if ($mfpc_probabilistic_beta > 0 && $mfpc_generated_at > 0) {
                // phpcs:ignore WordPress.WP.AlternativeFunctions.rand_mt_rand
                $mfpc_random_float = function_exists('wp_rand') ? wp_rand() / 2147483647 : mt_rand() / mt_getrandmax();
                if ($mfpc_random_float > 0 && (time() - $mfpc_generated_at) <= ($mfpc_cacheTime - ($mfpc_probabilistic_beta * -log($mfpc_random_float)))) {
                    $mfpc_debugMessage = 'Page retrieved from cache in %f seconds.';
                } else {
                    $mfpc_html = false; 
                    $mfpc_debugMessage = 'Page generated (stale cache, probabilistic refresh) in %f seconds.';
                }
            } else {
                $mfpc_debugMessage = 'Page retrieved from cache in %f seconds.';
            }
        } else {
            $mfpc_html = false;
            $mfpc_debugMessage = 'Page generated (invalid cache format) in %f seconds.';
        }
    } else {
        $mfpc_html = false; 
        $mfpc_debugMessage = 'Page generated (cache miss) in %f seconds.';
    }
} else {
     $mfpc_debugMessage = 'Page generated (caching disabled or connection failed) in %f seconds.';
}


// --- Page Generation ---
if ( $mfpc_html === false ) { 
    ob_start();

    $mfpc_wp_blog_header = __DIR__ . '/wp-blog-header.php';

    if (file_exists($mfpc_wp_blog_header)) {
        // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedConstantFound
        define( 'WP_USE_THEMES', true );
        $_SERVER['SCRIPT_FILENAME'] = __DIR__ . '/index.php';
        require $mfpc_wp_blog_header; 

        if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
            $mfpc_debug = true;
        }
    } else {
        echo "Error: Site temporarily unavailable.";
        if (!headers_sent()) {
             header("HTTP/1.1 503 Service Unavailable");
        }
    }

    $mfpc_html = ob_get_contents();
    ob_end_clean(); 

    // --- Lazy Load Transformation ---
    if ( $mfpc_lazy_load_enabled && $mfpc_html !== false ) {
        $mfpc_html = preg_replace_callback(
            '/<img\s+([^>]+)>/i',
            function ($mfpc_m) {
                if (stripos($mfpc_m[1], 'loading=') === false) {
                    return '<img loading="lazy" ' . $mfpc_m[1] . '>';
                }
                return $mfpc_m[0];
            },
            $mfpc_html
        );
        $mfpc_html = preg_replace_callback(
            '/<iframe\s+([^>]+)>/i',
            function ($mfpc_m) {
                if (stripos($mfpc_m[1], 'loading=') === false) {
                    return '<iframe loading="lazy" ' . $mfpc_m[1] . '>';
                }
                return $mfpc_m[0];
            },
            $mfpc_html
        );
    }

    // --- Asset Minification ---
    if ( $mfpc_minify_assets_enabled && $mfpc_html !== false && strpos( $mfpc_contentType, 'text/html' ) !== false ) {
        $mfpc_html = mfpc_minify_output( $mfpc_html );
    }

    // --- Cache Storage ---
    if ( !$mfpc_cache_bypassed_by_cookie && $mfpc_memcached && $mfpc_html !== false && $mfpc_html !== '' && $mfpc_cacheTime > 0 ) {
        $mfpc_gen_at = time();
        $mfpc_payload = $mfpc_html;
        if ( strpos( $mfpc_contentType, 'text/html' ) !== false ) {
            $mfpc_payload .= "\n<!-- MFPC_META: " . $mfpc_gen_at . " -->";
        }
        $mfpc_memcached->set( $mfpc_cacheKey, $mfpc_payload, $mfpc_cacheTime );
    }
}

// --- Cleanup & Output ---
if ($mfpc_memcached) {
    $mfpc_memcached->quit();
}

$mfpc_finish = microtime( true );
$mfpc_duration = $mfpc_finish - $mfpc_start;

// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
echo (string) $mfpc_html;

if ( $mfpc_debug && strpos( $mfpc_contentType, 'text/html' ) !== false ) {
    $mfpc_dbg_out = "\n<!-- " . sprintf( (string) $mfpc_debugMessage, (float) $mfpc_duration );
    if ($mfpc_cache_bypassed_by_cookie) {
        $mfpc_dbg_out .= ' | Cache Bypassed by Cookie';
    } else if ($mfpc_cacheTime > 0) { 
        $mfpc_dbg_out .= ' | Cache TTL: ' . (int) $mfpc_cacheTime . ' seconds';
    }
    if ( $mfpc_html !== false && $mfpc_cache_age > 0 ) {
        $mfpc_dbg_out .= ' | Age: ' . (int) $mfpc_cache_age . 's';
    }
    $mfpc_dbg_out .= ' -->';
    // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
    echo (string) $mfpc_dbg_out;
}

exit;
?>
