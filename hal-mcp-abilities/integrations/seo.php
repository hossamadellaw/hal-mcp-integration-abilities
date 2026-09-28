<?php
/**
 * hal-mcp-abilities — SEO integration with the Yoast handler (F22).
 *
 * The roadmap's execution limit for this version, implemented here: a real
 * Yoast handler that reads and edits the SEO title and description through
 * Yoast's own documented Metadata API (WPSEO_Meta::get_value()/set_value()
 * — the versioned interface, not a guessed meta key), and lets Yoast's own
 * meta watcher carry the change into its indexable storage. Yoast's REST
 * API is documented read-only and is never treated as a write path here.
 *
 * The unified field contract (F22) lists every field the model may ask
 * about, each carrying whether it is writable and the reason when it is
 * not: this version implements title and description; canonical/robots/
 * social/schema stay explicitly NOT writable until their save method is
 * verified — they are never written "because the meta name looks similar",
 * and no anonymous meta setter exists in this file.
 *
 * SEO changes follow the shared write policy (F05/F17): a draft's SEO
 * fields are draft work applied directly; anything touching a protected
 * (published/private/future/pending) item becomes a stored change request
 * carrying the before-values as its fingerprinted original snapshot, so the
 * SEO change is approved, re-validated against the live values, and applied
 * exactly like any other protected change.
 *
 * Detection is runtime-marker based (WPSEO_VERSION + the WPSEO_Meta class)
 * — never a plugin-name guess. When no supported SEO tool is present the
 * integration registers itself as unavailable with that reason; content
 * optimization continues, and the contract honestly says which metadata
 * needs an integration. Other SEO plugins can join later as additional
 * handlers under the same contract — no per-plugin files are invented here.
 *
 * @package hal-mcp-abilities
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The Yoast meta keys this handler is verified against (the documented
 * Metadata API keys for the SEO title and the meta description).
 *
 * @var array<string, string>
 */
const HAL_MCP_SEO_FIELD_META_KEYS = [
	'seo_title'       => 'title',
	'seo_description' => 'metadesc',
];

/**
 * The unified SEO field contract (F22): every field the model may name,
 * with its writability and the reason a non-writable field is not writable.
 * The writable entries carry the verified interface/version source they are
 * implemented against.
 *
 * @return array<string, array{label: string, writable: bool, reason: string, source: string}>
 */
function hal_mcp_seo_field_contract(): array {

	$hal_mcp_yoast_version = defined( 'WPSEO_VERSION' ) ? (string) WPSEO_VERSION : '';

	return [
		'seo_title'       => [
			'label'    => __( 'SEO title', 'hal-mcp' ),
			'writable' => hal_mcp_seo_yoast_present(),
			'reason'   => hal_mcp_seo_yoast_present() ? '' : __( 'Yoast SEO is not active; the field has no verified write interface.', 'hal-mcp' ),
			'source'   => sprintf( 'WPSEO_Meta::set_value( "title" ) (Yoast %s Metadata API)', $hal_mcp_yoast_version ),
		],
		'seo_description' => [
			'label'    => __( 'SEO description', 'hal-mcp' ),
			'writable' => hal_mcp_seo_yoast_present(),
			'reason'   => hal_mcp_seo_yoast_present() ? '' : __( 'Yoast SEO is not active; the field has no verified write interface.', 'hal-mcp' ),
			'source'   => sprintf( 'WPSEO_Meta::set_value( "metadesc" ) (Yoast %s Metadata API)', $hal_mcp_yoast_version ),
		],
		'canonical'       => [
			'label'    => __( 'Canonical URL', 'hal-mcp' ),
			'writable' => false,
			'reason'   => __( 'No verified save method for this field in this version (F22 implements the SEO title and description); it is never written by name similarity.', 'hal-mcp' ),
			'source'   => '',
		],
		'robots'          => [
			'label'    => __( 'Robots directives', 'hal-mcp' ),
			'writable' => false,
			'reason'   => __( 'No verified save method for this field in this version (F22 implements the SEO title and description); it is never written by name similarity.', 'hal-mcp' ),
			'source'   => '',
		],
		'social'          => [
			'label'    => __( 'Social (Open Graph/Twitter) metadata', 'hal-mcp' ),
			'writable' => false,
			'reason'   => __( 'No verified save method for this field in this version (F22 implements the SEO title and description); it is never written by name similarity.', 'hal-mcp' ),
			'source'   => '',
		],
		'schema'          => [
			'label'    => __( 'Structured data (schema)', 'hal-mcp' ),
			'writable' => false,
			'reason'   => __( 'No verified save method for this field in this version (F22 implements the SEO title and description); it is never written by name similarity.', 'hal-mcp' ),
			'source'   => '',
		],
	];
}

/**
 * Whether the Yoast handler's verified interface is available right now
 * (runtime markers only — a plugin name is never an availability claim).
 *
 * @return bool
 */
function hal_mcp_seo_yoast_present(): bool {
	return defined( 'WPSEO_VERSION' ) && class_exists( 'WPSEO_Meta' )
		&& method_exists( 'WPSEO_Meta', 'get_value' )
		&& method_exists( 'WPSEO_Meta', 'set_value' );
}

/**
 * Normalizes one raw Yoast meta value (defensive: some Yoast versions store
 * empty values as the literal string 'null').
 *
 * @param mixed $value Raw value.
 * @return string
 */
function hal_mcp_seo_normalize_value( $value ): string {
	$hal_mcp_value = (string) $value;

	return 'null' === $hal_mcp_value ? '' : $hal_mcp_value;
}

/**
 * Reads the SEO fields of one content object through the verified interface.
 *
 * @param int      $post_id  Content object ID.
 * @param string[] $fields   Optional subset (contract slugs); default: the
 *                           writable fields.
 * @return array<string, array{value: string, writable: bool, reason: string}>|WP_Error
 */
function hal_mcp_seo_read_fields( int $post_id, array $fields = [] ) {

	if ( ! hal_mcp_seo_yoast_present() ) {
		return new WP_Error(
			'hal_mcp_seo_unavailable',
			__( 'No supported SEO integration is active on this site; SEO metadata is not read or written.', 'hal-mcp' )
		);
	}

	if ( $post_id < 1 ) {
		return new WP_Error( 'hal_mcp_invalid_post_id', __( 'The content ID must be a positive integer.', 'hal-mcp' ) );
	}

	$hal_mcp_contract = hal_mcp_seo_field_contract();
	$hal_mcp_wanted   = empty( $fields ) ? array_keys( HAL_MCP_SEO_FIELD_META_KEYS ) : $fields;

	$hal_mcp_out = [];

	foreach ( $hal_mcp_wanted as $hal_mcp_field ) {
		$hal_mcp_field = (string) $hal_mcp_field;

		if ( ! isset( $hal_mcp_contract[ $hal_mcp_field ] ) ) {
			return new WP_Error(
				'hal_mcp_unknown_seo_field',
				sprintf(
					/* translators: %s: field slug. */
					__( 'The SEO field "%s" is not part of the SEO field contract.', 'hal-mcp' ),
					$hal_mcp_field
				)
			);
		}

		$hal_mcp_value = '';

		if ( isset( HAL_MCP_SEO_FIELD_META_KEYS[ $hal_mcp_field ] ) ) {
			$hal_mcp_value = hal_mcp_seo_normalize_value(
				WPSEO_Meta::get_value( HAL_MCP_SEO_FIELD_META_KEYS[ $hal_mcp_field ], $post_id )
			);
		}

		$hal_mcp_out[ $hal_mcp_field ] = [
			'value'    => $hal_mcp_value,
			'writable' => (bool) $hal_mcp_contract[ $hal_mcp_field ]['writable'],
			'reason'   => (string) $hal_mcp_contract[ $hal_mcp_field ]['reason'],
		];
	}

	return $hal_mcp_out;
}

/**
 * Writes the SEO title/description of one content object through the
 * verified interface (WPSEO_Meta::set_value — Yoast's own watcher carries
 * the change into its indexable storage). Only contract-writable fields are
 * ever written; every write is proven by reading the value back.
 *
 * Validation runs for ALL fields before the first write, so a rejected
 * field never leaves an earlier field already written. A mid-write failure
 * is reported honestly with what WAS written and what failed (§4.3: فشل
 * منتصف عملية متعددة الحقول يسجل ما تم وما فشل) — it is never re-attempted
 * automatically.
 *
 * @param int                  $post_id Content object ID.
 * @param array<string, mixed> $fields  Contract slug => value (writable only).
 * @return array{applied: bool, post_id: int, fields: string[]}|WP_Error
 */
function hal_mcp_seo_apply_fields( int $post_id, array $fields ) {

	if ( ! hal_mcp_seo_yoast_present() ) {
		return new WP_Error(
			'hal_mcp_seo_unavailable',
			__( 'No supported SEO integration is active on this site; SEO metadata is not read or written.', 'hal-mcp' )
		);
	}

	if ( $post_id < 1 ) {
		return new WP_Error( 'hal_mcp_invalid_post_id', __( 'The content ID must be a positive integer.', 'hal-mcp' ) );
	}

	if ( empty( $fields ) ) {
		return new WP_Error(
			'hal_mcp_nothing_to_update',
			__( 'Provide at least one SEO field to write.', 'hal-mcp' )
		);
	}

	// Pass 1 — validate and clean EVERY field before touching storage: an
	// unknown, non-writable, or unmapped field is refused by name with no
	// partial application.
	$hal_mcp_contract = hal_mcp_seo_field_contract();
	$hal_mcp_clean    = [];

	foreach ( $fields as $hal_mcp_field => $hal_mcp_value ) {
		$hal_mcp_field = (string) $hal_mcp_field;

		if ( ! isset( $hal_mcp_contract[ $hal_mcp_field ] ) ) {
			return new WP_Error(
				'hal_mcp_unknown_seo_field',
				sprintf(
					/* translators: %s: field slug. */
					__( 'The SEO field "%s" is not part of the SEO field contract.', 'hal-mcp' ),
					$hal_mcp_field
				)
			);
		}

		if ( empty( $hal_mcp_contract[ $hal_mcp_field ]['writable'] ) ) {
			return new WP_Error(
				'hal_mcp_seo_field_not_writable',
				sprintf(
					/* translators: %s: field slug. */
					__( 'The SEO field "%s" is not writable through this integration.', 'hal-mcp' ),
					$hal_mcp_field
				)
			);
		}

		if ( ! isset( HAL_MCP_SEO_FIELD_META_KEYS[ $hal_mcp_field ] ) ) {
			return new WP_Error(
				'hal_mcp_seo_field_unmapped',
				sprintf(
					/* translators: %s: field slug. */
					__( 'The SEO field "%s" has no verified storage key; it was not written.', 'hal-mcp' ),
					$hal_mcp_field
				)
			);
		}

		$hal_mcp_clean[ $hal_mcp_field ] = 'seo_title' === $hal_mcp_field
			? sanitize_text_field( (string) $hal_mcp_value )
			: sanitize_textarea_field( (string) $hal_mcp_value );
	}

	// Pass 2 — write and prove each field; a mid-write failure says exactly
	// what was already written and what failed.
	$hal_mcp_applied = [];

	foreach ( $hal_mcp_clean as $hal_mcp_field => $hal_mcp_value ) {
		$hal_mcp_written = WPSEO_Meta::set_value(
			HAL_MCP_SEO_FIELD_META_KEYS[ $hal_mcp_field ],
			$hal_mcp_value,
			$post_id
		);

		if ( true !== $hal_mcp_written ) {
			return new WP_Error(
				'hal_mcp_seo_write_failed',
				sprintf(
					/* translators: 1: failed field slug. 2: comma-separated already-written field slugs. */
					__( 'The SEO field "%1$s" could not be saved. Already written before this failure: %2$s.', 'hal-mcp' ),
					$hal_mcp_field,
					empty( $hal_mcp_applied ) ? __( 'nothing', 'hal-mcp' ) : implode( ', ', $hal_mcp_applied )
				)
			);
		}

		// Prove the write by reading it back through the same interface.
		if ( hal_mcp_seo_normalize_value( WPSEO_Meta::get_value( HAL_MCP_SEO_FIELD_META_KEYS[ $hal_mcp_field ], $post_id ) ) !== $hal_mcp_value ) {
			return new WP_Error(
				'hal_mcp_seo_write_unverified',
				sprintf(
					/* translators: 1: failed field slug. 2: comma-separated already-written field slugs. */
					__( 'The SEO field "%1$s" was written but could not be read back. Already proven before this failure: %2$s.', 'hal-mcp' ),
					$hal_mcp_field,
					empty( $hal_mcp_applied ) ? __( 'nothing', 'hal-mcp' ) : implode( ', ', $hal_mcp_applied )
				)
			);
		}

		$hal_mcp_applied[] = $hal_mcp_field;
	}

	return [
		'applied'  => true,
		'post_id'  => $post_id,
		'fields'   => $hal_mcp_applied,
	];
}

// ---------------------------------------------------------------------------
// Proposal path (direct for drafts, change request for protected items)
// ---------------------------------------------------------------------------

/**
 * Proposes an SEO field change for one content object (F22: تغيير SEO
 * لصفحة منشورة يدخل طلبها وبصمتها، مع معاينة القيم قبل وبعد). A draft is
 * edited directly through the shared write-path decision; a protected item
 * becomes a stored change request whose original snapshot carries the
 * current SEO values, so approval is bound to exactly those before-values.
 *
 * Unknown or non-writable fields are refused by name — never ignored.
 *
 * @param string               $object_type A policy object type ('post',
 *                                             'page', or an authorized
 *                                             content type).
 * @param int                  $object_id   Target object ID.
 * @param array<string, mixed> $fields      Contract slug => value.
 * @param array<string, mixed> $origin      Origin identity (source/model).
 * @return array<string, mixed>|WP_Error Either {applied_directly: true,
 *              applied: ...} or {applied_directly: false, request_id, state}
 *              with the model-facing state 'pending_approval' while the
 *              request awaits admin approval (the store state stays 'pending',
 *              roadmap §4.3/F17).
 */
function hal_mcp_seo_propose_fields( string $object_type, int $object_id, array $fields, array $origin = [] ) {

	if ( empty( $fields ) ) {
		return new WP_Error(
			'hal_mcp_nothing_to_update',
			__( 'Provide at least one SEO field to change.', 'hal-mcp' )
		);
	}

	$hal_mcp_post_type = hal_mcp_object_type_post_type( $object_type );

	if ( '' === $hal_mcp_post_type || hal_mcp_is_internal_post_type( $hal_mcp_post_type ) ) {
		return new WP_Error(
			'hal_mcp_invalid_target_type',
			__( 'SEO fields can only be proposed for addressable content objects.', 'hal-mcp' )
		);
	}

	$hal_mcp_post = get_post( $object_id );

	if ( ! $hal_mcp_post instanceof WP_Post || $hal_mcp_post->post_type !== $hal_mcp_post_type ) {
		return new WP_Error(
			'hal_mcp_target_not_found',
			__( 'No content object of that type was found with that ID.', 'hal-mcp' )
		);
	}

	if ( 'trash' === $hal_mcp_post->post_status ) {
		return new WP_Error(
			'hal_mcp_status_not_editable',
			__( 'This item is in the trash. Restore it first, then send the change again.', 'hal-mcp' )
		);
	}

	// Requester gate — the same object-level check any write path faces.
	if ( ! hal_mcp_permission( $object_type, 'edit', $object_id, [ 'ability' => 'hal/update-seo', 'reason' => 'requester_gate' ] ) ) {
		return new WP_Error(
			'hal_mcp_forbidden_object',
			__( 'You are not allowed to change the SEO fields of this item.', 'hal-mcp' )
		);
	}

	// Validate against the contract BEFORE deciding the path: an unknown or
	// non-writable field is refused by name.
	$hal_mcp_contract = hal_mcp_seo_field_contract();
	$hal_mcp_clean    = [];

	foreach ( $fields as $hal_mcp_field => $hal_mcp_value ) {
		$hal_mcp_field = (string) $hal_mcp_field;

		if ( ! isset( $hal_mcp_contract[ $hal_mcp_field ] ) ) {
			return new WP_Error(
				'hal_mcp_unknown_seo_field',
				sprintf(
					/* translators: %s: field slug. */
					__( 'The SEO field "%s" is not part of the SEO field contract.', 'hal-mcp' ),
					$hal_mcp_field
				)
			);
		}

		if ( empty( $hal_mcp_contract[ $hal_mcp_field ]['writable'] ) ) {
			return new WP_Error(
				'hal_mcp_seo_field_not_writable',
				sprintf(
					/* translators: 1: field slug. 2: the contract's reason. */
					__( 'The SEO field "%1$s" is not writable through this integration: %2$s', 'hal-mcp' ),
					$hal_mcp_field,
					(string) $hal_mcp_contract[ $hal_mcp_field ]['reason']
				)
			);
		}

		$hal_mcp_clean[ $hal_mcp_field ] = 'seo_title' === $hal_mcp_field
			? sanitize_text_field( (string) $hal_mcp_value )
			: sanitize_textarea_field( (string) $hal_mcp_value );
	}

	$hal_mcp_decision = hal_mcp_decide_write_path(
		$object_type,
		(string) $hal_mcp_post->post_status,
		[ 'operation' => 'hal/update-seo' ]
	);

	if ( 'deny' === $hal_mcp_decision['decision'] ) {
		return new WP_Error(
			'hal_mcp_status_not_editable',
			__( 'This item is in the trash. Restore it first, then send the change again.', 'hal-mcp' )
		);
	}

	if ( 'direct' === $hal_mcp_decision['decision'] ) {
		$hal_mcp_applied = hal_mcp_seo_apply_fields( $object_id, $hal_mcp_clean );

		if ( is_wp_error( $hal_mcp_applied ) ) {
			return $hal_mcp_applied;
		}

		hal_mcp_log_control_event(
			[
				'operation'      => 'update-seo',
				'stage'          => 'applied_direct',
				'source'         => (string) ( $origin['source'] ?? 'mcp' ),
				'success'        => true,
				'result_summary' => 'SEO fields applied directly on a draft: ' . implode( ', ', $hal_mcp_applied['fields'] ),
			]
		);

		return [
			'applied_directly' => true,
			'applied'          => $hal_mcp_applied,
		];
	}

	// Request path: the before-values are the fingerprinted original
	// snapshot (the apply-time revalidation reads them LIVE, never from
	// here).
	$hal_mcp_current = hal_mcp_seo_read_fields( $object_id, array_keys( $hal_mcp_clean ) );

	if ( is_wp_error( $hal_mcp_current ) ) {
		return $hal_mcp_current;
	}

	$hal_mcp_snapshot = [];

	foreach ( $hal_mcp_current as $hal_mcp_field => $hal_mcp_entry ) {
		$hal_mcp_snapshot[ $hal_mcp_field ] = (string) $hal_mcp_entry['value'];
	}

	$hal_mcp_queued = hal_mcp_create_change_request(
		[
			'operation'         => 'update-seo',
			'payload'           => $hal_mcp_clean,
			'targets'           => [
				[
					'type' => $object_type,
					'id'   => $object_id,
				],
			],
			'original_snapshot' => $hal_mcp_snapshot,
			'origin'            => $origin,
		]
	);

	if ( is_wp_error( $hal_mcp_queued ) ) {
		return $hal_mcp_queued;
	}

	return [
		'applied_directly' => false,
		'request_id'       => (int) $hal_mcp_queued['request_id'],
		// Model-facing answer: 'pending_approval' (roadmap §4.3/F17).
		'state'            => hal_mcp_request_model_state( (string) $hal_mcp_queued['state'] ),
		'before_values'    => $hal_mcp_snapshot,
	];
}

// ---------------------------------------------------------------------------
// F17 apply handler
// ---------------------------------------------------------------------------

/**
 * Live fingerprint revalidation for 'update-seo' requests (F17 contract):
 * reads the CURRENT SEO values of exactly the payload's fields through the
 * verified interface — never echoes the stored snapshot.
 *
 * @param array<string, mixed> $request Request data.
 * @return string '' when the values cannot be read (apply-time conflict).
 */
function hal_mcp_seo_revalidate_fingerprint( array $request ): string {

	$hal_mcp_targets = (array) ( $request['targets'] ?? [] );
	$hal_mcp_id      = (int) ( $hal_mcp_targets[0]['id'] ?? 0 );
	$hal_mcp_fields  = array_keys( (array) ( $request['payload'] ?? [] ) );

	if ( $hal_mcp_id < 1 || empty( $hal_mcp_fields ) ) {
		return '';
	}

	$hal_mcp_current = hal_mcp_seo_read_fields( $hal_mcp_id, $hal_mcp_fields );

	if ( is_wp_error( $hal_mcp_current ) ) {
		return '';
	}

	$hal_mcp_values = [];

	foreach ( $hal_mcp_current as $hal_mcp_field => $hal_mcp_entry ) {
		$hal_mcp_values[ $hal_mcp_field ] = (string) $hal_mcp_entry['value'];
	}

	return hal_mcp_fingerprint( $hal_mcp_values );
}

/**
 * Registers the 'update-seo' apply handler (F17). Applies ONLY the stored
 * payload through the domain function — never an alternate payload.
 *
 * @return void
 */
function hal_mcp_seo_register_apply_handler(): void {

	hal_mcp_register_apply_handler(
		'update-seo',
		static function ( array $request ) {
			$hal_mcp_targets = (array) ( $request['targets'] ?? [] );
			$hal_mcp_id      = (int) ( $hal_mcp_targets[0]['id'] ?? 0 );
			$hal_mcp_payload = (array) ( $request['payload'] ?? [] );

			$hal_mcp_result = hal_mcp_seo_apply_fields( $hal_mcp_id, $hal_mcp_payload );

			if ( is_wp_error( $hal_mcp_result ) ) {
				return $hal_mcp_result;
			}

			return sprintf(
				/* translators: 1: content ID. 2: comma-separated field slugs. */
				__( 'SEO fields updated for item #%1$d: %2$s.', 'hal-mcp' ),
				$hal_mcp_result['post_id'],
				implode( ', ', $hal_mcp_result['fields'] )
			);
		},
		'hal_mcp_seo_revalidate_fingerprint'
	);
}

hal_mcp_seo_register_apply_handler();

// ---------------------------------------------------------------------------
// Registration
// ---------------------------------------------------------------------------

hal_mcp_register_integration(
	'seo',
	[
		'label'      => hal_mcp_seo_yoast_present()
			? __( 'Yoast SEO', 'hal-mcp' )
			: __( 'SEO integration (no supported SEO tool active)', 'hal-mcp' ),
		'version'    => defined( 'WPSEO_VERSION' ) ? (string) WPSEO_VERSION : '',
		'source'     => hal_mcp_seo_yoast_present() ? 'yoast' : '',
		'available'  => hal_mcp_seo_yoast_present(),
		'operations' => [
			'read_fields'   => [
				'effect'     => 'read',
				'capability' => 'edit_posts',
				'writable'   => [],
			],
			'update_fields' => [
				'effect'     => 'edit',
				'capability' => 'edit_posts',
				'writable'   => [ 'seo_title', 'seo_description' ],
			],
		],
		'notes'      => __( 'The handler implements the SEO title and description through Yoast\'s documented Metadata API (read AND write are verified; Yoast REST is read-only and is never used as a write path). canonical/robots/social/schema stay explicitly not writable in this version. Protected items go through the approval flow with the before-values as their fingerprinted snapshot.', 'hal-mcp' ),
	]
);
