# HAL MCP Integration Abilities — Abilities Registry Reference

This is a design/safety reference for this plugin's `hal/*` abilities —
what each one does, what it may touch, and *why it's safe*. It is **not**
the live source of truth for what's registered on a given site; that lives
in WordPress itself and is always one command away:

```bash
# Everything registered by this plugin, read live from the registry.
wp ability list --namespace=hal

# Full detail on one ability, including whether it's REST-exposed.
wp ability get hal/get-post --fields=name,label,category,readonly,show_in_rest

# Confirm a specific user can (or can't) run a given ability.
wp ability can-run hal/create-post --user=<username>
```

If this file and the live `wp ability list` output ever disagree, the live
output is correct — update this file to match, not the other way around.

---

## Permission model (one shared policy)

Every `permission_callback` routes through the shared policy
`hal_mcp_permission( $type, $operation, $target_id )`
(`includes/permissions.php`) — an object policy, not a capability
allow-list — with the single documented exception `hal/get-request-status`,
whose callback is an authentication check (`is_user_logged_in`) and whose
per-requester authorization (`hal_mcp_user_can_view_request`) runs inside
the execute callback:

- **Reads** resolve the target's real read capability (`read_post` for
  posts/pages/attachments, the post type's registered `read` cap for custom
  types, WooCommerce's `edit_products` for products) and check it on every
  individual result — a list never leaks an item the user cannot read.
- **Writes** go through `hal_mcp_decide_write_path()`, which reads the
  target's *current* state: `draft`/`auto-draft` the user may edit → direct
  edit; anything protected (publish/future/private/pending/unknown custom
  states) or any protected-affecting field → a change request; `trash` →
  refused.
- **Approvals** are admin-screen acts: `manage_options` plus the effect
  capability on every target (`publish_posts` additionally required for a
  publish effect), a valid admin session + nonce, and the approver's own
  fingerprint of the original. Apply re-checks all of it.

Every call is recorded in `{$wpdb->prefix}hal_mcp_audit_log`
(`includes/audit-log.php`) — executions plus explicit control events
(requested / approved / rejected / applied / failed / conflict /
permission-denied), with sensitive-material redaction (labeled key fields,
Bearer tokens, `sk-…`/`sk-ant-…`/`AIza…` shapes, long base64) and a
fail-safe depth wall.

**Admin actions are not model tools.** Approve/reject/apply, settings, and
keys exist only behind the admin REST gate; nothing named like them is
registered as an ability, no model input can mark a request approved, and
conversation rows (`kind=run`) are refused at approve/apply by design.

---

## File map — 20 abilities, 10 files

| Ability | Source file | Category |
|---|---|---|
| `hal/get-site-inventory` | `abilities/read-system.php` | `hal-system` |
| `hal/get-post`, `hal/search-posts` | `abilities/read-posts.php` | `hal-content` |
| `hal/get-page`, `hal/search-pages` | `abilities/read-pages.php` | `hal-pages` |
| `hal/get-product`, `hal/search-products` | `abilities/read-products.php` | `hal-products` |
| `hal/list-media` | `abilities/read-media.php` | `hal-media` |
| `hal/create-post`, `hal/update-post` | `abilities/write-posts.php` | `hal-content` |
| `hal/create-page`, `hal/update-page` | `abilities/write-pages.php` | `hal-pages` |
| `hal/create-product`, `hal/update-product` | `abilities/write-products.php` | `hal-products` |
| `hal/upload-media` | `abilities/write-media.php` | `hal-media` |
| `hal/get-content`, `hal/search-content`, `hal/create-content`, `hal/update-content`, `hal/get-request-status` | `abilities/content.php` | `hal-content` |

Categories (`includes/categories.php`): `hal-content`, `hal-pages`,
`hal-products`, `hal-media`, `hal-system`.

**Language surface:** write and content abilities accept an optional
`language` validated against `hal_mcp_supported_languages()` — the site's
real configured surface, expanded by the active translation integration
(WPML/Polylang) and never a hardcoded list. Unsupported languages are
refused with the available list; no quality claims are made for any model.

**Content-type boundary:** `hal/*-content` abilities operate only on the
public, non-internal post types authorized for this plugin (filterable,
with re-validation); `post`/`page`/`product` are always routed to their
specialized abilities, and `hal_mcp_request`, users, orders, and options
are never reachable.

---

## Read abilities — direct effect

| Ability | Notes |
|---|---|
| `hal/get-site-inventory` | Returns the safe model summary of the environment inventory (per-site, fingerprint-invalidated): plugin/theme identities and states, public post types, operation statuses with reasons. No PHP version, capability maps, paths, or internal notes. `force_refresh` recomputes the local inventory — it never triggers a networked update check. |
| `hal/get-post` / `hal/get-product` | Single-object read with per-target read permission; products read via WooCommerce CRUD with `unsupported_fields` reported honestly; variations read with the parent relation explicit. |
| `hal/search-posts` / `hal/search-pages` / `hal/search-products` / `hal/search-content` | Bounded pagination (default 10, capped), per-result read permission, optional language filter against the real surface. `include_drafts` widens the status window only — never private or others' content without capability. |
| `hal/get-page` | Returns editor identity (Gutenberg block markup as source — never rendered HTML — or a bounded Elementor section outline with unverified elements reported), plus `seo_summary` from the SEO integration when one is active and honest refusal text when none is. |
| `hal/list-media` | Dimensions/alt/language metadata, server paths never exposed, unreadable parents reported as 0. |
| `hal/get-request-status` | State-only answer for the authorized requester; never a full snapshot for an unauthorized user. |

## Write abilities — direct, requested, or refused

**The rule that matters most:** the *policy* decides the path from the
target's live state, in code — the model's `requested_status` (`draft`/
`publish`) is input, never a decision.

| Ability | Direct | Change request | Refused |
|---|---|---|---|
| `hal/create-post` / `hal/create-page` / `hal/create-content` | Creates a draft (always, unless `requested_status=publish` → draft + approval request) | publish effect | unauthorized types/caps |
| `hal/update-post` / `hal/update-page` / `hal/update-content` / `hal/update-product` | edits in place when target is a draft the user may edit | any protected state or protected-affecting field (SEO fields, featured image, price/stock, design) | trashed targets; no-op payloads |
| `hal/create-product` | Simple/Variable via WooCommerce CRUD, type preserved (never a Simple container for proposals) | publish effect | unsupported product types (their writable fields are listed honestly) |
| `hal/upload-media` | base64 upload with per-type content proof in a private temp location before moving, size capped to the site's own limit, temp cleaned on every failure path | attaching to/altering media used by published content | unverifiable types (e.g. PDF without fileinfo); executables always |

Change requests carry the full §4.3 field set (kind, operation, targets,
sanitized change payload, original/proposed fingerprints, requester and
editor identities, language, state). Editing the proposal returns it to
`pending` and voids the prior approval; a changed original at apply time
becomes `conflict` — never a silent overwrite. Design requests
(`update-page` carrying editor content) additionally require server-derived
`ready` readiness: a real editor serialization must be ingested for this
exact request version before approval is possible (`needs_editor` blocks
approval), and unverified block names are sanitized and reported, never
blessed.

---

## Integration limits (by design, not oversight)

- **SEO** (`integrations/seo.php`): with Yoast active, `seo_title` and
  `seo_description` are writable through Yoast's documented Metadata API,
  verified by read-back; canonical/robots/social/schema are reported with
  their constraint reason and are **not** writable — no silent generation.
  Without an SEO tool, content optimization continues and metadata states
  its absence.
- **Translations** (`integrations/translations.php`): WPML and Polylang
  branches implement assign-language/read-links/link-translations through
  each system's official interfaces, with live group reads and explicit
  refusals for conflicting assignments. With neither system, language
  operations refuse honestly — no fabricated relations.
- **Blocks/Elementor** (`integrations/blocks.php`, `elementor.php`): design
  writes require the real editor's serialization (the `editor.js` bridge)
  for the exact request version; Elementor saves go through the Document
  API with cache clearing and refuse unverifiable widgets; raw HTML
  flattening of either editor is not a path this plugin takes.
- **Environment** (`includes/environment.php`): discovery reads the
  integrations registry, never plugin-name guesses; per-site cached with
  fingerprint invalidation; no networked checks inside discovery.

## Known limitations (documented, not hidden)

- **Operative verification is pending.** Everything above is proven against
  in-memory stubs/fixtures; live WordPress, live provider APIs, live
  Elementor/Yoast/WPML/Polylang runtimes, and browser REST semantics are
  not yet exercised. Nothing here claims operational success.
- The audit log has no retention/cleanup policy; plan housekeeping for
  sustained traffic.
- Product proposal storage for WooCommerce follows the stored request
  payload (type preserved); the v1 "SKU-stripping proposal draft" pattern
  is gone with the draft-proposal mechanism itself.
- Old proposal posts created by the v1 draft-proposal flow (if any exist on
  a migrated site) remain untouched — never auto-published, deleted, or
  approved.
