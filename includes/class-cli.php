<?php
namespace MFPC;

use WP_CLI;
use WP_CLI_Command;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Manage Memcached Full Page Cache.
 */
class CLI extends WP_CLI_Command {

	/**
	 * Flushes the Memcached servers configured in the plugin.
	 *
	 * ## EXAMPLES
	 *
	 *     wp mfpc flush
	 *
	 * @when after_wp_load
	 */
	public function flush() {
		$options = mfpc_get_options();
		$servers = $options['servers'];

		if ( empty( $servers ) ) {
			WP_CLI::error( 'No Memcached servers configured.' );
		}

		$memcached = mfpc_get_memcached_connection( $servers );

		if ( ! $memcached ) {
			WP_CLI::error( 'Could not connect to Memcached servers.' );
		}

		if ( $memcached->flush() ) {
			WP_CLI::success( 'Memcached flushed successfully.' );
		} else {
			WP_CLI::error( 'Failed to flush Memcached.' );
		}
        $memcached->quit();
	}

    /**
     * Checks the status of configured Memcached servers.
     *
     * ## EXAMPLES
     *
     *     wp mfpc status
     *
     * @when after_wp_load
     */
    public function status() {
        $options = mfpc_get_options();
        $servers = $options['servers'];

        if ( empty( $servers ) ) {
            WP_CLI::error( 'No Memcached servers configured.' );
        }

        foreach ( $servers as $server ) {
            $status = mfpc_check_server_status( $server );
            if ( $status['class'] === 'status-ok' ) {
                WP_CLI::log( "Server {$server['host']}:{$server['port']} - OK" );
            } else {
                WP_CLI::warning( "Server {$server['host']}:{$server['port']} - " . $status['message'] );
            }
        }
    }

    /**
     * Generates the Nginx configuration file.
     *
     * ## EXAMPLES
     *
     *     wp mfpc generate-nginx
     *
     * @when after_wp_load
     */
    public function generate_nginx() {
        $options = mfpc_get_options();
        
        // Call sanitize to trigger generation. 
        // Note: This does NOT save to DB because we are not calling register_setting's save mechanism,
        // we are just invoking the callback which does the file writing.
        mfpc_sanitize_settings($options);
        
        if ( get_transient('mfpc_nginx_config_success') ) {
             WP_CLI::success( get_transient('mfpc_nginx_config_success') );
             delete_transient('mfpc_nginx_config_success');
        } elseif ( get_transient('mfpc_nginx_config_error') ) {
             WP_CLI::error( get_transient('mfpc_nginx_config_error') );
             delete_transient('mfpc_nginx_config_error');
        } else {
             WP_CLI::log( "Nginx config generation attempted." );
        }
    }
}

WP_CLI::add_command( 'mfpc', 'MFPC\CLI' );
