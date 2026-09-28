<?php
/**
 * Local test runner for providers, transport, runner, settings, and admin
 * gates (V03 — batch 6 of the v2.0.0 execution roadmap §9, covering F15/F16,
 * F18, and the F19 server contracts).
 *
 * Per protocol: tool-name conversion, request building, response parsing,
 * IDs and names preserved, malformed responses refused, no key leakage.
 * Transport: SSRF/endpoint guards, status mapping, size caps, no retries.
 * Runner: bounded loop, pending_approval stop, resume rules, cancel, gates,
 * the per-run execution lock, and the per-segment wall-clock resume.
 * Settings: encrypted secrets (no plaintext fallback), strict sanitize.
 * Admin: REST route set + session-nonce decision gate.
 *
 * Pure in-memory WordPress stubs — no site, no database, no network. Each
 * case runs in its own PHP subprocess, mirroring tests/local/run.php.
 *
 * Usage:
 *   php tests/local/providers.php              # run every case
 *   php tests/local/providers.php --case=NAME  # run one case
 *
 * Exit code 0 = all requested cases passed.
 *
 * @package hal-mcp-abilities
 */

if ( PHP_SAPI !== 'cli' ) {
	exit( "tests/local/providers.php must run from the CLI only.\n" );
}

const HAL_TEST_CASE_OK = 'HAL_TEST_CASE_OK';

// ---------------------------------------------------------------------------
// Case list and orchestration
// ---------------------------------------------------------------------------

$hal_test_cases = [
	'provider_registry_shape',
	'tool_name_conversion',
	'protocol_builders',
	'protocol_parsers',
	'http_endpoint_guards',
	'http_transport',
	'settings_secrets_and_sanitize',
	'runner_loop_and_pending_stop',
	'runner_gates',
	'runner_resume_time_segment',
	'runner_lock_exclusive_resume',
	'admin_rest_routes_and_gate',
	'admin_approval_detail_editor_url',
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

	require_once __DIR__ . '/v03-stubs.php';
	hal_v02_reset_stubs();
	hal_v03_reset_stubs();

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
 * The registry: six providers + custom, valid protocols, HTTPS endpoints,
 * capability flags — and never a secret field.
 */
function hal_test_case_provider_registry_shape(): void {

	$hal_test_providers = hal_mcp_providers();

	hal_test_assert(
		array_keys( $hal_test_providers ) === [ 'openai', 'anthropic', 'gemini', 'glm', 'kimi', 'qwen', 'custom' ],
		'the registry must hold exactly the six roadmap providers plus custom, in order'
	);

	foreach ( $hal_test_providers as $hal_test_id => $hal_test_provider ) {
		hal_test_assert( ! array_key_exists( 'api_key', $hal_test_provider ), "{$hal_test_id}: the registry must not carry a secret field" );

		if ( 'custom' !== $hal_test_id ) {
			hal_test_assert( in_array( $hal_test_provider['protocol'], HAL_MCP_PROVIDER_PROTOCOLS, true ), "{$hal_test_id}: protocol must be one of the four supported" );
			hal_test_assert( str_starts_with( (string) $hal_test_provider['endpoint'], 'https://' ), "{$hal_test_id}: endpoint must be HTTPS" );
			hal_test_assert( '' !== (string) $hal_test_provider['default_model'], "{$hal_test_id}: a concrete default model must be present" );
			hal_test_assert( ! str_contains( (string) $hal_test_provider['default_model'], 'latest' ), "{$hal_test_id}: the default model must not be a 'latest' alias" );
		}

		hal_test_assert( isset( $hal_test_provider['auth'], $hal_test_provider['max_output'] ), "{$hal_test_id}: auth mode and max_output must be declared" );
	}
}

/**
 * Deterministic tool-name conversion per protocol, with the reverse map
 * refusing names that do not round-trip.
 */
function hal_test_case_tool_name_conversion(): void {

	hal_test_assert( 'hal__get-post' === hal_mcp_provider_tool_name( 'hal/get-post', 'openai-responses' ), 'openai-responses: / becomes __, dashes kept' );
	hal_test_assert( 'hal/get-post' === hal_mcp_provider_tool_ability( 'hal__get-post', 'openai-responses' ), 'openai-responses: the name must round-trip' );

	hal_test_assert( 'hal__get_post' === hal_mcp_provider_tool_name( 'hal/get-post', 'gemini-native' ), 'gemini: / becomes __ and dashes become _' );
	hal_test_assert( 'hal/get-post' === hal_mcp_provider_tool_ability( 'hal__get_post', 'gemini-native' ), 'gemini: the name must round-trip' );

	// A crafted name that does not round-trip is refused, never guessed.
	hal_test_assert( '' === hal_mcp_provider_tool_ability( 'hal__get__post', 'gemini-native' ), 'gemini: a non-round-tripping name must be refused' );
	hal_test_assert( '' === hal_mcp_provider_tool_ability( 'totally_unknown', 'openai-chat' ), 'a non-hal tool name must map to nothing' );
	hal_test_assert( '' === hal_mcp_provider_tool_name( 'vendor/thing', 'openai-chat' ), 'a non-hal ability name must convert to nothing' );
	hal_test_assert( 'hal__x' === hal_mcp_provider_tool_name( 'hal/x', 'openai-chat' ), 'a minimal hal name converts like any other' );
}

/**
 * Saves settings the way options.php does: sanitize, then the settings
 * option is written with the sanitized result.
 *
 * @param array $input Raw input.
 * @return array The sanitized settings (as stored).
 */
function hal_v03_save_settings( array $input ): array {
	$hal_v03_clean = hal_mcp_settings_sanitize( $input );
	update_option( HAL_MCP_SETTINGS_OPTION, $hal_v03_clean, false );

	return $hal_v03_clean;
}

/**
 * Per-protocol request building: shapes, tool nesting, auth headers, and the
 * parameter rules (temperature only when supported AND configured; the
 * output-limit field only when configured or required).
 */
function hal_test_case_protocol_builders(): void {

	$hal_test_tools = [
		'hal__get-post' => [
			'ability'     => 'hal/get-post',
			'description' => 'Get a single post',
			'parameters'  => [
				'type'                 => 'object',
				'properties'           => [ 'post_id' => [ 'type' => 'integer', 'minimum' => 1 ] ],
				'required'             => [ 'post_id' ],
				'additionalProperties' => false,
			],
		],
	];

	$hal_test_messages = [ [ 'role' => 'user', 'text' => 'Read post 5.' ] ];

	// openai-responses
	$hal_test_config                    = hal_v03_provider_config( 'openai-responses' );
	$hal_test_body                      = hal_mcp_provider_build_openai_responses( $hal_test_config, [ 'messages' => $hal_test_messages, 'tools' => $hal_test_tools ] );
	hal_test_assert( 'test-model-1' === $hal_test_body['model'] && false === $hal_test_body['store'], 'responses: model + store=false must be set' );
	hal_test_assert( 'function' === $hal_test_body['tools'][0]['type'] && 'hal__get-post' === $hal_test_body['tools'][0]['name'], 'responses: tools must be flat function objects with the converted name' );
	hal_test_assert( 'input_text' === $hal_test_body['input'][0]['content'][0]['type'], 'responses: user text must be an input_text item' );
	hal_test_assert( ! isset( $hal_test_body['temperature'] ), 'responses: temperature must be absent when not configured' );
	hal_test_assert( isset( $hal_test_body['max_output_tokens'] ), 'responses: the output-limit field must carry the default when nothing is configured' );

	// openai-chat
	$hal_test_config = hal_v03_provider_config( 'openai-chat' );
	$hal_test_body   = hal_mcp_provider_build_openai_chat( $hal_test_config, [ 'messages' => $hal_test_messages, 'tools' => $hal_test_tools ] );
	hal_test_assert( isset( $hal_test_body['messages'][0]['role'], $hal_test_body['messages'][0]['content'] ), 'chat: user message shape' );
	hal_test_assert( 'hal__get-post' === $hal_test_body['tools'][0]['function']['name'], 'chat: tools nested under function with the converted name' );

	// An assistant message carrying BOTH text and tool_calls must emit ONE
	// assistant message — the text becomes its content, never a duplicated
	// standalone text entry before it.
	$hal_test_chat_both = hal_mcp_provider_openai_chat_messages(
		[
			[ 'role' => 'assistant', 'text' => 'Checking.', 'tool_calls' => [ [ 'id' => 'call_1', 'name' => 'hal/get-post', 'arguments' => [ 'post_id' => 5 ] ] ] ],
		]
	);
	hal_test_assert( 1 === count( $hal_test_chat_both ), 'chat: an assistant message with text AND tool_calls must emit exactly ONE message' );
	hal_test_assert( 'assistant' === $hal_test_chat_both[0]['role'] && 'Checking.' === $hal_test_chat_both[0]['content'], 'chat: the single assistant message carries the text as its content' );
	hal_test_assert( 'call_1' === $hal_test_chat_both[0]['tool_calls'][0]['id'] && 'hal__get-post' === $hal_test_chat_both[0]['tool_calls'][0]['function']['name'], 'chat: the single assistant message carries the tool_calls array' );

	// anthropic-messages: max_tokens REQUIRED; continuation shape.
	$hal_test_config = hal_v03_provider_config( 'anthropic-messages' );
	$hal_test_body   = hal_mcp_provider_build_anthropic( $hal_test_config, [ 'messages' => $hal_test_messages, 'tools' => $hal_test_tools ] );
	hal_test_assert( isset( $hal_test_body['max_tokens'] ) && $hal_test_body['max_tokens'] >= 16, 'anthropic: max_tokens is always present' );
	hal_test_assert( 'hal__get-post' === $hal_test_body['tools'][0]['name'] && isset( $hal_test_body['tools'][0]['input_schema'] ), 'anthropic: tools carry input_schema' );

	$hal_test_continuation = [
		[ 'role' => 'user', 'text' => 'Read post 5.' ],
		[ 'role' => 'assistant', 'text' => '', 'tool_calls' => [ [ 'id' => 'toolu_1', 'name' => 'hal/get-post', 'arguments' => [ 'post_id' => 5 ] ] ] ],
		[ 'role' => 'user', 'text' => '', 'tool_results' => [ [ 'call_id' => 'toolu_1', 'name' => 'hal/get-post', 'content' => '{"title":"Hello"}', 'is_error' => false ] ] ],
	];
	$hal_test_body = hal_mcp_provider_build_anthropic( $hal_test_config, [ 'messages' => $hal_test_continuation, 'tools' => [] ] );
	hal_test_assert( 'tool_use' === $hal_test_body['messages'][1]['content'][0]['type'] && 'toolu_1' === $hal_test_body['messages'][1]['content'][0]['id'], 'anthropic: the assistant message carries the tool_use block with its id' );
	hal_test_assert( 'tool_result' === $hal_test_body['messages'][2]['content'][0]['type'] && 'toolu_1' === $hal_test_body['messages'][2]['content'][0]['tool_use_id'], 'anthropic: tool_result follows in a user message with the same id' );

	// gemini-native: schema normalization + endpoint substitution. The tool
	// map arrives with gemini-safe keys (the runner builds it per protocol).
	$hal_test_gemini_tools = [
		'hal__get_post' => [
			'ability'     => 'hal/get-post',
			'description' => 'Get a single post',
			'parameters'  => [
				'type'                 => 'object',
				'properties'           => [ 'post_id' => [ 'type' => 'integer', 'minimum' => 1 ] ],
				'required'             => [ 'post_id' ],
				'additionalProperties' => false,
			],
		],
	];
	$hal_test_config                  = hal_v03_provider_config( 'gemini-native' );
	$hal_test_config['endpoint']      = 'https://generativelanguage.googleapis.com/v1beta/models/{model}:generateContent';
	$hal_test_body                    = hal_mcp_provider_build_gemini( $hal_test_config, [ 'messages' => $hal_test_messages, 'tools' => $hal_test_gemini_tools ] );
	hal_test_assert( 'hal__get_post' === $hal_test_body['tools'][0]['functionDeclarations'][0]['name'], 'gemini: declaration uses the gemini-safe name' );
	$hal_test_params = $hal_test_body['tools'][0]['functionDeclarations'][0]['parameters'];
	hal_test_assert( 'OBJECT' === $hal_test_params['type'], 'gemini: the schema type must be upper-cased' );
	hal_test_assert( ! array_key_exists( 'additionalProperties', $hal_test_params ), 'gemini: additionalProperties must be stripped' );

	$hal_test_endpoint = hal_mcp_provider_endpoint( $hal_test_config );
	hal_test_assert( is_string( $hal_test_endpoint ) && str_contains( $hal_test_endpoint, 'models/test-model-1:generateContent' ), 'gemini: the endpoint template must substitute the model' );

	// Gemini alternation: tool results followed by resumed user text must
	// merge into ONE user entry (Gemini rejects consecutive user roles).
	$hal_test_gemini_body = hal_mcp_provider_build_gemini(
		$hal_test_config,
		[
			'messages' => [
				[ 'role' => 'user', 'text' => 'Read post 5.' ],
				[ 'role' => 'assistant', 'text' => '', 'tool_calls' => [ [ 'id' => 'g1', 'name' => 'hal/get-post', 'arguments' => [ 'post_id' => 5 ] ] ] ],
				[ 'role' => 'user', 'text' => '', 'tool_results' => [ [ 'call_id' => 'g1', 'name' => 'hal/get-post', 'content' => '{}', 'is_error' => false ] ] ],
				[ 'role' => 'user', 'text' => 'Now also fix the title.' ],
			],
			'tools' => [],
		]
	);

	hal_test_assert( 3 === count( $hal_test_gemini_body['contents'] ), 'gemini: consecutive user messages must merge into one contents entry' );
	hal_test_assert( 'user' === $hal_test_gemini_body['contents'][2]['role'], 'gemini: the merged entry keeps the user role' );
	hal_test_assert( isset( $hal_test_gemini_body['contents'][2]['parts'][0]['functionResponse'], $hal_test_gemini_body['contents'][2]['parts'][1]['text'] ), 'gemini: the merged entry carries the function response AND the resumed text' );

	// Auth headers per mode.
	hal_test_assert( 'Bearer sk-test-1234567890abcdef' === hal_mcp_provider_auth_headers( 'bearer', 'sk-test-1234567890abcdef' )['Authorization'], 'bearer auth header' );
	$hal_test_anthropic_headers = hal_mcp_provider_auth_headers( 'x-api-key', 'k' );
	hal_test_assert( 'k' === $hal_test_anthropic_headers['x-api-key'] && isset( $hal_test_anthropic_headers['anthropic-version'] ), 'anthropic: key header + version header' );
	hal_test_assert( 'k' === hal_mcp_provider_auth_headers( 'x-goog-api-key', 'k' )['x-goog-api-key'], 'gemini: key header' );
	hal_test_assert( [] === hal_mcp_provider_auth_headers( 'bearer', '' ), 'an empty key yields no auth header at all' );
}

/**
 * Per-protocol response parsing: text, multiple calls with ids preserved,
 * malformed responses, provider errors, and unknown-tool refusal.
 */
function hal_test_case_protocol_parsers(): void {

	// openai-responses: text + multiple calls, ids preserved.
	$hal_test_parsed = hal_mcp_provider_parse_openai_responses(
		[
			'id'     => 'resp_1',
			'status' => 'completed',
			'output' => [
				[ 'type' => 'message', 'content' => [ [ 'type' => 'output_text', 'text' => 'Hello.' ] ] ],
				[ 'type' => 'function_call', 'call_id' => 'c1', 'name' => 'hal__get-post', 'arguments' => '{"post_id":5}' ],
				[ 'type' => 'function_call', 'call_id' => 'c2', 'name' => 'hal__search-posts', 'arguments' => '{"query":"x"}' ],
			],
		]
	);
	hal_test_assert( 'Hello.' === $hal_test_parsed['text'] && 2 === count( $hal_test_parsed['tool_calls'] ), 'responses: text + two calls must parse' );
	hal_test_assert( [ 'c1', 'c2' ] === array_column( $hal_test_parsed['tool_calls'], 'id' ), 'responses: call ids must be preserved' );
	hal_test_assert( [ 'hal/get-post', 'hal/search-posts' ] === array_column( $hal_test_parsed['tool_calls'], 'name' ), 'responses: names must map back to hal/*' );
	hal_test_assert( 5 === $hal_test_parsed['tool_calls'][0]['arguments']['post_id'], 'responses: arguments must be decoded' );

	// Provider error + unknown tool.
	$hal_test_error = hal_mcp_provider_parse_openai_responses( [ 'error' => [ 'message' => 'boom' ] ] );
	hal_test_assert( is_wp_error( $hal_test_error ) && 'hal_mcp_provider_error' === $hal_test_error->get_error_code(), 'responses: a provider error must surface as hal_mcp_provider_error' );

	$hal_test_unknown = hal_mcp_provider_parse_openai_chat(
		[ 'choices' => [ [ 'message' => [ 'role' => 'assistant', 'tool_calls' => [ [ 'id' => 'x', 'function' => [ 'name' => 'not_a_hal_tool', 'arguments' => '{}' ] ] ] ], 'finish_reason' => 'tool_calls' ] ] ]
	);
	hal_test_assert( is_wp_error( $hal_test_unknown ) && 'hal_mcp_provider_unknown_tool' === $hal_test_unknown->get_error_code(), 'chat: an unmapped tool name must be refused, not executed' );

	// openai-chat: no choices at all → bad response.
	$hal_test_bad = hal_mcp_provider_parse_openai_chat( [ 'id' => 'x' ] );
	hal_test_assert( is_wp_error( $hal_test_bad ) && 'hal_mcp_provider_bad_response' === $hal_test_bad->get_error_code(), 'chat: a message-less envelope must be refused' );

	// anthropic: text + tool_use; stop_reason carried.
	$hal_test_parsed = hal_mcp_provider_parse_anthropic(
		[ 'id' => 'msg_1', 'stop_reason' => 'tool_use', 'content' => [ [ 'type' => 'text', 'text' => 'Checking.' ], [ 'type' => 'tool_use', 'id' => 'toolu_9', 'name' => 'hal__get-post', 'input' => [ 'post_id' => 7 ] ] ] ]
	);
	hal_test_assert( 'Checking.' === $hal_test_parsed['text'] && 'toolu_9' === $hal_test_parsed['tool_calls'][0]['id'], 'anthropic: text + tool_use parse with the id' );
	hal_test_assert( 'tool_use' === $hal_test_parsed['finish'], 'anthropic: stop_reason must be carried' );

	// gemini: synthetic ids minted in part order.
	$hal_test_parsed = hal_mcp_provider_parse_gemini(
		[ 'responseId' => 'r1', 'candidates' => [ [ 'finishReason' => 'STOP', 'content' => [ 'role' => 'model', 'parts' => [ [ 'text' => 'Hi' ], [ 'functionCall' => [ 'name' => 'hal__get_post', 'args' => [ 'post_id' => 3 ] ] ], [ 'functionCall' => [ 'name' => 'hal__search_posts', 'args' => [] ] ] ] ] ] ] ]
	);
	hal_test_assert( [ 'gemini-call-1', 'gemini-call-2' ] === array_column( $hal_test_parsed['tool_calls'], 'id' ), 'gemini: synthetic call ids must be minted in order' );
	hal_test_assert( [ 'hal/get-post', 'hal/search-posts' ] === array_column( $hal_test_parsed['tool_calls'], 'name' ), 'gemini: names must map back' );
}

/**
 * The transport's endpoint guards: HTTPS only, no userinfo, and loopback /
 * private / link-local / CGNAT / IPv6-local refused — including hosts whose
 * DNS resolves to a private address.
 */
function hal_test_case_http_endpoint_guards(): void {

	hal_test_assert( is_string( hal_mcp_http_validate_endpoint( 'https://provider.test/v1' ) ), 'a normal HTTPS endpoint must pass' );
	hal_test_assert( is_string( hal_mcp_http_validate_endpoint( 'https://93.184.216.34/v1' ) ), 'a public literal IP must pass' );

	foreach ( [ 'http://provider.test/v1', 'https://u:p@provider.test/v1', 'ftp://provider.test/v1' ] as $hal_test_bad ) {
		$hal_test_result = hal_mcp_http_validate_endpoint( $hal_test_bad );
		hal_test_assert( is_wp_error( $hal_test_result ), "{$hal_test_bad} must be refused" );
	}

	foreach ( [ 'https://127.0.0.1/v1', 'https://10.0.0.5/v1', 'https://192.168.1.1/v1', 'https://172.16.0.9/v1', 'https://169.254.1.1/v1', 'https://0.0.0.0/v1', 'https://100.64.0.1/v1', 'https://[::1]/v1', 'https://[fe80::1]/v1', 'https://[fd00::1]/v1',
		// IPv4-compatible IPv6 (::/96), decimal and hex embedded forms — the
		// review-verified SSRF gap class.
		'https://[::127.0.0.1]/v1', 'https://[::7f00:1]/v1', 'https://[::10.0.0.5]/v1',
		// Multicast and reserved ranges.
		'https://224.0.0.1/v1', 'https://240.0.0.1/v1',
		// IPv6 multicast (ff00::/8) and NAT64 (64:ff9b::/96 — the embedded
		// IPv4, here loopback, is what gets refused).
		'https://[ff02::1]/v1', 'https://[64:ff9b::127.0.0.1]/v1' ] as $hal_test_bad ) {
		$hal_test_result = hal_mcp_http_validate_endpoint( $hal_test_bad );
		hal_test_assert( is_wp_error( $hal_test_result ) && 'hal_mcp_http_endpoint_private' === $hal_test_result->get_error_code(), "{$hal_test_bad} must be refused as private/loopback" );
	}

	// DNS that resolves to a private address must be refused (SSRF).
	hal_v03_set_dns( 'private.test', [ '192.168.0.5' ] );
	$hal_test_result = hal_mcp_http_validate_endpoint( 'https://private.test/v1' );
	hal_test_assert( is_wp_error( $hal_test_result ) && 'hal_mcp_http_endpoint_private' === $hal_test_result->get_error_code(), 'a host resolving to a private IP must be refused' );

	// An unresolvable host fails closed.
	hal_v03_set_dns( 'void.invalid', [] );
	$hal_test_result = hal_mcp_http_validate_endpoint( 'https://void.invalid/v1' );
	hal_test_assert( is_wp_error( $hal_test_result ) && 'hal_mcp_http_endpoint_unresolvable' === $hal_test_result->get_error_code(), 'an unresolvable host must be refused, not guessed' );
}

/**
 * The transport pipeline: status mapping with REDACTED provider messages, the
 * size cap, malformed JSON, proven request args (no redirects, cert checks),
 * and strictly NO retries.
 */
function hal_test_case_http_transport(): void {

	// 401: the provider message reaches the error, but the key-shaped strings
	// inside it (sk- AND AIza shapes) must NOT.
	hal_v03_queue_http( [ 'status' => 401, 'body' => '{"error":{"message":"bad key sk-secret1234567890 AIzaSyD-9tJqAbCdEfGh1234567890"}}' ] );
	$hal_test_result = hal_mcp_http_post_json( 'https://provider.test/v1/responses', [ 'headers' => [ 'Authorization' => 'Bearer sk-test-1234567890abcdef' ], 'body' => [ 'model' => 'x' ] ] );

	hal_test_assert( is_wp_error( $hal_test_result ) && 'hal_mcp_http_auth' === $hal_test_result->get_error_code(), '401 must map to hal_mcp_http_auth' );
	hal_test_assert( str_contains( $hal_test_result->get_error_message(), 'bad key' ), 'the provider message should be readable after redaction' );
	hal_test_assert( ! str_contains( $hal_test_result->get_error_message(), 'sk-secret1234567890' ) && ! str_contains( $hal_test_result->get_error_message(), 'AIzaSyD-9tJqAbCdEfGh1234567890' ), 'both key-shaped strings inside the provider message must be redacted' );

	// Status mapping.
	hal_v03_queue_http( [ 'status' => 429, 'body' => '{}' ] );
	$hal_test_result = hal_mcp_http_post_json( 'https://provider.test/v1/responses', [ 'body' => [] ] );
	hal_test_assert( 'hal_mcp_http_rate_limited' === $hal_test_result->get_error_code(), '429 must map to rate_limited' );

	hal_v03_queue_http( [ 'status' => 503, 'body' => '' ] );
	$hal_test_result = hal_mcp_http_post_json( 'https://provider.test/v1/responses', [ 'body' => [] ] );
	hal_test_assert( 'hal_mcp_http_server_error' === $hal_test_result->get_error_code(), '5xx must map to server_error' );

	hal_v03_queue_http( [ 'status' => 418, 'body' => '' ] );
	$hal_test_result = hal_mcp_http_post_json( 'https://provider.test/v1/responses', [ 'body' => [] ] );
	hal_test_assert( 'hal_mcp_http_client_error' === $hal_test_result->get_error_code(), 'other 4xx must map to client_error' );

	// Transport failure.
	hal_v03_queue_http( [ 'transport_error' => 'connection reset' ] );
	$hal_test_result = hal_mcp_http_post_json( 'https://provider.test/v1/responses', [ 'body' => [] ] );
	hal_test_assert( 'hal_mcp_http_transport_failed' === $hal_test_result->get_error_code(), 'a transport failure must map to transport_failed' );

	// No retries: each failure above used exactly one call (401, 429, 503,
	// 418, transport failure).
	hal_test_assert( 5 === count( hal_v03_http_calls() ), 'each request must be attempted exactly once — no retries' );

	// Size cap (announced) and malformed JSON.
	hal_v03_queue_http( [ 'status' => 200, 'headers' => [ 'content-length' => '99999999' ], 'body' => '{}' ] );
	$hal_test_result = hal_mcp_http_post_json( 'https://provider.test/v1/responses', [ 'body' => [] ] );
	hal_test_assert( 'hal_mcp_http_response_too_large' === $hal_test_result->get_error_code(), 'an oversized announced response must be discarded' );

	hal_v03_queue_http( [ 'status' => 200, 'body' => '<html>error page sk-secret1234567890</html>' ] );
	$hal_test_result = hal_mcp_http_post_json( 'https://provider.test/v1/responses', [ 'body' => [] ] );
	hal_test_assert( 'hal_mcp_http_bad_json' === $hal_test_result->get_error_code(), 'a non-JSON 2xx body must be refused' );
	hal_test_assert( ! str_contains( $hal_test_result->get_error_message(), 'sk-secret1234567890' ), 'the malformed-body preview must be redacted' );

	// Success: proven request shape.
	hal_v03_queue_http( [ 'status' => 200, 'body' => '{"id":"resp_1"}' ] );
	$hal_test_result = hal_mcp_http_post_json( 'https://provider.test/v1/responses', [ 'headers' => [ 'Authorization' => 'Bearer k' ], 'body' => [ 'model' => 'x' ] ] );
	hal_test_assert( is_array( $hal_test_result ) && 'resp_1' === $hal_test_result['json']['id'], 'a good response must parse through' );

	$hal_test_call = hal_v03_http_calls()[ count( hal_v03_http_calls() ) - 1 ];
	hal_test_assert( 0 === $hal_test_call['args']['redirection'], 'redirects must be disabled' );
	hal_test_assert( true === $hal_test_call['args']['sslverify'], 'certificate verification must stay on' );
	hal_test_assert( 'application/json' === $hal_test_call['args']['headers']['Content-Type'], 'the content type must be set by the transport' );
	hal_test_assert( is_string( $hal_test_call['args']['body'] ), 'the body must be JSON-encoded by the transport' );
}

/**
 * Settings sanitize + encrypted secrets: keys are stripped from the stored
 * settings, encrypted with autoload off, decrypt cleanly, refuse to decrypt
 * after a key change, and are never echoed.
 */
function hal_test_case_settings_secrets_and_sanitize(): void {

	hal_test_assert( hal_mcp_settings_crypto_available(), 'OpenSSL AES-256-GCM must be available in this environment' );

	$hal_test_clean = hal_v03_save_settings(
		[
			'provider'                 => 'openai',
			'models'                   => [ 'openai' => 'test-model-1' ],
			'endpoints'                => [ 'openai' => 'https://provider.test/v1/responses' ],
			'text_only'                => [ 'openai' => '1' ],
			'policy'                   => [ 'max_rounds' => 99, 'max_seconds' => 9999 ],
			'delete_data_on_uninstall' => '1',
			'api_keys'                 => [ 'openai' => 'sk-live-abcdef1234567890' ],
		]
	);

	hal_test_assert( ! array_key_exists( 'api_keys', $hal_test_clean ), 'the stored settings must never carry key material' );
	hal_test_assert( ! str_contains( (string) wp_json_encode( $hal_test_clean ), 'sk-live-abcdef1234567890' ), 'the key must not appear anywhere in the stored settings' );
	hal_test_assert( 'openai' === $hal_test_clean['provider'] && 'test-model-1' === $hal_test_clean['models']['openai'], 'provider + model must sanitize through' );
	hal_test_assert( 8 === $hal_test_clean['policy']['max_rounds'] && 120 === $hal_test_clean['policy']['max_seconds'], 'policy values must clamp to the code ceilings' );
	hal_test_assert( true === $hal_test_clean['delete_data_on_uninstall'], 'the uninstall choice must be a strict boolean' );

	$hal_test_state_options = hal_v02_state()['updated_options'];
	hal_test_assert( false === ( $hal_test_state_options[ HAL_MCP_SECRETS_OPTION ] ?? null ), 'the secrets option must be written with autoload OFF' );

	// The key decrypts back, and the masked status never exposes it.
	$hal_test_secret = hal_mcp_settings_get_secret( 'openai' );
	hal_test_assert( 'sk-live-abcdef1234567890' === $hal_test_secret, 'the stored key must decrypt round-trip' );

	$hal_test_status = hal_mcp_settings_secret_status( 'openai' );
	hal_test_assert( true === $hal_test_status['set'] && true === $hal_test_status['readable'], 'the status must report a readable key' );
	hal_test_assert( 8 === strlen( $hal_test_status['hint'] ) && ! str_contains( $hal_test_status['hint'], 'abcdef1234567890' ), 'the hint must be the 8-char fingerprint, never key characters' );

	// Site-key mismatch (ONLY 'kkf' tampered — 'kf' untouched) = site secrets
	// changed → clear re-entry error. 'kf' is the per-blob fingerprint and is
	// never consulted on the decrypt path.
	$hal_test_secrets                 = get_option( HAL_MCP_SECRETS_OPTION );
	$hal_test_secrets['openai']['kkf'] = 'deadbeef';
	update_option( HAL_MCP_SECRETS_OPTION, $hal_test_secrets, false );

	$hal_test_secret = hal_mcp_settings_get_secret( 'openai' );
	hal_test_assert( is_wp_error( $hal_test_secret ) && 'hal_mcp_secret_key_changed' === $hal_test_secret->get_error_code(), 'a site-key fingerprint (kkf) mismatch must ask for re-entry' );

	// Re-storing a DIFFERENT key for the same provider must change 'kf' — it
	// fingerprints the sealed blob (per provider, per save), which the old
	// site-wide 'kf' never did.
	hal_mcp_settings_store_secret( 'openai', 'sk-live-abcdef1234567890' );
	$hal_test_secrets  = get_option( HAL_MCP_SECRETS_OPTION );
	$hal_test_kf_first = (string) $hal_test_secrets['openai']['kf'];
	hal_test_assert( 8 === strlen( $hal_test_kf_first ) && isset( $hal_test_secrets['openai']['kkf'] ), 'a stored row must carry the 8-hex blob fingerprint (kf) AND the site-key fingerprint (kkf)' );

	hal_mcp_settings_store_secret( 'openai', 'sk-other-abcdef1234567890' );
	$hal_test_secrets = get_option( HAL_MCP_SECRETS_OPTION );
	hal_test_assert( $hal_test_kf_first !== (string) $hal_test_secrets['openai']['kf'], 're-storing a different key must change the per-blob kf fingerprint' );

	// Two providers storing keys must get DIFFERENT kf values — the
	// fingerprint identifies the key's sealed blob, never the site.
	hal_mcp_settings_store_secret( 'glm', 'sk-glm-abcdef1234567890' );
	hal_mcp_settings_store_secret( 'kimi', 'sk-kimi-abcdef1234567890' );
	$hal_test_secrets = get_option( HAL_MCP_SECRETS_OPTION );
	hal_test_assert( (string) $hal_test_secrets['glm']['kf'] !== (string) $hal_test_secrets['kimi']['kf'], 'two providers must never share a kf fingerprint' );

	// A legacy row (old shape: only 'kf', no 'kkf') must decrypt WITHOUT the
	// key-changed error — the shape change never bricks keys stored with it
	// (a genuinely changed site key still fails closed at OpenSSL itself).
	$hal_test_legacy_blob   = (string) $hal_test_secrets['openai']['blob'];
	$hal_test_legacy_secret = 'sk-other-abcdef1234567890';
	update_option( HAL_MCP_SECRETS_OPTION, [ 'openai' => [ 'blob' => $hal_test_legacy_blob, 'kf' => 'legacy01' ] ], false );

	$hal_test_secret = hal_mcp_settings_get_secret( 'openai' );
	hal_test_assert( $hal_test_secret === $hal_test_legacy_secret, 'a legacy row (only kf, no kkf) must decrypt without the key-changed error' );

	// Corrupt blob → unreadable, never a fatal.
	update_option( HAL_MCP_SECRETS_OPTION, [ 'openai' => [ 'blob' => 'not-base64!!', 'kkf' => hal_mcp_settings_key_fingerprint() ] ], false );
	$hal_test_secret = hal_mcp_settings_get_secret( 'openai' );
	hal_test_assert( is_wp_error( $hal_test_secret ) && 'hal_mcp_secret_corrupt' === $hal_test_secret->get_error_code(), 'a corrupt blob must surface as unreadable' );

	// Remove path — the dedicated remove_keys map (the checkbox's own field
	// name, not a sentinel inside the key field).
	hal_mcp_settings_store_secret( 'openai', 'sk-live-abcdef1234567890' );
	hal_mcp_settings_sanitize( [ 'remove_keys' => [ 'openai' => '1' ] ] );
	hal_test_assert( '' === hal_mcp_settings_get_secret( 'openai' ), 'the remove_keys map must delete the stored key' );

	// Remove path — the legacy '__remove__' sentinel still honored.
	hal_mcp_settings_store_secret( 'openai', 'sk-live-abcdef1234567890' );
	hal_mcp_settings_sanitize( [ 'api_keys' => [ 'openai' => '__remove__' ] ] );
	hal_test_assert( '' === hal_mcp_settings_get_secret( 'openai' ), 'the legacy __remove__ sentinel must still delete the stored key' );

	// Endpoint override validation happens on save.
	hal_v03_save_settings( [ 'endpoints' => [ 'openai' => 'https://127.0.0.1/v1' ] ] );
	$hal_test_stored = hal_mcp_settings_get();
	hal_test_assert( ! isset( $hal_test_stored['endpoints']['openai'] ), 'a private endpoint override must be dropped on save' );
	hal_test_assert( [] !== hal_mcp_settings_last_sanitize_errors(), 'the refused endpoint must produce a visible settings error' );

	// Text-only provider is refused by the tool gate.
	hal_v03_save_settings( [ 'text_only' => [ 'openai' => '1' ] ] );
	hal_test_assert( false === hal_mcp_provider_supports_tools( 'openai' ), 'a text-only provider must fail the tool-capability gate' );
	hal_v03_save_settings( [] );
	hal_test_assert( true === hal_mcp_provider_supports_tools( 'openai' ), 'without the flag, the registry answer stands' );

	// Generation parameters clamp to the code ranges on save (each save stands
	// alone — sanitize rebuilds the maps from its own input).
	$hal_test_clean = hal_v03_save_settings( [ 'temperature' => [ 'openai' => 9 ] ] );
	hal_test_assert( 2.0 === $hal_test_clean['temperature']['openai'], 'an over-range temperature must clamp down to 2.0' );
	$hal_test_clean = hal_v03_save_settings( [ 'max_output' => [ 'openai' => 99999 ] ] );
	hal_test_assert( 32768 === $hal_test_clean['max_output']['openai'], 'an over-cap max_output must clamp to the registry cap' );
	$hal_test_clean = hal_v03_save_settings( [ 'max_output' => [ 'openai' => 1 ] ] );
	hal_test_assert( 16 === $hal_test_clean['max_output']['openai'], 'an under-floor max_output must clamp up to 16' );

	// Runtime config resolution.
	hal_v03_save_settings(
		[
			'provider'    => 'openai',
			'models'      => [ 'openai' => 'test-model-1' ],
			'endpoints'   => [ 'openai' => 'https://provider.test/v1/responses' ],
			'temperature' => [ 'openai' => 1.5 ],
			'max_output'  => [ 'openai' => 4096 ],
			'api_keys'    => [ 'openai' => 'sk-test-1234567890abcdef' ],
		]
	);

	$hal_test_stored = hal_mcp_settings_get();
	hal_test_assert( 1.5 === $hal_test_stored['temperature']['openai'], 'a numeric temperature must land in the stored settings' );
	hal_test_assert( 4096 === $hal_test_stored['max_output']['openai'], 'a numeric max_output must land in the stored settings' );

	$hal_test_config = hal_mcp_settings_provider_config( 'openai' );
	hal_test_assert( is_array( $hal_test_config ) && 'test-model-1' === $hal_test_config['model'], 'the runtime config must resolve model + endpoint from settings' );
	hal_test_assert( 'sk-test-1234567890abcdef' === $hal_test_config['api_key'], 'the runtime config must carry the decrypted key' );
	hal_test_assert( is_array( $hal_test_config ) && 1.5 === $hal_test_config['temperature_value'], 'a config built from those settings must carry temperature_value' );
	hal_test_assert( is_array( $hal_test_config ) && 4096 === $hal_test_config['max_output_value'], 'a config built from those settings must carry max_output_value' );

	$hal_test_unknown = hal_mcp_settings_provider_config( 'nope' );
	hal_test_assert( is_wp_error( $hal_test_unknown ), 'an unknown provider must be refused' );

	// The custom model is single-sourced from models['custom'] — the removed
	// custom['model'] branch must not be read (nor synthesized) anywhere.
	$hal_test_clean = hal_v03_save_settings(
		[
			'provider' => 'custom',
			'models'   => [ 'custom' => 'custom-model-1' ],
		]
	);
	hal_test_assert( [ 'protocol' => '' ] === $hal_test_clean['custom'], 'the custom settings must carry only the protocol — no model duplicate' );
	hal_test_assert( ! array_key_exists( 'model', $hal_test_clean['custom'] ), 'sanitize must not synthesize a custom[\'model\'] entry' );
}

/**
 * The runner end to end over the REAL settings config and the REAL request
 * store: one tool call executes through the Abilities API, the pending
 * approval stops the loop, resume is blocked while the request is pending,
 * and the loop completes once the request leaves pending.
 */
function hal_test_case_runner_loop_and_pending_stop(): void {

	hal_v02_set_caps(
		[
			'primitives' => [ 'edit_posts' => true, 'manage_options' => true ],
			'meta_caps'  => [ 'edit_post' => static fn( $id ) => true, 'read_post' => static fn( $id ) => true ],
		]
	);

	// Real config through the settings layer.
	hal_v03_save_settings(
		[
			'provider'  => 'openai',
			'models'    => [ 'openai' => 'test-model-1' ],
			'endpoints' => [ 'openai' => 'https://provider.test/v1/responses' ],
			'api_keys'  => [ 'openai' => 'sk-test-1234567890abcdef' ],
		]
	);

	// A real apply handler so the pending request is creatable.
	hal_test_assert(
		hal_mcp_register_apply_handler( 'update-post', static fn() => [ 'applied' => true ] ),
		'the test apply handler must register'
	);

	// The model-facing mapping must be the REAL helper, never a fabricated
	// string: the store keeps 'pending' (F17 machine untouched) and the tool
	// answer honors the roadmap §4.3 wording «يرد النظام pending_approval مع
	// معرف ومعاينة». Every other store state passes through unchanged.
	hal_test_assert( 'pending_approval' === hal_mcp_request_model_state( 'pending' ), 'the store pending state must map to the model-facing pending_approval' );
	hal_test_assert( 'applied' === hal_mcp_request_model_state( 'applied' ), 'non-pending store states must pass through the mapping unchanged' );

	// The stub hal/get-post ability: executing it creates a REAL change
	// request and maps the REAL store state through the REAL
	// hal_mcp_request_model_state() helper, so the tool answer is exactly
	// what production emits.
	wp_register_ability(
		'hal/get-post',
		[
			'description'         => 'Get a single post',
			'meta'                => [ 'mcp' => [ 'public' => true ] ],
			'input_schema'        => [ 'type' => 'object', 'properties' => [ 'post_id' => [ 'type' => 'integer' ] ] ],
			'permission_callback' => static fn( $input ) => true,
			'execute_callback'    => static function ( $input ) {
				$hal_test_created = hal_mcp_create_change_request(
					[
						'operation' => 'update-post',
						'payload'   => [ 'title' => 'Proposed title' ],
						'targets'   => [ [ 'type' => 'post', 'id' => 0 ] ],
						'origin'    => [ 'source' => 'runner', 'model' => 'test-model-1', 'version' => HAL_MCP_ABILITIES_VERSION ],
					]
				);

				if ( is_wp_error( $hal_test_created ) ) {
					return $hal_test_created;
				}

				return [
					'state'       => hal_mcp_request_model_state( (string) $hal_test_created['state'] ),
					'request_id'  => (int) $hal_test_created['request_id'],
					'preview'     => (string) $hal_test_created['preview'],
				];
			},
		]
	);

	// Round 1: the model calls the tool; the pending approval stops the loop.
	// (The settings config resolves the openai registry protocol —
	// openai-responses — so the scripted body is a Responses envelope.)
	hal_v03_queue_http(
		[
			'status' => 200,
			'body'   => '{"id":"resp-1","status":"completed","output":[{"type":"function_call","call_id":"call_1","name":"hal__get-post","arguments":"{\"post_id\":5}"}]}',
		]
	);

	$hal_test_run = hal_mcp_runner_start( 'openai', 'Update post 5 for me.' );

	hal_test_assert( is_array( $hal_test_run ), 'the run must start over the real config: ' . ( is_wp_error( $hal_test_run ) ? $hal_test_run->get_error_code() : '' ) );
	hal_test_assert( 'awaiting_approval' === $hal_test_run['state'], 'a pending_approval answer must stop the run in awaiting_approval' );
	hal_test_assert( 1 === $hal_test_run['rounds_used'], 'exactly one provider round must have run' );
	hal_test_assert( 1 === count( hal_v03_http_calls() ), 'the loop must stop without another provider call' );
	hal_test_assert( is_array( $hal_test_run['pending_request'] ) && $hal_test_run['pending_request']['request_id'] > 0, 'the pending request must be reported with its id' );

	// The run row exists in the store as kind=run and is NOT approvable.
	$hal_test_pending_id = (int) $hal_test_run['pending_request']['request_id'];
	$hal_test_refused    = hal_mcp_approve_change_request( $hal_test_run['run_id'] );
	hal_test_assert( is_wp_error( $hal_test_refused ) && 'hal_mcp_not_applicable' === $hal_test_refused->get_error_code(), 'a run row must be refused at approve()' );

	// Resume without a decision is refused — no duplicate proposals.
	$hal_test_resume = hal_mcp_runner_resume( $hal_test_run['run_id'] );
	hal_test_assert( is_wp_error( $hal_test_resume ) && 'hal_mcp_runner_pending_first' === $hal_test_resume->get_error_code(), 'resume must refuse while the pending request is undecided' );

	// The transcript must show the tool line and the user text.
	$hal_test_view = hal_mcp_runner_get_run( $hal_test_run['run_id'] );
	hal_test_assert( is_array( $hal_test_view ) && 'user' === $hal_test_view['messages'][0]['role'], 'the transcript must start with the user message' );
	hal_test_assert( 'hal/get-post' === $hal_test_view['messages'][2]['tool_calls'][0]['name'], 'the transcript must carry the executed tool name' );

	// The conversation must not contain the API key.
	$hal_test_stored = (string) wp_json_encode( (array) get_post_meta( $hal_test_run['run_id'], '_hal_payload', true ) );
	hal_test_assert( ! str_contains( $hal_test_stored, 'sk-test-1234567890abcdef' ), 'the API key must never enter the stored conversation' );

	// Reject the pending request → resume proceeds → the model's text ends it.
	hal_test_assert( is_array( hal_mcp_reject_change_request( $hal_test_pending_id, 'not now' ) ), 'the pending request must be rejectable through the real store' );

	hal_v03_queue_http(
		[
			'status' => 200,
			'body'   => '{"id":"resp-2","status":"completed","output":[{"type":"message","role":"assistant","content":[{"type":"output_text","text":"Understood, nothing changed."}]}]}',
		]
	);

	$hal_test_resume = hal_mcp_runner_resume( $hal_test_run['run_id'] );

	hal_test_assert( is_array( $hal_test_resume ) && 'done' === $hal_test_resume['state'], 'after the decision, resume must complete the run' );
	hal_test_assert( 2 === count( hal_v03_http_calls() ), 'the second round must call the provider exactly once' );
	hal_test_assert( str_contains( $hal_test_resume['messages'][3]['text'] ?? '', 'Understood' ), 'the final assistant text must be in the transcript' );

	// Cancelling a finished run is a no-op; a run row stays kind=run.
	hal_test_assert( true === hal_mcp_runner_cancel( $hal_test_run['run_id'] ), 'cancelling a finished run must be a harmless no-op' );
}

/**
 * Runner gates: text-only provider refused, over-long input refused, cancel
 * blocks every later step, an unregistered tool answers as a tool error
 * (never executed), and the code ceilings bind the policy.
 */
function hal_test_case_runner_gates(): void {

	hal_v02_set_caps( [ 'primitives' => [ 'edit_posts' => true, 'manage_options' => true ] ] );

	// Text-only provider: the tool loop must refuse with a clear reason.
	hal_v03_save_settings( [ 'provider' => 'openai', 'text_only' => [ 'openai' => '1' ] ] );
	$hal_test_refused = hal_mcp_runner_start( 'openai', 'hello', [ 'config' => hal_v03_provider_config( 'openai-responses' ) ] );
	hal_test_assert( is_wp_error( $hal_test_refused ) && 'hal_mcp_runner_not_tool_capable' === $hal_test_refused->get_error_code(), 'a text-only provider must refuse the tool loop' );

	// Over-long input.
	hal_v03_save_settings( [] );
	$hal_test_refused = hal_mcp_runner_start( 'openai', str_repeat( 'a', HAL_MCP_RUNNER_MAX_INPUT_CHARS + 1 ), [ 'config' => hal_v03_provider_config( 'openai-responses' ) ] );
	hal_test_assert( is_wp_error( $hal_test_refused ) && 'hal_mcp_runner_input_too_long' === $hal_test_refused->get_error_code(), 'an over-long instruction must be refused' );

	// An unregistered tool answers as a TOOL ERROR, never executed — and the
	// run keeps going until the scripted queue is dry, where it stops with an
	// honest provider error instead of a loop.
	hal_v03_queue_http(
		[
			'status' => 200,
			'body'   => '{"id":"r1","status":"completed","output":[{"type":"function_call","call_id":"c1","name":"hal__get-post","arguments":"{}"}]}',
		]
	);

	$hal_test_run = hal_mcp_runner_start( 'openai', 'do things', [ 'config' => hal_v03_provider_config( 'openai-responses' ) ] );
	hal_test_assert( is_array( $hal_test_run ) && 'error' === $hal_test_run['state'], 'with a dry scripted queue the run must stop with an honest error' );
	hal_test_assert( 2 === count( hal_v03_http_calls() ), 'round 1 calls the provider, round 2 hits the dry queue — two calls, no loop' );

	$hal_test_view = hal_mcp_runner_get_run( $hal_test_run['run_id'] );
	hal_test_assert( true === ( $hal_test_view['messages'][2]['tool_calls'][0]['is_error'] ?? null ), 'the unregistered tool call must be recorded as a tool error, never executed' );

	// Cancel blocks every later step.
	hal_test_assert( true === hal_mcp_runner_cancel( $hal_test_run['run_id'] ), 'cancelling a stopped run must succeed' );
	hal_test_assert( is_wp_error( hal_mcp_runner_resume( $hal_test_run['run_id'] ) ), 'a cancelled run must refuse every later step' );

	$hal_test_view = hal_mcp_runner_get_run( $hal_test_run['run_id'] );
	hal_test_assert( 'cancelled' === $hal_test_view['state'], 'the cancelled state must be persisted' );

	// Policy can only lower the ceilings.
	$hal_test_limits = hal_mcp_runner_limits();
	hal_test_assert( $hal_test_limits['max_rounds'] <= HAL_MCP_RUNNER_MAX_ROUNDS && $hal_test_limits['max_seconds'] <= HAL_MCP_RUNNER_MAX_SECONDS, 'the runner limits must stay under the code ceilings' );

	// Unknown provider refused at start.
	$hal_test_refused = hal_mcp_runner_start( 'nope', 'hello', [ 'config' => hal_v03_provider_config( 'openai-responses' ) ] );
	hal_test_assert( is_wp_error( $hal_test_refused ), 'an unknown provider id must be refused' );
}

/**
 * The wall-clock budget is PER SEGMENT: a run whose stored started_at /
 * segment_started_at are far in the past (a run stopped long ago, or rows
 * persisted before the segment key existed) must resume into a FRESH time
 * window — the provider call happens and the run completes — instead of
 * re-tripping the limit instantly because time() - started_at is already
 * over budget. The rounds budget stays cumulative.
 */
function hal_test_case_runner_resume_time_segment(): void {

	hal_v02_set_caps(
		[
			'primitives' => [ 'edit_posts' => true, 'manage_options' => true ],
			'meta_caps'  => [ 'edit_post' => static fn( $id ) => true, 'read_post' => static fn( $id ) => true ],
		]
	);

	hal_v03_save_settings(
		[
			'provider'  => 'openai',
			'models'    => [ 'openai' => 'test-model-1' ],
			'endpoints' => [ 'openai' => 'https://provider.test/v1/responses' ],
			'api_keys'  => [ 'openai' => 'sk-test-1234567890abcdef' ],
		]
	);

	// Seed a run row through the REAL store whose state says it stopped on
	// the time limit ten minutes ago — both clocks are stale.
	$hal_test_created = hal_mcp_create_change_request(
		[
			'kind'      => 'run',
			'operation' => 'run',
			'payload'   => [ [ 'role' => 'user', 'text' => 'Continue this finished thought.' ] ],
			'origin'    => [ 'source' => 'runner', 'model' => 'test-model-1', 'version' => HAL_MCP_ABILITIES_VERSION ],
		]
	);
	hal_test_assert( is_array( $hal_test_created ), 'the seeded run row must be creatable through the real store' );

	$hal_test_run_id = (int) $hal_test_created['request_id'];

	update_post_meta(
		$hal_test_run_id,
		'_hal_run',
		[
			'state'              => 'limit',
			'provider'           => 'openai',
			'model'              => 'test-model-1',
			'protocol'           => 'openai-responses',
			'rounds_used'        => 0,
			'started_at'         => time() - 600,
			'segment_started_at' => time() - 600,
			'updated_at'         => time() - 600,
			'finished_at'        => time() - 600,
			'last_error'         => 'The run reached its time limit.',
			'pending_request'    => null,
		]
	);

	// One text answer queued: if the clock is judged per segment, the resume
	// makes exactly one provider call and completes.
	hal_v03_queue_http(
		[
			'status' => 200,
			'body'   => '{"id":"resp-seg","status":"completed","output":[{"type":"message","role":"assistant","content":[{"type":"output_text","text":"Resumed and finished."}]}]}',
		]
	);

	$hal_test_resumed = hal_mcp_runner_resume( $hal_test_run_id );

	hal_test_assert(
		is_array( $hal_test_resumed ) && 'done' === $hal_test_resumed['state'],
		'a stale-clock resume must run the provider round, not re-trip the limit instantly: ' . ( is_wp_error( $hal_test_resumed ) ? $hal_test_resumed->get_error_code() . ' ' . $hal_test_resumed->get_error_message() : (string) $hal_test_resumed['state'] )
	);
	hal_test_assert( 1 === count( hal_v03_http_calls() ), 'the resumed segment must call the provider exactly once' );

	$hal_test_stored = (array) get_post_meta( $hal_test_run_id, '_hal_run', true );
	hal_test_assert( 1 === (int) ( $hal_test_stored['rounds_used'] ?? 0 ), 'the resumed round must increment the cumulative rounds_used' );
	hal_test_assert( ( time() - (int) ( $hal_test_stored['segment_started_at'] ?? 0 ) ) < 10, 'the resume must persist a FRESH segment_started_at, not inherit the stale clock' );
}

/**
 * The per-run execution lock (the F17 short lock, reused): start and resume
 * hold it for the whole loop; a concurrent resume of the same row is refused
 * with hal_mcp_runner_in_progress and mutates nothing; and the loop releases
 * the lock on its exit so the run is never orphaned behind a stale lock.
 */
function hal_test_case_runner_lock_exclusive_resume(): void {

	hal_v02_set_caps(
		[
			'primitives' => [ 'edit_posts' => true, 'manage_options' => true ],
			'meta_caps'  => [ 'edit_post' => static fn( $id ) => true, 'read_post' => static fn( $id ) => true ],
		]
	);

	hal_v03_save_settings(
		[
			'provider'  => 'openai',
			'models'    => [ 'openai' => 'test-model-1' ],
			'endpoints' => [ 'openai' => 'https://provider.test/v1/responses' ],
			'api_keys'  => [ 'openai' => 'sk-test-1234567890abcdef' ],
		]
	);

	// Part 1 — start holds the lock for its own loop and leaves it RELEASED:
	// a fresh acquire must win after the run returns.
	hal_v03_queue_http(
		[
			'status' => 200,
			'body'   => '{"id":"resp-lock-1","status":"completed","output":[{"type":"message","role":"assistant","content":[{"type":"output_text","text":"Started and done."}]}]}',
		]
	);

	$hal_test_started = hal_mcp_runner_start( 'openai', 'Start and finish in one round.' );
	hal_test_assert( is_array( $hal_test_started ) && 'done' === $hal_test_started['state'], 'the started run must complete in one round: ' . ( is_wp_error( $hal_test_started ) ? $hal_test_started->get_error_code() : (string) $hal_test_started['state'] ) );
	hal_test_assert( true === hal_mcp_request_acquire_lock( (int) $hal_test_started['run_id'] ), 'the loop must RELEASE the execution lock when start finishes' );
	hal_mcp_request_release_lock( (int) $hal_test_started['run_id'] );

	// Part 2 — a resume while the lock is held is refused without mutating
	// the run, and proceeds once the lock is released.
	$hal_test_created = hal_mcp_create_change_request(
		[
			'kind'      => 'run',
			'operation' => 'run',
			'payload'   => [ [ 'role' => 'user', 'text' => 'Resume me exactly once.' ] ],
			'origin'    => [ 'source' => 'runner', 'model' => 'test-model-1', 'version' => HAL_MCP_ABILITIES_VERSION ],
		]
	);
	hal_test_assert( is_array( $hal_test_created ), 'the fresh run row must be creatable through the real store' );

	$hal_test_run_id = (int) $hal_test_created['request_id'];

	update_post_meta(
		$hal_test_run_id,
		'_hal_run',
		[
			'state'              => 'active',
			'provider'           => 'openai',
			'model'              => 'test-model-1',
			'protocol'           => 'openai-responses',
			'rounds_used'        => 0,
			'started_at'         => time(),
			'segment_started_at' => time(),
			'updated_at'         => time(),
			'finished_at'        => 0,
			'last_error'         => '',
			'pending_request'    => null,
		]
	);

	hal_test_assert( true === hal_mcp_request_acquire_lock( $hal_test_run_id ), 'the test must be able to take the per-run lock manually' );

	$hal_test_calls_before = count( hal_v03_http_calls() );

	$hal_test_refused = hal_mcp_runner_resume( $hal_test_run_id );
	hal_test_assert( is_wp_error( $hal_test_refused ) && 'hal_mcp_runner_in_progress' === $hal_test_refused->get_error_code(), 'a locked run must refuse resume with hal_mcp_runner_in_progress' );
	hal_test_assert( $hal_test_calls_before === count( hal_v03_http_calls() ), 'the refused resume must not call the provider' );

	$hal_test_stored = (array) get_post_meta( $hal_test_run_id, '_hal_run', true );
	hal_test_assert( 'active' === (string) ( $hal_test_stored['state'] ?? '' ) && 0 === (int) ( $hal_test_stored['rounds_used'] ?? -1 ), 'the refused resume must not mutate the stored run state' );

	hal_mcp_request_release_lock( $hal_test_run_id );

	hal_v03_queue_http(
		[
			'status' => 200,
			'body'   => '{"id":"resp-lock-2","status":"completed","output":[{"type":"message","role":"assistant","content":[{"type":"output_text","text":"Resumed once."}]}]}',
		]
	);

	$hal_test_resumed = hal_mcp_runner_resume( $hal_test_run_id );
	hal_test_assert( is_array( $hal_test_resumed ) && 'done' === $hal_test_resumed['state'], 'after the lock is released, resume must proceed: ' . ( is_wp_error( $hal_test_resumed ) ? $hal_test_resumed->get_error_code() : (string) $hal_test_resumed['state'] ) );
	hal_test_assert( $hal_test_calls_before + 1 === count( hal_v03_http_calls() ), 'the unlocked resume must call the provider exactly once' );
	hal_test_assert( true === hal_mcp_request_acquire_lock( $hal_test_run_id ), 'the loop must RELEASE the execution lock when resume finishes' );
	hal_mcp_request_release_lock( $hal_test_run_id );
}

/**
 * The admin REST surface: the bounded route set with permission callbacks on
 * every route, and the decision gate (nonce + session + capability).
 */
function hal_test_case_admin_rest_routes_and_gate(): void {

	require_once hal_test_v03_plugin_dir() . '/hal-mcp-abilities/includes/admin.php';

	hal_mcp_admin_rest_routes();

	$hal_test_routes = hal_v03_state()['rest_routes'];

	hal_test_assert( count( $hal_test_routes ) >= 9, 'the bounded route set must be registered (chat, runs, approvals, decisions, test, models, environment, bridge)' );

	foreach ( $hal_test_routes as $hal_test_route => $hal_test_args ) {
		hal_test_assert( is_callable( $hal_test_args['permission_callback'] ?? null ), "{$hal_test_route}: every route must carry a permission callback" );
		hal_test_assert( is_callable( $hal_test_args['callback'] ?? null ), "{$hal_test_route}: every route must carry a callback" );
	}

	// The decision gate: no nonce → refused; valid nonce + manage_options →
	// allowed; valid nonce without manage_options → refused.
	$_SERVER['HTTP_X_WP_NONCE'] = 'test-nonce-value';

	hal_v03_state()['nonce_valid'] = false;
	hal_test_assert( false === hal_mcp_admin_rest_gate( true ), 'the gate must refuse without a valid nonce' );

	hal_v03_state()['nonce_valid'] = true;
	hal_v02_set_caps( [ 'primitives' => [ 'manage_options' => true ] ] );
	hal_test_assert( true === hal_mcp_admin_rest_gate( true ), 'the gate must pass with nonce + manage_options' );

	hal_v02_set_caps( [ 'primitives' => [ 'manage_options' => false ] ] );
	hal_test_assert( false === hal_mcp_admin_rest_gate( true ), 'the gate must refuse a non-manager even with a valid nonce' );
	hal_test_assert( true === hal_mcp_admin_rest_gate( false ), 'the session-only gate does not demand manage_options' );

	unset( $_SERVER['HTTP_X_WP_NONCE'] );
	hal_test_assert( false === hal_mcp_admin_rest_gate( true ), 'the gate must refuse when the nonce header is absent entirely' );
}

/**
 * The approval detail's editor-bridge affordance (F21): a design-bearing
 * update-page request surfaces the stored fingerprint plus a post.php editor
 * URL that transports the request id and fingerprint — while a non-page
 * request surfaces neither a link nor a fabricated URL. (The link itself is
 * re-validated server-side by the blocks ingest route.)
 */
function hal_test_case_admin_approval_detail_editor_url(): void {

	require_once hal_test_v03_plugin_dir() . '/hal-mcp-abilities/includes/admin.php';

	hal_v02_set_caps(
		[
			'primitives' => [ 'manage_options' => true, 'edit_posts' => true, 'edit_pages' => true ],
			'meta_caps'  => [ 'edit_page' => static fn( $id ) => true, 'edit_post' => static fn( $id ) => true ],
		]
	);

	hal_test_assert( hal_mcp_register_apply_handler( 'update-page', static fn() => [ 'applied' => true ] ), 'the update-page apply handler must register' );
	hal_test_assert( hal_mcp_register_apply_handler( 'update-post', static fn() => [ 'applied' => true ] ), 'the update-post apply handler must register' );

	$hal_test_page = wp_insert_post(
		[
			'post_type'   => 'page',
			'post_status' => 'publish',
			'post_title'  => 'Bridge page',
		]
	);
	hal_test_assert( $hal_test_page > 0, 'the seeded page must insert' );

	// A design-bearing update-page request on the page: the detail must carry
	// the fingerprint and a working editor link.
	$hal_test_created = hal_mcp_create_change_request(
		[
			'operation' => 'update-page',
			'payload'   => [ 'title' => 'Bridge page', 'content' => '<!-- wp:paragraph --><p>Hello.</p><!-- /wp:paragraph -->' ],
			'targets'   => [ [ 'type' => 'page', 'id' => $hal_test_page ] ],
			'origin'    => [ 'source' => 'runner', 'model' => 'test-model-1', 'version' => HAL_MCP_ABILITIES_VERSION ],
		]
	);
	hal_test_assert( is_array( $hal_test_created ), 'the update-page request must be creatable through the real store: ' . ( is_wp_error( $hal_test_created ) ? $hal_test_created->get_error_code() : '' ) );

	$hal_test_detail = hal_mcp_admin_rest_approval_detail(
		new Hal_V03_Rest_Request( [ 'id' => (int) $hal_test_created['request_id'] ] )
	);

	hal_test_assert( is_array( $hal_test_detail ), 'the detail handler must return the request detail' );
	hal_test_assert( (string) $hal_test_created['proposed_fingerprint'] === (string) ( $hal_test_detail['proposed_fingerprint'] ?? '' ), 'the detail must carry the stored proposed fingerprint' );

	$hal_test_editor_url = (string) ( $hal_test_detail['editor_url'] ?? 'missing' );
	hal_test_assert( '' !== $hal_test_editor_url, 'a design-bearing update-page request must surface a non-empty editor_url' );
	hal_test_assert( str_contains( $hal_test_editor_url, 'hal_mcp_request=' . $hal_test_created['request_id'] ), 'the editor_url must transport the request id' );
	hal_test_assert( str_contains( $hal_test_editor_url, 'hal_mcp_fp=' . rawurlencode( (string) $hal_test_created['proposed_fingerprint'] ) ), 'the editor_url must transport the fingerprint' );
	hal_test_assert( str_contains( $hal_test_editor_url, 'post=' . $hal_test_page ) && str_contains( $hal_test_editor_url, 'action=edit' ), 'the editor_url must point at the page edit screen' );

	// A non-page request gets NO editor link (and no fabricated URL).
	$hal_test_other = hal_mcp_create_change_request(
		[
			'operation' => 'update-post',
			'payload'   => [ 'title' => 'Just text' ],
			'targets'   => [ [ 'type' => 'post', 'id' => 0 ] ],
			'origin'    => [ 'source' => 'runner', 'model' => 'test-model-1', 'version' => HAL_MCP_ABILITIES_VERSION ],
		]
	);
	hal_test_assert( is_array( $hal_test_other ), 'the update-post request must be creatable through the real store' );

	$hal_test_detail = hal_mcp_admin_rest_approval_detail(
		new Hal_V03_Rest_Request( [ 'id' => (int) $hal_test_other['request_id'] ] )
	);

	hal_test_assert( is_array( $hal_test_detail ), 'the non-page detail handler must return the request detail' );
	hal_test_assert( '' === (string) ( $hal_test_detail['editor_url'] ?? 'missing' ), 'a non-page request must not surface an editor_url' );
}

/**
 * Absolute path to the plugin root for the admin.php require above.
 *
 * @return string
 */
function hal_test_v03_plugin_dir(): string {
	return dirname( __DIR__, 2 );
}
