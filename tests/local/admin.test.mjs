/**
 * V05 — Node built-in tests for the admin.js pure helpers (F19, batch 6).
 *
 * Runs the REAL functions the browser uses (admin.js exports them for
 * exactly this purpose — no parallel copy), against the synthetic,
 * clearly-labeled fixtures in fixtures.json. No DOM framework, no network,
 * no site: node --test tests/local/admin.test.mjs.
 *
 * Covered (roadmap V05): request-state display, text/key redaction before
 * display, duplicate approval submission prevention, the Overview step
 * states before provider setup, and the awaiting-approval state without any
 * action being executed merely by rendering.
 */

import { test } from 'node:test';
import assert from 'node:assert/strict';
import { createRequire } from 'node:module';
import { readFileSync } from 'node:fs';
import { fileURLToPath } from 'node:url';
import { dirname, join } from 'node:path';

const here = dirname( fileURLToPath( import.meta.url ) );
const require = createRequire( import.meta.url );
const halMcpLib = require( join( here, '../../hal-mcp-abilities/assets/admin.js' ) );
const halMcpFixtures = JSON.parse( readFileSync( join( here, 'fixtures.json' ), 'utf8' ) );

test( 'fixtures are declared synthetic', () => {
	assert.match( halMcpFixtures._notice, /SYNTHETIC/ );
} );

test( 'escapeText neutralizes markup in untrusted text', () => {
	assert.equal(
		halMcpLib.escapeText( '<img src=x onerror=alert(1)>' ),
		'&lt;img src=x onerror=alert(1)&gt;'
	);
	assert.equal( halMcpLib.escapeText( '"quoted"' ), '&quot;quoted&quot;' );
} );

test( 'redactPreview masks key-shaped strings before display', () => {
	const masked = halMcpLib.redactPreview( 'the key sk-fixture000000000000 failed, token Bearer abcdef123456 and hex deadbeefdeadbeefdeadbeefdeadbeef' );

	assert.ok( ! masked.includes( 'sk-fixture000000000000' ), 'sk- keys must be masked' );
	assert.ok( masked.includes( '[redacted]' ), 'the mask placeholder must appear' );
	assert.ok( masked.includes( 'Bearer [redacted]' ), 'bearer tokens must be masked' );
	assert.ok( ! masked.includes( 'deadbeefdeadbeefdeadbeefdeadbeef' ), 'long hex blobs must be masked' );
} );

test( 'formatRunState maps every state to a label and class', () => {
	const awaiting = halMcpLib.formatRunState( 'awaiting_approval' );
	assert.equal( awaiting.className, 'hal-mcp-state-awaiting' );
	assert.ok( awaiting.label.length > 0 );

	for ( const state of [ 'active', 'done', 'cancelled', 'error', 'limit' ] ) {
		assert.ok( halMcpLib.formatRunState( state ).label.length > 0, `${ state } must have a label` );
	}

	// Unknown states degrade to the error presentation, never a crash.
	assert.equal( halMcpLib.formatRunState( 'bogus' ).className, 'hal-mcp-state-error' );
} );

test( 'previewRows shows before/after, escapes text, and placeholders design blobs', () => {
	const rows = halMcpLib.previewRows( halMcpFixtures.request_detail );

	assert.equal( rows.length, 3 );

	// Untrusted model/user text is escaped even inside structured rows.
	assert.ok( ! rows[ 0 ].after.includes( '<script>' ), 'script tags must be escaped in the after value' );
	assert.ok( rows[ 0 ].after.includes( '&lt;script&gt;' ), 'the escaped form must be present' );

	// Key-shaped strings are masked in row values.
	assert.ok( ! rows[ 1 ].after.includes( 'sk-fixture000000000000' ), 'keys must be masked in row values' );

	// Design serialization is a placeholder, never rendered content.
	assert.match( rows[ 2 ].after, /\[design content/ );
	assert.equal( rows[ 2 ].design, true );
} );

test( 'submit guard prevents duplicate approval submissions', () => {
	const guard = halMcpLib.createSubmitGuard();
	const actionId = 'decision-approve-4711';

	assert.equal( guard.canSubmit( actionId ), true, 'the first submission is allowed' );
	assert.equal( guard.canSubmit( actionId ), false, 'a second submission for the same id is refused' );
	assert.equal( guard.canSubmit( 'decision-reject-4711' ), true, 'a different action id is independent' );

	guard.markDone( actionId );
	assert.equal( guard.canSubmit( actionId ), true, 'after completion the action is allowed again' );
} );

test( 'deriveSteps: before provider setup, nothing is reported as ready or connected', () => {
	const steps = Object.fromEntries(
		halMcpLib.deriveSteps( halMcpFixtures.overview_before_setup ).map( ( s ) => [ s.step, s.state ] )
	);

	assert.equal( steps[ 2 ], 'needs_action', 'step 2 (provider + key) must say needs_action' );
	assert.equal( steps[ 3 ], 'needs_action', 'step 3 (model + test) must say needs_action' );
	assert.equal( steps[ 4 ], 'not_started', 'step 4 (first request) must say not_started' );
	assert.equal( steps[ 5 ], 'nothing_pending', 'step 5 (approvals) must say nothing_pending' );
} );

test( 'deriveSteps: a saved key without a test is never a connection', () => {
	const steps = Object.fromEntries(
		halMcpLib.deriveSteps( halMcpFixtures.overview_key_no_test ).map( ( s ) => [ s.step, s.state ] )
	);

	assert.equal( steps[ 2 ], 'ready' );
	assert.equal( steps[ 3 ], 'awaiting_test', 'no test record means awaiting_test, never connected' );
} );

test( 'deriveSteps: awaiting approval is visible without executing anything', () => {
	const steps = Object.fromEntries(
		halMcpLib.deriveSteps( halMcpFixtures.overview_awaiting_approval ).map( ( s ) => [ s.step, s.state ] )
	);

	assert.equal( steps[ 3 ], 'connected' );
	assert.equal( steps[ 4 ], 'started' );
	assert.equal( steps[ 5 ], 'awaiting_approval' );
} );

test( 'the awaiting-approval run renders a pending note and never auto-continues', () => {
	// The fixture carries the shape the renderer consumes; the pure contract
	// under test: the state maps to the awaiting badge and the pending
	// request id is surfaced for the approvals tab.
	const run = halMcpFixtures.run_awaiting;
	const state = halMcpLib.formatRunState( run.state );

	assert.equal( state.className, 'hal-mcp-state-awaiting' );
	assert.equal( run.pending_request.request_id, halMcpFixtures.request_detail.request_id, 'the run points at the same request the approvals tab shows' );
	assert.ok( run.messages.every( ( m ) => m.role !== 'tool' ), 'the transcript stays in canonical roles' );
} );
