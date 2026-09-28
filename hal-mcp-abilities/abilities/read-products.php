<?php
/**
 * hal-mcp-abilities — hal/get-product, hal/search-products.
 *
 * Both abilities go through wc_get_product() for all product data (never raw
 * post meta), which keeps this plugin compatible regardless of whether a given
 * WooCommerce install stores product data the classic way or via newer
 * internal storage — the getters are the stable, version-independent surface
 * WooCommerce itself recommends.
 *
 * @package hal-mcp-abilities
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

add_action( 'wp_abilities_api_init', 'hal_mcp_register_read_product_abilities' );

/**
 * Computes the F09 "fields this product type does not support" list, so the
 * model can tell a legitimately empty value apart from a field WooCommerce
 * never maintains for this type. Values for unsupported fields are still
 * returned exactly as WooCommerce reports them (usually ''), never invented.
 *
 * @param WC_Product $product Product object (any core type).
 * @return string[]
 */
function hal_mcp_product_unsupported_fields( WC_Product $product ): array {

	$type = (string) $product->get_type();

	// Variable parents carry prices/stock/SKU at the variation level; a
	// variation has no product categories of its own. Everything else core
	// CRUD actually maintains reports its own empty string when unset, which
	// is a supported (unset) field, not an unsupported one.
	if ( 'variable' === $type ) {
		return [ 'regular_price', 'sale_price', 'on_sale', 'sku', 'manage_stock', 'stock_quantity', 'stock_status', 'in_stock' ];
	}

	if ( 'variation' === $type ) {
		return [ 'categories' ];
	}

	return [];
}

/**
 * Registers the hal/get-product and hal/search-products abilities.
 *
 * @return void
 */
function hal_mcp_register_read_product_abilities(): void {

	wp_register_ability(
		'hal/get-product',
		[
			'label'       => __( 'Get a single product', 'hal-mcp' ),
			'description' => __( 'Returns full data for a single WooCommerce product by its numeric ID: name, SKU, description, price, and stock. Accepts a variation ID too, returning it with its explicit parent relation (both the parent and the variation must be readable). Fields a product type does not support are listed in unsupported_fields.', 'hal-mcp' ),
			'category'    => 'hal-products',

			'input_schema' => [
				'type'                 => 'object',
				'properties'           => [
					'product_id' => [
						'type'        => 'integer',
						'minimum'     => 1,
						'description' => __( 'The numeric ID of the product (or product variation) to retrieve.', 'hal-mcp' ),
					],
				],
				'required'             => [ 'product_id' ],
				'additionalProperties' => false,
			],

			'output_schema' => [
				'type'       => 'object',
				'properties' => [
					'id'                => [ 'type' => 'integer' ],
					'name'              => [ 'type' => 'string' ],
					'sku'               => [ 'type' => 'string' ],
					'status'            => [ 'type' => 'string' ],
					'type'              => [ 'type' => 'string' ],
					'language'          => [
						'type'        => 'string',
						'description' => __( 'The language this product is reported in. Until a translations integration is active, every product is reported in the site locale.', 'hal-mcp' ),
					],
					'description'       => [ 'type' => 'string' ],
					'short_description' => [ 'type' => 'string' ],
					'regular_price'     => [ 'type' => 'string' ],
					'sale_price'        => [ 'type' => 'string' ],
					'price'             => [ 'type' => 'string' ],
					'on_sale'           => [ 'type' => 'boolean' ],
					'manage_stock'      => [ 'type' => 'boolean' ],
					'stock_quantity'    => [
						'type'        => 'integer',
						'description' => __( '0 when manage_stock is false (stock is not tracked for this product).', 'hal-mcp' ),
					],
					'stock_status'      => [ 'type' => 'string' ],
					'in_stock'          => [ 'type' => 'boolean' ],
					'categories'        => [
						'type'  => 'array',
						'items' => [ 'type' => 'string' ],
					],
					'parent_id'         => [
						'type'        => 'integer',
						'description' => __( 'For a variation: its parent product ID. 0 for a top-level product.', 'hal-mcp' ),
					],
					'parent_name'       => [
						'type'        => 'string',
						'description' => __( 'For a variation: its parent product name. Empty for a top-level product.', 'hal-mcp' ),
					],
					'attributes'        => [
						'type'        => 'object',
						'description' => __( 'For a variation: its selected attribute values, keyed by attribute name. Empty object otherwise.', 'hal-mcp' ),
						'additionalProperties' => [ 'type' => 'string' ],
					],
					'unsupported_fields' => [
						'type'        => 'array',
						'items'       => [ 'type' => 'string' ],
						'description' => __( 'Output fields WooCommerce does not maintain for this product type; their values are reported as WooCommerce gives them (usually empty) and carry no meaning here.', 'hal-mcp' ),
					],
				],
			],

			'permission_callback' => static fn( $input = [] ) => hal_mcp_permission( 'product', 'read', (int) ( $input['product_id'] ?? 0 ), [ 'ability' => 'hal/get-product' ] ),

			'execute_callback' => function ( $input ) {

				if ( ! function_exists( 'wc_get_product' ) ) {
					return new WP_Error(
						'hal_mcp_woocommerce_inactive',
						__( 'WooCommerce is not active on this site.', 'hal-mcp' )
					);
				}

				$product_id = absint( $input['product_id'] ?? 0 );

				if ( $product_id < 1 ) {
					return new WP_Error(
						'hal_mcp_invalid_product_id',
						__( 'product_id must be a positive integer.', 'hal-mcp' )
					);
				}

				// wc_get_product() also returns WC_Product_Variation objects when
				// given a variation ID (post_type 'product_variation'). F09:
				// variations ARE readable here — with the parent relation made
				// explicit and BOTH the parent and the variation permission-
				// checked — while any other non-product post type stays
				// excluded exactly as before.
				$hal_mcp_post_type = get_post_type( $product_id );

				if ( ! in_array( $hal_mcp_post_type, [ 'product', 'product_variation' ], true ) ) {
					return new WP_Error(
						'hal_mcp_product_not_found',
						__( 'No product was found with that ID.', 'hal-mcp' )
					);
				}

				$product = wc_get_product( $product_id );

				if ( ! $product instanceof WC_Product ) {
					return new WP_Error(
						'hal_mcp_product_not_found',
						__( 'No product was found with that ID.', 'hal-mcp' )
					);
				}

				// Object-level read check (F05): read_product (via the
				// registered WooCommerce mapping) with the ID enforces
				// ownership/private-status rules per product. Same generic
				// "not found" as above — the denial is logged, the item's
				// existence is not disclosed.
				if ( ! hal_mcp_permission( 'product', 'read', $product_id, [ 'ability' => 'hal/get-product' ] ) ) {
					return new WP_Error(
						'hal_mcp_product_not_found',
						__( 'No product was found with that ID.', 'hal-mcp' )
					);
				}

				// --- Variation branch (F09): explicit parent relation, both
				// sides readable. A variation whose parent is gone (or whose
				// parent the caller may not read) is treated as not found —
				// reading a child through its parent is the contract here. ---
				if ( 'variation' === (string) $product->get_type() ) {

					$hal_mcp_parent_id = (int) $product->get_parent_id();
					$hal_mcp_parent    = $hal_mcp_parent_id > 0 ? wc_get_product( $hal_mcp_parent_id ) : null;

					if ( ! $hal_mcp_parent instanceof WC_Product
						|| ! hal_mcp_permission( 'product', 'read', $hal_mcp_parent_id, [ 'ability' => 'hal/get-product', 'reason' => 'variation_parent' ] ) ) {
						return new WP_Error(
							'hal_mcp_product_not_found',
							__( 'No product was found with that ID.', 'hal-mcp' )
						);
					}

					return [
						'id'                => $product->get_id(),
						'name'              => $product->get_name(),
						'sku'               => (string) $product->get_sku(),
						'status'            => $product->get_status(),
						'type'              => 'variation',
						'language'          => (string) get_locale(),
						'description'       => $product->get_description(),
						'short_description' => '',
						'regular_price'     => (string) $product->get_regular_price(),
						'sale_price'        => (string) $product->get_sale_price(),
						'price'             => (string) $product->get_price(),
						'on_sale'           => $product->is_on_sale(),
						'manage_stock'      => (bool) $product->get_manage_stock(),
						'stock_quantity'    => (int) $product->get_stock_quantity(),
						'stock_status'      => $product->get_stock_status(),
						'in_stock'          => $product->is_in_stock(),
						'categories'        => [],
						'parent_id'         => $hal_mcp_parent_id,
						'parent_name'       => $hal_mcp_parent->get_name(),
						'attributes'        => array_map( 'strval', (array) $product->get_attributes() ),
						'unsupported_fields' => [ 'categories' ],
					];
				}

				$category_names = wp_get_post_terms( $product_id, 'product_cat', [ 'fields' => 'names' ] );

				return [
					'id'                => $product->get_id(),
					'name'              => $product->get_name(),
					'sku'               => (string) $product->get_sku(),
					'status'            => $product->get_status(),
					'type'              => $product->get_type(),
					'language'          => (string) get_locale(),
					'description'       => $product->get_description(),
					'short_description' => $product->get_short_description(),
					'regular_price'     => (string) $product->get_regular_price(),
					'sale_price'        => (string) $product->get_sale_price(),
					'price'             => (string) $product->get_price(),
					'on_sale'           => $product->is_on_sale(),
					'manage_stock'      => (bool) $product->get_manage_stock(),
					'stock_quantity'    => (int) $product->get_stock_quantity(),
					'stock_status'      => $product->get_stock_status(),
					'in_stock'          => $product->is_in_stock(),
					'categories'        => is_wp_error( $category_names ) ? [] : array_values( $category_names ),
					'parent_id'         => 0,
					'parent_name'       => '',
					'attributes'        => [],
					'unsupported_fields' => hal_mcp_product_unsupported_fields( $product ),
				];
			},

			'meta' => [ 'mcp' => [ 'public' => true ] ],
		]
	);

	wp_register_ability(
		'hal/search-products',
		[
			'label'       => __( 'Search products', 'hal-mcp' ),
			'description' => __( 'Searches WooCommerce products by keyword and returns a short summary of up to 10 matches per page, newest first. Use the page input for the next set of results (an empty result means there are no more). Use hal/get-product with the returned id to fetch full details.', 'hal-mcp' ),
			'category'    => 'hal-products',

			'input_schema' => [
				'type'                 => 'object',
				'properties'           => [
					'query'          => [
						'type'        => 'string',
						'minLength'   => 1,
						'description' => __( 'Keyword or phrase to search for in product names and descriptions.', 'hal-mcp' ),
					],
					'include_drafts' => [
						'type'        => 'boolean',
						'default'     => false,
						'description' => __( 'If true, also search draft, pending, and private products in addition to published ones. Default false (published only). Widening the status window never widens who may see a specific product — every result is still permission-checked per object.', 'hal-mcp' ),
					],
					'language'       => [
						'type'        => 'string',
						'description' => __( 'Optional language filter. Only languages the site actually offers are accepted; until a translations integration is active that is the site locale, and filtering by it changes nothing.', 'hal-mcp' ),
					],
					'page'           => [
						'type'        => 'integer',
						'minimum'     => 1,
						'maximum'     => 100,
						'default'     => 1,
						'description' => __( '1-based page number over the fixed limit of 10 results. Default 1.', 'hal-mcp' ),
					],
				],
				'required'             => [ 'query' ],
				'additionalProperties' => false,
			],

			'output_schema' => [
				'type'  => 'array',
				'items' => [
					'type'       => 'object',
					'properties' => [
						'id'           => [ 'type' => 'integer' ],
						'name'         => [ 'type' => 'string' ],
						'sku'          => [ 'type' => 'string' ],
						'status'       => [ 'type' => 'string' ],
						'language'     => [
							'type'        => 'string',
							'description' => __( 'The language this product is reported in. Until a translations integration is active, every product is reported in the site locale.', 'hal-mcp' ),
						],
						'price'        => [ 'type' => 'string' ],
						'stock_status' => [ 'type' => 'string' ],
					],
				],
			],

			'permission_callback' => static fn() => hal_mcp_permission( 'product', 'read', 0, [ 'ability' => 'hal/search-products' ] ),

			'execute_callback' => function ( $input ) {

				if ( ! function_exists( 'wc_get_product' ) ) {
					return new WP_Error(
						'hal_mcp_woocommerce_inactive',
						__( 'WooCommerce is not active on this site.', 'hal-mcp' )
					);
				}

				$query = isset( $input['query'] ) ? trim( (string) $input['query'] ) : '';

				if ( '' === $query ) {
					return new WP_Error(
						'hal_mcp_empty_query',
						__( 'query must not be empty.', 'hal-mcp' )
					);
				}

				$language_error = hal_mcp_validate_read_language( is_array( $input ) ? $input : [] );

				if ( $language_error instanceof WP_Error ) {
					return $language_error;
				}

				$include_drafts = ! empty( $input['include_drafts'] );
				$post_status    = $include_drafts
					? [ 'publish', 'draft', 'pending', 'private' ]
					: 'publish';

				// Bounded pagination (F09): fixed limit of 10, only the offset
				// moves; the schema caps page at 100 and the clamp below holds
				// even without the schema in the way.
				$page   = min( 100, max( 1, (int) ( $input['page'] ?? 1 ) ) );
				$offset = ( $page - 1 ) * 10;

				/*
				 * WooCommerce's own product query layer (wc_get_products() /
				 * WC_Product_Query) has no free-text search parameter equivalent
				 * to WP_Query's 's' — confirmed via WooCommerce's own open
				 * GitHub issue #21450 ("Search argument in WC_Product_Query and
				 * wc_get_products?"). Products, unlike orders under HPOS, are
				 * still stored as a regular post type, so using get_posts() with
				 * post_type=product for the search itself — then reading each
				 * match back through wc_get_product() for its actual data — is
				 * a safe, standard combination, not a workaround around
				 * WooCommerce's data layer.
				 */
				$found_post_ids = get_posts(
					[
						'post_type'   => 'product',
						'post_status' => $post_status,
						's'           => $query,
						'numberposts' => 10,
						'offset'      => $offset,
						'orderby'     => 'date',
						'order'       => 'DESC',
						'fields'      => 'ids',
					]
				);

				$results = [];

				foreach ( $found_post_ids as $found_post_id ) {
					// Per-result read check (F05), quiet: other users' draft
					// and private products are skipped without disclosure.
					if ( ! hal_mcp_permission( 'product', 'read', (int) $found_post_id, [ 'ability' => 'hal/search-products', 'quiet' => true ] ) ) {
						continue;
					}

					$product = wc_get_product( $found_post_id );

					if ( ! $product instanceof WC_Product ) {
						continue;
					}

					$results[] = [
						'id'           => $product->get_id(),
						'name'         => $product->get_name(),
						'sku'          => (string) $product->get_sku(),
						'status'       => $product->get_status(),
						'language'     => (string) get_locale(),
						'price'        => (string) $product->get_price(),
						'stock_status' => $product->get_stock_status(),
					];
				}

				return $results;
			},

			'meta' => [ 'mcp' => [ 'public' => true ] ],
		]
	);
}
