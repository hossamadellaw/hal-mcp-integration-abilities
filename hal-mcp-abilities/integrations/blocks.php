<?php
/**
 * hal-mcp-abilities — block-editor (Gutenberg/Spectra) integration (F21).
 *
 * The block editor is the storage for block pages (post_content markup), so
 * this integration owns three things the roadmap assigns to F21:
 *
 * 1. Detection. The registered block types are enumerated from WordPress's
 *    own block registry — never from plugin names — grouped by namespace, so
 *    Gutenberg and every block-based builder (Spectra Legacy under its own
 *    namespace, Spectra Blocks under whatever namespace it registers) are
 *    reported exactly as they exist. No single Spectra namespace is assumed,
 *    and no save() output is ever reproduced from attributes here.
 *
 * 2. Server-side sanitization. Every model- or editor.js-provided markup
 *    value is parsed and walked BEFORE it can be stored in a proposal, a
 *    preview, or a fingerprint: HTML goes through wp_kses_post (scripts,
 *    event handlers, and executable links never survive), known block
 *    structures are preserved (innerBlocks and untouched blocks keep their
 *    shape), and blocks whose type is not registered are kept but REPORTED
 *    as unverified — never silently blessed as valid.
 *
 * 3. The editor-serialization readiness contract. A design request authored
 *    by the model carries the independent readiness state `needs_editor` in
 *    the stored request data (roadmap F21): a real-editor serialization must
 *    be validated and stored (sanitized, re-fingerprinted) before the
 *    request can be approved — the gate itself lives in
 *    includes/change-requests.php. The value is derived and written
 *    SERVER-SIDE only; any readiness value arriving from the model or the
 *    browser is meaningless here by construction.
 *
 * The editor.js bridge (assets/editor.js) is the client half: it serializes
 * inside the REAL editor and posts the result to the protected admin route
 * (F19), which lands here through hal_mcp_blocks_ingest_editor_serialization().
 * This file never trusts that result either — it re-validates target and
 * request-version fingerprint, re-sanitizes, and re-fingerprints.
 *
 * @package hal-mcp-abilities
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Upper bound for the block-registry snapshot: a site with an extreme number
 * of registered block types cannot flood the inventory.
 *
 * @var int
 */
const HAL_MCP_BLOCKS_SNAPSHOT_MAX = 200;

/**
 * Upper bound for attributes reported per block type.
 *
 * @var int
 */
const HAL_MCP_BLOCKS_ATTRS_MAX = 30;

/**
 * Upper bound for blocks walked in one sanitization pass.
 *
 * @var int
 */
const HAL_MCP_BLOCKS_SANITIZE_MAX_BLOCKS = 500;

/**
 * Maximum block nesting depth the sanitizer walks. Deeper structures are
 * refused rather than truncated (a truncated design would look valid while
 * being broken).
 *
 * @var int
 */
const HAL_MCP_BLOCKS_SANITIZE_MAX_DEPTH = 10;

// The request meta key holding the editor-serialization readiness state
// (HAL_MCP_REQUEST_EDITOR_READINESS_KEY) is owned by the request store and
// defined in includes/change-requests.php; this file only reads and writes
// the value through the shared const.

// ---------------------------------------------------------------------------
// Detection
// ---------------------------------------------------------------------------

/**
 * Snapshots the registered block types from WordPress's own block registry,
 * bounded, grouped by namespace (F21: detect block types, their attributes,
 * and support per what actually exists — never from plugin names).
 *
 * @return array{
 *   available: bool,
 *   reason: string,
 *   namespaces: array<string, int>,
 *   blocks: array<int, array{name: string, namespace: string, attributes: string[]}>
 * } `available` is false (with a reason) when the block registry is not
 *   reachable; `blocks` is capped at HAL_MCP_BLOCKS_SNAPSHOT_MAX.
 */
function hal_mcp_blocks_registry_snapshot(): array {

	if ( ! class_exists( 'WP_Block_Type_Registry' ) ) {
		return [
			'available'  => false,
			'reason'     => 'the block types registry (WP_Block_Type_Registry) is not available',
			'namespaces' => [],
			'blocks'     => [],
		];
	}

	$hal_mcp_registry = WP_Block_Type_Registry::get_instance();

	if ( ! is_object( $hal_mcp_registry ) || ! method_exists( $hal_mcp_registry, 'get_all_registered' ) ) {
		return [
			'available'  => false,
			'reason'     => 'the block types registry does not expose get_all_registered()',
			'namespaces' => [],
			'blocks'     => [],
		];
	}

	$hal_mcp_all = $hal_mcp_registry->get_all_registered();

	if ( ! is_array( $hal_mcp_all ) ) {
		return [
			'available'  => false,
			'reason'     => 'the block registry returned no block list',
			'namespaces' => [],
			'blocks'     => [],
		];
	}

	$hal_mcp_blocks     = [];
	$hal_mcp_namespaces = [];

	foreach ( array_slice( $hal_mcp_all, 0, HAL_MCP_BLOCKS_SNAPSHOT_MAX, true ) as $hal_mcp_name => $hal_mcp_type ) {
		$hal_mcp_name = (string) $hal_mcp_name;

		if ( '' === $hal_mcp_name ) {
			continue;
		}

		$hal_mcp_namespace = str_contains( $hal_mcp_name, '/' )
			? (string) substr( $hal_mcp_name, 0, (int) strpos( $hal_mcp_name, '/' ) )
			: 'core';

		$hal_mcp_namespaces[ $hal_mcp_namespace ] = ( $hal_mcp_namespaces[ $hal_mcp_namespace ] ?? 0 ) + 1;

		$hal_mcp_attributes = [];

		if ( isset( $hal_mcp_type->attributes ) && is_array( $hal_mcp_type->attributes ) ) {
			$hal_mcp_attributes = array_slice( array_map( 'strval', array_keys( $hal_mcp_type->attributes ) ), 0, HAL_MCP_BLOCKS_ATTRS_MAX );
		}

		$hal_mcp_blocks[] = [
			'name'       => $hal_mcp_name,
			'namespace'  => $hal_mcp_namespace,
			'attributes' => $hal_mcp_attributes,
		];
	}

	return [
		'available'  => true,
		'reason'     => '',
		'namespaces' => $hal_mcp_namespaces,
		'blocks'     => $hal_mcp_blocks,
	];
}

/**
 * Whether a block name is registered in WordPress's block registry. The
 * sanitizer treats this as the only source of "known block" — an unregistered
 * block is kept but reported unverified, never blessed as valid.
 *
 * @param string $block_name Block name, e.g. 'core/paragraph'.
 * @return bool|null null when the registry is unreachable (unverifiable).
 */
function hal_mcp_blocks_is_registered( string $block_name ): ?bool {

	if ( '' === $block_name || ! class_exists( 'WP_Block_Type_Registry' ) ) {
		return null;
	}

	$hal_mcp_registry = WP_Block_Type_Registry::get_instance();

	if ( ! is_object( $hal_mcp_registry ) || ! method_exists( $hal_mcp_registry, 'is_registered' ) ) {
		return null;
	}

	return (bool) $hal_mcp_registry->is_registered( $block_name );
}

// ---------------------------------------------------------------------------
// Server-side sanitization of block markup
// ---------------------------------------------------------------------------

/**
 * Recursively sanitizes one attribute value (F21: منع script/event handlers
 * والروابط التنفيذية). Strings are sanitized BY KEY SHAPE: a value under a
 * URL-shaped key (any key ending in 'url', case-insensitively — 'url',
 * 'image_url', 'backgroundUrl' ...) goes through esc_url_raw, so executable
 * schemes are emptied; every other string goes through wp_kses_post (which
 * strips script tags and on* handlers). Arrays recurse with a depth wall,
 * passing the CHILD key down, scalars pass, everything else is dropped and
 * reported.
 *
 * @param mixed  $value  Value to sanitize.
 * @param string $key    The key the value sits under (model/editor-provided).
 * @param array  $report Sanitization report (by reference).
 * @param int    $depth  Current depth.
 * @return mixed The sanitized value, or null when the value had to be dropped.
 */
function hal_mcp_blocks_sanitize_value( $value, string $key, array &$report, int $depth = 0 ) {

	if ( is_string( $value ) ) {
		$hal_mcp_is_url = (bool) preg_match( '/url$/i', $key );

		$hal_mcp_clean = $hal_mcp_is_url
			? esc_url_raw( $value )
			: wp_kses_post( $value );

		if ( $hal_mcp_clean !== $value ) {
			++$report['sanitized_values'];
		}

		return $hal_mcp_clean;
	}

	if ( is_array( $value ) ) {
		if ( $depth > HAL_MCP_BLOCKS_SANITIZE_MAX_DEPTH ) {
			++$report['dropped_values'];
			return null;
		}

		$hal_mcp_clean = [];

		foreach ( $value as $hal_mcp_key => $hal_mcp_child ) {
			$hal_mcp_clean[ $hal_mcp_key ] = hal_mcp_blocks_sanitize_value( $hal_mcp_child, (string) $hal_mcp_key, $report, $depth + 1 );
		}

		return $hal_mcp_clean;
	}

	if ( is_bool( $value ) || is_int( $value ) || is_float( $value ) || null === $value ) {
		return $value;
	}

	++$report['dropped_values'];
	return null;
}

/**
 * Sanitizes one parsed block (and its inner blocks) in place (by reference
 * through the return). Unknown block types keep their structure and content
 * (kses-sanitized) but are REPORTED as unverified — the roadmap forbids
 * treating an edit that depends on an unavailable save() or an unknown block
 * as valid, and the report is what keeps that honest.
 *
 * @param array $block   Parsed block ({blockName, attrs, innerBlocks,
 *                       innerHTML, innerContent}).
 * @param array $report  Sanitization report (by reference).
 * @param int   $depth   Current depth.
 * @return array|null The sanitized block, or null when it had to be dropped.
 */
function hal_mcp_blocks_sanitize_block( array $block, array &$report, int $depth = 0 ): ?array {

	if ( $depth > HAL_MCP_BLOCKS_SANITIZE_MAX_DEPTH || $report['blocks'] >= HAL_MCP_BLOCKS_SANITIZE_MAX_BLOCKS ) {
		++$report['dropped_blocks'];
		return null;
	}

	++$report['blocks'];

	$hal_mcp_name = is_string( $block['blockName'] ?? null ) ? $block['blockName'] : '';

	$hal_mcp_registration = hal_mcp_blocks_is_registered( $hal_mcp_name );

	if ( '' !== $hal_mcp_name ) {
		if ( true === $hal_mcp_registration ) {
			++$report['known_blocks'];
		} else {
			// Unknown (or unverifiable — null) block: keep and report. The
			// name is model/editor-provided text, so it is capped and
			// tag-stripped before it enters the report. The markup itself is
			// still sanitized below; it is never "valid".
			++$report['unverified_blocks'];

			$hal_mcp_reported_name = function_exists( 'mb_substr' )
				? mb_substr( $hal_mcp_name, 0, 128 )
				: substr( $hal_mcp_name, 0, 128 );

			$report['unverified_names'][ sanitize_text_field( $hal_mcp_reported_name ) ] = true;
		}
	}

	// Attributes: the KEYS are model/editor-provided (NOT the block's own
	// registered attribute names) and pass through structurally; the VALUES
	// are sanitized by shape per key — URL-shaped keys through esc_url_raw,
	// everything else through wp_kses_post.
	if ( isset( $block['attrs'] ) && is_array( $block['attrs'] ) ) {
		$hal_mcp_clean_attrs = [];

		foreach ( $block['attrs'] as $hal_mcp_key => $hal_mcp_value ) {
			$hal_mcp_clean_attrs[ (string) $hal_mcp_key ] = hal_mcp_blocks_sanitize_value( $hal_mcp_value, (string) $hal_mcp_key, $report );
		}

		$block['attrs'] = $hal_mcp_clean_attrs;
	}

	// Stored HTML (innerHTML/innerContent) is untrusted markup: kses only.
	if ( isset( $block['innerHTML'] ) && is_string( $block['innerHTML'] ) ) {
		$hal_mcp_clean_html = wp_kses_post( $block['innerHTML'] );

		if ( $hal_mcp_clean_html !== $block['innerHTML'] ) {
			++$report['sanitized_html'];
		}

		$block['innerHTML'] = $hal_mcp_clean_html;
	}

	if ( isset( $block['innerContent'] ) && is_array( $block['innerContent'] ) ) {
		$hal_mcp_clean_inner = [];

		foreach ( $block['innerContent'] as $hal_mcp_part ) {
			$hal_mcp_clean_inner[] = is_string( $hal_mcp_part ) ? wp_kses_post( $hal_mcp_part ) : $hal_mcp_part;
		}

		$block['innerContent'] = $hal_mcp_clean_inner;
	}

	// innerBlocks: preserved structurally (F21: الحفاظ على innerBlocks
	// والسمات والكتل غير المستهدفة), sanitized recursively.
	if ( isset( $block['innerBlocks'] ) && is_array( $block['innerBlocks'] ) ) {
		$hal_mcp_clean_children = [];

		foreach ( $block['innerBlocks'] as $hal_mcp_child ) {
			$hal_mcp_clean_child = is_array( $hal_mcp_child )
				? hal_mcp_blocks_sanitize_block( $hal_mcp_child, $report, $depth + 1 )
				: null;

			if ( null !== $hal_mcp_clean_child ) {
				$hal_mcp_clean_children[] = $hal_mcp_clean_child;
			}
		}

		$block['innerBlocks'] = $hal_mcp_clean_children;
	}

	return $block;
}

/**
 * THE server-side sanitization point for block markup provided by the model
 * or by editor.js (F21). Parses, walks, sanitizes, and re-serializes; the
 * fingerprint any proposal carries is computed from the RETURNED markup, so
 * what was approved is exactly what was sanitized.
 *
 * parse_blocks()/serialize_blocks() handle the structure — this function
 * never assumes they reproduce each block's save() HTML; it only guarantees
 * that what goes IN to them comes back sanitized.
 *
 * @param string $markup Raw block markup (model or editor.js provided).
 * @return array{
 *   markup: string,
 *   report: array{blocks: int, known_blocks: int, unverified_blocks: int,
 *                 dropped_blocks: int, sanitized_values: int, sanitized_html: int,
 *                 dropped_values: int, unverified_names: array<string, true>}
 * }|WP_Error
 */
function hal_mcp_blocks_sanitize_markup( string $markup ) {

	if ( ! function_exists( 'parse_blocks' ) || ! function_exists( 'serialize_blocks' ) ) {
		return new WP_Error(
			'hal_mcp_blocks_parse_unavailable',
			__( 'Block parsing is not available in this environment.', 'hal-mcp' )
		);
	}

	if ( '' === trim( $markup ) ) {
		return new WP_Error(
			'hal_mcp_blocks_empty_markup',
			__( 'The block markup is empty.', 'hal-mcp' )
		);
	}

	$hal_mcp_parsed = parse_blocks( $markup );

	if ( ! is_array( $hal_mcp_parsed ) ) {
		return new WP_Error(
			'hal_mcp_blocks_parse_failed',
			__( 'The markup could not be parsed as block content.', 'hal-mcp' )
		);
	}

	$hal_mcp_report = [
		'blocks'            => 0,
		'known_blocks'      => 0,
		'unverified_blocks' => 0,
		'dropped_blocks'    => 0,
		'sanitized_values'  => 0,
		'sanitized_html'    => 0,
		'dropped_values'    => 0,
		'unverified_names'  => [],
	];

	$hal_mcp_clean_blocks = [];

	foreach ( $hal_mcp_parsed as $hal_mcp_block ) {
		$hal_mcp_clean = is_array( $hal_mcp_block )
			? hal_mcp_blocks_sanitize_block( $hal_mcp_block, $hal_mcp_report )
			: null;

		if ( null !== $hal_mcp_clean ) {
			$hal_mcp_clean_blocks[] = $hal_mcp_clean;
		}
	}

	$hal_mcp_report['unverified_names'] = array_keys( $hal_mcp_report['unverified_names'] );

	return [
		'markup' => (string) serialize_blocks( $hal_mcp_clean_blocks ),
		'report' => $hal_mcp_report,
	];
}

// ---------------------------------------------------------------------------
// Editor-serialization readiness (F21: needs_editor / ready, server-side)
// ---------------------------------------------------------------------------

/**
 * Whether a stored request is design-bearing and therefore requires a
 * real-editor serialization before approval (F21): a page-content change or
 * an Elementor design change proposed by the model. Posts are content, not
 * design, and never need the editor bridge.
 *
 * @param array<string, mixed> $request Request data (hal_mcp_get_change_request()).
 * @return bool
 */
function hal_mcp_blocks_request_is_design_bearing( array $request ): bool {

	$hal_mcp_targets = (array) ( $request['targets'] ?? [] );
	$hal_mcp_type    = (string) ( $hal_mcp_targets[0]['type'] ?? '' );

	if ( 'update-page-design' === (string) ( $request['operation'] ?? '' ) ) {
		return true;
	}

	if ( 'page' !== $hal_mcp_type ) {
		return false;
	}

	$hal_mcp_payload = (array) ( $request['payload'] ?? [] );

	return '' !== trim( (string) ( $hal_mcp_payload['content'] ?? '' ) );
}

/**
 * Derives the readiness state from the STORED request data — the server-side
 * derivation point. Never reads any model/browser-provided readiness value:
 * none exists in the input surface at all.
 *
 * @param array<string, mixed> $request Request data.
 * @return string 'needs_editor' for design-bearing requests, '' otherwise.
 */
function hal_mcp_blocks_derive_editor_readiness( array $request ): string {
	return hal_mcp_blocks_request_is_design_bearing( $request ) ? 'needs_editor' : '';
}

/**
 * Reads the request's editor-serialization readiness state. When the state
 * was never written, it is derived from the stored request and persisted, so
 * the approve() gate and every screen see the same derived truth.
 *
 * @param int  $request_id Request post ID.
 * @param bool $fresh      True to ignore (and re-derive over) any stored value.
 * @return string '' | 'needs_editor' | 'ready'.
 */
function hal_mcp_blocks_request_editor_readiness( int $request_id, bool $fresh = false ): string {

	$hal_mcp_stored = get_post_meta( $request_id, HAL_MCP_REQUEST_EDITOR_READINESS_KEY, true );

	if ( ! $fresh && is_string( $hal_mcp_stored ) && '' !== $hal_mcp_stored ) {
		return in_array( $hal_mcp_stored, [ 'needs_editor', 'ready' ], true ) ? $hal_mcp_stored : '';
	}

	$hal_mcp_request = hal_mcp_get_change_request( $request_id, true );

	if ( is_wp_error( $hal_mcp_request ) ) {
		return '';
	}

	$hal_mcp_derived = hal_mcp_blocks_derive_editor_readiness( $hal_mcp_request );

	if ( '' !== $hal_mcp_derived ) {
		hal_mcp_blocks_request_set_editor_readiness( $request_id, $hal_mcp_derived );
	} else {
		// The request stopped being design-bearing (e.g. its proposal was
		// replaced with a non-design payload): a stored 'needs_editor'/'ready'
		// from its previous shape must never outlive it. Storing '' is
		// exactly the meta deletion — clear the stale value.
		hal_mcp_blocks_request_set_editor_readiness( $request_id, '' );
	}

	return $hal_mcp_derived;
}

/**
 * Writes the readiness state (server-side only — there is no input surface
 * that reaches this from a model or the browser). Returns false when the
 * value is invalid or the write cannot be proven.
 *
 * @param int    $request_id Request post ID.
 * @param string $readiness  '' | 'needs_editor' | 'ready'.
 * @return bool
 */
function hal_mcp_blocks_request_set_editor_readiness( int $request_id, string $readiness ): bool {

	if ( ! in_array( $readiness, [ '', 'needs_editor', 'ready' ], true ) ) {
		return false;
	}

	if ( '' === $readiness ) {
		delete_post_meta( $request_id, HAL_MCP_REQUEST_EDITOR_READINESS_KEY );
		return true;
	}

	update_post_meta( $request_id, HAL_MCP_REQUEST_EDITOR_READINESS_KEY, $readiness );

	return get_post_meta( $request_id, HAL_MCP_REQUEST_EDITOR_READINESS_KEY, true ) === $readiness;
}

/**
 * Re-derives readiness after a payload change (hooked to
 * hal_mcp_change_request_payload_updated, F21: أي تعديل لاحق يلغي
 * الجاهزية). A stored 'ready' over a changed payload is stale by
 * definition — the derivation below overwrites it.
 *
 * @param int $request_id Request post ID.
 * @return void
 */
function hal_mcp_blocks_reset_editor_readiness( int $request_id ): void {
	hal_mcp_blocks_request_editor_readiness( $request_id, true );
}

add_action( 'hal_mcp_change_request_payload_updated', 'hal_mcp_blocks_reset_editor_readiness' );

// ---------------------------------------------------------------------------
// Editor-bridge ingestion (the F19 admin route lands here)
// ---------------------------------------------------------------------------

/**
 * Ingests one editor.js serialization result for a design request (F21:
 * admin.php within F19 receives the result through a protected route bound
 * to request ID / target / site / editor and the request-version
 * fingerprint; server-side revalidation of permission and schema happens
 * HERE — the route must only be the transport).
 *
 * Contract enforced:
 * - the request must exist, be viewable by the current user, and not be
 *   applying/applied (a rejected/failed/conflict request may be revived
 *   here through the payload-update contract's own state transitions —
 *   that revival voids any previous outcome by design and the request
 *   returns to pending for fresh approval);
 * - the request must be a design-bearing 'update-page' request — the
 *   bridge NEVER touches any other operation (an 'update-page-design'
 *   Elementor request has no block-markup payload, and content requests on
 *   other types are not design work);
 * - the stored proposal is MERGED, never replaced: the bridge replaces ONLY
 *   the content value (with the sanitized serialization) and sets the
 *   serialization report, preserving every other field of the proposal
 *   (title, template, featured image, requested_status, ...) — the normal
 *   atomic update-page request keeps all of its fields, and nothing
 *   evaporates through the wholesale payload replacement;
 * - the caller must hold the page's own edit capability (object-level, the
 *   same check any write path faces);
 * - the request-version fingerprint in the input must equal the STORED
 *   proposed fingerprint — a result for a stale or edited request version
 *   is refused;
 * - the markup is re-sanitized server-side; the sanitized serialization and
 *   its fingerprint are stored through hal_mcp_update_change_request_payload()
 *   (which resets the request to pending and voids any previous approval);
 * - only then does the readiness move to 'ready' — an approval before that
 *   is refused by the change-requests gate.
 *
 * @param int                  $request_id Request post ID.
 * @param array<string, mixed> $input      { request_fingerprint: string,
 *                                          markup: string }.
 * @return array<string, mixed>|WP_Error { request_id, state, readiness,
 *              proposed_fingerprint, report }
 */
function hal_mcp_blocks_ingest_editor_serialization( int $request_id, array $input ) {

	// The page domain functions (hal_mcp_page_read_current) live in the
	// ability layer, which the bootstrap loads only when the Abilities API
	// is present — without them no ingest path can work honestly.
	if ( ! function_exists( 'hal_mcp_page_read_current' ) ) {
		return new WP_Error(
			'hal_mcp_bridge_unavailable',
			__( 'The page ability modules are not loaded, so editor serialization cannot be validated.', 'hal-mcp' )
		);
	}

	$hal_mcp_request = hal_mcp_get_change_request( $request_id, true );

	if ( is_wp_error( $hal_mcp_request ) ) {
		return $hal_mcp_request;
	}

	if ( ! hal_mcp_user_can_view_request( $hal_mcp_request ) ) {
		return new WP_Error( 'hal_mcp_forbidden', __( 'You may not update this change request.', 'hal-mcp' ) );
	}

	if ( in_array( $hal_mcp_request['state'], [ 'applying', 'applied' ], true ) ) {
		return new WP_Error(
			'hal_mcp_invalid_state',
			__( 'This request has already been applied; create a new request instead.', 'hal-mcp' )
		);
	}

	// Operation guard: the bridge exists for the block-markup design of ONE
	// page request — nothing else may be rewritten through it.
	if ( 'update-page' !== (string) $hal_mcp_request['operation']
		|| ! hal_mcp_blocks_request_is_design_bearing( $hal_mcp_request ) ) {
		return new WP_Error(
			'hal_mcp_bridge_target_mismatch',
			__( 'The editor bridge serves block-markup page design requests only.', 'hal-mcp' )
		);
	}

	$hal_mcp_targets   = (array) ( $hal_mcp_request['targets'] ?? [] );
	$hal_mcp_type      = (string) ( $hal_mcp_targets[0]['type'] ?? '' );
	$hal_mcp_target_id = (int) ( $hal_mcp_targets[0]['id'] ?? 0 );

	if ( 'page' !== $hal_mcp_type || $hal_mcp_target_id < 1 ) {
		return new WP_Error(
			'hal_mcp_bridge_target_mismatch',
			__( 'The editor bridge serves page design requests only.', 'hal-mcp' )
		);
	}

	if ( ! hal_mcp_permission( 'page', 'edit', $hal_mcp_target_id, [ 'ability' => 'hal/editor-bridge', 'reason' => 'editor_bridge' ] ) ) {
		return new WP_Error( 'hal_mcp_forbidden_object', __( 'You are not allowed to edit this page.', 'hal-mcp' ) );
	}

	// Request-version fingerprint: the bridge must answer for exactly the
	// request version it was opened against — a stale or different request
	// is refused, never silently adopted.
	$hal_mcp_version_fingerprint = trim( (string) ( $input['request_fingerprint'] ?? '' ) );

	if ( '' === $hal_mcp_version_fingerprint
		|| ! hash_equals( (string) $hal_mcp_request['proposed_fingerprint'], $hal_mcp_version_fingerprint ) ) {
		hal_mcp_request_audit(
			$request_id,
			(string) $hal_mcp_request['operation'],
			'bridge_fingerprint_mismatch',
			false,
			'editor serialization refused: request-version fingerprint mismatch',
			'admin'
		);

		return new WP_Error(
			'hal_mcp_bridge_fingerprint_mismatch',
			__( 'The editor result does not match the current request version. Reopen the request and try again.', 'hal-mcp' )
		);
	}

	$hal_mcp_sanitized = hal_mcp_blocks_sanitize_markup( (string) ( $input['markup'] ?? '' ) );

	if ( is_wp_error( $hal_mcp_sanitized ) ) {
		return $hal_mcp_sanitized;
	}

	// MERGE semantics (the normal atomic update-page request): the NEW
	// payload is the request's EXISTING proposal with ONLY the content
	// value replaced by the sanitized serialization and the serialization
	// report block set — every other field (title, requested_status, ...)
	// is preserved, so nothing evaporates through the wholesale payload
	// replacement inside hal_mcp_update_change_request_payload().
	$hal_mcp_existing_payload = (array) ( $hal_mcp_request['payload'] ?? [] );

	$hal_mcp_new_payload                  = $hal_mcp_existing_payload;
	$hal_mcp_new_payload['content']       = $hal_mcp_sanitized['markup'];
	$hal_mcp_new_payload['serialization'] = [
		'source' => 'editor_bridge',
		'report' => $hal_mcp_sanitized['report'],
	];

	// Fresh snapshot of every ORIGINAL field the new payload replaces (F17
	// contract: a payload edit carries a fresh original snapshot). The
	// status change is not an original field and the serialization report is
	// bridge metadata, not page state — both are excluded; an empty field
	// list (only a status change remaining) snapshots nothing.
	$hal_mcp_snapshot_fields = array_values( array_diff( array_keys( $hal_mcp_new_payload ), [ 'requested_status', 'serialization' ] ) );

	$hal_mcp_snapshot = empty( $hal_mcp_snapshot_fields )
		? []
		: (array) ( hal_mcp_page_read_current( $hal_mcp_target_id, $hal_mcp_snapshot_fields ) ?? [] );

	$hal_mcp_updated = hal_mcp_update_change_request_payload(
		$request_id,
		$hal_mcp_new_payload,
		$hal_mcp_snapshot
	);

	if ( is_wp_error( $hal_mcp_updated ) ) {
		return $hal_mcp_updated;
	}

	// The sanitized final version and its fingerprint are now stored — the
	// readiness may move to ready (the payload-update hook above re-derived
	// it to needs_editor; setting it here is the ONE server-side
	// transition). The response reports the state that was PROVEN stored,
	// never an intended one.
	hal_mcp_blocks_request_set_editor_readiness( $request_id, 'ready' );

	hal_mcp_request_audit(
		$request_id,
		(string) $hal_mcp_request['operation'],
		'bridge_serialization_stored',
		true,
		'editor serialization validated and stored',
		'admin'
	);

	return [
		'request_id'           => $request_id,
		'state'                => hal_mcp_request_read_status( $request_id ),
		'readiness'            => hal_mcp_blocks_request_editor_readiness( $request_id ),
		'proposed_fingerprint' => (string) $hal_mcp_updated['proposed_fingerprint'],
		'report'               => $hal_mcp_sanitized['report'],
	];
}

// ---------------------------------------------------------------------------
// Registration
// ---------------------------------------------------------------------------

/**
 * Registers the block-editor integrations (runs once, when this file is
 * required by the integration loader at plugins_loaded priority 5 — before
 * the core stand-in registration at priority 6, whose 'gutenberg' entry then
 * stands down).
 *
 * Two entries, each with its own meaning:
 * - 'gutenberg': the block-editor SURFACE (read-only operation). Detection
 *   is LAZY on purpose: this runs at plugins_loaded, when the block
 *   registry is still EMPTY (block types register at init), so no snapshot
 *   is taken here — block types/attributes are enumerated at READ time via
 *   hal_mcp_blocks_registry_snapshot(), once the registry is full. The
 *   version is the WordPress core version the editor ships in — there is
 *   no other versioned source for core blocks.
 * - 'blocks': the page-design integration (block markup as the design
 *   source of a page). Its write operation is the model- and bridge-facing
 *   content write the page abilities and the F19 route flow into; the
 *   object-level authorization stays in permissions.php as always.
 *
 * @return void
 */
function hal_mcp_blocks_register_integration(): void {

	hal_mcp_register_integration(
		'gutenberg',
		[
			'label'      => __( 'WordPress block editor (Gutenberg)', 'hal-mcp' ),
			'version'    => (string) get_bloginfo( 'version' ),
			'source'     => 'core',
			'available'  => class_exists( 'WP_Block_Type_Registry' ),
			'operations' => [
				'read_blocks' => [
					'effect'     => 'read',
					'capability' => 'edit_posts',
					'writable'   => [],
				],
			],
			'notes'      => __( 'Block types and their attributes are enumerated at read time from the live block registry via hal_mcp_blocks_registry_snapshot() — the registry fills at init, so nothing is baked in here. No namespace is assumed, and save() output is never reproduced from attributes.', 'hal-mcp' ),
		]
	);

	// The page-design integration the environment's 'editors' area reads.
	hal_mcp_register_integration(
		'blocks',
		[
			'label'      => __( 'Block markup page design (Gutenberg and block-based builders)', 'hal-mcp' ),
			'version'    => (string) get_bloginfo( 'version' ),
			'source'     => 'core',
			'available'  => true,
			'operations' => [
				'write_blocks' => [
					'effect'     => 'edit',
					'capability' => 'edit_pages',
					'writable'   => [ 'content' ],
				],
			],
			'notes'      => __( 'Block markup is the design source for block pages. Model-authored design requests require a real-editor serialization (needs_editor) before approval; the sanitized bridge result is re-fingerprinted server-side.', 'hal-mcp' ),
		]
	);
}

hal_mcp_blocks_register_integration();
