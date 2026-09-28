<?php
/**
 * hal-mcp-abilities — settings, secrets, and policy (F19).
 *
 * Registers this plugin's options through the Settings API and owns the
 * SECRET layer everything else reads from:
 *
 * - hal_mcp_settings   Non-secret configuration: active provider, model per
 *                      provider, endpoint overrides, generation parameters,
 *                      text-only flags, policy limits, uninstall data choice.
 *                      Saved through the Settings API (options.php), admins
 *                      only.
 * - hal_mcp_secrets    API keys, ENCRYPTED, stored with autoload OFF. Keys
 *                      are never written into hal_mcp_settings, never echoed
 *                      back to a browser after save (the UI shows a masked
 *                      fingerprint only), and never returned by any getter —
 *                      only decrypted into a request header at call time.
 * - hal_mcp_connection_tests  Last connection-test outcome per provider, for
 *                      the Overview step states ("a saved key is not a
 *                      successful connection" — the test record is what says
 *                      connected, and only after an admin asked for it).
 *
 * Encryption (F19: "تشفير موثق مصادق عليه ... أو قراءة مفتاح من البيئة؛ لا
 * fallback إلى plaintext عند فشل التشفير"):
 * - AES-256-GCM via OpenSSL when the extension exists; auth tag stored with
 *   the ciphertext; a fresh 12-byte IV per write.
 * - Key material: HAL_MCP_SECRET_KEY from the environment when set, else
 *   derived server-side from WordPress salts. Each stored secret row carries
 *   two fingerprints and no key material: 'kf' — 8 hex chars of the SHA-256
 *   of the SEALED blob, so it identifies one provider's saved key (it
 *   differs per provider, changes on every key replace, and doubles as the
 *   masked hint the UI renders) — and 'kkf', the site-key fingerprint at
 *   store time, so a site-secret change fails decryption with a dedicated
 *   code and the UI asks for re-entry instead of guessing.
 * - No plaintext fallback: when OpenSSL is missing or encryption fails, the
 *   save is REFUSED with an error and the provider stays unconfigured.
 *
 * The uninstall choice is read by uninstall.php as a strict boolean; the
 * sanitizer here only ever writes true/false, and the checkbox path is the
 * only writer (an admin POST through options.php).
 *
 * @package hal-mcp-abilities
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Non-secret configuration option.
 *
 * @var string
 */
const HAL_MCP_SETTINGS_OPTION = 'hal_mcp_settings';

/**
 * Encrypted secrets option (autoload off).
 *
 * @var string
 */
const HAL_MCP_SECRETS_OPTION = 'hal_mcp_secrets';

/**
 * Connection-test outcomes option (autoload off).
 *
 * @var string
 */
const HAL_MCP_TESTS_OPTION = 'hal_mcp_connection_tests';

/**
 * Registers the Settings API entry points. Hooked to admin_init — nothing
 * runs at file load, so a stub-only test environment can require this file
 * safely.
 *
 * @return void
 */
function hal_mcp_settings_register(): void {

	register_setting(
		'hal_mcp_settings_group',
		HAL_MCP_SETTINGS_OPTION,
		[
			'type'              => 'array',
			'sanitize_callback' => 'hal_mcp_settings_sanitize',
			'default'           => [],
			'show_in_rest'      => false,
		]
	);
}

add_action( 'admin_init', 'hal_mcp_settings_register' );

/**
 * The settings store merged over registry defaults, normalized. This is the
 * single read path for every consumer (providers config, UI rendering) —
 * raw get_option() elsewhere risks drifting shapes.
 *
 * @return array<string, mixed>
 */
function hal_mcp_settings_get(): array {

	$hal_mcp_stored = get_option( HAL_MCP_SETTINGS_OPTION, [] );
	$hal_mcp_stored = is_array( $hal_mcp_stored ) ? $hal_mcp_stored : [];

	$hal_mcp_defaults = [
		'provider'                 => '',
		'models'                   => [],
		'endpoints'                => [],
		'temperature'              => [],
		'max_output'               => [],
		'text_only'                => [],
		'custom'                   => [ 'protocol' => '' ],
		'policy'                   => [ 'max_rounds' => HAL_MCP_RUNNER_DEFAULT_ROUNDS, 'max_seconds' => HAL_MCP_RUNNER_DEFAULT_SECONDS ],
		'delete_data_on_uninstall' => false,
	];

	$hal_mcp_settings = array_merge( $hal_mcp_defaults, $hal_mcp_stored );

	foreach ( [ 'models', 'endpoints', 'temperature', 'max_output', 'text_only' ] as $hal_mcp_map ) {
		$hal_mcp_settings[ $hal_mcp_map ] = is_array( $hal_mcp_settings[ $hal_mcp_map ] ) ? $hal_mcp_settings[ $hal_mcp_map ] : [];
	}

	$hal_mcp_settings['custom']                   = is_array( $hal_mcp_settings['custom'] ) ? $hal_mcp_settings['custom'] : $hal_mcp_defaults['custom'];
	$hal_mcp_settings['policy']                   = is_array( $hal_mcp_settings['policy'] ) ? $hal_mcp_settings['policy'] : $hal_mcp_defaults['policy'];
	$hal_mcp_settings['delete_data_on_uninstall'] = ( true === $hal_mcp_settings['delete_data_on_uninstall'] );

	return $hal_mcp_settings;
}

/**
 * The Settings API sanitize callback — the ONLY writer of the settings
 * option. Everything admin-side POSTs through here, so validation, secret
 * extraction, and the strict uninstall boolean all live in one place.
 *
 * API keys arrive inside hal_mcp_settings[api_keys]; they are immediately
 * extracted, encrypted into the secrets option, and STRIPPED from the
 * returned (stored) array. A stored settings array never contains key
 * material.
 *
 * @param mixed $input Raw submitted settings.
 * @return array<string, mixed> The clean settings to store.
 */
function hal_mcp_settings_sanitize( $input ): array {

	$hal_mcp_input   = is_array( $input ) ? $input : [];
	$hal_mcp_clean   = hal_mcp_settings_get();
	$hal_mcp_errors  = [];
	$hal_mcp_providers = hal_mcp_providers();

	$hal_mcp_provider = (string) ( $hal_mcp_input['provider'] ?? '' );

	if ( '' !== $hal_mcp_provider && ! isset( $hal_mcp_providers[ $hal_mcp_provider ] ) ) {
		$hal_mcp_errors[] = __( 'Unknown provider selection ignored.', 'hal-mcp' );
		$hal_mcp_provider = '';
	}

	$hal_mcp_clean['provider'] = $hal_mcp_provider;

	// Models: syntax-validated per provider; unknown provider rows dropped.
	$hal_mcp_models = [];

	foreach ( (array) ( $hal_mcp_input['models'] ?? [] ) as $hal_mcp_id => $hal_mcp_model ) {
		$hal_mcp_id = sanitize_key( (string) $hal_mcp_id );

		if ( ! isset( $hal_mcp_providers[ $hal_mcp_id ] ) ) {
			continue;
		}

		$hal_mcp_model = trim( (string) $hal_mcp_model );

		if ( '' === $hal_mcp_model ) {
			continue;
		}

		if ( ! hal_mcp_provider_model_name_valid( $hal_mcp_model ) ) {
			$hal_mcp_errors[] = sprintf(
				/* translators: 1: provider id, 2: rejected model name. */
				__( 'The model name "%2$s" for %1$s was rejected: it is not a valid model identifier.', 'hal-mcp' ),
				$hal_mcp_id,
				sanitize_text_field( $hal_mcp_model )
			);
			continue;
		}

		$hal_mcp_models[ $hal_mcp_id ] = $hal_mcp_model;
	}

	$hal_mcp_clean['models'] = $hal_mcp_models;

	// Endpoint overrides: validated through the transport's SSRF/https gate
	// BEFORE storage, so an unsafe URL can never be persisted. (Advanced
	// fields for known providers are hidden by default in the UI, but the
	// validation is identical wherever the value comes from.)
	$hal_mcp_endpoints = [];

	foreach ( (array) ( $hal_mcp_input['endpoints'] ?? [] ) as $hal_mcp_id => $hal_mcp_endpoint ) {
		$hal_mcp_id = sanitize_key( (string) $hal_mcp_id );

		if ( ! isset( $hal_mcp_providers[ $hal_mcp_id ] ) ) {
			continue;
		}

		$hal_mcp_endpoint = trim( (string) $hal_mcp_endpoint );

		if ( '' === $hal_mcp_endpoint ) {
			continue;
		}

		$hal_mcp_checked = hal_mcp_http_validate_endpoint( $hal_mcp_endpoint );

		if ( is_wp_error( $hal_mcp_checked ) ) {
			$hal_mcp_errors[] = sprintf(
				/* translators: 1: provider id, 2: refusal reason. */
				__( 'The endpoint override for %1$s was rejected: %2$s', 'hal-mcp' ),
				$hal_mcp_id,
				$hal_mcp_checked->get_error_message()
			);
			continue;
		}

		$hal_mcp_endpoints[ $hal_mcp_id ] = $hal_mcp_checked;
	}

	$hal_mcp_clean['endpoints'] = $hal_mcp_endpoints;

	// Generation parameters, per provider, clamped.
	$hal_mcp_temperature = [];

	foreach ( (array) ( $hal_mcp_input['temperature'] ?? [] ) as $hal_mcp_id => $hal_mcp_value ) {
		$hal_mcp_id = sanitize_key( (string) $hal_mcp_id );

		if ( ! isset( $hal_mcp_providers[ $hal_mcp_id ] ) || '' === (string) $hal_mcp_value ) {
			continue;
		}

		if ( ! is_numeric( $hal_mcp_value ) ) {
			continue;
		}

		$hal_mcp_temperature[ $hal_mcp_id ] = min( 2.0, max( 0.0, (float) $hal_mcp_value ) );
	}

	$hal_mcp_clean['temperature'] = $hal_mcp_temperature;

	$hal_mcp_max_output = [];

	foreach ( (array) ( $hal_mcp_input['max_output'] ?? [] ) as $hal_mcp_id => $hal_mcp_value ) {
		$hal_mcp_id = sanitize_key( (string) $hal_mcp_id );

		if ( ! isset( $hal_mcp_providers[ $hal_mcp_id ] ) || '' === (string) $hal_mcp_value ) {
			continue;
		}

		if ( ! is_numeric( $hal_mcp_value ) ) {
			continue;
		}

		$hal_mcp_cap   = (int) ( $hal_mcp_providers[ $hal_mcp_id ]['max_output'][2] ?? 32768 );
		$hal_mcp_max_output[ $hal_mcp_id ] = min( $hal_mcp_cap, max( 16, (int) $hal_mcp_value ) );
	}

	$hal_mcp_clean['max_output'] = $hal_mcp_max_output;

	// Text-only flags (F15: a text-only model is labeled as such and never
	// presented as a site-editing integration).
	$hal_mcp_text_only = [];

	foreach ( (array) ( $hal_mcp_input['text_only'] ?? [] ) as $hal_mcp_id => $hal_mcp_flag ) {
		$hal_mcp_id = sanitize_key( (string) $hal_mcp_id );

		if ( isset( $hal_mcp_providers[ $hal_mcp_id ] ) ) {
			$hal_mcp_text_only[ $hal_mcp_id ] = ! empty( $hal_mcp_flag );
		}
	}

	$hal_mcp_clean['text_only'] = $hal_mcp_text_only;

	// Custom provider protocol (the custom model is single-sourced from the
	// models map — models['custom'] — never from a second custom['model']
	// branch, which existed only as a divergent duplicate).
	$hal_mcp_custom_protocol = sanitize_key( (string) ( $hal_mcp_input['custom']['protocol'] ?? '' ) );

	if ( '' !== $hal_mcp_custom_protocol && ! in_array( $hal_mcp_custom_protocol, HAL_MCP_PROVIDER_PROTOCOLS, true ) ) {
		$hal_mcp_errors[] = __( 'The custom provider protocol was ignored: it is not one of the supported protocols.', 'hal-mcp' );
		$hal_mcp_custom_protocol = '';
	}

	$hal_mcp_clean['custom'] = [ 'protocol' => $hal_mcp_custom_protocol ];

	// Policy limits: may LOWER the code ceilings, never raise them (F18).
	$hal_mcp_clean['policy'] = [
		'max_rounds'  => min( HAL_MCP_RUNNER_MAX_ROUNDS, max( 1, (int) ( $hal_mcp_input['policy']['max_rounds'] ?? HAL_MCP_RUNNER_DEFAULT_ROUNDS ) ) ),
		'max_seconds' => min( HAL_MCP_RUNNER_MAX_SECONDS, max( 5, (int) ( $hal_mcp_input['policy']['max_seconds'] ?? HAL_MCP_RUNNER_DEFAULT_SECONDS ) ) ),
	];

	// The uninstall data choice: strict boolean, admin path only (this
	// callback IS the admin path — options.php with manage_options).
	$hal_mcp_clean['delete_data_on_uninstall'] = ! empty( $hal_mcp_input['delete_data_on_uninstall'] );

	// API keys: extract → encrypt → strip. Never stored as-is, never echoed.
	foreach ( (array) ( $hal_mcp_input['api_keys'] ?? [] ) as $hal_mcp_id => $hal_mcp_key ) {
		$hal_mcp_id = sanitize_key( (string) $hal_mcp_id );

		if ( ! isset( $hal_mcp_providers[ $hal_mcp_id ] ) ) {
			continue;
		}

		$hal_mcp_key = (string) $hal_mcp_key;

		if ( '__remove__' === $hal_mcp_key ) {
			hal_mcp_settings_delete_secret( $hal_mcp_id );
			continue;
		}

		if ( '' === trim( $hal_mcp_key ) ) {
			continue; // Empty field = keep the existing key.
		}

		$hal_mcp_stored = hal_mcp_settings_store_secret( $hal_mcp_id, trim( $hal_mcp_key ) );

		if ( is_wp_error( $hal_mcp_stored ) ) {
			$hal_mcp_errors[] = sprintf(
				/* translators: 1: provider id, 2: error message. */
				__( 'The API key for %1$s was NOT saved: %2$s', 'hal-mcp' ),
				$hal_mcp_id,
				$hal_mcp_stored->get_error_message()
			);
		}
	}

	// The remove checkbox posts its OWN field name (remove_keys, one entry per
	// provider) instead of overloading the key field with a sentinel value —
	// processing it after the key loop means an explicit remove wins over a
	// key typed in the same submit. The legacy '__remove__' sentinel above is
	// still honored for backwards compatibility.
	foreach ( (array) ( $hal_mcp_input['remove_keys'] ?? [] ) as $hal_mcp_id => $hal_mcp_remove ) {
		$hal_mcp_id = sanitize_key( (string) $hal_mcp_id );

		if ( ! isset( $hal_mcp_providers[ $hal_mcp_id ] ) || empty( $hal_mcp_remove ) ) {
			continue;
		}

		hal_mcp_settings_delete_secret( $hal_mcp_id );
	}

	foreach ( $hal_mcp_errors as $hal_mcp_error ) {
		hal_mcp_settings_error_buffer()[] = $hal_mcp_error;

		if ( function_exists( 'add_settings_error' ) ) {
			add_settings_error( HAL_MCP_SETTINGS_OPTION, 'hal_mcp_settings', $hal_mcp_error, 'error' );
		}
	}

	return $hal_mcp_clean;
}

/**
 * Shared buffer for sanitize-time validation messages (add_settings_error
 * may not exist outside a real admin page render, e.g. in local tests).
 *
 * @return array<int, string>
 */
function &hal_mcp_settings_error_buffer(): array {
	static $hal_mcp_errors = [];

	return $hal_mcp_errors;
}

/**
 * The sanitize callback's collected errors.
 *
 * @return array<int, string>
 */
function hal_mcp_settings_last_sanitize_errors(): array {
	return array_values( hal_mcp_settings_error_buffer() );
}

// ---------------------------------------------------------------------------
// Secrets
// ---------------------------------------------------------------------------

/**
 * Whether authenticated encryption is available in this environment.
 *
 * @return bool
 */
function hal_mcp_settings_crypto_available(): bool {
	return function_exists( 'openssl_encrypt' )
		&& function_exists( 'openssl_decrypt' )
		&& in_array( 'aes-256-gcm', openssl_get_cipher_methods( true ), true );
}

/**
 * The server-side key material: HAL_MCP_SECRET_KEY from the environment
 * when provided, else derived from WordPress salts. Never persisted, never
 * logged.
 *
 * @return string Raw key bytes (32).
 */
function hal_mcp_settings_crypto_key(): string {

	$hal_mcp_env = getenv( 'HAL_MCP_SECRET_KEY' );

	if ( false !== $hal_mcp_env && '' !== (string) $hal_mcp_env ) {
		return hash( 'sha256', 'hal-mcp-secret:v1:env:' . (string) $hal_mcp_env, true );
	}

	return hash( 'sha256', 'hal-mcp-secret:v1:salt:' . wp_salt( 'auth' ) . '|' . wp_salt( 'nonce' ), true );
}

/**
 * The current SITE key's fingerprint (8 hex chars) — stored with each secret
 * row as 'kkf' so a site-secret change is detectable as "re-enter your keys"
 * instead of a silent decrypt failure (F19: "تغيير أسرار الموقع ينتج طلب
 * إعادة إدخال واضح"). It never carries key material itself, and it is NOT
 * the per-provider masked hint the UI shows — that is 'kf', the fingerprint
 * of the sealed blob stored by hal_mcp_settings_store_secret().
 *
 * @return string
 */
function hal_mcp_settings_key_fingerprint(): string {
	return substr( hash( 'sha256', hal_mcp_settings_crypto_key() ), 0, 8 );
}

/**
 * Encrypts and stores one provider's API key (autoload OFF). Refuses
 * plaintext fallback when crypto is unavailable.
 *
 * The stored row carries the sealed blob plus two fingerprints and NO key
 * material:
 * - 'blob' — the ciphertext (base64 of "v1" + IV + tag + cipher).
 * - 'kf'   — 8 hex chars of the SHA-256 of the SEALED blob. Per-provider
 *            (every save's blob differs) and changed by every key replace;
 *            this is the masked hint the UI renders, never key characters.
 * - 'kkf'  — the site-key fingerprint at store time, the site-key-change
 *            detector hal_mcp_settings_get_secret() compares.
 *
 * @param string $provider_id Provider id.
 * @param string $secret      Raw key.
 * @return true|WP_Error
 */
function hal_mcp_settings_store_secret( string $provider_id, string $secret ) {

	if ( '' === $provider_id || '' === $secret ) {
		return new WP_Error( 'hal_mcp_secret_empty', __( 'Nothing to store.', 'hal-mcp' ) );
	}

	if ( ! hal_mcp_settings_crypto_available() ) {
		return new WP_Error(
			'hal_mcp_crypto_unavailable',
			__( 'Encrypted storage (OpenSSL AES-256-GCM) is not available on this server, so the key was NOT saved and no plaintext fallback was used.', 'hal-mcp' )
		);
	}

	$hal_mcp_iv      = random_bytes( 12 );
	$hal_mcp_tag     = '';
	$hal_mcp_cipher  = openssl_encrypt( $secret, 'aes-256-gcm', hal_mcp_settings_crypto_key(), OPENSSL_RAW_DATA, $hal_mcp_iv, $hal_mcp_tag );

	if ( false === $hal_mcp_cipher || '' === $hal_mcp_tag ) {
		return new WP_Error( 'hal_mcp_crypto_failed', __( 'Encryption failed, so the key was NOT saved and no plaintext fallback was used.', 'hal-mcp' ) );
	}

	$hal_mcp_blob = base64_encode( 'v1' . $hal_mcp_iv . $hal_mcp_tag . $hal_mcp_cipher );

	$hal_mcp_secrets              = get_option( HAL_MCP_SECRETS_OPTION, [] );
	$hal_mcp_secrets              = is_array( $hal_mcp_secrets ) ? $hal_mcp_secrets : [];
	$hal_mcp_secrets[ $provider_id ] = [
		'blob' => $hal_mcp_blob,
		'kf'   => substr( hash( 'sha256', $hal_mcp_blob ), 0, 8 ),
		'kkf'  => hal_mcp_settings_key_fingerprint(),
	];

	$hal_mcp_saved = update_option( HAL_MCP_SECRETS_OPTION, $hal_mcp_secrets, false );

	if ( ! $hal_mcp_saved ) {
		return new WP_Error( 'hal_mcp_secret_not_saved', __( 'The encrypted key could not be written to the options table.', 'hal-mcp' ) );
	}

	return true;
}

/**
 * Deletes one provider's stored key (the remove path).
 *
 * @param string $provider_id Provider id.
 * @return void
 */
function hal_mcp_settings_delete_secret( string $provider_id ): void {

	$hal_mcp_secrets = get_option( HAL_MCP_SECRETS_OPTION, [] );

	if ( ! is_array( $hal_mcp_secrets ) || ! array_key_exists( $provider_id, $hal_mcp_secrets ) ) {
		return;
	}

	unset( $hal_mcp_secrets[ $provider_id ] );

	update_option( HAL_MCP_SECRETS_OPTION, $hal_mcp_secrets, false );
}

/**
 * Decrypts one provider's key. The value exists only in the return value —
 * callers use it for one request header and never log or store it.
 *
 * The site-key-change check compares the row's STORED 'kkf' against the
 * current site-key fingerprint (hal_mcp_settings_key_fingerprint()). A
 * legacy row stored before 'kkf' existed (the old shape: 'kf' only) is
 * treated as a MATCH, so keys saved with the old row shape are never bricked
 * by the schema change — a genuinely changed site key still fails closed one
 * step later, at the OpenSSL decrypt itself.
 *
 * @param string $provider_id Provider id.
 * @return string|WP_Error '' when no key is stored for the provider;
 *                         WP_Error when a stored key cannot be decrypted
 *                         (wrong site secrets, corrupt blob, crypto gone).
 */
function hal_mcp_settings_get_secret( string $provider_id ) {

	$hal_mcp_secrets = get_option( HAL_MCP_SECRETS_OPTION, [] );

	if ( ! is_array( $hal_mcp_secrets ) || empty( $hal_mcp_secrets[ $provider_id ]['blob'] ) ) {
		return '';
	}

	if ( ! hal_mcp_settings_crypto_available() ) {
		return new WP_Error(
			'hal_mcp_crypto_unavailable',
			__( 'A saved key exists but encrypted storage is unavailable on this server; re-enter the key after restoring OpenSSL.', 'hal-mcp' )
		);
	}

	$hal_mcp_row = (array) $hal_mcp_secrets[ $provider_id ];

	// The site-key-change detector lives in 'kkf' (see
	// hal_mcp_settings_store_secret()); 'kf' identifies the sealed blob and
	// is never compared here. A legacy 'kf'-only row (no 'kkf') counts as a
	// match — fail-closed decryption below still catches a real key change.
	$hal_mcp_kkf = (string) ( $hal_mcp_row['kkf'] ?? '' );

	if ( '' !== $hal_mcp_kkf && $hal_mcp_kkf !== hal_mcp_settings_key_fingerprint() ) {
		return new WP_Error(
			'hal_mcp_secret_key_changed',
			__( 'The site security keys changed since this API key was saved, so it can no longer be decrypted. Re-enter the key to continue.', 'hal-mcp' )
		);
	}

	$hal_mcp_raw = base64_decode( (string) $hal_mcp_row['blob'], true );

	if ( false === $hal_mcp_raw || strlen( $hal_mcp_raw ) < 31 || 'v1' !== substr( $hal_mcp_raw, 0, 2 ) ) {
		return new WP_Error( 'hal_mcp_secret_corrupt', __( 'The stored API key is unreadable. Re-enter the key to continue.', 'hal-mcp' ) );
	}

	$hal_mcp_iv     = substr( $hal_mcp_raw, 2, 12 );
	$hal_mcp_tag    = substr( $hal_mcp_raw, 14, 16 );
	$hal_mcp_cipher = substr( $hal_mcp_raw, 30 );

	$hal_mcp_plain = openssl_decrypt( $hal_mcp_cipher, 'aes-256-gcm', hal_mcp_settings_crypto_key(), OPENSSL_RAW_DATA, $hal_mcp_iv, $hal_mcp_tag );

	if ( false === $hal_mcp_plain ) {
		return new WP_Error( 'hal_mcp_secret_unreadable', __( 'The stored API key could not be decrypted. Re-enter the key to continue.', 'hal-mcp' ) );
	}

	return $hal_mcp_plain;
}

/**
 * The masked status the UI renders — NEVER the key itself.
 *
 * @param string $provider_id Provider id.
 * @return array{set: bool, hint: string, readable: bool, error: string}
 */
function hal_mcp_settings_secret_status( string $provider_id ): array {

	$hal_mcp_secrets = get_option( HAL_MCP_SECRETS_OPTION, [] );
	$hal_mcp_set     = is_array( $hal_mcp_secrets ) && ! empty( $hal_mcp_secrets[ $provider_id ]['blob'] );

	if ( ! $hal_mcp_set ) {
		return [ 'set' => false, 'hint' => '', 'readable' => false, 'error' => '' ];
	}

	$hal_mcp_secret = hal_mcp_settings_get_secret( $provider_id );

	if ( is_wp_error( $hal_mcp_secret ) ) {
		return [
			'set'      => true,
			// The stored 'kf' blob-fingerprint hint — per-provider, changed by
			// every key replace; never key characters.
			'hint'     => (string) ( $hal_mcp_secrets[ $provider_id ]['kf'] ?? '' ),
			'readable' => false,
			'error'    => $hal_mcp_secret->get_error_message(),
		];
	}

	return [
		'set'      => true,
		// The stored 'kf' blob-fingerprint hint — per-provider, changed by
		// every key replace; never key characters.
		'hint'     => (string) ( $hal_mcp_secrets[ $provider_id ]['kf'] ?? '' ),
		'readable' => true,
		'error'    => '',
	];
}

// ---------------------------------------------------------------------------
// Runtime provider config (the bridge providers.php + runner.php read)
// ---------------------------------------------------------------------------

/**
 * Builds the FULL runtime config for one provider: registry defaults, saved
 * overrides, and the decrypted API key. This is the only function that puts
 * a secret into a config array, and the config goes only into
 * hal_mcp_provider_call()'s request headers.
 *
 * @param string $provider_id Provider id.
 * @param string $model       Optional model override.
 * @return array|WP_Error
 */
function hal_mcp_settings_provider_config( string $provider_id, string $model = '' ) {

	$hal_mcp_providers = hal_mcp_providers();

	if ( ! isset( $hal_mcp_providers[ $provider_id ] ) ) {
		return new WP_Error( 'hal_mcp_provider_unknown', __( 'Unknown provider.', 'hal-mcp' ) );
	}

	$hal_mcp_settings = hal_mcp_settings_get();
	$hal_mcp_registry = $hal_mcp_providers[ $provider_id ];

	$hal_mcp_config = [
		'id'              => $provider_id,
		'label'           => (string) $hal_mcp_registry['label'],
		'protocol'        => (string) $hal_mcp_registry['protocol'],
		'endpoint'        => (string) $hal_mcp_registry['endpoint'],
		'models_endpoint' => (string) $hal_mcp_registry['models_endpoint'],
		'auth'            => (string) $hal_mcp_registry['auth'],
		'temperature'     => (bool) $hal_mcp_registry['temperature'],
		'max_output'      => $hal_mcp_registry['max_output'],
		'timeout'         => 60.0,
	];

	if ( 'custom' === $provider_id ) {
		$hal_mcp_config['protocol'] = (string) ( $hal_mcp_settings['custom']['protocol'] ?? '' );
	}

	if ( ! in_array( $hal_mcp_config['protocol'], HAL_MCP_PROVIDER_PROTOCOLS, true ) ) {
		return new WP_Error(
			'hal_mcp_provider_setup_incomplete',
			__( 'Choose a supported protocol for this provider in the settings first.', 'hal-mcp' )
		);
	}

	if ( isset( $hal_mcp_settings['endpoints'][ $provider_id ] ) ) {
		$hal_mcp_config['endpoint'] = (string) $hal_mcp_settings['endpoints'][ $provider_id ];
	}

	if ( '' === trim( (string) $hal_mcp_config['endpoint'] ) ) {
		return new WP_Error(
			'hal_mcp_provider_setup_incomplete',
			__( 'Enter an HTTPS endpoint for this provider in the settings first.', 'hal-mcp' )
		);
	}

	// Single source of truth for the model: the per-provider models map. The
	// custom provider carries no registry default, so models['custom'] is its
	// only fallback — there is no second custom['model'] branch.
	$hal_mcp_config['model'] = '' !== trim( $model )
		? trim( $model )
		: (string) ( $hal_mcp_settings['models'][ $provider_id ] ?? (string) $hal_mcp_registry['default_model'] );

	if ( '' === trim( (string) $hal_mcp_config['model'] ) ) {
		return new WP_Error(
			'hal_mcp_provider_setup_incomplete',
			__( 'Choose a model for this provider in the settings first.', 'hal-mcp' )
		);
	}

	if ( $hal_mcp_config['temperature'] && isset( $hal_mcp_settings['temperature'][ $provider_id ] ) ) {
		$hal_mcp_config['temperature_value'] = (float) $hal_mcp_settings['temperature'][ $provider_id ];
	}

	if ( isset( $hal_mcp_settings['max_output'][ $provider_id ] ) ) {
		$hal_mcp_config['max_output_value'] = (int) $hal_mcp_settings['max_output'][ $provider_id ];
	}

	$hal_mcp_secret = hal_mcp_settings_get_secret( $provider_id );

	if ( is_wp_error( $hal_mcp_secret ) ) {
		return $hal_mcp_secret;
	}

	$hal_mcp_config['api_key'] = $hal_mcp_secret;

	return $hal_mcp_config;
}

// ---------------------------------------------------------------------------
// Policy + connection tests
// ---------------------------------------------------------------------------

/**
 * The effective policy limits the runner reads (settings may lower the code
 * ceilings; the runner clamps again).
 *
 * @return array{max_rounds: int, max_seconds: int}
 */
function hal_mcp_settings_policy_limits(): array {

	$hal_mcp_settings = hal_mcp_settings_get();

	return [
		'max_rounds'  => (int) ( $hal_mcp_settings['policy']['max_rounds'] ?? HAL_MCP_RUNNER_DEFAULT_ROUNDS ),
		'max_seconds' => (int) ( $hal_mcp_settings['policy']['max_seconds'] ?? HAL_MCP_RUNNER_DEFAULT_SECONDS ),
	];
}

/**
 * The recorded connection-test outcome(s) — what the Overview step states
 * read ("اختبار اتصال ناجح مع وقته"), never inferred from a saved key.
 *
 * @param string $provider_id Optional: one provider's record.
 * @return array<string, array{ok: bool, code: string, tested_at: int}>
 */
function hal_mcp_settings_test_results( string $provider_id = '' ): array {

	$hal_mcp_tests = get_option( HAL_MCP_TESTS_OPTION, [] );

	if ( ! is_array( $hal_mcp_tests ) ) {
		$hal_mcp_tests = [];
	}

	if ( '' !== $provider_id ) {
		$hal_mcp_row = $hal_mcp_tests[ $provider_id ] ?? null;

		return is_array( $hal_mcp_row ) ? [ $provider_id => $hal_mcp_row ] : [];
	}

	return $hal_mcp_tests;
}

/**
 * Records one connection-test outcome (written only by the admin-triggered
 * test route — never by a page load, never automatically for all providers).
 *
 * @param string $provider_id Provider id.
 * @param array  $result      { ok: bool, code: string }.
 * @return bool
 */
function hal_mcp_settings_record_test( string $provider_id, array $result ): bool {

	$hal_mcp_tests           = hal_mcp_settings_test_results();
	$hal_mcp_tests[ $provider_id ] = [
		'ok'        => ! empty( $result['ok'] ),
		'code'      => (string) ( $result['code'] ?? '' ),
		'tested_at' => time(),
	];

	return update_option( HAL_MCP_TESTS_OPTION, $hal_mcp_tests, false );
}
