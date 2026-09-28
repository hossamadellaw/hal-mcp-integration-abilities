/**
 * hal-mcp-abilities — editor bridge (F21).
 *
 * A small, deliberate bridge inside the REAL block editor: it serializes the
 * editor's current content through the editor's own registration and save
 * interfaces (wp.data / wp.blocks) and posts the result to the protected
 * admin route (F19) that validates it server-side
 * (hal_mcp_blocks_ingest_editor_serialization() in integrations/blocks.php).
 *
 * Contract points, all enforced here or server-side:
 * - This bridge is NOT an alternative editor and contains no editor logic:
 *   serialization comes from the running editor, nothing else. No Node
 *   renderer, no markup synthesis.
 * - The bridge is inert until the server injects a configuration
 *   (window.HAL_MCP_EDITOR_BRIDGE_CONFIG with the request id, the target
 *   page id, the request-version fingerprint, and the REST route + nonce).
 *   No config, no action — this file never decides by itself where to POST.
 * - The submission carries the request-version fingerprint it was opened
 *   against; the server refuses a result for a stale or different request
 *   version (hal_mcp_bridge_fingerprint_mismatch). The readiness state is
 *   derived and written SERVER-SIDE only — no value from this file can ever
 *   mark a request ready.
 * - One submission per page load: a second submit() on the same request
 *   version is refused client-side (the server-side payload contract would
 *   reject it anyway; this keeps the UI honest).
 * - The Elementor editor is deliberately NOT bridged here: Elementor's
 *   design source is its own JSON storage, which the server reads and saves
 *   through the PHP integration (integrations/elementor.php). If this file
 *   finds itself inside the Elementor editor without the block editor, it
 *   reports that honestly instead of guessing at Elementor's internal data
 *   model.
 *
 * Enqueued by the F19 admin screen on the request panel only.
 */

( function () {
	'use strict';

	var halMcpConfig = window.HAL_MCP_EDITOR_BRIDGE_CONFIG || null;
	var halMcpSubmitted = false;

	/**
	 * Whether the block editor data APIs this bridge needs are present.
	 *
	 * @return {boolean} True when wp.data/wp.blocks expose the interfaces.
	 */
	function halMcpEditorApisAvailable() {
		return Boolean(
			window.wp &&
			window.wp.data &&
			window.wp.data.select &&
			window.wp.blocks &&
			window.wp.blocks.serialize &&
			typeof window.wp.data.select( 'core/block-editor' ) !== 'undefined' &&
			window.wp.data.select( 'core/block-editor' ) !== null &&
			typeof window.wp.data.select( 'core/block-editor' ).getBlocks === 'function'
		);
	}

	/**
	 * Whether the page is currently inside the Elementor editor (detected,
	 * never assumed).
	 *
	 * @return {boolean}
	 */
	function halMcpIsElementorEditor() {
		return Boolean( window.elementor && window.elementor.config && window.elementor.config.document );
	}

	/**
	 * Serializes the current block-editor content through the editor's own
	 * interfaces.
	 *
	 * @return {Object} { ok, markup?, reason? } — never throws.
	 */
	function halMcpSerialize() {
		try {
			if ( ! halMcpEditorApisAvailable() ) {
				if ( halMcpIsElementorEditor() ) {
					return {
						ok: false,
						reason: 'elementor-editor: this bridge does not serialize Elementor designs; the server reads the Elementor JSON through its own integration.',
					};
				}

				return { ok: false, reason: 'the block editor data APIs are not available on this screen.' };
			}

			var halMcpBlocks = window.wp.data.select( 'core/block-editor' ).getBlocks();

			if ( ! Array.isArray( halMcpBlocks ) || halMcpBlocks.length === 0 ) {
				return { ok: false, reason: 'the editor content is empty; there is nothing to serialize.' };
			}

			return { ok: true, markup: window.wp.blocks.serialize( halMcpBlocks ) };
		} catch ( halMcpError ) {
			return {
				ok: false,
				reason: 'serialization failed inside the editor: ' + ( halMcpError && halMcpError.message ? halMcpError.message : 'unknown error' ),
			};
		}
	}

	/**
	 * Submits the current serialization for the configured request. The
	 * server re-validates target, permission, request-version fingerprint,
	 * and the markup itself; this function only transports.
	 *
	 * @return {Promise<Object>} The server's JSON response, or a
	 *                           { ok: false, reason } object.
	 */
	function halMcpSubmit() {
		if ( ! halMcpConfig ) {
			return Promise.resolve( {
				ok: false,
				reason: 'the bridge has no server-provided configuration on this screen.',
			} );
		}

		if ( halMcpSubmitted ) {
			return Promise.resolve( {
				ok: false,
				reason: 'this request version was already submitted; reopen the request to serialize again.',
			} );
		}

		var halMcpSerialized = halMcpSerialize();

		if ( ! halMcpSerialized.ok ) {
			return Promise.resolve( halMcpSerialized );
		}

		var halMcpBody = {
			request_id: halMcpConfig.requestId,
			request_fingerprint: halMcpConfig.requestFingerprint,
			markup: halMcpSerialized.markup,
		};

		halMcpSubmitted = true;

		return window.fetch( halMcpConfig.restUrl, {
			method: 'POST',
			credentials: 'same-origin',
			headers: {
				'Content-Type': 'application/json',
				'X-WP-Nonce': halMcpConfig.nonce,
			},
			body: JSON.stringify( halMcpBody ),
		} )
			.then( function ( halMcpResponse ) {
				return halMcpResponse.json().then( function ( halMcpJson ) {
					if ( ! halMcpResponse.ok ) {
						return {
							ok: false,
							reason: halMcpJson && halMcpJson.message ? halMcpJson.message : 'the server refused the serialization.',
						};
					}

					return halMcpJson;
				} );
			} )
			.catch( function () {
				halMcpSubmitted = false;

				return { ok: false, reason: 'the submission could not reach the server.' };
			} );
	}

	window.HAL_MCP_EditorBridge = {
		isAvailable: function () {
			return Boolean( halMcpConfig ) && halMcpEditorApisAvailable();
		},
		serialize: halMcpSerialize,
		submit: halMcpSubmit,
	};
}() );
