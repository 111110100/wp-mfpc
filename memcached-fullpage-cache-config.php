<?php
/**
 * Plugin Name:       Memcached Full Page Cache Config
 * Description:       Provides an admin interface to configure Memcached servers and cache rules for index-cached.php. Also allows purging cache on post save and generates Nginx upstream config.
 * Version:           1.4.0
 * Author:            Erwin Lomibao/Gemini Code Assist
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       mfpc-config
 */

namespace MFPC;

defined( 'ABSPATH' ) || exit; // Exit if accessed directly

// --- Constants ---
define( 'MFPC_OPTION_NAME', 'mfpc_settings' );
define( 'MFPC_PHP_CONFIG_FILE_PATH', WP_CONTENT_DIR . '/memcached-fp-config.php' );
define( 'MFPC_NGINX_TEMPLATE_FILE_PATH', plugin_dir_path( __FILE__ ) . 'nginx-template.conf' );
define( 'MFPC_NGINX_OUTPUT_FILE_PATH', WP_CONTENT_DIR . '/memcached_nginx.conf' ); // Output Nginx config here
define( 'MFPC_NGINX_UPSTREAM_FILE_PATH', WP_CONTENT_DIR . '/memcached_upstream.conf' ); // Output Nginx upstream config here

/**
 * Converts seconds into a human-readable time duration string.
 *
 * @param int $seconds The number of seconds.
 * @return string The human-readable time duration.
 */
function mfpc_seconds_to_human_time( $seconds ) {
    $seconds = (int) $seconds;
    if ( $seconds <= 0 ) {
        return __( 'No cache', 'mfpc-config' );
    }

    $periods = array(
        'day'    => 86400,
        'hour'   => 3600,
        'minute' => 60,
        'second' => 1,
    );

    $parts = array();

    foreach ( $periods as $name => $secs ) {
        $count = floor( $seconds / $secs );
        if ( $count > 0 ) {
            $parts[] = sprintf( _n( '%s ' . $name, '%s ' . $name . 's', $count, 'mfpc-config' ), $count );
            $seconds -= $count * $secs;
        }
    }

    // If only seconds remain after calculating larger units, or if the total was less than 60
    if ($seconds > 0 || empty($parts)) {
         $parts[] = sprintf( _n( '%s second', '%s seconds', $seconds, 'mfpc-config' ), $seconds );
    }


    return implode( ', ', $parts );
}

/**
 * Add our link to the WP admin menu.
 */
function mfpc_add_admin_menu_bar( $admin_bar ) {
    if (! current_user_can( 'manage_options' )) {
        return;
    }
    $admin_bar->add_menu([
        'id' => 'mfpc-config',
        'title' => __( 'Memcached Full Page Cache', 'mfpc-config' ),
        'href' => admin_url( 'admin.php?page=mfpc-config' ),
        'parent' => null,
        'group' => false,
        'meta' => '',
    ]);

    // Add Page Specific Stats if on frontend
    if ( ! is_admin() ) {
        $options = mfpc_get_options();
        $memcached = mfpc_get_memcached_connection( $options['servers'] );
        if ( $memcached ) {
            $host = $_SERVER['HTTP_HOST'] ?? parse_url( home_url(), PHP_URL_HOST );
            $uri = $_SERVER['REQUEST_URI'];
            $page_prefix = "mfpc:stats:{$host}:page:" . md5($uri) . ":";

            $hits = (int) $memcached->get( $page_prefix . 'hits' );
            $misses = (int) $memcached->get( $page_prefix . 'misses' );
            $total = $hits + $misses;
            $ratio = $total > 0 ? round( ( $hits / $total ) * 100, 1 ) : 0;

            $admin_bar->add_menu([
                'id' => 'mfpc-page-stats',
                'title' => sprintf( __( 'Page: %d Hits, %d Misses (%s%%)', 'mfpc-config' ), $hits, $misses, $ratio ),
                'parent' => 'mfpc-config',
                'href' => false,
            ]);
        }
    }
}
\add_action( 'admin_bar_menu', __NAMESPACE__ . '\mfpc_add_admin_menu_bar', 100 );

/**
 * Add the admin menu item.
 */
function mfpc_add_admin_menu() {
    add_menu_page(
        __( 'Fullpage Cache Config', 'mfpc-config' ),
        __( 'Fullpage Cache', 'mfpc-config' ),
        'manage_options',
        'mfpc-config',
        __NAMESPACE__ . '\mfpc_options_page_html',
        'dashicons-performance'
    );
    // Add submenu for Config (to appear as first item)
    add_submenu_page(
        'mfpc-config',
        __( 'Fullpage Cache Config', 'mfpc-config' ),
        __( 'Config', 'mfpc-config' ),
        'manage_options',
        'mfpc-config',
        __NAMESPACE__ . '\mfpc_options_page_html'
    );
    add_submenu_page(
        'mfpc-config',
        __( 'Memcached Stats', 'mfpc-config' ),
        __( 'Stats', 'mfpc-config' ),
        'manage_options',
        'mfpc-stats',
        __NAMESPACE__ . '\mfpc_stats_page_html'
    );
}
\add_action( 'admin_menu', __NAMESPACE__ . '\mfpc_add_admin_menu' );

/**
 * Register plugin settings.
 */
function mfpc_settings_init() {
    \register_setting( 'mfpc_options_group', MFPC_OPTION_NAME, __NAMESPACE__ . '\mfpc_sanitize_settings' );

    // --- General Section ---
    add_settings_section(
        'mfpc_general_section',
        __( 'General Settings', 'mfpc-config' ),
        null,
        'mfpc-config'
    );

    add_settings_field(
        'mfpc_debug_field',
        \__( 'Enable Debug Output', 'mfpc-config' ),
        __NAMESPACE__ . '\mfpc_debug_field_html',
        'mfpc-config',
        'mfpc_general_section'
    );

     add_settings_field(
        'mfpc_default_time_field',
        \__( 'Default Cache Time', 'mfpc-config' ),
        __NAMESPACE__ . '\mfpc_default_time_field_html',
        'mfpc-config',
        'mfpc_general_section'
    );

    add_settings_field(
        'mfpc_purge_on_save_field',
        \__( 'Purge Cache on Actions', 'mfpc-config' ), // Renamed for clarity
        __NAMESPACE__ . '\mfpc_purge_on_save_field_html',
        'mfpc-config',
        'mfpc_general_section'
    );

    add_settings_field(
        'mfpc_bypass_cookies_field',
        \__( 'Bypass Cache for Cookies', 'mfpc-config' ),
        __NAMESPACE__ . '\mfpc_bypass_cookies_field_html',
        'mfpc-config',
        'mfpc_general_section'
    );

    // --- Servers Section ---
    add_settings_section(
        'mfpc_servers_section',
        \__( 'Memcached Servers', 'mfpc-config' ),
        __NAMESPACE__ . '\mfpc_servers_section_html',
        'mfpc-config'
    );

    // --- Rules Section ---
    add_settings_section(
        'mfpc_rules_section',
        \__( 'Cache Time Rules', 'mfpc-config' ),
        __NAMESPACE__ . '\mfpc_rules_section_html',
        'mfpc-config'
    );
}
\add_action( 'admin_init', __NAMESPACE__ . '\mfpc_settings_init' );

/**
 * Add settings link to the plugin page.
 */
function mfpc_settings_link( $links ) {
    $settings_link = '<a href="' . esc_url( admin_url( 'admin.php?page=mfpc-config' ) ) . '">' . __( 'Settings', 'mfpc-config' ) . '</a>';
    array_unshift( $links, $settings_link );
    return $links;
}
\add_filter( 'plugin_action_links_' . \plugin_basename( __FILE__ ), __NAMESPACE__ . '\mfpc_settings_link' );

/**
 * Get plugin options with defaults.
 */
function mfpc_get_options() {
    $defaults = [
        'debug' => ( defined( 'WP_DEBUG' ) && WP_DEBUG ),
        'default_cache_time' => 3600,
        'purge_on_save' => false, // This now controls purging on save, status change, and delete
        'servers' => [
            ['host' => '127.0.0.1', 'port' => '11211']
        ],
        'rules' => [
            ['path' => '/', 'time' => 1800],
            ['path' => '/tag/', 'time' => 86400],
            ['path' => '/category/', 'time' => 86400],
            ['path' => '/author/', 'time' => 86400],
            ['path' => '/search/', 'time' => 86400],
        ],
        'bypass_cookies' => [ // Default cookie prefixes
            'comment_',
            'woocommerce_',
            'wordpress_',
            'xf_',
            'edd_',
            'jetpack_',
            'yith_wcwl_session_',
            'yith_wrvp_',
            'wpsc_',
            'ecwid_',
            'ec_',
            'bookly_',
        ],
    ];
    $options = \get_option( MFPC_OPTION_NAME, $defaults );

    // Force debug if WP_DEBUG is enabled
    if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
        $options['debug'] = true;
    }

    // --- Enterprise: Environment Overrides ---
    if ( defined( 'WP_MFPC_DEBUG' ) ) {
        $options['debug'] = constant( 'WP_MFPC_DEBUG' );
    }
    if ( defined( 'WP_MFPC_DEFAULT_CACHE_TIME' ) ) {
        $options['default_cache_time'] = constant( 'WP_MFPC_DEFAULT_CACHE_TIME' );
    }
    if ( defined( 'WP_MFPC_SERVERS' ) && is_array( constant( 'WP_MFPC_SERVERS' ) ) ) {
        $options['servers'] = constant( 'WP_MFPC_SERVERS' );
    }
    if ( defined( 'WP_MFPC_RULES' ) && is_array( constant( 'WP_MFPC_RULES' ) ) ) {
        $options['rules'] = constant( 'WP_MFPC_RULES' );
    }
    if ( defined( 'WP_MFPC_BYPASS_COOKIES' ) && is_array( constant( 'WP_MFPC_BYPASS_COOKIES' ) ) ) {
        $options['bypass_cookies'] = constant( 'WP_MFPC_BYPASS_COOKIES' );
    }

    // Ensure sub-arrays exist even if saved option is missing them
    $options['servers'] = isset($options['servers']) && is_array($options['servers']) ? $options['servers'] : $defaults['servers'];
    $options['rules'] = isset($options['rules']) && is_array($options['rules']) ? $options['rules'] : $defaults['rules'];
    $options['bypass_cookies'] = isset($options['bypass_cookies']) && is_array($options['bypass_cookies']) ? $options['bypass_cookies'] : $defaults['bypass_cookies'];

    return \wp_parse_args( $options, $defaults );
}


// --- HTML Callback Functions ---

/**
 * Render the main options page container.
 */
function mfpc_options_page_html() {
    if ( ! current_user_can( 'manage_options' ) ) {
        return;
    }

    // Handle Purge All Action
    if ( isset($_POST['mfpc_purge_all']) && check_admin_referer('mfpc_purge_all_action') ) {
        mfpc_purge_all_cache();
        echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__('All cache flushed successfully.', 'mfpc-config') . '</p></div>';
    }
    ?>
    <div class="wrap">
        <h1><?php echo esc_html( get_admin_page_title() ); ?></h1>
        <?php mfpc_render_stats_widget(); ?>
        <form action="options.php" method="post">
            <?php
            settings_fields( 'mfpc_options_group' );
            do_settings_sections( 'mfpc-config' );
            submit_button( __( 'Save Settings', 'mfpc-config' ) );
            ?>
            <button type="button" class="button" onclick="window.location.href='<?php echo esc_url(admin_url('admin.php?page=mfpc-config')); ?>';">
                <?php esc_html_e( 'Cancel', 'mfpc-config' ); ?>
            </button>
        </form>
        <form method="post" style="margin-top: 20px; display: inline-block;">
            <?php wp_nonce_field('mfpc_purge_all_action'); ?>
            <input type="hidden" name="mfpc_purge_all" value="1">
            <?php submit_button( __( 'Purge All Cache', 'mfpc-config' ), 'delete', 'submit', false, ['onclick' => "return confirm('" . esc_js( __( 'Are you sure you want to flush the entire Memcached cache?', 'mfpc-config' ) ) . "');"] ); ?>
            <p class="description" style="margin-top: 20px;">
                <strong><?php esc_html_e( 'Professional Services:', 'mfpc-config' ); ?></strong>
                <?php esc_html_e( 'Need help with installation, configuration, or customization? Contact me for professional services.', 'mfpc-config' ); ?>
            </p>
             <?php if ( isset( $_GET['settings-updated'] ) ) : ?>
                <div id="setting-error-settings_updated" class="notice notice-success settings-error is-dismissible">
                    <p><strong><?php esc_html_e( 'Settings saved.', 'mfpc-config' ); ?></strong></p>
                    <?php if ( get_transient('mfpc_php_config_success') ) : ?>
                        <p><?php echo esc_html( get_transient('mfpc_php_config_success') ); ?></p>
                        <?php delete_transient('mfpc_php_config_success'); ?>
                    <?php endif; ?>
                    <?php if ( get_transient('mfpc_nginx_config_success') ) : ?>
                        <p><?php echo esc_html( get_transient('mfpc_nginx_config_success') ); ?></p>
                        <p><strong><?php esc_html_e( 'Step 1:', 'mfpc-config' ); ?></strong> <?php printf(
                                wp_kses_post( __( 'Copy %s to %s (or include it in your `http` block).', 'mfpc-config' ) ),
                                '<code>' . esc_html( MFPC_NGINX_UPSTREAM_FILE_PATH ) . '</code>',
                                '<code>/etc/nginx/conf.d/</code>'
                            ); ?></p>
                        <p><strong><?php esc_html_e( 'Step 2:', 'mfpc-config' ); ?></strong> <?php printf(
                                wp_kses_post( __( 'Include %s in your main Nginx configuration `server` block (e.g., using an `include %s;` directive).', 'mfpc-config' ) ),
                                '<code>' . esc_html( MFPC_NGINX_OUTPUT_FILE_PATH ) . '</code>',
                                esc_html( MFPC_NGINX_OUTPUT_FILE_PATH )
                            ); ?></p>
                        <p><?php esc_html_e( 'After updating the Nginx configuration, you can test it with:', 'mfpc-config' ); ?>
                            <code>nginx -t && nginx -s reload</code>
                        <?php if ( !file_exists( ABSPATH . 'index-cached.php' ) ) : ?>
                            <p><?php esc_html_e( 'To use the full page cache, you need to copy index-cached.php to your WordPress root directory.', 'mfpc-config' ); ?></p>
                            <code>sudo cp <?php echo ABSPATH . 'wp-content/plugins/wp-mfpc/index-cached.php'?> <?php echo ABSPATH ?></code>
                        <?php endif; ?>
                        <?php delete_transient('mfpc_nginx_config_success'); ?>
                    <?php endif; ?>
                </div>
            <?php endif; ?>
             <?php if ( get_transient('mfpc_php_config_error') ) : ?>
                <div class="notice notice-error settings-error is-dismissible">
                    <p><strong><?php echo esc_html( get_transient('mfpc_php_config_error') ); ?></strong></p>
                </div>
                <?php delete_transient('mfpc_php_config_error'); ?>
            <?php endif; ?>
             <?php if ( get_transient('mfpc_nginx_config_error') ) : ?>
                <div class="notice notice-error settings-error is-dismissible">
                    <p><strong><?php echo esc_html( get_transient('mfpc_nginx_config_error') ); ?></strong></p>
                </div>
                <?php delete_transient('mfpc_nginx_config_error'); ?>
            <?php endif; ?>
             <?php if ( get_transient('mfpc_purge_error') ) : ?>
                <div class="notice notice-warning settings-error is-dismissible">
                    <p><strong><?php esc_html_e( 'Purge Warning:', 'mfpc-config' ); ?></strong> <?php echo esc_html( get_transient('mfpc_purge_error') ); ?></p>
                </div>
                <?php delete_transient('mfpc_purge_error'); ?>
            <?php endif; ?>
            <?php if ( $last_purged = get_transient('mfpc_last_purge_keys') ) : ?>
                <div class="notice notice-info is-dismissible">
                    <p><strong><?php esc_html_e( 'Last Purged Keys:', 'mfpc-config' ); ?></strong><br/><code style="display:block; margin-top:5px;"><?php echo nl2br(esc_html( $last_purged )); ?></code></p>
                </div>
            <?php endif; ?>
        </form>
    </div>
    <?php
}

/**
 * Render the Stats page.
 */
function mfpc_stats_page_html() {
    if ( ! current_user_can( 'manage_options' ) ) {
        return;
    }

    // Handle Reset Action
    if ( isset($_POST['mfpc_reset_stats']) && check_admin_referer('mfpc_reset_stats_action') ) {
        mfpc_reset_stats();
        echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__('Statistics have been reset.', 'mfpc-config') . '</p></div>';
    }

    ?>
    <div class="wrap">
        <h1><?php esc_html_e( 'Memcached Statistics', 'mfpc-config' ); ?></h1>
        <?php mfpc_render_stats_widget( true ); ?>
        
        <form method="post" style="margin-top: 20px;">
            <?php wp_nonce_field('mfpc_reset_stats_action'); ?>
            <input type="hidden" name="mfpc_reset_stats" value="1">
            <?php submit_button( __( 'Reset Stats', 'mfpc-config' ), 'secondary', 'submit', false ); ?>
        </form>
    </div>
    <?php
}

/**
 * Helper to render the stats widget/box.
 *
 * @param bool $detailed Whether to show detailed server stats.
 */
function mfpc_render_stats_widget( $detailed = false ) {
    $stats = mfpc_get_site_stats();
    ?>
    <div class="card" style="max-width: 100%; margin-top: 20px;">
        <h2 class="title"><?php esc_html_e( 'Cache Performance', 'mfpc-config' ); ?></h2>
        <div style="display: flex; flex-wrap: wrap; gap: 20px; margin-top: 10px;">
            <div style="flex: 1; min-width: 150px; text-align: center; padding: 10px; background: #f0f0f1; border-radius: 4px;">
                <div style="font-size: 24px; font-weight: bold; color: #2271b1;"><?php echo esc_html( number_format_i18n( $stats['hits'] ) ); ?></div>
                <div style="color: #646970;"><?php esc_html_e( 'Hits', 'mfpc-config' ); ?></div>
            </div>
            <div style="flex: 1; min-width: 150px; text-align: center; padding: 10px; background: #f0f0f1; border-radius: 4px;">
                <div style="font-size: 24px; font-weight: bold; color: #d63638;"><?php echo esc_html( number_format_i18n( $stats['misses'] ) ); ?></div>
                <div style="color: #646970;"><?php esc_html_e( 'Misses', 'mfpc-config' ); ?></div>
            </div>
            <div style="flex: 1; min-width: 150px; text-align: center; padding: 10px; background: #f0f0f1; border-radius: 4px;">
                <div style="font-size: 24px; font-weight: bold; color: #00a32a;"><?php echo esc_html( $stats['ratio'] ); ?>%</div>
                <div style="color: #646970;"><?php esc_html_e( 'Hit Ratio', 'mfpc-config' ); ?></div>
            </div>
        </div>
        <?php if ( $detailed && !empty($stats['server_stats']) ) : ?>
            <hr style="margin: 20px 0;">
            <h3><?php esc_html_e( 'Memcached Server Details', 'mfpc-config' ); ?></h3>
            <table class="widefat striped">
                <thead>
                    <tr>
                        <th><?php esc_html_e( 'Server', 'mfpc-config' ); ?></th>
                        <th><?php esc_html_e( 'Uptime', 'mfpc-config' ); ?></th>
                        <th><?php esc_html_e( 'Curr Items', 'mfpc-config' ); ?></th>
                        <th><?php esc_html_e( 'Bytes Used', 'mfpc-config' ); ?></th>
                        <th><?php esc_html_e( 'Connections', 'mfpc-config' ); ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ( $stats['server_stats'] as $server => $s ) : ?>
                        <tr>
                            <td><?php echo esc_html( $server ); ?></td>
                            <td><?php echo esc_html( mfpc_seconds_to_human_time( $s['uptime'] ?? 0 ) ); ?></td>
                            <td><?php echo esc_html( number_format_i18n( $s['curr_items'] ?? 0 ) ); ?></td>
                            <td><?php echo esc_html( size_format( $s['bytes'] ?? 0 ) ); ?></td>
                            <td><?php echo esc_html( number_format_i18n( $s['curr_connections'] ?? 0 ) ); ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
    </div>
    <?php
}

/**
 * Reset stats counters.
 */
function mfpc_reset_stats() {
    $options = mfpc_get_options();
    $memcached = mfpc_get_memcached_connection( $options['servers'] );
    if ( $memcached ) {
        // Use the same prefix logic as index-cached.php
        // Note: index-cached.php uses $_SERVER['HTTP_HOST']. We assume admin is on same host.
        $host = $_SERVER['HTTP_HOST'] ?? parse_url( home_url(), PHP_URL_HOST );
        $prefix = "mfpc:stats:{$host}:";
        
        $memcached->delete( $prefix . 'hits' );
        $memcached->delete( $prefix . 'misses' );
        // $memcached->delete( $prefix . 'bypass' ); // If we track bypass in future
    }
}

/**
 * Render Debug checkbox.
 */
function mfpc_debug_field_html() {
    $options = mfpc_get_options();
    ?>
    <input type="checkbox" id="mfpc_debug" name="<?php echo esc_attr(MFPC_OPTION_NAME); ?>[debug]" value="1" <?php checked( 1, $options['debug'], true ); ?> />
    <label for="mfpc_debug"><?php esc_html_e( 'Add HTML comment with cache status and timing to page source (in index-cached.php). Also enables PHP error logging for purge actions.', 'mfpc-config' ); ?></label>
    <?php
}

/**
 * Render Default Cache Time input.
 */
function mfpc_default_time_field_html() {
    $options = mfpc_get_options();
    ?>
    <input type="number" min="0" step="1" id="mfpc_default_cache_time" name="<?php echo esc_attr(MFPC_OPTION_NAME); ?>[default_cache_time]" value="<?php echo esc_attr( $options['default_cache_time'] ); ?>" class="small-text" />
    <p class="description"><?php esc_html_e( 'Default cache time in seconds if no specific rule matches. Set to 0 to disable caching by default.', 'mfpc-config' ); ?></p>
    <?php
}

/**
 * Render Purge on Save checkbox.
 */
function mfpc_purge_on_save_field_html() {
    $options = mfpc_get_options();
    ?>
    <input type="checkbox" id="mfpc_purge_on_save" name="<?php echo esc_attr(MFPC_OPTION_NAME); ?>[purge_on_save]" value="1" <?php checked( 1, $options['purge_on_save'], true ); ?> />
    <label for="mfpc_purge_on_save"><?php esc_html_e( 'Purge cache for the specific post/page and the homepage when a post or page is saved, updated, unpublished, or deleted.', 'mfpc-config' ); ?></label>
    <p class="description"><?php esc_html_e( 'Requires Memcached connection details below to be correct.', 'mfpc-config' ); ?></p>
    <?php
}

/**
 * Render Bypass Cookies textarea.
 */
function mfpc_bypass_cookies_field_html() {
    $options = mfpc_get_options();
    // Ensure bypass_cookies is an array before imploding
    $bypass_cookies_array = isset($options['bypass_cookies']) && is_array($options['bypass_cookies']) ? $options['bypass_cookies'] : [];
    $bypass_cookies_string = implode( "\n", $bypass_cookies_array );
    ?>
    <textarea id="mfpc_bypass_cookies" name="<?php echo esc_attr(MFPC_OPTION_NAME); ?>[bypass_cookies_text]" rows="10" cols="50" class="large-text code"><?php echo esc_textarea( $bypass_cookies_string ); ?></textarea>
    <p class="description">
        <?php esc_html_e( 'Enter cookie name prefixes, one per line. If a visitor has any cookie starting with one of these prefixes, the full page cache will be bypassed for them. For example, "wordpress_logged_in_" or "comment_author_".', 'mfpc-config' ); ?>
    </p>
    <?php
}

/**
 * Render Servers section description and table header.
 */
function mfpc_servers_section_html() {
    ?>
    <p><?php esc_html_e( 'Add Memcached server addresses and ports. Use the full path for socket connections (e.g., /var/run/memcached.sock) and set port to 0.', 'mfpc-config' ); ?></p>
    <p><?php esc_html_e( 'These servers will be used for purging and generating the Nginx upstream configuration.', 'mfpc-config' ); ?></p>
    <table class="wp-list-table widefat fixed striped" id="mfpc-servers-table">
        <thead>
            <tr>
                <th scope="col" style="width: 45%;"><?php esc_html_e( 'Memcached Server/Socket Path', 'mfpc-config' ); ?></th>
                <th scope="col" style="width: 15%;"><?php esc_html_e( 'Port (0 for socket)', 'mfpc-config' ); ?></th>
                <th scope="col" style="width: 20%;"><?php esc_html_e( 'Status', 'mfpc-config' ); ?></th> <?php // New Header ?>
                <th scope="col" style="width: 10%;"><?php esc_html_e( 'Actions', 'mfpc-config' ); ?></th>
            </tr>
        </thead>
        <tbody id="mfpc-servers-body">
            <?php
            $options = mfpc_get_options();
            if ( ! empty( $options['servers'] ) ) :
                foreach ( $options['servers'] as $index => $server ) :
                    $status_info = mfpc_check_server_status( $server ); // Check status
                    ?>
                    <tr class="mfpc-server-row">
                        <td><input type="text" name="<?php echo esc_attr(MFPC_OPTION_NAME); ?>[servers][<?php echo $index; ?>][host]" value="<?php echo esc_attr( $server['host'] ); ?>" class="regular-text" required /></td>
                        <td><input type="text" name="<?php echo esc_attr(MFPC_OPTION_NAME); ?>[servers][<?php echo $index; ?>][port]" value="<?php echo esc_attr( $server['port'] ); ?>" class="small-text" required /></td>
                        <td class="mfpc-server-status <?php echo esc_attr( $status_info['class'] ); ?>"><?php echo esc_html( $status_info['message'] ); ?></td> <?php // New Cell ?>
                        <td><button type="button" class="button mfpc-remove-row"><?php esc_html_e( 'Delete', 'mfpc-config' ); ?></button></td>
                    </tr>
                    <?php
                endforeach;
            else : // Provide a blank row if none exist
                 ?>
                 <tr class="mfpc-server-row mfpc-hidden-template">
                     <td><input type="text" name="<?php echo esc_attr(MFPC_OPTION_NAME); ?>[servers][0][host]" value="" class="regular-text" required /></td>
                     <td><input type="text" name="<?php echo esc_attr(MFPC_OPTION_NAME); ?>[servers][0][port]" value="" class="small-text" required /></td>
                     <td class="mfpc-server-status"></td> <?php // New Cell - Blank ?>
                     <td><button type="button" class="button mfpc-remove-row"><?php esc_html_e( 'Delete', 'mfpc-config' ); ?></button></td>
                 </tr>
                 <?php
            endif;
            ?>
        </tbody>
        <tfoot>
            <tr>
                <td colspan="4"> <?php // Updated colspan ?>
                    <button type="button" class="button" id="mfpc-add-server"><?php esc_html_e( 'Add Server', 'mfpc-config' ); ?></button>
                </td>
            </tr>
        </tfoot>
    </table>

    <!-- Server Row Template -->
    <template id="mfpc-server-template">
         <tr class="mfpc-server-row">
             <td><input type="text" name="" value="" class="regular-text" required /></td>
             <td><input type="text" name="" value="" class="small-text" required /></td>
             <td class="mfpc-server-status"></td> <?php // New Cell - Template ?>
             <td><button type="button" class="button mfpc-remove-row"><?php esc_html_e( 'Delete', 'mfpc-config' ); ?></button></td>
         </tr>
    </template>
    <?php
}

/**
 * Render Rules section description and table header.
 */
function mfpc_rules_section_html() {
     ?>
    <p><?php esc_html_e( 'Define specific cache times for URI paths. Rules are checked in order. Use simple string matching (e.g., "/category/" matches any URL containing "/category/").', 'mfpc-config' ); ?></p>
    <table class="wp-list-table widefat fixed striped" id="mfpc-rules-table">
        <thead>
            <tr>
                <th scope="col" style="width: 40%;"><?php esc_html_e( 'URI Path Contains', 'mfpc-config' ); ?></th>
                <th scope="col" style="width: 15%;"><?php esc_html_e( 'Time in Seconds', 'mfpc-config' ); ?></th>
                <th scope="col" style="width: 25%;"><?php esc_html_e( 'Approximate Time', 'mfpc-config' ); ?></th>
                <th scope="col" style="width: 10%;"><?php esc_html_e( 'Actions', 'mfpc-config' ); ?></th>
            </tr>
        </thead>
        <tbody id="mfpc-rules-body">
            <?php
            $options = mfpc_get_options();
             if ( ! empty( $options['rules'] ) ) :
                foreach ( $options['rules'] as $index => $rule ) :
                    ?>
                    <tr class="mfpc-rule-row">
                        <td><input type="text" name="<?php echo esc_attr(MFPC_OPTION_NAME); ?>[rules][<?php echo $index; ?>][path]" value="<?php echo esc_attr( $rule['path'] ); ?>" class="regular-text" required /></td>
                        <td><input type="number" min="0" step="1" name="<?php echo esc_attr(MFPC_OPTION_NAME); ?>[rules][<?php echo $index; ?>][time]" value="<?php echo esc_attr( $rule['time'] ); ?>" class="small-text mfpc-time-input" required /></td>
                        <td class="mfpc-human-time"><?php echo esc_html( mfpc_seconds_to_human_time( $rule['time'] ) ); ?></td>
                        <td><button type="button" class="button mfpc-remove-row"><?php esc_html_e( 'Delete', 'mfpc-config' ); ?></button></td>
                    </tr>
                    <?php
                endforeach;
             else : // Provide a blank row if none exist
                 ?>
                 <tr class="mfpc-rule-row mfpc-hidden-template">
                     <td><input type="text" name="<?php echo esc_attr(MFPC_OPTION_NAME); ?>[rules][0][path]" value="" class="regular-text" required /></td>
                     <td><input type="number" min="0" step="1" name="<?php echo esc_attr(MFPC_OPTION_NAME); ?>[rules][0][time]" value="" class="small-text mfpc-time-input" required /></td>
                     <td class="mfpc-human-time"><?php echo esc_html( mfpc_seconds_to_human_time( 0 ) ); ?></td>
                     <td><button type="button" class="button mfpc-remove-row"><?php esc_html_e( 'Delete', 'mfpc-config' ); ?></button></td>
                 </tr>
                 <?php
             endif;
            ?>
        </tbody>
        <tfoot>
            <tr>
                <td colspan="4">
                    <button type="button" class="button" id="mfpc-add-rule"><?php esc_html_e( 'Add Rule', 'mfpc-config' ); ?></button>
                </td>
            </tr>
        </tfoot>
    </table>

     <!-- Rule Row Template -->
    <template id="mfpc-rule-template">
         <tr class="mfpc-rule-row">
             <td><input type="text" name="" value="" class="regular-text" required /></td>
             <td><input type="number" min="0" step="1" name="" value="" class="small-text mfpc-time-input" required /></td>
             <td class="mfpc-human-time"><?php echo esc_html( mfpc_seconds_to_human_time( 0 ) ); ?></td>
             <td><button type="button" class="button mfpc-remove-row"><?php esc_html_e( 'Delete', 'mfpc-config' ); ?></button></td>
         </tr>
    </template>
     <?php
}

// --- Sanitization and Config Generation ---

/**
 * Sanitize settings and generate config files.
 */
function mfpc_sanitize_settings( $input ) {
    $new_input = [];
    $defaults = mfpc_get_options();

    // Sanitize Debug
    $new_input['debug'] = isset( $input['debug'] ) ? (bool) $input['debug'] : false;

    // Sanitize Default Cache Time
    $new_input['default_cache_time'] = isset( $input['default_cache_time'] ) ? absint( $input['default_cache_time'] ) : $defaults['default_cache_time'];

    // Sanitize Purge on Save
    $new_input['purge_on_save'] = isset( $input['purge_on_save'] ) ? (bool) $input['purge_on_save'] : false;

    // Sanitize Bypass Cookies
    $new_input['bypass_cookies'] = [];
    if ( isset( $input['bypass_cookies_text'] ) && is_string( $input['bypass_cookies_text'] ) ) {
        $cookie_lines = explode( "\n", $input['bypass_cookies_text'] );
        foreach ( $cookie_lines as $line ) {
            $trimmed_line = trim( sanitize_text_field( $line ) );
            if ( ! empty( $trimmed_line ) ) {
                $new_input['bypass_cookies'][] = $trimmed_line;
            }
        }
    } elseif ( isset( $input['bypass_cookies'] ) && is_array( $input['bypass_cookies'] ) ) {
        // Handle array input (e.g. from CLI)
        $new_input['bypass_cookies'] = array_map('sanitize_text_field', $input['bypass_cookies']);
    }
    // If the user clears the textarea, $new_input['bypass_cookies'] will be empty, which is the desired behavior.
    // Defaults are handled by mfpc_get_options() when the option is first read.


    // Sanitize Servers
    $new_input['servers'] = [];
    if ( isset( $input['servers'] ) && is_array( $input['servers'] ) ) {
        foreach ( $input['servers'] as $server ) {
            if ( ! empty( $server['host'] ) && isset( $server['port'] ) ) {
                 $host = trim( sanitize_text_field( $server['host'] ) );
                 if (strpos($host, '/') === 0) {
                     // Basic validation for socket path format (starts with /)
                     $port = '0';
                 } else {
                     // Basic validation for hostname/IP and port
                     $port = trim( sanitize_text_field( $server['port'] ) );
                     if (!ctype_digit($port) || $port < 0 || $port > 65535) { // Ensure port is a valid number
                         $port = '11211'; // Default if invalid
                     }
                 }
                 $new_input['servers'][] = [
                    'host' => $host,
                    'port' => $port,
                ];
            }
        }
    }
    if ( empty( $new_input['servers'] ) ) {
        $new_input['servers'] = $defaults['servers'];
    }


    // Sanitize Rules
    $new_input['rules'] = [];
    if ( isset( $input['rules'] ) && is_array( $input['rules'] ) ) {
        foreach ( $input['rules'] as $rule ) {
            if ( isset( $rule['path'] ) && isset( $rule['time'] ) ) { // Allow empty path for '/' rule
                $path = sanitize_text_field( trim($rule['path']) );
                $time = absint( $rule['time'] );
                // Only add rule if path is not empty OR if it's the root path '/'
                if ( $path !== '' || ($path === '' && $rule['path'] === '/') ) {
                     $new_input['rules'][] = [
                        'path' => $path,
                        'time' => $time,
                    ];
                }
            }
        }
    }
     // Ensure there's at least one rule if the original default had one (e.g., for '/')
    if ( empty($new_input['rules']) && !empty($defaults['rules']) ) {
         $new_input['rules'] = $defaults['rules'];
    }


    // --- Generate PHP Config File (for index-cached.php) ---
    $config_for_php_file = [
        'debug' => $new_input['debug'],
        'default_cache_time' => $new_input['default_cache_time'],
        'servers' => $new_input['servers'],
        'rules' => $new_input['rules'],
        'bypass_cookies' => $new_input['bypass_cookies'],
    ];

    $php_config_content = "<?php\n";
    $php_config_content .= "// Auto-generated by Memcached Full Page Cache Config plugin\n";
    $php_config_content .= "// Config for index-cached.php\n";
    $php_config_content .= "// Do not edit manually!\n\n";
    $php_config_content .= "defined( 'ABSPATH' ) || defined( 'MFPC_CACHE_LOADED' ) || exit; // Prevent direct access\n\n";
    $php_config_content .= "return " . var_export( $config_for_php_file, true ) . ";\n";

    $write_php_result = file_put_contents( MFPC_PHP_CONFIG_FILE_PATH, $php_config_content );

    if ( $write_php_result === false ) {
        $error_msg = sprintf( __( 'Error: Could not write PHP configuration file to %s. Please check file permissions.', 'mfpc-config' ), MFPC_PHP_CONFIG_FILE_PATH );
        add_settings_error( MFPC_OPTION_NAME, 'php_config_write_error', $error_msg, 'error' );
        set_transient('mfpc_php_config_error', $error_msg, 60);
    } else {
        delete_transient('mfpc_php_config_error');
        set_transient('mfpc_php_config_success', sprintf( __( 'PHP config file generated successfully at %s.', 'mfpc-config' ), MFPC_PHP_CONFIG_FILE_PATH ), 60);
    }

    // --- Generate Nginx Config File ---
    $nginx_config_generated = false;
    if ( file_exists( MFPC_NGINX_TEMPLATE_FILE_PATH ) ) {
        $template_content = file_get_contents( MFPC_NGINX_TEMPLATE_FILE_PATH );

        if ( $template_content !== false ) {
            $upstream_block = "# Auto-generated by Memcached Full Page Cache Config plugin\n";
            $upstream_block .= "upstream memcached_servers {\n";
            $upstream_block .= "    least_conn; # Optional: uncomment for load balancing\n";
            $has_valid_server = false;
            foreach ( $new_input['servers'] as $server ) {
                $host = $server['host'];
                $port = $server['port'];
                if ( strpos( $host, '/' ) === 0 ) {
                    // Check if socket exists before adding
                    if (file_exists($host)) {
                        $upstream_block .= "    server unix:" . $host . ";\n";
                        $has_valid_server = true;
                    } else {
                         if ($new_input['debug']) error_log("MFPC Nginx Gen: Skipping non-existent socket for upstream: " . $host);
                    }
                } elseif ( ctype_digit( $port ) && $port > 0 ) {
                    $upstream_block .= "    server " . $host . ":" . $port . ";\n";
                    $has_valid_server = true;
                } else {
                    if ($new_input['debug']) error_log("MFPC Nginx Gen: Skipping invalid server entry for upstream: Host={$host}, Port={$port}");
                }
            }
            // Add a fallback if no valid servers were found, to prevent Nginx errors
            if (!$has_valid_server) {
                 $upstream_block .= "    server 127.0.0.1:11211; # Fallback - no valid servers configured\n";
                 if ($new_input['debug']) error_log("MFPC Nginx Gen: No valid servers found, adding fallback 127.0.0.1:11211 to upstream block.");
            }

            $upstream_block .= "}\n";

            $write_upstream_result = file_put_contents( MFPC_NGINX_UPSTREAM_FILE_PATH, $upstream_block );

            $nginx_config_content = $template_content;
            $write_nginx_result = file_put_contents( MFPC_NGINX_OUTPUT_FILE_PATH, $nginx_config_content );

            if ( $write_nginx_result === false || $write_upstream_result === false ) {
                $error_msg = sprintf( __( 'Error: Could not write Nginx configuration files. Check permissions for %s and %s.', 'mfpc-config' ), MFPC_NGINX_OUTPUT_FILE_PATH, MFPC_NGINX_UPSTREAM_FILE_PATH );
                add_settings_error( MFPC_OPTION_NAME, 'nginx_config_write_error', $error_msg, 'error' );
                set_transient('mfpc_nginx_config_error', $error_msg, 60);
            } else {
                delete_transient('mfpc_nginx_config_error');
                set_transient('mfpc_nginx_config_success', sprintf( __( 'Nginx config files generated successfully.', 'mfpc-config' ) ), 60);
                $nginx_config_generated = true;
            }
        } else {
            $error_msg = sprintf( __( 'Error: Could not read Nginx template file from %s.', 'mfpc-config' ), MFPC_NGINX_TEMPLATE_FILE_PATH );
            add_settings_error( MFPC_OPTION_NAME, 'nginx_template_read_error', $error_msg, 'error' );
            set_transient('mfpc_nginx_config_error', $error_msg, 60);
        }
    } else {
        $error_msg = sprintf( __( 'Error: Nginx template file not found at %s.', 'mfpc-config' ), MFPC_NGINX_TEMPLATE_FILE_PATH );
        add_settings_error( MFPC_OPTION_NAME, 'nginx_template_missing_error', $error_msg, 'error' );
        set_transient('mfpc_nginx_config_error', $error_msg, 60);
    }

    if (!$nginx_config_generated) {
        delete_transient('mfpc_nginx_config_success');
    }

    return $new_input;
}

// --- Admin JavaScript ---

/**
 * Enqueue admin scripts.
 */
function mfpc_enqueue_admin_scripts( $hook_suffix ) {
    // Only load on our specific settings page
    if ( strpos($hook_suffix, 'mfpc-config') === false ) {
        return;
    }

    wp_enqueue_script( 'mfpc-admin-script', plugin_dir_url( __FILE__ ) . 'admin-script.js', array( 'jquery' ), '1.4.0', true ); // Increment version

    $script_data = array(
        'optionName' => MFPC_OPTION_NAME,
        'noCacheText' => __( 'No cache', 'mfpc-config' ),
        'ajax_url' => admin_url( 'admin-ajax.php' ), // Needed for potential future AJAX actions
        'nonce' => wp_create_nonce( 'mfpc_admin_nonce' ) // Nonce for security
    );
    wp_localize_script( 'mfpc-admin-script', 'mfpcConfigData', $script_data );

    // Add inline style for status and template
    $custom_css = "
        .mfpc-hidden-template { display: none !important; }
        .mfpc-server-status.status-ok { color: #228B22; font-weight: bold; } /* ForestGreen */
        .mfpc-server-status.status-error { color: #DC143C; font-weight: bold; } /* Crimson */
        .mfpc-server-status.status-unknown { color: #777; font-style: italic; }
    ";
    wp_add_inline_style('wp-admin', $custom_css); // Attach to a common admin handle
}
\add_action( 'admin_enqueue_scripts', __NAMESPACE__ . '\mfpc_enqueue_admin_scripts' );


// --- Server Status Check ---

/**
 * Checks the connection status of a single Memcached server.
 *
 * @param array $server Server details ('host', 'port').
 * @return array Contains 'message' (string) and 'class' (string) for status display.
 */
function mfpc_check_server_status( $server ) {
    if ( ! class_exists('\Memcached') ) {
        return ['message' => __( 'Memcached PECL extension not loaded.', 'mfpc-config' ), 'class' => 'status-error'];
    }

    if ( ! isset( $server['host'], $server['port'] ) ) {
         return ['message' => __( 'Invalid server config.', 'mfpc-config' ), 'class' => 'status-error'];
    }

    $host = $server['host'];
    $port = $server['port'];
    $is_socket = ( strpos( $host, '/' ) === 0 );
    $int_port = $is_socket ? 0 : intval( $port );

    // Basic validation before attempting connection
    if ( $is_socket && ! file_exists( $host ) ) {
        return ['message' => __( 'Socket file not found.', 'mfpc-config' ), 'class' => 'status-error'];
    }
    if ( ! $is_socket && ($int_port <= 0 || $int_port > 65535) ) {
        return ['message' => __( 'Invalid port.', 'mfpc-config' ), 'class' => 'status-error'];
    }

    $memcached = new \Memcached();
    // Set a short timeout (e.g., 100ms) to avoid blocking the page load for too long
    $memcached->setOption( \Memcached::OPT_CONNECT_TIMEOUT, 100 );
    // Important: Disable persistent connections for status checks by using a unique ID or new instance
    // $memcached = new Memcached('mfpc_status_check_' . uniqid()); // Alternative if needed

    $added = $memcached->addServer( $host, $int_port );

    if ( ! $added ) {
        // addServer returning false usually means it couldn't resolve or immediately connect
        $memcached->quit(); // Ensure closed
        return ['message' => __( 'Failed (Add Server)', 'mfpc-config' ), 'class' => 'status-error'];
    }

    // Try a lightweight command like getStats()
    // getStats returns false on failure, or an array (even if empty for a non-existent server key) on success.
    $stats = @$memcached->getStats(); // Use @ to suppress potential connection warnings if server goes down between addServer and getStats

    $memcached->quit(); // Close the connection

    if ( $stats === false ) {
        // If getStats fails after addServer succeeded, it indicates a problem during communication
        return ['message' => __( 'Failed (Get Stats)', 'mfpc-config' ), 'class' => 'status-error'];
    } else {
        // If getStats returns an array (even empty), the connection was successful
        return ['message' => __( 'Connected', 'mfpc-config' ), 'class' => 'status-ok'];
    }
}


// --- Cache Purging Logic ---

/**
 * Helper function to get a Memcached connection (for purging).
 * Uses persistent connections for efficiency during purge operations.
 *
 * @param array $servers Array of server configurations.
 * @param bool $debug Enable debug logging.
 * @return Memcached|null Memcached object on success, null on failure.
 */
function mfpc_get_memcached_connection( $servers, $debug = false ) {
    if ( ! class_exists('\Memcached') ) {
        if ($debug) error_log("MFPC Purge: Memcached class not found.");
        return null;
    }

    // Use a persistent ID based on server list to potentially reuse connections
    $persistent_id = 'mfpc_purge_' . md5(serialize($servers));
    $memcached = new \Memcached($persistent_id);

    // Check if servers are already added for this persistent connection
    if (count($memcached->getServerList()) > 0) {
         // Optionally verify server statuses here if needed, but can add overhead
         // $stats = $memcached->getStats();
         // if (empty($stats)) { /* Handle potentially dead persistent connection */ }
         if ($debug) error_log("MFPC Purge: Reusing persistent Memcached connection (ID: {$persistent_id}).");
         return $memcached;
    }

    // Set options before adding servers for persistent connections
    $memcached->setOption( \Memcached::OPT_COMPRESSION, false );
    $memcached->setOption( \Memcached::OPT_BUFFER_WRITES, true );
    $memcached->setOption( \Memcached::OPT_BINARY_PROTOCOL, true );
    $memcached->setOption( \Memcached::OPT_TCP_NODELAY, true);
    $memcached->setOption( \Memcached::OPT_CONNECT_TIMEOUT, 100); // ms
    $memcached->setOption( \Memcached::OPT_POLL_TIMEOUT, 100); // ms
    $memcached->setOption( \Memcached::OPT_RETRY_TIMEOUT, 1); // seconds

    $servers_to_add = [];
    if ( empty( $servers ) ) {
         if ($debug) error_log("MFPC Purge: No Memcached servers configured.");
         return null;
    }

    foreach ( $servers as $server ) {
        if ( isset($server['host'], $server['port']) ) {
            $host = $server['host'];
            $port = $server['port'];
            $is_socket = (strpos($host, '/') === 0);
            $int_port = $is_socket ? 0 : intval($port);

            if ( $is_socket ) {
                if (file_exists($host)) {
                    $servers_to_add[] = [$host, 0];
                } else {
                     if ($debug) error_log("MFPC Purge: Socket file not found: " . $host);
                }
            } elseif ($int_port > 0 && $int_port <= 65535) {
                $servers_to_add[] = [$host, $int_port];
            } else {
                 if ($debug) error_log("MFPC Purge: Invalid port for server: " . $host . ":" . $port);
            }
        }
    }

    if (!empty($servers_to_add)) {
        if ($memcached->addServers($servers_to_add)) {
             if ($debug) error_log("MFPC Purge: Added servers to Memcached connection (ID: {$persistent_id}): " . print_r($servers_to_add, true));
             // Optional: Verify connection after adding servers
             // $stats = $memcached->getStats();
             // if (empty($stats)) { /* Handle connection failure */ }
             return $memcached;
        } else {
             if ($debug) error_log("MFPC Purge: Failed to add servers using addServers() (ID: {$persistent_id}).");
             return null; // Failed to add servers
        }
    } else {
        if ($debug) error_log("MFPC Purge: No valid servers could be added to the connection (ID: {$persistent_id}).");
        return null; // No valid servers found
    }
}

/**
 * Get stats from Memcached.
 *
 * @return array Stats data.
 */
function mfpc_get_site_stats() {
    $stats = [
        'hits' => 0,
        'misses' => 0,
        'ratio' => 0,
        'server_stats' => []
    ];

    $options = mfpc_get_options();
    $memcached = mfpc_get_memcached_connection( $options['servers'] );

    if ( $memcached ) {
        // Use the same prefix logic as index-cached.php
        $host = $_SERVER['HTTP_HOST'] ?? parse_url( home_url(), PHP_URL_HOST );
        $prefix = "mfpc:stats:{$host}:";

        $stats['hits'] = (int) $memcached->get( $prefix . 'hits' );
        $stats['misses'] = (int) $memcached->get( $prefix . 'misses' );

        $total = $stats['hits'] + $stats['misses'];
        if ( $total > 0 ) {
            $stats['ratio'] = round( ( $stats['hits'] / $total ) * 100, 2 );
        }

        $stats['server_stats'] = $memcached->getStats();
    }

    return $stats;
}

// --- WP-CLI Integration ---
if ( defined( 'WP_CLI' ) && WP_CLI ) {
    require_once plugin_dir_path( __FILE__ ) . 'includes/class-cli.php';
}

// --- Health Check Integration ---
require_once plugin_dir_path( __FILE__ ) . 'includes/class-health-check.php';
$mfpc_health_check = new Health_Check();
$mfpc_health_check->init();

/**
 * Central function to perform the actual cache purge for given keys.
 *
 * @param array $keys_to_purge Array of cache keys to delete.
 * @param array $options Plugin options array.
 * @param string $context Informational context for logging (e.g., 'save', 'delete', 'status_change').
 */
function mfpc_perform_purge( $keys_to_purge, $options, $context = 'unknown' ) {
    if ( empty( $keys_to_purge ) ) {
        return;
    }

    $debug_mode = ! empty( $options['debug'] );
    $memcached = mfpc_get_memcached_connection( $options['servers'], $debug_mode );

    if ( ! $memcached ) {
        set_transient('mfpc_purge_error', __( 'Could not connect to Memcached server(s) to purge cache.', 'mfpc-config' ), 60);
        if ($debug_mode) error_log("MFPC Purge ({$context}): Failed to get Memcached connection.");
        return;
    }

    foreach ( $keys_to_purge as $key ) {
        if (empty($key)) continue;

        $deleted = $memcached->delete( $key );
        $res_code = $memcached->getResultCode();

        if ($debug_mode) {
            $delete_msg = $deleted ? 'successfully purged' : ($res_code === Memcached::RES_NOTFOUND ? 'key not found' : 'failed to purge');
            error_log("MFPC Purge ({$context}): Attempting Key '{$key}': {$delete_msg}.");
            if (!$deleted && $res_code !== Memcached::RES_NOTFOUND) {
                 error_log("MFPC Purge ({$context}): Memcached error deleting key '{$key}'. Code: " . $res_code . " (" . $memcached->getResultMessage() . ")");
            }
        }
    }
    
    // Store purged keys in transient for admin display (debugging)
    set_transient('mfpc_last_purge_keys', implode("\n", $keys_to_purge), 300);
    
    // No need to quit() persistent connections.
}

/**
 * Flushes the entire Memcached cache.
 */
function mfpc_purge_all_cache() {
    $options = mfpc_get_options();
    $memcached = mfpc_get_memcached_connection( $options['servers'], $options['debug'] );

    if ( $memcached ) {
        $memcached->flush();
        if ( $options['debug'] ) {
            error_log( "MFPC: All cache flushed via admin dashboard." );
        }
    }
}

/**
 * Get cache keys associated with a post (post URL and homepage).
 *
 * @param int|WP_Post $post_id_or_object Post ID or object.
 * @param bool $debug_mode Enable debug logging.
 * @return array Array of cache keys.
 */
function mfpc_get_purge_keys_for_post( $post_id_or_object, $debug_mode = false ) {
    $keys = [];
    $post = get_post($post_id_or_object);

    if (!$post) {
        if ($debug_mode) error_log("MFPC Purge Keys: Could not get post object for ID/Object provided.");
        return $keys;
    }

    $post_url = get_permalink( $post->ID );
    $home_url = home_url( '/' );

    // Helper to extract host and path safely, defaulting path to '/' if missing
    $get_host_path = function($url) {
        $parts = parse_url($url);
        if (!$parts || !isset($parts['host'])) return false;
        
        $host = $parts['host'];
        // Append port only if it is set and NOT a standard port (80 or 443)
        if ( isset( $parts['port'] ) && $parts['port'] != 80 && $parts['port'] != 443 ) {
            $host .= ':' . $parts['port'];
        }

        $path = isset($parts['path']) ? $parts['path'] : '/';
        if ( isset( $parts['query'] ) ) {
            $path .= '?' . $parts['query'];
        }
        return ['host' => $host, 'path' => $path, 'query' => isset($parts['query']) ? '?' . $parts['query'] : ''];
    };

    $post_parts = $get_host_path($post_url);
    $home_parts = $get_host_path($home_url);

    if ( $post_parts ) {
        $p_host = $post_parts['host'];
        $p_path = $post_parts['path']; // Includes query if present in original logic, but we separated it above.
        // Re-construct path with query for the exact match
        $p_full_path = $p_path . $post_parts['query'];

        $keys[] = "fullpage:{$p_host}{$p_full_path}";
        // Also purge feed for the post (comments feed)
        $keys[] = "fullpage:{$p_host}{$p_full_path}feed/";

        // --- Add Alternative Slash Version ---
        // If path is /foo/, add /foo. If path is /foo, add /foo/.
        // We only do this logic on the path component, then re-append query.
        $alt_path = ( substr($p_path, -1) === '/' ) ? rtrim($p_path, '/') : $p_path . '/';
        
        // Ensure we don't create an empty path (if root was '/') or duplicate
        if ( $alt_path !== '' && $alt_path !== $p_path ) {
             $keys[] = "fullpage:{$p_host}{$alt_path}{$post_parts['query']}";
             $keys[] = "fullpage:{$p_host}{$alt_path}{$post_parts['query']}feed/";
        }
    } else {
        if ($debug_mode) error_log("MFPC Purge Keys: Could not parse Post URL: {$post_url}");
    }

    if ( $home_parts ) {
        $h_full_path = $home_parts['path'] . $home_parts['query'];
        $keys[] = "fullpage:{$home_parts['host']}{$h_full_path}";
        $keys[] = "fullpage:{$home_parts['host']}{$h_full_path}feed/";
        $keys[] = "fullpage:{$home_parts['host']}{$h_full_path}rss/";
        $keys[] = "fullpage:{$home_parts['host']}{$h_full_path}atom/";
    } else {
        if ($debug_mode) error_log("MFPC Purge Keys: Could not parse Home URL: {$home_url}");
    }

    // --- Add Archive Pages for All Taxonomies (Categories, Tags, etc.) ---
    $taxonomies = get_object_taxonomies( $post->post_type );
    if ( ! empty( $taxonomies ) ) {
        foreach ( $taxonomies as $taxonomy ) {
            $terms = get_the_terms( $post->ID, $taxonomy );
            if ( $terms && ! is_wp_error( $terms ) ) {
                foreach ( $terms as $term ) {
                    $term_link = get_term_link( $term );
                    if ( ! is_wp_error( $term_link ) ) {
                        $term_parts = $get_host_path( $term_link );
                        if ( $term_parts ) {
                            $t_full_path = $term_parts['path'] . $term_parts['query'];
                            $keys[] = "fullpage:{$term_parts['host']}{$t_full_path}";
                            $keys[] = "fullpage:{$term_parts['host']}{$t_full_path}feed/";
                            
                            $t_alt_path = ( substr($term_parts['path'], -1) === '/' ) ? rtrim($term_parts['path'], '/') : $term_parts['path'] . '/';
                            if ( $t_alt_path !== '' && $t_alt_path !== $term_parts['path'] ) {
                                $keys[] = "fullpage:{$term_parts['host']}{$t_alt_path}{$term_parts['query']}";
                            }
                        }
                    }
                }
            }
        }
    }

    // --- Add Author Archive ---
    if ( isset($post->post_author) ) {
        $author_link = get_author_posts_url( $post->post_author );
        if ( $author_link ) {
            $author_parts = $get_host_path( $author_link );
            if ( $author_parts ) {
                $a_full_path = $author_parts['path'] . $author_parts['query'];
                $keys[] = "fullpage:{$author_parts['host']}{$a_full_path}";
                $keys[] = "fullpage:{$author_parts['host']}{$a_full_path}feed/";
                
                $a_alt_path = ( substr($author_parts['path'], -1) === '/' ) ? rtrim($author_parts['path'], '/') : $author_parts['path'] . '/';
                if ( $a_alt_path !== '' && $a_alt_path !== $author_parts['path'] ) {
                    $keys[] = "fullpage:{$author_parts['host']}{$a_alt_path}{$author_parts['query']}";
                }
            }
        }
    }

    // Remove duplicates just in case post is the homepage
    $keys = array_unique($keys);

    if ($debug_mode) {
        error_log("MFPC Purge Keys: Generated keys: " . implode(', ', $keys));
    }

    return $keys;
}


/**
 * Purge cache for a specific post and the homepage on save/update.
 *
 * @param int     $post_id Post ID.
 * @param WP_Post $post    Post object.
 */
function mfpc_purge_post_on_save( $post_id, $post ) {
    $options = mfpc_get_options();
    if ( empty( $options['purge_on_save'] ) ) {
        return;
    }

    // Only purge for published posts on save
    $purgeable_post_types = apply_filters('mfpc_purgeable_post_types', ['post', 'page']);
    if ( ! in_array( $post->post_type, $purgeable_post_types ) || $post->post_status !== 'publish' ) {
        return;
    }

    // Ignore autosaves and revisions
    if ( wp_is_post_autosave( $post_id ) || wp_is_post_revision( $post_id ) ) {
        return;
    }

    $keys_to_purge = mfpc_get_purge_keys_for_post( $post, $options['debug'] );
    mfpc_perform_purge( $keys_to_purge, $options, 'save' );
}
\add_action( 'save_post', __NAMESPACE__ . '\mfpc_purge_post_on_save', 99, 2 );


/**
 * Purge cache when a post status transitions away from 'publish'.
 *
 * @param string  $new_status New post status.
 * @param string  $old_status Old post status.
 * @param WP_Post $post       Post object.
 */
function mfpc_purge_post_on_status_transition( $new_status, $old_status, $post ) {
    $options = mfpc_get_options();
    if ( empty( $options['purge_on_save'] ) ) {
        return;
    }

    // Purge if:
    // 1. Post was published and is now something else (Unpublish)
    // 2. Post was not published and is now published (Publish/New Post)
    if ( ! ( ( $old_status === 'publish' && $new_status !== 'publish' ) || ( $old_status !== 'publish' && $new_status === 'publish' ) ) ) {
        return;
    }

    $purgeable_post_types = apply_filters('mfpc_purgeable_post_types', ['post', 'page']);
    if ( ! in_array( $post->post_type, $purgeable_post_types ) ) {
        return;
    }

    $keys_to_purge = mfpc_get_purge_keys_for_post( $post, $options['debug'] );
    mfpc_perform_purge( $keys_to_purge, $options, 'status_change' );
}
\add_action( 'transition_post_status', __NAMESPACE__ . '\mfpc_purge_post_on_status_transition', 10, 3 );


/**
 * Purge cache when a post is deleted.
 * Note: This runs *before* the post is deleted from the DB.
 *
 * @param int $post_id Post ID.
 */
function mfpc_purge_post_on_delete( $post_id ) {
    $options = mfpc_get_options();
    if ( empty( $options['purge_on_save'] ) ) {
        return;
    }

    $post = get_post( $post_id );
    if ( ! $post ) {
        return; // Post already gone or invalid ID
    }

    $purgeable_post_types = apply_filters('mfpc_purgeable_post_types', ['post', 'page']);
    if ( ! in_array( $post->post_type, $purgeable_post_types ) ) {
        return;
    }

    // We only need to purge if the post *was* published before deletion
    if ( $post->post_status !== 'publish' ) {
        // If it was trashed first, transition_post_status would have handled it.
        // If it was draft/pending, it likely wasn't cached anyway.
        return;
    }

    $keys_to_purge = mfpc_get_purge_keys_for_post( $post, $options['debug'] );
    mfpc_perform_purge( $keys_to_purge, $options, 'delete' );
}
\add_action( 'delete_post', __NAMESPACE__ . '\mfpc_purge_post_on_delete', 10, 1 );


// --- Bulk & Row Actions ---

/**
 * Register "Purge Cache" bulk action.
 */
function mfpc_register_bulk_action( $bulk_actions ) {
    $bulk_actions['mfpc_purge'] = __( 'Purge Cache', 'mfpc-config' );
    return $bulk_actions;
}
add_filter( 'bulk_actions-edit-post', __NAMESPACE__ . '\mfpc_register_bulk_action' );
add_filter( 'bulk_actions-edit-page', __NAMESPACE__ . '\mfpc_register_bulk_action' );

/**
 * Handle "Purge Cache" bulk action.
 */
function mfpc_handle_bulk_action( $redirect_to, $doaction, $post_ids ) {
    if ( $doaction !== 'mfpc_purge' ) {
        return $redirect_to;
    }

    $options = mfpc_get_options();
    $count = 0;
    foreach ( $post_ids as $post_id ) {
        $post = get_post( $post_id );
        if ( $post ) {
            $keys = mfpc_get_purge_keys_for_post( $post, $options['debug'] );
            mfpc_perform_purge( $keys, $options, 'bulk_purge' );
            $count++;
        }
    }

    return add_query_arg( 'mfpc_bulk_purged', $count, $redirect_to );
}
add_filter( 'handle_bulk_actions-edit-post', __NAMESPACE__ . '\mfpc_handle_bulk_action', 10, 3 );
add_filter( 'handle_bulk_actions-edit-page', __NAMESPACE__ . '\mfpc_handle_bulk_action', 10, 3 );

/**
 * Display admin notice for bulk purge.
 */
function mfpc_bulk_admin_notices() {
    if ( ! empty( $_REQUEST['mfpc_bulk_purged'] ) ) {
        $count = intval( $_REQUEST['mfpc_bulk_purged'] );
        printf( '<div id="message" class="updated notice is-dismissible"><p>' .
            _n( '%s post cache purged.', '%s posts cache purged.', $count, 'mfpc-config' ) .
            '</p></div>', $count );
    }
}
add_action( 'admin_notices', __NAMESPACE__ . '\mfpc_bulk_admin_notices' );

/**
 * Add "Purge" link to row actions.
 */
function mfpc_add_row_action( $actions, $post ) {
    if ( ! current_user_can( 'edit_post', $post->ID ) ) {
        return $actions;
    }

    $url = wp_nonce_url(
        admin_url( 'admin.php?action=mfpc_purge_post&post_id=' . $post->ID ),
        'mfpc_purge_post_' . $post->ID
    );

    $actions['mfpc_purge'] = '<a href="' . esc_url( $url ) . '">' . __( 'Purge', 'mfpc-config' ) . '</a>';
    return $actions;
}
add_filter( 'post_row_actions', __NAMESPACE__ . '\mfpc_add_row_action', 10, 2 );
add_filter( 'page_row_actions', __NAMESPACE__ . '\mfpc_add_row_action', 10, 2 );

/**
 * Handle single post purge via row action.
 */
function mfpc_handle_row_action_purge() {
    $post_id = isset( $_GET['post_id'] ) ? intval( $_GET['post_id'] ) : 0;
    check_admin_referer( 'mfpc_purge_post_' . $post_id );

    if ( ! current_user_can( 'edit_post', $post_id ) ) {
        wp_die( __( 'You do not have permission to purge this post.', 'mfpc-config' ) );
    }

    $options = mfpc_get_options();
    $post = get_post( $post_id );
    if ( $post ) {
        $keys = mfpc_get_purge_keys_for_post( $post, $options['debug'] );
        mfpc_perform_purge( $keys, $options, 'row_action_purge' );
    }

    $redirect_url = remove_query_arg( ['action', 'post_id', '_wpnonce'], wp_get_referer() );
    $redirect_url = add_query_arg( 'mfpc_bulk_purged', 1, $redirect_url );
    wp_redirect( $redirect_url );
    exit;
}
add_action( 'admin_action_mfpc_purge_post', __NAMESPACE__ . '\mfpc_handle_row_action_purge' );

// --- Uninstall Hook ---
/**
 * Clean up on plugin uninstallation.
 */
function mfpc_uninstall() {
    delete_option( MFPC_OPTION_NAME );

    // Attempt to delete config files
    if ( file_exists( MFPC_PHP_CONFIG_FILE_PATH ) ) {
        // Check write permissions before attempting unlink
        if ( is_writable( MFPC_PHP_CONFIG_FILE_PATH ) ) {
            @unlink( MFPC_PHP_CONFIG_FILE_PATH );
        } else {
            // Optionally log an error if permissions prevent deletion
            error_log("MFPC Uninstall: Could not delete PHP config file due to permissions: " . MFPC_PHP_CONFIG_FILE_PATH);
        }
    }
    if ( file_exists( MFPC_NGINX_OUTPUT_FILE_PATH ) ) {
         if ( is_writable( MFPC_NGINX_OUTPUT_FILE_PATH ) ) {
            @unlink( MFPC_NGINX_OUTPUT_FILE_PATH );
        } else {
             error_log("MFPC Uninstall: Could not delete Nginx config file due to permissions: " . MFPC_NGINX_OUTPUT_FILE_PATH);
        }
    }
    if ( file_exists( MFPC_NGINX_UPSTREAM_FILE_PATH ) ) {
         if ( is_writable( MFPC_NGINX_UPSTREAM_FILE_PATH ) ) {
            @unlink( MFPC_NGINX_UPSTREAM_FILE_PATH );
        } else {
             error_log("MFPC Uninstall: Could not delete Nginx upstream config file due to permissions: " . MFPC_NGINX_UPSTREAM_FILE_PATH);
        }
    }

    // Clean up transients
    delete_transient('mfpc_php_config_error');
    delete_transient('mfpc_nginx_config_error');
    delete_transient('mfpc_php_config_success');
    delete_transient('mfpc_nginx_config_success');
    delete_transient('mfpc_purge_error');
}
register_uninstall_hook( __FILE__, 'mfpc_uninstall' );

?>
