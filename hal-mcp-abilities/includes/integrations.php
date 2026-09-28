<?php
/**
 * hal-mcp-abilities — integration registry and loader (F24).
 *
 * The small named-callback registry the roadmap (§4.4) prescribes: a flat list
 * of integration definitions, each naming the functions it provides
 * (discover/read/validate/apply as needed), and a loader that requires the
 * four integration files when they exist. No base classes, no inheritance, no
 * auto-loading framework, and the model never gets to generate plugin files.
 *
 * What this registry deliberately does NOT do:
 * - Registering an integration here never means its operations are callable.
 *   Each definition declares its operations and their effects/permissions, and
 *   a runtime check (hal_mcp_integration_check()) must pass before anything
 *   uses it — knowing a plugin's name grants no execution rights.
 * - Third-party abilities are never passed through raw: anything not bound
 *   through this registry stays invisible to the model-facing catalog.
 * - It never invents MCP transport/auth of its own: the external channel is
 *   the official WordPress MCP Adapter, detected (not configured) here, and
 *   provider API keys are never part of that channel.
 *
 * The four integration files (blocks.php, elementor.php, seo.php,
 * translations.php) land with their roadmap batches (F21–F23). When one
 * appears, this loader picks it up with zero further bootstrap changes — the
 * same "load when present" pattern the bootstrap already uses for the admin
 * and updater modules. Until then the registry holds the core-detected
 * integrations only (content editor + MCP channel), which is real coverage,
 * not a placeholder.
 *
 * @package hal-mcp-abilities
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Integration definition shape (all keys optional except id/label):
 *
 *   id         string  Stable integration slug (e.g. 'gutenberg').
 *   label      string  Human label.
 *   version    string  Detected version, '' when unknown.
 *   source     string  Where the component comes from: 'core', 'adapter',
 *                      or the owning plugin family.
 *   operations array   operation_slug => { effect: 'read'|'edit'|'publish',
 *                        capability: primitive required at minimum,
 *                        writable: string[] fields this integration may write }
 *   abilities  array   Third-party ability names this integration binds
 *                      (hal_mcp_is_third_party_ability_bound() reads this).
 *   available  bool    Whether the underlying component is present right now.
 *
 * Registry entries are added by the integration files themselves and by
 * environment.php for core-detected components. Entries are data only —
 * effect/capability declarations are checked at call time, never trusted
 * as authorization on their own (object-level checks stay in
 * permissions.php).
 *
 * @return array<string, array<string, mixed>> Definitions by integration id.
 */
function &hal_mcp_integrations(): array {
	static $integrations = [];
	return $integrations;
}

/**
 * Registers (or replaces) one integration definition.
 *
 * A duplicate id is a coding error on the caller's side, not something to
 * merge silently: it is refused with an error_log line so the collision is
 * visible in debugging, matching the fail-loud pattern of categories.php.
 *
 * @param string $id   Integration slug.
 * @param array  $args Definition (see hal_mcp_integrations()).
 * @return bool True when registered.
 */
function hal_mcp_register_integration( string $id, array $args ): bool {

	$id = sanitize_key( $id );

	if ( '' === $id ) {
		error_log( 'hal-mcp-abilities: hal_mcp_register_integration() called with an empty id.' );
		return false;
	}

	$integrations = &hal_mcp_integrations();

	if ( isset( $integrations[ $id ] ) ) {
		error_log(
			sprintf(
				'hal-mcp-abilities: refused to register integration "%s" twice — the existing definition stands.',
				$id
			)
		);
		return false;
	}

	$integrations[ $id ] = $args;
	return true;
}

/**
 * Whether an integration is registered AND its underlying component is
 * available right now (F24: runtime check before execution).
 *
 * @param string $id Integration slug.
 * @return bool
 */
function hal_mcp_integration_available( string $id ): bool {
	$integrations = hal_mcp_integrations();
	return ! empty( $integrations[ $id ]['available'] );
}

/**
 * The operations an integration currently declares, each with its effect,
 * minimum capability, and writable fields — the data the content abilities
 * merge into their tool schemas (F24) and that the overview screen shows as
 * available / partially available / needs setup / unverified.
 *
 * @param string $id Integration slug.
 * @return array<string, array<string, mixed>> operation_slug => definition.
 */
function hal_mcp_integration_operations( string $id ): array {
	$integrations = hal_mcp_integrations();
	return isset( $integrations[ $id ]['operations'] ) && is_array( $integrations[ $id ]['operations'] )
		? $integrations[ $id ]['operations']
		: [];
}

/**
 * Runtime gate for one operation of one integration (F24): the integration
 * must be available, the operation declared, and the current user must hold
 * the declared minimum capability. Object-level authorization still happens
 * separately in permissions.php — this is the "is this integration's
 * operation usable at all" check, not a substitute for it.
 *
 * @param string $id        Integration slug.
 * @param string $operation Operation slug.
 * @return array{available: bool, reason: string} available=false always carries a reason.
 */
function hal_mcp_integration_check( string $id, string $operation ): array {

	if ( ! hal_mcp_integration_available( $id ) ) {
		return [
			'available' => false,
			'reason'    => 'integration_component_absent',
		];
	}

	$operations = hal_mcp_integration_operations( $id );

	if ( ! isset( $operations[ $operation ] ) ) {
		return [
			'available' => false,
			'reason'    => 'operation_not_declared',
		];
	}

	$capability = (string) ( $operations[ $operation ]['capability'] ?? '' );

	if ( '' === $capability || ! current_user_can( $capability ) ) {
		return [
			'available' => false,
			'reason'    => 'capability_missing:' . $capability,
		];
	}

	return [
		'available' => true,
		'reason'    => 'ok',
	];
}

/**
 * The four roadmap integration files, keyed by the integration id each will
 * register. Loaded after all plugins have loaded (see the plugins_loaded
 * hook below), so their file-level function_exists() checks for the target
 * plugin see the real, final plugin set — not a partially loaded one.
 *
 * @var array<string, string>
 */
const HAL_MCP_INTEGRATION_FILES = [
	'blocks'       => 'blocks.php',
	'elementor'    => 'elementor.php',
	'seo'          => 'seo.php',
	'translations' => 'translations.php',
];

/**
 * Requires every integration file that exists (F24). Hooked to
 * plugins_loaded at priority 5 — after every plugin (including this one)
 * has finished loading, and before environment.php's inventory hooks at
 * priority 10, so integrations registered from these files are visible to
 * the first inventory pass.
 *
 * @return void
 */
function hal_mcp_load_integration_files(): void {

	foreach ( HAL_MCP_INTEGRATION_FILES as $hal_mcp_file ) {
		$hal_mcp_path = HAL_MCP_ABILITIES_DIR . '/integrations/' . $hal_mcp_file;

		if ( is_readable( $hal_mcp_path ) ) {
			require_once $hal_mcp_path;
		}
	}
}

add_action( 'plugins_loaded', 'hal_mcp_load_integration_files', 5 );

/**
 * Registers the two core-detected integration facts the whole plugin needs
 * before any file integration exists (F24): the content editor WordPress
 * itself ships, and the external MCP channel.
 *
 * Hooked to plugins_loaded at priority 6 — after the integration files above
 * (5) so their own registrations win the id namespace, and still before
 * environment.php's inventory (10).
 *
 * The MCP entry is detection data only, exposed for the admin overview and
 * hal/get-site-inventory: it never holds transport/auth configuration, and
 * provider keys have nothing to do with it (roadmap §4.4/F24). Site
 * configuration of the channel itself belongs to the MCP Adapter plugin, not
 * here; this plugin only declares which of its own abilities it exposes, via
 * each ability's meta.mcp.public flag.
 *
 * @return void
 */
function hal_mcp_register_core_integrations(): void {

	// The block editor is core since 5.0: always present, never removable
	// through the plugins screen. Editor-side support (Spectra, Elementor)
	// is a property of installed plugins, detected by environment.php.
	// A later integration file (blocks.php, F21) may register its own richer
	// 'gutenberg' definition — that one wins and this core entry stands down.
	if ( ! isset( hal_mcp_integrations()['gutenberg'] ) ) {
		hal_mcp_register_integration(
			'gutenberg',
			[
				'label'      => __( 'WordPress block editor (Gutenberg)', 'hal-mcp' ),
				'version'    => (string) get_bloginfo( 'version' ),
				'available'  => true,
				'source'     => 'core',
				'operations' => [
					'read_blocks' => [
						'effect'     => 'read',
						'capability' => 'edit_posts',
						'writable'   => [],
					],
				],
			]
		);
	}

	// The official external channel: the WordPress MCP Adapter plugin
	// (v0.6.1 reference), present when its main class exists. Detection is
	// class-based exactly as the adapter's own documentation prescribes.
	// The adapter's version number is deliberately left unknown here — this
	// file does not guess constants it has not verified; the admin screen
	// (F19) can read the plugin header from the installed Adapter itself.
	if ( ! isset( hal_mcp_integrations()['mcp'] ) ) {
		hal_mcp_register_integration(
			'mcp',
			[
				'label'      => __( 'WordPress MCP Adapter (external channel)', 'hal-mcp' ),
				'version'    => '',
				'available'  => class_exists( 'WP\MCP\Core\McpAdapter' ),
				'source'     => 'adapter',
				'operations' => [
					'external_tool_access' => [
						'effect'     => 'read',
						'capability' => 'read',
						'writable'   => [],
					],
				],
			]
		);
	}
}

add_action( 'plugins_loaded', 'hal_mcp_register_core_integrations', 6 );

/**
 * The external-connection description the admin overview shows (F24): what
 * channel exists for external programs, and that this plugin exposes its
 * hal/* abilities there through per-ability meta.mcp.public decisions.
 *
 * The availability check is live (class_exists at call time), not the
 * registration-time snapshot — the channel is the one thing worth re-checking
 * on every read. Purely informational: it never configures anything, and it
 * includes no provider API keys (those live in the provider settings for the
 * internal chat screen, F15/F19).
 *
 * @return array{available: bool, label: string, version: string, note: string}
 */
function hal_mcp_external_channel_info(): array {

	$integrations = hal_mcp_integrations();
	$mcp          = $integrations['mcp'] ?? [];
	$available    = class_exists( 'WP\MCP\Core\McpAdapter' );

	return [
		'available' => $available,
		'label'     => (string) ( $mcp['label'] ?? '' ),
		'version'   => (string) ( $mcp['version'] ?? '' ),
		'note'      => __(
			'External programs (such as ChatGPT or Claude) reach this site through the official WordPress MCP Adapter plugin, using the same hal/* abilities and the same permission and approval policy as the internal screen. Exposing the channel requires installing and activating the Adapter; it is optional — the internal screen works without it.',
			'hal-mcp'
		),
	];
}

/**
 * Whether a third-party ability is bound through a registered integration
 * (F24): third-party abilities are never surfaced to the model raw, and this
 * is the single classification gate a future model-facing catalog (F18/F20)
 * must consult. Only an integration whose component is currently available
 * counts — an absent component's bindings stay dormant. A binding recorded
 * here declares the integration takes responsibility for that ability's
 * effects and permissions; execution-time object authorization stays in
 * permissions.php regardless.
 *
 * @param string $ability_name Third-party ability name, e.g. 'vendor/thing'.
 * @return bool True only when some registered, available integration binds it.
 */
function hal_mcp_is_third_party_ability_bound( string $ability_name ): bool {

	$ability_name = trim( $ability_name );

	if ( '' === $ability_name ) {
		return false;
	}

	foreach ( hal_mcp_integrations() as $definition ) {
		if ( empty( $definition['available'] ) ) {
			continue;
		}

		if ( in_array( $ability_name, (array) ( $definition['abilities'] ?? [] ), true ) ) {
			return true;
		}
	}

	return false;
}
