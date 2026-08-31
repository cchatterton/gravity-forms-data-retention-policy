#!/usr/bin/env bash
set -euo pipefail

PLUGIN_SLUG="gravity-forms-data-retention-policy"
REPOSITORY_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
DIST_DIR="$REPOSITORY_DIR/dist"
PACKAGE_DIR="$DIST_DIR/$PLUGIN_SLUG"

rm -rf "$PACKAGE_DIR"
rm -f "$DIST_DIR/$PLUGIN_SLUG.zip" "$REPOSITORY_DIR/$PLUGIN_SLUG.zip"
mkdir -p "$DIST_DIR"
cp -R "$REPOSITORY_DIR/$PLUGIN_SLUG" "$PACKAGE_DIR"

find "$PACKAGE_DIR" -name '.DS_Store' -delete
rm -rf "$PACKAGE_DIR/node_modules"

(
	cd "$DIST_DIR"
	zip -qr "$PLUGIN_SLUG.zip" "$PLUGIN_SLUG"
)

cp "$DIST_DIR/$PLUGIN_SLUG.zip" "$REPOSITORY_DIR/$PLUGIN_SLUG.zip"
