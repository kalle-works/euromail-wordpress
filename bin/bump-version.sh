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

perl -pi -e "s/^ \* Version:           .*/ * Version:           ${VERSION}/" euromail.php
perl -pi -e "s/define\( 'EUROMAIL_VERSION', '.*' \);/define( 'EUROMAIL_VERSION', '${VERSION}' );/" euromail.php

perl -pi -e "s/^Stable tag: .*/Stable tag: ${VERSION}/" readme.txt
perl -pi -e "s/== Changelog ==/== Changelog ==\n\n= ${VERSION} =\n* See CHANGELOG.md for the full list of changes in this release./" readme.txt

# package.json's own "version" isn't published anywhere, but keeping it in
# sync avoids a confusing mismatch for anyone reading the file directly.
node -e "
const fs = require('fs');
const pkg = JSON.parse(fs.readFileSync('package.json', 'utf8'));
pkg.version = '${VERSION}';
fs.writeFileSync('package.json', JSON.stringify(pkg, null, 2) + '\n');
"
