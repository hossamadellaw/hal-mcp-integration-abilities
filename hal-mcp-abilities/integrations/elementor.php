<?php
/**
 * hal-mcp-abilities — Elementor integration (F21).
 *
 * Elementor pages store their design as Elementor JSON (_elementor_data);
 * the F20 write abilities refuse to overwrite that JSON with flattened
 * markup. This integration is the design-source owner the F20 refusals
 * point to:
 *
 * 1. Detection. Runtime markers only: the Elementor loader action
 *    ('elementor/loaded') plus the version constant the plugin itself
 *    defines. A plugin name is never an availability claim.
 *
 * 2. Reading and saving through the versioned mechanism. Reads come from
 *    the stored design source itself (bounded, structure-validated). Saves
 *    go through the Elementor Document API with method-level guards and a
 *    Throwable wall — when the version's API does not expose the required
 *    methods, the save is REFUSED with an honest reason ("writing not
 *    verified for this version"), never approximated by editing cache
 *    files or replacing the page with flat HTML. After a successful save
 *    the generated CSS is refreshed through the files manager, guarded the
 *    same way (F21: التحديث عبر آلية Elementor المناسبة للإصدار).
 *
 * 3. Server-side sanitization of every design value before it can reach a
 *    proposal, preview, or fingerprint: element structure is whitelisted,
 *    settings values are kses-sanitized, URL-typed values go through
 *    esc_url_raw, ids and global references are preserved, and an element
 *    type (or, when the runtime is present, a widget type) that cannot be
 *    verified refuses the whole update — a partially understood design is
 *    never saved as if it were valid.
 *
 * The model never sends raw Elementor JSON through a tool: design changes
 * reach this integration through the change-request flow (operation
 * 'update-page-design', created by the F19 request flow), carry the
 * needs_editor readiness until their sanitized version is stored — the
 * real-editor result lands here through hal_mcp_elementor_ingest_editor_design(),
 * the Elementor half of the F21 editor bridge — and are applied only
 * through the registered apply handler below.
 *
 * @package hal-mcp-abilities
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Maximum number of elements walked in one sanitization/read pass.
 *
 * @var int
 */
const HAL_MCP_ELEMENTOR_MAX_ELEMENTS = 500;

/**
 * Maximum element nesting depth the sanitizer walks.
 *
 * @var int
 */
const HAL_MCP_ELEMENTOR_MAX_DEPTH = 15;

/**
 * Elementor element types this integration understands. Anything else is
 * refused on save (and reported on read) — a future Elementor element type
 * must be added here deliberately, after its handling is verified.
 *
 * @var string[]
 */
const HAL_MCP_ELEMENTOR_ELEMENT_TYPES = [ 'section', 'column', 'container', 'widget' ];

/**
 * The element keys preserved on sanitize. Everything else is dropped and
 * reported — Elementor's data structure (developers.elementor.com) carries
 * identity (id), type (elType/widgetType), content (settings), tree
 * (elements), and layout flags (isInner). Nothing else has a verified
 * meaning here.
 *
 * @var string[]
 */
const HAL_MCP_ELEMENTOR_ALLOWED_KEYS = [ 'id', 'elType', 'widgetType', 'settings', 'elements', 'isInner' ];

/**
 * Whether Elementor is actually present right now (runtime markers only).
 *
 * @return bool
 */
function hal_mcp_elementor_present(): bool {
	return did_action( 'elementor/loaded' ) && defined( 'ELEMENTOR_VERSION' );
}

/**
 * The Elementor Document object for a page, when the runtime exposes the
 * documents manager (guarded per method — the API surface is versioned and
 * this code must degrade to an honest refusal, not a fatal).
 *
 * @param int $page_id Page ID.
 * @return object|null The document, or null when the API is unavailable.
 */
function hal_mcp_elementor_document( int $page_id ): ?object {

	if ( ! hal_mcp_elementor_present() || ! class_exists( '\Elementor\Plugin' ) ) {
		return null;
	}

	try {
		$hal_mcp_plugin = \Elementor\Plugin::$instance ?? null;
	} catch ( \Throwable $hal_mcp_throwable ) {
		return null;
	}

	if ( ! $hal_mcp_plugin
		|| ! isset( $hal_mcp_plugin->documents )
		|| ! is_object( $hal_mcp_plugin->documents )
		|| ! method_exists( $hal_mcp_plugin->documents, 'get' ) ) {
		return null;
	}

	try {
		$hal_mcp_document = $hal_mcp_plugin->documents->get( $page_id );
	} catch ( \Throwable $hal_mcp_throwable ) {
		return null;
	}

	return is_object( $hal_mcp_document ) ? $hal_mcp_document : null;
}

// ---------------------------------------------------------------------------
// Server-side sanitization of Elementor structures
// ---------------------------------------------------------------------------

/**
 * Sanitizes one settings value. Strings go through wp_kses_post, URL-typed
 * keys (anything ending in 'url') through esc_url_raw, arrays recurse with
 * the depth wall, scalars pass, everything else is dropped and reported.
 *
 * @param mixed  $value    Value to sanitize.
 * @param string $key      The settings key it sits under.
 * @param array  $report   Sanitization report (by reference).
 * @param int    $depth    Current depth.
 * @return mixed Sanitized value, or null when dropped.
 */
function hal_mcp_elementor_sanitize_setting( $value, string $key, array &$report, int $depth = 0 ) {

	if ( is_array( $value ) ) {
		if ( $depth > HAL_MCP_ELEMENTOR_MAX_DEPTH ) {
			++$report['dropped_values'];
			return null;
		}

		$hal_mcp_clean = [];

		foreach ( $value as $hal_mcp_child_key => $hal_mcp_child ) {
			$hal_mcp_clean[ (string) $hal_mcp_child_key ] = hal_mcp_elementor_sanitize_setting(
				$hal_mcp_child,
				(string) $hal_mcp_child_key,
				$report,
				$depth + 1
			);
		}

		return $hal_mcp_clean;
	}

	if ( is_string( $value ) ) {
		$hal_mcp_is_url = (bool) preg_match( '/url$/', strtolower( $key ) );

		$hal_mcp_clean = $hal_mcp_is_url
			? esc_url_raw( $value )
			: wp_kses_post( $value );

		if ( $hal_mcp_clean !== $value ) {
			++$report['sanitized_values'];
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
 * Sanitizes one Elementor element recursively. Keys outside the whitelist
 * are dropped (and reported); ids and global references inside settings are
 * preserved (they are values under whitelisted keys, sanitized by type).
 *
 * @param array $element Element structure.
 * @param array $report  Sanitization report (by reference).
 * @param int   $depth   Current depth.
 * @return array|null The sanitized element, or null when it must be dropped.
 */
function hal_mcp_elementor_sanitize_element( array $element, array &$report, int $depth = 0 ): ?array {

	if ( $depth > HAL_MCP_ELEMENTOR_MAX_DEPTH || $report['elements'] >= HAL_MCP_ELEMENTOR_MAX_ELEMENTS ) {
		++$report['dropped_elements'];
		return null;
	}

	++$report['elements'];

	$hal_mcp_el_type = sanitize_key( (string) ( $element['elType'] ?? '' ) );

	if ( '' === $hal_mcp_el_type || ! in_array( $hal_mcp_el_type, HAL_MCP_ELEMENTOR_ELEMENT_TYPES, true ) ) {
		// An element type this integration does not understand: refuse the
		// whole update at the caller level — the report records it, the
		// caller decides (save refuses; read reports).
		$report['unverified_types'][ $hal_mcp_el_type ?: '(empty)' ] = true;
		++$report['dropped_elements'];
		return null;
	}

	$hal_mcp_id = sanitize_text_field( substr( (string) ( $element['id'] ?? '' ), 0, 32 ) );

	if ( ! preg_match( '/^[a-f0-9]{5,32}$/i', $hal_mcp_id ) ) {
		// Elementor generates its element ids in exactly this shape; anything
		// else is not an id this integration can verify — treated EXACTLY
		// like an unverifiable element type (save refuses; read reports and
		// excludes). A hostile "id" can therefore never survive into a
		// stored design.
		$report['unverified_types'][ 'id:' . $hal_mcp_id ] = true;
		++$report['dropped_elements'];
		return null;
	}

	$hal_mcp_clean = [
		'id'     => $hal_mcp_id,
		'elType' => $hal_mcp_el_type,
	];

	if ( 'widget' === $hal_mcp_el_type ) {
		$hal_mcp_widget = sanitize_key( (string) ( $element['widgetType'] ?? '' ) );

		if ( '' === $hal_mcp_widget ) {
			$report['unverified_types']['(widget without type)'] = true;
			++$report['dropped_elements'];
			return null;
		}

		$hal_mcp_widget_availability = hal_mcp_elementor_widget_available( $hal_mcp_widget );

		if ( false === $hal_mcp_widget_availability ) {
			// Runtime present and the widget is NOT registered: an edit
			// depending on it can never be valid — refuse.
			$report['unverified_types'][ 'widget:' . $hal_mcp_widget ] = true;
			++$report['dropped_elements'];
			return null;
		}

		// null (runtime absent — availability unverifiable) is reported; the
		// save path refuses anyway because it needs the runtime, and a read
		// keeps the element with the report attached.
		$hal_mcp_clean['widgetType'] = $hal_mcp_widget;
	}

	if ( isset( $element['isInner'] ) ) {
		$hal_mcp_clean['isInner'] = (bool) $element['isInner'];
	}

	if ( isset( $element['settings'] ) && is_array( $element['settings'] ) ) {
		$hal_mcp_clean_settings = [];

		foreach ( $element['settings'] as $hal_mcp_key => $hal_mcp_value ) {
			$hal_mcp_key = (string) $hal_mcp_key;

			if ( '' === $hal_mcp_key || strlen( $hal_mcp_key ) > 128 ) {
				++$report['dropped_values'];
				continue;
			}

			$hal_mcp_clean_settings[ $hal_mcp_key ] = hal_mcp_elementor_sanitize_setting( $hal_mcp_value, $hal_mcp_key, $report );
		}

		$hal_mcp_clean['settings'] = $hal_mcp_clean_settings;
	}

	if ( isset( $element['elements'] ) && is_array( $element['elements'] ) ) {
		$hal_mcp_clean_children = [];

		foreach ( $element['elements'] as $hal_mcp_child ) {
			$hal_mcp_clean_child = is_array( $hal_mcp_child )
				? hal_mcp_elementor_sanitize_element( $hal_mcp_child, $report, $depth + 1 )
				: null;

			if ( null !== $hal_mcp_clean_child ) {
				$hal_mcp_clean_children[] = $hal_mcp_clean_child;
			}
		}

		$hal_mcp_clean['elements'] = $hal_mcp_clean_children;
	}

	return $hal_mcp_clean;
}

/**
 * Whether a widget type is registered in the Elementor runtime. Returns
 * null when the runtime is absent (availability unverifiable) — never a
 * silent yes.
 *
 * @param string $widget_type Widget slug, e.g. 'heading'.
 * @return bool|null
 */
function hal_mcp_elementor_widget_available( string $widget_type ): ?bool {

	if ( ! hal_mcp_elementor_present() || ! class_exists( '\Elementor\Plugin' ) ) {
		return null;
	}

	try {
		$hal_mcp_plugin = \Elementor\Plugin::$instance ?? null;
	} catch ( \Throwable $hal_mcp_throwable ) {
		return null;
	}

	if ( ! $hal_mcp_plugin
		|| ! isset( $hal_mcp_plugin->widgets_manager )
		|| ! is_object( $hal_mcp_plugin->widgets_manager )
		|| ! method_exists( $hal_mcp_plugin->widgets_manager, 'get_widget_types' ) ) {
		return null;
	}

	try {
		$hal_mcp_widgets = $hal_mcp_plugin->widgets_manager->get_widget_types();
	} catch ( \Throwable $hal_mcp_throwable ) {
		return null;
	}

	if ( ! is_array( $hal_mcp_widgets ) ) {
		return null;
	}

	foreach ( $hal_mcp_widgets as $hal_mcp_widget ) {
		if ( is_object( $hal_mcp_widget ) && method_exists( $hal_mcp_widget, 'get_name' )
			&& $hal_mcp_widget->get_name() === $widget_type ) {
			return true;
		}
	}

	return false;
}

/**
 * THE server-side sanitization point for an Elementor elements array (F21:
 * تنقيح خادمي لكل قيمة متغيرة قبل حفظ المقترح والمعاينة وحساب بصمته).
 *
 * Two modes by design: the SAVE path (default) REFUSES when any element
 * carries a type (or widget) that cannot be verified — a partially
 * understood design is never saved. The READ path passes
 * $refuse_unverified = false: unverifiable elements are dropped from the
 * returned structure and NAMED in the report, so a live design built with a
 * future element type stays readable and honestly labeled instead of
 * invisible.
 *
 * @param array<int, mixed> $elements           Raw elements array.
 * @param bool              $refuse_unverified  True (save path) to refuse on
 *                                              unverifiable element types;
 *                                              false (read path) to report
 *                                              and exclude them.
 * @return array{
 *   elements: array<int, array<string, mixed>>,
 *   report: array{elements: int, dropped_elements: int, sanitized_values: int,
 *                 dropped_values: int, unverified_types: string[]}
 * }|WP_Error
 */
function hal_mcp_elementor_sanitize_elements( array $elements, bool $refuse_unverified = true ) {

	$hal_mcp_report = [
		'elements'          => 0,
		'dropped_elements'  => 0,
		'sanitized_values'  => 0,
		'dropped_values'    => 0,
		'unverified_types'  => [],
	];

	$hal_mcp_clean = [];

	foreach ( $elements as $hal_mcp_element ) {
		$hal_mcp_clean_element = is_array( $hal_mcp_element )
			? hal_mcp_elementor_sanitize_element( $hal_mcp_element, $hal_mcp_report )
			: null;

		if ( null !== $hal_mcp_clean_element ) {
			$hal_mcp_clean[] = $hal_mcp_clean_element;
		}
	}

	$hal_mcp_report['unverified_types'] = array_values( array_unique( array_map( 'strval', array_keys( $hal_mcp_report['unverified_types'] ) ) ) );

	if ( $refuse_unverified && ! empty( $hal_mcp_report['unverified_types'] ) ) {
		return new WP_Error(
			'hal_mcp_elementor_unverified_elements',
			sprintf(
				/* translators: %s: comma-separated list of unverifiable element or widget types. */
				__( 'The design contains elements this integration cannot verify and was not saved: %s.', 'hal-mcp' ),
				implode( ', ', $hal_mcp_report['unverified_types'] )
			)
		);
	}

	if ( empty( $hal_mcp_clean ) && ! empty( $elements ) ) {
		return new WP_Error(
			'hal_mcp_elementor_no_elements_survived',
			__( 'No element of the design survived validation; nothing was saved.', 'hal-mcp' )
		);
	}

	return [
		'elements' => $hal_mcp_clean,
		'report'   => $hal_mcp_report,
	];
}

// ---------------------------------------------------------------------------
// Read / save through the versioned mechanism
// ---------------------------------------------------------------------------

/**
 * Reads a page's Elementor design source (bounded, structure-validated).
 * The stored design is the source of truth; when the Elementor runtime and
 * its document API are present they are used, and otherwise the stored
 * design is read directly — reading never requires the runtime, saving does.
 *
 * @param int $page_id Page ID.
 * @return array{
 *   elements: array<int, array<string, mixed>>,
 *   report: array<string, mixed>,
 *   read_via: string
 * }|WP_Error
 */
function hal_mcp_elementor_read_design( int $page_id ) {

	if ( $page_id < 1 ) {
		return new WP_Error( 'hal_mcp_invalid_page_id', __( 'page_id must be a positive integer.', 'hal-mcp' ) );
	}

	$hal_mcp_document = hal_mcp_elementor_document( $page_id );

	if ( null !== $hal_mcp_document && method_exists( $hal_mcp_document, 'get_elements_data' ) ) {
		try {
			$hal_mcp_elements = $hal_mcp_document->get_elements_data();
		} catch ( \Throwable $hal_mcp_throwable ) {
			$hal_mcp_elements = null;
		}

		if ( is_array( $hal_mcp_elements ) ) {
			// Read mode: unverifiable element types are reported and
			// excluded, never a refusal — only a WRITE refuses on them.
			$hal_mcp_sanitized = hal_mcp_elementor_sanitize_elements( $hal_mcp_elements, false );

			if ( is_wp_error( $hal_mcp_sanitized ) ) {
				return $hal_mcp_sanitized;
			}

			return [
				'elements' => $hal_mcp_sanitized['elements'],
				'report'   => $hal_mcp_sanitized['report'],
				'read_via' => 'document_api',
			];
		}
	}

	$hal_mcp_stored = hal_mcp_elementor_read_stored_design( $page_id );

	if ( is_wp_error( $hal_mcp_stored ) ) {
		return $hal_mcp_stored;
	}

	$hal_mcp_stored['read_via'] = 'stored_design';

	return $hal_mcp_stored;
}

/**
 * Reads and validates the stored design source for a page (the _elementor_data
 * meta the F12 identity check reads). Empty or corrupted storage is an
 * honest not-found, not a design.
 *
 * @param int $page_id Page ID.
 * @return array{elements: array<int, array<string, mixed>>, report: array<string, mixed>}|WP_Error
 */
function hal_mcp_elementor_read_stored_design( int $page_id ) {

	$hal_mcp_raw = (string) get_post_meta( $page_id, '_elementor_data', true );

	if ( '' === trim( $hal_mcp_raw ) ) {
		return new WP_Error(
			'hal_mcp_elementor_design_not_found',
			__( 'No stored Elementor design was found for this page.', 'hal-mcp' )
		);
	}

	$hal_mcp_decoded = json_decode( $hal_mcp_raw, true );

	if ( ! is_array( $hal_mcp_decoded ) || empty( $hal_mcp_decoded ) ) {
		return new WP_Error(
			'hal_mcp_elementor_design_corrupted',
			__( 'The stored Elementor design could not be decoded; it was not interpreted or modified.', 'hal-mcp' )
		);
	}

	// Read mode: unverifiable element types are reported and excluded, never
	// a refusal — only a WRITE refuses on them.
	$hal_mcp_sanitized = hal_mcp_elementor_sanitize_elements( $hal_mcp_decoded, false );

	if ( is_wp_error( $hal_mcp_sanitized ) ) {
		return $hal_mcp_sanitized;
	}

	return [
		'elements' => $hal_mcp_sanitized['elements'],
		'report'   => $hal_mcp_sanitized['report'],
	];
}

/**
 * Saves an Elementor design for a page through the versioned mechanism
 * (F21: قراءة وحفظ document data وفق API/مصدر الإصدار). Requires the
 * Elementor runtime and its Document API; when the version's API does not
 * expose the required methods the save is REFUSED with an honest reason —
 * no cache-file editing, no flat-HTML replacement, no best-effort guess.
 *
 * Deliberate, documented size limit: the request payload store
 * (hal_mcp_sanitize_request_payload() in includes/change-requests.php)
 * refuses arrays nested deeper than 8, so an Elementor design whose nesting
 * exceeds that (deep container trees plus their settings subtrees) passes
 * sanitization here but is honestly refused at request creation with
 * hal_mcp_invalid_payload — a limit to revisit when F19 owns design
 * previews.
 *
 * @param int               $page_id  Page ID.
 * @param array<int, mixed> $elements Elements array (ALREADY sanitized —
 *                                    callers must go through
 *                                    hal_mcp_elementor_sanitize_elements();
 *                                    this function re-sanitizes defensively).
 * @return array{saved: bool, page_id: int, report: array<string, mixed>}|WP_Error
 */
function hal_mcp_elementor_apply_design( int $page_id, array $elements ) {

	if ( ! hal_mcp_elementor_present() ) {
		return new WP_Error(
			'hal_mcp_elementor_unavailable',
			__( 'Elementor is not active on this site; its designs cannot be read or saved through this integration.', 'hal-mcp' )
		);
	}

	$hal_mcp_document = hal_mcp_elementor_document( $page_id );

	if ( null === $hal_mcp_document || ! method_exists( $hal_mcp_document, 'save' ) ) {
		return new WP_Error(
			'hal_mcp_elementor_save_unavailable',
			__( 'The installed Elementor version does not expose the document save API this integration was verified against; the design was NOT saved.', 'hal-mcp' )
		);
	}

	$hal_mcp_sanitized = hal_mcp_elementor_sanitize_elements( $elements );

	if ( is_wp_error( $hal_mcp_sanitized ) ) {
		return $hal_mcp_sanitized;
	}

	try {
		$hal_mcp_result = $hal_mcp_document->save( [ 'elements' => $hal_mcp_sanitized['elements'] ] );
	} catch ( \Throwable $hal_mcp_throwable ) {
		return new WP_Error(
			'hal_mcp_elementor_save_failed',
			__( 'The Elementor document save failed; the design was not changed.', 'hal-mcp' )
		);
	}

	if ( false === $hal_mcp_result || ( is_wp_error( $hal_mcp_result ) ) ) {
		return new WP_Error(
			'hal_mcp_elementor_save_failed',
			__( 'The Elementor document save reported a failure; the design was not changed.', 'hal-mcp' )
		);
	}

	// Refresh the generated CSS through Elementor's own files manager — the
	// versioned mechanism for regenerating design output. Guarded; failure
	// here leaves the saved design in place with stale generated CSS and is
	// reported, not hidden.
	$hal_mcp_css_refreshed = false;

	try {
		$hal_mcp_plugin = \Elementor\Plugin::$instance ?? null;

		if ( $hal_mcp_plugin
			&& isset( $hal_mcp_plugin->files_manager )
			&& is_object( $hal_mcp_plugin->files_manager )
			&& method_exists( $hal_mcp_plugin->files_manager, 'clear_cache' ) ) {
			$hal_mcp_plugin->files_manager->clear_cache();
			$hal_mcp_css_refreshed = true;
		}
	} catch ( \Throwable $hal_mcp_throwable ) {
		$hal_mcp_css_refreshed = false;
	}

	$hal_mcp_sanitized['report']['css_refreshed'] = $hal_mcp_css_refreshed;

	return [
		'saved'    => true,
		'page_id'  => $page_id,
		'report'   => $hal_mcp_sanitized['report'],
	];
}

/**
 * Live fingerprint revalidation for 'update-page-design' requests (F17
 * contract: fingerprint the CURRENT stored design, never the snapshot the
 * request carries). The fingerprint covers the RAW stored design — the
 * complete original, including any element type this version cannot verify,
 * so a change anywhere in the design is detected.
 *
 * @param array<string, mixed> $request Request data.
 * @return string '' when the design cannot be read (apply-time conflict).
 */
function hal_mcp_elementor_revalidate_fingerprint( array $request ): string {

	$hal_mcp_targets  = (array) ( $request['targets'] ?? [] );
	$hal_mcp_page_id  = (int) ( $hal_mcp_targets[0]['id'] ?? 0 );

	if ( $hal_mcp_page_id < 1 ) {
		return '';
	}

	$hal_mcp_raw = (string) get_post_meta( $hal_mcp_page_id, '_elementor_data', true );

	if ( '' === trim( $hal_mcp_raw ) ) {
		return '';
	}

	$hal_mcp_decoded = json_decode( $hal_mcp_raw, true );

	if ( ! is_array( $hal_mcp_decoded ) ) {
		return '';
	}

	return hal_mcp_fingerprint( $hal_mcp_decoded );
}

/**
 * Registers the 'update-page-design' apply handler (F17). The handler
 * applies ONLY the stored, sanitized payload through the domain function —
 * never an alternate payload, per the shared change-request contract.
 *
 * @return void
 */
function hal_mcp_elementor_register_apply_handler(): void {

	hal_mcp_register_apply_handler(
		'update-page-design',
		static function ( array $request ) {
			$hal_mcp_targets  = (array) ( $request['targets'] ?? [] );
			$hal_mcp_page_id  = (int) ( $hal_mcp_targets[0]['id'] ?? 0 );
			$hal_mcp_payload  = (array) ( $request['payload'] ?? [] );

			if ( ! isset( $hal_mcp_payload['elements'] ) || ! is_array( $hal_mcp_payload['elements'] ) ) {
				return new WP_Error(
					'hal_mcp_elementor_payload_invalid',
					__( 'The stored design payload is malformed; nothing was applied.', 'hal-mcp' )
				);
			}

			$hal_mcp_result = hal_mcp_elementor_apply_design( $hal_mcp_page_id, $hal_mcp_payload['elements'] );

			if ( is_wp_error( $hal_mcp_result ) ) {
				return $hal_mcp_result;
			}

			return sprintf(
				/* translators: 1: page ID. 2: number of top-level elements. */
				__( 'Elementor design for page #%1$d saved (%2$d top-level elements).', 'hal-mcp' ),
				$hal_mcp_result['page_id'],
				count( $hal_mcp_payload['elements'] )
			);
		},
		'hal_mcp_elementor_revalidate_fingerprint'
	);
}

hal_mcp_elementor_register_apply_handler();

// ---------------------------------------------------------------------------
// Editor-bridge ingestion (the F19 admin route lands here)
// ---------------------------------------------------------------------------

/**
 * Ingests one editor.js design result for an Elementor design request (F21:
 * admin.php within F19 receives the result through a protected route bound
 * to request ID / target / site / editor and the request-version
 * fingerprint; server-side revalidation of permission and schema happens
 * HERE — the route must only be the transport). This is the Elementor half
 * of the bridge contract, mirroring
 * hal_mcp_blocks_ingest_editor_serialization() exactly.
 *
 * Contract enforced:
 * - the request must exist, be viewable by the current user, and not be
 *   applying/applied (a rejected/failed/conflict request may be revived
 *   here through the payload-update contract's own state transitions —
 *   that revival voids any previous outcome by design and the request
 *   returns to pending for fresh approval);
 * - the request must be exactly an 'update-page-design' request — the
 *   bridge NEVER touches any other operation (block-markup design goes
 *   through the blocks bridge; content requests are not design work);
 * - the stored payload must carry nothing beyond the design fields
 *   (elements/serialization): a request that also proposes other changes
 *   is refused here rather than silently rewritten;
 * - the first target must be a page with a positive ID, and the caller
 *   must hold the page's own edit capability (object-level, the same check
 *   any write path faces);
 * - the request-version fingerprint in the input must equal the STORED
 *   proposed fingerprint — a result for a stale or edited request version
 *   is refused (with an audit row);
 * - the elements are re-sanitized server-side in the SAVE mode: an element
 *   type, widget, or id that cannot be verified refuses the whole result;
 * - the sanitized elements and the report are stored through
 *   hal_mcp_update_change_request_payload() with an EMPTY original
 *   snapshot — a design request fingerprints the RAW stored design through
 *   live revalidation (the create pattern), so there is no field snapshot
 *   to refresh — which resets the request to pending and voids any
 *   previous approval;
 * - only then does the readiness move to 'ready' — an approval before that
 *   is refused by the change-requests gate.
 *
 * @param int                  $request_id Request post ID.
 * @param array<string, mixed> $input      { request_fingerprint: string,
 *                                          elements: array }.
 * @return array<string, mixed>|WP_Error { request_id, state, readiness,
 *              proposed_fingerprint, report }
 */
function hal_mcp_elementor_ingest_editor_design( int $request_id, array $input ) {

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

	// Operation guard: this bridge exists for the Elementor design of ONE
	// page request — nothing else may be rewritten through it.
	if ( 'update-page-design' !== (string) $hal_mcp_request['operation'] ) {
		return new WP_Error(
			'hal_mcp_bridge_target_mismatch',
			__( 'The editor bridge serves Elementor page-design requests only.', 'hal-mcp' )
		);
	}

	$hal_mcp_existing_payload = (array) ( $hal_mcp_request['payload'] ?? [] );

	if ( ! empty( array_diff_key( $hal_mcp_existing_payload, [ 'elements' => true, 'serialization' => true ] ) ) ) {
		return new WP_Error(
			'hal_mcp_elementor_bridge_payload_has_other_fields',
			__( 'This request also carries fields other than the page design; replacing its payload from the editor would drop them. Re-create it as a design-only request or edit it from the request panel.', 'hal-mcp' )
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

	$hal_mcp_input_elements = $input['elements'] ?? null;

	if ( ! is_array( $hal_mcp_input_elements ) || empty( $hal_mcp_input_elements ) ) {
		return new WP_Error(
			'hal_mcp_elementor_empty_design',
			__( 'The editor result carries no elements; nothing was stored.', 'hal-mcp' )
		);
	}

	// SAVE mode (default): an unverifiable element type, widget, or id
	// refuses the whole result — the stored version must be one this
	// integration fully understands.
	$hal_mcp_sanitized = hal_mcp_elementor_sanitize_elements( $hal_mcp_input_elements );

	if ( is_wp_error( $hal_mcp_sanitized ) ) {
		return $hal_mcp_sanitized;
	}

	// No field snapshot: a design request's original is the RAW stored
	// design, revalidated live at approve/apply time (the create pattern) —
	// an empty snapshot is exactly what the create path stores.
	$hal_mcp_updated = hal_mcp_update_change_request_payload(
		$request_id,
		[
			'elements'      => $hal_mcp_sanitized['elements'],
			'serialization' => [
				'source' => 'editor_bridge',
				'report' => $hal_mcp_sanitized['report'],
			],
		],
		[]
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

hal_mcp_register_integration(
	'elementor',
	[
		'label'      => __( 'Elementor', 'hal-mcp' ),
		'version'    => hal_mcp_elementor_present() ? (string) ELEMENTOR_VERSION : '',
		'source'     => 'elementor',
		'available'  => hal_mcp_elementor_present(),
		'operations' => [
			'read_page_design'  => [
				'effect'     => 'read',
				'capability' => 'edit_pages',
				'writable'   => [],
			],
			'update_page_design' => [
				'effect'     => 'edit',
				'capability' => 'edit_pages',
				'writable'   => [ 'design' ],
			],
		],
		'notes'      => __( 'Design reads come from the stored Elementor JSON; saves go through the Document API of the installed version with method-level guards and refresh the generated CSS. Unverifiable element or widget types refuse the save. Design requests carry the needs_editor readiness until their sanitized version is stored.', 'hal-mcp' ),
	]
);
