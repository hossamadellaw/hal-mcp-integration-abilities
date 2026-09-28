<?php
/**
 * Plugin Name:       HAL MCP Integration Abilities
 * Description:       Exposes restricted hal/* WordPress Abilities for reading and drafting site content through the official WordPress MCP Adapter.
 * Version:           2.0.0
 * Author:            Hossam Adel Lawyer
 * License:           GPL-2.0-or-later
 * Text Domain:       hal-mcp
 * Requires at least: 6.9
 * Requires PHP:      8.0
 * Update URI:        https://github.com/hossamadellaw/hal-mcp-integration-abilities
 *
 * Standard plugin entry point. Its only jobs are to define the shared
 * constants once, guard against the legacy mu-plugin copy still being
 * loaded, and require the real bootstrap at hal-mcp-abilities/hal-mcp-abilities.php.
 * All Abilities, permissions, and category registration live there and in
 * the files it requires.
 *
 * @package hal-mcp-abilities
 */

// Block direct access outside of the WordPress runtime.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/*
 * Another entry point already loaded this plugin — in practice the legacy
 * mu-plugin copy (wp-content/mu-plugins/hal-mcp-abilities-loader.php), which
 * WordPress always executes before any regular plugin. Keep that copy running
 * and stop here with a clear admin message instead of loading a second
 * instance: mu-plugins load first, so removing this check would define
 * duplicate constants and fatally crash every request during the transition.
 *
 * The mu-plugin files themselves are never touched automatically — removing
 * the old copy is an explicit admin step documented in README.md.
 */
if ( defined( 'HAL_MCP_ABILITIES_VERSION' ) ) {
	add_action(
		'admin_notices',
		static function () {
			if ( ! current_user_can( 'activate_plugins' ) ) {
				return;
			}

			if ( defined( 'WPMU_PLUGIN_DIR' ) && file_exists( WPMU_PLUGIN_DIR . '/hal-mcp-abilities-loader.php' ) ) {
				$message = __(
					'HAL MCP Integration Abilities: the legacy mu-plugin copy (wp-content/mu-plugins/hal-mcp-abilities-loader.php) is still active, so this standard plugin did not load. Remove the mu-plugin copy to switch this site to the standard plugin version.',
					'hal-mcp'
				);
			} else {
				$message = __(
					'HAL MCP Integration Abilities: another copy of this plugin is already loaded, so this copy did not load.',
					'hal-mcp'
				);
			}

			echo '<div class="notice notice-error"><p>' . esc_html( $message ) . '</p></div>';
		}
	);

	return;
}

define( 'HAL_MCP_ABILITIES_VERSION', '2.0.0' );
define( 'HAL_MCP_ABILITIES_DIR', __DIR__ . '/hal-mcp-abilities' );
define( 'HAL_MCP_ABILITIES_PLUGIN_FILE', __FILE__ );

/*
 * Fail loudly rather than silently (behavior carried over from the legacy
 * loader): an incomplete deploy that reaches this entry without the internal
 * bootstrap must surface in wp-admin, not fatal every request.
 */
if ( ! is_readable( HAL_MCP_ABILITIES_DIR . '/hal-mcp-abilities.php' ) ) {
	add_action(
		'admin_notices',
		static function () {
			if ( ! current_user_can( 'activate_plugins' ) ) {
				return;
			}
			echo '<div class="notice notice-error"><p>' .
				esc_html__(
					'HAL MCP Integration Abilities: the internal bootstrap (hal-mcp-abilities/hal-mcp-abilities.php) was not found. Re-upload the hal-mcp-abilities/ folder — no hal/* Abilities are registered.',
					'hal-mcp'
				) .
				'</p></div>';
		}
	);

	return;
}

require_once HAL_MCP_ABILITIES_DIR . '/hal-mcp-abilities.php';

/*
 * Versioned storage init on activation. Ongoing version upgrades reconcile on
 * their own through the plugins_loaded check in includes/audit-log.php, since
 * updating a plugin does not re-run activation. Deactivation needs no routine:
 * the plugin owns no scheduled events or caches, and its data (audit log,
 * settings) is deliberately kept on deactivation and on uninstall by default.
 */
register_activation_hook( __FILE__, 'hal_mcp_maybe_create_audit_log_table' );
