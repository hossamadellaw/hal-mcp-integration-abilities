<?php
/**
 * hal-mcp-abilities — generic custom-content abilities (F20).
 *
 * hal/get-content, hal/search-content, hal/create-content, hal/update-content
 * address the AUTHORIZED public custom post types
 * (hal_mcp_content_authorized_post_types(), includes/permissions.php), and
 * hal/get-request-status lets a request's OWN requester follow its state.
 *
 * The boundary rules this file enforces in code:
 * - Specialized types (post, page, attachment, product) are REFUSED with a
 *   pointer to their dedicated abilities — no generic path may bypass the
 *   products or editor policy (F20: «لا مسار generic يتجاوز سياسة المنتجات
 *   أو المحرر»).
 * - The plugin's internal request store, users, orders, options, and any
 *   other non-authorized type are refused: "public post type" alone makes
 *   nothing addressable, and the authorization list is filter-correctable
 *   by the manager (§4.4).
 * - The field set is the schema fields only — title, content, excerpt,
 *   language, requested_status. There is deliberately NO generic setter for
 *   arbitrary post meta («لا setter عام لكل postmeta»); SEO and translation
 *   fields join through their integrations in later roadmap steps (F22/F23),
 *   not through this file.
 * - Writes follow the F10 contract: draft edits direct, protected statuses
 *   and publish requests become F17 approval requests, and every request
 *   carries a fingerprint of exactly the original fields being changed.
 * - hal/get-request-status returns state/preview/result for the allowed
 *   requester only (author or manager) and NEVER the payload or snapshot —
 *   an unauthorized caller cannot even confirm a request exists.
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
 * Validates a content_type input against the authorization policy. Returns
 * a WP_Error that names the right specialized ability for post/page/product
 * targets, and a flat refusal for everything else.
 *
 * @param string $content_type Content type slug as supplied by the model.
 * @return WP_Error|null null when the type is authorized.
 */
function hal_mcp_content_validate_type( string $content_type ): ?WP_Error {

	if ( in_array( $content_type, [ 'post', 'page', 'attachment', 'product' ], true ) ) {
		return new WP_Error(
			'hal_mcp_specialized_content_type',
			sprintf(
				/* translators: %s: content type slug. */
				__( '"%s" has dedicated abilities (hal/get-post, hal/get-page, hal/get-product, ...). The generic content tools do not cover it.', 'hal-mcp' ),
				$content_type
			)
		);
	}

	if ( ! hal_mcp_is_content_object_type( $content_type ) ) {
		return new WP_Error(
			'hal_mcp_unauthorized_content_type',
			sprintf(
				/* translators: %s: content type slug. */
				__( 'The content type "%s" is not authorized for the generic content tools on this site.', 'hal-mcp' ),
				$content_type
			)
		);
	}

	return null;
}

/**
 * Validates and sanitizes the F20 generic content field set into a clean
 * change map (the domain-sanitization point).
 *
 * @param array<string, mixed> $input Raw ability input.
 * @return array<string, mixed>|WP_Error
 */
function hal_mcp_content_build_change_map( array $input ) {

	$change = [];

	if ( array_key_exists( 'title', $input ) ) {
		$title = sanitize_text_field( trim( (string) $input['title'] ) );

		if ( '' === $title ) {
			return new WP_Error( 'hal_mcp_missing_title', __( 'title must not be empty when provided.', 'hal-mcp' ) );
		}

		$change['title'] = $title;
	}

	if ( array_key_exists( 'content', $input ) ) {
		$change['content'] = wp_kses_post( (string) $input['content'] );
	}

	if ( array_key_exists( 'excerpt', $input ) ) {
		$change['excerpt'] = sanitize_textarea_field( (string) $input['excerpt'] );
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
 * Reads the CURRENT values of exactly the given field keys from the live
 * content item — shared by the creation-time snapshot and the pre-apply
 * fingerprint revalidation.
 *
 * @param int      $content_id Content item ID.
 * @param string[] $fields     Change-map keys (title, content, excerpt).
 * @return array<string, mixed>|null null when the item does not exist.
 */
function hal_mcp_content_read_current( int $content_id, array $fields ) {

	$post = get_post( $content_id );

	if ( ! $post instanceof WP_Post ) {
		return null;
	}

	$hal_mcp_current = [];

	foreach ( $fields as $hal_mcp_field ) {
		switch ( $hal_mcp_field ) {
			case 'title':
				$hal_mcp_current['title'] = (string) $post->post_title;
				break;
			case 'content':
				$hal_mcp_current['content'] = (string) $post->post_content;
				break;
			case 'excerpt':
				$hal_mcp_current['excerpt'] = (string) $post->post_excerpt;
				break;
		}
	}

	return $hal_mcp_current;
}

/**
 * THE domain apply function for generic content changes — shared by the
 * direct draft path and the F17 apply handler. Refuses to touch anything
 * that is not the exact content type the request targets, and never touches
 * the specialized or internal types.
 *
 * @param int                  $content_id  Target item ID.
 * @param string               $content_type The authorized content type the
 *                                           caller/claim says the item is.
 * @param array<string, mixed> $payload     Change map (fields + optional
 *                                          requested_status).
 * @return array{applied: bool, content_id: int, new_status: string, type: string}|WP_Error
 */
function hal_mcp_content_apply_change( int $content_id, string $content_type, array $payload ) {

	$hal_mcp_type_error = hal_mcp_content_validate_type( $content_type );

	if ( null !== $hal_mcp_type_error ) {
		return $hal_mcp_type_error;
	}

	$post = get_post( $content_id );

	if ( ! $post instanceof WP_Post || $post->post_type !== $content_type ) {
		return new WP_Error(
			'hal_mcp_content_not_found',
			__( 'No content item of that type was found with that ID.', 'hal-mcp' )
		);
	}

	if ( 'trash' === $post->post_status ) {
		return new WP_Error(
			'hal_mcp_status_not_editable',
			__( 'This content item is in the trash. Restore it first, then send the update again.', 'hal-mcp' )
		);
	}

	$update_args = [ 'ID' => $content_id ];

	if ( isset( $payload['title'] ) ) {
		$update_args['post_title'] = (string) $payload['title'];
	}
	if ( isset( $payload['content'] ) ) {
		$update_args['post_content'] = (string) $payload['content'];
	}
	if ( isset( $payload['excerpt'] ) ) {
		$update_args['post_excerpt'] = (string) $payload['excerpt'];
	}

	// Publishing is reached exclusively through an approved request —
	// hal_mcp_apply_change_request() has already verified the approver's
	// capabilities with a 'publish' effect by the time this runs.
	if ( isset( $payload['requested_status'] )
		&& in_array( $payload['requested_status'], [ 'draft', 'publish' ], true )
		&& $payload['requested_status'] !== $post->post_status ) {
		$update_args['post_status'] = (string) $payload['requested_status'];
	}

	$updated_id = wp_update_post( $update_args, true );

	if ( is_wp_error( $updated_id ) ) {
		return $updated_id;
	}

	return [
		'applied'    => true,
		'content_id' => $content_id,
		'new_status' => (string) get_post_status( $content_id ),
		'type'       => $content_type,
	];
}

/**
 * Live fingerprint revalidation for 'update-content' requests (F17
 * contract: fetch the target's CURRENT original fields, never echo the
 * stored snapshot).
 *
 * @param array<string, mixed> $request Request data from hal_mcp_get_change_request().
 * @return string
 */
function hal_mcp_content_revalidate_fingerprint( array $request ): string {

	$hal_mcp_payload = (array) ( $request['payload'] ?? [] );
	$hal_mcp_targets = (array) ( $request['targets'] ?? [] );
	$hal_mcp_target  = (array) ( $hal_mcp_targets[0] ?? [] );
	$hal_mcp_id      = (int) ( $hal_mcp_target['id'] ?? 0 );

	if ( $hal_mcp_id < 1 ) {
		return '';
	}

	$hal_mcp_fields = array_values( array_diff( array_keys( $hal_mcp_payload ), [ 'requested_status', 'language' ] ) );

	// Status-only requests read an EMPTY original ([]) whose fingerprint
	// matches the stored fingerprint of their empty snapshot; a GONE target
	// reads null and the '' return turns into an apply-time conflict.
	$hal_mcp_current = hal_mcp_content_read_current( $hal_mcp_id, $hal_mcp_fields );

	if ( null === $hal_mcp_current ) {
		return '';
	}

	return hal_mcp_fingerprint( $hal_mcp_current );
}

/**
 * Registers the 'update-content' apply handler (F17). The target's type
 * comes from the request's own stored target record, so the handler can
 * never be aimed at a different type than the requester named.
 *
 * @return void
 */
function hal_mcp_register_content_apply_handler(): void {

	hal_mcp_register_apply_handler(
		'update-content',
		static function ( array $request ) {
			$hal_mcp_targets = (array) ( $request['targets'] ?? [] );
			$hal_mcp_target  = (array) ( $hal_mcp_targets[0] ?? [] );
			$hal_mcp_id      = (int) ( $hal_mcp_target['id'] ?? 0 );
			$hal_mcp_type    = (string) ( $hal_mcp_target['type'] ?? '' );
			$hal_mcp_payload = (array) ( $request['payload'] ?? [] );

			$hal_mcp_result = hal_mcp_content_apply_change( $hal_mcp_id, $hal_mcp_type, $hal_mcp_payload );

			if ( is_wp_error( $hal_mcp_result ) ) {
				return $hal_mcp_result;
			}

			return sprintf(
				/* translators: 1: content item ID. 2: content type. 3: new status. */
				__( 'Content item #%1$d (type %2$s) updated; current status: %3$s.', 'hal-mcp' ),
				$hal_mcp_result['content_id'],
				$hal_mcp_result['type'],
				$hal_mcp_result['new_status']
			);
		},
		'hal_mcp_content_revalidate_fingerprint'
	);
}

hal_mcp_register_content_apply_handler();

add_action( 'wp_abilities_api_init', 'hal_mcp_register_content_abilities' );

/**
 * Registers the generic custom-content abilities and hal/get-request-status.
 *
 * @return void
 */
function hal_mcp_register_content_abilities(): void {

	$hal_mcp_status_output = [
		'request_id' => [
			'type'        => 'integer',
			'description' => __( 'The approval request\'s ID — the REQUEST\'s ID, not a content ID.', 'hal-mcp' ),
		],
		'state'      => [
			'type'        => 'string',
			'description' => __( 'pending, approved, applying, applied, rejected, failed, or conflict.', 'hal-mcp' ),
		],
	];

	$hal_mcp_write_output = [
		'applied_directly'   => [
			'type'        => 'boolean',
			'description' => __( 'True only when the item itself was edited in place. False when the change was stored as an approval request.', 'hal-mcp' ),
		],
		'id'                 => [
			'type'        => 'integer',
			'description' => __( 'The edited item\'s ID when applied directly. 0 when only an approval request was prepared — do not treat 0 as a content ID.', 'hal-mcp' ),
		],
		'type'               => [ 'type' => 'string' ],
		'status'             => [
			'type'        => 'string',
			'description' => __( 'The item\'s CURRENT status — unchanged until a human approves and applies the request.', 'hal-mcp' ),
		],
		'request_id'         => [
			'type'        => 'integer',
			'description' => __( 'The queued approval request\'s ID when one was prepared, otherwise 0.', 'hal-mcp' ),
		],
		'state'              => [
			'type'        => 'string',
			'description' => __( 'The model-facing state: "pending_approval" when a change request was queued and is awaiting admin approval (the store state remains "pending"), otherwise empty.', 'hal-mcp' ),
		],
		'proposed_update_for' => [
			'type'        => 'integer',
			'description' => __( 'The target item\'s ID when a request was prepared for it (0 when applied directly).', 'hal-mcp' ),
		],
		'edit_url'           => [
			'type'        => 'string',
			'description' => __( 'The edited item\'s edit URL when applied directly; empty for a prepared request.', 'hal-mcp' ),
		],
	];

	$hal_mcp_language_input = [
		'type'        => 'string',
		'description' => __( 'Optional language. Only languages the site actually offers are accepted; until a translations integration is active that is the site locale (a no-op, recorded on any approval request).', 'hal-mcp' ),
	];

	$hal_mcp_requested_status_input = [
		'type'        => 'string',
		'enum'        => [ 'draft', 'publish' ],
		'description' => __( 'Optional status change request. The request never forces the status directly — the policy decides. Omit to keep the current status.', 'hal-mcp' ),
	];

	$hal_mcp_content_type_input = static fn( string $hal_mcp_purpose ) => [
		'type'        => 'string',
		'description' => sprintf(
			/* translators: %s: what the content type is used for. */
			__( 'The public custom content type to %s. Only types authorized on this site are accepted; posts, pages, attachments, and products have their own dedicated abilities.', 'hal-mcp' ),
			$hal_mcp_purpose
		),
	];

	wp_register_ability(
		'hal/get-content',
		[
			'label'       => __( 'Get a custom content item', 'hal-mcp' ),
			'description' => __( 'Returns one item of an authorized public custom content type by its numeric ID. Posts, pages, attachments, and products are refused here — use their dedicated abilities.', 'hal-mcp' ),
			'category'    => 'hal-content',

			'input_schema' => [
				'type'                 => 'object',
				'properties'           => [
					'content_type' => $hal_mcp_content_type_input( 'read' ),
					'content_id'   => [
						'type'        => 'integer',
						'minimum'     => 1,
						'description' => __( 'The numeric ID of the item to retrieve.', 'hal-mcp' ),
					],
				],
				'required'             => [ 'content_type', 'content_id' ],
				'additionalProperties' => false,
			],

			'output_schema' => [
				'type'       => 'object',
				'properties' => [
					'id'       => [ 'type' => 'integer' ],
					'type'     => [ 'type' => 'string' ],
					'title'    => [ 'type' => 'string' ],
					'content'  => [ 'type' => 'string' ],
					'excerpt'  => [ 'type' => 'string' ],
					'status'   => [ 'type' => 'string' ],
					'language' => [
						'type'        => 'string',
						'description' => __( 'The language this item is reported in. Until a translations integration is active, every item is reported in the site locale.', 'hal-mcp' ),
					],
					'date'     => [ 'type' => 'string' ],
					'modified' => [ 'type' => 'string' ],
					'slug'     => [ 'type' => 'string' ],
				],
			],

			'permission_callback' => static function ( $input = [] ) {
				$hal_mcp_type = sanitize_key( (string) ( $input['content_type'] ?? '' ) );

				return hal_mcp_permission( $hal_mcp_type, 'read', (int) ( $input['content_id'] ?? 0 ), [ 'ability' => 'hal/get-content' ] );
			},

			'execute_callback' => function ( $input ) {

				$hal_mcp_type = sanitize_key( (string) ( $input['content_type'] ?? '' ) );

				$hal_mcp_type_error = hal_mcp_content_validate_type( $hal_mcp_type );

				if ( null !== $hal_mcp_type_error ) {
					return $hal_mcp_type_error;
				}

				$hal_mcp_id = absint( $input['content_id'] ?? 0 );

				if ( $hal_mcp_id < 1 ) {
					return new WP_Error(
						'hal_mcp_invalid_content_id',
						__( 'content_id must be a positive integer.', 'hal-mcp' )
					);
				}

				$post = get_post( $hal_mcp_id );

				// Generic "not found": existence in another type is never
				// disclosed, and denials are logged but not distinguished.
				if ( ! $post instanceof WP_Post || $post->post_type !== $hal_mcp_type ) {
					return new WP_Error(
						'hal_mcp_content_not_found',
						__( 'No content item of that type was found with that ID.', 'hal-mcp' )
					);
				}

				if ( ! hal_mcp_permission( $hal_mcp_type, 'read', $hal_mcp_id, [ 'ability' => 'hal/get-content' ] ) ) {
					return new WP_Error(
						'hal_mcp_content_not_found',
						__( 'No content item of that type was found with that ID.', 'hal-mcp' )
					);
				}

				return [
					'id'       => (int) $post->ID,
					'type'     => (string) $post->post_type,
					'title'    => (string) $post->post_title,
					'content'  => (string) $post->post_content,
					'excerpt'  => (string) $post->post_excerpt,
					'status'   => (string) $post->post_status,
					'language' => (string) get_locale(),
					'date'     => (string) $post->post_date,
					'modified' => (string) $post->post_modified,
					'slug'     => (string) $post->post_name,
				];
			},

			'meta' => [ 'mcp' => [ 'public' => true ] ],
		]
	);

	wp_register_ability(
		'hal/search-content',
		[
			'label'       => __( 'Search custom content', 'hal-mcp' ),
			'description' => __( 'Searches one authorized public custom content type by keyword and returns a short summary of up to 10 matches per page. Internal request records can never appear here.', 'hal-mcp' ),
			'category'    => 'hal-content',

			'input_schema' => [
				'type'                 => 'object',
				'properties'           => [
					'content_type'   => $hal_mcp_content_type_input( 'search' ),
					'query'          => [
						'type'        => 'string',
						'minLength'   => 1,
						'description' => __( 'Keyword or phrase to search for in titles and content.', 'hal-mcp' ),
					],
					'include_drafts' => [
						'type'        => 'boolean',
						'default'     => false,
						'description' => __( 'If true, also search draft, pending, and private items in addition to published ones. Default false (published only).', 'hal-mcp' ),
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
				'required'             => [ 'content_type', 'query' ],
				'additionalProperties' => false,
			],

			'output_schema' => [
				'type'  => 'array',
				'items' => [
					'type'       => 'object',
					'properties' => [
						'id'       => [ 'type' => 'integer' ],
						'type'     => [ 'type' => 'string' ],
						'title'    => [ 'type' => 'string' ],
						'status'   => [ 'type' => 'string' ],
						'language' => [
							'type'        => 'string',
							'description' => __( 'The language this item is reported in. Until a translations integration is active, every item is reported in the site locale.', 'hal-mcp' ),
						],
						'date'     => [ 'type' => 'string' ],
						'excerpt'  => [ 'type' => 'string' ],
					],
				],
			],

			'permission_callback' => static function ( $input = [] ) {
				$hal_mcp_type = sanitize_key( (string) ( $input['content_type'] ?? '' ) );

				return hal_mcp_permission( $hal_mcp_type, 'read', 0, [ 'ability' => 'hal/search-content' ] );
			},

			'execute_callback' => function ( $input ) {

				$hal_mcp_type = sanitize_key( (string) ( $input['content_type'] ?? '' ) );

				$hal_mcp_type_error = hal_mcp_content_validate_type( $hal_mcp_type );

				if ( null !== $hal_mcp_type_error ) {
					return $hal_mcp_type_error;
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

				$page   = min( 100, max( 1, (int) ( $input['page'] ?? 1 ) ) );
				$offset = ( $page - 1 ) * 10;

				$found_items = get_posts(
					[
						'post_type'   => $hal_mcp_type,
						'post_status' => $post_status,
						's'           => $query,
						'numberposts' => 10,
						'offset'      => $offset,
						'orderby'     => 'date',
						'order'       => 'DESC',
					]
				);

				$results = [];

				foreach ( $found_items as $found_item ) {
					// Per-result read check (F05), quiet: items the caller
					// may not read are skipped without disclosure.
					if ( ! hal_mcp_permission( $hal_mcp_type, 'read', (int) $found_item->ID, [ 'ability' => 'hal/search-content', 'quiet' => true ] ) ) {
						continue;
					}

					$results[] = [
						'id'       => (int) $found_item->ID,
						'type'     => (string) $found_item->post_type,
						'title'    => (string) $found_item->post_title,
						'status'   => (string) $found_item->post_status,
						'language' => (string) get_locale(),
						'date'     => (string) $found_item->post_date,
						'excerpt'  => (string) $found_item->post_excerpt,
					];
				}

				return $results;
			},

			'meta' => [ 'mcp' => [ 'public' => true ] ],
		]
	);

	wp_register_ability(
		'hal/create-content',
		[
			'label'       => __( 'Create a custom content draft', 'hal-mcp' ),
			'description' => __( 'Creates a new item of an authorized public custom content type as a draft (title, content, excerpt, language). Never publishes directly: pass requested_status="publish" to also queue an approval request that a human approves in wp-admin.', 'hal-mcp' ),
			'category'    => 'hal-content',

			'input_schema' => [
				'type'                 => 'object',
				'properties'           => [
					'content_type'    => $hal_mcp_content_type_input( 'create' ),
					'title'           => [
						'type'        => 'string',
						'description' => __( 'The item title.', 'hal-mcp' ),
					],
					'content'         => [
						'type'        => 'string',
						'description' => __( 'The item body. Basic HTML is allowed; scripts and unsafe markup are stripped.', 'hal-mcp' ),
					],
					'excerpt'         => [
						'type'        => 'string',
						'description' => __( 'Optional short excerpt/summary.', 'hal-mcp' ),
					],
					'language'        => $hal_mcp_language_input,
					'requested_status' => $hal_mcp_requested_status_input,
				],
				'required'             => [ 'content_type', 'title' ],
				'additionalProperties' => false,
			],

			'output_schema' => [
				'type'       => 'object',
				'properties' => array_merge(
					[
						'id'       => [
							'type'        => 'integer',
							'description' => __( 'The created draft item\'s ID (a draft always exists even when a publish approval is queued).', 'hal-mcp' ),
						],
						'status'   => [ 'type' => 'string' ],
						'edit_url' => [ 'type' => 'string' ],
					],
					$hal_mcp_status_output
				),
			],

			'permission_callback' => static function ( $input = [] ) {
				$hal_mcp_type = sanitize_key( (string) ( $input['content_type'] ?? '' ) );

				return hal_mcp_permission( $hal_mcp_type, 'create', 0, [ 'ability' => 'hal/create-content' ] );
			},

			'execute_callback' => function ( $input ) {

				$hal_mcp_type = sanitize_key( (string) ( $input['content_type'] ?? '' ) );

				$hal_mcp_type_error = hal_mcp_content_validate_type( $hal_mcp_type );

				if ( null !== $hal_mcp_type_error ) {
					return $hal_mcp_type_error;
				}

				$hal_mcp_change = hal_mcp_content_build_change_map( is_array( $input ) ? $input : [] );

				if ( is_wp_error( $hal_mcp_change ) ) {
					return $hal_mcp_change;
				}

				$hal_mcp_title = isset( $hal_mcp_change['title'] ) ? (string) $hal_mcp_change['title'] : '';

				if ( '' === $hal_mcp_title ) {
					return new WP_Error(
						'hal_mcp_missing_title',
						__( 'title must not be empty.', 'hal-mcp' )
					);
				}

				$hal_mcp_wants_publish = ( 'publish' === ( $hal_mcp_change['requested_status'] ?? 'draft' ) );

				$content_id = wp_insert_post(
					[
						'post_title'   => $hal_mcp_title,
						'post_content' => isset( $hal_mcp_change['content'] ) ? (string) $hal_mcp_change['content'] : '',
						'post_excerpt' => isset( $hal_mcp_change['excerpt'] ) ? (string) $hal_mcp_change['excerpt'] : '',
						'post_type'    => $hal_mcp_type,
						// Hard-coded literal — never derived from $input.
						'post_status'  => 'draft',
					],
					true
				);

				if ( is_wp_error( $content_id ) ) {
					return $content_id;
				}

				$content_id = (int) $content_id;

				$hal_mcp_request = [
					'request_id' => 0,
					'state'      => '',
				];

				if ( $hal_mcp_wants_publish ) {
					$hal_mcp_queued = hal_mcp_create_change_request(
						[
							'operation'         => 'update-content',
							'payload'           => [ 'requested_status' => 'publish' ],
							'targets'           => [
								[
									'type' => $hal_mcp_type,
									'id'   => $content_id,
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
					'id'         => $content_id,
					'status'     => (string) get_post_status( $content_id ),
					'edit_url'   => (string) get_edit_post_link( $content_id, 'raw' ),
					'request_id' => $hal_mcp_request['request_id'],
					'state'      => $hal_mcp_request['state'],
				];
			},

			'meta' => [ 'mcp' => [ 'public' => true ] ],
		]
	);

	wp_register_ability(
		'hal/update-content',
		[
			'label'       => __( 'Update a custom content item', 'hal-mcp' ),
			'description' => __( 'Updates an authorized public custom content item\'s title, content, excerpt, and/or language, and optionally requests a status change via requested_status. A draft with no status change is edited directly; anything else becomes one atomic approval request (request_id + state) for a human to approve in wp-admin. Requests with no actual effect are refused.', 'hal-mcp' ),
			'category'    => 'hal-content',

			'input_schema' => [
				'type'                 => 'object',
				'properties'           => [
					'content_type'    => $hal_mcp_content_type_input( 'update' ),
					'content_id'      => [
						'type'        => 'integer',
						'minimum'     => 1,
						'description' => __( 'The numeric ID of the item to update.', 'hal-mcp' ),
					],
					'title'           => [
						'type'        => 'string',
						'description' => __( 'New title. Omit to leave unchanged.', 'hal-mcp' ),
					],
					'content'         => [
						'type'        => 'string',
						'description' => __( 'New body content. Omit to leave unchanged.', 'hal-mcp' ),
					],
					'excerpt'         => [
						'type'        => 'string',
						'description' => __( 'New excerpt. Omit to leave unchanged.', 'hal-mcp' ),
					],
					'language'        => $hal_mcp_language_input,
					'requested_status' => $hal_mcp_requested_status_input,
				],
				'required'             => [ 'content_type', 'content_id' ],
				'additionalProperties' => false,
			],

			'output_schema' => [
				'type'       => 'object',
				'properties' => $hal_mcp_write_output,
			],

			'permission_callback' => static function ( $input = [] ) {
				$hal_mcp_type = sanitize_key( (string) ( $input['content_type'] ?? '' ) );

				return hal_mcp_permission( $hal_mcp_type, 'edit', (int) ( $input['content_id'] ?? 0 ), [ 'ability' => 'hal/update-content' ] );
			},

			'execute_callback' => function ( $input ) {

				$hal_mcp_type = sanitize_key( (string) ( $input['content_type'] ?? '' ) );

				$hal_mcp_type_error = hal_mcp_content_validate_type( $hal_mcp_type );

				if ( null !== $hal_mcp_type_error ) {
					return $hal_mcp_type_error;
				}

				$hal_mcp_id = absint( $input['content_id'] ?? 0 );

				if ( $hal_mcp_id < 1 ) {
					return new WP_Error(
						'hal_mcp_invalid_content_id',
						__( 'content_id must be a positive integer.', 'hal-mcp' )
					);
				}

				$hal_mcp_change = hal_mcp_content_build_change_map( is_array( $input ) ? $input : [] );

				if ( is_wp_error( $hal_mcp_change ) ) {
					return $hal_mcp_change;
				}

				$target_post = get_post( $hal_mcp_id );

				if ( ! $target_post instanceof WP_Post || $target_post->post_type !== $hal_mcp_type ) {
					return new WP_Error(
						'hal_mcp_content_not_found',
						__( 'No content item of that type was found with that ID.', 'hal-mcp' )
					);
				}

				// Object-level permission re-check (F05) — the enforcement
				// point that cannot be bypassed.
				if ( ! hal_mcp_permission( $hal_mcp_type, 'edit', $hal_mcp_id, [ 'ability' => 'hal/update-content' ] ) ) {
					return new WP_Error(
						'hal_mcp_forbidden_object',
						__( 'You are not allowed to edit this content item.', 'hal-mcp' )
					);
				}

				$hal_mcp_current_status = (string) $target_post->post_status;

				$hal_mcp_wants_status_change = isset( $hal_mcp_change['requested_status'] )
					&& $hal_mcp_change['requested_status'] !== $hal_mcp_current_status;

				// Guard: requested_status alone and a supported language field
				// are accepted without a content re-send — only a change with
				// NO effect at all is refused.
				$hal_mcp_field_keys = array_values( array_diff( array_keys( $hal_mcp_change ), [ 'requested_status', 'language' ] ) );

				$hal_mcp_language_noop = ! isset( $hal_mcp_change['language'] )
					|| $hal_mcp_change['language'] === sanitize_key( (string) get_locale() );

				if ( empty( $hal_mcp_field_keys ) && ! $hal_mcp_wants_status_change && $hal_mcp_language_noop ) {
					return new WP_Error(
						'hal_mcp_nothing_to_update',
						__( 'Provide at least one of title, content, excerpt, language, or a requested_status different from the current status.', 'hal-mcp' )
					);
				}

				// Shared policy decision (F05): same contract as posts/pages.
				$write_decision = hal_mcp_decide_write_path(
					$hal_mcp_type,
					$hal_mcp_current_status,
					[
						'operation'    => 'hal/update-content',
						'field_impact' => ( $hal_mcp_wants_status_change ? 'protected' : '' ),
					]
				);

				if ( 'deny' === $write_decision['decision'] ) {
					return new WP_Error(
						'hal_mcp_status_not_editable',
						__( 'This content item is in the trash. Restore it first, then send the update again.', 'hal-mcp' )
					);
				}

				// --- Case 1: draft, no status change — edit directly through
				// the same domain function the approval path uses. ---
				if ( 'direct' === $write_decision['decision'] ) {

					// Optimistic re-check on the FRESH status before writing.
					$fresh_decision = hal_mcp_decide_write_path( $hal_mcp_type, (string) get_post_status( $hal_mcp_id ), [ 'operation' => 'hal/update-content' ] );

					if ( 'direct' !== $fresh_decision['decision'] ) {
						// The abort is a control event, not a caller denial —
						// logged here because a bare WP_Error return would
						// leave no audit trace of a write that backed off.
						hal_mcp_log_permission_denial(
							'hal/update-content',
							'fresh status re-check failed; direct write aborted with no changes',
							[
								'stage'       => 'direct_write_aborted',
								'object_type' => $hal_mcp_type,
								'object_id'   => $hal_mcp_id,
							]
						);

						return new WP_Error(
							'hal_mcp_content_status_changed',
							__( 'This item was published or protected by someone else while this update was being processed. No changes were made — please retry the request.', 'hal-mcp' )
						);
					}

					$hal_mcp_field_payload = array_diff_key( $hal_mcp_change, [ 'requested_status' => true ] );

					$hal_mcp_result = hal_mcp_content_apply_change( $hal_mcp_id, $hal_mcp_type, $hal_mcp_field_payload );

					if ( is_wp_error( $hal_mcp_result ) ) {
						return $hal_mcp_result;
					}

					return [
						'applied_directly' => true,
						'id'               => $hal_mcp_id,
						'type'             => $hal_mcp_type,
						'status'           => (string) get_post_status( $hal_mcp_id ),
						'request_id'       => 0,
						'state'            => '',
						'proposed_update_for' => 0,
						'edit_url'         => (string) get_edit_post_link( $hal_mcp_id, 'raw' ),
					];
				}

				// --- Case 2: protected target, or a requested status change —
				// one atomic approval request. The live item is never touched.
				$hal_mcp_payload = array_diff_key( $hal_mcp_change, [ 'language' => true ] );

				$hal_mcp_original_fields = array_values( array_diff( array_keys( $hal_mcp_payload ), [ 'requested_status' ] ) );
				$hal_mcp_snapshot        = empty( $hal_mcp_original_fields )
					? []
					: (array) hal_mcp_content_read_current( $hal_mcp_id, $hal_mcp_original_fields );

				$hal_mcp_queued = hal_mcp_create_change_request(
					[
						'operation'         => 'update-content',
						'payload'           => $hal_mcp_payload,
						'targets'           => [
							[
								'type' => $hal_mcp_type,
								'id'   => $hal_mcp_id,
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
					'applied_directly' => false,
					'id'               => 0,
					'type'             => $hal_mcp_type,
					'status'           => $hal_mcp_current_status,
					'request_id'       => (int) $hal_mcp_queued['request_id'],
					// Model-facing answer: 'pending_approval' (roadmap §4.3/F17).
					'state'            => hal_mcp_request_model_state( (string) $hal_mcp_queued['state'] ),
					'proposed_update_for' => $hal_mcp_id,
					'edit_url'         => '',
				];
			},

			'meta' => [ 'mcp' => [ 'public' => true ] ],
		]
	);

	wp_register_ability(
		'hal/get-request-status',
		[
			'label'       => __( 'Get a change request status', 'hal-mcp' ),
			'description' => __( 'Returns the state of one content-change request for its allowed requester only (the request\'s author or a site manager): state, operation, short preview, and apply result. Payloads and snapshots are never returned, and an unauthorized caller cannot even confirm the request exists.', 'hal-mcp' ),
			'category'    => 'hal-content',

			'input_schema' => [
				'type'                 => 'object',
				'properties'           => [
					'request_id' => [
						'type'        => 'integer',
						'minimum'     => 1,
						'description' => __( 'The request_id returned when a change request was prepared.', 'hal-mcp' ),
					],
				],
				'required'             => [ 'request_id' ],
				'additionalProperties' => false,
			],

			'output_schema' => [
				'type'       => 'object',
				'properties' => [
					'request_id' => [ 'type' => 'integer' ],
					'operation'  => [ 'type' => 'string' ],
					'state'      => [
						'type'        => 'string',
						'description' => __( 'pending, approved, applying, applied, rejected, failed, or conflict.', 'hal-mcp' ),
					],
					'preview'    => [
						'type'        => 'string',
						'description' => __( 'Short human-readable preview: target refs and changed field names only — never payload values.', 'hal-mcp' ),
					],
					'targets'    => [
						'type'        => 'array',
						'description' => __( 'Target type/ID pairs the request is bound to.', 'hal-mcp' ),
						'items'       => [
							'type'       => 'object',
							'properties' => [
								'type' => [ 'type' => 'string' ],
								'id'   => [ 'type' => 'integer' ],
							],
						],
					],
					'language'   => [ 'type' => 'string' ],
					'created_at' => [ 'type' => 'string' ],
					'approved_at' => [ 'type' => 'string' ],
					'result'     => [
						'type'        => 'string',
						'description' => __( 'The recorded apply/reject result summary, when one exists.', 'hal-mcp' ),
					],
				],
			],

			'permission_callback' => static fn() => is_user_logged_in(),

			'execute_callback' => function ( $input ) {

				$hal_mcp_request_id = absint( $input['request_id'] ?? 0 );

				if ( $hal_mcp_request_id < 1 ) {
					return new WP_Error(
						'hal_mcp_invalid_request_id',
						__( 'request_id must be a positive integer.', 'hal-mcp' )
					);
				}

				// WITHOUT the payload: this ability's output is state-only, so
				// the payload view gate is never even consulted with sensitive
				// data. The view gate below is the disclosure boundary.
				$request = hal_mcp_get_change_request( $hal_mcp_request_id, false );

				if ( is_wp_error( $request ) || ! hal_mcp_user_can_view_request( $request ) ) {
					// Same generic "not found" for a missing request AND one
					// the caller may not see: existence is never disclosed to
					// an unauthorized caller (F20).
					return new WP_Error(
						'hal_mcp_request_not_found',
						__( 'No such change request.', 'hal-mcp' )
					);
				}

				return [
					'request_id'  => (int) $request['request_id'],
					'operation'   => (string) $request['operation'],
					'state'       => (string) $request['state'],
					'preview'     => (string) $request['preview'],
					'targets'     => array_map(
						static fn( $hal_mcp_target ) => [
							'type' => (string) ( $hal_mcp_target['type'] ?? '' ),
							'id'   => (int) ( $hal_mcp_target['id'] ?? 0 ),
						],
						(array) $request['targets']
					),
					'language'    => (string) $request['language'],
					'created_at'  => (string) $request['created_at'],
					'approved_at' => (string) $request['approved_at'],
					'result'      => (string) $request['result'],
				];
			},

			'meta' => [ 'mcp' => [ 'public' => true ] ],
		]
	);
}
