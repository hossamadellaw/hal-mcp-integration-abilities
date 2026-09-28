<?php
/**
 * build-release-lib.php — implementation of scripts/build-release.php (G11).
 *
 * Keep the entry script thin; all checks live here so the workflow and local
 * runs execute exactly the same code. Invoked only through the entry script.
 *
 * @package hal-mcp-abilities
 */

if ( PHP_SAPI !== 'cli' ) {
	exit( "Not allowed.\n" );
}

/**
 * Prints usage help.
 */
function hal_build_usage(): void {
	echo "Usage:\n";
	echo "  php scripts/build-release.php build [--out DIR] [--expect-version X.Y.Z]\n";
	echo "  php scripts/build-release.php verify <zip> [--expect-version X.Y.Z] [--expect-update-uri URI]\n";
}

/**
 * Parses `--key value` pairs; unknown or malformed options are usage errors.
 *
 * @param string[] $argv_args Remaining CLI args.
 * @param string[] $known     Option keys accepted for the command.
 * @return array<string,string>
 */
function hal_build_options( array $argv_args, array $known ): array {
	$hal_build_opts = [];
	$hal_build_i    = 0;
	while ( $hal_build_i < count( $argv_args ) ) {
		$hal_build_arg = $argv_args[ $hal_build_i ];
		if ( ! str_starts_with( $hal_build_arg, '--' ) ) {
			fwrite( STDERR, "Unexpected argument: {$hal_build_arg}\n" );
			exit( HAL_BUILD_USAGE );
		}
		$hal_build_key = substr( $hal_build_arg, 2 );
		if ( ! in_array( $hal_build_key, $known, true ) || ! isset( $argv_args[ $hal_build_i + 1 ] ) ) {
			fwrite( STDERR, "Unknown or incomplete option: {$hal_build_arg}\n" );
			exit( HAL_BUILD_USAGE );
		}
		$hal_build_opts[ $hal_build_key ] = $argv_args[ $hal_build_i + 1 ];
		$hal_build_i                     += 2;
	}

	return $hal_build_opts;
}

/**
 * Reports a named failure and stops with exit code 1.
 */
function hal_build_fail( string $message ): void {
	fwrite( STDERR, "FAIL: {$message}\n" );
	exit( HAL_BUILD_FAIL );
}

/**
 * Extracts the package identity (Version, version constant, Update URI, Stable
 * tag) from already-read main-file and readme contents, using the exact
 * patterns of this project's files.
 *
 * @param string $hal_build_main_php Main plugin file contents.
 * @param string $hal_build_readme   readme.txt contents.
 * @return array{version:string,constant:string,update_uri:string,stable_tag:string}
 */
function hal_build_identity_from_strings( string $hal_build_main_php, string $hal_build_readme ): array {
	$hal_build_identity = [
		'version'    => '',
		'constant'   => '',
		'update_uri' => '',
		'stable_tag' => '',
	];

	if ( preg_match( '/^[ \t]*\*[ \t]*Version:[ \t]*([0-9][0-9.]*)/m', $hal_build_main_php, $hal_build_m ) ) {
		$hal_build_identity['version'] = $hal_build_m[1];
	}
	if ( preg_match( "/define\(\s*'HAL_MCP_ABILITIES_VERSION'\s*,\s*'([0-9][0-9.]*)'/", $hal_build_main_php, $hal_build_m ) ) {
		$hal_build_identity['constant'] = $hal_build_m[1];
	}
	if ( preg_match( '/^[ \t]*\*[ \t]*Update URI:[ \t]*(\S+)/m', $hal_build_main_php, $hal_build_m ) ) {
		$hal_build_identity['update_uri'] = $hal_build_m[1];
	}
	if ( preg_match( '/^Stable tag:[ \t]*([0-9][0-9.]*)/m', $hal_build_readme, $hal_build_m ) ) {
		$hal_build_identity['stable_tag'] = $hal_build_m[1];
	}

	foreach ( $hal_build_identity as $hal_build_key => $hal_build_value ) {
		if ( '' === $hal_build_value ) {
			hal_build_fail( "package identity field not found: {$hal_build_key}" );
		}
	}

	return $hal_build_identity;
}

/**
 * Reads the package identity from a plugin root's source files.
 *
 * @param string $hal_build_root Absolute plugin root (source tree or extracted package).
 * @return array{version:string,constant:string,update_uri:string,stable_tag:string}
 */
function hal_build_read_identity( string $hal_build_root ): array {
	$hal_build_main   = (string) @file_get_contents( $hal_build_root . '/' . HAL_BUILD_ENTRY );
	$hal_build_readme = (string) @file_get_contents( $hal_build_root . '/readme.txt' );

	if ( '' === $hal_build_main || '' === $hal_build_readme ) {
		hal_build_fail( 'cannot read ' . HAL_BUILD_ENTRY . ' or readme.txt under ' . $hal_build_root );
	}

	return hal_build_identity_from_strings( $hal_build_main, $hal_build_readme );
}

/**
 * Asserts the four identity values agree with each other and, when supplied,
 * with the expected version / update URI.
 */
function hal_build_assert_identity( array $hal_build_identity, ?string $hal_build_expect_version, ?string $hal_build_expect_uri ): void {
	if ( $hal_build_identity['version'] !== $hal_build_identity['constant'] ) {
		hal_build_fail( "header Version ({$hal_build_identity['version']}) != version constant ({$hal_build_identity['constant']})" );
	}
	if ( $hal_build_identity['version'] !== $hal_build_identity['stable_tag'] ) {
		hal_build_fail( "header Version ({$hal_build_identity['version']}) != readme Stable tag ({$hal_build_identity['stable_tag']})" );
	}
	if ( null !== $hal_build_expect_version && $hal_build_identity['version'] !== $hal_build_expect_version ) {
		hal_build_fail( "package identity ({$hal_build_identity['version']}) != expected version ({$hal_build_expect_version})" );
	}
	if ( null !== $hal_build_expect_uri && $hal_build_identity['update_uri'] !== $hal_build_expect_uri ) {
		hal_build_fail( "Update URI ({$hal_build_identity['update_uri']}) != expected ({$hal_build_expect_uri})" );
	}
}

/**
 * Builds the file plan: [source absolute path => package-relative name].
 *
 * Derivation rules: the four root runtime files, the internal bootstrap, every
 * PHP file directly inside the module directories (includes, abilities,
 * integrations), every file inside assets/, and vendor/ recursively. No other
 * source-tree location is ever read, so docs, tests, Git-Github/, scripts/,
 * .git/, .env files and conversations cannot enter the package by construction.
 *
 * @param string $hal_build_root Absolute plugin root.
 * @return array<string,string>
 */
function hal_build_plan( string $hal_build_root ): array {
	$hal_build_plan = [];

	foreach ( HAL_BUILD_ROOT_FILES as $hal_build_file ) {
		$hal_build_source = $hal_build_root . '/' . $hal_build_file;
		if ( ! is_file( $hal_build_source ) || is_link( $hal_build_source ) ) {
			hal_build_fail( "allowlisted root file missing or is a link: {$hal_build_file}" );
		}
		$hal_build_plan[ $hal_build_source ] = $hal_build_file;
	}

	$hal_build_internal = $hal_build_root . '/' . HAL_BUILD_INTERNAL_DIR;
	if ( ! is_dir( $hal_build_internal ) ) {
		hal_build_fail( HAL_BUILD_INTERNAL_DIR . '/ folder not found in the source tree' );
	}

	$hal_build_plan[ $hal_build_internal . '/hal-mcp-abilities.php' ] = HAL_BUILD_INTERNAL_DIR . '/hal-mcp-abilities.php';

	foreach ( HAL_BUILD_PHP_DIRS as $hal_build_dir ) {
		$hal_build_dir_path = $hal_build_internal . '/' . $hal_build_dir;
		$hal_build_php      = glob( $hal_build_dir_path . '/*.php' ) ?: [];
		if ( [] === $hal_build_php ) {
			hal_build_fail( "no PHP modules found under {$hal_build_internal}/{$hal_build_dir}/" );
		}
		sort( $hal_build_php );
		foreach ( $hal_build_php as $hal_build_source ) {
			if ( is_link( $hal_build_source ) ) {
				hal_build_fail( 'symlinked module file refused: ' . substr( $hal_build_source, strlen( $hal_build_root ) + 1 ) );
			}
			$hal_build_plan[ $hal_build_source ] = HAL_BUILD_INTERNAL_DIR . '/' . $hal_build_dir . '/' . basename( $hal_build_source );
		}
	}

	$hal_build_assets = glob( $hal_build_internal . '/assets/*' ) ?: [];
	if ( [] === $hal_build_assets ) {
		hal_build_fail( "no asset files found under {$hal_build_internal}/assets/" );
	}
	sort( $hal_build_assets );
	foreach ( $hal_build_assets as $hal_build_source ) {
		if ( ! is_file( $hal_build_source ) ) {
			hal_build_fail( 'unexpected non-file entry in assets/: ' . basename( $hal_build_source ) );
		}
		if ( is_link( $hal_build_source ) ) {
			hal_build_fail( 'symlinked asset refused: ' . basename( $hal_build_source ) );
		}
		$hal_build_plan[ $hal_build_source ] = HAL_BUILD_INTERNAL_DIR . '/assets/' . basename( $hal_build_source );
	}

	hal_build_plan_dir( $hal_build_root . '/vendor', 'vendor', $hal_build_plan, realpath( $hal_build_root ) );

	return $hal_build_plan;
}

/**
 * Recursively appends a folder (vendor/) to the plan, refusing symlinks and
 * any entry whose realpath escapes the plugin root (NTFS junctions are not
 * detected by is_link on Windows — containment is checked against the root).
 *
 * @param string   $hal_build_dir        Absolute directory to walk.
 * @param string   $hal_build_prefix     Package-relative prefix.
 * @param string[] $hal_build_plan       Plan accumulator (by reference).
 * @param string   $hal_build_root_real  Realpath of the plugin root (containment boundary).
 */
function hal_build_plan_dir( string $hal_build_dir, string $hal_build_prefix, array &$hal_build_plan, string $hal_build_root_real ): void {
	if ( ! is_dir( $hal_build_dir ) ) {
		hal_build_fail( "vendor/ not found — run 'composer install' (or update) before building" );
	}
	$hal_build_entries = scandir( $hal_build_dir );
	if ( false === $hal_build_entries ) {
		hal_build_fail( "cannot read directory: {$hal_build_dir}" );
	}
	$hal_build_boundary = rtrim( $hal_build_root_real, '/\\' ) . DIRECTORY_SEPARATOR;
	foreach ( $hal_build_entries as $hal_build_entry_name ) {
		if ( '.' === $hal_build_entry_name || '..' === $hal_build_entry_name ) {
			continue;
		}
		$hal_build_source = $hal_build_dir . '/' . $hal_build_entry_name;
		if ( is_link( $hal_build_source ) ) {
			hal_build_fail( 'symlinked entry refused in vendor/: ' . $hal_build_prefix . '/' . $hal_build_entry_name );
		}
		$hal_build_real = realpath( $hal_build_source );
		if ( false === $hal_build_real || ! str_starts_with( $hal_build_real . DIRECTORY_SEPARATOR, $hal_build_boundary ) ) {
			hal_build_fail( 'entry resolves outside the plugin root (junction or broken link?): ' . $hal_build_prefix . '/' . $hal_build_entry_name );
		}
		if ( is_dir( $hal_build_source ) ) {
			hal_build_plan_dir( $hal_build_source, $hal_build_prefix . '/' . $hal_build_entry_name, $hal_build_plan, $hal_build_root_real );
			continue;
		}
		if ( ! is_file( $hal_build_source ) ) {
			hal_build_fail( 'unexpected non-file entry in vendor/: ' . $hal_build_prefix . '/' . $hal_build_entry_name );
		}
		$hal_build_plan[ $hal_build_source ] = $hal_build_prefix . '/' . $hal_build_entry_name;
	}
}

/**
 * Statically resolves this project's load-path idioms inside one PHP source
 * into slug-rooted target names:
 *
 *   HAL_MCP_ABILITIES_DIR . '...' / "..."            -> hal-mcp-abilities + literal
 *   plugin_dir_path( HAL_MCP_ABILITIES_PLUGIN_FILE ) . '...' / "..."  -> package root + literal
 *   plugin_dir_url ( HAL_MCP_ABILITIES_PLUGIN_FILE ) . '...' / "..."  -> package root + literal
 *   (require|include)(_once)? __DIR__ . '...' / "..." -> scanned file's own folder + literal
 *   (require|include)(_once)? dirname( __DIR__ ) . '...' / "..." -> one level above the scanned file's folder + literal
 *   (require|include)(_once)? '...' / "..."           -> scanned file's own folder + literal
 *
 * ABSPATH-based requires load WordPress core files and are deliberately not
 * package requirements. Dynamically composed paths (the bootstrap's module
 * foreach, the admin asset base concatenation) are covered by staging whole
 * runtime directories; a literal ending in '/' is reported as a prefix target.
 * The __DIR__, dirname(__DIR__) and bare-string families resolve against the
 * scanned file's own package folder (one level above it for dirname(__DIR__))
 * derived from $hal_build_source_name; a `..` segment inside any resolved
 * target is unsafe and fails loudly.
 *
 * @param string $hal_build_code        PHP source code.
 * @param string $hal_build_source_name Slug-rooted package name of the scanned file.
 * @return string[] Slug-rooted targets; prefix targets end with '/'.
 */
function hal_build_ref_targets( string $hal_build_code, string $hal_build_source_name ): array {
	$hal_build_targets = [];

	$hal_build_dir_part = dirname( $hal_build_source_name );

	/*
	 * Prefix families: <known expression> . '<literal>'. The internal-dir
	 * families resolve inside hal-mcp-abilities/ and their literals must start
	 * with '/'; the plugin_dir_* families resolve against the package root.
	 * Single- and double-quoted literals are both derived.
	 */
	$hal_build_prefix_families = [
		[ "/HAL_MCP_ABILITIES_DIR\s*\.\s*'([^']*)'/", true ],
		[ '/HAL_MCP_ABILITIES_DIR\s*\.\s*"([^"]*)"/', true ],
		[ "/plugin_dir_path\s*\(\s*HAL_MCP_ABILITIES_PLUGIN_FILE\s*\)\s*\.\s*'([^']*)'/", false ],
		[ '/plugin_dir_path\s*\(\s*HAL_MCP_ABILITIES_PLUGIN_FILE\s*\)\s*\.\s*"([^"]*)"/', false ],
		[ "/plugin_dir_url\s*\(\s*HAL_MCP_ABILITIES_PLUGIN_FILE\s*\)\s*\.\s*'([^']*)'/", false ],
		[ '/plugin_dir_url\s*\(\s*HAL_MCP_ABILITIES_PLUGIN_FILE\s*\)\s*\.\s*"([^"]*)"/', false ],
	];

	foreach ( $hal_build_prefix_families as [ $hal_build_pattern, $hal_build_internal ] ) {
		if ( ! preg_match_all( $hal_build_pattern, $hal_build_code, $hal_build_matches ) ) {
			continue;
		}
		foreach ( $hal_build_matches[1] as $hal_build_literal ) {
			if ( '' === $hal_build_literal ) {
				continue;
			}
			if ( $hal_build_internal ) {
				// Literal starts with '/': resolved inside hal-mcp-abilities/.
				if ( ! str_starts_with( $hal_build_literal, '/' ) ) {
					continue;
				}
				$hal_build_targets[] = HAL_BUILD_SLUG . '/' . HAL_BUILD_INTERNAL_DIR . $hal_build_literal;
				continue;
			}
			$hal_build_targets[] = HAL_BUILD_SLUG . '/' . $hal_build_literal;
		}
	}

	/*
	 * Direct families anchored on the require/include keywords as written: a
	 * __DIR__-relative literal, a dirname(__DIR__)-relative literal (resolved
	 * one level ABOVE the scanned file's own folder), or a bare-string literal
	 * (absolute system-path literals starting with '/' are skipped for the
	 * bare family only). The lookbehind keeps array keys ('include_drafts')
	 * and identifier/string contexts from matching.
	 */
	$hal_build_direct_families = [
		// [ pattern, skip absolute literals, base: 'dir' = the scanned file's
		// own folder, 'parent' = one level above it ].
		[ '/(?<![\w$\'"])(?:require|include)(?:_once)?\s*\(?\s*__DIR__\s*\.\s*[\'"]([^\'"]+)[\'"]/', false, 'dir' ],
		[ '/(?<![\w$\'"])(?:require|include)(?:_once)?\s*\(?\s*dirname\s*\(\s*__DIR__\s*\)\s*\.\s*[\'"]([^\'"]+)[\'"]/', false, 'parent' ],
		[ '/(?<![\w$\'"])(?:require|include)(?:_once)?\s*\(?\s*[\'"]([^\'"]+)[\'"]/', true, 'dir' ],
	];

	foreach ( $hal_build_direct_families as [ $hal_build_pattern, $hal_build_skip_absolute, $hal_build_base ] ) {
		if ( ! preg_match_all( $hal_build_pattern, $hal_build_code, $hal_build_matches ) ) {
			continue;
		}
		$hal_build_base_dir = 'parent' === $hal_build_base ? dirname( $hal_build_dir_part ) : $hal_build_dir_part;
		foreach ( $hal_build_matches[1] as $hal_build_literal ) {
			if ( $hal_build_skip_absolute && str_starts_with( $hal_build_literal, '/' ) ) {
				continue;
			}
			$hal_build_composed = $hal_build_base_dir . ( str_starts_with( $hal_build_literal, '/' ) ? '' : '/' ) . $hal_build_literal;
			$hal_build_resolved = hal_build_normalize( $hal_build_composed );
			if ( '' === $hal_build_resolved ) {
				hal_build_fail( "closure check: unsafe derived require target '{$hal_build_composed}' (referenced in {$hal_build_source_name})" );
			}
			$hal_build_targets[] = $hal_build_resolved;
		}
	}

	return array_values( array_unique( $hal_build_targets ) );
}

/**
 * Checks reference targets against an existence probe; fails on any miss.
 *
 * @param string[]   $hal_build_targets    Targets from hal_build_ref_targets().
 * @param string[]   $hal_build_names      Known package-relative names.
 * @param string     $hal_build_source_ref Which file referenced them (messages).
 * @return int Number of verified references.
 */
function hal_build_check_targets( array $hal_build_targets, array $hal_build_names, string $hal_build_source_ref ): int {
	$hal_build_name_set = array_flip( $hal_build_names );
	$hal_build_verified = 0;

	foreach ( $hal_build_targets as $hal_build_target ) {
		$hal_build_is_prefix = str_ends_with( $hal_build_target, '/' );
		if ( $hal_build_is_prefix ) {
			// Prefix reference: at least one package entry must live under it
			// (covers dynamic per-module and per-asset composition).
			$hal_build_hit = false;
			foreach ( $hal_build_name_set as $hal_build_known => $hal_build_unused ) {
				if ( str_starts_with( $hal_build_known, $hal_build_target ) ) {
					$hal_build_hit = true;
					break;
				}
			}
			if ( ! $hal_build_hit ) {
				hal_build_fail( "closure check: nothing packaged under '{$hal_build_target}' (referenced in {$hal_build_source_ref})" );
			}
		} elseif ( ! isset( $hal_build_name_set[ $hal_build_target ] ) ) {
			hal_build_fail( "closure check: '{$hal_build_target}' referenced in {$hal_build_source_ref} is missing from the package" );
		}
		++$hal_build_verified;
	}

	return $hal_build_verified;
}

/**
 * Normalizes a package-relative name; returns '' when unsafe.
 */
function hal_build_normalize( string $hal_build_name ): string {
	$hal_build_name = str_replace( '\\', '/', $hal_build_name );
	if ( '' !== $hal_build_name && str_starts_with( $hal_build_name, '/' ) ) {
		return '';
	}
	$hal_build_parts = [];
	foreach ( explode( '/', $hal_build_name ) as $hal_build_segment ) {
		if ( '' === $hal_build_segment || '.' === $hal_build_segment ) {
			continue;
		}
		if ( '..' === $hal_build_segment ) {
			return '';
		}
		$hal_build_parts[] = $hal_build_segment;
	}

	return implode( '/', $hal_build_parts );
}

/**
 * Runs the closure check over the staged build tree (real filesystem probe).
 *
 * @param string $hal_build_staging_root Staging root holding the slug folder.
 * @return int Verified reference count.
 */
function hal_build_check_closures_staged( string $hal_build_staging_root ): int {
	/*
	 * The staging tree mirrors the package CONTENTS (no top-level slug folder
	 * yet — that prefix is added when entries go into the ZIP), so collected
	 * names get the slug prefix here and targets match them directly.
	 */
	$hal_build_names = [];
	$hal_build_it    = new RecursiveIteratorIterator(
		new RecursiveDirectoryIterator( $hal_build_staging_root, FilesystemIterator::SKIP_DOTS )
	);
	foreach ( $hal_build_it as $hal_build_item ) {
		if ( $hal_build_item->isFile() ) {
			$hal_build_names[] = HAL_BUILD_SLUG . '/' . str_replace( '\\', '/', substr( $hal_build_item->getPathname(), strlen( $hal_build_staging_root ) + 1 ) );
		}
	}

	$hal_build_verified = 0;
	foreach ( HAL_BUILD_PHP_DIRS as $hal_build_dir ) {
		$hal_build_dir_path = $hal_build_staging_root . '/' . HAL_BUILD_INTERNAL_DIR . '/' . $hal_build_dir;
		$hal_build_php      = glob( $hal_build_dir_path . '/*.php' ) ?: [];
		sort( $hal_build_php );
		foreach ( $hal_build_php as $hal_build_file ) {
			$hal_build_code     = (string) @file_get_contents( $hal_build_file );
			$hal_build_rel_name = HAL_BUILD_SLUG . '/' . HAL_BUILD_INTERNAL_DIR . '/' . $hal_build_dir . '/' . basename( $hal_build_file );
			$hal_build_verified += hal_build_check_targets( hal_build_ref_targets( $hal_build_code, $hal_build_rel_name ), $hal_build_names, $hal_build_rel_name );
		}
	}

	// The internal bootstrap is outside the three module dirs but carries the
	// main require list — scan it explicitly.
	$hal_build_bootstrap = $hal_build_staging_root . '/' . HAL_BUILD_INTERNAL_DIR . '/hal-mcp-abilities.php';
	$hal_build_code      = (string) @file_get_contents( $hal_build_bootstrap );
	$hal_build_verified += hal_build_check_targets(
		hal_build_ref_targets( $hal_build_code, HAL_BUILD_SLUG . '/' . HAL_BUILD_INTERNAL_DIR . '/hal-mcp-abilities.php' ),
		$hal_build_names,
		HAL_BUILD_SLUG . '/' . HAL_BUILD_INTERNAL_DIR . '/hal-mcp-abilities.php'
	);

	return $hal_build_verified;
}

/**
 * Reads every entry of a ZIP (which validates each CRC) and returns the names.
 *
 * @return string[] All entry names.
 */
function hal_build_zip_read_all( string $hal_build_zip_path ): array {
	$hal_build_zip   = new ZipArchive();
	$hal_build_code = $hal_build_zip->open( $hal_build_zip_path, ZipArchive::CHECKCONS );
	if ( true !== $hal_build_code ) {
		hal_build_fail( "cannot open ZIP (code {$hal_build_code}): {$hal_build_zip_path}" );
	}
	$hal_build_names = [];
	for ( $hal_build_i = 0; $hal_build_i < $hal_build_zip->numFiles; ++$hal_build_i ) {
		$hal_build_name = $hal_build_zip->getNameIndex( $hal_build_i );
		if ( false === $hal_build_name || null === $hal_build_name ) {
			hal_build_fail( "unreadable entry index {$hal_build_i} in {$hal_build_zip_path}" );
		}
		if ( false === $hal_build_zip->getFromIndex( $hal_build_i ) ) {
			$hal_build_zip->close();
			hal_build_fail( "CRC/content failure on entry '{$hal_build_name}' in {$hal_build_zip_path}" );
		}
		$hal_build_names[] = $hal_build_name;
	}
	$hal_build_zip->close();

	return $hal_build_names;
}

/**
 * The `build` command.
 *
 * @param string               $hal_build_root Absolute plugin root.
 * @param array<string,string> $hal_build_opts Parsed options.
 * @return int Process exit code (0 on success; failures exit directly).
 */
function hal_build_command_build( string $hal_build_root, array $hal_build_opts ): int {
	if ( ! extension_loaded( 'zip' ) ) {
		hal_build_fail( 'the PHP zip extension is required for building' );
	}

	$hal_build_out = $hal_build_opts['out'] ?? 'dist';
	if ( ! is_dir( $hal_build_out ) && ! @mkdir( $hal_build_out, 0775, true ) ) {
		hal_build_fail( "cannot create output directory: {$hal_build_out}" );
	}

	$hal_build_identity = hal_build_read_identity( $hal_build_root );
	hal_build_assert_identity( $hal_build_identity, $hal_build_opts['expect-version'] ?? null, null );

	$hal_build_zip_name = HAL_BUILD_SLUG . '-' . $hal_build_identity['version'] . '.zip';
	$hal_build_zip_path = $hal_build_out . '/' . $hal_build_zip_name;

	$hal_build_plan = hal_build_plan( $hal_build_root );

	// Stage under the single top-level slug folder, verify the staged closures,
	// then zip the staging tree.
	$hal_build_staging = $hal_build_out . '/build-' . HAL_BUILD_SLUG;
	hal_build_rrmdir( $hal_build_staging );
	if ( ! @mkdir( $hal_build_staging . '/' . HAL_BUILD_SLUG, 0775, true ) ) {
		hal_build_fail( "cannot create staging directory: {$hal_build_staging}" );
	}
	foreach ( $hal_build_plan as $hal_build_source => $hal_build_rel_name ) {
		$hal_build_dest = $hal_build_staging . '/' . $hal_build_rel_name;
		$hal_build_dir  = dirname( $hal_build_dest );
		if ( ! is_dir( $hal_build_dir ) && ! @mkdir( $hal_build_dir, 0775, true ) ) {
			hal_build_fail( "cannot create staging subdirectory: {$hal_build_dir}" );
		}
		if ( ! @copy( $hal_build_source, $hal_build_dest ) ) {
			hal_build_fail( "cannot copy {$hal_build_rel_name} into staging" );
		}
	}

	$hal_build_refs = hal_build_check_closures_staged( $hal_build_staging );

	$hal_build_zip = new ZipArchive();
	if ( true !== $hal_build_zip->open( $hal_build_zip_path, ZipArchive::CREATE | ZipArchive::OVERWRITE ) ) {
		hal_build_fail( "cannot open ZIP for writing: {$hal_build_zip_path}" );
	}
	$hal_build_added = 0;
	foreach ( $hal_build_plan as $hal_build_source => $hal_build_rel_name ) {
		if ( true !== $hal_build_zip->addFile( $hal_build_source, HAL_BUILD_SLUG . '/' . $hal_build_rel_name ) ) {
			$hal_build_zip->close();
			hal_build_fail( "addFile failed for {$hal_build_rel_name}" );
		}
		++$hal_build_added;
	}
	if ( ! $hal_build_zip->close() ) {
		hal_build_fail( 'failed to finalize the ZIP archive' );
	}

	// CRC self-check: read every entry back out of the finished archive.
	hal_build_zip_read_all( $hal_build_zip_path );

	$hal_build_hash = hash_file( 'sha256', $hal_build_zip_path );
	if ( false === $hal_build_hash ) {
		hal_build_fail( 'cannot hash the built ZIP' );
	}
	file_put_contents( $hal_build_zip_path . '.sha256', $hal_build_hash . '  ' . $hal_build_zip_name . "\n" );

	hal_build_rrmdir( $hal_build_staging );

	echo "[build] {$hal_build_zip_name}: {$hal_build_added} entries, version {$hal_build_identity['version']}, {$hal_build_refs} closure reference(s) verified, sha256 {$hal_build_hash}\n";
	echo "[build] sidecar: {$hal_build_zip_path}.sha256\n";

	return HAL_BUILD_OK;
}

/**
 * The `verify` command: full package gate over a finished ZIP.
 *
 * @param string               $hal_build_zip_path ZIP to verify.
 * @param array<string,string> $hal_build_opts     Parsed options.
 * @return int Process exit code (0 on success; failures exit directly).
 */
function hal_build_command_verify( string $hal_build_zip_path, array $hal_build_opts ): int {
	if ( ! is_file( $hal_build_zip_path ) ) {
		hal_build_fail( "ZIP not found: {$hal_build_zip_path}" );
	}

	$hal_build_names = hal_build_zip_read_all( $hal_build_zip_path );

	// Safe names only: no traversal, no absolute paths, no backslash leakage,
	// no duplicates — duplicates are caught case-insensitively and with
	// Windows filename normalization, because case-variant or trailing-dot
	// shadow entries collide on Windows extraction targets even when they
	// look distinct inside the archive.
	$hal_build_clean    = [];
	$hal_build_seen_dup = [];
	foreach ( $hal_build_names as $hal_build_name ) {
		if ( str_contains( $hal_build_name, '\\' ) || str_starts_with( $hal_build_name, '/' ) ) {
			hal_build_fail( "unsafe entry name in ZIP: '{$hal_build_name}'" );
		}
		$hal_build_normalized = hal_build_normalize( $hal_build_name );
		if ( '' === $hal_build_normalized ) {
			hal_build_fail( "unsafe entry name in ZIP: '{$hal_build_name}'" );
		}
		$hal_build_dup_key = rtrim( strtolower( $hal_build_normalized ), '. ' );
		if ( isset( $hal_build_seen_dup[ $hal_build_dup_key ] ) ) {
			hal_build_fail( "duplicate/shadowed entry name in ZIP: '{$hal_build_normalized}'" );
		}
		$hal_build_seen_dup[ $hal_build_dup_key ] = true;
		$hal_build_clean[ $hal_build_normalized ] = true;
	}
	$hal_build_names = array_keys( $hal_build_clean );

	// Exactly one top-level folder, named after the slug.
	$hal_build_roots = [];
	foreach ( $hal_build_names as $hal_build_name ) {
		$hal_build_roots[ explode( '/', $hal_build_name )[0] ] = true;
	}
	if ( [ HAL_BUILD_SLUG ] !== array_keys( $hal_build_roots ) ) {
		hal_build_fail( 'ZIP must contain exactly one top-level folder named ' . HAL_BUILD_SLUG . '; found: ' . implode( ', ', array_keys( $hal_build_roots ) ) );
	}

	// Strict package shape, on top of the roots check: every entry name must
	// start with the slug folder (this also rejects a top-level FILE named
	// exactly HAL_BUILD_SLUG, which the roots check above lets through), and
	// each remainder must match the runtime allowlist shape — one of the four
	// root files, the internal bootstrap, a module PHP file directly inside
	// includes/abilities/integrations, an asset file one level under assets/,
	// or anything under vendor/.
	$hal_build_shape_module = '#^' . HAL_BUILD_INTERNAL_DIR . '/(?:' . implode( '|', HAL_BUILD_PHP_DIRS ) . ')/[^/]+\.php$#';
	$hal_build_shape_asset  = '#^' . HAL_BUILD_INTERNAL_DIR . '/assets/[^/]+$#';
	foreach ( $hal_build_names as $hal_build_name ) {
		if ( ! str_starts_with( $hal_build_name, HAL_BUILD_SLUG . '/' ) ) {
			hal_build_fail( "entry outside the package shape: '{$hal_build_name}'" );
		}
		$hal_build_rel = substr( $hal_build_name, strlen( HAL_BUILD_SLUG ) + 1 );
		if (
			! in_array( $hal_build_rel, HAL_BUILD_ROOT_FILES, true )
			&& HAL_BUILD_INTERNAL_DIR . '/hal-mcp-abilities.php' !== $hal_build_rel
			&& ! preg_match( $hal_build_shape_module, $hal_build_rel )
			&& ! preg_match( $hal_build_shape_asset, $hal_build_rel )
			&& ! str_starts_with( $hal_build_rel, 'vendor/' )
		) {
			hal_build_fail( "entry outside the package shape: '{$hal_build_name}'" );
		}
	}

	// Leakage gate. Development roots are rejected only OUTSIDE vendor/:
	// vendor content is pinned by composer.lock and audited, and a future PUC
	// release legitimately carrying its own tests/ or docs/ folder must not
	// wedge the release gate. Secret-like FILE names are rejected everywhere,
	// vendor included.
	$hal_build_forbidden_roots = [ '.git', '.github', 'docs', 'tests', 'Git-Github', 'scripts', 'node_modules' ];
	$hal_build_vendor_prefix   = HAL_BUILD_SLUG . '/vendor/';
	foreach ( $hal_build_names as $hal_build_name ) {
		$hal_build_segments = explode( '/', $hal_build_name );
		$hal_build_base     = (string) end( $hal_build_segments );
		if ( ! str_starts_with( $hal_build_name, $hal_build_vendor_prefix ) ) {
			foreach ( $hal_build_segments as $hal_build_segment ) {
				if ( in_array( $hal_build_segment, $hal_build_forbidden_roots, true ) || str_starts_with( $hal_build_segment, '_tmp-' ) ) {
					hal_build_fail( "development content leaked into package: '{$hal_build_name}'" );
				}
			}
		}
		if ( '' !== $hal_build_base && ( str_starts_with( $hal_build_base, '.env' ) || preg_match( '/\.(log|pem|key)$/', $hal_build_base ) ) ) {
			hal_build_fail( "secret-like file leaked into package: '{$hal_build_name}'" );
		}
		if ( str_starts_with( $hal_build_name, HAL_BUILD_SLUG . '/' ) ) {
			$hal_build_rel = substr( $hal_build_name, strlen( HAL_BUILD_SLUG ) + 1 );
			if ( in_array( $hal_build_rel, [ 'composer.json', 'composer.lock', 'ABILITIES-REGISTRY.md' ], true ) ) {
				hal_build_fail( "non-runtime file leaked into package: '{$hal_build_rel}'" );
			}
		}
	}

	// Required runtime set: root files, bootstrap, module files, composer
	// autoload, PUC. The module files load via the bootstrap's foreach over a
	// literal list (dynamic paths the closure scanner cannot derive), so each
	// one is required here explicitly, mirrored from HAL_BUILD_MODULE_FILES.
	$hal_build_required = [
		HAL_BUILD_ENTRY,
		'uninstall.php',
		'readme.txt',
		'LICENSE',
		HAL_BUILD_INTERNAL_DIR . '/hal-mcp-abilities.php',
		'vendor/autoload.php',
		'vendor/composer/autoload_real.php',
		'vendor/composer/autoload_psr4.php',
		'vendor/composer/autoload_static.php',
		HAL_BUILD_VENDOR_PUC,
	];
	foreach ( HAL_BUILD_MODULE_FILES as $hal_build_module ) {
		$hal_build_required[] = HAL_BUILD_INTERNAL_DIR . '/includes/' . $hal_build_module;
	}
	foreach ( $hal_build_required as $hal_build_required_name ) {
		if ( ! isset( $hal_build_clean[ HAL_BUILD_SLUG . '/' . $hal_build_required_name ] ) ) {
			hal_build_fail( "required file missing from package: {$hal_build_required_name}" );
		}
	}

	// Vendor integrity beyond presence: the pinned PUC package must be intact
	// inside the ZIP, not merely present. composer.lock at the TOOL's project
	// root pins yahnis-elsts/plugin-update-checker; the packaged
	// vendor/composer/installed.php must declare the same version, and the PUC
	// payload must still carry its real loader and the v5p7 namespace.
	// Note on the version comparison: composer.lock stores 'v5.7' while
	// installed.php stores pretty_version 'v5.7' plus the expanded numeric
	// form '5.7.0.0'; version_compare( '5.7.0.0', '5.7' ) is 1, not 0, so the
	// robust pairing for these actual shapes is: pretty_version with the
	// leading v/V stripped equals the lock version stripped, AND the expanded
	// version equals the lock version followed only by zero dotted components
	// (5.7.0.0 ~ 5.7, while 5.7.9.9 is rejected).
	$hal_build_lock_path = dirname( __DIR__ ) . '/composer.lock';
	$hal_build_lock_json = @file_get_contents( $hal_build_lock_path );
	$hal_build_lock      = ( false !== $hal_build_lock_json && '' !== $hal_build_lock_json ) ? json_decode( $hal_build_lock_json, true ) : null;
	if ( ! is_array( $hal_build_lock ) ) {
		hal_build_fail( "cannot read composer.lock at the tool's project root: {$hal_build_lock_path}" );
	}
	$hal_build_puc_package  = 'yahnis-elsts/plugin-update-checker';
	$hal_build_lock_version = '';
	foreach ( $hal_build_lock['packages'] ?? [] as $hal_build_locked ) {
		if ( is_array( $hal_build_locked ) && ( $hal_build_locked['name'] ?? '' ) === $hal_build_puc_package ) {
			$hal_build_lock_version = (string) ( $hal_build_locked['version'] ?? '' );
			break;
		}
	}
	if ( '' === $hal_build_lock_version ) {
		hal_build_fail( "composer.lock does not pin {$hal_build_puc_package}" );
	}

	$hal_build_zip = new ZipArchive();
	if ( true !== $hal_build_zip->open( $hal_build_zip_path ) ) {
		hal_build_fail( "cannot reopen ZIP for vendor integrity checks: {$hal_build_zip_path}" );
	}

	// (a) installed.php, read FROM INSIDE THE ZIP; eval-free parsing anchored
	// on the package-name key of composer's data file shape.
	$hal_build_installed_php = (string) $hal_build_zip->getFromName( HAL_BUILD_SLUG . '/vendor/composer/installed.php' );
	if ( '' === $hal_build_installed_php ) {
		$hal_build_zip->close();
		hal_build_fail( 'vendor/composer/installed.php missing or empty inside the package' );
	}
	if ( ! preg_match(
		"/'" . preg_quote( $hal_build_puc_package, '/' ) . "'\s*=>\s*array\(\s*'pretty_version'\s*=>\s*'([^']*)'\s*,\s*'version'\s*=>\s*'([^']*)'/",
		$hal_build_installed_php,
		$hal_build_installed_m
	) ) {
		$hal_build_zip->close();
		hal_build_fail( "vendor/composer/installed.php inside the package does not declare {$hal_build_puc_package} in the expected composer shape" );
	}
	$hal_build_lock_norm = ltrim( $hal_build_lock_version, 'vV' );
	$hal_build_pretty    = $hal_build_installed_m[1];
	$hal_build_expanded  = $hal_build_installed_m[2];
	if (
		ltrim( $hal_build_pretty, 'vV' ) !== $hal_build_lock_norm
		|| (
			$hal_build_expanded !== $hal_build_lock_norm
			&& ! preg_match( '/^' . preg_quote( $hal_build_lock_norm, '/' ) . '(\.0+)*$/', $hal_build_expanded )
		)
	) {
		$hal_build_zip->close();
		hal_build_fail( "vendor/composer/installed.php inside the package declares a {$hal_build_puc_package} version mismatch: pretty '{$hal_build_pretty}', version '{$hal_build_expanded}' vs composer.lock '{$hal_build_lock_version}'" );
	}

	// (b) The PUC root file in this library layout is a small loader (218
	// bytes) pointing at load-v5p7.php, which is where the v5p7 namespace is
	// declared; guard BOTH against hollowing — the demonstrated hollowed stub
	// loses the loader reference, and a gutted payload loses size and marker.
	$hal_build_puc_root = (string) $hal_build_zip->getFromName( HAL_BUILD_SLUG . '/' . HAL_BUILD_VENDOR_PUC );
	if ( '' === $hal_build_puc_root ) {
		$hal_build_zip->close();
		hal_build_fail( 'vendor PUC entry file missing or empty inside the package' );
	}
	if ( ! str_contains( $hal_build_puc_root, 'load-v5p7.php' ) ) {
		$hal_build_zip->close();
		hal_build_fail( 'vendor PUC entry file is hollowed inside the package: the load-v5p7.php loader reference is missing' );
	}
	$hal_build_puc_payload = (string) $hal_build_zip->getFromName( HAL_BUILD_SLUG . '/vendor/yahnis-elsts/plugin-update-checker/load-v5p7.php' );
	if ( '' === $hal_build_puc_payload ) {
		$hal_build_zip->close();
		hal_build_fail( 'vendor PUC payload load-v5p7.php missing or empty inside the package' );
	}
	if ( strlen( $hal_build_puc_payload ) <= 1024 ) {
		$hal_build_zip->close();
		hal_build_fail( 'vendor PUC payload load-v5p7.php suspiciously small inside the package: ' . strlen( $hal_build_puc_payload ) . ' bytes' );
	}
	if ( ! str_contains( $hal_build_puc_payload, 'YahnisElsts\PluginUpdateChecker\v5p7' ) ) {
		$hal_build_zip->close();
		hal_build_fail( 'vendor PUC payload load-v5p7.php does not contain the v5p7 namespace marker inside the package' );
	}

	$hal_build_zip->close();

	// Closure checks against the packaged code itself.
	$hal_build_zip = new ZipArchive();
	if ( true !== $hal_build_zip->open( $hal_build_zip_path ) ) {
		hal_build_fail( "cannot reopen ZIP for closure reads: {$hal_build_zip_path}" );
	}
	$hal_build_verified = 0;
	foreach ( HAL_BUILD_PHP_DIRS as $hal_build_dir ) {
		$hal_build_prefix = HAL_BUILD_SLUG . '/' . HAL_BUILD_INTERNAL_DIR . '/' . $hal_build_dir . '/';
		foreach ( $hal_build_names as $hal_build_name ) {
			if ( ! str_starts_with( $hal_build_name, $hal_build_prefix ) || ! str_ends_with( $hal_build_name, '.php' ) ) {
				continue;
			}
			$hal_build_code = $hal_build_zip->getFromName( $hal_build_name );
			if ( false === $hal_build_code ) {
				hal_build_fail( "cannot read packaged module for closure check: {$hal_build_name}" );
			}
			$hal_build_verified += hal_build_check_targets( hal_build_ref_targets( (string) $hal_build_code, $hal_build_name ), $hal_build_names, $hal_build_name );
		}
	}

	// The internal bootstrap is outside the three module dirs but carries the
	// main require list — scan it explicitly.
	$hal_build_bootstrap_name = HAL_BUILD_SLUG . '/' . HAL_BUILD_INTERNAL_DIR . '/hal-mcp-abilities.php';
	$hal_build_code           = $hal_build_zip->getFromName( $hal_build_bootstrap_name );
	if ( false === $hal_build_code ) {
		hal_build_fail( "cannot read packaged bootstrap for closure check: {$hal_build_bootstrap_name}" );
	}
	$hal_build_verified += hal_build_check_targets( hal_build_ref_targets( (string) $hal_build_code, $hal_build_bootstrap_name ), $hal_build_names, $hal_build_bootstrap_name );

	$hal_build_zip->close();

	// Package identity, read from inside the ZIP — never from local files.
	$hal_build_zip = new ZipArchive();
	if ( true !== $hal_build_zip->open( $hal_build_zip_path ) ) {
		hal_build_fail( "cannot reopen ZIP for identity read: {$hal_build_zip_path}" );
	}
	$hal_build_main_php = (string) $hal_build_zip->getFromName( HAL_BUILD_SLUG . '/' . HAL_BUILD_ENTRY );
	$hal_build_readme   = (string) $hal_build_zip->getFromName( HAL_BUILD_SLUG . '/readme.txt' );
	$hal_build_zip->close();

	$hal_build_identity = hal_build_identity_from_strings( $hal_build_main_php, $hal_build_readme );
	hal_build_assert_identity( $hal_build_identity, $hal_build_opts['expect-version'] ?? null, $hal_build_opts['expect-update-uri'] ?? null );

	// Asset name must carry the expected release version when one was declared.
	$hal_build_expected_zip_name = HAL_BUILD_SLUG . '-' . $hal_build_identity['version'] . '.zip';
	if ( null !== ( $hal_build_opts['expect-version'] ?? null ) && basename( $hal_build_zip_path ) !== $hal_build_expected_zip_name ) {
		hal_build_fail( "asset name '" . basename( $hal_build_zip_path ) . "' does not match the expected release name {$hal_build_expected_zip_name}" );
	}

	// SHA256 sidecar, when present next to the ZIP, must match.
	$hal_build_sidecar = $hal_build_zip_path . '.sha256';
	if ( is_file( $hal_build_sidecar ) ) {
		$hal_build_hash_actual = hash_file( 'sha256', $hal_build_zip_path );
		$hal_build_hash_stored = '';
		foreach ( file( $hal_build_sidecar, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES ) as $hal_build_line ) {
			$hal_build_fields = preg_split( '/\s+/', trim( $hal_build_line ) ) ?: [];
			if ( isset( $hal_build_fields[0], $hal_build_fields[1] ) && $hal_build_fields[1] === basename( $hal_build_zip_path ) ) {
				$hal_build_hash_stored = $hal_build_fields[0];
				break;
			}
		}
		if ( '' === $hal_build_hash_stored || ! is_string( $hal_build_hash_actual ) || ! hash_equals( $hal_build_hash_actual, $hal_build_hash_stored ) ) {
			hal_build_fail( 'SHA256 sidecar mismatch for ' . basename( $hal_build_zip_path ) );
		}
		echo "[verify] sha256 sidecar matches ({$hal_build_hash_stored})\n";
	}

	printf(
		"[verify] OK: %d entries, version %s, update URI %s, %d closure reference(s) verified\n",
		count( $hal_build_names ),
		$hal_build_identity['version'],
		$hal_build_identity['update_uri'],
		$hal_build_verified
	);

	return HAL_BUILD_OK;
}

/**
 * Recursively removes a staging directory created by this tool.
 */
function hal_build_rrmdir( string $hal_build_dir ): void {
	if ( ! is_dir( $hal_build_dir ) ) {
		return;
	}
	$hal_build_entries = scandir( $hal_build_dir ) ?: [];
	foreach ( $hal_build_entries as $hal_build_entry_name ) {
		if ( '.' === $hal_build_entry_name || '..' === $hal_build_entry_name ) {
			continue;
		}
		$hal_build_path = $hal_build_dir . '/' . $hal_build_entry_name;
		if ( is_dir( $hal_build_path ) && ! is_link( $hal_build_path ) ) {
			hal_build_rrmdir( $hal_build_path );
			continue;
		}
		@unlink( $hal_build_path );
	}
	@rmdir( $hal_build_dir );
}
