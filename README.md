# HAL MCP Integration Abilities

A standard WordPress plugin that lets you — and, if you choose, external MCP
clients — read site content and propose changes to it through official
WordPress building blocks: the [Abilities API](https://developer.wordpress.org/apis/abilities-api/)
(core since 6.9) and the official [WordPress MCP Adapter](https://github.com/WordPress/mcp-adapter)
for the external channel.

It registers **20 narrow `hal/*` abilities** (reads, drafts, and change
requests across posts, pages, products, media, authorized content types, and
system inventory), plus the admin screens to drive them: provider setup, a
bounded model conversation runner, previews, and human approval before any
change to published content is applied.

Nothing here rewrites how WordPress works. No Gateway, no Hub plugin, no
build pipeline on the client site. Writes never bypass the approval policy:
published content is changed only through an explicitly approved change
request, never directly by a model.

**Status:** v2.0.0 executed and reviewed locally per the governing roadmap
[`docs/versions/hal-mcp-integration-abilities-v2.0.0-execution-roadmap.md`](docs/versions/hal-mcp-integration-abilities-v2.0.0-execution-roadmap.md)
(batches 1–8 complete). All local verification is in-memory stubs and
fixtures — **not yet operatively verified against a live site, live provider
APIs, or live editor/SEO/translation plugins**, and not yet deployed. See
[Compatibility limits](#compatibility-limits) before touching a live site.

---

## How it works

```
Internal use (wp-admin):
  Admin (HAL MCP tab) → providers.php → runner.php → hal/* ability
      → object permissions + change policy → direct edit (drafts)
      or hal_mcp_request approval → applied + audited

External use (MCP client):
  MCP client → WordPress MCP Adapter (official, separate plugin)
      → the same hal/* abilities → the same policy
```

Both paths run the same registered abilities with the same permission and
approval policy. Approve/reject decisions are admin-screen actions only —
they are never exposed as model-facing tools, and no model input can mark a
request approved.

---

## Repository structure

```
hal-mcp-integration-abilities.php   ← standard plugin entry (root). Defines the
                                       version/dir/plugin-file constants, guards
                                       against the legacy mu-plugin copy, requires
                                       the bootstrap, wires activation.
uninstall.php                       ← keeps all plugin data by default; deletes
                                       only this plugin's own data after an
                                       explicit admin opt-in (current site only).
hal-mcp-abilities/                  ← internal folder (no second plugin header)
├── hal-mcp-abilities.php           ← internal bootstrap: load guards, requires
│                                      includes always, ability files only when
│                                      the Abilities API exists
├── ABILITIES-REGISTRY.md           ← per-ability safety reference (permission,
│                                      effect, integration limits)
├── includes/                       ← permissions, audit-log, change-requests,
│                                      categories, environment, integrations,
│                                      http-client, providers, runner, settings,
│                                      admin, updater
├── abilities/                      ← 10 ability files registering the 20 hal/*
│                                      abilities (see ABILITIES-REGISTRY.md)
├── integrations/                   ← blocks, elementor, seo, translations —
│                                      discovered integrations, loaded only when
│                                      present on the site
└── assets/                         ← admin.js, admin.css (admin screens),
                                       editor.js (block-editor serialization bridge)
```

---

## Quick start

The admin screen is **HAL MCP** in wp-admin (one page, five tabs: Overview,
Chat, Providers, Approvals, Environment & Log). The Overview tab is the
starting point and mirrors these steps:

1. **Review what was discovered.** Open Environment & Log — the plugin keeps
   a per-site inventory (WordPress/PHP, theme, plugins, post types,
   WooCommerce, SEO/languages/editors) that it refreshes automatically when
   the environment changes, with a manual re-discovery button.
2. **Pick a provider and enter its key.** Providers tab: OpenAI, Anthropic,
   Google Gemini, Z.AI GLM, Moonshot Kimi, Qwen, or a custom OpenAI-compatible
   endpoint. Keys are stored encrypted (AES-256-GCM), shown masked, and never
   sent back to the browser after saving.
3. **Pick a model and test the connection yourself.** Model lists are fetched
   on demand; the connection test runs only when you click it — nothing calls
   a provider just because you opened a page.
4. **Write your first request.** Chat tab: ask for a draft or an edit; pick
   the content or page and, where the site is multilingual, the language.
5. **Review the preview, then approve or reject.** Drafts you already may
   edit are edited directly; anything touching published content becomes a
   change request with a before/after preview. Approving applies the stored
   payload exactly — a changed original or edited proposal invalidates the
   approval and forces re-review.
6. **Follow the result.** Approvals tab for decisions and state; Environment
   & Log for the audit trail.

## Prerequisites

- **WordPress 6.9+** and **PHP 8.0+** (the Abilities API is core as of 6.9).
- A provider API key for whichever model family you configure — only when you
  want the internal conversation features. Everything else (reads, drafts via
  MCP, audit logging, discovery) works without one.
- **WordPress MCP Adapter**, installed from its [Releases page](https://github.com/WordPress/mcp-adapter/releases)
  only — needed for the external MCP channel, not for the admin UI.
- WooCommerce, Elementor, Yoast SEO, WPML/Polylang, etc. are **optional and
  discovered, never assumed**: each capability that depends on one reports
  its real state (available / partial / needs setup / unverified) with a
  reason when it is not there.

## Security model, summarized

1. **Real object permissions, every call.** Every ability resolves the
   acting user's actual capability on the specific target (`read_post`,
   `edit_post`, `publish_posts`, … via the target's registered capability
   map). No role name is trusted and no ability assumes Administrator.
2. **Writes decide their path from state, in code.** Drafts you are allowed
   to edit are edited directly; anything protected (published, private,
   scheduled, pending, unknown custom states) becomes a change request;
   trashed targets are refused. Protected-affecting fields force the request
   path regardless of state.
3. **Approval is a human, admin-screen act.** Approving requires
   `manage_options` plus the effect capability on every target, a valid
   admin session + nonce (an application password or an MCP call cannot),
   and applies only the stored, re-verified payload — original fingerprints
   are re-checked at apply time, so a silently changed original becomes a
   conflict, never an overwrite.
4. **Provider keys stay server-side.** Encrypted at rest, autoload off,
   masked in the UI, redacted if they ever surface in an error or log line
   (`sk-…`/`sk-ant-…`/`AIza…` shapes, Bearer tokens, labeled key fields).
5. **Everything is logged.** Ability executions and control events
   (requested / approved / rejected / applied / failed / conflict /
   permission-denied) go to the plugin's own audit table with
   sensitive-material redaction and a fail-safe depth wall.

## External channel (MCP)

Install the official MCP Adapter and its transport of choice; this plugin's
`hal/*` abilities flagged `mcp.public` appear through it to any MCP-compliant
client. The same permission and approval policy applies — an external client
gets drafts and change requests, never direct writes to published content.
The Providers tab's Environment section reports what the external channel
looks like on this site, without exposing any provider keys and without
configuring any external program for you.

## Updates

The plugin self-updates from the owner repository
[`https://github.com/hossamadellaw/hal-mcp-integration-abilities`](https://github.com/hossamadellaw/hal-mcp-integration-abilities)
via the standard plugin-update flow (release ZIPs; see `Git-Github/Docs/`).
No release has been published yet — until one is, updates come from manual
installs.

## Data on uninstall

Deleting the plugin never deletes content it created. By default the
uninstall keeps the plugin's own data too — the audit log table, settings,
encrypted secrets, environment inventory, and internal change requests — so
an accidental uninstall destroys nothing. Removing that data requires an
explicit admin opt-in in the plugin settings before deletion. On multisite,
deletion covers the current site only — never a network-wide bulk wipe.

**Transition from the legacy MU-plugin copy:** if the old copy
(`wp-content/mu-plugins/hal-mcp-abilities-loader.php` + folder) is still
present, mu-plugins load first and the standard plugin stays dormant with a
clear admin notice. Removing the mu-plugin copy is an explicit admin step,
never automatic.

## Compatibility limits

Be honest about what is proven:

- **Executed and reviewed locally is not operatively verified.** All local
  checks run against in-memory stubs and fixtures (PHP 8.4 harness + Node
  tests). Real provider HTTP semantics, live Abilities API behavior, real
  Elementor/Yoast/WPML/Polylang runtimes, REST nonce behavior in a browser,
  and multisite behavior are **not** proven until exercised on a real site.
- Protected/publish flows depend on WordPress capability semantics that can
  shift between releases; verify with `wp ability can-run … --user=<role>`
  on your actual site before trusting any write path.
- The audit log has no retention/cleanup policy yet; plan housekeeping for
  sustained traffic.
- ChatGPT/Codex/Claude are MCP clients, not separate model providers: the
  external channel is the Adapter; the admin UI uses the configured model
  provider's API.
