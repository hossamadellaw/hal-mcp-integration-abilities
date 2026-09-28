<?php
/**
 * hal-mcp-abilities — hal/create-post, hal/update-post.
 *
 * The one non-negotiable rule for this file: post_status for anything this
 * file writes is decided in code, never taken from $input. The F10 contract
 * narrows that rule to its precise form: the model may REQUEST a status via
 * the unified `requested_status` input (draft/publish), but the policy layer
 * decides what happens with that request — draft creation is direct,
 * publishing (and every write to a protected status) goes through a stored
 * change request (F17) that a human approves in wp-admin. Nothing here ever
 * calls wp_update_post() with post_status='publish' outside the request
 * apply handler, and the handler only ever runs after hal_mcp_apply_
 * change_request() has re-verified the approver's capabilities.
 *
 * F10 structure (roadmap):
 * - Domain functions live in THIS file and are shared with the apply
 *   handler — the approval path and the direct path run the same
 *   hal_mcp_post_apply_change() code, so they cannot drift apart.
 * - A protected target is never touched live and never spawns a "proposal
 *   draft" copy anymore: the change (fields + requested status together,
 *   one atomic proposal) is stored as a hal_mcp_request with a fingerprint
 *   of exactly the original fields being changed. Output carries request_id
 *   + state and never claims applied_directly for a prepared request.
 * - The v1 nothing-to-update guard now accepts requested_status alone (e.g.
 *   publishing an existing draft after approval) and a supported language
 *   field without forcing a content re-send — but still refuses a request
 *   with no actual effect (same status, no fields).
 * - Language is validated against the site's REAL language surface
 *   (includes/environment.php). Without a translations integration (F23)
 *   the only language is the site locale, and assigning it is a no-op.
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
 * Validates and sanitizes the F10 field set shared by hal/create-post and
 * hal/update-post into a clean change map. Field-level sanitization happens
 * HERE (the request payload store only enforces structure), so every value
 * that reaches storage or a post is already domain-clean.
 *
 * Categories must already exist (assigning is content work; creating new
 * taxonomy terms from model input is a side effect this version does not
 * take). A featured image must be an existing attachment the caller can
 * read. Language must be one the site actually offers.
 *
 * @param array<string, mixed> $input Raw ability input.
 * @return array<string, mixed>|WP_Error Clean change map (only provided
 *   fields present), or WP_Error for the first invalid field.
 */
function hal_mcp_post_build_change_map( array $input ) {

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

	if ( array_key_exists( 'categories', $input ) ) {
		$hal_mcp_category_ids = hal_mcp_post_resolve_category_ids( (array) $input['categories'] );

		if ( is_wp_error( $hal_mcp_category_ids ) ) {
			return $hal_mcp_category_ids;
		}

		$change['categories'] = $hal_mcp_category_ids;
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

			if ( ! hal_mcp_permission( 'media', 'read', $hal_mcp_image_id, [ 'ability' => 'hal/write-posts', 'reason' => 'featured_image' ] ) ) {
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
 * Resolves category names or IDs to existing term IDs (F10: تصنيفات). Terms
 * that do not exist are refused with their names listed — this version never
 * creates taxonomy terms from model input.
 *
 * @param array<int|string, mixed> $categories Category names and/or IDs.
 * @return int[]|WP_Error
 */
function hal_mcp_post_resolve_category_ids( array $categories ) {

	$hal_mcp_ids    = [];
	$hal_mcp_unknown = [];

	foreach ( $categories as $hal_mcp_category ) {
		if ( is_int( $hal_mcp_category ) || ( is_string( $hal_mcp_category ) && ctype_digit( trim( $hal_mcp_category ) ) ) ) {
			$hal_mcp_term_id = absint( $hal_mcp_category );
			$hal_mcp_term    = $hal_mcp_term_id > 0 ? get_term( $hal_mcp_term_id, 'category' ) : null;
		} else {
			$hal_mcp_term    = get_term_by( 'name', sanitize_text_field( (string) $hal_mcp_category ), 'category' );
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
				__( 'These categories do not exist on this site and are not created automatically: %s.', 'hal-mcp' ),
				implode( ', ', array_unique( $hal_mcp_unknown ) )
			)
		);
	}

	return array_values( array_unique( $hal_mcp_ids ) );
}

/**
 * Reads the CURRENT values of exactly the given field keys from the live
 * post — the single definition used both for the original snapshot at
 * request-creation time and for the pre-apply fingerprint revalidation, so
 * the two reads cannot diverge in shape.
 *
 * @param int                     $post_id Post ID.
 * @param string[]                $fields  Field keys (title, content,
 *                                         excerpt, categories,
 *                                         featured_image_id).
 * @return array<string, mixed>|null null when the post does not exist.
 */
function hal_mcp_post_read_current( int $post_id, array $fields ) {

	$post = get_post( $post_id );

	if ( ! $post instanceof WP_Post || 'post' !== $post->post_type ) {
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
			case 'categories':
				$hal_mcp_current['categories'] = array_map( 'intval', wp_get_post_categories( $post_id ) );
				break;
			case 'featured_image_id':
				$hal_mcp_current['featured_image_id'] = (int) get_post_meta( $post_id, '_thumbnail_id', true );
				break;
		}
	}

	return $hal_mcp_current;
}

/**
 * THE domain apply function for post changes (F10: «استعمال دوال domain داخل
 * الملف نفسه للتحقق والتطبيق وإعادة استخدامها من طلبات الاعتماد»). Both the
 * direct draft path and the F17 apply handler run through here, so the two
 * paths cannot drift.
 *
 * The payload passed here must already be domain-sanitized (it is, in both
 * call paths: built by hal_mcp_post_build_change_map(), or stored from that
 * same builder and replayed verbatim by the handler). Re-validation of
 * permission happens in the callers; this function re-checks the target
 * still exists and is not in the trash before writing.
 *
 * @param int                     $post_id Target post ID.
 * @param array<string, mixed>    $payload Change map (fields + optional
 *                                         requested_status).
 * @return array{applied: bool, post_id: int, new_status: string}|WP_Error
 */
function hal_mcp_post_apply_change( int $post_id, array $payload ) {

	$post = get_post( $post_id );

	if ( ! $post instanceof WP_Post || 'post' !== $post->post_type ) {
		return new WP_Error(
			'hal_mcp_post_not_found',
			__( 'No blog post was found with that ID.', 'hal-mcp' )
		);
	}

	if ( 'trash' === $post->post_status ) {
		return new WP_Error(
			'hal_mcp_status_not_editable',
			__( 'This post is in the trash. Restore it first, then send the update again.', 'hal-mcp' )
		);
	}

	$update_args = [ 'ID' => $post_id ];

	if ( isset( $payload['title'] ) ) {
		$update_args['post_title'] = (string) $payload['title'];
	}
	if ( isset( $payload['content'] ) ) {
		$update_args['post_content'] = (string) $payload['content'];
	}
	if ( isset( $payload['excerpt'] ) ) {
		$update_args['post_excerpt'] = (string) $payload['excerpt'];
	}

	// Deliberately no post_status key unless the payload explicitly requests
	// one, and only the two values the F10 contract names. Publishing is
	// reached exclusively through an approved request — hal_mcp_apply_
	// change_request() has already verified the approver's capabilities with
	// a 'publish' effect by the time this line runs with requested_status.
	if ( isset( $payload['requested_status'] )
		&& in_array( $payload['requested_status'], [ 'draft', 'publish' ], true )
		&& $payload['requested_status'] !== $post->post_status ) {
		$update_args['post_status'] = (string) $payload['requested_status'];
	}

	$updated_id = wp_update_post( $update_args, true );

	if ( is_wp_error( $updated_id ) ) {
		return $updated_id;
	}

	if ( isset( $payload['categories'] ) && is_array( $payload['categories'] ) ) {
		wp_set_post_categories( $post_id, array_map( 'intval', $payload['categories'] ) );
	}

	if ( isset( $payload['featured_image_id'] ) ) {
		$hal_mcp_image_id = absint( $payload['featured_image_id'] );

		if ( $hal_mcp_image_id > 0 ) {
			update_post_meta( $post_id, '_thumbnail_id', $hal_mcp_image_id );
		} else {
			delete_post_meta( $post_id, '_thumbnail_id' );
		}
	}

	return [
		'applied'    => true,
		'post_id'    => $post_id,
		'new_status' => (string) get_post_status( $post_id ),
	];
}

/**
 * Live fingerprint revalidation for 'update-post' requests (F17 contract:
 * fetch the target's CURRENT original fields, never echo the stored
 * snapshot). Field keys come from the stored payload; a status-only request
 * carries no original fields and returns '' (nothing to compare — the
 * apply-time fail-safe handles that case).
 *
 * @param array<string, mixed> $request Request data from hal_mcp_get_change_request().
 * @return string Fingerprint of the live original fields, or '' when there
 *                is nothing to compare (or the target no longer exists —
 *                which the apply-time check turns into a conflict).
 */
function hal_mcp_post_revalidate_fingerprint( array $request ): string {

	$hal_mcp_payload = (array) ( $request['payload'] ?? [] );
	$hal_mcp_targets = (array) ( $request['targets'] ?? [] );
	$hal_mcp_post_id = (int) ( $hal_mcp_targets[0]['id'] ?? 0 );

	if ( $hal_mcp_post_id < 1 ) {
		return '';
	}

	$hal_mcp_fields = array_values( array_diff( array_keys( $hal_mcp_payload ), [ 'requested_status' ] ) );

	// Status-only requests read an EMPTY original ([]) whose fingerprint
	// matches the stored fingerprint of their empty snapshot; a GONE target
	// reads null and the '' return turns into an apply-time conflict — the
	// handler must never publish onto a vanished post.
	$hal_mcp_current = hal_mcp_post_read_current( $hal_mcp_post_id, $hal_mcp_fields );

	if ( null === $hal_mcp_current ) {
		return '';
	}

	return hal_mcp_fingerprint( $hal_mcp_current );
}

/**
 * Registers the 'update-post' apply handler (F17). Runs at file load, so the
 * handler exists for the whole lifetime of this module — the same lifetime
 * the abilities themselves have (the bootstrap requires this file only when
 * the Abilities API is present).
 *
 * @return void
 */
function hal_mcp_register_post_apply_handler(): void {

	hal_mcp_register_apply_handler(
		'update-post',
		static function ( array $request ) {
			$hal_mcp_targets = (array) ( $request['targets'] ?? [] );
			$hal_mcp_post_id = (int) ( $hal_mcp_targets[0]['id'] ?? 0 );
			$hal_mcp_payload = (array) ( $request['payload'] ?? [] );

			$hal_mcp_result = hal_mcp_post_apply_change( $hal_mcp_post_id, $hal_mcp_payload );

			if ( is_wp_error( $hal_mcp_result ) ) {
				return $hal_mcp_result;
			}

			return sprintf(
				/* translators: 1: post ID. 2: new post status. */
				__( 'Post #%1$d updated; current status: %2$s.', 'hal-mcp' ),
				$hal_mcp_result['post_id'],
				$hal_mcp_result['new_status']
			);
		},
		'hal_mcp_post_revalidate_fingerprint'
	);
}

hal_mcp_register_post_apply_handler();

add_action( 'wp_abilities_api_init', 'hal_mcp_register_write_post_abilities' );

/**
 * Registers the hal/create-post and hal/update-post abilities.
 *
 * @return void
 */
function hal_mcp_register_write_post_abilities(): void {

	wp_register_ability(
		'hal/create-post',
		[
			'label'       => __( 'Create a draft post', 'hal-mcp' ),
			'description' => __( 'Creates a new blog post as a draft (optionally with excerpt, categories, featured image, and language). Never publishes directly: pass requested_status="publish" to also queue an approval request that a human approves in wp-admin — the response then carries request_id and state.', 'hal-mcp' ),
			'category'    => 'hal-content',

			'input_schema' => [
				'type'                 => 'object',
				'properties'           => [
					'title'             => [
						'type'        => 'string',
						'description' => __( 'The post title.', 'hal-mcp' ),
					],
					'content'           => [
						'type'        => 'string',
						'description' => __( 'The post body. Basic HTML is allowed; scripts and unsafe markup are stripped.', 'hal-mcp' ),
					],
					'excerpt'           => [
						'type'        => 'string',
						'description' => __( 'Optional short excerpt/summary.', 'hal-mcp' ),
					],
					'categories'        => [
						'type'        => 'array',
						'items'       => [
							'type' => [ 'string', 'integer' ],
						],
						'description' => __( 'Optional category names or IDs. Every category must already exist; none are created automatically.', 'hal-mcp' ),
					],
					'featured_image_id' => [
						'type'        => 'integer',
						'minimum'     => 0,
						'default'     => 0,
						'description' => __( 'Optional ID of an existing media library item to set as the featured image. 0 (default) sets none.', 'hal-mcp' ),
					],
					'language'          => [
						'type'        => 'string',
						'description' => __( 'Optional language for the post. Only languages the site actually offers are accepted; until a translations integration is active that is the site locale (a no-op, recorded on any approval request).', 'hal-mcp' ),
					],
					'requested_status'  => [
						'type'        => 'string',
						'enum'        => [ 'draft', 'publish' ],
						'default'     => 'draft',
						'description' => __( '"draft" (default) creates the post as a draft directly. "publish" still creates it as a draft AND queues an approval request for the publishing step — never an immediate publish.', 'hal-mcp' ),
					],
				],
				'required'             => [ 'title', 'content' ],
				'additionalProperties' => false,
			],

			'output_schema' => [
				'type'       => 'object',
				'properties' => [
					'id'          => [
						'type'        => 'integer',
						'description' => __( 'The created draft post\'s ID (a draft always exists even when a publish approval is queued).', 'hal-mcp' ),
					],
					'status'      => [ 'type' => 'string' ],
					'edit_url'    => [ 'type' => 'string' ],
					'request_id'  => [
						'type'        => 'integer',
						'description' => __( 'The queued approval request\'s ID when requested_status was "publish", otherwise 0. This is the REQUEST\'s ID, not a content ID.', 'hal-mcp' ),
					],
					'state'       => [
						'type'        => 'string',
						'description' => __( 'The model-facing state: "pending_approval" when a change request was queued and is awaiting admin approval (the store state remains "pending"), otherwise empty.', 'hal-mcp' ),
					],
				],
			],

			'permission_callback' => static fn() => hal_mcp_permission( 'post', 'create', 0, [ 'ability' => 'hal/create-post' ] ),

			'execute_callback' => function ( $input ) {

				$hal_mcp_change = hal_mcp_post_build_change_map( is_array( $input ) ? $input : [] );

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

				// Draft creation is the direct path — the literal below is the
				// one rule that matters most in this whole plugin: never
				// derived from $input, no matter what a caller sends. With
				// requested_status="publish" the draft is still what gets
				// created here; the publish step itself becomes a request.
				$post_id = wp_insert_post(
					[
						'post_title'   => $hal_mcp_title,
						'post_content' => $hal_mcp_content,
						'post_excerpt' => isset( $hal_mcp_change['excerpt'] ) ? (string) $hal_mcp_change['excerpt'] : '',
						'post_type'    => 'post',
						'post_status'  => 'draft',
					],
					true
				);

				if ( is_wp_error( $post_id ) ) {
					return $post_id;
				}

				$post_id = (int) $post_id;

				// Non-status fields are draft work (F10: تعديل draft المسموح
				// مباشرة) — applied through the SAME domain function the
				// approval path uses.
				$hal_mcp_field_payload = array_diff_key( $hal_mcp_change, [ 'requested_status' => true ] );

				if ( ! empty( $hal_mcp_field_payload ) ) {
					$hal_mcp_field_result = hal_mcp_post_apply_change( $post_id, $hal_mcp_field_payload );

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
							'operation'         => 'update-post',
							'payload'           => [ 'requested_status' => 'publish' ],
							'targets'           => [
								[
									'type' => 'post',
									'id'   => $post_id,
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
					'id'         => $post_id,
					'status'     => (string) get_post_status( $post_id ),
					'edit_url'   => (string) get_edit_post_link( $post_id, 'raw' ),
					'request_id' => $hal_mcp_request['request_id'],
					'state'      => $hal_mcp_request['state'],
				];
			},

			'meta' => [ 'mcp' => [ 'public' => true ] ],
		]
	);

	wp_register_ability(
		'hal/update-post',
		[
			'label'       => __( 'Update a post', 'hal-mcp' ),
			'description' => __( 'Updates a post\'s title, content, excerpt, categories, featured image, and/or language, and optionally requests a status change via requested_status ("draft"/"publish"). A draft with no status change is edited directly. Anything else — a protected target (published, private, scheduled, pending) or a publish request — is stored as one atomic approval request (request_id + state in the response) for a human to approve in wp-admin; the live post is never touched and no content ID is invented. Requests with no actual effect are refused.', 'hal-mcp' ),
			'category'    => 'hal-content',

			'input_schema' => [
				'type'                 => 'object',
				'properties'           => [
					'post_id'           => [
						'type'        => 'integer',
						'minimum'     => 1,
						'description' => __( 'The numeric ID of the post to update.', 'hal-mcp' ),
					],
					'title'             => [
						'type'        => 'string',
						'description' => __( 'New title. Omit to leave the title unchanged.', 'hal-mcp' ),
					],
					'content'           => [
						'type'        => 'string',
						'description' => __( 'New body content. Omit to leave the content unchanged.', 'hal-mcp' ),
					],
					'excerpt'           => [
						'type'        => 'string',
						'description' => __( 'New excerpt. Omit to leave the excerpt unchanged.', 'hal-mcp' ),
					],
					'categories'        => [
						'type'        => 'array',
						'items'       => [
							'type' => [ 'string', 'integer' ],
						],
						'description' => __( 'New full category list (names or IDs, all must already exist). Replaces the post\'s categories. Omit to leave unchanged.', 'hal-mcp' ),
					],
					'featured_image_id' => [
						'type'        => 'integer',
						'minimum'     => 0,
						'description' => __( 'New featured image media ID, or 0 to remove the current one. Omit to leave unchanged.', 'hal-mcp' ),
					],
					'language'          => [
						'type'        => 'string',
						'description' => __( 'Language for the post. Only languages the site actually offers are accepted; until a translations integration is active that is the site locale (a no-op, recorded on any approval request). Accepted without re-sending content fields.', 'hal-mcp' ),
					],
					'requested_status'  => [
						'type'        => 'string',
						'enum'        => [ 'draft', 'publish' ],
						'description' => __( 'Optional status change request. The request never forces the status directly — the policy decides: on a draft, "publish" queues an approval; on a protected post, "draft" (unpublish) and "publish" both ride the same approval request. Omit to keep the current status.', 'hal-mcp' ),
					],
				],
				'required'             => [ 'post_id' ],
				'additionalProperties' => false,
			],

			'output_schema' => [
				'type'       => 'object',
				'properties' => [
					'applied_directly'   => [
						'type'        => 'boolean',
						'description' => __( 'True only when the target post itself was edited in place. False when the change was stored as an approval request — a request alone never claims to have been applied.', 'hal-mcp' ),
					],
					'id'                 => [
						'type'        => 'integer',
						'description' => __( 'The edited post\'s ID when applied directly. 0 when only an approval request was prepared — do not treat 0 as a content ID.', 'hal-mcp' ),
					],
					'status'             => [
						'type'        => 'string',
						'description' => __( 'The target post\'s CURRENT status — unchanged until a human approves and applies the request.', 'hal-mcp' ),
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
						'description' => __( 'The target post\'s ID when a request was prepared for it (0 when applied directly). Replaces the old proposal-draft semantics: no copy post exists anymore.', 'hal-mcp' ),
					],
					'edit_url'           => [
						'type'        => 'string',
						'description' => __( 'The edited post\'s edit URL when applied directly; empty for a prepared request (the review happens in the admin approval screen, not on an invented URL).', 'hal-mcp' ),
					],
				],
			],

			'permission_callback' => static fn( $input = [] ) => hal_mcp_permission( 'post', 'edit', (int) ( $input['post_id'] ?? 0 ), [ 'ability' => 'hal/update-post' ] ),

			'execute_callback' => function ( $input ) {

				$post_id = absint( $input['post_id'] ?? 0 );

				if ( $post_id < 1 ) {
					return new WP_Error(
						'hal_mcp_invalid_post_id',
						__( 'post_id must be a positive integer.', 'hal-mcp' )
					);
				}

				$hal_mcp_change = hal_mcp_post_build_change_map( is_array( $input ) ? $input : [] );

				if ( is_wp_error( $hal_mcp_change ) ) {
					return $hal_mcp_change;
				}

				$target_post = get_post( $post_id );

				// Same generic "not found" whether the ID is missing entirely
				// or belongs to a different post_type — see the identical
				// rationale in abilities/read-posts.php.
				if ( ! $target_post instanceof WP_Post || 'post' !== $target_post->post_type ) {
					return new WP_Error(
						'hal_mcp_post_not_found',
						__( 'No blog post was found with that ID.', 'hal-mcp' )
					);
				}

				// Object-level permission re-check (F05): the pre-execute
				// permission_callback saw the same ID, but the Abilities API
				// does not validate permission inputs — this is the
				// enforcement point that cannot be bypassed.
				if ( ! hal_mcp_permission( 'post', 'edit', $post_id, [ 'ability' => 'hal/update-post' ] ) ) {
					return new WP_Error(
						'hal_mcp_forbidden_object',
						__( 'You are not allowed to edit this post.', 'hal-mcp' )
					);
				}

				$hal_mcp_current_status = (string) $target_post->post_status;

				$hal_mcp_wants_status_change = isset( $hal_mcp_change['requested_status'] )
					&& $hal_mcp_change['requested_status'] !== $hal_mcp_current_status;

				// Guard fix (F10: write-posts.php:189–193): requested_status
				// alone (e.g. publishing an existing draft) and a supported
				// language field are both accepted without a content re-send
				// — only a change with NO effect at all is refused. Language
				// equal to the site locale is a no-op by definition until a
				// translations integration (F23) widens the surface.
				$hal_mcp_field_keys = array_values( array_diff( array_keys( $hal_mcp_change ), [ 'requested_status', 'language' ] ) );

				$hal_mcp_language_noop = ! isset( $hal_mcp_change['language'] )
					|| $hal_mcp_change['language'] === sanitize_key( (string) get_locale() );

				if ( empty( $hal_mcp_field_keys ) && ! $hal_mcp_wants_status_change && $hal_mcp_language_noop ) {
					return new WP_Error(
						'hal_mcp_nothing_to_update',
						__( 'Provide at least one of title, content, excerpt, categories, featured_image_id, language, or a requested_status different from the current status.', 'hal-mcp' )
					);
				}

				// Shared policy decision (F05): draft/auto-draft edits
				// directly, protected statuses become a request, trash is
				// refused. A requested status change rides the request path
				// even on a draft — publishing is the policy's decision, not
				// the tool's.
				$write_decision = hal_mcp_decide_write_path(
					'post',
					$hal_mcp_current_status,
					[
						'operation' => 'hal/update-post',
						'field_impact' => ( $hal_mcp_wants_status_change ? 'protected' : '' ),
					]
				);

				if ( 'deny' === $write_decision['decision'] ) {
					return new WP_Error(
						'hal_mcp_status_not_editable',
						__( 'This post is in the trash. Restore it first, then send the update again.', 'hal-mcp' )
					);
				}

				// --- Case 1: draft, no status change — edit directly through
				// the same domain function the approval path uses. ---
				if ( 'direct' === $write_decision['decision'] ) {

					// Optimistic re-check immediately before the write: the
					// status read above and this save are two separate
					// requests, so re-run the shared decision on the FRESH
					// status (e.g. a human publishing or privatizing this same
					// post concurrently in wp-admin) before writing to it
					// directly. If the post is no longer directly editable,
					// abort without writing — a protected post must never be
					// edited directly, no exceptions.
					$fresh_decision = hal_mcp_decide_write_path( 'post', (string) get_post_status( $post_id ), [ 'operation' => 'hal/update-post' ] );

					if ( 'direct' !== $fresh_decision['decision'] ) {
						// The abort is a control event, not a caller denial —
						// logged here because a bare WP_Error return would
						// leave no audit trace of a write that backed off.
						hal_mcp_log_permission_denial(
							'hal/update-post',
							'fresh status re-check failed; direct write aborted with no changes',
							[
								'stage'       => 'direct_write_aborted',
								'object_type' => 'post',
								'object_id'   => $post_id,
							]
						);

						return new WP_Error(
							'hal_mcp_post_status_changed',
							__( 'This post was published or protected by someone else while this update was being processed. No changes were made — please retry the request.', 'hal-mcp' )
						);
					}

					$hal_mcp_field_payload = array_diff_key( $hal_mcp_change, [ 'requested_status' => true ] );

					$hal_mcp_result = hal_mcp_post_apply_change( $post_id, $hal_mcp_field_payload );

					if ( is_wp_error( $hal_mcp_result ) ) {
						return $hal_mcp_result;
					}

					return [
						'applied_directly'    => true,
						'id'                  => $post_id,
						'status'              => (string) get_post_status( $post_id ),
						'request_id'          => 0,
						'state'               => '',
						'proposed_update_for' => 0,
						'edit_url'            => (string) get_edit_post_link( $post_id, 'raw' ),
					];
				}

				// --- Case 2: protected target, or a requested status change —
				// one atomic approval request (fields + requested status
				// together, per §4.3's approval binding). The live post is
				// never touched; no proposal copy post is created anymore.
				$hal_mcp_payload = array_diff_key( $hal_mcp_change, [ 'language' => true ] );

				// Snapshot of EXACTLY the original fields being changed (F17
				// contract) — read live, right now. A status-only request has
				// nothing to snapshot ({}), and the apply handler re-checks
				// the target's liveness itself.
				$hal_mcp_original_fields = array_values( array_diff( array_keys( $hal_mcp_payload ), [ 'requested_status' ] ) );
				$hal_mcp_snapshot        = empty( $hal_mcp_original_fields )
					? []
					: (array) hal_mcp_post_read_current( $post_id, $hal_mcp_original_fields );

				$hal_mcp_queued = hal_mcp_create_change_request(
					[
						'operation'         => 'update-post',
						'payload'           => $hal_mcp_payload,
						'targets'           => [
							[
								'type' => 'post',
								'id'   => $post_id,
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
					'proposed_update_for' => $post_id,
					'edit_url'            => '',
				];
			},

			'meta' => [ 'mcp' => [ 'public' => true ] ],
		]
	);
}
