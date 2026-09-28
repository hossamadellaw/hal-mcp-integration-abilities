<?php
/**
 * hal-mcp-abilities — hal/get-page, hal/search-pages (F12).
 *
 * Read-only abilities over post_type='page', deliberately parallel to
 * read-posts.php with the F12 page-specific contract:
 *
 * - Editor identity comes from the PAGE'S OWN stored data, never from a
 *   plugin name: a page whose _elementor_data decodes to a non-empty
 *   Elementor structure was built with Elementor and its JSON is the design
 *   source; everything else is block
 *   markup in post_content (Gutenberg/Spectra and any block-based builder
 *   share that storage). get-page returns the block MARKUP as the source
 *   for block pages — never the RENDERED HTML — and returns a bounded
 *   section OUTLINE for Elementor pages, never their full JSON blob.
 * - Editable elements and the SEO summary slot are reported honestly: the
 *   SEO summary says so itself when no SEO integration is active (F22
 *   ships those handlers); Elementor section WRITES need the editor bridge
 *   (F21) and are refused by write-pages.php, not faked here.
 * - Internal preview data and approval requests can never appear in the
 *   search: the query is post_type='page' only, and the plugin's request
 *   store is a separate non-public post type.
 * - Every row is permission-checked per page (F05), include_drafts widens
 *   only the status window, and language filtering validates against the
 *   site's real language surface (see read-posts.php for the full note).
 *
 * @package hal-mcp-abilities
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The maximum number of top-level Elementor sections reported in the
 * outline. Bounded so a very large design cannot flood the output.
 *
 * @var int
 */
const HAL_MCP_PAGE_OUTLINE_MAX_SECTIONS = 50;

/**
 * Detects a page's editor identity and read-safe design summary from the
 * page's own stored data (F12: «الإرجاع يميز Elementor JSON وكتل
 * Gutenberg/Spectra»).
 *
 * @param WP_Post $page The page post object.
 * @return array{
 *   editor: array{id: string, label: string, note: string},
 *   content_source: string,
 *   sections: array<int, array<string, mixed>>
 * } `sections` is non-empty only for Elementor pages (bounded outline).
 */
function hal_mcp_page_editor_identity( WP_Post $page ): array {

	$hal_mcp_elementor_data = (string) get_post_meta( $page->ID, '_elementor_data', true );

	$hal_mcp_decoded = '' !== trim( $hal_mcp_elementor_data )
		? json_decode( $hal_mcp_elementor_data, true )
		: null;

	// Only a REAL, non-empty Elementor structure counts as an Elementor
	// design: an empty ('[]') or corrupted meta falls through to the
	// block-editor branch, so a page whose Elementor data is missing or
	// broken keeps its block content readable and writable instead of being
	// silently hidden behind an outline-only "design".
	if ( is_array( $hal_mcp_decoded ) && ! empty( $hal_mcp_decoded ) ) {
		$hal_mcp_sections = [];

		foreach ( array_slice( $hal_mcp_decoded, 0, HAL_MCP_PAGE_OUTLINE_MAX_SECTIONS ) as $hal_mcp_section ) {
			if ( ! is_array( $hal_mcp_section ) ) {
				continue;
			}

			$hal_mcp_sections[] = [
				'id'   => sanitize_text_field( (string) ( $hal_mcp_section['id'] ?? '' ) ),
				'type' => sanitize_key( (string) ( $hal_mcp_section['elType'] ?? '' ) ),
				'widget_type' => sanitize_key( (string) ( $hal_mcp_section['widgetType'] ?? '' ) ),
			];
		}

		return [
			'editor' => [
				'id'    => 'elementor',
				'label' => __( 'Elementor', 'hal-mcp' ),
				'note'  => __( 'The page design is stored as Elementor JSON. This ability returns a section outline only; writing section design requires the editor integration (roadmap F21).', 'hal-mcp' ),
			],
			'content_source' => 'elementor_json',
			'sections'       => $hal_mcp_sections,
		];
	}

	return [
		'editor' => [
			'id'    => 'blocks',
			'label' => __( 'Block editor (Gutenberg; block-based builders store here too)', 'hal-mcp' ),
			'note'  => '',
		],
		'content_source' => 'block_markup',
		'sections'       => [],
	];
}

add_action( 'wp_abilities_api_init', 'hal_mcp_register_read_page_abilities' );

/**
 * Registers the hal/get-page and hal/search-pages abilities.
 *
 * @return void
 */
function hal_mcp_register_read_page_abilities(): void {

	wp_register_ability(
		'hal/get-page',
		[
			'label'       => __( 'Get a single page', 'hal-mcp' ),
			'description' => __( 'Returns a single page by its numeric ID: title, the DESIGN SOURCE (block markup for block-editor pages; a section outline for Elementor pages — never rendered HTML), editor identity, status, language, template, featured image, editable elements, and an SEO summary slot. Never returns another post type\'s content.', 'hal-mcp' ),
			'category'    => 'hal-pages',

			'input_schema' => [
				'type'                 => 'object',
				'properties'           => [
					'page_id' => [
						'type'        => 'integer',
						'minimum'     => 1,
						'description' => __( 'The numeric ID of the page to retrieve.', 'hal-mcp' ),
					],
				],
				'required'             => [ 'page_id' ],
				'additionalProperties' => false,
			],

			'output_schema' => [
				'type'       => 'object',
				'properties' => [
					'id'          => [ 'type' => 'integer' ],
					'title'       => [ 'type' => 'string' ],
					'content'     => [
						'type'        => 'string',
						'description' => __( 'For block-editor pages: the raw block markup (the actual design source). For Elementor pages: empty — the design is in the sections outline, and the rendered HTML is deliberately NOT returned as a source.', 'hal-mcp' ),
					],
					'content_source' => [
						'type'        => 'string',
						'enum'        => [ 'block_markup', 'elementor_json' ],
						'description' => __( 'Which storage the design source lives in for this page.', 'hal-mcp' ),
					],
					'editor'      => [
						'type'       => 'object',
						'properties' => [
							'id'    => [ 'type' => 'string' ],
							'label' => [ 'type' => 'string' ],
							'note'  => [ 'type' => 'string' ],
						],
					],
					'sections'    => [
						'type'        => 'array',
						'description' => __( 'Elementor pages only: bounded top-level section outline (ids/types), not the full design JSON.', 'hal-mcp' ),
						'items'       => [
							'type'       => 'object',
							'properties' => [
								'id'          => [ 'type' => 'string' ],
								'type'        => [ 'type' => 'string' ],
								'widget_type' => [ 'type' => 'string' ],
							],
						],
					],
					'status'      => [ 'type' => 'string' ],
					'language'    => [
						'type'        => 'string',
						'description' => __( 'The language this page is reported in. Until a translations integration is active, every page is reported in the site locale.', 'hal-mcp' ),
					],
					'template'    => [
						'type'        => 'string',
						'description' => __( 'The page template slug; empty string means the theme default.', 'hal-mcp' ),
					],
					'featured_image_id' => [ 'type' => 'integer' ],
					'editable_elements' => [
						'type'        => 'array',
						'description' => __( 'The elements write access can address for this page, each with its key and value kind. Elementor section writes are not offered until the editor integration ships.', 'hal-mcp' ),
						'items'       => [
							'type'       => 'object',
							'properties' => [
								'key'  => [ 'type' => 'string' ],
								'kind' => [ 'type' => 'string' ],
								'note' => [ 'type' => 'string' ],
							],
						],
					],
					'seo_summary' => [
						'type'        => 'object',
						'description' => __( 'The page\'s SEO metadata summary as reported by the active SEO integration; reports its own unavailability when none is active.', 'hal-mcp' ),
						'properties'  => [
							'available' => [ 'type' => 'boolean' ],
							'note'      => [ 'type' => 'string' ],
							'fields'    => [
								'type'  => 'object',
								'description' => __( 'SEO field values for this page, when an SEO integration supplies them.', 'hal-mcp' ),
								'additionalProperties' => [ 'type' => 'string' ],
							],
						],
					],
					'date'        => [ 'type' => 'string' ],
					'modified'    => [ 'type' => 'string' ],
					'slug'        => [ 'type' => 'string' ],
					'parent_id'   => [ 'type' => 'integer' ],
				],
			],

			'permission_callback' => static fn( $input = [] ) => hal_mcp_permission( 'page', 'read', (int) ( $input['page_id'] ?? 0 ), [ 'ability' => 'hal/get-page' ] ),

			'execute_callback' => function ( $input ) {

				$page_id = absint( $input['page_id'] ?? 0 );

				if ( $page_id < 1 ) {
					return new WP_Error(
						'hal_mcp_invalid_page_id',
						__( 'page_id must be a positive integer.', 'hal-mcp' )
					);
				}

				$page = get_post( $page_id );

				// Same generic "not found" convention as read-posts.php: the
				// ID's existence in another post type is never disclosed.
				if ( ! $page instanceof WP_Post || 'page' !== $page->post_type ) {
					return new WP_Error(
						'hal_mcp_page_not_found',
						__( 'No page was found with that ID.', 'hal-mcp' )
					);
				}

				// Object-level read check (F05): another user's private or
				// draft page is not returned; the denial is logged, the
				// page's existence is not disclosed.
				if ( ! hal_mcp_permission( 'page', 'read', $page_id, [ 'ability' => 'hal/get-page' ] ) ) {
					return new WP_Error(
						'hal_mcp_page_not_found',
						__( 'No page was found with that ID.', 'hal-mcp' )
					);
				}

				$hal_mcp_identity = hal_mcp_page_editor_identity( $page );

				$is_elementor = ( 'elementor_json' === $hal_mcp_identity['content_source'] );

				$hal_mcp_editable = [
					[
						'key'  => 'title',
						'kind' => 'text',
						'note' => '',
					],
					[
						'key'  => 'content',
						'kind' => $is_elementor ? 'elementor_json' : 'block_markup',
						'note' => $is_elementor
							? __( 'Design writes need the editor integration (F21); title/status-only updates go through the normal write path.', 'hal-mcp' )
							: '',
					],
					[
						'key'  => 'template',
						'kind' => 'choice',
						'note' => '',
					],
					[
						'key'  => 'featured_image_id',
						'kind' => 'media_id',
						'note' => '',
					],
					[
						'key'  => 'requested_status',
						'kind' => 'choice',
						'note' => __( 'Status changes go through the approval policy, never directly.', 'hal-mcp' ),
					],
				];

				// SEO summary slot (F12): filled by the active SEO integration's
				// handler (F22) — field values through the verified interface,
				// honestly empty (with the reason) when none is active.
				$hal_mcp_seo_available = hal_mcp_integration_available( 'seo' )
					&& function_exists( 'hal_mcp_seo_read_fields' );

				$hal_mcp_seo_fields = [];
				$hal_mcp_seo_note   = __( 'No SEO integration is active on this site; no SEO metadata is reported.', 'hal-mcp' );

				if ( $hal_mcp_seo_available ) {
					$hal_mcp_seo_read = hal_mcp_seo_read_fields( $page->ID );

					if ( is_wp_error( $hal_mcp_seo_read ) ) {
						$hal_mcp_seo_available = false;
						$hal_mcp_seo_note      = $hal_mcp_seo_read->get_error_message();
					} else {
						foreach ( $hal_mcp_seo_read as $hal_mcp_field => $hal_mcp_entry ) {
							$hal_mcp_seo_fields[ $hal_mcp_field ] = (string) $hal_mcp_entry['value'];
						}

						$hal_mcp_seo_note = __( 'SEO metadata is read through the active SEO integration\'s verified interface.', 'hal-mcp' );
					}
				}

				$hal_mcp_seo_summary = [
					'available' => $hal_mcp_seo_available,
					'note'      => $hal_mcp_seo_note,
					'fields'    => $hal_mcp_seo_fields,
				];

				return [
					'id'                => (int) $page->ID,
					'title'             => (string) $page->post_title,
					'content'           => $is_elementor ? '' : (string) $page->post_content,
					'content_source'    => $hal_mcp_identity['content_source'],
					'editor'            => $hal_mcp_identity['editor'],
					'sections'          => $hal_mcp_identity['sections'],
					'status'            => (string) $page->post_status,
					'language'          => (string) get_locale(),
					'template'          => (string) get_page_template_slug( $page->ID ),
					'featured_image_id' => (int) get_post_thumbnail_id( $page->ID ),
					'editable_elements' => $hal_mcp_editable,
					'seo_summary'       => $hal_mcp_seo_summary,
					'date'              => (string) $page->post_date,
					'modified'          => (string) $page->post_modified,
					'slug'              => (string) $page->post_name,
					'parent_id'         => (int) $page->post_parent,
				];
			},

			'meta' => [ 'mcp' => [ 'public' => true ] ],
		]
	);

	wp_register_ability(
		'hal/search-pages',
		[
			'label'       => __( 'Search pages', 'hal-mcp' ),
			'description' => __( 'Searches pages by keyword and returns a short summary (not full content) of up to 10 matches per page, newest first. Use the page input for the next set of results (an empty result means there are no more). Internal preview data and approval requests can never appear here.', 'hal-mcp' ),
			'category'    => 'hal-pages',

			'input_schema' => [
				'type'                 => 'object',
				'properties'           => [
					'query'          => [
						'type'        => 'string',
						'minLength'   => 1,
						'description' => __( 'Keyword or phrase to search for in page titles and content.', 'hal-mcp' ),
					],
					'include_drafts' => [
						'type'        => 'boolean',
						'default'     => false,
						'description' => __( 'If true, also search draft, pending, and private pages in addition to published ones. Default false (published only). Widening the status window never widens who may see a specific page.', 'hal-mcp' ),
					],
					'language'       => [
						'type'        => 'string',
						'description' => __( 'Optional language filter. Only languages the site actually offers are accepted; until a translations integration is active that is the site locale, and filtering by it changes nothing.', 'hal-mcp' ),
					],
					'page'           => [
						'type'        => 'integer',
						'minimum'     => 1,
						'maximum'     => 100,
						'default'     => 1,
						'description' => __( '1-based page number over the fixed limit of 10 results. Default 1.', 'hal-mcp' ),
					],
				],
				'required'             => [ 'query' ],
				'additionalProperties' => false,
			],

			'output_schema' => [
				'type'  => 'array',
				'items' => [
					'type'       => 'object',
					'properties' => [
						'id'       => [ 'type' => 'integer' ],
						'title'    => [ 'type' => 'string' ],
						'type'     => [ 'type' => 'string' ],
						'status'   => [ 'type' => 'string' ],
						'language' => [
							'type'        => 'string',
							'description' => __( 'The language this page is reported in. Until a translations integration is active, every page is reported in the site locale.', 'hal-mcp' ),
						],
						'date'     => [ 'type' => 'string' ],
						'excerpt'  => [ 'type' => 'string' ],
					],
				],
			],

			'permission_callback' => static fn() => hal_mcp_permission( 'page', 'read', 0, [ 'ability' => 'hal/search-pages' ] ),

			'execute_callback' => function ( $input ) {

				$query = isset( $input['query'] ) ? trim( (string) $input['query'] ) : '';

				if ( '' === $query ) {
					return new WP_Error(
						'hal_mcp_empty_query',
						__( 'query must not be empty.', 'hal-mcp' )
					);
				}

				$language_error = hal_mcp_validate_read_language( is_array( $input ) ? $input : [] );

				if ( $language_error instanceof WP_Error ) {
					return $language_error;
				}

				$include_drafts = ! empty( $input['include_drafts'] );
				$post_status    = $include_drafts
					? [ 'publish', 'draft', 'pending', 'private' ]
					: 'publish';

				// Bounded pagination over the fixed limit of 10, exactly as in
				// hal/search-posts.
				$page   = min( 100, max( 1, (int) ( $input['page'] ?? 1 ) ) );
				$offset = ( $page - 1 ) * 10;

				// post_type='page' only: the plugin's request store and any
				// internal preview data live outside this post type, so they
				// cannot enter the results even in principle.
				$found_pages = get_posts(
					[
						'post_type'   => 'page',
						'post_status' => $post_status,
						's'           => $query,
						'numberposts' => 10,
						'offset'      => $offset,
						'orderby'     => 'date',
						'order'       => 'DESC',
					]
				);

				$results = [];

				foreach ( $found_pages as $found_page ) {
					// Per-result read check (F05), quiet: other users' drafts
					// and private pages are skipped without disclosure.
					if ( ! hal_mcp_permission( 'page', 'read', (int) $found_page->ID, [ 'ability' => 'hal/search-pages', 'quiet' => true ] ) ) {
						continue;
					}

					$results[] = [
						'id'       => (int) $found_page->ID,
						'title'    => (string) $found_page->post_title,
						'type'     => 'page',
						'status'   => (string) $found_page->post_status,
						'language' => (string) get_locale(),
						'date'     => (string) $found_page->post_date,
						'excerpt'  => (string) $found_page->post_excerpt,
					];
				}

				return $results;
			},

			'meta' => [ 'mcp' => [ 'public' => true ] ],
		]
	);
}
