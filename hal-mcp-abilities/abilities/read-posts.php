<?php
/**
 * hal-mcp-abilities — hal/get-post, hal/search-posts.
 *
 * Both abilities are deliberately scoped to post_type = 'post' only. Without
 * this, get_post( $id ) / get_posts() would happily return pages, WooCommerce
 * products, or any other registered post type — silently bypassing the
 * category/capability split this plugin is built around (hal-content /
 * edit_posts vs hal-products / edit_products). Scoping to 'post' here keeps
 * that boundary real in code, not just in naming.
 *
 * F08 additions, deliberately narrow:
 * - pagination on hal/search-posts: a `page` input over the unchanged fixed
 *   limit of 10 results (the v1 default is preserved, not grown into a
 *   per-call page size the model could inflate).
 * - a `language` input, validated against the environment's ACTUAL language
 *   surface (includes/environment.php). Until a translations integration
 *   (F23) registers more, the only language a site offers is its own
 *   configured locale — so a request for any other language is refused with
 *   the real reason, and filtering by the site locale is the honest no-op it
 *   is (every post on a non-translated site is in the site language). Output
 *   rows report that same locale as `language` instead of pretending each
 *   post carries language data it does not have.
 * - include_drafts widens only the STATUS window; every returned row is
 *   still permission-checked per object (F05), so private posts and other
 *   users' content never leak through it.
 *
 * @package hal-mcp-abilities
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

add_action( 'wp_abilities_api_init', 'hal_mcp_register_read_post_abilities' );

/**
 * Registers the hal/get-post and hal/search-posts abilities.
 *
 * @return void
 */
function hal_mcp_register_read_post_abilities(): void {

	wp_register_ability(
		'hal/get-post',
		[
			'label'       => __( 'Get a single post', 'hal-mcp' ),
			'description' => __( 'Returns the full content and metadata of a single blog post by its numeric ID, including the language the site reports for it.', 'hal-mcp' ),
			'category'    => 'hal-content',

			'input_schema' => [
				'type'                 => 'object',
				'properties'           => [
					'post_id' => [
						'type'        => 'integer',
						'minimum'     => 1,
						'description' => __( 'The numeric ID of the post to retrieve.', 'hal-mcp' ),
					],
				],
				'required'             => [ 'post_id' ],
				'additionalProperties' => false,
			],

			'output_schema' => [
				'type'       => 'object',
				'properties' => [
					'id'        => [ 'type' => 'integer' ],
					'title'     => [ 'type' => 'string' ],
					'content'   => [ 'type' => 'string' ],
					'excerpt'   => [ 'type' => 'string' ],
					'status'    => [ 'type' => 'string' ],
					'type'      => [ 'type' => 'string' ],
					'language'  => [
						'type'        => 'string',
						'description' => __( 'The language this post is reported in. Until a translations integration is active, every post is reported in the site locale.', 'hal-mcp' ),
					],
					'date'      => [ 'type' => 'string' ],
					'modified'  => [ 'type' => 'string' ],
					'author_id' => [ 'type' => 'integer' ],
					'slug'      => [ 'type' => 'string' ],
					'parent_id' => [ 'type' => 'integer' ],
				],
			],

			'permission_callback' => static fn( $input = [] ) => hal_mcp_permission( 'post', 'read', (int) ( $input['post_id'] ?? 0 ), [ 'ability' => 'hal/get-post' ] ),

			'execute_callback' => function ( $input ) {

				// Re-validate defensively even though input_schema already enforces
				// type + minimum: this function must be safe to call from anywhere,
				// not only via the schema-validated Abilities API path.
				$post_id = absint( $input['post_id'] ?? 0 );

				if ( $post_id < 1 ) {
					return new WP_Error(
						'hal_mcp_invalid_post_id',
						__( 'post_id must be a positive integer.', 'hal-mcp' )
					);
				}

				// get_post() does not filter by post_status, so a post sitting in
				// the trash (post_status = 'trash') can be returned here the same
				// as any other status — treated as within this ability's scope of
				// "any post_type='post' item the caller can edit", not a bug.
				$post = get_post( $post_id );

				// Deliberately the same generic "not found" whether the ID does not
				// exist at all, or exists but is not post_type 'post' (a page, a
				// WooCommerce product, an order, etc.). Distinguishing the two would
				// leak the existence and type of content outside this ability's
				// scope to whoever is calling it.
				if ( ! $post instanceof WP_Post || 'post' !== $post->post_type ) {
					return new WP_Error(
						'hal_mcp_post_not_found',
						__( 'No blog post was found with that ID.', 'hal-mcp' )
					);
				}

				// Object-level read check (F05): read_post with the ID maps
				// through WordPress's own ownership/private-status rules, so
				// another user's private or draft post is not returned. Same
				// generic "not found" as above — the denial is logged, but
				// the item's existence is not disclosed.
				if ( ! hal_mcp_permission( 'post', 'read', $post_id, [ 'ability' => 'hal/get-post' ] ) ) {
					return new WP_Error(
						'hal_mcp_post_not_found',
						__( 'No blog post was found with that ID.', 'hal-mcp' )
					);
				}

				return [
					'id'        => (int) $post->ID,
					'title'     => (string) $post->post_title,
					'content'   => (string) $post->post_content,
					'excerpt'   => (string) $post->post_excerpt,
					'status'    => (string) $post->post_status,
					'type'      => (string) $post->post_type,
					'language'  => (string) get_locale(),
					'date'      => (string) $post->post_date,
					'modified'  => (string) $post->post_modified,
					'author_id' => (int) $post->post_author,
					'slug'      => (string) $post->post_name,
					'parent_id' => (int) $post->post_parent,
				];
			},

			'meta' => [ 'mcp' => [ 'public' => true ] ],
		]
	);

	wp_register_ability(
		'hal/search-posts',
		[
			'label'       => __( 'Search posts', 'hal-mcp' ),
			'description' => __( 'Searches blog posts by keyword and returns a short summary (not full content) of up to 10 matches per page, newest first. Use the page input for the next set of results (an empty result means there are no more). Use hal/get-post with the returned id to fetch full content.', 'hal-mcp' ),
			'category'    => 'hal-content',

			'input_schema' => [
				'type'                 => 'object',
				'properties'           => [
					'query'          => [
						'type'        => 'string',
						'minLength'   => 1,
						'description' => __( 'Keyword or phrase to search for in post titles and content.', 'hal-mcp' ),
					],
					'include_drafts' => [
						'type'        => 'boolean',
						'default'     => false,
						'description' => __( 'If true, also search draft, pending, and private posts in addition to published ones. Default false (published only). Widening the status window never widens who may see a specific post — every result is still permission-checked per object.', 'hal-mcp' ),
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
							'description' => __( 'The language this post is reported in. Until a translations integration is active, every post is reported in the site locale.', 'hal-mcp' ),
						],
						'date'     => [ 'type' => 'string' ],
						'excerpt'  => [ 'type' => 'string' ],
					],
				],
			],

			'permission_callback' => static fn() => hal_mcp_permission( 'post', 'read', 0, [ 'ability' => 'hal/search-posts' ] ),

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

				// Default: published only, matching get_posts()'s own core default.
				// include_drafts=true additionally searches draft/pending/private.
				// The coarse permission_callback gates who may call this
				// ability at all; per-result, every returned item is checked
				// individually below (F05) — include_drafts widens the status
				// window, never who is allowed to see a specific item.
				$include_drafts = ! empty( $input['include_drafts'] );
				$post_status    = $include_drafts
					? [ 'publish', 'draft', 'pending', 'private' ]
					: 'publish';

				// Bounded pagination (F08): the fixed v1 limit of 10 stays; only
				// the offset moves. The schema caps page at 100 so a hostile
				// caller cannot walk an unbounded offset, and the defensive
				// clamp below holds even when this function is called without
				// the schema in the way.
				$page   = min( 100, max( 1, (int) ( $input['page'] ?? 1 ) ) );
				$offset = ( $page - 1 ) * 10;

				$found_posts = get_posts(
					[
						'post_type'   => 'post',
						'post_status' => $post_status,
						's'           => $query,
						'numberposts' => 10,
						'offset'      => $offset,
						'orderby'     => 'date',
						'order'       => 'DESC',
					]
				);

				$results = [];

				foreach ( $found_posts as $found_post ) {
					// Per-result read check (F05), quiet so bulk filtering
					// does not spam the audit log: other users' drafts and
					// private posts are skipped without disclosure.
					if ( ! hal_mcp_permission( 'post', 'read', (int) $found_post->ID, [ 'ability' => 'hal/search-posts', 'quiet' => true ] ) ) {
						continue;
					}

					$results[] = [
						'id'       => (int) $found_post->ID,
						'title'    => (string) $found_post->post_title,
						'type'     => 'post',
						'status'   => (string) $found_post->post_status,
						'language' => (string) get_locale(),
						'date'     => (string) $found_post->post_date,
						'excerpt'  => (string) $found_post->post_excerpt,
					];
				}

				return $results;
			},

			'meta' => [ 'mcp' => [ 'public' => true ] ],
		]
	);
}
