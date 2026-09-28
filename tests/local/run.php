<?php
/**
 * Local test runner for hal-mcp-integration-abilities (V01).
 *
 * Pure in-memory WordPress stubs — no site, no database, no network. Each case
 * runs in its own PHP subprocess because plugin constants persist for the life
 * of a process and the cases intentionally load the plugin under different
 * preconditions (fresh load, duplicate MU copy, missing Abilities API, ...).
 *
 * Usage:
 *   php tests/local/run.php              # run every case
 *   php tests/local/run.php --case=NAME  # run one case (used internally)
 *
 * Exit code 0 = all requested cases passed.
 *
 * @package hal-mcp-abilities
 */

if ( PHP_SAPI !== 'cli' ) {
	exit( "tests/local/run.php must run from the CLI only.\n" );
}

const HAL_TEST_CASE_OK = 'HAL_TEST_CASE_OK';

// ---------------------------------------------------------------------------
// Case list and orchestration
// ---------------------------------------------------------------------------

$hal_test_cases = [
	'load_fresh',
	'load_duplicate_mu_copy',
	'load_without_abilities_api',
	'bootstrap_double_require',
	'bootstrap_guard_blocks_second_load',
	'bootstrap_direct_without_entry',
	'entry_missing_bootstrap',
	'uninstall_keeps_data_by_default',
	'uninstall_deletes_after_opt_in',
	'categories_have_no_site_names',
	'updater_noop_without_plugin_file',
	'updater_missing_library_is_contained',
	'updater_initializes_with_release_assets',
	'updater_failure_is_contained_and_sanitized',
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

	require_once __DIR__ . '/stubs.php';
	hal_test_reset_stubs();

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
 * Fresh standard-plugin load with the Abilities API present: constants, the
 * internal bootstrap guard, include modules, and the activation hook all land.
 */
function hal_test_case_load_fresh(): void {
	hal_test_define_runtime_constants();
	hal_test_install_ability_stubs();

	require hal_test_plugin_dir() . '/hal-mcp-integration-abilities.php';

	hal_test_assert( defined( 'HAL_MCP_ABILITIES_VERSION' ) && '2.0.0' === HAL_MCP_ABILITIES_VERSION, 'version constant must be 2.0.0' );
	hal_test_assert( defined( 'HAL_MCP_ABILITIES_DIR' ) && str_ends_with( HAL_MCP_ABILITIES_DIR, 'hal-mcp-abilities' ), 'DIR constant must point at the internal folder' );
	hal_test_assert( defined( 'HAL_MCP_ABILITIES_PLUGIN_FILE' ) && str_ends_with( HAL_MCP_ABILITIES_PLUGIN_FILE, 'hal-mcp-integration-abilities.php' ), 'plugin file constant must be the root entry' );
	hal_test_assert( defined( 'HAL_MCP_ABILITIES_BOOTSTRAP_LOADED' ), 'internal bootstrap must mark itself loaded' );
	hal_test_assert( function_exists( 'hal_mcp_check_capability' ), 'permissions.php must load' );
	hal_test_assert( function_exists( 'hal_mcp_maybe_create_audit_log_table' ), 'audit-log.php must load' );
	hal_test_assert( function_exists( 'hal_mcp_register_ability_categories' ), 'categories.php must load' );
	hal_test_assert( function_exists( 'hal_mcp_register_integration' ), 'integrations.php must load' );
	hal_test_assert( function_exists( 'hal_mcp_environment_inventory' ), 'environment.php must load' );

	// plugins_loaded carries the audit storage init (10) plus the batch-3
	// discovery hooks: the integration-file loader (5) and the core
	// integration registrations (6) — and the batch-7 updater init (20).
	hal_test_assert(
		4 === count( hal_test_hooks( 'plugins_loaded' ) ),
		'plugins_loaded must carry the audit init, the two integration hooks, and the updater init'
	);

	$activation = hal_test_hooks( 'activation' );
	hal_test_assert( 1 === count( $activation ), 'exactly one activation hook must be registered' );
	hal_test_assert( is_string( $activation[0][1] ) && function_exists( $activation[0][1] ), 'activation callback must be a defined function' );

	// Plugin header sanity: the entry file itself carries the required fields.
	$hal_test_header = (string) file_get_contents( hal_test_plugin_dir() . '/hal-mcp-integration-abilities.php' );
	foreach ( [ 'Plugin Name:       HAL MCP Integration Abilities', 'Version:           2.0.0', 'Text Domain:       hal-mcp', 'Requires at least: 6.9', 'Requires PHP:      8.0', 'Update URI:        https://github.com/hossamadellaw/hal-mcp-integration-abilities' ] as $hal_test_field ) {
		hal_test_assert( str_contains( $hal_test_header, $hal_test_field ), "plugin header must contain: {$hal_test_field}" );
	}
}

/**
 * The legacy mu-plugin copy is still active (it defines the version constant
 * first, because mu-plugins load before regular plugins): the standard entry
 * must bail with an admin notice and load nothing.
 */
function hal_test_case_load_duplicate_mu_copy(): void {
	// Simulate the old MU bootstrap having already run.
	define( 'HAL_MCP_ABILITIES_VERSION', '1.0.0' );

	// Give the notice a legacy loader file to point at.
	$hal_test_mu_dir          = sys_get_temp_dir() . '/hal-test-mu-' . getmypid();
	$hal_test_mu_loader       = $hal_test_mu_dir . '/hal-mcp-abilities-loader.php';
	$hal_test_fake_wpmu_dir   = $hal_test_mu_dir;
	hal_test_define_runtime_constants( $hal_test_fake_wpmu_dir );
	@mkdir( $hal_test_mu_dir );
	file_put_contents( $hal_test_mu_loader, "<?php\n" );

	require hal_test_plugin_dir() . '/hal-mcp-integration-abilities.php';

	hal_test_assert( ! defined( 'HAL_MCP_ABILITIES_DIR' ), 'duplicate load must not define the DIR constant' );
	hal_test_assert( ! defined( 'HAL_MCP_ABILITIES_PLUGIN_FILE' ), 'duplicate load must not define the plugin file constant' );
	hal_test_assert( ! defined( 'HAL_MCP_ABILITIES_BOOTSTRAP_LOADED' ), 'duplicate load must not run the bootstrap' );

	$hal_test_notices = hal_test_hooks( 'admin_notices' );
	hal_test_assert( 1 === count( $hal_test_notices ), 'duplicate load must register exactly one admin notice' );

	// Render the notice: it must name the legacy mu-plugin path.
	$hal_test_notices[0][1]();
	hal_test_assert( str_contains( hal_test_output(), 'mu-plugins/hal-mcp-abilities-loader.php' ), 'duplicate-load notice must name the legacy loader path' );

	@unlink( $hal_test_mu_loader );
	@rmdir( $hal_test_mu_dir );
}

/**
 * Without the Abilities API the ability files must stay unloaded, the shared
 * includes must still load, and a clear admin notice must be registered.
 */
function hal_test_case_load_without_abilities_api(): void {
	hal_test_define_runtime_constants();
	// Deliberately no hal_test_install_ability_stubs() here: the Abilities API
	// must be absent for this case.

	require hal_test_plugin_dir() . '/hal-mcp-integration-abilities.php';

	hal_test_assert( function_exists( 'hal_mcp_check_capability' ), 'permissions.php must load without the Abilities API' );
	hal_test_assert( function_exists( 'hal_mcp_maybe_create_audit_log_table' ), 'audit-log.php must load without the Abilities API' );
	hal_test_assert( function_exists( 'hal_mcp_register_integration' ), 'integrations.php must load without the Abilities API' );
	hal_test_assert( function_exists( 'hal_mcp_environment_inventory' ), 'environment.php must load without the Abilities API' );
	hal_test_assert( ! function_exists( 'hal_mcp_register_write_post_abilities' ), 'ability files must not load without the Abilities API' );

	$hal_test_notices = hal_test_hooks( 'admin_notices' );
	hal_test_assert( 1 === count( $hal_test_notices ), 'missing-API state must register exactly one admin notice' );

	$hal_test_notices[0][1]();
	hal_test_assert( str_contains( hal_test_output(), 'Abilities API' ), 'missing-API notice must explain the Abilities API' );
}

/**
 * Requiring the internal bootstrap twice must stay harmless: the second require
 * returns early and nothing double-registers. The hook counts prove the second
 * require registered nothing, not merely that no fatal occurred.
 */
function hal_test_case_bootstrap_double_require(): void {
	hal_test_define_runtime_constants();
	hal_test_install_ability_stubs();
	// Simulate the root entry having defined its constants before requiring the
	// internal bootstrap directly.
	if ( ! defined( 'HAL_MCP_ABILITIES_VERSION' ) ) {
		define( 'HAL_MCP_ABILITIES_VERSION', '2.0.0' );
	}
	if ( ! defined( 'HAL_MCP_ABILITIES_DIR' ) ) {
		define( 'HAL_MCP_ABILITIES_DIR', hal_test_plugin_dir() . '/hal-mcp-abilities' );
	}

	require hal_test_plugin_dir() . '/hal-mcp-abilities/hal-mcp-abilities.php';
	hal_test_assert( defined( 'HAL_MCP_ABILITIES_BOOTSTRAP_LOADED' ), 'first bootstrap require must set the marker' );

	$hal_test_hooks_first = hal_test_count_load_hooks();

	require hal_test_plugin_dir() . '/hal-mcp-abilities/hal-mcp-abilities.php';

	hal_test_assert(
		$hal_test_hooks_first === hal_test_count_load_hooks(),
		'second bootstrap require must not register any hook twice'
	);
}

/**
 * Direct test of the internal load marker: a process where the marker is ALREADY
 * defined (bootstrap "ran" earlier) must not load a single include. Removing the
 * guard from the bootstrap makes this case fail, because the includes would load.
 */
function hal_test_case_bootstrap_guard_blocks_second_load(): void {
	hal_test_define_runtime_constants();
	hal_test_install_ability_stubs();
	define( 'HAL_MCP_ABILITIES_VERSION', '2.0.0' );
	define( 'HAL_MCP_ABILITIES_DIR', hal_test_plugin_dir() . '/hal-mcp-abilities' );
	define( 'HAL_MCP_ABILITIES_BOOTSTRAP_LOADED', true );

	require hal_test_plugin_dir() . '/hal-mcp-abilities/hal-mcp-abilities.php';

	hal_test_assert( ! function_exists( 'hal_mcp_check_capability' ), 'the internal guard must stop an already-loaded bootstrap from requiring includes' );
	hal_test_assert( 0 === hal_test_count_load_hooks(), 'no load-time hooks may register when the guard trips' );
}

/**
 * Requiring the internal bootstrap without the root entry's constants must bail
 * with a clear notice, and must NOT set the load marker (so a later legitimate
 * load in the same process stays possible).
 */
function hal_test_case_bootstrap_direct_without_entry(): void {
	// The WordPress runtime is present (ABSPATH defined) but the plugin entry's
	// constants are not — that is the broken deploy this case simulates.
	hal_test_define_runtime_constants();

	require hal_test_plugin_dir() . '/hal-mcp-abilities/hal-mcp-abilities.php';

	hal_test_assert( ! defined( 'HAL_MCP_ABILITIES_BOOTSTRAP_LOADED' ), 'a broken-deploy require must not set the load marker' );
	$hal_test_notices = hal_test_hooks( 'admin_notices' );
	hal_test_assert( 1 === count( $hal_test_notices ), 'broken-deploy require must register exactly one admin notice' );

	$hal_test_notices[0][1]();
	hal_test_assert( str_contains( hal_test_output(), 'plugin entry file' ), 'broken-deploy notice must name the missing entry file' );
}

/**
 * An incomplete deploy (entry present, internal folder missing) must fail
 * loudly with one admin notice and load nothing — not fatal the whole site.
 * Simulated by copying the entry file to a temp directory without the
 * hal-mcp-abilities/ folder next to it.
 */
function hal_test_case_entry_missing_bootstrap(): void {
	hal_test_define_runtime_constants();
	hal_test_install_ability_stubs();

	$hal_test_deploy = sys_get_temp_dir() . '/hal-test-deploy-' . getmypid();
	@mkdir( $hal_test_deploy );
	copy( hal_test_plugin_dir() . '/hal-mcp-integration-abilities.php', $hal_test_deploy . '/hal-mcp-integration-abilities.php' );

	require $hal_test_deploy . '/hal-mcp-integration-abilities.php';

	hal_test_assert( ! function_exists( 'hal_mcp_check_capability' ), 'incomplete deploy must not attempt to load includes' );
	$hal_test_notices = hal_test_hooks( 'admin_notices' );
	hal_test_assert( 1 === count( $hal_test_notices ), 'incomplete deploy must register exactly one admin notice' );

	$hal_test_notices[0][1]();
	$hal_test_notice_text = hal_test_output();
	hal_test_assert( str_contains( $hal_test_notice_text, 'hal-mcp-abilities.php' ), 'incomplete-deploy notice must name the missing bootstrap file' );
	hal_test_assert( ! str_contains( $hal_test_notice_text, $hal_test_deploy ), 'incomplete-deploy notice must not expose the absolute deployment path' );

	@unlink( $hal_test_deploy . '/hal-mcp-integration-abilities.php' );
	@rmdir( $hal_test_deploy );
}

/**
 * Default uninstall policy: without a prior explicit admin opt-in, nothing is
 * deleted — no options, no audit table, no requests.
 */
function hal_test_case_uninstall_keeps_data_by_default(): void {
	hal_test_define_runtime_constants();
	hal_test_stubs()['options'] = []; // get_option('hal_mcp_settings') -> false

	require hal_test_plugin_dir() . '/uninstall.php';

	hal_test_assert( [] === hal_test_stubs()['deleted_options'], 'no option may be deleted without opt-in' );
	hal_test_assert( [] === hal_test_stubs()['wpdb']->queries && [] === hal_test_stubs()['wpdb']->selected, 'no SQL may run without opt-in' );
	hal_test_assert( [] === hal_test_stubs()['deleted_posts'], 'no post may be deleted without opt-in' );
}

/**
 * Opted-in uninstall: the plugin's own options, its audit table, and its
 * internal request CPT posts are deleted — and nothing else.
 */
function hal_test_case_uninstall_deletes_after_opt_in(): void {
	hal_test_define_runtime_constants();
	hal_test_stubs()['options'] = [ 'hal_mcp_settings' => [ 'delete_data_on_uninstall' => true ] ];
	hal_test_stubs()['wpdb']->columns = [ '101', '102' ];

	require hal_test_plugin_dir() . '/uninstall.php';

	hal_test_assert(
		[ 'hal_mcp_settings', 'hal_mcp_secrets', 'hal_mcp_connection_tests', 'hal_mcp_audit_log_db_version', 'hal_mcp_environment_inventory' ] === hal_test_stubs()['deleted_options'],
		'exactly the plugin-owned options must be deleted, in list order (settings, encrypted secrets, connection tests, audit schema, environment inventory)'
	);

	$hal_test_wpdb = hal_test_stubs()['wpdb'];

	hal_test_assert( 1 === count( $hal_test_wpdb->queries ), 'exactly one SQL statement (the table drop) must run' );
	hal_test_assert( str_contains( $hal_test_wpdb->queries[0], 'DROP TABLE IF EXISTS wp_hal_mcp_audit_log' ), 'the audit log table must be dropped on the current site' );

	hal_test_assert( [ 101, 102 ] === hal_test_stubs()['deleted_posts'], 'the internal request CPT posts must be deleted' );
	hal_test_assert( 1 === count( $hal_test_wpdb->selected ) && str_contains( $hal_test_wpdb->selected[0], "post_type = 'hal_mcp_request'" ), 'only the internal request CPT may be selected for deletion' );
}

/**
 * Category slugs are frozen API surface and must survive unchanged, and no
 * description may name a specific site.
 */
function hal_test_case_categories_have_no_site_names(): void {
	hal_test_define_runtime_constants();
	hal_test_install_ability_stubs();

	require hal_test_plugin_dir() . '/hal-mcp-abilities/includes/categories.php';

	hal_mcp_register_ability_categories();

	$hal_test_registered = hal_test_stubs()['registered_categories'];
	hal_test_assert(
		[ 'hal-content', 'hal-pages', 'hal-products', 'hal-media', 'hal-system' ] === array_keys( $hal_test_registered ),
		'category slugs must stay exactly hal-content, hal-pages, hal-products, hal-media, hal-system (hal-pages added with the F12/F20 page abilities)'
	);

		foreach ( $hal_test_registered as $hal_test_slug => $hal_test_args ) {
		hal_test_assert( '' !== $hal_test_args['label'] && '' !== $hal_test_args['description'], "{$hal_test_slug} must keep a label and a description" );
		hal_test_assert( ! str_contains( strtolower( $hal_test_args['description'] ), 'hossamadellaw' ), "{$hal_test_slug} description must not name a site" );
	}
}

/**
 * G03: without HAL_MCP_ABILITIES_PLUGIN_FILE (the legacy mu-plugin copy or a
 * broken direct require), the updater must register nothing beyond its own
 * init hook and must stay completely inert when that hook fires.
 */
function hal_test_case_updater_noop_without_plugin_file(): void {
	hal_test_define_runtime_constants();

	require hal_test_plugin_dir() . '/hal-mcp-abilities/includes/updater.php';

	hal_test_assert( function_exists( 'hal_mcp_updater_init' ), 'updater.php must load' );
	hal_test_assert( 1 === count( hal_test_hooks( 'plugins_loaded' ) ), 'updater.php must register exactly its init hook' );

	hal_mcp_updater_init();

	hal_test_assert( [] === hal_test_hooks( 'admin_notices' ), 'no updater notice may be registered without the plugin file constant' );
}

/**
 * G03: a deploy whose plugin folder has no vendor/ (is_readable fails) must
 * skip the updater quietly — no notice, no crash, nothing attempted.
 */
function hal_test_case_updater_missing_library_is_contained(): void {
	hal_test_define_runtime_constants();

	// A deploy directory containing only the entry file — no vendor/.
	$hal_test_deploy = sys_get_temp_dir() . '/hal-test-novendor-' . getmypid();
	@mkdir( $hal_test_deploy );
	copy( hal_test_plugin_dir() . '/hal-mcp-integration-abilities.php', $hal_test_deploy . '/hal-mcp-integration-abilities.php' );

	require hal_test_plugin_dir() . '/hal-mcp-abilities/includes/updater.php';

	if ( ! defined( 'HAL_MCP_ABILITIES_PLUGIN_FILE' ) ) {
		define( 'HAL_MCP_ABILITIES_PLUGIN_FILE', $hal_test_deploy . '/hal-mcp-integration-abilities.php' );
	}

	hal_mcp_updater_init();

	hal_test_assert( [] === hal_test_hooks( 'admin_notices' ), 'missing vendor/ is an environment state, not an admin-visible defect' );
	hal_test_assert( ! class_exists( 'YahnisElsts\\PluginUpdateChecker\\v5p7\\PucFactory' ), 'no PUC class may load when the library is missing' );

	@unlink( $hal_test_deploy . '/hal-mcp-integration-abilities.php' );
	@rmdir( $hal_test_deploy );
}

/**
 * G03: with a readable library defining the v5p7 namespace, init must build
 * the checker with this plugin's identity, must NOT authenticate (public
 * repository path), and must enforce release assets with the exact regex and
 * REQUIRE_RELEASE_ASSETS — the fail-closed contract against source-archive
 * fallback.
 */
function hal_test_case_updater_initializes_with_release_assets(): void {
	hal_test_define_runtime_constants();

	$hal_test_deploy = sys_get_temp_dir() . '/hal-test-updater-' . getmypid();
	hal_test_write_fake_puc_library( $hal_test_deploy, false );

	require hal_test_plugin_dir() . '/hal-mcp-abilities/includes/updater.php';

	if ( ! defined( 'HAL_MCP_ABILITIES_PLUGIN_FILE' ) ) {
		define( 'HAL_MCP_ABILITIES_PLUGIN_FILE', $hal_test_deploy . '/hal-mcp-integration-abilities.php' );
	}

	hal_mcp_updater_init();

	hal_test_assert( [] === hal_test_hooks( 'admin_notices' ), 'a successful updater init must not raise any notice' );
	hal_test_assert( [] !== Hal_Test_Fake_Updater_State::$built, 'buildUpdateChecker must be called' );
	hal_test_assert(
		'https://github.com/hossamadellaw/hal-mcp-integration-abilities' === Hal_Test_Fake_Updater_State::$built[0],
		'the checker must be pointed at this plugin repository'
	);
	hal_test_assert( $hal_test_deploy . '/hal-mcp-integration-abilities.php' === Hal_Test_Fake_Updater_State::$built[1], 'the checker must read the plugin entry file' );
	hal_test_assert( 'hal-mcp-integration-abilities' === Hal_Test_Fake_Updater_State::$built[2], 'the checker slug must be the plugin slug' );
	hal_test_assert( null === Hal_Test_Fake_Updater_State::$auth, 'no authentication may be set on the public-repository path' );
	hal_test_assert(
		[ '/^hal-mcp-integration-abilities-[0-9]+\.[0-9]+\.[0-9]+\.zip$/i', 2 ] === Hal_Test_Fake_Updater_State::$assets,
		'release assets must be enforced with the exact ASSET_REGEX and REQUIRE_RELEASE_ASSETS (2)'
	);

	hal_test_rrmdir( $hal_test_deploy );
}

/**
 * G03: a Throwable during updater initialization must be contained: exactly
 * one admin notice with the sanitized general message, no exception text, no
 * secret, and no crash.
 */
function hal_test_case_updater_failure_is_contained_and_sanitized(): void {
	hal_test_define_runtime_constants();

	$hal_test_deploy = sys_get_temp_dir() . '/hal-test-updater-throw-' . getmypid();
	hal_test_write_fake_puc_library( $hal_test_deploy, true );

	require hal_test_plugin_dir() . '/hal-mcp-abilities/includes/updater.php';

	if ( ! defined( 'HAL_MCP_ABILITIES_PLUGIN_FILE' ) ) {
		define( 'HAL_MCP_ABILITIES_PLUGIN_FILE', $hal_test_deploy . '/hal-mcp-integration-abilities.php' );
	}

	hal_mcp_updater_init();

	$hal_test_notices = hal_test_hooks( 'admin_notices' );
	hal_test_assert( 1 === count( $hal_test_notices ), 'a contained failure must register exactly one admin notice' );

	$hal_test_notices[0][1]();
	$hal_test_output = hal_test_output();
	hal_test_assert( str_contains( $hal_test_output, 'update checker' ), 'the notice must explain the update checker is affected' );
	hal_test_assert( str_contains( $hal_test_output, 'unaffected' ), 'the notice must state plugin features are unaffected' );
	hal_test_assert( ! str_contains( $hal_test_output, 'HAL-TEST-SECRET' ), 'the notice must not leak exception internals' );

	hal_test_rrmdir( $hal_test_deploy );
}

/**
 * Writes a fake PUC library (v5p7 namespace) into a deploy's vendor/ so the
 * updater can be exercised without composer or network.
 *
 * @param string $hal_test_deploy Target deploy directory.
 * @param bool   $hal_test_throw  When true, buildUpdateChecker throws with a
 *                                planted secret to prove sanitization.
 */
function hal_test_write_fake_puc_library( string $hal_test_deploy, bool $hal_test_throw ): void {
	$hal_test_dir = $hal_test_deploy . '/vendor/yahnis-elsts/plugin-update-checker';
	@mkdir( $hal_test_dir, 0775, true );

	$hal_test_throw_body = $hal_test_throw
		? "throw new \\RuntimeException( 'HAL-TEST-SECRET updater exploded' );"
		: '\\Hal_Test_Fake_Updater_State::$built = func_get_args(); return new \\Hal_Test_Fake_UpdateChecker();';

	file_put_contents(
		$hal_test_dir . '/plugin-update-checker.php',
		"<?php
namespace YahnisElsts\\PluginUpdateChecker\\v5p7 {
\tfinal class PucFactory {
\t\tpublic static function buildUpdateChecker( ...\$args ) {
\t\t\t{$hal_test_throw_body}
\t\t}
\t}
}
namespace YahnisElsts\\PluginUpdateChecker\\v5p7\\Vcs {
\tfinal class Api {
\t\tconst REQUIRE_RELEASE_ASSETS = 2;
\t}
}
namespace {
\tfinal class Hal_Test_Fake_UpdateChecker {
\t\tpublic function setAuthentication( \$hal_test_token ): void {
\t\t\t\\Hal_Test_Fake_Updater_State::\$auth = \$hal_test_token;
\t\t}
\t\tpublic function getVcsApi(): Hal_Test_Fake_VcsApi {
\t\t\treturn new Hal_Test_Fake_VcsApi();
\t\t}
\t}
\tfinal class Hal_Test_Fake_VcsApi {
\t\tpublic function enableReleaseAssets( \$hal_test_regex, \$hal_test_mode ): void {
\t\t\t\\Hal_Test_Fake_Updater_State::\$assets = [ \$hal_test_regex, \$hal_test_mode ];
\t\t}
\t}
\tfinal class Hal_Test_Fake_Updater_State {
\t\tpublic static \$built = [];
\t\tpublic static \$assets = [];
\t\tpublic static \$auth = null;
\t}
}
"
	);

	copy( hal_test_plugin_dir() . '/hal-mcp-integration-abilities.php', $hal_test_deploy . '/hal-mcp-integration-abilities.php' );
}

/**
 * Recursively removes a case's temp deploy directory.
 */
function hal_test_rrmdir( string $hal_test_dir ): void {
	if ( ! is_dir( $hal_test_dir ) ) {
		return;
	}
	foreach ( scandir( $hal_test_dir ) ?: [] as $hal_test_entry ) {
		if ( '.' === $hal_test_entry || '..' === $hal_test_entry ) {
			continue;
		}
		$hal_test_path = $hal_test_dir . '/' . $hal_test_entry;
		if ( is_dir( $hal_test_path ) ) {
			hal_test_rrmdir( $hal_test_path );
			continue;
		}
		@unlink( $hal_test_path );
	}
	@rmdir( $hal_test_dir );
}
