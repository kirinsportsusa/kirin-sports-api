<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}


/**
 * Return the standard statuses included in customer order history.
 *
 * @return string[]
 */
function kirin_api_order_history_statuses() {
	return array( 'pending', 'processing', 'on-hold', 'completed', 'cancelled', 'refunded', 'failed' );
}


/**
 * Read a positive pagination value without silently correcting invalid input.
 *
 * @param WP_REST_Request $request   REST request.
 * @param string          $parameter Parameter name.
 * @param int             $default   Default value.
 * @param int|null        $maximum   Optional maximum value.
 * @return int|WP_Error
 */
function kirin_api_orders_pagination_value( $request, $parameter, $default, $maximum = null ) {
	$value = $request->get_param( $parameter );

	if ( null === $value ) {
		return $default;
	}

	$value = is_int( $value ) || is_string( $value )
		? filter_var( $value, FILTER_VALIDATE_INT )
		: false;

	if ( false === $value || $value < 1 || ( null !== $maximum && $value > $maximum ) ) {
		return new WP_Error(
			'kirin_orders_invalid_pagination',
			__( 'Pagination values must be positive integers and per_page cannot exceed 100.', 'kirin-sports-api' ),
			array( 'status' => 400 )
		);
	}

	return (int) $value;
}


/**
 * Resolve currency precision, falling back to WooCommerce's configured decimals.
 *
 * @param string $currency Stored order currency.
 * @return int
 */
function kirin_api_order_currency_decimals( $currency ) {
	static $decimals = array();

	if ( ! isset( $decimals[ $currency ] ) ) {
		$decimals[ $currency ] = wc_get_price_decimals();

		if ( class_exists( 'NumberFormatter' ) && preg_match( '/^[A-Z]{3}$/', $currency ) ) {
			$formatter = new NumberFormatter( 'en_US', NumberFormatter::CURRENCY );

			if ( $formatter->setTextAttribute( NumberFormatter::CURRENCY_CODE, $currency ) ) {
				$currency_decimals = $formatter->getAttribute( NumberFormatter::FRACTION_DIGITS );

				if ( false !== $currency_decimals && $currency_decimals >= 0 ) {
					$decimals[ $currency ] = (int) $currency_decimals;
				}
			}
		}
	}

	return $decimals[ $currency ];
}


/**
 * Format an existing WooCommerce amount without rounding away stored precision.
 *
 * @param string|float $amount   Existing order or item amount.
 * @param int          $decimals Currency decimal places.
 * @return string
 */
function kirin_api_order_money( $amount, $decimals ) {
	$amount = wc_format_decimal( $amount, false, true );
	$parts  = explode( '.', '' !== $amount ? $amount : '0', 2 );
	$digits = isset( $parts[1] ) ? $parts[1] : '';

	// Pad to currency precision, but retain any additional significant digits.
	$decimals = max( $decimals, strlen( $digits ) );

	return $parts[0] . ( $decimals > 0 ? '.' . str_pad( $digits, $decimals, '0' ) : '' );
}


/**
 * Recognize a registration using the same rules as the Phase 3 endpoint.
 *
 * Phase 3 has no separate recognition helper. Keep its metadata existence
 * checks and product fallback here without changing the registration endpoint.
 *
 * @param WC_Order_Item_Product $item          Purchased line item.
 * @param array                 $product_flags Product/variation flags cached for this page.
 * @return int|null
 */
function kirin_api_order_item_registration_id( $item, &$product_flags ) {
	$players_meta_exists = is_callable( array( $item, 'meta_exists' ) )
		? $item->meta_exists( '_kirin_training_players' )
		: is_array( $item->get_meta( '_kirin_training_players', true ) );
	$entry_meta_exists = is_callable( array( $item, 'meta_exists' ) )
		? $item->meta_exists( '_kirin_forminator_entry_id' )
		: '' !== $item->get_meta( '_kirin_forminator_entry_id', true );

	if ( $players_meta_exists || $entry_meta_exists ) {
		return (int) $item->get_id();
	}

	$product_id   = (int) $item->get_product_id();
	$variation_id = (int) $item->get_variation_id();
	$cache_key    = $product_id . ':' . $variation_id;

	if ( ! array_key_exists( $cache_key, $product_flags ) ) {
		$product = $item->get_product();

		if ( ! $product && $product_id && function_exists( 'wc_get_product' ) ) {
			$product = wc_get_product( $product_id );
		}

		$product_flags[ $cache_key ] = (
			is_object( $product )
			&& is_callable( array( $product, 'get_meta' ) )
			&& 'yes' === $product->get_meta( '_kirin_registration_required', true )
		);
	}

	return $product_flags[ $cache_key ] ? (int) $item->get_id() : null;
}


/**
 * Format the allowlisted summary of one customer-owned order.
 *
 * @param WC_Order $order         Customer-owned order.
 * @param array    $product_flags Product/variation flags cached for this page.
 * @return array
 */
function kirin_api_format_order( $order, &$product_flags ) {
	$currency   = $order->get_currency();
	$decimals   = kirin_api_order_currency_decimals( $currency );
	$order_date = $order->get_date_created();
	$fees       = array();
	$items      = array();
	$registration_allowed = in_array( $order->get_status(), kirin_api_registration_order_statuses(), true );

	foreach ( $order->get_fees() as $fee ) {
		$fees[] = array(
			'name'  => sanitize_text_field( (string) $fee->get_name() ),
			'total' => kirin_api_order_money( $fee->get_total(), $decimals ),
			'tax'   => kirin_api_order_money( $fee->get_total_tax(), $decimals ),
		);
	}

	foreach ( $order->get_items( 'line_item' ) as $item ) {
		$item_data = array(
			'product_id' => (int) $item->get_product_id(),
			'name'       => sanitize_text_field( (string) $item->get_name() ),
			'quantity'   => $item->get_quantity(),
			'subtotal'   => kirin_api_order_money( $item->get_subtotal(), $decimals ),
			'total'      => kirin_api_order_money( $item->get_total(), $decimals ),
			'total_tax'  => kirin_api_order_money( $item->get_total_tax(), $decimals ),
		);

		$registration_id = $registration_allowed
			? kirin_api_order_item_registration_id( $item, $product_flags )
			: null;

		if ( null !== $registration_id ) {
			$item_data['registration_id'] = $registration_id;
		}

		$items[] = $item_data;
	}

	return array(
		'id'           => (int) $order->get_id(),
		'number'       => (string) $order->get_order_number(),
		'date_created' => $order_date && is_callable( array( $order_date, 'getTimestamp' ) )
			? gmdate( 'Y-m-d\TH:i:s\Z', $order_date->getTimestamp() )
			: null,
		'status'       => sanitize_key( $order->get_status() ),
		'currency'     => sanitize_text_field( $currency ),
		'totals'       => array(
			'subtotal'       => kirin_api_order_money( $order->get_subtotal(), $decimals ),
			'discount_total' => kirin_api_order_money( $order->get_discount_total(), $decimals ),
			'shipping_total' => kirin_api_order_money( $order->get_shipping_total(), $decimals ),
			'tax_total'      => kirin_api_order_money( $order->get_total_tax(), $decimals ),
			'total'          => kirin_api_order_money( $order->get_total(), $decimals ),
			'refunded_total' => kirin_api_order_money( $order->get_total_refunded(), $decimals ),
		),
		'fees'         => $fees,
		'items'        => $items,
	);
}


/**
 * Return one page of purchase history for the authenticated customer.
 *
 * @param WP_REST_Request $request REST request.
 * @return WP_REST_Response|WP_Error
 */
function kirin_api_orders( $request ) {
	$user_id = get_current_user_id();

	if ( $user_id <= 0 ) {
		return new WP_Error(
			'kirin_authentication_required',
			__( 'Authentication is required.', 'kirin-sports-api' ),
			array( 'status' => 401 )
		);
	}

	if ( ! function_exists( 'wc_get_orders' ) || ! function_exists( 'wc_format_decimal' ) || ! function_exists( 'wc_get_price_decimals' ) ) {
		return new WP_Error(
			'kirin_woocommerce_unavailable',
			__( 'WooCommerce is required to retrieve orders.', 'kirin-sports-api' ),
			array( 'status' => 500 )
		);
	}

	$page = kirin_api_orders_pagination_value( $request, 'page', 1 );

	if ( is_wp_error( $page ) ) {
		return $page;
	}

	$per_page = kirin_api_orders_pagination_value( $request, 'per_page', 20, 100 );

	if ( is_wp_error( $per_page ) ) {
		return $per_page;
	}

	if ( $page > intdiv( PHP_INT_MAX, $per_page ) ) {
		return new WP_Error(
			'kirin_orders_invalid_pagination',
			__( 'The requested page is too large.', 'kirin-sports-api' ),
			array( 'status' => 400 )
		);
	}

	$statuses = kirin_api_order_history_statuses();
	$result   = wc_get_orders(
		array(
			'type'        => 'shop_order',
			'customer_id' => $user_id,
			'status'      => $statuses,
			'limit'       => $per_page,
			'paged'       => $page,
			'paginate'    => true,
			'orderby'     => 'date',
			'order'       => 'DESC',
			'return'      => 'objects',
		)
	);

	if ( is_wp_error( $result ) || ! is_object( $result ) || ! isset( $result->orders, $result->total ) || ! is_array( $result->orders ) ) {
		return new WP_Error(
			'kirin_order_query_failed',
			__( 'Orders could not be retrieved.', 'kirin-sports-api' ),
			array( 'status' => 500 )
		);
	}

	$orders        = array();
	$product_flags = array();

	foreach ( $result->orders as $order ) {
		if (
			! $order instanceof WC_Order
			|| (int) $order->get_customer_id() !== (int) $user_id
			|| ! in_array( $order->get_status(), $statuses, true )
		) {
			continue;
		}

		$orders[] = kirin_api_format_order( $order, $product_flags );
	}

	return rest_ensure_response(
		array(
			'count'    => count( $orders ),
			'total'    => absint( $result->total ),
			'page'     => $page,
			'per_page' => $per_page,
			'orders'   => $orders,
		)
	);
}
