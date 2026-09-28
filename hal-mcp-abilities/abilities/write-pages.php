<?php
/**
 * hal-mcp-abilities — hal/create-page, hal/update-page (F20).
 *
 * Page writes follow the same F10 contract as posts: the model may REQUEST
 * a status via the unified `requested_status` input, but the policy decides —
 * draft creation is direct, publishing and every write to a protected page
 * become a stored change request (F17) approved by a human in wp-admin.
 *
 * Editor rule (F20: «يحددان المحرر من الأصل/الاختيار المتاح»):
 * - A NEW page is created as a block-editor page — block markup is the only
 *   design source this API can write for a page that has no stored design
 *   yet, and no Elementor JSON is ever synthesized here.
 * - An EXISTING page's editor is read from its own stored data
 *   (hal_mcp_page_editor_identity(), shared with read-pages.php). Block
 *   pages accept block-markup content; Elementor pages REFUSE content
 *   writes with a clear reason — their JSON design source needs the editor
 *   bridge (F21) and is never overwritten by flattened markup. Title,
 *   template, featured image, and status changes do not touch the design
 *   JSON and stay available for Elementor pages.
 *
 * Supported page fields (F20): title, content (block markup), template
 * (theme-declared only), featured image, language, requested_status.
 * Global settings that affect other pages are NOT reachable from these
 * abilities — anything beyond this page is an admin-assist request, not a
 * hidden write.
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
 * Validates and sanitizes the F20 page field set into a clean change map
 * (the domain-sanitization point). Templates must be declared by the active
 * theme (or 'default'); featured images must be readable media items;
 * language must be one the site actually offers.
 *
 * @param array<string, mixed> $input Raw ability input.
 * @return array<string, mixed>|WP_Error
 */
function hal_mcp_page_build_change_map( array $input ) {

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

	if ( array_key_exists( 'template', $input ) ) {
		$hal_mcp_template = sanitize_text_field( (string) $input['template'] );

		if ( '' !== $hal_mcp_template && 'default' !== $hal_mcp_template ) {
			$hal_mcp_theme_templates = function_exists( 'get_page_templates' ) ? (array) get_page_templates() : [];

			if ( ! in_array( $hal_mcp_template, array_values( $hal_mcp_theme_templates ), true ) ) {
				return new WP_Error(
					'hal_mcp_unknown_template',
					__( 'template must be a template the active theme declares (or "default").', 'hal-mcp' )
				);
			}
		}

		$change['template'] = $hal_mcp_template;
	}

	if ( array_key_exists( 'featured_image_id', $input ) ) {
		// Negative IDs can never name a media item, and absint() would fold
		// them into 0's documented "remove" meaning — refuse them here, the
		// same non-negative contract stock_quantity enforces for products
		// and the schemas' minimum: 0 declares.
		if ( (int) $input['featured_image_id'] < 0 ) {
			return new WP_Error(
				'hal_mcp_invalid_featured_image',
				__( 'featured_image_id must be a non-negative integer.', 'hal-mcp' )
			);
		}

		$hal_mcp_image_id = absint( $input['featured_image_id'] );

		// 0 is the schemas' documented "remove the current featured image"
		// value: there is no media item to read and no permission to check,
		// so it maps straight to the removal marker the apply layer turns
		// into delete_post_meta('_thumbnail_id').
		if ( 0 === $hal_mcp_image_id ) {
			$change['featured_image_id'] = 0;
		} else {
			$hal_mcp_parent = get_post( $hal_mcp_image_id );

			if ( ! $hal_mcp_parent instanceof WP_Post || 'attachment' !== $hal_mcp_parent->post_type ) {
				return new WP_Error(
					'hal_mcp_featured_image_not_found',
					__( 'featured_image_id must refer to an existing media library item.', 'hal-mcp' )
				);
			}

			if ( ! hal_mcp_permission( 'media', 'read', $hal_mcp_image_id, [ 'ability' => 'hal/write-pages', 'reason' => 'featured_image' ] ) ) {
				return new WP_Error(
					'hal_mcp_featured_image_forbidden',
					__( 'You are not allowed to use that media library item.', 'hal-mcp' )
				);
			}

			$change['featured_image_id'] = $hal_mcp_image_id;
		}
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
 * page — shared by the creation-time snapshot and the pre-apply
 * fingerprint revalidation.
 *
 * @param int      $page_id Page ID.
 * @param string[] $fields  Change-map keys (title, content, template,
 *                          featured_image_id).
 * @return array<string, mixed>|null null when the page does not exist.
 */
function hal_mcp_page_read_current( int $page_id, array $fields ) {

	$page = get_post( $page_id );

	if ( ! $page instanceof WP_Post || 'page' !== $page->post_type ) {
		return null;
	}

	$hal_mcp_current = [];

	foreach ( $fields as $hal_mcp_field ) {
		switch ( $hal_mcp_field ) {
			case 'title':
				$hal_mcp_current['title'] = (string) $page->post_title;
				break;
			case 'content':
				$hal_mcp_current['content'] = (string) $page->post_content;
				break;
			case 'template':
				$hal_mcp_current['template'] = (string) get_page_template_slug( $page_id );
				break;
			case 'featured_image_id':
				$hal_mcp_current['featured_image_id'] = (int) get_post_meta( $page_id, '_thumbnail_id', true );
				break;
		}
	}

	return $hal_mcp_current;
}

/**
 * THE domain apply function for page changes — shared by the direct draft
 * path and the F17 apply handler, so the two paths cannot drift.
 *
 * @param int                  $page_id Target page ID.
 * @param array<string, mixed> $payload Change map (fields + optional
 *                                      requested_status).
 * @return array{applied: bool, page_id: int, new_status: string}|WP_Error
 */
function hal_mcp_page_apply_change( int $page_id, array $payload ) {

	$page = get_post( $page_id );

	if ( ! $page instanceof WP_Post || 'page' !== $page->post_type ) {
		return new WP_Error(
			'hal_mcp_page_not_found',
			__( 'No page was found with that ID.', 'hal-mcp' )
		);
	}

	if ( 'trash' === $page->post_status ) {
		return new WP_Error(
			'hal_mcp_status_not_editable',
			__( 'This page is in the trash. Restore it first, then send the update again.', 'hal-mcp' )
		);
	}

	$update_args = [ 'ID' => $page_id ];

	if ( isset( $payload['title'] ) ) {
		$update_args['post_title'] = (string) $payload['title'];
	}
	if ( isset( $payload['content'] ) ) {
		$update_args['post_content'] = (string) $payload['content'];
	}

	// The template is deliberately NOT written yet: the post update below
	// must succeed first, so a failed update can never leave a template
	// change standing on a request that is marked failed.
	$hal_mcp_template_meta = null;

	if ( isset( $payload['template'] ) ) {
		// WordPress stores '' for the theme default; the F20 contract's
		// 'default' maps to that.
		$hal_mcp_template_meta = 'default' === (string) $payload['template'] ? '' : (string) $payload['template'];
	}

	// Publishing is reached exclusively through an approved request —
	// hal_mcp_apply_change_request() has already verified the approver's
	// capabilities with a 'publish' effect by the time this runs.
	if ( isset( $payload['requested_status'] )
		&& in_array( $payload['requested_status'], [ 'draft', 'publish' ], true )
		&& $payload['requested_status'] !== $page->post_status ) {
		$update_args['post_status'] = (string) $payload['requested_status'];
	}

	if ( count( $update_args ) > 1 ) {
		$hal_mcp_updated = wp_update_post( $update_args, true );

		if ( is_wp_error( $hal_mcp_updated ) ) {
			return $hal_mcp_updated;
		}
	}

	// Written exactly once, and only after the (possibly skipped) post
	// update succeeded.
	if ( null !== $hal_mcp_template_meta ) {
		update_post_meta( $page_id, '_wp_page_template', $hal_mcp_template_meta );
	}

	if ( isset( $payload['featured_image_id'] ) ) {
		$hal_mcp_image_id = absint( $payload['featured_image_id'] );

		if ( $hal_mcp_image_id > 0 ) {
			update_post_meta( $page_id, '_thumbnail_id', $hal_mcp_image_id );
		} else {
			delete_post_meta( $page_id, '_thumbnail_id' );
		}
	}

	return [
		'applied'    => true,
		'page_id'    => $page_id,
		'new_status' => (string) get_post_status( $page_id ),
	];
}

/**
 * Live fingerprint revalidation for 'update-page' requests (F17 contract:
 * fetch the target's CURRENT original fields, never echo the stored
 * snapshot).
 *
 * @param array<string, mixed> $request Request data from hal_mcp_get_change_request().
 * @return string
 */
function hal_mcp_page_revalidate_fingerprint( array $request ): string {

	$hal_mcp_payload = (array) ( $request['payload'] ?? [] );
	$hal_mcp_targets = (array) ( $request['targets'] ?? [] );
	$hal_mcp_page_id = (int) ( $hal_mcp_targets[0]['id'] ?? 0 );

	if ( $hal_mcp_page_id < 1 ) {
		return '';
	}

	$hal_mcp_fields = array_values( array_diff( array_keys( $hal_mcp_payload ), [ 'requested_status', 'language' ] ) );

	// Status-only requests read an EMPTY original ([]) whose fingerprint
	// matches the stored fingerprint of their empty snapshot; a GONE target
	// reads null and the '' return turns into an apply-time conflict.
	$hal_mcp_current = hal_mcp_page_read_current( $hal_mcp_page_id, $hal_mcp_fields );

	if ( null === $hal_mcp_current ) {
		return '';
	}

	return hal_mcp_fingerprint( $hal_mcp_current );
}

/**
 * Registers the 'update-page' apply handler (F17). Runs at file load so the
 * approval path works for the whole lifetime of this module — the same
 * lifetime the abilities themselves have (the bootstrap requires this file
 * only when the Abilities API is present).
 *
 * @return void
 */
function hal_mcp_register_page_apply_handler(): void {

	hal_mcp_register_apply_handler(
		'update-page',
		static function ( array $request ) {
			$hal_mcp_targets = (array) ( $request['targets'] ?? [] );
			$hal_mcp_page_id = (int) ( $hal_mcp_targets[0]['id'] ?? 0 );
			$hal_mcp_payload = (array) ( $request['payload'] ?? [] );

			$hal_mcp_result = hal_mcp_page_apply_change( $hal_mcp_page_id, $hal_mcp_payload );

			if ( is_wp_error( $hal_mcp_result ) ) {
				return $hal_mcp_result;
			}

			return sprintf(
				/* translators: 1: page ID. 2: new page status. */
				__( 'Page #%1$d updated; current status: %2$s.', 'hal-mcp' ),
				$hal_mcp_result['page_id'],
				$hal_mcp_result['new_status']
			);
		},
		'hal_mcp_page_revalidate_fingerprint'
	);
}

hal_mcp_register_page_apply_handler();

add_action( 'wp_abilities_api_init', 'hal_mcp_register_write_page_abilities' );

/**
 * Registers the hal/create-page and hal/update-page abilities.
 *
 * @return void
 */
function hal_mcp_register_write_page_abilities(): void {

	wp_register_ability(
		'hal/create-page',
		[
			'label'       => __( 'Create a draft page', 'hal-mcp' ),
			'description' => __( 'Creates a new page as a block-editor draft (title, block-markup content, theme-declared template, featured image, language). Never publishes directly: pass requested_status="publish" to also queue an approval request that a human approves in wp-admin. Elementor designs are never synthesized here — a new page is always block-based.', 'hal-mcp' ),
			'category'    => 'hal-pages',

			'input_schema' => [
				'type'                 => 'object',
				'properties'           => [
					'title'             => [
						'type'        => 'string',
						'description' => __( 'The page title.', 'hal-mcp' ),
					],
					'content'           => [
						'type'        => 'string',
						'description' => __( 'The page body as block markup (Gutenberg blocks; sections and arrangement are expressed by the block structure itself). Scripts and unsafe markup are stripped.', 'hal-mcp' ),
					],
					'template'          => [
						'type'        => 'string',
						'description' => __( 'Optional page template the active theme declares, or "default".', 'hal-mcp' ),
					],
					'featured_image_id' => [
						'type'        => 'integer',
						'minimum'     => 0,
						'default'     => 0,
						'description' => __( 'Optional ID of an existing media library item as the featured image. 0 (default) sets none.', 'hal-mcp' ),
					],
					'language'          => [
						'type'        => 'string',
						'description' => __( 'Optional language. Only languages the site actually offers are accepted; until a translations integration is active that is the site locale (a no-op, recorded on any approval request).', 'hal-mcp' ),
					],
					'requested_status'  => [
						'type'        => 'string',
						'enum'        => [ 'draft', 'publish' ],
						'default'     => 'draft',
						'description' => __( '"draft" (default) creates the page as a draft directly. "publish" still creates it as a draft AND queues an approval request for the publishing step — never an immediate publish.', 'hal-mcp' ),
					],
				],
				'required'             => [ 'title', 'content' ],
				'additionalProperties' => false,
			],

			'output_schema' => [
				'type'       => 'object',
				'properties' => [
					'id'         => [
						'type'        => 'integer',
						'description' => __( 'The created draft page\'s ID (a draft always exists even when a publish approval is queued).', 'hal-mcp' ),
					],
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

			'permission_callback' => static fn() => hal_mcp_permission( 'page', 'create', 0, [ 'ability' => 'hal/create-page' ] ),

			'execute_callback' => function ( $input ) {

				$hal_mcp_change = hal_mcp_page_build_change_map( is_array( $input ) ? $input : [] );

				if ( is_wp_error( $hal_mcp_change ) ) {
					return $hal_mcp_change;
				}

				$hal_mcp_title   = isset( $hal_mcp_change['title'] ) ? (string) $hal_mcp_change['title'] : '';
				$hal_mcp_content = isset( $hal_mcp_change['content'] ) ? (string) $hal_mcp_change['content'] : '';

				if ( '' === $hal_mcp_title || '' === $hal_mcp_content ) {
					return new WP_Error(
						'hal_mcp_missing_title',
						__( 'title and content must not be empty.', 'hal-mcp' )
					);
				}

				$hal_mcp_wants_publish = ( 'publish' === ( $hal_mcp_change['requested_status'] ?? 'draft' ) );

				$page_id = wp_insert_post(
					[
						'post_title'   => $hal_mcp_title,
						'post_content' => $hal_mcp_content,
						'post_type'    => 'page',
						// Hard-coded literal — never derived from $input.
						'post_status'  => 'draft',
					],
					true
				);

				if ( is_wp_error( $page_id ) ) {
					return $page_id;
				}

				$page_id = (int) $page_id;

				// Non-status fields are draft work, applied through the SAME
				// domain function the approval path uses.
				$hal_mcp_field_payload = array_diff_key( $hal_mcp_change, [ 'requested_status' => true ] );

				if ( ! empty( $hal_mcp_field_payload ) ) {
					$hal_mcp_field_result = hal_mcp_page_apply_change( $page_id, $hal_mcp_field_payload );

					if ( is_wp_error( $hal_mcp_field_result ) ) {
						return $hal_mcp_field_result;
					}
				}

				$hal_mcp_request = [
					'request_id' => 0,
					'state'      => '',
				];

				if ( $hal_mcp_wants_publish ) {
					$hal_mcp_queued = hal_mcp_create_change_request(
						[
							'operation'         => 'update-page',
							'payload'           => [ 'requested_status' => 'publish' ],
							'targets'           => [
								[
									'type' => 'page',
									'id'   => $page_id,
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
					'id'         => $page_id,
					'status'     => (string) get_post_status( $page_id ),
					'edit_url'   => (string) get_edit_post_link( $page_id, 'raw' ),
					'request_id' => $hal_mcp_request['request_id'],
					'state'      => $hal_mcp_request['state'],
				];
			},

			'meta' => [ 'mcp' => [ 'public' => true ] ],
		]
	);

	wp_register_ability(
		'hal/update-page',
		[
			'label'       => __( 'Update a page', 'hal-mcp' ),
			'description' => __( 'Updates a page\'s title, block-markup content, theme-declared template, featured image, and/or language, and optionally requests a status change via requested_status. The page\'s editor is detected from its stored design: block pages accept content updates; Elementor pages REFUSE content writes (their JSON design needs the editor bridge) while title/template/featured-image/status changes stay available. A draft with no status change is edited directly; anything else becomes one atomic approval request (request_id + state) for a human to approve in wp-admin. Requests with no actual effect are refused.', 'hal-mcp' ),
			'category'    => 'hal-pages',

			'input_schema' => [
				'type'                 => 'object',
				'properties'           => [
					'page_id'           => [
						'type'        => 'integer',
						'minimum'     => 1,
						'description' => __( 'The numeric ID of the page to update.', 'hal-mcp' ),
					],
					'title'             => [
						'type'        => 'string',
						'description' => __( 'New title. Omit to leave unchanged.', 'hal-mcp' ),
					],
					'content'           => [
						'type'        => 'string',
						'description' => __( 'New page body as block markup. Refused for Elementor-built pages (their design source is Elementor JSON, not markup). Omit to leave unchanged.', 'hal-mcp' ),
					],
					'template'          => [
						'type'        => 'string',
						'description' => __( 'New page template the active theme declares, or "default". Omit to leave unchanged.', 'hal-mcp' ),
					],
					'featured_image_id' => [
						'type'        => 'integer',
						'minimum'     => 0,
						'description' => __( 'New featured image media ID, or 0 to remove the current one. Omit to leave unchanged.', 'hal-mcp' ),
					],
					'language'          => [
						'type'        => 'string',
						'description' => __( 'Language for the page. Only languages the site actually offers are accepted; until a translations integration is active that is the site locale (a no-op, recorded on any approval request). Accepted without re-sending content fields.', 'hal-mcp' ),
					],
					'requested_status'  => [
						'type'        => 'string',
						'enum'        => [ 'draft', 'publish' ],
						'description' => __( 'Optional status change request. The request never forces the status directly — the policy decides. Omit to keep the current status.', 'hal-mcp' ),
					],
				],
				'required'             => [ 'page_id' ],
				'additionalProperties' => false,
			],

			'output_schema' => [
				'type'       => 'object',
				'properties' => [
					'applied_directly'   => [
						'type'        => 'boolean',
						'description' => __( 'True only when the target page itself was edited in place. False when the change was stored as an approval request.', 'hal-mcp' ),
					],
					'id'                 => [
						'type'        => 'integer',
						'description' => __( 'The edited page\'s ID when applied directly. 0 when only an approval request was prepared — do not treat 0 as a content ID.', 'hal-mcp' ),
					],
					'status'             => [
						'type'        => 'string',
						'description' => __( 'The target page\'s CURRENT status — unchanged until a human approves and applies the request.', 'hal-mcp' ),
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
						'description' => __( 'The target page\'s ID when a request was prepared for it (0 when applied directly).', 'hal-mcp' ),
					],
					'edit_url'           => [
						'type'        => 'string',
						'description' => __( 'The edited page\'s edit URL when applied directly; empty for a prepared request.', 'hal-mcp' ),
					],
				],
			],

			'permission_callback' => static fn( $input = [] ) => hal_mcp_permission( 'page', 'edit', (int) ( $input['page_id'] ?? 0 ), [ 'ability' => 'hal/update-page' ] ),

			'execute_callback' => function ( $input ) {

				$page_id = absint( $input['page_id'] ?? 0 );

				if ( $page_id < 1 ) {
					return new WP_Error(
						'hal_mcp_invalid_page_id',
						__( 'page_id must be a positive integer.', 'hal-mcp' )
					);
				}

				$hal_mcp_change = hal_mcp_page_build_change_map( is_array( $input ) ? $input : [] );

				if ( is_wp_error( $hal_mcp_change ) ) {
					return $hal_mcp_change;
				}

				$target_page = get_post( $page_id );

				if ( ! $target_page instanceof WP_Post || 'page' !== $target_page->post_type ) {
					return new WP_Error(
						'hal_mcp_page_not_found',
						__( 'No page was found with that ID.', 'hal-mcp' )
					);
				}

				// Object-level permission re-check (F05) — the enforcement
				// point that cannot be bypassed by a malformed pre-execute
				// input.
				if ( ! hal_mcp_permission( 'page', 'edit', $page_id, [ 'ability' => 'hal/update-page' ] ) ) {
					return new WP_Error(
						'hal_mcp_forbidden_object',
						__( 'You are not allowed to edit this page.', 'hal-mcp' )
					);
				}

				// Editor detection from the page's own stored design (F20).
				// Elementor pages never accept flattened markup writes here.
				$hal_mcp_identity = hal_mcp_page_editor_identity( $target_page );

				if ( 'elementor_json' === $hal_mcp_identity['content_source'] && array_key_exists( 'content', $hal_mcp_change ) ) {
					return new WP_Error(
						'hal_mcp_editor_content_refused',
						__( 'This page is built with Elementor. Its design is stored as Elementor JSON, which this ability will not overwrite with markup. Title, template, featured image, language, and status changes are available; design changes need the editor integration.', 'hal-mcp' )
					);
				}

				$hal_mcp_current_status = (string) $target_page->post_status;

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
						__( 'Provide at least one of title, content, template, featured_image_id, language, or a requested_status different from the current status.', 'hal-mcp' )
					);
				}

				// Shared policy decision (F05): same contract as posts.
				$write_decision = hal_mcp_decide_write_path(
					'page',
					$hal_mcp_current_status,
					[
						'operation'    => 'hal/update-page',
						'field_impact' => ( $hal_mcp_wants_status_change ? 'protected' : '' ),
					]
				);

				if ( 'deny' === $write_decision['decision'] ) {
					return new WP_Error(
						'hal_mcp_status_not_editable',
						__( 'This page is in the trash. Restore it first, then send the update again.', 'hal-mcp' )
					);
				}

				// --- Case 1: draft, no status change — edit directly through
				// the same domain function the approval path uses. ---
				if ( 'direct' === $write_decision['decision'] ) {

					// Optimistic re-check on the FRESH status before writing.
					$fresh_decision = hal_mcp_decide_write_path( 'page', (string) get_post_status( $page_id ), [ 'operation' => 'hal/update-page' ] );

					if ( 'direct' !== $fresh_decision['decision'] ) {
						// The abort is a control event, not a caller denial —
						// logged here because a bare WP_Error return would
						// leave no audit trace of a write that backed off.
						hal_mcp_log_permission_denial(
							'hal/update-page',
							'fresh status re-check failed; direct write aborted with no changes',
							[
								'stage'       => 'direct_write_aborted',
								'object_type' => 'page',
								'object_id'   => $page_id,
							]
						);

						return new WP_Error(
							'hal_mcp_page_status_changed',
							__( 'This page was published or protected by someone else while this update was being processed. No changes were made — please retry the request.', 'hal-mcp' )
						);
					}

					$hal_mcp_field_payload = array_diff_key( $hal_mcp_change, [ 'requested_status' => true ] );

					$hal_mcp_result = hal_mcp_page_apply_change( $page_id, $hal_mcp_field_payload );

					if ( is_wp_error( $hal_mcp_result ) ) {
						return $hal_mcp_result;
					}

					return [
						'applied_directly'    => true,
						'id'                  => $page_id,
						'status'              => (string) get_post_status( $page_id ),
						'request_id'          => 0,
						'state'               => '',
						'proposed_update_for' => 0,
						'edit_url'            => (string) get_edit_post_link( $page_id, 'raw' ),
					];
				}

				// --- Case 2: protected target, or a requested status change —
				// one atomic approval request (fields + requested status
				// together). The live page is never touched.
				$hal_mcp_payload = array_diff_key( $hal_mcp_change, [ 'language' => true ] );

				$hal_mcp_original_fields = array_values( array_diff( array_keys( $hal_mcp_payload ), [ 'requested_status' ] ) );
				$hal_mcp_snapshot        = empty( $hal_mcp_original_fields )
					? []
					: (array) hal_mcp_page_read_current( $page_id, $hal_mcp_original_fields );

				$hal_mcp_queued = hal_mcp_create_change_request(
					[
						'operation'         => 'update-page',
						'payload'           => $hal_mcp_payload,
						'targets'           => [
							[
								'type' => 'page',
								'id'   => $page_id,
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
					'status'              => $hal_mcp_current_status,
					'request_id'          => (int) $hal_mcp_queued['request_id'],
					// Model-facing answer: 'pending_approval' (roadmap §4.3/F17).
					'state'               => hal_mcp_request_model_state( (string) $hal_mcp_queued['state'] ),
					'proposed_update_for' => $page_id,
					'edit_url'            => '',
				];
			},

			'meta' => [ 'mcp' => [ 'public' => true ] ],
		]
	);
}
