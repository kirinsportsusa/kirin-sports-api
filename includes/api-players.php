<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}


/** Customer player registrations endpoint. */


/**
 * Return the order statuses that make a registration eligible for /players.
 *
 * Keep this list in one place so the policy can be updated independently.
 *
 * @return string[]
 */
function kirin_api_player_eligible_order_statuses() {
	return array( 'processing', 'completed' );
}


/**
 * Return the authenticated customer's player registration occurrences.
 *
 * @return WP_REST_Response|WP_Error
 */
function kirin_api_players() {
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
			__( 'WooCommerce is required to retrieve player registrations.', 'kirin-sports-api' ),
			array( 'status' => 500 )
		);
	}

	$orders  = wc_get_orders(
		array(
			'customer_id' => $user_id,
			'status'      => kirin_api_player_eligible_order_statuses(),
			'limit'       => -1,
			'return'      => 'objects',
		)
	);
	$players = array();

	if ( is_array( $orders ) ) {
		$eligible_statuses = kirin_api_player_eligible_order_statuses();

		foreach ( $orders as $order ) {
			if (
				! is_object( $order )
				|| ! is_callable( array( $order, 'get_customer_id' ) )
				|| ! is_callable( array( $order, 'get_status' ) )
				|| ! is_callable( array( $order, 'get_items' ) )
				|| (int) $order->get_customer_id() !== (int) $user_id
				|| ! in_array( $order->get_status(), $eligible_statuses, true )
			) {
				continue;
			}

			foreach ( $order->get_items( 'line_item' ) as $item ) {
				if ( ! is_object( $item ) || ! is_callable( array( $item, 'get_meta' ) ) ) {
					continue;
				}

				$registered_players = $item->get_meta( '_kirin_training_players', true );

				if ( ! is_array( $registered_players ) ) {
					continue;
				}

				foreach ( $registered_players as $registered_player ) {
					if ( ! is_array( $registered_player ) ) {
						continue;
					}

					$birth_year = isset( $registered_player['birth_year'] ) && is_scalar( $registered_player['birth_year'] )
						? $registered_player['birth_year']
						: '';

					$players[] = array(
						'first_name' => isset( $registered_player['first_name'] ) && is_scalar( $registered_player['first_name'] )
							? sanitize_text_field( (string) $registered_player['first_name'] )
							: '',
						'last_name'  => isset( $registered_player['last_name'] ) && is_scalar( $registered_player['last_name'] )
							? sanitize_text_field( (string) $registered_player['last_name'] )
							: '',
						'birth_date' => isset( $registered_player['birth_date'] ) && is_scalar( $registered_player['birth_date'] )
							? sanitize_text_field( (string) $registered_player['birth_date'] )
							: '',
						'birth_year' => is_numeric( $birth_year ) ? (int) $birth_year : null,
						'gender'     => isset( $registered_player['gender'] ) && is_scalar( $registered_player['gender'] )
							? sanitize_text_field( (string) $registered_player['gender'] )
							: '',
					);
				}
			}
		}
	}

	return rest_ensure_response(
		array(
			'count'   => count( $players ),
			'players' => $players,
		)
	);
}
