=== HAL MCP Integration Abilities ===
Contributors: hossamadellaw
Requires at least: 6.9
Tested up to: 6.9
Requires PHP: 8.0
Stable tag: 2.0.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Exposes restricted hal/* WordPress Abilities for reading and drafting site content through the official WordPress MCP Adapter.

== Description ==

This plugin registers a restricted family of hal/* Abilities (WordPress Abilities API, WordPress 6.9+) and exposes them through the official WordPress MCP Adapter, so a configured AI provider can read site state and draft content changes — every protected change stays pending until an administrator approves it in wp-admin.

* Read Abilities for posts, pages, products, media, and system state.
* Write Abilities that draft changes under an approval gate; publishing a protected change happens only after an admin approval, never from the model path.
* Provider runner with selectable provider/model, previews, and internal tool calls over the official MCP channel; API keys are stored encrypted, never echoed back, never logged.
* Editor-aware integrations (Gutenberg blocks, Elementor), SEO metadata, and translations, adapted to what is actually installed on the site.
* Update checks against this plugin's GitHub Releases (Plugin Update Checker); release ZIPs are enforced — no silent fallback to source archives.

This is a private, self-hosted plugin distributed from
https://github.com/hossamadellaw/hal-mcp-integration-abilities — it is not listed on WordPress.org.

== Installation ==

1. Download the release ZIP (hal-mcp-integration-abilities-X.Y.Z.zip) from the repository's Releases page.
2. Install it through wp-admin → Plugins → Add New → Upload Plugin, or unzip it into wp-content/plugins/.
3. The plugin registers its Abilities automatically on sites running WordPress 6.9+.

== Changelog ==

= 2.0.0 =
* Initial release of the 2.0 line: restricted hal/* Abilities for reading and drafting posts, pages, products, and media, exposed through the official WordPress MCP Adapter.
* Approval gate: protected changes remain pending until an administrator approves them in wp-admin; model/Provider paths can never publish or elevate permissions on their own.
* Provider runner and settings with encrypted secret storage, connection tests, and per-provider model selection.
* Environment and integrations discovery for block and Elementor editors, SEO metadata, and translation plugins.
* GitHub Releases update system with fail-closed release-asset enforcement and contained updater initialization.
