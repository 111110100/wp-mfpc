<?php
/**
 * @package index-cached.php
 * @author joseph wynn/erwin lomibao/Gemini Code Assist
 * @version 3.0
 * @license commercial
 * @website https://github.com/111110100/wordpress-memcached-nginx-combo
 */

$start = microtime(true);

// --- Configuration Loading ---
$config_file = __DIR__ . '/wp-content/memcached-fp-config.php'; // Adjust path if needed

define( 'MFPC_CACHE_LOADED', true );

$config = [];
if ( file_exists( $config_file ) ) {
    // Use error suppression potentially, or add more robust error checking
    $config = @include $config_file;
    if ($config === false) {
        // Handle include error, maybe log it
        $config = []; // Reset config on error
        // echo "<!-- Error loading config file: {$config_file} -->"; // Optional debug
    }
}

// --- Enterprise: Emergency Bypass ---
// Create a file named '.mfpc-bypass' in the same directory to instantly disable caching.
if (file_exists(__DIR__ . '/.mfpc-bypass')) {
    $config['debug'] = true; // Force debug to show bypass reason
    $bypass_reason = "Emergency bypass file found";
    // We will handle the actual bypass logic by checking this variable or forcing cacheTime to 0
    $default_cache_time = 0;
}

// Set defaults from config or hardcoded fallbacks
$debug = ( defined( 'WP_DEBUG' ) && WP_DEBUG ) || ( isset( $config['debug'] ) ? (bool) $config['debug'] : false );

if ( $debug ) {
    ini_set('display_errors', 1);
    error_reporting(E_ALL);
    echo "<!-- MFPC DEBUG: Script Start. Config Loaded. Servers: " . (isset($config['servers']) ? count($config['servers']) : '0') . " -->\n";
}

$servers = isset( $config['servers'] ) && is_array( $config['servers'] ) && !empty($config['servers'])
           ? $config['servers']
           : [['host' => '127.0.0.1', 'port' => '11211']]; // Default server
$rules = isset( $config['rules'] ) && is_array( $config['rules'] ) ? $config['rules'] : [];
$default_cache_time = isset( $config['default_cache_time'] ) ? (int) $config['default_cache_time'] : 0; // Default 0 (no cache) if not set
$lazy_load_enabled = isset( $config['lazy_load'] ) ? (bool) $config['lazy_load'] : false;

// --- Probabilistic Early Expiration ---
// To prevent cache stampedes, one process can be chosen to regenerate a cache item
// shortly before it expires, while others continue to serve the stale content.
$probabilistic_beta = 10.0; // Higher value means earlier refresh probability. Set to 0 to disable.

// --- Bypass Cookie Configuration ---
$bypass_cookie_prefixes = isset( $config['bypass_cookies'] ) && is_array( $config['bypass_cookies'] ) ? $config['bypass_cookies'] : [];

// --- Function to check for bypass cookies ---
/**
 * Checks if any cookie in the current request starts with one of the defined prefixes.
 *
 * @param array $bypass_prefixes Array of cookie name prefixes.
 * @param bool $debug_mode Enable debug logging for this function.
 * @return bool True if a bypass cookie is found, false otherwise.
 */
function mfpc_should_bypass_cache(array $bypass_prefixes, bool $debug_mode = false): bool {
    if (empty($bypass_prefixes) || empty($_COOKIE)) {
        return false;
    }
    foreach ($_COOKIE as $cookie_name => $cookie_value) {
        foreach ($bypass_prefixes as $prefix) {
            if (strpos($cookie_name, $prefix) === 0) {
                if ($debug_mode) {
                    error_log("Memcached Bypass: Cookie '{$cookie_name}' matched prefix '{$prefix}'. Bypassing cache.");
                }
                return true;
            }
        }
    }
    return false;
}

// --- Cache Time & Content Type Determination ---
$contentType = "Content-Type: text/html";
$cacheTime = $default_cache_time;

// --- CLI Testing Support ---
if ( php_sapi_name() === 'cli' ) {
    if ( ! isset( $_SERVER['HTTP_HOST'] ) ) {
        $_SERVER['HTTP_HOST'] = 'localhost';
    }
    if ( ! isset( $_SERVER['REQUEST_URI'] ) ) {
        $_SERVER['REQUEST_URI'] = '/';
    }
}

$request_uri = $_SERVER['REQUEST_URI'];
$matched_rule = false;

// Check rules from config
if (!empty($rules)) {
    foreach ( $rules as $rule ) {
        // Ensure rule structure is valid before using
        if ( isset($rule['path'], $rule['time']) && is_string($rule['path']) && $rule['path'] !== '' ) {
            // Use strpos for potentially faster matching at the start or anywhere
            // Using strstr as per original logic to find substring anywhere
            if ( strstr( $request_uri, $rule['path'] ) !== false ) {
                $cacheTime = (int) $rule['time'];
                $matched_rule = true;
                // If rules should be processed in order and first match wins:
                // break;
            }
        }
    }
}

// Special handling for feeds (overrides rules if necessary, or apply if no rule matched)
// You might want to add specific rules for feeds in the admin instead.
if ( strstr( $request_uri, '/feed/' ) !== false ) {
    $contentType = "Content-Type: application/rss+xml";
    // Optionally set a specific cache time for feeds if not covered by rules
    // if (!$matched_rule) $cacheTime = 1800; // e.g., 30 minutes for feeds if no rule matched
} elseif ( strstr( $request_uri, '/atom/' ) !== false ) {
    $contentType = "Content-Type: application/atom+xml";
    // if (!$matched_rule) $cacheTime = 1800;
}

// Set Content-Type header
header( $contentType );

if ( $debug ) {
    echo "<!-- MFPC DEBUG: Headers sent. Cache Time: $cacheTime -->\n";
}

// --- Memcached Connection ---
$memcached = null; // Initialize as null
$connection_success = false;

// Only attempt connection if caching is potentially enabled (cacheTime > 0 or debug is on)
if ($cacheTime > 0 || $debug) {
    if (class_exists('Memcached')) {
        $memcached = new \Memcached();
        if (!empty($servers)) {
            foreach ( $servers as $server ) {
                if ( isset($server['host'], $server['port']) ) {
                    $host = $server['host'];
                    $port = $server['port']; // Keep as string from config initially

                    // Check if host looks like a socket path
                    if (strpos($host, '/') === 0) {
                        // Attempt connection only if socket file exists
                        if (file_exists($host)) {
                            if ($memcached->addServer($host, 0)) { // Port must be 0 for sockets
                                $connection_success = true;
                            } else {
                                if ($debug) error_log("Memcached: Failed to add socket server: " . $host);
                            }
                        } else {
                             if ($debug) error_log("Memcached: Socket file not found: " . $host);
                        }
                    } else {
                        // Treat as hostname/IP, ensure port is integer
                        $int_port = intval($port);
                        if ($int_port > 0) {
                            if ($memcached->addServer($host, $int_port)) {
                                $connection_success = true;
                            } else {
                                 if ($debug) error_log("Memcached: Failed to add server: " . $host . ":" . $int_port);
                            }
                        } else {
                             if ($debug) error_log("Memcached: Invalid port for server: " . $host . ":" . $port);
                        }
                    }
                }
            }
        }

        // Configure options if connection was successful
        if ($connection_success) {
            $memcached->setOption( \Memcached::OPT_COMPRESSION, false );
            $memcached->setOption( \Memcached::OPT_BUFFER_WRITES, true );
            // Binary protocol might fail with sockets, consider conditional setting or removing if using sockets primarily
            $memcached->setOption( \Memcached::OPT_BINARY_PROTOCOL, true );
        } else {
            if ($debug) error_log("Memcached: No valid servers configured or connection failed.");
            $memcached = null; // Ensure memcached object is null if connection failed
        }
    } else {
         if ($debug) error_log("Memcached: Memcached class not found. Is the extension installed and enabled?");
    }
} else {
     if ($debug) error_log("Memcached: Caching disabled for this request (cacheTime=0).");
}

// --- Cache Retrieval ---
$cacheKey = "fullpage:{$_SERVER['HTTP_HOST']}{$request_uri}";
$html = false;
$debugMessage = '';
$cache_bypassed_by_cookie = false;
$cache_age = 0;

// Check for bypass cookies BEFORE attempting to get from cache
if (!empty($bypass_cookie_prefixes)) {
    $cache_bypassed_by_cookie = mfpc_should_bypass_cache($bypass_cookie_prefixes, $debug);
}

if ($cache_bypassed_by_cookie) {
    $html = false; // Ensure we generate fresh content
    $debugMessage = 'Page generated (cache bypassed by cookie) in %f seconds.';
} elseif ( isset($bypass_reason) ) {
    $html = false;
    $debugMessage = 'Page generated (' . $bypass_reason . ') in %f seconds.';
} elseif ( $memcached && $cacheTime > 0 ) {
    $cached_item_raw = $memcached->get( $cacheKey );
    if ( $debug ) {
        echo "<!-- MFPC DEBUG: Cache Key: $cacheKey -->\n";
    }
    if ($cached_item_raw !== false) {
        $generated_at = 0;
        
        // Detect legacy serialized format (starts with 'a:')
        if (strpos($cached_item_raw, 'a:') === 0) {
            $cached_item = @unserialize($cached_item_raw);
            if (is_array($cached_item) && isset($cached_item['html'], $cached_item['generated_at'])) {
                $html = $cached_item['html'];
                $generated_at = $cached_item['generated_at'];
            }
        } else {
            // Raw HTML format (for Nginx)
            $html = $cached_item_raw;
            if (preg_match('/<!-- MFPC_META: (\d+) -->/', $html, $matches)) {
                $generated_at = (int)$matches[1];
            }
        }

        if ($html !== false) {
            $cache_age = ($generated_at > 0) ? (time() - $generated_at) : 0;

            // Probabilistic early expiration check. A beta of 0 disables this feature.
            if ($probabilistic_beta > 0) {
                $random_float = mt_rand() / mt_getrandmax();
                // The formula is: (time() - generated_at) > TTL - beta * -log(rand)
                // We check the inverse: if it's NOT time to regenerate, it's a hit.
                // This also avoids a log(0) warning, as if $random_float is 0, the condition is false and we regenerate.
                if ($random_float > 0 && (time() - $generated_at) <= ($cacheTime - ($probabilistic_beta * -log($random_float)))) {
                    // It's a hit.
                    $debugMessage = 'Page retrieved from cache in %f seconds.';
                } else {
                    // This request will regenerate the cache. Other concurrent requests will likely get a different
                    // random number, pass the check, and be served the stale content from this item.
                    $html = false; // Treat as a miss to trigger regeneration.
                    $debugMessage = 'Page generated (stale cache, probabilistic refresh) in %f seconds.';
                }
            } else {
                // Probabilistic check disabled.
                $debugMessage = 'Page retrieved from cache in %f seconds.';
            }
        } else {
            // Invalid cache format, treat as a miss.
            $html = false;
            $debugMessage = 'Page generated (invalid cache format) in %f seconds.';
        }
    } else {
        // Item not found in cache.
        $resultCode = $memcached->getResultCode();
        if ($resultCode !== \Memcached::RES_NOTFOUND) {
             if ($debug) error_log("Memcached: Error getting key '{$cacheKey}'. Result code: " . $resultCode . " (" . $memcached->getResultMessage() . ")");
        }
        $html = false; // Ensure it's false on miss
        $debugMessage = 'Page generated (cache miss) in %f seconds.';
    }
} else {
     $debugMessage = 'Page generated (caching disabled or connection failed) in %f seconds.';
}


// --- Page Generation (if cache miss or disabled) ---
if ( $html === false ) { // This condition now covers cache miss, disabled, or bypassed
    if ( $debug ) {
        echo "<!-- MFPC DEBUG: Cache Miss/Bypass. Loading WordPress... -->\n";
    }
    ob_start();

    // Define WordPress entry point
    $wp_blog_header = __DIR__ . '/wp-blog-header.php';

    if (file_exists($wp_blog_header)) {
        // Set flag for WordPress theme loading
        define( 'WP_USE_THEMES', true );
        // Spoof SCRIPT_FILENAME so WordPress thinks it's running index.php
        $_SERVER['SCRIPT_FILENAME'] = __DIR__ . '/index.php';
        /** Loads the WordPress Environment and Template */
        if ( $debug ) {
            echo "<!-- MFPC DEBUG: Require wp-blog-header.php -->\n";
        }
        require $wp_blog_header; // Use require for WordPress core file

        // Update debug status if WP_DEBUG was defined by WordPress
        if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
            $debug = true;
        }
    } else {
        // Handle error: WordPress core file missing
        error_log("Error: WordPress wp-blog-header.php not found at {$wp_blog_header}.");
        // Output a user-friendly error or trigger a 503
        echo "Error: Site temporarily unavailable.";
        if (!headers_sent()) {
             header("HTTP/1.1 503 Service Unavailable");
        }
    }

    $html = ob_get_contents();
    if ( $debug ) {
        echo "<!-- MFPC DEBUG: WP Output Captured. Length: " . strlen($html) . " -->\n";
    }
    ob_end_clean(); // Clean buffer regardless of WP success/failure

    // --- Lazy Load Transformation ---
    if ( $lazy_load_enabled && $html !== false ) {
        // Add loading="lazy" to img tags that don't have it
        $html = preg_replace_callback(
            '/<img\s+([^>]+)>/i',
            function ($matches) {
                if (stripos($matches[1], 'loading=') === false) {
                    return '<img loading="lazy" ' . $matches[1] . '>';
                }
                return $matches[0];
            },
            $html
        );
        // Add loading="lazy" to iframe tags that don't have it
        $html = preg_replace_callback(
            '/<iframe\s+([^>]+)>/i',
            function ($matches) {
                if (stripos($matches[1], 'loading=') === false) {
                    return '<iframe loading="lazy" ' . $matches[1] . '>';
                }
                return $matches[0];
            },
            $html
        );
    }

    // --- Cache Storage ---
    // Only try to set cache if NOT bypassed, connection was successful, content exists, and cache time > 0
    if ( !$cache_bypassed_by_cookie && $memcached && $html !== false && $html !== '' && $cacheTime > 0 ) {
        $generated_at = time();
        $payload = $html . "\n<!-- MFPC_META: " . $generated_at . " -->";
        $set_success = $memcached->set( $cacheKey, $payload, $cacheTime );
        if (!$set_success && $debug) {
             error_log("Memcached: Failed to set key '{$cacheKey}'. Result code: " . $memcached->getResultCode() . " (" . $memcached->getResultMessage() . ")");
        }
    } elseif ($cache_bypassed_by_cookie && $debug) {
        error_log("Memcached: Cache storage skipped for key '{$cacheKey}' due to cookie bypass.");
    }
}

// --- Cleanup & Output ---
if ($memcached) {
    $memcached->quit();
}

$finish = microtime( true );
$duration = $finish - $start;
$cacheExpiry = 'Cache TTL: %d seconds';

// Output the HTML
echo $html;

// Add debug comment if enabled
if ( $debug ) {
    $debug_output = "\n<!-- " . sprintf( $debugMessage, $duration );
    if ($cache_bypassed_by_cookie) {
        $debug_output .= ' | Cache Bypassed by Cookie';
    } else if ($cacheTime > 0) { // Only show TTL if caching was attempted
        $debug_output .= ' | ' . sprintf( $cacheExpiry, $cacheTime );
    }
    if ( $html !== false && $cache_age > 0 ) {
        $debug_output .= ' | Age: ' . $cache_age . 's';
    }
    $debug_output .= ' -->';
    echo $debug_output;
}

exit;
?>
