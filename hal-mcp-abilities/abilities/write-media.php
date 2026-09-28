<?php
/**
 * hal-mcp-abilities — hal/upload-media.
 *
 * Abilities API input is JSON, so there is no multipart file upload the way
 * a browser form has one — the file arrives as a base64 string in $input and
 * is written to disk, then registered as a media library attachment via
 * wp_insert_attachment() + wp_generate_attachment_metadata(), which is
 * WordPress's own documented low-level upload sequence (the same building
 * blocks media_handle_upload() itself is built from).
 *
 * F13 content-verification order (the v1 sequence verified the content only
 * AFTER wp_upload_bits() had already written it to the final upload path):
 *
 *   1. extension allow-list + base64 strictness + size caps (kept from v1);
 *   2. the decoded bytes are staged into a server temp file OUTSIDE the
 *      public web root (wp_tempnam/get_temp_dir). If the environment has no
 *      such location, the upload is refused — the file is never staged
 *      somewhere web-reachable;
 *   3. the STAGED file's content is verified with WordPress's own
 *      wp_check_filetype_and_ext() PLUS a per-type content proof: real
 *      image bytes via getimagesize() for images, a real %PDF signature via
 *      fileinfo for PDFs. When a type cannot be verified credibly (a PDF
 *      with no fileinfo extension, for example) that type is REFUSED with a
 *      clear message — the claimed extension is never trusted on its own;
 *   4. only then is the verified file moved into the uploads directory
 *      through wp_handle_sideload() — WordPress's own media mechanism — and
 *      registered as an attachment. The temp file is cleaned up on every
 *      failure path.
 *
 * Size limit (F13: مواءمة الحد مع حد الموقع): the plugin's own cap is the
 * minimum of its 10 MB constant and the site's configured upload ceiling
 * (wp_max_upload_size()), so a 2 MB host plan cannot be promised 10 MB.
 *
 * Parent attachment (F13): post, page, product, and authorized generic
 * content types may receive uploads, always gated by the parent's REAL edit
 * capability (F05), not just upload_files.
 *
 * Error hygiene: no local server path ever appears in an error the model
 * sees; specifics go to error_log only. No executable format is accepted,
 * and no new infrastructure (scanner, antivirus) is introduced.
 *
 * @package hal-mcp-abilities
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * File extensions this ability will accept, regardless of what WordPress
 * core's own broader default allow-list permits. Kept deliberately narrow
 * for a v1 upload ability: common web image formats plus PDF for downloadable
 * documents. Extend this list only with a deliberate decision, not by default.
 *
 * @var string[]
 */
const HAL_MCP_ALLOWED_MEDIA_EXTENSIONS = [ 'jpg', 'jpeg', 'png', 'gif', 'webp', 'pdf' ];

/**
 * The plugin's own maximum accepted decoded file size, in bytes (10 MB). The
 * EFFECTIVE cap is min(this, wp_max_upload_size()) — see
 * hal_mcp_media_max_upload_bytes().
 *
 * @var int
 */
const HAL_MCP_MAX_UPLOAD_BYTES = 10 * 1024 * 1024;

/**
 * Extensions verified as images through getimagesize() on the staged file.
 *
 * @var string[]
 */
const HAL_MCP_IMAGE_MEDIA_EXTENSIONS = [ 'jpg', 'jpeg', 'png', 'gif', 'webp' ];

/**
 * The plugin's effective upload cap: min(own cap, site's configured ceiling)
 * (F13: لا افتراض أن 10MB يناسب كل استضافة). wp_max_upload_size() reads the
 * server's own upload_max_filesize/post_max_size configuration.
 *
 * @return int
 */
function hal_mcp_media_max_upload_bytes(): int {

	if ( ! function_exists( 'wp_max_upload_size' ) ) {
		return HAL_MCP_MAX_UPLOAD_BYTES;
	}

	$hal_mcp_site_limit = (int) wp_max_upload_size();

	if ( $hal_mcp_site_limit < 1 ) {
		return HAL_MCP_MAX_UPLOAD_BYTES;
	}

	return min( HAL_MCP_MAX_UPLOAD_BYTES, $hal_mcp_site_limit );
}

/**
 * Maps an allow-listed extension to its expected MIME family prefix, used
 * to cross-check whatever content proof the staged file produced.
 *
 * @param string $extension Lower-case extension.
 * @return string|null 'image' or 'application/pdf', null when unknown.
 */
function hal_mcp_media_expected_mime_family( string $extension ): ?string {

	if ( in_array( $extension, HAL_MCP_IMAGE_MEDIA_EXTENSIONS, true ) ) {
		return 'image';
	}

	if ( 'pdf' === $extension ) {
		return 'application/pdf';
	}

	return null;
}

/**
 * Content-proofs the STAGED file against its claimed type (F13: فحص محتوى
 * الملف قبل النقل النهائي). Returns true only when the file's actual bytes
 * credibly match the claimed type:
 *
 * - images: getimagesize() must identify a real image AND fileinfo (when
 *   available) must agree on an image/* type;
 * - PDF: fileinfo is REQUIRED — without it a PDF cannot be verified
 *   credibly and is refused, extension or no extension;
 * - any other allow-listed type (none today): WordPress's own
 *   wp_check_filetype_and_ext() result plus fileinfo agreement when
 *   fileinfo exists.
 *
 * @param string $temp_path Staged temp file path.
 * @param string $extension  Claimed (and allow-listed) extension.
 * @return bool
 */
function hal_mcp_media_verify_staged_content( string $temp_path, string $extension ): bool {

	// A non-empty, readable staged file is the precondition for any proof.
	if ( '' === $temp_path || ! is_file( $temp_path ) || ! is_readable( $temp_path ) || filesize( $temp_path ) < 1 ) {
		return false;
	}

	$hal_mcp_has_fileinfo = function_exists( 'finfo_open' );

	// fileinfo-derived real type (the strongest generic signal available).
	$hal_mcp_real_mime = '';

	if ( $hal_mcp_has_fileinfo ) {
		$hal_mcp_finfo = finfo_open( FILEINFO_MIME_TYPE );

		if ( false !== $hal_mcp_finfo ) {
			$hal_mcp_real_mime = (string) finfo_file( $hal_mcp_finfo, $temp_path );
			finfo_close( $hal_mcp_finfo );
		}
	}

	$hal_mcp_family = hal_mcp_media_expected_mime_family( $extension );

	if ( 'image' === $hal_mcp_family ) {
		// Real image bytes are the primary proof; fileinfo must not disagree.
		$hal_mcp_image_info = @getimagesize( $temp_path ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- probing an untrusted file; failures are the rejection path.

		if ( ! is_array( $hal_mcp_image_info ) || empty( $hal_mcp_image_info['mime'] ) ) {
			return false;
		}

		if ( $hal_mcp_has_fileinfo && '' !== $hal_mcp_real_mime && ! str_starts_with( $hal_mcp_real_mime, 'image/' ) ) {
			return false;
		}

		return true;
	}

	if ( 'application/pdf' === $hal_mcp_family ) {
		// Without fileinfo a PDF cannot be verified credibly — refuse (F13:
		// «خصوصًا PDF عند غياب fileinfo»), never fall back to the extension.
		if ( ! $hal_mcp_has_fileinfo ) {
			return false;
		}

		return str_starts_with( $hal_mcp_real_mime, 'application/pdf' );
	}

	// Unknown family: only a fileinfo agreement would count, and nothing
	// outside images+PDF should reach this line (the allow-list is closed).
	return $hal_mcp_has_fileinfo && '' !== $hal_mcp_real_mime;
}

add_action( 'wp_abilities_api_init', 'hal_mcp_register_write_media_abilities' );

/**
 * Registers the hal/upload-media ability.
 *
 * @return void
 */
function hal_mcp_register_write_media_abilities(): void {

	wp_register_ability(
		'hal/upload-media',
		[
			'label'       => __( 'Upload a media library file', 'hal-mcp' ),
			'description' => __( 'Uploads a file (as base64-encoded content) into the WordPress media library. Only image files (jpg, jpeg, png, gif, webp) and PDF documents are accepted, up to the smaller of 10 MB and this site\'s own upload limit. Content is verified in a private server temp location before anything enters the media library; an unverifiable type (e.g. PDF without fileinfo) is refused.', 'hal-mcp' ),
			'category'    => 'hal-media',

			'input_schema' => [
				'type'                 => 'object',
				'properties'           => [
					'filename'         => [
						'type'        => 'string',
						'description' => __( 'Desired filename including extension, e.g. "diagram.png".', 'hal-mcp' ),
					],
					'file_data_base64' => [
						'type'        => 'string',
						'description' => __( 'The file content, base64-encoded.', 'hal-mcp' ),
					],
					'title'            => [
						'type'        => 'string',
						'description' => __( 'Optional media title. Defaults to the filename without its extension.', 'hal-mcp' ),
					],
					'alt_text'         => [
						'type'        => 'string',
						'description' => __( 'Optional alt text, relevant for images.', 'hal-mcp' ),
					],
					'parent_post_id'   => [
						'type'        => 'integer',
						'minimum'     => 0,
						'default'     => 0,
						'description' => __( 'Optional ID of an existing post, page, or product (or an authorized content type) to attach this media to. 0 (default) leaves it unattached. Attaching requires real edit permission on the parent.', 'hal-mcp' ),
					],
				],
				'required'             => [ 'filename', 'file_data_base64' ],
				'additionalProperties' => false,
			],

			'output_schema' => [
				'type'       => 'object',
				'properties' => [
					'id'        => [ 'type' => 'integer' ],
					'url'       => [ 'type' => 'string' ],
					'mime_type' => [ 'type' => 'string' ],
					'filename'  => [ 'type' => 'string' ],
				],
			],

			'permission_callback' => static fn() => hal_mcp_permission( 'media', 'upload', 0, [ 'ability' => 'hal/upload-media' ] ),

			'execute_callback' => function ( $input ) {

				if ( ! function_exists( 'wp_generate_attachment_metadata' ) ) {
					require_once ABSPATH . 'wp-admin/includes/image.php';
				}

				if ( ! function_exists( 'wp_tempnam' ) || ! function_exists( 'wp_handle_sideload' ) ) {
					require_once ABSPATH . 'wp-admin/includes/file.php';
				}

				$hal_mcp_max_bytes = hal_mcp_media_max_upload_bytes();

				$filename = isset( $input['filename'] ) ? trim( (string) $input['filename'] ) : '';

				if ( '' === $filename ) {
					return new WP_Error(
						'hal_mcp_missing_filename',
						__( 'filename must not be empty.', 'hal-mcp' )
					);
				}

				$extension = strtolower( pathinfo( $filename, PATHINFO_EXTENSION ) );

				if ( ! in_array( $extension, HAL_MCP_ALLOWED_MEDIA_EXTENSIONS, true ) ) {
					return new WP_Error(
						'hal_mcp_disallowed_file_type',
						sprintf(
							/* translators: %s: comma-separated list of allowed file extensions. */
							__( 'That file type is not allowed. Allowed types: %s.', 'hal-mcp' ),
							implode( ', ', HAL_MCP_ALLOWED_MEDIA_EXTENSIONS )
						)
					);
				}

				$base64_data = isset( $input['file_data_base64'] ) ? (string) $input['file_data_base64'] : '';

				if ( '' === $base64_data ) {
					return new WP_Error(
						'hal_mcp_missing_file_data',
						__( 'file_data_base64 must not be empty.', 'hal-mcp' )
					);
				}

				// Reject an oversized payload by its encoded length before
				// decoding it into memory at all: base64 encodes 4 characters
				// for every 3 bytes of binary data. The cap is the site-
				// aligned one (F13), not a hard-coded assumption.
				$max_base64_length = (int) ( ceil( $hal_mcp_max_bytes / 3 ) * 4 );

				if ( strlen( $base64_data ) > $max_base64_length ) {
					return new WP_Error(
						'hal_mcp_file_too_large',
						sprintf(
							/* translators: %d: maximum allowed file size in megabytes. */
							__( 'File is too large. This site accepts media up to %d MB.', 'hal-mcp' ),
							(int) ( $hal_mcp_max_bytes / ( 1024 * 1024 ) )
						)
					);
				}

				// Strict mode: reject input containing characters outside the
				// base64 alphabet instead of silently ignoring them.
				$binary_data = base64_decode( $base64_data, true );

				if ( false === $binary_data || '' === $binary_data ) {
					return new WP_Error(
						'hal_mcp_invalid_file_data',
						__( 'file_data_base64 is not valid base64-encoded data.', 'hal-mcp' )
					);
				}

				if ( strlen( $binary_data ) > $hal_mcp_max_bytes ) {
					return new WP_Error(
						'hal_mcp_file_too_large',
						sprintf(
							/* translators: %d: maximum allowed file size in megabytes. */
							__( 'File is too large. This site accepts media up to %d MB.', 'hal-mcp' ),
							(int) ( $hal_mcp_max_bytes / ( 1024 * 1024 ) )
						)
					);
				}

				$parent_post_id = absint( $input['parent_post_id'] ?? 0 );

				if ( $parent_post_id > 0 ) {
					$parent_type = get_post_type( $parent_post_id );

					// F13: page and authorized generic content types join
					// post/product as attachable parents. Anything outside
					// this closed set is refused without disclosure.
					$hal_mcp_parent_object_type = '';

					if ( 'post' === $parent_type ) {
						$hal_mcp_parent_object_type = 'post';
					} elseif ( 'page' === $parent_type ) {
						$hal_mcp_parent_object_type = 'page';
					} elseif ( 'product' === $parent_type ) {
						$hal_mcp_parent_object_type = 'product';
					} elseif ( in_array( (string) $parent_type, hal_mcp_content_authorized_post_types(), true ) ) {
						$hal_mcp_parent_object_type = (string) $parent_type;
					}

					if ( '' === $hal_mcp_parent_object_type ) {
						return new WP_Error(
							'hal_mcp_invalid_parent',
							__( 'parent_post_id must refer to an existing post, page, product, or authorized content item.', 'hal-mcp' )
						);
					}

					// Link eligibility (F05/F13): attaching media to a parent
					// is a modification of that parent — require the actual
					// edit capability on it, not just upload_files.
					if ( ! hal_mcp_permission( $hal_mcp_parent_object_type, 'edit', $parent_post_id, [ 'ability' => 'hal/upload-media', 'reason' => 'attach_parent' ] ) ) {
						return new WP_Error(
							'hal_mcp_parent_forbidden',
							__( 'You are not allowed to modify the item this file would be attached to.', 'hal-mcp' )
						);
					}
				}

				$sanitized_filename = sanitize_file_name( $filename );

				// --- Stage the decoded bytes in a private server temp file
				// (F13 step 2). wp_tempnam() uses the environment's temp
				// directory; when that directory resolves inside the public
				// web root there is NO secure staging location and the upload
				// is refused instead of being staged publicly. ---
				$temp_path = wp_tempnam( $sanitized_filename );

				if ( '' === (string) $temp_path || ! is_file( (string) $temp_path ) ) {
					return new WP_Error(
						'hal_mcp_upload_failed',
						__( 'The file could not be staged for content verification. Nothing was uploaded.', 'hal-mcp' )
					);
				}

				$hal_mcp_abspath  = (string) realpath( ABSPATH );
				$hal_mcp_temp_real = (string) realpath( (string) $temp_path );

				// Fail closed: when either path cannot be resolved, the
				// staging location cannot be PROVEN to sit outside the public
				// web root — an empty realpath must refuse the upload exactly
				// like a temp dir inside ABSPATH, never skip the check.
				if ( '' === $hal_mcp_abspath
					|| '' === $hal_mcp_temp_real
					|| str_starts_with( $hal_mcp_temp_real . DIRECTORY_SEPARATOR, $hal_mcp_abspath . DIRECTORY_SEPARATOR ) ) {
					// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- best-effort cleanup of a staging file we are refusing to use.
					@unlink( (string) $temp_path );

					return new WP_Error(
						'hal_mcp_no_secure_staging',
						__( 'This server has no private temp location outside the public web root, so the upload was refused for content-verification safety. Nothing was uploaded.', 'hal-mcp' )
					);
				}

				// Every failure path from here on must clean the staged file —
				// each branch unlinks before returning (F13: تنظيف المؤقت في
				// جميع مسارات الفشل).
				if ( false === file_put_contents( (string) $temp_path, $binary_data ) ) {
					// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- best-effort cleanup.
					@unlink( (string) $temp_path );

					return new WP_Error(
						'hal_mcp_upload_failed',
						__( 'The file could not be staged for content verification. Nothing was uploaded.', 'hal-mcp' )
					);
				}

				// --- Verify the STAGED content (F13 step 3): WordPress's own
				// ext/type check plus the per-type content proof. ---
				$filetype_check = wp_check_filetype_and_ext( (string) $temp_path, $sanitized_filename );
				$real_extension = strtolower( (string) ( $filetype_check['ext'] ?? '' ) );

				if ( empty( $filetype_check['type'] )
					|| ! in_array( $real_extension, HAL_MCP_ALLOWED_MEDIA_EXTENSIONS, true )
					|| ! hal_mcp_media_verify_staged_content( (string) $temp_path, $extension ) ) {
					// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- best-effort cleanup of a rejected staged file.
					@unlink( (string) $temp_path );

					return new WP_Error(
						'hal_mcp_disallowed_file_type',
						sprintf(
							/* translators: %s: the claimed file extension. */
							__( "The uploaded file's actual content could not be verified as a safe \"%s\" document, so it was rejected. For PDFs this server also needs the fileinfo extension.", 'hal-mcp' ),
							$extension
						)
					);
				}

				// --- Move the VERIFIED file into the uploads directory
				// through WordPress's own sideload mechanism (F13 step 4).
				// wp_handle_sideload() renames the temp file into place, so
				// on success no temp cleanup is needed. ---
				$hal_mcp_sideload = [
					'name'     => $sanitized_filename,
					'tmp_name' => (string) $temp_path,
					'error'    => 0,
					'size'     => strlen( $binary_data ),
				];

				$hal_mcp_move = wp_handle_sideload(
					$hal_mcp_sideload,
					[
						'test_form' => false,
						// Defence in depth: the sideload re-checks the type
						// against core's allowed MIME list with our narrow
						// allow-list as the ceiling.
						'mimes'     => [
							'jpg|jpeg|jpe' => 'image/jpeg',
							'png'          => 'image/png',
							'gif'          => 'image/gif',
							'webp'         => 'image/webp',
							'pdf'          => 'application/pdf',
						],
					]
				);

				if ( ! is_array( $hal_mcp_move ) || empty( $hal_mcp_move['file'] ) ) {
					// The exact reason may reference server paths — it goes to
					// the log, never to the model (F13: عدم كشف المسار المحلي).
					error_log(
						sprintf(
							'hal-mcp-abilities: verified media sideload failed for a %s upload: %s',
							$extension,
							is_array( $hal_mcp_move ) ? (string) ( $hal_mcp_move['error'] ?? 'unknown error' ) : 'unknown error'
						)
					);

					if ( is_file( (string) $temp_path ) ) {
						// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- best-effort cleanup after a failed move.
						@unlink( (string) $temp_path );
					}

					return new WP_Error(
						'hal_mcp_upload_failed',
						__( 'The verified file could not be moved into the media library. Nothing was uploaded.', 'hal-mcp' )
					);
				}

				$final_path   = (string) $hal_mcp_move['file'];
				$final_type   = (string) ( $hal_mcp_move['type'] ?? $filetype_check['type'] );

				$attachment_title = isset( $input['title'] ) ? trim( (string) $input['title'] ) : '';

				if ( '' === $attachment_title ) {
					$attachment_title = pathinfo( $sanitized_filename, PATHINFO_FILENAME );
				}

				$attachment_id = wp_insert_attachment(
					[
						'post_mime_type' => $final_type,
						'post_title'     => sanitize_text_field( $attachment_title ),
						'post_content'   => '',
						'post_status'    => 'inherit',
					],
					$final_path,
					$parent_post_id,
					true
				);

				if ( is_wp_error( $attachment_id ) ) {
					// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- best-effort cleanup; the DB insert failed so this file would otherwise be orphaned.
					@unlink( $final_path );

					// A WP_Error from wp_insert_attachment carries no server
					// path; return it as-is rather than flattening detail.
					return $attachment_id;
				}

				$attachment_metadata = wp_generate_attachment_metadata( $attachment_id, $final_path );
				$metadata_updated    = wp_update_attachment_metadata( $attachment_id, $attachment_metadata );

				if ( ! $metadata_updated ) {
					error_log(
						sprintf(
							'hal-mcp-abilities: wp_update_attachment_metadata() failed for attachment ID %d.',
							$attachment_id
						)
					);
				}

				$alt_text = isset( $input['alt_text'] ) ? trim( (string) $input['alt_text'] ) : '';

				if ( '' !== $alt_text ) {
					$alt_text_updated = update_post_meta( $attachment_id, '_wp_attachment_image_alt', sanitize_text_field( $alt_text ) );

					if ( ! $alt_text_updated ) {
						error_log(
							sprintf(
								'hal-mcp-abilities: update_post_meta() failed to set alt text for attachment ID %d.',
								$attachment_id
							)
						);
					}
				}

				return [
					'id'        => (int) $attachment_id,
					'url'       => (string) wp_get_attachment_url( $attachment_id ),
					'mime_type' => $final_type,
					'filename'  => wp_basename( $final_path ),
				];
			},

			'meta' => [ 'mcp' => [ 'public' => true ] ],
		]
	);
}
