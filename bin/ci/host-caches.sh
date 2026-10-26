#!/usr/bin/env bash
#
# Pick the download caches for a job that runs on the runner host (wp-env, Plugin Check) and export them to
# $GITHUB_ENV:
#
#   npm_config_cache     npm and npx (wp-env itself)          /opt/ci-cache/npm-host
#   HOST_COMPOSER_CACHE  the composer:2 container's cache     /opt/ci-cache/composer-host
#   WP_ENV_SOURCES       WordPress / WooCommerce zips         /opt/ci-cache/wp-env-sources
#
# The job containers run as root and fill /opt/ci-cache/{npm,composer} with root-owned files, which the runner
# user cannot write: npm then fails and Composer proceeds without a cache, so every host job downloaded its
# dependencies afresh. Host jobs keep caches of their own, owned by the runner user. A cache directory that
# cannot be used falls back to a job-local one with a notice, as before.
set -euo pipefail

usable() {
	mkdir -p "$1" 2>/dev/null || return 1
	[ -w "$1" ] && [ -z "$(find "$1" ! -user "$(id -u)" -print -quit 2>/dev/null)" ]
}

pick() {
	local name="$1" shared="/opt/ci-cache/$2"
	if usable "$shared"; then
		echo "$name=$shared" >> "$GITHUB_ENV"
	else
		echo "::notice::$shared is not writable by $(id -un); using a job-local cache for $name."
		mkdir -p "$RUNNER_TEMP/$2"
		echo "$name=$RUNNER_TEMP/$2" >> "$GITHUB_ENV"
	fi
}

pick npm_config_cache npm-host
pick HOST_COMPOSER_CACHE composer-host
pick WP_ENV_SOURCES wp-env-sources
