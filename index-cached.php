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

// Set defaults from config or hardcoded fallbacks
$debug = isset( $config['debug'] ) ? (bool) $config['debug'] : false; // Default debug off
$servers = isset( $config['servers'] ) && is_array( $config['servers'] ) && !empty($config['servers'])
           ? $config['servers']
           : [['host' => '127.0.0.1', 'port' => '11211']]; // Default server
$rules = isset( $config['rules'] ) && is_array( $config['rules'] ) ? $config['rules'] : [];
$default_cache_time = isset( $config['default_cache_time'] ) ? (int) $config['default_cache_time'] : 0; // Default 0 (no cache) if not set

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

// --- Memcached Connection ---
$memcached = null; // Initialize as null
$connection_success = false;

// Only attempt connection if caching is potentially enabled (cacheTime > 0 or debug is on)
if ($cacheTime > 0 || $debug) {
    if (class_exists('Memcached')) {
        $memcached = new Memcached();
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
            $memcached->setOption( Memcached::OPT_COMPRESSION, false );
            $memcached->setOption( Memcached::OPT_BUFFER_WRITES, true );
            // Binary protocol might fail with sockets, consider conditional setting or removing if using sockets primarily
            $memcached->setOption( Memcached::OPT_BINARY_PROTOCOL, true );
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

// Check for bypass cookies BEFORE attempting to get from cache
if (!empty($bypass_cookie_prefixes)) {
    $cache_bypassed_by_cookie = mfpc_should_bypass_cache($bypass_cookie_prefixes, $debug);
}

if ($cache_bypassed_by_cookie) {
    $html = false; // Ensure we generate fresh content
    $debugMessage = 'Page generated (cache bypassed by cookie) in %f seconds.';
} elseif ( $memcached && $cacheTime > 0 ) {
    $html = $memcached->get( $cacheKey );
    if ($html !== false) {
        $debugMessage = 'Page retrieved from cache in %f seconds.';
    } else {
        // Item not found or error during get
        $resultCode = $memcached->getResultCode();
        if ($resultCode !== Memcached::RES_NOTFOUND) {
             if ($debug) error_log("Memcached: Error getting key '{$cacheKey}'. Result code: " . $resultCode . " (" . $memcached->getResultMessage() . ")");
        }
        $debugMessage = 'Page generated (cache miss or error) in %f seconds.';
    }
} else {
     $debugMessage = 'Page generated (caching disabled or connection failed) in %f seconds.';
}


// --- Page Generation (if cache miss or disabled) ---
if ( $html === false ) { // This condition now covers cache miss, disabled, or bypassed
    ob_start();

    // Define WordPress entry point
    $wp_index_file = __DIR__ . '/index.php';

    if (file_exists($wp_index_file)) {
        // Set flag for WordPress theme loading
        define( 'WP_USE_THEMES', true );
        /** Loads the WordPress Environment and Template */
        require $wp_index_file; // Use require for WordPress core file
    } else {
        // Handle error: WordPress core file missing
        error_log("Error: WordPress index.php not found at {$wp_index_file}.");
        // Output a user-friendly error or trigger a 503
        echo "Error: Site temporarily unavailable.";
        if (!headers_sent()) {
             header("HTTP/1.1 503 Service Unavailable");
        }
    }

    $html = ob_get_contents();
    ob_end_clean(); // Clean buffer regardless of WP success/failure

    // --- Cache Storage ---
    // Only try to set cache if NOT bypassed, connection was successful, content exists, and cache time > 0
    if ( !$cache_bypassed_by_cookie && $memcached && $html !== false && $html !== '' && $cacheTime > 0 ) {
        $set_success = $memcached->set( $cacheKey, $html, $cacheTime );
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
    $debug_output .= ' -->';
    echo $debug_output;
}

exit;
?>
