<?php
/**
 * hal-mcp-abilities — provider registry and protocol adapters (F15).
 *
 * The internal UI's path from a user command to a tool-call round (roadmap
 * §4.2): provider config → one request in the provider's protocol → parsed
 * text + tool calls back. The transport itself lives in http-client.php; this
 * file owns ONLY the provider knowledge: the six-provider registry, the four
 * supported protocols, tool-name conversion, per-protocol request building
 * and response parsing, and the parameter rules.
 *
 * Providers (roadmap R3): OpenAI, Anthropic/Claude, Google/Gemini, Z.AI/GLM,
 * Moonshot/Kimi, Qwen, plus an admin-defined custom provider. Four protocols
 * cover all of them — deliberately NOT assumed to share dialects:
 *
 *   openai-responses    OpenAI /v1/responses (tools as flat function objects,
 *                       call results as function_call_output items)
 *   openai-chat         the Chat Completions compatible path GLM/Kimi/Qwen and
 *                       custom providers use (tools nested under 'function',
 *                       results as role:'tool' messages)
 *   anthropic-messages  Anthropic /v1/messages (max_tokens REQUIRED, blocks,
 *                       tool_result goes in the following user message)
 *   gemini-native       Gemini generateContent (parts, no call IDs — synthetic
 *                       ones are minted, schemas upper-cased and stripped of
 *                       unsupported keys)
 *
 * Secrets never live here: the registry holds endpoints and capability
 * flags; API keys are read through the F19 settings layer only at call time
 * and exist inside the request headers for the lifetime of one HTTP call.
 *
 * Tool-name conversion (F15: "خريطة عكسية ثابتة إلى hal/*؛ لا تنفيذ اسم دالة
 * عشوائي"): hal/get-post style names contain '/', which OpenAI/Anthropic
 * forbid and Gemini additionally forbids '-'. The conversion is deterministic
 * ('hal/get-post' → 'hal__get-post', Gemini 'hal__get_post') AND every call
 * carries the explicit map built from the catalog; execution only happens
 * for a name present in that map — a provider-echoed unknown name is refused,
 * never executed.
 *
 * A provider/model marked text-only (tool_calling=false) is refused by the
 * tool runner and labeled "text generation only" in the UI — it is never
 * presented as a site-editing integration (F15).
 *
 * @package hal-mcp-abilities
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The four protocols this plugin speaks. The custom provider must pick one of
 * these — no self-invented protocols (F15).
 *
 * @var string[]
 */
const HAL_MCP_PROVIDER_PROTOCOLS = [ 'openai-responses', 'openai-chat', 'anthropic-messages', 'gemini-native' ];

/**
 * Tool-name conversion mode per protocol family: which characters each
 * provider accepts after the deterministic conversion.
 *
 * @var string[]
 */
const HAL_MCP_PROVIDER_DASHED_PROTOCOLS = [ 'openai-responses', 'openai-chat', 'anthropic-messages' ];

/**
 * The default provider registry (F15: "سجل إعدادات افتراضية"). Data only —
 * NO secrets, ever. Keys:
 *
 *   label            Human name.
 *   protocol         One of HAL_MCP_PROVIDER_PROTOCOLS.
 *   endpoint         Chat/completion endpoint. '{model}' is substituted for
 *                    gemini-native; other protocols use the URL as-is.
 *   models_endpoint  Model-listing endpoint ('' when the provider has none —
 *                    manual model entry is always available, F15).
 *   default_model    Editable default (concrete documented names; never a
 *                    'latest' alias, per §10.7).
 *   tool_calling     Whether the provider family supports function calling.
 *   list_models      Whether models_endpoint is expected to work.
 *   temperature      Whether the protocol accepts a temperature parameter.
 *   max_output       [ field, default, cap ] — the protocol's output-limit
 *                    field name, the default, and the hard cap. Anthropic
 *                    REQUIRES the field, so 'required' is set for it.
 *   auth             'bearer' | 'x-api-key' | 'x-goog-api-key'.
 *
 * @return array<string, array<string, mixed>>
 */
function hal_mcp_providers(): array {

	/**
	 * Filters the provider registry defaults. Additive corrections only —
	 * the protocol and auth keys of the six built-ins are API surface this
	 * file's adapters implement, and an unknown protocol is refused at
	 * config resolution.
	 *
	 * @param array<string, array<string, mixed>> $providers Provider definitions.
	 */
	return (array) apply_filters(
		'hal_mcp_providers',
		[
			'openai'    => [
				'label'           => __( 'OpenAI', 'hal-mcp' ),
				'protocol'        => 'openai-responses',
				'endpoint'        => 'https://api.openai.com/v1/responses',
				'models_endpoint' => 'https://api.openai.com/v1/models',
				'default_model'   => 'gpt-4o-mini',
				'tool_calling'    => true,
				'list_models'     => true,
				'temperature'     => true,
				'max_output'      => [ 'max_output_tokens', 2048, 32768 ],
				'auth'            => 'bearer',
			],
			'anthropic' => [
				'label'           => __( 'Anthropic (Claude)', 'hal-mcp' ),
				'protocol'        => 'anthropic-messages',
				'endpoint'        => 'https://api.anthropic.com/v1/messages',
				'models_endpoint' => 'https://api.anthropic.com/v1/models',
				'default_model'   => 'claude-3-5-haiku-20241022',
				'tool_calling'    => true,
				'list_models'     => true,
				'temperature'     => true,
				'max_output'      => [ 'max_tokens', 2048, 16384, true ],
				'auth'            => 'x-api-key',
			],
			'gemini'    => [
				'label'           => __( 'Google (Gemini)', 'hal-mcp' ),
				'protocol'        => 'gemini-native',
				'endpoint'        => 'https://generativelanguage.googleapis.com/v1beta/models/{model}:generateContent',
				'models_endpoint' => 'https://generativelanguage.googleapis.com/v1beta/models',
				'default_model'   => 'gemini-2.0-flash',
				'tool_calling'    => true,
				'list_models'     => true,
				'temperature'     => true,
				'max_output'      => [ 'maxOutputTokens', 2048, 32768 ],
				'auth'            => 'x-goog-api-key',
			],
			'glm'       => [
				'label'           => __( 'Z.AI (GLM)', 'hal-mcp' ),
				'protocol'        => 'openai-chat',
				'endpoint'        => 'https://api.z.ai/api/paas/v4/chat/completions',
				'models_endpoint' => 'https://api.z.ai/api/paas/v4/models',
				'default_model'   => 'glm-4-flash',
				'tool_calling'    => true,
				'list_models'     => true,
				'temperature'     => true,
				'max_output'      => [ 'max_tokens', 2048, 32768 ],
				'auth'            => 'bearer',
			],
			'kimi'      => [
				'label'           => __( 'Moonshot (Kimi)', 'hal-mcp' ),
				'protocol'        => 'openai-chat',
				'endpoint'        => 'https://api.moonshot.ai/v1/chat/completions',
				'models_endpoint' => 'https://api.moonshot.ai/v1/models',
				'default_model'   => 'moonshot-v1-8k',
				'tool_calling'    => true,
				'list_models'     => true,
				'temperature'     => true,
				'max_output'      => [ 'max_tokens', 2048, 32768 ],
				'auth'            => 'bearer',
			],
			'qwen'      => [
				'label'           => __( 'Qwen (DashScope)', 'hal-mcp' ),
				'protocol'        => 'openai-chat',
				'endpoint'        => 'https://dashscope-intl.aliyuncs.com/compatible-mode/v1/chat/completions',
				'models_endpoint' => 'https://dashscope-intl.aliyuncs.com/compatible-mode/v1/models',
				'default_model'   => 'qwen-plus',
				'tool_calling'    => true,
				'list_models'     => true,
				'temperature'     => true,
				'max_output'      => [ 'max_tokens', 2048, 32768 ],
				'auth'            => 'bearer',
			],
			'custom'    => [
				'label'           => __( 'Custom provider', 'hal-mcp' ),
				'protocol'        => '',
				'endpoint'        => '',
				'models_endpoint' => '',
				'default_model'   => '',
				'tool_calling'    => true,
				'list_models'     => false,
				'temperature'     => true,
				'max_output'      => [ 'max_tokens', 2048, 32768 ],
				'auth'            => 'bearer',
			],
		]
	);
}

/**
 * Whether a model/provider pair can participate in tool calling (F15: a
 * text-only model is refused by the runner and labeled honestly in the UI).
 * The provider registry decides at family level; the settings layer carries
 * the admin's per-provider text-only override.
 *
 * @param string $provider_id Provider id.
 * @return bool
 */
function hal_mcp_provider_supports_tools( string $provider_id ): bool {

	$hal_mcp_provider = hal_mcp_providers()[ $provider_id ] ?? null;

	if ( ! is_array( $hal_mcp_provider ) ) {
		return false;
	}

	if ( empty( $hal_mcp_provider['tool_calling'] ) ) {
		return false;
	}

	// The F19 settings layer records an explicit per-provider text-only
	// decision; when settings.php is not loaded (or no flag stored), the
	// registry's answer stands.
	if ( function_exists( 'hal_mcp_settings_get' ) ) {
		$hal_mcp_settings = hal_mcp_settings_get();

		if ( ! empty( $hal_mcp_settings['text_only'][ $provider_id ] ) ) {
			return false;
		}
	}

	return true;
}

/**
 * Converts a hal/* ability name to the provider-facing tool name for one
 * protocol. Deterministic and collision-free for this plugin's name space
 * (lowercase alnum, single '/', dashes): '/' becomes '__', and for Gemini
 * dashes become '_' as well.
 *
 * @param string $ability_name hal/* name.
 * @param string $protocol     One of HAL_MCP_PROVIDER_PROTOCOLS.
 * @return string '' when the input is not a well-formed hal/* name.
 */
function hal_mcp_provider_tool_name( string $ability_name, string $protocol ): string {

	// Strict canonical shape: no consecutive dashes, no underscores — the
	// exact namespace this plugin registers. The strictness is what makes
	// the reverse mapping unambiguous ('hal--x' can never round-trip into a
	// valid name).
	if ( ! preg_match( '/^hal\/[a-z0-9]+(-[a-z0-9]+)*$/', $ability_name ) ) {
		return '';
	}

	if ( in_array( $protocol, HAL_MCP_PROVIDER_DASHED_PROTOCOLS, true ) ) {
		return strlen( (string) str_replace( '/', '__', $ability_name ) ) <= 64
			? (string) str_replace( '/', '__', $ability_name )
			: '';
	}

	// gemini-native: must match ^[a-zA-Z_][a-zA-Z0-9_]*$.
	$hal_mcp_name = str_replace( [ '/', '-' ], [ '__', '_' ], $ability_name );

	return strlen( $hal_mcp_name ) <= 64 ? $hal_mcp_name : '';
}

/**
 * The reverse of hal_mcp_provider_tool_name(): a provider-echoed tool name
 * back to its hal/* ability. Deterministic for this plugin's namespace, and
 * the runner ALSO holds the explicit per-call map built from the catalog —
 * the reverse function is the fallback consistency check, never the sole
 * authority (F15: no random function-name execution).
 *
 * @param string $tool_name Provider-facing name.
 * @param string $protocol  One of HAL_MCP_PROVIDER_PROTOCOLS.
 * @return string '' when the name does not map back cleanly.
 */
function hal_mcp_provider_tool_ability( string $tool_name, string $protocol ): string {

	if ( '' === $tool_name || strlen( $tool_name ) > 64 ) {
		return '';
	}

	// For gemini the '__' separator is restored FIRST, then the remaining
	// underscores become dashes: 'hal__get_post' → 'hal/get-post', while a
	// crafted 'hal__get__post' lands as 'hal/get/post' and fails the
	// round-trip check below instead of being guessed.
	$hal_mcp_ability = in_array( $protocol, HAL_MCP_PROVIDER_DASHED_PROTOCOLS, true )
		? (string) preg_replace( '/^hal__/', 'hal/', $tool_name )
		: str_replace( '_', '-', (string) preg_replace( '/^hal__/', 'hal/', $tool_name ) );

	// Round-trip check: the name must convert back to itself, so a crafted
	// name like 'hal__get__post' (which would not round-trip for gemini) is
	// refused instead of guessed.
	return hal_mcp_provider_tool_name( $hal_mcp_ability, $protocol ) === $tool_name ? $hal_mcp_ability : '';
}

/**
 * The auth headers for one provider call. The API key exists here only for
 * the lifetime of this array — it is never logged, never stored, never
 * returned in any result (F15/F19).
 *
 * @param string $auth        Auth mode: 'bearer' | 'x-api-key' | 'x-goog-api-key'.
 * @param string $api_key     The decrypted key (settings layer).
 * @return array<string, string> '' key yields NO auth header (the request
 *                               will fail upstream with the real reason
 *                               instead of a fabricated one here).
 */
function hal_mcp_provider_auth_headers( string $auth, string $api_key ): array {

	if ( '' === $api_key ) {
		return [];
	}

	if ( 'x-api-key' === $auth ) {
		return [
			'x-api-key'         => $api_key,
			'anthropic-version' => '2023-06-01',
		];
	}

	if ( 'x-goog-api-key' === $auth ) {
		return [ 'x-goog-api-key' => $api_key ];
	}

	return [ 'Authorization' => 'Bearer ' . $api_key ];
}

/**
 * Builds the provider request for one tool round and calls the transport.
 *
 * The canonical conversation shape (runner.php owns it) is a list of:
 *   [ 'role' => 'user'|'assistant',
 *     'text' => string,
 *     'tool_calls'   => [ [ 'id', 'name'(hal/*), 'arguments'(array) ], ... ],
 *     'tool_results' => [ [ 'call_id', 'name', 'content'(string), 'is_error'(bool) ], ... ] ]
 *
 * @param array $config Provider config (see hal_mcp_providers() + settings
 *                      merge): protocol, endpoint, model, auth, max_output,
 *                      temperature.
 * @param array $args {
 *     @type array $messages Canonical conversation.
 *     @type array $tools   Tool map: provider tool name => [ 'ability' => hal/*,
 *                          'description' => string, 'parameters' => array ].
 *                          Empty array = no tools offered (text-only path).
 * }
 * @return array|WP_Error {
 *     text: string,
 *     tool_calls: [ [ 'id', 'name'(hal/*), 'arguments'(array) ], ... ],
 *     response_id: string,
 *     finish: string
 * }
 */
function hal_mcp_provider_call( array $config, array $args ) {

	$hal_mcp_protocol = (string) ( $config['protocol'] ?? '' );

	if ( ! in_array( $hal_mcp_protocol, HAL_MCP_PROVIDER_PROTOCOLS, true ) ) {
		return new WP_Error( 'hal_mcp_provider_protocol_unknown', __( 'The provider has no supported protocol configured.', 'hal-mcp' ) );
	}

	$hal_mcp_model = trim( (string) ( $config['model'] ?? '' ) );

	if ( '' === $hal_mcp_model ) {
		return new WP_Error( 'hal_mcp_provider_model_missing', __( 'No model is configured for this provider.', 'hal-mcp' ) );
	}

	if ( ! hal_mcp_provider_model_name_valid( $hal_mcp_model ) ) {
		return new WP_Error( 'hal_mcp_provider_model_invalid', __( 'The configured model name is not a valid model identifier.', 'hal-mcp' ) );
	}

	$hal_mcp_endpoint = hal_mcp_provider_endpoint( $config );

	if ( is_wp_error( $hal_mcp_endpoint ) ) {
		return $hal_mcp_endpoint;
	}

	$hal_mcp_body = match ( $hal_mcp_protocol ) {
		'openai-responses'   => hal_mcp_provider_build_openai_responses( $config, $args ),
		'openai-chat'        => hal_mcp_provider_build_openai_chat( $config, $args ),
		'anthropic-messages' => hal_mcp_provider_build_anthropic( $config, $args ),
		'gemini-native'      => hal_mcp_provider_build_gemini( $config, $args ),
		default              => new WP_Error( 'hal_mcp_provider_protocol_unknown', __( 'The provider has no supported protocol configured.', 'hal-mcp' ) ),
	};

	if ( is_wp_error( $hal_mcp_body ) ) {
		return $hal_mcp_body;
	}

	$hal_mcp_response = hal_mcp_http_post_json(
		$hal_mcp_endpoint,
		[
			'headers' => hal_mcp_provider_auth_headers( (string) ( $config['auth'] ?? 'bearer' ), (string) ( $config['api_key'] ?? '' ) ),
			'body'    => $hal_mcp_body,
			'timeout' => (float) ( $config['timeout'] ?? 60 ),
		]
	);

	if ( is_wp_error( $hal_mcp_response ) ) {
		return $hal_mcp_response;
	}

	return match ( $hal_mcp_protocol ) {
		'openai-responses'   => hal_mcp_provider_parse_openai_responses( $hal_mcp_response['json'] ?? [] ),
		'openai-chat'        => hal_mcp_provider_parse_openai_chat( $hal_mcp_response['json'] ?? [] ),
		'anthropic-messages' => hal_mcp_provider_parse_anthropic( $hal_mcp_response['json'] ?? [] ),
		'gemini-native'      => hal_mcp_provider_parse_gemini( $hal_mcp_response['json'] ?? [] ),
		default              => new WP_Error( 'hal_mcp_provider_protocol_unknown', __( 'The provider has no supported protocol configured.', 'hal-mcp' ) ),
	};
}

/**
 * Resolves the final endpoint URL for one call (model substitution for the
 * Gemini template, endpoint overrides already merged into $config by the
 * settings layer).
 *
 * @param array $config Provider config.
 * @return string|WP_Error
 */
function hal_mcp_provider_endpoint( array $config ) {

	$hal_mcp_endpoint = trim( (string) ( $config['endpoint'] ?? '' ) );
	$hal_mcp_model    = trim( (string) ( $config['model'] ?? '' ) );

	if ( '' === $hal_mcp_endpoint ) {
		return new WP_Error( 'hal_mcp_provider_endpoint_missing', __( 'No endpoint is configured for this provider.', 'hal-mcp' ) );
	}

	$hal_mcp_endpoint = str_replace( '{model}', rawurlencode( $hal_mcp_model ), $hal_mcp_endpoint );

	return hal_mcp_http_validate_endpoint( $hal_mcp_endpoint );
}

/**
 * Syntax validation for a manually entered model name (F15: "إدخال اسم نموذج
 * يدوي متحقق"). Model ids across the six families share a conservative
 * charset; anything outside it is refused rather than sent upstream.
 *
 * @param string $model Model identifier.
 * @return bool
 */
function hal_mcp_provider_model_name_valid( string $model ): bool {
	return (bool) preg_match( '/^[A-Za-z0-9][A-Za-z0-9._:\-\/]{0,127}$/', $model );
}

/**
 * Fetches the provider's model list when it offers one (F15: "قائمة نماذج من
 * API إذا أتاحها المزود"). Failure is a clean WP_Error — the UI falls back
 * to manual entry, never a hard failure.
 *
 * @param array $config Provider config (with models_endpoint + auth + key).
 * @return array<int, array{id: string, label: string}>|WP_Error
 */
function hal_mcp_provider_list_models( array $config ) {

	$hal_mcp_url = trim( (string) ( $config['models_endpoint'] ?? '' ) );

	if ( '' === $hal_mcp_url ) {
		return new WP_Error( 'hal_mcp_provider_models_unavailable', __( 'This provider does not offer a model-list endpoint; enter the model name manually.', 'hal-mcp' ) );
	}

	$hal_mcp_url = hal_mcp_http_validate_endpoint( $hal_mcp_url );

	if ( is_wp_error( $hal_mcp_url ) ) {
		return $hal_mcp_url;
	}

	$hal_mcp_response = hal_mcp_http_get_json(
		$hal_mcp_url,
		[
			'headers' => hal_mcp_provider_auth_headers( (string) ( $config['auth'] ?? 'bearer' ), (string) ( $config['api_key'] ?? '' ) ),
			'timeout' => (float) ( $config['timeout'] ?? 30 ),
		]
	);

	if ( is_wp_error( $hal_mcp_response ) ) {
		return $hal_mcp_response;
	}

	$hal_mcp_json = $hal_mcp_response['json'] ?? [];
	$hal_mcp_rows = [];

	if ( isset( $hal_mcp_json['data'] ) && is_array( $hal_mcp_json['data'] ) ) {
		// OpenAI / Anthropic / OpenAI-compatible families.
		foreach ( $hal_mcp_json['data'] as $hal_mcp_row ) {
			$hal_mcp_id = is_array( $hal_mcp_row ) ? (string) ( $hal_mcp_row['id'] ?? '' ) : '';

			if ( '' !== $hal_mcp_id ) {
				$hal_mcp_rows[] = [
					'id'    => $hal_mcp_id,
					'label' => is_array( $hal_mcp_row ) && isset( $hal_mcp_row['display_name'] ) ? (string) $hal_mcp_row['display_name'] : $hal_mcp_id,
				];
			}
		}
	} elseif ( isset( $hal_mcp_json['models'] ) && is_array( $hal_mcp_json['models'] ) ) {
		// Gemini: names come as "models/gemini-2.0-flash".
		foreach ( $hal_mcp_json['models'] as $hal_mcp_row ) {
			$hal_mcp_name = is_array( $hal_mcp_row ) ? (string) ( $hal_mcp_row['name'] ?? '' ) : '';
			$hal_mcp_name = preg_replace( '#^models/#', '', $hal_mcp_name );

			if ( '' !== (string) $hal_mcp_name ) {
				$hal_mcp_rows[] = [
					'id'    => (string) $hal_mcp_name,
					'label' => is_array( $hal_mcp_row ) && isset( $hal_mcp_row['displayName'] ) ? (string) $hal_mcp_row['displayName'] : (string) $hal_mcp_name,
				];
			}
		}
	}

	if ( [] === $hal_mcp_rows ) {
		return new WP_Error( 'hal_mcp_provider_models_unavailable', __( 'The provider returned no usable model list; enter the model name manually.', 'hal-mcp' ) );
	}

	return $hal_mcp_rows;
}

// ---------------------------------------------------------------------------
// Per-protocol request builders
// ---------------------------------------------------------------------------

/**
 * Merges the optional generation parameters the provider actually supports
 * (F15: "لا إرسال معلمات ثابتة لكل المزودين"). Temperature only when the
 * family accepts it AND the admin configured one; the output-limit field
 * only when configured or when the protocol requires it (Anthropic).
 *
 * @param array $config Provider config (temperature/max_output from settings).
 * @return array<string, mixed>
 */
function hal_mcp_provider_generation_params( array $config ): array {

	$hal_mcp_params = [];

	if ( ! empty( $config['temperature'] ) && isset( $config['temperature_value'] ) && is_numeric( $config['temperature_value'] ) ) {
		$hal_mcp_params['temperature_value'] = min( 2.0, max( 0.0, (float) $config['temperature_value'] ) );
	}

	$hal_mcp_max_output = is_array( $config['max_output'] ?? null ) ? $config['max_output'] : [];

	if ( [] !== $hal_mcp_max_output ) {
		$hal_mcp_field   = (string) ( $hal_mcp_max_output[0] ?? '' );
		$hal_mcp_cap     = (int) ( $hal_mcp_max_output[2] ?? 32768 );
		$hal_mcp_default = (int) ( $hal_mcp_max_output[1] ?? 2048 );
		$hal_mcp_wanted  = isset( $config['max_output_value'] ) && is_numeric( $config['max_output_value'] )
			? (int) $config['max_output_value']
			: $hal_mcp_default;

		if ( '' !== $hal_mcp_field ) {
			$hal_mcp_params['max_output_field'] = $hal_mcp_field;
			$hal_mcp_params['max_output_value'] = min( $hal_mcp_cap, max( 16, $hal_mcp_wanted ) );
		}
	}

	return $hal_mcp_params;
}

/**
 * Builds an OpenAI Responses request body.
 *
 * @param array $config Provider config.
 * @param array $args   See hal_mcp_provider_call().
 * @return array|WP_Error
 */
function hal_mcp_provider_build_openai_responses( array $config, array $args ) {

	$hal_mcp_params = hal_mcp_provider_generation_params( $config );

	$hal_mcp_body = [
		'model' => (string) $config['model'],
		'input' => hal_mcp_provider_openai_responses_items( $args['messages'] ?? [] ),
		'store' => false,
	];

	if ( isset( $hal_mcp_params['max_output_field'] ) ) {
		$hal_mcp_body[ $hal_mcp_params['max_output_field'] ] = $hal_mcp_params['max_output_value'];
	}

	if ( isset( $hal_mcp_params['temperature_value'] ) ) {
		$hal_mcp_body['temperature'] = $hal_mcp_params['temperature_value'];
	}

	if ( ! empty( $args['tools'] ) && hal_mcp_provider_supports_tools( (string) ( $config['id'] ?? '' ) ) ) {
		$hal_mcp_tools = [];

		foreach ( $args['tools'] as $hal_mcp_tool_name => $hal_mcp_tool ) {
			$hal_mcp_tools[] = [
				'type'        => 'function',
				'name'        => (string) $hal_mcp_tool_name,
				'description' => (string) ( $hal_mcp_tool['description'] ?? '' ),
				'parameters'  => is_array( $hal_mcp_tool['parameters'] ?? null ) ? $hal_mcp_tool['parameters'] : [ 'type' => 'object', 'properties' => [] ],
			];
		}

		$hal_mcp_body['tools']       = $hal_mcp_tools;
		$hal_mcp_body['tool_choice'] = 'auto';
	}

	return $hal_mcp_body;
}

/**
 * Builds the Responses input items from the canonical conversation:
 * text/role items, function_call items, and their function_call_output
 * follow-ups, in conversation order (F15: IDs preserved so the model sees a
 * coherent continuation and completed work is never re-created).
 *
 * @param array $messages Canonical conversation.
 * @return array<int, array<string, mixed>>
 */
function hal_mcp_provider_openai_responses_items( array $messages ): array {

	$hal_mcp_items = [];

	foreach ( $messages as $hal_mcp_message ) {
		$hal_mcp_role = ( 'assistant' === ( $hal_mcp_message['role'] ?? '' ) ) ? 'assistant' : 'user';
		$hal_mcp_text = trim( (string) ( $hal_mcp_message['text'] ?? '' ) );

		if ( '' !== $hal_mcp_text ) {
			$hal_mcp_items[] = [
				'role'    => $hal_mcp_role,
				'content' => [
					[
						'type' => 'assistant' === $hal_mcp_role ? 'output_text' : 'input_text',
						'text' => $hal_mcp_text,
					],
				],
			];
		}

		foreach ( (array) ( $hal_mcp_message['tool_calls'] ?? [] ) as $hal_mcp_call ) {
			$hal_mcp_items[] = [
				'type'       => 'function_call',
				'call_id'    => (string) ( $hal_mcp_call['id'] ?? '' ),
				'name'       => hal_mcp_provider_tool_name( (string) ( $hal_mcp_call['name'] ?? '' ), 'openai-responses' ),
				'arguments'  => (string) wp_json_encode( $hal_mcp_call['arguments'] ?? [] ),
			];
		}

		foreach ( (array) ( $hal_mcp_message['tool_results'] ?? [] ) as $hal_mcp_result ) {
			$hal_mcp_items[] = [
				'type'    => 'function_call_output',
				'call_id' => (string) ( $hal_mcp_result['call_id'] ?? '' ),
				'output'  => (string) ( $hal_mcp_result['content'] ?? '' ),
			];
		}
	}

	return $hal_mcp_items;
}

/**
 * Builds an OpenAI-compatible Chat Completions request body (GLM/Kimi/Qwen
 * and the custom provider's openai-chat protocol).
 *
 * @param array $config Provider config.
 * @param array $args   See hal_mcp_provider_call().
 * @return array|WP_Error
 */
function hal_mcp_provider_build_openai_chat( array $config, array $args ) {

	$hal_mcp_params = hal_mcp_provider_generation_params( $config );

	$hal_mcp_body = [
		'model'    => (string) $config['model'],
		'messages' => hal_mcp_provider_openai_chat_messages( $args['messages'] ?? [] ),
	];

	if ( isset( $hal_mcp_params['max_output_field'] ) ) {
		$hal_mcp_body[ $hal_mcp_params['max_output_field'] ] = $hal_mcp_params['max_output_value'];
	}

	if ( isset( $hal_mcp_params['temperature_value'] ) ) {
		$hal_mcp_body['temperature'] = $hal_mcp_params['temperature_value'];
	}

	if ( ! empty( $args['tools'] ) && hal_mcp_provider_supports_tools( (string) ( $config['id'] ?? '' ) ) ) {
		$hal_mcp_tools = [];

		foreach ( $args['tools'] as $hal_mcp_tool_name => $hal_mcp_tool ) {
			$hal_mcp_tools[] = [
				'type'     => 'function',
				'function' => [
					'name'        => (string) $hal_mcp_tool_name,
					'description' => (string) ( $hal_mcp_tool['description'] ?? '' ),
					'parameters'  => is_array( $hal_mcp_tool['parameters'] ?? null ) ? $hal_mcp_tool['parameters'] : [ 'type' => 'object', 'properties' => [] ],
				],
			];
		}

		$hal_mcp_body['tools']       = $hal_mcp_tools;
		$hal_mcp_body['tool_choice'] = 'auto';
	}

	return $hal_mcp_body;
}

/**
 * Builds Chat Completions messages from the canonical conversation. A
 * message carrying BOTH text and tool_calls is emitted as ONE assistant
 * message (content = text or null) with the tool_calls — the text is never
 * duplicated into a standalone message; text-only messages are unchanged.
 *
 * @param array $messages Canonical conversation.
 * @return array<int, array<string, mixed>>
 */
function hal_mcp_provider_openai_chat_messages( array $messages ): array {

	$hal_mcp_out = [];

	foreach ( $messages as $hal_mcp_message ) {
		$hal_mcp_text = trim( (string) ( $hal_mcp_message['text'] ?? '' ) );
		$hal_mcp_role = ( 'assistant' === ( $hal_mcp_message['role'] ?? '' ) ) ? 'assistant' : 'user';
		$hal_mcp_calls = ! empty( $hal_mcp_message['tool_calls'] );

		// The standalone text message is emitted only when there are no tool
		// calls; with tool_calls the text rides in the single assistant
		// message below (content = text or null) instead of a duplicate.
		if ( '' !== $hal_mcp_text && ! $hal_mcp_calls ) {
			$hal_mcp_out[] = [
				'role'    => $hal_mcp_role,
				'content' => $hal_mcp_text,
			];
		}

		if ( $hal_mcp_calls ) {
			$hal_mcp_row           = [
				'role'       => 'assistant',
				'content'    => '' !== $hal_mcp_text ? $hal_mcp_text : null,
				'tool_calls' => [],
			];

			foreach ( (array) $hal_mcp_message['tool_calls'] as $hal_mcp_call ) {
				$hal_mcp_row['tool_calls'][] = [
					'id'       => (string) ( $hal_mcp_call['id'] ?? '' ),
					'type'     => 'function',
					'function' => [
						'name'      => hal_mcp_provider_tool_name( (string) ( $hal_mcp_call['name'] ?? '' ), 'openai-chat' ),
						'arguments' => (string) wp_json_encode( $hal_mcp_call['arguments'] ?? [] ),
					],
				];
			}

			$hal_mcp_out[] = $hal_mcp_row;
		}

		foreach ( (array) ( $hal_mcp_message['tool_results'] ?? [] ) as $hal_mcp_result ) {
			$hal_mcp_out[] = [
				'role'         => 'tool',
				'tool_call_id' => (string) ( $hal_mcp_result['call_id'] ?? '' ),
				'content'      => (string) ( $hal_mcp_result['content'] ?? '' ),
			];
		}
	}

	return $hal_mcp_out;
}

/**
 * Builds an Anthropic Messages request body. max_tokens is REQUIRED by this
 * protocol — the generation-params merge always yields the field for it.
 *
 * @param array $config Provider config.
 * @param array $args   See hal_mcp_provider_call().
 * @return array|WP_Error
 */
function hal_mcp_provider_build_anthropic( array $config, array $args ) {

	$hal_mcp_params = hal_mcp_provider_generation_params( $config );

	$hal_mcp_messages = hal_mcp_provider_anthropic_messages( $args['messages'] ?? [] );

	$hal_mcp_body = [
		'model'      => (string) $config['model'],
		'max_tokens' => (int) ( $hal_mcp_params['max_output_value'] ?? 2048 ),
		'messages'   => $hal_mcp_messages,
	];

	if ( isset( $hal_mcp_params['temperature_value'] ) ) {
		$hal_mcp_body['temperature'] = $hal_mcp_params['temperature_value'];
	}

	if ( ! empty( $args['tools'] ) && hal_mcp_provider_supports_tools( (string) ( $config['id'] ?? '' ) ) ) {
		$hal_mcp_tools = [];

		foreach ( $args['tools'] as $hal_mcp_tool_name => $hal_mcp_tool ) {
			$hal_mcp_tools[] = [
				'name'         => (string) $hal_mcp_tool_name,
				'description'  => (string) ( $hal_mcp_tool['description'] ?? '' ),
				'input_schema' => is_array( $hal_mcp_tool['parameters'] ?? null ) ? $hal_mcp_tool['parameters'] : [ 'type' => 'object', 'properties' => [] ],
			];
		}

		$hal_mcp_body['tools'] = $hal_mcp_tools;
	}

	return $hal_mcp_body;
}

/**
 * Builds Anthropic messages: text goes in role messages, tool_use blocks sit
 * in the assistant message, and every tool_result lands in the immediately
 * following user message (Anthropic's required shape).
 *
 * @param array $messages Canonical conversation.
 * @return array<int, array<string, mixed>>
 */
function hal_mcp_provider_anthropic_messages( array $messages ): array {

	$hal_mcp_out = [];

	foreach ( $messages as $hal_mcp_message ) {
		$hal_mcp_is_assistant = 'assistant' === ( $hal_mcp_message['role'] ?? '' );
		$hal_mcp_text         = trim( (string) ( $hal_mcp_message['text'] ?? '' ) );

		if ( $hal_mcp_is_assistant ) {
			$hal_mcp_content = [];

			if ( '' !== $hal_mcp_text ) {
				$hal_mcp_content[] = [ 'type' => 'text', 'text' => $hal_mcp_text ];
			}

			foreach ( (array) ( $hal_mcp_message['tool_calls'] ?? [] ) as $hal_mcp_call ) {
				$hal_mcp_content[] = [
					'type'  => 'tool_use',
					'id'    => (string) ( $hal_mcp_call['id'] ?? '' ),
					'name'  => hal_mcp_provider_tool_name( (string) ( $hal_mcp_call['name'] ?? '' ), 'anthropic-messages' ),
					'input' => is_array( $hal_mcp_call['arguments'] ?? null ) ? $hal_mcp_call['arguments'] : [],
				];
			}

			if ( [] !== $hal_mcp_content ) {
				$hal_mcp_out[] = [
					'role'    => 'assistant',
					'content' => $hal_mcp_content,
				];
			}

			continue;
		}

		$hal_mcp_results = (array) ( $hal_mcp_message['tool_results'] ?? [] );

		if ( [] !== $hal_mcp_results ) {
			$hal_mcp_content = [];

			foreach ( $hal_mcp_results as $hal_mcp_result ) {
				$hal_mcp_content[] = [
					'type'        => 'tool_result',
					'tool_use_id' => (string) ( $hal_mcp_result['call_id'] ?? '' ),
					'content'     => (string) ( $hal_mcp_result['content'] ?? '' ),
					'is_error'    => ! empty( $hal_mcp_result['is_error'] ),
				];
			}

			if ( '' !== $hal_mcp_text ) {
				array_unshift( $hal_mcp_content, [ 'type' => 'text', 'text' => $hal_mcp_text ] );
			}

			$hal_mcp_out[] = [
				'role'    => 'user',
				'content' => $hal_mcp_content,
			];

			continue;
		}

		if ( '' !== $hal_mcp_text ) {
			$hal_mcp_out[] = [
				'role'    => 'user',
				'content' => [ [ 'type' => 'text', 'text' => $hal_mcp_text ] ],
			];
		}
	}

	return $hal_mcp_out;
}

/**
 * Builds a Gemini generateContent request body: contents with parts, schemas
 * normalized to Gemini's subset, output limit under generationConfig.
 *
 * @param array $config Provider config.
 * @param array $args   See hal_mcp_provider_call().
 * @return array|WP_Error
 */
function hal_mcp_provider_build_gemini( array $config, array $args ) {

	$hal_mcp_params = hal_mcp_provider_generation_params( $config );

	$hal_mcp_body = [
		'contents' => hal_mcp_provider_gemini_contents( $args['messages'] ?? [] ),
	];

	if ( isset( $hal_mcp_params['max_output_field'] ) ) {
		$hal_mcp_body['generationConfig'] = [ 'maxOutputTokens' => (int) $hal_mcp_params['max_output_value'] ];
	}

	if ( isset( $hal_mcp_params['temperature_value'] ) ) {
		$hal_mcp_body['generationConfig']['temperature'] = $hal_mcp_params['temperature_value'];
	}

	if ( ! empty( $args['tools'] ) && hal_mcp_provider_supports_tools( (string) ( $config['id'] ?? '' ) ) ) {
		$hal_mcp_declarations = [];

		foreach ( $args['tools'] as $hal_mcp_tool_name => $hal_mcp_tool ) {
			$hal_mcp_declarations[] = [
				'name'        => (string) $hal_mcp_tool_name,
				'description' => (string) ( $hal_mcp_tool['description'] ?? '' ),
				'parameters'  => hal_mcp_provider_gemini_schema( is_array( $hal_mcp_tool['parameters'] ?? null ) ? $hal_mcp_tool['parameters'] : [ 'type' => 'object', 'properties' => [] ] ),
			];
		}

		$hal_mcp_body['tools'] = [ [ 'functionDeclarations' => $hal_mcp_declarations ] ];
	}

	return $hal_mcp_body;
}

/**
 * Builds Gemini contents from the canonical conversation. Function calls
 * become functionCall parts in 'model' role; results become functionResponse
 * parts in 'user' role (Gemini has no call IDs — the NAME pairs them).
 *
 * Consecutive same-role entries are MERGED into one contents entry: Gemini
 * generateContent rejects multiturn requests whose user roles do not
 * alternate, and a resume-with-text after a tool-results round would
 * otherwise emit two consecutive user entries (a verified 400).
 *
 * @param array $messages Canonical conversation.
 * @return array<int, array<string, mixed>>
 */
function hal_mcp_provider_gemini_contents( array $messages ): array {

	$hal_mcp_out = [];

	foreach ( $messages as $hal_mcp_message ) {
		$hal_mcp_is_model = 'assistant' === ( $hal_mcp_message['role'] ?? '' );
		$hal_mcp_role     = $hal_mcp_is_model ? 'model' : 'user';
		$hal_mcp_text     = trim( (string) ( $hal_mcp_message['text'] ?? '' ) );
		$hal_mcp_parts    = [];

		if ( '' !== $hal_mcp_text ) {
			$hal_mcp_parts[] = [ 'text' => $hal_mcp_text ];
		}

		foreach ( (array) ( $hal_mcp_message['tool_calls'] ?? [] ) as $hal_mcp_call ) {
			$hal_mcp_parts[] = [
				'functionCall' => [
					'name' => hal_mcp_provider_tool_name( (string) ( $hal_mcp_call['name'] ?? '' ), 'gemini-native' ),
					'args' => is_array( $hal_mcp_call['arguments'] ?? null ) ? $hal_mcp_call['arguments'] : [],
				],
			];
		}

		foreach ( (array) ( $hal_mcp_message['tool_results'] ?? [] ) as $hal_mcp_result ) {
			$hal_mcp_parts[] = [
				'functionResponse' => [
					// The response pairs by NAME with the preceding call.
					'name'     => hal_mcp_provider_tool_name( (string) ( $hal_mcp_result['name'] ?? '' ), 'gemini-native' ),
					'response' => [ 'result' => (string) ( $hal_mcp_result['content'] ?? '' ) ],
				],
			];
		}

		if ( [] === $hal_mcp_parts ) {
			continue;
		}

		// Alternation guard: merge into the previous entry when it carries
		// the same role (e.g. tool results followed by resumed user text).
		$hal_mcp_last = count( $hal_mcp_out ) - 1;

		if ( $hal_mcp_last >= 0 && ( $hal_mcp_out[ $hal_mcp_last ]['role'] ?? '' ) === $hal_mcp_role ) {
			$hal_mcp_out[ $hal_mcp_last ]['parts'] = array_merge( $hal_mcp_out[ $hal_mcp_last ]['parts'], $hal_mcp_parts );
			continue;
		}

		$hal_mcp_out[] = [
			'role'  => $hal_mcp_role,
			'parts' => $hal_mcp_parts,
		];
	}

	return $hal_mcp_out;
}

/**
 * Normalizes a JSON-schema tool parameter definition to Gemini's accepted
 * subset: 'type' values upper-cased, 'additionalProperties' dropped (Gemini
 * rejects it), everything else passed through recursively.
 *
 * @param mixed $schema Schema node.
 * @return array<string, mixed>
 */
function hal_mcp_provider_gemini_schema( $schema ): array {

	if ( ! is_array( $schema ) ) {
		return [];
	}

	$hal_mcp_clean = [];

	foreach ( $schema as $hal_mcp_key => $hal_mcp_value ) {
		if ( 'additionalProperties' === $hal_mcp_key ) {
			continue;
		}

		if ( 'type' === $hal_mcp_key && is_string( $hal_mcp_value ) ) {
			$hal_mcp_clean['type'] = strtoupper( $hal_mcp_value );
			continue;
		}

		if ( is_array( $hal_mcp_value ) ) {
			if ( 'properties' === $hal_mcp_key ) {
				$hal_mcp_clean['properties'] = [];

				foreach ( $hal_mcp_value as $hal_mcp_prop_key => $hal_mcp_prop_value ) {
					$hal_mcp_clean['properties'][ (string) $hal_mcp_prop_key ] = hal_mcp_provider_gemini_schema( $hal_mcp_prop_value );
				}

				continue;
			}

			if ( 'items' === $hal_mcp_key ) {
				$hal_mcp_clean['items'] = hal_mcp_provider_gemini_schema( $hal_mcp_value );
				continue;
			}

			if ( in_array( $hal_mcp_key, [ 'required', 'enum', 'description', 'format' ], true ) ) {
				$hal_mcp_clean[ $hal_mcp_key ] = $hal_mcp_value;
			}
		}
	}

	return $hal_mcp_clean;
}

// ---------------------------------------------------------------------------
// Per-protocol response parsers
// ---------------------------------------------------------------------------

/**
 * Shared final shape for every parser (F15: "نتيجة كل نداء يحافظ على معرفه").
 *
 * @param string $hal_mcp_text       Assistant text ('' when none).
 * @param array  $hal_mcp_tool_calls [ ['id','name'(hal/*),'arguments'(array)] ].
 * @param string $hal_mcp_response_id Provider response id ('' when none).
 * @param string $hal_mcp_finish      Finish reason ('' when unknown).
 * @return array
 */
function hal_mcp_provider_result( string $hal_mcp_text, array $hal_mcp_tool_calls, string $hal_mcp_response_id, string $hal_mcp_finish ): array {
	return [
		'text'        => $hal_mcp_text,
		'tool_calls'  => $hal_mcp_tool_calls,
		'response_id' => $hal_mcp_response_id,
		'finish'      => $hal_mcp_finish,
	];
}

/**
 * Parses an OpenAI Responses envelope.
 *
 * @param array $hal_mcp_json Parsed response JSON.
 * @return array|WP_Error
 */
function hal_mcp_provider_parse_openai_responses( array $hal_mcp_json ) {

	if ( isset( $hal_mcp_json['error'] ) ) {
		return new WP_Error(
			'hal_mcp_provider_error',
			(string) hal_mcp_http_provider_error_message( (string) wp_json_encode( $hal_mcp_json ) ?: '' )
		);
	}

	$hal_mcp_text       = '';
	$hal_mcp_tool_calls = [];

	foreach ( (array) ( $hal_mcp_json['output'] ?? [] ) as $hal_mcp_item ) {
		if ( ! is_array( $hal_mcp_item ) ) {
			continue;
		}

		if ( 'message' === ( $hal_mcp_item['type'] ?? '' ) ) {
			foreach ( (array) ( $hal_mcp_item['content'] ?? [] ) as $hal_mcp_block ) {
				if ( is_array( $hal_mcp_block ) && 'output_text' === ( $hal_mcp_block['type'] ?? '' ) ) {
					$hal_mcp_text .= (string) ( $hal_mcp_block['text'] ?? '' );
				}
			}

			continue;
		}

		if ( 'function_call' === ( $hal_mcp_item['type'] ?? '' ) ) {
			$hal_mcp_ability = hal_mcp_provider_tool_ability( (string) ( $hal_mcp_item['name'] ?? '' ), 'openai-responses' );

			if ( '' === $hal_mcp_ability ) {
				return new WP_Error(
					'hal_mcp_provider_unknown_tool',
					__( 'The model requested a tool this site does not offer; the call was refused, not executed.', 'hal-mcp' )
				);
			}

			$hal_mcp_tool_calls[] = [
				'id'        => (string) ( $hal_mcp_item['call_id'] ?? $hal_mcp_item['id'] ?? '' ),
				'name'      => $hal_mcp_ability,
				'arguments' => hal_mcp_provider_decode_arguments( (string) ( $hal_mcp_item['arguments'] ?? '' ) ),
			];
		}
	}

	if ( 'failed' === ( $hal_mcp_json['status'] ?? '' ) && [] === $hal_mcp_tool_calls && '' === $hal_mcp_text ) {
		return new WP_Error(
			'hal_mcp_provider_error',
			(string) hal_mcp_http_provider_error_message( (string) wp_json_encode( $hal_mcp_json['error'] ?? $hal_mcp_json ) )
		);
	}

	return hal_mcp_provider_result( $hal_mcp_text, $hal_mcp_tool_calls, (string) ( $hal_mcp_json['id'] ?? '' ), (string) ( $hal_mcp_json['status'] ?? '' ) );
}

/**
 * Parses an OpenAI-compatible Chat Completions envelope.
 *
 * @param array $hal_mcp_json Parsed response JSON.
 * @return array|WP_Error
 */
function hal_mcp_provider_parse_openai_chat( array $hal_mcp_json ) {

	if ( isset( $hal_mcp_json['error'] ) ) {
		return new WP_Error(
			'hal_mcp_provider_error',
			(string) hal_mcp_http_provider_error_message( (string) wp_json_encode( $hal_mcp_json ) )
		);
	}

	$hal_mcp_choice = $hal_mcp_json['choices'][0] ?? null;

	if ( ! is_array( $hal_mcp_choice ) || ! isset( $hal_mcp_choice['message'] ) ) {
		return new WP_Error( 'hal_mcp_provider_bad_response', __( 'The provider response had no usable message.', 'hal-mcp' ) );
	}

	$hal_mcp_message    = (array) $hal_mcp_choice['message'];
	$hal_mcp_text       = is_string( $hal_mcp_message['content'] ?? null ) ? (string) $hal_mcp_message['content'] : '';
	$hal_mcp_tool_calls = [];

	foreach ( (array) ( $hal_mcp_message['tool_calls'] ?? [] ) as $hal_mcp_call ) {
		if ( ! is_array( $hal_mcp_call ) ) {
			continue;
		}

		$hal_mcp_ability = hal_mcp_provider_tool_ability( (string) ( $hal_mcp_call['function']['name'] ?? '' ), 'openai-chat' );

		if ( '' === $hal_mcp_ability ) {
			return new WP_Error(
				'hal_mcp_provider_unknown_tool',
				__( 'The model requested a tool this site does not offer; the call was refused, not executed.', 'hal-mcp' )
			);
		}

		$hal_mcp_tool_calls[] = [
			'id'        => (string) ( $hal_mcp_call['id'] ?? '' ),
			'name'      => $hal_mcp_ability,
			'arguments' => hal_mcp_provider_decode_arguments( (string) ( $hal_mcp_call['function']['arguments'] ?? '' ) ),
		];
	}

	return hal_mcp_provider_result( $hal_mcp_text, $hal_mcp_tool_calls, (string) ( $hal_mcp_json['id'] ?? '' ), (string) ( $hal_mcp_choice['finish_reason'] ?? '' ) );
}

/**
 * Parses an Anthropic Messages envelope.
 *
 * @param array $hal_mcp_json Parsed response JSON.
 * @return array|WP_Error
 */
function hal_mcp_provider_parse_anthropic( array $hal_mcp_json ) {

	if ( isset( $hal_mcp_json['error'] ) ) {
		return new WP_Error(
			'hal_mcp_provider_error',
			(string) hal_mcp_http_provider_error_message( (string) wp_json_encode( $hal_mcp_json ) )
		);
	}

	if ( ! isset( $hal_mcp_json['content'] ) || ! is_array( $hal_mcp_json['content'] ) ) {
		return new WP_Error( 'hal_mcp_provider_bad_response', __( 'The provider response had no usable content.', 'hal-mcp' ) );
	}

	$hal_mcp_text       = '';
	$hal_mcp_tool_calls = [];

	foreach ( $hal_mcp_json['content'] as $hal_mcp_block ) {
		if ( ! is_array( $hal_mcp_block ) ) {
			continue;
		}

		if ( 'text' === ( $hal_mcp_block['type'] ?? '' ) ) {
			$hal_mcp_text .= (string) ( $hal_mcp_block['text'] ?? '' );
			continue;
		}

		if ( 'tool_use' === ( $hal_mcp_block['type'] ?? '' ) ) {
			$hal_mcp_ability = hal_mcp_provider_tool_ability( (string) ( $hal_mcp_block['name'] ?? '' ), 'anthropic-messages' );

			if ( '' === $hal_mcp_ability ) {
				return new WP_Error(
					'hal_mcp_provider_unknown_tool',
					__( 'The model requested a tool this site does not offer; the call was refused, not executed.', 'hal-mcp' )
				);
			}

			$hal_mcp_tool_calls[] = [
				'id'        => (string) ( $hal_mcp_block['id'] ?? '' ),
				'name'      => $hal_mcp_ability,
				'arguments' => is_array( $hal_mcp_block['input'] ?? null ) ? $hal_mcp_block['input'] : [],
			];
		}
	}

	return hal_mcp_provider_result( $hal_mcp_text, $hal_mcp_tool_calls, (string) ( $hal_mcp_json['id'] ?? '' ), (string) ( $hal_mcp_json['stop_reason'] ?? '' ) );
}

/**
 * Parses a Gemini generateContent envelope. Gemini has no call IDs —
 * deterministic synthetic ids (gemini-call-1, gemini-call-2, ...) are minted
 * here in part order, which is exactly how the results are paired back later.
 *
 * @param array $hal_mcp_json Parsed response JSON.
 * @return array|WP_Error
 */
function hal_mcp_provider_parse_gemini( array $hal_mcp_json ) {

	if ( isset( $hal_mcp_json['error'] ) ) {
		return new WP_Error(
			'hal_mcp_provider_error',
			(string) hal_mcp_http_provider_error_message( (string) wp_json_encode( $hal_mcp_json ) )
		);
	}

	$hal_mcp_candidate = $hal_mcp_json['candidates'][0] ?? null;

	if ( ! is_array( $hal_mcp_candidate ) || ! isset( $hal_mcp_candidate['content']['parts'] ) ) {
		return new WP_Error( 'hal_mcp_provider_bad_response', __( 'The provider response had no usable candidates.', 'hal-mcp' ) );
	}

	$hal_mcp_text       = '';
	$hal_mcp_tool_calls = [];
	$hal_mcp_counter    = 0;

	foreach ( (array) $hal_mcp_candidate['content']['parts'] as $hal_mcp_part ) {
		if ( ! is_array( $hal_mcp_part ) ) {
			continue;
		}

		if ( isset( $hal_mcp_part['text'] ) && is_string( $hal_mcp_part['text'] ) ) {
			$hal_mcp_text .= $hal_mcp_part['text'];
			continue;
		}

		if ( isset( $hal_mcp_part['functionCall'] ) && is_array( $hal_mcp_part['functionCall'] ) ) {
			$hal_mcp_ability = hal_mcp_provider_tool_ability( (string) ( $hal_mcp_part['functionCall']['name'] ?? '' ), 'gemini-native' );

			if ( '' === $hal_mcp_ability ) {
				return new WP_Error(
					'hal_mcp_provider_unknown_tool',
					__( 'The model requested a tool this site does not offer; the call was refused, not executed.', 'hal-mcp' )
				);
			}

			++$hal_mcp_counter;

			$hal_mcp_tool_calls[] = [
				'id'        => 'gemini-call-' . $hal_mcp_counter,
				'name'      => $hal_mcp_ability,
				'arguments' => is_array( $hal_mcp_part['functionCall']['args'] ?? null ) ? $hal_mcp_part['functionCall']['args'] : [],
			];
		}
	}

	return hal_mcp_provider_result( $hal_mcp_text, $hal_mcp_tool_calls, (string) ( $hal_mcp_json['responseId'] ?? '' ), (string) ( $hal_mcp_candidate['finishReason'] ?? '' ) );
}

/**
 * Decodes a provider-echoed tool-arguments JSON string. Bad JSON becomes an
 * empty array — the ability's own input validation refuses it downstream
 * with the real reason instead of a parse error here.
 *
 * @param string $hal_mcp_raw Raw arguments string.
 * @return array<string, mixed>
 */
function hal_mcp_provider_decode_arguments( string $hal_mcp_raw ): array {

	if ( '' === trim( $hal_mcp_raw ) ) {
		return [];
	}

	$hal_mcp_decoded = json_decode( $hal_mcp_raw, true );

	return is_array( $hal_mcp_decoded ) ? $hal_mcp_decoded : [];
}
