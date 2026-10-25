#!/usr/bin/env bash
#
# Bump the plugin version everywhere it is declared, and optionally the compatibility headers.
#
#   bin/bump-version.sh <version> [--wp-min=X.Y] [--wp-tested=X.Y] [--wc-min=X.Y] [--wc-tested=X.Y]
#
# Updates: main file "Version" header + PIXALINK_LOYALTY_VERSION, readme.txt "Stable tag", package.json,
# and adds a changelog.txt / readme.txt changelog heading when missing. Compatibility flags update the main
# file headers, readme.txt and Requirements::MIN_WC_VERSION so they never drift apart.
set -euo pipefail

usage() {
	sed -n '3,10p' "$0" | sed 's/^# \{0,1\}//'
	exit 2
}

[ $# -ge 1 ] || usage
NEW="$1"
shift
if ! printf '%s' "$NEW" | grep -Eq '^[0-9]+\.[0-9]+\.[0-9]+(-[0-9A-Za-z.-]+)?$'; then
	echo "Version must look like 1.2.3 or 1.2.3-beta.1 (got '$NEW')." >&2
	exit 2
fi

WP_MIN=""
WP_TESTED=""
WC_MIN=""
WC_TESTED=""
for arg in "$@"; do
	case "$arg" in
		--wp-min=*) WP_MIN="${arg#*=}" ;;
		--wp-tested=*) WP_TESTED="${arg#*=}" ;;
		--wc-min=*) WC_MIN="${arg#*=}" ;;
		--wc-tested=*) WC_TESTED="${arg#*=}" ;;
		*) usage ;;
	esac
done

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
MAIN="$ROOT/pixalink-loyalty-for-woocommerce.php"
README="$ROOT/readme.txt"
CHANGELOG="$ROOT/changelog.txt"
REQUIREMENTS="$ROOT/src/Requirements.php"
TODAY="$(date -u +%Y-%m-%d)"

export NEW WP_MIN WP_TESTED WC_MIN WC_TESTED TODAY

perl -pi -e 's/^( \* Version:\s+).*$/$1$ENV{NEW}/' "$MAIN"
perl -pi -e "s/define\\( 'PIXALINK_LOYALTY_VERSION', '[^']*' \\);/define( 'PIXALINK_LOYALTY_VERSION', '\$ENV{NEW}' );/" "$MAIN"
perl -pi -e 's/^(Stable tag:\s+).*$/$1$ENV{NEW}/' "$README"
(cd "$ROOT" && npm pkg set version="$NEW" >/dev/null)

if [ -n "$WP_MIN" ]; then
	perl -pi -e 's/^( \* Requires at least:\s+).*$/$1$ENV{WP_MIN}/' "$MAIN"
	perl -pi -e 's/^(Requires at least:\s+).*$/$1$ENV{WP_MIN}/' "$README"
fi
if [ -n "$WP_TESTED" ]; then
	perl -pi -e 's/^(Tested up to:\s+).*$/$1$ENV{WP_TESTED}/' "$README"
fi
if [ -n "$WC_MIN" ]; then
	perl -pi -e 's/^( \* WC requires at least:\s+).*$/$1$ENV{WC_MIN}/' "$MAIN"
	perl -pi -e "s/(MIN_WC_VERSION\\s+=\\s+')[^']*(';)/\${1}\$ENV{WC_MIN}\${2}/" "$REQUIREMENTS"
fi
if [ -n "$WC_TESTED" ]; then
	perl -pi -e 's/^( \* WC tested up to:\s+).*$/$1$ENV{WC_TESTED}/' "$MAIN"
fi

if ! grep -q "^= $NEW " "$CHANGELOG"; then
	perl -0pi -e 's/(\*\*\* Pixalink Loyalty for WooCommerce Changelog \*\*\*\n\n)/$1= $ENV{NEW} - $ENV{TODAY} =\n* \n\n/' "$CHANGELOG"
fi
if ! grep -q "^= $NEW =" "$README"; then
	perl -0pi -e 's/(== Changelog ==\n\n)/$1= $ENV{NEW} =\n* \n\n/' "$README"
fi

grep -n "Version:\|PIXALINK_LOYALTY_VERSION'\|Requires at least\|WC requires at least\|WC tested up to" "$MAIN"
grep -n "Stable tag\|Tested up to\|Requires at least" "$README"
grep -n "MIN_WC_VERSION  =" "$REQUIREMENTS"
echo "Bumped to $NEW. Fill in the changelog entries before tagging v$NEW."
