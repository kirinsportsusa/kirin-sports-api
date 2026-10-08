<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}


/**
 * Return order statuses included in a customer's registration history.
 *
 * @return string[]
 */
function kirin_api_registration_order_statuses() {
	return array( 'processing', 'completed', 'on-hold', 'cancelled', 'refunded' );
}


/**
 * Format the allowlisted player summary stored on a registration item.
 *
 * @param mixed $players Player metadata value.
 * @return array
 */
function kirin_api_registration_player_summaries( $players ) {
	if ( ! is_array( $players ) ) {
		return array();
	}

	$summaries = array();

	foreach ( $players as $player ) {
		if ( ! is_array( $player ) ) {
			continue;
		}

		$birth_year = isset( $player['birth_year'] ) && is_scalar( $player['birth_year'] )
			? $player['birth_year']
			: '';

		$summaries[] = array(
			'first_name' => isset( $player['first_name'] ) && is_scalar( $player['first_name'] )
				? sanitize_text_field( (string) $player['first_name'] )
				: '',
			'last_name'  => isset( $player['last_name'] ) && is_scalar( $player['last_name'] )
				? sanitize_text_field( (string) $player['last_name'] )
				: '',
			'birth_year' => is_numeric( $birth_year ) ? (int) $birth_year : null,
		);
	}

	return $summaries;
}


/**
 * Return paginated registration occurrences for the authenticated customer.
 *
 * @param WP_REST_Request $request REST request.
 * @return WP_REST_Response|WP_Error
 */
function kirin_api_registrations( $request ) {
	$user_id = get_current_user_id();

	if ( $user_id <= 0 ) {
		return new WP_Error(
			'kirin_authentication_required',
			__( 'Authentication is required.', 'kirin-sports-api' ),
			array( 'status' => 401 )
		);
	}

	if ( ! function_exists( 'wc_get_orders' ) ) {
		return new WP_Error(
			'kirin_woocommerce_unavailable',
			__( 'WooCommerce is required to retrieve registrations.', 'kirin-sports-api' ),
			array( 'status' => 500 )
		);
	}

	$page     = max( 1, absint( $request->get_param( 'page' ) ) );
	$per_page = absint( $request->get_param( 'per_page' ) );

	if ( 0 === $per_page ) {
		$per_page = 20;
	}

	$per_page = min( 100, $per_page );
	$offset   = ( $page - 1 ) * $per_page;
	$batch    = 100;
	$total    = 0;
	$registrations = array();
	$order_page    = 1;
	$max_order_pages = 1;
	$statuses = kirin_api_registration_order_statuses();

	do {
		$order_result = wc_get_orders(
			array(
				'customer_id' => $user_id,
				'status'      => $statuses,
				'limit'       => $batch,
				'paged'       => $order_page,
				'paginate'    => true,
				'orderby'     => 'date',
				'order'       => 'DESC',
				'return'      => 'objects',
			)
		);

		if ( is_wp_error( $order_result ) || ! is_object( $order_result ) || ! isset( $order_result->orders ) || ! is_array( $order_result->orders ) ) {
			return new WP_Error(
				'kirin_registration_query_failed',
				__( 'Registrations could not be retrieved.', 'kirin-sports-api' ),
				array( 'status' => 500 )
			);
		}

		$max_order_pages = isset( $order_result->max_num_pages )
			? max( 1, absint( $order_result->max_num_pages ) )
			: 1;

		foreach ( $order_result->orders as $order ) {
			if (
				! is_object( $order )
				|| ! is_callable( array( $order, 'get_customer_id' ) )
				|| ! is_callable( array( $order, 'get_status' ) )
				|| ! is_callable( array( $order, 'get_items' ) )
				|| (int) $order->get_customer_id() !== (int) $user_id
				|| ! in_array( $order->get_status(), $statuses, true )
			) {
				continue;
			}

			$order_items = $order->get_items( 'line_item' );

			if ( ! is_array( $order_items ) ) {
				continue;
			}

			$order_date = $order->get_date_created();
			$created_at = $order_date && is_callable( array( $order_date, 'getTimestamp' ) )
				? gmdate( 'Y-m-d\TH:i:s\Z', $order_date->getTimestamp() )
				: null;

			foreach ( $order_items as $item ) {
				if (
					! is_object( $item )
					|| ! is_callable( array( $item, 'get_id' ) )
					|| ! is_callable( array( $item, 'get_product_id' ) )
					|| ! is_callable( array( $item, 'get_name' ) )
					|| ! is_callable( array( $item, 'get_quantity' ) )
					|| ! is_callable( array( $item, 'get_meta' ) )
				) {
					continue;
				}

				$product_id = (int) $item->get_product_id();
				$product    = is_callable( array( $item, 'get_product' ) ) ? $item->get_product() : false;

				if ( ! $product && $product_id && function_exists( 'wc_get_product' ) ) {
					$product = wc_get_product( $product_id );
				}

				$registration_required = (
					is_object( $product )
					&& is_callable( array( $product, 'get_meta' ) )
					&& 'yes' === $product->get_meta( '_kirin_registration_required', true )
				);

				$players_meta_exists = is_callable( array( $item, 'meta_exists' ) )
					? $item->meta_exists( '_kirin_training_players' )
					: is_array( $item->get_meta( '_kirin_training_players', true ) );
				$entry_meta_exists = is_callable( array( $item, 'meta_exists' ) )
					? $item->meta_exists( '_kirin_forminator_entry_id' )
					: '' !== $item->get_meta( '_kirin_forminator_entry_id', true );

				if ( ! $registration_required && ! $players_meta_exists && ! $entry_meta_exists ) {
					continue;
				}

				$total++;

				if ( $total <= $offset || $total > ( $offset + $per_page ) ) {
					continue;
				}

				$player_meta = $item->get_meta( '_kirin_training_players', true );

				$registrations[] = array(
					'id'           => (int) $item->get_id(),
					'product'      => array(
						'id'   => $product_id,
						'name' => sanitize_text_field( (string) $item->get_name() ),
					),
					'order_status' => sanitize_key( $order->get_status() ),
					'created_at'   => $created_at,
					'quantity'     => $item->get_quantity(),
					'players'      => kirin_api_registration_player_summaries( $player_meta ),
				);
			}
		}

		$order_page++;
	} while ( $order_page <= $max_order_pages );

	return rest_ensure_response(
		array(
			'count'         => count( $registrations ),
			'total'         => $total,
			'page'          => $page,
			'per_page'      => $per_page,
			'registrations' => $registrations,
		)
	);
}
