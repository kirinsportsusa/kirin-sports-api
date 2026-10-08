<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}


/** Customer account endpoint. */


/**
 * Require an authenticated WordPress user for private API endpoints.
 *
 * @return true|WP_Error
 */
function kirin_api_require_authenticated_user() {
	if ( is_user_logged_in() && get_current_user_id() > 0 ) {
		return true;
	}

	return new WP_Error(
		'kirin_authentication_required',
		__( 'Authentication is required.', 'kirin-sports-api' ),
		array( 'status' => 401 )
	);
}


/**
 * Return the authenticated WordPress user's basic account details.
 *
 * @return WP_REST_Response|WP_Error
 */
function kirin_api_me() {
	$user_id = get_current_user_id();

	if ( $user_id <= 0 ) {
		return new WP_Error(
			'kirin_authentication_required',
			__( 'Authentication is required.', 'kirin-sports-api' ),
			array( 'status' => 401 )
		);
	}

	$user = wp_get_current_user();
	$first_name = get_user_meta( $user_id, 'first_name', true );
	$last_name  = get_user_meta( $user_id, 'last_name', true );

	return rest_ensure_response(
		array(
			'id'           => (int) $user_id,
			'email'        => sanitize_email( $user->user_email ),
			'first_name'   => sanitize_text_field( is_scalar( $first_name ) ? (string) $first_name : '' ),
			'last_name'    => sanitize_text_field( is_scalar( $last_name ) ? (string) $last_name : '' ),
			'display_name' => sanitize_text_field( $user->display_name ),
		)
	);
}
