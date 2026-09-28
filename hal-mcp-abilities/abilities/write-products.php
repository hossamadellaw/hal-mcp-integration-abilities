<?php
/**
 * hal-mcp-abilities — hal/create-product, hal/update-product.
 *
 * Product data is written through WooCommerce's own CRUD (wc_get_product() +
 * the product's set_*()/save()), never wp_insert_post() with post_type=
 * product — bypassing WooCommerce would skip the wc_product_meta_lookup
 * table that functions like wc_get_product_id_by_sku() depend on.
 *
 * Same non-negotiable rule as write-posts.php, in its F10 form: the model
 * may REQUEST a status via the unified `requested_status` input, but the
 * policy decides — draft creation is direct, publishing and every write to a
 * protected product become a stored change request (F17) approved by a
 * human in wp-admin. Nothing here publishes outside the request apply
 * handler.
 *
 * F11 rules enforced here:
 * - The apply path loads the product with wc_get_product() and saves THE
   * SAME object, so the product's type is preserved through every request.
 *   No WC_Product_Simple is ever constructed as a proposal container (the
 *   v1 proposal-draft pattern is gone entirely — proposals are F17 rows).
 * - Core basic types (simple, variable, variation) get the operations
 *   WooCommerce's CRUD actually supports. Attached/discovered types expose
 *   only their supported fields and are never converted to Simple.
 * - A variation is addressed through its parent: the write-path decision
 *   and the fresh re-check use the PARENT's status, so editing a variation
 *   of a published product is a protected change, not a live edit.
 * - Every field that affects a published product (prices, stock, images,
 *   categories, SKU, ...) rides the approval request with a fingerprint of
 *   exactly the original fields being changed.
 * - WooCommerce being absent is an error FOR THESE ABILITIES ONLY (a clean
 *   WP_Error) — the rest of the plugin keeps working.
 *
 * @package hal-mcp-abilities
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// ---------------------------------------------------------------------------
// Domain layer — validation, live reads, and the one apply function shared
// by the direct path and the F17 apply handler.
// ---------------------------------------------------------------------------

/**
 * The product fields each product type supports (F11: الأنواع الأساسية
 * تستخدم العمليات التي يدعمها CRUD فعلًا؛ الأنواع الملحقة تعرض حقولها
 * المدعومة فقط). Anything a type does not list is REFUSED with a clear
 * error — never silently stored onto a product type that ignores it.
 *
 * @param string $product_type WooCommerce product type slug ('variation'
 *                             for product_variation rows).
 * @return string[] Supported change-map keys.
 */
function hal_mcp_product_supported_fields( string $product_type ): array {

	switch ( $product_type ) {
		case 'simple':
			return [ 'name', 'description', 'short_description', 'regular_price', 'sale_price', 'sku', 'manage_stock', 'stock_quantity', 'stock_status', 'categories', 'images', 'attributes' ];
		case 'variable':
			// A variable parent carries prices/stock at the variation level;
			// its own CRUD supports the identity and attribute fields.
			return [ 'name', 'description', 'short_description', 'sku', 'categories', 'images', 'attributes' ];
		case 'variation':
			// A variation's name is derived from its attribute pairs; its
			// identity fields are prices/stock/SKU on the parent's catalog.
			return [ 'description', 'regular_price', 'sale_price', 'sku', 'manage_stock', 'stock_quantity', 'stock_status', 'images' ];
	}

	// Attached/discovered types: identity fields only, never converted.
	return [ 'name', 'description', 'short_description' ];
}

/**
 * Validates and sanitizes the F11 field set into a clean change map (the
 * domain-sanitization point; the request store only enforces structure).
 * Prices are plain numbers, stock is an integer, categories must already
 * exist (product_cat), images must be readable media library attachments,
 * attributes are name/values pairs for WooCommerce's CRUD.
 *
 * @param array<string, mixed> $input Raw ability input.
 * @return array<string, mixed>|WP_Error Clean change map (only provided
 *   fields present), or WP_Error for the first invalid field.
 */
function hal_mcp_product_build_change_map( array $input ) {

	$change = [];

	if ( array_key_exists( 'name', $input ) ) {
		$name = sanitize_text_field( trim( (string) $input['name'] ) );

		if ( '' === $name ) {
			return new WP_Error( 'hal_mcp_missing_name', __( 'name must not be empty when provided.', 'hal-mcp' ) );
		}

		$change['name'] = $name;
	}

	if ( array_key_exists( 'description', $input ) ) {
		$change['description'] = wp_kses_post( (string) $input['description'] );
	}

	if ( array_key_exists( 'short_description', $input ) ) {
		$change['short_description'] = wp_kses_post( (string) $input['short_description'] );
	}

	foreach ( [ 'regular_price', 'sale_price' ] as $hal_mcp_price_field ) {
		if ( array_key_exists( $hal_mcp_price_field, $input ) ) {
			$hal_mcp_price = trim( (string) $input[ $hal_mcp_price_field ] );

			if ( '' !== $hal_mcp_price && ! is_numeric( $hal_mcp_price ) ) {
				return new WP_Error(
					'hal_mcp_invalid_price',
					__( 'Prices must be plain numbers, e.g. "19.99".', 'hal-mcp' )
				);
			}

			$change[ $hal_mcp_price_field ] = $hal_mcp_price;
		}
	}

	if ( array_key_exists( 'sku', $input ) ) {
		$hal_mcp_sku = sanitize_text_field( trim( (string) $input['sku'] ) );

		if ( '' !== $hal_mcp_sku && strlen( $hal_mcp_sku ) > 100 ) {
			return new WP_Error(
				'hal_mcp_invalid_sku',
				__( 'SKU must be 100 characters or fewer.', 'hal-mcp' )
			);
		}

		$change['sku'] = $hal_mcp_sku;
	}

	if ( array_key_exists( 'manage_stock', $input ) ) {
		$change['manage_stock'] = (bool) $input['manage_stock'];
	}

	if ( array_key_exists( 'stock_quantity', $input ) ) {
		$hal_mcp_stock = $input['stock_quantity'];

		if ( ! is_int( $hal_mcp_stock ) || $hal_mcp_stock < 0 ) {
			return new WP_Error(
				'hal_mcp_invalid_stock',
				__( 'stock_quantity must be a non-negative integer.', 'hal-mcp' )
			);
		}

		$change['stock_quantity'] = $hal_mcp_stock;
	}

	if ( array_key_exists( 'stock_status', $input ) ) {
		$hal_mcp_status = (string) $input['stock_status'];

		if ( ! in_array( $hal_mcp_status, [ 'instock', 'outofstock', 'onbackorder' ], true ) ) {
			return new WP_Error(
				'hal_mcp_invalid_stock_status',
				__( 'stock_status accepts only "instock", "outofstock", or "onbackorder".', 'hal-mcp' )
			);
		}

		$change['stock_status'] = $hal_mcp_status;
	}

	if ( array_key_exists( 'categories', $input ) ) {
		$hal_mcp_category_ids = hal_mcp_product_resolve_category_ids( (array) $input['categories'] );

		if ( is_wp_error( $hal_mcp_category_ids ) ) {
			return $hal_mcp_category_ids;
		}

		$change['categories'] = $hal_mcp_category_ids;
	}

	if ( array_key_exists( 'images', $input ) ) {
		$hal_mcp_image_ids = hal_mcp_product_resolve_image_ids( (array) $input['images'] );

		if ( is_wp_error( $hal_mcp_image_ids ) ) {
			return $hal_mcp_image_ids;
		}

		$change['images'] = $hal_mcp_image_ids;
	}

	if ( array_key_exists( 'attributes', $input ) ) {
		$hal_mcp_attributes = hal_mcp_product_build_attributes( (array) $input['attributes'] );

		if ( is_wp_error( $hal_mcp_attributes ) ) {
			return $hal_mcp_attributes;
		}

		$change['attributes'] = $hal_mcp_attributes;
	}

	if ( array_key_exists( 'language', $input ) ) {
		$hal_mcp_language = trim( (string) $input['language'] );

		if ( '' !== $hal_mcp_language && ! hal_mcp_language_is_supported( $hal_mcp_language ) ) {
			return new WP_Error(
				'hal_mcp_language_unsupported',
				sprintf(
					/* translators: 1: requested language code. 2: comma-separated list of languages this site offers. */
					__( 'The language "%1$s" is not available on this site. Available languages: %2$s.', 'hal-mcp' ),
					$hal_mcp_language,
					implode( ', ', hal_mcp_supported_languages() )
				)
			);
		}

		if ( '' !== $hal_mcp_language ) {
			$change['language'] = sanitize_key( $hal_mcp_language );
		}
	}

	if ( array_key_exists( 'requested_status', $input ) ) {
		$hal_mcp_requested = (string) $input['requested_status'];

		if ( ! in_array( $hal_mcp_requested, [ 'draft', 'publish' ], true ) ) {
			return new WP_Error(
				'hal_mcp_invalid_requested_status',
				__( 'requested_status accepts only "draft" or "publish".', 'hal-mcp' )
			);
		}

		$change['requested_status'] = $hal_mcp_requested;
	}

	return $change;
}

/**
 * Resolves product category names or IDs to existing term IDs (product_cat).
 * Unknown terms are refused with their names listed — no term is created
 * from model input.
 *
 * @param array<int|string, mixed> $categories Category names and/or IDs.
 * @return int[]|WP_Error
 */
function hal_mcp_product_resolve_category_ids( array $categories ) {

	$hal_mcp_ids     = [];
	$hal_mcp_unknown = [];

	foreach ( $categories as $hal_mcp_category ) {
		if ( is_int( $hal_mcp_category ) || ( is_string( $hal_mcp_category ) && ctype_digit( trim( $hal_mcp_category ) ) ) ) {
			$hal_mcp_term_id = absint( $hal_mcp_category );
			$hal_mcp_term    = $hal_mcp_term_id > 0 ? get_term( $hal_mcp_term_id, 'product_cat' ) : null;
		} else {
			$hal_mcp_term    = get_term_by( 'name', sanitize_text_field( (string) $hal_mcp_category ), 'product_cat' );
			$hal_mcp_term_id = $hal_mcp_term instanceof WP_Term ? (int) $hal_mcp_term->term_id : 0;
		}

		if ( ! $hal_mcp_term instanceof WP_Term || $hal_mcp_term_id < 1 ) {
			$hal_mcp_unknown[] = sanitize_text_field( (string) $hal_mcp_category );
			continue;
		}

		$hal_mcp_ids[] = $hal_mcp_term_id;
	}

	if ( ! empty( $hal_mcp_unknown ) ) {
		return new WP_Error(
			'hal_mcp_unknown_category',
			sprintf(
				/* translators: %s: comma-separated list of category names that do not exist on this site. */
				__( 'These product categories do not exist on this site and are not created automatically: %s.', 'hal-mcp' ),
				implode( ', ', array_unique( $hal_mcp_unknown ) )
			)
		);
	}

	return array_values( array_unique( $hal_mcp_ids ) );
}

/**
 * Validates image IDs for the images field: every ID must be an existing
 * media library attachment the caller may read. The whole list is validated
 * before anything is stored — a mixed result refuses the change with every
 * bad ID named, and nothing partial is ever created.
 *
 * @param array<int, mixed> $images Attachment IDs.
 * @return int[]|WP_Error
 */
function hal_mcp_product_resolve_image_ids( array $images ) {

	$hal_mcp_ids    = [];
	$hal_mcp_invalid = [];

	foreach ( $images as $hal_mcp_image ) {
		$hal_mcp_image_id = (int) $hal_mcp_image;

		// A non-positive ID is refused as-is, never absint()-ed: absint()
		// would silently turn -5 into 5 and attach an attachment the caller
		// never named.
		if ( $hal_mcp_image_id < 1 ) {
			$hal_mcp_invalid[] = $hal_mcp_image_id;
			continue;
		}

		$hal_mcp_post = get_post( $hal_mcp_image_id );

		if ( ! $hal_mcp_post instanceof WP_Post
			|| 'attachment' !== $hal_mcp_post->post_type
			|| ! hal_mcp_permission( 'media', 'read', $hal_mcp_image_id, [ 'ability' => 'hal/write-products', 'reason' => 'product_image', 'quiet' => true ] ) ) {
			$hal_mcp_invalid[] = $hal_mcp_image_id;
			continue;
		}

		$hal_mcp_ids[] = $hal_mcp_image_id;
	}

	if ( ! empty( $hal_mcp_invalid ) ) {
		return new WP_Error(
			'hal_mcp_invalid_product_image',
			sprintf(
				/* translators: %s: comma-separated list of media IDs that are not readable media library attachments. */
				__( 'These IDs are not usable as product images (missing or not readable media items): %s. Nothing was changed.', 'hal-mcp' ),
				implode( ', ', array_unique( $hal_mcp_invalid ) )
			)
		);
	}

	return array_values( array_unique( $hal_mcp_ids ) );
}

/**
 * Validates and normalizes the attributes field into a plain structure the
 * apply layer turns into WC_Product_Attribute objects: name + values (each
 * non-empty after sanitization), visible, for_variation.
 *
 * @param array<int, mixed> $attributes Raw attribute list.
 * @return array<int, array<string, mixed>>|WP_Error
 */
function hal_mcp_product_build_attributes( array $attributes ) {

	$hal_mcp_clean = [];

	foreach ( $attributes as $hal_mcp_attribute ) {
		if ( ! is_array( $hal_mcp_attribute )
			|| ! isset( $hal_mcp_attribute['name'], $hal_mcp_attribute['values'] )
			|| ! is_array( $hal_mcp_attribute['values'] ) ) {
			return new WP_Error(
				'hal_mcp_invalid_attribute',
				__( 'Each attribute needs a name and a values array.', 'hal-mcp' )
			);
		}

		$hal_mcp_name   = sanitize_text_field( trim( (string) $hal_mcp_attribute['name'] ) );
		$hal_mcp_values = array_values( array_filter( array_map(
			static fn( $hal_mcp_value ) => sanitize_text_field( trim( (string) $hal_mcp_value ) ),
			$hal_mcp_attribute['values']
		) ) );

		if ( '' === $hal_mcp_name || empty( $hal_mcp_values ) ) {
			return new WP_Error(
				'hal_mcp_invalid_attribute',
				__( 'Attribute names and values must not be empty.', 'hal-mcp' )
			);
		}

		$hal_mcp_clean[] = [
			'name'          => $hal_mcp_name,
			'values'        => $hal_mcp_values,
			'visible'       => ! isset( $hal_mcp_attribute['visible'] ) || (bool) $hal_mcp_attribute['visible'],
			'for_variation' => ! empty( $hal_mcp_attribute['for_variation'] ),
		];
	}

	return $hal_mcp_clean;
}

/**
 * Reads the CURRENT values of exactly the given field keys from the live
 * product through WooCommerce's own getters — the single definition used
 * both for the original snapshot at request-creation time and for the
 * pre-apply fingerprint revalidation.
 *
 * @param int      $product_id Product or variation ID.
 * @param string[] $fields     Change-map keys.
 * @return array<string, mixed>|null null when the product does not exist.
 */
function hal_mcp_product_read_current( int $product_id, array $fields ) {

	$product = function_exists( 'wc_get_product' ) ? wc_get_product( $product_id ) : null;

	if ( ! $product instanceof WC_Product ) {
		return null;
	}

	$hal_mcp_current = [];

	foreach ( $fields as $hal_mcp_field ) {
		switch ( $hal_mcp_field ) {
			case 'name':
				$hal_mcp_current['name'] = (string) $product->get_name();
				break;
			case 'description':
				$hal_mcp_current['description'] = (string) $product->get_description();
				break;
			case 'short_description':
				$hal_mcp_current['short_description'] = (string) $product->get_short_description();
				break;
			case 'regular_price':
				$hal_mcp_current['regular_price'] = (string) $product->get_regular_price();
				break;
			case 'sale_price':
				$hal_mcp_current['sale_price'] = (string) $product->get_sale_price();
				break;
			case 'sku':
				$hal_mcp_current['sku'] = (string) $product->get_sku();
				break;
			case 'manage_stock':
				$hal_mcp_current['manage_stock'] = (bool) $product->get_manage_stock();
				break;
			case 'stock_quantity':
				$hal_mcp_current['stock_quantity'] = (int) $product->get_stock_quantity();
				break;
			case 'stock_status':
				$hal_mcp_current['stock_status'] = (string) $product->get_stock_status();
				break;
			case 'categories':
				$hal_mcp_current['categories'] = array_map( 'intval', wp_get_post_terms( $product_id, 'product_cat', [ 'fields' => 'ids' ] ) );
				break;
			case 'images':
				// The effective image list is exactly what
				// hal_mcp_product_apply_change() writes from the `images`
				// input: the product image first, then the gallery.
				// Fingerprinting the gallery alone let a changed product
				// image slip past the pre-apply conflict check.
				$hal_mcp_images = array_map( 'intval', $product->get_gallery_image_ids() );

				if ( (int) $product->get_image_id() > 0 ) {
					array_unshift( $hal_mcp_images, (int) $product->get_image_id() );
				}

				$hal_mcp_current['images'] = $hal_mcp_images;
				break;
			case 'attributes':
				// Only the human-authored shape (name/values/visible/for_variation)
				// is fingerprinted — WooCommerce's internal attribute objects
				// carry runtime fields that would make every read look changed.
				$hal_mcp_attribute_map = [];
				foreach ( (array) $product->get_attributes() as $hal_mcp_key => $hal_mcp_attribute ) {
					if ( $hal_mcp_attribute instanceof WC_Product_Attribute ) {
						$hal_mcp_attribute_map[ (string) $hal_mcp_key ] = [
							'name'          => $hal_mcp_attribute->get_name(),
							'values'        => array_map( 'strval', (array) $hal_mcp_attribute->get_options() ),
							'visible'       => (bool) $hal_mcp_attribute->get_visible(),
							'for_variation' => (bool) $hal_mcp_attribute->get_variation(),
						];
					}
				}
				$hal_mcp_current['attributes'] = $hal_mcp_attribute_map;
				break;
		}
	}

	return $hal_mcp_current;
}

/**
 * THE domain apply function for product changes (F10/F11 contract, shared
 * with the F17 apply handler). Loads the product with wc_get_product() and
 * saves THE SAME object, so the product's type is preserved — no
 * WC_Product_Simple is ever constructed here. Only fields present in the
 * payload and supported by the product's own type are applied; setters are
 * reached through the product object's own methods, never post meta.
 *
 * @param int                  $product_id Product or variation ID.
 * @param array<string, mixed> $payload    Change map (fields + optional
 *                                         requested_status).
 * @return array{applied: bool, product_id: int, new_status: string, type: string}|WP_Error
 */
function hal_mcp_product_apply_change( int $product_id, array $payload ) {

	if ( ! function_exists( 'wc_get_product' ) ) {
		return new WP_Error(
			'hal_mcp_woocommerce_inactive',
			__( 'WooCommerce is not active on this site.', 'hal-mcp' )
		);
	}

	$product = wc_get_product( $product_id );

	if ( ! $product instanceof WC_Product ) {
		return new WP_Error(
			'hal_mcp_product_not_found',
			__( 'No product was found with that ID.', 'hal-mcp' )
		);
	}

	if ( 'trash' === $product->get_status() ) {
		return new WP_Error(
			'hal_mcp_status_not_editable',
			__( 'This product is in the trash. Restore it first, then send the update again.', 'hal-mcp' )
		);
	}

	$product_type = 'product_variation' === $product->get_type() ? 'variation' : (string) $product->get_type();
	$hal_mcp_supported = hal_mcp_product_supported_fields( $product_type );

	foreach ( array_diff( array_keys( $payload ), [ 'requested_status', 'language' ] ) as $hal_mcp_field ) {
		if ( ! in_array( $hal_mcp_field, $hal_mcp_supported, true ) ) {
			return new WP_Error(
				'hal_mcp_field_unsupported_for_type',
				sprintf(
					/* translators: 1: field name. 2: product type. */
					__( 'The field "%1$s" is not supported for a product of type "%2$s".', 'hal-mcp' ),
					(string) $hal_mcp_field,
					$product_type
				)
			);
		}
	}

	// WooCommerce's own setters throw WC_Data_Exception for invalid values
	// (e.g. a duplicate SKU) — caught and turned into a clean WP_Error.
	try {
		if ( isset( $payload['name'] ) && method_exists( $product, 'set_name' ) ) {
			$product->set_name( (string) $payload['name'] );
		}
		if ( isset( $payload['description'] ) ) {
			$product->set_description( (string) $payload['description'] );
		}
		if ( isset( $payload['short_description'] ) ) {
			$product->set_short_description( (string) $payload['short_description'] );
		}
		if ( isset( $payload['regular_price'] ) && '' !== (string) $payload['regular_price'] ) {
			$product->set_regular_price( (string) $payload['regular_price'] );
		}
		if ( isset( $payload['sale_price'] ) ) {
			$product->set_sale_price( '' !== (string) $payload['sale_price'] ? (string) $payload['sale_price'] : '' );
		}
		if ( isset( $payload['sku'] ) ) {
			$product->set_sku( '' !== (string) $payload['sku'] ? (string) $payload['sku'] : '' );
		}
		if ( isset( $payload['manage_stock'] ) ) {
			$product->set_manage_stock( (bool) $payload['manage_stock'] );
		}
		if ( isset( $payload['stock_quantity'] ) ) {
			$product->set_stock_quantity( (int) $payload['stock_quantity'] );
		}
		if ( isset( $payload['stock_status'] ) ) {
			$product->set_stock_status( (string) $payload['stock_status'] );
		}
		if ( isset( $payload['images'] ) && is_array( $payload['images'] ) ) {
			$hal_mcp_image_ids = array_map( 'intval', $payload['images'] );

			$product->set_image_id( (int) ( $hal_mcp_image_ids[0] ?? 0 ) );
			$product->set_gallery_image_ids( array_slice( $hal_mcp_image_ids, 1 ) );
		}
		if ( isset( $payload['attributes'] ) && is_array( $payload['attributes'] ) && class_exists( 'WC_Product_Attribute' ) ) {
			$hal_mcp_position = 0;
			$hal_mcp_built    = [];

			foreach ( $payload['attributes'] as $hal_mcp_attribute ) {
				$hal_mcp_built_attribute = new WC_Product_Attribute();
				$hal_mcp_built_attribute->set_name( (string) $hal_mcp_attribute['name'] );
				$hal_mcp_built_attribute->set_options( array_map( 'strval', (array) $hal_mcp_attribute['values'] ) );
				$hal_mcp_built_attribute->set_position( $hal_mcp_position++ );
				$hal_mcp_built_attribute->set_visible( ! empty( $hal_mcp_attribute['visible'] ) );
				$hal_mcp_built_attribute->set_variation( ! empty( $hal_mcp_attribute['for_variation'] ) );
				$hal_mcp_built[] = $hal_mcp_built_attribute;
			}

			$product->set_attributes( $hal_mcp_built );
		}

		// Deliberately no set_status() unless the payload explicitly requests
		// one of the two F10-contract values. Publishing is reached
		// exclusively through an approved request — hal_mcp_apply_change_
		// request() has already verified the approver's capabilities with a
		// 'publish' effect by the time this line runs with requested_status.
		if ( isset( $payload['requested_status'] )
			&& in_array( $payload['requested_status'], [ 'draft', 'publish' ], true )
			&& $payload['requested_status'] !== $product->get_status() ) {
			$product->set_status( (string) $payload['requested_status'] );
		}

		$hal_mcp_saved_id = $product->save();
	} catch ( WC_Data_Exception $hal_mcp_exception ) {
		return new WP_Error( 'hal_mcp_product_save_failed', $hal_mcp_exception->getMessage() );
	}

	if ( ! $hal_mcp_saved_id ) {
		return new WP_Error(
			'hal_mcp_product_save_failed',
			__( 'Could not save the product.', 'hal-mcp' )
		);
	}

	if ( isset( $payload['categories'] ) && is_array( $payload['categories'] ) ) {
		wp_set_post_terms( $product_id, array_map( 'intval', $payload['categories'] ), 'product_cat' );
	}

	$hal_mcp_fresh = wc_get_product( $product_id );

	return [
		'applied'     => true,
		'product_id'  => $product_id,
		'new_status'  => $hal_mcp_fresh instanceof WC_Product ? (string) $hal_mcp_fresh->get_status() : '',
		'type'        => $product_type,
	];
}

/**
 * Live fingerprint revalidation for 'update-product' requests (F17
 * contract: fetch the target's CURRENT original fields, never echo the
 * stored snapshot).
 *
 * @param array<string, mixed> $request Request data from hal_mcp_get_change_request().
 * @return string Fingerprint of the live original fields, or '' when there
 *                is nothing to compare (or the target no longer exists).
 */
function hal_mcp_product_revalidate_fingerprint( array $request ): string {

	$hal_mcp_payload   = (array) ( $request['payload'] ?? [] );
	$hal_mcp_targets   = (array) ( $request['targets'] ?? [] );
	$hal_mcp_product_id = (int) ( $hal_mcp_targets[0]['id'] ?? 0 );

	if ( $hal_mcp_product_id < 1 ) {
		return '';
	}

	$hal_mcp_fields = array_values( array_diff( array_keys( $hal_mcp_payload ), [ 'requested_status', 'language' ] ) );

	// Status-only requests read an EMPTY original ([]) whose fingerprint
	// matches the stored fingerprint of their empty snapshot; a GONE target
	// reads null and the '' return turns into an apply-time conflict.
	$hal_mcp_current = hal_mcp_product_read_current( $hal_mcp_product_id, $hal_mcp_fields );

	if ( null === $hal_mcp_current ) {
		return '';
	}

	return hal_mcp_fingerprint( $hal_mcp_current );
}

/**
 * Registers the 'update-product' apply handler (F17). Runs at file load so
 * the approval path works for the whole lifetime of this module — the same
 * lifetime the abilities themselves have (the bootstrap requires this file
 * only when the Abilities API is present).
 *
 * @return void
 */
function hal_mcp_register_product_apply_handler(): void {

	hal_mcp_register_apply_handler(
		'update-product',
		static function ( array $request ) {
			$hal_mcp_targets = (array) ( $request['targets'] ?? [] );
			$hal_mcp_product_id = (int) ( $hal_mcp_targets[0]['id'] ?? 0 );
			$hal_mcp_payload = (array) ( $request['payload'] ?? [] );

			$hal_mcp_result = hal_mcp_product_apply_change( $hal_mcp_product_id, $hal_mcp_payload );

			if ( is_wp_error( $hal_mcp_result ) ) {
				return $hal_mcp_result;
			}

			return sprintf(
				/* translators: 1: product ID. 2: product type. 3: new status. */
				__( 'Product #%1$d (type %2$s) updated; current status: %3$s.', 'hal-mcp' ),
				$hal_mcp_result['product_id'],
				$hal_mcp_result['type'],
				$hal_mcp_result['new_status']
			);
		},
		'hal_mcp_product_revalidate_fingerprint'
	);
}

hal_mcp_register_product_apply_handler();

add_action( 'wp_abilities_api_init', 'hal_mcp_register_write_product_abilities' );

/**
 * Registers the hal/create-product and hal/update-product abilities.
 *
 * @return void
 */
function hal_mcp_register_write_product_abilities(): void {

	wp_register_ability(
		'hal/create-product',
		[
			'label'       => __( 'Create a draft product', 'hal-mcp' ),
			'description' => __( 'Creates a new WooCommerce product as a draft (simple or variable, optionally with description, prices, SKU, stock, categories, images, and attributes). Never publishes directly: pass requested_status="publish" to also queue an approval request that a human approves in wp-admin — the response then carries request_id and state.', 'hal-mcp' ),
			'category'    => 'hal-products',

			'input_schema' => [
				'type'                 => 'object',
				'properties'           => [
					'name'              => [
						'type'        => 'string',
						'description' => __( 'The product name.', 'hal-mcp' ),
					],
					'type'              => [
						'type'        => 'string',
						'enum'        => [ 'simple', 'variable' ],
						'default'     => 'simple',
						'description' => __( 'The WooCommerce product type to create. Default "simple". A variable product is created without variations — variations are managed in wp-admin. Attached types are never created from here.', 'hal-mcp' ),
					],
					'description'       => [
						'type'        => 'string',
						'description' => __( 'The full product description. Basic HTML is allowed.', 'hal-mcp' ),
					],
					'short_description' => [
						'type'        => 'string',
						'description' => __( 'Optional short description shown near the price/add-to-cart area.', 'hal-mcp' ),
					],
					'regular_price'     => [
						'type'        => 'string',
						'description' => __( 'Optional regular price as a plain number, e.g. "19.99". Ignored for type "variable" (prices live on its variations).', 'hal-mcp' ),
					],
					'sale_price'        => [
						'type'        => 'string',
						'description' => __( 'Optional sale price as a plain number.', 'hal-mcp' ),
					],
					'sku'               => [
						'type'        => 'string',
						'description' => __( 'Optional SKU. Must be unique across the whole site or the ability will return an error.', 'hal-mcp' ),
					],
					'manage_stock'      => [
						'type'        => 'boolean',
						'description' => __( 'Optional: track stock for this product.', 'hal-mcp' ),
					],
					'stock_quantity'    => [
						'type'        => 'integer',
						'minimum'     => 0,
						'description' => __( 'Optional stock quantity when manage_stock is true.', 'hal-mcp' ),
					],
					'stock_status'      => [
						'type'        => 'string',
						'enum'        => [ 'instock', 'outofstock', 'onbackorder' ],
						'description' => __( 'Optional stock status.', 'hal-mcp' ),
					],
					'categories'        => [
						'type'        => 'array',
						'items'       => [
							'type' => [ 'string', 'integer' ],
						],
						'description' => __( 'Optional product category names or IDs. Every category must already exist; none are created automatically.', 'hal-mcp' ),
					],
					'images'            => [
						'type'        => 'array',
						'items'       => [
							'type' => 'integer',
						],
						'description' => __( 'Optional media library attachment IDs: the first becomes the product image, the rest the gallery. Every ID must be a readable media item.', 'hal-mcp' ),
					],
					'attributes'        => [
						'type'        => 'array',
						'items'       => [
							'type'       => 'object',
							'properties' => [
								'name'          => [ 'type' => 'string' ],
								'values'        => [
									'type'  => 'array',
									'items' => [ 'type' => 'string' ],
								],
								'visible'       => [ 'type' => 'boolean' ],
								'for_variation' => [
									'type'        => 'boolean',
									'description' => __( 'True marks the attribute as variation-defining (relevant for type "variable").', 'hal-mcp' ),
								],
							],
							'required'   => [ 'name', 'values' ],
						],
						'description' => __( 'Optional product attributes (name + values).', 'hal-mcp' ),
					],
					'language'          => [
						'type'        => 'string',
						'description' => __( 'Optional language. Only languages the site actually offers are accepted; until a translations integration is active that is the site locale (a no-op, recorded on any approval request).', 'hal-mcp' ),
					],
					'requested_status'  => [
						'type'        => 'string',
						'enum'        => [ 'draft', 'publish' ],
						'default'     => 'draft',
						'description' => __( '"draft" (default) creates the product as a draft directly. "publish" still creates it as a draft AND queues an approval request for the publishing step — never an immediate publish.', 'hal-mcp' ),
					],
				],
				'required'             => [ 'name' ],
				'additionalProperties' => false,
			],

			'output_schema' => [
				'type'       => 'object',
				'properties' => [
					'id'         => [
						'type'        => 'integer',
						'description' => __( 'The created draft product\'s ID (a draft always exists even when a publish approval is queued).', 'hal-mcp' ),
					],
					'type'       => [ 'type' => 'string' ],
					'status'     => [ 'type' => 'string' ],
					'edit_url'   => [ 'type' => 'string' ],
					'request_id' => [
						'type'        => 'integer',
						'description' => __( 'The queued approval request\'s ID when requested_status was "publish", otherwise 0. This is the REQUEST\'s ID, not a content ID.', 'hal-mcp' ),
					],
					'state'      => [
						'type'        => 'string',
						'description' => __( 'The model-facing state: "pending_approval" when a change request was queued and is awaiting admin approval (the store state remains "pending"), otherwise empty.', 'hal-mcp' ),
					],
				],
			],

			'permission_callback' => static fn() => hal_mcp_permission( 'product', 'create', 0, [ 'ability' => 'hal/create-product' ] ),

			'execute_callback' => function ( $input ) {

				if ( ! function_exists( 'wc_get_product' ) ) {
					return new WP_Error(
						'hal_mcp_woocommerce_inactive',
						__( 'WooCommerce is not active on this site.', 'hal-mcp' )
					);
				}

				$hal_mcp_change = hal_mcp_product_build_change_map( is_array( $input ) ? $input : [] );

				if ( is_wp_error( $hal_mcp_change ) ) {
					return $hal_mcp_change;
				}

				$hal_mcp_name = isset( $hal_mcp_change['name'] ) ? (string) $hal_mcp_change['name'] : '';

				if ( '' === $hal_mcp_name ) {
					return new WP_Error(
						'hal_mcp_missing_name',
						__( 'name must not be empty.', 'hal-mcp' )
					);
				}

				$hal_mcp_type = (string) ( $input['type'] ?? 'simple' );

				if ( ! in_array( $hal_mcp_type, [ 'simple', 'variable' ], true ) ) {
					return new WP_Error(
						'hal_mcp_invalid_product_type',
						__( 'type accepts only "simple" or "variable".', 'hal-mcp' )
					);
				}

				$hal_mcp_wants_publish = ( 'publish' === ( $hal_mcp_change['requested_status'] ?? 'draft' ) );

				// Only the created type's supported fields may ride creation —
				// a variable parent simply has no price/stock of its own.
				$hal_mcp_supported = hal_mcp_product_supported_fields( $hal_mcp_type );

				foreach ( array_diff( array_keys( $hal_mcp_change ), [ 'requested_status', 'language' ] ) as $hal_mcp_field ) {
					if ( ! in_array( $hal_mcp_field, $hal_mcp_supported, true ) ) {
						return new WP_Error(
							'hal_mcp_field_unsupported_for_type',
							sprintf(
								/* translators: 1: field name. 2: product type. */
								__( 'The field "%1$s" is not supported for a product of type "%2$s".', 'hal-mcp' ),
								(string) $hal_mcp_field,
								$hal_mcp_type
							)
						);
					}
				}

				// WooCommerce's own CRUD classes, by requested type — never a
				// Simple standing in for another type.
				$hal_mcp_class = 'variable' === $hal_mcp_type ? 'WC_Product_Variable' : 'WC_Product_Simple';

				if ( ! class_exists( $hal_mcp_class ) ) {
					return new WP_Error(
						'hal_mcp_woocommerce_inactive',
						__( 'WooCommerce is not active on this site.', 'hal-mcp' )
					);
				}

				// WooCommerce's own setters throw WC_Data_Exception for invalid
				// values (e.g. a duplicate SKU) — caught and turned into a
				// clean WP_Error rather than an uncaught fatal error.
				try {
					$product = new $hal_mcp_class();
					$product->set_name( $hal_mcp_name );

					if ( isset( $hal_mcp_change['description'] ) ) {
						$product->set_description( (string) $hal_mcp_change['description'] );
					}
					if ( isset( $hal_mcp_change['short_description'] ) ) {
						$product->set_short_description( (string) $hal_mcp_change['short_description'] );
					}
					if ( isset( $hal_mcp_change['regular_price'] ) && '' !== (string) $hal_mcp_change['regular_price'] ) {
						$product->set_regular_price( (string) $hal_mcp_change['regular_price'] );
					}
					if ( isset( $hal_mcp_change['sale_price'] ) ) {
						$product->set_sale_price( (string) $hal_mcp_change['sale_price'] );
					}
					if ( isset( $hal_mcp_change['sku'] ) && '' !== (string) $hal_mcp_change['sku'] ) {
						$product->set_sku( (string) $hal_mcp_change['sku'] );
					}
					if ( isset( $hal_mcp_change['manage_stock'] ) ) {
						$product->set_manage_stock( (bool) $hal_mcp_change['manage_stock'] );
					}
					if ( isset( $hal_mcp_change['stock_quantity'] ) ) {
						$product->set_stock_quantity( (int) $hal_mcp_change['stock_quantity'] );
					}
					if ( isset( $hal_mcp_change['stock_status'] ) ) {
						$product->set_stock_status( (string) $hal_mcp_change['stock_status'] );
					}
					if ( isset( $hal_mcp_change['images'] ) && is_array( $hal_mcp_change['images'] ) ) {
						$hal_mcp_image_ids = array_map( 'intval', $hal_mcp_change['images'] );

						$product->set_image_id( (int) ( $hal_mcp_image_ids[0] ?? 0 ) );
						$product->set_gallery_image_ids( array_slice( $hal_mcp_image_ids, 1 ) );
					}
					if ( isset( $hal_mcp_change['attributes'] ) && is_array( $hal_mcp_change['attributes'] ) && class_exists( 'WC_Product_Attribute' ) ) {
						$hal_mcp_position = 0;
						$hal_mcp_built    = [];

						foreach ( $hal_mcp_change['attributes'] as $hal_mcp_attribute ) {
							$hal_mcp_built_attribute = new WC_Product_Attribute();
							$hal_mcp_built_attribute->set_name( (string) $hal_mcp_attribute['name'] );
							$hal_mcp_built_attribute->set_options( array_map( 'strval', (array) $hal_mcp_attribute['values'] ) );
							$hal_mcp_built_attribute->set_position( $hal_mcp_position++ );
							$hal_mcp_built_attribute->set_visible( ! empty( $hal_mcp_attribute['visible'] ) );
							$hal_mcp_built_attribute->set_variation( ! empty( $hal_mcp_attribute['for_variation'] ) );
							$hal_mcp_built[] = $hal_mcp_built_attribute;
						}

						$product->set_attributes( $hal_mcp_built );
					}

					// Hard-coded literal, exactly as in hal/create-post — never
					// derived from $input, no matter what a caller sends.
					$product->set_status( 'draft' );

					$product_id = $product->save();
				} catch ( WC_Data_Exception $hal_mcp_exception ) {
					return new WP_Error( 'hal_mcp_product_save_failed', $hal_mcp_exception->getMessage() );
				}

				if ( ! $product_id ) {
					return new WP_Error(
						'hal_mcp_product_save_failed',
						__( 'Could not save the product.', 'hal-mcp' )
					);
				}

				$product_id = (int) $product_id;

				if ( isset( $hal_mcp_change['categories'] ) && is_array( $hal_mcp_change['categories'] ) ) {
					wp_set_post_terms( $product_id, array_map( 'intval', $hal_mcp_change['categories'] ), 'product_cat' );
				}

				$hal_mcp_request = [
					'request_id' => 0,
					'state'      => '',
				];

				if ( $hal_mcp_wants_publish ) {
					$hal_mcp_queued = hal_mcp_create_change_request(
						[
							'operation'         => 'update-product',
							'payload'           => [ 'requested_status' => 'publish' ],
							'targets'           => [
								[
									'type' => 'product',
									'id'   => $product_id,
								],
							],
							'original_snapshot' => [],
							'language'          => isset( $hal_mcp_change['language'] ) ? (string) $hal_mcp_change['language'] : '',
							'origin'            => [ 'source' => 'mcp' ],
						]
					);

					if ( is_wp_error( $hal_mcp_queued ) ) {
						return $hal_mcp_queued;
					}

					$hal_mcp_request = [
						'request_id' => (int) $hal_mcp_queued['request_id'],
						// Model-facing answer: 'pending' in the store becomes
						// 'pending_approval' for the model (roadmap §4.3/F17).
						'state'      => hal_mcp_request_model_state( (string) $hal_mcp_queued['state'] ),
					];
				}

				return [
					'id'         => $product_id,
					'type'       => $hal_mcp_type,
					'status'     => 'draft',
					'edit_url'   => (string) get_edit_post_link( $product_id, 'raw' ),
					'request_id' => $hal_mcp_request['request_id'],
					'state'      => $hal_mcp_request['state'],
				];
			},

			'meta' => [ 'mcp' => [ 'public' => true ] ],
		]
	);

	wp_register_ability(
		'hal/update-product',
		[
			'label'       => __( 'Update a product', 'hal-mcp' ),
			'description' => __( 'Updates a product\'s name, descriptions, prices, SKU, stock, categories, images, attributes, and/or language, and optionally requests a status change via requested_status ("draft"/"publish"). The product\'s type is preserved — a Simple is never created as a stand-in. A draft product (or a variation of a draft product) with no status change is edited directly; anything else becomes one atomic approval request (request_id + state in the response) for a human to approve in wp-admin. A variation is addressed by its own ID; its write-path decision follows its PARENT\'s status. Requests with no actual effect are refused.', 'hal-mcp' ),
			'category'    => 'hal-products',

			'input_schema' => [
				'type'                 => 'object',
				'properties'           => [
					'product_id'        => [
						'type'        => 'integer',
						'minimum'     => 1,
						'description' => __( 'The numeric ID of the product (or variation) to update.', 'hal-mcp' ),
					],
					'name'              => [
						'type'        => 'string',
						'description' => __( 'New name. Not supported for variations (their name is derived from their attributes). Omit to leave unchanged.', 'hal-mcp' ),
					],
					'description'       => [
						'type'        => 'string',
						'description' => __( 'New full description. Omit to leave unchanged.', 'hal-mcp' ),
					],
					'short_description' => [
						'type'        => 'string',
						'description' => __( 'New short description. Omit to leave unchanged.', 'hal-mcp' ),
					],
					'regular_price'     => [
						'type'        => 'string',
						'description' => __( 'New regular price as a plain number, e.g. "19.99". Not supported for a variable parent (prices live on its variations). Omit to leave unchanged.', 'hal-mcp' ),
					],
					'sale_price'        => [
						'type'        => 'string',
						'description' => __( 'New sale price as a plain number. Omit to leave unchanged.', 'hal-mcp' ),
					],
					'sku'               => [
						'type'        => 'string',
						'description' => __( 'New SKU (must be unique site-wide). Omit to leave unchanged.', 'hal-mcp' ),
					],
					'manage_stock'      => [
						'type'        => 'boolean',
						'description' => __( 'Whether to track stock. Omit to leave unchanged.', 'hal-mcp' ),
					],
					'stock_quantity'    => [
						'type'        => 'integer',
						'minimum'     => 0,
						'description' => __( 'New stock quantity. Omit to leave unchanged.', 'hal-mcp' ),
					],
					'stock_status'      => [
						'type'        => 'string',
						'enum'        => [ 'instock', 'outofstock', 'onbackorder' ],
						'description' => __( 'New stock status. Omit to leave unchanged.', 'hal-mcp' ),
					],
					'categories'        => [
						'type'        => 'array',
						'items'       => [
							'type' => [ 'string', 'integer' ],
						],
						'description' => __( 'New full product category list (names or IDs, all must already exist). Replaces the product\'s categories. Not supported for variations. Omit to leave unchanged.', 'hal-mcp' ),
					],
					'images'            => [
						'type'        => 'array',
						'items'       => [
							'type' => 'integer',
						],
						'description' => __( 'New full image list (media IDs): the first becomes the product image, the rest the gallery. Every ID must be a readable media item. Omit to leave unchanged.', 'hal-mcp' ),
					],
					'attributes'        => [
						'type'        => 'array',
						'items'       => [
							'type'       => 'object',
							'properties' => [
								'name'          => [ 'type' => 'string' ],
								'values'        => [
									'type'  => 'array',
									'items' => [ 'type' => 'string' ],
								],
								'visible'       => [ 'type' => 'boolean' ],
								'for_variation' => [ 'type' => 'boolean' ],
							],
							'required'   => [ 'name', 'values' ],
						],
						'description' => __( 'New full attribute list. Replaces the product\'s attributes. Omit to leave unchanged.', 'hal-mcp' ),
					],
					'language'          => [
						'type'        => 'string',
						'description' => __( 'Language for the product. Only languages the site actually offers are accepted; until a translations integration is active that is the site locale (a no-op, recorded on any approval request). Accepted without re-sending other fields.', 'hal-mcp' ),
					],
					'requested_status'  => [
						'type'        => 'string',
						'enum'        => [ 'draft', 'publish' ],
						'description' => __( 'Optional status change request. The request never forces the status directly — the policy decides: on a draft, "publish" queues an approval; on a protected product (or a variation of one), both values ride the same approval request. Omit to keep the current status.', 'hal-mcp' ),
					],
				],
				'required'             => [ 'product_id' ],
				'additionalProperties' => false,
			],

			'output_schema' => [
				'type'       => 'object',
				'properties' => [
					'applied_directly'   => [
						'type'        => 'boolean',
						'description' => __( 'True only when the target product itself was edited in place. False when the change was stored as an approval request — a request alone never claims to have been applied.', 'hal-mcp' ),
					],
					'id'                 => [
						'type'        => 'integer',
						'description' => __( 'The edited product\'s ID when applied directly. 0 when only an approval request was prepared — do not treat 0 as a content ID.', 'hal-mcp' ),
					],
					'type'               => [
						'type'        => 'string',
						'description' => __( 'The product\'s own type — unchanged by any write in this version.', 'hal-mcp' ),
					],
					'status'             => [
						'type'        => 'string',
						'description' => __( 'The target\'s CURRENT status — unchanged until a human approves and applies the request.', 'hal-mcp' ),
					],
					'request_id'         => [
						'type'        => 'integer',
						'description' => __( 'The queued approval request\'s ID when one was prepared, otherwise 0. This is the REQUEST\'s ID, not a content ID.', 'hal-mcp' ),
					],
					'state'              => [
						'type'        => 'string',
						'description' => __( 'The model-facing state: "pending_approval" when a change request was queued and is awaiting admin approval (the store state remains "pending"), otherwise empty.', 'hal-mcp' ),
					],
					'proposed_update_for' => [
						'type'        => 'integer',
						'description' => __( 'The target product\'s ID when a request was prepared for it (0 when applied directly). Replaces the old proposal-product semantics: no copy product exists anymore.', 'hal-mcp' ),
					],
					'edit_url'           => [
						'type'        => 'string',
						'description' => __( 'The edited product\'s edit URL when applied directly; empty for a prepared request (the review happens in the admin approval screen).', 'hal-mcp' ),
					],
				],
			],

			'permission_callback' => static fn( $input = [] ) => hal_mcp_permission( 'product', 'edit', (int) ( $input['product_id'] ?? 0 ), [ 'ability' => 'hal/update-product' ] ),

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

				$hal_mcp_change = hal_mcp_product_build_change_map( is_array( $input ) ? $input : [] );

				if ( is_wp_error( $hal_mcp_change ) ) {
					return $hal_mcp_change;
				}

				// Same variation scoping as hal/get-product: wc_get_product()
				// returns WC_Product_Variation for a variation ID, and F11
				// explicitly supports updating one — through its parent.
				$hal_mcp_post_type = get_post_type( $product_id );

				if ( ! in_array( $hal_mcp_post_type, [ 'product', 'product_variation' ], true ) ) {
					return new WP_Error(
						'hal_mcp_product_not_found',
						__( 'No product was found with that ID.', 'hal-mcp' )
					);
				}

				$target_product = wc_get_product( $product_id );

				if ( ! $target_product instanceof WC_Product ) {
					return new WP_Error(
						'hal_mcp_product_not_found',
						__( 'No product was found with that ID.', 'hal-mcp' )
					);
				}

				$is_variation = ( 'product_variation' === $hal_mcp_post_type );

				// F11: a variation's write-path decision follows its PARENT —
				// editing a variation of a published product is a protected
				// change, not a live edit.
				$hal_mcp_gate_id = $product_id;

				if ( $is_variation ) {
					$hal_mcp_parent_id = (int) $target_product->get_parent_id();

					if ( $hal_mcp_parent_id < 1 || ! wc_get_product( $hal_mcp_parent_id ) instanceof WC_Product ) {
						return new WP_Error(
							'hal_mcp_product_not_found',
							__( 'No product was found with that ID.', 'hal-mcp' )
						);
					}

					$hal_mcp_gate_id = $hal_mcp_parent_id;
				}

				// Object-level permission re-check (F05): the pre-execute
				// permission_callback saw the same ID, but the Abilities API
				// does not validate permission inputs — this is the
				// enforcement point that cannot be bypassed. For a variation,
				// both the parent and the variation itself must be editable.
				if ( ! hal_mcp_permission( 'product', 'edit', $hal_mcp_gate_id, [ 'ability' => 'hal/update-product' ] )
					|| ( $is_variation && ! hal_mcp_permission( 'product', 'edit', $product_id, [ 'ability' => 'hal/update-product', 'reason' => 'variation_object' ] ) ) ) {
					return new WP_Error(
						'hal_mcp_forbidden_object',
						__( 'You are not allowed to edit this product.', 'hal-mcp' )
					);
				}

				// Per-type field support (F11: الأنواع الملحقة تعرض حقولها
				// المدعومة فقط) — refused here, before anything is stored.
				$hal_mcp_product_type = $is_variation ? 'variation' : (string) $target_product->get_type();
				$hal_mcp_supported    = hal_mcp_product_supported_fields( $hal_mcp_product_type );

				foreach ( array_diff( array_keys( $hal_mcp_change ), [ 'requested_status', 'language' ] ) as $hal_mcp_field ) {
					if ( ! in_array( $hal_mcp_field, $hal_mcp_supported, true ) ) {
						return new WP_Error(
							'hal_mcp_field_unsupported_for_type',
							sprintf(
								/* translators: 1: field name. 2: product type. */
								__( 'The field "%1$s" is not supported for a product of type "%2$s".', 'hal-mcp' ),
								(string) $hal_mcp_field,
								$hal_mcp_product_type
							)
						);
					}
				}

				$hal_mcp_current_status = (string) $target_product->get_status();

				$hal_mcp_wants_status_change = isset( $hal_mcp_change['requested_status'] )
					&& $hal_mcp_change['requested_status'] !== $hal_mcp_current_status;

				// Guard fix (F10/F11: write-products.php:244–248): requested_status
				// alone and a supported language field are accepted without a
				// content re-send — only a change with NO effect at all is
				// refused (same status, no fields, language equal to the site
				// locale).
				$hal_mcp_field_keys = array_values( array_diff( array_keys( $hal_mcp_change ), [ 'requested_status', 'language' ] ) );

				$hal_mcp_language_noop = ! isset( $hal_mcp_change['language'] )
					|| $hal_mcp_change['language'] === sanitize_key( (string) get_locale() );

				if ( empty( $hal_mcp_field_keys ) && ! $hal_mcp_wants_status_change && $hal_mcp_language_noop ) {
					return new WP_Error(
						'hal_mcp_nothing_to_update',
						__( 'Provide at least one supported product field, a language, or a requested_status different from the current status.', 'hal-mcp' )
					);
				}

				// Shared policy decision (F05) — decided on the PARENT status
				// for variations: only drafts edit directly; protected
				// statuses become a request; trash is refused.
				$write_decision = hal_mcp_decide_write_path(
					'product',
					(string) get_post_status( $hal_mcp_gate_id ),
					[
						'operation'    => 'hal/update-product',
						'field_impact' => ( $hal_mcp_wants_status_change ? 'protected' : '' ),
					]
				);

				if ( 'deny' === $write_decision['decision'] ) {
					return new WP_Error(
						'hal_mcp_status_not_editable',
						__( 'This product is in the trash. Restore it first, then send the update again.', 'hal-mcp' )
					);
				}

				// --- Case 1: draft (or variation of a draft), no status
				// change — edit directly through the same domain function the
				// approval path uses. ---
				if ( 'direct' === $write_decision['decision'] ) {

					// Optimistic re-check immediately before the write: re-run
					// the shared decision on the FRESH parent status (a human
					// may have published it concurrently in wp-admin). If it
					// is no longer directly editable, abort without writing.
					$fresh_decision = hal_mcp_decide_write_path( 'product', (string) get_post_status( $hal_mcp_gate_id ), [ 'operation' => 'hal/update-product' ] );

					if ( 'direct' !== $fresh_decision['decision'] ) {
						// The abort is a control event, not a caller denial —
						// logged here because a bare WP_Error return would
						// leave no audit trace of a write that backed off.
						hal_mcp_log_permission_denial(
							'hal/update-product',
							'fresh status re-check failed; direct write aborted with no changes',
							[
								'stage'       => 'direct_write_aborted',
								'object_type' => 'product',
								'object_id'   => $product_id,
							]
						);

						return new WP_Error(
							'hal_mcp_product_status_changed',
							__( 'This product was published or protected by someone else while this update was being processed. No changes were made — please retry the request.', 'hal-mcp' )
						);
					}

					$hal_mcp_field_payload = array_diff_key( $hal_mcp_change, [ 'requested_status' => true ] );

					$hal_mcp_result = hal_mcp_product_apply_change( $product_id, $hal_mcp_field_payload );

					if ( is_wp_error( $hal_mcp_result ) ) {
						return $hal_mcp_result;
					}

					return [
						'applied_directly'    => true,
						'id'                  => $product_id,
						'type'                => $hal_mcp_product_type,
						'status'              => (string) $hal_mcp_result['new_status'],
						'request_id'          => 0,
						'state'               => '',
						'proposed_update_for' => 0,
						'edit_url'            => (string) get_edit_post_link( $product_id, 'raw' ),
					];
				}

				// --- Case 2: protected target (or a requested status change) —
				// one atomic approval request. The live product is never
				// touched; no proposal copy product is created anymore.
				$hal_mcp_payload = array_diff_key( $hal_mcp_change, [ 'language' => true ] );

				$hal_mcp_original_fields = array_values( array_diff( array_keys( $hal_mcp_payload ), [ 'requested_status' ] ) );
				$hal_mcp_snapshot        = empty( $hal_mcp_original_fields )
					? []
					: (array) hal_mcp_product_read_current( $product_id, $hal_mcp_original_fields );

				$hal_mcp_queued = hal_mcp_create_change_request(
					[
						'operation'         => 'update-product',
						'payload'           => $hal_mcp_payload,
						'targets'           => [
							[
								'type' => 'product',
								'id'   => $product_id,
							],
						],
						'original_snapshot' => $hal_mcp_snapshot,
						'language'          => isset( $hal_mcp_change['language'] ) ? (string) $hal_mcp_change['language'] : '',
						'origin'            => [ 'source' => 'mcp' ],
					]
				);

				if ( is_wp_error( $hal_mcp_queued ) ) {
					return $hal_mcp_queued;
				}

				return [
					'applied_directly'    => false,
					'id'                  => 0,
					'type'                => $hal_mcp_product_type,
					'status'              => $hal_mcp_current_status,
					'request_id'          => (int) $hal_mcp_queued['request_id'],
					// Model-facing answer: 'pending_approval' (roadmap §4.3/F17).
					'state'               => hal_mcp_request_model_state( (string) $hal_mcp_queued['state'] ),
					'proposed_update_for' => $product_id,
					'edit_url'            => '',
				];
			},

			'meta' => [ 'mcp' => [ 'public' => true ] ],
		]
	);
}
