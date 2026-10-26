#!/usr/bin/env bash
#
# Make sure the Docker Hub images a wp-env needs are tagged on this runner, pulling each one only when it is
# missing or was last pulled more than MAX_AGE_DAYS ago.
#
#   bin/ci/ensure-images.sh wordpress:php8.3 wordpress:cli-php8.3 mariadb:lts phpmyadmin:latest
#
# Why: wp-env builds its WordPress and CLI images FROM wordpress:*. BuildKit reads a base image that is tagged
# locally without contacting the registry, but resolves an untagged one against Docker Hub on every build. The
# runners share one public IP and Docker Hub allows it 100 anonymous pulls an hour, so a full run (plus Explore's
# CI) hit 429 Too Many Requests and wp-env failed to start. wp-env's own `docker compose pull` still runs, but it
# tolerates a failed pull when the image is already here.
#
# Pull times are recorded in STAMP_DIR (default /opt/ci-cache/docker-pulls). A failed refresh of an image that is
# already here is only a warning; a missing image that cannot be pulled fails the job.
set -uo pipefail

MAX_AGE_DAYS="${MAX_AGE_DAYS:-7}"
STAMP_DIR="${STAMP_DIR:-/opt/ci-cache/docker-pulls}"
mkdir -p "$STAMP_DIR" 2>/dev/null && [ -w "$STAMP_DIR" ] || STAMP_DIR="${RUNNER_TEMP:-/tmp}/docker-pulls"
mkdir -p "$STAMP_DIR"

status=0
for image in "$@"; do
	stamp="$STAMP_DIR/$(printf '%s' "$image" | tr '/:' '__')"
	if docker image inspect "$image" >/dev/null 2>&1; then
		if [ -n "$(find "$stamp" -mtime -"$MAX_AGE_DAYS" 2>/dev/null)" ]; then
			echo "$image: present, pulled within $MAX_AGE_DAYS days"
			continue
		fi
		if docker pull -q "$image" >/dev/null; then
			touch "$stamp"
			echo "$image: refreshed"
		else
			echo "::warning::Could not refresh $image; using the copy already on ${RUNNER_NAME:-this runner}."
		fi
		continue
	fi
	for attempt in 1 2 3; do
		if docker pull -q "$image" >/dev/null; then
			touch "$stamp"
			echo "$image: pulled"
			continue 2
		fi
		echo "Pulling $image failed (attempt $attempt)"
		sleep 20
	done
	echo "::error::$image is not on ${RUNNER_NAME:-this runner} and could not be pulled (Docker Hub rate limit?)."
	status=1
done
exit "$status"
