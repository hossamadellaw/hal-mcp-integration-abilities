<?php
/**
 * hal-mcp-abilities — internal bootstrap.
 *
 * This file is loaded by the root plugin entry (hal-mcp-integration-abilities.php,
 * which defines HAL_MCP_ABILITIES_VERSION, HAL_MCP_ABILITIES_DIR, and
 * HAL_MCP_ABILITIES_PLUGIN_FILE). It is an internal bootstrap, not a second
 * plugin entry: it must never carry its own plugin header.
 *
 * Responsibilities of this file, and only these:
 *   1. Guard against being required more than once, independently of the root
 *      entry's version constant.
 *   2. Require the shared include files (permissions, audit log, change
 *      requests, integrations, environment, categories) and the per-domain
 *      ability files, in a fixed, readable order.
 *
 * No ability, category, or permission logic is defined here — that lives in
 * includes/ and abilities/, each hooked into its own WordPress action.
 *
 * @package hal-mcp-abilities
 */

// Block direct access outside of the WordPress runtime.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/*
 * The root entry owns the shared constants. Reaching this file without them
 * means a broken deploy — e.g. this internal folder was copied somewhere and
 * required directly, without the plugin entry that defines them. This check
 * runs before the load marker below, so a broken deploy can never mark this
 * bootstrap as loaded and silently block a later, legitimate load.
 */
if ( ! defined( 'HAL_MCP_ABILITIES_VERSION' ) || ! defined( 'HAL_MCP_ABILITIES_DIR' ) ) {
	add_action(
		'admin_notices',
		static function () {
			if ( ! current_user_can( 'activate_plugins' ) ) {
				return;
			}
			echo '<div class="notice notice-error"><p>' .
				esc_html__(
					'hal-mcp-abilities: loaded without the plugin entry file. Activate the HAL MCP Integration Abilities plugin from wp-content/plugins/ instead of requiring this bootstrap directly. Nothing was loaded.',
					'hal-mcp'
				) .
				'</p></div>';
		}
	);
	return;
}

/*
 * Internal load guard, separate from HAL_MCP_ABILITIES_VERSION: the root entry
 * defines that constant before requiring this file, so this bootstrap must not
 * treat its presence as "already loaded". Requiring this file twice (multiple
 * entry points, or a defensive require in a future module) must stay harmless.
 */
if ( defined( 'HAL_MCP_ABILITIES_BOOTSTRAP_LOADED' ) ) {
	return;
}

define( 'HAL_MCP_ABILITIES_BOOTSTRAP_LOADED', true );

/*
 * Shared foundations. These are loaded regardless of whether the Abilities API
 * is available: permissions/audit-log/change-requests define plain WordPress
 * code the admin area and storage init rely on, and categories.php only adds
 * an action callback for a hook that fires when the Abilities API is actually
 * present. Audit logging also owns the versioned storage init (plugins_loaded
 * check), which must run on activation and after every update — not only when
 * abilities can register.
 *
 * integrations.php (F24) and environment.php (F14) are the discovery
 * foundations, and both honor the roadmap rule that discovery is initialized
 * after the environment is complete, never by require order alone: their
 * function definitions load here, the four integration FILES load on
 * plugins_loaded (after every plugin has finished loading), and the
 * environment inventory is captured lazily — only on first read, and only
 * cached after `init` has registered all post types.
 */
require_once HAL_MCP_ABILITIES_DIR . '/includes/permissions.php';
require_once HAL_MCP_ABILITIES_DIR . '/includes/audit-log.php';
require_once HAL_MCP_ABILITIES_DIR . '/includes/change-requests.php';
require_once HAL_MCP_ABILITIES_DIR . '/includes/integrations.php';
require_once HAL_MCP_ABILITIES_DIR . '/includes/environment.php';
require_once HAL_MCP_ABILITIES_DIR . '/includes/categories.php';

/*
 * Batch-6 conversation modules (F16 transport, F19 settings, F15 providers,
 * F18 runner) plus the admin-facing and updater modules, which land in later
 * roadmap batches (admin screens exist since F19; the GitHub updater comes
 * with G03). Load them as soon as they exist so adding them never requires
 * touching this bootstrap again. Order matters for a human reading the list —
 * providers.php reads secrets through settings.php only at call time — but
 * every module defines functions and hooks nothing at load except its own
 * callbacks, so each is harmless when its later dependencies do not exist.
 */
foreach ( [ 'http-client.php', 'providers.php', 'settings.php', 'runner.php', 'admin.php', 'updater.php' ] as $hal_mcp_module ) {
	$hal_mcp_module_path = HAL_MCP_ABILITIES_DIR . '/includes/' . $hal_mcp_module;
	if ( is_readable( $hal_mcp_module_path ) ) {
		require_once $hal_mcp_module_path;
	}
}

/*
 * Defensive environment check for the ability files only.
 *
 * The Abilities API (wp_register_ability(), wp_register_ability_category(), and
 * the wp_abilities_api_init / wp_abilities_api_categories_init hooks) shipped in
 * WordPress core as of 6.9. We still guard explicitly rather than assume, per
 * official guidance to check function_exists( 'wp_register_ability' ) before
 * registering — a missing API must disable only the abilities, not the rest of
 * the plugin (and never by installing anything automatically).
 *
 * If the check fails, we deliberately do NOT require the ability files below:
 * those files call wp_register_ability_category() / wp_register_ability()
 * directly, which would fatal on a core that does not define them.
 */
if ( function_exists( 'wp_register_ability' ) && function_exists( 'wp_register_ability_category' ) ) {
	/*
	 * Per-domain ability files. Each file registers its own abilities inside a
	 * callback hooked to wp_abilities_api_init (see includes/categories.php and
	 * includes/permissions.php for the shared category slugs and
	 * capability-check helper they use). WordPress fires
	 * wp_abilities_api_categories_init before wp_abilities_api_init by core
	 * design, so the categories above are always registered first regardless
	 * of require() order — this ordering is for a human reading the file top
	 * to bottom.
	 */
	require_once HAL_MCP_ABILITIES_DIR . '/abilities/read-system.php';
	require_once HAL_MCP_ABILITIES_DIR . '/abilities/read-posts.php';
	require_once HAL_MCP_ABILITIES_DIR . '/abilities/read-products.php';
	require_once HAL_MCP_ABILITIES_DIR . '/abilities/read-media.php';
	require_once HAL_MCP_ABILITIES_DIR . '/abilities/read-pages.php';
	require_once HAL_MCP_ABILITIES_DIR . '/abilities/write-posts.php';
	require_once HAL_MCP_ABILITIES_DIR . '/abilities/write-products.php';
	require_once HAL_MCP_ABILITIES_DIR . '/abilities/write-media.php';
	require_once HAL_MCP_ABILITIES_DIR . '/abilities/write-pages.php';
	require_once HAL_MCP_ABILITIES_DIR . '/abilities/content.php';
} else {
	add_action(
		'admin_notices',
		static function () {
			if ( ! current_user_can( 'activate_plugins' ) ) {
				return;
			}
			echo '<div class="notice notice-error"><p>' .
				esc_html__(
					'hal-mcp-abilities: the WordPress Abilities API (wp_register_ability) was not found. This site needs WordPress 6.9 or later. No hal/* Abilities are registered; the rest of the plugin remains available.',
					'hal-mcp'
				) .
				'</p></div>';
		}
	);
}
