<?php
/**
 * hal-mcp-abilities — change requests and approval (F17).
 *
 * Stores proposed changes in one internal post type (hal_mcp_request, per
 * roadmap §4.3) and drives them through a strict state machine:
 *
 *     pending → approved → applying → applied
 *                        ↘ failed     (handler error)
 *     pending → rejected
 *     applying → conflict    (original changed since approval)
 *     any edit of the proposal → back to pending, previous approval voided
 *
 * Contract points this file ENFORCES in code:
 * - approved state is never accepted from a payload or a model tool: the
 *   approval functions require manage_options plus the capability the effect
 *   needs on each target (hal_mcp_user_can_approve_target()), and apply()
 *   re-checks those capabilities again at execution time.
 * - apply() never accepts an alternate payload: handlers receive exactly the
 *   stored, fingerprinted request data.
 * - the original fingerprint is re-validated before anything is written; a
   * changed original means conflict, re-preview and re-approval — never a
 *   silent overwrite.
 * - a protected change is never executed without a reliable state record:
 *   apply() requires the audit log table (F06) to be present, the applying
 *   status write to persist, and an idempotency lock to be held. A repeat
 *   apply on an applied request returns without re-executing.
 * - apply handlers are registered locally, in memory, under known operation
 *   names by the domain ability files — never serialized callables, never
 *   eval, never generic SQL.
 * - rejecting or leaving a request pending never fails the conversation: the
 *   create() return shape carries request_id/state/preview so a caller can
 *   answer pending_approval cleanly.
 *
 * Contract points that land with the F19 admin screen: the ONLY call site of
 * approve()/apply() is the protected admin endpoint (capability + nonce +
 * authenticated session). Until that screen exists, nothing calls them — the
 * capability gates here are the function-level half of that enforcement.
 *
 * kind=run (F18, roadmap §4.3): short tool-loop conversations reuse this same
 * store instead of a second storage. Run rows carry no targets and no apply
 * handler, and they are structurally UNAPPROVABLE and UNAPPLIABLE — approve()
 * and apply() refuse them exactly like admin_assist rows. Their real state
 * lives in the _hal_run meta (runner.php), not in the _hal_status machine.
 *
 * @package hal-mcp-abilities
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The internal change-request post type (roadmap §4.3). The slug is part of
 * the uninstall contract — uninstall.php selects and deletes exactly this
 * type when an admin opted in to data deletion.
 *
 * @var string
 */
const HAL_MCP_REQUEST_POST_TYPE = 'hal_mcp_request';

/**
 * Request states (the value stored in the _hal_status meta key — deliberately
 * NOT the WP post_status, which stays 'draft' and is meaningless here).
 *
 * @var string[]
 */
const HAL_MCP_REQUEST_STATES = [ 'pending', 'approved', 'applying', 'applied', 'rejected', 'failed', 'conflict' ];

/**
 * Version of the request field schema stored in meta (F17: مخطط versioned).
 * Bump when the stored field set changes, so stored requests can be migrated
 * or recognized by reading this value back.
 *
 * @var string
 */
const HAL_MCP_REQUEST_SCHEMA_VERSION = '1.0';

/**
 * Lock lifetime in seconds: a lock older than this is considered abandoned
 * (e.g. a fatal mid-apply) and may be taken over.
 *
 * @var int
 */
const HAL_MCP_REQUEST_LOCK_SECONDS = 120;

/**
 * The request meta key holding the editor-serialization readiness state
 * (F21). Values: '' (not a design request), 'needs_editor', 'ready'. The
 * request store owns its meta keys; the editor integration (blocks.php)
 * reads and writes the value through this shared const.
 *
 * @var string
 */
const HAL_MCP_REQUEST_EDITOR_READINESS_KEY = '_hal_editor_readiness';

/**
 * Allowed request kinds. 'content_change' and 'admin_assist' predate F18;
 * 'run' is the F18 tool-loop conversation store (roadmap §4.3: kind=run
 * keeps short-run state without a second store). Run rows are never
 * approved or applied — see the approve()/apply() guards.
 *
 * @var string[]
 */
const HAL_MCP_REQUEST_KINDS = [ 'content_change', 'admin_assist', 'run' ];

add_action( 'init', 'hal_mcp_register_request_post_type' );

/**
 * Registers the internal change-request post type.
 *
 * Fully non-public: not queryable, not searchable, not in REST, not exported,
 * not listed in any admin UI (the F19 screen reads through this file's
 * functions, which gate access themselves). Every capability maps to
 * manage_options as defence in depth.
 *
 * @return void
 */
function hal_mcp_register_request_post_type(): void {

	$manage_only = [
		'edit_post'              => 'manage_options',
		'read_post'              => 'manage_options',
		'delete_post'            => 'manage_options',
		'edit_posts'             => 'manage_options',
		'edit_others_posts'      => 'manage_options',
		'publish_posts'          => 'manage_options',
		'read_private_posts'     => 'manage_options',
		'delete_posts'           => 'manage_options',
		'delete_private_posts'   => 'manage_options',
		'delete_published_posts' => 'manage_options',
		'delete_others_posts'    => 'manage_options',
		'edit_private_posts'     => 'manage_options',
		'edit_published_posts'   => 'manage_options',
		'create_posts'           => 'manage_options',
	];

	register_post_type(
		HAL_MCP_REQUEST_POST_TYPE,
		[
			'labels'              => [
				'name'          => __( 'HAL change requests', 'hal-mcp' ),
				'singular_name' => __( 'HAL change request', 'hal-mcp' ),
			],
			'public'              => false,
			'publicly_queryable'  => false,
			'show_ui'             => false,
			'show_in_menu'        => false,
			'show_in_nav_menus'   => false,
			'show_in_admin_bar'   => false,
			'show_in_rest'        => false,
			'exclude_from_search' => true,
			'can_export'          => false,
			'delete_with_user'    => false,
			'hierarchical'        => false,
			'supports'            => [ 'title', 'author' ],
			'capability_type'     => 'post',
			'map_meta_cap'        => false,
			'capabilities'        => $manage_only,
		]
	);
}

// ---------------------------------------------------------------------------
// Handler registry (in-memory, per request — never persisted)
// ---------------------------------------------------------------------------

/**
 * Backing store for the apply-handler registry.
 *
 * @return array<string, array{handler: callable, revalidate: callable|null}>
 */
function &hal_mcp_apply_handler_registry(): array {
	static $registry = [];
	return $registry;
}

/**
 * Registers the apply handler for an operation (F17: handlers live in the
 * domain ability files, registered locally under known operation names — no
 * serialized callables, no eval, no generic SQL).
 *
 * @param string        $operation  Operation slug, e.g. 'update-post' — must
 *                                  match the operation a request was created with.
 * @param callable      $handler    fn( array $request ): array|WP_Error — receives
 *                                  the stored request data; applies the change.
 * @param callable|null $revalidate Optional fn( array $request ): string. CONTRACT
 *                                  (enforced by review; apply() and approve() both
 *                                  call it): the callback MUST fetch the target's
 *                                  CURRENT original fields from the live object
 *                                  (get_post()/wc_get_product()/...) and return
 *                                  hal_mcp_fingerprint() of exactly those fields.
 *                                  It MUST NOT derive its value from
 *                                  $request['original_snapshot'] or
 *                                  $request['original_fingerprint'] — the requester
 *                                  controls both after a payload edit, and a
 *                                  callback that echoes the stored snapshot makes
 *                                  the pre-apply check a tautology that defeats
 *                                  §4.3's no-silent-overwrite rule. apply() refuses
 *                                  on mismatch; approve() re-anchors the stored
 *                                  fingerprint to this callback's live read.
 * @return bool False when the arguments are invalid or the operation already
 *              has a handler (a duplicate registration is a bug, not an update).
 */
function hal_mcp_register_apply_handler( string $operation, callable $handler, ?callable $revalidate = null ): bool {

	$operation = trim( $operation );

	if ( '' === $operation || ! is_callable( $handler ) ) {
		return false;
	}

	$registry = &hal_mcp_apply_handler_registry();

	if ( array_key_exists( $operation, $registry ) ) {
		error_log(
			sprintf(
				'hal-mcp-abilities: refused duplicate apply-handler registration for operation "%s".',
				$operation
			)
		);
		return false;
	}

	$registry[ $operation ] = [
		'handler'    => $handler,
		'revalidate' => is_callable( $revalidate ) ? $revalidate : null,
	];

	return true;
}

/**
 * Whether an operation has a registered apply handler.
 *
 * @param string $operation Operation slug.
 * @return bool
 */
function hal_mcp_has_apply_handler( string $operation ): bool {
	$registry = &hal_mcp_apply_handler_registry();
	return array_key_exists( $operation, $registry );
}

// ---------------------------------------------------------------------------
// Fingerprints and payload hygiene
// ---------------------------------------------------------------------------

/**
 * Canonical fingerprint of a value (F17): recursively key-sorted JSON, SHA-256
 * hashed, so payload comparison does not depend on array order.
 *
 * @param mixed $data Any JSON-encodable value.
 * @return string 64 hex chars, or '' when the value cannot be encoded.
 */
function hal_mcp_fingerprint( $data ): string {

	if ( ! is_array( $data ) && ! is_scalar( $data ) && null !== $data ) {
		return '';
	}

	hal_mcp_canonicalize_for_fingerprint( $data );

	$encoded = wp_json_encode( $data );

	return is_string( $encoded ) ? hash( 'sha256', $encoded ) : '';
}

/**
 * Recursively ksorts arrays so wp_json_encode() output is order-independent.
 *
 * @param mixed $data By reference.
 * @return void
 */
function hal_mcp_canonicalize_for_fingerprint( &$data ): void {

	if ( ! is_array( $data ) ) {
		return;
	}

	ksort( $data );

	foreach ( $data as &$value ) {
		hal_mcp_canonicalize_for_fingerprint( $value );
	}

	unset( $value );
}

/**
 * Structural sanitization of a payload before it is stored (F17: منقحة).
 * Field-level content sanitization (wp_kses_post, prices, dates, ...) is the
 * DOMAIN layer's job before it hands a payload over; this enforces structure:
 * only strings/int/float/bool/null and (nested) arrays of those — no objects,
 * no callables, no resources, no non-finite floats (NAN/INF make
 * wp_json_encode() fail, which would silently produce an empty fingerprint
 * and thereby disable the pre-apply conflict re-check). Nested depth is
 * bounded, and the bound matches the audit redactor's walk wall (9).
 * Integer keys are accepted at every depth: the v2 domain field sets carry
 * legitimate LIST fields — 'categories' on posts, 'images' and 'attributes'
 * (whose items nest 'values' lists) on products — so a payload of list items
 * is storable as-is, and the canonical fingerprint already ksorts lists
 * harmlessly. Only string keys that are empty after trimming stay rejected.
 *
 * Returns the cleaned payload, or null when it cannot be represented safely.
 * (The integer-key acceptance is load-bearing beyond the v2 list fields:
 * the F21/F22/F23 integration payloads — Elementor 'elements' arrays among
 * them — are list-shaped and must be storable as-is.)
 *
 * @param mixed $payload Proposed change payload.
 * @param int   $depth   Recursion depth (internal).
 * @return array<string|int, mixed>|null
 */
function hal_mcp_sanitize_request_payload( $payload, int $depth = 0 ): ?array {

	if ( ! is_array( $payload ) || $depth > 8 ) {
		return null;
	}

	$clean = [];

	foreach ( $payload as $key => $value ) {
		// Integer keys are the list positions of legitimate v2 domain list
		// fields (categories/images/attributes — see the docblock); only a
		// string key that is empty after trimming is unsanitizable.
		if ( ! is_int( $key ) && '' === trim( $key ) ) {
			return null;
		}

		if ( is_array( $value ) ) {
			$nested = hal_mcp_sanitize_request_payload( $value, $depth + 1 );

			if ( null === $nested ) {
				return null;
			}

			$clean[ $key ] = $nested;
			continue;
		}

		if ( is_string( $value ) || is_int( $value ) || is_bool( $value ) || null === $value ) {
			$clean[ $key ] = $value;
			continue;
		}

		// Floats are stored only when finite — see the docblock.
		if ( is_float( $value ) && is_finite( $value ) ) {
			$clean[ $key ] = $value;
			continue;
		}

		return null;
	}

	return $clean;
}

// ---------------------------------------------------------------------------
// Storage helpers
// ---------------------------------------------------------------------------

/**
 * Reads the stored state of a request row.
 *
 * @param int $request_id Request post ID.
 * @return string One of HAL_MCP_REQUEST_STATES, or '' when the row is gone.
 */
function hal_mcp_request_read_status( int $request_id ): string {

	$status = get_post_meta( $request_id, '_hal_status', true );

	return is_string( $status ) && in_array( $status, HAL_MCP_REQUEST_STATES, true ) ? $status : '';
}

/**
 * Performs one validated state transition and verifies it persisted.
 *
 * Deliberately strict (F06/F17): when the write cannot be proven, this
 * returns false and the caller must NOT execute the change nor claim success.
 *
 * @param int    $request_id Request post ID.
 * @param string $from       Required current state ('' to skip the check).
 * @param string $to         Target state.
 * @return bool
 */
function hal_mcp_request_set_status( int $request_id, string $from, string $to ): bool {

	if ( ! in_array( $to, HAL_MCP_REQUEST_STATES, true ) ) {
		return false;
	}

	if ( '' !== $from && hal_mcp_request_read_status( $request_id ) !== $from ) {
		return false;
	}

	update_post_meta( $request_id, '_hal_status', $to );

	// Prove the write before anyone acts on it.
	return hal_mcp_request_read_status( $request_id ) === $to;
}

/**
 * Writes one audit row for a request lifecycle event (F06 control points).
 * Never throws.
 *
 * @param int    $request_id Request ID.
 * @param string $operation  Operation slug.
 * @param string $stage      Event stage.
 * @param bool   $success    Whether the event is a success.
 * @param string $summary    Short outcome summary.
 * @param string $source     mcp | admin | system | model.
 * @return void
 */
function hal_mcp_request_audit( int $request_id, string $operation, string $stage, bool $success, string $summary, string $source ): void {

	hal_mcp_log_control_event(
		[
			'operation'      => $operation,
			'stage'          => $stage,
			'source'         => $source,
			'request_id'     => $request_id > 0 ? $request_id : null,
			'success'        => $success,
			'result_summary' => $summary,
		]
	);
}

/**
 * Acquires the per-request idempotency lock (F17: قفل قصير). Uses the
 * unique-meta race: add_post_meta(..., true) fails when the key already
 * exists, which is the closest lock WordPress offers without a new table.
 * The roadmap explicitly documents this as NOT an atomic guarantee against
 * every other plugin writing outside this path.
 *
 * @param int $request_id Request post ID.
 * @return bool
 */
function hal_mcp_request_acquire_lock( int $request_id ): bool {

	$existing = get_post_meta( $request_id, '_hal_lock_ts', true );

	if ( $existing && ( time() - (int) $existing ) < HAL_MCP_REQUEST_LOCK_SECONDS ) {
		return false;
	}

	if ( $existing ) {
		delete_post_meta( $request_id, '_hal_lock_ts' );
	}

	return (bool) add_post_meta( $request_id, '_hal_lock_ts', time(), true );
}

/**
 * Releases the per-request idempotency lock.
 *
 * @param int $request_id Request post ID.
 * @return void
 */
function hal_mcp_request_release_lock( int $request_id ): void {
	delete_post_meta( $request_id, '_hal_lock_ts' );
}

// ---------------------------------------------------------------------------
// View / manage gates
// ---------------------------------------------------------------------------

/**
 * Whether the current user may see this request: its author, or a manager.
 *
 * @param array<string, mixed> $request Request data (from hal_mcp_get_change_request()).
 * @return bool
 */
function hal_mcp_user_can_view_request( array $request ): bool {

	if ( current_user_can( 'manage_options' ) ) {
		return true;
	}

	return (int) ( $request['requester_id'] ?? 0 ) === get_current_user_id();
}

/**
 * Builds the short human-readable preview line for a request — target refs
 * and changed FIELD NAMES only, never payload values (values may be long or
 * sensitive; the F19 admin screen renders a proper before/after diff from the
 * stored snapshot).
 *
 * Field names come from the model, so they are treated as untrusted text:
 * sanitize_text_field() strips tags before the preview is stored in
 * post_title, so a hostile key like "<script>…" can never persist raw HTML
 * in the title.
 *
 * @param string               $operation Operation slug.
 * @param array<int, array>    $targets   Target descriptors.
 * @param array<string, mixed> $payload   Change payload.
 * @return string
 */
function hal_mcp_build_request_preview( string $operation, array $targets, array $payload ): string {

	$target_refs = [];

	foreach ( $targets as $target ) {
		if ( is_array( $target ) ) {
			$target_refs[] = (string) ( $target['type'] ?? '?' ) . '#' . (string) (int) ( $target['id'] ?? 0 );
		}
	}

	$fields = array_map(
		static fn( $hal_mcp_field ) => sanitize_text_field( (string) $hal_mcp_field ),
		array_keys( $payload )
	);

	$preview = sprintf(
		'%1$s → %2$s [%3$s]',
		sanitize_text_field( $operation ),
		'' === implode( ', ', $target_refs ) ? __( 'no target', 'hal-mcp' ) : implode( ', ', $target_refs ),
		implode( ', ', $fields )
	);

	return strlen( $preview ) > 160 ? substr( $preview, 0, 157 ) . '…' : $preview;
}

// ---------------------------------------------------------------------------
// Create / read / list
// ---------------------------------------------------------------------------

/**
 * Creates a change request in the internal store.
 *
 * @param array<string, mixed> $args {
 *     @type string                $operation         Operation slug (required). For
 *                                                    kind=content_change a registered
 *                                                    apply handler must already exist.
 *     @type array<string, mixed>  $payload           Proposed change fields (required).
 *     @type string                $kind              'content_change' (default) or 'admin_assist'.
 *     @type array<int, array>     $targets           [['type' => 'post', 'id' => 12], ...].
 *     @type array<string, mixed>  $original_snapshot Original values of ONLY the fields
 *                                                    that will change (roadmap §4.3) —
 *                                                    fingerprinted for the pre-apply
 *                                                    re-check.
 *     @type string                $language          Target language, when known.
 *     @type array<string, mixed>  $origin            Editor/integration identity:
 *                                                    source (mcp|admin|runner), model,
 *                                                    version.
 * }
 * @return array<string, mixed>|WP_Error { request_id, state, preview,
 *         proposed_fingerprint, original_fingerprint }
 */
function hal_mcp_create_change_request( array $args ) {

	$kind      = (string) ( $args['kind'] ?? 'content_change' );
	$operation = trim( (string) ( $args['operation'] ?? '' ) );

	if ( ! in_array( $kind, HAL_MCP_REQUEST_KINDS, true ) ) {
		return new WP_Error( 'hal_mcp_invalid_request_kind', __( 'Unknown change-request kind.', 'hal-mcp' ) );
	}

	// F18 run rows: one operation slug, no targets, no handler — the payload
	// IS the redacted conversation (runner.php owns its shape and limits).
	if ( 'run' === $kind && '' === $operation ) {
		$operation = 'run';
	}

	if ( '' === $operation ) {
		return new WP_Error( 'hal_mcp_missing_operation', __( 'A change request needs an operation.', 'hal-mcp' ) );
	}

	$payload = hal_mcp_sanitize_request_payload( $args['payload'] ?? null );

	if ( null === $payload ) {
		return new WP_Error( 'hal_mcp_invalid_payload', __( 'The change payload could not be stored safely.', 'hal-mcp' ) );
	}

	if ( 'content_change' === $kind && ! hal_mcp_has_apply_handler( $operation ) ) {
		return new WP_Error(
			'hal_mcp_no_apply_handler',
			__( 'This operation has no registered apply handler; the request would never be appliable.', 'hal-mcp' )
		);
	}

	$targets = [];
	foreach ( (array) ( $args['targets'] ?? [] ) as $target ) {
		if ( is_array( $target ) && isset( $target['type'], $target['id'] ) ) {
			$targets[] = [
				'type' => sanitize_key( (string) $target['type'] ),
				'id'   => (int) $target['id'],
			];
		}
	}

	// A content change with zero targets would skip the per-target capability
	// loop at approve/apply entirely. Create-style requests use id 0.
	if ( 'content_change' === $kind && empty( $targets ) ) {
		return new WP_Error(
			'hal_mcp_missing_target',
			__( 'A content change request needs at least one target (use id 0 for a create-style request).', 'hal-mcp' )
		);
	}

	// Requester capability gate (§4.3: "تطبق صلاحيات الطالب على طلب التغيير"
	// — the requester must be able to perform the requested operation on
	// each target BEFORE the request is stored; the approver gates remain
	// the second, independent check at approve AND again at apply). A
	// requester without any capability on a target cannot queue a request
	// against it at all.
	if ( 'content_change' === $kind ) {
		foreach ( $targets as $target ) {
			$hal_mcp_object_type = $target['type'];
			$hal_mcp_object_id  = $target['id'];

			// F20: the same gate the policy uses — a const policy key OR an
			// authorized generic content post type — so a target type that is
			// neither can never get a request queued against it.
			$hal_mcp_post_type = hal_mcp_object_type_post_type( $hal_mcp_object_type );

			if ( '' === $hal_mcp_post_type
				|| hal_mcp_is_internal_post_type( $hal_mcp_post_type ) ) {
				return new WP_Error(
					'hal_mcp_invalid_target_type',
					sprintf(
						/* translators: %s: target object type slug. */
						__( 'Change requests cannot target the "%s" object type.', 'hal-mcp' ),
						$hal_mcp_object_type
					)
				);
			}

			// id 0 (create-style): coarse create primitive. id > 0: the
			// object's own edit capability with the ID — ownership rules
			// included, so a requester cannot propose changes to another
			// user's private/draft item.
			if ( $hal_mcp_object_id > 0 ) {
				$hal_mcp_allowed = hal_mcp_permission( $hal_mcp_object_type, 'edit', $hal_mcp_object_id, [ 'ability' => 'hal/create-change-request', 'reason' => 'requester_gate' ] );
			} else {
				$hal_mcp_allowed = hal_mcp_permission( $hal_mcp_object_type, 'create', 0, [ 'ability' => 'hal/create-change-request', 'reason' => 'requester_gate' ] );
			}

			if ( ! $hal_mcp_allowed ) {
				return new WP_Error(
					'hal_mcp_requester_forbidden',
					__( 'You are not allowed to request changes to that target.', 'hal-mcp' )
				);
			}
		}
	}

	$original_snapshot = hal_mcp_sanitize_request_payload( $args['original_snapshot'] ?? [] );

	if ( null === $original_snapshot ) {
		return new WP_Error( 'hal_mcp_invalid_snapshot', __( 'The original snapshot could not be stored safely.', 'hal-mcp' ) );
	}

	$origin = [
		'source'  => sanitize_key( (string) ( $args['origin']['source'] ?? 'mcp' ) ),
		'model'   => sanitize_text_field( (string) ( $args['origin']['model'] ?? '' ) ),
		'version' => sanitize_text_field( (string) ( $args['origin']['version'] ?? HAL_MCP_ABILITIES_VERSION ) ),
	];

	$original_fingerprint  = hal_mcp_fingerprint( $original_snapshot );
	$proposed_fingerprint  = hal_mcp_fingerprint( $payload );

	// Run rows list the conversation's opening user text, not payload field
	// names (the conversation is a list, so the field-name preview would be
	// meaningless for them). Text is sanitized exactly like a field name —
	// model/user text is untrusted everywhere it is rendered.
	if ( 'run' === $kind ) {
		$hal_mcp_first_text = '';

		foreach ( (array) ( $args['payload'] ?? [] ) as $hal_mcp_message ) {
			if ( is_array( $hal_mcp_message ) && 'user' === ( $hal_mcp_message['role'] ?? '' )
				&& isset( $hal_mcp_message['text'] ) ) {
				$hal_mcp_first_text = sanitize_text_field( (string) $hal_mcp_message['text'] );
				break;
			}
		}

		$preview = '' === $hal_mcp_first_text
			? __( 'HAL tool run', 'hal-mcp' )
			: $hal_mcp_first_text;
		$preview = strlen( $preview ) > 160 ? substr( $preview, 0, 157 ) . '…' : $preview;
	} else {
		$preview = hal_mcp_build_request_preview( $operation, $targets, $payload );
	}

	$request_id = wp_insert_post(
		[
			'post_type'    => HAL_MCP_REQUEST_POST_TYPE,
			// The WP post_status is meaningless for this internal type; the
			// real state machine lives in _hal_status.
			'post_status'  => 'draft',
			'post_title'   => $preview,
			'post_author'  => get_current_user_id(),
			'meta_input'   => [
				'_hal_schema_version'       => HAL_MCP_REQUEST_SCHEMA_VERSION,
				'_hal_kind'                => $kind,
				'_hal_operation'           => $operation,
				'_hal_site_id'             => get_current_blog_id(),
				'_hal_targets'             => $targets,
				'_hal_payload'             => $payload,
				'_hal_original_snapshot'   => $original_snapshot,
				'_hal_original_fingerprint' => $original_fingerprint,
				'_hal_proposed_fingerprint' => $proposed_fingerprint,
				'_hal_language'            => sanitize_key( (string) ( $args['language'] ?? '' ) ),
				'_hal_origin'              => $origin,
				'_hal_status'              => 'pending',
			],
		],
		true
	);

	if ( is_wp_error( $request_id ) ) {
		// F06: a lost approval request is never announced as success.
		return new WP_Error(
			'hal_mcp_request_not_saved',
			__( 'The change request could not be saved. No change was proposed.', 'hal-mcp' )
		);
	}

	$request_id = (int) $request_id;

	hal_mcp_request_audit(
		$request_id,
		$operation,
		'requested',
		true,
		$preview,
		$origin['source']
	);

	return [
		'request_id'           => $request_id,
		'state'                => 'pending',
		'preview'              => $preview,
		'proposed_fingerprint' => $proposed_fingerprint,
		'original_fingerprint' => $original_fingerprint,
	];
}

/**
 * Maps a STORE state to the MODEL-FACING tool answer.
 *
 * The store keeps its own 'pending' state (the F17 state machine is
 * untouched — rows are stored, approved and applied exactly as before);
 * this helper only maps what the MODEL sees when a write tool queues a
 * request, so the tool answer honors the roadmap §4.3/F17 contract
 * wording «يرد النظام pending_approval مع معرف ومعاينة»: the model is
 * told 'pending_approval' and stops, instead of a bare 'pending' that
 * the runner could never match.
 *
 * @param string $store_state Raw store state (e.g. 'pending').
 * @return string The model-facing state ('pending_approval' for 'pending',
 *                otherwise the input unchanged).
 */
function hal_mcp_request_model_state( string $store_state ): string {

	if ( 'pending' === $store_state ) {
		return 'pending_approval';
	}

	return $store_state;
}

/**
 * Reads one change request.
 *
 * @param int  $request_id  Request post ID.
 * @param bool $with_payload Include payload/snapshot fields (author or manager only).
 * @return array<string, mixed>|WP_Error
 */
function hal_mcp_get_change_request( int $request_id, bool $with_payload = true ) {

	$post = get_post( $request_id );

	if ( ! $post || HAL_MCP_REQUEST_POST_TYPE !== $post->post_type ) {
		return new WP_Error( 'hal_mcp_request_not_found', __( 'No such change request.', 'hal-mcp' ) );
	}

	$request = [
		'request_id'           => (int) $post->ID,
		'schema_version'       => (string) get_post_meta( $post->ID, '_hal_schema_version', true ),
		'kind'                 => (string) get_post_meta( $post->ID, '_hal_kind', true ),
		'operation'            => (string) get_post_meta( $post->ID, '_hal_operation', true ),
		'site_id'              => (int) get_post_meta( $post->ID, '_hal_site_id', true ),
		'requester_id'         => (int) $post->post_author,
		'targets'              => (array) get_post_meta( $post->ID, '_hal_targets', true ),
		'language'             => (string) get_post_meta( $post->ID, '_hal_language', true ),
		'origin'               => (array) get_post_meta( $post->ID, '_hal_origin', true ),
		'original_fingerprint' => (string) get_post_meta( $post->ID, '_hal_original_fingerprint', true ),
		'proposed_fingerprint' => (string) get_post_meta( $post->ID, '_hal_proposed_fingerprint', true ),
		'state'                => hal_mcp_request_read_status( (int) $post->ID ),
		'created_at'           => (string) $post->post_date,
		'preview'              => (string) $post->post_title,
		'approver'             => (string) get_post_meta( $post->ID, '_hal_approver', true ),
		'approved_at'          => (string) get_post_meta( $post->ID, '_hal_approved_at', true ),
		'result'               => (string) get_post_meta( $post->ID, '_hal_result', true ),
	];

	if ( $with_payload ) {
		if ( ! hal_mcp_user_can_view_request( $request ) ) {
			return new WP_Error( 'hal_mcp_forbidden', __( 'You may not view this change request.', 'hal-mcp' ) );
		}

		$request['payload']           = (array) get_post_meta( $post->ID, '_hal_payload', true );
		$request['original_snapshot'] = (array) get_post_meta( $post->ID, '_hal_original_snapshot', true );
	}

	return $request;
}

/**
 * Lists change requests the current user is allowed to see (F17: قوائم
 * الإدارة تقرأ الطلبات المسموح بها فقط) — everything for managers, only
 * their own requests otherwise. Summaries only; full payloads come from
 * hal_mcp_get_change_request().
 *
 * @param array<string, mixed> $args { status, page (1-based), per_page (max 100) }.
 * @return array<int, array<string, mixed>>|WP_Error
 */
function hal_mcp_list_change_requests( array $args = [] ) {

	if ( ! current_user_can( 'manage_options' ) && ! get_current_user_id() ) {
		return new WP_Error( 'hal_mcp_forbidden', __( 'You may not list change requests.', 'hal-mcp' ) );
	}

	$status_filter = (string) ( $args['status'] ?? '' );

	if ( '' !== $status_filter && ! in_array( $status_filter, HAL_MCP_REQUEST_STATES, true ) ) {
		return new WP_Error( 'hal_mcp_invalid_state', __( 'Unknown request state filter.', 'hal-mcp' ) );
	}

	// F19/F18: the admin screens filter by kind (approvals tab shows
	// content_change rows; the commands tab lists run rows). Unknown kinds
	// are refused rather than silently ignored.
	$kind_filter = (string) ( $args['kind'] ?? '' );

	if ( '' !== $kind_filter && ! in_array( $kind_filter, HAL_MCP_REQUEST_KINDS, true ) ) {
		return new WP_Error( 'hal_mcp_invalid_kind', __( 'Unknown request kind filter.', 'hal-mcp' ) );
	}

	$page    = max( 1, (int) ( $args['page'] ?? 1 ) );
	$per_page = min( 100, max( 1, (int) ( $args['per_page'] ?? 20 ) ) );

	// Meta-query the state (it lives in _hal_status, not post_status).
	$meta_query = [
		[
			'key'     => '_hal_status',
			'value'   => '' !== $status_filter ? $status_filter : HAL_MCP_REQUEST_STATES,
			'compare' => '' !== $status_filter ? '=' : 'IN',
		],
	];

	if ( '' !== $kind_filter ) {
		$meta_query[] = [
			'key'     => '_hal_kind',
			'value'   => $kind_filter,
			'compare' => '=',
		];
	}

	$query_args = [
		'post_type'      => HAL_MCP_REQUEST_POST_TYPE,
		'post_status'    => 'draft',
		'posts_per_page' => $per_page,
		'paged'          => $page,
		'orderby'        => 'date',
		'order'          => 'DESC',
		'fields'         => 'all',
		'meta_query'     => $meta_query, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- state filtering for admin lists; the table is small.
	];

	if ( ! current_user_can( 'manage_options' ) ) {
		$query_args['author'] = get_current_user_id();
	}

	$found = get_posts( $query_args );

	$summaries = [];

	foreach ( (array) $found as $request_post ) {
		$summaries[] = [
			'request_id'   => (int) $request_post->ID,
			'kind'         => (string) get_post_meta( $request_post->ID, '_hal_kind', true ),
			'operation'    => (string) get_post_meta( $request_post->ID, '_hal_operation', true ),
			'state'        => hal_mcp_request_read_status( (int) $request_post->ID ),
			'preview'      => (string) $request_post->post_title,
			'created_at'   => (string) $request_post->post_date,
			'requester_id' => (int) $request_post->post_author,
		];
	}

	return $summaries;
}

/**
 * Derives the apply effect ('edit' | 'publish') from a request's stored
 * payload — the ONE definition both approve() and apply() share, so the two
 * gates can never drift apart on what a publish-intent request requires.
 *
 * @param array<string, mixed> $request Request data (from hal_mcp_get_change_request()).
 * @return string 'publish' when the stored payload requests publishing, else 'edit'.
 */
function hal_mcp_request_apply_effect( array $request ): string {
	return ( isset( $request['payload']['requested_status'] ) && 'publish' === $request['payload']['requested_status'] ) ? 'publish' : 'edit';
}

// ---------------------------------------------------------------------------
// Approve / reject
// ---------------------------------------------------------------------------

/**
 * Approves a pending request.
 *
 * Gates (F05/F17): manage_options PLUS the capability the apply effect needs
 * on every target. The approver identity and time are recorded; the stored
 * payload is what gets applied later — an approved flag received from a
 * payload or a model tool has no meaning here.
 *
 * @param int $request_id Request post ID.
 * @return array<string, mixed>|WP_Error { request_id, state, approver }
 */
function hal_mcp_approve_change_request( int $request_id ) {

	// With the payload: the apply effect (publish vs edit) is derived from
	// requested_status, and the approver is always manage_options so the
	// payload view gate passes.
	$request = hal_mcp_get_change_request( $request_id, true );

	if ( is_wp_error( $request ) ) {
		return $request;
	}

	if ( ! current_user_can( 'manage_options' ) ) {
		hal_mcp_request_audit( $request_id, $request['operation'], 'approve_denied', false, 'approver lacks manage_options', 'admin' );
		return new WP_Error( 'hal_mcp_forbidden', __( 'Only an administrator may approve change requests.', 'hal-mcp' ) );
	}

	if ( in_array( $request['kind'], [ 'admin_assist', 'run' ], true ) ) {
		return new WP_Error(
			'hal_mcp_not_applicable',
			__( 'Only content-change requests are approved; this row is not an appliable change.', 'hal-mcp' )
		);
	}

	if ( 'pending' !== $request['state'] ) {
		return new WP_Error(
			'hal_mcp_invalid_state',
			sprintf(
				/* translators: %s: current request state. */
				__( 'Only a pending request can be approved (current state: %s).', 'hal-mcp' ),
				$request['state']
			)
		);
	}

	// F21: a design request whose real-editor serialization has not been
	// validated and stored yet is not approvable — approval requires the
	// sanitized final version and its fingerprint to be saved first. The
	// STORED readiness value is authoritative when present (it is written
	// server-side only, never from a model/browser input); the derivation in
	// the editor integration covers the first read, before any value has
	// been persisted. When that module is not loaded there is no design
	// flow and the gate stands down.
	if ( 'needs_editor' === get_post_meta( $request_id, HAL_MCP_REQUEST_EDITOR_READINESS_KEY, true ) ) {
		return new WP_Error(
			'hal_mcp_editor_serialization_required',
			__( 'This design request still needs a real-editor serialization before it can be approved. Open it in the editor from the request panel, save the serialization, and approve again.', 'hal-mcp' )
		);
	}

	if ( function_exists( 'hal_mcp_blocks_request_editor_readiness' )
		&& 'needs_editor' === hal_mcp_blocks_request_editor_readiness( $request_id ) ) {
		return new WP_Error(
			'hal_mcp_editor_serialization_required',
			__( 'This design request still needs a real-editor serialization before it can be approved. Open it in the editor from the request panel, save the serialization, and approve again.', 'hal-mcp' )
		);
	}

	$effect = hal_mcp_request_apply_effect( $request );

	foreach ( (array) $request['targets'] as $target ) {
		$object_type = (string) ( $target['type'] ?? '' );
		$object_id   = (int) ( $target['id'] ?? 0 );

		if ( '' === $object_type || ! hal_mcp_user_can_approve_target( $object_type, $object_id, $effect ) ) {
			hal_mcp_request_audit( $request_id, $request['operation'], 'approve_denied', false, 'approver lacks the capability the effect needs on a target', 'admin' );
			return new WP_Error(
				'hal_mcp_forbidden_target',
				__( 'The approver does not have the capability this change needs on its target.', 'hal-mcp' )
			);
		}
	}

	if ( ! hal_mcp_request_set_status( $request_id, 'pending', 'approved' ) ) {
		return new WP_Error( 'hal_mcp_state_not_saved', __( 'The approval could not be saved. Nothing was approved.', 'hal-mcp' ) );
	}

	update_post_meta( $request_id, '_hal_approver', (string) wp_get_current_user()->user_login );
	update_post_meta( $request_id, '_hal_approved_at', current_time( 'mysql' ) );

	// Anchor the baseline at APPROVAL time (§4.3: الموافقة مرتبطة بنفس
	// المحتوى). When the operation has a revalidate callback, the stored
	// original fingerprint is REPLACED with that callback's live read at the
	// moment of approval — an approver-stamped baseline, not a value the
	// requester stamped at creation or edit time. A forged or stale snapshot
	// can no longer choose what apply() compares against. No-op when the
	// operation has no revalidate callback yet, or the live read returns ''
	// (the apply-time fail-safe still refuses an unprovable original).
	$hal_mcp_registry = &hal_mcp_apply_handler_registry();

	if ( isset( $hal_mcp_registry[ $request['operation'] ]['revalidate'] ) && is_callable( $hal_mcp_registry[ $request['operation'] ]['revalidate'] ) ) {
		$hal_mcp_live_fingerprint = (string) call_user_func( $hal_mcp_registry[ $request['operation'] ]['revalidate'], $request );

		if ( '' !== $hal_mcp_live_fingerprint ) {
			update_post_meta( $request_id, '_hal_original_fingerprint', $hal_mcp_live_fingerprint );
		}
	}

	hal_mcp_request_audit( $request_id, $request['operation'], 'approved', true, $request['preview'], 'admin' );

	return [
		'request_id' => $request_id,
		'state'      => 'approved',
		'approver'   => (string) get_post_meta( $request_id, '_hal_approver', true ),
	];
}

/**
 * Rejects a pending request. A rejection never fails the conversation — the
 * caller receives a clean state to relay.
 *
 * @param int    $request_id Request post ID.
 * @param string $reason     Short rejection reason, stored with the request.
 * @return array<string, mixed>|WP_Error { request_id, state }
 */
function hal_mcp_reject_change_request( int $request_id, string $reason = '' ) {

	$request = hal_mcp_get_change_request( $request_id, false );

	if ( is_wp_error( $request ) ) {
		return $request;
	}

	if ( ! current_user_can( 'manage_options' ) ) {
		hal_mcp_request_audit( $request_id, $request['operation'], 'reject_denied', false, 'rejecter lacks manage_options', 'admin' );
		return new WP_Error( 'hal_mcp_forbidden', __( 'Only an administrator may reject change requests.', 'hal-mcp' ) );
	}

	if ( 'pending' !== $request['state'] && 'approved' !== $request['state'] ) {
		return new WP_Error(
			'hal_mcp_invalid_state',
			sprintf(
				/* translators: %s: current request state. */
				__( 'This request can no longer be rejected (current state: %s).', 'hal-mcp' ),
				$request['state']
			)
		);
	}

	if ( ! hal_mcp_request_set_status( $request_id, $request['state'], 'rejected' ) ) {
		return new WP_Error( 'hal_mcp_state_not_saved', __( 'The rejection could not be saved.', 'hal-mcp' ) );
	}

	update_post_meta( $request_id, '_hal_result', sanitize_text_field( $reason ) );

	hal_mcp_request_audit( $request_id, $request['operation'], 'rejected', true, sanitize_text_field( $reason ), 'admin' );

	return [
		'request_id' => $request_id,
		'state'      => 'rejected',
	];
}

// ---------------------------------------------------------------------------
// Apply
// ---------------------------------------------------------------------------

/**
 * Applies an approved request through its registered handler.
 *
 * Order of gates (F17): approved state → approver capability re-check →
 * audit log present → handler registered → idempotency lock → applying state
 * persisted → original fingerprint re-validated → handler executed with the
 * STORED payload only. Any gate that fails returns a clean WP_Error and —
 * except where a state transition already happened — leaves the request
 * state as it was. A repeat apply on an applied request returns success
 * WITHOUT executing anything again.
 *
 * Known, deliberate limitation: a fatal error mid-handler leaves the request
 * in 'applying'. That is fail-safe (nothing re-applies it automatically, per
 * §4.3), but the lock expiry alone does not recover it — an administrator
 * must correct the _hal_status meta manually. Terminal state-write failures
 * are reported honestly (error_log + audit row) instead of being claimed as
 * clean successes.
 *
 * @param int $request_id Request post ID.
 * @return array<string, mixed>|WP_Error { request_id, state, result, already_applied }
 */
function hal_mcp_apply_change_request( int $request_id ) {

	$request = hal_mcp_get_change_request( $request_id, true );

	if ( is_wp_error( $request ) ) {
		return $request;
	}

	// Idempotency: applying twice must not repeat the effect.
	if ( 'applied' === $request['state'] ) {
		return [
			'request_id'      => $request_id,
			'state'           => 'applied',
			'result'          => $request['result'],
			'already_applied' => true,
		];
	}

	if ( 'approved' !== $request['state'] ) {
		return new WP_Error(
			'hal_mcp_invalid_state',
			sprintf(
				/* translators: %s: current request state. */
				__( 'Only an approved request can be applied (current state: %s).', 'hal-mcp' ),
				$request['state']
			)
		);
	}

	if ( in_array( $request['kind'], [ 'admin_assist', 'run' ], true ) ) {
		return new WP_Error(
			'hal_mcp_not_applicable',
			__( 'Only content-change requests are applied; this row is not an appliable change.', 'hal-mcp' )
		);
	}

	// Re-check the approver's capabilities at execution time (F05: إعادة
	// الفحص عند التطبيق), with the SAME effect derivation approve() used —
	// a requested publish is re-checked as publish here, never silently
	// downgraded to edit.
	if ( ! current_user_can( 'manage_options' ) ) {
		hal_mcp_request_audit( $request_id, $request['operation'], 'apply_denied', false, 'applier lacks manage_options', 'admin' );
		return new WP_Error( 'hal_mcp_forbidden', __( 'Only an administrator may apply change requests.', 'hal-mcp' ) );
	}

	$apply_effect = hal_mcp_request_apply_effect( $request );

	foreach ( (array) $request['targets'] as $target ) {
		$object_type = (string) ( $target['type'] ?? '' );
		$object_id   = (int) ( $target['id'] ?? 0 );

		if ( '' === $object_type || ! hal_mcp_user_can_approve_target( $object_type, $object_id, $apply_effect ) ) {
			hal_mcp_request_audit( $request_id, $request['operation'], 'apply_denied', false, 'applier lacks the capability on a target', 'admin' );
			return new WP_Error( 'hal_mcp_forbidden_target', __( 'The current user does not have the capability this change needs on its target.', 'hal-mcp' ) );
		}
	}

	// No reliable record → no protected change (F06).
	if ( ! hal_mcp_audit_log_table_ready() ) {
		return new WP_Error(
			'hal_mcp_audit_unavailable',
			__( 'The audit log is unavailable, so this protected change was not executed.', 'hal-mcp' )
		);
	}

	$registry = &hal_mcp_apply_handler_registry();

	if ( ! isset( $registry[ $request['operation'] ] ) ) {
		return new WP_Error(
			'hal_mcp_no_apply_handler',
			__( 'This operation has no registered apply handler; the request was not executed.', 'hal-mcp' )
		);
	}

	if ( ! hal_mcp_request_acquire_lock( $request_id ) ) {
		return new WP_Error(
			'hal_mcp_apply_in_progress',
			__( 'This request is already being applied.', 'hal-mcp' )
		);
	}

	// Persist 'applying' BEFORE executing, and prove it persisted — a change
	// executed without a saved state record would be unprovable (F06).
	if ( ! hal_mcp_request_set_status( $request_id, 'approved', 'applying' ) ) {
		hal_mcp_request_release_lock( $request_id );
		return new WP_Error( 'hal_mcp_state_not_saved', __( 'The apply state could not be saved. Nothing was executed.', 'hal-mcp' ) );
	}

	hal_mcp_request_audit( $request_id, $request['operation'], 'apply_started', true, $request['preview'], 'admin' );

	// Fail-safe (F06/F17): an empty stored fingerprint over a NON-EMPTY
	// snapshot means the original could not be fingerprinted at creation
	// (the payload sanitizer now rejects the known causes, but legacy rows
	// may predate it). Without a reliable baseline there is no conflict
	// check — refuse rather than apply blind. A request with NO snapshot at
	// all is fine: there is nothing to compare. Independent of whether the
	// handler registered a revalidate callback.
	if ( '' === $request['original_fingerprint'] && ! empty( $request['original_snapshot'] ) ) {
		hal_mcp_request_set_status( $request_id, 'applying', 'conflict' );
		hal_mcp_request_release_lock( $request_id );
		hal_mcp_request_audit( $request_id, $request['operation'], 'conflict', false, 'original fingerprint unavailable; re-creation required', 'admin' );

		return new WP_Error(
			'hal_mcp_original_unfingerprintable',
			__( 'The original content could not be fingerprinted, so its current state cannot be verified. The change was NOT applied — create a new request.', 'hal-mcp' )
		);
	}

	// Fingerprint re-check: if the original changed since approval, the
	// stored approval is stale — conflict, re-preview, re-approve. Never an
	// overwrite.
	$revalidate = $registry[ $request['operation'] ]['revalidate'];

	if ( null !== $revalidate ) {
		$current_fingerprint = (string) call_user_func( $revalidate, $request );

		if ( '' !== $request['original_fingerprint'] && $current_fingerprint !== $request['original_fingerprint'] ) {
			if ( ! hal_mcp_request_set_status( $request_id, 'applying', 'conflict' ) ) {
				// Fail-safe by design (§4.3: no automatic re-apply): the row
				// stays in 'applying' until an administrator corrects its
				// _hal_status meta. Never silent — say so in the log.
				error_log( 'hal-mcp-abilities: change request #' . $request_id . ' reached conflict but the conflict state could not be persisted; an administrator must correct its _hal_status meta.' );
			}
			hal_mcp_request_release_lock( $request_id );
			hal_mcp_request_audit( $request_id, $request['operation'], 'conflict', false, 'original changed since approval; re-approval required', 'admin' );

			return new WP_Error(
				'hal_mcp_original_changed',
				__( 'The original content changed after this request was approved. The change was NOT applied — review it and approve again.', 'hal-mcp' )
			);
		}
	}

	$result = call_user_func( $registry[ $request['operation'] ]['handler'], $request );

	if ( is_wp_error( $result ) ) {
		$summary = $result->get_error_message();

		if ( ! hal_mcp_request_set_status( $request_id, 'applying', 'failed' ) ) {
			error_log( 'hal-mcp-abilities: change request #' . $request_id . ' failed mid-apply but the failed state could not be persisted; an administrator must correct its _hal_status meta.' );
		}
		update_post_meta( $request_id, '_hal_result', sanitize_text_field( $summary ) );
		hal_mcp_request_release_lock( $request_id );
		hal_mcp_request_audit( $request_id, $request['operation'], 'apply_failed', false, $summary, 'admin' );

		return new WP_Error( 'hal_mcp_apply_failed', $summary );
	}

	$result_summary = is_string( $result ) ? $result : (string) wp_json_encode( $result );
	update_post_meta( $request_id, '_hal_result', sanitize_text_field( $result_summary ) );
	hal_mcp_request_release_lock( $request_id );

	// The effect already happened and cannot be undone — but success must
	// not be ANNOUNCED without a provable state record (F06): if the
	// terminal write failed, say exactly that instead of claiming a clean
	// applied state.
	if ( ! hal_mcp_request_set_status( $request_id, 'applying', 'applied' ) ) {
		error_log( 'hal-mcp-abilities: change request #' . $request_id . ' WAS applied but the applied state could not be persisted; an administrator must verify and correct its _hal_status meta.' );
		// Stage named for the anomaly (not 'applied') so admin lists show
		// exactly what went wrong at a glance.
		hal_mcp_request_audit( $request_id, $request['operation'], 'apply_state_unsaved', false, 'applied but the state record could not be persisted', 'admin' );

		return new WP_Error(
			'hal_mcp_apply_state_unsaved',
			__( 'The change was applied, but its applied state could not be recorded. Ask an administrator to verify this request before it is reused.', 'hal-mcp' )
		);
	}

	hal_mcp_request_audit( $request_id, $request['operation'], 'applied', true, $result_summary, 'admin' );

	return [
		'request_id'      => $request_id,
		'state'           => 'applied',
		'result'          => $result_summary,
		'already_applied' => false,
	];
}

// ---------------------------------------------------------------------------
// Proposal edits (approval invalidation) and admin-assist requests
// ---------------------------------------------------------------------------

/**
 * Replaces a request's payload, resetting it to pending and voiding any
 * previous approval (roadmap §4.3: تعديل المقترح يعيد الحالة إلى pending
 * ويلغي الاعتماد السابق). Refused once applying/applied — history cannot be
 * rewritten.
 *
 * @param int                  $request_id         Request post ID.
 * @param array<string, mixed> $payload            New payload.
 * @param array<string, mixed> $original_snapshot  Fresh snapshot of the original
 *                                                fields (the old one is stale by
 *                                                definition once the proposal changed).
 * @return array<string, mixed>|WP_Error { request_id, state, proposed_fingerprint }
 */
function hal_mcp_update_change_request_payload( int $request_id, array $payload, array $original_snapshot = [] ) {

	$request = hal_mcp_get_change_request( $request_id, false );

	if ( is_wp_error( $request ) ) {
		return $request;
	}

	if ( ! hal_mcp_user_can_view_request( $request ) ) {
		return new WP_Error( 'hal_mcp_forbidden', __( 'You may not edit this change request.', 'hal-mcp' ) );
	}

	if ( in_array( $request['state'], [ 'applying', 'applied' ], true ) ) {
		return new WP_Error(
			'hal_mcp_invalid_state',
			__( 'This request has already been applied; create a new request instead.', 'hal-mcp' )
		);
	}

	$clean_payload = hal_mcp_sanitize_request_payload( $payload );

	if ( null === $clean_payload ) {
		return new WP_Error( 'hal_mcp_invalid_payload', __( 'The change payload could not be stored safely.', 'hal-mcp' ) );
	}

	$clean_snapshot = hal_mcp_sanitize_request_payload( $original_snapshot );

	if ( null === $clean_snapshot ) {
		return new WP_Error( 'hal_mcp_invalid_snapshot', __( 'The original snapshot could not be stored safely.', 'hal-mcp' ) );
	}

	$proposed_fingerprint = hal_mcp_fingerprint( $clean_payload );

	// Write-order invariant (§4.3: تعديل المقترح يلغي الاعتماد السابق): the
	// approval must be VOIDED before the new payload lands. If the state
	// reset ran after the payload write and then failed, the row would sit
	// in `approved` holding a proposal the approver never saw — so the
	// transition runs and is PROVEN first, and the payload/snapshot/
	// fingerprints are only persisted once the row is provably back in
	// `pending`, where no approval exists to hijack. A pending row skips the
	// transition and only refreshes its payload.
	$current_state = hal_mcp_request_read_status( $request_id );

	if ( in_array( $current_state, [ 'approved', 'rejected', 'failed', 'conflict' ], true ) ) {
		if ( ! hal_mcp_request_set_status( $request_id, $current_state, 'pending' ) ) {
			return new WP_Error(
				'hal_mcp_state_not_saved',
				__( 'The request could not be reset to pending. The previous payload and approval are untouched.', 'hal-mcp' )
			);
		}
	}

	delete_post_meta( $request_id, '_hal_approver' );
	delete_post_meta( $request_id, '_hal_approved_at' );
	update_post_meta( $request_id, '_hal_result', '' );

	update_post_meta( $request_id, '_hal_payload', $clean_payload );
	update_post_meta( $request_id, '_hal_original_snapshot', $clean_snapshot );
	update_post_meta( $request_id, '_hal_original_fingerprint', hal_mcp_fingerprint( $clean_snapshot ) );
	update_post_meta( $request_id, '_hal_proposed_fingerprint', $proposed_fingerprint );

	update_post(
		(int) $request_id,
		[
			'ID'        => $request_id,
			'post_title' => hal_mcp_build_request_preview( $request['operation'], (array) $request['targets'], $clean_payload ),
		]
	);

	hal_mcp_request_audit( $request_id, $request['operation'], 'request_invalidated', true, 'proposal changed; approval voided', (string) ( $request['origin']['source'] ?? 'model' ) );

	/**
	 * Fires after a request's payload was replaced and its approval voided
	 * (F21: أي تعديل لاحق يلغي الاعتماد — والجاهزية معه). The editor
	 * integration re-derives the editor-serialization readiness from the
	 * NEW payload, so a stored 'ready' can never outlive the proposal it
	 * was validated against.
	 *
	 * @param int $request_id Request post ID.
	 */
	do_action( 'hal_mcp_change_request_payload_updated', $request_id );

	return [
		'request_id'           => $request_id,
		'state'                => hal_mcp_request_read_status( $request_id ),
		'proposed_fingerprint' => $proposed_fingerprint,
	];
}

/**
 * Records a descriptive request for an administrative operation outside the
 * content tools (roadmap §4.3/F17): the model or a user asks, the request is
 * stored for the administrator to carry out MANUALLY — no generic execution
 * mechanism exists for these, by design.
 *
 * The returned 'state' is the MODEL-FACING mapping (hal_mcp_request_model_state:
 * 'pending_approval') — the store row itself keeps _hal_status 'pending'.
 *
 * @param string               $description What the administrator is asked to do.
 * @param array<string, mixed> $context     Optional structured context (redacted
 *                                          before it reaches the audit log).
 * @return array<string, mixed>|WP_Error { request_id, state, preview }
 */
function hal_mcp_create_admin_assist_request( string $description, array $context = [] ) {

	$description = sanitize_text_field( $description );

	if ( '' === $description ) {
		return new WP_Error( 'hal_mcp_missing_description', __( 'Describe what the administrator should do.', 'hal-mcp' ) );
	}

	$clean_context = hal_mcp_sanitize_request_payload( $context ) ?? [];

	$request_id = wp_insert_post(
		[
			'post_type'   => HAL_MCP_REQUEST_POST_TYPE,
			'post_status' => 'draft',
			// No model-provided content is HTML here — plain text only.
			'post_title'  => substr( $description, 0, 160 ),
			'post_author' => get_current_user_id(),
			'meta_input'  => [
				'_hal_kind'      => 'admin_assist',
				'_hal_operation' => 'admin_assist',
				'_hal_site_id'   => get_current_blog_id(),
				'_hal_targets'   => [],
				'_hal_payload'   => $clean_context,
				'_hal_language'  => '',
				'_hal_origin'    => [
					'source'  => 'model',
					'model'   => '',
					'version' => HAL_MCP_ABILITIES_VERSION,
				],
				'_hal_status'    => 'pending',
			],
		],
		true
	);

	if ( is_wp_error( $request_id ) ) {
		return new WP_Error(
			'hal_mcp_request_not_saved',
			__( 'The admin-assist request could not be saved.', 'hal-mcp' )
		);
	}

	$request_id = (int) $request_id;

	hal_mcp_request_audit( $request_id, 'admin_assist', 'requested', true, $description, 'model' );

	return [
		'request_id' => $request_id,
		'state'      => hal_mcp_request_model_state( 'pending' ),
		'preview'    => $description,
	];
}
