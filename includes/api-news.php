<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}


/**
 * Validate positive integer parameters before REST schema sanitization.
 *
 * @param mixed           $value     Supplied value.
 * @param WP_REST_Request $request   REST request.
 * @param string          $parameter Parameter name.
 * @return bool
 */
function kirin_api_news_validate_integer( $value, $request, $parameter ) {
	return (
		( is_int( $value ) || is_string( $value ) )
		&& false !== filter_var(
			$value,
			FILTER_VALIDATE_INT,
			array( 'options' => array( 'min_range' => 1, 'max_range' => 'per_page' === $parameter ? 100 : PHP_INT_MAX ) )
		)
	);
}


/**
 * Read a validated integer, with a default only when omitted.
 *
 * @param WP_REST_Request $request   REST request.
 * @param string          $parameter Parameter name.
 * @param int|null        $default   Default value.
 * @return int|null|WP_Error
 */
function kirin_api_news_integer( $request, $parameter, $default = null ) {
	$value = $request->get_param( $parameter );

	if ( null === $value ) {
		return $default;
	}

	if ( ! kirin_api_news_validate_integer( $value, $request, $parameter ) ) {
		return new WP_Error(
			'kirin_news_invalid_parameter',
			__( 'Parameters must be positive integers and per_page cannot exceed 100.', 'kirin-sports-api' ),
			array( 'status' => 400 )
		);
	}

	return (int) $value;
}


/**
 * Require public standard posts independently of the query and login state.
 *
 * @param WP_Post|false|null $post Article record.
 * @return bool
 */
function kirin_api_news_is_public( $post ) {
	return (
		$post instanceof WP_Post
		&& 'post' === $post->post_type
		&& 'publish' === $post->post_status
		&& '' === $post->post_password
		&& is_post_publicly_viewable( $post )
	);
}


/**
 * Decode text and remove HTML without running title or excerpt filters.
 *
 * @param string $text Stored text.
 * @return string
 */
function kirin_api_news_plain_text( $text ) {
	$text = html_entity_decode( $text, ENT_QUOTES | ENT_HTML5, get_bloginfo( 'charset' ) );
	$text = preg_replace( '/<\/(?:p|div|h[1-6]|li|blockquote|tr|figure)\s*>|<br\s*\/?>/i', ' ', $text );

	return trim( wp_strip_all_tags( $text, true ) );
}


/**
 * Keep saved markup for reviewed static blocks without running render callbacks.
 *
 * @param array $blocks Parsed block tree.
 * @param int   $depth  Current nesting depth.
 * @return string
 */
function kirin_api_news_block_markup( $blocks, $depth = 0 ) {
	if ( $depth > 20 ) {
		return '';
	}

	$static_blocks = array(
		'core/paragraph', 'core/heading', 'core/list', 'core/list-item',
		'core/quote', 'core/pullquote', 'core/image', 'core/gallery',
		'core/columns', 'core/column', 'core/group', 'core/table',
		'core/separator', 'core/spacer', 'core/buttons', 'core/button',
		'core/details', 'core/code', 'core/preformatted', 'core/verse',
		'core/cover', 'core/media-text', 'core/audio', 'core/video',
		'core/file', 'core/html', 'core/freeform', 'core/shortcode',
	);
	$html = '';

	foreach ( $blocks as $block ) {
		$name = $block['blockName'];

		if ( 'core/embed' === $name ) {
			$url = isset( $block['attrs']['url'] )
				? esc_url( $block['attrs']['url'], array( 'http', 'https' ) )
				: '';

			if ( '' !== $url ) {
				$html .= '<p><a href="' . $url . '">' . esc_html__( 'View embedded media', 'kirin-sports-api' ) . '</a></p>';
			}
			continue;
		}

		// Unknown and dynamic content blocks are omitted, including their children.
		if ( null !== $name && ! in_array( $name, $static_blocks, true ) ) {
			continue;
		}

		$child = 0;
		foreach ( $block['innerContent'] as $fragment ) {
			if ( is_string( $fragment ) ) {
				$html .= $fragment;
			} elseif ( isset( $block['innerBlocks'][ $child ] ) ) {
				$html .= kirin_api_news_block_markup( array( $block['innerBlocks'][ $child++ ] ), $depth + 1 );
			}
		}
	}

	return $html;
}


/**
 * Preserve text inside passive Avada wrappers and discard other shortcodes.
 *
 * No registered shortcode callback is executed, including unknown shortcodes.
 *
 * @param string $html  Stored markup.
 * @param int    $depth Current shortcode nesting depth.
 * @return string
 */
function kirin_api_news_strip_shortcodes( $html, $depth = 0 ) {
	if ( $depth > 20 ) {
		return '';
	}

	if ( ! preg_match_all( '/\[\/?([A-Za-z_][A-Za-z0-9_-]*)(?=[\s\/\]])/', $html, $matches ) ) {
		return $html;
	}

	$wrappers = array( 'fusion_builder_container', 'fusion_builder_row', 'fusion_builder_column', 'fusion_text' );
	$html = preg_replace_callback(
		'/' . get_shortcode_regex( array_unique( $matches[1] ) ) . '/s',
		function ( $match ) use ( $wrappers, $depth ) {
			return in_array( $match[2], $wrappers, true )
				? kirin_api_news_strip_shortcodes( isset( $match[5] ) ? $match[5] : '', $depth + 1 )
				: '';
		},
		$html
	);

	// Remove unmatched shortcode markers as well; never expose raw Avada tags.
	return null !== $html ? preg_replace( '/\[\/?[A-Za-z_][A-Za-z0-9_-]*\b[^\]]*\]/', '', $html ) : '';
}


/**
 * Build conservative article HTML without shortcode or dynamic-block execution.
 *
 * @param string $content Stored article or excerpt content.
 * @return string
 */
function kirin_api_news_safe_html( $content ) {
	$html = kirin_api_news_block_markup( parse_blocks( $content ) );
	$html = kirin_api_news_strip_shortcodes( $html );

	// Remove unsafe containers and their contents, not just their opening tags.
	$html = preg_replace( '/<(script|style|form|iframe|object|embed|textarea|select)\b[^>]*>.*?<\/\1\s*>/is', '', $html );
	$html = preg_replace( '/<(?:script|style|form|iframe|object|embed|textarea|select)\b[^>]*>.*$/is', '', $html );

	$allowed = array(
		'a'          => array( 'href' => true, 'title' => true ),
		'p'          => array(),
		'br'         => array(),
		'hr'         => array(),
		'h1'         => array(),
		'h2'         => array(),
		'h3'         => array(),
		'h4'         => array(),
		'h5'         => array(),
		'h6'         => array(),
		'ul'         => array(),
		'ol'         => array( 'start' => true ),
		'li'         => array(),
		'blockquote' => array( 'cite' => true ),
		'pre'        => array(),
		'code'       => array(),
		'strong'     => array(),
		'em'         => array(),
		'b'          => array(),
		'i'          => array(),
		'u'          => array(),
		's'          => array(),
		'del'        => array(),
		'ins'        => array(),
		'sub'        => array(),
		'sup'        => array(),
		'div'        => array(),
		'span'       => array(),
		'figure'     => array(),
		'figcaption' => array(),
		'img'        => array( 'src' => true, 'alt' => true, 'width' => true, 'height' => true ),
		'table'      => array(),
		'caption'    => array(),
		'thead'      => array(),
		'tbody'      => array(),
		'tfoot'      => array(),
		'tr'         => array(),
		'th'         => array( 'colspan' => true, 'rowspan' => true, 'scope' => true ),
		'td'         => array( 'colspan' => true, 'rowspan' => true ),
		'details'    => array(),
		'summary'    => array(),
		'video'      => array( 'src' => true, 'controls' => true, 'poster' => true, 'width' => true, 'height' => true ),
		'audio'      => array( 'src' => true, 'controls' => true ),
		'source'     => array( 'src' => true, 'type' => true ),
	);

	return wp_kses( wpautop( $html ), $allowed, array( 'http', 'https' ) );
}


/**
 * Return a GMT timestamp with second precision, or null for missing dates.
 *
 * @param WP_Post $post  Article record.
 * @param string  $field Date field: date or modified.
 * @return string|null
 */
function kirin_api_news_date( $post, $field ) {
	$date = get_post_datetime( $post, $field, 'gmt' );

	return $date ? gmdate( 'Y-m-d\TH:i:s\Z', $date->getTimestamp() ) : null;
}


/**
 * Format one public article, adding full HTML only for the detail endpoint.
 *
 * @param WP_Post $post   Article record.
 * @param bool    $detail Whether to include full content.
 * @return array
 */
function kirin_api_news_summary( $post, $detail = false ) {
	$content_html = null;
	$excerpt      = $post->post_excerpt;

	if ( '' === trim( $excerpt ) ) {
		$content_html = kirin_api_news_safe_html( $post->post_content );
		$excerpt      = $content_html;
	} else {
		$excerpt = kirin_api_news_safe_html( $excerpt );
	}

	$categories = array();
	$terms      = get_the_terms( $post, 'category' );

	if ( is_array( $terms ) ) {
		foreach ( $terms as $term ) {
			$categories[] = array(
				'id'   => (int) $term->term_id,
				'name' => kirin_api_news_plain_text( $term->name ),
				'slug' => $term->slug,
			);
		}
	}

	$image      = null;
	$image_id   = get_post_thumbnail_id( $post );
	$image_data = $image_id && wp_attachment_is_image( $image_id )
		? wp_get_attachment_image_src( $image_id, $detail ? 'large' : 'medium' )
		: false;

	if ( $image_data ) {
		$url = esc_url_raw( $image_data[0], array( 'http', 'https' ) );

		if ( '' !== $url ) {
			$image = array(
				'url'    => $url,
				'alt'    => kirin_api_news_plain_text( get_post_meta( $image_id, '_wp_attachment_image_alt', true ) ),
				'width'  => (int) $image_data[1],
				'height' => (int) $image_data[2],
			);
		}
	}

	$data = array(
		'id'             => (int) $post->ID,
		'title'          => kirin_api_news_plain_text( $post->post_title ),
		'slug'           => $post->post_name,
		'excerpt'        => wp_trim_words( kirin_api_news_plain_text( $excerpt ), 55, '…' ),
		'date'           => kirin_api_news_date( $post, 'date' ),
		'modified'       => kirin_api_news_date( $post, 'modified' ),
		'featured_image' => $image,
		'categories'     => $categories,
		'url'            => esc_url_raw( get_permalink( $post ), array( 'http', 'https' ) ),
	);

	if ( $detail ) {
		$data['content_html'] = null !== $content_html ? $content_html : kirin_api_news_safe_html( $post->post_content );
	}

	return $data;
}


/**
 * Return one bounded page of all eligible standard posts.
 *
 * @param WP_REST_Request $request REST request.
 * @return WP_REST_Response|WP_Error
 */
function kirin_api_news( $request ) {
	$page = kirin_api_news_integer( $request, 'page', 1 );

	if ( is_wp_error( $page ) ) {
		return $page;
	}

	$per_page = kirin_api_news_integer( $request, 'per_page', 20 );

	if ( is_wp_error( $per_page ) ) {
		return $per_page;
	}

	$category = kirin_api_news_integer( $request, 'category' );

	if ( is_wp_error( $category ) ) {
		return $category;
	}

	if ( $page > intdiv( PHP_INT_MAX, $per_page ) ) {
		return new WP_Error( 'kirin_news_invalid_page', __( 'The requested page is too large.', 'kirin-sports-api' ), array( 'status' => 400 ) );
	}

	$args = array(
		'post_type'              => 'post',
		'post_status'            => 'publish',
		'has_password'           => false,
		'posts_per_page'         => $per_page,
		'paged'                  => $page,
		'orderby'                => array( 'date' => 'DESC', 'ID' => 'DESC' ),
		'ignore_sticky_posts'    => true,
		'no_found_rows'          => false,
		'update_post_meta_cache' => true,
		'update_post_term_cache' => true,
	);

	if ( null !== $category ) {
		$term = get_term( $category, 'category' );

		if ( ! $term || is_wp_error( $term ) ) {
			return new WP_Error( 'kirin_news_invalid_category', __( 'The post category does not exist.', 'kirin-sports-api' ), array( 'status' => 400 ) );
		}

		$args['tax_query'] = array(
			array(
				'taxonomy'         => 'category',
				'field'            => 'term_id',
				'terms'            => array( $category ),
				'include_children' => false,
			),
		);
	}

	$query = new WP_Query( $args );
	$news  = array();
	update_post_thumbnail_cache( $query );

	foreach ( $query->posts as $post ) {
		if ( kirin_api_news_is_public( $post ) ) {
			$news[] = kirin_api_news_summary( $post );
		}
	}

	return rest_ensure_response(
		array(
			'count'    => count( $news ),
			'total'    => (int) $query->found_posts,
			'page'     => $page,
			'per_page' => $per_page,
			'news'     => $news,
		)
	);
}


/**
 * Return a public article, with the same 404 for every unavailable record.
 *
 * @param WP_REST_Request $request REST request.
 * @return WP_REST_Response|WP_Error
 */
function kirin_api_news_article( $request ) {
	$id   = kirin_api_news_integer( $request, 'id' );
	$post = ! is_wp_error( $id ) && null !== $id ? get_post( $id ) : null;

	if ( ! kirin_api_news_is_public( $post ) ) {
		return new WP_Error( 'kirin_news_not_found', __( 'Article not found.', 'kirin-sports-api' ), array( 'status' => 404 ) );
	}

	return rest_ensure_response( kirin_api_news_summary( $post, true ) );
}


/**
 * Return existing post categories alphabetically, excluding the default category.
 *
 * @return WP_REST_Response|WP_Error
 */
function kirin_api_news_categories() {
	$default_category = (int) get_option( 'default_category' );
	$terms = get_terms(
		array(
			'taxonomy'   => 'category',
			'hide_empty' => false,
			'exclude'    => $default_category > 0 ? array( $default_category ) : array(),
			'orderby'    => 'name',
			'order'      => 'ASC',
		)
	);

	if ( is_wp_error( $terms ) ) {
		return new WP_Error( 'kirin_news_categories_unavailable', __( 'News categories could not be retrieved.', 'kirin-sports-api' ), array( 'status' => 500 ) );
	}

	$categories = array();
	foreach ( $terms as $term ) {
		// Keep the navigation boundary even if a term-query filter adds the default.
		if ( (int) $term->term_id === $default_category ) {
			continue;
		}

		$categories[] = array(
			'id'   => (int) $term->term_id,
			'name' => kirin_api_news_plain_text( $term->name ),
			'slug' => $term->slug,
		);
	}

	return rest_ensure_response( array( 'count' => count( $categories ), 'categories' => $categories ) );
}
