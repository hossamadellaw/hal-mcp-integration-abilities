<?php
/**
 * Local test runner for environment, integrations, and the system inventory
 * (V04).
 *
 * The roadmap's V04 file is shared across batches. This run covers the
 * batch-3 contracts (F14/F24/F07: lazy capture with fingerprint-driven
 * invalidation, the model-safe summary, honest per-area operation statuses,
 * the integration registry's runtime/binding gates, the current-site-only
 * multisite branch, the missing-plugin-API degradation path,
 * hal/get-site-inventory), the batch-4 contracts (F20 policy surface, the
 * F08 language surface, F13 media content verification with real file
 * fixtures), and the batch-5 integration contracts (F21 block/Elementor
 * sanitization and editor-bridge readiness, F22 SEO field contract and the
 * Yoast handler, F23 translation linking for WPML/Polylang).
 *
 * Pure in-memory WordPress stubs — no site, no database, no network. Each
 * case runs in its own PHP subprocess, mirroring tests/local/run.php. The
 * parse/serialize, kses, WPSEO_Meta, SitePress, and pll_* fixtures verify
 * that the CONTRACTS hold (dangerous content does not survive; groups are
 * preserved; live reads govern) — they do not reproduce the real
 * implementations' semantics, and prove nothing about live rendering.
 *
 * Usage:
 *   php tests/local/content-integrations.php              # run every case
 *   php tests/local/content-integrations.php --case=NAME  # run one case
 *
 * Exit code 0 = all requested cases passed.
 *
 * @package hal-mcp-abilities
 */

if ( PHP_SAPI !== 'cli' ) {
	exit( "tests/local/content-integrations.php must run from the CLI only.\n" );
}

const HAL_TEST_CASE_OK = 'HAL_TEST_CASE_OK';

// ---------------------------------------------------------------------------
// Case list and orchestration
// ---------------------------------------------------------------------------

$hal_test_cases = [
	'environment_caches_and_auto_invalidates',
	'environment_model_summary_is_safe',
	'environment_operation_status_honesty',
	'integration_registry_runtime_and_binding',
	'read_system_ability_uses_environment',
	'environment_multisite_current_site_only',
	'environment_survives_missing_plugin_api',
	'content_authorization_and_language_surface',
	'media_content_verification',
	'integration_blocks_contract',
	'integration_elementor_contract',
	'integration_seo_contract',
	'integration_translations_contract',
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

	// This case exercises the degradation path for an unreadable
	// wp-admin/includes/plugin.php — the plugin API stubs must stay absent.
	if ( 'environment_survives_missing_plugin_api' === $hal_test_only ) {
		define( 'HAL_V04_SKIP_PLUGIN_API', true );
	}

	require_once __DIR__ . '/v04-stubs.php';
	hal_v04_reset_stubs();
	hal_v04_fire_plugins_loaded();

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

/**
 * Lazy capture, per-site caching, fingerprint-driven auto-invalidation, and
 * the explicit invalidate action (F14 bullets 1–2).
 */
function hal_test_case_environment_caches_and_auto_invalidates(): void {
	$hal_test_state = &hal_v04_state();
	$hal_test_state['plugins'] = [
		'woocommerce/woocommerce.php' => [ 'Name' => 'WooCommerce', 'Version' => '9.4.0' ],
		'akismet/akismet.php'         => [ 'Name' => 'Akismet Anti-spam', 'Version' => '5.3.2' ],
		'hello.php'                   => [ 'Name' => 'Hello Dolly', 'Version' => '1.7.2' ],
	];
	$hal_test_state['active_plugins'] = [ 'woocommerce/woocommerce.php', 'akismet/akismet.php' ];

	$hal_test_first = hal_mcp_environment_inventory();

	hal_test_assert( 'recomputed' === $hal_test_first['freshness'], 'the first inventory read must be a recompute' );

	$hal_test_stored = hal_v04_state()['options']['hal_mcp_environment_inventory'] ?? null;
	hal_test_assert( is_array( $hal_test_stored ), 'the captured inventory must be stored per site' );
	hal_test_assert( '1.0' === ( $hal_test_stored['schema'] ?? '' ), 'the stored inventory must carry its schema version' );
	hal_test_assert( 1 === ( $hal_test_stored['blog_id'] ?? 0 ), 'the stored inventory must record the current blog id' );
	hal_test_assert( 64 === strlen( (string) ( $hal_test_stored['fingerprint'] ?? '' ) ), 'the fingerprint must be a sha256 hex string' );

	$hal_test_captured_at = (string) $hal_test_first['data']['captured_at'];
	hal_test_assert( '' !== $hal_test_captured_at, 'the inventory must record its capture time' );

	// Unchanged environment, simulated as a NEW request (memo cleared):
	// served from the stored option, no re-capture, no new write.
	hal_mcp_environment_reset_memo();
	$hal_test_state['now'] = '2026-09-15 10:05:00';
	$hal_test_second       = hal_mcp_environment_inventory();

	hal_test_assert( 'cached' === $hal_test_second['freshness'], 'an unchanged environment must be served from cache' );
	hal_test_assert( $hal_test_second['data']['captured_at'] === $hal_test_captured_at, 'a cache hit must not re-capture' );

	// A plugin deactivation changes the fingerprint: auto-invalidation.
	hal_mcp_environment_reset_memo();
	$hal_test_state['active_plugins'] = [ 'akismet/akismet.php' ];
	$hal_test_third                   = hal_mcp_environment_inventory();

	hal_test_assert( 'recomputed' === $hal_test_third['freshness'], 'a fingerprint change must trigger recompute' );
	hal_test_assert( '2026-09-15 10:05:00' === $hal_test_third['data']['captured_at'], 'a recompute must capture at read time' );

	$hal_test_woo = hal_v04_find_plugin( $hal_test_third['data']['plugins']['regular'], 'woocommerce/woocommerce.php' );
	hal_test_assert( null !== $hal_test_woo && 'inactive' === $hal_test_woo['status'], 'the deactivated plugin must be listed as inactive, not dropped' );

	$hal_test_stored_after = hal_v04_state()['options']['hal_mcp_environment_inventory'] ?? null;
	hal_test_assert( is_array( $hal_test_stored_after ) && $hal_test_stored_after['fingerprint'] !== $hal_test_stored['fingerprint'], 'the stored fingerprint must move with the environment' );

	// Two writes so far (first capture + invalidation re-capture), no more.
	$hal_test_env_writes = array_count_values( hal_v04_state()['option_writes'] )['hal_mcp_environment_inventory'] ?? 0;
	hal_test_assert( 2 === $hal_test_env_writes, 'cache reads must never write; only captures do' );

	// Explicit invalidation (the F19 re-discover entry point): delete + recompute.
	$hal_test_state['now'] = '2026-09-15 10:10:00';
	hal_mcp_environment_reset_memo();
	hal_test_assert( hal_mcp_environment_invalidate(), 'invalidate must delete the stored inventory' );
	hal_test_assert( in_array( 'hal_mcp_environment_inventory', hal_v04_state()['option_deletes'], true ), 'invalidate must delete by exact option name' );

	$hal_test_fourth = hal_mcp_environment_inventory();
	hal_test_assert( 'recomputed' === $hal_test_fourth['freshness'], 'the read after invalidation must re-capture' );
}

/**
 * The model-facing summary carries identifiers and states only — no PHP
 * version, no capability maps, no notes, no absolute paths — and a call
 * before `init` is served live without caching (F14/F07).
 */
function hal_test_case_environment_model_summary_is_safe(): void {
	$hal_test_state = &hal_v04_state();
	$hal_test_state['plugins'] = [
		'woocommerce/woocommerce.php' => [ 'Name' => 'WooCommerce', 'Version' => '9.4.0' ],
	];
	$hal_test_state['active_plugins'] = [ 'woocommerce/woocommerce.php' ];
	$hal_test_state['post_types'] = [
		'post' => [ 'label' => 'Posts', 'public' => true, 'hierarchical' => false, 'show_in_rest' => true, 'supports' => [ 'title' => true, 'editor' => true ] ],
		'page' => [ 'label' => 'Pages', 'public' => true, 'hierarchical' => true, 'show_in_rest' => true, 'supports' => [ 'title' => true, 'editor' => true ] ],
		'hal_mcp_request' => [ 'label' => 'HAL requests', 'public' => false ],
	];

	$hal_test_data    = hal_mcp_environment_inventory()['data'];
	$hal_test_summary = hal_mcp_environment_model_summary( $hal_test_data );

	hal_test_assert(
		[ 'wordpress_core_version', 'is_multisite', 'active_theme', 'plugins', 'public_post_types', 'public_taxonomies' ] === array_keys( $hal_test_summary ),
		'the model summary must be exactly the safe key set'
	);
	hal_test_assert( '7.0.2' === $hal_test_summary['wordpress_core_version'], 'the summary must carry the WordPress core version' );
	hal_test_assert(
		[ 'name', 'version', 'slug' ] === array_keys( $hal_test_summary['active_theme'] ),
		'the theme entry must carry name, version, and the stable slug'
	);
	hal_test_assert( 'law-firm-theme' === $hal_test_summary['active_theme']['slug'], 'the theme slug must be the stylesheet identifier' );

	$hal_test_woo = hal_v04_find_plugin( $hal_test_summary['plugins'], 'woocommerce/woocommerce.php' );
	hal_test_assert( null !== $hal_test_woo, 'the summary must list plugins by stable basename identifier' );
	hal_test_assert(
		[ 'name', 'plugin', 'installed_version', 'status' ] === array_keys( $hal_test_woo ),
		'plugin summary entries must carry identifiers and state only'
	);

	hal_test_assert( in_array( 'post', $hal_test_summary['public_post_types'], true ), 'public post types must be listed' );
	hal_test_assert( ! in_array( 'hal_mcp_request', $hal_test_summary['public_post_types'], true ), 'the internal request CPT must never appear in the summary' );

	$hal_test_json = (string) wp_json_encode( $hal_test_summary );
	hal_test_assert( ! str_contains( $hal_test_json, '/tmp/hal-v04-fake' ), 'the summary must not leak absolute paths' );
	hal_test_assert( ! str_contains( $hal_test_json, 'PHP_VERSION' ) && ! isset( $hal_test_summary['php'] ), 'the PHP version is admin detail and stays out of the summary' );

	// A call before `init`: served live, never cached (types may not all be
	// registered yet), and the per-request memo must not hide it.
	hal_mcp_environment_invalidate();
	$hal_test_state['did_init'] = false;
	$hal_test_state['now']      = '2026-09-15 10:15:00';

	$hal_test_early = hal_mcp_environment_inventory();
	hal_test_assert( 'uncached_live' === $hal_test_early['freshness'], 'a pre-init call must be served live without caching' );
	hal_test_assert( '2026-09-15 10:15:00' === $hal_test_early['data']['captured_at'], 'the pre-init result must be freshly captured' );
	hal_test_assert( ! isset( hal_v04_state()['options']['hal_mcp_environment_inventory'] ), 'a pre-init capture must not be written to the option store' );
}

/**
 * Operation statuses are honest: derived from runtime markers and the
 * integration registry, never from plugin names; every non-available status
 * carries its reason; and the MCP channel check is live (F14 bullet 5, F07
 * bullet 4).
 */
function hal_test_case_environment_operation_status_honesty(): void {
	$hal_test_state = &hal_v04_state();

	// No ability modules loaded in this case: posts/media must NOT claim
	// availability, and no WooCommerce marker exists.
	$hal_test_statuses = hal_mcp_environment_operation_statuses();

	$hal_test_seen = array_column( $hal_test_statuses, 'area' );
	sort( $hal_test_seen );
	$hal_test_expected = HAL_MCP_ENVIRONMENT_OPERATION_AREAS;
	sort( $hal_test_expected );
	hal_test_assert(
		$hal_test_expected === $hal_test_seen,
		'every operation area must be reported exactly once'
	);

	foreach ( $hal_test_statuses as $hal_test_entry ) {
		if ( 'available' !== $hal_test_entry['status'] ) {
			hal_test_assert( '' !== $hal_test_entry['reason'], "the {$hal_test_entry['area']} status must carry its reason" );
		}
	}

	$hal_test_posts = hal_v04_find_operation( $hal_test_statuses, 'posts' );
	hal_test_assert( 'needs_setup' === $hal_test_posts['status'], 'without its modules loaded, posts must not claim availability' );

	$hal_test_products = hal_v04_find_operation( $hal_test_statuses, 'products' );
	hal_test_assert( 'needs_setup' === $hal_test_products['status'], 'without WooCommerce, products must be needs_setup' );
	hal_test_assert( str_contains( $hal_test_products['reason'], 'WooCommerce' ), 'the products reason must name WooCommerce' );

	$hal_test_seo = hal_v04_find_operation( $hal_test_statuses, 'seo' );
	hal_test_assert( 'needs_setup' === $hal_test_seo['status'] && str_contains( $hal_test_seo['reason'], 'registered' ), 'seo must report the registered-but-absent component honestly (the F22 handler registers itself even without Yoast)' );

	$hal_test_translations = hal_v04_find_operation( $hal_test_statuses, 'translations' );
	hal_test_assert( 'needs_setup' === $hal_test_translations['status'] && str_contains( $hal_test_translations['reason'], 'registered' ), 'translations must report the registered-but-absent component honestly (no WPML/Polylang in this environment)' );

	$hal_test_channel = hal_v04_find_operation( $hal_test_statuses, 'external_mcp_channel' );
	hal_test_assert( 'needs_setup' === $hal_test_channel['status'] && str_contains( $hal_test_channel['reason'], 'Adapter' ), 'without the adapter class, the channel must be needs_setup' );

	// The adapter detection is LIVE: defining the class flips the status.
	require_once __DIR__ . '/fixtures/mcp-adapter-class.php';

	$hal_test_statuses_live = hal_mcp_environment_operation_statuses();
	$hal_test_channel_live  = hal_v04_find_operation( $hal_test_statuses_live, 'external_mcp_channel' );
	hal_test_assert( 'available' === $hal_test_channel_live['status'], 'the channel check must react to the adapter class existing now' );

	// The editors area now names the F21 page-design integrations: the
	// blocks integration is available (block markup is the design source of
	// every block page), Elementor is registered but absent here.
	$hal_test_editors = hal_v04_find_operation( $hal_test_statuses_live, 'editors' );
	hal_test_assert( 'available' === $hal_test_editors['status'] && str_contains( $hal_test_editors['reason'], 'blocks' ), 'editors must report the available blocks integration' );
	hal_test_assert( ! str_contains( $hal_test_editors['reason'], 'elementor' ), 'the unavailable Elementor entry must not be counted as ready' );
}

/**
 * The integration registry (F24): registration/duplicate/empty-id rules,
 * the per-operation runtime check, the discovery snapshot in the inventory,
 * the third-party binding gate, and the loader picking up a present file.
 */
function hal_test_case_integration_registry_runtime_and_binding(): void {
	$hal_test_state = &hal_v04_state();
	$hal_test_state['caps'] = [ 'edit_posts' => true ];

	// The loader already required the four real integration files when
	// plugins_loaded fired — that IS the pick-up proof now that the files
	// exist (batch 5); the batch-3 temp-fixture dance would overwrite the
	// real seo.php and is gone on purpose.
	hal_test_assert( hal_mcp_integration_available( 'blocks' ), 'the loader must have required blocks.php (F21)' );
	hal_test_assert( isset( hal_mcp_integrations()['gutenberg'] ) && true === hal_mcp_integrations()['gutenberg']['available'], 'blocks.php must have replaced the core gutenberg stand-in' );
	hal_test_assert( isset( hal_mcp_integrations()['elementor'] ) && false === hal_mcp_integrations()['elementor']['available'], 'elementor.php must register honestly as unavailable without Elementor' );
	hal_test_assert( isset( hal_mcp_integrations()['seo'] ) && false === hal_mcp_integrations()['seo']['available'], 'seo.php must register honestly as unavailable without Yoast' );
	hal_test_assert( isset( hal_mcp_integrations()['translations'] ) && false === hal_mcp_integrations()['translations']['available'], 'translations.php must register honestly as unavailable without WPML/Polylang' );

	hal_test_assert(
		hal_mcp_register_integration( 'seo_probe', [
			'label'      => 'Test SEO',
			'version'    => '1.0-test',
			'source'     => 'test',
			'available'  => true,
			'operations' => [
				'probe_read' => [ 'effect' => 'read', 'capability' => 'edit_posts', 'writable' => [ 'seo_title' ] ],
			],
		] ),
		'a valid integration definition must register'
	);

	hal_test_assert(
		! hal_mcp_register_integration( 'seo_probe', [ 'label' => 'Duplicate', 'version' => '2.0', 'available' => true ] ),
		'a duplicate id must be refused'
	);
	hal_test_assert(
		! hal_mcp_register_integration( '', [ 'label' => 'No id', 'version' => '1.0', 'available' => true ] ),
		'an empty id must be refused'
	);

	hal_test_assert( hal_mcp_integration_available( 'seo_probe' ), 'the registered integration must be available' );
	hal_test_assert( ! hal_mcp_integration_available( 'no_such_integration' ), 'an unregistered integration must not be available' );

	$hal_test_check = hal_mcp_integration_check( 'seo_probe', 'probe_read' );
	hal_test_assert( true === $hal_test_check['available'] && 'ok' === $hal_test_check['reason'], 'a declared operation with the capability must pass the runtime check' );

	$hal_test_check = hal_mcp_integration_check( 'seo_probe', 'probe_write' );
	hal_test_assert( false === $hal_test_check['available'] && 'operation_not_declared' === $hal_test_check['reason'], 'an undeclared operation must fail the runtime check' );

	$hal_test_state['caps'] = [];
	$hal_test_check         = hal_mcp_integration_check( 'seo_probe', 'probe_read' );
	hal_test_assert( false === $hal_test_check['available'] && str_contains( $hal_test_check['reason'], 'capability_missing' ), 'a missing capability must fail the runtime check' );
	$hal_test_state['caps'] = [ 'edit_posts' => true ];

	// The discovery snapshot the inventory embeds (real entries included).
	$hal_test_integrations = hal_mcp_environment_inventory()['data']['integrations'];

	$hal_test_blocks_entry = null;
	$hal_test_mcp_entry    = null;
	foreach ( $hal_test_integrations as $hal_test_entry ) {
		if ( 'blocks' === $hal_test_entry['id'] ) {
			$hal_test_blocks_entry = $hal_test_entry;
		}
		if ( 'mcp' === $hal_test_entry['id'] ) {
			$hal_test_mcp_entry = $hal_test_entry;
		}
	}
	hal_test_assert( null !== $hal_test_blocks_entry && true === $hal_test_blocks_entry['available'] && in_array( 'write_blocks', $hal_test_blocks_entry['operations'], true ), 'the inventory must snapshot the blocks integration with its operations' );
	hal_test_assert( null !== $hal_test_mcp_entry && false === $hal_test_mcp_entry['available'], 'the MCP channel entry must reflect the adapter being absent' );

	// Third-party abilities stay unbound until an integration binds them —
	// and only an AVAILABLE integration's bindings count.
	hal_test_assert( ! hal_mcp_is_third_party_ability_bound( 'yoast/head' ), 'an ability no integration binds must stay unclassified' );

	hal_mcp_register_integration( 'bound_dormant', [ 'label' => 'Dormant binder', 'version' => '1.0', 'available' => false, 'abilities' => [ 'yoast/head' ] ] );
	hal_test_assert( ! hal_mcp_is_third_party_ability_bound( 'yoast/head' ), 'an unavailable integration\'s binding must stay dormant' );

	hal_mcp_register_integration( 'bound_test', [ 'label' => 'Binder', 'version' => '1.0', 'available' => true, 'abilities' => [ 'yoast/head' ] ] );
	hal_test_assert( hal_mcp_is_third_party_ability_bound( 'yoast/head' ), 'an explicitly bound ability of an available integration must classify' );
	hal_test_assert( ! hal_mcp_is_third_party_ability_bound( 'other/thing' ), 'an unbound third-party ability must stay unclassified' );
	hal_test_assert( ! hal_mcp_is_third_party_ability_bound( '' ), 'an empty ability name must never classify' );
}

/**
 * hal/get-site-inventory reads the environment summary (F07): stable
 * identifiers, merged update flags from the cached transient, honest
 * statuses with the real modules loaded, the permission gate, and — the
 * core separation — never a single wp_update_plugins() call.
 */
function hal_test_case_read_system_ability_uses_environment(): void {
	$hal_test_state = &hal_v04_state();
	$hal_test_state['caps'] = [ 'edit_posts' => true ];
	$hal_test_state['plugins'] = [
		'woocommerce/woocommerce.php' => [ 'Name' => 'WooCommerce', 'Version' => '9.4.0' ],
		'akismet/akismet.php'         => [ 'Name' => 'Akismet Anti-spam', 'Version' => '5.3.2' ],
		'hello.php'                   => [ 'Name' => 'Hello Dolly', 'Version' => '1.7.2' ],
	];
	$hal_test_state['active_plugins'] = [ 'akismet/akismet.php' ];
	$hal_test_state['update_plugins'] = (object) [
		'response' => [
			'woocommerce/woocommerce.php' => (object) [ 'new_version' => '9.9.9' ],
		],
	];
	$hal_test_state['post_types'] = [
		'post' => [ 'label' => 'Posts', 'public' => true, 'show_in_rest' => true, 'supports' => [ 'title' => true, 'editor' => true ] ],
		'page' => [ 'label' => 'Pages', 'public' => true, 'show_in_rest' => true, 'supports' => [ 'title' => true, 'editor' => true ] ],
		'hal_mcp_request' => [ 'label' => 'HAL requests', 'public' => false ],
	];

	// WooCommerce's runtime marker only — the same marker the abilities use.
	if ( ! function_exists( 'wc_get_product_types' ) ) {
		/**
		 * Synthetic product types (test fixture).
		 *
		 * @return array<string, string>
		 */
		function wc_get_product_types(): array {
			return [ 'simple' => 'Simple', 'variable' => 'Variable' ];
		}
	}

	// Load the per-domain ability files exactly as the bootstrap would with
	// the Abilities API present, then register the system ability.
	require_once HAL_MCP_ABILITIES_DIR . '/abilities/read-system.php';
	require_once HAL_MCP_ABILITIES_DIR . '/abilities/read-posts.php';
	require_once HAL_MCP_ABILITIES_DIR . '/abilities/read-products.php';
	require_once HAL_MCP_ABILITIES_DIR . '/abilities/read-media.php';
	require_once HAL_MCP_ABILITIES_DIR . '/abilities/write-posts.php';
	require_once HAL_MCP_ABILITIES_DIR . '/abilities/write-products.php';
	require_once HAL_MCP_ABILITIES_DIR . '/abilities/write-media.php';
	require_once HAL_MCP_ABILITIES_DIR . '/abilities/read-pages.php';
	require_once HAL_MCP_ABILITIES_DIR . '/abilities/write-pages.php';
	require_once HAL_MCP_ABILITIES_DIR . '/abilities/content.php';

	hal_mcp_register_read_system_abilities();

	$hal_test_args = hal_v04_state()['registered_abilities']['hal/get-site-inventory'] ?? null;
	hal_test_assert( is_array( $hal_test_args ), 'hal/get-site-inventory must register through the Abilities API' );
	hal_test_assert( 'hal-system' === ( $hal_test_args['category'] ?? '' ), 'the inventory ability must stay in the hal-system category' );
	hal_test_assert( true === ( $hal_test_args['meta']['mcp']['public'] ?? null ), 'mcp.public must stay explicitly true on the inventory ability' );
	hal_test_assert( false === ( $hal_test_args['input_schema']['additionalProperties'] ?? true ), 'the input schema must reject unexpected fields' );
	hal_test_assert(
		isset( $hal_test_args['input_schema']['properties']['force_refresh'] ) && ! isset( $hal_test_args['input_schema']['properties']['check_updates'] ),
		'force_refresh must be the only refresh input, documented as local-only'
	);

	// The gate: edit_posts grants, its absence denies.
	hal_test_assert( true === call_user_func( $hal_test_args['permission_callback'], [] ), 'the system read gate must honor edit_posts' );
	$hal_test_state['caps'] = [];
	hal_test_assert( false === call_user_func( $hal_test_args['permission_callback'], [] ), 'without edit_posts the gate must deny' );
	$hal_test_state['caps'] = [ 'edit_posts' => true ];

	$hal_test_execute = $hal_test_args['execute_callback'];

	$hal_test_out = $hal_test_execute( [] );

	hal_test_assert(
		[ 'wordpress_core_version', 'is_multisite', 'active_theme', 'plugins', 'public_post_types', 'public_taxonomies', 'operation_status', 'data_freshness' ] === array_keys( $hal_test_out ),
		'the ability output must be exactly the declared safe shape'
	);
	hal_test_assert( 'recomputed' === $hal_test_out['data_freshness'], 'the first read must report a recompute' );

	$hal_test_woo = hal_v04_find_plugin( $hal_test_out['plugins'], 'woocommerce/woocommerce.php' );
	hal_test_assert( null !== $hal_test_woo && 'inactive' === $hal_test_woo['status'], 'installed-but-inactive must be distinguished from active' );
	hal_test_assert( true === $hal_test_woo['update_available'] && '9.9.9' === $hal_test_woo['latest_version'], 'update flags must come from the cached transient' );

	$hal_test_aki = hal_v04_find_plugin( $hal_test_out['plugins'], 'akismet/akismet.php' );
	hal_test_assert( 'active' === $hal_test_aki['status'] && false === $hal_test_aki['update_available'] && '' === $hal_test_aki['latest_version'], 'no transient entry means no update flag' );

	hal_test_assert( in_array( 'post', $hal_test_out['public_post_types'], true ) && ! in_array( 'hal_mcp_request', $hal_test_out['public_post_types'], true ), 'public types listed, internal CPT never' );

	$hal_test_products = hal_v04_find_operation( $hal_test_out['operation_status'], 'products' );
	hal_test_assert( 'available' === $hal_test_products['status'], 'with WooCommerce active and modules loaded, products must be available' );
	$hal_test_posts = hal_v04_find_operation( $hal_test_out['operation_status'], 'posts' );
	hal_test_assert( 'available' === $hal_test_posts['status'], 'with modules loaded, posts must be available' );
	$hal_test_pages = hal_v04_find_operation( $hal_test_out['operation_status'], 'pages' );
	hal_test_assert( 'available' === $hal_test_pages['status'], 'with page modules loaded, pages must be available' );
	$hal_test_custom = hal_v04_find_operation( $hal_test_out['operation_status'], 'custom_content' );
	hal_test_assert( 'partial' === $hal_test_custom['status'] && str_contains( $hal_test_custom['reason'], 'authorized' ), 'a loaded content module over zero authorized types must be honestly partial' );

	hal_test_assert( ! str_contains( (string) wp_json_encode( $hal_test_out ), '/tmp/hal-v04-fake' ), 'the ability output must not leak absolute paths' );

	// Cache hit, then forced recompute — and through ALL of it, the v1
	// wordpress.org network check must never fire (F07 separation).
	hal_mcp_environment_reset_memo();
	$hal_test_out = $hal_test_execute( [] );
	hal_test_assert( 'cached' === $hal_test_out['data_freshness'], 'an unchanged environment must be served cached' );

	hal_mcp_environment_reset_memo();
	$hal_test_state['now'] = '2026-09-15 10:20:00';
	$hal_test_out          = $hal_test_execute( [ 'force_refresh' => true ] );
	hal_test_assert( 'recomputed' === $hal_test_out['data_freshness'], 'force_refresh must recompute the local inventory' );

	hal_test_assert( 0 === hal_v04_state()['wp_update_plugins_calls'], 'no code path in the v2 inventory ability may call wp_update_plugins' );
}

/**
 * Multisite: the current site's branch only — network-active and must-use
 * plugins reported as distinct states, no switch_to_blog, no site scanning
 * (F14 bullet 3).
 */
function hal_test_case_environment_multisite_current_site_only(): void {
	$hal_test_state = &hal_v04_state();
	$hal_test_state['multisite'] = true;
	$hal_test_state['blog_id']   = 3;
	$hal_test_state['sitewide']  = [ 'woocommerce/woocommerce.php' => 1 ];
	$hal_test_state['plugins']   = [
		'woocommerce/woocommerce.php' => [ 'Name' => 'WooCommerce', 'Version' => '9.4.0' ],
		'akismet/akismet.php'         => [ 'Name' => 'Akismet Anti-spam', 'Version' => '5.3.2' ],
	];
	$hal_test_state['active_plugins'] = [ 'akismet/akismet.php' ];
	$hal_test_state['mu_plugins']     = [ 'my-loader.php' => [ 'Name' => 'My Loader', 'Version' => '1.0' ] ];

	$hal_test_data    = hal_mcp_environment_inventory()['data'];
	$hal_test_summary = hal_mcp_environment_model_summary( $hal_test_data );

	hal_test_assert( true === $hal_test_data['wordpress']['multisite'] && 3 === $hal_test_data['blog_id'], 'the inventory must record the multisite state and current blog' );

	$hal_test_woo = hal_v04_find_plugin( $hal_test_data['plugins']['regular'], 'woocommerce/woocommerce.php' );
	hal_test_assert( 'network_active' === $hal_test_woo['status'], 'a network-active plugin must be reported as network_active on the current site' );
	hal_test_assert( in_array( 'woocommerce/woocommerce.php', $hal_test_data['plugins']['network_active'], true ), 'the network-active identifier list must carry the plugin' );

	$hal_test_aki = hal_v04_find_plugin( $hal_test_data['plugins']['regular'], 'akismet/akismet.php' );
	hal_test_assert( 'active' === $hal_test_aki['status'], 'a site-active plugin stays active beside network ones' );

	$hal_test_mu = hal_v04_find_plugin( $hal_test_summary['plugins'], 'my-loader.php' );
	hal_test_assert( null !== $hal_test_mu && 'must_use' === $hal_test_mu['status'], 'must-use plugins are a distinct category in the summary' );

	$hal_test_statuses = hal_mcp_environment_operation_statuses( $hal_test_data );
	hal_test_assert( null !== hal_v04_find_operation( $hal_test_statuses, 'posts' ), 'statuses must derive on multisite exactly as on single site' );

	hal_test_assert( 0 === hal_v04_state()['switched'], 'nothing in the inventory flow may call switch_to_blog — current site only' );
}

/**
 * Without the wp-admin plugin API the plugins section degrades with a note,
 * everything else stays intact, and no admin notice is raised (F14
 * bullet 5: gaps carry reasons, nothing blocks independent functions).
 */
function hal_test_case_environment_survives_missing_plugin_api(): void {
	hal_test_assert( ! function_exists( 'get_plugins' ) && ! function_exists( 'get_mu_plugins' ), 'the skip flag must keep the wp-admin plugin API absent' );

	$hal_test_data = hal_mcp_environment_inventory()['data'];

	hal_test_assert( false === $hal_test_data['plugins']['available'], 'the plugins section must report itself unavailable' );
	hal_test_assert( [] === $hal_test_data['plugins']['regular'] && [] === $hal_test_data['plugins']['must_use'], 'no plugin rows may appear without the plugin API' );
	hal_test_assert( ! empty( $hal_test_data['notes'] ), 'the gap must be recorded as a note, not swallowed' );

	$hal_test_summary = hal_mcp_environment_model_summary( $hal_test_data );
	hal_test_assert( [] === $hal_test_summary['plugins'], 'the model summary degrades to an empty plugin list' );
	hal_test_assert( '' !== $hal_test_summary['wordpress_core_version'], 'the rest of the summary stays intact' );

	$hal_test_statuses = hal_mcp_environment_operation_statuses( $hal_test_data );
	hal_test_assert( HAL_MCP_ENVIRONMENT_OPERATION_AREAS === array_column( $hal_test_statuses, 'area' ), 'the status report stays complete' );

	hal_test_assert( empty( hal_v04_state()['hooks']['admin_notices'] ?? [] ), 'no admin notice may be raised by the inventory' );
	hal_test_assert( 0 === hal_v04_state()['wp_update_plugins_calls'], 'degraded discovery must still never touch the network' );
}

/**
 * F20 policy surface: which public custom post types the generic content
 * abilities may address, and the F08 language surface the read/write
 * abilities validate against.
 */
function hal_test_case_content_authorization_and_language_surface(): void {
	$hal_test_state = &hal_v04_state();

	$hal_test_state['post_types']['post'] = [ 'label' => 'Posts', 'public' => true, 'cap' => [ 'create_posts' => 'edit_posts', 'edit_post' => 'edit_post', 'read_post' => 'read_post', 'publish_posts' => 'publish_posts' ] ];
	$hal_test_state['post_types']['hal_doc'] = [ 'label' => 'Document', 'public' => true, 'cap' => [ 'create_posts' => 'edit_posts', 'edit_post' => 'edit_doc', 'read_post' => 'read_doc', 'publish_posts' => 'publish_docs' ] ];
	$hal_test_state['post_types']['hal_secret'] = [ 'label' => 'Internal', 'public' => false, 'cap' => [ 'create_posts' => 'edit_posts', 'edit_post' => 'edit_doc', 'read_post' => 'read_doc', 'publish_posts' => 'publish_docs' ] ];

	// Hostile-filter probe (defence in depth): a filter smuggling in a
	// specialized type, the internal request store, a non-public capped type,
	// and a type without a capability map must not widen the authorization
	// surface — the re-validation after the filter re-applies the discovery
	// rules. Registered BEFORE the first call in this subprocess, so the
	// static cache is cold and the filter actually runs.
	add_filter(
		'hal_mcp_content_authorized_post_types',
		static fn( $hal_test_types ) => array_merge( (array) $hal_test_types, [ 'post', 'hal_mcp_request', 'hal_secret', 'post_type_with_no_caps' ] )
	);

	$hal_test_authorized = hal_mcp_content_authorized_post_types();
	hal_test_assert( in_array( 'hal_doc', $hal_test_authorized, true ), 'a public custom type with a full capability map must be authorized' );
	hal_test_assert( ! in_array( 'post', $hal_test_authorized, true ), 'specialized types must never enter the generic authorization list, even via a filter' );
	hal_test_assert( ! in_array( 'hal_mcp_request', $hal_test_authorized, true ), 'the internal request store must never enter the authorization list, even via a filter' );
	hal_test_assert( ! in_array( 'hal_secret', $hal_test_authorized, true ), 'a non-public type with a full capability map must never enter the authorization list, even via a filter' );
	hal_test_assert( ! in_array( 'post_type_with_no_caps', $hal_test_authorized, true ), 'a type without a capability map must never enter the authorization list, even via a filter' );

	// The policy gate accepts the authorized type through the SAME path as
	// the const policy keys.
	$hal_test_state['caps'] = [ 'edit_doc' => true, 'read_doc' => true, 'edit_posts' => true ];
	hal_test_assert( true === hal_mcp_permission( 'hal_doc', 'edit', 0 ), 'the policy gate must accept an authorized content type' );
	hal_test_assert( false === hal_mcp_permission( 'hal_doc', 'delete', 0 ), 'undeclared operations must stay refused for content types' );
	$hal_test_state['caps'] = [];
	hal_test_assert( false === hal_mcp_permission( 'hal_doc', 'edit', 0 ), 'authorization without capability must still deny' );

	// The language surface: the site locale only, until a translations
	// integration widens the list through its filter.
	hal_test_assert( [ 'en_US' ] === hal_mcp_supported_languages(), 'without a translations integration the site locale is the only language' );
	hal_test_assert( true === hal_mcp_language_is_supported( 'en_US' ) && false === hal_mcp_language_is_supported( 'fr_FR' ), 'language support must follow the declared surface' );
	hal_test_assert( true === hal_mcp_language_is_supported( 'EN_US' ), 'language support must be case-insensitive on the supplied code' );
	hal_test_assert( null === hal_mcp_validate_read_language( [ 'language' => 'en_US' ] ), 'the site locale must validate as a documented no-op' );
	$hal_test_refused = hal_mcp_validate_read_language( [ 'language' => 'fr_FR' ] );
	hal_test_assert( $hal_test_refused instanceof WP_Error, 'an unoffered language must be refused with the real reason' );
	hal_test_assert( null === hal_mcp_validate_read_language( [] ), 'an absent language input must validate to no filtering' );
}


/**
 * F13 content verification with REAL file fixtures (no site, no network:
 * the fixtures live in the system temp dir and are cleaned up). Covers the
 * per-type content proof, the PDF-requires-fileinfo refusal (adaptive: the
 * local CLI has no fileinfo extension, which is exactly the documented
 * refusal branch), the site-aligned size cap, and the ability's early
 * refusals before any staging happens.
 */
function hal_test_case_media_content_verification(): void {

	require_once HAL_MCP_ABILITIES_DIR . '/abilities/write-media.php';

	// The staging/sideload mechanics are WordPress's own documented
	// functions; this case stubs them so the execute path can run far
	// enough to prove the EARLY refusals.
	if ( ! function_exists( 'wp_generate_attachment_metadata' ) ) {
		function wp_generate_attachment_metadata( $attachment_id, $file ): array {
			return [];
		}
	}
	if ( ! function_exists( 'wp_tempnam' ) ) {
		function wp_tempnam( string $filename = '' ): string {
			return (string) tempnam( sys_get_temp_dir(), 'halv04' );
		}
	}
	if ( ! function_exists( 'wp_handle_sideload' ) ) {
		function wp_handle_sideload( array $file, array $overrides = [] ) {
			return [ 'error' => 'sideload is not exercised in this case' ];
		}
	}

	// 1) Site-aligned size cap: without wp_max_upload_size the own 10 MB
	// cap stands.
	hal_test_assert(
		HAL_MCP_MAX_UPLOAD_BYTES === hal_mcp_media_max_upload_bytes(),
		'without wp_max_upload_size the plugin cap must stand'
	);

	// 2) MIME family mapping.
	hal_test_assert(
		'image' === hal_mcp_media_expected_mime_family( 'png' )
			&& 'application/pdf' === hal_mcp_media_expected_mime_family( 'pdf' )
			&& null === hal_mcp_media_expected_mime_family( 'exe' ),
		'the MIME family mapping must cover the allow-list and nothing else'
	);

	// 3) Real PNG bytes verify as PNG.
	$hal_test_png = (string) base64_decode(
		'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==',
		true
	);
	$hal_test_png_path = (string) tempnam( sys_get_temp_dir(), 'halv04' );
	file_put_contents( $hal_test_png_path, $hal_test_png );
	hal_test_assert(
		true === hal_mcp_media_verify_staged_content( $hal_test_png_path, 'png' ),
		'real PNG bytes must verify as PNG'
	);

	// 4) Garbage bytes claimed as PNG are refused; an empty file is refused.
	file_put_contents( $hal_test_png_path, 'this is not an image at all' );
	hal_test_assert(
		false === hal_mcp_media_verify_staged_content( $hal_test_png_path, 'png' ),
		'garbage bytes claimed as PNG must be refused'
	);
	file_put_contents( $hal_test_png_path, '' );
	hal_test_assert(
		false === hal_mcp_media_verify_staged_content( $hal_test_png_path, 'png' ),
		'an empty staged file must be refused'
	);
	unlink( $hal_test_png_path );

	// 5) PDF is adaptive on fileinfo: real PDF bytes verify ONLY when
	// fileinfo is available — otherwise refused (F13: the extension alone
	// is never trusted for PDFs). The garbage-PDF refusal holds in both
	// worlds.
	$hal_test_pdf_path = (string) tempnam( sys_get_temp_dir(), 'halv04' );
	file_put_contents( $hal_test_pdf_path, "%PDF-1.4\n1 0 obj\nendobj\n%%EOF" );
	if ( function_exists( 'finfo_open' ) ) {
		hal_test_assert(
			true === hal_mcp_media_verify_staged_content( $hal_test_pdf_path, 'pdf' ),
			'real PDF bytes must verify when fileinfo is available'
		);
	} else {
		hal_test_assert(
			false === hal_mcp_media_verify_staged_content( $hal_test_pdf_path, 'pdf' ),
			'PDF must be refused when fileinfo is absent (F13), even with real PDF bytes'
		);
	}
	file_put_contents( $hal_test_pdf_path, 'pretending to be a pdf' );
	hal_test_assert(
		false === hal_mcp_media_verify_staged_content( $hal_test_pdf_path, 'pdf' ),
		'garbage bytes claimed as PDF must be refused regardless of fileinfo'
	);
	unlink( $hal_test_pdf_path );

	// 6) The ability registers and refuses early, before any staging.
	hal_mcp_register_write_media_abilities();
	$hal_test_upload = hal_v04_state()['registered_abilities']['hal/upload-media'] ?? null;
	hal_test_assert( is_array( $hal_test_upload ), 'the upload ability must register over the stub API' );
	$hal_test_execute = $hal_test_upload['execute_callback'];

	$hal_test_disallowed = $hal_test_execute( [ 'filename' => 'payload.exe', 'file_data_base64' => 'AAAA' ] );
	hal_test_assert(
		is_wp_error( $hal_test_disallowed ) && 'hal_mcp_disallowed_file_type' === array_key_first( (array) $hal_test_disallowed->errors ),
		'a disallowed extension must be refused before staging'
	);

	$hal_test_bad_b64 = $hal_test_execute( [ 'filename' => 'a.png', 'file_data_base64' => '!!!not base64!!!' ] );
	hal_test_assert(
		is_wp_error( $hal_test_bad_b64 ) && 'hal_mcp_invalid_file_data' === array_key_first( (array) $hal_test_bad_b64->errors ),
		'invalid base64 must be refused strictly'
	);

	$hal_test_oversized = $hal_test_execute( [ 'filename' => 'a.png', 'file_data_base64' => str_repeat( 'QUJD', 4 * 1024 * 1024 ) ] );
	hal_test_assert(
		is_wp_error( $hal_test_oversized ) && 'hal_mcp_file_too_large' === array_key_first( (array) $hal_test_oversized->errors ),
		'an oversized payload must be refused by its encoded length before decode'
	);

	// 7) The model-facing error strings carry no server paths.
	foreach ( [ $hal_test_disallowed, $hal_test_bad_b64, $hal_test_oversized ] as $hal_test_error ) {
		if ( is_wp_error( $hal_test_error ) ) {
			hal_test_assert(
				! str_contains( (string) wp_json_encode( $hal_test_error ), sys_get_temp_dir() ) && ! str_contains( (string) wp_json_encode( $hal_test_error ), '\\' ),
				'model-facing errors must not disclose server paths'
			);
		}
	}
}

/**
 * F21 block-markup integration: registry detection from the live registry
 * (no namespace assumption), server-side sanitization of model/bridge
 * markup, the needs_editor/ready readiness derivation, the approval gate,
 * and the editor-bridge ingestion contract (wrong version refused; the
 * sanitized serialization stored and re-fingerprinted).
 */
function hal_test_case_integration_blocks_contract(): void {
	$hal_test_state = &hal_v04_state();

	// The page and post domain layers are part of the contract under test
	// (hal_mcp_page_read_current for ingest; the update-post apply handler
	// for the non-design contrast); loaded exactly as the bootstrap would
	// with the Abilities API present.
	require_once HAL_MCP_ABILITIES_DIR . '/abilities/write-pages.php';
	require_once HAL_MCP_ABILITIES_DIR . '/abilities/write-posts.php';

	$hal_test_state['caps'] = [
		'edit_posts'     => true,
		'manage_options' => true,
		'edit_pages'     => true,
		'publish_pages'  => true,
	];

	$hal_test_state['post_types']['page'] = [ 'label' => 'Pages', 'public' => true, 'cap' => [ 'create_posts' => 'edit_pages', 'edit_post' => 'edit_pages', 'read_post' => 'read_pages', 'publish_posts' => 'publish_pages' ] ];
	$hal_test_state['post_types']['post'] = [ 'label' => 'Posts', 'public' => true, 'cap' => [ 'create_posts' => 'edit_posts', 'edit_post' => 'edit_posts', 'read_post' => 'read_posts', 'publish_posts' => 'publish_posts' ] ];

	// A registered block type and an unregistered one, for the sanitizer's
	// known/unknown decisions — Spectra under its own namespace ('uagb') is
	// enumerated exactly because it EXISTS, never assumed.
	$hal_test_state['block_types'] = [
		'core/paragraph' => [ 'attributes' => [ 'content' => [], 'dropCap' => [] ] ],
		'uagb/info-box'  => [ 'attributes' => [ 'heading' => [] ] ],
	];

	$hal_test_snapshot = hal_mcp_blocks_registry_snapshot();
	hal_test_assert( true === $hal_test_snapshot['available'], 'the registry snapshot must be available' );
	hal_test_assert( isset( $hal_test_snapshot['namespaces']['core'] ) && isset( $hal_test_snapshot['namespaces']['uagb'] ), 'the snapshot must enumerate the real namespaces without assuming one' );
	hal_test_assert( in_array( 'content', $hal_test_snapshot['blocks'][0]['attributes'], true ), 'the snapshot must carry the blocks\' attribute keys' );

	// Sanitization: dangerous markup never survives; structure is preserved;
	// unknown blocks are REPORTED, never blessed.
	$hal_test_fixture = (string) wp_json_encode( [
		[
			'blockName'    => 'core/paragraph',
			'attrs'        => [ 'content' => 'safe <script>alert(1)</script> text' ],
			'innerBlocks'  => [],
			'innerHTML'    => '<p onclick="evil()">hello</p>',
			'innerContent' => [ '<p onclick="evil()">hello</p>' ],
		],
		[
			'blockName'    => 'unknown/widget',
			'attrs'        => [],
			'innerBlocks'  => [],
			'innerHTML'    => '<em>kept</em>',
			'innerContent' => [ '<em>kept</em>' ],
		],
	] );

	$hal_test_clean = hal_mcp_blocks_sanitize_markup( $hal_test_fixture );
	hal_test_assert( ! is_wp_error( $hal_test_clean ), 'fixture block markup must sanitize' );
	hal_test_assert( ! str_contains( $hal_test_clean['markup'], '<script>' ) && ! str_contains( $hal_test_clean['markup'], 'onclick' ), 'script tags and event handlers must not survive sanitization' );
	hal_test_assert( 1 === $hal_test_clean['report']['unverified_blocks'] && in_array( 'unknown/widget', $hal_test_clean['report']['unverified_names'], true ), 'the unknown block must be reported unverified, never blessed as valid' );
	hal_test_assert( 2 === $hal_test_clean['report']['blocks'], 'both blocks must be walked' );

	$hal_test_reparse = hal_mcp_blocks_sanitize_markup( $hal_test_clean['markup'] );
	hal_test_assert( ! is_wp_error( $hal_test_reparse ) && $hal_test_reparse['markup'] === $hal_test_clean['markup'], 'the sanitized markup must be stable across a re-sanitize' );

	// URL-shaped attribute keys: a value under a key ending in 'url' goes
	// through esc_url_raw, so an executable scheme is EMPTIED — not merely
	// kses-stripped; every other key kses through.
	$hal_test_url_fixture = (string) wp_json_encode( [
		[
			'blockName'    => 'core/paragraph',
			'attrs'        => [ 'url' => 'javascript:alert(1)', 'backgroundUrl' => 'javascript:alert(1)', 'linkUrl' => 'javascript:alert(1)', 'content' => 'kept' ],
			'innerBlocks'  => [],
			'innerHTML'    => '',
			'innerContent' => [],
		],
	] );

	$hal_test_url_clean = hal_mcp_blocks_sanitize_markup( $hal_test_url_fixture );
	hal_test_assert( ! is_wp_error( $hal_test_url_clean ), 'the url-attr fixture must sanitize' );

	$hal_test_url_blocks = json_decode( $hal_test_url_clean['markup'], true );
	hal_test_assert(
		is_array( $hal_test_url_blocks )
			&& '' === (string) ( $hal_test_url_blocks[0]['attrs']['url'] ?? 'x' )
			&& '' === (string) ( $hal_test_url_blocks[0]['attrs']['backgroundUrl'] ?? 'x' )
			&& '' === (string) ( $hal_test_url_blocks[0]['attrs']['linkUrl'] ?? 'x' )
			&& 'kept' === (string) ( $hal_test_url_blocks[0]['attrs']['content'] ?? '' ),
		'a value under a url-shaped attr key must be emptied by esc_url_raw while other keys kses through'
	);

	// Readiness: a page-content request derives needs_editor; a post
	// content request is never design-bearing.
	$hal_test_page_id = hal_v04_seed_post( [ 'post_type' => 'page', 'post_status' => 'publish', 'post_title' => 'About', 'post_content' => 'old content' ] );
	$hal_test_post_id = hal_v04_seed_post( [ 'post_type' => 'post', 'post_status' => 'publish', 'post_title' => 'News', 'post_content' => 'old' ] );

	$hal_test_page_request = hal_mcp_create_change_request(
		[
			'operation'         => 'update-page',
			'payload'           => [ 'content' => 'new markup' ],
			'targets'           => [ [ 'type' => 'page', 'id' => $hal_test_page_id ] ],
			'original_snapshot' => [ 'content' => 'old content' ],
			'origin'            => [ 'source' => 'mcp' ],
		]
	);
	hal_test_assert( ! is_wp_error( $hal_test_page_request ), 'the page design request must be created' );
	hal_test_assert( 'needs_editor' === hal_mcp_blocks_request_editor_readiness( (int) $hal_test_page_request['request_id'] ), 'a page design request must derive needs_editor server-side' );

	$hal_test_post_request = hal_mcp_create_change_request(
		[
			'operation'         => 'update-post',
			'payload'           => [ 'content' => 'new text' ],
			'targets'           => [ [ 'type' => 'post', 'id' => $hal_test_post_id ] ],
			'original_snapshot' => [ 'content' => 'old' ],
			'origin'            => [ 'source' => 'mcp' ],
		]
	);
	hal_test_assert( ! is_wp_error( $hal_test_post_request ), 'the post content request must be created' );
	hal_test_assert( '' === hal_mcp_blocks_request_editor_readiness( (int) $hal_test_post_request['request_id'] ), 'a post content request must NOT be design-bearing' );

	// The approval gate: needs_editor is not approvable (F21).
	$hal_test_denied = hal_mcp_approve_change_request( (int) $hal_test_page_request['request_id'] );
	hal_test_assert(
		is_wp_error( $hal_test_denied ) && 'hal_mcp_editor_serialization_required' === array_key_first( (array) $hal_test_denied->errors ),
		'a needs_editor request must be refused by the approval gate'
	);

	// The bridge: a wrong request-version fingerprint is refused.
	$hal_test_wrong = hal_mcp_blocks_ingest_editor_serialization(
		(int) $hal_test_page_request['request_id'],
		[
			'request_fingerprint' => str_repeat( 'a', 64 ),
			'markup'              => '<p>editor result</p>',
		]
	);
	hal_test_assert(
		is_wp_error( $hal_test_wrong ) && 'hal_mcp_bridge_fingerprint_mismatch' === array_key_first( (array) $hal_test_wrong->errors ),
		'an editor result for a different request version must be refused'
	);

	// The bridge refuses anything that is not a design-bearing 'update-page'
	// request — no other operation's payload may be rewritten through it.
	$hal_test_non_design = hal_mcp_blocks_ingest_editor_serialization(
		(int) $hal_test_post_request['request_id'],
		[
			'request_fingerprint' => (string) $hal_test_post_request['proposed_fingerprint'],
			'markup'              => '<p>x</p>',
		]
	);
	hal_test_assert(
		is_wp_error( $hal_test_non_design ) && 'hal_mcp_bridge_target_mismatch' === array_key_first( (array) $hal_test_non_design->errors ),
		'the bridge must refuse a non-design request outright'
	);

	// A request whose payload also carries title/status fields is the NORMAL
	// atomic update-page request: the bridge MERGES into the stored proposal
	// — only the content value is replaced by the sanitized serialization,
	// every other field is preserved.
	$hal_test_mixed = hal_mcp_create_change_request(
		[
			'operation'         => 'update-page',
			'payload'           => [ 'title' => 'New title', 'content' => 'markup' ],
			'targets'           => [ [ 'type' => 'page', 'id' => $hal_test_page_id ] ],
			'original_snapshot' => [ 'title' => 'About', 'content' => 'old content' ],
			'origin'            => [ 'source' => 'mcp' ],
		]
	);
	hal_test_assert( ! is_wp_error( $hal_test_mixed ), 'the mixed-field design request must be created' );

	$hal_test_mixed_ingest = hal_mcp_blocks_ingest_editor_serialization(
		(int) $hal_test_mixed['request_id'],
		[
			'request_fingerprint' => (string) $hal_test_mixed['proposed_fingerprint'],
			'markup'              => '<p>y</p>',
		]
	);
	hal_test_assert( ! is_wp_error( $hal_test_mixed_ingest ) && 'ready' === $hal_test_mixed_ingest['readiness'], 'the mixed-field request must ingest through the merge and flip readiness to ready' );

	$hal_test_mixed_updated = hal_mcp_get_change_request( (int) $hal_test_mixed['request_id'], true );
	hal_test_assert(
		! is_wp_error( $hal_test_mixed_updated )
			&& 'New title' === (string) ( $hal_test_mixed_updated['payload']['title'] ?? '' )
			&& '<p>y</p>' === (string) ( $hal_test_mixed_updated['payload']['content'] ?? '' ),
		'the merged payload must preserve the untouched title and store the sanitized content'
	);

	// The matching result ingests: re-sanitized server-side, stored through
	// the payload-update contract (approval would be voided), readiness
	// flips to ready.
	$hal_test_ingest = hal_mcp_blocks_ingest_editor_serialization(
		(int) $hal_test_page_request['request_id'],
		[
			'request_fingerprint' => (string) $hal_test_page_request['proposed_fingerprint'],
			'markup'              => '<p>editor result <script>bad()</script><span onclick="x()">t</span></p>',
		]
	);
	hal_test_assert( ! is_wp_error( $hal_test_ingest ) && 'ready' === $hal_test_ingest['readiness'], 'a matching editor result must ingest and flip readiness to ready' );

	$hal_test_updated = hal_mcp_get_change_request( (int) $hal_test_page_request['request_id'], true );
	hal_test_assert(
		! is_wp_error( $hal_test_updated ) && ! str_contains( (string) $hal_test_updated['payload']['content'], '<script>' ) && ! str_contains( (string) $hal_test_updated['payload']['content'], 'onclick' ),
		'the stored serialization must be the sanitized one'
	);

	// A later payload edit re-derives needs_editor (F21: أي تعديل لاحق).
	hal_mcp_update_change_request_payload(
		(int) $hal_test_page_request['request_id'],
		[ 'content' => 'another proposal' ],
		[ 'content' => 'old content' ]
	);
	hal_test_assert( 'needs_editor' === hal_mcp_blocks_request_editor_readiness( (int) $hal_test_page_request['request_id'] ), 'a payload edit must re-derive needs_editor' );

	// With the sanitized final version stored, approval passes.
	hal_mcp_blocks_request_set_editor_readiness( (int) $hal_test_page_request['request_id'], 'ready' );
	$hal_test_approved = hal_mcp_approve_change_request( (int) $hal_test_page_request['request_id'] );
	hal_test_assert( ! is_wp_error( $hal_test_approved ) && 'approved' === $hal_test_approved['state'], 'a ready request must pass the approval gate' );

	// Stale readiness: once the proposal stops being design-bearing (a
	// title-only payload), the stored readiness must be CLEARED — a stale
	// 'needs_editor'/'ready' can never outlive its shape — and the approval
	// gate must stand down (the payload edit also reset the state to
	// pending, voiding the approval above).
	hal_mcp_update_change_request_payload(
		(int) $hal_test_page_request['request_id'],
		[ 'title' => 'Title only' ],
		[ 'title' => 'About' ]
	);
	hal_test_assert( '' === hal_mcp_blocks_request_editor_readiness( (int) $hal_test_page_request['request_id'] ), 'a proposal that stopped being design-bearing must read an empty readiness' );

	$hal_test_reapproved = hal_mcp_approve_change_request( (int) $hal_test_page_request['request_id'] );
	hal_test_assert( ! is_wp_error( $hal_test_reapproved ) && 'approved' === $hal_test_reapproved['state'], 'a non-design proposal must approve with no editor-serialization gate' );
}

/**
 * F21 Elementor integration: runtime-marker detection, structure/setting
 * sanitization (scripts stripped, executable urls emptied, ids and global
 * references preserved), read-vs-save honesty on unverifiable element
 * types, the save path refusing without the runtime, the needs_editor
 * derivation for design operations, and the live fingerprint revalidation.
 */
function hal_test_case_integration_elementor_contract(): void {
	$hal_test_state = &hal_v04_state();

	// The page domain layer is part of the contract under test (the ingest
	// guard requires hal_mcp_page_read_current's module, exactly as the
	// bootstrap loads it with the Abilities API present).
	require_once HAL_MCP_ABILITIES_DIR . '/abilities/write-pages.php';

	$hal_test_state['caps'] = [
		'edit_posts'     => true,
		'manage_options' => true,
		'edit_pages'     => true,
		'publish_pages'  => true,
	];

	$hal_test_state['post_types']['page'] = [ 'label' => 'Pages', 'public' => true, 'cap' => [ 'create_posts' => 'edit_pages', 'edit_post' => 'edit_pages', 'read_post' => 'read_pages', 'publish_posts' => 'publish_pages' ] ];

	// Detection: Elementor absent — registered but honestly unavailable.
	hal_test_assert( false === hal_mcp_elementor_present(), 'Elementor must be absent in this environment' );
	hal_test_assert( isset( hal_mcp_integrations()['elementor'] ) && false === hal_mcp_integrations()['elementor']['available'], 'the elementor integration must register as unavailable' );

	$hal_test_page_id = hal_v04_seed_post( [ 'post_type' => 'page', 'post_status' => 'publish', 'post_title' => 'Elementor page' ] );

	// Sanitizer: dangerous values do not survive; ids and globals survive.
	$hal_test_fixture = [
		[
			'id'       => 'a1b2c3d',
			'elType'   => 'section',
			'settings' => [
				'title'         => 'ok <script>evil()</script>',
				'link'          => [ 'url' => 'javascript:alert(1)' ],
				'backgroundUrl' => 'javascript:alert(1)',
				'linkUrl'       => 'javascript:alert(1)',
				'__globals__'   => [ 'typography' => 'global-1' ],
			],
				'elements' => [
					[ 'id' => 'e4f5a6b', 'elType' => 'widget', 'widgetType' => 'heading', 'settings' => [ 'title' => 'Heading' ], 'elements' => [] ],
				],
		],
	];

	$hal_test_clean = hal_mcp_elementor_sanitize_elements( $hal_test_fixture, false );
	hal_test_assert( ! is_wp_error( $hal_test_clean ), 'a verifiable element structure must sanitize' );

	$hal_test_json = (string) wp_json_encode( $hal_test_clean['elements'] );
	hal_test_assert( ! str_contains( $hal_test_json, '<script>' ) && ! str_contains( $hal_test_json, 'javascript:' ), 'script content and executable urls must not survive' );
	hal_test_assert( '' === (string) ( $hal_test_clean['elements'][0]['settings']['backgroundUrl'] ?? 'x' ) && '' === (string) ( $hal_test_clean['elements'][0]['settings']['linkUrl'] ?? 'x' ), 'camelCase url-shaped keys must be emptied by esc_url_raw' );
	hal_test_assert( isset( $hal_test_clean['elements'][0]['settings']['__globals__']['typography'] ), 'global references must be preserved' );
	hal_test_assert( 'a1b2c3d' === $hal_test_clean['elements'][0]['id'] && 'e4f5a6b' === $hal_test_clean['elements'][0]['elements'][0]['id'], 'element ids must be preserved' );

	// Unverifiable element type AND a hostile element id: the READ reports
	// and excludes both; the SAVE refuses outright. The hostile id is
	// treated exactly like an unverifiable type (Elementor's own generated
	// ids are 5-32 hex chars).
	$hal_test_unknown = [
		[ 'id' => 'z9', 'elType' => 'future-thing', 'settings' => [] ],
		[ 'id' => '0a1b2c3', 'elType' => 'section', 'settings' => [] ],
		[ 'id' => 'body{background:url(//evil)}', 'elType' => 'section', 'settings' => [] ],
	];

	$hal_test_read = hal_mcp_elementor_sanitize_elements( $hal_test_unknown, false );
	hal_test_assert( ! is_wp_error( $hal_test_read ) && 1 === count( $hal_test_read['elements'] ), 'the read must keep only the conforming element' );
	hal_test_assert( in_array( 'future-thing', $hal_test_read['report']['unverified_types'], true ), 'the read must report the unverifiable element type' );
	hal_test_assert( in_array( 'id:body{background:url(//evil)}', $hal_test_read['report']['unverified_types'], true ), 'the read must report the non-conforming element id like an unverifiable type' );
	hal_test_assert( is_wp_error( hal_mcp_elementor_sanitize_elements( $hal_test_unknown ) ), 'the save path must refuse the unverifiable element type' );

	$hal_test_save_refused = hal_mcp_elementor_sanitize_elements( $hal_test_unknown );
	hal_test_assert(
		is_wp_error( $hal_test_save_refused ) && str_contains( $hal_test_save_refused->get_error_message(), 'id:body{background:url(//evil)}' ),
		'the save refusal must name the non-conforming element id'
	);

	// Widget availability: without the runtime nothing is verified (null),
	// never a silent yes.
	hal_test_assert( null === hal_mcp_elementor_widget_available( 'heading' ), 'widget availability must be null without the runtime' );

	// Reads work from the stored design without the runtime; corrupt and
	// missing storage are honest refusals.
	update_post_meta( $hal_test_page_id, '_elementor_data', wp_json_encode( $hal_test_fixture ) );
	$hal_test_stored = hal_mcp_elementor_read_stored_design( $hal_test_page_id );
	hal_test_assert( ! is_wp_error( $hal_test_stored ) && 1 === count( $hal_test_stored['elements'] ), 'the stored design must read and validate' );

	update_post_meta( $hal_test_page_id, '_elementor_data', '{broken' );
	hal_test_assert( is_wp_error( hal_mcp_elementor_read_stored_design( $hal_test_page_id ) ), 'a corrupted design must be an honest refusal, never half-interpreted' );
	delete_post_meta( $hal_test_page_id, '_elementor_data' );
	hal_test_assert( is_wp_error( hal_mcp_elementor_read_stored_design( $hal_test_page_id ) ), 'a missing design must be an honest not-found' );

	// The save path refuses without the runtime — no cache edits, no flat
	// HTML replacement, no faked success.
	$hal_test_save_refused = hal_mcp_elementor_apply_design( $hal_test_page_id, $hal_test_fixture );
	hal_test_assert(
		is_wp_error( $hal_test_save_refused ) && 'hal_mcp_elementor_unavailable' === array_key_first( (array) $hal_test_save_refused->errors ),
		'the save must refuse without the Elementor runtime'
	);

	// needs_editor for the design operation; the apply handler is
	// registered and refuses honestly at apply time (a design present in
	// storage, so approval re-stamps a real fingerprint first).
	hal_test_assert( hal_mcp_has_apply_handler( 'update-page-design' ), 'the update-page-design handler must be registered' );

	update_post_meta( $hal_test_page_id, '_elementor_data', wp_json_encode( $hal_test_fixture ) );

	$hal_test_design_request = hal_mcp_create_change_request(
		[
			'operation'         => 'update-page-design',
			'payload'           => [ 'elements' => $hal_test_fixture ],
			'targets'           => [ [ 'type' => 'page', 'id' => $hal_test_page_id ] ],
			'original_snapshot' => [],
			'origin'            => [ 'source' => 'mcp' ],
		]
	);
	hal_test_assert( ! is_wp_error( $hal_test_design_request ), 'the design request must be created' );
	hal_test_assert( 'needs_editor' === hal_mcp_blocks_request_editor_readiness( (int) $hal_test_design_request['request_id'] ), 'a design operation must derive needs_editor (blocks readiness covers it)' );

	hal_mcp_blocks_request_set_editor_readiness( (int) $hal_test_design_request['request_id'], 'ready' );
	hal_test_assert( ! is_wp_error( hal_mcp_approve_change_request( (int) $hal_test_design_request['request_id'] ) ), 'the ready design request must approve' );

	$hal_test_design_apply = hal_mcp_apply_change_request( (int) $hal_test_design_request['request_id'] );
	hal_test_assert( is_wp_error( $hal_test_design_apply ), 'the apply must refuse without the Elementor runtime' );
	hal_test_assert( 'failed' === hal_mcp_request_read_status( (int) $hal_test_design_request['request_id'] ), 'the failed apply must be recorded honestly as failed' );

	// The server-side ready transition (the Elementor half of the editor
	// bridge): a real-editor design result ingests through the same contract
	// as the blocks bridge — wrong version refused, extra payload fields
	// refused, a matching result stored sanitized and readiness flipped to
	// ready so the request can finally be approved.
	$hal_test_second_request = hal_mcp_create_change_request(
		[
			'operation'         => 'update-page-design',
			'payload'           => [ 'elements' => $hal_test_fixture ],
			'targets'           => [ [ 'type' => 'page', 'id' => $hal_test_page_id ] ],
			'original_snapshot' => [],
			'origin'            => [ 'source' => 'mcp' ],
		]
	);
	hal_test_assert( ! is_wp_error( $hal_test_second_request ), 'the second design request must be created' );

	$hal_test_wrong_fp = hal_mcp_elementor_ingest_editor_design(
		(int) $hal_test_second_request['request_id'],
		[
			'request_fingerprint' => str_repeat( 'b', 64 ),
			'elements'            => $hal_test_fixture,
		]
	);
	hal_test_assert(
		is_wp_error( $hal_test_wrong_fp ) && 'hal_mcp_bridge_fingerprint_mismatch' === array_key_first( (array) $hal_test_wrong_fp->errors ),
		'an editor design result for a different request version must be refused'
	);

	$hal_test_extra_request = hal_mcp_create_change_request(
		[
			'operation'         => 'update-page-design',
			'payload'           => [ 'elements' => $hal_test_fixture, 'title' => 'not design-only' ],
			'targets'           => [ [ 'type' => 'page', 'id' => $hal_test_page_id ] ],
			'original_snapshot' => [],
			'origin'            => [ 'source' => 'mcp' ],
		]
	);
	hal_test_assert( ! is_wp_error( $hal_test_extra_request ), 'the extra-field design request must be created' );

	$hal_test_extra_refused = hal_mcp_elementor_ingest_editor_design(
		(int) $hal_test_extra_request['request_id'],
		[
			'request_fingerprint' => (string) $hal_test_extra_request['proposed_fingerprint'],
			'elements'            => $hal_test_fixture,
		]
	);
	hal_test_assert(
		is_wp_error( $hal_test_extra_refused ) && 'hal_mcp_elementor_bridge_payload_has_other_fields' === array_key_first( (array) $hal_test_extra_refused->errors ),
		'the Elementor bridge must refuse a payload carrying fields beyond the design fields'
	);

	$hal_test_ingest = hal_mcp_elementor_ingest_editor_design(
		(int) $hal_test_second_request['request_id'],
		[
			'request_fingerprint' => (string) $hal_test_second_request['proposed_fingerprint'],
			'elements'            => $hal_test_fixture,
		]
	);
	hal_test_assert( ! is_wp_error( $hal_test_ingest ) && 'ready' === $hal_test_ingest['readiness'], 'a matching editor design result must ingest and flip readiness to ready' );

	$hal_test_ingest_updated = hal_mcp_get_change_request( (int) $hal_test_second_request['request_id'], true );
	hal_test_assert(
		! is_wp_error( $hal_test_ingest_updated ) && isset( $hal_test_ingest_updated['payload']['serialization']['source'] ) && 'editor_bridge' === $hal_test_ingest_updated['payload']['serialization']['source'],
		'the ingested design must store the bridge serialization report'
	);

	hal_test_assert( ! is_wp_error( hal_mcp_approve_change_request( (int) $hal_test_second_request['request_id'] ) ), 'the ingested design request must now approve' );

	$hal_test_second_apply = hal_mcp_apply_change_request( (int) $hal_test_second_request['request_id'] );
	hal_test_assert( is_wp_error( $hal_test_second_apply ), 'the apply must still refuse honestly without the Elementor runtime' );
	hal_test_assert( 'failed' === hal_mcp_request_read_status( (int) $hal_test_second_request['request_id'] ), 'the failed apply must be recorded honestly as failed' );

	// Live revalidation: the fingerprint covers the RAW stored design and
	// moves with it; a gone design yields '' (apply-time conflict).
	$hal_test_request_data = hal_mcp_get_change_request( (int) $hal_test_design_request['request_id'], true );
	$hal_test_fp_before    = hal_mcp_elementor_revalidate_fingerprint( is_wp_error( $hal_test_request_data ) ? [] : $hal_test_request_data );
	update_post_meta( $hal_test_page_id, '_elementor_data', wp_json_encode( [ [ 'id' => 'changed', 'elType' => 'section', 'settings' => [] ] ] ) );
	$hal_test_fp_after = hal_mcp_elementor_revalidate_fingerprint( is_wp_error( $hal_test_request_data ) ? [] : $hal_test_request_data );
	hal_test_assert( '' !== $hal_test_fp_before && $hal_test_fp_before !== $hal_test_fp_after, 'the design fingerprint must read the live storage' );
	delete_post_meta( $hal_test_page_id, '_elementor_data' );
	hal_test_assert( '' === hal_mcp_elementor_revalidate_fingerprint( is_wp_error( $hal_test_request_data ) ? [] : $hal_test_request_data ), 'a gone design must yield an empty fingerprint (apply-time conflict)' );
}

/**
 * F22 SEO integration: registration honesty without Yoast, the unified
 * field contract (canonical/robots/social/schema explicitly not writable
 * WITH reasons), unknown-field refusal by name, the Yoast handler's
 * read/write through the documented Metadata API, the draft-direct vs
 * protected-request path split, and the live fingerprint revalidation with
 * a full approve/apply loop.
 */
function hal_test_case_integration_seo_contract(): void {
	$hal_test_state = &hal_v04_state();

	// Without Yoast: honest registration and refusals.
	hal_test_assert( false === hal_mcp_seo_yoast_present(), 'Yoast must be absent in this environment' );
	hal_test_assert( isset( hal_mcp_integrations()['seo'] ) && false === hal_mcp_integrations()['seo']['available'], 'the seo integration must register as unavailable without Yoast' );
	hal_test_assert( is_wp_error( hal_mcp_seo_read_fields( 1 ) ), 'reads must refuse without a verified interface' );
	hal_test_assert( is_wp_error( hal_mcp_seo_apply_fields( 1, [ 'seo_title' => 'x' ] ) ), 'writes must refuse without a verified interface' );

	$hal_test_contract = hal_mcp_seo_field_contract();
	foreach ( [ 'canonical', 'robots', 'social', 'schema' ] as $hal_test_field ) {
		hal_test_assert( false === $hal_test_contract[ $hal_test_field ]['writable'] && '' !== $hal_test_contract[ $hal_test_field ]['reason'], "the {$hal_test_field} field must be not-writable WITH its reason" );
	}

	// The Yoast interface FIXTURE (the documented Metadata API shape). The
	// integration's availability snapshot stays what it was at load time —
	// honesty about the past; the handler's guards check the interface at
	// call time — honesty about the present. Both asserted deliberately.
	if ( ! defined( 'WPSEO_VERSION' ) ) {
		define( 'WPSEO_VERSION', '24.5-fixture' );
	}

	if ( ! class_exists( 'WPSEO_Meta' ) ) {
		/**
		 * Yoast Metadata API fixture: get_value/set_value over a per-post
		 * store, recording every write for the cases to assert on.
		 */
		final class WPSEO_Meta {

			/** @var array<int, array<string, string>> */
			public static $storage = [];

			/**
			 * Reads one meta value.
			 *
			 * @param string $meta_key Meta key.
			 * @param int    $post_id  Post ID.
			 * @return string
			 */
			public static function get_value( string $meta_key, int $post_id = 0 ): string {
				return self::$storage[ $post_id ][ $meta_key ] ?? '';
			}

			/**
			 * Writes one meta value.
			 *
			 * @param string $meta_key   Meta key.
			 * @param mixed  $meta_value Value.
			 * @param int    $post_id    Post ID.
			 * @return bool
			 */
			public static function set_value( string $meta_key, $meta_value, int $post_id ): bool {
				self::$storage[ $post_id ][ $meta_key ] = (string) $meta_value;

				return true;
			}
		}
	}

	hal_test_assert( true === hal_mcp_seo_yoast_present(), 'the handler guards must see the interface at call time' );

	$hal_test_state['caps'] = [ 'edit_posts' => true, 'manage_options' => true ];
	$hal_test_state['post_types']['post'] = [ 'label' => 'Posts', 'public' => true, 'cap' => [ 'create_posts' => 'edit_posts', 'edit_post' => 'edit_posts', 'read_post' => 'read_posts', 'publish_posts' => 'publish_posts' ] ];

	$hal_test_draft = hal_v04_seed_post( [ 'post_type' => 'post', 'post_status' => 'draft', 'post_title' => 'Draft' ] );
	$hal_test_live  = hal_v04_seed_post( [ 'post_type' => 'post', 'post_status' => 'publish', 'post_title' => 'Live' ] );

	$hal_test_read = hal_mcp_seo_read_fields( $hal_test_draft );
	hal_test_assert( ! is_wp_error( $hal_test_read ) && true === $hal_test_read['seo_title']['writable'], 'the title field must read as writable with the interface present' );

	hal_test_assert( is_wp_error( hal_mcp_seo_read_fields( $hal_test_draft, [ 'meta_keywords' ] ) ), 'an unknown field must be refused by name' );
	hal_test_assert( is_wp_error( hal_mcp_seo_apply_fields( $hal_test_draft, [ 'canonical' => 'https://x' ] ) ), 'a non-writable field must be refused on write' );

	// Draft: direct apply through the verified interface.
	$hal_test_direct = hal_mcp_seo_propose_fields( 'post', $hal_test_draft, [ 'seo_title' => 'Draft title' ] );
	hal_test_assert( ! is_wp_error( $hal_test_direct ) && true === $hal_test_direct['applied_directly'], 'a draft SEO change must apply directly' );
	hal_test_assert( 'Draft title' === WPSEO_Meta::get_value( 'title', $hal_test_draft ), 'the title must be written through the verified interface' );

	// Published: request path with the before-values as snapshot.
	$hal_test_queued = hal_mcp_seo_propose_fields( 'post', $hal_test_live, [ 'seo_title' => 'New SEO title', 'seo_description' => 'New desc' ] );
	hal_test_assert( ! is_wp_error( $hal_test_queued ) && false === $hal_test_queued['applied_directly'] && (int) $hal_test_queued['request_id'] > 0, 'a published item\'s SEO change must become a change request' );
	hal_test_assert( isset( $hal_test_queued['before_values'] ), 'the request must carry the before-values for the preview' );

	// A change behind the requester's back becomes the new live baseline the
	// approval re-stamps from — the stored payload is what applies.
	WPSEO_Meta::set_value( 'title', 'changed behind the approver\'s back', $hal_test_live );
	hal_test_assert( ! is_wp_error( hal_mcp_approve_change_request( (int) $hal_test_queued['request_id'] ) ), 'the seo request must approve' );

	$hal_test_applied = hal_mcp_apply_change_request( (int) $hal_test_queued['request_id'] );
	hal_test_assert( ! is_wp_error( $hal_test_applied ) && 'applied' === $hal_test_applied['state'], 'the approved seo request must apply through its handler' );
	hal_test_assert( 'New SEO title' === WPSEO_Meta::get_value( 'title', $hal_test_live ), 'the handler must apply the stored payload only' );

	// A live change AFTER approval is a conflict — never a silent overwrite.
	$hal_test_second = hal_mcp_seo_propose_fields( 'post', $hal_test_live, [ 'seo_title' => 'Second proposal' ] );
	hal_test_assert( ! is_wp_error( $hal_test_second ) && (int) $hal_test_second['request_id'] > 0, 'the second seo request must queue' );
	hal_test_assert( ! is_wp_error( hal_mcp_approve_change_request( (int) $hal_test_second['request_id'] ) ), 'the second seo request must approve' );
	WPSEO_Meta::set_value( 'title', 'live change after approval', $hal_test_live );

	$hal_test_conflict = hal_mcp_apply_change_request( (int) $hal_test_second['request_id'] );
	hal_test_assert( is_wp_error( $hal_test_conflict ), 'a changed original must conflict at apply time' );
	hal_test_assert( 'conflict' === hal_mcp_request_read_status( (int) $hal_test_second['request_id'] ), 'the conflict must be recorded' );
	hal_test_assert( 'live change after approval' === WPSEO_Meta::get_value( 'title', $hal_test_live ), 'the conflicting payload must never overwrite the live value' );

	// Live revalidation reads the interface, never the snapshot.
	hal_test_assert( hal_mcp_has_apply_handler( 'update-seo' ), 'the update-seo handler must be registered' );
	$hal_test_probe = [ 'targets' => [ [ 'id' => $hal_test_live ] ], 'payload' => [ 'seo_title' => 'x' ] ];
	$hal_test_fp1   = hal_mcp_seo_revalidate_fingerprint( $hal_test_probe );
	WPSEO_Meta::set_value( 'title', 'moved again', $hal_test_live );
	$hal_test_fp2 = hal_mcp_seo_revalidate_fingerprint( $hal_test_probe );
	hal_test_assert( '' !== $hal_test_fp1 && $hal_test_fp1 !== $hal_test_fp2, 'the revalidate must read live values' );

	// The hal/get-page seo_summary AVAILABLE branch: with the verified
	// interface present, the page read reports the SEO values through it
	// (the unavailable branch is covered in V02).
	require_once HAL_MCP_ABILITIES_DIR . '/abilities/read-pages.php';
	hal_mcp_register_read_page_abilities();

	$hal_test_state['post_types']['page'] = [ 'label' => 'Pages', 'public' => true, 'cap' => [ 'create_posts' => 'edit_pages', 'edit_post' => 'edit_pages', 'read_post' => 'read_pages', 'publish_posts' => 'publish_pages' ] ];
	$hal_test_state['caps']['edit_pages'] = true;
	$hal_test_state['caps']['read_pages'] = true;

	// On a real site the integration registers available at plugins_loaded
	// (Yoast active before this plugin loaded); in this subprocess the
	// fixture was defined after that moment, so the load-time definition is
	// updated to what the now-present interface implies — the handler's own
	// guards re-check the interface at call time either way.
	$hal_test_integrations_ref                     = &hal_mcp_integrations();
	$hal_test_integrations_ref['seo']['available'] = true;

	$hal_test_seo_page = hal_v04_seed_post( [ 'post_type' => 'page', 'post_status' => 'publish', 'post_title' => 'SEO page' ] );
	WPSEO_Meta::set_value( 'title', 'Page SEO title', $hal_test_seo_page );
	WPSEO_Meta::set_value( 'metadesc', 'Page SEO description', $hal_test_seo_page );

	$hal_test_page_ability = hal_v04_state()['registered_abilities']['hal/get-page'] ?? null;
	hal_test_assert( is_array( $hal_test_page_ability ), 'hal/get-page must register through the Abilities API' );

	$hal_test_page_out = call_user_func( $hal_test_page_ability['execute_callback'], [ 'page_id' => $hal_test_seo_page ] );
	hal_test_assert( ! is_wp_error( $hal_test_page_out ), 'hal/get-page must execute for the seeded page' );
	hal_test_assert( true === ( $hal_test_page_out['seo_summary']['available'] ?? null ), 'seo_summary must be available with the verified interface present' );
	hal_test_assert(
		'Page SEO title' === (string) ( $hal_test_page_out['seo_summary']['fields']['seo_title'] ?? '' )
			&& 'Page SEO description' === (string) ( $hal_test_page_out['seo_summary']['fields']['seo_description'] ?? '' ),
		'the seo fields must carry the values read from the WPSEO fixture store'
	);
	hal_test_assert( str_contains( (string) ( $hal_test_page_out['seo_summary']['note'] ?? '' ), 'verified interface' ), 'the seo note must say the values come from the verified interface' );
}

/**
 * F23 translations integration: honest refusal and no invented relations
 * without a system; the Polylang branch through the documented function
 * reference (resolve/assign/link with group preservation and conflict
 * refusal, widened language surface, published-source link going through
 * the approval flow); the WPML branch through the official group interface.
 */
function hal_test_case_integration_translations_contract(): void {
	$hal_test_state = &hal_v04_state();

	// Without a system: refusals, no invented relations, locale-only surface.
	hal_test_assert( '' === hal_mcp_translations_active_system(), 'no translation system must be detected in this environment' );
	hal_test_assert( isset( hal_mcp_integrations()['translations'] ) && false === hal_mcp_integrations()['translations']['available'], 'the translations integration must register as unavailable' );
	hal_test_assert( is_wp_error( hal_mcp_translations_read_links( 1, 'post' ) ), 'reading links must refuse without a system' );
	hal_test_assert( is_wp_error( hal_mcp_translations_assign_language( 1, 'post', 'fr' ) ), 'assigning a language must refuse without a system' );
	hal_test_assert( is_wp_error( hal_mcp_translations_link_translation( 1, 2, 'post', 'fr' ) ), 'linking must refuse without a system' );
	hal_test_assert( is_wp_error( hal_mcp_translations_propose_link( 1, 2, 'post', 'fr' ) ), 'proposing a link must refuse without a system' );
	hal_test_assert( [ 'en_US' ] === hal_mcp_supported_languages(), 'the language surface must stay the site locale without a system' );

	$hal_test_state['caps'] = [ 'edit_posts' => true, 'manage_options' => true ];
	$hal_test_state['post_types']['post'] = [ 'label' => 'Posts', 'public' => true, 'cap' => [ 'create_posts' => 'edit_posts', 'edit_post' => 'edit_posts', 'read_post' => 'read_posts', 'publish_posts' => 'publish_posts' ] ];

	// --- Polylang fixture (the documented function reference). ---
	if ( ! defined( 'POLYLANG_VERSION' ) ) {
		define( 'POLYLANG_VERSION', '3.6-fixture' );
	}

	if ( ! function_exists( 'pll_languages_list' ) ) {
		/** Configured languages from the stub state. */
		function pll_languages_list(): array {
			return (array) ( hal_v04_state()['pll_languages'] ?? [] );
		}
	}

	if ( ! function_exists( 'pll_get_post_language' ) ) {
		/** One post's language from the stub state. */
		function pll_get_post_language( int $post_id ): string {
			return (string) ( hal_v04_state()['pll_post_lang'][ $post_id ] ?? '' );
		}
	}

	if ( ! function_exists( 'pll_set_post_language' ) ) {
		/** Assigns one post's language in the stub state. */
		function pll_set_post_language( int $post_id, string $lang ): void {
			hal_v04_state()['pll_post_lang'][ $post_id ] = $lang;
		}
	}

	if ( ! function_exists( 'pll_get_post_translations' ) ) {
		/**
		 * One post's translation group (documented shape: the group includes
		 * the post itself; a post with a language but no group is its own
		 * single-member group).
		 *
		 * @param int $post_id Post ID.
		 * @return array<string, int>
		 */
		function pll_get_post_translations( int $post_id ): array {
			$hal_test_stored = hal_v04_state()['pll_translations'][ $post_id ] ?? [];

			if ( ! empty( $hal_test_stored ) ) {
				return $hal_test_stored;
			}

			$hal_test_lang = (string) ( hal_v04_state()['pll_post_lang'][ $post_id ] ?? '' );

			return '' !== $hal_test_lang ? [ $hal_test_lang => $post_id ] : [];
		}
	}

	if ( ! function_exists( 'pll_save_post_translations' ) ) {
		/**
		 * Saves the whole group for every member (documented shape). Real
		 * Polylang (PLL_Translated_Object::save_translations →
		 * validate_translations) silently DROPS entries whose post has no
		 * language term — mirrored honestly: an entry whose member holds no
		 * language in the stub state is not stored.
		 */
		function pll_save_post_translations( array $arr ): void {
			$hal_test_clean = [];

			foreach ( $arr as $hal_test_lang => $hal_test_member ) {
				if ( '' !== (string) ( hal_v04_state()['pll_post_lang'][ (int) $hal_test_member ] ?? '' ) ) {
					$hal_test_clean[ (string) $hal_test_lang ] = (int) $hal_test_member;
				}
			}

			foreach ( $hal_test_clean as $hal_test_member ) {
				hal_v04_state()['pll_translations'][ $hal_test_member ] = $hal_test_clean;
			}
		}
	}

	$hal_test_state['pll_languages'] = [ 'en_US', 'fr_FR' ];

	hal_test_assert( 'polylang' === hal_mcp_translations_active_system(), 'the Polylang branch must activate through its documented markers' );
	hal_test_assert( '' !== hal_mcp_translations_active_version(), 'the version source must be recorded' );
	hal_test_assert( in_array( 'fr_FR', hal_mcp_supported_languages(), true ), 'the configured languages must widen the existing language surface' );

	// resolve-language: canonical, case-insensitive; unconfigured refused.
	hal_test_assert( 'fr_FR' === hal_mcp_translations_resolve_language( 'fr_fr' ), 'the resolver must return the canonical configured code' );
	hal_test_assert( '' === hal_mcp_translations_resolve_language( 'de_DE' ), 'an unconfigured language must not resolve' );

	$hal_test_p1 = hal_v04_seed_post( [ 'post_type' => 'post', 'post_status' => 'draft' ] );
	$hal_test_p2 = hal_v04_seed_post( [ 'post_type' => 'post', 'post_status' => 'draft' ] );
	$hal_test_p3 = hal_v04_seed_post( [ 'post_type' => 'post', 'post_status' => 'publish' ] );
	$hal_test_p4 = hal_v04_seed_post( [ 'post_type' => 'post', 'post_status' => 'draft' ] );
	$hal_test_p5 = hal_v04_seed_post( [ 'post_type' => 'post', 'post_status' => 'draft' ] );

	// Assign + re-assignment refusal.
	hal_test_assert( ! is_wp_error( hal_mcp_translations_assign_language( $hal_test_p1, 'post', 'en_US' ) ), 'assign-language must assign a configured language' );
	hal_test_assert( is_wp_error( hal_mcp_translations_assign_language( $hal_test_p1, 'post', 'fr_FR' ) ), 'silent re-assignment must be refused' );

	// Draft pair: direct link, group preserved, conflict refused.
	$hal_test_link = hal_mcp_translations_link_translation( $hal_test_p1, $hal_test_p2, 'post', 'fr_FR' );
	hal_test_assert( ! is_wp_error( $hal_test_link ) && [ 'en_US' => $hal_test_p1, 'fr_FR' => $hal_test_p2 ] === $hal_test_link['links'], 'linking a draft pair must work directly and preserve the group' );
	hal_test_assert( is_wp_error( hal_mcp_translations_link_translation( $hal_test_p1, $hal_test_p5, 'post', 'fr_FR' ) ), 'a conflicting group set must be refused' );

	// A target already sitting in another group is refused, never silently
	// regrouped (F23: الحفاظ على الروابط القديمة غير المستهدفة).
	$hal_test_c = hal_v04_seed_post( [ 'post_type' => 'post', 'post_status' => 'draft' ] );
	hal_test_assert( ! is_wp_error( hal_mcp_translations_assign_language( $hal_test_c, 'post', 'en_US' ) ), 'the new source must take a language first' );
	$hal_test_regroup = hal_mcp_translations_link_translation( $hal_test_c, $hal_test_p2, 'post', 'fr_FR' );
	hal_test_assert(
		is_wp_error( $hal_test_regroup ) && 'hal_mcp_translation_target_in_group' === array_key_first( (array) $hal_test_regroup->errors ),
		'a target already in another group must be refused, not silently regrouped'
	);

	// The TARGET's status decides too: a DRAFT source linked to a PUBLISHED
	// target is a request, never direct (F23: العلاقات التي تؤثر في محتوى
	// منشور أو hreflang تمر بالاعتماد أيضًا).
	$hal_test_p6 = hal_v04_seed_post( [ 'post_type' => 'post', 'post_status' => 'publish' ] );
	hal_test_assert( ! is_wp_error( hal_mcp_translations_assign_language( $hal_test_p6, 'post', 'en_US' ) ), 'the published target must take a language first' );
	$hal_test_p7 = hal_v04_seed_post( [ 'post_type' => 'post', 'post_status' => 'draft' ] );

	$hal_test_cross = hal_mcp_translations_propose_link( $hal_test_p7, $hal_test_p6, 'post', 'fr_FR' );
	hal_test_assert(
		! is_wp_error( $hal_test_cross ) && false === $hal_test_cross['applied_directly'] && (int) $hal_test_cross['request_id'] > 0,
		'a link touching a PUBLISHED target must become a change request even from a draft source'
	);

	// Published source: the link goes through the approval flow (F23).
	hal_test_assert( ! is_wp_error( hal_mcp_translations_assign_language( $hal_test_p3, 'post', 'en_US' ) ), 'the published source must take a language first' );

	$hal_test_proposal = hal_mcp_translations_propose_link( $hal_test_p3, $hal_test_p4, 'post', 'fr_FR' );
	hal_test_assert( ! is_wp_error( $hal_test_proposal ) && false === $hal_test_proposal['applied_directly'] && (int) $hal_test_proposal['request_id'] > 0, 'a link on a published source must become a change request' );
	hal_test_assert( isset( $hal_test_proposal['before_links'] ), 'the request must carry the group\'s before-links' );

	hal_test_assert( hal_mcp_has_apply_handler( 'link-translation' ), 'the link-translation handler must be registered' );
	hal_test_assert( ! is_wp_error( hal_mcp_approve_change_request( (int) $hal_test_proposal['request_id'] ) ), 'the link request must approve' );

	$hal_test_applied = hal_mcp_apply_change_request( (int) $hal_test_proposal['request_id'] );
	hal_test_assert( ! is_wp_error( $hal_test_applied ) && 'applied' === $hal_test_applied['state'], 'the link request must apply through its handler' );

	$hal_test_group = hal_mcp_translations_read_links( $hal_test_p3, 'post' );
	hal_test_assert( ( (int) $hal_test_group['links']['fr_FR'] ?? 0 ) === $hal_test_p4, 'the applied link must be verifiable in the live group' );

	// Authorization binding (F23 audit): a raw request whose payload names
	// DIFFERENT objects than its stored targets must approve (the gates run
	// on the targets the approver previews) but never apply — the handler
	// derives ids and type from the target records and refuses the
	// divergent payload.
	$hal_test_p8 = hal_v04_seed_post( [ 'post_type' => 'post', 'post_status' => 'draft' ] );
	$hal_test_p9 = hal_v04_seed_post( [ 'post_type' => 'post', 'post_status' => 'draft' ] );

	$hal_test_mismatch = hal_mcp_create_change_request(
		[
			'operation'         => 'link-translation',
			'payload'           => [
				'source_id' => $hal_test_p8,
				'target_id' => $hal_test_p9,
				'post_type' => 'post',
				'language'  => 'en_US',
			],
			'targets'           => [
				[ 'type' => 'post', 'id' => $hal_test_p1 ],
			],
			'original_snapshot' => [],
			'origin'            => [ 'source' => 'mcp' ],
		]
	);
	hal_test_assert( ! is_wp_error( $hal_test_mismatch ), 'the mismatched raw request must be created (the requester gate runs on the real target)' );
	hal_test_assert( ! is_wp_error( hal_mcp_approve_change_request( (int) $hal_test_mismatch['request_id'] ) ), 'the mismatched request must approve — the approver gates run on the targets' );

	$hal_test_mismatch_apply = hal_mcp_apply_change_request( (int) $hal_test_mismatch['request_id'] );
	hal_test_assert( is_wp_error( $hal_test_mismatch_apply ), 'a payload diverging from the approved targets must not apply' );
	hal_test_assert( 'failed' === hal_mcp_request_read_status( (int) $hal_test_mismatch['request_id'] ), 'the divergent payload must be recorded as a failed apply' );
	hal_test_assert( str_contains( (string) $hal_test_mismatch_apply->get_error_message(), 'does not match' ), 'the apply refusal must carry the payload/targets mismatch message' );

	// The apply wrapper re-wraps a handler refusal with its own fixed code,
	// so the handler-level code is asserted at the handler boundary; the
	// apply result above carries the same refusal message.
	$hal_test_handler_registry  = &hal_mcp_apply_handler_registry();
	$hal_test_handler_error     = call_user_func( $hal_test_handler_registry['link-translation']['handler'], hal_mcp_get_change_request( (int) $hal_test_mismatch['request_id'], true ) );
	hal_test_assert(
		is_wp_error( $hal_test_handler_error ) && 'hal_mcp_translation_payload_mismatch' === array_key_first( (array) $hal_test_handler_error->errors ),
		'the handler must refuse the divergent payload with hal_mcp_translation_payload_mismatch'
	);

	// Approve→apply conflict (F23 audit): a second link on the SAME
	// published source is approved against the group state it previewed; a
	// link added to that group behind the approver's back makes the apply a
	// CONFLICT — the approved map is never silently overwritten.
	$hal_test_state['pll_languages'] = [ 'en_US', 'fr_FR', 'de_DE', 'es_ES' ];
	hal_test_assert( ! is_wp_error( hal_mcp_translations_assign_language( $hal_test_p5, 'post', 'de_DE' ) ), 'the second draft target must take its language first' );

	$hal_test_second_link = hal_mcp_translations_propose_link( $hal_test_p3, $hal_test_p5, 'post', 'de_DE' );
	hal_test_assert( ! is_wp_error( $hal_test_second_link ) && false === $hal_test_second_link['applied_directly'] && (int) $hal_test_second_link['request_id'] > 0, 'the second link on the published source must become a change request' );
	hal_test_assert( ! is_wp_error( hal_mcp_approve_change_request( (int) $hal_test_second_link['request_id'] ) ), 'the second link request must approve' );

	// Behind the approver's back: another allowed pair links into the SAME
	// source group (real Polylang lets another editor grow the group), which
	// changes the live link map the approval was anchored to.
	$hal_test_p10 = hal_v04_seed_post( [ 'post_type' => 'post', 'post_status' => 'draft' ] );
	hal_test_assert( ! is_wp_error( hal_mcp_translations_assign_language( $hal_test_p10, 'post', 'es_ES' ) ), 'the back-door target must take a configured language first' );
	hal_test_assert( ! is_wp_error( hal_mcp_translations_link_translation( $hal_test_p3, $hal_test_p10, 'post', 'es_ES' ) ), 'the back-door link must be a legal link on an authorized pair' );

	$hal_test_second_apply = hal_mcp_apply_change_request( (int) $hal_test_second_link['request_id'] );
	hal_test_assert( is_wp_error( $hal_test_second_apply ), 'a group changed after approval must conflict at apply time' );
	hal_test_assert( 'conflict' === hal_mcp_request_read_status( (int) $hal_test_second_link['request_id'] ), 'the conflict must be recorded' );

	$hal_test_group_after = hal_mcp_translations_read_links( $hal_test_p3, 'post' );
	hal_test_assert(
		( (int) ( $hal_test_group_after['links']['es_ES'] ?? 0 ) ) === $hal_test_p10 && ! isset( $hal_test_group_after['links']['de_DE'] ),
		'the conflicting payload must never overwrite the live group map'
	);

	// --- WPML fixture (the official language/group interface). ---
	if ( ! defined( 'ICL_SITEPRESS_VERSION' ) ) {
		define( 'ICL_SITEPRESS_VERSION', '4.7-fixture' );
	}

	if ( ! class_exists( 'SitePress' ) ) {
		/**
		 * WPML SitePress fixture: element groups with trid lookup and
		 * translations reads, exactly the two methods the branch guards on.
		 */
		class SitePress {

			/**
			 * Finds the group trid containing an element.
			 *
			 * @param int    $element_id   Element ID.
			 * @param string $element_type Element type.
			 * @return int 0 when no group exists.
			 */
			public function get_element_trid( int $element_id, string $element_type ): int {
				foreach ( hal_v04_state()['wpml_groups'] as $hal_v04_trid => $hal_v04_group ) {
					if ( $hal_v04_group['type'] === $element_type && in_array( $element_id, $hal_v04_group['elements'], true ) ) {
						return (int) $hal_v04_trid;
					}
				}

				return 0;
			}

			/**
			 * Reads one group's translations (lang => element objects).
			 *
			 * @param int    $trid         Group trid.
			 * @param string $element_type Element type.
			 * @return array<string, object>
			 */
			public function get_element_translations( int $trid, string $element_type = '' ): array {
				$hal_v04_group = hal_v04_state()['wpml_groups'][ $trid ] ?? null;

				if ( null === $hal_v04_group ) {
					return [];
				}

				$hal_v04_out = [];

				foreach ( $hal_v04_group['elements'] as $hal_v04_lang => $hal_v04_element_id ) {
					$hal_v04_out[ $hal_v04_lang ] = (object) [ 'element_id' => $hal_v04_element_id ];
				}

				return $hal_v04_out;
			}
		}
	}

	$GLOBALS['sitepress'] = new SitePress();

	// WPML's configured-languages source: the documented active-languages
	// filter the branch reads — keyed BY language code, as WPML returns it.
	$hal_test_state['wpml_languages'] = [
		'en_US' => [ 'code' => 'en_US', 'display_name' => 'English' ],
		'fr_FR' => [ 'code' => 'fr_FR', 'display_name' => 'Français' ],
	];

	add_filter(
		'wpml_active_languages',
		static fn( $hal_test_langs ) => hal_v04_state()['wpml_languages'],
		10
	);

	// The official group interface (S21): the filter callback plays WPML's
	// documented role of joining/creating groups.
	add_filter(
		'wpml_set_element_language_details',
		static function ( $hal_v04_nothing, array $hal_v04_args ) {
			$hal_v04_state = &hal_v04_state();

			if ( null === $hal_v04_args['trid'] ) {
				$hal_v04_trid = count( $hal_v04_state['wpml_groups'] ) + 1;
				$hal_v04_state['wpml_groups'][ $hal_v04_trid ] = [
					'type'     => (string) $hal_v04_args['element_type'],
					'elements' => [ (string) $hal_v04_args['language_code'] => (int) $hal_v04_args['element_id'] ],
				];

				return $hal_v04_trid;
			}

			if ( isset( $hal_v04_state['wpml_groups'][ (int) $hal_v04_args['trid'] ] ) ) {
				$hal_v04_state['wpml_groups'][ (int) $hal_v04_args['trid'] ]['elements'][ (string) $hal_v04_args['language_code'] ] = (int) $hal_v04_args['element_id'];
			}

			return (int) $hal_v04_args['trid'];
		},
		10
	);

	hal_test_assert( 'wpml' === hal_mcp_translations_active_system(), 'the WPML branch must activate through its documented markers' );

	$hal_test_w1 = hal_v04_seed_post( [ 'post_type' => 'post', 'post_status' => 'draft' ] );
	$hal_test_w2 = hal_v04_seed_post( [ 'post_type' => 'post', 'post_status' => 'draft' ] );

	hal_test_assert( ! is_wp_error( hal_mcp_translations_assign_language( $hal_test_w1, 'post', 'en_US' ) ), 'the WPML assign must run through the official interface' );

	$hal_test_wlink = hal_mcp_translations_link_translation( $hal_test_w1, $hal_test_w2, 'post', 'fr_FR' );
	hal_test_assert( ! is_wp_error( $hal_test_wlink ) && 2 === count( $hal_test_wlink['links'] ), 'the WPML link must join the source group preserving its links' );

	$hal_test_wgroup = hal_mcp_translations_read_links( $hal_test_w1, 'post' );
	hal_test_assert( 'en_US' === $hal_test_wgroup['element_language'] && ( $hal_test_wgroup['links']['fr_FR'] ?? 0 ) === $hal_test_w2, 'the WPML group read must report the element\'s language and links' );
}