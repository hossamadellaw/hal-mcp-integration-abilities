/**
 * hal-mcp-abilities — admin screen interaction (F19).
 *
 * Structure: the pure helpers (state formatting, preview rows, text
 * redaction, duplicate-submit guard, overview step derivation) are exposed
 * BOTH to the browser (window.HalMcpLib) and to the Node test runner
 * (module.exports) — tests/local/admin.test.mjs (V05) exercises exactly the
 * functions the browser uses, not a parallel copy.
 *
 * Contract points (F19):
 * - No API keys and no permission logic live here: the REST nonce comes from
 *   the server-injected window.HAL_MCP_ADMIN config; every decision is made
 *   and enforced server-side. This file only transports and renders.
 * - Model/user text is rendered as TEXT (textContent), never as HTML — the
 *   pure helpers additionally provide escaped forms for the few structured
 *   spots, and key-shaped strings are masked before display.
 * - A decision button cannot be double-submitted: the guard disables it for
 *   the same request id until a response lands.
 * - Nothing runs automatically: the connection test, the environment
 *   refresh, and model loading happen only on explicit clicks. Loading the
 *   Approvals tab never executes any action on any request.
 */

( function () {
	'use strict';

	// -----------------------------------------------------------------
	// Pure helpers (browser + Node tests)
	// -----------------------------------------------------------------

	/**
	 * Escapes untrusted text for innerHTML contexts. The transcript and
	 * detail rendering uses textContent wherever possible; this is the
	 * belt-and-braces escape for composed fragments.
	 *
	 * @param {string} text Untrusted text.
	 * @return {string} Escaped text.
	 */
	function halMcpEscapeText( text ) {
		return String( text )
			.replace( /&/g, '&amp;' )
			.replace( /</g, '&lt;' )
			.replace( />/g, '&gt;' )
			.replace( /"/g, '&quot;' )
			.replace( /'/g, '&#039;' );
	}

	/**
	 * Masks key-shaped strings inside untrusted text before it is shown:
	 * sk-... keys, Bearer tokens, and long hex/base64 blobs become
	 * "[redacted]". The server already redacts audit output; this guards the
	 * model/provider text paths too.
	 *
	 * @param {string} text Untrusted text.
	 * @return {string} Masked text.
	 */
	function halMcpRedactPreview( text ) {
		return String( text )
			.replace( /sk-[A-Za-z0-9_-]{8,}/g, '[redacted]' )
			.replace( /Bearer\s+[A-Za-z0-9._-]{8,}/gi, 'Bearer [redacted]' )
			.replace( /\b[A-Fa-f0-9]{32,}\b/g, '[redacted]' );
	}

	/**
	 * One translatable UI string from the server-injected l10n map, with the
	 * English literal as the fallback (the pure helpers must render identically
	 * under the Node test runner, where no window.HAL_MCP_ADMIN exists).
	 *
	 * @param {string} key      L10n key (see the 'l10n' map in admin.php).
	 * @param {string} fallback English literal.
	 * @return {string} The translated or fallback string.
	 */
	function halMcpL10nLabel( key, fallback ) {
		var halMcpText = halMcpConfig && halMcpConfig.l10n ? halMcpConfig.l10n[ key ] : '';

		return typeof halMcpText === 'string' && halMcpText !== '' ? halMcpText : fallback;
	}

	/**
	 * Maps a run state to its display label and CSS class. Labels come from
	 * the server-injected l10n map when present, falling back to the English
	 * literals.
	 *
	 * @param {string} state Run state.
	 * @return {{label: string, className: string}} Display data.
	 */
	function halMcpFormatRunState( state ) {
		var halMcpStates = {
			active: { label: halMcpL10nLabel( 'runActive', 'running' ), className: 'hal-mcp-state-active' },
			awaiting_approval: { label: halMcpL10nLabel( 'runAwaitingApproval', 'waiting for approval' ), className: 'hal-mcp-state-awaiting' },
			done: { label: halMcpL10nLabel( 'runDone', 'done' ), className: 'hal-mcp-state-done' },
			cancelled: { label: halMcpL10nLabel( 'runCancelled', 'cancelled' ), className: 'hal-mcp-state-cancelled' },
			error: { label: halMcpL10nLabel( 'runError', 'stopped with an error' ), className: 'hal-mcp-state-error' },
			limit: { label: halMcpL10nLabel( 'runLimit', 'paused at its limit' ), className: 'hal-mcp-state-limit' },
		};

		return halMcpStates[ state ] || { label: String( state ), className: 'hal-mcp-state-error' };
	}

	/**
	 * Builds the before/after rows for one request detail. Only fields that
	 * appear in the payload are shown; the serialization/design blob is
	 * reduced to a placeholder — design content is previewed in the real
	 * editor, never rendered from the request panel.
	 *
	 * @param {Object} request The /approvals/{id} response detail.
	 * @return {Array<{field: string, before: string, after: string, design: boolean}>} Rows.
	 */
	function halMcpPreviewRows( request ) {
		var halMcpRows = [];
		var halMcpFields = ( request && request.rows ) || [];

		halMcpFields.forEach( function ( row ) {
			var halMcpDesign = Boolean( row.is_design );

			// Values are escaped here, not only at render time: every
			// consumer of these rows (textContent now, any structured
			// context later) receives display-safe text.
			halMcpRows.push( {
				field: halMcpEscapeText( row.field ),
				before: halMcpDesign ? '[design content — preview it in the editor]' : halMcpEscapeText( halMcpRedactPreview( row.before || '' ) ),
				after: halMcpDesign ? '[design content — preview it in the editor]' : halMcpEscapeText( halMcpRedactPreview( row.after || '' ) ),
				design: halMcpDesign,
			} );
		} );

		return halMcpRows;
	}

	/**
	 * Duplicate-submit guard for decision buttons: one in-flight submission
	 * per action id; a second call is refused until the first completes.
	 *
	 * @return {{canSubmit: Function, markDone: Function}} Guard.
	 */
	function halMcpCreateSubmitGuard() {
		var halMcpPending = {};

		return {
			/**
			 * Whether this action may start now.
			 *
			 * @param {string} actionId Unique action id.
			 * @return {boolean} True once per id until markDone.
			 */
			canSubmit: function ( actionId ) {
				if ( halMcpPending[ actionId ] ) {
					return false;
				}

				halMcpPending[ actionId ] = true;

				return true;
			},
			/**
			 * Releases the action (after success or failure).
			 *
			 * @param {string} actionId Unique action id.
			 * @return {void}
			 */
			markDone: function ( actionId ) {
				delete halMcpPending[ actionId ];
			},
		};
	}

	/**
	 * Derives the Overview step states from existing data only — a saved key
	 * is never reported as a connection, and a step with no data says so.
	 *
	 * @param {Object} data { hasProvider, hasKey, hasModel, test: {ok,tested_at}|null, hasRuns, pendingCount }.
	 * @return {Array<{step: number, state: string}>} Step states.
	 */
	function halMcpDeriveSteps( data ) {
		var halMcpTest = data.test || null;
		var halMcpTested = Boolean( halMcpTest && halMcpTest.ok );

		return [
			{ step: 2, state: data.hasProvider && data.hasKey ? 'ready' : 'needs_action' },
			{
				step: 3,
				state: ! ( data.hasProvider && data.hasModel )
					? 'needs_action'
					: ( halMcpTested ? 'connected' : 'awaiting_test' ),
			},
			{ step: 4, state: data.hasRuns ? 'started' : 'not_started' },
			{ step: 5, state: data.pendingCount > 0 ? 'awaiting_approval' : 'nothing_pending' },
		];
	}

	var halMcpLib = {
		escapeText: halMcpEscapeText,
		redactPreview: halMcpRedactPreview,
		formatRunState: halMcpFormatRunState,
		previewRows: halMcpPreviewRows,
		createSubmitGuard: halMcpCreateSubmitGuard,
		deriveSteps: halMcpDeriveSteps,
	};

	if ( typeof module !== 'undefined' && module.exports ) {
		module.exports = halMcpLib;

		return;
	}

	window.HalMcpLib = halMcpLib;

	// -----------------------------------------------------------------
	// Browser wiring (inert without the server-injected config)
	// -----------------------------------------------------------------

	var halMcpConfig = window.HAL_MCP_ADMIN || null;
	var halMcpGuard = halMcpCreateSubmitGuard();
	var halMcpRunId = 0;

	/**
	 * Shows/hides the resume and cancel buttons for a run state. Resume is
	 * offered for resumable states (limit / error / awaiting_approval — the
	 * server refuses it while its request is still pending, with the
	 * reason); cancel for every non-finished state.
	 *
	 * @param {Object} run Run data (or null to hide both).
	 * @return {void}
	 */
	function halMcpUpdateRunButtons( run ) {
		var halMcpResume = document.querySelector( '[data-hal-action="resume"]' );
		var halMcpCancel = document.querySelector( '[data-hal-action="cancel"]' );

		if ( ! halMcpResume || ! halMcpCancel ) {
			return;
		}

		if ( ! run ) {
			halMcpResume.hidden = true;
			halMcpCancel.hidden = true;
			return;
		}

		halMcpRunId = run.run_id || halMcpRunId;

		var halMcpState = run.state || '';

		halMcpResume.hidden = ! [ 'limit', 'error', 'awaiting_approval' ].includes( halMcpState );
		halMcpCancel.hidden = [ 'done', 'cancelled' ].includes( halMcpState );
	}

	/**
	 * Fetch helper: JSON POST/GET to this plugin's REST routes with the
	 * session nonce. Rejects with the server message when present.
	 *
	 * @param {string} path   Route path after the namespace.
	 * @param {Object} options { method, body }.
	 * @return {Promise<Object>} Parsed JSON.
	 */
	function halMcpApi( path, options ) {
		if ( ! halMcpConfig ) {
			return Promise.reject( { message: 'the admin bridge has no configuration on this screen.' } );
		}

		var halMcpOpts = {
			method: ( options && options.method ) || 'GET',
			credentials: 'same-origin',
			headers: { 'X-WP-Nonce': halMcpConfig.nonce },
		};

		if ( options && options.body ) {
			halMcpOpts.headers[ 'Content-Type' ] = 'application/json';
			halMcpOpts.body = JSON.stringify( options.body );
		}

		return window.fetch( halMcpConfig.restUrl + path, halMcpOpts )
			.then( function ( response ) {
				return response.json().then( function ( json ) {
					if ( ! response.ok ) {
						return Promise.reject( { message: json && json.message ? json.message : 'the request was refused.' } );
					}

					return json;
				} );
			} );
	}

	/**
	 * Renders the transcript rows from a run response.
	 *
	 * @param {Object} run Run data (messages included).
	 * @param {HTMLElement} container Target.
	 * @return {void}
	 */
	function halMcpRenderRun( run, container ) {
		while ( container.firstChild ) {
			container.removeChild( container.firstChild );
		}

		if ( ! run || ! run.messages ) {
			return;
		}

		var halMcpState = halMcpFormatRunState( run.state );
		var halMcpHeading = document.createElement( 'p' );
		halMcpHeading.className = 'hal-mcp-run-state ' + halMcpState.className;
		halMcpHeading.textContent = '#' + run.run_id + ' — ' + halMcpState.label;
		container.appendChild( halMcpHeading );

		run.messages.forEach( function ( message ) {
			var halMcpBlock = document.createElement( 'div' );
			halMcpBlock.className = 'hal-mcp-message hal-mcp-message-' + message.role;

			if ( message.text ) {
				var halMcpText = document.createElement( 'p' );
				halMcpText.textContent = halMcpRedactPreview( message.text );
				halMcpBlock.appendChild( halMcpText );
			}

			( message.tool_calls || [] ).forEach( function ( call ) {
				var halMcpLine = document.createElement( 'p' );
				halMcpLine.className = 'hal-mcp-tool-line';

				if ( typeof call.is_error === 'boolean' ) {
					halMcpLine.textContent = ( call.is_error ? '✗ ' : '✓ ' ) + call.name + ( call.truncated ? ' (result truncated)' : '' );
				} else {
					halMcpLine.textContent = '→ ' + call.name;
				}

				halMcpBlock.appendChild( halMcpLine );
			} );

			container.appendChild( halMcpBlock );
		} );

		if ( run.pending_request && run.pending_request.request_id ) {
			var halMcpPending = document.createElement( 'p' );
			halMcpPending.className = 'hal-mcp-pending-note';
			halMcpPending.textContent = halMcpL10nLabel( 'pendingNote', 'Waiting for approval: request #%s. Open the Approvals tab — the run will not continue by itself.' ).replace( '%s', String( run.pending_request.request_id ) );
			container.appendChild( halMcpPending );
		}

		halMcpUpdateRunButtons( run );
	}

	document.addEventListener( 'click', function ( event ) {
		var halMcpTarget = event.target.closest ? event.target.closest( '[data-hal-action]' ) : null;

		if ( ! halMcpTarget ) {
			return;
		}

		var halMcpAction = halMcpTarget.getAttribute( 'data-hal-action' );

		if ( 'send' === halMcpAction ) {
			var halMcpInstruction = document.querySelector( '[data-hal-role="instruction"]' );
			var halMcpProvider = document.querySelector( '[data-hal-role="provider"]' );
			var halMcpModel = document.querySelector( '[data-hal-role="model"]' );
			var halMcpTranscript = document.querySelector( '[data-hal-role="transcript"]' );
			var halMcpStatus = document.querySelector( '[data-hal-role="chat-status"]' );

			if ( ! halMcpInstruction || ! halMcpTranscript ) {
				return;
			}

			if ( ! halMcpGuard.canSubmit( 'chat' ) ) {
				return;
			}

			halMcpStatus.textContent = halMcpL10nLabel( 'working', 'working…' );

			halMcpApi( 'chat', {
				method: 'POST',
				body: {
					text: halMcpInstruction.value,
					provider_id: halMcpProvider ? halMcpProvider.value : '',
					model: halMcpModel ? halMcpModel.value : '',
				},
			} )
				.then( function ( run ) {
					halMcpRenderRun( run, halMcpTranscript );
					halMcpStatus.textContent = '';
					halMcpGuard.markDone( 'chat' );
				} )
				.catch( function ( error ) {
					halMcpStatus.textContent = error && error.message ? error.message : 'the request failed.';
					halMcpGuard.markDone( 'chat' );
				} );
		}

		if ( 'approve' === halMcpAction || 'reject' === halMcpAction || 'apply' === halMcpAction ) {
			var halMcpRequestId = halMcpTarget.getAttribute( 'data-request-id' );

			if ( ! halMcpGuard.canSubmit( 'decision-' + halMcpAction + '-' + halMcpRequestId ) ) {
				return;
			}

			halMcpApi( 'approvals/' + encodeURIComponent( halMcpRequestId ) + '/' + halMcpAction, { method: 'POST' } )
				.then( function ( result ) {
					halMcpTarget.insertAdjacentHTML( 'afterend', ' <em>' + halMcpEscapeText( 'state: ' + ( result.state || 'unknown' ) ) + '</em>' );
					halMcpGuard.markDone( 'decision-' + halMcpAction + '-' + halMcpRequestId );
				} )
				.catch( function ( error ) {
					halMcpTarget.insertAdjacentHTML( 'afterend', ' <em class="hal-mcp-error">' + halMcpEscapeText( error && error.message ? error.message : 'refused.' ) + '</em>' );
					halMcpGuard.markDone( 'decision-' + halMcpAction + '-' + halMcpRequestId );
				} );
		}

		if ( 'open-request' === halMcpAction ) {
			var halMcpDetail = document.querySelector( '[data-hal-role="request-detail"]' );

			if ( ! halMcpDetail ) {
				return;
			}

			halMcpApi( 'approvals/' + encodeURIComponent( halMcpTarget.getAttribute( 'data-request-id' ) ) )
				.then( function ( detail ) {
					while ( halMcpDetail.firstChild ) {
						halMcpDetail.removeChild( halMcpDetail.firstChild );
					}

					var halMcpTitle = document.createElement( 'h3' );
					halMcpTitle.textContent = '#' + detail.request_id + ' — ' + detail.operation;
					halMcpDetail.appendChild( halMcpTitle );

					halMcpPreviewRows( detail ).forEach( function ( row ) {
						var halMcpRow = document.createElement( 'div' );
						halMcpRow.className = 'hal-mcp-diff-row';

						var halMcpField = document.createElement( 'strong' );
						halMcpField.textContent = row.field;
						halMcpRow.appendChild( halMcpField );

						var halMcpBefore = document.createElement( 'div' );
						halMcpBefore.className = 'hal-mcp-diff-before';
						halMcpBefore.textContent = row.before;
						halMcpRow.appendChild( halMcpBefore );

						var halMcpAfter = document.createElement( 'div' );
						halMcpAfter.className = 'hal-mcp-diff-after';
						halMcpAfter.textContent = row.after;
						halMcpRow.appendChild( halMcpAfter );

						halMcpDetail.appendChild( halMcpRow );
					} );

					// The F21 editor-bridge link (server-built, server-gated):
					// only shown when the request is design-bearing AND the
					// server returned the prepared editor URL. Built as a real
					// anchor — never innerHTML with unescaped values.
					if ( 'needs_editor' === detail.editor_readiness && detail.editor_url ) {
						var halMcpEditorLink = document.createElement( 'a' );
						halMcpEditorLink.href = detail.editor_url;
						halMcpEditorLink.className = 'button button-secondary';
						halMcpEditorLink.textContent = halMcpL10nLabel( 'openEditor', 'Open in the editor to serialize the design' );
						halMcpDetail.appendChild( halMcpEditorLink );
					}

					halMcpDetail.hidden = false;
				} )
				.catch( function ( error ) {
					halMcpDetail.hidden = false;
					halMcpDetail.textContent = error && error.message ? error.message : 'the request could not be read.';
				} );
		}

		if ( 'resume' === halMcpAction ) {
			var halMcpResumeTranscript = document.querySelector( '[data-hal-role="transcript"]' );
			var halMcpResumeStatus = document.querySelector( '[data-hal-role="chat-status"]' );
			var halMcpResumeInstruction = document.querySelector( '[data-hal-role="instruction"]' );

			if ( ! halMcpResumeTranscript || ! halMcpRunId ) {
				return;
			}

			if ( ! halMcpGuard.canSubmit( 'resume-' + halMcpRunId ) ) {
				return;
			}

			if ( halMcpResumeStatus ) {
				halMcpResumeStatus.textContent = halMcpL10nLabel( 'working', 'working…' );
			}

			halMcpApi( 'chat', {
				method: 'POST',
				body: {
					run_id: halMcpRunId,
					text: halMcpResumeInstruction ? halMcpResumeInstruction.value : '',
				},
			} )
				.then( function ( run ) {
					halMcpRenderRun( run, halMcpResumeTranscript );
					if ( halMcpResumeStatus ) {
						halMcpResumeStatus.textContent = '';
					}
					halMcpGuard.markDone( 'resume-' + halMcpRunId );
				} )
				.catch( function ( error ) {
					if ( halMcpResumeStatus ) {
						halMcpResumeStatus.textContent = error && error.message ? error.message : 'the request failed.';
					}
					halMcpGuard.markDone( 'resume-' + halMcpRunId );
				} );
		}

		if ( 'cancel' === halMcpAction ) {
			var halMcpCancelStatus = document.querySelector( '[data-hal-role="chat-status"]' );

			if ( ! halMcpRunId || ! halMcpGuard.canSubmit( 'cancel-' + halMcpRunId ) ) {
				return;
			}

			halMcpApi( 'runs/' + encodeURIComponent( halMcpRunId ) + '/cancel', { method: 'POST' } )
				.then( function ( result ) {
					halMcpUpdateRunButtons( null );
					halMcpGuard.markDone( 'cancel-' + halMcpRunId );

					if ( halMcpCancelStatus ) {
						halMcpCancelStatus.textContent = result.message || halMcpL10nLabel( 'cancelled', 'cancelled.' );
					}

					return halMcpApi( 'runs/' + encodeURIComponent( halMcpRunId ) );
				} )
				.then( function ( run ) {
					var halMcpCancelTranscript = document.querySelector( '[data-hal-role="transcript"]' );

					if ( run && halMcpCancelTranscript ) {
						halMcpRenderRun( run, halMcpCancelTranscript );
					}
				} )
				.catch( function ( error ) {
					halMcpGuard.markDone( 'cancel-' + halMcpRunId );

					if ( halMcpCancelStatus ) {
						halMcpCancelStatus.textContent = error && error.message ? error.message : 'the cancellation failed.';
					}
				} );
		}

		if ( 'open-run' === halMcpAction ) {
			var halMcpRunTranscript = document.querySelector( '[data-hal-role="transcript"]' );

			if ( ! halMcpRunTranscript ) {
				return;
			}

			halMcpApi( 'runs/' + encodeURIComponent( halMcpTarget.getAttribute( 'data-run-id' ) ) )
				.then( function ( run ) {
					halMcpRenderRun( run, halMcpRunTranscript );
				} )
				.catch( function ( error ) {
					halMcpRunTranscript.textContent = error && error.message ? error.message : 'the run could not be read.';
				} );
		}

		if ( 'load-models' === halMcpAction ) {
			var halMcpProviderSelect = document.querySelector( '[data-hal-role="provider"]' );
			var halMcpModelList = document.getElementById( 'hal-mcp-model-list' );

			if ( ! halMcpProviderSelect || ! halMcpModelList ) {
				return;
			}

			halMcpApi( 'providers/' + encodeURIComponent( halMcpProviderSelect.value ) + '/models' )
				.then( function ( result ) {
					while ( halMcpModelList.firstChild ) {
						halMcpModelList.removeChild( halMcpModelList.firstChild );
					}

					( result.models || [] ).forEach( function ( model ) {
						var halMcpOption = document.createElement( 'option' );
						halMcpOption.value = model.id;
						halMcpModelList.appendChild( halMcpOption );
					} );
				} )
				.catch( function () {
					// Manual entry stays available — the list is a convenience,
					// never a requirement.
				} );
		}

		if ( 'refresh-environment' === halMcpAction ) {
			var halMcpRefreshStatus = document.querySelector( '[data-hal-role="refresh-status"]' );

			halMcpApi( 'environment/refresh', { method: 'POST' } )
				.then( function () {
					window.location.reload();
				} )
				.catch( function ( error ) {
					if ( halMcpRefreshStatus ) {
						halMcpRefreshStatus.textContent = error && error.message ? error.message : 'the refresh failed.';
					}
				} );
		}
	} );
}() );
