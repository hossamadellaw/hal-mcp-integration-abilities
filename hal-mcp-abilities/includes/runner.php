<?php
/**
 * hal-mcp-abilities — bounded internal tool-run loop (F18).
 *
 * Drives the internal UI's conversation: one provider call per step, tool
 * calls executed through the Abilities API, state persisted between HTTP
 * requests so a run can stop and resume without repeating completed work.
 *
 * Contract points (roadmap F18):
 * - Small and bounded: a hard cap on provider rounds (cumulative across
 *   resume), on wall-clock time PER SEGMENT (start and every successful
 *   resume begin a fresh segment), on input text length, and on each tool
 *   result's serialized size. No cron, no daemon, no queue — the loop runs
 *   inside one admin request and stops.
 * - Run state lives in the kind=run rows of the F17 store (roadmap §4.3:
 *   "يمكن استعمال kind=run ... دون إضافة مخزن ثان"), persisted and PROVEN
 *   (read back) after every mutation; a state write that cannot be proven
 *   stops the run instead of being claimed.
 * - One run, one executor: start, resume, and cancel take the F17
 *   per-request lock (قفل قصير) as the run's execution lock and release it
 *   on every exit path; a concurrent call on the same row is refused
 *   (hal_mcp_runner_in_progress) instead of double-executing tool rounds.
 * - Tool calls go through wp_get_ability()->execute() — the same validation,
 *   permission, and audit-hook path every MCP client uses. The catalog only
 *   contains this plugin's hal/* abilities flagged mcp.public; no approval
 *   tools, no key tools, no internal-request tools exist to expose.
 * - A tool result that answers pending_approval STOPS the loop: the state
 *   moves to awaiting_approval, what was done so far is reported, and
 *   nothing continues by itself. Resuming without a new instruction is
 *   refused while that request is still pending — the model cannot churn
 *   out duplicate proposals (F18: "لا استمرار ذاتي ينشئ اقتراحات مكررة");
 *   approving/rejecting happens in the approvals tab, never here.
 * - Page content and tool results are UNTRUSTED DATA: they are stored and
 *   transported as opaque strings, never parsed as instructions, and no
 *   permission decision ever reads them (F18: "لا تغيير سياسة الصلاحيات
 *   بتعليمات داخل المحتوى").
 * - Cancelling prevents every subsequent step, and the response says so in
 *   plain words: an already-completed write is NOT reverted by cancelling.
 *
 * @package hal-mcp-abilities
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Hard ceilings (code-owned; the F19 policy settings may lower them, never
 * raise them).
 *
 * @var int
 */
const HAL_MCP_RUNNER_MAX_ROUNDS = 8;

/**
 * Wall-clock budget for ONE segment of a run (start or resume), in seconds.
 * The rounds budget stays cumulative across resume; the clock does not —
 * every start and every successful resume begins a fresh segment (see
 * segment_started_at), so a run is never dead after its first minute.
 *
 * @var int
 */
const HAL_MCP_RUNNER_MAX_SECONDS = 120;

/**
 * Default (policy-lowered) limits.
 *
 * @var int
 */
const HAL_MCP_RUNNER_DEFAULT_ROUNDS = 4;

/**
 * Default wall-clock budget for one segment, in seconds.
 *
 * @var int
 */
const HAL_MCP_RUNNER_DEFAULT_SECONDS = 60;

/**
 * Longest user instruction accepted into a run.
 *
 * @var int
 */
const HAL_MCP_RUNNER_MAX_INPUT_CHARS = 20000;

/**
 * Longest serialized tool result forwarded to the provider.
 *
 * @var int
 */
const HAL_MCP_RUNNER_MAX_RESULT_CHARS = 8000;

/**
 * Longest conversation (canonical messages) one run may accumulate.
 *
 * @var int
 */
const HAL_MCP_RUNNER_MAX_MESSAGES = 100;

/**
 * Run states (the _hal_run meta 'state' key — the F17 _hal_status machine is
 * NOT used for run rows; it stays 'pending' and approve/apply refuse them).
 *
 * @var string[]
 */
const HAL_MCP_RUNNER_STATES = [ 'active', 'awaiting_approval', 'done', 'cancelled', 'error', 'limit' ];

/**
 * Starts a run: creates the kind=run row, seeds the conversation with the
 * user's instruction, and runs tool rounds until the conversation completes,
 * an approval blocks the path, a limit is reached, or an error occurs.
 *
 * After the seed state is proven, the run takes the F17 per-request lock
 * (قفل قصير) as its EXECUTION lock: while the loop runs, the row is owned by
 * this request and every other start/resume/cancel of it is refused —
 * concurrent resumes must never double-execute tool rounds.
 *
 * @param string $provider_id Provider id from the registry.
 * @param string $text        The user's instruction.
 * @param array  $args {
 *     @type string     $model    Model override (settings default otherwise).
 *     @type array|null $config   Full provider config override (tests only;
 *                                production resolves through the F19 settings
 *                                layer, which is where secrets come from).
 * }
 * @return array|WP_Error { run_id, state, messages, pending_request,
 *                          rounds_used, model, provider }
 */
function hal_mcp_runner_start( string $provider_id, string $text, array $args = [] ) {

	$text = trim( $text );

	if ( '' === $text ) {
		return new WP_Error( 'hal_mcp_runner_empty_input', __( 'Write an instruction for the assistant first.', 'hal-mcp' ) );
	}

	if ( strlen( $text ) > HAL_MCP_RUNNER_MAX_INPUT_CHARS ) {
		return new WP_Error(
			'hal_mcp_runner_input_too_long',
			sprintf(
				/* translators: 1: allowed characters, 2: received characters. */
				__( 'The instruction is too long (up to %1$s characters; received %2$s). Shorten it or split it into steps.', 'hal-mcp' ),
				number_format_i18n( HAL_MCP_RUNNER_MAX_INPUT_CHARS ),
				number_format_i18n( strlen( $text ) )
			)
		);
	}

	$hal_mcp_config = hal_mcp_runner_provider_config( $provider_id, (string) ( $args['model'] ?? '' ), $args );

	if ( is_wp_error( $hal_mcp_config ) ) {
		return $hal_mcp_config;
	}

	$hal_mcp_messages = [
		[
			'role' => 'user',
			'text' => $text,
		],
	];

	$hal_mcp_created = hal_mcp_create_change_request(
		[
			'kind'      => 'run',
			'operation' => 'run',
			'payload'   => $hal_mcp_messages,
			'origin'    => [
				'source' => 'runner',
				'model'  => (string) $hal_mcp_config['model'],
				'version' => HAL_MCP_ABILITIES_VERSION,
			],
		]
	);

	if ( is_wp_error( $hal_mcp_created ) ) {
		return $hal_mcp_created;
	}

	$hal_mcp_run_id = (int) $hal_mcp_created['request_id'];

	// The initial run state is persisted and PROVEN before the first provider
	// call — a run row without a stored state must never look active.
	$hal_mcp_seeded = hal_mcp_runner_persist(
		$hal_mcp_run_id,
		[
			'run' => [
				'state'              => 'active',
				'provider'           => (string) $hal_mcp_config['id'],
				'model'              => (string) $hal_mcp_config['model'],
				'protocol'           => (string) $hal_mcp_config['protocol'],
				'rounds_used'        => 0,
				'started_at'         => time(),
				'segment_started_at' => time(),
				'updated_at'         => time(),
				'finished_at'        => 0,
				'last_error'         => '',
				'pending_request'    => null,
			],
			'messages' => $hal_mcp_messages,
		]
	);

	if ( is_wp_error( $hal_mcp_seeded ) ) {
		return $hal_mcp_seeded;
	}

	// The run now takes the F17 per-request lock as its execution lock: from
	// here until the loop exits (whatever the exit state), a concurrent
	// resume/cancel of this row is refused instead of interleaving its own
	// state writes and double-executing rounds. On refusal the seeded row
	// stays resumable — the lock belongs to whoever holds it.
	if ( ! hal_mcp_request_acquire_lock( $hal_mcp_run_id ) ) {
		return new WP_Error(
			'hal_mcp_runner_in_progress',
			__( 'This run is already executing in another request. Wait for it to finish, or cancel it if it is stuck.', 'hal-mcp' )
		);
	}

	hal_mcp_runner_audit( $hal_mcp_run_id, 'run_started', true, sprintf( 'provider %s model %s', $hal_mcp_config['id'], $hal_mcp_config['model'] ) );

	return hal_mcp_runner_loop( $hal_mcp_run_id );
}

/**
 * Resumes a stored run: continues the tool loop with the conversation as it
 * was persisted. With new text, the message is appended first; without, the
 * run continues only when it is resumable (limit/error) or its blocking
 * approval request has left `pending` (approved/applied/rejected).
 *
 * Resume takes the F17 per-request lock BEFORE anything is mutated: while the
 * lock is held (by a start, another resume, or a cancel), this call is
 * refused instead of double-executing the run's tool rounds. The lock is
 * released on EVERY exit path — the refusals below, persist failures, and
 * every loop exit (the loop releases on its own returns).
 *
 * A successful resume also begins a fresh wall-clock segment
 * (segment_started_at), so the time budget measures THIS segment, never the
 * run's total age; the rounds budget stays cumulative.
 *
 * @param int    $run_id Run row id.
 * @param string $text   Optional new user message.
 * @return array|WP_Error Same shape as hal_mcp_runner_start().
 */
function hal_mcp_runner_resume( int $run_id, string $text = '' ) {

	$hal_mcp_run = hal_mcp_runner_load_run( $run_id );

	if ( is_wp_error( $hal_mcp_run ) ) {
		return $hal_mcp_run;
	}

	if ( ! hal_mcp_request_acquire_lock( $run_id ) ) {
		return new WP_Error(
			'hal_mcp_runner_in_progress',
			__( 'This run is already executing in another request. Wait for it to finish, or cancel it if it is stuck.', 'hal-mcp' )
		);
	}

	$hal_mcp_state = (string) $hal_mcp_run['run']['state'];

	if ( in_array( $hal_mcp_state, [ 'done', 'cancelled' ], true ) ) {
		hal_mcp_request_release_lock( $run_id );

		return new WP_Error( 'hal_mcp_runner_finished', __( 'This run is finished; start a new one.', 'hal-mcp' ) );
	}

	if ( 'awaiting_approval' === $hal_mcp_state ) {
		$hal_mcp_pending = (array) ( $hal_mcp_run['run']['pending_request'] ?? [] );
		$hal_mcp_pending_id = (int) ( $hal_mcp_pending['request_id'] ?? 0 );

		if ( $hal_mcp_pending_id > 0 && 'pending' === hal_mcp_request_read_status( $hal_mcp_pending_id ) ) {
			if ( '' === trim( $text ) ) {
				hal_mcp_request_release_lock( $run_id );

				return new WP_Error(
					'hal_mcp_runner_pending_first',
					__( 'This run is waiting for a decision on its change request. Approve or reject it in the approvals tab first — resuming now would duplicate the proposal.', 'hal-mcp' )
				);
			}

			// A new instruction continues the conversation even with a
			// pending request; the pending request itself stays untouched in
			// the approvals tab.
		}
	}

	$hal_mcp_run['run']['state'] = 'active';

	if ( '' !== trim( $text ) ) {
		$text = trim( $text );

		if ( strlen( $text ) > HAL_MCP_RUNNER_MAX_INPUT_CHARS ) {
			hal_mcp_request_release_lock( $run_id );

			return new WP_Error( 'hal_mcp_runner_input_too_long', __( 'The instruction is too long.', 'hal-mcp' ) );
		}

		$hal_mcp_run['messages'][] = [
			'role' => 'user',
			'text' => $text,
		];

		if ( count( $hal_mcp_run['messages'] ) > HAL_MCP_RUNNER_MAX_MESSAGES ) {
			hal_mcp_request_release_lock( $run_id );

			return new WP_Error( 'hal_mcp_runner_too_many_messages', __( 'This conversation is full; start a new run.', 'hal-mcp' ) );
		}
	}

	// Fresh wall-clock segment: the time budget restarts with every successful
	// resume, while rounds_used (the cumulative round budget) is NOT touched.
	$hal_mcp_run['run']['segment_started_at'] = time();

	$hal_mcp_saved = hal_mcp_runner_persist( $run_id, $hal_mcp_run );

	if ( is_wp_error( $hal_mcp_saved ) ) {
		hal_mcp_request_release_lock( $run_id );

		return $hal_mcp_saved;
	}

	return hal_mcp_runner_loop( $run_id );
}

/**
 * Cancels a run: marks it so no further step ever runs. Already-completed
 * writes are NOT reverted by this — the response says so in plain words
 * (F18: "لا يوهم المستخدم بأن كتابة مكتملة قد تراجعت تلقائيًا").
 *
 * Cancel takes the same per-run execution lock the loop holds: a cancel
 * racing an in-flight segment is refused (the segment's request owns the
 * row) instead of interleaving its state write mid-loop. The lock is
 * released on every exit path below.
 *
 * @param int $run_id Run row id.
 * @return true|WP_Error
 */
function hal_mcp_runner_cancel( int $run_id ) {

	$hal_mcp_run = hal_mcp_runner_load_run( $run_id );

	if ( is_wp_error( $hal_mcp_run ) ) {
		return $hal_mcp_run;
	}

	if ( in_array( $hal_mcp_run['run']['state'], [ 'done', 'cancelled' ], true ) ) {
		return true;
	}

	if ( ! hal_mcp_request_acquire_lock( $run_id ) ) {
		return new WP_Error(
			'hal_mcp_runner_in_progress',
			__( 'This run is already executing in another request; cancel it again once that request has finished.', 'hal-mcp' )
		);
	}

	$hal_mcp_run['run']['state']       = 'cancelled';
	$hal_mcp_run['run']['finished_at'] = time();

	$hal_mcp_saved = hal_mcp_runner_persist( $run_id, $hal_mcp_run );

	if ( is_wp_error( $hal_mcp_saved ) ) {
		hal_mcp_request_release_lock( $run_id );

		return $hal_mcp_saved;
	}

	hal_mcp_runner_audit( $run_id, 'run_cancelled', true, 'cancelled by the user; completed changes are not reverted' );

	hal_mcp_request_release_lock( $run_id );

	return true;
}

/**
 * Reads one run for its owner or a manager (same visibility rule as the F17
 * store), returning the sanitized transcript the UI renders.
 *
 * @param int  $run_id         Run row id.
 * @param bool $with_transcript Include the message list.
 * @return array|WP_Error
 */
function hal_mcp_runner_get_run( int $run_id, bool $with_transcript = true ) {

	$hal_mcp_run = hal_mcp_runner_load_run( $run_id );

	if ( is_wp_error( $hal_mcp_run ) ) {
		return $hal_mcp_run;
	}

	$hal_mcp_out = [
		'run_id'          => $run_id,
		'state'           => (string) $hal_mcp_run['run']['state'],
		'provider'        => (string) $hal_mcp_run['run']['provider'],
		'model'           => (string) $hal_mcp_run['run']['model'],
		'rounds_used'     => (int) $hal_mcp_run['run']['rounds_used'],
		'last_error'      => (string) $hal_mcp_run['run']['last_error'],
		'pending_request' => is_array( $hal_mcp_run['run']['pending_request'] ?? null ) ? $hal_mcp_run['run']['pending_request'] : null,
		'started_at'      => (int) $hal_mcp_run['run']['started_at'],
		'finished_at'     => (int) ( $hal_mcp_run['run']['finished_at'] ?? 0 ),
	];

	if ( $with_transcript ) {
		$hal_mcp_out['messages'] = hal_mcp_runner_transcript( (array) $hal_mcp_run['messages'] );
	}

	return $hal_mcp_out;
}

// ---------------------------------------------------------------------------
// Internals
// ---------------------------------------------------------------------------

/**
 * The tool loop: provider call → parse → execute tool calls → detect
 * pending approvals → repeat until a terminal or blocking state.
 *
 * The caller (start/resume) holds the per-run execution lock (F17) on entry;
 * this function RELEASES it on every exit path — errors, done, limit,
 * awaiting_approval, cancelled — so callers must never release after the
 * loop returns.
 *
 * @param int $run_id Run row id.
 * @return array|WP_Error Same shape as hal_mcp_runner_start().
 */
function hal_mcp_runner_loop( int $run_id ) {

	$hal_mcp_run = hal_mcp_runner_load_run( $run_id );

	if ( is_wp_error( $hal_mcp_run ) ) {
		hal_mcp_request_release_lock( $run_id );

		return $hal_mcp_run;
	}

	$hal_mcp_config = hal_mcp_runner_provider_config( (string) $hal_mcp_run['run']['provider'], (string) $hal_mcp_run['run']['model'], [] );

	if ( is_wp_error( $hal_mcp_config ) ) {
		hal_mcp_request_release_lock( $run_id );

		return $hal_mcp_config;
	}

	$hal_mcp_limits = hal_mcp_runner_limits();

	while ( true ) {
		// Re-load each round: state may have been cancelled between requests.
		$hal_mcp_run = hal_mcp_runner_load_run( $run_id );

		if ( is_wp_error( $hal_mcp_run ) ) {
			hal_mcp_request_release_lock( $run_id );

			return $hal_mcp_run;
		}

		$hal_mcp_state = (string) $hal_mcp_run['run']['state'];

		if ( 'active' !== $hal_mcp_state ) {
			break;
		}

		if ( (int) $hal_mcp_run['run']['rounds_used'] >= $hal_mcp_limits['max_rounds'] ) {
			$hal_mcp_run['run']['state'] = 'limit';
			$hal_mcp_run['run']['finished_at'] = time();
			$hal_mcp_run['run']['last_error'] = __( 'The run reached its tool-round limit. Resume it to continue, or start a new run.', 'hal-mcp' );
			break;
		}

		// Wall-clock budget per segment: segment_started_at is written fresh
		// by every start and successful resume, so this measures the CURRENT
		// segment's window — not the run's total age (which would make every
		// resume after the first minute dead on arrival). The rounds budget
		// above stays cumulative.
		if ( ( time() - (int) $hal_mcp_run['run']['segment_started_at'] ) >= $hal_mcp_limits['max_seconds'] ) {
			$hal_mcp_run['run']['state'] = 'limit';
			$hal_mcp_run['run']['finished_at'] = time();
			$hal_mcp_run['run']['last_error'] = __( 'The run reached its time limit. Resume it to continue with a fresh time window.', 'hal-mcp' );
			break;
		}

		$hal_mcp_catalog = hal_mcp_runner_tool_catalog( (string) $hal_mcp_config['protocol'] );

		$hal_mcp_answer = hal_mcp_provider_call(
			$hal_mcp_config,
			[
				'messages' => (array) $hal_mcp_run['messages'],
				'tools'    => $hal_mcp_catalog,
			]
		);

		++$hal_mcp_run['run']['rounds_used'];
		$hal_mcp_run['run']['updated_at'] = time();

		if ( is_wp_error( $hal_mcp_answer ) ) {
			$hal_mcp_run['run']['state']      = 'error';
			$hal_mcp_run['run']['finished_at'] = time();
			$hal_mcp_run['run']['last_error'] = $hal_mcp_answer->get_error_message();

			$hal_mcp_saved = hal_mcp_runner_persist( $run_id, $hal_mcp_run );

			if ( is_wp_error( $hal_mcp_saved ) ) {
				hal_mcp_request_release_lock( $run_id );

				return $hal_mcp_saved;
			}

			hal_mcp_runner_audit( $run_id, 'run_error', false, $hal_mcp_answer->get_error_code() . ': provider call failed' );

			hal_mcp_request_release_lock( $run_id );

			return hal_mcp_runner_result( $run_id, $hal_mcp_run, $hal_mcp_answer->get_error_message() );
		}

		// Assistant turn: text and/or tool calls, appended verbatim (data,
		// never instructions).
		$hal_mcp_run['messages'][] = [
			'role'       => 'assistant',
			'text'       => (string) $hal_mcp_answer['text'],
			'tool_calls' => array_map(
				static fn( $hal_mcp_call ) => [
					'id'        => (string) ( $hal_mcp_call['id'] ?? '' ),
					'name'      => (string) ( $hal_mcp_call['name'] ?? '' ),
					'arguments' => is_array( $hal_mcp_call['arguments'] ?? null ) ? $hal_mcp_call['arguments'] : [],
				],
				(array) $hal_mcp_answer['tool_calls']
			),
		];

		if ( [] === $hal_mcp_answer['tool_calls'] ) {
			// No tool calls: the conversation is complete. A model refusal
			// arrives as plain text here, which is the honest answer shape.
			$hal_mcp_run['run']['state']       = 'done';
			$hal_mcp_run['run']['finished_at'] = time();

			$hal_mcp_saved = hal_mcp_runner_persist( $run_id, $hal_mcp_run );

			if ( is_wp_error( $hal_mcp_saved ) ) {
				hal_mcp_request_release_lock( $run_id );

				return $hal_mcp_saved;
			}

			hal_mcp_runner_audit( $run_id, 'run_completed', true, sprintf( '%d round(s)', (int) $hal_mcp_run['run']['rounds_used'] ) );

			hal_mcp_request_release_lock( $run_id );

			return hal_mcp_runner_result( $run_id, $hal_mcp_run, '' );
		}

		// Execute the tool calls through the Abilities API — the same
		// permission/audit path as any MCP client (F18). Execution is gated
		// by the round's OFFERED catalog: a name the provider echoes that
		// was not offered this round is answered as a tool error, never
		// executed (F15's reverse map is the parse-time check; this is the
		// execution-time one).
		$hal_mcp_offered = [];

		foreach ( $hal_mcp_catalog as $hal_mcp_tool ) {
			$hal_mcp_offered[ (string) $hal_mcp_tool['ability'] ] = true;
		}

		$hal_mcp_results = [];
		$hal_mcp_pending = null;

		foreach ( $hal_mcp_answer['tool_calls'] as $hal_mcp_call ) {
			$hal_mcp_results[] = hal_mcp_runner_execute_tool( $hal_mcp_call, $hal_mcp_offered, $hal_mcp_pending );
		}

		// Tool results: role 'user' — every protocol returns results from the
		// client side (Anthropic requires tool_result in a user message;
		// Gemini pairs functionResponse in user contents; the OpenAI family
		// ignores the role for tool items but stays consistent).
		$hal_mcp_run['messages'][] = [
			'role'         => 'user',
			'text'         => '',
			'tool_results' => $hal_mcp_results,
		];

		if ( null !== $hal_mcp_pending ) {
			// A protected write answered pending_approval: STOP. Everything
			// from this round is stored; nothing continues on its own.
			$hal_mcp_run['run']['state']           = 'awaiting_approval';
			$hal_mcp_run['run']['pending_request'] = $hal_mcp_pending;

			$hal_mcp_saved = hal_mcp_runner_persist( $run_id, $hal_mcp_run );

			if ( is_wp_error( $hal_mcp_saved ) ) {
				hal_mcp_request_release_lock( $run_id );

				return $hal_mcp_saved;
			}

			hal_mcp_runner_audit( $run_id, 'run_awaiting_approval', true, sprintf( 'request #%d', (int) $hal_mcp_pending['request_id'] ) );

			hal_mcp_request_release_lock( $run_id );

			return hal_mcp_runner_result( $run_id, $hal_mcp_run, '' );
		}

		$hal_mcp_saved = hal_mcp_runner_persist( $run_id, $hal_mcp_run );

		if ( is_wp_error( $hal_mcp_saved ) ) {
			hal_mcp_request_release_lock( $run_id );

			return $hal_mcp_saved;
		}

		// Loop continues: next provider round sees text + calls + results.
	}

	$hal_mcp_saved = hal_mcp_runner_persist( $run_id, $hal_mcp_run );

	if ( is_wp_error( $hal_mcp_saved ) ) {
		hal_mcp_request_release_lock( $run_id );

		return $hal_mcp_saved;
	}

	if ( 'limit' === (string) $hal_mcp_run['run']['state'] ) {
		hal_mcp_runner_audit( $run_id, 'run_limit', true, sprintf( '%d round(s)', (int) $hal_mcp_run['run']['rounds_used'] ) );
	}

	hal_mcp_request_release_lock( $run_id );

	return hal_mcp_runner_result( $run_id, $hal_mcp_run, '' );
}

/**
 * Executes ONE tool call through the Abilities API. Two gates stand before
 * execution: the call's hal/* name must be in the round's OFFERED catalog
 * (parse-time reverse mapping plus this execution-time check — an ability
 * registered without mcp.public is never executable here even if a provider
 * echoes its name), and the ability must be registered. The call name came
 * from the provider; anything else is answered as a tool error.
 *
 * @param array  $hal_mcp_call     [ id, name (hal/*), arguments ].
 * @param array  $hal_mcp_offered  Map of hal/* names offered this round.
 * @param mixed  $hal_mcp_pending  By-ref: set to the FIRST pending-approval
 *                                 info in the round (first wins, so every
 *                                 created request stays visible; the rest
 *                                 remain in the approvals tab).
 * @return array [ call_id, name, content, is_error, truncated ].
 */
function hal_mcp_runner_execute_tool( array $hal_mcp_call, array $hal_mcp_offered = [], &$hal_mcp_pending = null ): array {

	$hal_mcp_result = [
		'call_id'   => (string) ( $hal_mcp_call['id'] ?? '' ),
		'name'      => (string) ( $hal_mcp_call['name'] ?? '' ),
		'content'   => '',
		'is_error'  => true,
		'truncated' => false,
	];

	if ( '' === $hal_mcp_result['name'] || ! str_starts_with( $hal_mcp_result['name'], 'hal/' ) ) {
		$hal_mcp_result['content'] = wp_json_encode( [ 'error' => 'unknown tool' ] ) ?: '{}';

		return $hal_mcp_result;
	}

	if ( ! isset( $hal_mcp_offered[ $hal_mcp_result['name'] ] ) ) {
		$hal_mcp_result['content'] = wp_json_encode( [ 'error' => 'tool not offered in this run' ] ) ?: '{}';

		return $hal_mcp_result;
	}

	if ( ! function_exists( 'wp_get_ability' ) ) {
		$hal_mcp_result['content'] = wp_json_encode( [ 'error' => 'the Abilities API is not available on this site' ] ) ?: '{}';

		return $hal_mcp_result;
	}

	$hal_mcp_ability = wp_get_ability( $hal_mcp_result['name'] );

	if ( null === $hal_mcp_ability || ! method_exists( $hal_mcp_ability, 'execute' ) ) {
		$hal_mcp_result['content'] = wp_json_encode( [ 'error' => 'tool not registered' ] ) ?: '{}';

		return $hal_mcp_result;
	}

	$hal_mcp_outcome = $hal_mcp_ability->execute( is_array( $hal_mcp_call['arguments'] ?? null ) ? $hal_mcp_call['arguments'] : [] );

	if ( is_wp_error( $hal_mcp_outcome ) ) {
		$hal_mcp_result['content'] = wp_json_encode( [ 'error' => $hal_mcp_outcome->get_error_message() ] ) ?: '{}';

		return $hal_mcp_result;
	}

	$hal_mcp_result['is_error'] = false;

	if ( is_array( $hal_mcp_outcome ) ) {
		// The approval gate answered: record and let the loop stop AFTER
		// this round's results are stored (F18: stop at pending_approval,
		// show what was done). FIRST pending wins — later ones in the same
		// round stay visible in the approvals tab instead of being
		// overwritten in the note.
		if ( null === $hal_mcp_pending && isset( $hal_mcp_outcome['state'] ) && 'pending_approval' === $hal_mcp_outcome['state'] && ! empty( $hal_mcp_outcome['request_id'] ) ) {
			$hal_mcp_pending = [
				'request_id' => (int) $hal_mcp_outcome['request_id'],
				'preview'    => (string) ( $hal_mcp_outcome['preview'] ?? '' ),
			];
		}

		$hal_mcp_result['content'] = wp_json_encode( $hal_mcp_outcome ) ?: '{}';
	} else {
		$hal_mcp_result['content'] = wp_json_encode( [ 'ok' => true ] ) ?: '{}';
	}

	if ( strlen( $hal_mcp_result['content'] ) > HAL_MCP_RUNNER_MAX_RESULT_CHARS ) {
		$hal_mcp_result['content']   = substr( $hal_mcp_result['content'], 0, HAL_MCP_RUNNER_MAX_RESULT_CHARS );
		$hal_mcp_result['truncated'] = true;
	}

	return $hal_mcp_result;
}

/**
 * The model-facing tool catalog for one protocol: this plugin's hal/*
 * abilities flagged mcp.public, name-converted and schema-carried. No
 * approval tools, no key tools, no internal-request tools — such abilities
 * do not exist in this plugin by design (F18).
 *
 * @param string $protocol One of HAL_MCP_PROVIDER_PROTOCOLS.
 * @return array<string, array{ability: string, description: string, parameters: array}>
 */
function hal_mcp_runner_tool_catalog( string $protocol ): array {

	if ( ! function_exists( 'wp_get_abilities' ) || ! function_exists( 'hal_mcp_provider_tool_name' ) ) {
		return [];
	}

	$hal_mcp_catalog = [];

	foreach ( (array) wp_get_abilities() as $hal_mcp_ability ) {
		if ( ! is_object( $hal_mcp_ability ) || ! method_exists( $hal_mcp_ability, 'get_name' ) ) {
			continue;
		}

		$hal_mcp_name = (string) $hal_mcp_ability->get_name();

		if ( ! str_starts_with( $hal_mcp_name, 'hal/' ) ) {
			continue;
		}

		$hal_mcp_meta = method_exists( $hal_mcp_ability, 'get_meta' ) ? (array) $hal_mcp_ability->get_meta() : [];

		if ( empty( $hal_mcp_meta['mcp']['public'] ) ) {
			continue;
		}

		$hal_mcp_tool_name = hal_mcp_provider_tool_name( $hal_mcp_name, $protocol );

		if ( '' === $hal_mcp_tool_name ) {
			continue;
		}

		$hal_mcp_description = method_exists( $hal_mcp_ability, 'get_description' ) ? (string) $hal_mcp_ability->get_description() : '';

		$hal_mcp_catalog[ $hal_mcp_tool_name ] = [
			'ability'     => $hal_mcp_name,
			'description' => $hal_mcp_description,
			'parameters'  => method_exists( $hal_mcp_ability, 'get_input_schema' ) && is_array( $hal_mcp_ability->get_input_schema() ) ? $hal_mcp_ability->get_input_schema() : [],
		];
	}

	return $hal_mcp_catalog;
}

/**
 * Resolves the provider config for a run through the F19 settings layer —
 * the only place secrets come from. Tests may pass a full config override.
 *
 * @param string $provider_id Provider id.
 * @param string $model       Model override.
 * @param array  $args        Start args (config override for tests).
 * @return array|WP_Error
 */
function hal_mcp_runner_provider_config( string $provider_id, string $model, array $args ) {

	if ( isset( $args['config'] ) && is_array( $args['config'] ) ) {
		$hal_mcp_config = $args['config'];
	} else {
		if ( ! function_exists( 'hal_mcp_settings_provider_config' ) ) {
			return new WP_Error( 'hal_mcp_runner_settings_missing', __( 'The provider settings module is not loaded.', 'hal-mcp' ) );
		}

		$hal_mcp_config = hal_mcp_settings_provider_config( $provider_id, $model );
	}

	if ( is_wp_error( $hal_mcp_config ) ) {
		return $hal_mcp_config;
	}

	if ( '' === trim( (string) ( $hal_mcp_config['protocol'] ?? '' ) ) ) {
		return new WP_Error( 'hal_mcp_runner_provider_incomplete', __( 'Choose a provider and protocol in the settings first.', 'hal-mcp' ) );
	}

	if ( ! hal_mcp_provider_supports_tools( $provider_id ) ) {
		return new WP_Error(
			'hal_mcp_runner_not_tool_capable',
			__( 'This provider or model is set up for text generation only; site editing through tools needs a tool-capable provider.', 'hal-mcp' )
		);
	}

	return $hal_mcp_config;
}

/**
 * The effective limits: code ceilings, optionally lowered by the F19 policy
 * settings. Never raised by settings.
 *
 * @return array{max_rounds: int, max_seconds: int}
 */
function hal_mcp_runner_limits(): array {

	$hal_mcp_limits = [
		'max_rounds'  => HAL_MCP_RUNNER_DEFAULT_ROUNDS,
		'max_seconds' => HAL_MCP_RUNNER_DEFAULT_SECONDS,
	];

	if ( function_exists( 'hal_mcp_settings_policy_limits' ) ) {
		$hal_mcp_policy = hal_mcp_settings_policy_limits();

		$hal_mcp_limits['max_rounds']  = min( HAL_MCP_RUNNER_MAX_ROUNDS, max( 1, (int) $hal_mcp_policy['max_rounds'] ) );
		$hal_mcp_limits['max_seconds'] = min( HAL_MCP_RUNNER_MAX_SECONDS, max( 5, (int) $hal_mcp_policy['max_seconds'] ) );
	}

	return $hal_mcp_limits;
}

/**
 * Loads one run row with its stored conversation and state.
 *
 * @param int $run_id Run row id.
 * @return array|WP_Error { run: array, messages: array }
 */
function hal_mcp_runner_load_run( int $run_id ) {

	$hal_mcp_request = hal_mcp_get_change_request( $run_id, false );

	if ( is_wp_error( $hal_mcp_request ) ) {
		return new WP_Error( 'hal_mcp_runner_not_found', __( 'No such run.', 'hal-mcp' ) );
	}

	if ( 'run' !== (string) $hal_mcp_request['kind'] ) {
		return new WP_Error( 'hal_mcp_runner_not_found', __( 'No such run.', 'hal-mcp' ) );
	}

	if ( ! hal_mcp_user_can_view_request( $hal_mcp_request ) ) {
		return new WP_Error( 'hal_mcp_runner_forbidden', __( 'You may not view this run.', 'hal-mcp' ) );
	}

	$hal_mcp_run_meta = (array) get_post_meta( $run_id, '_hal_run', true );
	$hal_mcp_run_meta = hal_mcp_runner_state_normalized( $hal_mcp_run_meta );

	return [
		'run'      => $hal_mcp_run_meta,
		'messages' => (array) get_post_meta( $run_id, '_hal_payload', true ),
	];
}

/**
 * Normalizes a stored run-state array: unknown states collapse to 'error',
 * missing keys get defaults, counters stay bounded. Stored data is never
 * trusted structurally.
 *
 * @param array $hal_mcp_run_meta Stored meta.
 * @return array<string, mixed>
 */
function hal_mcp_runner_state_normalized( array $hal_mcp_run_meta ): array {

	$hal_mcp_state = (string) ( $hal_mcp_run_meta['state'] ?? '' );

	if ( '' === $hal_mcp_state ) {
		// A run row without state meta is a row whose first persist failed —
		// surface it as an error, never as an active run.
		$hal_mcp_state = 'error';
	}

	if ( ! in_array( $hal_mcp_state, HAL_MCP_RUNNER_STATES, true ) ) {
		$hal_mcp_state = 'error';
	}

	$hal_mcp_started_at = (int) ( $hal_mcp_run_meta['started_at'] ?? time() );

	return [
		'state'           => $hal_mcp_state,
		'provider'        => (string) ( $hal_mcp_run_meta['provider'] ?? '' ),
		'model'           => (string) ( $hal_mcp_run_meta['model'] ?? '' ),
		'protocol'        => (string) ( $hal_mcp_run_meta['protocol'] ?? '' ),
		'rounds_used'     => min( HAL_MCP_RUNNER_MAX_ROUNDS * 2, max( 0, (int) ( $hal_mcp_run_meta['rounds_used'] ?? 0 ) ) ),
		'started_at'      => $hal_mcp_started_at,
		// The wall-clock segment marker: start and every successful resume
		// write it fresh; rows persisted before the key existed default to
		// the run's own start (and a resume rewrites it before the loop
		// reads it, so no legacy row is time-limit dead).
		'segment_started_at' => (int) ( $hal_mcp_run_meta['segment_started_at'] ?? $hal_mcp_started_at ),
		'updated_at'      => (int) ( $hal_mcp_run_meta['updated_at'] ?? time() ),
		'finished_at'     => (int) ( $hal_mcp_run_meta['finished_at'] ?? 0 ),
		'last_error'      => (string) ( $hal_mcp_run_meta['last_error'] ?? '' ),
		'pending_request' => is_array( $hal_mcp_run_meta['pending_request'] ?? null ) ? $hal_mcp_run_meta['pending_request'] : null,
	];
}

/**
 * Persists the conversation and run state, and PROVES the write (F18: state
 * must be demonstrably stored between HTTP requests). The proof covers BOTH
 * the run state (exact read-back of the control fields) and the conversation
 * (every message of this round demonstrably landed). Returns WP_Error when
 * the proof fails — the caller must stop, not claim.
 *
 * @param int   $run_id      Run row id.
 * @param array $hal_mcp_run Loaded run (run + messages).
 * @return true|WP_Error
 */
function hal_mcp_runner_persist( int $run_id, array $hal_mcp_run ) {

	$hal_mcp_run['run']['updated_at'] = time();

	$hal_mcp_conversation = hal_mcp_sanitize_request_payload( $hal_mcp_run['messages'] );

	if ( null === $hal_mcp_conversation ) {
		return new WP_Error( 'hal_mcp_runner_state_unsanitizable', __( 'The conversation could not be stored safely; the run stopped.', 'hal-mcp' ) );
	}

	update_post_meta( $run_id, '_hal_payload', $hal_mcp_conversation );
	update_post_meta( $run_id, '_hal_run', $hal_mcp_run['run'] );

	// Prove the state write before anything acts on it (F06 habit): the
	// stored state and counter must read back exactly as written.
	$hal_mcp_read_back = get_post_meta( $run_id, '_hal_run', true );

	if ( ! is_array( $hal_mcp_read_back )
		|| (string) ( $hal_mcp_read_back['state'] ?? '' ) !== (string) $hal_mcp_run['run']['state']
		|| (int) ( $hal_mcp_read_back['rounds_used'] ?? -1 ) !== (int) $hal_mcp_run['run']['rounds_used'] ) {
		return new WP_Error( 'hal_mcp_runner_state_not_saved', __( 'The run state could not be saved; the run stopped instead of continuing on an unproven state.', 'hal-mcp' ) );
	}

	// Prove the conversation write too: the next request resumes from the
	// STORED transcript, so a payload write that silently failed would make
	// the model re-ask finished questions or re-run executed tools. Count
	// equality is the honest cheap proof — a full hash is deliberately NOT
	// used: the stored payload is the SANITIZED copy (the sanitizer may drop
	// or normalize values), and re-hashing the entire transcript on every
	// round buys nothing beyond what the state proof above pins exactly; a
	// lost or stale write shows up as a different message count. The same
	// error as the state proof: one unproven store, one hard stop.
	$hal_mcp_stored_conversation = get_post_meta( $run_id, '_hal_payload', true );

	if ( ! is_array( $hal_mcp_stored_conversation )
		|| count( $hal_mcp_stored_conversation ) !== count( $hal_mcp_conversation ) ) {
		return new WP_Error( 'hal_mcp_runner_state_not_saved', __( 'The run state could not be saved; the run stopped instead of continuing on an unproven state.', 'hal-mcp' ) );
	}

	return true;
}

/**
 * Builds the UI result for one loop exit.
 *
 * @param int    $run_id          Run row id.
 * @param array  $hal_mcp_run     Loaded run.
 * @param string $hal_mcp_note    Short human note ('' when none).
 * @return array
 */
function hal_mcp_runner_result( int $run_id, array $hal_mcp_run, string $hal_mcp_note ): array {

	$hal_mcp_out = hal_mcp_runner_get_run( $run_id, false );

	if ( is_wp_error( $hal_mcp_out ) ) {
		return $hal_mcp_out;
	}

	$hal_mcp_out['messages'] = hal_mcp_runner_transcript( (array) $hal_mcp_run['messages'] );

	if ( '' !== $hal_mcp_note ) {
		$hal_mcp_out['note'] = $hal_mcp_note;
	}

	return $hal_mcp_out;
}

/**
 * The sanitized transcript for display: role + text (+ tool call names and
 * result status), each text block bounded. Tool result CONTENTS are not
 * echoed here in full — the UI shows tool outcomes as structured lines, and
 * full payloads live in the F17 store for the owner/manager.
 *
 * @param array $hal_mcp_messages Canonical conversation.
 * @return array<int, array<string, mixed>>
 */
function hal_mcp_runner_transcript( array $hal_mcp_messages ): array {

	$hal_mcp_out = [];

	foreach ( $hal_mcp_messages as $hal_mcp_message ) {
		if ( ! is_array( $hal_mcp_message ) ) {
			continue;
		}

		$hal_mcp_row = [
			'role'       => (string) ( $hal_mcp_message['role'] ?? 'user' ),
			'text'       => mb_substr( (string) ( $hal_mcp_message['text'] ?? '' ), 0, 4000 ),
			'tool_calls' => [],
		];

		foreach ( (array) ( $hal_mcp_message['tool_calls'] ?? [] ) as $hal_mcp_call ) {
			if ( is_array( $hal_mcp_call ) && ! empty( $hal_mcp_call['name'] ) ) {
				$hal_mcp_row['tool_calls'][] = [
					'name' => (string) $hal_mcp_call['name'],
					'args' => hal_mcp_sanitize_request_payload( $hal_mcp_call['arguments'] ?? [] ) ?? [],
				];
			}
		}

		foreach ( (array) ( $hal_mcp_message['tool_results'] ?? [] ) as $hal_mcp_result ) {
			if ( is_array( $hal_mcp_result ) && ! empty( $hal_mcp_result['name'] ) ) {
				$hal_mcp_row['tool_calls'][] = [
					'name'       => (string) $hal_mcp_result['name'],
					'is_error'   => ! empty( $hal_mcp_result['is_error'] ),
					'truncated'  => ! empty( $hal_mcp_result['truncated'] ),
					'call_id'    => (string) ( $hal_mcp_result['call_id'] ?? '' ),
				];
			}
		}

		$hal_mcp_out[] = $hal_mcp_row;
	}

	return $hal_mcp_out;
}

/**
 * Writes one audit control event for a run milestone (F06). Never throws.
 *
 * @param int    $run_id  Run row id.
 * @param string $stage   Event stage.
 * @param bool   $success Success flag.
 * @param string $summary Short summary (no conversation content).
 * @return void
 */
function hal_mcp_runner_audit( int $run_id, string $stage, bool $success, string $summary ): void {

	if ( ! function_exists( 'hal_mcp_request_audit' ) ) {
		return;
	}

	hal_mcp_request_audit( $run_id, 'run', $stage, $success, $summary, 'admin' );
}
