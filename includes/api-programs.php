<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
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
 *
 * Schedule is optional.
 *
 * If Schedule is enabled:
 *
 *     schedule_enabled = true
 *     schedule = schedule data
 *
 * If Schedule is not enabled:
 *
 *     schedule_enabled = false
 *     schedule = null
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
	 *
	 * Schedule enabled/disabled does NOT affect whether
	 * the product is included here.
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
		 * --------------------------------------------------------
		 * Schedule Enabled
		 * --------------------------------------------------------
		 *
		 * Schedule is optional for a Program.
		 */
		$schedule_enabled = (
			'yes' === get_post_meta(
				$product_id,
				'_kirin_training_enabled',
				true
			)
		);


		/*
		 * --------------------------------------------------------
		 * Basic Product Information
		 * --------------------------------------------------------
		 */
		$program = array_merge(
			kirin_api_product_summary( $product ),
			array(
				'schedule_enabled' => $schedule_enabled,
				'schedule'         => null,
			)
		);


		/*
		 * --------------------------------------------------------
		 * Schedule
		 * --------------------------------------------------------
		 *
		 * Only load schedule information when scheduling
		 * is enabled for the product.
		 */
		if ( $schedule_enabled ) {

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


			/*
			 * Build schedule response.
			 */
			$program['schedule'] = array(
				'start_date' => $start_date,
				'end_date'   => $end_date,
				'location'   => $location,
				'days'       => array_values( $days ),
				'age_groups' => $age_groups,
			);

		}


		/*
		 * --------------------------------------------------------
		 * Add Program
		 * --------------------------------------------------------
		 */
		$programs[] = $program;

	}


	/*
	 * ------------------------------------------------------------
	 * Return API Response
	 * ------------------------------------------------------------
	 */
	return rest_ensure_response(
		array(
			'count'    => count( $programs ),
			'programs' => $programs,
		)
	);

}
