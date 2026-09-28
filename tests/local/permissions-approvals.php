<?php
/**
 * Local test runner for permissions and change-requests (V02).
 *
 * Covers the batch-2 contracts (F05/F06/F17) from the v2.0.0 execution
 * roadmap §9: unauthorized-object denial, protected-status write decisions,
 * approval gates (no approval from the model path), apply semantics (stored
 * payload only, fingerprint re-check, idempotency, partial failure), payload
 * invalidation on edit, audit redaction, and the admin-only log reader.
 *
 * Pure in-memory WordPress stubs — no site, no database, no network. Each case
 * runs in its own PHP subprocess, mirroring tests/local/run.php.
 *
 * Usage:
 *   php tests/local/permissions-approvals.php              # run every case
 *   php tests/local/permissions-approvals.php --case=NAME  # run one case
 *
 * Exit code 0 = all requested cases passed.
 *
 * @package hal-mcp-abilities
 */

if ( PHP_SAPI !== 'cli' ) {
	exit( "tests/local/permissions-approvals.php must run from the CLI only.\n" );
}

const HAL_TEST_CASE_OK = 'HAL_TEST_CASE_OK';

// ---------------------------------------------------------------------------
// Case list and orchestration
// ---------------------------------------------------------------------------

$hal_test_cases = [
	'permission_policy_object_checks',
	'write_path_policy_status_matrix',
	'approve_gates',
	'apply_requires_approval_and_uses_stored_payload',
	'payload_edit_invalidates_approval',
	'fingerprint_conflict_refuses_apply',
	'double_apply_is_idempotent',
	'partial_failure_records_state',
	'publish_effect_gate_and_list_scope',
	'requester_gate_and_payload_hygiene',
	'audit_redaction_and_admin_reader',
	'hal_write_post_contracts',
	'hal_content_type_boundary',
	'hal_page_write_contracts',
	'hal_page_read_contracts',
	'hal_product_write_contracts',
	'ability_schema_contracts',
];

$hal_test_only = null;
foreach ( array_slice( $argv, 1 ) as $hal_test_arg ) {
	if ( str_starts_with( $hal_test_arg, '--case=' ) ) {
		$hal_test_only = substr( $hal_test_arg, strlen( '--case=' ) );
	}
}

if ( null !== $hal_test_only ) {
	if ( ! in_array( $hal_test_only, $hal_test_cases, true ) ) {
		fwrite( STDERR, "Unknown case: {$hal_test_only}\n" );
		exit( 1 );
	}

	require_once __DIR__ . '/v02-stubs.php';
	hal_v02_reset_stubs();

	call_user_func( 'hal_test_case_' . $hal_test_only );

	echo HAL_TEST_CASE_OK . "\n";
	exit( 0 );
}

$hal_test_failures = 0;
foreach ( $hal_test_cases as $hal_test_case ) {
	$hal_test_cmd = escapeshellarg( PHP_BINARY ) . ' ' . escapeshellarg( __FILE__ ) . ' --case=' . escapeshellarg( $hal_test_case );
	$hal_test_out = [];
	exec( $hal_test_cmd . ' 2>&1', $hal_test_out, $hal_test_code );

	$hal_test_passed = 0 === $hal_test_code && str_contains( implode( "\n", $hal_test_out ), HAL_TEST_CASE_OK );

	printf(
		"%s %s\n",
		$hal_test_passed ? 'PASS' : 'FAIL',
		$hal_test_case
	);

	if ( ! $hal_test_passed ) {
		++$hal_test_failures;
		echo implode( "\n", $hal_test_out ) . "\n";
	}
}

printf(
	"\n%d case(s), %d failure(s)%s\n",
	count( $hal_test_cases ),
	$hal_test_failures,
	0 === $hal_test_failures ? ' — all green' : ''
);

exit( 0 === $hal_test_failures ? 0 : 1 );

// ---------------------------------------------------------------------------
// Cases (each runs in a fresh subprocess with a clean stub environment)
// ---------------------------------------------------------------------------
// ---------------------------------------------------------------------------
// Batch 4 (F10/F20): the real ability contracts over the stub world.
// ---------------------------------------------------------------------------

/**
 * F10: hal/create-post + hal/update-post run the requested_status contract
 * through the REAL request store and the REAL apply handler: drafts edit
 * directly, protected targets and publish requests become F17 rows, no-ops
 * are refused, and the handler's revalidate reads the live original.
 */
function hal_test_case_hal_write_post_contracts(): void {

	hal_v02_set_caps(
		[
			'primitives' => [ 'edit_posts' => true, 'manage_options' => true, 'publish_posts' => true ],
			'meta_caps'  => [ 'edit_post' => static fn( $id ) => true, 'read_post' => static fn( $id ) => true ],
		]
	);

	require_once __DIR__ . '/../../hal-mcp-abilities/abilities/write-posts.php';
	hal_mcp_register_write_post_abilities();

	$hal_test_update = hal_v02_state()['registered_abilities']['hal/update-post'] ?? null;
	$hal_test_create = hal_v02_state()['registered_abilities']['hal/create-post'] ?? null;
	hal_test_assert( is_array( $hal_test_update ) && is_array( $hal_test_create ), 'the write-post abilities must register over the stub API' );
	hal_test_assert( hal_mcp_has_apply_handler( 'update-post' ), 'the update-post apply handler must be registered at file load' );

	hal_v02_seed_post(
		[
			'ID' => 500, 'post_type' => 'post', 'post_status' => 'draft',
			'post_title' => 'Draft title', 'post_content' => 'Draft body',
			'post_author' => 1, 'post_excerpt' => '',
		]
	);

	// 1) Draft + a field change → applied directly through the shared
	// domain function, status untouched.
	$hal_test_direct = ( $hal_test_update['execute_callback'] )( [ 'post_id' => 500, 'title' => 'Direct edit' ] );
	hal_test_assert( is_array( $hal_test_direct ) && true === $hal_test_direct['applied_directly'], 'a draft field edit must apply directly' );
	hal_test_assert( 'Direct edit' === get_post( 500 )->post_title, 'the direct edit must reach the stub store' );
	hal_test_assert( 'draft' === $hal_test_direct['status'] && 0 === $hal_test_direct['request_id'], 'a direct edit must not carry a request' );

	// 1b) Categories on the DIRECT path: an unknown name returns a clean
	// WP_Error naming the missing term (the resolver returns instead of
	// fataling), and a seeded name/ID mix resolves and applies on the draft.
	$hal_test_unknown_cat = ( $hal_test_update['execute_callback'] )( [ 'post_id' => 500, 'categories' => [ 'NoSuchCategory' ] ] );
	hal_test_assert( is_wp_error( $hal_test_unknown_cat ), 'an unknown category name must be refused with a clean error, not a fatal' );
	hal_test_assert( str_contains( (string) $hal_test_unknown_cat->get_error_message(), 'do not exist' ), 'the unknown-category error must name the missing term' );

	hal_v02_seed_term( 5, 'Court Rulings' );
	hal_v02_seed_term( 6, 'Legal News' );

	$hal_test_cats_direct = ( $hal_test_update['execute_callback'] )( [ 'post_id' => 500, 'categories' => [ 'Legal News', 5 ] ] );
	hal_test_assert( is_array( $hal_test_cats_direct ) && true === $hal_test_cats_direct['applied_directly'], 'a draft categories edit must resolve seeded names and IDs and apply directly' );
	hal_test_assert( [ 6, 5 ] === (array) ( hal_v02_state()['posts_terms'][500] ?? [] ), 'the direct categories edit must reach the post term store' );

	// 2) requested_status=publish alone on the draft → ONE pending request,
	// no live change, no invented content ID.
	$hal_test_publish = ( $hal_test_update['execute_callback'] )( [ 'post_id' => 500, 'requested_status' => 'publish' ] );
	hal_test_assert( is_array( $hal_test_publish ) && false === $hal_test_publish['applied_directly'], 'a publish request alone must not claim to be applied' );
	hal_test_assert( 0 === $hal_test_publish['id'] && $hal_test_publish['request_id'] > 0, 'a prepared request must carry request_id, not a content ID' );
	hal_test_assert( 'pending_approval' === $hal_test_publish['state'], 'a fresh queued request must answer the model-facing pending_approval (the store row itself stays pending)' );
	hal_test_assert( 'draft' === get_post( 500 )->post_status, 'the live post must stay draft until a human approves' );

	// 3) Approve + apply through the REAL handler → published.
	$hal_test_approved = hal_mcp_approve_change_request( $hal_test_publish['request_id'] );
	hal_test_assert( ! is_wp_error( $hal_test_approved ) && 'approved' === $hal_test_approved['state'], 'the publish request must be approvable' );
	$hal_test_applied = hal_mcp_apply_change_request( $hal_test_publish['request_id'] );
	hal_test_assert( ! is_wp_error( $hal_test_applied ) && 'applied' === $hal_test_applied['state'], 'the approved publish request must apply cleanly' );
	hal_test_assert( 'publish' === get_post( 500 )->post_status, 'the handler must publish the draft after approval' );

	// 4) No-op refused: same status, no fields.
	$hal_test_noop = ( $hal_test_update['execute_callback'] )( [ 'post_id' => 500, 'requested_status' => 'publish' ] );
	hal_test_assert( is_wp_error( $hal_test_noop ), 'a change with no actual effect must be refused' );

	// 5) Published post + field → protected request with a snapshot of
	// EXACTLY the changed field; changing the live original after approval
	// turns the apply into a conflict (never a silent overwrite).
	$hal_test_protected = ( $hal_test_update['execute_callback'] )( [ 'post_id' => 500, 'title' => 'Proposed live change' ] );
	hal_test_assert( is_array( $hal_test_protected ) && false === $hal_test_protected['applied_directly'] && $hal_test_protected['request_id'] > 0, 'a published post edit must become a request' );
	$hal_test_stored = hal_mcp_get_change_request( $hal_test_protected['request_id'] );
	hal_test_assert( [ 'title' ] === array_keys( $hal_test_stored['payload'] ), 'the payload must carry exactly the changed fields' );
	hal_test_assert( [ 'title' ] === array_keys( $hal_test_stored['original_snapshot'] ), 'the snapshot must cover exactly the changed fields' );
	hal_test_assert( 'Proposed live change' === $hal_test_stored['payload']['title'], 'the stored payload must hold the sanitized proposal' );
	hal_mcp_approve_change_request( $hal_test_protected['request_id'] );
	update_post( 500, [ 'post_title' => 'Human edited meanwhile' ] );
	$hal_test_conflict = hal_mcp_apply_change_request( $hal_test_protected['request_id'] );
	hal_test_assert( is_wp_error( $hal_test_conflict ), 'applying over a changed original must be refused' );
	hal_test_assert( 'Human edited meanwhile' === get_post( 500 )->post_title, 'the conflict must have left the live title untouched' );

	// 5b) Categories on a PROTECTED target: the list payload ('categories'
	// carries integer list positions) must be storable as a request, and the
	// approved request must apply through the shared domain function.
	$hal_test_cats_req = ( $hal_test_update['execute_callback'] )( [ 'post_id' => 500, 'categories' => [ 'Legal News' ] ] );
	hal_test_assert( is_array( $hal_test_cats_req ) && false === $hal_test_cats_req['applied_directly'] && $hal_test_cats_req['request_id'] > 0, 'a categories change on a published post must be storable as a request' );
	hal_test_assert( ! is_wp_error( hal_mcp_approve_change_request( $hal_test_cats_req['request_id'] ) ), 'the categories request must be approvable' );
	hal_test_assert( ! is_wp_error( hal_mcp_apply_change_request( $hal_test_cats_req['request_id'] ) ), 'the approved categories request must apply' );
	hal_test_assert( [ 6 ] === (array) ( hal_v02_state()['posts_terms'][500] ?? [] ), 'the approved categories request must update the post terms' );

	// 6) Create with requested_status=publish → draft exists AND a pending
	// publish request rides on it.
	$hal_test_created = ( $hal_test_create['execute_callback'] )( [ 'title' => 'Fresh', 'content' => 'Body', 'requested_status' => 'publish' ] );
	hal_test_assert( is_array( $hal_test_created ) && $hal_test_created['id'] > 0, 'create with publish must still create the draft' );
	hal_test_assert( 'draft' === $hal_test_created['status'] && $hal_test_created['request_id'] > 0, 'create with publish must queue the approval' );

	// 7) A language the site does not offer is refused with the real reason.
	$hal_test_lang = ( $hal_test_update['execute_callback'] )( [ 'post_id' => 500, 'title' => 'X', 'language' => 'fr_FR' ] );
	hal_test_assert( is_wp_error( $hal_test_lang ), 'an unsupported language must be refused' );

	// 8) Trash is denied by the shared policy.
	hal_v02_seed_post(
		[
			'ID' => 501, 'post_type' => 'post', 'post_status' => 'trash',
			'post_title' => 'Trashed', 'post_content' => '', 'post_author' => 1,
		]
	);
	$hal_test_trash = ( $hal_test_update['execute_callback'] )( [ 'post_id' => 501, 'title' => 'X' ] );
	hal_test_assert( is_wp_error( $hal_test_trash ), 'a trashed post must be denied outright' );
}

/**
 * F20: the generic content tools respect the authorization boundary —
 * specialized types are refused with a pointer, unauthorized public types
 * are refused, an authorized type works end to end through a request, and
 * hal/get-request-status serves state only, to its allowed requester only.
 */
function hal_test_case_hal_content_type_boundary(): void {

	hal_v02_state()['post_types'] = [
		'hal_doc' => [
			'name'   => 'hal_doc',
			'label'  => 'Document',
			'public' => true,
			'cap'    => [
				'create_posts'  => 'edit_posts',
				'edit_post'     => 'edit_doc',
				'read_post'     => 'read_doc',
				'publish_posts' => 'publish_docs',
			],
		],
		'hal_secret' => [
			'name'   => 'hal_secret',
			'label'  => 'Internal thing',
			'public' => false,
			'cap'    => [
				'create_posts'  => 'edit_posts',
				'edit_post'     => 'edit_doc',
				'read_post'     => 'read_doc',
				'publish_posts' => 'publish_docs',
			],
		],
	];

	hal_v02_set_caps(
		[
			'primitives' => [ 'edit_posts' => true, 'manage_options' => true, 'publish_docs' => true, 'edit_doc' => true, 'read_doc' => true ],
			'meta_caps'  => [],
		]
	);

	require_once __DIR__ . '/../../hal-mcp-abilities/abilities/content.php';
	hal_mcp_register_content_abilities();

	$hal_test_registered = hal_v02_state()['registered_abilities'];
	hal_test_assert( isset( $hal_test_registered['hal/get-content'], $hal_test_registered['hal/search-content'], $hal_test_registered['hal/create-content'], $hal_test_registered['hal/update-content'], $hal_test_registered['hal/get-request-status'] ), 'all five F20 abilities must register' );

	$hal_test_get     = $hal_test_registered['hal/get-content'];
	$hal_test_create  = $hal_test_registered['hal/create-content'];
	$hal_test_update  = $hal_test_registered['hal/update-content'];
	$hal_test_status  = $hal_test_registered['hal/get-request-status'];

	// Specialized types are refused with guidance, not silently served.
	$hal_test_post_refused = ( $hal_test_get['execute_callback'] )( [ 'content_type' => 'post', 'content_id' => 1 ] );
	hal_test_assert( is_wp_error( $hal_test_post_refused ), 'hal/get-content must refuse posts (dedicated abilities exist)' );

	// Non-public types are not authorized even with a capability map.
	$hal_test_secret_refused = ( $hal_test_get['execute_callback'] )( [ 'content_type' => 'hal_secret', 'content_id' => 1 ] );
	hal_test_assert( is_wp_error( $hal_test_secret_refused ), 'hal/get-content must refuse unauthorized types' );

	// Authorized type, readable item → returned with its type and language.
	hal_v02_seed_post(
		[
			'ID' => 600, 'post_type' => 'hal_doc', 'post_status' => 'draft',
			'post_title' => 'Doc', 'post_content' => 'Doc body', 'post_author' => 1,
		]
	);
	$hal_test_item = ( $hal_test_get['execute_callback'] )( [ 'content_type' => 'hal_doc', 'content_id' => 600 ] );
	hal_test_assert( is_array( $hal_test_item ) && 'hal_doc' === $hal_test_item['type'] && 'Doc' === $hal_test_item['title'], 'an authorized item must be readable through the generic tool' );

	// Wrong type for the ID → generic not found (no type disclosure).
	$hal_test_wrong = ( $hal_test_get['execute_callback'] )( [ 'content_type' => 'hal_doc', 'content_id' => 500 ] );
	hal_test_assert( is_wp_error( $hal_test_wrong ), 'a type/ID mismatch must be a generic not-found' );

	// Create + publish request on the authorized type, applied by the REAL
	// generic handler.
	$hal_test_created = ( $hal_test_create['execute_callback'] )( [ 'content_type' => 'hal_doc', 'title' => 'New doc', 'content' => 'Body', 'requested_status' => 'publish' ] );
	hal_test_assert( is_array( $hal_test_created ) && $hal_test_created['id'] > 0 && $hal_test_created['request_id'] > 0, 'create on an authorized type must queue the publish approval' );
	hal_mcp_approve_change_request( $hal_test_created['request_id'] );
	$hal_test_applied = hal_mcp_apply_change_request( $hal_test_created['request_id'] );
	hal_test_assert( ! is_wp_error( $hal_test_applied ) && 'publish' === get_post( $hal_test_created['id'] )->post_status, 'the generic handler must publish the approved doc' );

	// Protected update on the doc → request; the stored target type is the
	// one the requester named, and the handler refuses a type/ID mismatch.
	$hal_test_upd = ( $hal_test_update['execute_callback'] )( [ 'content_type' => 'hal_doc', 'content_id' => $hal_test_created['id'], 'content' => 'Changed' ] );
	hal_test_assert( is_array( $hal_test_upd ) && false === $hal_test_upd['applied_directly'] && $hal_test_upd['request_id'] > 0, 'a published doc edit must become a request' );

	// hal/get-request-status: the requester sees state only — no payload,
	// no snapshot — and a stranger cannot even confirm existence.
	$hal_test_view = ( $hal_test_status['execute_callback'] )( [ 'request_id' => $hal_test_upd['request_id'] ] );
	hal_test_assert( is_array( $hal_test_view ) && 'pending' === $hal_test_view['state'], 'the requester must see the request state' );
	hal_test_assert( ! array_key_exists( 'payload', $hal_test_view ) && ! array_key_exists( 'original_snapshot', $hal_test_view ), 'the status view must never carry payload or snapshot' );
	hal_test_assert( str_contains( (string) $hal_test_view['preview'], 'hal_doc#' . $hal_test_created['id'] ), 'the preview must name the target ref' );

	hal_v02_set_user( 9, 'stranger' );
	hal_v02_set_caps(
		[
			'primitives' => [ 'manage_options' => false, 'edit_doc' => true, 'read_doc' => true ],
			'meta_caps'  => [],
		]
	);
	$hal_test_stranger = ( $hal_test_status['execute_callback'] )( [ 'request_id' => $hal_test_upd['request_id'] ] );
	hal_test_assert( is_wp_error( $hal_test_stranger ), 'an unauthorized caller must get a generic not-found' );
}

/**
 * F20: hal/create-page + hal/update-page over the stub world — draft
 * creation is direct, protected pages and publish requests ride F17, and an
 * Elementor-built page refuses content writes while its non-design fields
 * stay available.
 */
function hal_test_case_hal_page_write_contracts(): void {

	hal_v02_set_caps(
		[
			'primitives' => [ 'manage_options' => true, 'publish_pages' => true, 'edit_pages' => true ],
			'meta_caps'  => [ 'edit_page' => static fn( $id ) => true, 'read_page' => static fn( $id ) => true ],
		]
	);

	require_once __DIR__ . '/../../hal-mcp-abilities/abilities/read-pages.php';
	require_once __DIR__ . '/../../hal-mcp-abilities/abilities/write-pages.php';
	hal_mcp_register_write_page_abilities();

	$hal_test_registered = hal_v02_state()['registered_abilities'];
	$hal_test_create     = $hal_test_registered['hal/create-page'] ?? null;
	$hal_test_update     = $hal_test_registered['hal/update-page'] ?? null;
	hal_test_assert( is_array( $hal_test_create ) && is_array( $hal_test_update ), 'the page abilities must register over the stub API' );
	hal_test_assert( hal_mcp_has_apply_handler( 'update-page' ), 'the update-page apply handler must be registered at file load' );

	// 1) Create → draft directly; publish → draft + pending request.
	$hal_test_created = ( $hal_test_create['execute_callback'] )( [ 'title' => 'About', 'content' => '<!-- wp:paragraph --><p>About</p><!-- /wp:paragraph -->' ] );
	hal_test_assert( is_array( $hal_test_created ) && $hal_test_created['id'] > 0 && 'draft' === $hal_test_created['status'] && 0 === $hal_test_created['request_id'], 'a plain page create must be a direct draft' );

	$hal_test_publish = ( $hal_test_create['execute_callback'] )( [ 'title' => 'Contact', 'content' => 'Body', 'requested_status' => 'publish' ] );
	hal_test_assert( is_array( $hal_test_publish ) && $hal_test_publish['request_id'] > 0 && 'draft' === get_post( $hal_test_publish['id'] )->post_status, 'create with publish must keep the page a draft and queue approval' );
	hal_mcp_approve_change_request( $hal_test_publish['request_id'] );
	hal_mcp_apply_change_request( $hal_test_publish['request_id'] );
	hal_test_assert( 'publish' === get_post( $hal_test_publish['id'] )->post_status, 'the page handler must publish after approval' );

	// 2) Block page update → direct on a draft.
	hal_v02_seed_post(
		[
			'ID' => 700, 'post_type' => 'page', 'post_status' => 'draft',
			'post_title' => 'Draft page', 'post_content' => '<!-- wp:paragraph --><p>x</p><!-- /wp:paragraph -->',
			'post_author' => 1,
		]
	);
	$hal_test_direct = ( $hal_test_update['execute_callback'] )( [ 'page_id' => 700, 'title' => 'Renamed' ] );
	hal_test_assert( is_array( $hal_test_direct ) && true === $hal_test_direct['applied_directly'], 'a draft block page edit must apply directly' );

	// 3) Elementor-built page: content writes are refused with the real
	// reason; title changes still work (and ride the protected path here).
	hal_v02_seed_post(
		[
			'ID' => 701, 'post_type' => 'page', 'post_status' => 'publish',
			'post_title' => 'Elementor page', 'post_content' => '', 'post_author' => 1,
		]
	);
	update_post_meta( 701, '_elementor_data', '[{"id":"a1","elType":"section"}]' );
	$hal_test_refused = ( $hal_test_update['execute_callback'] )( [ 'page_id' => 701, 'content' => '<!-- wp:paragraph --><p>flattened</p><!-- /wp:paragraph -->' ] );
	hal_test_assert( is_wp_error( $hal_test_refused ), 'an Elementor page must refuse flattened content writes' );
	hal_test_assert( '' === get_post( 701 )->post_content, 'the Elementor design source must stay untouched' );
	$hal_test_title_only = ( $hal_test_update['execute_callback'] )( [ 'page_id' => 701, 'title' => 'Renamed via request' ] );
	hal_test_assert( is_array( $hal_test_title_only ) && false === $hal_test_title_only['applied_directly'] && $hal_test_title_only['request_id'] > 0, 'a non-design change to a published Elementor page must still become a request' );
	hal_test_assert( 'publish' === get_post( 701 )->post_status, 'the live page must be untouched while the request is pending' );

	// 4) Template validation: only theme-declared templates (or default).
	hal_v02_state()['page_templates'] = [ 'Full Width' => 'page-templates/full.php' ];
	$hal_test_template = ( $hal_test_update['execute_callback'] )( [ 'page_id' => 700, 'template' => 'page-templates/not-there.php' ] );
	hal_test_assert( is_wp_error( $hal_test_template ), 'an undeclared template must be refused' );
	$hal_test_template_ok = ( $hal_test_update['execute_callback'] )( [ 'page_id' => 700, 'template' => 'page-templates/full.php' ] );
	hal_test_assert( is_array( $hal_test_template_ok ) && true === $hal_test_template_ok['applied_directly'], 'a theme-declared template must apply on a draft' );
}


/**
 * F05: object-level checks through the registered mapping; internal and
 * unknown targets refused; denials logged once, quiet checks never logged.
 */
function hal_test_case_permission_policy_object_checks(): void {

	hal_v02_set_caps(
		[
			'primitives' => [ 'edit_posts' => true, 'manage_options' => true, 'upload_files' => true ],
			'meta_caps'  => [
				'edit_post'    => static fn( $id ) => 100 === (int) $id,
				'read_post'    => static fn( $id ) => 100 === (int) $id,
				'edit_product' => static fn( $id ) => true,
				'read_product' => static fn( $id ) => true,
			],
		]
	);

	hal_test_assert( hal_mcp_permission( 'post', 'edit', 100 ), 'editing an authorized post must pass' );
	hal_test_assert( ! hal_mcp_permission( 'post', 'edit', 999 ), 'editing an unauthorized post must be denied' );
	hal_test_assert( ! hal_mcp_permission( 'post', 'read', 999 ), 'reading an unauthorized post must be denied' );
	hal_test_assert( hal_mcp_permission( 'product', 'edit', 7 ), 'product edit follows the registered mapping' );

	// Unknown object type / operation: refused outright, never evaluated.
	hal_test_assert( ! hal_mcp_permission( 'order', 'edit', 5 ), 'unknown object type must be denied' );
	hal_test_assert( ! hal_mcp_permission( 'post', 'delete', 100 ), 'undeclared operation must be denied' );

	// Internal post types are never addressable.
	hal_test_assert( hal_mcp_is_internal_post_type( 'hal_mcp_request' ), 'hal_mcp_request must be marked internal' );

	// Every non-quiet denial above logged exactly one control event row.
	$hal_test_denials = hal_v02_audit_rows( 'permission_denied' );
	hal_test_assert( 4 === count( $hal_test_denials ), 'four denied checks must produce four denial rows (got ' . count( $hal_test_denials ) . ')' );

	// Quiet checks (bulk result filtering) must not log.
	hal_test_assert( ! hal_mcp_user_can_edit_object( 'post', 999, true ), 'quiet edit check must still deny' );
	hal_test_assert( 4 === count( hal_v02_audit_rows( 'permission_denied' ) ), 'quiet denial must not add a row' );
}

/**
 * F05: the shared direct/request/deny decision — draft/auto-draft direct,
 * protected statuses request, trash deny, protected field impact forces
 * request.
 */
function hal_test_case_write_path_policy_status_matrix(): void {

	foreach ( [ 'draft', 'auto-draft' ] as $hal_test_status ) {
		$hal_test_decision = hal_mcp_decide_write_path( 'post', $hal_test_status );
		hal_test_assert( 'direct' === $hal_test_decision['decision'], "{$hal_test_status} must be direct" );
	}

	foreach ( [ 'publish', 'future', 'private', 'pending', 'some-custom-status' ] as $hal_test_status ) {
		$hal_test_decision = hal_mcp_decide_write_path( 'post', $hal_test_status );
		hal_test_assert( 'request' === $hal_test_decision['decision'], "{$hal_test_status} must be protected (request)" );
	}

	hal_test_assert( 'deny' === hal_mcp_decide_write_path( 'post', 'trash' )['decision'], 'trash must be denied' );

	// Field impact overrides status: a protected-impact field on a draft is
	// still a request.
	hal_test_assert(
		'request' === hal_mcp_decide_write_path( 'post', 'draft', [ 'field_impact' => 'protected' ] )['decision'],
		'protected field impact must force the request path'
	);
}

/**
 * F05/F17: approval requires manage_options AND the capability the effect
 * needs on the target — a model-path caller (no manage_options) can never
 * approve, and a manager without the object capability cannot either.
 */
function hal_test_case_approve_gates(): void {

	hal_v02_set_user( 5, 'editor-agent' );
	hal_v02_set_caps(
		[
			'primitives' => [ 'manage_options' => false, 'edit_posts' => true ],
			'meta_caps'  => [ 'edit_post' => static fn( $id ) => true, 'read_post' => static fn( $id ) => true ],
		]
	);

	hal_mcp_register_apply_handler( 'update-post', static fn() => [ 'ok' => true ] );

	$hal_test_request = hal_mcp_create_change_request(
		[
			'operation'         => 'update-post',
			'payload'           => [ 'title' => 'New title' ],
			'targets'           => [ [ 'type' => 'post', 'id' => 100 ] ],
			'original_snapshot' => [ 'title' => 'Old title' ],
		]
	);

	hal_test_assert( ! is_wp_error( $hal_test_request ), 'request creation must succeed' );
	hal_test_assert( 'pending' === $hal_test_request['state'], 'a fresh request must be pending' );

	// Model path: no manage_options → refused, request stays pending.
	$hal_test_denied = hal_mcp_approve_change_request( $hal_test_request['request_id'] );
	hal_test_assert( is_wp_error( $hal_test_denied ), 'approval without manage_options must be refused' );
	hal_test_assert( 'pending' === hal_mcp_request_read_status( $hal_test_request['request_id'] ), 'refused approval must leave the request pending' );

	// Manager without the target capability → refused.
	hal_v02_set_user( 1, 'admin' );
	hal_v02_set_caps(
		[
			'primitives' => [ 'manage_options' => true ],
			'meta_caps'  => [ 'edit_post' => static fn( $id ) => false, 'read_post' => static fn( $id ) => false ],
		]
	);

	$hal_test_denied2 = hal_mcp_approve_change_request( $hal_test_request['request_id'] );
	hal_test_assert( is_wp_error( $hal_test_denied2 ), 'approval without the target capability must be refused' );
	hal_test_assert( 'pending' === hal_mcp_request_read_status( $hal_test_request['request_id'] ), 'still pending after the second refusal' );

	// Manager with the target capability → approved, approver recorded.
	hal_v02_set_caps(
		[
			'primitives' => [ 'manage_options' => true ],
			'meta_caps'  => [ 'edit_post' => static fn( $id ) => true, 'read_post' => static fn( $id ) => true ],
		]
	);

	$hal_test_approved = hal_mcp_approve_change_request( $hal_test_request['request_id'] );
	hal_test_assert( ! is_wp_error( $hal_test_approved ), 'manager with target capability must be able to approve' );
	hal_test_assert( 'approved' === $hal_test_approved['state'], 'state must move to approved' );
	hal_test_assert( 'admin' === $hal_test_approved['approver'], 'approver identity must be recorded' );

	// Both refusals and the approval were audited.
	hal_test_assert( 2 === count( hal_v02_audit_rows( 'approve_denied' ) ), 'both approval refusals must be audited' );
	hal_test_assert( 1 === count( hal_v02_audit_rows( 'approved' ) ), 'the approval must be audited' );
}

/**
 * F17: apply refuses unapproved requests, executes ONLY the stored payload,
 * and records applied state + result.
 */
function hal_test_case_apply_requires_approval_and_uses_stored_payload(): void {

	hal_v02_set_user( 5, 'editor-agent' );
	hal_v02_set_caps(
		[
			'primitives' => [ 'edit_posts' => true ],
			'meta_caps'  => [ 'edit_post' => static fn( $id ) => true, 'read_post' => static fn( $id ) => true ],
		]
	);

	$hal_test_seen_payload = null;
	hal_mcp_register_apply_handler(
		'update-post',
		static function ( $hal_test_request ) use ( &$hal_test_seen_payload ) {
			$hal_test_seen_payload = $hal_test_request['payload'];
			return [ 'applied' => true ];
		}
	);

	$hal_test_request = hal_mcp_create_change_request(
		[
			'operation'         => 'update-post',
			'payload'           => [ 'title' => 'Proposed B' ],
			'targets'           => [ [ 'type' => 'post', 'id' => 100 ] ],
			'original_snapshot' => [ 'title' => 'Original A' ],
		]
	);
	hal_test_assert( ! is_wp_error( $hal_test_request ), 'request creation must succeed' );

	// Apply before approval → refused, still pending.
	$hal_test_early = hal_mcp_apply_change_request( $hal_test_request['request_id'] );
	hal_test_assert( is_wp_error( $hal_test_early ), 'apply before approval must be refused' );
	hal_test_assert( 'pending' === hal_mcp_request_read_status( $hal_test_request['request_id'] ), 'refused apply must leave the request pending' );
	hal_test_assert( null === $hal_test_seen_payload, 'no handler may run before approval' );

	// Approve (manager) then apply.
	hal_v02_set_user( 1, 'admin' );
	hal_v02_set_caps(
		[
			'primitives' => [ 'manage_options' => true ],
			'meta_caps'  => [ 'edit_post' => static fn( $id ) => true, 'read_post' => static fn( $id ) => true ],
		]
	);

	hal_test_assert( ! is_wp_error( hal_mcp_approve_change_request( $hal_test_request['request_id'] ) ), 'approval must succeed' );

	$hal_test_applied = hal_mcp_apply_change_request( $hal_test_request['request_id'] );
	hal_test_assert( ! is_wp_error( $hal_test_applied ), 'apply after approval must succeed' );
	hal_test_assert( 'applied' === $hal_test_applied['state'], 'state must move to applied' );
	hal_test_assert( [ 'title' => 'Proposed B' ] === $hal_test_seen_payload, 'the handler must receive exactly the stored payload' );

	// Lifecycle audit trail: requested → approved → apply_started → applied.
	hal_test_assert( 1 === count( hal_v02_audit_rows( 'requested' ) ), 'the request must be audited' );
	hal_test_assert( 1 === count( hal_v02_audit_rows( 'apply_started' ) ), 'apply start must be audited' );
	hal_test_assert( 1 === count( hal_v02_audit_rows( 'applied' ) ), 'apply success must be audited' );
}

/**
 * F17/§4.3: editing the proposal resets it to pending, voids the previous
 * approval, and apply refuses until re-approved.
 */
function hal_test_case_payload_edit_invalidates_approval(): void {

	hal_v02_set_user( 5, 'editor-agent' );
	hal_v02_set_caps(
		[
			'primitives' => [ 'edit_posts' => true ],
			'meta_caps'  => [ 'edit_post' => static fn( $id ) => true, 'read_post' => static fn( $id ) => true ],
		]
	);
	hal_mcp_register_apply_handler( 'update-post', static fn() => [ 'ok' => true ] );

	$hal_test_request = hal_mcp_create_change_request(
		[
			'operation'         => 'update-post',
			'payload'           => [ 'title' => 'Version one' ],
			'targets'           => [ [ 'type' => 'post', 'id' => 100 ] ],
			'original_snapshot' => [ 'title' => 'Original' ],
		]
	);

	hal_v02_set_user( 1, 'admin' );
	hal_v02_set_caps(
		[
			'primitives' => [ 'manage_options' => true ],
			'meta_caps'  => [ 'edit_post' => static fn( $id ) => true, 'read_post' => static fn( $id ) => true ],
		]
	);
	hal_test_assert( ! is_wp_error( hal_mcp_approve_change_request( $hal_test_request['request_id'] ) ), 'approval must succeed' );

	// Edit the proposal → back to pending, approval voided.
	$hal_test_edited = hal_mcp_update_change_request_payload(
		$hal_test_request['request_id'],
		[ 'title' => 'Version two' ],
		[ 'title' => 'Original' ]
	);

	hal_test_assert( ! is_wp_error( $hal_test_edited ), 'proposal edit must succeed' );
	hal_test_assert( 'pending' === $hal_test_edited['state'], 'edited proposal must be back to pending' );
	hal_test_assert( '' === (string) get_post_meta( $hal_test_request['request_id'], '_hal_approver', true ), 'previous approval must be voided' );

	$hal_test_early = hal_mcp_apply_change_request( $hal_test_request['request_id'] );
	hal_test_assert( is_wp_error( $hal_test_early ), 'apply must be refused after the proposal changed' );

	// Re-approval is required and works on the new payload.
	hal_test_assert( ! is_wp_error( hal_mcp_approve_change_request( $hal_test_request['request_id'] ) ), 're-approval must succeed' );
	hal_test_assert( ! is_wp_error( hal_mcp_apply_change_request( $hal_test_request['request_id'] ) ), 'apply must succeed after re-approval' );
}

/**
 * F17/§4.3: a changed original is a conflict — no overwrite, handler never
 * runs, re-approval required.
 */
function hal_test_case_fingerprint_conflict_refuses_apply(): void {

	hal_v02_set_user( 1, 'admin' );
	hal_v02_set_caps(
		[
			'primitives' => [ 'manage_options' => true ],
			'meta_caps'  => [ 'edit_post' => static fn( $id ) => true, 'read_post' => static fn( $id ) => true ],
		]
	);

	$hal_test_handler_calls = 0;
	$hal_test_revalidate_calls = 0;
	hal_mcp_register_apply_handler(
		'update-post',
		static function () use ( &$hal_test_handler_calls ) {
			++$hal_test_handler_calls;
			return [ 'ok' => true ];
		},
		// Stateful live read, as a real revalidate behaves: the approval-time
		// anchor reads the ORIGINAL; the apply-time read sees it CHANGED.
		static function () use ( &$hal_test_revalidate_calls ) {
			++$hal_test_revalidate_calls;
			return hal_mcp_fingerprint(
				1 === $hal_test_revalidate_calls
					? [ 'title' => 'Original' ]
					: [ 'title' => 'Changed elsewhere' ]
			);
		}
	);

	$hal_test_request = hal_mcp_create_change_request(
		[
			'operation'         => 'update-post',
			'payload'           => [ 'title' => 'Proposed' ],
			'targets'           => [ [ 'type' => 'post', 'id' => 100 ] ],
			'original_snapshot' => [ 'title' => 'Original' ],
		]
	);

	hal_test_assert( ! is_wp_error( hal_mcp_approve_change_request( $hal_test_request['request_id'] ) ), 'approval must succeed' );

	// Approve-time anchoring: the stored baseline is now the LIVE read at
	// approval, not merely the requester-stamped snapshot value.
	hal_test_assert(
		hal_mcp_fingerprint( [ 'title' => 'Original' ] ) === (string) get_post_meta( $hal_test_request['request_id'], '_hal_original_fingerprint', true ),
		'approve must anchor the stored fingerprint to the live read'
	);

	$hal_test_result = hal_mcp_apply_change_request( $hal_test_request['request_id'] );

	hal_test_assert( is_wp_error( $hal_test_result ), 'apply must refuse on fingerprint mismatch' );
	hal_test_assert( 'conflict' === hal_mcp_request_read_status( $hal_test_request['request_id'] ), 'state must move to conflict' );
	hal_test_assert( 0 === $hal_test_handler_calls, 'the handler must never run on conflict' );
	hal_test_assert( 1 === count( hal_v02_audit_rows( 'conflict' ) ), 'the conflict must be audited' );
}

/**
 * F17/§9: repeating apply must not repeat the effect.
 */
function hal_test_case_double_apply_is_idempotent(): void {

	hal_v02_set_user( 1, 'admin' );
	hal_v02_set_caps(
		[
			'primitives' => [ 'manage_options' => true ],
			'meta_caps'  => [ 'edit_post' => static fn( $id ) => true, 'read_post' => static fn( $id ) => true ],
		]
	);

	$hal_test_handler_calls = 0;
	hal_mcp_register_apply_handler(
		'update-post',
		static function () use ( &$hal_test_handler_calls ) {
			++$hal_test_handler_calls;
			return [ 'ok' => true ];
		}
	);

	$hal_test_request = hal_mcp_create_change_request(
		[
			'operation'         => 'update-post',
			'payload'           => [ 'title' => 'Once only' ],
			'targets'           => [ [ 'type' => 'post', 'id' => 100 ] ],
			'original_snapshot' => [ 'title' => 'Original' ],
		]
	);

	hal_test_assert( ! is_wp_error( hal_mcp_approve_change_request( $hal_test_request['request_id'] ) ), 'approval must succeed' );
	hal_test_assert( ! is_wp_error( hal_mcp_apply_change_request( $hal_test_request['request_id'] ) ), 'first apply must succeed' );

	$hal_test_second = hal_mcp_apply_change_request( $hal_test_request['request_id'] );

	hal_test_assert( ! is_wp_error( $hal_test_second ), 'second apply must return cleanly' );
	hal_test_assert( true === ( $hal_test_second['already_applied'] ?? false ), 'second apply must report already_applied' );
	hal_test_assert( 1 === $hal_test_handler_calls, 'the handler must have run exactly once' );
}

/**
 * F06/F17: a handler failure records failed state and the error, and the
 * audit row exists — no success is claimed.
 */
function hal_test_case_partial_failure_records_state(): void {

	hal_v02_set_user( 1, 'admin' );
	hal_v02_set_caps(
		[
			'primitives' => [ 'manage_options' => true ],
			'meta_caps'  => [ 'edit_post' => static fn( $id ) => true, 'read_post' => static fn( $id ) => true ],
		]
	);

	hal_mcp_register_apply_handler(
		'update-post',
		static fn() => new WP_Error( 'hal_test_boom', 'field three could not be saved' )
	);

	$hal_test_request = hal_mcp_create_change_request(
		[
			'operation'         => 'update-post',
			'payload'           => [ 'title' => 'Will fail' ],
			'targets'           => [ [ 'type' => 'post', 'id' => 100 ] ],
			'original_snapshot' => [ 'title' => 'Original' ],
		]
	);

	hal_test_assert( ! is_wp_error( hal_mcp_approve_change_request( $hal_test_request['request_id'] ) ), 'approval must succeed' );

	$hal_test_result = hal_mcp_apply_change_request( $hal_test_request['request_id'] );

	hal_test_assert( is_wp_error( $hal_test_result ), 'a handler error must surface as WP_Error' );
	hal_test_assert( 'failed' === hal_mcp_request_read_status( $hal_test_request['request_id'] ), 'state must move to failed' );
	hal_test_assert( str_contains( (string) get_post_meta( $hal_test_request['request_id'], '_hal_result', true ), 'field three' ), 'the failure detail must be stored on the request' );
	hal_test_assert( 1 === count( hal_v02_audit_rows( 'apply_failed' ) ), 'the failure must be audited' );
}

/**
 * F05/F17: a requested_status=publish effect requires the publish primitive
 * at BOTH approve and apply (create-style and existing targets), and the
 * list scope: managers see everything, requesters only their own.
 */
function hal_test_case_publish_effect_gate_and_list_scope(): void {

	hal_v02_set_user( 5, 'editor-agent' );
	hal_v02_set_caps(
		[
			'primitives' => [ 'edit_posts' => true ],
			'meta_caps'  => [ 'edit_post' => static fn( $id ) => true, 'read_post' => static fn( $id ) => true ],
		]
	);
	hal_mcp_register_apply_handler( 'update-post', static fn() => [ 'ok' => true ] );

	$hal_test_existing = hal_mcp_create_change_request(
		[
			'operation'         => 'update-post',
			'payload'           => [ 'requested_status' => 'publish', 'title' => 'Go live' ],
			'targets'           => [ [ 'type' => 'post', 'id' => 100 ] ],
			'original_snapshot' => [ 'title' => 'Original' ],
		]
	);
	$hal_test_create_style = hal_mcp_create_change_request(
		[
			'operation'         => 'update-post',
			'payload'           => [ 'requested_status' => 'publish', 'title' => 'New content' ],
			'targets'           => [ [ 'type' => 'post', 'id' => 0 ] ],
			'original_snapshot' => [],
		]
	);
	hal_test_assert( ! is_wp_error( $hal_test_existing ) && ! is_wp_error( $hal_test_create_style ), 'both publish-intent requests must be created' );

	// Manager WITHOUT the publish primitive: both refused.
	hal_v02_set_user( 1, 'admin' );
	hal_v02_set_caps(
		[
			'primitives' => [ 'manage_options' => true ],
			'meta_caps'  => [ 'edit_post' => static fn( $id ) => true, 'read_post' => static fn( $id ) => true ],
		]
	);

	hal_test_assert( is_wp_error( hal_mcp_approve_change_request( $hal_test_existing['request_id'] ) ), 'publish intent on an existing target must require the publish primitive' );
	hal_test_assert( is_wp_error( hal_mcp_approve_change_request( $hal_test_create_style['request_id'] ) ), 'publish intent on a create-style target must require the publish primitive' );

	// Manager WITH the publish primitive: both approved.
	hal_v02_set_caps(
		[
			'primitives' => [ 'manage_options' => true, 'publish_posts' => true ],
			'meta_caps'  => [ 'edit_post' => static fn( $id ) => true, 'read_post' => static fn( $id ) => true ],
		]
	);

	hal_test_assert( ! is_wp_error( hal_mcp_approve_change_request( $hal_test_existing['request_id'] ) ), 'approval with the publish primitive must succeed' );
	hal_test_assert( ! is_wp_error( hal_mcp_approve_change_request( $hal_test_create_style['request_id'] ) ), 'create-style approval with the publish primitive must succeed' );

	// Apply-time re-check: revoke the primitive → apply refuses, state stays
	// approved.
	hal_v02_set_caps(
		[
			'primitives' => [ 'manage_options' => true ],
			'meta_caps'  => [ 'edit_post' => static fn( $id ) => true, 'read_post' => static fn( $id ) => true ],
		]
	);

	$hal_test_apply = hal_mcp_apply_change_request( $hal_test_existing['request_id'] );
	hal_test_assert( is_wp_error( $hal_test_apply ), 'apply must re-check the publish effect' );
	hal_test_assert( 'approved' === hal_mcp_request_read_status( $hal_test_existing['request_id'] ), 'a refused apply must leave the request approved' );

	// List scope: managers see everything, requesters only their own.
	hal_v02_set_user( 9, 'other-editor' );
	hal_v02_set_caps(
		[
			'primitives' => [ 'edit_posts' => true ],
			// The requester gate at create() requires the requester to hold
			// the object edit capability on the target — a realistic second
			// editor has it on their own content.
			'meta_caps'  => [ 'edit_post' => static fn( $id ) => true, 'read_post' => static fn( $id ) => true ],
		]
	);
	hal_mcp_create_change_request(
		[
			'operation'         => 'update-post',
			'payload'           => [ 'title' => 'Other editor proposal' ],
			'targets'           => [ [ 'type' => 'post', 'id' => 200 ] ],
			'original_snapshot' => [ 'title' => 'Other original' ],
		]
	);

	hal_v02_set_user( 1, 'admin' );
	hal_v02_set_caps( [ 'primitives' => [ 'manage_options' => true ], 'meta_caps' => [] ] );
	hal_test_assert( 3 === count( hal_mcp_list_change_requests() ), 'a manager must see all requests' );

	hal_v02_set_user( 5, 'editor-agent' );
	hal_v02_set_caps( [ 'primitives' => [ 'edit_posts' => true ], 'meta_caps' => [] ] );
	hal_test_assert( 2 === count( hal_mcp_list_change_requests() ), 'a requester must see only their own requests' );
}

/**
 * F17/§4.3: the requester must hold the capability on each target before a
 * request is stored; non-finite floats are rejected; hostile field names
 * never persist raw HTML in the preview title; an unfingerprintable
 * original is refused at apply.
 */
function hal_test_case_requester_gate_and_payload_hygiene(): void {

	// Requester WITHOUT the target capability → create refused.
	hal_v02_set_user( 5, 'editor-agent' );
	hal_v02_set_caps(
		[
			'primitives' => [ 'edit_posts' => true ],
			'meta_caps'  => [ 'edit_post' => static fn( $id ) => false, 'read_post' => static fn( $id ) => true ],
		]
	);
	hal_mcp_register_apply_handler( 'update-post', static fn() => [ 'ok' => true ] );

	$hal_test_denied = hal_mcp_create_change_request(
		[
			'operation'         => 'update-post',
			'payload'           => [ 'title' => 'Not mine' ],
			'targets'           => [ [ 'type' => 'post', 'id' => 300 ] ],
			'original_snapshot' => [ 'title' => 'Original' ],
		]
	);
	hal_test_assert( is_wp_error( $hal_test_denied ), 'create must refuse a requester without the target capability' );

	// Unknown target type → refused (the policy has no 'order' key).
	$hal_test_bad_type = hal_mcp_create_change_request(
		[
			'operation'         => 'update-post',
			'payload'           => [ 'title' => 'X' ],
			'targets'           => [ [ 'type' => 'order', 'id' => 1 ] ],
			'original_snapshot' => [ 'title' => 'Original' ],
		]
	);
	hal_test_assert( is_wp_error( $hal_test_bad_type ), 'create must refuse an unknown target type' );

	// Requester WITH the capability → create works, and the preview title
	// never carries raw HTML from a hostile field name.
	hal_v02_set_caps(
		[
			'primitives' => [ 'edit_posts' => true ],
			'meta_caps'  => [ 'edit_post' => static fn( $id ) => true, 'read_post' => static fn( $id ) => true ],
		]
	);

	$hal_test_request = hal_mcp_create_change_request(
		[
			'operation'         => 'update-post',
			'payload'           => [ '<script>alert(1)</script>' => 'value' ],
			'targets'           => [ [ 'type' => 'post', 'id' => 100 ] ],
			'original_snapshot' => [ 'title' => 'Original' ],
		]
	);
	hal_test_assert( ! is_wp_error( $hal_test_request ), 'create with a hostile field name must still succeed' );

	$hal_test_title = (string) get_post( $hal_test_request['request_id'] )->post_title;
	hal_test_assert( ! str_contains( $hal_test_title, '<script>' ), 'the preview title must not persist raw HTML' );

	// NAN/INF in a payload → rejected (empty fingerprints would silently
	// disable the pre-apply conflict check).
	foreach ( [ NAN, INF, -INF ] as $hal_test_bad_float ) {
		$hal_test_nan = hal_mcp_create_change_request(
			[
				'operation'         => 'update-post',
				'payload'           => [ 'weight' => $hal_test_bad_float ],
				'targets'           => [ [ 'type' => 'post', 'id' => 100 ] ],
				'original_snapshot' => [ 'title' => 'Original' ],
			]
		);
		hal_test_assert( is_wp_error( $hal_test_nan ), 'non-finite floats must be rejected from payloads (NAN/INF iteration)' );
	}

	// Fail-safe: an EMPTY stored fingerprint over a non-empty snapshot is
	// refused at apply (legacy rows from before the sanitizer gate).
	hal_v02_set_user( 1, 'admin' );
	hal_v02_set_caps(
		[
			'primitives' => [ 'manage_options' => true, 'publish_posts' => true ],
			'meta_caps'  => [ 'edit_post' => static fn( $id ) => true, 'read_post' => static fn( $id ) => true ],
		]
	);

	$hal_test_request2 = hal_mcp_create_change_request(
		[
			'operation'         => 'update-post',
			'payload'           => [ 'title' => 'Will be legacy' ],
			'targets'           => [ [ 'type' => 'post', 'id' => 100 ] ],
			'original_snapshot' => [ 'title' => 'Original' ],
		]
	);

	// Simulate a legacy row: wipe the stored original fingerprint.
	update_post_meta( $hal_test_request2['request_id'], '_hal_original_fingerprint', '' );

	hal_test_assert( ! is_wp_error( hal_mcp_approve_change_request( $hal_test_request2['request_id'] ) ), 'approval must succeed' );

	$hal_test_unverified = hal_mcp_apply_change_request( $hal_test_request2['request_id'] );
	hal_test_assert( is_wp_error( $hal_test_unverified ), 'apply must refuse an unfingerprintable original' );
	hal_test_assert( 'conflict' === hal_mcp_request_read_status( $hal_test_request2['request_id'] ), 'the row must end in conflict, never applied' );

	// admin_assist requests are never approvable.
	$hal_test_assist = hal_mcp_create_admin_assist_request( 'Rotate the SMTP credentials please' );
	hal_test_assert( ! is_wp_error( $hal_test_assist ), 'admin-assist creation must succeed' );
	hal_test_assert( is_wp_error( hal_mcp_approve_change_request( $hal_test_assist['request_id'] ) ), 'admin_assist must not be approvable' );
}

/**
 * F06: sensitive keys and long base64 runs are redacted in stored rows, and
 * the log reader is manage_options-gated.
 */
function hal_test_case_audit_redaction_and_admin_reader(): void {

	hal_v02_set_user( 1, 'admin' );
	hal_v02_set_caps(
		[
			'primitives' => [ 'manage_options' => true ],
			'meta_caps'  => [],
		]
	);

	// 700 consecutive base64-alphabet characters (no '=' breaks): the kind of
	// encoded run the redactor is required to drop.
	$hal_test_long_base64 = str_repeat( 'QUJDREVGR0hJSktMTU5PUFFSU1RVVldYWVo', 20 );

	hal_mcp_log_control_event(
		[
			'operation'      => 'test:redaction',
			'stage'          => 'requested',
			'source'         => 'model',
			'success'        => true,
			'result_summary' => 'payload was ' . $hal_test_long_base64,
			'context'        => [
				'api_key'       => 'sk-super-secret-123',
				'Authorization' => 'Bearer abc.def.ghi',
				'nested'        => [ 'session_token' => 'hunter2' ],
				'note'          => 'harmless text stays',
			],
		]
	);

	// Plain-string secrets the JSON walk can never see: a JSON fragment
	// embedded in prose, a Bearer header, and a deep-nested secret (the
	// redaction wall must fail safe, not fail silent).
	hal_mcp_log_control_event(
		[
			'operation'      => 'test:redaction2',
			'stage'          => 'requested',
			'source'         => 'model',
			'success'        => true,
			'result_summary' => 'Connection failed: {"api_key": "sk-plainstring-987654321"} trailing text',
			'context'        => [
				'error_note' => 'Authorization: Bearer eyJhbGciOiJIUzI1NiJ9.payload.signature',
				'deep'       => [ 'l1' => [ 'l2' => [ 'l3' => [ 'l4' => [ 'l5' => [ 'l6' => [ 'l7' => [ 'l8' => [ 'password' => 'deep-secret-42', 'wrapper' => [ 'hidden' => 'buried-value-77' ] ] ] ] ] ] ] ] ] ],
			],
		]
	);

	// Bare key shapes in plain prose: the sk- floor must match the admin.js
	// preview helper and the http-client mask (8+ tail), and a Google AIza key
	// is provider key material wherever it appears.
	hal_mcp_log_control_event(
		[
			'operation'      => 'test:redaction3',
			'stage'          => 'requested',
			'source'         => 'model',
			'success'        => true,
			'result_summary' => 'upstream said: bad key sk-ant-short12345 and bad key AIzaSyA-shortkey0',
			'context'        => [ 'note' => 'bare key shapes in prose' ],
		]
	);

	$hal_test_rows = hal_v02_all_audit_rows();
	hal_test_assert( 3 === count( $hal_test_rows ), 'all three control-event rows must be stored' );

	$hal_test_row = null;
	foreach ( $hal_test_rows as $hal_test_candidate ) {
		if ( 'test:redaction' === $hal_test_candidate['operation'] ) {
			$hal_test_row = $hal_test_candidate;
		}
	}
	$hal_test_row2 = null;
	foreach ( $hal_test_rows as $hal_test_candidate ) {
		if ( 'test:redaction2' === $hal_test_candidate['operation'] ) {
			$hal_test_row2 = $hal_test_candidate;
		}
	}
	hal_test_assert( null !== $hal_test_row && null !== $hal_test_row2, 'both rows must carry their operation names' );
	hal_test_assert( ! str_contains( $hal_test_row['input_summary'], 'sk-super-secret-123' ), 'api_key value must be redacted' );
	hal_test_assert( ! str_contains( $hal_test_row['input_summary'], 'hunter2' ), 'nested session_token must be redacted' );
	hal_test_assert( str_contains( $hal_test_row['input_summary'], 'harmless text stays' ), 'non-sensitive content must survive' );
	hal_test_assert( str_contains( $hal_test_row['input_summary'], '<redacted>' ), 'sensitive keys must show the redaction placeholder' );
	hal_test_assert( ! str_contains( $hal_test_row['result_summary'], $hal_test_long_base64 ), 'long base64 in the result must be redacted' );
	hal_test_assert( str_contains( $hal_test_row['result_summary'], '<redacted base64>' ), 'the base64 placeholder must be present' );

	// Plain-string coverage: JSON fragment in prose, Bearer header, deep
	// nesting at the redaction wall (fail-safe, not fail-silent).
	hal_test_assert( ! str_contains( $hal_test_row2['result_summary'], 'sk-plainstring-987654321' ), 'a JSON-embedded api_key in prose must be redacted' );
	hal_test_assert( ! str_contains( $hal_test_row2['input_summary'], 'eyJhbGciOiJIUzI1NiJ9.payload.signature' ), 'a Bearer token in a plain string must be redacted' );
	hal_test_assert( ! str_contains( $hal_test_row2['input_summary'], 'deep-secret-42' ), 'a secret at depth 9 must not survive the redaction wall' );
	hal_test_assert( ! str_contains( $hal_test_row2['input_summary'], 'buried-value-77' ), 'an array past the wall must be wholesale-redacted, not skipped' );

	// Bare provider key shapes in prose (batch 8): the audit redactor's sk-
	// floor must match the admin.js helper (8+ tail) and Google AIza keys are
	// key material wherever they appear.
	$hal_test_row3 = null;
	foreach ( $hal_test_rows as $hal_test_candidate ) {
		if ( 'test:redaction3' === $hal_test_candidate['operation'] ) {
			$hal_test_row3 = $hal_test_candidate;
		}
	}
	hal_test_assert( null !== $hal_test_row3, 'the bare-key-shape row must be stored' );
	hal_test_assert( ! str_contains( $hal_test_row3['result_summary'], 'sk-ant-short12345' ), 'a short-tail sk- key (8-15 chars) in prose must be redacted like the admin.js helper does' );
	hal_test_assert( ! str_contains( $hal_test_row3['result_summary'], 'AIzaSyA-shortkey0' ), 'a bare Google AIza key in prose must be redacted' );
	hal_test_assert( str_contains( $hal_test_row3['result_summary'], '<redacted_key>' ), 'bare key shapes must carry the key placeholder' );

	// Admin-only reader.
	$hal_test_read = hal_mcp_get_audit_log_entries( 1, 20 );
	hal_test_assert( ! is_wp_error( $hal_test_read ), 'a manager must be able to read the log' );
	hal_test_assert( 3 === count( $hal_test_read['entries'] ), 'the reader must return the stored rows' );

	hal_v02_set_caps( [ 'primitives' => [ 'manage_options' => false ], 'meta_caps' => [] ] );

	$hal_test_denied = hal_mcp_get_audit_log_entries( 1, 20 );
	hal_test_assert( is_wp_error( $hal_test_denied ), 'a non-manager must be refused the log' );
}

/**
 * F12 read side over the stub world: block pages return the markup as the
 * design source with an honest SEO slot; Elementor pages return a bounded
 * outline and never their JSON as content; per-page permission denies read
 * like a not-found; search rows carry the type and skip unreadable pages.
 */
function hal_test_case_hal_page_read_contracts(): void {

	hal_v02_set_caps(
		[
			'primitives' => [ 'edit_pages' => true, 'manage_options' => true ],
			'meta_caps'  => [
				'edit_page' => static fn( $id ) => true,
				'read_page' => static fn( $id ) => 702 !== (int) $id,
			],
		]
	);

	require_once __DIR__ . '/../../hal-mcp-abilities/abilities/read-pages.php';
	hal_mcp_register_read_page_abilities();

	$hal_test_get    = hal_v02_state()['registered_abilities']['hal/get-page'] ?? null;
	$hal_test_search = hal_v02_state()['registered_abilities']['hal/search-pages'] ?? null;
	hal_test_assert( is_array( $hal_test_get ) && is_array( $hal_test_search ), 'the page read abilities must register over the stub API' );

	hal_v02_seed_post(
		[
			'ID' => 700, 'post_type' => 'page', 'post_status' => 'draft',
			'post_title' => 'Block page', 'post_content' => '<!-- wp:paragraph --><p>x</p><!-- /wp:paragraph -->',
			'post_author' => 1,
		]
	);
	update_post_meta( 700, '_wp_page_template', 'page-templates/full.php' );

	hal_v02_seed_post(
		[
			'ID' => 701, 'post_type' => 'page', 'post_status' => 'publish',
			'post_title' => 'Elementor page', 'post_content' => '', 'post_author' => 1,
		]
	);
	update_post_meta( 701, '_elementor_data', '[{"id":"a1","elType":"section","widgetType":"heading"}]' );

	hal_v02_seed_post(
		[
			'ID' => 702, 'post_type' => 'page', 'post_status' => 'draft',
			'post_title' => 'Stranger draft', 'post_content' => 'hidden', 'post_author' => 9,
		]
	);

	// Block page: markup IS the source; editor identity from stored data.
	$hal_test_block = ( $hal_test_get['execute_callback'] )( [ 'page_id' => 700 ] );
	hal_test_assert( is_array( $hal_test_block ), 'get-page must return the block page' );
	hal_test_assert( 'block_markup' === $hal_test_block['content_source'] && 'blocks' === $hal_test_block['editor']['id'], 'a block page must report block_markup as its source' );
	hal_test_assert( str_starts_with( (string) $hal_test_block['content'], '<!-- wp:paragraph -->' ), 'the returned content must be the stored markup, not rendered HTML' );
	hal_test_assert( 'page-templates/full.php' === $hal_test_block['template'], 'the template slug must be read from the page meta' );
	hal_test_assert( false === $hal_test_block['seo_summary']['available'] && '' !== $hal_test_block['seo_summary']['note'], 'the SEO slot must report its unavailability honestly' );
	$hal_test_keys = array_column( $hal_test_block['editable_elements'], 'key' );
	hal_test_assert( in_array( 'title', $hal_test_keys, true ) && in_array( 'requested_status', $hal_test_keys, true ), 'editable elements must name the writable keys' );

	// Elementor page: bounded outline, content empty, never the JSON.
	$hal_test_elementor = ( $hal_test_get['execute_callback'] )( [ 'page_id' => 701 ] );
	hal_test_assert( 'elementor_json' === $hal_test_elementor['content_source'] && 'elementor' === $hal_test_elementor['editor']['id'], 'an Elementor page must report its editor identity' );
	hal_test_assert( '' === $hal_test_elementor['content'], 'the Elementor design JSON must never be returned as content' );
	hal_test_assert( [ 'id' => 'a1', 'type' => 'section', 'widget_type' => 'heading' ] === $hal_test_elementor['sections'][0], 'the outline must carry the section id/type only' );

	// Missing ID and unreadable page: same generic not-found.
	hal_test_assert( is_wp_error( ( $hal_test_get['execute_callback'] )( [ 'page_id' => 999 ] ) ), 'a missing page must be a generic not-found' );
	hal_test_assert( is_wp_error( ( $hal_test_get['execute_callback'] )( [ 'page_id' => 702 ] ) ), 'another user\u0027s unreadable page must be a generic not-found' );

	// Search: rows typed as page; the unreadable page is skipped silently.
	$hal_test_rows = ( $hal_test_search['execute_callback'] )( [ 'query' => 'page' ] );
	$hal_test_ids  = array_column( $hal_test_rows, 'id' );
	hal_test_assert( in_array( 700, $hal_test_ids, true ) && in_array( 701, $hal_test_ids, true ), 'readable pages must appear in search results' );
	hal_test_assert( ! in_array( 702, $hal_test_ids, true ), 'the unreadable page must be skipped without disclosure' );
	foreach ( $hal_test_rows as $hal_test_row ) {
		hal_test_assert( 'page' === $hal_test_row['type'], 'every search row must carry its type' );
	}
}

/**
 * F11 write contracts over fake WooCommerce classes: type preserved through
 * every request, per-type field refusal, variation gated by its PARENT
 * status, SKU duplication a clean error, and price edits on a published
 * product ride an approval request with a matching snapshot and conflict
 * protection.
 */
function hal_test_case_hal_product_write_contracts(): void {

	// --- Fake WooCommerce surface (this case's subprocess only). ---
	if ( ! class_exists( 'WC_Data_Exception' ) ) {
		// WooCommerce's WC_Data_Exception is constructed as ($code, $message).
		class WC_Data_Exception extends Exception {
			public function __construct( string $code = '', string $message = '' ) {
				parent::__construct( $message, 0 );
			}
		}
	}
	if ( ! class_exists( 'WC_Product_Attribute' ) ) {
		class WC_Product_Attribute {
			private $name = '';
			private $options = [];
			private $position = 0;
			private $visible = false;
			private $variation = false;
			public function set_name( $v ): void { $this->name = (string) $v; }
			public function get_name(): string { return $this->name; }
			public function set_options( $v ): void { $this->options = (array) $v; }
			public function get_options(): array { return $this->options; }
			public function set_position( $v ): void { $this->position = (int) $v; }
			public function get_position(): int { return $this->position; }
			public function set_visible( $v ): void { $this->visible = (bool) $v; }
			public function get_visible(): bool { return $this->visible; }
			public function set_variation( $v ): void { $this->variation = (bool) $v; }
			public function get_variation(): bool { return $this->variation; }
		}
	}
	if ( ! class_exists( 'WC_Product' ) ) {
		class WC_Product {
			public $hal_id = 0;
			public function __construct( int $id = 0 ) { $this->hal_id = $id; }
			// New instances (create flow) buffer their fields until save()
			// allocates the real ID, mirroring WooCommerce's own create path.
			protected function hal_set( string $key, $value ): void {
				if ( $this->hal_id > 0 ) {
					hal_v02_state()['products'][ $this->hal_id ][ $key ] = $value;
					return;
				}
				hal_v02_state()['new_product_buffer'][ $key ] = $value;
			}
			protected function hal_get( string $key, $default = '' ) {
				if ( $this->hal_id > 0 ) {
					return hal_v02_state()['products'][ $this->hal_id ][ $key ] ?? $default;
				}
				return hal_v02_state()['new_product_buffer'][ $key ] ?? $default;
			}
			public function get_id(): int { return $this->hal_id; }
			public function get_status(): string { return (string) $this->hal_get( 'status', 'draft' ); }
			public function set_status( $v ): void { $this->hal_set( 'status', (string) $v ); }
			public function get_type(): string { return (string) $this->hal_get( 'type', 'simple' ); }
			public function get_parent_id(): int { return (int) $this->hal_get( 'parent_id', 0 ); }
			public function get_name(): string { return (string) $this->hal_get( 'name' ); }
			public function set_name( $v ): void { $this->hal_set( 'name', (string) $v ); }
			public function get_description(): string { return (string) $this->hal_get( 'description' ); }
			public function set_description( $v ): void { $this->hal_set( 'description', (string) $v ); }
			public function get_short_description(): string { return (string) $this->hal_get( 'short_description' ); }
			public function set_short_description( $v ): void { $this->hal_set( 'short_description', (string) $v ); }
			public function get_regular_price(): string { return (string) $this->hal_get( 'regular_price' ); }
			public function set_regular_price( $v ): void { $this->hal_set( 'regular_price', (string) $v ); }
			public function get_sale_price(): string { return (string) $this->hal_get( 'sale_price' ); }
			public function set_sale_price( $v ): void { $this->hal_set( 'sale_price', (string) $v ); }
			public function get_manage_stock(): bool { return (bool) $this->hal_get( 'manage_stock', false ); }
			public function set_manage_stock( $v ): void { $this->hal_set( 'manage_stock', (bool) $v ); }
			public function get_stock_quantity(): int { return (int) $this->hal_get( 'stock_quantity', 0 ); }
			public function set_stock_quantity( $v ): void { $this->hal_set( 'stock_quantity', (int) $v ); }
			public function get_stock_status(): string { return (string) $this->hal_get( 'stock_status', 'instock' ); }
			public function set_stock_status( $v ): void { $this->hal_set( 'stock_status', (string) $v ); }
			public function get_image_id(): int { return (int) $this->hal_get( 'image_id', 0 ); }
			public function set_image_id( $v ): void { $this->hal_set( 'image_id', (int) $v ); }
			public function get_gallery_image_ids(): array { return (array) $this->hal_get( 'gallery', [] ); }
			public function set_gallery_image_ids( $v ): void { $this->hal_set( 'gallery', array_map( 'intval', (array) $v ) ); }
			public function get_sku(): string { return (string) $this->hal_get( 'sku' ); }
			public function set_sku( $v ): void {
				$v = (string) $v;
				if ( '' !== $v ) {
					foreach ( hal_v02_state()['products'] as $hal_pid => $hal_row ) {
						if ( $hal_pid !== $this->hal_id && ( $hal_row['sku'] ?? '' ) === $v ) {
							throw new WC_Data_Exception( 'invalid_sku', 'Invalid or duplicated SKU.' );
						}
					}
				}
				$this->hal_set( 'sku', $v );
			}
			public function save(): int {
				if ( $this->hal_id > 0 ) {
					if ( isset( hal_v02_state()['posts'][ $this->hal_id ] ) ) {
						hal_v02_state()['posts'][ $this->hal_id ]['post_status'] = $this->get_status();
					}
					return $this->hal_id;
				}

				$hal_new_id = ++hal_v02_state()['next_id'];
				hal_v02_state()['products'][ $hal_new_id ] = array_merge(
					[ 'type' => 'simple', 'status' => 'draft', 'parent_id' => 0 ],
					hal_v02_state()['new_product_buffer']
				);
				hal_v02_state()['posts'][ $hal_new_id ] = [
					'ID' => $hal_new_id,
					'post_type' => 'product',
					'post_status' => (string) ( hal_v02_state()['new_product_buffer']['status'] ?? 'draft' ),
					'post_title' => (string) ( hal_v02_state()['new_product_buffer']['name'] ?? '' ),
					'post_author' => 1,
					'post_date' => '2026-09-17 10:00:00',
				];
				hal_v02_state()['new_product_buffer'] = [];
				$this->hal_id = $hal_new_id;
				return $hal_new_id;
			}
		}
		class WC_Product_Simple extends WC_Product {
			public function get_type(): string { return 'simple'; }
		}
		class WC_Product_Variable extends WC_Product {
			public function get_type(): string { return 'variable'; }
		}
		class WC_Product_Variation extends WC_Product {
			public function get_type(): string { return 'variation'; }
		}
	}
	if ( ! function_exists( 'wc_get_product' ) ) {
		function wc_get_product( $id ) {
			$hal_row = hal_v02_state()['products'][ (int) $id ] ?? null;
			if ( null === $hal_row ) {
				return false;
			}
			$hal_class = 'WC_Product_Simple';
			if ( 'variable' === $hal_row['type'] ) {
				$hal_class = 'WC_Product_Variable';
			} elseif ( 'variation' === $hal_row['type'] ) {
				$hal_class = 'WC_Product_Variation';
			}
			return new $hal_class( (int) $id );
		}
	}
	if ( ! function_exists( 'hal_v02_seed_product' ) ) {
		function hal_v02_seed_product( int $id, string $type, string $status, array $extra = [] ): void {
			hal_v02_state()['products'][ $id ] = array_merge(
				[ 'type' => $type, 'status' => $status, 'name' => '', 'description' => '', 'short_description' => '', 'regular_price' => '', 'sale_price' => '', 'sku' => '', 'manage_stock' => false, 'stock_quantity' => 0, 'stock_status' => 'instock', 'parent_id' => 0, 'gallery' => [] ],
				$extra
			);
			hal_v02_state()['posts'][ $id ] = [
				'ID' => $id,
				'post_type' => 'variation' === $type ? 'product_variation' : 'product',
				'post_status' => $status,
				'post_title' => (string) ( $extra['name'] ?? '' ),
				'post_author' => 1,
				'post_date' => '2026-09-17 10:00:00',
			];
		}
	}

	hal_v02_set_caps(
		[
			'primitives' => [ 'edit_products' => true, 'manage_options' => true, 'publish_products' => true ],
			// read_post is the attachment read mapping the images validation
			// resolves through (mirrors hal_test_case_ability_schema_contracts()).
			'meta_caps'  => [ 'edit_product' => static fn( $id ) => true, 'read_product' => static fn( $id ) => true, 'read_post' => static fn( $id ) => true ],
		]
	);

	require_once __DIR__ . '/../../hal-mcp-abilities/abilities/write-products.php';
	hal_mcp_register_write_product_abilities();

	$hal_test_create = hal_v02_state()['registered_abilities']['hal/create-product'] ?? null;
	$hal_test_update = hal_v02_state()['registered_abilities']['hal/update-product'] ?? null;
	hal_test_assert( is_array( $hal_test_create ) && is_array( $hal_test_update ), 'the product abilities must register over the stub API' );
	hal_test_assert( hal_mcp_has_apply_handler( 'update-product' ), 'the update-product apply handler must be registered at file load' );

	hal_v02_seed_product( 800, 'simple', 'draft', [ 'name' => 'Draft product', 'regular_price' => '10.00' ] );
	hal_v02_seed_product( 801, 'simple', 'publish', [ 'name' => 'Live product', 'regular_price' => '10.00' ] );
	hal_v02_seed_product( 810, 'variation', 'publish', [ 'name' => 'Variation of live', 'parent_id' => 801, 'regular_price' => '12.00' ] );

	// 1) Create simple → draft directly; variable refuses parent prices.
	$hal_test_made = ( $hal_test_create['execute_callback'] )( [ 'name' => 'New', 'type' => 'simple' ] );
	hal_test_assert( is_array( $hal_test_made ) && 'draft' === $hal_test_made['status'] && 'simple' === $hal_test_made['type'] && 0 === $hal_test_made['request_id'], 'a plain create must be a direct simple draft' );
	$hal_test_variable = ( $hal_test_create['execute_callback'] )( [ 'name' => 'Var', 'type' => 'variable', 'regular_price' => '9.99' ] );
	hal_test_assert( is_wp_error( $hal_test_variable ), 'a variable parent must refuse its own price field' );

	// 2) Draft simple edit → direct, type preserved.
	$hal_test_direct = ( $hal_test_update['execute_callback'] )( [ 'product_id' => 800, 'name' => 'Renamed draft' ] );
	hal_test_assert( is_array( $hal_test_direct ) && true === $hal_test_direct['applied_directly'] && 'simple' === $hal_test_direct['type'], 'a draft product edit must apply directly keeping its type' );

	// 3) Unsupported field for the type → refused before anything is stored.
	$hal_test_unsupported = ( $hal_test_update['execute_callback'] )( [ 'product_id' => 810, 'name' => 'No renaming variations' ] );
	hal_test_assert( is_wp_error( $hal_test_unsupported ), 'a variation must refuse the name field' );

	// 4) Publish request on the draft → applied by the REAL handler, type
	// preserved through the request path.
	$hal_test_publish = ( $hal_test_update['execute_callback'] )( [ 'product_id' => 800, 'requested_status' => 'publish' ] );
	hal_test_assert( is_array( $hal_test_publish ) && false === $hal_test_publish['applied_directly'] && $hal_test_publish['request_id'] > 0, 'publishing a draft product must queue a request' );
	hal_mcp_approve_change_request( $hal_test_publish['request_id'] );
	$hal_test_applied = hal_mcp_apply_change_request( $hal_test_publish['request_id'] );
	hal_test_assert( ! is_wp_error( $hal_test_applied ) && 'publish' === get_post( 800 )->post_status, 'the approved publish request must apply' );
	hal_test_assert( 'simple' === hal_v02_state()['products'][800]['type'], 'the product type must be unchanged after the request applied' );

	// 5) Price edit on the PUBLISHED product → request with an exact
	// snapshot; a changed original after approval → conflict, no overwrite.
	$hal_test_price = ( $hal_test_update['execute_callback'] )( [ 'product_id' => 801, 'regular_price' => '25.00' ] );
	hal_test_assert( is_array( $hal_test_price ) && false === $hal_test_price['applied_directly'] && $hal_test_price['request_id'] > 0, 'a published product price edit must become a request' );
	$hal_test_stored = hal_mcp_get_change_request( $hal_test_price['request_id'] );
	hal_test_assert( [ 'regular_price' ] === array_keys( $hal_test_stored['payload'] ) && [ 'regular_price' ] === array_keys( $hal_test_stored['original_snapshot'] ), 'the price request must carry exactly the changed field and its snapshot' );
	hal_mcp_approve_change_request( $hal_test_price['request_id'] );
	hal_v02_state()['products'][801]['regular_price'] = '99.99';
	hal_test_assert( is_wp_error( hal_mcp_apply_change_request( $hal_test_price['request_id'] ) ), 'applying over a changed original must conflict' );
	hal_test_assert( '99.99' === hal_v02_state()['products'][801]['regular_price'], 'the conflict must leave the live price untouched' );

	// 6) Variation of a PUBLISHED product: gated by the parent → request,
	// applied only after approval.
	$hal_test_variation = ( $hal_test_update['execute_callback'] )( [ 'product_id' => 810, 'regular_price' => '14.00' ] );
	hal_test_assert( is_array( $hal_test_variation ) && false === $hal_test_variation['applied_directly'] && $hal_test_variation['request_id'] > 0, 'a variation of a published product must ride the request path' );
	hal_test_assert( '12.00' === hal_v02_state()['products'][810]['regular_price'], 'the live variation price must be untouched while pending' );
	hal_mcp_approve_change_request( $hal_test_variation['request_id'] );
	hal_test_assert( ! is_wp_error( hal_mcp_apply_change_request( $hal_test_variation['request_id'] ) ), 'the approved variation request must apply' );
	hal_test_assert( '14.00' === hal_v02_state()['products'][810]['regular_price'], 'the variation price must update after approval' );

	// 7) Duplicate SKU on the direct path → clean WP_Error, not a fatal.
	// (800 is published by now, so the attempt must come from a DRAFT product
	// to stay on the direct path where set_sku() actually runs.)
	hal_v02_seed_product( 802, 'simple', 'draft', [ 'name' => 'Taken SKU', 'sku' => 'TAKEN' ] );
	hal_v02_seed_product( 803, 'simple', 'draft', [ 'name' => 'Draft with free sku' ] );
	$hal_test_sku = ( $hal_test_update['execute_callback'] )( [ 'product_id' => 803, 'sku' => 'TAKEN' ] );
	hal_test_assert( is_wp_error( $hal_test_sku ), 'a duplicate SKU must return a clean error' );

	// 8) Language-only no-op refused.
	hal_test_assert( is_wp_error( ( $hal_test_update['execute_callback'] )( [ 'product_id' => 800, 'language' => 'en_US' ] ) ), 'a language-only no-op must be refused' );

	// 9) Images on a PUBLISHED product → the REAL list payload ('images'
	// carries integer list positions) is storable as a request, and a product
	// image changed between approval and apply conflicts — the live image and
	// gallery stay untouched end to end. The images validation needs real
	// attachment rows plus the attachment read capability.
	hal_v02_seed_post( [ 'ID' => 55, 'post_type' => 'attachment', 'post_status' => 'inherit', 'post_title' => 'Image 55', 'post_author' => 1 ] );
	hal_v02_seed_post( [ 'ID' => 56, 'post_type' => 'attachment', 'post_status' => 'inherit', 'post_title' => 'Image 56', 'post_author' => 1 ] );
	hal_v02_seed_post( [ 'ID' => 66, 'post_type' => 'attachment', 'post_status' => 'inherit', 'post_title' => 'Image 66', 'post_author' => 1 ] );
	hal_v02_seed_product( 804, 'simple', 'publish', [ 'name' => 'Img product', 'image_id' => 55, 'gallery' => [ 56 ] ] );

	$hal_test_images_req = ( $hal_test_update['execute_callback'] )( [ 'product_id' => 804, 'images' => [ 66, 55 ] ] );
	hal_test_assert( is_array( $hal_test_images_req ) && false === $hal_test_images_req['applied_directly'] && $hal_test_images_req['request_id'] > 0, 'a published product images edit must be storable as a request' );
	hal_test_assert( ! is_wp_error( hal_mcp_approve_change_request( $hal_test_images_req['request_id'] ) ), 'the images request must be approvable' );

	hal_v02_state()['products'][804]['image_id'] = 77;
	$hal_test_images_conflict = hal_mcp_apply_change_request( $hal_test_images_req['request_id'] );
	hal_test_assert( is_wp_error( $hal_test_images_conflict ), 'applying over a changed product image must conflict' );
	hal_test_assert( 77 === (int) hal_v02_state()['products'][804]['image_id'] && [ 56 ] === (array) hal_v02_state()['products'][804]['gallery'], 'the conflict must leave the live product image and gallery untouched' );

	// 10) A non-positive image ID on the DIRECT path → clean typed error,
	// never absint()-ed into a different attachment.
	hal_v02_seed_product( 805, 'simple', 'draft', [ 'name' => 'Draft image product' ] );
	$hal_test_bad_image = ( $hal_test_update['execute_callback'] )( [ 'product_id' => 805, 'images' => [ -3 ] ] );
	hal_test_assert( is_wp_error( $hal_test_bad_image ) && 'hal_mcp_invalid_product_image' === array_key_first( (array) $hal_test_bad_image->errors ), 'a non-positive image ID on the direct path must be refused with a clean error' );
}

/**
 * Batch 4 audit (F08–F13, F20): every registered ability carries the schema
 * contract (closed input schema, non-empty properties, mcp.public, callable
 * callbacks); featured_image_id=0 really removes the thumbnail while a
 * non-existent ID stays refused (F08/F20); and the product images request
 * conflicts when the product image changes between approval and apply
 * (F11/F17 — the fingerprint must cover the product image, not only the
 * gallery).
 */
function hal_test_case_ability_schema_contracts(): void {

	// --- Fake WooCommerce surface (this case's subprocess only), the same
	// guarded pattern hal_test_case_hal_product_write_contracts() uses. ---
	if ( ! class_exists( 'WC_Data_Exception' ) ) {
		// WooCommerce's WC_Data_Exception is constructed as ($code, $message).
		class WC_Data_Exception extends Exception {
			public function __construct( string $code = '', string $message = '' ) {
				parent::__construct( $message, 0 );
			}
		}
	}
	if ( ! class_exists( 'WC_Product_Attribute' ) ) {
		class WC_Product_Attribute {
			private $name = '';
			private $options = [];
			private $position = 0;
			private $visible = false;
			private $variation = false;
			public function set_name( $v ): void { $this->name = (string) $v; }
			public function get_name(): string { return $this->name; }
			public function set_options( $v ): void { $this->options = (array) $v; }
			public function get_options(): array { return $this->options; }
			public function set_position( $v ): void { $this->position = (int) $v; }
			public function get_position(): int { return $this->position; }
			public function set_visible( $v ): void { $this->visible = (bool) $v; }
			public function get_visible(): bool { return $this->visible; }
			public function set_variation( $v ): void { $this->variation = (bool) $v; }
			public function get_variation(): bool { return $this->variation; }
		}
	}
	if ( ! class_exists( 'WC_Product' ) ) {
		class WC_Product {
			public $hal_id = 0;
			public function __construct( int $id = 0 ) { $this->hal_id = $id; }
			protected function hal_set( string $key, $value ): void {
				if ( $this->hal_id > 0 ) {
					hal_v02_state()['products'][ $this->hal_id ][ $key ] = $value;
					return;
				}
				hal_v02_state()['new_product_buffer'][ $key ] = $value;
			}
			protected function hal_get( string $key, $default = '' ) {
				if ( $this->hal_id > 0 ) {
					return hal_v02_state()['products'][ $this->hal_id ][ $key ] ?? $default;
				}
				return hal_v02_state()['new_product_buffer'][ $key ] ?? $default;
			}
			public function get_id(): int { return $this->hal_id; }
			public function get_status(): string { return (string) $this->hal_get( 'status', 'draft' ); }
			public function set_status( $v ): void { $this->hal_set( 'status', (string) $v ); }
			public function get_type(): string { return (string) $this->hal_get( 'type', 'simple' ); }
			public function get_parent_id(): int { return (int) $this->hal_get( 'parent_id', 0 ); }
			public function get_name(): string { return (string) $this->hal_get( 'name' ); }
			public function set_name( $v ): void { $this->hal_set( 'name', (string) $v ); }
			public function get_image_id(): int { return (int) $this->hal_get( 'image_id', 0 ); }
			public function set_image_id( $v ): void { $this->hal_set( 'image_id', (int) $v ); }
			public function get_gallery_image_ids(): array { return (array) $this->hal_get( 'gallery', [] ); }
			public function set_gallery_image_ids( $v ): void { $this->hal_set( 'gallery', array_map( 'intval', (array) $v ) ); }
			public function get_sku(): string { return (string) $this->hal_get( 'sku' ); }
			public function set_sku( $v ): void { $this->hal_set( 'sku', (string) $v ); }
			public function save(): int {
				if ( $this->hal_id > 0 ) {
					if ( isset( hal_v02_state()['posts'][ $this->hal_id ] ) ) {
						hal_v02_state()['posts'][ $this->hal_id ]['post_status'] = $this->get_status();
					}
					return $this->hal_id;
				}

				$hal_new_id = ++hal_v02_state()['next_id'];
				hal_v02_state()['products'][ $hal_new_id ] = array_merge(
					[ 'type' => 'simple', 'status' => 'draft', 'parent_id' => 0 ],
					hal_v02_state()['new_product_buffer']
				);
				hal_v02_state()['posts'][ $hal_new_id ] = [
					'ID' => $hal_new_id,
					'post_type' => 'product',
					'post_status' => (string) ( hal_v02_state()['new_product_buffer']['status'] ?? 'draft' ),
					'post_title' => (string) ( hal_v02_state()['new_product_buffer']['name'] ?? '' ),
					'post_author' => 1,
					'post_date' => '2026-09-17 10:00:00',
				];
				hal_v02_state()['new_product_buffer'] = [];
				$this->hal_id = $hal_new_id;
				return $hal_new_id;
			}
		}
		class WC_Product_Simple extends WC_Product {
			public function get_type(): string { return 'simple'; }
		}
		class WC_Product_Variable extends WC_Product {
			public function get_type(): string { return 'variable'; }
		}
		class WC_Product_Variation extends WC_Product {
			public function get_type(): string { return 'variation'; }
		}
	}
	if ( ! function_exists( 'wc_get_product' ) ) {
		function wc_get_product( $id ) {
			$hal_row = hal_v02_state()['products'][ (int) $id ] ?? null;
			if ( null === $hal_row ) {
				return false;
			}
			$hal_class = 'WC_Product_Simple';
			if ( 'variable' === $hal_row['type'] ) {
				$hal_class = 'WC_Product_Variable';
			} elseif ( 'variation' === $hal_row['type'] ) {
				$hal_class = 'WC_Product_Variation';
			}
			return new $hal_class( (int) $id );
		}
	}
	if ( ! function_exists( 'hal_v02_seed_product' ) ) {
		function hal_v02_seed_product( int $id, string $type, string $status, array $extra = [] ): void {
			hal_v02_state()['products'][ $id ] = array_merge(
				[ 'type' => $type, 'status' => $status, 'name' => '', 'description' => '', 'short_description' => '', 'regular_price' => '', 'sale_price' => '', 'sku' => '', 'manage_stock' => false, 'stock_quantity' => 0, 'stock_status' => 'instock', 'parent_id' => 0, 'gallery' => [] ],
				$extra
			);
			hal_v02_state()['posts'][ $id ] = [
				'ID' => $id,
				'post_type' => 'variation' === $type ? 'product_variation' : 'product',
				'post_status' => $status,
				'post_title' => (string) ( $extra['name'] ?? '' ),
				'post_author' => 1,
				'post_date' => '2026-09-17 10:00:00',
			];
		}
	}

	hal_v02_set_caps(
		[
			'primitives' => [ 'edit_posts' => true, 'edit_pages' => true, 'edit_products' => true, 'manage_options' => true ],
			'meta_caps'  => [
				'edit_post'    => static fn( $id ) => true,
				'read_post'    => static fn( $id ) => true,
				'edit_page'    => static fn( $id ) => true,
				'read_page'    => static fn( $id ) => true,
				'edit_product' => static fn( $id ) => true,
				'read_product' => static fn( $id ) => true,
			],
		]
	);

	// Load every ability module exactly as the bootstrap would with the
	// Abilities API present, then run the registrars the hook would fire
	// (V02's add_action stub records nothing, so the hook never fires).
	require_once __DIR__ . '/../../hal-mcp-abilities/abilities/read-posts.php';
	require_once __DIR__ . '/../../hal-mcp-abilities/abilities/read-products.php';
	require_once __DIR__ . '/../../hal-mcp-abilities/abilities/read-media.php';
	require_once __DIR__ . '/../../hal-mcp-abilities/abilities/read-pages.php';
	require_once __DIR__ . '/../../hal-mcp-abilities/abilities/write-posts.php';
	require_once __DIR__ . '/../../hal-mcp-abilities/abilities/write-products.php';
	require_once __DIR__ . '/../../hal-mcp-abilities/abilities/write-pages.php';
	require_once __DIR__ . '/../../hal-mcp-abilities/abilities/content.php';
	require_once __DIR__ . '/../../hal-mcp-abilities/abilities/write-media.php';

	hal_mcp_register_read_post_abilities();
	hal_mcp_register_read_product_abilities();
	hal_mcp_register_read_media_abilities();
	hal_mcp_register_read_page_abilities();
	hal_mcp_register_write_post_abilities();
	hal_mcp_register_write_product_abilities();
	hal_mcp_register_write_page_abilities();
	hal_mcp_register_content_abilities();

	// write-media only registers a hook at load; the registrar itself must
	// exist and must land hal/upload-media in the stub registry.
	hal_test_assert( function_exists( 'hal_mcp_register_write_media_abilities' ), 'write-media must load and expose its registrar' );
	hal_mcp_register_write_media_abilities();

	$hal_test_registered = hal_v02_state()['registered_abilities'];

	hal_test_assert(
		isset(
			$hal_test_registered['hal/get-post'],
			$hal_test_registered['hal/search-posts'],
			$hal_test_registered['hal/get-product'],
			$hal_test_registered['hal/search-products'],
			$hal_test_registered['hal/list-media'],
			$hal_test_registered['hal/get-page'],
			$hal_test_registered['hal/search-pages'],
			$hal_test_registered['hal/create-post'],
			$hal_test_registered['hal/update-post'],
			$hal_test_registered['hal/create-product'],
			$hal_test_registered['hal/update-product'],
			$hal_test_registered['hal/create-page'],
			$hal_test_registered['hal/update-page'],
			$hal_test_registered['hal/get-content'],
			$hal_test_registered['hal/search-content'],
			$hal_test_registered['hal/create-content'],
			$hal_test_registered['hal/update-content'],
			$hal_test_registered['hal/get-request-status'],
			$hal_test_registered['hal/upload-media']
		),
		'all nine ability modules must register their abilities over the stub API'
	);

	// The schema contract every ability must satisfy.
	foreach ( $hal_test_registered as $hal_test_name => $hal_test_args ) {
		hal_test_assert( false === ( $hal_test_args['input_schema']['additionalProperties'] ?? true ), "{$hal_test_name}: input_schema must reject unexpected fields" );
		hal_test_assert( ! empty( $hal_test_args['input_schema']['properties'] ), "{$hal_test_name}: input_schema must declare properties" );
		hal_test_assert( true === ( $hal_test_args['meta']['mcp']['public'] ?? null ), "{$hal_test_name}: meta.mcp.public must stay explicitly true" );
		hal_test_assert( is_callable( $hal_test_args['permission_callback'] ) && is_callable( $hal_test_args['execute_callback'] ), "{$hal_test_name}: both callbacks must be callable" );
	}

	// --- FIX (F08/F20): featured_image_id=0 must actually remove the
	// thumbnail, as both schemas promise; a non-existent ID stays refused. ---
	hal_v02_seed_post(
		[
			'ID' => 900, 'post_type' => 'post', 'post_status' => 'draft',
			'post_title' => 'Thumb post', 'post_content' => 'Body', 'post_author' => 1,
		]
	);
	hal_v02_seed_post(
		[
			'ID' => 901, 'post_type' => 'page', 'post_status' => 'draft',
			'post_title' => 'Thumb page', 'post_content' => 'Body', 'post_author' => 1,
		]
	);
	update_post_meta( 900, '_thumbnail_id', 55 );
	update_post_meta( 901, '_thumbnail_id', 55 );

	$hal_test_remove_post = ( $hal_test_registered['hal/update-post']['execute_callback'] )( [ 'post_id' => 900, 'featured_image_id' => 0 ] );
	hal_test_assert( is_array( $hal_test_remove_post ) && true === $hal_test_remove_post['applied_directly'], 'featured_image_id=0 on a draft post must apply directly' );
	hal_test_assert( '' === get_post_meta( 900, '_thumbnail_id', true ), 'featured_image_id=0 must remove the post thumbnail meta' );

	// Negative IDs are refused outright — never absint()-ed into 0's
	// "remove" meaning.
	$hal_test_negative_post = ( $hal_test_registered['hal/update-post']['execute_callback'] )( [ 'post_id' => 900, 'featured_image_id' => -5 ] );
	hal_test_assert( is_wp_error( $hal_test_negative_post ) && 'hal_mcp_invalid_featured_image' === array_key_first( (array) $hal_test_negative_post->errors ), 'a negative featured_image_id must be refused, never absint()-ed into a removal' );

	$hal_test_remove_page = ( $hal_test_registered['hal/update-page']['execute_callback'] )( [ 'page_id' => 901, 'featured_image_id' => 0 ] );
	hal_test_assert( is_array( $hal_test_remove_page ) && true === $hal_test_remove_page['applied_directly'], 'featured_image_id=0 on a draft page must apply directly' );
	hal_test_assert( '' === get_post_meta( 901, '_thumbnail_id', true ), 'featured_image_id=0 must remove the page thumbnail meta' );

	$hal_test_missing_post = ( $hal_test_registered['hal/update-post']['execute_callback'] )( [ 'post_id' => 900, 'featured_image_id' => 999999 ] );
	hal_test_assert( is_wp_error( $hal_test_missing_post ) && 'hal_mcp_featured_image_not_found' === array_key_first( (array) $hal_test_missing_post->errors ), 'a non-existent featured_image_id must be refused on posts' );

	$hal_test_missing_page = ( $hal_test_registered['hal/update-page']['execute_callback'] )( [ 'page_id' => 901, 'featured_image_id' => 999999 ] );
	hal_test_assert( is_wp_error( $hal_test_missing_page ) && 'hal_mcp_featured_image_not_found' === array_key_first( (array) $hal_test_missing_page->errors ), 'a non-existent featured_image_id must be refused on pages' );

	// --- FIX (F11/F17): the product images fingerprint must cover the
	// product image AND the gallery, so changing the product image between
	// approval and apply conflicts instead of silently overwriting. The
	// images validation requires real attachment rows for the media IDs. ---
	hal_v02_seed_post(
		[ 'ID' => 55, 'post_type' => 'attachment', 'post_status' => 'inherit', 'post_title' => 'Image 55', 'post_author' => 1 ]
	);
	hal_v02_seed_post(
		[ 'ID' => 66, 'post_type' => 'attachment', 'post_status' => 'inherit', 'post_title' => 'Image 66', 'post_author' => 1 ]
	);
	hal_v02_seed_product( 804, 'simple', 'publish', [ 'name' => 'Live product', 'image_id' => 55 ] );

	// The images change map accepts readable attachments and refuses
	// non-positive IDs instead of silently absint()-ing them.
	$hal_test_map = hal_mcp_product_build_change_map( [ 'images' => [ 66, 55 ] ] );
	hal_test_assert( is_array( $hal_test_map ) && [ 66, 55 ] === $hal_test_map['images'], 'the images change map must accept readable attachment IDs' );
	$hal_test_bad_images = hal_mcp_product_build_change_map( [ 'images' => [ -5, 66 ] ] );
	hal_test_assert( is_wp_error( $hal_test_bad_images ) && 'hal_mcp_invalid_product_image' === array_key_first( (array) $hal_test_bad_images->errors ), 'a non-positive image ID must be refused, never absint()-ed' );

	// The read both the snapshot and the pre-apply revalidation use: the
	// product image first, then the gallery — the exact shape apply writes.
	hal_test_assert( [ 55 ] === hal_mcp_product_read_current( 804, [ 'images' ] )['images'], 'the effective image list must fingerprint the product image first' );
	hal_v02_state()['products'][804]['gallery'] = [ 66 ];
	hal_test_assert( [ 55, 66 ] === hal_mcp_product_read_current( 804, [ 'images' ] )['images'], 'the effective image list must cover the gallery after the product image' );

	// End to end: approve anchors the fingerprint to the LIVE read, so a
	// product image changed afterwards must conflict at apply. The storable
	// stand-in here carries the field key with a scalar — kept as an
	// additional conflict probe, not a payload-shape limitation: the
	// sanitizer accepts integer keys, and the sibling case in
	// hal_test_case_hal_product_write_contracts() stores a real list
	// payload end to end.
	$hal_test_images_req = hal_mcp_create_change_request(
		[
			'operation'         => 'update-product',
			'payload'           => [ 'images' => 66 ],
			'targets'           => [ [ 'type' => 'product', 'id' => 804 ] ],
			'original_snapshot' => [ 'images' => 55 ],
		]
	);
	hal_test_assert( ! is_wp_error( $hal_test_images_req ), 'an update-product request for the images field must be storable' );

	hal_mcp_approve_change_request( $hal_test_images_req['request_id'] );
	hal_v02_state()['products'][804]['image_id'] = 77;
	$hal_test_images_conflict = hal_mcp_apply_change_request( $hal_test_images_req['request_id'] );
	hal_test_assert( is_wp_error( $hal_test_images_conflict ), 'applying over a changed product image must conflict' );
	hal_test_assert( 'conflict' === hal_mcp_request_read_status( $hal_test_images_req['request_id'] ), 'the request must end in conflict, never applied' );
	hal_test_assert( 77 === (int) hal_v02_state()['products'][804]['image_id'] && [ 66 ] === hal_v02_state()['products'][804]['gallery'], 'the conflict must leave the live product image and gallery untouched' );
}