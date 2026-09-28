<?php
/**
 * In-memory WordPress stubs for tests/local/permissions-approvals.php (V02).
 *
 * Same philosophy as tests/local/stubs.php: only the interfaces the batch's
 * code paths actually touch are stubbed, and everything records its calls so
 * the cases can assert on behavior. No site, no database, no network.
 *
 * Loads the three include modules (permissions, audit log, change requests)
 * exactly as the internal bootstrap would, with the root-entry constants
 * defined first.
 *
 * @package hal-mcp-abilities
 */

if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', '/tmp/hal-v02-fake/' );
}

if ( ! defined( 'HAL_MCP_ABILITIES_VERSION' ) ) {
	define( 'HAL_MCP_ABILITIES_VERSION', '2.0.0' );
}

// Defined by wp-includes/wp-db.php in WordPress; the audit reader uses ARRAY_A.
if ( ! defined( 'ARRAY_A' ) ) {
	define( 'ARRAY_A', 'ARRAY_A' );
}

if ( ! defined( 'ARRAY_N' ) ) {
	define( 'ARRAY_N', 'ARRAY_N' );
}

/**
 * Shared mutable stub state.
 *
 * @return array<string, mixed>
 */
function &hal_v02_state(): array {
	static $state = [
		'posts'      => [],
		'meta'       => [],
		'primitives' => [],
		'meta_caps'  => [],
		'user'       => [ 'id' => 1, 'login' => 'admin' ],
		'options'    => [],
		'updated_options' => [],
		'filters'    => [],
		'audit_rows' => [],
		'next_id'    => 100,
		'wpdb'       => null,
		'post_types' => [],
		'locale'     => 'en_US',
		'terms'      => [],
		'posts_terms' => [],
		'registered_abilities' => [],
	];

	return $state;
}

/**
 * Resets the stub environment and requires the plugin include modules, once
 * per case (each case runs in a fresh subprocess, but reset also re-seeds the
 * audit-log schema option so hal_mcp_audit_log_table_ready() sees a ready
 * table).
 */
function hal_v02_reset_stubs(): void {
	$state                   = &hal_v02_state();
	$state['posts']          = [];
	$state['meta']           = [];
	$state['primitives']     = [];
	$state['meta_caps']      = [];
	$state['user']           = [ 'id' => 1, 'login' => 'admin' ];
	$state['options']        = [ 'hal_mcp_audit_log_db_version' => HAL_MCP_AUDIT_LOG_DB_VERSION ];
	$state['updated_options'] = [];
	$state['filters']        = [];
	$state['audit_rows']     = [];
	$state['next_id']        = 100;
	$state['post_types']     = [];
	$state['locale']         = 'en_US';
	$state['terms']          = [];
	$state['posts_terms']    = [];
	$state['registered_abilities'] = [];
	$state['wpdb']           = new Hal_V02_Wpdb();
	$GLOBALS['wpdb']         = $state['wpdb'];
}

/**
 * Configures the capability answer table.
 *
 * @param array{primitives: array<string, bool>, meta_caps: array<string, callable>} $caps Caps.
 */
function hal_v02_set_caps( array $caps ): void {
	$state              = &hal_v02_state();
	$state['primitives'] = $caps['primitives'] ?? [];
	$state['meta_caps']  = $caps['meta_caps'] ?? [];
}

/**
 * Sets the current user.
 *
 * @param int    $id    User ID.
 * @param string $login User login.
 */
function hal_v02_set_user( int $id, string $login ): void {
	$state         = &hal_v02_state();
	$state['user'] = [ 'id' => $id, 'login' => $login ];
}

/**
 * All stored audit rows.
 *
 * @return array<int, array<string, mixed>>
 */
function hal_v02_all_audit_rows(): array {
	return hal_v02_state()['audit_rows'];
}

/**
 * Audit rows filtered by stage.
 *
 * @param string $stage Stage slug.
 * @return array<int, array<string, mixed>>
 */
function hal_v02_audit_rows( string $stage ): array {
	return array_values(
		array_filter(
			hal_v02_state()['audit_rows'],
			static fn( $row ) => ( $row['stage'] ?? '' ) === $stage
		)
	);
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

		/**
		 * First error code (used by the batch-6 transport and runner paths).
		 *
		 * @return string
		 */
		public function get_error_code(): string {
			return (string) array_key_first( $this->errors );
		}
	}
}

if ( ! class_exists( 'WP_Post' ) ) {
	/**
	 * Minimal WP_Post.
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

		/** @var int */
		public $post_author = 0;

		/** @var string */
		public $post_date = '1970-01-01 00:00:00';

		/** @var string */
		public $post_content = '';

		/** @var string */
		public $post_excerpt = '';

		/** @var string */
		public $post_modified = '1970-01-01 00:00:00';

		/** @var string */
		public $post_name = '';

		/** @var int */
		public $post_parent = 0;

		/**
		 * Constructor from an assoc row.
		 *
		 * @param array<string, mixed> $row Post fields.
		 */
		public function __construct( array $row ) {
			foreach ( $row as $key => $value ) {
				if ( property_exists( $this, $key ) ) {
					$this->{$key} = $value;
				}
			}
		}
	}
}

if ( ! class_exists( 'WP_Term' ) ) {
	/**
	 * Minimal WP_Term carrying the fields the domain term resolvers read.
	 */
	class WP_Term {

		/** @var int */
		public $term_id = 0;

		/** @var string */
		public $name = '';

		/** @var string */
		public $taxonomy = '';

		/**
		 * Constructor from a stub term row.
		 *
		 * @param array<string, mixed> $row Term fields.
		 */
		public function __construct( array $row ) {
			foreach ( $row as $key => $value ) {
				if ( property_exists( $this, $key ) ) {
					$this->{$key} = $value;
				}
			}
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
	/** Records nothing; the hooks are not exercised in these cases. */
	function add_action( string $hook_name, $callback, int $priority = 10, int $accepted_args = 1 ): void {}
}

if ( ! function_exists( 'do_action' ) ) {
	/**
	 * Fires nothing: the cases assert on stored state, not hook dispatch.
	 * Present so the F21 payload-updated action in
	 * hal_mcp_update_change_request_payload() can run under the stubs.
	 *
	 * @param string $hook_name Hook name.
	 * @param mixed  ...$args   Arguments.
	 */
	function do_action( string $hook_name, ...$args ): void {}
}

if ( ! function_exists( 'register_post_type' ) ) {
	/** Records the registration; the CPT itself is not queried here. */
	function register_post_type( string $post_type, array $args = [] ): void {
		hal_v02_state()['options'][ 'registered_cpt_' . $post_type ] = $args;
	}
}

if ( ! class_exists( 'WP_Post_Type' ) ) {
	/**
	 * Minimal WP_Post_Type carrying the capability object the policy reads.
	 */
	class WP_Post_Type {

		/** @var string */
		public $name = '';

		/** @var object */
		public $labels;

		/** @var bool */
		public $public = false;

		/** @var object */
		public $cap;

		/**
		 * Constructor from a stub definition row.
		 *
		 * @param array<string, mixed> $def Definition row (name/public/cap/label).
		 */
		public function __construct( array $def ) {
			$this->name    = (string) ( $def['name'] ?? '' );
			$this->labels  = (object) [ 'name' => (string) ( $def['label'] ?? $this->name ) ];
			$this->public  = (bool) ( $def['public'] ?? false );
			$this->cap     = (object) ( $def['cap'] ?? [] );
		}
	}
}

if ( ! function_exists( 'get_post_types' ) ) {
	/**
	 * Reads the stub post type registry; the F20 authorization list and the
	 * const policy types both resolve through this.
	 *
	 * @param array<string, mixed> $args   Filter args ('public').
	 * @param string               $output 'names' or 'objects'.
	 * @return array<string, mixed>
	 */
	function get_post_types( array $args = [], string $output = 'names' ): array {
		$hal_v02_out = [];

		foreach ( hal_v02_state()['post_types'] as $hal_v02_name => $hal_v02_def ) {
			if ( isset( $args['public'] ) && (bool) $args['public'] !== (bool) ( $hal_v02_def['public'] ?? false ) ) {
				continue;
			}

			$hal_v02_def['name'] = $hal_v02_def['name'] ?? $hal_v02_name;

			$hal_v02_out[ $hal_v02_name ] = 'objects' === $output
				? new WP_Post_Type( $hal_v02_def )
				: $hal_v02_name;
		}

		return $hal_v02_out;
	}
}

if ( ! function_exists( 'apply_filters' ) ) {
	/**
	 * Applies the callbacks registered under the hook (add_filter below),
	 * passing the value through unchanged when none are registered.
	 *
	 * @param string $hook_name Filter name.
	 * @param mixed  $value     Value to filter.
	 * @param mixed  ...$args   Extra args (unused here).
	 * @return mixed
	 */
	function apply_filters( string $hook_name, $value, ...$args ) {
		foreach ( hal_v02_state()['filters'][ $hook_name ] ?? [] as $hal_v02_callback ) {
			$value = call_user_func( $hal_v02_callback, $value, ...$args );
		}

		return $value;
	}
}

if ( ! function_exists( 'add_filter' ) ) {
	/**
	 * Registers one filter callback.
	 *
	 * @param string   $hook_name Filter name.
	 * @param callable $callback  Callback.
	 * @return bool
	 */
	function add_filter( string $hook_name, $callback ): bool {
		hal_v02_state()['filters'][ $hook_name ][] = $callback;

		return true;
	}
}

if ( ! function_exists( 'get_post_type_object' ) ) {
	/**
	 * Registered capability mappings for the three post types the policy
	 * touches, mirroring core/WooCommerce registration shapes.
	 *
	 * @param string $post_type Post type.
	 * @return object|null
	 */
	function get_post_type_object( string $post_type ): ?object {
		// Registered custom types resolve through the stub registry first.
		$hal_v02_def = hal_v02_state()["post_types"][ $post_type ] ?? null;

		if ( null !== $hal_v02_def && ! empty( $hal_v02_def["cap"] ) ) {
			return (object) [ "cap" => (object) $hal_v02_def["cap"], "public" => (bool) ( $hal_v02_def["public"] ?? false ) ];
		}

		$hal_v02_maps = [
			'post'       => [
				'create_posts'   => 'edit_posts',
				'edit_post'      => 'edit_post',
				'read_post'      => 'read_post',
				'publish_posts'  => 'publish_posts',
			],
			'page'       => [
				'create_posts'   => 'edit_pages',
				'edit_post'      => 'edit_page',
				'read_post'      => 'read_page',
				'publish_posts'  => 'publish_pages',
			],
			'product'    => [
				'create_posts'   => 'edit_products',
				'edit_post'      => 'edit_product',
				'read_post'      => 'read_product',
				'publish_posts'  => 'publish_products',
			],
			'attachment' => [
				'create_posts'   => 'upload_files',
				'edit_post'      => 'edit_post',
				'read_post'      => 'read_post',
				'publish_posts'  => 'edit_posts',
			],
		];

		if ( ! isset( $hal_v02_maps[ $post_type ] ) ) {
			return null;
		}

		return (object) [ 'cap' => (object) $hal_v02_maps[ $post_type ], 'public' => true ];
	}
}

if ( ! function_exists( 'current_user_can' ) ) {
	/**
	 * Answers from the configured capability table: a meta cap first (with the
	 * object ID), then a primitive, else false.
	 *
	 * @param string $capability Capability slug.
	 * @param mixed  ...$args    Extra args (object ID for meta caps).
	 * @return bool
	 */
	function current_user_can( string $capability, ...$args ): bool {
		$state = &hal_v02_state();

		if ( isset( $state['meta_caps'][ $capability ] ) && isset( $args[0] ) ) {
			return (bool) call_user_func( $state['meta_caps'][ $capability ], $args[0] );
		}

		return (bool) ( $state['primitives'][ $capability ] ?? false );
	}
}

if ( ! function_exists( 'get_current_user_id' ) ) {
	/** Current user ID from the stub state. */
	function get_current_user_id(): int {
		return (int) hal_v02_state()['user']['id'];
	}
}

if ( ! function_exists( 'wp_get_current_user' ) ) {
	/** Current user object stub. */
	function wp_get_current_user(): object {
		return (object) [ 'user_login' => hal_v02_state()['user']['login'] ];
	}
}

if ( ! function_exists( 'get_current_blog_id' ) ) {
	/** Single site. */
	function get_current_blog_id(): int {
		return 1;
	}
}

if ( ! function_exists( 'get_post' ) ) {
	/**
	 * Fetches a stub post.
	 *
	 * @param int|WP_Post $post Post ID or object.
	 * @return WP_Post|null
	 */
	function get_post( $post ): ?WP_Post {
		$hal_v02_id = $post instanceof WP_Post ? $post->ID : (int) $post;
		$hal_v02_row = hal_v02_state()['posts'][ $hal_v02_id ] ?? null;

		return null === $hal_v02_row ? null : new WP_Post( $hal_v02_row );
	}
}

if ( ! function_exists( 'wp_insert_post' ) ) {
	/**
	 * Inserts a stub post, honoring meta_input.
	 *
	 * @param array<string, mixed> $arr      Post fields.
	 * @param bool                 $wp_error Return WP_Error on failure.
	 * @return int|WP_Error
	 */
	function wp_insert_post( array $arr, bool $wp_error = false ) {
		$state   = &hal_v02_state();
		$post_id = $state['next_id']++;

		$state['posts'][ $post_id ] = [
			'ID'          => $post_id,
			'post_type'   => (string) ( $arr['post_type'] ?? 'post' ),
			'post_status' => (string) ( $arr['post_status'] ?? 'draft' ),
			'post_title'  => (string) ( $arr['post_title'] ?? '' ),
			'post_author' => (int) ( $arr['post_author'] ?? 0 ),
			'post_date'   => '2026-09-13 12:00:00',
			'post_content'  => (string) ( $arr['post_content'] ?? '' ),
			'post_excerpt'  => (string) ( $arr['post_excerpt'] ?? '' ),
			'post_modified' => '2026-09-13 12:00:00',
			'post_name'     => '',
			'post_parent'   => 0,
		];

		foreach ( (array) ( $arr['meta_input'] ?? [] ) as $meta_key => $meta_value ) {
			$state['meta'][ $post_id ][ $meta_key ] = [ $meta_value ];
		}

		return $post_id;
	}
}

if ( ! function_exists( 'update_post' ) ) {
	/**
	 * Updates stub post fields.
	 *
	 * @param int                  $post_id Post ID.
	 * @param array<string, mixed> $fields  Fields to set.
	 * @return int
	 */
	function update_post( int $post_id, array $fields ): int {
		$state = &hal_v02_state();

		if ( ! isset( $state['posts'][ $post_id ] ) ) {
			return 0;
		}

		foreach ( $fields as $field => $value ) {
			if ( 'ID' === $field ) {
				continue;
			}
			$state['posts'][ $post_id ][ $field ] = $value;
		}

		return $post_id;
	}
}

if ( ! function_exists( 'wp_update_post' ) ) {
	/**
	 * Updates stub post fields (the fields the domain apply functions set);
	 * returns the ID, or a WP_Error when the post does not exist and
	 * $wp_error was requested.
	 *
	 * @param array<string, mixed> $arr      Fields (ID required).
	 * @param bool                 $wp_error Return WP_Error on failure.
	 * @return int|WP_Error
	 */
	function wp_update_post( array $arr, bool $wp_error = false ) {
		$state   = &hal_v02_state();
		$post_id = (int) ( $arr['ID'] ?? 0 );

		if ( ! isset( $state['posts'][ $post_id ] ) ) {
			return $wp_error ? new WP_Error( 'invalid_post', 'Invalid post ID.' ) : 0;
		}

		foreach ( $arr as $field => $value ) {
			if ( 'ID' === $field ) {
				continue;
			}
			$state['posts'][ $post_id ][ $field ] = $value;
		}

		return $post_id;
	}
}

if ( ! function_exists( 'hal_v02_seed_post' ) ) {
	/**
	 * Seeds one stub post row directly (used by the write-contract cases to
	 * control IDs and ownership).
	 *
	 * @param array<string, mixed> $row Post fields (ID required).
	 * @return int
	 */
	function hal_v02_seed_post( array $row ): int {
		$post_id = (int) ( $row['ID'] ?? 0 );

		if ( $post_id < 1 ) {
			return 0;
		}

		hal_v02_state()['posts'][ $post_id ] = $row;

		return $post_id;
	}
}

if ( ! function_exists( 'update_post_meta' ) ) {
	/** Sets one meta value. */
	function update_post_meta( int $post_id, string $meta_key, $meta_value ): bool {
		hal_v02_state()['meta'][ $post_id ][ $meta_key ] = [ $meta_value ];
		return true;
	}
}

if ( ! function_exists( 'get_post_meta' ) ) {
	/**
	 * Reads one meta value.
	 *
	 * @param int    $post_id Post ID.
	 * @param string $meta_key Meta key.
	 * @param bool   $single Single value.
	 * @return mixed
	 */
	function get_post_meta( int $post_id, string $meta_key, bool $single = false ) {
		$values = hal_v02_state()['meta'][ $post_id ][ $meta_key ] ?? [];

		return $single ? ( $values[0] ?? '' ) : $values;
	}
}

if ( ! function_exists( 'add_post_meta' ) ) {
	/**
	 * Adds meta; with $unique it fails when the key exists (lock semantics).
	 *
	 * @param int    $post_id Post ID.
	 * @param string $meta_key Meta key.
	 * @param mixed  $meta_value Meta value.
	 * @param bool   $unique Unique.
	 * @return bool
	 */
	function add_post_meta( int $post_id, string $meta_key, $meta_value, bool $unique = false ): bool {
		$state = &hal_v02_state();

		if ( $unique && isset( $state['meta'][ $post_id ][ $meta_key ] ) ) {
			return false;
		}

		$state['meta'][ $post_id ][ $meta_key ][] = $meta_value;
		return true;
	}
}

if ( ! function_exists( 'delete_post_meta' ) ) {
	/** Deletes a meta key. */
	function delete_post_meta( int $post_id, string $meta_key ): bool {
		unset( hal_v02_state()['meta'][ $post_id ][ $meta_key ] );
		return true;
	}
}

if ( ! function_exists( 'get_posts' ) ) {
	/**
	 * Filters the stub posts by post_type/post_status/author and the single
	 * meta_query clause the list function uses. Paging is not simulated (the
	 * data set is tiny); assertions are on membership, not pagination.
	 *
	 * @param array<string, mixed> $args Query args.
	 * @return array<int, WP_Post>
	 */
	function get_posts( array $args ): array {
		$state = &hal_v02_state();

		$hal_v02_found = [];

		foreach ( $state['posts'] as $hal_v02_row ) {
			if ( ( $args['post_type'] ?? '' ) !== $hal_v02_row['post_type'] ) {
				continue;
			}
			if ( isset( $args['author'] ) && (int) $args['author'] !== (int) $hal_v02_row['post_author'] ) {
				continue;
			}

			$hal_v02_clause = ( $args['meta_query'] ?? [] )[0] ?? null;

			if ( is_array( $hal_v02_clause ) ) {
				$hal_v02_actual = $state['meta'][ $hal_v02_row['ID'] ][ $hal_v02_clause['key'] ][0] ?? '';
				$hal_v02_want   = $hal_v02_clause['value'] ?? '';

				if ( 'IN' === ( $hal_v02_clause['compare'] ?? '=' ) ) {
					if ( ! in_array( $hal_v02_actual, (array) $hal_v02_want, true ) ) {
						continue;
					}
				} elseif ( $hal_v02_actual !== $hal_v02_want ) {
					continue;
				}
			}

			$hal_v02_found[] = new WP_Post( $hal_v02_row );
		}

		return $hal_v02_found;
	}
}

if ( ! function_exists( 'get_option' ) ) {
	/** Reads the stub options. */
	function get_option( string $option, $default = false ) {
		$hal_v02_options = hal_v02_state()['options'];

		return array_key_exists( $option, $hal_v02_options ) ? $hal_v02_options[ $option ] : $default;
	}
}

if ( ! function_exists( 'update_option' ) ) {
	/**
	 * Sets a stub option. The $autoload argument is recorded so the batch-6
	 * cases can assert the secrets/tests options are written non-autoloaded.
	 *
	 * @param string $option   Option name.
	 * @param mixed  $value    Value.
	 * @param mixed  $autoload Autoload flag as passed by the caller.
	 */
	function update_option( string $option, $value, $autoload = null ): bool {
		hal_v02_state()['options'][ $option ]    = $value;
		hal_v02_state()['updated_options'][ $option ] = $autoload;

		return true;
	}
}

if ( ! function_exists( 'current_time' ) ) {
	/** Fixed timestamp. */
	function current_time( string $type ): string {
		return '2026-09-13 12:00:00';
	}
}

if ( ! function_exists( 'wp_json_encode' ) ) {
	/** JSON encode wrapper. */
	function wp_json_encode( $value ) {
		return json_encode( $value );
	}
}

if ( ! function_exists( 'absint' ) ) {
	/** Non-negative int. */
	function absint( $value ): int {
		return abs( (int) $value );
	}
}

if ( ! function_exists( 'wp_unslash' ) ) {
	/** Identity unslash (the stub world carries no superglobal slashes). */
	function wp_unslash( $value ) {
		return $value;
	}
}

if ( ! function_exists( 'sanitize_text_field' ) ) {
	/** Strip tags, trim. */
	function sanitize_text_field( string $value ): string {
		return trim( strip_tags( $value ) );
	}
}

if ( ! function_exists( 'sanitize_key' ) ) {
	/** Lowercase alnum/dash/underscore. */
	function sanitize_key( string $value ): string {
		return preg_replace( '/[^a-z0-9_\-]/', '', strtolower( $value ) );
	}
}

if ( ! function_exists( '__' ) ) {
	/** Translation stub: identity. */
	function __( string $text, string $domain = 'default' ): string {
		return $text;
	}
}

/**
 * Minimal $wpdb stand-in: insert() records audit rows, get_var() answers the
 * SHOW TABLES check, get_results() returns the recorded rows.
 */
final class Hal_V02_Wpdb {

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
		hal_v02_state()['audit_rows'][] = $data;
		$this->insert_id                = count( hal_v02_state()['audit_rows'] );

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
		return hal_v02_state()['audit_rows'];
	}
}

$GLOBALS['wpdb'] = new Hal_V02_Wpdb();

if ( ! function_exists( 'wp_kses_post' ) ) {
	/** Passthrough: content sanitization depth is not what these cases probe. */
	function wp_kses_post( string $content ): string {
		return $content;
	}
}

if ( ! function_exists( 'sanitize_textarea_field' ) ) {
	/** Passthrough with trimming, matching the caller's expectation shape. */
	function sanitize_textarea_field( string $value ): string {
		return trim( $value );
	}
}

if ( ! function_exists( 'get_locale' ) ) {
	/** Locale from the stub state. */
	function get_locale(): string {
		return (string) hal_v02_state()['locale'];
	}
}

if ( ! function_exists( 'get_post_status' ) ) {
	/** Status of a stub post (ID or object). */
	function get_post_status( $post ): string {
		$hal_v02_id = $post instanceof WP_Post ? $post->ID : (int) $post;
		$hal_v02_row = hal_v02_state()['posts'][ $hal_v02_id ] ?? null;

		return is_array( $hal_v02_row ) ? (string) ( $hal_v02_row['post_status'] ?? '' ) : '';
	}
}

if ( ! function_exists( 'get_post_type' ) ) {
	/** Post type of a stub post (ID or object). */
	function get_post_type( $post ): ?string {
		$hal_v02_id = $post instanceof WP_Post ? $post->ID : (int) $post;
		$hal_v02_row = hal_v02_state()['posts'][ $hal_v02_id ] ?? null;

		return is_array( $hal_v02_row ) ? (string) ( $hal_v02_row['post_type'] ?? '' ) : null;
	}
}

if ( ! function_exists( 'get_edit_post_link' ) ) {
	/** Deterministic edit URL; $context 'raw' returns it unescaped. */
	function get_edit_post_link( int $post_id, string $context = 'display' ): ?string {
		return isset( hal_v02_state()['posts'][ $post_id ] )
			? 'http://example.org/wp-admin/post.php?post=' . $post_id . '&amp;action=edit'
			: null;
	}
}

if ( ! function_exists( 'wp_register_ability' ) ) {
	/** Records ability definitions so cases can invoke their callbacks. */
	function wp_register_ability( string $ability_name, array $args = [] ): bool {
		hal_v02_state()['registered_abilities'][ $ability_name ] = $args;
		return true;
	}
}

if ( ! function_exists( 'wp_register_ability_category' ) ) {
	/** Records nothing: categories are not under test in these cases. */
	function wp_register_ability_category( string $slug, array $args = [] ): bool {
		return true;
	}
}

if ( ! function_exists( 'is_user_logged_in' ) ) {
	/** Logged-in when the stub user has an ID. */
	function is_user_logged_in(): bool {
		return (int) hal_v02_state()['user']['id'] > 0;
	}
}

if ( ! function_exists( 'get_page_template_slug' ) ) {
	/** Page template from the stub meta. */
	function get_page_template_slug( int $post_id ): string {
		$hal_v02_value = get_post_meta( $post_id, '_wp_page_template', true );

		return is_string( $hal_v02_value ) ? $hal_v02_value : '';
	}
}

if ( ! function_exists( 'get_page_templates' ) ) {
	/** Theme-declared templates from the stub state. */
	function get_page_templates(): array {
		return (array) ( hal_v02_state()['page_templates'] ?? [] );
	}
}

if ( ! function_exists( 'get_post_thumbnail_id' ) ) {
	/** Thumbnail from the stub meta. */
	function get_post_thumbnail_id( int $post_id ): int {
		return (int) get_post_meta( $post_id, '_thumbnail_id', true );
	}
}

// ---------------------------------------------------------------------------
// Terms and post categories (the v2 list-field paths: 'categories' on posts
// and products resolve through get_term()/get_term_by() and persist through
// the wp_set_post_* functions).
// ---------------------------------------------------------------------------

if ( ! function_exists( 'hal_v02_seed_term' ) ) {
	/**
	 * Seeds one stub term row directly (used by the write-contract cases to
	 * control which category names exist).
	 *
	 * @param int    $id       Term ID.
	 * @param string $name     Term name.
	 * @param string $taxonomy Term taxonomy.
	 * @return int
	 */
	function hal_v02_seed_term( int $id, string $name, string $taxonomy = 'category' ): int {
		hal_v02_state()['terms'][ $id ] = [
			'term_id'  => $id,
			'name'     => $name,
			'taxonomy' => $taxonomy,
		];

		return $id;
	}
}

if ( ! function_exists( 'get_term' ) ) {
	/**
	 * Fetches a stub term by ID from the 'terms' registry.
	 *
	 * @param int    $id       Term ID.
	 * @param string $taxonomy Taxonomy the caller expects.
	 * @return WP_Term|null null when the term is not seeded.
	 */
	function get_term( int $id, string $taxonomy = 'category' ): ?WP_Term {
		$hal_v02_row = hal_v02_state()['terms'][ $id ] ?? null;

		if ( null === $hal_v02_row ) {
			return null;
		}

		$hal_v02_row['taxonomy'] = (string) ( $hal_v02_row['taxonomy'] ?? $taxonomy );

		return new WP_Term( $hal_v02_row );
	}
}

if ( ! function_exists( 'get_term_by' ) ) {
	/**
	 * Fetches a stub term by field ('name' is the only field the domain
	 * resolvers use), within one taxonomy.
	 *
	 * @param string $field    Lookup field.
	 * @param mixed  $value    Field value.
	 * @param string $taxonomy Taxonomy to search.
	 * @return WP_Term|false
	 */
	function get_term_by( string $field, $value, string $taxonomy = 'category' ) {
		if ( 'name' !== $field ) {
			return false;
		}

		foreach ( hal_v02_state()['terms'] as $hal_v02_row ) {
			if ( (string) ( $hal_v02_row['name'] ?? '' ) === (string) $value
				&& (string) ( $hal_v02_row['taxonomy'] ?? '' ) === $taxonomy ) {
				return new WP_Term( $hal_v02_row );
			}
		}

		return false;
	}
}

if ( ! function_exists( 'wp_get_post_categories' ) ) {
	/**
	 * Categories of a stub post from the 'posts_terms' registry (the
	 * 'categories' snapshot read in hal_mcp_post_read_current()).
	 *
	 * @param int $post_id Post ID.
	 * @return array<int, int>
	 */
	function wp_get_post_categories( int $post_id ): array {
		return array_map( 'intval', (array) ( hal_v02_state()['posts_terms'][ $post_id ] ?? [] ) );
	}
}

if ( ! function_exists( 'wp_set_post_categories' ) ) {
	/**
	 * Writes a stub post's categories.
	 *
	 * @param int               $post_id    Post ID.
	 * @param array<int, mixed> $categories Category IDs.
	 * @return bool
	 */
	function wp_set_post_categories( int $post_id, array $categories = [] ): bool {
		hal_v02_state()['posts_terms'][ $post_id ] = array_map( 'intval', $categories );

		return true;
	}
}

if ( ! function_exists( 'wp_get_post_terms' ) ) {
	/**
	 * Stub post terms per taxonomy; 'fields' => 'ids'/'names' shapes the
	 * return exactly as core does.
	 *
	 * @param int                  $post_id  Post ID.
	 * @param string               $taxonomy Taxonomy slug.
	 * @param array<string, mixed> $args     Query args ('fields').
	 * @return array<int, mixed>
	 */
	function wp_get_post_terms( int $post_id, string $taxonomy, array $args = [] ): array {
		$hal_v02_rows = [];

		foreach ( (array) ( hal_v02_state()['posts_terms'][ $post_id ] ?? [] ) as $hal_v02_term_id ) {
			$hal_v02_term_id = (int) $hal_v02_term_id;
			$hal_v02_row     = hal_v02_state()['terms'][ $hal_v02_term_id ] ?? null;

			if ( null === $hal_v02_row || (string) ( $hal_v02_row['taxonomy'] ?? '' ) !== $taxonomy ) {
				continue;
			}

			$hal_v02_rows[] = new WP_Term( $hal_v02_row );
		}

		$hal_v02_fields = (string) ( $args['fields'] ?? '' );

		if ( 'ids' === $hal_v02_fields ) {
			return array_map( static fn( $hal_v02_term ) => $hal_v02_term->term_id, $hal_v02_rows );
		}

		if ( 'names' === $hal_v02_fields ) {
			return array_map( static fn( $hal_v02_term ) => $hal_v02_term->name, $hal_v02_rows );
		}

		return $hal_v02_rows;
	}
}

if ( ! function_exists( 'wp_set_post_terms' ) ) {
	/**
	 * Writes a stub post's terms for one taxonomy.
	 *
	 * @param int               $post_id  Post ID.
	 * @param array<int, mixed> $term_ids Term IDs.
	 * @param string            $taxonomy Taxonomy slug.
	 * @return bool
	 */
	function wp_set_post_terms( int $post_id, array $term_ids = [], string $taxonomy = 'category' ): bool {
		hal_v02_state()['posts_terms'][ $post_id ] = array_map( 'intval', $term_ids );

		return true;
	}
}

// Load the plugin modules exactly as the internal bootstrap would.
require_once __DIR__ . '/../../hal-mcp-abilities/includes/permissions.php';
require_once __DIR__ . '/../../hal-mcp-abilities/includes/audit-log.php';
require_once __DIR__ . '/../../hal-mcp-abilities/includes/change-requests.php';
require_once __DIR__ . '/../../hal-mcp-abilities/includes/integrations.php';

// The ability files depend on environment.php's language surface; this stub
// world does not load that module, so a minimal locale-only equivalent is
// provided here (guarded: real WordPress loads the real definitions first).
if ( ! function_exists( 'hal_mcp_supported_languages' ) ) {
	/** Locale-only language surface for the stub world. */
	function hal_mcp_supported_languages(): array {
		return [ (string) hal_v02_state()['locale'] ];
	}
}

if ( ! function_exists( 'hal_mcp_language_is_supported' ) ) {
	/** Supported when the trimmed code equals the stub locale. */
	function hal_mcp_language_is_supported( string $language ): bool {
		$language = trim( $language );

		return '' !== $language && in_array( $language, hal_mcp_supported_languages(), true );
	}
}

if ( ! function_exists( 'hal_mcp_validate_read_language' ) ) {
	/**
	 * Locale-only validation for the stub world (the real definition lives
	 * in includes/environment.php): null for absent/empty/site-locale input,
	 * WP_Error for anything else.
	 *
	 * @param array<string, mixed> $input Ability input.
	 * @return WP_Error|null
	 */
	function hal_mcp_validate_read_language( array $input ): ?WP_Error {

		if ( ! array_key_exists( 'language', $input ) ) {
			return null;
		}

		$language = trim( (string) $input['language'] );

		if ( '' === $language ) {
			return null;
		}

		if ( ! hal_mcp_language_is_supported( $language ) ) {
			return new WP_Error(
				'hal_mcp_language_unsupported',
				sprintf( 'The language "%s" is not available on this site.', $language )
			);
		}

		return null;
	}
}
