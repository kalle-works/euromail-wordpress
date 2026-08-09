#!/usr/bin/env bash
#
# Installs a built plugin zip into a throwaway, freshly-installed WordPress
# site (official wordpress + mariadb images, no source mount — the plugin
# only exists as the zip, exactly like a real wordpress.org install) and
# confirms it activates without a fatal error. Run before ever deploying a
# build to the wordpress.org SVN repo.
set -euo pipefail

ZIP_PATH="${1:?Usage: verify-zip-install.sh <path-to-plugin-zip>}"
ZIP_PATH="$(cd "$(dirname "$ZIP_PATH")" && pwd)/$(basename "$ZIP_PATH")"

RUN_ID="$$-${RANDOM}"
NETWORK="euromail-verify-net-${RUN_ID}"
DB_CONTAINER="euromail-verify-db-${RUN_ID}"
WP_CONTAINER="euromail-verify-wp-${RUN_ID}"
VOLUME="euromail-verify-html-${RUN_ID}"

cleanup() {
	docker rm -f "$WP_CONTAINER" "$DB_CONTAINER" >/dev/null 2>&1 || true
	docker volume rm "$VOLUME" >/dev/null 2>&1 || true
	docker network rm "$NETWORK" >/dev/null 2>&1 || true
}
trap cleanup EXIT

echo "Starting a throwaway WordPress install to verify ${ZIP_PATH}..."

docker network create "$NETWORK" >/dev/null
docker volume create "$VOLUME" >/dev/null

docker run -d --name "$DB_CONTAINER" --network "$NETWORK" \
	-e MARIADB_ROOT_PASSWORD=root \
	-e MARIADB_DATABASE=wordpress \
	mariadb:11 >/dev/null

docker run -d --name "$WP_CONTAINER" --network "$NETWORK" \
	-e WORDPRESS_DB_HOST="${DB_CONTAINER}" \
	-e WORDPRESS_DB_NAME=wordpress \
	-e WORDPRESS_DB_USER=root \
	-e WORDPRESS_DB_PASSWORD=root \
	-v "${VOLUME}:/var/www/html" \
	wordpress:php8.2-apache >/dev/null

wp() {
	docker run --rm --network "$NETWORK" \
		-v "${VOLUME}:/var/www/html" \
		-e WORDPRESS_DB_HOST="${DB_CONTAINER}" \
		-e WORDPRESS_DB_NAME=wordpress \
		-e WORDPRESS_DB_USER=root \
		-e WORDPRESS_DB_PASSWORD=root \
		--user root \
		wordpress:cli wp "$@"
}

echo "Waiting for MariaDB to accept connections..."
for i in $(seq 1 30); do
	if docker exec "$DB_CONTAINER" mariadb-admin ping -uroot -proot --silent >/dev/null 2>&1; then
		break
	fi
	if [ "$i" -eq 30 ]; then
		echo "MariaDB never became ready." >&2
		exit 1
	fi
	sleep 2
done

echo "Waiting for wp-config.php to exist (written by the wordpress:apache entrypoint)..."
for i in $(seq 1 30); do
	if docker run --rm -v "${VOLUME}:/var/www/html" alpine test -f /var/www/html/wp-config.php >/dev/null 2>&1; then
		break
	fi
	if [ "$i" -eq 30 ]; then
		echo "wp-config.php was never created." >&2
		exit 1
	fi
	sleep 2
done

echo "Waiting for wp-cli to actually reach the database through wp-config.php..."
for i in $(seq 1 30); do
	if wp db check --allow-root >/dev/null 2>&1; then
		break
	fi
	if [ "$i" -eq 30 ]; then
		echo "wp-cli could never connect to the database via wp-config.php." >&2
		exit 1
	fi
	sleep 2
done

wp core install \
	--allow-root \
	--url=http://localhost \
	--title="Euromail zip verification" \
	--admin_user=admin \
	--admin_password=password \
	--admin_email=admin@example.com


# Copied onto the shared named volume (not just the apache container's own
# filesystem) so the separate, ephemeral wp-cli containers spawned by wp()
# can see it too — they only mount the volume, not each other's /tmp.
docker cp "$ZIP_PATH" "${WP_CONTAINER}:/var/www/html/euromail.zip"

wp plugin install /var/www/html/euromail.zip --activate --allow-root

wp eval '
if ( ! class_exists( "Euromail_Plugin" ) ) {
	fwrite( STDERR, "Euromail_Plugin class not found after activation.\n" );
	exit( 1 );
}
echo "Euromail_Plugin loaded and active.\n";
' --allow-root

ACTIVE=$(wp plugin list --field=status --name=euromail --allow-root 2>/dev/null || true)

if [ "$ACTIVE" != "active" ]; then
	echo "Plugin did not report as active (status: '${ACTIVE:-<empty>}')." >&2
	exit 1
fi

echo "Zip install verification passed: the plugin activates cleanly from a real zip on a fresh WordPress install."
