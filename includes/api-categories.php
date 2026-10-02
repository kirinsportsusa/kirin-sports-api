<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}


/**
 * Return product categories that contain customer-purchasable products.
 *
 * Category counts reflect the products exposed by the products API. Parent
 * categories are included when a visible product belongs to a child category.
 *
 * @return WP_REST_Response|WP_Error
 */
function kirin_api_categories() {

	if (
		! taxonomy_exists( 'product_cat' )
		|| ! function_exists( 'wc_get_products' )
	) {
		return new WP_Error(
			'kirin_woocommerce_required',
			'WooCommerce is required for the categories API.',
			array(
				'status' => 500,
			)
		);
	}

	$products       = kirin_api_get_customer_products();
	$category_counts = array();

	foreach ( $products as $product ) {
		$included_categories = array();

		foreach ( $product->get_category_ids() as $category_id ) {
			$included_categories[] = (int) $category_id;

			foreach ( get_ancestors( $category_id, 'product_cat', 'taxonomy' ) as $ancestor_id ) {
				$included_categories[] = (int) $ancestor_id;
			}
		}

		foreach ( array_unique( $included_categories ) as $category_id ) {
			if ( ! isset( $category_counts[ $category_id ] ) ) {
				$category_counts[ $category_id ] = 0;
			}

			++$category_counts[ $category_id ];
		}
	}

	$category_data = array();

	if ( ! empty( $category_counts ) ) {
		$categories = get_terms(
			array(
				'taxonomy'   => 'product_cat',
				'hide_empty' => false,
				'include'    => array_keys( $category_counts ),
				'orderby'    => 'name',
				'order'      => 'ASC',
			)
		);

		if ( is_wp_error( $categories ) ) {
			return $categories;
		}

		foreach ( $categories as $category ) {
			$category_data[] = array(
				'id'          => (int) $category->term_id,
				'name'        => sanitize_text_field( $category->name ),
				'slug'        => sanitize_title( $category->slug ),
				'description' => wp_kses_post( $category->description ),
				'parent'      => (int) $category->parent,
				'count'       => (int) $category_counts[ $category->term_id ],
			);
		}
	}

	return rest_ensure_response(
		array(
			'count'      => count( $category_data ),
			'categories' => $category_data,
		)
	);

}
