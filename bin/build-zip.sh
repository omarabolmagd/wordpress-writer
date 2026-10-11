#!/usr/bin/env bash
# Builds dist/ana-afdal-writer.zip: the installable plugin without tests or tooling.
set -euo pipefail

ROOT="$(cd "$(dirname "$0")/.." && pwd)"
OUT="$ROOT/dist"
STAGE="$OUT/ana-afdal-writer"

rm -rf "$OUT"
mkdir -p "$STAGE"
cp -r "$ROOT/ana-afdal-writer.php" "$ROOT/uninstall.php" "$ROOT/src" "$ROOT/admin" "$ROOT/assets" "$ROOT/languages" "$STAGE/"
cp "$ROOT/README.md" "$STAGE/README.md"

(cd "$OUT" && zip -qr ana-afdal-writer.zip ana-afdal-writer)
rm -rf "$STAGE"
echo "Built $OUT/ana-afdal-writer.zip ($(du -h "$OUT/ana-afdal-writer.zip" | cut -f1))"
