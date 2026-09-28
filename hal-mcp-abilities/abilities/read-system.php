<?php
/**
 * hal-mcp-abilities — hal/get-site-inventory.
 *
 * v2 (F07 rework): reads the environment module's cached inventory (F14)
 * instead of scanning plugins on every call, and returns only the safe,
 * model-facing summary: stable identifiers (plugin basenames, theme slug),
 * per-plugin state (installed / active / network-active / must-use),
 * public content types and taxonomies, per-area operation support
 * statuses, and the update flags from WordPress's own cached update data.
 *
 * Two deliberate separations (roadmap F07):
 * - force_refresh recomputes the LOCAL environment inventory now. It never
 *   performs a network request — the v1 wordpress.org update check
 *   (wp_update_plugins) is gone from this ability entirely; refreshing
 *   that cache is WordPress's own cron/admin job, and the flags below are
 *   read from the cached transient like every other admin screen reads
 *   them.
 * - Administrative detail (PHP version, capability maps, absolute paths,
 *   inventory notes) stays inside the stored inventory for the admin
 *   screen (F19). This ability's output is the model-safe summary only.
 *
 * The statuses describe environment support, not per-call authorization:
 * every call is still permission-checked per object (F05), and knowing a
 * plugin's name never grants running its APIs (§4.4) — products, for
 * example, only report 'available' when WooCommerce's own runtime marker
 * (wc_get_product_types) is present, not merely because the plugin is
 * active.
 *
 * @package hal-mcp-abilities
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

add_action( 'wp_abilities_api_init', 'hal_mcp_register_read_system_abilities' );

/**
 * Registers the hal/get-site-inventory ability.
 *
 * @return void
 */
function hal_mcp_register_read_system_abilities(): void {

	wp_register_ability(
		'hal/get-site-inventory',
		[
			'label'       => __( 'Get site environment and plugin inventory', 'hal-mcp' ),
			'description' => __(
				"Returns the site's environment summary: WordPress core version, multisite state, the active theme, and every installed plugin with its stable identifier, installed version, state (active / network-active / inactive / must-use), and whether WordPress's own cached update data shows an update. Also reports the public content types and taxonomies and per-area operation support statuses (available, partial, needs_setup, unverified) with reasons. The statuses describe what the site supports — every actual call is still permission-checked per object. force_refresh recomputes the local environment inventory; it never makes a network request.",
				'hal-mcp'
			),
			'category'    => 'hal-system',

			'input_schema' => [
				'type'                 => 'object',
				'properties'           => [
					'force_refresh' => [
						'type'        => 'boolean',
						'description' => __( 'If true, recompute the local environment inventory now (no network request). Default false: serve the cached summary while its fingerprint still matches the live environment.', 'hal-mcp' ),
						'default'     => false,
					],
				],
				'additionalProperties' => false,
			],

			'output_schema' => [
				'type'       => 'object',
				'properties' => [
					'wordpress_core_version' => [
						'type'        => 'string',
						'description' => __( 'The installed WordPress core version, e.g. 7.0.2.', 'hal-mcp' ),
					],
					'is_multisite'           => [
						'type'        => 'boolean',
						'description' => __( 'Whether the site runs as a multisite network. The inventory always describes the current site only.', 'hal-mcp' ),
					],
					'active_theme'           => [
						'type'       => 'object',
						'properties' => [
							'name'    => [ 'type' => 'string' ],
							'version' => [ 'type' => 'string' ],
							'slug'    => [
								'type'        => 'string',
								'description' => __( 'The theme stylesheet — the stable theme identifier.', 'hal-mcp' ),
							],
						],
					],
					'plugins'                => [
						'type'  => 'array',
						'items' => [
							'type'       => 'object',
							'properties' => [
								'name'              => [ 'type' => 'string' ],
								'plugin'            => [
									'type'        => 'string',
									'description' => __( 'The plugin basename, e.g. woocommerce/woocommerce.php — the stable plugin identifier relative to the plugins directory.', 'hal-mcp' ),
								],
								'installed_version' => [ 'type' => 'string' ],
								'status'            => [
									'type'        => 'string',
									'enum'        => [ 'active', 'network_active', 'inactive', 'must_use' ],
									'description' => __( 'Installed plugins that are not active are listed as inactive — installed never means usable.', 'hal-mcp' ),
								],
								'update_available'  => [
									'type'        => 'boolean',
									'description' => __( 'From WordPress\'s own cached update data; never triggers a network check.', 'hal-mcp' ),
								],
								'latest_version'    => [
									'type'        => 'string',
									'description' => __( 'Empty string when update_available is false.', 'hal-mcp' ),
								],
							],
						],
					],
					'public_post_types'      => [
						'type'  => 'array',
						'items' => [ 'type' => 'string' ],
					],
					'public_taxonomies'      => [
						'type'  => 'array',
						'items' => [ 'type' => 'string' ],
					],
					'operation_status'       => [
						'type'  => 'array',
						'items' => [
							'type'       => 'object',
							'properties' => [
								'area'   => [
									'type' => 'string',
									'enum' => [ 'posts', 'pages', 'products', 'media', 'custom_content', 'seo', 'translations', 'editors', 'external_mcp_channel' ],
								],
								'status' => [
									'type'        => 'string',
									'enum'        => [ 'available', 'partial', 'needs_setup', 'unverified' ],
									'description' => __( 'Environment support at operation level (§4.4). Statuses are derived from runtime markers and the integration registry — never from plugin names alone.', 'hal-mcp' ),
								],
								'reason' => [
									'type'        => 'string',
									'description' => __( 'Why the status is not "available"; empty when it is.', 'hal-mcp' ),
								],
							],
						],
					],
					'data_freshness'         => [
						'type'        => 'string',
						'enum'        => [ 'cached', 'recomputed' ],
						'description' => __( '"recomputed" when the environment inventory was (re)built during this call; "cached" when a fingerprint-matching summary was served.', 'hal-mcp' ),
					],
				],
			],

			'permission_callback' => static fn() => hal_mcp_permission( 'system', 'read', 0, [ 'ability' => 'hal/get-site-inventory' ] ),

			'execute_callback' => function ( $input ) {

				// Explicit bool cast: input_schema validates this as boolean
				// before execute_callback runs, but we don't rely solely on
				// that upstream guarantee here.
				$force_refresh = ! empty( $input['force_refresh'] );

				/*
				 * F07 separation: this is the ONLY environment path the
				 * ability takes, and it never touches the network. The v1
				 * wp_update_plugins() call is intentionally gone — the
				 * update flags below come from WordPress's own cached
				 * transient, exactly like every admin screen reads them.
				 */
				$hal_mcp_envelope = hal_mcp_environment_inventory( $force_refresh );

				$hal_mcp_summary = hal_mcp_environment_model_summary( $hal_mcp_envelope['data'] );
				$hal_mcp_summary['operation_status'] = hal_mcp_environment_operation_statuses( $hal_mcp_envelope['data'] );

				$hal_mcp_updates = hal_mcp_environment_plugin_update_map();

				foreach ( $hal_mcp_summary['plugins'] as $hal_mcp_index => $hal_mcp_plugin ) {
					$hal_mcp_update = $hal_mcp_updates[ $hal_mcp_plugin['plugin'] ] ?? null;

					$hal_mcp_summary['plugins'][ $hal_mcp_index ]['update_available'] = is_array( $hal_mcp_update );
					$hal_mcp_summary['plugins'][ $hal_mcp_index ]['latest_version']   = is_array( $hal_mcp_update )
						? (string) ( $hal_mcp_update['latest_version'] ?? '' )
						: '';
				}

				$hal_mcp_summary['data_freshness'] = 'cached' === $hal_mcp_envelope['freshness'] ? 'cached' : 'recomputed';

				return $hal_mcp_summary;
			},

			'meta' => [ 'mcp' => [ 'public' => true ] ],
		]
	);
}
