<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}


/**
 * ============================================================
 * APP
 * GET /wp-json/kirin/v1/app
 * ============================================================
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
