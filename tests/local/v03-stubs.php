<?php
/**
 * In-memory WordPress stubs for tests/local/providers.php (V03, batch 6).
 *
 * Builds on v02-stubs.php (the F05/F06/F17 world: posts, meta, options, caps,
 * audit log) and adds exactly what the batch-6 modules touch: the HTTP
 * transport (scripted wp_safe_remote_* responses + scripted DNS), the
 * Abilities API read side (wp_get_ability/wp_get_abilities over registered
 * stub abilities), nonce verification, REST route registration, and a couple
 * of admin-only helpers.
 *
 * No site, no database, no network: every "provider call" is answered from a
 * scripted queue, and DNS answers come from a scripted map.
 *
 * @package hal-mcp-abilities
 */

require_once __DIR__ . '/v02-stubs.php';

/*
 * The batch-6 modules themselves, in bootstrap order (functions only — every
 * side effect is inside hooked callbacks, so requiring them here is safe
 * before reset runs).
 */
require_once __DIR__ . '/../../hal-mcp-abilities/includes/http-client.php';
require_once __DIR__ . '/../../hal-mcp-abilities/includes/providers.php';
require_once __DIR__ . '/../../hal-mcp-abilities/includes/settings.php';
require_once __DIR__ . '/../../hal-mcp-abilities/includes/runner.php';

// Defined by wp-includes/formatting.php / functions.php in WordPress; the
// settings and admin modules read them.
if ( ! defined( 'MINUTE_IN_SECONDS' ) ) {
	define( 'MINUTE_IN_SECONDS', 60 );
}

// ---------------------------------------------------------------------------
// Batch-6 stub state
// ---------------------------------------------------------------------------

/**
 * Extra state for the batch-6 stubs: scripted HTTP responses, recorded HTTP
 * calls, scripted DNS, scripted nonce validity, and recorded REST routes.
 *
 * @return array<string, mixed>
 */
function &hal_v03_state(): array {
	static $state = [
		'http_queue'    => [],
		'http_calls'    => [],
		'dns'           => [],
		'nonce_valid'   => true,
		'rest_routes'   => [],
	];

	return $state;
}

/**
 * Resets the batch-6 stub layer (call AFTER hal_v02_reset_stubs()).
 *
 * @return void
 */
function hal_v03_reset_stubs(): void {
	$state                  = &hal_v03_state();
	$state['http_queue']    = [];
	$state['http_calls']    = [];
	$state['dns']           = [];
	$state['nonce_valid']   = true;
	$state['rest_routes']   = [];

	hal_v03_dns_filter_registered();
	unset( $_SERVER['HTTP_X_WP_NONCE'] );
}

/**
 * Queues one scripted HTTP response (or a transport failure).
 *
 * @param array{transport_error?: string, status?: int, headers?: array, body?: string} $response Response.
 * @return void
 */
function hal_v03_queue_http( array $response ): void {
	hal_v03_state()['http_queue'][] = $response;
}

/**
 * All recorded HTTP calls (url + full args), in order.
 *
 * @return array<int, array{url: string, args: array}>
 */
function hal_v03_http_calls(): array {
	return hal_v03_state()['http_calls'];
}

/**
 * Scripts DNS answers for one host.
 *
 * @param string        $host Host name.
 * @param string[]|null $ips  IPs (null = default public answer).
 * @return void
 */
function hal_v03_set_dns( string $host, ?array $ips = null ): void {
	hal_v03_state()['dns'][ strtolower( $host ) ] = $ips ?? [ '93.184.216.34' ];
}

/**
 * A full provider config for the stub world: one endpoint override pointing
 * at a scripted host, a fixed model, and a real (locally encrypted) key.
 *
 * @param string $protocol Protocol slug.
 * @return array<string, mixed>
 */
function hal_v03_provider_config( string $protocol ): array {
	return [
		'id'       => 'openai',
		'label'    => 'OpenAI',
		'protocol' => $protocol,
		'endpoint' => 'https://provider.test/v1/responses',
		'auth'     => 'bearer',
		'model'    => 'test-model-1',
		'api_key'  => 'sk-test-1234567890abcdef',
		'max_output' => [ 'max_output_tokens', 2048, 32768 ],
		'temperature' => true,
		'timeout'  => 30.0,
	];
}

// ---------------------------------------------------------------------------
// WordPress function stubs (batch-6 surface)
// ---------------------------------------------------------------------------

if ( ! function_exists( 'wp_safe_remote_post' ) ) {
	/**
	 * Records the call and answers from the scripted queue (or a transport
	 * error when the queue entry says so). NEVER touches the network.
	 *
	 * @param string $url  URL.
	 * @param array  $args Request args.
	 * @return array|WP_Error
	 */
	function wp_safe_remote_post( string $url, array $args = [] ) {
		return hal_v03_http_answer( $url, $args );
	}
}

if ( ! function_exists( 'wp_safe_remote_get' ) ) {
	/**
	 * Same as the POST stub.
	 *
	 * @param string $url  URL.
	 * @param array  $args Request args.
	 * @return array|WP_Error
	 */
	function wp_safe_remote_get( string $url, array $args = [] ) {
		return hal_v03_http_answer( $url, $args );
	}
}

/**
 * Shared answer logic for both HTTP stubs.
 *
 * @param string $url  URL.
 * @param array  $args Request args.
 * @return array|WP_Error
 */
function hal_v03_http_answer( string $url, array $args ) {

	hal_v03_state()['http_calls'][] = [ 'url' => $url, 'args' => $args ];

	$hal_v03_next = array_shift( hal_v03_state()['http_queue'] );

	if ( null === $hal_v03_next ) {
		return new WP_Error( 'http_request_failed', 'no scripted response queued' );
	}

	if ( ! empty( $hal_v03_next['transport_error'] ) ) {
		return new WP_Error( 'http_request_failed', (string) $hal_v03_next['transport_error'] );
	}

	return [
		'response' => [
			'code'    => (int) ( $hal_v03_next['status'] ?? 200 ),
			'message' => 'OK',
		],
		'headers' => (array) ( $hal_v03_next['headers'] ?? [] ),
		'body'    => (string) ( $hal_v03_next['body'] ?? '' ),
	];
}

if ( ! function_exists( 'wp_remote_retrieve_response_code' ) ) {
	/** Response code from the stub envelope. */
	function wp_remote_retrieve_response_code( $response ): int {
		return is_array( $response ) ? (int) ( $response['response']['code'] ?? 0 ) : 0;
	}
}

if ( ! function_exists( 'wp_remote_retrieve_body' ) ) {
	/** Body from the stub envelope. */
	function wp_remote_retrieve_body( $response ): string {
		return is_array( $response ) ? (string) ( $response['body'] ?? '' ) : '';
	}
}

if ( ! function_exists( 'wp_remote_retrieve_headers' ) ) {
	/** Headers from the stub envelope. */
	function wp_remote_retrieve_headers( $response ) {
		return is_array( $response ) ? ( $response['headers'] ?? [] ) : [];
	}
}

if ( ! function_exists( 'hal_v03_dns_filter' ) ) {
	/**
	 * The hal_mcp_http_dns_answers filter implementation: the scripted map
	 * wins, unknown hosts fall through (null), and an empty scripted answer
	 * means unresolvable. (Answers are still public-checked by the transport
	 * — this only narrows.)
	 *
	 * @param mixed  $pre  Pre-answered value (null → consult the map).
	 * @param string $host Host being resolved.
	 * @return string[]|null
	 */
function hal_v03_dns_filter( $pre, string $host ) {
	unset( $pre );

	$hal_v03_dns = hal_v03_state()['dns'];

	if ( array_key_exists( strtolower( $host ), $hal_v03_dns ) ) {
		return $hal_v03_dns[ strtolower( $host ) ];
	}

	// Default scripted answer: a public IP, so provider.test works without
	// touching the real network.
	return [ '93.184.216.34' ];
}
}

if ( ! function_exists( 'hal_v03_dns_filter_registered' ) ) {
	/**
	 * Registers the DNS filter once per process (called by hal_v03_set_dns).
	 *
	 * @return void
	 */
	function hal_v03_dns_filter_registered(): void {
		static $hal_v03_done = false;

		if ( ! $hal_v03_done ) {
			add_filter( 'hal_mcp_http_dns_answers', 'hal_v03_dns_filter' );
			$hal_v03_done = true;
		}
	}
}

if ( ! function_exists( 'wp_salt' ) ) {
	/** Deterministic salt for the secret-key derivation. */
	function wp_salt( string $scheme = 'auth' ): string {
		return 'test-salt-' . $scheme;
	}
}

if ( ! function_exists( 'wp_verify_nonce' ) ) {
	/** Scripted nonce validity. */
	function wp_verify_nonce( string $nonce, string|int $action = -1 ): bool {
		unset( $action );

		return (bool) hal_v03_state()['nonce_valid'] && '' !== $nonce;
	}
}

if ( ! function_exists( 'register_rest_route' ) ) {
	/** Records the route for assertions. */
	function register_rest_route( string $namespace, string $route, array $args = [] ): void {
		hal_v03_state()['rest_routes'][ $namespace . $route ] = $args;
	}
}

if ( ! function_exists( 'rest_ensure_response' ) ) {
	/** Identity wrapper for handler tests. */
	function rest_ensure_response( $data ) {
		return $data;
	}
}

if ( ! function_exists( 'number_format_i18n' ) ) {
	/** Plain number formatting. */
	function number_format_i18n( $number, $decimals = 0 ): string {
		return number_format( (float) $number, (int) $decimals, '.', '' );
	}
}

if ( ! function_exists( 'date_i18n' ) ) {
	/** Deterministic date formatting for the overview render path. */
	function date_i18n( string $format, $timestamp = null ): string {
		return date( $format, $timestamp ?? time() );
	}
}

if ( ! function_exists( 'admin_url' ) ) {
	/**
	 * Deterministic admin URL for the stub world (same example.org base the
	 * v02 get_edit_post_link() stub uses).
	 *
	 * @param string $path  Path relative to wp-admin/.
	 * @param string $scheme Ignored (the stub world has no schemes).
	 * @return string
	 */
	function admin_url( string $path = '', string $scheme = 'admin' ): string {
		unset( $scheme );

		return 'http://example.org/wp-admin/' . ltrim( $path, '/' );
	}
}

if ( ! function_exists( 'add_query_arg' ) ) {
	/**
	 * Appends a query string to a URL (both the array+url form and the
	 * key/value/url form WordPress supports; the admin surface uses the
	 * array+url form).
	 *
	 * @param mixed ...$args ( array $args, string $url ) or ( string $key,
	 *                       mixed $value, string $url ).
	 * @return string
	 */
	function add_query_arg( ...$args ): string {
		$hal_v03_query = '';
		$hal_v03_url   = '';

		if ( isset( $args[0] ) && is_array( $args[0] ) ) {
			if ( ! isset( $args[1] ) || ! is_string( $args[1] ) ) {
				return '';
			}

			$hal_v03_query = http_build_query( $args[0] );
			$hal_v03_url   = $args[1];
		} elseif ( isset( $args[0], $args[1], $args[2] ) && is_string( $args[2] ) ) {
			$hal_v03_query = http_build_query( [ (string) $args[0] => $args[1] ] );
			$hal_v03_url   = $args[2];
		}

		if ( '' === $hal_v03_url || '' === $hal_v03_query ) {
			return $hal_v03_url;
		}

		return $hal_v03_url . ( str_contains( $hal_v03_url, '?' ) ? '&' : '?' ) . $hal_v03_query;
	}
}

if ( ! class_exists( 'Hal_V03_Rest_Request' ) ) {
	/**
	 * Minimal WP_REST_Request stand-in for direct admin-handler tests: the
	 * handlers only read params (and the decision handler's route), so the
	 * stub carries an immutable params map and nothing else.
	 */
	final class Hal_V03_Rest_Request {

		/** @var array<string, mixed> */
		private $params = [];

		/**
		 * Constructor.
		 *
		 * @param array<string, mixed> $params Param map ( '__route' seeds
		 *                                             get_route()).
		 */
		public function __construct( array $params = [] ) {
			$this->params = $params;
		}

		/**
		 * @param string $key Param name.
		 * @return mixed Null when absent, like WP_REST_Request.
		 */
		public function get_param( string $key ) {
			return $this->params[ $key ] ?? null;
		}

		/**
		 * @return string The route the request was constructed with ('' by
		 *                default — only the decision handler reads it).
		 */
		public function get_route(): string {
			return (string) ( $this->params['__route'] ?? '' );
		}
	}
}

// ---------------------------------------------------------------------------
// Abilities API read side (wp_get_ability / wp_get_abilities)
// ---------------------------------------------------------------------------

if ( ! class_exists( 'Hal_V03_Ability' ) ) {
	/**
	 * Minimal WP_Ability stand-in: execute() runs the permission callback,
	 * then the execute callback — the same order WP_Ability::execute() uses
	 * (input validation is the ability's own job here).
	 */
	final class Hal_V03_Ability {

		/** @var string */
		public $name = '';

		/** @var array<string, mixed> */
		public $args = [];

		/**
		 * Constructor.
		 *
		 * @param string $name Ability name.
		 * @param array  $args Registration args.
		 */
		public function __construct( string $name, array $args ) {
			$this->name = $name;
			$this->args = $args;
		}

		/**
		 * @return string
		 */
		public function get_name(): string {
			return $this->name;
		}

		/**
		 * @return string
		 */
		public function get_description(): string {
			return (string) ( $this->args['description'] ?? '' );
		}

		/**
		 * @return array
		 */
		public function get_meta(): array {
			return (array) ( $this->args['meta'] ?? [] );
		}

		/**
		 * @return array
		 */
		public function get_input_schema(): array {
			return (array) ( $this->args['input_schema'] ?? [] );
		}

		/**
		 * The execution path the runner relies on.
		 *
		 * STUB HONESTY: this does NOT simulate the real WP_Ability::execute()
		 * contract. Input validation is NOT performed (the real API validates
		 * the input against the ability's input schema), and the
		 * wp_before_execute_ability / wp_after_execute_ability hooks are NOT
		 * fired — execute() here runs the permission callback and then the
		 * execute callback, nothing else. Tests written against this stub
		 * therefore prove the ORDER of those two steps only; validation
		 * behavior and hook-driven behavior (audit rows included) are out of
		 * this stub's scope.
		 *
		 * @param mixed $input Input.
		 * @return mixed|WP_Error
		 */
		public function execute( $input = null ) {

			$hal_v03_permission = $this->args['permission_callback'] ?? null;

			if ( is_callable( $hal_v03_permission ) && true !== call_user_func( $hal_v03_permission, $input ) ) {
				return new WP_Error( 'ability_invalid_permissions', 'permission denied' );
			}

			return call_user_func( $this->args['execute_callback'], $input );
		}
	}
}

if ( ! function_exists( 'wp_get_ability' ) ) {
	/**
	 * Looks up a registered stub ability.
	 *
	 * @param string $name Ability name.
	 * @return Hal_V03_Ability|null
	 */
	function wp_get_ability( string $name ): ?Hal_V03_Ability {
		$hal_v03_args = hal_v02_state()['registered_abilities'][ $name ] ?? null;

		return null === $hal_v03_args ? null : new Hal_V03_Ability( $name, $hal_v03_args );
	}
}

if ( ! function_exists( 'wp_get_abilities' ) ) {
	/**
	 * All registered stub abilities.
	 *
	 * @return Hal_V03_Ability[]
	 */
	function wp_get_abilities(): array {
		$hal_v03_out = [];

		foreach ( hal_v02_state()['registered_abilities'] as $hal_v03_name => $hal_v03_args ) {
			$hal_v03_out[] = new Hal_V03_Ability( $hal_v03_name, $hal_v03_args );
		}

		return $hal_v03_out;
	}
}
