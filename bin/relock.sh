#!/usr/bin/env bash
#
# Regenerates composer.lock the way every future dependency change needs
# to. Run this any time composer.json's require/require-dev changes,
# instead of a plain `composer update` in this checkout.
#
# Why not just `composer update` here directly: this machine's real
# ../euromail-php sibling, if you have one checked out for local SDK
# development, wins path-repository priority over the vcs repository and
# locks euromail/euromail-php to whatever's on disk there (e.g. dev-main)
# instead of the actual published tag — the exact deploy-breaking bug
# this script exists to prevent recurring (composer install, what CI and
# every real install runs, replays the lock's already-resolved source
# exactly; it fails outright anywhere that local sibling doesn't exist).
#
# Why an isolated copy with an EMPTY stand-in ../euromail-php, rather than
# no sibling directory at all: composer's path repository throws a hard
# error ("the url supplied for the path repository does not exist") when
# the directory is missing outright, so composer update can't run at all
# with the path repository still declared in composer.json and no
# sibling present anywhere. An existing-but-empty directory satisfies
# that existence check, finds no installable package there, and falls
# through to the vcs repository correctly — while still resolving
# against composer.json exactly as committed (path repository entry
# included), so the regenerated lock's content-hash actually matches and
# `composer install` doesn't warn "lock file is not up to date" on every
# future run.
set -euo pipefail

REPO_ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
WORKDIR="$(mktemp -d)"
trap 'rm -rf "${WORKDIR}"' EXIT

echo "Assembling an isolated copy in ${WORKDIR}..."
mkdir -p "${WORKDIR}/euromail-php" # Empty stand-in sibling — see above.
rsync -a --exclude=.git --exclude=node_modules --exclude=vendor --exclude=composer.lock \
	"${REPO_ROOT}/" "${WORKDIR}/wordpress-sendmail/"

cd "${WORKDIR}/wordpress-sendmail"
composer update --no-interaction

cp composer.lock "${REPO_ROOT}/composer.lock"

echo "composer.lock regenerated from the vcs source and copied back to ${REPO_ROOT}."
echo "Next: run 'composer install' in ${REPO_ROOT} to refresh your own vendor/, then run the test suite."
