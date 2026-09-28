# hal-mcp-integration-abilities — Architecture, Rules, and Roadmap

This file answers three questions the other two documents don't: **why does
this exist, how do its files actually depend on each other, and what governs
any future change?** For "how do I deploy it," see
[`README.md`](README.md). For "what does ability X do," see
[`hal-mcp-abilities/ABILITIES-REGISTRY.md`](hal-mcp-abilities/ABILITIES-REGISTRY.md).

> **Governing plan note (updated 2026-09-26):** v2.0.0 execution is governed
> by
> [`docs/versions/hal-mcp-integration-abilities-v2.0.0-execution-roadmap.md`](docs/versions/hal-mcp-integration-abilities-v2.0.0-execution-roadmap.md).
> All roadmap batches (1–8) are now **executed and reviewed locally** —
> in-memory stubs and fixtures, no live site. Operational verification is
> claimed nowhere; see README's compatibility limits. The "candidate ideas"
> roadmap at the bottom of this file is historical v1 thinking — where the
> v2.0.0 roadmap covers the same ground, that document wins.

---

## What this addition is, in one paragraph

`hal-mcp-integration-abilities` is the custom component that gives two
audiences real, constrained access to a WordPress site: an administrator,
through a plugin admin screen, and an MCP-compliant client, through the
official WordPress MCP Adapter. It registers 20 narrow `hal/*` abilities on
the Abilities API, drives them through a bounded conversation runner against
a configured model provider (OpenAI, Anthropic, Gemini, GLM, Kimi, Qwen, or
a custom OpenAI-compatible endpoint), and routes every change to protected
content through a stored, human-approved change request. The Abilities API,
WordPress's capability system and HTTP API, and the MCP Adapter are
WordPress's own; the provider/transport layer, the admin screens, and the
approval store are custom code — this plugin is no longer the *only*
custom piece of its stack, and nothing here pretends otherwise.

## Why it exists

Two problems, one plugin. First: an AI agent that's actually useful for
day-to-day site work — drafting a post, checking a product's stock, listing
what needs updating — needs some way to see and act on real site state, not
a static description of it. Second: giving an agent that access naturally
raises the question of what it could get wrong, on purpose or by accident,
on a site real clients depend on. This plugin's entire design is the answer
to the second problem in service of the first: real, useful access, shaped
so the worst case is a stored proposal that was never approved — never a
silent change to something a client already sees.

---

## The two paths

Both paths run the *same registered abilities* under the *same policy*:

**Internal (wp-admin):** the admin screen (one page, five tabs — Overview,
Chat, Providers, Approvals, Environment & Log) talks to a small REST API
whose decision routes require a real admin session + nonce + capability.
A chat message goes `providers.php` (protocol builders/parsers per provider)
→ `http-client.php` (shared hardened transport) → `runner.php` (one provider
round per HTTP request, resumable, hard-bounded) → the proposed `hal/*`
tool call executes through `wp_get_ability()->execute()` — the same
validation, permission, and audit-hook path every MCP client uses.

**External (MCP):** any MCP client connects through the official MCP
Adapter (a separate plugin, discovered — never bundled). The adapter
surfaces only `hal/*` abilities flagged `meta.mcp.public`; approve/reject
and every other admin action are absent from that surface by construction.

## How the files actually depend on each other

Current layout: root entry + `uninstall.php` + the internal bootstrap +
12 includes + 10 ability files + 4 integration files + 3 assets.

**Hard, load-order dependency — enforced by WordPress core, not by our
`require()` order:** `includes/categories.php` registers on
`wp_abilities_api_categories_init`; every file under `abilities/` registers
on `wp_abilities_api_init`. WordPress fires the categories hook first, by
core design — any ability referencing a category before it exists simply
fails to register (loudly, since batch 8's `WP_Error`-aware guard).

**Shared foundations, loaded always:** permissions, audit-log,
change-requests, integrations, environment, and categories load regardless
of whether the Abilities API exists, so diagnostics, storage init, and the
approval store never depend on a core feature being present. Ability files
load only when `wp_register_ability()` exists.

**Modules loaded when present** (`http-client`, `providers`, `settings`,
`runner`, `admin`, `updater`): each defines functions and hooks nothing at
load except its own callbacks, so a missing file disables only its feature.
The bootstrap requires them as soon as they exist, so adding a module never
means touching the bootstrap again.

**The change-request store is the choke point.** `includes/change-requests.php`
owns the internal `hal_mcp_request` post type and the only
approve/reject/apply functions. Its state machine is
`pending → approved → applying → applied`, with `rejected`/`failed`/
`conflict` alternatives. Approval requires `manage_options` plus the effect
capability on every target; apply re-checks both and re-verifies the
original's fingerprint against the one the approver saw — a changed
original becomes a `conflict`, never an overwrite. Conversation state lives
in the same store as `kind=run` rows, which approve/apply refuse outright.
No other file writes request state; the admin REST routes and the domain
handlers go through this file's gates.

**Permission policy is one function.** Every ability's
`permission_callback` lands in `hal_mcp_permission()` — an object policy
(type, operation, target ID) that resolves the real per-object capability
(`read_post`/`edit_post`/…) from the target's registered capability map and
logs denials. The direct-vs-request write decision lives in
`hal_mcp_decide_write_path()` and is consulted by the write callbacks
themselves and again at apply time, so an MCP client calling an ability
directly goes through the identical gate.

**Integration files** (`integrations/blocks|elementor|seo|translations.php`)
register themselves in the small named registry in
`includes/integrations.php` with label, version, source, and per-operation
effect/capability/writability declarations, each with a runtime check.
Environment discovery reads that registry — never plugin-name guessing.
Third-party *abilities* (if any are ever registered) are not surfaced at
all: the runner's tool catalog contains only this plugin's `hal/*`
abilities flagged `mcp.public`, so nothing unclassified can reach the model
raw.

**Assets:** `admin.js` exports its display helpers as pure functions
(exercised directly by `node --test tests/local/admin.test.mjs` — no
parallel test copy), never touches keys, and holds no permission logic;
`editor.js` is the block-editor serialization bridge, inert without
server-injected configuration; `admin.css` is scoped to the plugin's screen
and uses logical properties so RTL follows the admin locale.

---

## Governing rules

These are non-negotiable for any future file added to this plugin:

1. Every `wp_register_ability()` call lives inside a function hooked to
   `wp_abilities_api_init`; every category on
   `wp_abilities_api_categories_init`, first.
2. Every ability has a real `permission_callback` routed through
   `hal_mcp_permission()` — never a direct capability check, never
   `__return_true`.
3. `requested_status` is ordinary input; **the policy decides the path**.
   Drafts the acting user may edit are edited directly; anything protected
   (publish/future/private/pending/unknown custom states, or any
   protected-affecting field) becomes a change request; trashed targets are
   refused. No write ever reads an `approved`-like value from input.
4. Protected content changes **only** through an approved, stored,
   fingerprint-verified change request — replacing v1's "linked draft
   proposal" pattern. Approval is a human admin-screen act (session + nonce
   + capability), never a model tool, never an application-password call.
5. `meta.mcp.public` is a deliberate per-ability decision, never copied by
   default.
6. No shell/SQL/PHP execution, user management, plugin installation, or
   settings tools — admin asks outside the content scope are recorded as
   `admin_assist` requests a human executes manually; they are never
   executable by the store.
7. No ability assumes Administrator — not in code, not in comments, not
   hypothetically. Approvals run under the approver's own capabilities and
   never escalate the requester's role.
8. Nothing is taken on memory when an official source settles it; where
   none does, that's said plainly.
9. No new architectural component (Gateway, Hub, SDK, queue daemon) without
   being raised and agreed on first. A new file following these patterns is
   normal growth; a new kind of component is not.

---

## Roadmap: candidate ideas from v1 (historical)

None of this was approved or scheduled — it's what naturally fell out of
v1 decisions, kept for history. Where the v2.0.0 roadmap covered the same
ground, it happened there and differently:

- ~~A narrow "apply this proposal" ability~~ → generalized into the
  change-request store and its approval flow (v2 batches 2–6).
- ~~WPML-aware writes~~ → the translations integration with WPML and
  Polylang branches (v2 batch 5).
- Revisit read abilities against WordPress 7.1 core abilities — still
  worth revisiting when core ships them.
- ~~Close the audit log's permission-denial gap~~ → control events logged
  from the plugin's own control points (v2 batch 2), which is deliberately
  independent of core hook improvements.
- ~~Stock/inventory as its own ability~~ → product fields (including
  stock) are handled inside the product write contract with per-field
  impact, through the same approval policy.
- A custom MCP server, if the default one becomes limiting — still a
  known option, still not needed.
- Validate a second real MCP client — still unproven anywhere; the
  "protocol-agnostic" claim rests on the official Adapter.
