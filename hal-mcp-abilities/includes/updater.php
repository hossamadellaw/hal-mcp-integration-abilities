<?php
/**
 * hal-mcp-abilities — GitHub Releases update checker (G03).
 *
 * Wires the Plugin Update Checker library (PUC, composer dependency
 * yahnis-elsts/plugin-update-checker, minor-bound to the v5p7 namespace) to
 * this plugin's public GitHub repository, so a site can update through the
 * standard WordPress update flow instead of manual ZIP uploads.
 *
 * Failure containment is the contract of this file: a missing library, a
 * namespace/library mismatch, or any Throwable during updater initialization
 * may only cost the update feature — never the rest of the plugin and never
 * the site. That is why the bootstrap may load this module unconditionally and
 * why nothing here fatals.
 *
 * The public distribution path needs no credentials: a public repository plus
 * a Release ZIP asset matching ASSET_REGEX is enough. The token constant below
 * exists only for a private-repository decision the owner has not made; it
 * must come from wp-config.php or the hosting environment, never from Git.
 *
 * @package hal-mcp-abilities
 */

// Block direct access outside of the WordPress runtime.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/*
 * Repository URL, overridable from wp-config.php (which loads before plugins).
 * Must match the Update URI in the plugin header and the distribution identity
 * documented in Git-Github/Docs/GITHUB-RELEASES-UPDATE-SYSTEM.md.
 */
if ( ! defined( 'HAL_MCP_ABILITIES_GITHUB_REPO' ) ) {
	define( 'HAL_MCP_ABILITIES_GITHUB_REPO', 'https://github.com/hossamadellaw/hal-mcp-integration-abilities' );
}

/*
 * Read-only fine-grained token for the private-repository path only. Deliberately
 * undefined by default: the public site must not carry credentials, and this
 * constant must never be set in a committed file.
 */
if ( ! defined( 'HAL_MCP_ABILITIES_GITHUB_TOKEN' ) ) {
	define( 'HAL_MCP_ABILITIES_GITHUB_TOKEN', '' );
}

/**
 * Starts the update checker on plugins_loaded, contained.
 *
 * Runs only when the standard plugin entry is the loader: the constant it
 * defines is what makes the root ZIP layout discoverable. The legacy mu-plugin
 * copy (or a broken direct require of the bootstrap) defines the version
 * constant but not this one, and that context gets no updater.
 *
 * @return void
 */
function hal_mcp_updater_init(): void {
	if ( ! defined( 'HAL_MCP_ABILITIES_PLUGIN_FILE' ) || '' === HAL_MCP_ABILITIES_PLUGIN_FILE ) {
		return;
	}

	if ( ! is_string( HAL_MCP_ABILITIES_GITHUB_REPO ) || '' === HAL_MCP_ABILITIES_GITHUB_REPO ) {
		return;
	}

	$hal_mcp_library = plugin_dir_path( HAL_MCP_ABILITIES_PLUGIN_FILE ) . 'vendor/yahnis-elsts/plugin-update-checker/plugin-update-checker.php';
	if ( ! is_readable( $hal_mcp_library ) ) {
		// vendor/ missing or incomplete (e.g. a hand-copied deploy without
		// composer install). Skip silently beyond a debug-level note: this is
		// an environment state, not a plugin defect, and the site is fine.
		if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
			// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
			error_log( 'HAL MCP Integration Abilities: update checker skipped, PUC library not readable at the expected vendor path.' );
		}
		return;
	}

	/*
	 * Namespace-locked binding (G03): the code below references v5p7 classes
	 * literally, so the composer constraint must stay ~5.7.0. If another
	 * plugin already loaded this namespace, reuse it; the library itself is
	 * internally guarded against double loading.
	 */
	$hal_mcp_puc_factory = 'YahnisElsts\\PluginUpdateChecker\\v5p7\\PucFactory';

	try {
		if ( ! class_exists( $hal_mcp_puc_factory, false ) ) {
			require_once $hal_mcp_library;
		}

		// A readable vendor path alone proves nothing: verify the namespace
		// actually landed before using it (partial upload / wrong version).
		if ( ! class_exists( $hal_mcp_puc_factory ) ) {
			hal_mcp_updater_admin_notice(
				__(
					'HAL MCP Integration Abilities: the update checker library did not load (vendor copy incomplete or a different version). Plugin features are unaffected; automatic updates are disabled.',
					'hal-mcp'
				)
			);
			return;
		}

		$hal_mcp_update_checker = $hal_mcp_puc_factory::buildUpdateChecker(
			HAL_MCP_ABILITIES_GITHUB_REPO,
			HAL_MCP_ABILITIES_PLUGIN_FILE,
			'hal-mcp-integration-abilities'
		);

		if ( defined( 'HAL_MCP_ABILITIES_GITHUB_TOKEN' ) && is_string( HAL_MCP_ABILITIES_GITHUB_TOKEN ) && '' !== HAL_MCP_ABILITIES_GITHUB_TOKEN ) {
			$hal_mcp_update_checker->setAuthentication( HAL_MCP_ABILITIES_GITHUB_TOKEN );
		}

		/*
		 * Release-asset enforcement, fail-closed: without this, PUC may fall
		 * back to the automatic source archive when the built ZIP is missing —
		 * a package without vendor/ that would break the update. The regex
		 * must keep matching the single asset name the release workflow
		 * publishes for this plugin.
		 */
		$hal_mcp_update_checker->getVcsApi()->enableReleaseAssets(
			'/^hal-mcp-integration-abilities-[0-9]+\.[0-9]+\.[0-9]+\.zip$/i',
			\YahnisElsts\PluginUpdateChecker\v5p7\Vcs\Api::REQUIRE_RELEASE_ASSETS
		);
	} catch ( \Throwable $hal_mcp_updater_error ) {
		/*
		 * Contained failure: no token, no repository URL, no exception text,
		 * no stack trace reaches the notice or the log — only a generic line
		 * under WP_DEBUG, plus one calm admin notice.
		 */
		if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
			// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
			error_log( 'HAL MCP Integration Abilities: update checker initialization failed.' );
		}

		hal_mcp_updater_admin_notice(
			__(
				'HAL MCP Integration Abilities: the update checker could not start, so automatic GitHub updates are disabled. Plugin features are unaffected; update by uploading a release ZIP if needed.',
				'hal-mcp'
			)
		);
	}
}

/**
 * Registers one sanitized admin notice, capability-gated.
 *
 * @param string $message Pre-sanitized translatable message; escaped on output.
 * @return void
 */
function hal_mcp_updater_admin_notice( string $message ): void {
	add_action(
		'admin_notices',
		static function () use ( $message ): void {
			if ( ! current_user_can( 'activate_plugins' ) ) {
				return;
			}

			echo '<div class="notice notice-warning"><p>' . esc_html( $message ) . '</p></div>';
		}
	);
}

add_action( 'plugins_loaded', 'hal_mcp_updater_init', 20 );
