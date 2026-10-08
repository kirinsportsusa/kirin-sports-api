<?php
/**
 * Plugin Name: Kirin Sports API
 * Description: API layer for Kirin Sports USA.
 * Version: 1.2.0
 * Author: Kirin Sports USA
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}


/**
 * Load API endpoint modules.
 */
require_once __DIR__ . '/includes/api-app.php';
require_once __DIR__ . '/includes/api-categories.php';
require_once __DIR__ . '/includes/api-products.php';
require_once __DIR__ . '/includes/api-programs.php';
require_once __DIR__ . '/includes/api-account.php';
require_once __DIR__ . '/includes/api-players.php';
require_once __DIR__ . '/includes/api-registrations.php';
require_once __DIR__ . '/includes/api-schedule.php';
require_once __DIR__ . '/includes/api-orders.php';
require_once __DIR__ . '/includes/api-news.php';
require_once __DIR__ . '/includes/api-teams.php';


/**
 * ============================================================
 * Kirin Sports API
 *
 * Namespace:
 * kirin/v1
 * ============================================================
 */


/**
 * Register API routes.
 */
add_action(
	'rest_api_init',
	function () {

		/*
		 * --------------------------------------------------------
		 * GET /wp-json/kirin/v1/app
		 * --------------------------------------------------------
		 */
		register_rest_route(
			'kirin/v1',
			'/app',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => 'kirin_api_app',
				'permission_callback' => '__return_true',
			)
		);


		/*
		 * --------------------------------------------------------
		 * GET /wp-json/kirin/v1/programs
		 * --------------------------------------------------------
		 */
		register_rest_route(
			'kirin/v1',
			'/programs',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => 'kirin_api_programs',
				'permission_callback' => '__return_true',
			)
		);


		/*
		 * --------------------------------------------------------
		 * GET /wp-json/kirin/v1/categories
		 * --------------------------------------------------------
		 */
		register_rest_route(
			'kirin/v1',
			'/categories',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => 'kirin_api_categories',
				'permission_callback' => '__return_true',
			)
		);


		/*
		 * --------------------------------------------------------
		 * GET /wp-json/kirin/v1/products
		 * GET /wp-json/kirin/v1/products/{id}
		 * --------------------------------------------------------
		 */
		register_rest_route(
			'kirin/v1',
			'/products',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => 'kirin_api_products',
				'permission_callback' => '__return_true',
				'args'                => array(
					'category' => array(
						'type'              => 'string',
						'sanitize_callback' => 'sanitize_title',
					),
				),
			)
		);


		register_rest_route(
			'kirin/v1',
			'/products/(?P<id>\d+)',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => 'kirin_api_product',
				'permission_callback' => '__return_true',
				'args'                => array(
					'id' => array(
						'sanitize_callback' => 'absint',
					),
				),
			)
		);


		/*
		 * --------------------------------------------------------
		 * GET /wp-json/kirin/v1/me
		 * --------------------------------------------------------
		 */
		register_rest_route(
			'kirin/v1',
			'/me',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => 'kirin_api_me',
				'permission_callback' => 'kirin_api_require_authenticated_user',
			)
		);


		/*
		 * --------------------------------------------------------
		 * GET /wp-json/kirin/v1/players
		 * --------------------------------------------------------
		 */
		register_rest_route(
			'kirin/v1',
			'/players',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => 'kirin_api_players',
				'permission_callback' => 'kirin_api_require_authenticated_user',
			)
		);


		/*
		 * --------------------------------------------------------
		 * GET /wp-json/kirin/v1/registrations
		 * --------------------------------------------------------
		 */
		register_rest_route(
			'kirin/v1',
			'/registrations',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => 'kirin_api_registrations',
				'permission_callback' => 'kirin_api_require_authenticated_user',
				'args'                => array(
					'page'     => array(
						'type'              => 'integer',
						'default'           => 1,
						'sanitize_callback' => 'absint',
					),
					'per_page' => array(
						'type'              => 'integer',
						'default'           => 20,
						'sanitize_callback' => 'absint',
					),
				),
			)
		);

	}
);
