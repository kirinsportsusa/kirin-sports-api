<?php
/**
 * Plugin Name: Kirin Sports API
 * Description: API layer for Kirin Sports USA.
 * Version: 1.0.0
 * Author: Kirin Sports USA
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}


/**
 * Kirin Sports API
 *
 * Namespace:
 * kirin/v1
 */
add_action(
	'rest_api_init',
	function () {

		register_rest_route(
			'kirin/v1',
			'/app',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => 'kirin_api_app',
				'permission_callback' => '__return_true',
			)
		);

	}
);


/**
 * GET /wp-json/kirin/v1/app
 */
function kirin_api_app() {

	return rest_ensure_response(
		array(
			'name'        => 'Kirin Sports USA',
			'api_version' => '1',
			'website'     => home_url( '/' ),
			'features'    => array(
				'programs'      => true,
				'products'      => true,
				'store'         => true,
				'players'       => true,
				'registrations' => true,
				'schedule'      => true,
				'orders'        => true,
				'news'          => true,
				'teams'         => true,
			),
		)
	);

}