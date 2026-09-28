<?php
/**
 * hal-mcp-abilities — shared permission policy.
 *
 * This file is the single place that decides what a hal/* ability may do
 * (F05 in the v2.0.0 execution roadmap). The v1 design checked four hard-coded
 * capability strings and nothing else: an editor of one author could read and
 * write another author's drafts, and no check ever looked at the specific
 * object an ability targeted.
 *
 * The v2 policy has three layers, all checked through hal_mcp_permission():
 *
 * 1. Operation policy (this file). An operation is only ever evaluated against
 *    the post-type capability mapping WordPress/WooCommerce registered —
 *    never against a capability string invented at the call site, and never
 *    against a role name. Internal post types (the hal_mcp_request store) are
 *    refused outright as targets, so no generic query can ever address them.
 * 2. Object-level checks. When an ability names a target ID, the check goes
 *    through map_meta_cap via the registered mapping (read_post/edit_post and
 *    their product/page equivalents with the ID), so ownership and
 *    private-status rules WordPress already knows are enforced per object,
 *    not per post type.
 * 3. Separate administrative gates. Managing this plugin's settings and
 *    approving change requests require manage_options PLUS the capability the
 *    applied effect needs on the target — deliberately separate from the
 *    capabilities the model-facing tools use, and deliberately absent from
 *    every ability's permission_callback (no key management or approval is
 *    ever exposed as a model tool).
 *
 * Abilities API note (confirmed against WP_Ability in the WordPress 6.9
 * branch): permission_callback receives the ability input when an input
 * schema exists, and must return a strict bool. The closures in abilities/
 * therefore read the target ID straight from the input for the pre-execute
 * check, and the execute callbacks re-check object permissions before any
 * write — the Abilities API does not validate permission inputs itself, so
 * the re-check is the enforcement point that cannot be bypassed by a
 * malformed pre-execute input.
 *
 * @package hal-mcp-abilities
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Post types this plugin must never treat as writable or readable targets.
 * hal_mcp_request is this plugin's internal change-request store; everything
 * else the policy refuses by not being in HAL_MCP_OBJECT_POLICY at all.
 *
 * @var string[]
 */
const HAL_MCP_INTERNAL_POST_TYPES = [ 'hal_mcp_request' ];

/**
 * The operation policy: which real post type each policy object type maps to,
 * and which operations that object type supports.
 *
 * Operations resolve to capabilities exclusively through the post type's
 * registered mapping (get_post_type_object()->cap), so products and pages
 * follow whatever WooCommerce/core registered — including future changes to
 * those mappings — instead of hard-coded strings here.
 *
 * @var array<string, array<string, mixed>>
 */
const HAL_MCP_OBJECT_POLICY = [
	'post'    => [
		'post_type'  => 'post',
		'operations' => [ 'read', 'edit', 'create' ],
	],
	'page'    => [
		'post_type'  => 'page',
		'operations' => [ 'read', 'edit', 'create' ],
	],
	'product' => [
		'post_type'  => 'product',
		'operations' => [ 'read', 'edit', 'create' ],
	],
	'media'   => [
		'post_type'  => 'attachment',
		'operations' => [ 'read', 'list', 'upload', 'edit' ],
	],
	'system'  => [
		// No real post type: the inventory reader (hal/get-site-inventory)
		// keeps v1's coarse edit_posts gate on purpose (F07): its output is
		// the model-safe environment summary from includes/environment.php —
		// no secrets, no paths, no capability maps. The fuller inventory
		// stays admin-side data (the F19 screen) and is never an ability
		// output.
		'post_type'  => '',
		'operations' => [ 'read' ],
	],
];

/**
 * Post types the F20 generic content abilities (hal/get-content, hal/
 * search-content, hal/create-content, hal/update-content) may address.
 *
 * Everything else that is NOT one of these — nor a HAL_MCP_OBJECT_POLICY key —
 * is refused outright by the policy, so "public post type" alone never makes a
 * type writable through the generic path.
 *
 * @var string[]
 */
const HAL_MCP_SPECIALIZED_OBJECT_POST_TYPES = [ 'post', 'page', 'attachment', 'product', 'product_variation' ];

/**
 * Legacy capability strings kept for backwards compatibility only. Everything
 * in this list is a coarse primitive; all object-level work must go through
 * hal_mcp_permission(), which never passes an arbitrary string to
 * current_user_can().
 *
 * @var string[]
 */
const HAL_MCP_ALLOWED_CAPABILITIES = [
	'edit_posts',
	'edit_others_posts',
	'upload_files',
	'edit_products',
];

/**
 * The public post types the F20 generic content abilities may address (F20:
 * «للأنواع العامة المخصصة المصرح بها»).
 *
 * A type qualifies only when ALL of the following hold:
 * - it is publicly queryable (public=true),
 * - it is not an internal type of this plugin and not one of the specialized
 *   types that have dedicated abilities (post/page/attachment/product —
 *   the generic path must never bypass the products or editor policy),
 * - it registers a real capability map (read_post/edit_post/create_posts),
 *   so every operation resolves through the REGISTERED mapping, never an
 *   invented string.
 *
 * The list is filterable so a manager can correct discovery (§4.4) without
 * editing code; a filter removing entries is always honoured.
 *
 * @return string[]
 */
function hal_mcp_content_authorized_post_types(): array {

	static $hal_mcp_cached = null;

	if ( is_array( $hal_mcp_cached ) ) {
		return $hal_mcp_cached;
	}

	$hal_mcp_types = [];

	foreach ( get_post_types( [ 'public' => true ], 'objects' ) as $hal_mcp_type ) {
		if ( ! $hal_mcp_type instanceof WP_Post_Type ) {
			continue;
		}

		if ( hal_mcp_is_internal_post_type( $hal_mcp_type->name )
			|| in_array( $hal_mcp_type->name, HAL_MCP_SPECIALIZED_OBJECT_POST_TYPES, true ) ) {
			continue;
		}

		if ( empty( $hal_mcp_type->cap )
			|| empty( $hal_mcp_type->cap->read_post )
			|| empty( $hal_mcp_type->cap->edit_post )
			|| empty( $hal_mcp_type->cap->create_posts ) ) {
			continue;
		}

		$hal_mcp_types[] = $hal_mcp_type->name;
	}

	/**
	 * Filters the post types addressable by the generic content abilities.
	 *
	 * @param string[] $hal_mcp_types Discovered authorized public post types.
	 */
	$hal_mcp_types = (array) apply_filters( 'hal_mcp_content_authorized_post_types', $hal_mcp_types );

	/*
	 * Re-validate the filtered list against the same rules discovery used
	 * (defence in depth): a filter may correct detection (drop a misdetected
	 * type, add a legitimately public one), but it is bounded to PUBLIC types
	 * with full capability maps — it can never inject a specialized type into
	 * the generic path, a non-public type that merely carries a cap map, or a
	 * type without a real capability map. Internal types stay blocked
	 * downstream regardless.
	 */
	$hal_mcp_types = array_values( array_unique( array_filter( array_map( 'strval', $hal_mcp_types ) ) ) );

	$hal_mcp_clean = [];

	foreach ( $hal_mcp_types as $hal_mcp_type ) {
		if ( hal_mcp_is_internal_post_type( $hal_mcp_type )
			|| in_array( $hal_mcp_type, HAL_MCP_SPECIALIZED_OBJECT_POST_TYPES, true ) ) {
			continue;
		}

		$hal_mcp_object = get_post_type_object( $hal_mcp_type );

		if ( ! $hal_mcp_object
			|| empty( $hal_mcp_object->public )
			|| empty( $hal_mcp_object->cap )
			|| empty( $hal_mcp_object->cap->read_post )
			|| empty( $hal_mcp_object->cap->edit_post )
			|| empty( $hal_mcp_object->cap->create_posts ) ) {
			continue;
		}

		$hal_mcp_clean[] = $hal_mcp_type;
	}

	$hal_mcp_cached = $hal_mcp_clean;

	return $hal_mcp_cached;
}

/**
 * Whether an object type slug is an authorized generic content post type
 * (F20). Used by the policy gate, the change-request target gate, and the
 * approval capability check so all three paths stay identical.
 *
 * @param string $object_type Object type slug (a post type slug for content types).
 * @return bool
 */
function hal_mcp_is_content_object_type( string $object_type ): bool {
	return in_array( $object_type, hal_mcp_content_authorized_post_types(), true );
}

/**
 * Resolves the real post type behind a policy object type or an authorized
 * content type — the single lookup every policy consumer shares, so the
 * const policy and the F20 content types can never drift apart.
 *
 * @param string $object_type A HAL_MCP_OBJECT_POLICY key or an authorized
 *                            content post type slug.
 * @return string The post type slug, or '' when the object type is unknown.
 */
function hal_mcp_object_type_post_type( string $object_type ): string {

	if ( array_key_exists( $object_type, HAL_MCP_OBJECT_POLICY ) ) {
		return (string) HAL_MCP_OBJECT_POLICY[ $object_type ]['post_type'];
	}

	if ( hal_mcp_is_content_object_type( $object_type ) ) {
		return $object_type;
	}

	return '';
}

/**
 * The operations a policy object type or authorized content type supports.
 *
 * @param string $object_type Object type slug.
 * @return string[]
 */
function hal_mcp_object_type_operations( string $object_type ): array {

	// Array (not '') so the unknown-type fallback return honors the string[]
	// signature instead of risking a latent TypeError.
	$hal_mcp_operations = [];

	if ( array_key_exists( $object_type, HAL_MCP_OBJECT_POLICY ) ) {
		$hal_mcp_operations = HAL_MCP_OBJECT_POLICY[ $object_type ]['operations'];
	} elseif ( hal_mcp_is_content_object_type( $object_type ) ) {
		$hal_mcp_operations = [ 'read', 'edit', 'create' ];
	}

	return $hal_mcp_operations;
}

/**
 * The central permission gate for every hal/* ability (F05).
 *
 * @param string $object_type A HAL_MCP_OBJECT_POLICY key, e.g. 'post' or 'product'.
 * @param string $operation   One of the object type's declared operations.
 * @param int    $object_id   Target ID when the ability names one; 0 for
 *                            create/list-style calls, which resolves to the
 *                            coarse create_posts/upload_files gate. Every
 *                            execute path that touches a specific object MUST
 *                            re-check here with the real ID — an edit or read
 *                            called with a 0 ID gets only the coarse gate.
 * @param array  $context     Optional: 'ability' (calling ability name),
 *                            'quiet' (bool, suppress denial logging for bulk
 *                            per-result filtering), 'reason' (why the check
 *                            runs, for the audit row).
 * @return bool True only when the policy, the post-type mapping, and the
 *              object-level capability check (if an ID was given) all pass.
 */
function hal_mcp_permission( string $object_type, string $operation, int $object_id = 0, array $context = [] ): bool {

	$denied = static function ( string $reason ) use ( $object_type, $operation, $object_id, $context ): bool {
		if ( empty( $context['quiet'] ) ) {
			$hal_mcp_row_context = [
				'object_type' => $object_type,
				'operation'   => $operation,
				'object_id'   => $object_id,
				'stage'       => 'permission_denied',
			];

			// 'reason' (why the check runs) is documented to reach the audit
			// row — keep that promise (e.g. write-media's attach_parent).
			if ( ! empty( $context['reason'] ) ) {
				$hal_mcp_row_context['check_reason'] = (string) $context['reason'];
			}

			hal_mcp_log_permission_denial(
				trim( (string) ( $context['ability'] ?? '' ) ) ?: "{$object_type}:{$operation}",
				$reason,
				$hal_mcp_row_context
			);
		}
		return false;
	};

	// F20: an authorized generic content post type goes through the exact
	// same policy gate as a const policy key — same operations, same
	// registered capability mapping, same denial logging. A type that is
	// neither a policy key nor an authorized content type is refused here,
	// so "public post type" alone never makes anything addressable.
	if ( ! array_key_exists( $object_type, HAL_MCP_OBJECT_POLICY ) && ! hal_mcp_is_content_object_type( $object_type ) ) {
		return $denied( 'object type is not in the operation policy' );
	}

	$hal_mcp_operations = hal_mcp_object_type_operations( $object_type );

	if ( ! in_array( $operation, $hal_mcp_operations, true ) ) {
		return $denied( 'operation is not declared for this object type' );
	}

	$post_type = hal_mcp_object_type_post_type( $object_type );

	if ( hal_mcp_is_internal_post_type( $post_type ) ) {
		return $denied( 'internal post types are never addressable' );
	}

	$capability = hal_mcp_resolve_object_capability( $object_type, $operation, $object_id > 0 );

	if ( null === $capability ) {
		return $denied( 'no registered capability mapping for this post type (is its plugin active?)' );
	}

	if ( $capability['needs_object_id'] ) {
		if ( $object_id < 1 ) {
			return $denied( 'object-level check requested without a target ID' );
		}
		$allowed = current_user_can( $capability['capability'], $object_id );
	} else {
		$allowed = current_user_can( $capability['capability'] );
	}

	if ( ! $allowed ) {
		return $denied( sprintf( 'capability check failed: %s', $capability['capability'] ) );
	}

	return true;
}

/**
 * Resolves the capability for an object type + operation through the post
 * type's REGISTERED capability mapping (F05: products/pages follow the
 * registered mapping; no capability string is invented here).
 *
 * @param string $object_type A HAL_MCP_OBJECT_POLICY key.
 * @param string $operation   A declared operation for that object type.
 * @param bool   $has_target  Whether an object ID is available.
 * @return array<string, mixed>|null { capability: string, needs_object_id: bool }
 *              or null when the post type is not registered (plugin inactive).
 */
function hal_mcp_resolve_object_capability( string $object_type, string $operation, bool $has_target ): ?array {

	$post_type = hal_mcp_object_type_post_type( $object_type );

	// Literal primitives: no post-type mapping exists for these operations.
	if ( '' === $post_type ) {
		return [ 'capability' => 'edit_posts', 'needs_object_id' => false ];
	}

	if ( 'upload' === $operation || 'list' === $operation ) {
		return [ 'capability' => 'upload_files', 'needs_object_id' => false ];
	}

	$post_type_object = get_post_type_object( $post_type );

	if ( ! $post_type_object || empty( $post_type_object->cap ) ) {
		return null;
	}

	if ( 'create' === $operation || ! $has_target ) {
		// No target (or a create): fall back to the type's own create_posts
		// primitive — for 'post' that is literally edit_posts and for
		// 'product' literally edit_products, preserving the v1 coarse gate.
		return [
			'capability'      => (string) $post_type_object->cap->create_posts,
			'needs_object_id' => false,
		];
	}

	if ( 'read' === $operation ) {
		return [
			'capability'      => (string) $post_type_object->cap->read_post,
			'needs_object_id' => true,
		];
	}

	return [
		'capability'      => (string) $post_type_object->cap->edit_post,
		'needs_object_id' => true,
	];
}

/**
 * Convenience wrapper for domain/read code: may the current user read this
 * specific object? Denials are logged unless $quiet is set (bulk filtering).
 *
 * @param string $object_type A HAL_MCP_OBJECT_POLICY key.
 * @param int    $object_id   Target ID.
 * @param bool   $quiet       True to suppress the audit denial row.
 * @return bool
 */
function hal_mcp_user_can_read_object( string $object_type, int $object_id, bool $quiet = false ): bool {
	return hal_mcp_permission( $object_type, 'read', $object_id, [ 'quiet' => $quiet ] );
}

/**
 * Convenience wrapper for domain/write code: may the current user edit this
 * specific object? Denials are logged unless $quiet is set.
 *
 * @param string $object_type A HAL_MCP_OBJECT_POLICY key.
 * @param int    $object_id   Target ID.
 * @param bool   $quiet       True to suppress the audit denial row.
 * @return bool
 */
function hal_mcp_user_can_edit_object( string $object_type, int $object_id, bool $quiet = false ): bool {
	return hal_mcp_permission( $object_type, 'edit', $object_id, [ 'quiet' => $quiet ] );
}

/**
 * Whether the current user may APPROVE a change request whose apply effect on
 * the target is 'edit' or 'publish' (F05: settings/approval management is
 * manage_options PLUS the capability the effect itself needs on the target;
 * a site administrator who cannot edit a specific object may not approve a
 * change to it either).
 *
 * WordPress has no per-object publish meta cap, so a publish effect is gated
 * by BOTH the type's publish primitive AND the object's own edit cap — the
 * same rule for create-style targets (id 0, publish primitive only) and
 * existing targets. This is the gate approve() and apply() share, so the two
 * paths can never drift apart.
 *
 * Called from includes/change-requests.php at approve() and again inside
 * apply() — the apply-time re-check is the authoritative one.
 *
 * @param string $object_type A HAL_MCP_OBJECT_POLICY key.
 * @param int    $object_id   Target ID (0 for create-style requests).
 * @param string $effect      'edit' or 'publish'.
 * @return bool
 */
function hal_mcp_user_can_approve_target( string $object_type, int $object_id, string $effect = 'edit' ): bool {

	if ( ! current_user_can( 'manage_options' ) ) {
		return false;
	}

	// F20: authorized generic content types resolve through their own
	// registered mapping, exactly like the const policy types.
	$post_type = hal_mcp_object_type_post_type( $object_type );

	if ( '' === $post_type || hal_mcp_is_internal_post_type( $post_type ) ) {
		return false;
	}

	$post_type_object = get_post_type_object( $post_type );

	if ( ! $post_type_object || empty( $post_type_object->cap ) ) {
		return false;
	}

	$wants_publish = ( 'publish' === $effect );

	if ( $object_id < 1 ) {
		// Create-style request: the approver needs the primitive that
		// publishing/creating under this type requires.
		$capability = $wants_publish
			? (string) $post_type_object->cap->publish_posts
			: (string) $post_type_object->cap->create_posts;

		return current_user_can( $capability );
	}

	// Existing target: a publish effect additionally requires the type's
	// publish primitive — the object-level edit cap alone would let a
	// manager approve a publishing change they could not perform themselves.
	if ( $wants_publish && ! current_user_can( (string) $post_type_object->cap->publish_posts ) ) {
		return false;
	}

	// map_meta_cap applies ownership/status rules — an approver who cannot
	// edit the object under the same rules a human editor would face may not
	// approve the change.
	return current_user_can( (string) $post_type_object->cap->edit_post, $object_id );
}

/**
 * Decides how a write to a known-status object must proceed (F05: one shared
 * direct/request/deny decision used by the ability callbacks themselves, so
 * an MCP client cannot bypass it by calling a helper directly).
 *
 * Policy (roadmap §4.3): direct execution is limited to draft/auto-draft.
 * publish/future/private/pending and unknown custom statuses are protected —
 * a write to them becomes a change request. Trash is denied outright.
 *
 * The old v1 gate ('anything not publish is directly editable') is gone: it
 * let a private/future/trash post be edited live.
 *
 * @param string $object_type    A HAL_MCP_OBJECT_POLICY key ('post', 'page', 'product').
 * @param string $current_status The target's current post_status.
 * @param array  $args           Optional: 'field_impact' => 'protected' forces
 *                               the request path regardless of status (used
 *                               when the affected fields carry protected
 *                               impact, e.g. SEO metadata on a live item);
 *                               'operation' => calling ability name.
 * @return array{decision: string, reason: string} 'direct' | 'request' | 'deny'.
 */
function hal_mcp_decide_write_path( string $object_type, string $current_status, array $args = [] ): array {

	if ( ! empty( $args['field_impact'] ) && 'protected' === $args['field_impact'] ) {
		return [
			'decision' => 'request',
			'reason'   => 'field_impact_protected',
		];
	}

	if ( 'trash' === $current_status ) {
		return [
			'decision' => 'deny',
			'reason'   => 'status_trash',
		];
	}

	if ( in_array( $current_status, [ 'draft', 'auto-draft' ], true ) ) {
		return [
			'decision' => 'direct',
			'reason'   => 'status_draft',
		];
	}

	return [
		'decision' => 'request',
		'reason'   => 'status_protected:' . $current_status,
	];
}

/**
 * Whether a post type is internal to this plugin and therefore must never
 * appear as a target or in any public/general query result.
 *
 * @param string $post_type Post type slug.
 * @return bool
 */
function hal_mcp_is_internal_post_type( string $post_type ): bool {
	return in_array( $post_type, HAL_MCP_INTERNAL_POST_TYPES, true );
}

/**
 * Records a local permission denial as an audit control event (F05/F06).
 *
 * The Abilities API hooks cannot see a denied call — wp_before_execute_ability
 * only fires after the permission check has already passed — so the policy
 * layer logs denials itself. Goes through the audit log's redaction; never
 * throws. Callers must not assume the audit hooks will log this for them.
 *
 * @param string $operation Operation or ability name.
 * @param string $reason    Human-readable denial reason.
 * @param array  $context   Structured context (redacted before storage).
 * @return void
 */
function hal_mcp_log_permission_denial( string $operation, string $reason, array $context = [] ): void {

	$stage = (string) ( $context['stage'] ?? 'permission_denied' );
	unset( $context['stage'] );

	hal_mcp_log_control_event(
		[
			'operation'      => $operation,
			'stage'          => $stage,
			'success'        => false,
			'result_summary' => $reason,
			'context'        => $context,
		]
	);
}

/**
 * Legacy coarse capability check, kept for backwards compatibility with the
 * v1 surface (external code that called it directly). It has no internal
 * callers anymore — every ability now goes through hal_mcp_permission().
 *
 * Only the literal primitives in HAL_MCP_ALLOWED_CAPABILITIES are ever
 * evaluated; anything else is refused without a current_user_can() call. New
 * code must use hal_mcp_permission() — this function cannot do object-level
 * checks and must not grow to accept them.
 *
 * @param string $capability A single primitive capability slug.
 * @return bool True if the capability is allow-listed AND the current user has it.
 */
function hal_mcp_check_capability( string $capability ): bool {

	$capability = trim( $capability );

	if ( '' === $capability ) {
		error_log( 'hal-mcp-abilities: hal_mcp_check_capability() called with an empty capability.' );
		return false;
	}

	if ( ! in_array( $capability, HAL_MCP_ALLOWED_CAPABILITIES, true ) ) {
		error_log(
			sprintf(
				'hal-mcp-abilities: refused to check capability "%s" — it is not in HAL_MCP_ALLOWED_CAPABILITIES.',
				$capability
			)
		);
		return false;
	}

	return current_user_can( $capability );
}
