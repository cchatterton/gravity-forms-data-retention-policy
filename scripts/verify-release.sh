#!/usr/bin/env bash
set -euo pipefail

PLUGIN_SLUG="gravity-forms-data-retention-policy"
EXPECTED_VERSION="1.2.3"
REPOSITORY_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
PLUGIN_DIR="$REPOSITORY_DIR/$PLUGIN_SLUG"
MAIN_FILE="$PLUGIN_DIR/$PLUGIN_SLUG.php"
ZIP_FILE="$REPOSITORY_DIR/$PLUGIN_SLUG.zip"
DIST_ZIP_FILE="$REPOSITORY_DIR/dist/$PLUGIN_SLUG.zip"

grep -q "^ \* Version: $EXPECTED_VERSION$" "$MAIN_FILE"
grep -q '^ \* Requires at least: 6.0$' "$MAIN_FILE"
grep -q "define( 'GFDRP_VERSION', '$EXPECTED_VERSION' );" "$MAIN_FILE"
grep -q "^Stable tag: $EXPECTED_VERSION$" "$PLUGIN_DIR/readme.txt"
grep -q '"version": "1.2.3"' "$REPOSITORY_DIR/update.json"
grep -q '^ \* Update URI: https://github.com/cchatterton/gravity-forms-data-retention-policy$' "$MAIN_FILE"
grep -q '^ \* License: GPL v2 or later$' "$MAIN_FILE"

if grep -q '^ \* Plugin URI:' "$MAIN_FILE"; then
	echo "Plugin URI must not be declared." >&2
	exit 1
fi

while IFS= read -r php_file; do
	php -l "$php_file" >/dev/null
done < <(find "$PLUGIN_DIR" -type f -name '*.php' -print)

test -f "$PLUGIN_DIR/LICENSE"
test -f "$PLUGIN_DIR/readme.txt"
test -f "$ZIP_FILE"
test -f "$DIST_ZIP_FILE"
cmp -s "$ZIP_FILE" "$DIST_ZIP_FILE"

zip_listing="$(unzip -Z1 "$ZIP_FILE")"

if printf '%s\n' "$zip_listing" | grep -Ev "^$PLUGIN_SLUG/" >/dev/null; then
	echo "The release ZIP contains files outside $PLUGIN_SLUG/." >&2
	exit 1
fi

if printf '%s\n' "$zip_listing" | grep -Eq '(^|/)(\.git|node_modules|\.DS_Store)(/|$)|\.zip$'; then
	echo "The release ZIP contains a prohibited development artifact." >&2
	exit 1
fi

printf 'Release verification passed for %s %s.\n' "$PLUGIN_SLUG" "$EXPECTED_VERSION"
