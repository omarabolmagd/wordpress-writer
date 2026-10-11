#!/usr/bin/env bash
# Starts (or restarts) the local test servers in the background:
#   http://localhost:8080  WordPress, through tests/router.php
#   http://localhost:8081  the mock Claude API (tests/mock-anthropic-server.php)
#
# Usage: tests/serve.sh /path/to/wordpress [log-dir]
set -euo pipefail

WP_DIR="$(cd "${1:?path to the WordPress folder}" && pwd)"
LOG_DIR="${2:-/tmp}"
PLUGIN_DIR="$(cd "$(dirname "$0")/.." && pwd)"

pkill -f '^php -S localhost:808[01]' 2>/dev/null || true
sleep 1

export NO_PROXY=localhost,127.0.0.1 no_proxy=localhost,127.0.0.1
AAW_MOCK_SEARCH_MS="${AAW_MOCK_SEARCH_MS:-1800}" AAW_MOCK_DELAY_MS="${AAW_MOCK_DELAY_MS:-35}" AAW_WP_DIR="$WP_DIR" PHP_CLI_SERVER_WORKERS=2 nohup php -S localhost:8081 "$PLUGIN_DIR/tests/mock-anthropic-server.php" > "$LOG_DIR/aaw-mock-claude.log" 2>&1 &
PHP_CLI_SERVER_WORKERS=4 nohup php -S localhost:8080 -t "$WP_DIR" "$PLUGIN_DIR/tests/router.php" > "$LOG_DIR/aaw-wordpress.log" 2>&1 &
sleep 2
echo "WordPress on http://localhost:8080, mock Claude on http://localhost:8081"
