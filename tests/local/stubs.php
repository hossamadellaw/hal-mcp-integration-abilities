<?php
/**
 * In-memory WordPress stubs for tests/local/run.php (V01).
 *
 * Only the interfaces the batch's code paths actually touch are stubbed, and
 * every stub records its calls so the cases can assert on behavior, not on
 * implementation. No site, no database, no network.
 *
 * @package hal-mcp-abilities
 */

/**
 * Shared mutable stub state: recorded hooks, options, deletions, and the fake
 * $wpdb. Everything a case asserts on lives in here.
 *
 * @return array<string, mixed>
 */
function &hal_test_stubs(): array {
	static $hal_test_state = [
		'hooks'                 => [],
		'output'                => '',
		'options'               => [],
		'deleted_options'       => [],
		'deleted_posts'         => [],
		'registered_categories' => [],
		'wpdb'                  => null,
	];

	return $hal_test_state;
}

/**
 * Resets the stub environment. Called once per case, before the case body runs.
 */
function hal_test_reset_stubs(): void {
	$hal_test_state             = &hal_test_stubs();
	$hal_test_state['hooks']    = [];
	$hal_test_state['options']  = [];
	$hal_test_state['deleted_options'] = [];
	$hal_test_state['deleted_posts']   = [];
	$hal_test_state['registered_categories'] = [];
	$hal_test_state['wpdb']     = new Hal_Test_Wpdb();
	// uninstall.php reads the real global $wpdb; point it at the recorded one.
	$GLOBALS['wpdb']            = $hal_test_state['wpdb'];
	$hal_test_state['output']   = '';

	ob_start();
}

/**
 * Defines the WordPress runtime constants the plugin entry and uninstall
 * handler check.
 *
 * @param string|null $hal_test_wpmu_dir Optional WPMU_PLUGIN_DIR override.
 */
function hal_test_define_runtime_constants( ?string $hal_test_wpmu_dir = null ): void {
	if ( ! defined( 'ABSPATH' ) ) {
		define( 'ABSPATH', hal_test_plugin_dir() . '/' );
	}
	if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
		define( 'WP_UNINSTALL_PLUGIN', 'hal-mcp-integration-abilities/hal-mcp-integration-abilities.php' );
	}
	if ( ! defined( 'WPMU_PLUGIN_DIR' ) ) {
		define( 'WPMU_PLUGIN_DIR', $hal_test_wpmu_dir ?? sys_get_temp_dir() . '/hal-test-mu-none-' . getmypid() );
	}
}

/**
 * Minimal WP_Error probe. categories.php (batch 8) treats WP_Error as a
 * registration failure alongside null; this keeps that contract testable in
 * the harness even though the ability stubs below never return one.
 *
 * @param mixed $thing Value to test.
 * @return bool True when $thing is a WP_Error instance.
 */
if ( ! function_exists( 'is_wp_error' ) ) {
	function is_wp_error( $thing ): bool {
		return $thing instanceof WP_Error;
	}
}

/**
 * Installs the Abilities API stubs. Cases that simulate a site WITHOUT the
 * Abilities API simply never call this, so function_exists() stays false for
 * the plugin's environment check.
 */
function hal_test_install_ability_stubs(): void {
	if ( ! function_exists( 'wp_register_ability' ) ) {
		/**
		 * Records nothing: ability registration never runs at load time in these
		 * cases, because registration happens inside hooked callbacks.
		 *
		 * @param string $ability_name Ability name.
		 * @param array<string, mixed> $args Ability args.
		 * @return bool Non-null so the plugin's failure check never trips.
		 */
		function wp_register_ability( string $ability_name, array $args = [] ): bool {
			return true;
		}
	}

	if ( ! function_exists( 'wp_register_ability_category' ) ) {
		/**
		 * Records the category so cases can assert on slugs and descriptions.
		 *
		 * @param string $slug Category slug.
		 * @param array<string, mixed> $args Category args.
		 * @return bool Non-null so the plugin's failure check never trips.
		 */
		function wp_register_ability_category( string $slug, array $args = [] ): bool {
			hal_test_stubs()['registered_categories'][ $slug ] = $args;
			return true;
		}
	}
}

/**
 * Returns the callbacks recorded for a hook, in registration order. Each entry
 * is [ $file_or_priority, $callback ].
 *
 * @param string $hook Hook name.
 * @return array<int, array<int, mixed>>
 */
function hal_test_hooks( string $hook ): array {
	return hal_test_stubs()['hooks'][ $hook ] ?? [];
}

/**
 * Total number of load-time hooks the plugin may register: the Abilities API
 * registration hooks, the audit log's storage hooks, and the activation hook.
 * Used to prove a re-require registered nothing twice.
 *
 * @return int
 */
function hal_test_count_load_hooks(): int {
	$hal_test_hooks = hal_test_stubs()['hooks'];

	$hal_test_total = 0;
	foreach ( [ 'wp_abilities_api_init', 'wp_abilities_api_categories_init', 'plugins_loaded', 'wp_before_execute_ability', 'wp_after_execute_ability', 'activation', 'admin_notices' ] as $hal_test_hook ) {
		$hal_test_total += count( $hal_test_hooks[ $hal_test_hook ] ?? [] );
	}

	return $hal_test_total;
}

/**
 * Returns and stops buffering the output the case has captured so far.
 */
function hal_test_output(): string {
	$hal_test_out = (string) ob_get_clean();
	hal_test_stubs()['output'] = $hal_test_out;
	ob_start();

	return $hal_test_out;
}

/**
 * Absolute path to the plugin root, from this file's location.
 */
function hal_test_plugin_dir(): string {
	return dirname( __DIR__, 2 );
}

/**
 * Assertion helper: prints the message and exits non-zero on failure.
 *
 * @param bool   $condition Condition to assert.
 * @param string $message   Failure description.
 */
function hal_test_assert( bool $condition, string $message ): void {
	if ( ! $condition ) {
		fwrite( STDERR, "ASSERTION FAILED: {$message}\n" );
		exit( 1 );
	}
}

// WordPress function stubs. Defined unconditionally: every case needs at least
// add_action, and none of these overlap the deliberately-absent Abilities API.

if ( ! function_exists( 'add_action' ) ) {
	/**
	 * Records the callback for assertions.
	 *
	 * @param string   $hook_name Hook name.
	 * @param callable $callback  Callback.
	 * @param int      $priority  Priority.
	 * @param int      $accepted_args Accepted args.
	 */
	function add_action( string $hook_name, $callback, int $priority = 10, int $accepted_args = 1 ): void {
		hal_test_stubs()['hooks'][ $hook_name ][] = [ $priority, $callback ];
	}
}

if ( ! function_exists( 'plugin_dir_path' ) ) {
	/**
	 * Resolves the directory of a plugin file, like WordPress. Used by the
	 * updater (G03) to locate vendor/; a plain dirname keeps the cases honest
	 * about real filesystem layout.
	 *
	 * @param string $file Absolute plugin file path.
	 * @return string Trailing-slash directory.
	 */
	function plugin_dir_path( string $file ): string {
		return rtrim( dirname( $file ), '/\\' ) . '/';
	}
}

if ( ! function_exists( 'register_activation_hook' ) ) {
	/**
	 * Records the activation callback under the synthetic 'activation' hook.
	 *
	 * @param string   $file     Plugin file.
	 * @param callable $callback Callback.
	 */
	function register_activation_hook( string $file, $callback ): void {
		hal_test_stubs()['hooks']['activation'][] = [ $file, $callback ];
	}
}

if ( ! function_exists( 'current_user_can' ) ) {
	/**
	 * Admin notices in the plugin guard on activate_plugins; tests run as an
	 * administrator.
	 *
	 * @param string $capability Capability.
	 * @return bool
	 */
	function current_user_can( string $capability ): bool {
		return true;
	}
}

if ( ! function_exists( '__' ) ) {
	/** Translation stub: identity. */
	function __( string $text, string $domain = 'default' ): string {
		return $text;
	}
}

if ( ! function_exists( 'esc_html__' ) ) {
	/** Translation+escape stub: identity. */
	function esc_html__( string $text, string $domain = 'default' ): string {
		return $text;
	}
}

if ( ! function_exists( 'esc_html' ) ) {
	/** Escape stub: identity. */
	function esc_html( string $text ): string {
		return $text;
	}
}

if ( ! function_exists( 'get_option' ) ) {
	/**
	 * Reads the stub options store; missing options return false like WordPress.
	 *
	 * @param string $option  Option name.
	 * @param mixed  $default Default.
	 * @return mixed
	 */
	function get_option( string $option, $default = false ) {
		$hal_test_options = hal_test_stubs()['options'];

		return array_key_exists( $option, $hal_test_options ) ? $hal_test_options[ $option ] : $default;
	}
}

if ( ! function_exists( 'delete_option' ) ) {
	/** Records the deletion for assertions. */
	function delete_option( string $option ): void {
		hal_test_stubs()['deleted_options'][] = $option;
	}
}

if ( ! function_exists( 'wp_delete_post' ) ) {
	/** Records the deletion for assertions. */
	function wp_delete_post( int $post_id, bool $force_delete = false ) {
		hal_test_stubs()['deleted_posts'][] = $post_id;

		return null;
	}
}

/**
 * Minimal $wpdb stand-in: enough surface for uninstall.php — the table prefix,
 * the posts table name, prepare(), query(), and get_col() — with every SQL
 * statement recorded for assertions.
 */
final class Hal_Test_Wpdb {

	/** @var string */
	public $prefix = 'wp_';

	/** @var string */
	public $posts = 'wp_posts';

	/** @var string[] SQL statements passed to query(). */
	public $queries = [];

	/** @var string[] SQL statements passed to get_col(). */
	public $selected = [];

	/** @var array<int, mixed> Row values returned by get_col(). */
	public $columns = [];

	/**
	 * Records and "executes" a query.
	 *
	 * @param string $sql SQL statement.
	 * @return bool
	 */
	public function query( string $sql ): bool {
		$this->queries[] = $sql;

		return true;
	}

	/**
	 * Naive prepare(): substitutes %s / %d / %i placeholders in order.
	 *
	 * @param string $sql  SQL with placeholders.
	 * @param mixed  ...$args Values.
	 * @return string
	 */
	public function prepare( string $sql, ...$args ): string {
		foreach ( $args as $arg ) {
			$sql = (string) preg_replace(
				'/%[sdfi]/',
				is_int( $arg ) ? (string) $arg : "'" . addslashes( (string) $arg ) . "'",
				$sql,
				1
			);
		}

		return $sql;
	}

	/**
	 * Records the statement and returns the configured column values.
	 *
	 * @param string $sql SQL statement.
	 * @return string[]
	 */
	public function get_col( ?string $sql = null ): array {
		$this->selected[] = (string) $sql;

		return $this->columns;
	}
}

$GLOBALS['wpdb'] = new Hal_Test_Wpdb();
