<?php
/**
 * hal-mcp-abilities — hal/list-media.
 *
 * Read-only ability listing WordPress media library attachments. Uses
 * get_posts() with post_type=attachment (WordPress core's own get_posts()
 * already defaults post_status to 'inherit' for this post type — set
 * explicitly here anyway for clarity to a future reader).
 *
 * @package hal-mcp-abilities
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

add_action( 'wp_abilities_api_init', 'hal_mcp_register_read_media_abilities' );

/**
 * Registers the hal/list-media ability.
 *
 * @return void
 */
function hal_mcp_register_read_media_abilities(): void {

	wp_register_ability(
		'hal/list-media',
		[
			'label'       => __( 'List media library items', 'hal-mcp' ),
			'description' => __( 'Lists up to 10 media library attachments, most recent first, optionally filtered by keyword (title/filename) and/or MIME type. Returns design-relevant metadata (alt text, pixel dimensions for images) and the language the site reports for the item; never server file paths.', 'hal-mcp' ),
			'category'    => 'hal-media',

			'input_schema' => [
				'type'                 => 'object',
				'properties'           => [
					'search'    => [
						'type'        => 'string',
						'description' => __( 'Optional keyword to filter media by title or filename.', 'hal-mcp' ),
					],
					'mime_type' => [
						'type'        => 'string',
						'description' => __( 'Optional MIME type filter — e.g. "image" for all images, or "application/pdf" for a specific type.', 'hal-mcp' ),
					],
				],
				'additionalProperties' => false,
			],

			'output_schema' => [
				'type'  => 'array',
				'items' => [
					'type'       => 'object',
					'properties' => [
						'id'        => [ 'type' => 'integer' ],
						'title'     => [ 'type' => 'string' ],
						'filename'  => [ 'type' => 'string' ],
						'mime_type' => [ 'type' => 'string' ],
						'url'       => [ 'type' => 'string' ],
						'date'      => [ 'type' => 'string' ],
						'language'  => [
							'type'        => 'string',
							'description' => __( 'The language this item is reported in. Until a translations integration is active, every item is reported in the site locale.', 'hal-mcp' ),
						],
						'width'     => [
							'type'        => 'integer',
							'description' => __( 'Pixel width for images, 0 otherwise.', 'hal-mcp' ),
						],
						'height'    => [
							'type'        => 'integer',
							'description' => __( 'Pixel height for images, 0 otherwise.', 'hal-mcp' ),
						],
						'parent_id' => [
							'type'        => 'integer',
							'description' => __( 'The attached post/product/page ID when one is set AND readable by the caller; 0 when unattached or when the parent exists but the caller may not read it (its relation is never disclosed through this list).', 'hal-mcp' ),
						],
						'alt_text'  => [
							'type'        => 'string',
							'description' => __( 'Empty string for non-image attachments or images without alt text set.', 'hal-mcp' ),
						],
					],
				],
			],

			'permission_callback' => static fn() => hal_mcp_permission( 'media', 'list', 0, [ 'ability' => 'hal/list-media' ] ),

			'execute_callback' => function ( $input ) {

				$search    = isset( $input['search'] ) ? trim( (string) $input['search'] ) : '';
				$mime_type = isset( $input['mime_type'] ) ? trim( (string) $input['mime_type'] ) : '';

				$query_args = [
					'post_type'   => 'attachment',
					'post_status' => 'inherit',
					'numberposts' => 10,
					'orderby'     => 'date',
					'order'       => 'DESC',
				];

				if ( '' !== $search ) {
					$query_args['s'] = $search;
				}

				if ( '' !== $mime_type ) {
					$query_args['post_mime_type'] = $mime_type;
				}

				$attachments = get_posts( $query_args );

				$results = [];

				foreach ( $attachments as $attachment ) {
					// Per-result read check (F05), quiet: attachments the
					// current user may not read are skipped without disclosure.
					if ( ! hal_mcp_permission( 'media', 'read', (int) $attachment->ID, [ 'ability' => 'hal/list-media', 'quiet' => true ] ) ) {
						continue;
					}

					$file_path = get_attached_file( $attachment->ID );

					// Parent relation (F09): the parent is reported only when
					// it exists AND the caller may read it under the site's own
					// policy. An unreadable parent collapses to 0 — same as
					// unattached — so the list never discloses a relation the
					// caller could not have followed anyway.
					$parent_id = (int) $attachment->post_parent;

					if ( $parent_id > 0 && ! hal_mcp_permission( 'post', 'read', $parent_id, [ 'ability' => 'hal/list-media', 'quiet' => true, 'reason' => 'parent_read_check' ] )
						&& ! hal_mcp_permission( 'product', 'read', $parent_id, [ 'ability' => 'hal/list-media', 'quiet' => true, 'reason' => 'parent_read_check' ] ) ) {
						$parent_id = 0;
					}

					// Pixel dimensions (F09: design-relevant metadata) come
					// from WordPress's own attachment metadata, so non-images
					// naturally report 0 without a separate is-image branch.
					$hal_mcp_metadata = wp_get_attachment_metadata( (int) $attachment->ID );

					$results[] = [
						'id'        => (int) $attachment->ID,
						'title'     => (string) $attachment->post_title,
						// wp_basename(), not PHP's basename(): WordPress core
						// recommends it because plain basename() can mangle
						// multibyte filenames depending on the server locale.
						'filename'  => $file_path ? wp_basename( $file_path ) : '',
						'mime_type' => (string) $attachment->post_mime_type,
						'url'       => (string) wp_get_attachment_url( $attachment->ID ),
						'date'      => (string) $attachment->post_date,
						'language'  => (string) get_locale(),
						'width'     => (int) ( $hal_mcp_metadata['width'] ?? 0 ),
						'height'    => (int) ( $hal_mcp_metadata['height'] ?? 0 ),
						'parent_id' => $parent_id,
						'alt_text'  => (string) get_post_meta( $attachment->ID, '_wp_attachment_image_alt', true ),
					];
				}

				return $results;
			},

			'meta' => [ 'mcp' => [ 'public' => true ] ],
		]
	);
}
