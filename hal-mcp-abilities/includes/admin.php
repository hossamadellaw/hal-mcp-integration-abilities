<?php
/**
 * hal-mcp-abilities — admin screens and protected REST routes (F19).
 *
 * One admin entry ("HAL MCP") with five tabs rendered INSIDE this file
 * (roadmap F19: "لا يحتاج كل تبويب ملفًا مستقلًا"): Overview (the default
 * landing tab), Commands (chat with the tool runner), Providers (the
 * Settings API form), Approvals (the F17 decision surface), Environment &
 * Log (inventory, integrations, audit log).
 *
 * Security shape (F19, each item enforced in code):
 * - Every REST route carries a permission_callback, and the decision routes
 *   (approve/reject/apply) require a verified X-WP-Nonce BOUND TO THE COOKIE
 *   SESSION plus manage_options — the nonce alone is never enough, and an
 *   Application Password or an external MCP call can never approve: they do
 *   not hold the admin page's nonce (hal_mcp_admin_rest_gate()).
 * - Approve/apply re-check the capability the effect needs on every target —
 *   inside change-requests.php, at BOTH the approve and the apply call.
 * - The preview surface (approval detail) reads stored request data only,
 *   gated by the F17 view rules; no temporary public page is created, no
 *   published original is modified to "render" a preview, and design
 *   payloads show as bounded text (the real-editor preview is the editor
 *   bridge, on the original editor screen).
 * - GET renders perform NO writes. Writes happen only through the POST
 *   routes (REST with nonce) and the Settings API form (options.php).
 * - admin.js holds no API keys and no permission logic; admin.css stays
 *   inside this screen and follows the WordPress admin look, RTL included.
 * - The connection test runs ONLY when an administrator clicks it — no paid
 *   calls at page load, and never a sweep across all providers.
 *
 * @package hal-mcp-abilities
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The admin page slug (and REST namespace root).
 *
 * @var string
 */
const HAL_MCP_ADMIN_PAGE = 'hal-mcp';

/**
 * The REST namespace for this plugin's admin routes.
 *
 * @var string
 */
const HAL_MCP_REST_NAMESPACE = 'hal-mcp/v1';

add_action( 'admin_menu', 'hal_mcp_admin_menu' );
add_action( 'admin_enqueue_scripts', 'hal_mcp_admin_enqueue' );
add_action( 'rest_api_init', 'hal_mcp_admin_rest_routes' );

/**
 * Registers the single admin entry. No submenus — tabs live inside the page,
 * and Overview is what the bare page shows (no forced redirect anywhere).
 *
 * @return void
 */
function hal_mcp_admin_menu(): void {
	add_menu_page(
		__( 'HAL MCP Integration Abilities', 'hal-mcp' ),
		__( 'HAL MCP', 'hal-mcp' ),
		'manage_options',
		HAL_MCP_ADMIN_PAGE,
		'hal_mcp_admin_render_page',
		'dashicons-format-chat',
		80
	);
}

/**
 * Enqueues the admin assets. This plugin's screen gets admin.js/admin.css;
 * the editor bridge (editor.js, F21) is enqueued on the REAL post editor
 * screen only, and only when the administrator opened it from a request
 * panel (hal_mcp_request + hal_mcp_fp query args) with a viewable request —
 * it stays inert otherwise (no server config, no action).
 *
 * @param string $hook_suffix Current admin page hook.
 * @return void
 */
function hal_mcp_admin_enqueue( string $hook_suffix ): void {

	if ( 'toplevel_page_' . HAL_MCP_ADMIN_PAGE === $hook_suffix ) {
		$hal_mcp_base = plugin_dir_url( HAL_MCP_ABILITIES_PLUGIN_FILE ) . 'hal-mcp-abilities/assets/';

		wp_enqueue_style( 'hal-mcp-admin', $hal_mcp_base . 'admin.css', [], HAL_MCP_ABILITIES_VERSION );
		wp_enqueue_script( 'hal-mcp-admin', $hal_mcp_base . 'admin.js', [], HAL_MCP_ABILITIES_VERSION, true );

		wp_add_inline_script(
			'hal-mcp-admin',
			'window.HAL_MCP_ADMIN = ' . wp_json_encode(
				[
					'restUrl'  => esc_url_raw( rest_url( HAL_MCP_REST_NAMESPACE . '/' ) ),
					'nonce'    => wp_create_nonce( 'wp_rest' ),
					'tab'      => hal_mcp_admin_current_tab(),
					'adminUrl' => admin_url( 'admin.php' ),
					// The UI strings admin.js renders (F19: translatable text in
					// the browser surface too — admin.js keeps the English
					// literals only as fallbacks when this config is absent).
					'l10n'     => [
						'working'             => __( 'working…', 'hal-mcp' ),
						'cancelled'           => __( 'cancelled.', 'hal-mcp' ),
						'pendingNote'         => __( 'Waiting for approval: request #%s. Open the Approvals tab — the run will not continue by itself.', 'hal-mcp' ),
						'runActive'           => __( 'running', 'hal-mcp' ),
						'runAwaitingApproval' => __( 'waiting for approval', 'hal-mcp' ),
						'runDone'             => __( 'done', 'hal-mcp' ),
						'runCancelled'        => __( 'cancelled', 'hal-mcp' ),
						'runError'            => __( 'stopped with an error', 'hal-mcp' ),
						'runLimit'            => __( 'paused at its limit', 'hal-mcp' ),
						'openEditor'          => __( 'Open in the editor to serialize the design', 'hal-mcp' ),
					],
				]
			) . ';',
			'before'
		);

		return;
	}

	// The editor bridge: post edit screens only.
	if ( ! in_array( $hook_suffix, [ 'post.php', 'post-new.php' ], true ) ) {
		return;
	}

	$hal_mcp_request_id = isset( $_GET['hal_mcp_request'] ) ? absint( $_GET['hal_mcp_request'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only routing; the bridge route re-validates everything server-side.
	$hal_mcp_fingerprint = isset( $_GET['hal_mcp_fp'] ) ? sanitize_text_field( wp_unslash( (string) $_GET['hal_mcp_fp'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended

	if ( $hal_mcp_request_id < 1 || '' === $hal_mcp_fingerprint ) {
		return;
	}

	$hal_mcp_request = hal_mcp_get_change_request( $hal_mcp_request_id, true );

	if ( is_wp_error( $hal_mcp_request ) || 'update-page' !== (string) $hal_mcp_request['operation'] ) {
		return;
	}

	$hal_mcp_targets   = (array) ( $hal_mcp_request['targets'] ?? [] );
	$hal_mcp_page_id   = (int) ( $hal_mcp_targets[0]['id'] ?? 0 );

	if ( $hal_mcp_page_id < 1 || ! hal_mcp_permission( 'page', 'edit', $hal_mcp_page_id, [ 'ability' => 'hal/editor-bridge-open', 'quiet' => true ] ) ) {
		return;
	}

	wp_enqueue_script(
		'hal-mcp-editor-bridge',
		plugin_dir_url( HAL_MCP_ABILITIES_PLUGIN_FILE ) . 'hal-mcp-abilities/assets/editor.js',
		[],
		HAL_MCP_ABILITIES_VERSION,
		true
	);

	wp_add_inline_script(
		'hal-mcp-editor-bridge',
		'window.HAL_MCP_EDITOR_BRIDGE_CONFIG = ' . wp_json_encode(
			[
				'requestId'         => $hal_mcp_request_id,
				'requestFingerprint' => $hal_mcp_fingerprint,
				'pageId'            => $hal_mcp_page_id,
				'restUrl'           => esc_url_raw( rest_url( HAL_MCP_REST_NAMESPACE . '/editor-serialization' ) ),
				'nonce'             => wp_create_nonce( 'wp_rest' ),
			]
		) . ';',
		'before'
	);
}

// ---------------------------------------------------------------------------
// REST routes
// ---------------------------------------------------------------------------

/**
 * Registers the bounded REST route set (F19). Every route has a permission
 * callback; every decision route additionally carries the in-function gates
 * of the F17 store (manage_options + per-target capability, re-checked).
 *
 * @return void
 */
function hal_mcp_admin_rest_routes(): void {

	$hal_mcp_session = static fn() => hal_mcp_admin_rest_gate( false );
	$hal_mcp_manager = static fn() => hal_mcp_admin_rest_gate( true );
	$hal_mcp_editor  = static function () {
		return hal_mcp_admin_rest_gate( false ) && current_user_can( 'edit_posts' );
	};

	register_rest_route(
		HAL_MCP_REST_NAMESPACE,
		'/chat',
		[
			'methods'             => 'POST',
			'permission_callback' => $hal_mcp_editor,
			'callback'            => 'hal_mcp_admin_rest_chat',
			'args'                => [
				'text'        => [ 'type' => 'string', 'required' => true ],
				'run_id'      => [ 'type' => 'integer', 'default' => 0 ],
				'provider_id' => [ 'type' => 'string', 'default' => '' ],
				'model'       => [ 'type' => 'string', 'default' => '' ],
			],
		]
	);

	register_rest_route(
		HAL_MCP_REST_NAMESPACE,
		'/runs/(?P<id>\d+)',
		[
			'methods'             => 'GET',
			'permission_callback' => $hal_mcp_session,
			'callback'            => 'hal_mcp_admin_rest_run_state',
		]
	);

	register_rest_route(
		HAL_MCP_REST_NAMESPACE,
		'/runs/(?P<id>\d+)/cancel',
		[
			'methods'             => 'POST',
			'permission_callback' => $hal_mcp_session,
			'callback'            => 'hal_mcp_admin_rest_run_cancel',
		]
	);

	register_rest_route(
		HAL_MCP_REST_NAMESPACE,
		'/approvals',
		[
			'methods'             => 'GET',
			'permission_callback' => $hal_mcp_manager,
			'callback'            => 'hal_mcp_admin_rest_approvals',
		]
	);

	register_rest_route(
		HAL_MCP_REST_NAMESPACE,
		'/approvals/(?P<id>\d+)',
		[
			'methods'             => 'GET',
			'permission_callback' => $hal_mcp_manager,
			'callback'            => 'hal_mcp_admin_rest_approval_detail',
		]
	);

	foreach ( [ 'approve', 'reject', 'apply' ] as $hal_mcp_action ) {
		register_rest_route(
			HAL_MCP_REST_NAMESPACE,
			'/approvals/(?P<id>\d+)/' . $hal_mcp_action,
			[
				'methods'             => 'POST',
				'permission_callback' => $hal_mcp_manager,
				'callback'            => 'hal_mcp_admin_rest_decision',
				'args'                => [
					'reason' => [ 'type' => 'string', 'default' => '' ],
				],
			]
		);
	}

	register_rest_route(
		HAL_MCP_REST_NAMESPACE,
		'/connection-test',
		[
			'methods'             => 'POST',
			'permission_callback' => $hal_mcp_manager,
			'callback'            => 'hal_mcp_admin_rest_connection_test',
			'args'                => [
				'provider_id' => [ 'type' => 'string', 'required' => true ],
				'model'       => [ 'type' => 'string', 'default' => '' ],
			],
		]
	);

	register_rest_route(
		HAL_MCP_REST_NAMESPACE,
		'/providers/(?P<id>[a-z\-]+)/models',
		[
			'methods'             => 'GET',
			'permission_callback' => $hal_mcp_manager,
			'callback'            => 'hal_mcp_admin_rest_provider_models',
		]
	);

	register_rest_route(
		HAL_MCP_REST_NAMESPACE,
		'/environment/refresh',
		[
			'methods'             => 'POST',
			'permission_callback' => $hal_mcp_manager,
			'callback'            => 'hal_mcp_admin_rest_environment_refresh',
		]
	);

	register_rest_route(
		HAL_MCP_REST_NAMESPACE,
		'/editor-serialization',
		[
			'methods'             => 'POST',
			'permission_callback' => $hal_mcp_editor,
			'callback'            => 'hal_mcp_admin_rest_editor_serialization',
			'args'                => [
				'request_id'         => [ 'type' => 'integer', 'required' => true ],
				'request_fingerprint' => [ 'type' => 'string', 'required' => true ],
				'markup'             => [ 'type' => 'string', 'required' => true ],
			],
		]
	);
}

/**
 * The shared REST gate: a verified wp_rest nonce BOUND TO THE COOKIE SESSION
 * (this is what excludes Application Passwords and external MCP calls — they
 * never hold the admin page's nonce), a logged-in user, and optionally
 * manage_options. The nonce alone is never sufficient — the capability is
 * the second factor.
 *
 * @param bool $manage_options_required Whether decisions routes demand manage_options.
 * @return bool
 */
function hal_mcp_admin_rest_gate( bool $manage_options_required ): bool {

	if ( get_current_user_id() < 1 ) {
		return false;
	}

	$hal_mcp_nonce = isset( $_SERVER['HTTP_X_WP_NONCE'] ) ? sanitize_text_field( (string) wp_unslash( $_SERVER['HTTP_X_WP_NONCE'] ) ) : '';

	if ( '' === $hal_mcp_nonce || ! wp_verify_nonce( $hal_mcp_nonce, 'wp_rest' ) ) {
		return false;
	}

	if ( $manage_options_required && ! current_user_can( 'manage_options' ) ) {
		return false;
	}

	return true;
}

/**
 * POST /chat — starts a new run or resumes one.
 *
 * @param WP_REST_Request $hal_mcp_request Request.
 * @return WP_REST_Response|WP_Error
 */
function hal_mcp_admin_rest_chat( $hal_mcp_request ) {

	$hal_mcp_run_id = (int) $hal_mcp_request->get_param( 'run_id' );
	$hal_mcp_text   = (string) $hal_mcp_request->get_param( 'text' );

	if ( $hal_mcp_run_id > 0 ) {
		$hal_mcp_result = hal_mcp_runner_resume( $hal_mcp_run_id, $hal_mcp_text );
	} else {
		$hal_mcp_provider_id = sanitize_key( (string) $hal_mcp_request->get_param( 'provider_id' ) );

		if ( '' === $hal_mcp_provider_id && function_exists( 'hal_mcp_settings_get' ) ) {
			$hal_mcp_provider_id = (string) hal_mcp_settings_get()['provider'];
		}

		if ( '' === $hal_mcp_provider_id ) {
			return new WP_Error( 'hal_mcp_no_provider', __( 'Choose a provider in the settings first.', 'hal-mcp' ) );
		}

		$hal_mcp_result = hal_mcp_runner_start(
			$hal_mcp_provider_id,
			$hal_mcp_text,
			[ 'model' => sanitize_text_field( (string) $hal_mcp_request->get_param( 'model' ) ) ]
		);
	}

	if ( is_wp_error( $hal_mcp_result ) ) {
		return $hal_mcp_result;
	}

	return rest_ensure_response( $hal_mcp_result );
}

/**
 * GET /runs/{id} — one run's state and transcript for its owner/manager.
 *
 * @param WP_REST_Request $hal_mcp_request Request.
 * @return WP_REST_Response|WP_Error
 */
function hal_mcp_admin_rest_run_state( $hal_mcp_request ) {

	$hal_mcp_result = hal_mcp_runner_get_run( (int) $hal_mcp_request->get_param( 'id' ) );

	if ( is_wp_error( $hal_mcp_result ) ) {
		return $hal_mcp_result;
	}

	return rest_ensure_response( $hal_mcp_result );
}

/**
 * POST /runs/{id}/cancel — cancels a run (owner/manager; the runner checks
 * the view gate and refuses finished runs). Completed writes are NOT
 * reverted, and the response says so.
 *
 * @param WP_REST_Request $hal_mcp_request Request.
 * @return WP_REST_Response|WP_Error
 */
function hal_mcp_admin_rest_run_cancel( $hal_mcp_request ) {

	$hal_mcp_result = hal_mcp_runner_cancel( (int) $hal_mcp_request->get_param( 'id' ) );

	if ( is_wp_error( $hal_mcp_result ) ) {
		return $hal_mcp_result;
	}

	return rest_ensure_response(
		[
			'cancelled' => true,
			// The plain-words boundary the roadmap demands.
			'message'   => __( 'The run was cancelled. Changes already applied are NOT reverted.', 'hal-mcp' ),
		]
	);
}

/**
 * GET /approvals — pending content-change requests, annotated with the
 * approver authorization per row (bounded: max 100 rows).
 *
 * @param WP_REST_Request $hal_mcp_request Request.
 * @return WP_REST_Response|WP_Error
 */
function hal_mcp_admin_rest_approvals( $hal_mcp_request ) {

	$hal_mcp_rows = hal_mcp_list_change_requests(
		[ 'status' => 'pending', 'kind' => 'content_change', 'per_page' => 100 ]
	);

	if ( is_wp_error( $hal_mcp_rows ) ) {
		return $hal_mcp_rows;
	}

	$hal_mcp_authorized = 0;

	foreach ( $hal_mcp_rows as &$hal_mcp_row ) {
		$hal_mcp_row['authorized'] = hal_mcp_admin_request_authorized_for_current( (int) $hal_mcp_row['request_id'] );

		if ( $hal_mcp_row['authorized'] ) {
			++$hal_mcp_authorized;
		}

		if ( function_exists( 'hal_mcp_blocks_request_editor_readiness' ) ) {
			$hal_mcp_row['editor_readiness'] = hal_mcp_blocks_request_editor_readiness( (int) $hal_mcp_row['request_id'] );
		}
	}

	unset( $hal_mcp_row );

	return rest_ensure_response(
		[
			'requests'   => $hal_mcp_rows,
			'authorized' => $hal_mcp_authorized,
		]
	);
}

/**
 * GET /approvals/{id} — the protected preview: before/after rows for the
 * changed fields, from the STORED snapshot and payload. No public page, no
 * rendering of design payloads (F19).
 *
 * A design-bearing update-page request also carries the F21 editor-bridge
 * link (editor_url): post.php opened with the request id and the stored
 * request-version fingerprint. The route, state, and fingerprint are
 * re-validated server-side by hal_mcp_blocks_ingest_editor_serialization()
 * (blocks.php) — this link only transports. Built ONLY when the first target
 * is an editable page and the current user holds its edit permission;
 * otherwise it stays an empty string (no dead end, no fabricated URL).
 *
 * @param WP_REST_Request $hal_mcp_request Request.
 * @return WP_REST_Response|WP_Error
 */
function hal_mcp_admin_rest_approval_detail( $hal_mcp_request ) {

	$hal_mcp_request_id = (int) $hal_mcp_request->get_param( 'id' );

	$hal_mcp_data = hal_mcp_get_change_request( $hal_mcp_request_id, true );

	if ( is_wp_error( $hal_mcp_data ) ) {
		return $hal_mcp_data;
	}

	$hal_mcp_rows   = [];
	$hal_mcp_payload = (array) ( $hal_mcp_data['payload'] ?? [] );
	$hal_mcp_before = (array) ( $hal_mcp_data['original_snapshot'] ?? [] );

	foreach ( array_keys( $hal_mcp_payload ) as $hal_mcp_field ) {
		$hal_mcp_after_value  = $hal_mcp_payload[ $hal_mcp_field ];
		$hal_mcp_before_value = $hal_mcp_before[ $hal_mcp_field ] ?? null;

		// Design serialization is shown as bounded text only — never
		// rendered, never a temporary page (F19/F21).
		$hal_mcp_is_design = ( 'content' === $hal_mcp_field && isset( $hal_mcp_payload['serialization'] ) )
			|| ( 'elements' === $hal_mcp_field )
			|| ( 'serialization' === $hal_mcp_field );

		$hal_mcp_rows[] = [
			'field'      => (string) $hal_mcp_field,
			'before'     => hal_mcp_admin_preview_value( $hal_mcp_before_value ),
			'after'      => hal_mcp_admin_preview_value( $hal_mcp_after_value ),
			'is_design'  => $hal_mcp_is_design,
			'is_new'     => ! array_key_exists( $hal_mcp_field, $hal_mcp_before ),
		];
	}

	// The F21 editor-bridge link: built ONLY for an update-page request whose
	// first target is an existing page the current user may edit, and only
	// when a stored request-version fingerprint exists. The ingest route
	// re-validates everything server-side (blocks.php) — this URL only
	// transports the request id and fingerprint to the real editor screen.
	$hal_mcp_editor_url  = '';
	$hal_mcp_fingerprint = (string) ( $hal_mcp_data['proposed_fingerprint'] ?? '' );

	if ( 'update-page' === (string) $hal_mcp_data['operation'] && '' !== $hal_mcp_fingerprint ) {
		$hal_mcp_targets = (array) ( $hal_mcp_data['targets'] ?? [] );
		$hal_mcp_target  = (array) ( $hal_mcp_targets[0] ?? [] );
		$hal_mcp_page_id = (int) ( $hal_mcp_target['id'] ?? 0 );

		if (
			'page' === (string) ( $hal_mcp_target['type'] ?? '' )
			&& $hal_mcp_page_id > 0
			&& hal_mcp_permission( 'page', 'edit', $hal_mcp_page_id, [ 'ability' => 'hal/editor-bridge-open', 'quiet' => true ] )
		) {
			$hal_mcp_editor_url = add_query_arg(
				[
					'post'            => $hal_mcp_page_id,
					'action'          => 'edit',
					'hal_mcp_request' => $hal_mcp_request_id,
					'hal_mcp_fp'      => $hal_mcp_fingerprint,
				],
				admin_url( 'post.php' )
			);
		}
	}

	$hal_mcp_detail = [
		'request_id'           => $hal_mcp_request_id,
		'operation'            => (string) $hal_mcp_data['operation'],
		'state'                => (string) $hal_mcp_data['state'],
		'preview'              => (string) $hal_mcp_data['preview'],
		'targets'              => (array) $hal_mcp_data['targets'],
		'language'             => (string) $hal_mcp_data['language'],
		'origin'               => (array) $hal_mcp_data['origin'],
		'created_at'           => (string) $hal_mcp_data['created_at'],
		'rows'                 => $hal_mcp_rows,
		'authorized'           => hal_mcp_admin_request_authorized_for_current( $hal_mcp_request_id ),
		'proposed_fingerprint' => $hal_mcp_fingerprint,
		'editor_url'           => $hal_mcp_editor_url,
	];

	if ( function_exists( 'hal_mcp_blocks_request_editor_readiness' ) ) {
		$hal_mcp_detail['editor_readiness'] = hal_mcp_blocks_request_editor_readiness( $hal_mcp_request_id );
	}

	return rest_ensure_response( $hal_mcp_detail );
}

/**
 * POST /approvals/{id}/{approve|reject|apply} — the decision routes. The
 * F17 functions own the real gates (manage_options + the capability the
 * effect needs on each target, re-checked at apply); this handler only
 * routes and reports.
 *
 * @param WP_REST_Request $hal_mcp_request Request.
 * @return WP_REST_Response|WP_Error
 */
function hal_mcp_admin_rest_decision( $hal_mcp_request ) {

	$hal_mcp_request_id = (int) $hal_mcp_request->get_param( 'id' );
	$hal_mcp_route      = (string) $hal_mcp_request->get_route();

	if ( str_ends_with( $hal_mcp_route, '/approve' ) ) {
		$hal_mcp_result = hal_mcp_approve_change_request( $hal_mcp_request_id );
	} elseif ( str_ends_with( $hal_mcp_route, '/reject' ) ) {
		$hal_mcp_result = hal_mcp_reject_change_request( $hal_mcp_request_id, sanitize_text_field( (string) $hal_mcp_request->get_param( 'reason' ) ) );
	} else {
		$hal_mcp_result = hal_mcp_apply_change_request( $hal_mcp_request_id );
	}

	if ( is_wp_error( $hal_mcp_result ) ) {
		return $hal_mcp_result;
	}

	return rest_ensure_response( $hal_mcp_result );
}

/**
 * POST /connection-test — an admin-triggered probe of ONE provider. Uses the
 * free model-list endpoint when the provider offers one; otherwise one
 * minimal text call. The outcome is recorded with its time — the Overview
 * step states read THIS, never the mere existence of a saved key.
 *
 * @param WP_REST_Request $hal_mcp_request Request.
 * @return WP_REST_Response|WP_Error
 */
function hal_mcp_admin_rest_connection_test( $hal_mcp_request ) {

	$hal_mcp_provider_id = sanitize_key( (string) $hal_mcp_request->get_param( 'provider_id' ) );
	$hal_mcp_model       = sanitize_text_field( (string) $hal_mcp_request->get_param( 'model' ) );

	if ( ! function_exists( 'hal_mcp_settings_provider_config' ) ) {
		return new WP_Error( 'hal_mcp_settings_missing', __( 'The settings module is not loaded.', 'hal-mcp' ) );
	}

	$hal_mcp_config = hal_mcp_settings_provider_config( $hal_mcp_provider_id, $hal_mcp_model );

	if ( is_wp_error( $hal_mcp_config ) ) {
		hal_mcp_settings_record_test( $hal_mcp_provider_id, [ 'ok' => false, 'code' => $hal_mcp_config->get_error_code() ] );

		return $hal_mcp_config;
	}

	$hal_mcp_list = hal_mcp_provider_list_models( $hal_mcp_config );

	if ( ! is_wp_error( $hal_mcp_list ) ) {
		hal_mcp_settings_record_test( $hal_mcp_provider_id, [ 'ok' => true, 'code' => 'ok' ] );

		return rest_ensure_response(
			[
				'ok'        => true,
				'code'      => 'ok',
				'tested_at' => time(),
				'message'   => __( 'Connection succeeded (model list read).', 'hal-mcp' ),
				'models'    => array_slice( $hal_mcp_list, 0, 50 ),
			]
		);
	}

	// No usable model-list endpoint: one minimal, explicitly requested call.
	if ( 'hal_mcp_provider_models_unavailable' !== $hal_mcp_list->get_error_code() ) {
		hal_mcp_settings_record_test( $hal_mcp_provider_id, [ 'ok' => false, 'code' => $hal_mcp_list->get_error_code() ] );

		return $hal_mcp_list;
	}

	$hal_mcp_probe = hal_mcp_provider_call(
		$hal_mcp_config,
		[
			'messages' => [ [ 'role' => 'user', 'text' => __( 'Reply with the word OK only.', 'hal-mcp' ) ] ],
			'tools'    => [],
		]
	);

	$hal_mcp_ok = ! is_wp_error( $hal_mcp_probe );

	hal_mcp_settings_record_test( $hal_mcp_provider_id, [ 'ok' => $hal_mcp_ok, 'code' => $hal_mcp_ok ? 'ok' : $hal_mcp_probe->get_error_code() ] );

	if ( ! $hal_mcp_ok ) {
		return $hal_mcp_probe;
	}

	return rest_ensure_response(
		[
			'ok'        => true,
			'code'      => 'ok',
			'tested_at' => time(),
			'message'   => __( 'Connection succeeded (minimal call).', 'hal-mcp' ),
			'models'    => [],
		]
	);
}

/**
 * GET /providers/{id}/models — the model list for the model selector.
 *
 * @param WP_REST_Request $hal_mcp_request Request.
 * @return WP_REST_Response|WP_Error
 */
function hal_mcp_admin_rest_provider_models( $hal_mcp_request ) {

	if ( ! function_exists( 'hal_mcp_settings_provider_config' ) ) {
		return new WP_Error( 'hal_mcp_settings_missing', __( 'The settings module is not loaded.', 'hal-mcp' ) );
	}

	$hal_mcp_config = hal_mcp_settings_provider_config( sanitize_key( (string) $hal_mcp_request->get_param( 'id' ) ), '' );

	if ( is_wp_error( $hal_mcp_config ) ) {
		return $hal_mcp_config;
	}

	return rest_ensure_response( [ 'models' => hal_mcp_provider_list_models( $hal_mcp_config ) ] );
}

/**
 * POST /environment/refresh — the Overview "re-discover" button. Invalidates
 * the cached inventory and returns the fresh operation statuses.
 *
 * @param WP_REST_Request $hal_mcp_request Request.
 * @return WP_REST_Response|WP_Error
 */
function hal_mcp_admin_rest_environment_refresh( $hal_mcp_request ) {

	unset( $hal_mcp_request );

	if ( ! function_exists( 'hal_mcp_environment_invalidate' ) ) {
		return new WP_Error( 'hal_mcp_environment_missing', __( 'The environment module is not loaded.', 'hal-mcp' ) );
	}

	hal_mcp_environment_invalidate();

	return rest_ensure_response(
		[
			'refreshed' => true,
			'statuses'  => function_exists( 'hal_mcp_environment_operation_statuses' ) ? hal_mcp_environment_operation_statuses() : [],
		]
	);
}

/**
 * POST /editor-serialization — the F21 editor bridge's protected route.
 * Everything is re-validated server-side inside the ingest function
 * (request state, operation shape, page edit permission, request-version
 * fingerprint); this route only transports.
 *
 * @param WP_REST_Request $hal_mcp_request Request.
 * @return WP_REST_Response|WP_Error
 */
function hal_mcp_admin_rest_editor_serialization( $hal_mcp_request ) {

	if ( ! function_exists( 'hal_mcp_blocks_ingest_editor_serialization' ) ) {
		return new WP_Error( 'hal_mcp_bridge_unavailable', __( 'The editor integration is not loaded.', 'hal-mcp' ) );
	}

	$hal_mcp_result = hal_mcp_blocks_ingest_editor_serialization(
		(int) $hal_mcp_request->get_param( 'request_id' ),
		[
			'request_fingerprint' => sanitize_text_field( (string) $hal_mcp_request->get_param( 'request_fingerprint' ) ),
			'markup'              => (string) $hal_mcp_request->get_param( 'markup' ),
		]
	);

	if ( is_wp_error( $hal_mcp_result ) ) {
		return $hal_mcp_result;
	}

	return rest_ensure_response( $hal_mcp_result );
}

// ---------------------------------------------------------------------------
// Shared helpers
// ---------------------------------------------------------------------------

/**
 * Whether the CURRENT user (an administrator via the REST gate) may approve
 * one request: the capability the apply effect needs on every target. Used
 * for the honest "authorized" counts (F19: "عدد طلبات الموافقة المخولة").
 *
 * @param int $hal_mcp_request_id Request id.
 * @return bool
 */
function hal_mcp_admin_request_authorized_for_current( int $hal_mcp_request_id ): bool {

	if ( ! current_user_can( 'manage_options' ) ) {
		return false;
	}

	$hal_mcp_data = hal_mcp_get_change_request( $hal_mcp_request_id, true );

	if ( is_wp_error( $hal_mcp_data ) ) {
		return false;
	}

	$hal_mcp_effect = function_exists( 'hal_mcp_request_apply_effect' ) ? hal_mcp_request_apply_effect( $hal_mcp_data ) : 'edit';

	foreach ( (array) ( $hal_mcp_data['targets'] ?? [] ) as $hal_mcp_target ) {
		if ( ! hal_mcp_user_can_approve_target( (string) ( $hal_mcp_target['type'] ?? '' ), (int) ( $hal_mcp_target['id'] ?? 0 ), $hal_mcp_effect ) ) {
			return false;
		}
	}

	return [] !== (array) ( $hal_mcp_data['targets'] ?? [] );
}

/**
 * One preview value for the approval diff: strings truncated, arrays shown
 * as bounded JSON. Rendering happens with esc_html on the client markup —
 * the values themselves stay raw data here.
 *
 * @param mixed $hal_mcp_value Stored value.
 * @return string
 */
function hal_mcp_admin_preview_value( $hal_mcp_value ): string {

	if ( null === $hal_mcp_value ) {
		return '';
	}

	if ( is_bool( $hal_mcp_value ) ) {
		return $hal_mcp_value ? 'true' : 'false';
	}

	if ( is_array( $hal_mcp_value ) ) {
		$hal_mcp_value = (string) wp_json_encode( $hal_mcp_value );
	}

	$hal_mcp_value = (string) $hal_mcp_value;

	if ( strlen( $hal_mcp_value ) > 1000 ) {
		$hal_mcp_value = substr( $hal_mcp_value, 0, 999 ) . '…';
	}

	return $hal_mcp_value;
}

/**
 * The current tab slug (allowlisted; overview is the default and the bare
 * page — no redirect).
 *
 * @return string
 */
function hal_mcp_admin_current_tab(): string {

	// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only tab routing on an admin page.
	$hal_mcp_tab = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( (string) $_GET['tab'] ) ) : 'overview';

	$hal_mcp_tabs = [ 'overview', 'commands', 'providers', 'approvals', 'environment' ];

	return in_array( $hal_mcp_tab, $hal_mcp_tabs, true ) ? $hal_mcp_tab : 'overview';
}

/**
 * One Overview step state's translatable label. The slug itself stays in the
 * CSS class attribute (styling and tests key off it); what renders to the
 * administrator is this label.
 *
 * @param string $state Step state slug (ready/needs_action/connected/
 *                     awaiting_test/started/not_started/nothing_pending/
 *                     awaiting_other/unavailable).
 * @return string The display label; an unknown slug passes through unchanged.
 */
function hal_mcp_admin_step_state_label( string $state ): string {

	$hal_mcp_labels = [
		'ready'           => __( 'Ready', 'hal-mcp' ),
		'needs_action'    => __( 'Needs action', 'hal-mcp' ),
		'connected'       => __( 'Connected', 'hal-mcp' ),
		'awaiting_test'   => __( 'Awaiting the connection test', 'hal-mcp' ),
		'started'         => __( 'Started', 'hal-mcp' ),
		'not_started'     => __( 'Not started', 'hal-mcp' ),
		'nothing_pending' => __( 'Nothing pending', 'hal-mcp' ),
		'awaiting_other'  => __( 'Awaiting another approver', 'hal-mcp' ),
		'unavailable'     => __( 'Unavailable', 'hal-mcp' ),
	];

	return $hal_mcp_labels[ $state ] ?? $state;
}

// ---------------------------------------------------------------------------
// Page rendering (GET — reads only)
// ---------------------------------------------------------------------------

/**
 * Renders the admin page and the active tab.
 *
 * @return void
 */
function hal_mcp_admin_render_page(): void {

	if ( ! current_user_can( 'manage_options' ) ) {
		wp_die( esc_html__( 'You need administrator access to view this page.', 'hal-mcp' ) );
	}

	$hal_mcp_tab = hal_mcp_admin_current_tab();

	?>
	<div class="wrap hal-mcp-admin">
		<h1><?php esc_html_e( 'HAL MCP Integration Abilities', 'hal-mcp' ); ?></h1>
		<nav class="nav-tab-wrapper hal-mcp-tabs" aria-label="<?php esc_attr_e( 'HAL MCP sections', 'hal-mcp' ); ?>">
			<?php
			$hal_mcp_labels = [
				'overview'    => __( 'Overview', 'hal-mcp' ),
				'commands'    => __( 'Commands', 'hal-mcp' ),
				'providers'   => __( 'Providers', 'hal-mcp' ),
				'approvals'   => __( 'Approvals', 'hal-mcp' ),
				'environment' => __( 'Environment & Log', 'hal-mcp' ),
			];

			foreach ( $hal_mcp_labels as $hal_mcp_slug => $hal_mcp_label ) {
				printf(
					'<a class="nav-tab%1$s" href="%2$s">%3$s</a>',
					$hal_mcp_tab === $hal_mcp_slug ? ' nav-tab-active' : '',
					esc_url( admin_url( 'admin.php?page=' . HAL_MCP_ADMIN_PAGE . '&tab=' . $hal_mcp_slug ) ),
					esc_html( $hal_mcp_label )
				);
			}
			?>
		</nav>

		<?php
		switch ( $hal_mcp_tab ) {
			case 'commands':
				hal_mcp_admin_render_commands();
				break;
			case 'providers':
				hal_mcp_admin_render_providers();
				break;
			case 'approvals':
				hal_mcp_admin_render_approvals();
				break;
			case 'environment':
				hal_mcp_admin_render_environment();
				break;
			default:
				hal_mcp_admin_render_overview();
		}
		?>
	</div>
	<?php
}

/**
 * The Overview tab: the short opening explanation, the six linked steps with
 * statuses derived from EXISTING data (no fake success), and the three
 * summary cards. Buttons link to their tabs.
 *
 * @return void
 */
function hal_mcp_admin_render_overview(): void {

	$hal_mcp_settings    = function_exists( 'hal_mcp_settings_get' ) ? hal_mcp_settings_get() : [];
	$hal_mcp_provider_id = (string) ( $hal_mcp_settings['provider'] ?? '' );
	$hal_mcp_providers   = hal_mcp_providers();

	$hal_mcp_key_set     = false;
	$hal_mcp_key_hint    = '';
	$hal_mcp_key_error   = '';

	if ( '' !== $hal_mcp_provider_id ) {
		$hal_mcp_status = hal_mcp_settings_secret_status( $hal_mcp_provider_id );

		$hal_mcp_key_set   = (bool) $hal_mcp_status['set'];
		$hal_mcp_key_hint  = (string) $hal_mcp_status['hint'];
		$hal_mcp_key_error = (string) $hal_mcp_status['error'];
	}

	$hal_mcp_model = '' !== $hal_mcp_provider_id
		? (string) ( $hal_mcp_settings['models'][ $hal_mcp_provider_id ] ?? ( 'custom' === $hal_mcp_provider_id ? '' : (string) ( $hal_mcp_providers[ $hal_mcp_provider_id ]['default_model'] ?? '' ) ) )
		: '';

	$hal_mcp_tests  = hal_mcp_settings_test_results( $hal_mcp_provider_id );
	$hal_mcp_test   = $hal_mcp_tests[ $hal_mcp_provider_id ] ?? null;

	$hal_mcp_runs     = hal_mcp_list_change_requests( [ 'kind' => 'run', 'per_page' => 1 ] );
	$hal_mcp_has_run  = is_array( $hal_mcp_runs ) && [] !== $hal_mcp_runs;

	$hal_mcp_pending = hal_mcp_list_change_requests( [ 'status' => 'pending', 'kind' => 'content_change', 'per_page' => 100 ] );
	$hal_mcp_pending = is_array( $hal_mcp_pending ) ? $hal_mcp_pending : [];

	$hal_mcp_authorized = 0;
	foreach ( $hal_mcp_pending as $hal_mcp_row ) {
		if ( hal_mcp_admin_request_authorized_for_current( (int) $hal_mcp_row['request_id'] ) ) {
			++$hal_mcp_authorized;
		}
	}

	$hal_mcp_statuses   = function_exists( 'hal_mcp_environment_operation_statuses' ) ? hal_mcp_environment_operation_statuses() : [];
	$hal_mcp_inventory  = function_exists( 'hal_mcp_environment_inventory' );

	$hal_mcp_step_url = static fn( string $hal_mcp_tab ) => admin_url( 'admin.php?page=' . HAL_MCP_ADMIN_PAGE . '&tab=' . $hal_mcp_tab );

	$hal_mcp_steps = [
		[
			'title'   => __( '1. Review what was discovered on your site', 'hal-mcp' ),
			'state'   => $hal_mcp_inventory ? 'ready' : 'unavailable',
			'detail'  => $hal_mcp_inventory
				? __( 'The environment inventory is available in the Environment tab.', 'hal-mcp' )
				: __( 'The environment module is not loaded, so discovery is unavailable.', 'hal-mcp' ),
			'url'     => $hal_mcp_step_url( 'environment' ),
			'action'  => __( 'Open Environment', 'hal-mcp' ),
		],
		[
			'title'   => __( '2. Choose the provider and enter its key', 'hal-mcp' ),
			'state'   => '' === $hal_mcp_provider_id ? 'needs_action' : ( $hal_mcp_key_set ? 'ready' : 'needs_action' ),
			'detail'  => '' === $hal_mcp_provider_id
				? __( 'No provider is selected yet.', 'hal-mcp' )
				: ( $hal_mcp_key_set
					? sprintf( /* translators: %s: masked key fingerprint */ __( 'A key is saved for %s (ending hint: %s).', 'hal-mcp' ), $hal_mcp_providers[ $hal_mcp_provider_id ]['label'] ?? $hal_mcp_provider_id, $hal_mcp_key_hint )
					: __( 'The provider is selected but its key is not saved yet.', 'hal-mcp' ) ) . ( '' !== $hal_mcp_key_error ? ' ' . $hal_mcp_key_error : '' ),
			'url'     => $hal_mcp_step_url( 'providers' ),
			'action'  => __( 'Open Providers', 'hal-mcp' ),
		],
		[
			'title'   => __( '3. Choose the model and test the connection', 'hal-mcp' ),
			'state'   => ( '' === $hal_mcp_provider_id || '' === $hal_mcp_model )
				? 'needs_action'
				: ( is_array( $hal_mcp_test ) && $hal_mcp_test['ok']
					? 'connected'
					: 'awaiting_test' ),
			'detail'  => ( '' === $hal_mcp_provider_id || '' === $hal_mcp_model )
				? __( 'Choose the provider and model first.', 'hal-mcp' )
				: ( is_array( $hal_mcp_test )
					? ( $hal_mcp_test['ok']
						? sprintf( /* translators: %s: date/time of the successful test */ __( 'Connection tested successfully at %s.', 'hal-mcp' ), date_i18n( 'Y-m-d H:i', (int) $hal_mcp_test['tested_at'] ) )
						: __( 'The last connection test failed. Run it again after fixing the settings.', 'hal-mcp' ) )
					: __( 'A saved key is not a proven connection — run the test from the Providers tab when you are ready.', 'hal-mcp' ) ),
			'url'     => $hal_mcp_step_url( 'providers' ),
			'action'  => __( 'Open Providers', 'hal-mcp' ),
		],
		[
			'title'   => __( '4. Write your first request', 'hal-mcp' ),
			'state'   => $hal_mcp_has_run ? 'started' : 'not_started',
			'detail'  => $hal_mcp_has_run
				? __( 'Runs already exist — continue in the Commands tab.', 'hal-mcp' )
				: __( 'No command has been run yet.', 'hal-mcp' ),
			'url'     => $hal_mcp_step_url( 'commands' ),
			'action'  => __( 'Start a request', 'hal-mcp' ),
		],
		[
			'title'   => __( '5. Review the preview, then approve or reject', 'hal-mcp' ),
			'state'   => [] === $hal_mcp_pending ? 'nothing_pending' : ( $hal_mcp_authorized > 0 ? 'awaiting_approval' : 'awaiting_other' ),
			'detail'  => [] === $hal_mcp_pending
				? __( 'No change is waiting for approval.', 'hal-mcp' )
				: sprintf(
					/* translators: 1: pending count, 2: count you are authorized to approve. */
					_n( '%1$s change request is waiting (%2$s of them is within your approval rights).', '%1$s change requests are waiting (%2$s of them are within your approval rights).', count( $hal_mcp_pending ), 'hal-mcp' ),
					number_format_i18n( count( $hal_mcp_pending ) ),
					number_format_i18n( $hal_mcp_authorized )
				),
			'url'     => $hal_mcp_step_url( 'approvals' ),
			'action'  => __( 'Review approvals', 'hal-mcp' ),
		],
		[
			'title'   => __( '6. Follow the result', 'hal-mcp' ),
			'state'   => function_exists( 'hal_mcp_get_audit_log_entries' ) ? 'ready' : 'unavailable',
			'detail'  => __( 'Applied changes and their outcomes are recorded in the audit log (Environment & Log tab).', 'hal-mcp' ),
			'url'     => $hal_mcp_step_url( 'environment' ),
			'action'  => __( 'Open Environment & Log', 'hal-mcp' ),
		],
	];
	?>
	<p class="hal-mcp-intro">
		<?php esc_html_e( 'This screen lets you work with a language model on your site content: choose a provider, write a request, review what it proposes, and approve publishing yourself. Saving a draft and publishing are different steps — publishing always waits for your approval here.', 'hal-mcp' ); ?>
	</p>

	<div class="hal-mcp-cards">
		<div class="hal-mcp-card">
			<h2><?php esc_html_e( 'Provider & model', 'hal-mcp' ); ?></h2>
			<p>
				<?php
				if ( '' === $hal_mcp_provider_id ) {
					esc_html_e( 'Not chosen yet.', 'hal-mcp' );
				} else {
					printf(
						'%1$s — %2$s',
						esc_html( $hal_mcp_providers[ $hal_mcp_provider_id ]['label'] ?? $hal_mcp_provider_id ),
						esc_html( '' !== $hal_mcp_model ? $hal_mcp_model : __( 'no model chosen', 'hal-mcp' ) )
					);
					if ( function_exists( 'hal_mcp_provider_supports_tools' ) && ! hal_mcp_provider_supports_tools( $hal_mcp_provider_id ) ) {
						echo '<br /><em>' . esc_html__( 'Text generation only — this provider is not set up for site editing through tools.', 'hal-mcp' ) . '</em>';
					}
				}
				?>
			</p>
			<p><a class="button" href="<?php echo esc_url( $hal_mcp_step_url( 'commands' ) ); ?>"><?php esc_html_e( 'Start a request', 'hal-mcp' ); ?></a></p>
		</div>
		<div class="hal-mcp-card">
			<h2><?php esc_html_e( 'Site capabilities', 'hal-mcp' ); ?></h2>
			<ul>
				<?php foreach ( $hal_mcp_statuses as $hal_mcp_area => $hal_mcp_status_row ) : ?>
					<li>
						<span class="hal-mcp-status hal-mcp-status-<?php echo esc_attr( (string) ( $hal_mcp_status_row['status'] ?? 'unverified' ) ); ?>"><?php echo esc_html( (string) ( $hal_mcp_status_row['status'] ?? 'unverified' ) ); ?></span>
						<?php echo esc_html( (string) $hal_mcp_area ); ?>
					</li>
				<?php endforeach; ?>
				<?php if ( [] === $hal_mcp_statuses ) : ?>
					<li><?php esc_html_e( 'The environment summary is not available yet.', 'hal-mcp' ); ?></li>
				<?php endif; ?>
			</ul>
		</div>
		<div class="hal-mcp-card">
			<h2><?php esc_html_e( 'Waiting approvals', 'hal-mcp' ); ?></h2>
			<p><?php echo esc_html( number_format_i18n( $hal_mcp_authorized ) ); ?> / <?php echo esc_html( number_format_i18n( count( $hal_mcp_pending ) ) ); ?></p>
			<p><a class="button" href="<?php echo esc_url( $hal_mcp_step_url( 'approvals' ) ); ?>"><?php esc_html_e( 'Review approvals', 'hal-mcp' ); ?></a></p>
		</div>
	</div>

	<ol class="hal-mcp-steps">
		<?php foreach ( $hal_mcp_steps as $hal_mcp_step ) : ?>
			<li class="hal-mcp-step hal-mcp-step-<?php echo esc_attr( (string) $hal_mcp_step['state'] ); ?>">
				<span class="hal-mcp-step-state"><?php echo esc_html( hal_mcp_admin_step_state_label( (string) $hal_mcp_step['state'] ) ); ?></span>
				<span class="hal-mcp-step-title"><?php echo esc_html( (string) $hal_mcp_step['title'] ); ?></span>
				<span class="hal-mcp-step-detail"><?php echo esc_html( (string) $hal_mcp_step['detail'] ); ?></span>
				<a class="button button-secondary" href="<?php echo esc_url( (string) $hal_mcp_step['url'] ); ?>"><?php echo esc_html( (string) $hal_mcp_step['action'] ); ?></a>
			</li>
		<?php endforeach; ?>
	</ol>
	<?php
}

/**
 * The Commands tab: provider/model pickers, the instruction box, and the
 * transcript surface. Interaction lives in admin.js; this render performs no
 * writes and makes no provider calls.
 *
 * @return void
 */
function hal_mcp_admin_render_commands(): void {

	$hal_mcp_settings = hal_mcp_settings_get();
	$hal_mcp_providers = hal_mcp_providers();
	$hal_mcp_runs = hal_mcp_list_change_requests( [ 'kind' => 'run', 'per_page' => 10 ] );
	?>
	<p><?php esc_html_e( 'Write what you want in plain language. The assistant works through your site tools; anything protected stops and waits for your approval in the Approvals tab.', 'hal-mcp' ); ?></p>

	<table class="form-table" role="presentation">
		<tr>
			<th scope="row"><label for="hal-mcp-provider"><?php esc_html_e( 'Provider', 'hal-mcp' ); ?></label></th>
			<td>
				<select id="hal-mcp-provider" data-hal-role="provider">
					<option value=""><?php esc_html_e( '— use the saved provider —', 'hal-mcp' ); ?></option>
					<?php foreach ( $hal_mcp_providers as $hal_mcp_id => $hal_mcp_provider ) : ?>
						<option value="<?php echo esc_attr( $hal_mcp_id ); ?>" <?php selected( $hal_mcp_settings['provider'], $hal_mcp_id ); ?>>
							<?php echo esc_html( (string) $hal_mcp_provider['label'] ); ?>
						</option>
					<?php endforeach; ?>
				</select>
			</td>
		</tr>
		<tr>
			<th scope="row"><label for="hal-mcp-model"><?php esc_html_e( 'Model', 'hal-mcp' ); ?></label></th>
			<td>
				<input type="text" id="hal-mcp-model" data-hal-role="model" class="regular-text" placeholder="<?php esc_attr_e( 'saved model', 'hal-mcp' ); ?>" />
				<button type="button" class="button" data-hal-action="load-models"><?php esc_html_e( 'Load model list', 'hal-mcp' ); ?></button>
				<datalist id="hal-mcp-model-list"></datalist>
			</td>
		</tr>
	</table>

	<label for="hal-mcp-instruction" class="screen-reader-text"><?php esc_html_e( 'Your instruction', 'hal-mcp' ); ?></label>
	<textarea id="hal-mcp-instruction" data-hal-role="instruction" rows="5" class="large-text" maxlength="<?php echo esc_attr( (string) HAL_MCP_RUNNER_MAX_INPUT_CHARS ); ?>"></textarea>

	<p>
		<button type="button" class="button button-primary" data-hal-action="send"><?php esc_html_e( 'Send', 'hal-mcp' ); ?></button>
		<button type="button" class="button" data-hal-action="resume" hidden><?php esc_html_e( 'Continue run', 'hal-mcp' ); ?></button>
		<button type="button" class="button-link-delete" data-hal-action="cancel" hidden><?php esc_html_e( 'Cancel run', 'hal-mcp' ); ?></button>
		<span data-hal-role="chat-status" class="hal-mcp-chat-status" aria-live="polite"></span>
	</p>

	<div data-hal-role="transcript" class="hal-mcp-transcript" aria-live="polite"></div>

	<?php if ( is_array( $hal_mcp_runs ) && [] !== $hal_mcp_runs ) : ?>
		<h2><?php esc_html_e( 'Recent runs', 'hal-mcp' ); ?></h2>
		<ul class="hal-mcp-runs">
			<?php foreach ( $hal_mcp_runs as $hal_mcp_run_row ) : ?>
				<li>
					<button type="button" class="button-link" data-hal-action="open-run" data-run-id="<?php echo esc_attr( (string) $hal_mcp_run_row['request_id'] ); ?>">#<?php echo esc_html( (string) $hal_mcp_run_row['request_id'] ); ?></button>
					<?php echo esc_html( wp_html_excerpt( (string) $hal_mcp_run_row['preview'], 80, '…' ) ); ?>
					<span class="hal-mcp-status hal-mcp-status-run"><?php echo esc_html( (string) $hal_mcp_run_row['state'] ); ?></span>
				</li>
			<?php endforeach; ?>
		</ul>
	<?php endif; ?>
	<?php
}

/**
 * The Providers tab: the Settings API form (options.php handles the nonce,
 * capability, and the sanitize callback in settings.php). Key fields are
 * password-type with masked hints; advanced endpoint overrides are collapsed
 * by default.
 *
 * @return void
 */
function hal_mcp_admin_render_providers(): void {

	$hal_mcp_settings = hal_mcp_settings_get();
	$hal_mcp_providers = hal_mcp_providers();
	?>
	<p><?php esc_html_e( 'Provider settings are stored per site. API keys are stored encrypted and are never shown again after saving — the hint below each field only proves one is stored.', 'hal-mcp' ); ?></p>

	<form method="post" action="options.php">
		<?php settings_fields( 'hal_mcp_settings_group' ); ?>

		<table class="form-table" role="presentation">
			<tr>
				<th scope="row"><?php esc_html_e( 'Active provider', 'hal-mcp' ); ?></th>
				<td>
					<?php foreach ( $hal_mcp_providers as $hal_mcp_id => $hal_mcp_provider ) : ?>
						<label class="hal-mcp-radio">
							<input type="radio" name="<?php echo esc_attr( HAL_MCP_SETTINGS_OPTION ); ?>[provider]" value="<?php echo esc_attr( $hal_mcp_id ); ?>" <?php checked( $hal_mcp_settings['provider'], $hal_mcp_id ); ?> />
							<?php echo esc_html( (string) $hal_mcp_provider['label'] ); ?>
						</label>
					<?php endforeach; ?>
				</td>
			</tr>
		</table>

		<h2><?php esc_html_e( 'Providers', 'hal-mcp' ); ?></h2>
		<?php foreach ( $hal_mcp_providers as $hal_mcp_id => $hal_mcp_provider ) : ?>
			<?php
			$hal_mcp_secret_status = hal_mcp_settings_secret_status( $hal_mcp_id );
			$hal_mcp_is_custom     = 'custom' === $hal_mcp_id;
			?>
			<details class="hal-mcp-provider-box" <?php echo $hal_mcp_settings['provider'] === $hal_mcp_id ? 'open' : ''; ?>>
				<summary><?php echo esc_html( (string) $hal_mcp_provider['label'] ); ?> — <?php echo esc_html( $hal_mcp_secret_status['set'] ? __( 'key saved', 'hal-mcp' ) : __( 'no key', 'hal-mcp' ) ); ?></summary>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><label for="hal-mcp-model-<?php echo esc_attr( $hal_mcp_id ); ?>"><?php esc_html_e( 'Model', 'hal-mcp' ); ?></label></th>
						<td>
							<input type="text" id="hal-mcp-model-<?php echo esc_attr( $hal_mcp_id ); ?>" class="regular-text" name="<?php echo esc_attr( HAL_MCP_SETTINGS_OPTION ); ?>[models][<?php echo esc_attr( $hal_mcp_id ); ?>]" value="<?php echo esc_attr( (string) ( $hal_mcp_settings['models'][ $hal_mcp_id ] ?? ( $hal_mcp_is_custom ? '' : (string) $hal_mcp_provider['default_model'] ) ) ); ?>" />
							<p class="description">
								<?php echo $hal_mcp_secret_status['set']
									? esc_html( sprintf( /* translators: %s: masked key fingerprint */ __( 'A key is saved (fingerprint %s). Leave the key field empty to keep it.', 'hal-mcp' ), (string) $hal_mcp_secret_status['hint'] ) )
									: esc_html__( 'No key saved for this provider yet.', 'hal-mcp' );
								?>
								<?php if ( '' !== (string) $hal_mcp_secret_status['error'] ) : ?>
									<br /><em><?php echo esc_html( (string) $hal_mcp_secret_status['error'] ); ?></em>
								<?php endif; ?>
							</p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="hal-mcp-key-<?php echo esc_attr( $hal_mcp_id ); ?>"><?php esc_html_e( 'API key', 'hal-mcp' ); ?></label></th>
						<td>
							<input type="password" id="hal-mcp-key-<?php echo esc_attr( $hal_mcp_id ); ?>" class="regular-text" name="<?php echo esc_attr( HAL_MCP_SETTINGS_OPTION ); ?>[api_keys][<?php echo esc_attr( $hal_mcp_id ); ?>]" value="" autocomplete="off" />
							<label>
								<input type="checkbox" name="<?php echo esc_attr( HAL_MCP_SETTINGS_OPTION ); ?>[remove_keys][<?php echo esc_attr( $hal_mcp_id ); ?>]" value="1" />
								<?php esc_html_e( 'Remove the saved key', 'hal-mcp' ); ?>
							</label>
						</td>
					</tr>
					<?php if ( $hal_mcp_is_custom ) : ?>
						<tr>
							<th scope="row"><label for="hal-mcp-protocol"><?php esc_html_e( 'Protocol', 'hal-mcp' ); ?></label></th>
							<td>
								<select id="hal-mcp-protocol" name="<?php echo esc_attr( HAL_MCP_SETTINGS_OPTION ); ?>[custom][protocol]">
									<option value=""><?php esc_html_e( '— choose —', 'hal-mcp' ); ?></option>
									<?php foreach ( HAL_MCP_PROVIDER_PROTOCOLS as $hal_mcp_protocol ) : ?>
										<option value="<?php echo esc_attr( $hal_mcp_protocol ); ?>" <?php selected( (string) ( $hal_mcp_settings['custom']['protocol'] ?? '' ), $hal_mcp_protocol ); ?>><?php echo esc_html( $hal_mcp_protocol ); ?></option>
									<?php endforeach; ?>
								</select>
							</td>
						</tr>
						<tr>
							<th scope="row"><label for="hal-mcp-custom-endpoint"><?php esc_html_e( 'Endpoint', 'hal-mcp' ); ?></label></th>
							<td>
								<input type="url" id="hal-mcp-custom-endpoint" class="large-text" name="<?php echo esc_attr( HAL_MCP_SETTINGS_OPTION ); ?>[endpoints][<?php echo esc_attr( $hal_mcp_id ); ?>]" value="<?php echo esc_attr( (string) ( $hal_mcp_settings['endpoints'][ $hal_mcp_id ] ?? '' ) ); ?>" />
								<p class="description"><?php esc_html_e( 'HTTPS only. Local and private addresses are refused.', 'hal-mcp' ); ?></p>
							</td>
						</tr>
					<?php else : ?>
						<tr>
							<th scope="row"><label for="hal-mcp-text-only-<?php echo esc_attr( $hal_mcp_id ); ?>"><?php esc_html_e( 'Capability', 'hal-mcp' ); ?></label></th>
							<td>
								<label>
									<input type="checkbox" id="hal-mcp-text-only-<?php echo esc_attr( $hal_mcp_id ); ?>" name="<?php echo esc_attr( HAL_MCP_SETTINGS_OPTION ); ?>[text_only][<?php echo esc_attr( $hal_mcp_id ); ?>]" value="1" <?php checked( ! empty( $hal_mcp_settings['text_only'][ $hal_mcp_id ] ) ); ?> />
									<?php esc_html_e( 'Use for text generation only (no site-editing tools)', 'hal-mcp' ); ?>
								</label>
							</td>
						</tr>
					<?php endif; ?>
				</table>
				<?php if ( ! $hal_mcp_is_custom ) : ?>
					<details class="hal-mcp-advanced">
						<summary><?php esc_html_e( 'Advanced: endpoint override', 'hal-mcp' ); ?></summary>
						<p>
							<label for="hal-mcp-endpoint-<?php echo esc_attr( $hal_mcp_id ); ?>"><?php esc_html_e( 'Endpoint URL', 'hal-mcp' ); ?></label><br />
							<input type="url" id="hal-mcp-endpoint-<?php echo esc_attr( $hal_mcp_id ); ?>" class="large-text" name="<?php echo esc_attr( HAL_MCP_SETTINGS_OPTION ); ?>[endpoints][<?php echo esc_attr( $hal_mcp_id ); ?>]" value="<?php echo esc_attr( (string) ( $hal_mcp_settings['endpoints'][ $hal_mcp_id ] ?? '' ) ); ?>" placeholder="<?php echo esc_attr( (string) $hal_mcp_provider['endpoint'] ); ?>" />
						</p>
						<p class="description"><?php esc_html_e( 'Leave empty to use the standard endpoint. HTTPS only; unsafe URLs are refused on save.', 'hal-mcp' ); ?></p>
						<p>
							<label for="hal-mcp-temperature-<?php echo esc_attr( $hal_mcp_id ); ?>"><?php esc_html_e( 'Temperature (optional)', 'hal-mcp' ); ?></label><br />
							<input type="number" id="hal-mcp-temperature-<?php echo esc_attr( $hal_mcp_id ); ?>" class="small-text" step="0.1" min="0" max="2" name="<?php echo esc_attr( HAL_MCP_SETTINGS_OPTION ); ?>[temperature][<?php echo esc_attr( $hal_mcp_id ); ?>]" value="<?php echo esc_attr( isset( $hal_mcp_settings['temperature'][ $hal_mcp_id ] ) ? (string) $hal_mcp_settings['temperature'][ $hal_mcp_id ] : '' ); ?>" placeholder="<?php esc_attr_e( 'default', 'hal-mcp' ); ?>" />
						</p>
						<p>
							<label for="hal-mcp-max-output-<?php echo esc_attr( $hal_mcp_id ); ?>"><?php esc_html_e( 'Max output tokens (optional)', 'hal-mcp' ); ?></label><br />
							<input type="number" id="hal-mcp-max-output-<?php echo esc_attr( $hal_mcp_id ); ?>" class="small-text" min="16" max="<?php echo esc_attr( (string) ( $hal_mcp_provider['max_output'][2] ?? 32768 ) ); ?>" name="<?php echo esc_attr( HAL_MCP_SETTINGS_OPTION ); ?>[max_output][<?php echo esc_attr( $hal_mcp_id ); ?>]" value="<?php echo esc_attr( isset( $hal_mcp_settings['max_output'][ $hal_mcp_id ] ) ? (string) $hal_mcp_settings['max_output'][ $hal_mcp_id ] : '' ); ?>" />
						</p>
						<p class="description"><?php esc_html_e( 'Both fields may stay empty — the provider defaults apply until you set them.', 'hal-mcp' ); ?></p>
					</details>
				<?php endif; ?>
			</details>
		<?php endforeach; ?>

		<h2><?php esc_html_e( 'Run limits and data policy', 'hal-mcp' ); ?></h2>
		<table class="form-table" role="presentation">
			<tr>
				<th scope="row"><label for="hal-mcp-policy-rounds"><?php esc_html_e( 'Max tool rounds per run', 'hal-mcp' ); ?></label></th>
				<td>
					<input type="number" id="hal-mcp-policy-rounds" min="1" max="<?php echo esc_attr( (string) HAL_MCP_RUNNER_MAX_ROUNDS ); ?>" name="<?php echo esc_attr( HAL_MCP_SETTINGS_OPTION ); ?>[policy][max_rounds]" value="<?php echo esc_attr( (string) ( $hal_mcp_settings['policy']['max_rounds'] ?? HAL_MCP_RUNNER_DEFAULT_ROUNDS ) ); ?>" class="small-text" />
					<p class="description"><?php esc_html_e( 'The hard ceiling is set by the code; this only lowers it.', 'hal-mcp' ); ?></p>
				</td>
			</tr>
			<tr>
				<th scope="row"><label for="hal-mcp-policy-seconds"><?php esc_html_e( 'Max seconds per run', 'hal-mcp' ); ?></label></th>
				<td>
					<input type="number" id="hal-mcp-policy-seconds" min="5" max="<?php echo esc_attr( (string) HAL_MCP_RUNNER_MAX_SECONDS ); ?>" name="<?php echo esc_attr( HAL_MCP_SETTINGS_OPTION ); ?>[policy][max_seconds]" value="<?php echo esc_attr( (string) ( $hal_mcp_settings['policy']['max_seconds'] ?? HAL_MCP_RUNNER_DEFAULT_SECONDS ) ); ?>" class="small-text" />
				</td>
			</tr>
			<tr>
				<th scope="row"><?php esc_html_e( 'Data on uninstall', 'hal-mcp' ); ?></th>
				<td>
					<label>
						<input type="checkbox" name="<?php echo esc_attr( HAL_MCP_SETTINGS_OPTION ); ?>[delete_data_on_uninstall]" value="1" <?php checked( ! empty( $hal_mcp_settings['delete_data_on_uninstall'] ) ); ?> />
						<?php esc_html_e( 'Delete this plugin\'s own data (settings, encrypted keys, audit log, internal requests) when the plugin is deleted. Site content is never deleted.', 'hal-mcp' ); ?>
					</label>
				</td>
			</tr>
		</table>

		<?php submit_button(); ?>
	</form>
	<?php
}

/**
 * The Approvals tab: the pending content-change list. Detail/decisions go
 * through admin.js to the REST routes; this render reads only.
 *
 * @return void
 */
function hal_mcp_admin_render_approvals(): void {

	$hal_mcp_rows = hal_mcp_list_change_requests( [ 'status' => 'pending', 'kind' => 'content_change', 'per_page' => 100 ] );
	?>
	<p><?php esc_html_e( 'A protected change applies only after you approve its exact saved version here. If the underlying content changed after approval, applying it is refused — review and approve again.', 'hal-mcp' ); ?></p>

	<div data-hal-role="approvals-list" class="hal-mcp-approvals">
		<?php if ( is_array( $hal_mcp_rows ) && [] !== $hal_mcp_rows ) : ?>
			<table class="widefat striped">
				<thead>
					<tr>
						<th><?php esc_html_e( 'Request', 'hal-mcp' ); ?></th>
						<th><?php esc_html_e( 'State', 'hal-mcp' ); ?></th>
						<th><?php esc_html_e( 'Requested', 'hal-mcp' ); ?></th>
						<th><?php esc_html_e( 'Actions', 'hal-mcp' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php foreach ( $hal_mcp_rows as $hal_mcp_row ) : ?>
						<tr data-request-id="<?php echo esc_attr( (string) $hal_mcp_row['request_id'] ); ?>">
							<td>
								<button type="button" class="button-link" data-hal-action="open-request" data-request-id="<?php echo esc_attr( (string) $hal_mcp_row['request_id'] ); ?>">#<?php echo esc_html( (string) $hal_mcp_row['request_id'] ); ?></button>
								<?php echo esc_html( wp_html_excerpt( (string) $hal_mcp_row['preview'], 120, '…' ) ); ?>
							</td>
							<td><?php echo esc_html( (string) $hal_mcp_row['state'] ); ?></td>
							<td><?php echo esc_html( (string) $hal_mcp_row['created_at'] ); ?></td>
							<td>
								<button type="button" class="button button-small" data-hal-action="approve" data-request-id="<?php echo esc_attr( (string) $hal_mcp_row['request_id'] ); ?>"><?php esc_html_e( 'Approve', 'hal-mcp' ); ?></button>
								<button type="button" class="button button-small" data-hal-action="reject" data-request-id="<?php echo esc_attr( (string) $hal_mcp_row['request_id'] ); ?>"><?php esc_html_e( 'Reject', 'hal-mcp' ); ?></button>
								<span class="hal-mcp-action-feedback" aria-live="polite"></span>
							</td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
		<?php else : ?>
			<p><em><?php esc_html_e( 'No change requests are waiting for approval.', 'hal-mcp' ); ?></em></p>
		<?php endif; ?>
	</div>

	<div data-hal-role="request-detail" class="hal-mcp-request-detail" hidden></div>
	<?php
}

/**
 * The Environment & Log tab: the full admin inventory, integration states,
 * the external-channel note, and the audit log with pagination.
 *
 * @return void
 */
function hal_mcp_admin_render_environment(): void {

	$hal_mcp_inventory = function_exists( 'hal_mcp_environment_inventory' ) ? hal_mcp_environment_inventory() : [];
	$hal_mcp_statuses  = function_exists( 'hal_mcp_environment_operation_statuses' ) ? hal_mcp_environment_operation_statuses() : [];
	$hal_mcp_channel   = function_exists( 'hal_mcp_external_channel_info' ) ? hal_mcp_external_channel_info() : [];
	$hal_mcp_log_page  = isset( $_GET['log_page'] ) ? max( 1, absint( $_GET['log_page'] ) ) : 1; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only pagination.
	$hal_mcp_log       = function_exists( 'hal_mcp_get_audit_log_entries' ) ? hal_mcp_get_audit_log_entries( $hal_mcp_log_page, 20 ) : null;
	?>
	<p>
		<button type="button" class="button" data-hal-action="refresh-environment"><?php esc_html_e( 'Re-run discovery', 'hal-mcp' ); ?></button>
		<span data-hal-role="refresh-status" aria-live="polite"></span>
	</p>

	<h2><?php esc_html_e( 'Operation status', 'hal-mcp' ); ?></h2>
	<ul>
		<?php foreach ( $hal_mcp_statuses as $hal_mcp_area => $hal_mcp_status_row ) : ?>
			<li>
				<span class="hal-mcp-status hal-mcp-status-<?php echo esc_attr( (string) ( $hal_mcp_status_row['status'] ?? 'unverified' ) ); ?>"><?php echo esc_html( (string) ( $hal_mcp_status_row['status'] ?? 'unverified' ) ); ?></span>
				<strong><?php echo esc_html( (string) $hal_mcp_area ); ?></strong>
				<?php if ( ! empty( $hal_mcp_status_row['reason'] ) ) : ?>
					— <?php echo esc_html( (string) $hal_mcp_status_row['reason'] ); ?>
				<?php endif; ?>
			</li>
		<?php endforeach; ?>
		<?php if ( [] === $hal_mcp_statuses ) : ?>
			<li><em><?php esc_html_e( 'The environment module is not loaded.', 'hal-mcp' ); ?></em></li>
		<?php endif; ?>
	</ul>

	<h2><?php esc_html_e( 'External channel (MCP)', 'hal-mcp' ); ?></h2>
	<?php if ( ! empty( $hal_mcp_channel ) ) : ?>
		<p>
			<span class="hal-mcp-status hal-mcp-status-<?php echo esc_attr( ! empty( $hal_mcp_channel['available'] ) ? 'available' : 'needs_setup' ); ?>">
				<?php echo esc_html( ! empty( $hal_mcp_channel['available'] ) ? __( 'available', 'hal-mcp' ) : __( 'not installed', 'hal-mcp' ) ); ?>
			</span>
			<?php echo esc_html( (string) ( $hal_mcp_channel['label'] ?? '' ) ); ?>
		</p>
		<p class="description"><?php echo esc_html( (string) ( $hal_mcp_channel['note'] ?? '' ) ); ?></p>
	<?php endif; ?>

	<h2><?php esc_html_e( 'Inventory (administrator view)', 'hal-mcp' ); ?></h2>
	<pre class="hal-mcp-inventory"><?php echo esc_html( (string) wp_json_encode( $hal_mcp_inventory, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) ); ?></pre>

	<h2><?php esc_html_e( 'Audit log', 'hal-mcp' ); ?></h2>
	<?php if ( is_wp_error( $hal_mcp_log ) || null === $hal_mcp_log ) : ?>
		<p><em><?php echo esc_html( is_wp_error( $hal_mcp_log ) ? $hal_mcp_log->get_error_message() : __( 'The audit log module is not loaded.', 'hal-mcp' ) ); ?></em></p>
	<?php else : ?>
		<table class="widefat striped">
			<thead>
				<tr>
					<th><?php esc_html_e( 'Time', 'hal-mcp' ); ?></th>
					<th><?php esc_html_e( 'Operation', 'hal-mcp' ); ?></th>
					<th><?php esc_html_e( 'Stage', 'hal-mcp' ); ?></th>
					<th><?php esc_html_e( 'Source', 'hal-mcp' ); ?></th>
					<th><?php esc_html_e( 'Summary', 'hal-mcp' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<?php foreach ( (array) $hal_mcp_log['entries'] as $hal_mcp_entry ) : ?>
					<tr>
						<td><?php echo esc_html( (string) ( $hal_mcp_entry['logged_at'] ?? '' ) ); ?></td>
						<td><?php echo esc_html( (string) ( $hal_mcp_entry['operation'] ?? '' ) ); ?></td>
						<td><?php echo esc_html( (string) ( $hal_mcp_entry['stage'] ?? '' ) ); ?></td>
						<td><?php echo esc_html( (string) ( $hal_mcp_entry['source'] ?? '' ) ); ?></td>
						<td><?php echo esc_html( (string) ( $hal_mcp_entry['result_summary'] ?? '' ) ); ?></td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
		<p class="hal-mcp-log-pagination">
			<?php if ( $hal_mcp_log_page > 1 ) : ?>
				<a class="button" href="<?php echo esc_url( admin_url( 'admin.php?page=' . HAL_MCP_ADMIN_PAGE . '&tab=environment&log_page=' . ( $hal_mcp_log_page - 1 ) ) ); ?>"><?php esc_html_e( 'Newer', 'hal-mcp' ); ?></a>
			<?php endif; ?>
			<a class="button" href="<?php echo esc_url( admin_url( 'admin.php?page=' . HAL_MCP_ADMIN_PAGE . '&tab=environment&log_page=' . ( $hal_mcp_log_page + 1 ) ) ); ?>"><?php esc_html_e( 'Older', 'hal-mcp' ); ?></a>
		</p>
	<?php endif; ?>
	<?php
}
