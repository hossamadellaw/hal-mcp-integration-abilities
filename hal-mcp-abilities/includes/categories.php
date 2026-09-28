<?php
/**
 * hal-mcp-abilities — ability category registration.
 *
 * WordPress core only ships two built-in ability categories ("site" and "user").
 * None of our abilities fit either, so we register five of our own, one per
 * functional domain, matching the abilities/*.php file split in this plugin.
 * Registering a single catch-all category instead would work mechanically, but
 * would defeat the entire purpose of categories (discovery/filtering via
 * `wp ability list --category=` and REST `/wp-abilities/v1/categories`).
 *
 * This file must be required before any file in abilities/ — see
 * hal-mcp-abilities.php, which requires this file first for that reason.
 *
 * @package hal-mcp-abilities
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

add_action( 'wp_abilities_api_categories_init', 'hal_mcp_register_ability_categories' );

/**
 * Registers all hal-mcp-abilities ability categories.
 *
 * Called only from the wp_abilities_api_categories_init action (enforced by
 * WordPress core itself: wp_register_ability_category() triggers a
 * _doing_it_wrong() notice and returns null if called from anywhere else).
 *
 * @return void
 */
function hal_mcp_register_ability_categories(): void {

	$categories = [
		'hal-content'  => [
			'label'       => __( 'HAL: Content', 'hal-mcp' ),
			'description' => __( 'Abilities for reading and drafting blog posts, authorized custom content types, and following change-request states.', 'hal-mcp' ),
		],
		'hal-pages'    => [
			'label'       => __( 'HAL: Pages', 'hal-mcp' ),
			'description' => __( 'Abilities for reading and drafting WordPress pages with their editor identity.', 'hal-mcp' ),
		],
		'hal-products' => [
			'label'       => __( 'HAL: Products', 'hal-mcp' ),
			'description' => __( 'Abilities for reading and drafting WooCommerce products.', 'hal-mcp' ),
		],
		'hal-media'    => [
			'label'       => __( 'HAL: Media', 'hal-mcp' ),
			'description' => __( 'Abilities for listing and uploading media library items.', 'hal-mcp' ),
		],
		'hal-system'   => [
			'label'       => __( 'HAL: System', 'hal-mcp' ),
			'description' => __( 'Read-only abilities reporting installed WordPress core, theme, and plugin versions.', 'hal-mcp' ),
		],
	];

	$failed_categories = [];

	foreach ( $categories as $slug => $args ) {
		$registered = wp_register_ability_category( $slug, $args );

		// Fail loudly: a category that silently fails to register means every
		// ability that references it will also silently fail to register later.
		// WordPress 6.9 documents null as the only failure return, but a future
		// core contract change to WP_Error must fail loudly too, never pass.
		if ( null === $registered || is_wp_error( $registered ) ) {
			error_log(
				sprintf(
					'hal-mcp-abilities: failed to register ability category "%s". Abilities referencing this category will not register.',
					$slug
				)
			);
			$failed_categories[] = $slug;
		}
	}

	// error_log() alone isn't visible to anyone working in wp-admin day to day,
	// so it doesn't actually deliver on the "fail loudly" intent above — surface
	// the same failure there too, once per request and aggregated into a single
	// notice (not one per category), matching the admin_notices fallback pattern
	// already used in hal-mcp-abilities.php for the missing-Abilities-API case.
	if ( ! empty( $failed_categories ) ) {
		add_action(
			'admin_notices',
			static function () use ( $failed_categories ) {
				if ( ! current_user_can( 'activate_plugins' ) ) {
					return;
				}
				echo '<div class="notice notice-error"><p>' .
					esc_html(
						sprintf(
							/* translators: %s: comma-separated list of ability category slugs that failed to register. */
							__( 'hal-mcp-abilities: failed to register ability categories: %s. Abilities referencing these categories will not register.', 'hal-mcp' ),
							implode( ', ', $failed_categories )
						)
					) .
					'</p></div>';
			}
		);
	}
}
