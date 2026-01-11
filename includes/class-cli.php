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
	 * ## OPTIONS
	 *
	 * <type>
	 * : The type of flush to perform (all, post, or page).
	 *
	 * [<id>]
	 * : The ID of the post or page to flush. Required if type is post or page.
	 *
	 * ## EXAMPLES
	 *
	 *     wp mfpc flush all
	 *     wp mfpc flush post 123
	 *     wp mfpc flush page 456
	 *
	 * @when after_wp_load
	 */
	public function flush( $args, $assoc_args ) {
		$options = mfpc_get_options();

		if ( empty( $args ) ) {
			WP_CLI::error( 'Usage: wp mfpc flush <all|post|page> [<id>]' );
		}

		list( $type, $id ) = $args + [ null, null ];

		if ( 'all' === $type ) {
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
		} elseif ( in_array( $type, [ 'post', 'page' ] ) ) {
			if ( empty( $id ) || ! is_numeric( $id ) ) {
				WP_CLI::error( "Invalid ID. Usage: wp mfpc flush $type <id>" );
			}

			$post = get_post( $id );
			if ( ! $post ) {
				WP_CLI::error( "Post/Page with ID $id not found." );
			}

			$keys = mfpc_get_purge_keys_for_post( $post, false );
			if ( empty( $keys ) ) {
				WP_CLI::warning( "No cache keys generated for $type $id." );
				return;
			}

			mfpc_perform_purge( $keys, $options, 'cli_purge' );

			if ( get_transient( 'mfpc_purge_error' ) ) {
				$error = get_transient( 'mfpc_purge_error' );
				delete_transient( 'mfpc_purge_error' );
				WP_CLI::error( $error );
			}

			WP_CLI::success( "Cache purged for $type $id." );
		} else {
			WP_CLI::error( 'Invalid type. Usage: wp mfpc flush <all|post|page> [<id>]' );
		}
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

    /**
     * Pre-caches (warms) the most recent posts and pages.
     *
     * ## OPTIONS
     *
     * [<count>]
     * : Number of recent posts/pages to warm. Defaults to plugin setting.
     *
     * ## EXAMPLES
     *
     *     wp mfpc warmup
     *     wp mfpc warmup 50
     *
     * @when after_wp_load
     */
    public function warmup( $args, $assoc_args ) {
        $options = mfpc_get_options();
        $count = isset($args[0]) ? (int)$args[0] : (isset($options['pre_cache_recent_count']) ? (int)$options['pre_cache_recent_count'] : 0);

        if ( $count <= 0 ) {
            WP_CLI::error( "Please specify a count or configure the 'Pre-cache Recent Posts' setting." );
        }

        WP_CLI::log( "Fetching {$count} recent items..." );
        
        $urls = mfpc_get_recent_urls( $count );
        
        if ( empty( $urls ) ) {
             WP_CLI::warning( "No URLs found to warm." );
             return;
        }
        
        WP_CLI::log( "Warming up " . count($urls) . " URLs..." );

        $memcached = mfpc_get_memcached_connection( $options['servers'] );
        
        foreach ( $urls as $url ) {
            // Use blocking request for CLI to ensure execution
            $response = wp_remote_get( $url, [ 'blocking' => true, 'sslverify' => false, 'timeout' => 30, 'user-agent' => 'MFPC-CLI-Warmup/1.0' ] );

            $post_id = url_to_postid( $url );
            $title = $post_id ? get_the_title( $post_id ) : ( $url === home_url( '/' ) ? 'Home' : 'Unknown' );
            
            if ( is_wp_error( $response ) ) {
                WP_CLI::warning( "Failed: $title ($url) - " . $response->get_error_message() );
                continue;
            }

            $code = wp_remote_retrieve_response_code( $response );
            if ( $code !== 200 ) {
                 WP_CLI::warning( "Failed: $title ($url) - HTTP $code" );
            }

            $status_msg = "Fetched";
            // Verify if cached
            if ( $memcached ) {
                $parts = parse_url( $url );
                $host = $parts['host'];
                if ( isset( $parts['port'] ) && ! in_array( $parts['port'], [ 80, 443 ] ) ) {
                    $host .= ':' . $parts['port'];
                }
                $path = isset( $parts['path'] ) ? $parts['path'] : '/';
                $query = isset( $parts['query'] ) ? '?' . $parts['query'] : '';
                $key = "fullpage:{$host}{$path}{$query}";

                if ( $memcached->get( $key ) !== false ) {
                    $status_msg .= " & Cached";
                } else {
                    $status_msg .= " (Miss/Not Cached)";
                }
            }

            WP_CLI::log( "Processed: $title ($url) - $status_msg" );
        }
        
        if ( $memcached ) {
            $memcached->quit();
        }
        
        WP_CLI::success( "Cache warmup complete." );
    }

    /**
     * Displays available commands.
     *
     * ## EXAMPLES
     *
     *     wp mfpc help
     *
     * @when after_wp_load
     */
    public function help() {
        WP_CLI::log( "Available commands:" );
        WP_CLI::log( "  wp mfpc flush <all|post|page> [<id>] Flush all cache or specific post/page." );
        WP_CLI::log( "  wp mfpc status                   Check status of Memcached servers." );
        WP_CLI::log( "  wp mfpc warmup [<count>]         Pre-cache recent posts/pages." );
        WP_CLI::log( "  wp mfpc generate-nginx           Generate Nginx configuration file." );
        WP_CLI::log( "  wp mfpc help                     Display this help message." );
    }
}

WP_CLI::add_command( 'mfpc', 'MFPC\CLI' );
