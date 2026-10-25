#!/usr/bin/env bash
#
# Regenerate languages/pixalink-loyalty-for-woocommerce.pot with WP-CLI's i18n command.
# Uses `wp` from PATH when available, otherwise downloads wp-cli.phar into .cache/ (gitignored).
set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
SLUG="pixalink-loyalty-for-woocommerce"
PHAR="$ROOT/.cache/wp-cli.phar"

if command -v wp >/dev/null 2>&1; then
	WP=(wp)
else
	if [ ! -f "$PHAR" ]; then
		mkdir -p "$ROOT/.cache"
		curl -sSfL -o "$PHAR" https://raw.githubusercontent.com/wp-cli/builds/gh-pages/phar/wp-cli.phar
	fi
	WP=(php "$PHAR")
fi

mkdir -p "$ROOT/languages"
"${WP[@]}" i18n make-pot "$ROOT" "$ROOT/languages/$SLUG.pot" \
	--slug="$SLUG" \
	--domain="$SLUG" \
	--package-name="Pixalink Loyalty for WooCommerce" \
	--exclude=node_modules,vendor,build,dist,tests,bin,artifacts,.cache \
	--headers='{"Report-Msgid-Bugs-To":"https://pixalink.io"}'

echo "$ROOT/languages/$SLUG.pot"
