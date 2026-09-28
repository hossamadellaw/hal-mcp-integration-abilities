<?php
/**
 * scripts/build-release.php — local build + package verification tool (G11).
 *
 * Single location for the release-package checks, reused by the local
 * developer and by .github/workflows/release.yml: nothing here duplicates a
 * check that lives elsewhere, and no other file re-implements package checks.
 *
 * Commands (CLI only):
 *   php scripts/build-release.php build [--out DIR] [--expect-version X.Y.Z]
 *       Assembles the runtime allowlist into one ZIP named
 *       hal-mcp-integration-abilities-<version>.zip plus a .sha256 sidecar.
 *       The version comes from the packaged plugin header and must already
 *       agree with the version constant and the readme Stable tag; when
 *       --expect-version is given (the workflow passes the tag), disagreement
 *       fails the build.
 *   php scripts/build-release.php verify <zip> [--expect-version X.Y.Z]
 *                                      [--expect-update-uri URI]
 *       Verifies a ZIP end to end: CRC of every entry, safe entry names, one
 *       top-level slug folder, no development/secret leakage, the required
 *       runtime set, require/asset/vendor closure derived from the packaged
 *       code itself, and the four-way package identity (header Version,
 *       version constant, Update URI, readme Stable tag).
 *
 * Exit codes: 0 pass, 1 check failure, 2 usage.
 *
 * @package hal-mcp-abilities
 */

if ( PHP_SAPI !== 'cli' ) {
	exit( "scripts/build-release.php must run from the CLI only.\n" );
}

const HAL_BUILD_OK    = 0;
const HAL_BUILD_FAIL  = 1;
const HAL_BUILD_USAGE = 2;

const HAL_BUILD_SLUG           = 'hal-mcp-integration-abilities';
const HAL_BUILD_ENTRY          = 'hal-mcp-integration-abilities.php';
const HAL_BUILD_INTERNAL_DIR   = 'hal-mcp-abilities';
const HAL_BUILD_VENDOR_PUC     = 'vendor/yahnis-elsts/plugin-update-checker/plugin-update-checker.php';

/*
 * Package allowlist (G11): the entry, uninstall.php, readme.txt, LICENSE, the
 * hal-mcp-abilities/ runtime files (bootstrap, includes/, abilities/,
 * integrations/, assets/) and vendor/. Everything else — docs, tests,
 * Git-Github/, scripts/, .git/, .env, keys, conversations — is excluded by
 * construction, and the verify command rejects it if it ever appears.
 */
const HAL_BUILD_ROOT_FILES = [ HAL_BUILD_ENTRY, 'uninstall.php', 'readme.txt', 'LICENSE' ];
const HAL_BUILD_PHP_DIRS   = [ 'includes', 'abilities', 'integrations' ];
// Mirrors the foreach module list in hal-mcp-abilities/hal-mcp-abilities.php —
// update BOTH when that list changes.
const HAL_BUILD_MODULE_FILES = [ 'http-client.php', 'providers.php', 'settings.php', 'runner.php', 'admin.php', 'updater.php' ];

$hal_build_args = array_slice( $argv, 1 );
$hal_build_cmd  = $hal_build_args[0] ?? '';

if ( '--help' === $hal_build_cmd || '' === $hal_build_cmd ) {
	hal_build_usage();
	exit( HAL_BUILD_USAGE );
}

array_shift( $hal_build_args );

require __DIR__ . '/build-release-lib.php';

$hal_build_root = dirname( __DIR__ );

if ( 'build' === $hal_build_cmd ) {
	exit( hal_build_command_build( $hal_build_root, hal_build_options( $hal_build_args, [ 'out', 'expect-version' ] ) ) );
}

if ( 'verify' === $hal_build_cmd && ! empty( $hal_build_args ) ) {
	$hal_build_zip = array_shift( $hal_build_args );
	exit( hal_build_command_verify( $hal_build_zip, hal_build_options( $hal_build_args, [ 'expect-version', 'expect-update-uri' ] ) ) );
}

hal_build_usage();
exit( HAL_BUILD_USAGE );
