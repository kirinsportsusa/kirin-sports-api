<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}


/**
 * Return published products that are visible and purchasable by customers.
 *
 * @param string $category_slug Optional product category slug.
 * @return WC_Product[]
 */
function kirin_api_get_customer_products( $category_slug = '' ) {

	$args = array(
		'status'  => 'publish',
		'limit'   => -1,
		'orderby' => 'menu_order',
		'order'   => 'ASC',
	);

	if ( '' !== $category_slug ) {
		$args['category'] = array( $category_slug );
	}

	$products = wc_get_products( $args );

	return array_values(
		array_filter(
			$products,
			function ( $product ) {
				return (
					$product instanceof WC_Product
					&& 'publish' === $product->get_status()
					&& $product->is_visible()
					&& $product->is_purchasable()
				);
			}
		)
	);

}


/**
 * Return the common customer-facing product fields used by products/programs.
 *
 * @param WC_Product $product WooCommerce product.
 * @return array
 */
function kirin_api_product_summary( $product ) {

	$product_id = $product->get_id();
	$price      = $product->get_price();

	return array(
		'id'                    => $product_id,
		'name'                  => $product->get_name(),
		'slug'                  => $product->get_slug(),
		'description'           => wp_kses_post( $product->get_description() ),
		'short_description'     => wp_kses_post( $product->get_short_description() ),
		'price'                 => (float) $price,
		'formatted_price'       => wp_strip_all_tags( wc_price( $price ) ),
		'currency'              => get_woocommerce_currency(),
		'registration_required' => (
			'yes' === get_post_meta(
				$product_id,
				'_kirin_registration_required',
				true
			)
		),
	);

}


/**
 * Build a customer-facing product response, including category and image data.
 *
 * @param WC_Product $product WooCommerce product.
 * @return array
 */
function kirin_api_format_product( $product ) {

	$data = kirin_api_product_summary( $product );

	$regular_price = $product->get_regular_price();
	$sale_price    = $product->get_sale_price();
	$image_id      = $product->get_image_id();
	$image_url     = $image_id ? wp_get_attachment_image_url( $image_id, 'full' ) : false;
	$image_alt     = $image_id
		? get_post_meta( $image_id, '_wp_attachment_image_alt', true )
		: '';
	$categories    = array();

	foreach ( $product->get_category_ids() as $category_id ) {
		$category = get_term( $category_id, 'product_cat' );

		if ( is_wp_error( $category ) || ! $category ) {
			continue;
		}

		$categories[] = array(
			'id'   => (int) $category->term_id,
			'name' => sanitize_text_field( $category->name ),
			'slug' => sanitize_title( $category->slug ),
		);
	}

	$data['regular_price'] = '' !== $regular_price ? (float) $regular_price : null;
	$data['sale_price']    = '' !== $sale_price ? (float) $sale_price : null;
	$data['image']         = $image_url
		? array(
			'id'  => (int) $image_id,
			'url' => esc_url_raw( $image_url ),
			'alt' => sanitize_text_field( $image_alt ),
		)
		: null;
	$data['categories']    = $categories;
	$data['stock_status']  = sanitize_key( $product->get_stock_status() );
	$data['in_stock']      = (bool) $product->is_in_stock();

	return $data;

}


/**
 * Return all customer-purchasable products, optionally filtered by category.
 *
 * @param WP_REST_Request $request REST request.
 * @return WP_REST_Response|WP_Error
 */
function kirin_api_products( $request ) {

	if ( ! function_exists( 'wc_get_products' ) ) {
		return new WP_Error(
			'kirin_woocommerce_required',
			'WooCommerce is required for the products API.',
			array(
				'status' => 500,
			)
		);
	}

	$category_slug = sanitize_title( $request->get_param( 'category' ) );

	if ( '' !== $category_slug ) {
		$category = get_term_by( 'slug', $category_slug, 'product_cat' );

		if ( ! $category || is_wp_error( $category ) ) {
			return new WP_Error(
				'kirin_invalid_product_category',
				'The requested product category does not exist.',
				array(
					'status' => 400,
				)
			);
		}
	}

	$products      = kirin_api_get_customer_products( $category_slug );
	$product_data  = array();

	foreach ( $products as $product ) {
		$product_data[] = kirin_api_format_product( $product );
	}

	return rest_ensure_response(
		array(
			'count'    => count( $product_data ),
			'products' => $product_data,
		)
	);

}


/**
 * Return one customer-purchasable published product.
 *
 * @param WP_REST_Request $request REST request.
 * @return WP_REST_Response|WP_Error
 */
function kirin_api_product( $request ) {

	if ( ! function_exists( 'wc_get_product' ) ) {
		return new WP_Error(
			'kirin_woocommerce_required',
			'WooCommerce is required for the products API.',
			array(
				'status' => 500,
			)
		);
	}

	$product = wc_get_product( absint( $request['id'] ) );

	if (
		! $product
		|| 'publish' !== $product->get_status()
		|| ! $product->is_visible()
		|| ! $product->is_purchasable()
	) {
		return new WP_Error(
			'kirin_product_not_found',
			'Product not found.',
			array(
				'status' => 404,
			)
		);
	}

	return rest_ensure_response( kirin_api_format_product( $product ) );

}
