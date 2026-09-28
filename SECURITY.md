# Security Policy

## Reporting a vulnerability

Please report security issues **privately** — do not open a public GitHub issue with exploit details.

1. Preferred channel: GitHub **private vulnerability reporting** on this repository
   (<https://github.com/hossamadellaw/hal-mcp-integration-abilities/security/advisories/new>), if the feature is enabled.
   Writing this file does not itself enable that feature; the repository owner enables it under
   *Settings → Code security and analysis → Private vulnerability reporting*.
2. If private reporting is unavailable, contact the repository owner (Hossam Adel) through the private contact
   details published on hossamadellaw.com, and clearly mark the message as a security report.

Reports should include: affected version tag, a minimal description or proof of concept, and the impact you observed.
Please allow a reasonable time for a fix before any public disclosure.

## Scope

* This plugin's PHP/JS/CSS code, its Abilities and permission checks, the approval gate, the MCP/adapter surface,
  provider secret handling, and the update-checker integration.
* Out of scope: vulnerabilities in WordPress core, third-party plugins, or hosting infrastructure; social engineering;
  automated load or exploit traffic against hossamadellaw.com.

## Handling of secrets

* Provider API keys are never stored in this repository, never written into settings, and never echoed back to a
  browser or log; they are encrypted server-side and decrypted only into a request header at call time.
* Never send real keys, tokens, or site credentials in a report — use obviously fake values.
* The update system requires no credentials on a public repository. If the repository is ever made private, its
  read-only token must live in wp-config.php or the hosting environment, never in Git or in the plugin files.

## Supported versions

Security fixes are released for the latest release tag (vX.Y.Z) only.
