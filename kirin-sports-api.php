<?php
/**
 * Plugin Name: Kirin Sports API
 * Description: API layer for Kirin Sports USA.
 * Version: 1.1.0
 * Author: Kirin Sports USA
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}


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

	}
);


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


/**
 * ============================================================
 * PROGRAMS
 * GET /wp-json/kirin/v1/programs
 * ============================================================
 *
 * A Program is currently defined as:
 *
 * - A published WooCommerce product
 * - In the "Programs" product category
 * - With Kirin Schedule enabled
 *
 * The API reads the existing WooCommerce/Kirin data.
 * It does not create or modify any program data.
 */
function kirin_api_programs() {

	/*
	 * Make sure WooCommerce is available.
	 */
	if ( ! function_exists( 'wc_get_products' ) ) {

		return new WP_Error(
			'kirin_woocommerce_required',
			'WooCommerce is required for the programs API.',
			array(
				'status' => 500,
			)
		);

	}


	/*
	 * Get published products from the Programs category.
	 *
	 * "programs" is the WooCommerce product category slug.
	 */
	$products = wc_get_products(
		array(
			'status'   => 'publish',
			'limit'    => -1,
			'category' => array( 'programs' ),
			'orderby'  => 'menu_order',
			'order'    => 'ASC',
		)
	);


	$programs = array();


	foreach ( $products as $product ) {

		$product_id = $product->get_id();


		/*
		 * Only include products with Kirin Schedule enabled.
		 */
		$schedule_enabled = get_post_meta(
			$product_id,
			'_kirin_training_enabled',
			true
		);


		if ( 'yes' !== $schedule_enabled ) {
			continue;
		}


		/*
		 * Basic product information.
		 */
		$program = array(
			'id'                   => $product_id,
			'name'                 => $product->get_name(),
			'slug'                 => $product->get_slug(),
			'description'          => wp_kses_post(
				$product->get_description()
			),
			'short_description'    => wp_kses_post(
				$product->get_short_description()
			),
			'price'                => (float) $product->get_price(),
			'formatted_price'      => wp_strip_all_tags(
				wc_price( $product->get_price() )
			),
			'currency'             => get_woocommerce_currency(),
			'registration_required' => (
				'yes' === get_post_meta(
					$product_id,
					'_kirin_registration_required',
					true
				)
			),
			'schedule'             => array(),
		);


		/*
		 * --------------------------------------------------------
		 * Schedule
		 * --------------------------------------------------------
		 */

		$start_date = get_post_meta(
			$product_id,
			'_kirin_training_start_date',
			true
		);

		$end_date = get_post_meta(
			$product_id,
			'_kirin_training_end_date',
			true
		);

		$location = get_post_meta(
			$product_id,
			'_kirin_training_location',
			true
		);

		$days = get_post_meta(
			$product_id,
			'_kirin_training_days',
			true
		);

		$age_schedule = get_post_meta(
			$product_id,
			'_kirin_age_schedule',
			true
		);


		/*
		 * Normalize schedule days.
		 */
		if ( ! is_array( $days ) ) {

			if ( empty( $days ) ) {
				$days = array();
			} else {
				$days = array_map(
					'trim',
					preg_split(
						'/[,|]+/',
						(string) $days
					)
				);
			}

		}


		/*
		 * Normalize age-group schedule.
		 */
		if ( ! is_array( $age_schedule ) ) {
			$age_schedule = array();
		}


		$age_groups = array();


		foreach ( $age_schedule as $age_group ) {

			if ( ! is_array( $age_group ) ) {
				continue;
			}


			$birth_from = isset(
				$age_group['birth_from']
			)
				? $age_group['birth_from']
				: '';

			$birth_to = isset(
				$age_group['birth_to']
			)
				? $age_group['birth_to']
				: '';

			$start_time = isset(
				$age_group['start_time']
			)
				? $age_group['start_time']
				: '';

			$end_time = isset(
				$age_group['end_time']
			)
				? $age_group['end_time']
				: '';


			$age_groups[] = array(
				'birth_year_from' => (int) $birth_from,
				'birth_year_to'   => (int) $birth_to,
				'start_time'      => $start_time,
				'end_time'        => $end_time,
			);

		}


		$program['schedule'] = array(
			'start_date' => $start_date,
			'end_date'   => $end_date,
			'location'   => $location,
			'days'       => array_values( $days ),
			'age_groups' => $age_groups,
		);


		/*
		 * Add the program to the API response.
		 */
		$programs[] = $program;

	}


	return rest_ensure_response(
		array(
			'count'    => count( $programs ),
			'programs' => $programs,
		)
	);

}