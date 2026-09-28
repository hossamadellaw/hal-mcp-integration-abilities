<?php
/**
 * hal-mcp-abilities — shared provider HTTP transport (F16).
 *
 * The ONLY place provider API traffic flows through (roadmap F15/F16: "مشاركة
 * النقل"). It wraps the WordPress HTTP API and owns the safety envelope:
 *
 * - HTTPS only, certificate verification on, bounded timeout, bounded response
 *   size. wp_safe_remote_post()/wp_safe_remote_get() are used so WordPress's
 *   own unsafe-URL rejection (wp_http_validate_url) applies on top of the
 *   explicit checks below.
 * - Redirects are DISABLED (redirection=0): a 30x must never carry an
 *   Authorization header to a different host. Providers do not need follows;
 *   if one starts redirecting, the error says so and nothing is replayed.
 * - Endpoint validation (hal_mcp_http_validate_endpoint): https scheme, no
 *   userinfo credentials in the URL, and — for SSRF — the host must resolve
 *   only to public IPs. Loopback, private, link-local, CGNAT, multicast,
 *   reserved, unspecified, IPv4-mapped and IPv4-compatible addresses are
 *   refused, and a literal-IP host is checked the same way. Local model
 *   servers are NOT approved as a roadmap requirement and the local network
 *   is never opened automatically (§10: لا توسع الشبكة المحلية تلقائيًا);
 *   there is deliberately no filter that bypasses these checks in this
 *   version. Known, documented limit: the validation resolves the host
 *   once and WordPress's HTTP layer re-resolves independently at request
 *   time — DNS rebinding between the two is a theoretical TOCTOU this
 *   single-resolution design does not pin (fail-closed elsewhere keeps the
 *   window narrow; noted in the roadmap batch record).
 * - Transport errors and non-2xx statuses map to named WP_Error codes; the
 *   response body never reaches an error message raw — previews go through
 *   the audit log's redactor first, so an API key echoed by a provider (or
 *   any key-like string) cannot leak into logs or admin screens. A second
 *   shape-based pass (hal_mcp_http_mask_key_shapes) masks unlabeled
 *   Google/OpenAI/Anthropic key echoes from hostile/custom endpoints —
 *   defense in depth; the audit-log redactor's own widening stays F25's.
 * - No automatic retries, ever — a retry would re-execute a tool round the
 *   model already saw, and F18 resumes from saved call IDs instead (F16: "لا
 *   إعادة تنفيذ كتابة موقع بسبب retry").
 *
 * This file knows NOTHING about publishing rules, WooCommerce, editors, or
 * provider dialects — transport only (F16's last item). Callers pass the full
 * endpoint URL, headers, and an already-validated body array.
 *
 * @package hal-mcp-abilities
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Hard ceilings for one provider call. These are SAFETY caps — callers may
 * ask for less, never more.
 *
 * @var int
 */
const HAL_MCP_HTTP_MAX_TIMEOUT_SECONDS = 120;

/**
 * Largest response body accepted from a provider (1 MB). Model JSON fits far
 * below this; anything larger is treated as a hostile/misbehaving endpoint.
 *
 * @var int
 */
const HAL_MCP_HTTP_MAX_RESPONSE_BYTES = 1048576;

/**
 * Longest body excerpt embedded in an error message (after redaction).
 *
 * @var int
 */
const HAL_MCP_HTTP_ERROR_PREVIEW_CHARS = 280;

/**
 * Validates a provider endpoint URL against the transport's safety envelope.
 *
 * Fails CLOSED: anything not explicitly allowed is refused. Used both before
 * every call and when an administrator saves a custom/override endpoint, so
 * an unsafe URL can never be persisted for later use either.
 *
 * @param string $url Candidate endpoint URL.
 * @return string|WP_Error The normalized URL on success, WP_Error with a
 *                         hal_mcp_http_endpoint_* code on refusal.
 */
function hal_mcp_http_validate_endpoint( string $url ) {

	$url = trim( $url );

	if ( '' === $url || strlen( $url ) > 2048 ) {
		return new WP_Error( 'hal_mcp_http_endpoint_missing', __( 'The endpoint URL is empty or too long.', 'hal-mcp' ) );
	}

	$scheme = hal_mcp_http_url_scheme( $url );

	if ( 'https' !== $scheme ) {
		return new WP_Error(
			'hal_mcp_http_endpoint_not_https',
			__( 'Provider endpoints must use HTTPS.', 'hal-mcp' )
		);
	}

	$parts = hal_mcp_http_url_parts( $url );

	if ( null === $parts || '' === $parts['host'] ) {
		return new WP_Error( 'hal_mcp_http_endpoint_invalid', __( 'The endpoint URL could not be parsed.', 'hal-mcp' ) );
	}

	// URL-embedded credentials are refused: keys belong in headers, and a
	// userinfo string is a classic way to smuggle or leak them (F16).
	if ( '' !== $parts['user'] || '' !== $parts['pass'] ) {
		return new WP_Error(
			'hal_mcp_http_endpoint_userinfo',
			__( 'The endpoint URL must not contain embedded credentials.', 'hal-mcp' )
		);
	}

	if ( $parts['port'] < 0 || $parts['port'] > 65535 ) {
		return new WP_Error( 'hal_mcp_http_endpoint_invalid', __( 'The endpoint URL has an invalid port.', 'hal-mcp' ) );
	}

	$hal_mcp_ips = hal_mcp_http_resolve_host( $parts['host'] );

	if ( is_wp_error( $hal_mcp_ips ) ) {
		return $hal_mcp_ips;
	}

	foreach ( $hal_mcp_ips as $hal_mcp_ip ) {
		$hal_mcp_check = hal_mcp_http_ip_is_public( $hal_mcp_ip );

		if ( true !== $hal_mcp_check ) {
			return new WP_Error(
				'hal_mcp_http_endpoint_private',
				__( 'The endpoint host resolves to a local or private address, which this transport refuses.', 'hal-mcp' )
			);
		}
	}

	return $url;
}

/**
 * Resolves a host name to the list of IPs the transport must accept before
 * the request may leave. Literal IP hosts return themselves; names resolve
 * through DNS (both A and AAAA when the functions exist).
 *
 * Fail-closed: a name that cannot be resolved is refused, not guessed.
 *
 * @param string $host Host name or literal IP.
 * @return string[]|WP_Error
 */
function hal_mcp_http_resolve_host( string $host ) {

	$host = strtolower( trim( $host ) );

	// Bracketed IPv6 literal.
	if ( str_starts_with( $host, '[' ) && str_ends_with( $host, ']' ) ) {
		$host = substr( $host, 1, -1 );
	}

	// A literal IP never goes through name resolution.
	$hal_mcp_literal = filter_var( $host, FILTER_VALIDATE_IP );

	if ( false !== $hal_mcp_literal ) {
		return [ $host ];
	}

	/*
	 * Pre-answered lookups (local tests script DNS here; an integrator MAY
	 * pin answers for an exotic resolver setup). Whatever this hook returns
	 * is still public-checked below — it can NARROW the answer, never
	 * whitelist a loopback/private address — and an empty answer fails
	 * closed like an unresolvable host. Literal IPs (checked above) can
	 * never be influenced by it.
	 */
	$hal_mcp_pre = apply_filters( 'hal_mcp_http_dns_answers', null, $host );

	if ( is_array( $hal_mcp_pre ) ) {
		$hal_mcp_pre = array_values( array_unique( array_filter( array_map( 'strval', $hal_mcp_pre ) ) ) );

		if ( [] === $hal_mcp_pre ) {
			return new WP_Error(
				'hal_mcp_http_endpoint_unresolvable',
				__( 'The endpoint host could not be resolved to an address; refusing instead of guessing.', 'hal-mcp' )
			);
		}

		return $hal_mcp_pre;
	}

	$hal_mcp_ips = [];

	if ( function_exists( 'gethostbynamel' ) ) {
		$hal_mcp_ips = (array) gethostbynamel( $host );
	}

	if ( [] === $hal_mcp_ips && function_exists( 'dns_get_record' ) ) {
		$hal_mcp_aaaa = @dns_get_record( $host, DNS_AAAA );

		foreach ( (array) $hal_mcp_aaaa as $hal_mcp_record ) {
			if ( ! empty( $hal_mcp_record['ipv6'] ) ) {
				$hal_mcp_ips[] = (string) $hal_mcp_record['ipv6'];
			}
		}
	}

	$hal_mcp_ips = array_values( array_unique( array_filter( array_map( 'strval', $hal_mcp_ips ) ) ) );

	if ( [] === $hal_mcp_ips ) {
		return new WP_Error(
			'hal_mcp_http_endpoint_unresolvable',
			__( 'The endpoint host could not be resolved to an address; refusing instead of guessing.', 'hal-mcp' )
		);
	}

	return $hal_mcp_ips;
}

/**
 * Whether one IP address is a public address this transport may contact.
 * Refused ranges: loopback, private, link-local, CGNAT, unspecified, and
 * IPv4-mapped IPv4; on the IPv6 side loopback ::1, the unspecified address,
 * IPv4-compatible ::/96 and NAT64 64:ff9b::/96 (both checked through their
 * embedded IPv4), unique-local fc00::/7, link-local fe80::/10, and multicast
 * ff00::/8 (F16: SSRF/loopback/private/link-local).
 *
 * @param string $ip IPv4 or IPv6 address.
 * @return bool True when public, false when local/reserved/unparseable.
 */
function hal_mcp_http_ip_is_public( string $ip ): bool {

	$ip = trim( $ip );

	if ( '' === $ip ) {
		return false;
	}

	$hal_mcp_binary = @inet_pton( $ip );

	if ( false === $hal_mcp_binary || null === $hal_mcp_binary ) {
		return false;
	}

	// IPv4-mapped IPv6 (::ffff:a.b.c.d) is checked as the embedded IPv4 —
	// a private v4 hidden inside v6 must not pass.
	if ( 16 === strlen( $hal_mcp_binary ) && str_starts_with( $hal_mcp_binary, str_repeat( "\0", 10 ) . "\xff\xff" ) ) {
		$hal_mcp_binary = substr( $hal_mcp_binary, 12 );
	}

	if ( 4 === strlen( $hal_mcp_binary ) ) {
		$hal_mcp_v4 = unpack( 'N', $hal_mcp_binary )[1];

		$hal_mcp_ranges = [
			[ 0x00000000, 0xFF000000 ], // 0.0.0.0/8 "this network"
			[ 0x0A000000, 0xFF000000 ], // 10.0.0.0/8 private
			[ 0x64400000, 0xFFC00000 ], // 100.64.0.0/10 CGNAT
			[ 0x7F000000, 0xFF000000 ], // 127.0.0.0/8 loopback
			[ 0xA9FE0000, 0xFFFF0000 ], // 169.254.0.0/16 link-local
			[ 0xAC100000, 0xFFF00000 ], // 172.16.0.0/12 private
			[ 0xC0A80000, 0xFFFF0000 ], // 192.168.0.0/16 private
			[ 0xE0000000, 0xF0000000 ], // 224.0.0.0/4 multicast
			[ 0xF0000000, 0xF0000000 ], // 240.0.0.0/4 reserved
			[ 0xFFFFFFFF, 0xFFFFFFFF ], // broadcast
		];

		foreach ( $hal_mcp_ranges as [ $hal_mcp_network, $hal_mcp_mask ] ) {
			if ( ( $hal_mcp_v4 & $hal_mcp_mask ) === $hal_mcp_network ) {
				return false;
			}
		}

		return true;
	}

	// IPv6: loopback ::1, unspecified ::, IPv4-compatible ::/96 (e.g.
	// ::127.0.0.1 and its hex form ::7f00:1 — modern kernels treat the
	// embedded address as loopback/private), NAT64 64:ff9b::/96 (checked
	// through its embedded IPv4 the same way), unique-local fc00::/7,
	// link-local fe80::/10, and multicast ff00::/8 are refused.
	if ( str_repeat( "\0", 15 ) === substr( $hal_mcp_binary, 0, 15 ) && "\x01" === substr( $hal_mcp_binary, 15, 1 ) ) {
		return false; // ::1
	}

	if ( str_repeat( "\0", 12 ) === substr( $hal_mcp_binary, 0, 12 ) ) {
		// Inside ::/96 the last 4 bytes ARE an IPv4 address (the all-zero
		// address :: was already refused above) — check it as IPv4.
		return hal_mcp_http_ip_is_public( (string) inet_ntop( substr( $hal_mcp_binary, 12 ) ) );
	}

	if ( "\x00\x64\xff\x9b" === substr( $hal_mcp_binary, 0, 4 ) && str_repeat( "\0", 8 ) === substr( $hal_mcp_binary, 4, 8 ) ) {
		// 64:ff9b::/96 (NAT64) likewise embeds an IPv4 address in its last
		// 4 bytes — route it through the same IPv4 check the ::/96 branch
		// above uses, so 64:ff9b::127.0.0.1 is refused while 64:ff9b::8.8.8.8
		// stays allowed.
		return hal_mcp_http_ip_is_public( (string) inet_ntop( substr( $hal_mcp_binary, 12 ) ) );
	}

	$hal_mcp_first = ord( $hal_mcp_binary[0] );

	if ( ( $hal_mcp_first & 0xFE ) === 0xFC ) {
		return false; // fc00::/7 unique-local
	}

	if ( ( $hal_mcp_first & 0xFF ) === 0xFE && ( ord( $hal_mcp_binary[1] ) & 0xC0 ) === 0x80 ) {
		return false; // fe80::/10 link-local
	}

	if ( ( $hal_mcp_first & 0xFF ) === 0xFF ) {
		return false; // ff00::/8 multicast
	}

	if ( str_starts_with( $hal_mcp_binary, "\x20\x02" ) || str_starts_with( $hal_mcp_binary, "\x20\x01\x00\x00" ) ) {
		// 2002::/16 (6to4) and 2001::/32 (Teredo) embed IPv4 tunnels; they
		// are globally routable in principle — allowed, as for any public IP.
		return true;
	}

	return true;
}

/**
 * POSTs a JSON document to a provider endpoint and returns the parsed
 * envelope. The single transport entry providers.php uses.
 *
 * @param string $url  Endpoint URL (validated again here — callers cannot
 *                     bypass the checks by skipping their own).
 * @param array  $args {
 *     @type array $headers            Extra headers (auth). Content-Type/Accept
 *                                     are set here; callers never override them.
 *     @type array $body               JSON-encodable request body.
 *     @type float $timeout            Seconds (capped at HAL_MCP_HTTP_MAX_TIMEOUT_SECONDS).
 *     @type int   $max_response_bytes Response cap (capped at HAL_MCP_HTTP_MAX_RESPONSE_BYTES).
 * }
 * @return array|WP_Error {
 *     status: int, headers: array, body: string, json: array|null, duration: float
 * } — `json` is null when the body is not JSON (callers decide whether that
 * is acceptable; this transport reports it, it does not interpret it).
 */
function hal_mcp_http_post_json( string $url, array $args = [] ) {
	return hal_mcp_http_request( 'POST', $url, $args );
}

/**
 * GETs a provider endpoint (model listing, connection probes). Same envelope
 * and guards as the POST path.
 *
 * @param string $url  Endpoint URL.
 * @param array  $args Same shape as hal_mcp_http_post_json() minus body.
 * @return array|WP_Error
 */
function hal_mcp_http_get_json( string $url, array $args = [] ) {
	unset( $args['body'] );

	return hal_mcp_http_request( 'GET', $url, $args );
}

/**
 * The shared request pipeline behind the POST/GET wrappers.
 *
 * @param string $method HTTP method ('POST' or 'GET').
 * @param string $url    Endpoint URL.
 * @param array  $args   See hal_mcp_http_post_json().
 * @return array|WP_Error
 */
function hal_mcp_http_request( string $method, string $url, array $args = [] ) {

	$hal_mcp_checked = hal_mcp_http_validate_endpoint( $url );

	if ( is_wp_error( $hal_mcp_checked ) ) {
		return $hal_mcp_checked;
	}

	$hal_mcp_timeout = isset( $args['timeout'] ) ? (float) $args['timeout'] : 30.0;
	$hal_mcp_timeout = min( HAL_MCP_HTTP_MAX_TIMEOUT_SECONDS, max( 1.0, $hal_mcp_timeout ) );

	$hal_mcp_max_bytes = isset( $args['max_response_bytes'] ) ? (int) $args['max_response_bytes'] : HAL_MCP_HTTP_MAX_RESPONSE_BYTES;
	$hal_mcp_max_bytes = min( HAL_MCP_HTTP_MAX_RESPONSE_BYTES, max( 1024, $hal_mcp_max_bytes ) );

	$hal_mcp_headers = array_merge(
		[ 'Accept' => 'application/json' ],
		(array) ( $args['headers'] ?? [] )
	);

	$hal_mcp_body   = null;
	$hal_mcp_encode = null;

	if ( 'POST' === $method ) {
		$hal_mcp_headers['Content-Type'] = 'application/json';
		$hal_mcp_body                    = wp_json_encode( $args['body'] ?? [] );

		if ( false === $hal_mcp_body || null === $hal_mcp_body ) {
			return new WP_Error( 'hal_mcp_http_request_unencodable', __( 'The request body could not be encoded as JSON.', 'hal-mcp' ) );
		}

		$hal_mcp_encode = $hal_mcp_body;
	}

	$hal_mcp_started = microtime( true );

	$hal_mcp_response = 'POST' === $method
		? wp_safe_remote_post(
			$hal_mcp_checked,
			[
				'timeout'     => $hal_mcp_timeout,
				'redirection' => 0,
				'sslverify'   => true,
				'headers'     => $hal_mcp_headers,
				'body'        => $hal_mcp_encode,
			]
		)
		: wp_safe_remote_get(
			$hal_mcp_checked,
			[
				'timeout'     => $hal_mcp_timeout,
				'redirection' => 0,
				'sslverify'   => true,
				'headers'     => $hal_mcp_headers,
			]
		);

	$hal_mcp_duration = microtime( true ) - $hal_mcp_started;

	if ( is_wp_error( $hal_mcp_response ) ) {
		hal_mcp_http_log_transport_error( $hal_mcp_checked, $hal_mcp_response->get_error_code(), $hal_mcp_response->get_error_message() );

		// The transport message is safe (WordPress-generated), but keep the
		// redaction habit anyway — an endpoint URL could echo into some
		// wrapper messages.
		return new WP_Error(
			'hal_mcp_http_transport_failed',
			sprintf(
				/* translators: 1: HTTP method, 2: underlying WordPress transport error message (redacted). */
				__( 'The %1$s request to the provider failed before a response arrived: %2$s', 'hal-mcp' ),
				$method,
				hal_mcp_redact_for_audit_log( $hal_mcp_response->get_error_message() )
			)
		);
	}

	$hal_mcp_status = (int) wp_remote_retrieve_response_code( $hal_mcp_response );
	$hal_mcp_raw    = (string) wp_remote_retrieve_body( $hal_mcp_response );
	$hal_mcp_rh     = (array) wp_remote_retrieve_headers( $hal_mcp_response );

	// Size guard, both from the announced length and the actual body.
	$hal_mcp_declared = (int) ( $hal_mcp_rh['content-length'] ?? 0 );

	if ( $hal_mcp_declared > $hal_mcp_max_bytes || strlen( $hal_mcp_raw ) > $hal_mcp_max_bytes ) {
		hal_mcp_http_log_transport_error( $hal_mcp_checked, 'response_too_large', sprintf( 'status %d', $hal_mcp_status ) );

		return new WP_Error(
			'hal_mcp_http_response_too_large',
			__( 'The provider response exceeded the accepted size limit and was discarded.', 'hal-mcp' )
		);
	}

	if ( $hal_mcp_status < 200 || $hal_mcp_status >= 300 ) {
		return hal_mcp_http_status_error( $method, $hal_mcp_status, $hal_mcp_raw );
	}

	$hal_mcp_json = null;

	if ( '' !== trim( $hal_mcp_raw ) ) {
		$hal_mcp_decoded = json_decode( $hal_mcp_raw, true );

		if ( is_array( $hal_mcp_decoded ) ) {
			$hal_mcp_json = $hal_mcp_decoded;
		} else {
			// Malformed JSON on a 2xx response: providers occasionally
			// return HTML error pages with 200. Report honestly; the
			// caller decides what the protocol allows.
			return new WP_Error(
				'hal_mcp_http_bad_json',
				sprintf(
					/* translators: 1: truncated, redacted response preview. */
					__( 'The provider returned a non-JSON body on a successful status: %s', 'hal-mcp' ),
					hal_mcp_http_body_preview( $hal_mcp_raw )
				)
			);
		}
	}

	return [
		'status'   => $hal_mcp_status,
		'headers'  => $hal_mcp_rh,
		'body'     => $hal_mcp_raw,
		'json'     => $hal_mcp_json,
		'duration' => $hal_mcp_duration,
	];
}

/**
 * Maps a non-2xx status to a named transport error, with a redacted body
 * preview so provider error text can be read by an administrator without
 * leaking key-shaped strings.
 *
 * @param string $method HTTP method.
 * @param int    $status Response status code.
 * @param string $body   Raw response body.
 * @return WP_Error
 */
function hal_mcp_http_status_error( string $method, int $status, string $body ): WP_Error {

	$hal_mcp_code = match ( true ) {
		401 === $status || 403 === $status => 'hal_mcp_http_auth',
		429 === $status                    => 'hal_mcp_http_rate_limited',
		$status >= 500                     => 'hal_mcp_http_server_error',
		default                            => 'hal_mcp_http_client_error',
	};

	hal_mcp_http_log_transport_error( 'provider-response', $hal_mcp_code, sprintf( 'status %d (%s)', $status, $method ) );

	$hal_mcp_messages = [
		'hal_mcp_http_auth'           => __( 'The provider rejected the credentials (wrong, missing, or revoked API key).', 'hal-mcp' ),
		'hal_mcp_http_rate_limited'   => __( 'The provider rate-limited this request. Try again later.', 'hal-mcp' ),
		'hal_mcp_http_server_error'   => __( 'The provider had a server error. Try again later.', 'hal-mcp' ),
		'hal_mcp_http_client_error'   => __( 'The provider refused the request.', 'hal-mcp' ),
	];

	$hal_mcp_detail = hal_mcp_http_provider_error_message( $body );

	return new WP_Error(
		$hal_mcp_code,
		trim( $hal_mcp_messages[ $hal_mcp_code ] . ( '' !== $hal_mcp_detail ? ' ' . $hal_mcp_detail : '' ) )
	);
}

/**
 * Masks provider-key-shaped strings — Google 'AIza…' keys and
 * OpenAI/Anthropic 'sk-…'/'sk-ant-…' keys — in text bound for an error
 * message. Defense in depth against unlabeled key echoes from hostile or
 * custom endpoints: the audit-log redactor covers labeled material
 * (Authorization headers, key=value shapes) and its own pattern widening
 * stays F25's; this pass only catches the raw key shapes themselves,
 * wherever a hostile endpoint chose to print them.
 *
 * @param string $text Text about to be embedded in an error message.
 * @return string The text with every key-shaped run replaced by '[redacted]'.
 */
function hal_mcp_http_mask_key_shapes( string $text ): string {

	return (string) preg_replace(
		[
			'/AIza[0-9A-Za-z_\-]{10,}/',
			'/sk-(ant-)?[A-Za-z0-9_\-]{8,}/',
		],
		'[redacted]',
		$text
	);
}

/**
 * Extracts a short, redacted provider error message from a response body,
 * when the body carries one (OpenAI/Anthropic/Gemini families all use an
 * error.message-ish shape). The audit-log redactor runs first, then the
 * shape mask catches unlabeled key echoes before the message is returned.
 *
 * @param string $body Raw response body.
 * @return string '' when nothing usable is found.
 */
function hal_mcp_http_provider_error_message( string $body ): string {

	if ( '' === trim( $body ) ) {
		return '';
	}

	$hal_mcp_decoded = json_decode( $body, true );
	$hal_mcp_message = '';

	if ( is_array( $hal_mcp_decoded ) ) {
		if ( isset( $hal_mcp_decoded['error'] ) ) {
			if ( is_array( $hal_mcp_decoded['error'] ) ) {
				$hal_mcp_message = (string) ( $hal_mcp_decoded['error']['message'] ?? '' );
			} elseif ( is_string( $hal_mcp_decoded['error'] ) ) {
				$hal_mcp_message = $hal_mcp_decoded['error'];
			}
		} elseif ( isset( $hal_mcp_decoded['message'] ) && is_string( $hal_mcp_decoded['message'] ) ) {
			$hal_mcp_message = $hal_mcp_decoded['message'];
		} elseif ( isset( $hal_mcp_decoded['detail'] ) ) {
			$hal_mcp_message = is_array( $hal_mcp_decoded['detail'] )
				? (string) ( $hal_mcp_decoded['detail']['message'] ?? '' )
				: (string) $hal_mcp_decoded['detail'];
		}
	}

	if ( '' === trim( $hal_mcp_message ) ) {
		return '';
	}

	$hal_mcp_message = hal_mcp_redact_for_audit_log( sanitize_text_field( $hal_mcp_message ) );

	// Mask the message BEFORE returning it: the shape pass is defense in
	// depth against key echoes the redactor above leaves unlabeled (its own
	// widening stays F25's).
	$hal_mcp_message = hal_mcp_http_mask_key_shapes( $hal_mcp_message );

	if ( strlen( $hal_mcp_message ) > HAL_MCP_HTTP_ERROR_PREVIEW_CHARS ) {
		$hal_mcp_message = substr( $hal_mcp_message, 0, HAL_MCP_HTTP_ERROR_PREVIEW_CHARS - 1 ) . '…';
	}

	return sprintf(
		/* translators: %s: provider error message, redacted. */
		__( 'The provider said: %s', 'hal-mcp' ),
		$hal_mcp_message
	);
}

/**
 * Builds a truncated, redacted body preview for error messages. The
 * audit-log redactor runs first, then the key-shape mask catches unlabeled
 * key echoes the redactor leaves behind (its own widening stays F25's) —
 * defense in depth, never a second authority.
 *
 * @param string $body Raw body.
 * @return string
 */
function hal_mcp_http_body_preview( string $body ): string {

	$body = hal_mcp_redact_for_audit_log( $body );
	$body = hal_mcp_http_mask_key_shapes( $body );

	if ( strlen( $body ) > HAL_MCP_HTTP_ERROR_PREVIEW_CHARS ) {
		$body = substr( $body, 0, HAL_MCP_HTTP_ERROR_PREVIEW_CHARS - 1 ) . '…';
	}

	return $body;
}

/**
 * Records one transport failure as an audit control event (F06/F16: تنقيح
 * السجلات). Logs the failure KIND and the endpoint host — never headers,
 * never the request body, never a full URL with credentials (there are none,
 * but the habit holds the boundary).
 *
 * @param string $endpoint_or_method Endpoint URL (host extracted) or method name.
 * @param string $code               Error code/kind.
 * @param string $detail             Short detail line (already non-sensitive).
 * @return void
 */
function hal_mcp_http_log_transport_error( string $endpoint_or_method, string $code, string $detail ): void {

	$hal_mcp_host = $endpoint_or_method;

	if ( str_contains( $endpoint_or_method, '://' ) ) {
		$hal_mcp_parts = hal_mcp_http_url_parts( $endpoint_or_method );
		$hal_mcp_host  = null !== $hal_mcp_parts ? $hal_mcp_parts['host'] : '(unparsed)';
	}

	// Guarded for standalone test loading; in production audit-log.php always
	// loads first.
	if ( ! function_exists( 'hal_mcp_log_control_event' ) ) {
		return;
	}

	hal_mcp_log_control_event(
		[
			'operation'      => 'provider-http',
			'stage'          => 'http_error',
			'success'        => false,
			'result_summary' => sprintf( '%s on %s: %s', $code, $hal_mcp_host, $detail ),
		]
	);
}

// ---------------------------------------------------------------------------
// URL helpers (thin, stub-friendly wrappers around PHP parsing)
// ---------------------------------------------------------------------------

/**
 * Returns the scheme of a URL ('' when unparseable).
 *
 * @param string $url URL.
 * @return string
 */
function hal_mcp_http_url_scheme( string $url ): string {

	$hal_mcp_scheme = parse_url( $url, PHP_URL_SCHEME );

	return is_string( $hal_mcp_scheme ) ? strtolower( $hal_mcp_scheme ) : '';
}

/**
 * Parses the URL parts the transport checks. Returns null when the URL is
 * structurally unusable.
 *
 * @param string $url URL.
 * @return array{host: string, user: string, pass: string, port: int}|null
 */
function hal_mcp_http_url_parts( string $url ): ?array {

	$hal_mcp_parts = parse_url( $url );

	if ( ! is_array( $hal_mcp_parts ) || empty( $hal_mcp_parts['host'] ) ) {
		return null;
	}

	return [
		'host' => strtolower( (string) $hal_mcp_parts['host'] ),
		'user' => isset( $hal_mcp_parts['user'] ) ? (string) $hal_mcp_parts['user'] : '',
		'pass' => isset( $hal_mcp_parts['pass'] ) ? (string) $hal_mcp_parts['pass'] : '',
		'port' => isset( $hal_mcp_parts['port'] ) ? (int) $hal_mcp_parts['port'] : 443,
	];
}
