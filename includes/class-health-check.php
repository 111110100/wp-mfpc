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
			'label' => \__( 'Memcached Full Page Cache', 'mfpc-config' ),
			'test'  => [ $this, 'test_memcached' ],
		];
		return $tests;
	}

	public function test_memcached() {
		$result = [
			'label'       => \__( 'Memcached Connection', 'mfpc-config' ),
			'status'      => 'good',
			'badge'       => [
				'label' => \__( 'Performance', 'mfpc-config' ),
				'color' => 'blue',
			],
			'description' => sprintf(
				'<p>%s</p>',
				\__( 'The Memcached servers are reachable.', 'mfpc-config' )
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
				\__( 'No Memcached servers are configured.', 'mfpc-config' )
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
				\__( 'Some Memcached servers are not reachable:', 'mfpc-config' ),
				implode( '</li><li>', $failed_servers )
			);
		}

		return $result;
	}
}
