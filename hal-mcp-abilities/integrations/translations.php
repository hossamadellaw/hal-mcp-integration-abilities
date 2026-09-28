<?php
/**
 * hal-mcp-abilities — translations integration: WPML and Polylang (F23).
 *
 * The roadmap's execution limit for this version, implemented here: two
 * REAL branches that detect the language system, list the configured
 * languages, read translation relations, assign a language to an element,
 * and link a translation into an existing group while preserving the rest
 * of the group's links and refusing conflicting sets.
 *
 * - The WPML branch uses the official language/group interface: the
 *   documented wpml_set_element_language_details hook for assignment and
 *   linking, and the SitePress element methods (guarded per method) for
 *   reading the group. Version source: ICL_SITEPRESS_VERSION.
 * - The Polylang branch uses the documented Polylang function reference:
 *   pll_languages_list(), pll_get_post_language(),
 *   pll_set_post_language(), pll_get_post_translations(), and
 *   pll_save_post_translations(). Version source: POLYLANG_VERSION.
 *
 * Every interface call is guarded (defined/function_exists/method_exists)
 * and the version source is recorded with the handler; a missing method is
 * an honest refusal, never a guessed table or a silent partial write.
 *
 * Policy (roadmap F23/§4.4):
 * - The language surface of the site widens through the SAME filter the
 *   read/write abilities already validate against
 *   (hal_mcp_supported_languages) — the model-facing abilities accept the
 *   configured languages without any change to their code.
 * - Linking a translation into a group whose source is published affects
 *   hreflang/output and therefore goes through the approval flow (F17)
 *   like any other protected change; draft-to-draft linking on an
 *   authorized draft is direct work.
 * - With NO translation system: no relation is ever invented. The
 *   integration registers as unavailable, the language surface stays the
 *   site locale, and content can still be written in any language the
 *   policy accepts — with the relation limits stated honestly.
 * - No bulk translation of "the whole site" exists: these operations run
 *   on explicitly named, authorized objects only.
 *
 * @package hal-mcp-abilities
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// ---------------------------------------------------------------------------
// Detection (runtime markers only)
// ---------------------------------------------------------------------------

/**
 * Whether the WPML branch's verified interface is available right now.
 *
 * @return bool
 */
function hal_mcp_translations_wpml_present(): bool {
	if ( ! defined( 'ICL_SITEPRESS_VERSION' ) || ! class_exists( 'SitePress' ) ) {
		return false;
	}

	$hal_mcp_sitepress = $GLOBALS['sitepress'] ?? null;

	return $hal_mcp_sitepress instanceof SitePress
		&& method_exists( $hal_mcp_sitepress, 'get_element_trid' )
		&& method_exists( $hal_mcp_sitepress, 'get_element_translations' );
}

/**
 * Whether the Polylang branch's verified interface is available right now.
 *
 * @return bool
 */
function hal_mcp_translations_polylang_present(): bool {
	return defined( 'POLYLANG_VERSION' )
		&& function_exists( 'pll_languages_list' )
		&& function_exists( 'pll_get_post_language' )
		&& function_exists( 'pll_set_post_language' )
		&& function_exists( 'pll_get_post_translations' )
		&& function_exists( 'pll_save_post_translations' );
}

/**
 * The active translation system (WPML wins when both are present — one
 * authority per site, always).
 *
 * @return string '' | 'wpml' | 'polylang'.
 */
function hal_mcp_translations_active_system(): string {
	if ( hal_mcp_translations_wpml_present() ) {
		return 'wpml';
	}

	if ( hal_mcp_translations_polylang_present() ) {
		return 'polylang';
	}

	return '';
}

/**
 * The version string of the active system (the version source recorded with
 * every handler).
 *
 * @return string
 */
function hal_mcp_translations_active_version(): string {
	if ( hal_mcp_translations_wpml_present() ) {
		return (string) ICL_SITEPRESS_VERSION;
	}

	if ( hal_mcp_translations_polylang_present() ) {
		return (string) POLYLANG_VERSION;
	}

	return '';
}

/**
 * The languages the active system has configured. Empty when no system is
 * available — never a guessed list.
 *
 * @return string[]
 */
function hal_mcp_translations_configured_languages(): array {

	$hal_mcp_system = hal_mcp_translations_active_system();

	if ( 'wpml' === $hal_mcp_system ) {
		$hal_mcp_all = apply_filters( 'wpml_active_languages', null, [] );

		if ( ! is_array( $hal_mcp_all ) ) {
			return [];
		}

		return array_values( array_filter( array_map( 'strval', array_keys( $hal_mcp_all ) ) ) );
	}

	if ( 'polylang' === $hal_mcp_system ) {
		$hal_mcp_all = pll_languages_list();

		if ( ! is_array( $hal_mcp_all ) ) {
			return [];
		}

		return array_values( array_filter( array_map( 'strval', $hal_mcp_all ) ) );
	}

	return [];
}

// ---------------------------------------------------------------------------
// Shared operations (resolve-language / read-links / assign / link)
// ---------------------------------------------------------------------------

/**
 * Resolves a requested language against the site's CONFIGURED language list
 * (F23: resolve-language). Case-insensitive matching returns the canonical
 * configured code; anything else resolves to '' — an unconfigured language
 * is never accepted by assignment.
 *
 * @param string $language Requested language code.
 * @return string The canonical code, or '' when unresolvable.
 */
function hal_mcp_translations_resolve_language( string $language ): string {

	$language = trim( $language );

	if ( '' === $language ) {
		return '';
	}

	foreach ( hal_mcp_translations_configured_languages() as $hal_mcp_configured ) {
		if ( 0 === strcasecmp( $hal_mcp_configured, $language ) ) {
			return $hal_mcp_configured;
		}
	}

	return '';
}

/**
 * Reads the translation group of one object (F23: read-links): a map of
 * language code => object ID, plus the object's own language. The group's
 * non-targeted links are the authority any later link must preserve.
 *
 * @param int    $post_id   Object ID.
 * @param string $post_type The object type slug (the post type the element
 *                          type is derived from, e.g. 'post_post').
 * @return array{
 *   system: string,
 *   element_language: string,
 *   links: array<string, int>
 * }|WP_Error
 */
function hal_mcp_translations_read_links( int $post_id, string $post_type ) {

	$hal_mcp_system = hal_mcp_translations_active_system();

	if ( '' === $hal_mcp_system ) {
		return new WP_Error(
			'hal_mcp_translations_unavailable',
			__( 'No translation system is active on this site; translation relations cannot be read or created.', 'hal-mcp' )
		);
	}

	if ( $post_id < 1 ) {
		return new WP_Error( 'hal_mcp_invalid_post_id', __( 'The content ID must be a positive integer.', 'hal-mcp' ) );
	}

	if ( 'wpml' === $hal_mcp_system ) {
		$hal_mcp_sitepress  = $GLOBALS['sitepress'];
		$hal_mcp_element_tp = 'post_' . $post_type;

		$hal_mcp_trid = $hal_mcp_sitepress->get_element_trid( $post_id, $hal_mcp_element_tp );

		if ( ! $hal_mcp_trid ) {
			// No group yet: a standalone element with no links.
			return [
				'system'          => 'wpml',
				'element_language' => '',
				'links'           => [],
			];
		}

		$hal_mcp_translations = $hal_mcp_sitepress->get_element_translations( $hal_mcp_trid, $hal_mcp_element_tp );

		if ( ! is_array( $hal_mcp_translations ) ) {
			return new WP_Error(
				'hal_mcp_translations_read_failed',
				__( 'The WPML translation group could not be read.', 'hal-mcp' )
			);
		}

		$hal_mcp_links    = [];
		$hal_mcp_own_lang = '';

		foreach ( $hal_mcp_translations as $hal_mcp_code => $hal_mcp_entry ) {
			$hal_mcp_code = (string) $hal_mcp_code;
			$hal_mcp_id   = is_object( $hal_mcp_entry ) ? (int) ( $hal_mcp_entry->element_id ?? 0 ) : (int) ( $hal_mcp_entry['element_id'] ?? 0 );

			if ( '' === $hal_mcp_code || $hal_mcp_id < 1 ) {
				continue;
			}

			$hal_mcp_links[ $hal_mcp_code ] = $hal_mcp_id;

			if ( $hal_mcp_id === $post_id ) {
				$hal_mcp_own_lang = $hal_mcp_code;
			}
		}

		return [
			'system'          => 'wpml',
			'element_language' => $hal_mcp_own_lang,
			'links'           => $hal_mcp_links,
		];
	}

	// Polylang branch.
	$hal_mcp_links = pll_get_post_translations( $post_id );

	if ( ! is_array( $hal_mcp_links ) ) {
		return new WP_Error(
			'hal_mcp_translations_read_failed',
			__( 'The Polylang translation group could not be read.', 'hal-mcp' )
		);
	}

	$hal_mcp_clean = [];

	foreach ( $hal_mcp_links as $hal_mcp_code => $hal_mcp_link_id ) {
		$hal_mcp_code = (string) $hal_mcp_code;

		if ( '' === $hal_mcp_code || (int) $hal_mcp_link_id < 1 ) {
			continue;
		}

		$hal_mcp_clean[ $hal_mcp_code ] = (int) $hal_mcp_link_id;
	}

	$hal_mcp_own = function_exists( 'pll_get_post_language' ) ? (string) pll_get_post_language( $post_id ) : '';

	return [
		'system'          => 'polylang',
		'element_language' => $hal_mcp_own,
		'links'           => $hal_mcp_clean,
	];
}

/**
 * Assigns a language to an element that has no language yet (F23:
 * assign-language). The language must be one the active system has
 * configured; an element already holding a DIFFERENT language is refused —
 * re-assignment is a policy decision, not a side effect.
 *
 * @param int    $post_id   Object ID.
 * @param string $post_type The object's post type.
 * @param string $language  Requested language code.
 * @return array{assigned: bool, system: string, language: string}|WP_Error
 */
function hal_mcp_translations_assign_language( int $post_id, string $post_type, string $language ) {

	$hal_mcp_system = hal_mcp_translations_active_system();

	if ( '' === $hal_mcp_system ) {
		return new WP_Error(
			'hal_mcp_translations_unavailable',
			__( 'No translation system is active on this site; translation relations cannot be read or created.', 'hal-mcp' )
		);
	}

	$hal_mcp_language = hal_mcp_translations_resolve_language( $language );

	if ( '' === $hal_mcp_language ) {
		return new WP_Error(
			'hal_mcp_language_unconfigured',
			sprintf(
				/* translators: %s: requested language code. */
				__( 'The language "%s" is not configured in the site\'s translation system.', 'hal-mcp' ),
				$language
			)
		);
	}

	if ( 'wpml' === $hal_mcp_system ) {
		$hal_mcp_existing = hal_mcp_translations_read_links( $post_id, $post_type );

		if ( is_wp_error( $hal_mcp_existing ) ) {
			return $hal_mcp_existing;
		}

		if ( '' !== $hal_mcp_existing['element_language'] && $hal_mcp_existing['element_language'] !== $hal_mcp_language ) {
			return new WP_Error(
				'hal_mcp_language_already_assigned',
				sprintf(
					/* translators: 1: current language. 2: requested language. */
					__( 'This element is already assigned to "%1$s"; assigning it to "%2$s" needs an explicit decision, not a silent re-assignment.', 'hal-mcp' ),
					$hal_mcp_existing['element_language'],
					$hal_mcp_language
				)
			);
		}

		// Official group interface (S21): no trid = the element becomes the
		// source of its own new group.
		apply_filters(
			'wpml_set_element_language_details',
			null,
			[
				'element_id'          => $post_id,
				'element_type'        => 'post_' . $post_type,
				'trid'                => null,
				'language_code'       => $hal_mcp_language,
				'source_language_code' => null,
			]
		);

		// Prove the assignment by reading it back through the same
		// interface (the documented filter's success carries no return
		// value to trust on its own).
		$hal_mcp_after = hal_mcp_translations_read_links( $post_id, $post_type );

		if ( is_wp_error( $hal_mcp_after ) || $hal_mcp_after['element_language'] !== $hal_mcp_language ) {
			return new WP_Error(
				'hal_mcp_language_assign_unverified',
				__( 'The language assignment could not be verified afterwards; re-read the element before relying on it.', 'hal-mcp' )
			);
		}

		return [
			'assigned' => true,
			'system'   => 'wpml',
			'language' => $hal_mcp_language,
		];
	}

	// Polylang branch.
	$hal_mcp_existing_lang = function_exists( 'pll_get_post_language' ) ? (string) pll_get_post_language( $post_id ) : '';

	if ( '' !== $hal_mcp_existing_lang && strcasecmp( $hal_mcp_existing_lang, $hal_mcp_language ) !== 0 ) {
		return new WP_Error(
			'hal_mcp_language_already_assigned',
			sprintf(
				/* translators: 1: current language. 2: requested language. */
				__( 'This element is already assigned to "%1$s"; assigning it to "%2$s" needs an explicit decision, not a silent re-assignment.', 'hal-mcp' ),
				$hal_mcp_existing_lang,
				$hal_mcp_language
			)
		);
	}

	pll_set_post_language( $post_id, $hal_mcp_language );

	// Prove the assignment by reading it back through the same interface.
	if ( (string) pll_get_post_language( $post_id ) !== $hal_mcp_language ) {
		return new WP_Error(
			'hal_mcp_language_assign_unverified',
			__( 'The language assignment could not be verified afterwards; re-read the element before relying on it.', 'hal-mcp' )
		);
	}

	return [
		'assigned' => true,
		'system'   => 'polylang',
		'language' => $hal_mcp_language,
	];
}

/**
 * Links a translation into an existing group (F23: link-translations) while
 * PRESERVING the group's other links (the group is read live, the new link
 * merged in, and the whole set saved) and REFUSING conflicting sets: if the
 * target language already holds a DIFFERENT object in the group, the link
 * is refused — groups never grow two objects for one language.
 *
 * @param int    $source_id The group's existing object.
 * @param int    $target_id The new translation object.
 * @param string $post_type The objects' post type.
 * @param string $language  The target object's language.
 * @return array{linked: bool, system: string, language: string, links: array<string, int>}|WP_Error
 */
function hal_mcp_translations_link_translation( int $source_id, int $target_id, string $post_type, string $language ) {

	$hal_mcp_system = hal_mcp_translations_active_system();

	if ( '' === $hal_mcp_system ) {
		return new WP_Error(
			'hal_mcp_translations_unavailable',
			__( 'No translation system is active on this site; translation relations cannot be read or created.', 'hal-mcp' )
		);
	}

	$hal_mcp_language = hal_mcp_translations_resolve_language( $language );

	if ( '' === $hal_mcp_language ) {
		return new WP_Error(
			'hal_mcp_language_unconfigured',
			sprintf(
				/* translators: %s: requested language code. */
				__( 'The language "%s" is not configured in the site\'s translation system.', 'hal-mcp' ),
				$language
			)
		);
	}

	if ( $source_id < 1 || $target_id < 1 || $source_id === $target_id ) {
		return new WP_Error(
			'hal_mcp_invalid_translation_targets',
			__( 'The source and target must be two different existing objects.', 'hal-mcp' )
		);
	}

	// Defense in depth (F23 audit): BOTH objects must be editable by the
	// current user before anything is read or written. The direct path's
	// requester gate and the apply path's approval gates already log their
	// denials — this check stays quiet to avoid double audit rows.
	foreach ( [ $source_id, $target_id ] as $hal_mcp_link_object ) {
		if ( ! hal_mcp_permission( $post_type, 'edit', $hal_mcp_link_object, [ 'ability' => 'hal/link-translation', 'reason' => 'link_objects', 'quiet' => true ] ) ) {
			return new WP_Error(
				'hal_mcp_forbidden_object',
				__( 'You are not allowed to link this object into a translation group.', 'hal-mcp' )
			);
		}
	}

	// The group is read LIVE — the existing links are the authority this
	// operation must preserve.
	$hal_mcp_group = hal_mcp_translations_read_links( $source_id, $post_type );

	if ( is_wp_error( $hal_mcp_group ) ) {
		return $hal_mcp_group;
	}

	if ( isset( $hal_mcp_group['links'][ $hal_mcp_language ] )
		&& (int) $hal_mcp_group['links'][ $hal_mcp_language ] !== $target_id ) {
		return new WP_Error(
			'hal_mcp_translation_group_conflict',
			sprintf(
				/* translators: 1: language code. 2: the object ID already linked for that language. */
				__( 'The language "%1$s" is already linked to object #%2$d in this group; resolve that conflict first — groups are never silently rewritten.', 'hal-mcp' ),
				$hal_mcp_language,
				(int) $hal_mcp_group['links'][ $hal_mcp_language ]
			)
		);
	}

	if ( isset( $hal_mcp_group['links'][ $hal_mcp_language ] )
		&& (int) $hal_mcp_group['links'][ $hal_mcp_language ] === $target_id ) {
		// Already linked exactly like this: idempotent success, no rewrite.
		return [
			'linked'   => true,
			'system'   => $hal_mcp_system,
			'language' => $hal_mcp_language,
			'links'    => $hal_mcp_group['links'],
		];
	}

	// The TARGET's own group is read too (F23: الحفاظ على الروابط القديمة
	// غير المستهدفة): pulling an object out of an existing group silently —
	// which both backends would do to satisfy a new link — is refused. The
	// idempotent case above already returned, so a target still holding a
	// multi-member group here belongs to a DIFFERENT group.
	$hal_mcp_target_group = hal_mcp_translations_read_links( $target_id, $post_type );

	if ( is_wp_error( $hal_mcp_target_group ) ) {
		return $hal_mcp_target_group;
	}

	$hal_mcp_target_other_links = $hal_mcp_target_group['links'];

	if ( '' !== $hal_mcp_target_group['element_language'] ) {
		unset( $hal_mcp_target_other_links[ $hal_mcp_target_group['element_language'] ] );
	}

	if ( ! empty( $hal_mcp_target_other_links ) ) {
		return new WP_Error(
			'hal_mcp_translation_target_in_group',
			sprintf(
				/* translators: 1: object ID. */
				__( 'Object #%1$d already belongs to another translation group; moving it needs an explicit decision, not a silent regroup.', 'hal-mcp' ),
				$target_id
			)
		);
	}

	if ( 'wpml' === $hal_mcp_system ) {
		$hal_mcp_sitepress  = $GLOBALS['sitepress'];
		$hal_mcp_element_tp = 'post_' . $post_type;

		$hal_mcp_trid = $hal_mcp_sitepress->get_element_trid( $source_id, $hal_mcp_element_tp );

		if ( ! $hal_mcp_trid ) {
			return new WP_Error(
				'hal_mcp_translations_group_missing',
				__( 'The source object has no WPML translation group; assign it a language first.', 'hal-mcp' )
			);
		}

		// Official group interface (S21): the target joins the source's
		// group under its language; every other link of the group stays
		// untouched — WPML implements the preservation.
		apply_filters(
			'wpml_set_element_language_details',
			null,
			[
				'element_id'          => $target_id,
				'element_type'        => $hal_mcp_element_tp,
				'trid'                => $hal_mcp_trid,
				'language_code'       => $hal_mcp_language,
				'source_language_code' => '' !== $hal_mcp_group['element_language']
					? $hal_mcp_group['element_language']
					: null,
			]
		);
	} else {
		// Polylang: the target must HOLD its language before the group is
		// saved — real Polylang (PLL_Translated_Object::save_translations →
		// validate_translations) silently DROPS entries whose post has no
		// language term, so a language-less target would make the link
		// silently vanish. Assign, then merge the new link into the group
		// and save the WHOLE set through the official function —
		// pll_save_post_translations() is the documented save-translations
		// interface (both functions are guaranteed present by the
		// availability guard).
		pll_set_post_language( $target_id, $hal_mcp_language );

		$hal_mcp_set                      = $hal_mcp_group['links'];
		$hal_mcp_set[ $hal_mcp_language ] = $target_id;

		pll_save_post_translations( $hal_mcp_set );
	}

	$hal_mcp_after = hal_mcp_translations_read_links( $source_id, $post_type );

	if ( is_wp_error( $hal_mcp_after ) ) {
		return $hal_mcp_after;
	}

	// Prove the merge preserved the group: every previous link must still
	// be there, and the new one must point at the target.
	foreach ( $hal_mcp_group['links'] as $hal_mcp_code => $hal_mcp_link_id ) {
		if ( ! isset( $hal_mcp_after['links'][ $hal_mcp_code ] )
			|| (int) $hal_mcp_after['links'][ $hal_mcp_code ] !== (int) $hal_mcp_link_id ) {
			return new WP_Error(
				'hal_mcp_translations_link_unverified',
				__( 'The link could not be verified against the group afterwards; re-read the group before relying on it.', 'hal-mcp' )
			);
		}
	}

	if ( (int) ( $hal_mcp_after['links'][ $hal_mcp_language ] ?? 0 ) !== $target_id ) {
		return new WP_Error(
			'hal_mcp_translations_link_unverified',
			__( 'The new link could not be verified against the group; re-read the group before relying on it.', 'hal-mcp' )
		);
	}

	return [
		'linked'   => true,
		'system'   => $hal_mcp_system,
		'language' => $hal_mcp_language,
		'links'    => $hal_mcp_after['links'],
	];
}

// ---------------------------------------------------------------------------
// Language surface (the filter the read/write abilities already validate against)
// ---------------------------------------------------------------------------

/**
 * Widens the site's language surface with the active system's configured
 * languages (F23: the F08/F10/F20 abilities' filter). With no system the
 * list flows through unchanged — the site locale stays the only language,
 * and nothing is invented.
 *
 * @param string[] $languages Current surface (site locale by default).
 * @return string[]
 */
function hal_mcp_translations_language_surface( array $languages ): array {

	$hal_mcp_configured = hal_mcp_translations_configured_languages();

	if ( empty( $hal_mcp_configured ) ) {
		return $languages;
	}

	return array_values( array_unique( array_merge( array_map( 'strval', $languages ), $hal_mcp_configured ) ) );
}

add_filter( 'hal_mcp_supported_languages', 'hal_mcp_translations_language_surface' );

// ---------------------------------------------------------------------------
// Proposal path (protected items go through the approval flow)
// ---------------------------------------------------------------------------

/**
 * Proposes linking a translation into a group (F23: العلاقات التي تؤثر في
 * محتوى منشور أو hreflang تمر بالاعتماد أيضًا). Draft-to-draft linking on
 * authorized objects is direct; a link whose SOURCE is protected (published/
 * private/future/pending) becomes a stored change request whose snapshot
 * carries the group's current links, so approval is bound to exactly that
 * group state.
 *
 * @param int                  $source_id Group's existing object.
 * @param int                  $target_id New translation object.
 * @param string               $post_type Objects' post type.
 * @param string               $language  Target object's language.
 * @param array<string, mixed> $origin    Origin identity (source/model).
 * @return array<string, mixed>|WP_Error {applied_directly, ...} or
 *              {applied_directly: false, request_id, state}.
 */
function hal_mcp_translations_propose_link( int $source_id, int $target_id, string $post_type, string $language, array $origin = [] ) {

	if ( '' === hal_mcp_translations_active_system() ) {
		return new WP_Error(
			'hal_mcp_translations_unavailable',
			__( 'No translation system is active on this site; translation relations cannot be read or created.', 'hal-mcp' )
		);
	}

	$hal_mcp_object_type = hal_mcp_object_type_post_type( $post_type );

	if ( '' === $hal_mcp_object_type || hal_mcp_is_internal_post_type( $hal_mcp_object_type ) ) {
		return new WP_Error(
			'hal_mcp_invalid_target_type',
			__( 'Translation links can only be proposed for addressable content objects.', 'hal-mcp' )
		);
	}

	$hal_mcp_source = get_post( $source_id );
	$hal_mcp_target = get_post( $target_id );

	if ( ! $hal_mcp_source instanceof WP_Post || $hal_mcp_source->post_type !== $hal_mcp_object_type ) {
		return new WP_Error(
			'hal_mcp_target_not_found',
			__( 'No source object of that type was found with that ID.', 'hal-mcp' )
		);
	}

	if ( ! $hal_mcp_target instanceof WP_Post || $hal_mcp_target->post_type !== $hal_mcp_object_type ) {
		return new WP_Error(
			'hal_mcp_target_not_found',
			__( 'No target object of that type was found with that ID.', 'hal-mcp' )
		);
	}

	if ( 'trash' === $hal_mcp_source->post_status || 'trash' === $hal_mcp_target->post_status ) {
		return new WP_Error(
			'hal_mcp_status_not_editable',
			__( 'A trashed object cannot take part in a translation link. Restore it first.', 'hal-mcp' )
		);
	}

	// Requester gate on BOTH objects — the same object-level check any
	// write path faces (create_change_request re-checks each target too).
	foreach ( [ [ $source_id, $hal_mcp_source ], [ $target_id, $hal_mcp_target ] ] as $hal_mcp_probe ) {
		if ( ! hal_mcp_permission( $hal_mcp_object_type, 'edit', $hal_mcp_probe[0], [ 'ability' => 'hal/link-translation', 'reason' => 'requester_gate' ] ) ) {
			return new WP_Error(
				'hal_mcp_forbidden_object',
				__( 'You are not allowed to link this object into a translation group.', 'hal-mcp' )
			);
		}
	}

	// The language must be configured BEFORE any path is chosen.
	if ( '' === hal_mcp_translations_resolve_language( $language ) ) {
		return new WP_Error(
			'hal_mcp_language_unconfigured',
			sprintf(
				/* translators: %s: requested language code. */
				__( 'The language "%s" is not configured in the site\'s translation system.', 'hal-mcp' ),
				$language
			)
		);
	}

	$hal_mcp_decision = hal_mcp_decide_write_path(
		$hal_mcp_object_type,
		(string) $hal_mcp_source->post_status,
		[ 'operation' => 'hal/link-translation' ]
	);

	if ( 'deny' === $hal_mcp_decision['decision'] ) {
		return new WP_Error(
			'hal_mcp_status_not_editable',
			__( 'A trashed object cannot take part in a translation link. Restore it first.', 'hal-mcp' )
		);
	}

	// The TARGET's status decides too (F23: العلاقات التي تؤثر في محتوى
	// منشور أو hreflang تمر بالاعتماد أيضًا): a link is direct ONLY when
	// BOTH sides are draft work — a protected side (published/private/
	// future/pending) on either end makes the whole link a request, because
	// linking changes what that side exposes (hreflang, relation output).
	$hal_mcp_target_decision = hal_mcp_decide_write_path(
		$hal_mcp_object_type,
		(string) $hal_mcp_target->post_status,
		[ 'operation' => 'hal/link-translation' ]
	);

	if ( 'deny' === $hal_mcp_target_decision['decision'] ) {
		return new WP_Error(
			'hal_mcp_status_not_editable',
			__( 'A trashed object cannot take part in a translation link. Restore it first.', 'hal-mcp' )
		);
	}

	if ( 'direct' === $hal_mcp_decision['decision'] && 'direct' === $hal_mcp_target_decision['decision'] ) {
		$hal_mcp_linked = hal_mcp_translations_link_translation( $source_id, $target_id, $hal_mcp_object_type, $language );

		if ( is_wp_error( $hal_mcp_linked ) ) {
			return $hal_mcp_linked;
		}

		hal_mcp_log_control_event(
			[
				'operation'      => 'link-translation',
				'stage'          => 'applied_direct',
				'source'         => (string) ( $origin['source'] ?? 'mcp' ),
				'success'        => true,
				'result_summary' => sprintf( 'Translation linked directly (drafts): #%1$d -> #%2$d in "%3$s".', $source_id, $target_id, $hal_mcp_linked['language'] ),
			]
		);

		return [
			'applied_directly' => true,
			'applied'          => $hal_mcp_linked,
		];
	}

	// Request path: the group's current links are the fingerprinted
	// original snapshot — a link applied against a different group state
	// than the one approved is a conflict, never an overwrite.
	$hal_mcp_group = hal_mcp_translations_read_links( $source_id, $post_type );

	if ( is_wp_error( $hal_mcp_group ) ) {
		return $hal_mcp_group;
	}

	$hal_mcp_queued = hal_mcp_create_change_request(
		[
			'operation'         => 'link-translation',
			'payload'           => [
				'source_id' => $source_id,
				'target_id' => $target_id,
				'post_type' => $hal_mcp_object_type,
				'language'  => hal_mcp_translations_resolve_language( $language ),
			],
			'targets'           => [
				[
					'type' => $hal_mcp_object_type,
					'id'   => $source_id,
				],
				[
					'type' => $hal_mcp_object_type,
					'id'   => $target_id,
				],
			],
			'original_snapshot' => [
				'links' => $hal_mcp_group['links'],
			],
			'language'          => hal_mcp_translations_resolve_language( $language ),
			'origin'            => $origin,
		]
	);

	if ( is_wp_error( $hal_mcp_queued ) ) {
		return $hal_mcp_queued;
	}

	return [
		'applied_directly' => false,
		'request_id'       => (int) $hal_mcp_queued['request_id'],
		// Model-facing answer: 'pending_approval' (roadmap §4.3/F17).
		'state'            => hal_mcp_request_model_state( (string) $hal_mcp_queued['state'] ),
		'before_links'     => $hal_mcp_group['links'],
	];
}

// ---------------------------------------------------------------------------
// F17 apply handler
// ---------------------------------------------------------------------------

/**
 * Live fingerprint revalidation for 'link-translation' requests (F17
 * contract): fingerprints the source group's CURRENT links — never echoes
 * the stored snapshot.
 *
 * @param array<string, mixed> $request Request data.
 * @return string '' when the group cannot be read (apply-time conflict).
 */
function hal_mcp_translations_revalidate_fingerprint( array $request ): string {

	$hal_mcp_targets   = (array) ( $request['targets'] ?? [] );
	$hal_mcp_source_id = (int) ( $hal_mcp_targets[0]['id'] ?? 0 );
	$hal_mcp_payload   = (array) ( $request['payload'] ?? [] );
	$hal_mcp_post_type = (string) ( $hal_mcp_payload['post_type'] ?? '' );

	if ( $hal_mcp_source_id < 1 || '' === $hal_mcp_post_type ) {
		return '';
	}

	$hal_mcp_group = hal_mcp_translations_read_links( $hal_mcp_source_id, $hal_mcp_post_type );

	if ( is_wp_error( $hal_mcp_group ) ) {
		return '';
	}

	return hal_mcp_fingerprint( $hal_mcp_group['links'] );
}

/**
 * Registers the 'link-translation' apply handler (F17). Applies ONLY the
 * stored payload through the shared link function — which re-checks the
 * group and refuses conflicting sets at apply time too. The handler is
 * authorization-BOUND TO THE TARGETS (F23 audit): the approver previews the
 * stored target records and the capability gates run on THEM, so the ids
 * and object type are derived from the targets and the payload must name
 * exactly the same objects — a payload that diverges from its targets is
 * refused, never applied.
 *
 * @return void
 */
function hal_mcp_translations_register_apply_handler(): void {

	hal_mcp_register_apply_handler(
		'link-translation',
		static function ( array $request ) {
			$hal_mcp_payload = (array) ( $request['payload'] ?? [] );
			$hal_mcp_targets = (array) ( $request['targets'] ?? [] );

			// Derived from the STORED TARGET records — the surface the
			// requester/approver gates actually ran against — never from the
			// payload alone.
			$hal_mcp_source_id = (int) ( $hal_mcp_targets[0]['id'] ?? 0 );
			$hal_mcp_target_id = (int) ( $hal_mcp_targets[1]['id'] ?? 0 );
			$hal_mcp_post_type = (string) ( $hal_mcp_targets[0]['type'] ?? '' );
			$hal_mcp_language  = (string) ( $hal_mcp_payload['language'] ?? '' );

			// The payload must agree with the targets EXACTLY; any divergence
			// (including a missing second target) is a mismatch, not a
			// fallback to payload values.
			if ( (int) ( $hal_mcp_payload['source_id'] ?? 0 ) !== $hal_mcp_source_id
				|| (int) ( $hal_mcp_payload['target_id'] ?? 0 ) !== $hal_mcp_target_id
				|| (string) ( $hal_mcp_payload['post_type'] ?? '' ) !== $hal_mcp_post_type ) {
				return new WP_Error(
					'hal_mcp_translation_payload_mismatch',
					__( 'The stored payload does not match the request\'s approved targets; nothing was applied.', 'hal-mcp' )
				);
			}

			if ( $hal_mcp_source_id < 1 || $hal_mcp_target_id < 1 || '' === $hal_mcp_post_type || '' === $hal_mcp_language ) {
				return new WP_Error(
					'hal_mcp_translations_payload_invalid',
					__( 'The stored link payload is malformed; nothing was applied.', 'hal-mcp' )
				);
			}

			$hal_mcp_linked = hal_mcp_translations_link_translation( $hal_mcp_source_id, $hal_mcp_target_id, $hal_mcp_post_type, $hal_mcp_language );

			if ( is_wp_error( $hal_mcp_linked ) ) {
				return $hal_mcp_linked;
			}

			return sprintf(
				/* translators: 1: source ID. 2: target ID. 3: language code. */
				__( 'Translation linked: object #%1$d and object #%2$d are now related in "%3$s".', 'hal-mcp' ),
				$hal_mcp_source_id,
				$hal_mcp_target_id,
				$hal_mcp_linked['language']
			);
		},
		'hal_mcp_translations_revalidate_fingerprint'
	);
}

hal_mcp_translations_register_apply_handler();

// ---------------------------------------------------------------------------
// Registration
// ---------------------------------------------------------------------------

hal_mcp_register_integration(
	'translations',
	[
		'label'      => __( 'Translations (WPML / Polylang)', 'hal-mcp' ),
		'version'    => hal_mcp_translations_active_version(),
		'source'     => hal_mcp_translations_active_system(),
		'available'  => '' !== hal_mcp_translations_active_system(),
		'operations' => [
			'resolve_language' => [
				'effect'     => 'read',
				'capability' => 'edit_posts',
				'writable'   => [],
			],
			'read_links'       => [
				'effect'     => 'read',
				'capability' => 'edit_posts',
				'writable'   => [],
			],
			'assign_language'  => [
				'effect'     => 'edit',
				'capability' => 'edit_posts',
				'writable'   => [ 'language' ],
			],
			'link_translations' => [
				'effect'     => 'edit',
				'capability' => 'edit_posts',
				'writable'   => [ 'language', 'translations' ],
			],
		],
		'notes'      => __( 'Two real branches: WPML through the official wpml_set_element_language_details group interface, Polylang through the documented pll_* function reference. Groups are read live before every link; conflicting sets are refused; links affecting a published source go through the approval flow. Without a system, no relation is ever invented.', 'hal-mcp' ),
	]
);
