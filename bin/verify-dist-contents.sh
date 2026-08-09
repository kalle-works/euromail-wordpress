#!/usr/bin/env bash
#
# Asserts the assembled distributable plugin directory (built via
# `rsync --exclude-from=.distignore`, see release.yml) has the shape a
# real WordPress.org install needs: none of the dev-only files leaked in,
# and the runtime dependencies genuinely did.
set -euo pipefail

DIST_DIR="${1:?Usage: verify-dist-contents.sh <path-to-assembled-dist-dir>}"

FAILED=0

must_be_absent() {
	if [ -e "${DIST_DIR}/$1" ]; then
		echo "FAIL: '$1' must not be present in the distributable build, but it is." >&2
		FAILED=1
	fi
}

must_be_present() {
	if [ ! -e "${DIST_DIR}/$1" ]; then
		echo "FAIL: '$1' must be present in the distributable build, but it is missing." >&2
		FAILED=1
	fi
}

must_be_absent "tests"
must_be_absent "e2e"
must_be_absent ".wp-env.json"
must_be_absent ".wp-env-override.json"
must_be_absent "node_modules"
must_be_absent ".git"
must_be_absent ".github"
must_be_absent "composer.json"
must_be_absent "composer.lock"
must_be_absent "package.json"
must_be_absent "package-lock.json"
must_be_absent "phpunit.xml"
must_be_absent "phpcs.xml.dist"
must_be_absent "bin"
must_be_absent ".wordpress-org"
# A leftover build/ or pkg/ directory from a previous local run, left in
# the checkout that gets rsynced, would otherwise get copied into the new
# build/ verbatim (rsync --exclude-from=.distignore only excludes what's
# listed there) — a nested build/build/ (or a stale zip) shipped inside
# the plugin itself.
must_be_absent "build"
must_be_absent "pkg"

must_be_present "vendor/autoload.php"
must_be_present "vendor/euromail/euromail-php"
must_be_present "euromail.php"
must_be_present "uninstall.php"
must_be_present "readme.txt"
must_be_present "includes"
must_be_present "languages/euromail.pot"

if [ "$FAILED" -ne 0 ]; then
	echo "Distributable build contents check FAILED." >&2
	exit 1
fi

echo "Distributable build contents check passed: no dev files leaked in, all runtime files present."
