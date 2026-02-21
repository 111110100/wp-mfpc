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

        $memcached = mfpc_get_memcached_connection( $servers );

        if ( ! $memcached ) {
            WP_CLI::error( 'Could not connect to Memcached. Check configuration and ensure the PECL extension is loaded.' );
            return;
        }

        $all_stats = $memcached->getStats();
        $memcached->quit();

        if ( $all_stats === false ) {
            WP_CLI::error( 'Failed to retrieve stats from Memcached. All configured servers may be down.' );
            return;
        }

        $items = [];
        foreach ( $servers as $server_config ) {
            $host        = $server_config['host'];
            $port        = $server_config['port'];
            // The key returned by getStats() for a socket is 'path:0'.
            $server_key  = ( strpos( $host, '/' ) === 0 ) ? $host . ':0' : "{$host}:{$port}";
            $display_key = ( strpos( $host, '/' ) === 0 ) ? $host : "{$host}:{$port}";

            if ( isset( $all_stats[ $server_key ] ) && is_array( $all_stats[ $server_key ] ) ) {
                $server_stats = $all_stats[ $server_key ];
                $items[]      = [
                    'Server'      => $display_key,
                    'Status'      => 'Connected',
                    'Uptime'      => mfpc_seconds_to_human_time( $server_stats['uptime'] ?? 0 ),
                    'Items'       => number_format_i18n( $server_stats['curr_items'] ?? 0 ),
                    'Bytes Used'  => size_format( $server_stats['bytes'] ?? 0 ),
                    'Connections' => number_format_i18n( $server_stats['curr_connections'] ?? 0 ),
                ];
            } else {
                // Server is configured but not in stats, so it's down.
                $items[] = [
                    'Server'      => $display_key,
                    'Status'      => 'Failed to connect',
                    'Uptime'      => 'N/A',
                    'Items'       => 'N/A',
                    'Bytes Used'  => 'N/A',
                    'Connections' => 'N/A',
                ];
            }
        }

        if ( empty( $items ) ) {
            WP_CLI::warning( 'No server stats to display. This could happen if no servers are configured or none are reachable.' );
            return;
        }

        WP_CLI\Utils\format_items( 'table', $items, [ 'Server', 'Status', 'Uptime', 'Items', 'Bytes Used', 'Connections' ] );
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
     * Pre-caches (warms) posts and pages.
     *
     * ## OPTIONS
     *
     * <type>
     * : The type of warmup to perform (all, post, page, or number of recent posts).
     *
     * [<id>]
     * : The ID of the post or page to warm. Required if type is post or page.
     *
     * ## EXAMPLES
     *
     *     wp mfpc warmup all
     *     wp mfpc warmup post 123
     *     wp mfpc warmup 50
     *
     * @when after_wp_load
     */
    public function warmup( $args, $assoc_args ) {
        $options = mfpc_get_options();

        if ( empty( $args ) ) {
            $count = isset( $options['pre_cache_recent_count'] ) ? (int) $options['pre_cache_recent_count'] : 0;
            if ( $count > 0 ) {
                $args = [ $count ];
            } else {
                WP_CLI::error( 'Usage: wp mfpc warmup <all|post|page|count> [<id>]' );
            }
        }

        list( $type, $id ) = $args + [ null, null ];
        $urls = [];

        if ( 'all' === $type ) {
            WP_CLI::log( 'Fetching all published posts and pages...' );
            $query = new \WP_Query( [
                'post_type'      => [ 'post', 'page' ],
                'post_status'    => 'publish',
                'posts_per_page' => -1,
                'fields'         => 'ids',
            ] );
            foreach ( $query->posts as $p_id ) {
                $urls[] = get_permalink( $p_id );
            }
            $urls[] = home_url( '/' );
            $urls   = array_unique( $urls );
        } elseif ( in_array( $type, [ 'post', 'page' ], true ) ) {
            if ( empty( $id ) || ! is_numeric( $id ) ) {
                WP_CLI::error( "Invalid ID. Usage: wp mfpc warmup $type <id>" );
            }
            $post = get_post( $id );
            if ( ! $post ) {
                WP_CLI::error( "Post/Page with ID $id not found." );
            }
            $urls[] = get_permalink( $id );
        } elseif ( is_numeric( $type ) ) {
            $count = (int) $type;
            if ( $count <= 0 ) {
                WP_CLI::error( 'Count must be greater than 0.' );
            }
            WP_CLI::log( "Fetching {$count} recent items..." );
            $urls = mfpc_get_recent_urls( $count );
        } else {
            WP_CLI::error( 'Invalid type. Usage: wp mfpc warmup <all|post|page|count> [<id>]' );
        }

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
        WP_CLI::log( "  wp mfpc flush <all|post|page> [<id>]  Flush all cache or specific post/page." );
        WP_CLI::log( "  wp mfpc status                        Check status of Memcached servers." );
        WP_CLI::log( "  wp mfpc warmup [<count>]              Pre-cache recent posts/pages." );
        WP_CLI::log( "  wp mfpc warmup <all|post|page> [<id>] Pre-cache all or specific post/page." );
        WP_CLI::log( "  wp mfpc generate-nginx                Generate Nginx configuration file." );
        WP_CLI::log( "  wp mfpc help                          Display this help message." );
    }
}

WP_CLI::add_command( 'mfpc', 'MFPC\CLI' );
