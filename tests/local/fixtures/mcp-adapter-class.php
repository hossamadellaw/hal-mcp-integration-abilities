<?php
/**
 * Test fixture — NOT plugin code.
 *
 * A stand-in for the official WordPress MCP Adapter's main class so the
 * local tests can simulate the adapter being active. Requiring this file is
 * what flips class_exists( 'WP\MCP\Core\McpAdapter' ) to true; the
 * environment inventory's live channel check (and only that check) reacts
 * to it. Synthetic and declared as such — it proves nothing about the real
 * adapter beyond the detection marker, which is quoted from the Adapter
 * v0.6.1 README.
 *
 * @package hal-mcp-abilities (tests only)
 */

namespace WP\MCP\Core;

class McpAdapter {
}
