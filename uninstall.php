<?php
/**
 * hal-mcp-integration-abilities — uninstall handler.
 *
 * Data policy (default: keep everything):
 *   - The audit log table, its schema-version option, the plugin settings,
 *     the cached environment inventory, and the internal change-request
 *     storage are PRESERVED when the plugin is deleted, so an accidental or
 *     temporary uninstall never destroys the site's operational history.
 *   - Deleting them happens only when an administrator explicitly opted in
 *     beforehand via the "delete data on uninstall" choice in the plugin
 *     settings (the hal_mcp_settings option). No such choice, no deletion.
 *   - Content the plugin created through its abilities — posts, products,
 *     media, translations, and any draft proposals kept as normal content —
 *     is NEVER deleted here, in either mode. Only the plugin's own options,
 *     its own audit log table, and its own internal request CPT are in scope.
 *   - Multisite: only the current site's data is removed. There is no
 *     network-wide bulk deletion; repeat the opt-in deletion per site.
 *
 * @package hal-mcp-abilities
 */

// Abort if WordPress did not initiate a real uninstall for this plugin.
if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

/*
 * The settings option is read directly here (the plugin itself is not loaded
 * during uninstall). The F19 settings screen stores this choice as a strict
 * boolean through its sanitize callback; the strict identity check here
 * matches that contract — only an explicit opt-in (true) triggers deletion,
 * and any other value keeps everything.
 */
$hal_mcp_settings = get_option( 'hal_mcp_settings' );

if ( ! is_array( $hal_mcp_settings ) || true !== ( $hal_mcp_settings['delete_data_on_uninstall'] ?? null ) ) {
	return;
}

global $wpdb;

/*
 * Options owned by this plugin, by explicit name — not a prefix wildcard, so a
 * coincidental key from another plugin can never be caught. Any option this
 * plugin adds in a future version must be registered in this list.
 */
foreach ( [ 'hal_mcp_settings', 'hal_mcp_secrets', 'hal_mcp_connection_tests', 'hal_mcp_audit_log_db_version', 'hal_mcp_environment_inventory' ] as $hal_mcp_option ) {
	delete_option( $hal_mcp_option );
}

// The audit log table, on the current site only.
$hal_mcp_audit_table = $wpdb->prefix . 'hal_mcp_audit_log';
$wpdb->query( "DROP TABLE IF EXISTS {$hal_mcp_audit_table}" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name is built from $wpdb->prefix, not user input.

/*
 * The internal change-request CPT (change requests, approval state, field
 * snapshots). Selected by its reserved post type name and deleted through
 * wp_delete_post() so postmeta and term relationships go with it. Content
 * types owned by the site (post, product, attachment, translations) are never
 * touched, whatever created them.
 */
$hal_mcp_request_ids = $wpdb->get_col(
	$wpdb->prepare(
		"SELECT ID FROM {$wpdb->posts} WHERE post_type = %s",
		'hal_mcp_request'
	)
);

foreach ( $hal_mcp_request_ids as $hal_mcp_request_id ) {
	wp_delete_post( (int) $hal_mcp_request_id, true );
}
