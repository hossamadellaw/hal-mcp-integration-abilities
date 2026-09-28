<?php
/**
 * hal-mcp-abilities — environment discovery and inventory (F14).
 *
 * One lazily computed, cached summary of what this site can support for
 * hal/* operations: WordPress/PHP versions, single/multisite, the active
 * theme, every installed plugin with its identifier and state (with
 * must-use and network-active plugins handled as distinct categories),
 * public content types and taxonomies with their registered capability
 * mappings, WooCommerce and its product types, and the integrations
 * registered by includes/integrations.php.
 *
 * Roadmap §4.4/F14 rules this file enforces in code:
 * - The first inventory is LOCAL and runs only after the environment is
 *   complete: capture never happens at require time, and a call before
 *   `init` returns a live result WITHOUT caching it, because post types
 *   are not all registered yet (F02's discovery-initialization rule).
 * - Cache invalidation is fingerprint-driven: a cheap signature (active
 *   plugin set, network/must-use plugins, theme, locale, WP/PHP/plugin
 *   versions) is compared on every read, so activating/deactivating a
 *   plugin, switching the theme, or changing the language re-captures
 *   automatically. No heavy scan per request, no scheduler, no hooks on
 *   every page load — the check only runs when someone asks for the
 *   inventory, and hal_mcp_environment_invalidate() is the explicit
 *   re-discovery entry point (the F19 admin button will call it).
 * - Discovery of languages, editors, and SEO goes through
 *   integrations.php (the registry), never through plugin-name guessing
 *   here. Capturing performs no network request of any kind: the
 *   wordpress.org update check is an admin-side action, and the update
 *   flags hal/get-site-inventory shows are read from WordPress's own
 *   cached update data (hal_mcp_environment_plugin_update_map()).
 * - The result is a summary stored per site (get_option()/update_option()
 *   are per-site on multisite; the current blog's ID is recorded with it).
 *   Nothing scans all sites, nothing calls switch_to_blog().
 * - Every gap carries its reason in place (notes/statuses), and no section
 *   failure blocks the independent ones. Nothing here raises an
 *   admin_notice.
 *
 * Known, documented bound: an in-place PLUGIN update that changes no
 * activation state does not invalidate the cache by itself — reading every
 * plugin header would make the fingerprint as expensive as the inventory it
 * guards. Theme updates DO invalidate: the theme's Version header is one of
 * the fingerprint components. hal_mcp_environment_invalidate() (admin
 * re-discover) and force_refresh cover the plugin-update case explicitly.
 *
 * @package hal-mcp-abilities
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The per-site option that holds the cached inventory summary (roadmap §4.4:
 * "تحفظ نتيجة موجزة لكل موقع على حدة"). The slug is part of the uninstall
 * contract — uninstall.php deletes it by name when an admin opted in to data
 * deletion.
 *
 * @var string
 */
const HAL_MCP_ENVIRONMENT_INVENTORY_OPTION = 'hal_mcp_environment_inventory';

/**
 * Version of the stored inventory shape. Bumping it invalidates every stored
 * copy on the next read, so a shape change never serves stale structures.
 *
 * @var string
 */
const HAL_MCP_ENVIRONMENT_SCHEMA_VERSION = '1.0';

/**
 * The fixed operation areas the model-facing status report covers (§4.4:
 * capabilities are shown at operation level). Each entry gets exactly one
 * status: available, partial, needs_setup, or unverified — with a reason
 * whenever it is not plainly available.
 *
 * @var string[]
 */
const HAL_MCP_ENVIRONMENT_OPERATION_AREAS = [
	'posts',
	'pages',
	'products',
	'media',
	'custom_content',
	'seo',
	'translations',
	'editors',
	'external_mcp_channel',
];

/**
 * Per-request memo of the inventory envelope, so one request that touches
 * the inventory through several paths (ability + statuses + summary) pays
 * for at most one capture or one option read.
 *
 * @return array|null ['freshness' => string, 'data' => array]|null
 */
function &hal_mcp_environment_memo(): ?array {
	static $memo = null;
	return $memo;
}

/**
 * Whether the wp-admin plugin API (get_plugins/get_mu_plugins) is loadable,
 * loading it once if needed. Guarded with is_readable() rather than a bare
 * require so an incomplete deploy degrades one section instead of fataling.
 *
 * @return bool
 */
function hal_mcp_environment_plugin_admin_available(): bool {

	if ( ! function_exists( 'get_plugins' ) || ! function_exists( 'get_mu_plugins' ) ) {
		$hal_mcp_admin_file = ABSPATH . 'wp-admin/includes/plugin.php';

		if ( is_readable( $hal_mcp_admin_file ) ) {
			require_once $hal_mcp_admin_file;
		}
	}

	return function_exists( 'get_plugins' ) && function_exists( 'get_mu_plugins' );
}

/**
 * The cheap, always-current components the fingerprint is built from
 * (§4.4: invalidation on plugin/theme/language change). Deliberately light:
 * option reads, one theme header read, one small mu-plugin directory scan —
 * never a full plugin-header sweep.
 *
 * @return array<string, mixed>
 */
function hal_mcp_environment_fingerprint_components(): array {

	$hal_mcp_active = array_values( array_unique( (array) get_option( 'active_plugins', [] ) ) );
	sort( $hal_mcp_active );

	$hal_mcp_mu = [];
	$hal_mcp_network = [];

	if ( hal_mcp_environment_plugin_admin_available() ) {
		$hal_mcp_mu = array_keys( get_mu_plugins() );
		sort( $hal_mcp_mu );
	}

	if ( is_multisite() && function_exists( 'get_site_option' ) ) {
		$hal_mcp_network = array_keys( (array) get_site_option( 'active_sitewide_plugins', [] ) );
		sort( $hal_mcp_network );
	}

	$hal_mcp_theme = wp_get_theme();

	return [
		'schema'         => HAL_MCP_ENVIRONMENT_SCHEMA_VERSION,
		'plugin_version' => defined( 'HAL_MCP_ABILITIES_VERSION' ) ? HAL_MCP_ABILITIES_VERSION : '',
		'wordpress'      => (string) get_bloginfo( 'version' ),
		'php'            => PHP_VERSION,
		'multisite'      => (bool) is_multisite(),
		'blog'           => get_current_blog_id(),
		'theme'          => [ (string) $hal_mcp_theme->get_stylesheet(), (string) $hal_mcp_theme->get( 'Version' ) ],
		'locale'         => (string) get_locale(),
		'active_plugins' => $hal_mcp_active,
		'mu_plugins'     => $hal_mcp_mu,
		'network_plugins' => $hal_mcp_network,
	];
}

/**
 * The environment fingerprint (F14): a stable identifier of the environment
 * snapshot. Equal fingerprints mean the cached summary still describes the
 * site; a changed fingerprint is the invalidation signal.
 *
 * @return string sha256 hex.
 */
function hal_mcp_environment_fingerprint(): string {
	return hash( 'sha256', (string) wp_json_encode( hal_mcp_environment_fingerprint_components() ) );
}

/**
 * The inventory entry point (F14): lazily capture, cache per site, and
 * serve — with the fingerprint check making stale caches self-invalidate.
 *
 * A call before `init` has fired (a caller hooked too early) gets a live
 * result that is NOT cached, because post types are not fully registered
 * yet; capturing and caching that snapshot would poison later reads.
 *
 * @param bool $force_refresh True to bypass the cache and re-capture now
 *                            (never a network request).
 * @return array{freshness: string, data: array<string, mixed>}
 *         freshness: 'cached' | 'recomputed' | 'uncached_live'.
 */
function hal_mcp_environment_inventory( bool $force_refresh = false ): array {

	$hal_mcp_memo      = &hal_mcp_environment_memo();
	$hal_mcp_init_done = function_exists( 'did_action' ) ? did_action( 'init' ) > 0 : true;

	// A pre-init memo (a caller hooked too early) must never satisfy a
	// post-init read in the same request: its post-type section is
	// incomplete by definition, so only a post-init memo may be served.
	if ( null !== $hal_mcp_memo && ! $force_refresh && $hal_mcp_init_done ) {
		return $hal_mcp_memo;
	}

	$hal_mcp_fingerprint = hal_mcp_environment_fingerprint();
	$hal_mcp_stored      = get_option( HAL_MCP_ENVIRONMENT_INVENTORY_OPTION, [] );

	if (
		! $force_refresh
		&& $hal_mcp_init_done
		&& is_array( $hal_mcp_stored )
		&& ! empty( $hal_mcp_stored['data'] )
		&& is_array( $hal_mcp_stored['data'] )
		&& hash_equals( (string) ( $hal_mcp_stored['fingerprint'] ?? '' ), $hal_mcp_fingerprint )
	) {
		$hal_mcp_memo = [
			'freshness' => 'cached',
			'data'      => $hal_mcp_stored['data'],
		];

		return $hal_mcp_memo;
	}

	$hal_mcp_data = hal_mcp_environment_collect_inventory();

	if ( $hal_mcp_init_done ) {
		// autoload=false: the inventory is read on demand (ability calls,
		// admin screen), never on every page load — §4.4's "no heavy check
		// per request" applies to the option load too.
		update_option(
			HAL_MCP_ENVIRONMENT_INVENTORY_OPTION,
			[
				'schema'      => HAL_MCP_ENVIRONMENT_SCHEMA_VERSION,
				'fingerprint' => $hal_mcp_fingerprint,
				'blog_id'     => get_current_blog_id(),
				'data'        => $hal_mcp_data,
			],
			false
		);
	}

	$hal_mcp_memo = [
		'freshness' => $hal_mcp_init_done ? 'recomputed' : 'uncached_live',
		'data'      => $hal_mcp_data,
	];

	return $hal_mcp_memo;
}

/**
 * Explicit cache invalidation: the "re-discover" action (§4.4 / F14). The
 * next read re-captures from live site state. Also clears the per-request
 * memo so a caller that just changed the environment sees fresh data.
 *
 * @return bool True when the stored option existed and was deleted.
 */
function hal_mcp_environment_invalidate(): bool {

	$hal_mcp_memo    = &hal_mcp_environment_memo();
	$hal_mcp_memo    = null;

	return delete_option( HAL_MCP_ENVIRONMENT_INVENTORY_OPTION );
}

/**
 * Clears the per-request memo WITHOUT touching the stored option: the next
 * read goes through the fingerprint check again. This is what an admin flow
 * uses after changing settings in the same request; the local tests also use
 * it to simulate separate requests.
 *
 * @return void
 */
function hal_mcp_environment_reset_memo(): void {
	$hal_mcp_memo = &hal_mcp_environment_memo();
	$hal_mcp_memo = null;
}

/**
 * Collects the full inventory summary from live site state (F14 §4.4 list).
 * Pure reads: no network request, no content writes, no option writes (the
 * caller owns the cache write). Every section degrades independently — a
 * missing piece records its reason in 'notes' instead of failing the rest.
 *
 * @return array<string, mixed>
 */
function hal_mcp_environment_collect_inventory(): array {

	$inventory = [
		'schema'        => HAL_MCP_ENVIRONMENT_SCHEMA_VERSION,
		'blog_id'       => get_current_blog_id(),
		'captured_at'   => current_time( 'mysql' ),
		'wordpress'     => [
			'version'   => (string) get_bloginfo( 'version' ),
			'multisite' => (bool) is_multisite(),
		],
		'php'           => [ 'version' => PHP_VERSION ],
		'theme'         => [ 'slug' => '', 'name' => '', 'version' => '' ],
		'plugins'       => [
			'available'      => false,
			'regular'        => [],
			'must_use'       => [],
			'network_active' => [],
		],
		'content_types' => [],
		'taxonomies'    => [],
		'woocommerce'   => [ 'active' => false, 'product_types' => [] ],
		'language'      => [
			'locale' => (string) get_locale(),
			'is_rtl' => (bool) is_rtl(),
		],
		'integrations'  => [],
		'capabilities'  => [],
		'notes'         => [],
	];

	$hal_mcp_theme = wp_get_theme();

	if ( $hal_mcp_theme && method_exists( $hal_mcp_theme, 'get' ) ) {
		$inventory['theme'] = [
			'slug'    => (string) $hal_mcp_theme->get_stylesheet(),
			'name'    => (string) $hal_mcp_theme->get( 'Name' ),
			'version' => (string) $hal_mcp_theme->get( 'Version' ),
		];
	}

	/*
	 * Plugins (F14: identifier, version, state; MU/network plugins distinct).
	 * Statuses come from WordPress's own activation records — never from
	 * guessing by plugin name.
	 */
	if ( hal_mcp_environment_plugin_admin_available() ) {

		$hal_mcp_installed = get_plugins();
		$hal_mcp_active    = (array) get_option( 'active_plugins', [] );
		$hal_mcp_network   = [];

		if ( is_multisite() && function_exists( 'get_site_option' ) ) {
			$hal_mcp_network = array_keys( (array) get_site_option( 'active_sitewide_plugins', [] ) );
		}

		$inventory['plugins']['available']      = true;
		$inventory['plugins']['network_active'] = array_values( $hal_mcp_network );

		if ( is_array( $hal_mcp_installed ) ) {
			foreach ( $hal_mcp_installed as $hal_mcp_file => $hal_mcp_data ) {
				if ( in_array( $hal_mcp_file, $hal_mcp_network, true ) ) {
					$hal_mcp_status = 'network_active';
				} elseif ( in_array( $hal_mcp_file, $hal_mcp_active, true ) ) {
					$hal_mcp_status = 'active';
				} else {
					$hal_mcp_status = 'inactive';
				}

				$inventory['plugins']['regular'][] = [
					'name'              => (string) ( $hal_mcp_data['Name'] ?? '' ),
					'plugin'            => (string) $hal_mcp_file,
					'installed_version' => (string) ( $hal_mcp_data['Version'] ?? '' ),
					'status'            => $hal_mcp_status,
				];
			}
		} else {
			$inventory['notes'][] = 'the installed plugins list could not be read';
		}

		foreach ( get_mu_plugins() as $hal_mcp_file => $hal_mcp_data ) {
			$inventory['plugins']['must_use'][] = [
				'name'              => (string) ( $hal_mcp_data['Name'] ?? '' ),
				'plugin'            => (string) $hal_mcp_file,
				'installed_version' => (string) ( $hal_mcp_data['Version'] ?? '' ),
				'status'            => 'must_use',
			];
		}
	} else {
		$inventory['notes'][] = 'plugin list unavailable: wp-admin/includes/plugin.php is not readable';
	}

	/*
	 * Public content types, taxonomies, and the capability mappings the
	 * permission policy resolves through (§4.4: "الصلاحيات ذات الصلة").
	 * Internal plugin post types never appear here even if some day made
	 * public, and types without a registered capability mapping simply
	 * carry no entry in 'capabilities' — the policy already refuses them.
	 */
	$hal_mcp_post_types = function_exists( 'get_post_types' ) ? get_post_types( [ 'public' => true ], 'objects' ) : [];

	foreach ( $hal_mcp_post_types as $hal_mcp_type ) {
		$hal_mcp_name = (string) ( $hal_mcp_type->name ?? '' );

		if ( '' === $hal_mcp_name || hal_mcp_is_internal_post_type( $hal_mcp_name ) ) {
			continue;
		}

		$inventory['content_types'][] = [
			'name'         => $hal_mcp_name,
			'label'        => (string) ( $hal_mcp_type->labels->name ?? $hal_mcp_name ),
			'hierarchical' => (bool) ( $hal_mcp_type->hierarchical ?? false ),
			'show_in_rest' => (bool) ( $hal_mcp_type->show_in_rest ?? false ),
			'supports'     => function_exists( 'get_all_post_type_supports' )
				? array_keys( (array) get_all_post_type_supports( $hal_mcp_name ) )
				: [],
		];

		$hal_mcp_object = function_exists( 'get_post_type_object' ) ? get_post_type_object( $hal_mcp_name ) : null;

		if ( $hal_mcp_object && ! empty( $hal_mcp_object->cap ) ) {
			$inventory['capabilities'][ $hal_mcp_name ] = [
				'create_posts'  => (string) ( $hal_mcp_object->cap->create_posts ?? '' ),
				'edit_post'     => (string) ( $hal_mcp_object->cap->edit_post ?? '' ),
				'read_post'     => (string) ( $hal_mcp_object->cap->read_post ?? '' ),
				'publish_posts' => (string) ( $hal_mcp_object->cap->publish_posts ?? '' ),
			];
		}
	}

	$hal_mcp_taxonomies = function_exists( 'get_taxonomies' ) ? get_taxonomies( [ 'public' => true ], 'objects' ) : [];

	foreach ( $hal_mcp_taxonomies as $hal_mcp_taxonomy ) {
		$inventory['taxonomies'][] = [
			'name'         => (string) ( $hal_mcp_taxonomy->name ?? '' ),
			'label'        => (string) ( $hal_mcp_taxonomy->labels->name ?? '' ),
			'hierarchical' => (bool) ( $hal_mcp_taxonomy->hierarchical ?? false ),
			'show_in_rest' => (bool) ( $hal_mcp_taxonomy->show_in_rest ?? false ),
			'object_types' => array_values( (array) ( $hal_mcp_taxonomy->object_type ?? [] ) ),
		];
	}

	/*
	 * WooCommerce through its own runtime marker (§4.4/F07: plugin presence
	 * is never equated with API availability) — the function the product
	 * abilities themselves gate on.
	 */
	if ( function_exists( 'wc_get_product_types' ) ) {
		$inventory['woocommerce'] = [
			'active'        => true,
			'product_types' => (array) wc_get_product_types(),
		];
	}

	/*
	 * Integrations from the registry (F14: languages/editors/SEO are
	 * discovered through integrations.php, not guessed here). With no
	 * handler files shipped yet this list holds the core-detected entries
	 * (block editor, MCP channel) — real coverage, honestly labeled.
	 */
	$hal_mcp_integrations = [];

	foreach ( hal_mcp_integrations() as $hal_mcp_id => $hal_mcp_definition ) {
		$hal_mcp_integrations[] = [
			'id'         => $hal_mcp_id,
			'label'      => (string) ( $hal_mcp_definition['label'] ?? $hal_mcp_id ),
			'version'    => (string) ( $hal_mcp_definition['version'] ?? '' ),
			'source'     => (string) ( $hal_mcp_definition['source'] ?? '' ),
			'available'  => ! empty( $hal_mcp_definition['available'] ),
			'operations' => array_keys( (array) ( $hal_mcp_definition['operations'] ?? [] ) ),
		];
	}

	$inventory['integrations'] = $hal_mcp_integrations;

	return $inventory;
}

/**
 * The safe, model-facing slice of the inventory (F07: "تقديم ملخص قدرات آمن
 * للنموذج"). Stable identifiers and states only — PHP version, capability
 * maps, notes, and integration internals stay in the stored inventory for
 * the admin screen (F19) and are never part of an ability output.
 *
 * @param array<string, mixed>|null $inventory_data A collect_inventory() result,
 *                                                   or null to fetch the current one.
 * @return array<string, mixed>
 */
function hal_mcp_environment_model_summary( ?array $inventory_data = null ): array {

	if ( null === $inventory_data ) {
		$inventory_data = hal_mcp_environment_inventory()['data'];
	}

	$hal_mcp_plugins = [];

	foreach ( [ 'regular', 'must_use' ] as $hal_mcp_group ) {
		foreach ( (array) ( $inventory_data['plugins'][ $hal_mcp_group ] ?? [] ) as $hal_mcp_entry ) {
			$hal_mcp_plugins[] = [
				'name'              => (string) ( $hal_mcp_entry['name'] ?? '' ),
				'plugin'            => (string) ( $hal_mcp_entry['plugin'] ?? '' ),
				'installed_version' => (string) ( $hal_mcp_entry['installed_version'] ?? '' ),
				'status'            => (string) ( $hal_mcp_entry['status'] ?? '' ),
			];
		}
	}

	return [
		'wordpress_core_version' => (string) ( $inventory_data['wordpress']['version'] ?? '' ),
		'is_multisite'           => (bool) ( $inventory_data['wordpress']['multisite'] ?? false ),
		'active_theme'           => [
			'name'    => (string) ( $inventory_data['theme']['name'] ?? '' ),
			'version' => (string) ( $inventory_data['theme']['version'] ?? '' ),
			'slug'    => (string) ( $inventory_data['theme']['slug'] ?? '' ),
		],
		'plugins'                => $hal_mcp_plugins,
		'public_post_types'      => array_values( array_filter( array_column( (array) ( $inventory_data['content_types'] ?? [] ), 'name' ) ) ),
		'public_taxonomies'      => array_values( array_filter( array_column( (array) ( $inventory_data['taxonomies'] ?? [] ), 'name' ) ) ),
	];
}

/**
 * Per-area operation support statuses (§4.4: available / partial /
 * needs_setup / unverified — each with a reason when not plainly
 * available), derived from runtime markers and the integration registry —
 * never from plugin names alone (F07: knowing a plugin exists is not
 * knowing its APIs are callable or permitted).
 *
 * The statuses describe environment support, not per-call authorization:
 * every actual call is still permission-checked per object (F05).
 *
 * @param array<string, mixed>|null $inventory_data A collect_inventory() result,
 *                                                   or null to fetch the current one.
 * @return array<int, array{area: string, status: string, reason: string}>
 */
function hal_mcp_environment_operation_statuses( ?array $inventory_data = null ): array {

	if ( null === $inventory_data ) {
		$inventory_data = hal_mcp_environment_inventory()['data'];
	}

	$hal_mcp_abilities_api = function_exists( 'wp_register_ability' );
	$hal_mcp_api_reason    = 'the WordPress Abilities API is not available (WordPress 6.9 or later is required)';
	$hal_mcp_statuses      = [];

	// Posts and media: shipped since v1, gated only by the Abilities API.
	$hal_mcp_has_post_tools  = $hal_mcp_abilities_api
		&& function_exists( 'hal_mcp_register_read_post_abilities' )
		&& function_exists( 'hal_mcp_register_write_post_abilities' );
	$hal_mcp_has_media_tools = $hal_mcp_abilities_api
		&& function_exists( 'hal_mcp_register_read_media_abilities' )
		&& function_exists( 'hal_mcp_register_write_media_abilities' );

	$hal_mcp_statuses[] = [
		'area'   => 'posts',
		'status' => $hal_mcp_has_post_tools ? 'available' : 'needs_setup',
		'reason' => $hal_mcp_has_post_tools ? '' : 'post ability modules are not loaded (Abilities API missing?)',
	];

	// Pages (F12/F20): same runtime-marker pattern as posts — the modules
	// being loaded is what the status reports, never a plugin name alone.
	$hal_mcp_has_page_tools = $hal_mcp_abilities_api
		&& function_exists( 'hal_mcp_register_read_page_abilities' )
		&& function_exists( 'hal_mcp_register_write_page_abilities' );

	$hal_mcp_statuses[] = [
		'area'   => 'pages',
		'status' => $hal_mcp_has_page_tools ? 'available' : 'needs_setup',
		'reason' => $hal_mcp_has_page_tools ? '' : 'page ability modules are not loaded (Abilities API missing?)',
	];

	// Products: WooCommerce through its runtime marker, then our modules.
	if ( ! function_exists( 'wc_get_product_types' ) ) {
		$hal_mcp_statuses[] = [
			'area'   => 'products',
			'status' => 'needs_setup',
			'reason' => 'WooCommerce is not active on this site',
		];
	} elseif ( $hal_mcp_abilities_api
		&& function_exists( 'hal_mcp_register_read_product_abilities' )
		&& function_exists( 'hal_mcp_register_write_product_abilities' ) ) {
		$hal_mcp_statuses[] = [
			'area'   => 'products',
			'status' => 'available',
			'reason' => '',
		];
	} else {
		$hal_mcp_statuses[] = [
			'area'   => 'products',
			'status' => 'needs_setup',
			'reason' => 'product ability modules are not loaded (Abilities API missing?)',
		];
	}

	$hal_mcp_statuses[] = [
		'area'   => 'media',
		'status' => $hal_mcp_has_media_tools ? 'available' : 'needs_setup',
		'reason' => $hal_mcp_has_media_tools ? '' : 'media ability modules are not loaded (Abilities API missing?)',
	];

	// Generic custom content (F20): the module being loaded is the base; the
	// abilities are only useful when at least one public custom post type is
	// authorized, so a loaded module over zero authorized types is honestly
	// 'partial', not 'available'.
	$hal_mcp_has_content_module = $hal_mcp_abilities_api
		&& function_exists( 'hal_mcp_register_content_abilities' );

	if ( ! $hal_mcp_has_content_module ) {
		$hal_mcp_statuses[] = [
			'area'   => 'custom_content',
			'status' => 'needs_setup',
			'reason' => 'generic content ability modules are not loaded (Abilities API missing?)',
		];
	} elseif ( function_exists( 'hal_mcp_content_authorized_post_types' ) && ! empty( hal_mcp_content_authorized_post_types() ) ) {
		$hal_mcp_statuses[] = [
			'area'   => 'custom_content',
			'status' => 'available',
			'reason' => '',
		];
	} else {
		$hal_mcp_statuses[] = [
			'area'   => 'custom_content',
			'status' => 'partial',
			'reason' => 'the generic content module is loaded, but no public custom post type is authorized (see the hal_mcp_content_authorized_post_types filter)',
		];
	}

	/*
	 * Integration areas, from the registry snapshot in the inventory
	 * (languages/editors/SEO discovery is routed through integrations.php).
	 * The MCP channel is re-checked live — it is the one component whose
	 * availability is worth verifying at read time, exactly the way the
	 * Adapter's own documentation prescribes (class_exists).
	 */
	$hal_mcp_by_id = [];
	foreach ( (array) ( $inventory_data['integrations'] ?? [] ) as $hal_mcp_integration ) {
		$hal_mcp_by_id[ (string) ( $hal_mcp_integration['id'] ?? '' ) ] = $hal_mcp_integration;
	}

	$hal_mcp_statuses[] = hal_mcp_environment_integration_status( 'seo', $hal_mcp_by_id, 'no SEO integration handler is present in this version' );

	$hal_mcp_statuses[] = hal_mcp_environment_integration_status( 'translations', $hal_mcp_by_id, 'no translation integration handler is present in this version' );

	$hal_mcp_editors = [];
	foreach ( [ 'blocks', 'elementor' ] as $hal_mcp_editor_id ) {
		if ( isset( $hal_mcp_by_id[ $hal_mcp_editor_id ] ) ) {
			$hal_mcp_editors[] = $hal_mcp_by_id[ $hal_mcp_editor_id ];
		}
	}

	if ( empty( $hal_mcp_editors ) ) {
		$hal_mcp_editor_reason = isset( $hal_mcp_by_id['gutenberg'] )
			? 'the block editor is present (core), but page-design integration handlers are not part of this version'
			: 'no editor integration is present in this version';

		$hal_mcp_statuses[] = [
			'area'   => 'editors',
			'status' => 'needs_setup',
			'reason' => $hal_mcp_editor_reason,
		];
	} else {
		$hal_mcp_ready = array_values( array_filter( $hal_mcp_editors, static fn( $hal_mcp_entry ) => ! empty( $hal_mcp_entry['available'] ) ) );

		$hal_mcp_statuses[] = [
			'area'   => 'editors',
			'status' => empty( $hal_mcp_ready ) ? 'needs_setup' : 'available',
			'reason' => empty( $hal_mcp_ready )
				? 'editor integration handlers are registered but their components are not available'
				: 'editor integration: ' . implode( ', ', array_column( $hal_mcp_ready, 'id' ) ),
		];
	}

	$hal_mcp_statuses[] = [
		'area'   => 'external_mcp_channel',
		'status' => class_exists( 'WP\MCP\Core\McpAdapter' ) ? 'available' : 'needs_setup',
		'reason' => class_exists( 'WP\MCP\Core\McpAdapter' )
			? 'the official WordPress MCP Adapter is active'
			: 'the official WordPress MCP Adapter plugin is not active on this site (optional: the internal screen works without it)',
	];

	return $hal_mcp_statuses;
}

/**
 * Builds one integration-area status entry from the inventory's integration
 * snapshot, with the area's own not-registered reason as the fallback.
 *
 * @param string                       $id               Integration id to look up.
 * @param array<string, array>         $integrations_by_id Inventory integrations keyed by id.
 * @param string                       $missing_reason   Reason when not registered at all.
 * @return array{area: string, status: string, reason: string}
 */
function hal_mcp_environment_integration_status( string $id, array $integrations_by_id, string $missing_reason ): array {

	if ( ! isset( $integrations_by_id[ $id ] ) ) {
		return [
			'area'   => $id,
			'status' => 'needs_setup',
			'reason' => $missing_reason,
		];
	}

	$hal_mcp_entry = $integrations_by_id[ $id ];

	if ( ! empty( $hal_mcp_entry['available'] ) ) {
		return [
			'area'   => $id,
			'status' => 'available',
			'reason' => 'integration "' . $id . '" is registered and its component is present',
		];
	}

	return [
		'area'   => $id,
		'status' => 'needs_setup',
		'reason' => 'integration "' . $id . '" is registered but its component is not available',
	];
}

/**
 * The live plugin-update map for the ability output (F07): read from
 * WordPress's own cached update data — the same source every admin screen
 * reads. No network request is made here, ever; refreshing that cache is
 * WordPress's own cron/admin job, not this plugin's.
 *
 * @return array<string, array{update_available: bool, latest_version: string}>
 *         Keyed by plugin basename.
 */
function hal_mcp_environment_plugin_update_map(): array {

	$hal_mcp_map = [];

	if ( ! function_exists( 'get_site_transient' ) ) {
		return $hal_mcp_map;
	}

	$hal_mcp_updates = get_site_transient( 'update_plugins' );

	if ( ! is_object( $hal_mcp_updates ) || ! isset( $hal_mcp_updates->response ) || ! is_array( $hal_mcp_updates->response ) ) {
		return $hal_mcp_map;
	}

	foreach ( $hal_mcp_updates->response as $hal_mcp_file => $hal_mcp_response ) {
		$hal_mcp_latest = '';

		if ( is_object( $hal_mcp_response ) && isset( $hal_mcp_response->new_version ) ) {
			$hal_mcp_latest = (string) $hal_mcp_response->new_version;
		} elseif ( is_array( $hal_mcp_response ) && isset( $hal_mcp_response['new_version'] ) ) {
			$hal_mcp_latest = (string) $hal_mcp_response['new_version'];
		}

		if ( '' !== $hal_mcp_latest ) {
			$hal_mcp_map[ (string) $hal_mcp_file ] = [
				'update_available' => true,
				'latest_version'   => $hal_mcp_latest,
			];
		}
	}

	return $hal_mcp_map;
}

// ---------------------------------------------------------------------------
// Language support surface (F08/F09/F10/F20 reads and writes)
// ---------------------------------------------------------------------------

if ( ! function_exists( 'hal_mcp_supported_languages' ) ) {
	/**
	 * The languages the environment actually offers (F08: «اختيار اللغة
	 * والتصفية وفق البيئة»). Without a translations integration (F23), the
	 * only language a site truly offers is its own configured locale —
	 * inventing a language list would fake a capability the site does not
	 * have. F23's handler widens this list through the filter below once it
	 * ships; a filter that empties the list is honoured, and the ability
	 * layer refuses language filtering entirely when the list is empty.
	 *
	 * Computed live (cheap: one filter + one get_locale()), never cached in
	 * the stored inventory, so it cannot go stale the way a snapshot would.
	 *
	 * @return string[]
	 */
	function hal_mcp_supported_languages(): array {

		$hal_mcp_languages = [ (string) get_locale() ];

		/**
		 * Filters the languages the site offers for filtering and assignment.
		 *
		 * @param string[] $hal_mcp_languages Site locale by default; a
		 *                                      translations integration (F23)
		 *                                      adds its configured languages.
		 */
		$hal_mcp_languages = (array) apply_filters( 'hal_mcp_supported_languages', $hal_mcp_languages );

		return array_values( array_unique( array_filter( array_map( 'strval', $hal_mcp_languages ) ) ) );
	}

	/**
	 * Whether a specific language code is one the environment offers.
	 *
	 * @param string $language Language code/locale as supplied by the model.
	 * @return bool
	 */
	function hal_mcp_language_is_supported( string $language ): bool {

		$language = trim( $language );

		if ( '' === $language ) {
			return false;
		}

		// Case-insensitive on purpose: language codes arrive in many casings
		// from the model ("EN_US"), and the site's own locale is documented
		// to be an accepted no-op whatever casing it is written in.
		return in_array( strtolower( $language ), array_map( 'strtolower', hal_mcp_supported_languages() ), true );
	}
}

if ( ! function_exists( 'hal_mcp_validate_read_language' ) ) {
	/**
	 * Validates the shared optional `language` input for the read and write
	 * abilities (F08/F09/F10/F12/F20). Returns null when the input is absent
	 * or empty, and a WP_Error naming the site's REAL language surface when
	 * the requested language is not one the environment offers. There is no
	 * "success value" on purpose: without a translations integration (F23)
	 * no language column exists to filter on, so requesting the site's own
	 * locale is a documented no-op, and the caller proceeds with nothing to
	 * filter.
	 *
	 * Lives beside the language surface helpers because every domain file
	 * (read and write side) validates through this one definition.
	 *
	 * @param array<string, mixed> $input Ability input.
	 * @return WP_Error|null WP_Error when the language is not supported.
	 */
	function hal_mcp_validate_read_language( array $input ): ?WP_Error {

		if ( ! array_key_exists( 'language', $input ) ) {
			return null;
		}

		$language = trim( (string) $input['language'] );

		if ( '' === $language ) {
			return null;
		}

		if ( ! hal_mcp_language_is_supported( $language ) ) {
			return new WP_Error(
				'hal_mcp_language_unsupported',
				sprintf(
					/* translators: 1: requested language code. 2: comma-separated list of languages this site offers. */
					__( 'The language "%1$s" is not available on this site. Available languages: %2$s.', 'hal-mcp' ),
					$language,
					implode( ', ', hal_mcp_supported_languages() )
				)
			);
		}

		return null;
	}
}
