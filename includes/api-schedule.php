<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}


/**
 * Maximum number of local calendar dates allowed in one schedule request.
 *
 * @return int
 */
function kirin_api_schedule_max_date_window_days() {
	return 366;
}


/**
 * Parse a strict local YYYY-MM-DD date.
 *
 * @param mixed        $value    Date value.
 * @param DateTimeZone $timezone Site timezone.
 * @return DateTimeImmutable|false
 */
function kirin_api_schedule_parse_date( $value, $timezone ) {
	if ( ! is_string( $value ) || ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $value ) ) {
		return false;
	}

	$date   = DateTimeImmutable::createFromFormat( '!Y-m-d', $value, $timezone );
	$errors = DateTimeImmutable::getLastErrors();

	if (
		! $date
		|| ( is_array( $errors ) && ( $errors['warning_count'] || $errors['error_count'] ) )
		|| $date->format( 'Y-m-d' ) !== $value
	) {
		return false;
	}

	return $date;
}


/**
 * Resolve the bounded local date range for a schedule request.
 *
 * @param WP_REST_Request $request  REST request.
 * @param DateTimeZone    $timezone Site timezone.
 * @return array|WP_Error
 */
function kirin_api_schedule_date_window( $request, $timezone ) {
	$raw_from = $request->get_param( 'from' );
	$raw_to   = $request->get_param( 'to' );
	$from     = null;
	$to       = null;

	if ( null !== $raw_from ) {
		$from = kirin_api_schedule_parse_date( $raw_from, $timezone );

		if ( ! $from ) {
			return new WP_Error(
				'kirin_schedule_invalid_from_date',
				__( 'The from date must use YYYY-MM-DD format and be a valid date.', 'kirin-sports-api' ),
				array( 'status' => 400 )
			);
		}
	}

	if ( null !== $raw_to ) {
		$to = kirin_api_schedule_parse_date( $raw_to, $timezone );

		if ( ! $to ) {
			return new WP_Error(
				'kirin_schedule_invalid_to_date',
				__( 'The to date must use YYYY-MM-DD format and be a valid date.', 'kirin-sports-api' ),
				array( 'status' => 400 )
			);
		}
	}

	$today = ( new DateTimeImmutable( 'now', $timezone ) )->setTime( 0, 0, 0 );

	if ( ! $from && ! $to ) {
		$from = $today;
		$to   = $today->modify( '+' . ( kirin_api_schedule_max_date_window_days() - 1 ) . ' days' );
	} elseif ( $from && ! $to ) {
		$to = $from->modify( '+' . ( kirin_api_schedule_max_date_window_days() - 1 ) . ' days' );
	} elseif ( ! $from && $to ) {
		$from = $to->modify( '-' . ( kirin_api_schedule_max_date_window_days() - 1 ) . ' days' );
	}

	if ( $from > $to ) {
		return new WP_Error(
			'kirin_schedule_invalid_date_range',
			__( 'The from date must be on or before the to date.', 'kirin-sports-api' ),
			array( 'status' => 400 )
		);
	}

	$window_days = (int) $from->diff( $to )->days + 1;

	if ( $window_days > kirin_api_schedule_max_date_window_days() ) {
		return new WP_Error(
			'kirin_schedule_date_window_too_large',
			__( 'The requested date range cannot exceed 366 days.', 'kirin-sports-api' ),
			array( 'status' => 400 )
		);
	}

	return array(
		'from' => $from,
		'to'   => $to,
	);
}


/**
 * Read a positive integer query parameter, using a default when omitted.
 *
 * @param WP_REST_Request $request      REST request.
 * @param string          $parameter    Parameter name.
 * @param int             $default      Default value.
 * @param int|null        $maximum      Optional maximum value.
 * @return int|WP_Error
 */
function kirin_api_schedule_pagination_value( $request, $parameter, $default, $maximum = null ) {
	$value = $request->get_param( $parameter );

	if ( null === $value ) {
		return $default;
	}

	if ( ! is_scalar( $value ) ) {
		return new WP_Error(
			'kirin_schedule_invalid_pagination',
			__( 'Pagination values must be positive integers.', 'kirin-sports-api' ),
			array( 'status' => 400 )
		);
	}

	$value = filter_var( $value, FILTER_VALIDATE_INT );

	if ( false === $value || $value < 1 || ( null !== $maximum && $value > $maximum ) ) {
		return new WP_Error(
			'kirin_schedule_invalid_pagination',
			null !== $maximum
				? __( 'Pagination values must be positive integers and per_page cannot exceed 100.', 'kirin-sports-api' )
				: __( 'Pagination values must be positive integers.', 'kirin-sports-api' ),
			array( 'status' => 400 )
		);
	}

	return (int) $value;
}


/**
 * Return the first matching birth-year schedule rule, preserving rule order.
 *
 * @param int   $birth_year   Player birth year.
 * @param array $age_schedule Product age rules.
 * @return array|false
 */
function kirin_api_schedule_player_time( $birth_year, $age_schedule ) {
	if ( ! is_array( $age_schedule ) ) {
		return false;
	}

	foreach ( $age_schedule as $rule ) {
		if ( ! is_array( $rule ) ) {
			continue;
		}

		$from = isset( $rule['birth_from'] ) && is_scalar( $rule['birth_from'] )
			? filter_var( $rule['birth_from'], FILTER_VALIDATE_INT )
			: false;
		$to   = isset( $rule['birth_to'] ) && is_scalar( $rule['birth_to'] )
			? filter_var( $rule['birth_to'], FILTER_VALIDATE_INT )
			: false;

		if ( false === $from || false === $to || $from < 1 || $to < $from ) {
			continue;
		}

		if ( $birth_year >= $from && $birth_year <= $to ) {
			$start = isset( $rule['start_time'] ) && is_scalar( $rule['start_time'] )
				? trim( (string) $rule['start_time'] )
				: '';
			$end   = isset( $rule['end_time'] ) && is_scalar( $rule['end_time'] )
				? trim( (string) $rule['end_time'] )
				: '';

			if ( ! kirin_api_schedule_valid_time( $start ) || ! kirin_api_schedule_valid_time( $end ) ) {
				return false;
			}

			return array(
				'start_time' => substr( $start, 0, 5 ),
				'end_time'   => substr( $end, 0, 5 ),
			);
		}
	}

	return false;
}


/**
 * Check a saved local time value and support seconds without returning them.
 *
 * @param string $time Local time value.
 * @return bool
 */
function kirin_api_schedule_valid_time( $time ) {
	return (bool) preg_match( '/^(?:[01]\d|2[0-3]):[0-5]\d(?::[0-5]\d)?$/', $time );
}


/**
 * Generate sessions for one registered player and product configuration.
 *
 * @param WC_Product $product    Product associated with the order item.
 * @param array      $player     Stored player record.
 * @param array      $config     Cached schedule configuration.
 * @param array      $date_range Requested local date range.
 * @param DateTimeZone $timezone Site timezone.
 * @param int        $item_id    WooCommerce order-item ID.
 * @param int        $sequence   Stable in-request tie breaker.
 * @return array
 */
function kirin_api_schedule_player_sessions( $product, $player, $config, $date_range, $timezone, $item_id, &$sequence ) {
	if ( empty( $config['start_date'] ) || empty( $config['end_date'] ) ) {
		return array();
	}

	$start_date = kirin_api_schedule_parse_date( $config['start_date'], $timezone );
	$end_date   = kirin_api_schedule_parse_date( $config['end_date'], $timezone );

	if ( ! $start_date || ! $end_date || $start_date > $end_date ) {
		return array();
	}

	$birth_year = isset( $player['birth_year'] ) && is_scalar( $player['birth_year'] )
		? filter_var( $player['birth_year'], FILTER_VALIDATE_INT )
		: false;

	if ( false === $birth_year || $birth_year < 1 ) {
		return array();
	}

	$time = kirin_api_schedule_player_time( $birth_year, $config['age_schedule'] );

	if ( ! $time ) {
		return array();
	}

	$day_map = array(
		'sunday'    => 0,
		'monday'    => 1,
		'tuesday'   => 2,
		'wednesday' => 3,
		'thursday'  => 4,
		'friday'    => 5,
		'saturday'  => 6,
	);
	$selected_days = array();

	if ( is_array( $config['days'] ) ) {
		foreach ( $config['days'] as $day ) {
			if ( ! is_scalar( $day ) ) {
				continue;
			}

			$day = strtolower( trim( (string) $day ) );

			if ( isset( $day_map[ $day ] ) ) {
				$selected_days[] = $day_map[ $day ];
			}
		}
	}

	if ( empty( $selected_days ) ) {
		return array();
	}

	if ( $start_date < $date_range['from'] ) {
		$start_date = $date_range['from'];
	}

	if ( $end_date > $date_range['to'] ) {
		$end_date = $date_range['to'];
	}

	if ( $start_date > $end_date ) {
		return array();
	}

	$first_name = isset( $player['first_name'] ) && is_scalar( $player['first_name'] )
		? trim( (string) $player['first_name'] )
		: '';
	$last_name  = isset( $player['last_name'] ) && is_scalar( $player['last_name'] )
		? trim( (string) $player['last_name'] )
		: '';

	if ( '' === $first_name && '' === $last_name ) {
		return array();
	}

	$sessions = array();

	while ( $start_date <= $end_date ) {
		if ( in_array( (int) $start_date->format( 'w' ), $selected_days, true ) ) {
			$sessions[] = array(
				'registration_id' => (int) $item_id,
				'player'          => array(
					'first_name' => sanitize_text_field( $first_name ),
					'last_name'  => sanitize_text_field( $last_name ),
				),
				'program'         => array(
					'id'   => (int) $product->get_id(),
					'name' => sanitize_text_field( $product->get_name() ),
				),
				'date'            => $start_date->format( 'Y-m-d' ),
				'start_time'      => $time['start_time'],
				'end_time'        => $time['end_time'],
				'location'        => is_scalar( $config['location'] )
					? sanitize_text_field( (string) $config['location'] )
					: '',
				'_sequence'       => $sequence++,
			);
		}

		$start_date = $start_date->modify( '+1 day' );
	}

	return $sessions;
}


/**
 * Return scheduled sessions for the authenticated customer.
 *
 * @param WP_REST_Request $request REST request.
 * @return WP_REST_Response|WP_Error
 */
function kirin_api_schedule( $request ) {
	$user_id = get_current_user_id();

	if ( $user_id <= 0 ) {
		return new WP_Error(
			'kirin_authentication_required',
			__( 'Authentication is required.', 'kirin-sports-api' ),
			array( 'status' => 401 )
		);
	}

	if ( ! function_exists( 'wc_get_orders' ) || ! function_exists( 'wc_get_product' ) ) {
		return new WP_Error(
			'kirin_woocommerce_unavailable',
			__( 'WooCommerce is required to retrieve the schedule.', 'kirin-sports-api' ),
			array( 'status' => 500 )
		);
	}

	$page = kirin_api_schedule_pagination_value( $request, 'page', 1 );

	if ( is_wp_error( $page ) ) {
		return $page;
	}

	$per_page = kirin_api_schedule_pagination_value( $request, 'per_page', 20, 100 );

	if ( is_wp_error( $per_page ) ) {
		return $per_page;
	}

	if ( $page > intdiv( PHP_INT_MAX, $per_page ) ) {
		return new WP_Error(
			'kirin_schedule_invalid_pagination',
			__( 'The requested page is too large.', 'kirin-sports-api' ),
			array( 'status' => 400 )
		);
	}

	$timezone   = wp_timezone();
	$date_range = kirin_api_schedule_date_window( $request, $timezone );

	if ( is_wp_error( $date_range ) ) {
		return $date_range;
	}

	$timezone_name = function_exists( 'wp_timezone_string' ) ? wp_timezone_string() : '';

	if ( empty( $timezone_name ) ) {
		$timezone_name = $timezone->getName();
	}

	$batch_size      = 100;
	$order_page      = 1;
	$max_order_pages = 1;
	$order_statuses  = array( 'processing', 'completed' );
	$product_configs = array();
	$sessions        = array();
	$sequence        = 0;

	do {
		$order_result = wc_get_orders(
			array(
				'customer_id' => $user_id,
				'status'      => array( 'wc-processing', 'wc-completed' ),
				'limit'       => $batch_size,
				'paged'       => $order_page,
				'paginate'    => true,
				'orderby'     => 'date',
				'order'       => 'DESC',
				'return'      => 'objects',
			)
		);

		if ( is_wp_error( $order_result ) || ! is_object( $order_result ) || ! isset( $order_result->orders ) || ! is_array( $order_result->orders ) ) {
			return new WP_Error(
				'kirin_schedule_order_query_failed',
				__( 'The schedule could not be retrieved.', 'kirin-sports-api' ),
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
				|| ! in_array( $order->get_status(), $order_statuses, true )
			) {
				continue;
			}

			$order_items = $order->get_items( 'line_item' );

			if ( ! is_array( $order_items ) ) {
				continue;
			}

			foreach ( $order_items as $item ) {
				if (
					! is_object( $item )
					|| ! is_callable( array( $item, 'get_id' ) )
					|| ! is_callable( array( $item, 'get_product' ) )
					|| ! is_callable( array( $item, 'get_meta' ) )
				) {
					continue;
				}

				$product = $item->get_product();

				if ( ! $product || ! is_callable( array( $product, 'get_id' ) ) || ! is_callable( array( $product, 'get_name' ) ) ) {
					continue;
				}

				$product_id = (int) $product->get_id();

				if ( ! isset( $product_configs[ $product_id ] ) ) {
					$product_configs[ $product_id ] = array(
						'enabled' => 'yes' === get_post_meta( $product_id, '_kirin_training_enabled', true ),
						'start_date' => get_post_meta( $product_id, '_kirin_training_start_date', true ),
						'end_date' => get_post_meta( $product_id, '_kirin_training_end_date', true ),
						'location' => get_post_meta( $product_id, '_kirin_training_location', true ),
						'days' => get_post_meta( $product_id, '_kirin_training_days', true ),
						'age_schedule' => get_post_meta( $product_id, '_kirin_age_schedule', true ),
					);

					if ( ! is_array( $product_configs[ $product_id ]['days'] ) ) {
						$product_configs[ $product_id ]['days'] = array();
					}

					if ( ! is_array( $product_configs[ $product_id ]['age_schedule'] ) ) {
						$product_configs[ $product_id ]['age_schedule'] = array();
					}
				}

				$config = $product_configs[ $product_id ];

				if ( ! $config['enabled'] ) {
					continue;
				}

				$players = $item->get_meta( '_kirin_training_players', true );

				if ( empty( $players ) || ! is_array( $players ) ) {
					continue;
				}

				foreach ( $players as $player ) {
					if ( ! is_array( $player ) ) {
						continue;
					}

					$player_sessions = kirin_api_schedule_player_sessions(
						$product,
						$player,
						$config,
						$date_range,
						$timezone,
						$item->get_id(),
						$sequence
					);

					foreach ( $player_sessions as $player_session ) {
						$sessions[] = $player_session;
					}
				}
			}
		}

		$order_page++;
	} while ( $order_page <= $max_order_pages );

	usort(
		$sessions,
		function ( $left, $right ) {
			$sort_fields = array(
				array( $left['date'], $right['date'] ),
				array( $left['start_time'], $right['start_time'] ),
				array( strtolower( $left['player']['last_name'] ), strtolower( $right['player']['last_name'] ) ),
				array( strtolower( $left['player']['first_name'] ), strtolower( $right['player']['first_name'] ) ),
				array( $left['registration_id'], $right['registration_id'] ),
				array( $left['_sequence'], $right['_sequence'] ),
			);

			foreach ( $sort_fields as $field ) {
				if ( $field[0] === $field[1] ) {
					continue;
				}

				return $field[0] < $field[1] ? -1 : 1;
			}

			return 0;
		}
	);

	$total    = count( $sessions );
	$sessions = array_slice( $sessions, ( $page - 1 ) * $per_page, $per_page );

	foreach ( $sessions as &$session ) {
		unset( $session['_sequence'] );
	}
	unset( $session );

	return rest_ensure_response(
		array(
			'count'    => count( $sessions ),
			'total'    => $total,
			'page'     => $page,
			'per_page' => $per_page,
			'timezone' => $timezone_name,
			'sessions' => $sessions,
		)
	);
}
