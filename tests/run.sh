#!/usr/bin/env bash
# Rebuilds a local WordPress (SQLite) from scratch, seeds it like anaafdal.com,
# activates the plugin and runs the end-to-end test.
#
# Usage: tests/run.sh /path/to/wordpress /path/to/wp-cli.phar
set -euo pipefail

WP_DIR="${1:?path to a WordPress install using the SQLite drop-in}"
WP_CLI="${2:?path to wp-cli.phar}"
PLUGIN_DIR="$(cd "$(dirname "$0")/.." && pwd)"
wp() { php "$WP_CLI" --allow-root --path="$WP_DIR" "$@"; }

rm -rf "$WP_DIR/wp-content/database" "$WP_DIR/wp-content/uploads"
mkdir -p "$WP_DIR/wp-content/database"
: > "$WP_DIR/wp-content/debug.log"
wp core install --url=http://localhost:8080 --title="انا افضل" --admin_user=admin --admin_password=admin --admin_email=test@example.com --skip-email >/dev/null
mkdir -p "$WP_DIR/wp-content/mu-plugins"
cp "$PLUGIN_DIR/tests/mu-random-slugs.php" "$WP_DIR/wp-content/mu-plugins/"
ln -sfn "$PLUGIN_DIR" "$WP_DIR/wp-content/plugins/ana-afdal-writer"
wp plugin activate ana-afdal-writer >/dev/null
wp eval-file "$PLUGIN_DIR/tests/seed-site.php"
wp eval-file "$PLUGIN_DIR/tests/e2e.php"
if grep -v "get_page_by_title" "$WP_DIR/wp-content/debug.log" | grep -q "PHP "; then
	echo "PHP notices or errors were logged:"
	grep -v "get_page_by_title" "$WP_DIR/wp-content/debug.log" | grep "PHP " | head -20
	exit 1
fi
