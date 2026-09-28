<?php
/**
 * hal-mcp-abilities — audit log.
 *
 * Records every hal/* ability call to a dedicated DB table.
 *
 * Two Abilities API action hooks are used together, not just one:
 *
 * - wp_before_execute_ability fires once input validation and the
 *   permission check have both already passed, immediately before
 *   execute_callback runs. This file writes a row here first, marked
 *   unsuccessful, and remembers its ID for the rest of the request.
 * - wp_after_execute_ability fires only once execute_callback has returned
 *   something other than a WP_Error *and* that return value has passed
 *   output_schema validation (confirmed by reading WP_Ability::execute()
 *   in wp-includes/abilities-api/class-wp-ability.php, WordPress 7.0.x: a
 *   WP_Error from execute_callback, and a failed output_schema validation,
 *   both return early — before this action ever fires). This file updates
 *   the row written above to mark it successful, instead of inserting a
 *   second row.
 *
 * Net effect: an execute_callback failure or an output_schema validation
 * failure — previously invisible to this log entirely, since the old
 * single-hook design only ever received results already guaranteed to be
 * successful — now leaves a row with success = 0 and a fixed placeholder
 * result_summary (the specific WP_Error message is not observable from
 * wp_before_execute_ability, so it cannot be recorded).
 *
 * Known v1 limitation, still not closed by the above: WP_Ability::execute()
 * returns before wp_before_execute_ability even fires for a call that fails
 * input_schema validation or the permission_callback check, so neither
 * failure kind is recorded here at all. WordPress 7.1 adds wp_ability_invoked,
 * an action that fires at the very start of execute() — before input
 * normalization, validation, or permission checks — for every invocation,
 * specifically so it can be used for this kind of auditing (see "Observing
 * every ability invocation",
 * make.wordpress.org/core/2026/07/31/abilities-api-improvements-in-wordpress-7-1/).
 * This site is on 7.0.2, where that action does not exist yet. Revisit this
 * file once the site is on 7.1 rather than building a heavier workaround now.
 *
 * Control-point events (F06): the two hooks above are a complement, not the
 * whole picture — permission denials and change-request lifecycle events
 * (requested/approved/rejected/applied/conflict/failed) never reach them, so
 * point-of-control code in permissions.php and change-requests.php logs those
 * itself through hal_mcp_log_control_event(), which writes rows into this
 * same table using the operation/stage/source/request_id columns added in
 * schema 2.0.0. wp_before/after remain responsible only for ability
 * executions, and this file deliberately does NOT claim that
 * wp_after_execute_ability catches validation/permission failures (it does
 * not — see the limitation paragraph above).
 *
 * Storage init is versioned and runs from two paths: the plugin activation hook
 * (registered by the standard plugin entry, hal-mcp-integration-abilities.php)
 * and this plugins_loaded check — the latter is the upgrade path, because
 * updating a plugin does not re-run activation. Table creation is checked
 * cheaply on every request (a single get_option() call) and only actually runs
 * dbDelta() the first time, or after a HAL_MCP_AUDIT_LOG_DB_VERSION bump.
 *
 * @package hal-mcp-abilities
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Bump this and dbDelta() will re-run to reconcile the table on the next
 * request, even on a site where the table already exists from an older
 * version of this file.
 *
 * 2.0.0 (F06): added request_id, operation, stage, and source columns for
 * control-point events — one schema bump for all four, no new analytics
 * table.
 *
 * @var string
 */
const HAL_MCP_AUDIT_LOG_DB_VERSION = '2.0.0';

/**
 * Maximum stored length, in characters, for the input/result summary columns.
 * Prevents a single unusually large call (e.g. hal/upload-media, whose input
 * contains a base64 file) from bloating the table; see the redaction in
 * hal_mcp_build_input_summary() below for the upload-specific case.
 *
 * @var int
 */
const HAL_MCP_AUDIT_LOG_MAX_SUMMARY_LENGTH = 2000;

/**
 * result_summary placeholder written for a row inserted from
 * wp_before_execute_ability, before whether the call will reach
 * wp_after_execute_ability is known. A row still holding this value once its
 * request has finished means that call never reached wp_after_execute_ability
 * — see the file header for exactly what that does, and does not, tell you.
 *
 * @var string
 */
const HAL_MCP_AUDIT_LOG_PENDING_RESULT_SUMMARY = 'Not recorded: execute_callback returned a WP_Error or output_schema validation failed. The specific error message is not observable from wp_before_execute_ability.';

add_action( 'plugins_loaded', 'hal_mcp_maybe_create_audit_log_table' );

/**
 * Creates (or updates) the audit log table, but only when
 * HAL_MCP_AUDIT_LOG_DB_VERSION doesn't match what's already recorded — i.e.
 * essentially never, after the first request post-install, unless a
 * previous attempt failed to actually create the table (see below).
 *
 * @return void
 */
function hal_mcp_maybe_create_audit_log_table(): void {

	if ( get_option( 'hal_mcp_audit_log_db_version' ) === HAL_MCP_AUDIT_LOG_DB_VERSION ) {
		return;
	}

	global $wpdb;

	if ( ! function_exists( 'dbDelta' ) ) {
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
	}

	$table_name      = $wpdb->prefix . 'hal_mcp_audit_log';
	$charset_collate = $wpdb->get_charset_collate();

	// dbDelta() has strict formatting requirements: each column on its own
	// line, and exactly two spaces between "PRIMARY KEY" and its column list.
	$sql = "CREATE TABLE {$table_name} (
		id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
		logged_at DATETIME NOT NULL,
		user_login VARCHAR(60) NOT NULL,
		ability_name VARCHAR(191) NOT NULL,
		input_summary TEXT NULL,
		result_summary TEXT NULL,
		success TINYINT(1) NOT NULL DEFAULT 1,
		request_id BIGINT UNSIGNED NULL DEFAULT NULL,
		operation VARCHAR(191) NOT NULL DEFAULT '',
		stage VARCHAR(32) NOT NULL DEFAULT '',
		source VARCHAR(32) NOT NULL DEFAULT '',
		PRIMARY KEY  (id),
		KEY logged_at (logged_at),
		KEY ability_name (ability_name)
	) {$charset_collate};";

	dbDelta( $sql );

	// Only record this version as reconciled once the table demonstrably
	// exists: a dbDelta() failure (DB permissions, charset mismatch, etc.)
	// must not be mistaken for success, or this function would never retry
	// on a later request.
	$table_exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table_name ) ) === $table_name;

	if ( ! $table_exists ) {
		error_log(
			sprintf(
				'hal-mcp-abilities: dbDelta() did not create/update the audit log table "%s". Will retry on the next request.',
				$table_name
			)
		);
		return;
	}

	update_option( 'hal_mcp_audit_log_db_version', HAL_MCP_AUDIT_LOG_DB_VERSION );
}

add_action( 'wp_before_execute_ability', 'hal_mcp_log_ability_attempt', 10, 2 );

/**
 * Handles wp_before_execute_ability for every ability on the site, filtering
 * down to this plugin's own hal/* abilities before writing anything.
 *
 * By the time this fires, input validation and the permission check have
 * both already passed (see the file header) — a permission-denied or
 * invalid-input call never reaches this function.
 *
 * Writes a row immediately, marked unsuccessful. If execute_callback goes on
 * to succeed, hal_mcp_log_ability_execution() below updates this same row
 * instead of inserting a second one; if it fails, or output_schema
 * validation fails, the row is left exactly as written here.
 *
 * @param string $ability_name The namespaced ability name, e.g. 'hal/get-post'.
 * @param mixed  $input        The input passed to the ability.
 * @return void
 */
function hal_mcp_log_ability_attempt( string $ability_name, $input ): void {

	if ( ! str_starts_with( $ability_name, 'hal/' ) ) {
		return;
	}

	$row_id = hal_mcp_insert_audit_log_row(
		[
			'logged_at'      => current_time( 'mysql' ),
			'user_login'     => (string) wp_get_current_user()->user_login,
			'ability_name'   => $ability_name,
			'input_summary'  => hal_mcp_build_input_summary( $input ),
			'result_summary' => HAL_MCP_AUDIT_LOG_PENDING_RESULT_SUMMARY,
			'success'        => 0,
		]
	);

	if ( null !== $row_id ) {
		hal_mcp_remember_pending_audit_log_row( $ability_name, $row_id );
	}
}

add_action( 'wp_after_execute_ability', 'hal_mcp_log_ability_execution', 10, 3 );

/**
 * Handles wp_after_execute_ability for every ability on the site, filtering
 * down to this plugin's own hal/* abilities before writing anything.
 *
 * $result here is always the ability's actual, already output-validated
 * return value, never a WP_Error (see the file header for why). The
 * is_wp_error() check below is kept as defence in depth in case that ever
 * changes in a future core version, not because it is expected to be true
 * today.
 *
 * @param string $ability_name The namespaced ability name, e.g. 'hal/get-post'.
 * @param mixed  $input        The (already-validated) input passed to the ability.
 * @param mixed  $result       Whatever execute_callback returned — a value, or WP_Error.
 * @return void
 */
function hal_mcp_log_ability_execution( string $ability_name, $input, $result ): void {

	// This hook fires for every ability on the site, not just ours — a
	// theme, another plugin, or WordPress core itself may register abilities
	// too. Only hal/* is this plugin's responsibility to log.
	if ( ! str_starts_with( $ability_name, 'hal/' ) ) {
		return;
	}

	$success        = ! is_wp_error( $result );
	$result_summary = hal_mcp_build_result_summary( $result );
	$pending_row_id = hal_mcp_take_pending_audit_log_row( $ability_name );

	if ( null !== $pending_row_id ) {
		hal_mcp_update_audit_log_row( $pending_row_id, $result_summary, $success );
		return;
	}

	// Defensive fallback: no pending row means hal_mcp_log_ability_attempt()
	// either never ran for this call (its own insert failed — see
	// hal_mcp_insert_audit_log_row()) or something fired this action
	// directly without going through WP_Ability::execute(). Either way,
	// still record the call rather than silently losing it.
	hal_mcp_insert_audit_log_row(
		[
			'logged_at'      => current_time( 'mysql' ),
			'user_login'     => (string) wp_get_current_user()->user_login,
			'ability_name'   => $ability_name,
			'input_summary'  => hal_mcp_build_input_summary( $input ),
			'result_summary' => $result_summary,
			'success'        => $success ? 1 : 0,
		]
	);
}

/**
 * Backing store for hal_mcp_remember_pending_audit_log_row() and
 * hal_mcp_take_pending_audit_log_row(): a stack of audit log row IDs per
 * ability name, for rows written from wp_before_execute_ability that have
 * not yet been confirmed by a matching wp_after_execute_ability call in the
 * same request. A stack (not a single value) per ability name defends
 * against the same ability being invoked again — e.g. recursively — before
 * an earlier call's row has been resolved.
 *
 * @return array<string, int[]>
 */
function &hal_mcp_pending_audit_log_rows(): array {
	static $pending_ids = [];
	return $pending_ids;
}

/**
 * Remembers an audit log row ID as pending confirmation for an ability.
 *
 * @param string $ability_name The namespaced ability name.
 * @param int    $row_id       The audit log row ID to remember.
 * @return void
 */
function hal_mcp_remember_pending_audit_log_row( string $ability_name, int $row_id ): void {
	$pending_ids = &hal_mcp_pending_audit_log_rows();

	$pending_ids[ $ability_name ][] = $row_id;
}

/**
 * Retrieves and forgets the most recently remembered pending audit log row
 * ID for an ability, if any.
 *
 * @param string $ability_name The namespaced ability name.
 * @return int|null The row ID, or null if none was pending.
 */
function hal_mcp_take_pending_audit_log_row( string $ability_name ): ?int {
	$pending_ids = &hal_mcp_pending_audit_log_rows();

	if ( empty( $pending_ids[ $ability_name ] ) ) {
		return null;
	}

	return array_pop( $pending_ids[ $ability_name ] );
}

/**
 * Builds the truncated, upload-redacted input_summary value for an audit
 * log row.
 *
 * hal/upload-media's input contains a base64-encoded file, sometimes
 * megabytes long — storing that verbatim in every log row would bloat the
 * table for no benefit, so it is replaced with a short placeholder before
 * summarizing.
 *
 * @param mixed $input The input an ability received.
 * @return string
 */
function hal_mcp_build_input_summary( $input ): string {

	if ( is_array( $input ) && isset( $input['file_data_base64'] ) && is_string( $input['file_data_base64'] ) ) {
		$input['file_data_base64'] = sprintf( '<omitted, %d bytes base64>', strlen( $input['file_data_base64'] ) );
	}

	return hal_mcp_truncate_for_audit_log( hal_mcp_redact_for_audit_log( wp_json_encode( $input ) ) );
}

/**
 * Builds the truncated result_summary value for an audit log row.
 *
 * @param mixed $result_or_error The ability's return value, or a WP_Error.
 * @return string
 */
function hal_mcp_build_result_summary( $result_or_error ): string {

	$result_summary = is_wp_error( $result_or_error )
		? $result_or_error->get_error_message()
		: wp_json_encode( $result_or_error );

	return hal_mcp_truncate_for_audit_log( hal_mcp_redact_for_audit_log( $result_summary ) );
}

/**
 * Redacts sensitive material from a value about to be stored in the audit log
 * (F06): API keys, authorization headers, cookies/tokens/secrets/passwords,
 * and long base64 runs — whether they arrive as a structured (JSON-encoded)
 * payload or embedded in a plain string such as a WP_Error message or a URL.
 *
 * Applied to every input_summary/result_summary write in this file, so raw
 * payloads never reach the table unchanged; callers store a short summary of
 * what changed instead of a raw payload (the change-request preview is built
 * by change-requests.php, not by echoing the payload here).
 *
 * @param mixed $value Expected to be a string, handled defensively otherwise.
 * @return string
 */
function hal_mcp_redact_for_audit_log( $value ): string {

	$value = is_string( $value ) ? $value : '';

	if ( '' === $value ) {
		return '';
	}

	// Structured payloads: decode, redact by key name, re-encode. If the
	// string is not valid JSON this is skipped and only the base64-run pass
	// below applies.
	$decoded = json_decode( $value, true );

	if ( is_array( $decoded ) ) {
		hal_mcp_redact_array_for_audit_log( $decoded );

		$re_encoded = wp_json_encode( $decoded );

		if ( is_string( $re_encoded ) ) {
			$value = $re_encoded;
		}
	}

	// String-level passes: material the JSON walk above can never see — a
	// token pasted into a WP_Error message, a key inside a URL, a header
	// dumped into a summary. Only well-known token/key shapes are matched; in
	// an audit log over-redaction is safe and under-redaction is not. The
	// placeholders are single tokens (no spaces) so a later pass cannot
	// half-match one and leave the rest behind.

	// Bearer tokens: "Authorization: Bearer eyJ..." in prose or headers.
	$value = (string) preg_replace(
		'~\bBearer\s+[A-Za-z0-9._+\\-]{8,}~i',
		'<redacted_token>',
		$value
	);

	// Provider-style key material (sk-... / sk-ant-... / AIza...) wherever it
	// appears. The sk- floor must match the 8+ tail of the admin.js preview
	// helper and the http-client shape mask: a key of tail length 8-15 is key
	// material, not prose, and the admin screen renders these rows.
	$value = (string) preg_replace(
		'~\bsk-(?:ant-)?[A-Za-z0-9_\\-]{8,}~i',
		'<redacted_key>',
		$value
	);
	$value = (string) preg_replace(
		'~\bAIza[0-9A-Za-z_\\-]{10,}~',
		'<redacted_key>',
		$value
	);

	// key=value / "key": "value" shapes — including camelCase and hyphenated
	// key names ((?i) plus the optional separator covers apiKey,
	// session_token, accessToken, ...). Runs after the token passes, so
	// their placeholders are simply re-covered, never half-left behind.
	$value = (string) preg_replace_callback(
		'~(?i)\b((?:authorization|api[_-]?key|access[_-]?token|auth[_-]?token|session(?:[_-]?(?:token|id))?|secret|passw(?:or)?d|credential|cookie|token)["\']?\s*[:=]\s*["\']?)\s*([^\s"\',;&}]{3,})~',
		static fn( array $hal_mcp_match ) => $hal_mcp_match[1] . '<redacted>',
		$value
	);

	// Any run of 512+ base64-alphabet characters is treated as encoded
	// material and dropped, even outside a recognized field name.
	$value = (string) preg_replace_callback(
		'~[A-Za-z0-9+/]{512,}={0,2}~',
		static fn() => '<redacted base64>',
		$value
	);

	return $value;
}

/**
 * Walks a decoded payload and replaces the values of sensitive-named keys
 * with a placeholder, in place.
 *
 * Depth is bounded — but the bound is a WALL, not a silent stop: beyond the
 * walk limit the entire sub-value is redacted wholesale (fail-safe), never
 * returned partially cleaned, because returning a "clean" value that still
 * contains nested secrets would defeat the point. (The request payload
 * sanitizer accepts up to 9 levels, so this must not be lower than that.)
 *
 * @param array<string, mixed> $items Payload items, by reference.
 * @param int                  $depth Current recursion depth.
 * @return void
 */
function hal_mcp_redact_array_for_audit_log( array &$items, int $depth = 0 ): void {

	if ( $depth > 9 ) {
		// Past the wall: redact whole sub-values instead of walking them —
		// arrays, strings, and other scalars alike, so nothing below the wall
		// survives into the stored row. Placeholders are single tokens (no
		// spaces) so the later string passes cannot half-match them.
		foreach ( $items as &$hal_mcp_item ) {
			if ( is_array( $hal_mcp_item ) ) {
				$hal_mcp_item = [ '<redacted-deep-nested>' ];
			} elseif ( null !== $hal_mcp_item && '' !== $hal_mcp_item ) {
				$hal_mcp_item = '<redacted-deep-nested>';
			}
		}
		unset( $hal_mcp_item );

		return;
	}

	foreach ( $items as $key => &$item ) {
		if ( is_array( $item ) ) {
			hal_mcp_redact_array_for_audit_log( $item, $depth + 1 );
			continue;
		}

		if ( is_string( $key ) && hal_mcp_is_sensitive_log_key( $key ) ) {
			$item = '<redacted>';
		}
	}

	unset( $item );
}

/**
 * Whether a payload key name looks like it carries credentials or secrets.
 * Key names are normalized (lowercased, separators and camelCase collapsed:
 * privateKey → privatekey, session-token → sessiontoken) so camelCase and
 * hyphenated variants are caught, then matched case-insensitively.
 *
 * Deliberately NOT matched: bare 'auth' — it is a prefix of 'author', a
 * legitimate content field; authorization headers are caught by the full
 * 'authorization'/'bearer' fragments.
 *
 * @param string $key Payload key name.
 * @return bool
 */
function hal_mcp_is_sensitive_log_key( string $key ): bool {

	$key = strtolower( preg_replace( '/[^a-z0-9]/i', '', $key ) );

	if ( '' === $key ) {
		return false;
	}

	$fragments = [
		'authorization',
		'apikey',
		'token',
		'secret',
		'password',
		'passwd',
		'cookie',
		'session',
		'bearer',
		'credential',
		'base64',
	];

	foreach ( $fragments as $fragment ) {
		if ( str_contains( $key, $fragment ) ) {
			return true;
		}
	}

	// Standalone 'auth'/'key', or any key name ending in 'key'
	// (privatekey, accesskey, licensekey, ...).
	return in_array( $key, [ 'auth', 'key' ], true ) || str_ends_with( $key, 'key' );
}

/**
 * Inserts one row into the audit log table. Deliberately never throws: a
 * logging failure must never be allowed to break the ability call it is
 * trying to record.
 *
 * @param array<string, mixed> $data Column values, matching the audit log table.
 * @return int|null The new row's ID, or null if the insert failed.
 */
function hal_mcp_insert_audit_log_row( array $data ): ?int {

	global $wpdb;

	// Formats are derived from the column keys (schema 2.0.0 added columns,
	// and control-event rows carry a different key set than hook rows), so a
	// mismatched positional format list can never corrupt a row.
	$hal_mcp_int_columns = [ 'id', 'request_id', 'success' ];

	$hal_mcp_formats = [];

	foreach ( array_keys( $data ) as $hal_mcp_column ) {
		$hal_mcp_formats[] = in_array( $hal_mcp_column, $hal_mcp_int_columns, true ) ? '%d' : '%s';
	}

	// $wpdb->insert() escapes values itself; it does not need separate
	// sanitization of the values passed in $data.
	$inserted = $wpdb->insert(
		$wpdb->prefix . 'hal_mcp_audit_log',
		$data,
		$hal_mcp_formats
	);

	if ( false === $inserted ) {
		error_log(
			sprintf(
				'hal-mcp-abilities: failed to write an audit log row for ability "%s".',
				(string) ( $data['ability_name'] ?? 'unknown' )
			)
		);
		return null;
	}

	return (int) $wpdb->insert_id;
}

/**
 * Updates an existing audit log row's outcome. Deliberately never throws,
 * for the same reason as hal_mcp_insert_audit_log_row() above.
 *
 * @param int    $row_id         The audit log row ID to update.
 * @param string $result_summary The new result_summary value.
 * @param bool   $success        The new success value.
 * @return void
 */
function hal_mcp_update_audit_log_row( int $row_id, string $result_summary, bool $success ): void {

	global $wpdb;

	$updated = $wpdb->update(
		$wpdb->prefix . 'hal_mcp_audit_log',
		[
			'result_summary' => $result_summary,
			'success'        => $success ? 1 : 0,
		],
		[ 'id' => $row_id ],
		[ '%s', '%d' ],
		[ '%d' ]
	);

	if ( false === $updated ) {
		error_log(
			sprintf(
				'hal-mcp-abilities: failed to update audit log row #%d.',
				$row_id
			)
		);
	}
}

/**
 * Truncates a string for storage in the audit log's summary columns.
 *
 * @param mixed $value Expected to be a string (the result of wp_json_encode()
 *                      or a WP_Error message), but handled defensively in
 *                      case encoding ever fails and returns false/null.
 * @return string
 */
function hal_mcp_truncate_for_audit_log( $value ): string {

	$value = is_string( $value ) ? $value : '';

	if ( strlen( $value ) > HAL_MCP_AUDIT_LOG_MAX_SUMMARY_LENGTH ) {
		return substr( $value, 0, HAL_MCP_AUDIT_LOG_MAX_SUMMARY_LENGTH ) . '…';
	}

	return $value;
}

/**
 * Writes one control-point event row (F06): permission denials from
 * permissions.php, and request/approve/reject/apply/conflict/fail events from
 * change-requests.php. These never reach the Abilities API hooks, which only
 * cover ability executions — this is the plugin's own explicit logging at the
 * points where state changes.
 *
 * The context array is redacted through the same pipeline as ability inputs
 * before storage. Never throws; returns null (and does not break the caller)
 * when the row could not be written.
 *
 * @param array<string, mixed> $args {
 *     @type string   $operation      Operation or ability name (required).
 *     @type string   $stage          Event stage, e.g. permission_denied,
 *                                    requested, approved, rejected,
 *                                    apply_started, applied, apply_failed,
 *                                    conflict.
 *     @type string   $source         Who drove it: mcp | admin | system | model.
 *     @type int|null $request_id     Linked hal_mcp_request ID, when one exists.
 *     @type bool     $success        Whether the event is a success.
 *     @type mixed    $result_summary Outcome (string or WP_Error).
 *     @type array    $context        Structured context, redacted before storage.
 * }
 * @return int|null The new row's ID, or null if the insert failed.
 */
function hal_mcp_log_control_event( array $args ): ?int {

	$operation = trim( (string) ( $args['operation'] ?? '' ) );

	if ( '' === $operation ) {
		error_log( 'hal-mcp-abilities: hal_mcp_log_control_event() called without an operation.' );
		return null;
	}

	$result = $args['result_summary'] ?? '';
	$result = is_wp_error( $result ) ? $result->get_error_message() : (string) $result;

	$data = [
		'logged_at'      => current_time( 'mysql' ),
		'user_login'     => (string) wp_get_current_user()->user_login,
		'ability_name'   => '',
		'input_summary'  => isset( $args['context'] ) ? hal_mcp_build_input_summary( $args['context'] ) : '',
		'result_summary' => hal_mcp_truncate_for_audit_log( hal_mcp_redact_for_audit_log( $result ) ),
		'success'        => ! empty( $args['success'] ) ? 1 : 0,
		'operation'      => substr( $operation, 0, 191 ),
		'stage'          => substr( (string) ( $args['stage'] ?? '' ), 0, 32 ),
		'source'         => substr( (string) ( $args['source'] ?? '' ), 0, 32 ),
	];

	// request_id stays at its column default (NULL) when absent — a 0 would
	// be indistinguishable from "no request" in the admin lists.
	if ( ! empty( $args['request_id'] ) ) {
		$data['request_id'] = (int) $args['request_id'];
	}

	return hal_mcp_insert_audit_log_row( $data );
}

/**
 * Whether the audit log table demonstrably exists at the current schema
 * version. Cached per request. Change-requests.php checks this BEFORE
 * executing a protected change: without a reliable state record, no protected
 * change is executed and no success is announced (F06).
 *
 * @return bool
 */
function hal_mcp_audit_log_table_ready(): bool {

	static $ready = null;

	if ( null !== $ready ) {
		return $ready;
	}

	if ( get_option( 'hal_mcp_audit_log_db_version' ) !== HAL_MCP_AUDIT_LOG_DB_VERSION ) {
		$ready = false;
		return $ready;
	}

	global $wpdb;

	$table_name = $wpdb->prefix . 'hal_mcp_audit_log';

	$ready = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table_name ) ) === $table_name;

	return $ready;
}

/**
 * Reads audit log entries for an administrator (F06: the log is admin-only,
 * with a limited page size — the F19 admin screen renders this).
 *
 * @param int $page     1-based page number.
 * @param int $per_page Rows per page, capped at 100.
 * @return array<string, mixed>|WP_Error { page, per_page, entries }
 *         Entries are raw column arrays; rendering/escaping belongs to the
 *         admin screen.
 */
function hal_mcp_get_audit_log_entries( int $page = 1, int $per_page = 20 ) {

	if ( ! current_user_can( 'manage_options' ) ) {
		return new WP_Error(
			'hal_mcp_forbidden',
			__( 'The audit log is available to administrators only.', 'hal-mcp' )
		);
	}

	if ( ! hal_mcp_audit_log_table_ready() ) {
		return new WP_Error(
			'hal_mcp_audit_log_unavailable',
			__( 'The audit log table is not available yet.', 'hal-mcp' )
		);
	}

	global $wpdb;

	$page     = max( 1, $page );
	$per_page = min( 100, max( 1, $per_page ) );
	$offset   = ( $page - 1 ) * $per_page;

	$table_name = $wpdb->prefix . 'hal_mcp_audit_log';

	$rows = $wpdb->get_results(
		$wpdb->prepare(
			"SELECT * FROM {$table_name} ORDER BY id DESC LIMIT %d OFFSET %d", // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- table name comes from $wpdb->prefix, values go through prepare().
			$per_page,
			$offset
		),
		ARRAY_A
	);

	if ( ! is_array( $rows ) ) {
		return new WP_Error(
			'hal_mcp_audit_log_read_failed',
			__( 'The audit log could not be read.', 'hal-mcp' )
		);
	}

	return [
		'page'     => $page,
		'per_page' => $per_page,
		'entries'  => $rows,
	];
}
