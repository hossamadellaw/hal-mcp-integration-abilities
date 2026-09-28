<?php
/**
 * In-memory WordPress stubs for tests/local/content-integrations.php (V04).
 *
 * Same philosophy as tests/local/stubs.php and v02-stubs.php: only the
 * interfaces the batch's code paths actually touch are stubbed, and every
 * stub records its calls so the cases can assert on behavior, not on
 * implementation. No site, no database, no network.
 *
 * Loads the four shared include modules (permissions, audit log,
 * integrations, environment) exactly as the internal bootstrap would, with
 * the root-entry constants defined first. The per-domain ability files are
 * NOT loaded here — the case that exercises them requires them explicitly,
 * mirroring the bootstrap's conditional ability loading.
 *
 * Setting HAL_V04_SKIP_PLUGIN_API before this file is required simulates an
 * environment where wp-admin/includes/plugin.php is unreadable (the
 * "plugin list unavailable" degradation path).
 *
 * @package hal-mcp-abilities
 */

if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', '/tmp/hal-v04-fake/' );
}

if ( ! defined( 'HAL_MCP_ABILITIES_VERSION' ) ) {
	define( 'HAL_MCP_ABILITIES_VERSION', '2.0.0' );
}

if ( ! defined( 'HAL_MCP_ABILITIES_DIR' ) ) {
	define( 'HAL_MCP_ABILITIES_DIR', dirname( __DIR__, 2 ) . '/hal-mcp-abilities' );
}

if ( ! defined( 'ARRAY_A' ) ) {
	define( 'ARRAY_A', 'ARRAY_A' );
}

/**
 * Shared mutable stub state. Everything a case asserts on lives in here.
 *
 * @return array<string, mixed>
 */
function &hal_v04_state(): array {
	static $state = [
		'now'            => '2026-09-15 10:00:00',
		'wp_version'     => '7.0.2',
		'multisite'      => false,
		'blog_id'        => 1,
		'locale'         => 'en_US',
		'is_rtl'         => false,
		'theme'          => [ 'stylesheet' => 'law-firm-theme', 'name' => 'Law Firm Theme', 'version' => '1.2.0' ],
		'plugins'        => [],
		'mu_plugins'     => [],
		'active_plugins' => [],
		'sitewide'       => [],
		'did_init'       => true,
		'switched'       => 0,
		'update_plugins' => null,
		'post_types'     => [],
		'taxonomies'     => [],
		'wp_update_plugins_calls' => 0,
		'option_writes'  => [],
		'option_deletes' => [],
		'options'        => [],
		'caps'           => [],
		'registered_abilities' => [],
		'hooks'          => [],
		'filters'        => [],
		'audit_rows'     => [],
		'wpdb'           => null,
		'user'           => [ 'login' => 'admin' ],
		'user_id'        => 1,
		'posts'          => [],
		'post_meta'      => [],
		'block_types'    => [],
		'pll_languages'  => [],
		'pll_post_lang'  => [],
		'pll_translations' => [],
		'wpml_groups'    => [],
	];

	return $state;
}

/**
 * Resets the stub environment and requires the plugin include modules, once
 * per case (each case runs in a fresh subprocess).
 */
function hal_v04_reset_stubs(): void {
	$state                    = &hal_v04_state();
	$state['now']             = '2026-09-15 10:00:00';
	$state['wp_version']      = '7.0.2';
	$state['multisite']       = false;
	$state['blog_id']         = 1;
	$state['locale']          = 'en_US';
	$state['is_rtl']          = false;
	$state['theme']           = [ 'stylesheet' => 'law-firm-theme', 'name' => 'Law Firm Theme', 'version' => '1.2.0' ];
	$state['plugins']         = [];
	$state['mu_plugins']      = [];
	$state['active_plugins']  = [];
	$state['sitewide']        = [];
	$state['did_init']        = true;
	$state['switched']        = 0;
	$state['update_plugins']  = null;
	$state['post_types']      = [];
	$state['taxonomies']      = [];
	$state['wp_update_plugins_calls'] = 0;
	$state['option_writes']   = [];
	$state['option_deletes']  = [];
	$state['caps']            = [];
	$state['registered_abilities'] = [];
	$state['filters']         = [];
	// NOTE: 'hooks' is deliberately NOT reset — the plugin modules register
	// their load-time hooks when this file requires them (before reset runs),
	// and hal_v04_fire_plugins_loaded() replays exactly those registrations.
	$state['audit_rows']      = [];
	$state['wpdb']            = new Hal_V04_Wpdb();
	$GLOBALS['wpdb']          = $state['wpdb'];
	$state['user']            = [ 'login' => 'admin' ];
	$state['user_id']         = 1;
	$state['posts']           = [];
	$state['post_meta']       = [];
	$state['block_types']     = [];
	$state['pll_languages']   = [];
	$state['pll_post_lang']   = [];
	$state['pll_translations'] = [];
	$state['wpml_groups']     = [];

	// The audit module reads this at load-hook time; the seeded value makes
	// its storage init an early return (no dbDelta in the stub world).
	$state['options'] = [ 'hal_mcp_audit_log_db_version' => HAL_MCP_AUDIT_LOG_DB_VERSION ];

	ob_start();
}

/**
 * Fires the recorded plugins_loaded callbacks in priority order, simulating
 * the moment every plugin has finished loading (the integration loader at 5,
 * the core integration registrations at 6, the audit storage init at 10).
 */
function hal_v04_fire_plugins_loaded(): void {
	$hooks = hal_v04_state()['hooks']['plugins_loaded'] ?? [];

	$priorities = array_keys( $hooks );
	sort( $priorities );

	foreach ( $priorities as $priority ) {
		foreach ( $hooks[ $priority ] as $callback ) {
			call_user_func( $callback );
		}
	}
}

/**
 * Finds one operation-status entry by area.
 *
 * @param array<int, array<string, mixed>> $statuses Status list.
 * @param string                           $area     Area slug.
 * @return array<string, mixed>|null
 */
function hal_v04_find_operation( array $statuses, string $area ): ?array {
	foreach ( $statuses as $status ) {
		if ( ( $status['area'] ?? '' ) === $area ) {
			return $status;
		}
	}

	return null;
}

/**
 * Finds one plugin entry in a summary/inventory plugin list.
 *
 * @param array<int, array<string, mixed>> $plugins  Plugin entries.
 * @param string                           $basename Plugin basename.
 * @return array<string, mixed>|null
 */
function hal_v04_find_plugin( array $plugins, string $basename ): ?array {
	foreach ( $plugins as $plugin ) {
		if ( ( $plugin['plugin'] ?? '' ) === $basename ) {
			return $plugin;
		}
	}

	return null;
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

// ---------------------------------------------------------------------------
// WordPress function/class stubs
// ---------------------------------------------------------------------------

if ( ! class_exists( 'WP_Error' ) ) {
	/**
	 * Minimal WP_Error.
	 */
	class WP_Error {

		/** @var array<int, string> */
		public $errors = [];

		/**
		 * Constructor.
		 *
		 * @param string $code    Error code.
		 * @param string $message Error message.
		 */
		public function __construct( string $code = '', string $message = '' ) {
			if ( '' !== $code ) {
				$this->errors[ $code ] = $message;
			}
		}

		/**
		 * First error message.
		 *
		 * @return string
		 */
		public function get_error_message(): string {
			return (string) reset( $this->errors );
		}
	}
}

if ( ! function_exists( 'is_wp_error' ) ) {
	/**
	 * WP_Error check.
	 *
	 * @param mixed $thing Anything.
	 * @return bool
	 */
	function is_wp_error( $thing ): bool {
		return $thing instanceof WP_Error;
	}
}

if ( ! function_exists( 'add_action' ) ) {
	/**
	 * Records callbacks per hook with their priority, so the runner can fire
	 * plugins_loaded in real order.
	 *
	 * @param string   $hook_name Hook name.
	 * @param callable $callback  Callback.
	 * @param int      $priority  Priority.
	 * @param int      $accepted_args Accepted args.
	 */
	function add_action( string $hook_name, $callback, int $priority = 10, int $accepted_args = 1 ): void {
		hal_v04_state()['hooks'][ $hook_name ][ $priority ][] = $callback;
	}
}

if ( ! function_exists( 'do_action' ) ) {
	/**
	 * Fires every recorded callback for a hook (WordPress passes the value
	 * as the first argument; extra args follow).
	 *
	 * @param string $hook_name Hook name.
	 * @param mixed  ...$args   Arguments.
	 */
	function do_action( string $hook_name, ...$args ): void {
		$hal_v04_hooks = hal_v04_state()['hooks'][ $hook_name ] ?? [];

		$hal_v04_priorities = array_keys( $hal_v04_hooks );
		sort( $hal_v04_priorities );

		foreach ( $hal_v04_priorities as $hal_v04_priority ) {
			foreach ( $hal_v04_hooks[ $hal_v04_priority ] as $hal_v04_callback ) {
				call_user_func( $hal_v04_callback, ...$args );
			}
		}
	}
}

if ( ! function_exists( '__' ) ) {
	/** Translation stub: identity. */
	function __( string $text, string $domain = 'default' ): string {
		return $text;
	}
}

if ( ! function_exists( 'sanitize_key' ) ) {
	/** Lowercase alnum/dash/underscore. */
	function sanitize_key( string $value ): string {
		return preg_replace( '/[^a-z0-9_\-]/', '', strtolower( $value ) );
	}
}

if ( ! function_exists( 'current_time' ) ) {
	/** Fixed, case-mutable timestamp. */
	function current_time( string $type ): string {
		return hal_v04_state()['now'];
	}
}

if ( ! function_exists( 'wp_json_encode' ) ) {
	/** JSON encode wrapper. */
	function wp_json_encode( $value ) {
		return json_encode( $value );
	}
}

if ( ! function_exists( 'get_option' ) ) {
	/**
	 * Reads the stub options store; missing options return false like WordPress.
	 * 'active_plugins' is served from its dedicated state key so tests can
	 * mutate the activation set without touching the raw options array.
	 *
	 * @param string $option  Option name.
	 * @param mixed  $default Default.
	 * @return mixed
	 */
	function get_option( string $option, $default = false ) {
		if ( 'active_plugins' === $option ) {
			return hal_v04_state()['active_plugins'];
		}

		$hal_v04_options = hal_v04_state()['options'];

		return array_key_exists( $option, $hal_v04_options ) ? $hal_v04_options[ $option ] : $default;
	}
}

if ( ! function_exists( 'update_option' ) ) {
	/**
	 * Sets a stub option and records the write.
	 *
	 * @param string $option   Option name.
	 * @param mixed  $value    Value.
	 * @param mixed  $autoload Autoload (accepted, unused).
	 * @return bool
	 */
	function update_option( string $option, $value, $autoload = null ): bool {
		hal_v04_state()['options'][ $option ] = $value;
		hal_v04_state()['option_writes'][]    = $option;

		return true;
	}
}

if ( ! function_exists( 'delete_option' ) ) {
	/**
	 * Deletes a stub option and records the deletion.
	 *
	 * @param string $option Option name.
	 * @return bool True when the option existed.
	 */
	function delete_option( string $option ): bool {
		$state = &hal_v04_state();
		$state['option_deletes'][] = $option;

		if ( array_key_exists( $option, $state['options'] ) ) {
			unset( $state['options'][ $option ] );
			return true;
		}

		return false;
	}
}

if ( ! function_exists( 'get_site_option' ) ) {
	/**
	 * Reads the stub network options store (multisite paths only).
	 *
	 * @param string $option  Option name.
	 * @param mixed  $default Default.
	 * @return mixed
	 */
	function get_site_option( string $option, $default = false ) {
		$hal_v04_state = hal_v04_state();

		if ( 'active_sitewide_plugins' === $option ) {
			return (array) ( $hal_v04_state['sitewide'] ?? [] );
		}

		return $default;
	}
}

if ( ! function_exists( 'get_current_blog_id' ) ) {
	/** Current blog ID from the stub state. */
	function get_current_blog_id(): int {
		return (int) hal_v04_state()['blog_id'];
	}
}

if ( ! function_exists( 'did_action' ) ) {
	/** Returns 1 for 'init' when the stub says init has fired. */
	function did_action( string $hook_name ): int {
		return ( 'init' === $hook_name && hal_v04_state()['did_init'] ) ? 1 : 0;
	}
}

if ( ! function_exists( 'switch_to_blog' ) ) {
	/** Records the switch — inventory code must NEVER call it (F14). */
	function switch_to_blog( int $blog_id ): bool {
		++hal_v04_state()['switched'];

		return true;
	}
}

if ( ! function_exists( 'restore_current_blog' ) ) {
	/** Records the restore — same rule as switch_to_blog(). */
	function restore_current_blog(): bool {
		--hal_v04_state()['switched'];

		return true;
	}
}

if ( ! function_exists( 'is_multisite' ) ) {
	/** Multisite flag from the stub state. */
	function is_multisite(): bool {
		return (bool) hal_v04_state()['multisite'];
	}
}

if ( ! function_exists( 'get_bloginfo' ) ) {
	/** Core version (the only field the batch reads). */
	function get_bloginfo( string $show = '' ): string {
		return 'version' === $show ? (string) hal_v04_state()['wp_version'] : '';
	}
}

if ( ! function_exists( 'get_locale' ) ) {
	/** Site locale from the stub state. */
	function get_locale(): string {
		return (string) hal_v04_state()['locale'];
	}
}

if ( ! function_exists( 'is_rtl' ) ) {
	/** RTL flag from the stub state. */
	function is_rtl(): bool {
		return (bool) hal_v04_state()['is_rtl'];
	}
}

if ( ! class_exists( 'Hal_V04_Theme' ) ) {
	/**
	 * Minimal WP_Theme stand-in: the three calls the inventory makes.
	 */
	final class Hal_V04_Theme {

		/** @var array<string, string> */
		private $data;

		/**
		 * Constructor.
		 *
		 * @param array<string, string> $data Theme data.
		 */
		public function __construct( array $data ) {
			$this->data = $data;
		}

		/**
		 * Stylesheet (stable slug).
		 *
		 * @return string
		 */
		public function get_stylesheet(): string {
			return (string) ( $this->data['stylesheet'] ?? '' );
		}

		/**
		 * Header lookup.
		 *
		 * @param string $header Header name.
		 * @return string
		 */
		public function get( string $header ): string {
			if ( 'Name' === $header ) {
				return (string) ( $this->data['name'] ?? '' );
			}

			if ( 'Version' === $header ) {
				return (string) ( $this->data['version'] ?? '' );
			}

			return '';
		}
	}
}

if ( ! function_exists( 'wp_get_theme' ) ) {
	/** Theme object from the stub state. */
	function wp_get_theme(): Hal_V04_Theme {
		return new Hal_V04_Theme( hal_v04_state()['theme'] );
	}
}

if ( ! defined( 'HAL_V04_SKIP_PLUGIN_API' ) ) {
	if ( ! function_exists( 'get_plugins' ) ) {
		/**
		 * Installed plugins from the stub state (wp-admin API shape).
		 *
		 * @param string $plugin_folder Optional subfolder.
		 * @return array<string, array<string, string>>
		 */
		function get_plugins( string $plugin_folder = '' ): array {
			return hal_v04_state()['plugins'];
		}
	}

	if ( ! function_exists( 'get_mu_plugins' ) ) {
		/**
		 * Must-use plugins from the stub state (wp-admin API shape).
		 *
		 * @return array<string, array<string, string>>
		 */
		function get_mu_plugins(): array {
			return hal_v04_state()['mu_plugins'];
		}
	}
}

if ( ! function_exists( 'get_site_transient' ) ) {
	/**
	 * Reads the stub site transient (update_plugins only).
	 *
	 * @param string $transient Transient name.
	 * @return mixed
	 */
	function get_site_transient( string $transient ) {
		return 'update_plugins' === $transient ? hal_v04_state()['update_plugins'] : false;
	}
}

if ( ! function_exists( 'wp_update_plugins' ) ) {
	/**
	 * Records the call — this is the wordpress.org network check that the
	 * v2 inventory and ability must NEVER trigger (F07 separation proof).
	 */
	function wp_update_plugins(): void {
		++hal_v04_state()['wp_update_plugins_calls'];
	}
}

if ( ! function_exists( 'add_filter' ) ) {
	/**
	 * Registers a filter callback in the stub registry (minimal filter
	 * support: the hostile-filter probes need add_filter to actually store
	 * callbacks, not to be a no-op).
	 *
	 * @param string   $hook_name Filter name.
	 * @param callable $callback  Callback.
	 * @param int      $priority  Priority.
	 * @return true
	 */
	function add_filter( string $hook_name, $callback, int $priority = 10 ): bool {
		hal_v04_state()['filters'][ $hook_name ][ $priority ][] = $callback;

		return true;
	}
}

if ( ! function_exists( 'apply_filters' ) ) {
	/**
	 * Invokes every registered callback in priority order; each callback's
	 * return becomes the next value (WordPress filter semantics). With no
	 * callbacks registered the value flows through unchanged, as before.
	 *
	 * @param string $hook_name Filter name.
	 * @param mixed  $value     Value to filter.
	 * @param mixed  ...$args   Extra args passed through to the callbacks.
	 * @return mixed
	 */
	function apply_filters( string $hook_name, $value, ...$args ) {
		$hal_v04_filters = hal_v04_state()['filters'][ $hook_name ] ?? [];

		$hal_v04_priorities = array_keys( $hal_v04_filters );
		sort( $hal_v04_priorities );

		foreach ( $hal_v04_priorities as $hal_v04_priority ) {
			foreach ( $hal_v04_filters[ $hal_v04_priority ] as $hal_v04_callback ) {
				$value = call_user_func( $hal_v04_callback, $value, ...$args );
			}
		}

		return $value;
	}
}

if ( ! class_exists( 'WP_Post_Type' ) ) {
	/**
	 * Minimal WP_Post_Type carrying the capability object the policy reads
	 * (F20 authorization) and the label shape the inventory reads.
	 */
	class WP_Post_Type {

		/** @var string */
		public $name = '';

		/** @var object */
		public $labels;

		/** @var bool */
		public $public = false;

		/** @var bool */
		public $hierarchical = false;

		/** @var bool */
		public $show_in_rest = false;

		/** @var object */
		public $cap;

		/**
		 * Constructor from a stub definition row.
		 *
		 * @param array<string, mixed> $def Definition row.
		 */
		public function __construct( array $def ) {
			$this->name         = (string) ( $def['name'] ?? '' );
			$this->labels       = (object) [ 'name' => (string) ( $def['label'] ?? $this->name ) ];
			$this->public       = (bool) ( $def['public'] ?? false );
			$this->hierarchical = (bool) ( $def['hierarchical'] ?? false );
			$this->show_in_rest = (bool) ( $def['show_in_rest'] ?? false );
			$this->cap          = (object) ( $def['cap'] ?? [] );
		}
	}
}

if ( ! function_exists( 'get_post_types' ) ) {
	/**
	 * Filters the stub post types; supports the shapes the inventory uses.
	 *
	 * @param array<string, mixed> $args   Filter args ('public').
	 * @param string               $output 'names' or 'objects'.
	 * @return array<string, mixed>
	 */
	function get_post_types( array $args = [], string $output = 'names' ): array {
		$hal_v04_out = [];

		foreach ( hal_v04_state()['post_types'] as $hal_v04_name => $hal_v04_def ) {
			if ( isset( $args['public'] ) && (bool) $args['public'] !== (bool) ( $hal_v04_def['public'] ?? false ) ) {
				continue;
			}

			$hal_v04_def['name'] = $hal_v04_name;

			$hal_v04_out[ $hal_v04_name ] = 'objects' === $output
				? new WP_Post_Type( $hal_v04_def )
				: $hal_v04_name;
		}

		return $hal_v04_out;
	}
}

if ( ! function_exists( 'get_taxonomies' ) ) {
	/**
	 * Filters the stub taxonomies; supports the shapes the inventory uses.
	 *
	 * @param array<string, mixed> $args   Filter args ('public').
	 * @param string               $output 'names' or 'objects'.
	 * @return array<string, mixed>
	 */
	function get_taxonomies( array $args = [], string $output = 'names' ): array {
		$hal_v04_out = [];

		foreach ( hal_v04_state()['taxonomies'] as $hal_v04_name => $hal_v04_def ) {
			if ( isset( $args['public'] ) && (bool) $args['public'] !== (bool) ( $hal_v04_def['public'] ?? false ) ) {
				continue;
			}

			$hal_v04_out[ $hal_v04_name ] = 'objects' === $output
				? (object) [
					'name'         => $hal_v04_name,
					'labels'       => (object) [ 'name' => (string) ( $hal_v04_def['label'] ?? $hal_v04_name ) ],
					'hierarchical' => (bool) ( $hal_v04_def['hierarchical'] ?? false ),
					'show_in_rest' => (bool) ( $hal_v04_def['show_in_rest'] ?? false ),
					'object_type'  => (array) ( $hal_v04_def['object_type'] ?? [] ),
				]
				: $hal_v04_name;
		}

		return $hal_v04_out;
	}
}

if ( ! function_exists( 'get_all_post_type_supports' ) ) {
	/**
	 * Registered supports for a stub post type.
	 *
	 * @param string $post_type Post type.
	 * @return array<string, bool>
	 */
	function get_all_post_type_supports( string $post_type ): array {
		return (array) ( hal_v04_state()['post_types'][ $post_type ]['supports'] ?? [] );
	}
}

if ( ! function_exists( 'get_post_type_object' ) ) {
	/**
	 * Registered capability mapping for a stub post type.
	 *
	 * @param string $post_type Post type.
	 * @return object|null
	 */
	function get_post_type_object( string $post_type ): ?object {
		$hal_v04_def = hal_v04_state()['post_types'][ $post_type ] ?? null;

		if ( null === $hal_v04_def || empty( $hal_v04_def['cap'] ) ) {
			return null;
		}

		// 'public' mirrors real WP_Post_Type: the policy re-validation reads it
		// off the registered object to keep non-public types unauthorized.
		return (object) [ 'cap' => (object) $hal_v04_def['cap'], 'public' => (bool) ( $hal_v04_def['public'] ?? false ) ];
	}
}

if ( ! function_exists( 'current_user_can' ) ) {
	/**
	 * Answers from the configured primitive capability table.
	 *
	 * @param string $capability Capability slug.
	 * @param mixed  ...$args    Extra args (unused here).
	 * @return bool
	 */
	function current_user_can( string $capability, ...$args ): bool {
		return (bool) ( hal_v04_state()['caps'][ $capability ] ?? false );
	}
}

if ( ! function_exists( 'wp_get_current_user' ) ) {
	/** Current user object stub. */
	function wp_get_current_user(): object {
		return (object) [ 'user_login' => hal_v04_state()['user']['login'] ];
	}
}

if ( ! function_exists( 'wp_register_ability' ) ) {
	/**
	 * Records the ability registration so cases can assert on and invoke the
	 * captured definitions.
	 *
	 * @param string $ability_name Ability name.
	 * @param array  $args         Ability args.
	 * @return bool
	 */
	function wp_register_ability( string $ability_name, array $args = [] ): bool {
		hal_v04_state()['registered_abilities'][ $ability_name ] = $args;

		return true;
	}
}

if ( ! function_exists( 'wp_register_ability_category' ) ) {
	/** Records nothing: categories are not under test in these cases. */
	function wp_register_ability_category( string $slug, array $args = [] ): bool {
		return true;
	}
}

// ---------------------------------------------------------------------------
// Post / meta / sanitization / block-structure stubs (batch-5 contracts)
// ---------------------------------------------------------------------------

if ( ! class_exists( 'WP_Post' ) ) {
	/**
	 * Minimal WP_Post carrying the fields the integration code reads.
	 */
	class WP_Post {

		/** @var int */
		public $ID = 0;

		/** @var string */
		public $post_type = '';

		/** @var string */
		public $post_status = '';

		/** @var string */
		public $post_title = '';

		/** @var string */
		public $post_content = '';

		/** @var string */
		public $post_excerpt = '';

		/** @var string */
		public $post_name = '';

		/** @var int */
		public $post_parent = 0;

		/** @var int */
		public $post_author = 0;

		/** @var string */
		public $post_date = '';

		/** @var string */
		public $post_modified = '';

		/**
		 * Constructor from a stub row.
		 *
		 * @param array<string, mixed> $row Post row.
		 */
		public function __construct( array $row ) {
			$this->ID            = (int) ( $row['ID'] ?? 0 );
			$this->post_type     = (string) ( $row['post_type'] ?? '' );
			$this->post_status   = (string) ( $row['post_status'] ?? '' );
			$this->post_title    = (string) ( $row['post_title'] ?? '' );
			$this->post_content  = (string) ( $row['post_content'] ?? '' );
			$this->post_excerpt  = (string) ( $row['post_excerpt'] ?? '' );
			$this->post_name     = (string) ( $row['post_name'] ?? '' );
			$this->post_parent   = (int) ( $row['post_parent'] ?? 0 );
			$this->post_author   = (int) ( $row['post_author'] ?? 0 );
			$this->post_date     = (string) ( $row['post_date'] ?? '' );
			$this->post_modified = (string) ( $row['post_modified'] ?? '' );
		}
	}
}

if ( ! function_exists( 'hal_v04_seed_post' ) ) {
	/**
	 * Seeds one post row into the stub store and returns its ID.
	 *
	 * @param array<string, mixed> $row Post row (ID optional).
	 * @return int
	 */
	function hal_v04_seed_post( array $row ): int {
		$state = &hal_v04_state();

		$hal_v04_id = isset( $row['ID'] ) ? (int) $row['ID'] : count( $state['posts'] ) + 1;
		$row['ID']  = $hal_v04_id;

		$state['posts'][ $hal_v04_id ] = $row;

		return $hal_v04_id;
	}
}

if ( ! function_exists( 'get_post' ) ) {
	/**
	 * Post object from the stub store.
	 *
	 * @param int|WP_Post $post Post ID or object.
	 * @return WP_Post|null
	 */
	function get_post( $post ): ?WP_Post {
		$hal_v04_id = ( $post instanceof WP_Post ) ? $post->ID : (int) $post;
		$hal_v04_row = hal_v04_state()['posts'][ $hal_v04_id ] ?? null;

		return is_array( $hal_v04_row ) ? new WP_Post( $hal_v04_row ) : null;
	}
}

if ( ! function_exists( 'get_post_meta' ) ) {
	/**
	 * Single/meta-array read from the stub meta store.
	 *
	 * @param int    $object_id Post ID.
	 * @param string $meta_key  Meta key.
	 * @param bool   $single    Single value.
	 * @return mixed
	 */
	function get_post_meta( int $object_id, string $meta_key, bool $single = false ) {
		$hal_v04_value = hal_v04_state()['post_meta'][ $object_id ][ $meta_key ] ?? '';

		return $single ? $hal_v04_value : [ $hal_v04_value ];
	}
}

if ( ! function_exists( 'update_post_meta' ) ) {
	/**
	 * Writes the stub meta store.
	 *
	 * @param int    $object_id   Post ID.
	 * @param string $meta_key    Meta key.
	 * @param mixed  $meta_value  Value.
	 * @return bool
	 */
	function update_post_meta( int $object_id, string $meta_key, $meta_value ): bool {
		hal_v04_state()['post_meta'][ $object_id ][ $meta_key ] = $meta_value;

		return true;
	}
}

if ( ! function_exists( 'add_post_meta' ) ) {
	/**
	 * Adds meta; with $unique=true it fails when the key exists (the change
	 * request lock depends on exactly this).
	 *
	 * @param int    $object_id   Post ID.
	 * @param string $meta_key    Meta key.
	 * @param mixed  $meta_value  Value.
	 * @param bool   $unique      Unique.
	 * @return bool
	 */
	function add_post_meta( int $object_id, string $meta_key, $meta_value, bool $unique = false ): bool {
		$state = &hal_v04_state();

		if ( $unique && array_key_exists( $meta_key, $state['post_meta'][ $object_id ] ?? [] ) ) {
			return false;
		}

		$state['post_meta'][ $object_id ][ $meta_key ] = $meta_value;

		return true;
	}
}

if ( ! function_exists( 'delete_post_meta' ) ) {
	/**
	 * Deletes one meta key.
	 *
	 * @param int    $object_id Post ID.
	 * @param string $meta_key  Meta key.
	 * @return bool
	 */
	function delete_post_meta( int $object_id, string $meta_key ): bool {
		$state = &hal_v04_state();

		if ( ! array_key_exists( $meta_key, $state['post_meta'][ $object_id ] ?? [] ) ) {
			return false;
		}

		unset( $state['post_meta'][ $object_id ][ $meta_key ] );

		return true;
	}
}

if ( ! function_exists( 'wp_insert_post' ) ) {
	/**
	 * Inserts a post row (+ meta_input) and returns its ID.
	 *
	 * @param array<string, mixed> $arr      Post fields.
	 * @param bool                 $wp_error Return WP_Error on failure.
	 * @return int|WP_Error
	 */
	function wp_insert_post( array $arr, bool $wp_error = false ) {
		$hal_v04_id = hal_v04_seed_post(
			[
				'post_type'    => (string) ( $arr['post_type'] ?? 'post' ),
				'post_status'  => (string) ( $arr['post_status'] ?? 'draft' ),
				'post_title'   => (string) ( $arr['post_title'] ?? '' ),
				'post_content' => (string) ( $arr['post_content'] ?? '' ),
				'post_author'  => (int) ( $arr['post_author'] ?? 0 ),
				'post_date'    => hal_v04_state()['now'],
			]
		);

		foreach ( (array) ( $arr['meta_input'] ?? [] ) as $hal_v04_key => $hal_v04_value ) {
			update_post_meta( $hal_v04_id, (string) $hal_v04_key, $hal_v04_value );
		}

		return $hal_v04_id;
	}
}

if ( ! function_exists( 'wp_update_post' ) ) {
	/**
	 * Updates the stored row's fields.
	 *
	 * @param array<string, mixed> $arr      Post fields (ID required).
	 * @param bool                 $wp_error Return WP_Error on failure.
	 * @return int|WP_Error
	 */
	function wp_update_post( array $arr, bool $wp_error = false ) {
		$state = &hal_v04_state();
		$hal_v04_id = (int) ( $arr['ID'] ?? 0 );

		if ( $hal_v04_id < 1 || ! isset( $state['posts'][ $hal_v04_id ] ) ) {
			return $wp_error ? new WP_Error( 'invalid_post', 'Invalid post ID.' ) : 0;
		}

		foreach ( [ 'post_type', 'post_status', 'post_title', 'post_content', 'post_excerpt' ] as $hal_v04_field ) {
			if ( array_key_exists( $hal_v04_field, $arr ) ) {
				$state['posts'][ $hal_v04_id ][ $hal_v04_field ] = (string) $arr[ $hal_v04_field ];
			}
		}

		return $hal_v04_id;
	}
}

if ( ! function_exists( 'get_post_status' ) ) {
	/** Post status from the stub store. */
	function get_post_status( int $post_id ): string {
		return (string) ( hal_v04_state()['posts'][ $post_id ]['post_status'] ?? '' );
	}
}

if ( ! function_exists( 'get_page_template_slug' ) ) {
	/**
	 * Page template slug from the stub post row.
	 *
	 * @param int $post_id Post ID.
	 * @return string
	 */
	function get_page_template_slug( int $post_id ): string {
		return (string) ( hal_v04_state()['posts'][ $post_id ]['page_template'] ?? '' );
	}
}

if ( ! function_exists( 'get_post_thumbnail_id' ) ) {
	/**
	 * Featured-image ID from the stub meta store.
	 *
	 * @param int $post_id Post ID.
	 * @return int
	 */
	function get_post_thumbnail_id( int $post_id ): int {
		return (int) ( hal_v04_state()['post_meta'][ $post_id ]['_thumbnail_id'] ?? 0 );
	}
}

if ( ! function_exists( 'update_post' ) ) {
	/**
	 * Real-WP update_post(): the change-request payload editor uses it for
	 * the preview title; same fields as the wp_update_post stub.
	 *
	 * @param array<string, mixed>|object $arr Post fields (ID required).
	 * @return int|WP_Error
	 */
	function update_post( $arr ) {
		return wp_update_post( (array) $arr, true );
	}
}

if ( ! function_exists( 'get_current_user_id' ) ) {
	/** Current user ID from the stub state. */
	function get_current_user_id(): int {
		return (int) hal_v04_state()['user_id'];
	}
}

if ( ! function_exists( 'wp_strip_all_tags' ) ) {
	/** Strips tags (and optionally script/style blocks) from a string. */
	function wp_strip_all_tags( string $text, bool $remove_breaks = false ): string {
		$text = (string) preg_replace( '@<(script|style)[^>]*?>.*?\\1>@si', '', $text );
		$text = strip_tags( $text );

		return $remove_breaks ? (string) preg_replace( '/[\r\n\t ]+/', ' ', $text ) : trim( $text );
	}
}

if ( ! function_exists( 'sanitize_text_field' ) ) {
	/** Strips tags, control chars, and extra whitespace. */
	function sanitize_text_field( string $str ): string {
		$str = wp_strip_all_tags( $str );
		$str = (string) preg_replace( '/[\r\n\t ]+/', ' ', $str );
		$str = (string) preg_replace( '/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', '', $str );

		return trim( $str );
	}
}

if ( ! function_exists( 'sanitize_textarea_field' ) ) {
	/** Like sanitize_text_field but keeps newlines. */
	function sanitize_textarea_field( string $str ): string {
		$str = wp_strip_all_tags( $str );
		$str = (string) preg_replace( '/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', '', $str );

		return trim( $str );
	}
}

if ( ! function_exists( 'absint' ) ) {
	/** Absolute non-negative integer. */
	function absint( $maybeint ): int {
		return abs( (int) $maybeint );
	}
}

if ( ! function_exists( 'esc_url_raw' ) ) {
	/**
	 * URL sanitization (fixture approximation): javascript:/data:/vbscript:
	 * schemes become empty; everything else passes through.
	 */
	function esc_url_raw( string $url ): string {
		if ( (bool) preg_match( '/^\s*(javascript|data|vbscript)\s*:/i', $url ) ) {
			return '';
		}

		return $url;
	}
}

if ( ! function_exists( 'wp_kses_post' ) ) {
	/**
	 * Post-content sanitization (fixture approximation): strips script and
	 * style blocks entirely, on* event handler attributes, and javascript:/
	 * data: URL attribute values. The real wp_kses_post allow-list is NOT
	 * reproduced — these cases verify that dangerous content does not
	 * survive, not the full production allow-list.
	 */
	function wp_kses_post( string $content ): string {
		$content = (string) preg_replace( '@<(script|style)[^>]*?>.*?\\1>@si', '', $content );
		$content = (string) preg_replace( '/\son[a-z]+\s*=\s*("[^"]*"|\'[^\']*\'|[^\s>]+)/i', '', $content );
		$content = (string) preg_replace( '/((?:href|src|action)\s*=\s*("|\'))\s*(javascript|data|vbscript)\s*:[^"\']*\2/i', '$1$2', $content );

		return $content;
	}
}

if ( ! function_exists( 'parse_blocks' ) ) {
	/**
	 * Block parsing FIXTURE (not real parse_blocks semantics): JSON input
	 * decodes to the block array; anything else becomes one free-HTML block.
	 * The sanitizer contracts are verified against this deterministic
	 * structure; real core parsing is a documented fixture limit.
	 *
	 * @param string $content Block markup (JSON fixture or free HTML).
	 * @return array<int, array<string, mixed>>
	 */
	function parse_blocks( string $content ): array {
		$hal_v04_trimmed = trim( $content );

		if ( '' === $hal_v04_trimmed ) {
			return [];
		}

		if ( str_starts_with( $hal_v04_trimmed, '[' ) ) {
			$hal_v04_decoded = json_decode( $hal_v04_trimmed, true );

			return is_array( $hal_v04_decoded ) ? $hal_v04_decoded : [];
		}

		return [
			[
				'blockName'    => null,
				'attrs'        => [],
				'innerBlocks'  => [],
				'innerHTML'    => $content,
				'innerContent' => [ $content ],
			],
		];
	}
}

if ( ! function_exists( 'serialize_blocks' ) ) {
	/**
	 * Block serialization FIXTURE (inverse of the parse fixture): named
	 * blocks serialize as a JSON array (re-parseable by the fixture parser);
	 * free-HTML-only content serializes as concatenated HTML.
	 *
	 * @param array<int, array<string, mixed>> $blocks Parsed blocks.
	 * @return string
	 */
	function serialize_blocks( array $blocks ): string {
		$hal_v04_has_named = false;

		foreach ( $blocks as $hal_v04_block ) {
			if ( ! empty( $hal_v04_block['blockName'] ) ) {
				$hal_v04_has_named = true;
				break;
			}
		}

		if ( $hal_v04_has_named ) {
			return (string) wp_json_encode( array_values( $blocks ) );
		}

		$hal_v04_out = '';

		foreach ( $blocks as $hal_v04_block ) {
			$hal_v04_out .= (string) ( $hal_v04_block['innerHTML'] ?? '' );
		}

		return $hal_v04_out;
	}
}

if ( ! class_exists( 'WP_Block_Type_Registry' ) ) {
	/**
	 * Minimal block-registry stand-in backed by state['block_types']
	 * (name => {attributes: string[]}); the detection snapshot and the
	 * sanitizer's known/unknown decisions read exactly this.
	 */
	class WP_Block_Type_Registry {

		/**
		 * Returns the shared instance (state-backed).
		 *
		 * @return self
		 */
		public static function get_instance(): self {
			return new self();
		}

		/**
		 * Whether a block name is registered.
		 *
		 * @param string $name Block name.
		 * @return bool
		 */
		public function is_registered( string $name ): bool {
			return array_key_exists( $name, hal_v04_state()['block_types'] );
		}

		/**
		 * All registered block types (as objects carrying ->attributes).
		 *
		 * @return array<string, object>
		 */
		public function get_all_registered(): array {
			$hal_v04_out = [];

			foreach ( hal_v04_state()['block_types'] as $hal_v04_name => $hal_v04_def ) {
				$hal_v04_out[ $hal_v04_name ] = (object) [
					'name'       => $hal_v04_name,
					'attributes' => (array) ( $hal_v04_def['attributes'] ?? [] ),
				];
			}

			return $hal_v04_out;
		}
	}
}

/**
 * Minimal $wpdb stand-in: enough surface for the audit module's denial
 * logging, with every statement recorded.
 */
final class Hal_V04_Wpdb {

	/** @var string */
	public $prefix = 'wp_';

	/** @var int */
	public $insert_id = 0;

	/**
	 * Inserts a row (audit log only in these tests).
	 *
	 * @param string             $table   Table name.
	 * @param array<stringmixed> $data    Column values.
	 * @param array<int, string> $formats Formats.
	 * @return int|false
	 */
	public function insert( string $table, array $data, array $formats = [] ) {
		hal_v04_state()['audit_rows'][] = $data;
		$this->insert_id                = count( hal_v04_state()['audit_rows'] );

		return 1;
	}

	/**
	 * Answers the SHOW TABLES readiness probe.
	 *
	 * @param string|null $sql SQL.
	 * @return string|null
	 */
	public function get_var( ?string $sql = null ): ?string {
		if ( null !== $sql && str_contains( $sql, 'SHOW TABLES' ) ) {
			return $this->prefix . 'hal_mcp_audit_log';
		}

		return null;
	}

	/**
	 * Naive prepare(): substitutes %s / %d placeholders in order.
	 *
	 * @param string $sql  SQL with placeholders.
	 * @param mixed  ...$args Values.
	 * @return string
	 */
	public function prepare( string $sql, ...$args ): string {
		foreach ( $args as $arg ) {
			$sql = (string) preg_replace(
				'/%[sd]/',
				is_int( $arg ) ? (string) $arg : "'" . addslashes( (string) $arg ) . "'",
				$sql,
				1
			);
		}

		return $sql;
	}

	/**
	 * Returns the recorded rows.
	 *
	 * @param string|null $sql  SQL.
	 * @param string|null $mode Fetch mode.
	 * @return array<int, array<string, mixed>>
	 */
	public function get_results( ?string $sql = null, ?string $mode = null ): array {
		return hal_v04_state()['audit_rows'];
	}
}

$GLOBALS['wpdb'] = new Hal_V04_Wpdb();

// Load the plugin modules exactly as the internal bootstrap would: shared
// foundations first (permissions is a dependency of the inventory's internal
// post-type guard), then integrations (registry), then environment.
require_once __DIR__ . '/../../hal-mcp-abilities/includes/permissions.php';
require_once __DIR__ . '/../../hal-mcp-abilities/includes/audit-log.php';
require_once __DIR__ . '/../../hal-mcp-abilities/includes/integrations.php';
require_once __DIR__ . '/../../hal-mcp-abilities/includes/change-requests.php';
require_once __DIR__ . '/../../hal-mcp-abilities/includes/environment.php';
