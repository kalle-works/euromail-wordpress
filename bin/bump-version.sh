#!/usr/bin/env bash
#
# Bumps every place the plugin's version number is recorded, called by
# semantic-release's @semantic-release/exec prepareCmd with the new
# version as $1. Run from the repo root.
#
# Uses `perl -pi` rather than `sed -i`: sed's -i takes a mandatory backup
# suffix argument on BSD/macOS but not on GNU/Linux, so the same command
# doesn't work unmodified on both — perl's -i behaves identically on both,
# which matters here since this script runs both in CI (Linux) and locally
# during development (macOS).
set -euo pipefail

VERSION="${1:?Usage: bump-version.sh <version>}"

# semantic-release's prepareCmd only runs when it has already decided to
# release, so this file's mere existence afterward is release.yml's signal
# that a release actually happened (raw `npx semantic-release` gives no
# other easy way to tell from the shell).
echo "${VERSION}" > .next-version

# A pattern that stops matching after some unrelated formatting change
# (e.g. the fixed-width alignment in euromail.php's header block getting
# reflowed) must fail loudly, not silently leave the old version in
# place — a no-op sed/perl substitution exits 0 either way. Checks for the
# EXACT string this specific substitution should have produced, not just
# "the new version appears somewhere in the file" — two substitutions can
# target the same file (euromail.php, readme.txt both have two), and a
# working second substitution would otherwise mask a silently-broken
# first one.
assert_contains() {
	local file="$1" needle="$2"

	if ! grep -qF -- "$needle" "$file"; then
		echo "bump-version.sh: expected to find \"${needle}\" in ${file} after that substitution, but it's not there — the pattern didn't match anything (a silent no-op). Fix the pattern in bin/bump-version.sh." >&2
		exit 1
	fi
}

# \s* rather than a fixed run of spaces: tolerant of the exact alignment
# changing (e.g. phpcbf reflowing the header block) without silently
# stopping the version substitution from matching at all.
perl -pi -e "s/^ \* Version:\s*.*/ * Version:           ${VERSION}/" euromail.php
assert_contains euromail.php " * Version:           ${VERSION}"

perl -pi -e "s/define\(\s*'EUROMAIL_VERSION',\s*'.*'\s*\);/define( 'EUROMAIL_VERSION', '${VERSION}' );/" euromail.php
assert_contains euromail.php "define( 'EUROMAIL_VERSION', '${VERSION}' );"

perl -pi -e "s/^Stable tag:\s*.*/Stable tag: ${VERSION}/" readme.txt
assert_contains readme.txt "Stable tag: ${VERSION}"

perl -pi -e "s/== Changelog ==/== Changelog ==\n\n= ${VERSION} =\n* See CHANGELOG.md for the full list of changes in this release./" readme.txt
assert_contains readme.txt "= ${VERSION} ="

# package.json's own "version" isn't published anywhere, but keeping it in
# sync avoids a confusing mismatch for anyone reading the file directly.
node -e "
const fs = require('fs');
const pkg = JSON.parse(fs.readFileSync('package.json', 'utf8'));
pkg.version = '${VERSION}';
fs.writeFileSync('package.json', JSON.stringify(pkg, null, 2) + '\n');
"
assert_contains package.json "\"version\": \"${VERSION}\""

# package-lock.json (lockfileVersion 3) records the package's own version
# in two places: the top-level "version" and packages[""].version (the
# root package entry). npm itself treats a mismatch between package.json
# and package-lock.json as worth warning about on the next `npm install`,
# so both are kept in sync here, and the node script itself throws (rather
# than silently skipping) if packages[""] isn't there to update.
node -e "
const fs = require('fs');
const lock = JSON.parse(fs.readFileSync('package-lock.json', 'utf8'));
lock.version = '${VERSION}';
if (!lock.packages || !lock.packages['']) {
	throw new Error('package-lock.json has no packages[\"\"] entry to bump — lockfile format changed?');
}
lock.packages[''].version = '${VERSION}';
fs.writeFileSync('package-lock.json', JSON.stringify(lock, null, 2) + '\n');
"
# Both fields hold the identical string, so this only confirms at least
# one of the two substitutions stuck; the node script's own thrown error
# above is what actually guards the packages[""] one specifically.
assert_contains package-lock.json "\"version\": \"${VERSION}\""
