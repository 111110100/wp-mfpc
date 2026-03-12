<?php
namespace MFPC;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Health_Check {

    public function init() {
        \add_filter( 'site_status_tests', [ $this, 'add_tests' ] );
    }

    public function add_tests( $tests ) {
        $tests['direct']['mfpc_memcached'] = [
            'label' => \__( 'Memcached Full Page Cache', 'memblaze-full-page-cache' ),
            'test'  => [ $this, 'test_memcached' ],
        ];
        return $tests;
    }

    public function test_memcached() {
        $result = [
            'label'       => \__( 'Memcached Connection', 'memblaze-full-page-cache' ),
            'status'      => 'good',
            'badge'       => [
                'label' => \__( 'Performance', 'memblaze-full-page-cache' ),
                'color' => 'blue',
            ],
            'description' => sprintf(
                '<p>%s</p>',
                \__( 'The Memcached servers are reachable.', 'memblaze-full-page-cache' )
            ),
            'actions'     => '',
            'test'        => 'mfpc_memcached',
        ];

        $options = mfpc_get_options();
        $servers = $options['servers'];

        if ( empty( $servers ) ) {
            $result['status'] = 'recommended';
            $result['description'] = sprintf(
                '<p>%s</p>',
                \__( 'No Memcached servers are configured.', 'memblaze-full-page-cache' )
            );
            return $result;
        }

        $failed_servers = [];
        foreach ( $servers as $server ) {
            $status = mfpc_check_server_status( $server );
            if ( $status['class'] !== 'status-ok' ) {
                $failed_servers[] = "{$server['host']}:{$server['port']} (" . $status['message'] . ")";
            }
        }

        if ( ! empty( $failed_servers ) ) {
            $result['status'] = 'critical';
            $result['description'] = sprintf(
                '<p>%s</p><ul><li>%s</li></ul>',
                \__( 'Some Memcached servers are not reachable:', 'memblaze-full-page-cache' ),
                implode( '</li><li>', $failed_servers )
            );
        }

        return $result;
    }
}
