#!/usr/bin/env bash
#
# Installs a built plugin zip into a throwaway, freshly-installed WordPress
# site (official wordpress + mariadb images, no source mount — the plugin
# only exists as the zip, exactly like a real wordpress.org install),
# confirms it activates without a fatal error, then configures the API
# backend against the project's own e2e mock API, sends a real test email
# through wp_mail(), and confirms the delivery log recorded it. Run before
# ever deploying a build to the wordpress.org SVN repo — this is the one
# check in the whole pipeline that would actually catch a wrong vendor/
# path or a missing runtime file that no unit test can see.
set -euo pipefail

ZIP_PATH="${1:?Usage: verify-zip-install.sh <path-to-plugin-zip>}"
ZIP_PATH="$(cd "$(dirname "$ZIP_PATH")" && pwd)/$(basename "$ZIP_PATH")"

REPO_ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
MOCK_API_SRC="${REPO_ROOT}/e2e/mock-api"

RUN_ID="$$-${RANDOM}"
NETWORK="euromail-verify-net-${RUN_ID}"
DB_CONTAINER="euromail-verify-db-${RUN_ID}"
WP_CONTAINER="euromail-verify-wp-${RUN_ID}"
MOCK_CONTAINER="euromail-verify-mockapi-${RUN_ID}"
VOLUME="euromail-verify-html-${RUN_ID}"
MOCK_API_KEY="em_live_e2etest" # Matches VALID_KEY in e2e/mock-api/server.js.

cleanup() {
	docker rm -f "$WP_CONTAINER" "$DB_CONTAINER" "$MOCK_CONTAINER" >/dev/null 2>&1 || true
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

# Runs on the same isolated Docker network as everything else, addressable
# by container name — avoids relying on host.docker.internal, which isn't
# available the same way on every Docker setup (Docker Desktop vs. native
# Linux). The mock API only uses Node's http/crypto core modules (see
# e2e/mock-api/server.js), so no npm install is needed inside the image.
docker run -d --name "$MOCK_CONTAINER" --network "$NETWORK" \
	-v "${MOCK_API_SRC}:/app:ro" \
	-w /app \
	node:20-alpine \
	node server.js >/dev/null

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

echo "Waiting for the mock API to accept connections..."
for i in $(seq 1 30); do
	if docker run --rm --network "$NETWORK" node:20-alpine \
		node -e "require('http').get('http://${MOCK_CONTAINER}:8825/_requests', r => process.exit(r.statusCode ? 0 : 1)).on('error', () => process.exit(1))" >/dev/null 2>&1; then
		break
	fi
	if [ "$i" -eq 30 ]; then
		echo "The mock API never became ready." >&2
		exit 1
	fi
	sleep 1
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

echo "Configuring the API backend against the mock API and sending a real test email..."

wp option update euromail_backend api --allow-root
wp option update euromail_api_key "${MOCK_API_KEY}" --allow-root
wp option update euromail_api_base_url "http://${MOCK_CONTAINER}:8825" --allow-root
wp option update euromail_force_from_enabled 0 --allow-root

wp eval '
$sent = wp_mail( "recipient@example.com", "Euromail zip verification test", "Sent by bin/verify-zip-install.sh." );

if ( ! $sent ) {
	fwrite( STDERR, "wp_mail() returned false — the test send failed.\n" );
	exit( 1 );
}

global $wpdb;
$row = $wpdb->get_row( "SELECT * FROM {$wpdb->prefix}euromail_log ORDER BY id DESC LIMIT 1", ARRAY_A );

if ( ! $row ) {
	fwrite( STDERR, "wp_mail() reported success but no row was written to the delivery log.\n" );
	exit( 1 );
}

if ( "recipient@example.com" !== $row["mail_to"] ) {
	fwrite( STDERR, "Log row mail_to mismatch: got \"" . $row["mail_to"] . "\".\n" );
	exit( 1 );
}

if ( "sent" !== $row["status"] ) {
	fwrite( STDERR, "Log row status was \"" . $row["status"] . "\", expected \"sent\".\n" );
	exit( 1 );
}

if ( "api" !== $row["backend"] ) {
	fwrite( STDERR, "Log row backend was \"" . $row["backend"] . "\", expected \"api\" (the mock API was not actually used).\n" );
	exit( 1 );
}

echo "Test send succeeded: log row #{$row["id"]} recorded status=sent, backend=api, mail_to=recipient@example.com.\n";
' --allow-root

# A dead/unreachable mock here must produce this script's own clear
# diagnostic, not an unhandled Node exception or a bash "integer
# expression expected" error from feeding garbage into [ -lt ] below —
# both a request timeout and a connection error are caught explicitly.
if ! REQUEST_COUNT=$(docker run --rm --network "$NETWORK" node:20-alpine \
	node -e "
		const http = require('http');
		const req = http.get('http://${MOCK_CONTAINER}:8825/_requests', (res) => {
			let body = '';
			res.on('data', (chunk) => { body += chunk; });
			res.on('end', () => {
				try {
					const data = JSON.parse(body);
					console.log(data.requests.length);
				} catch (e) {
					console.error('Could not parse the mock API response: ' + e.message);
					process.exit(1);
				}
			});
		});
		req.setTimeout(10000, () => {
			console.error('Timed out waiting for the mock API to respond to GET /_requests.');
			req.destroy();
			process.exit(1);
		});
		req.on('error', (e) => {
			console.error('Could not reach the mock API: ' + e.message);
			process.exit(1);
		});
	"); then
	echo "Failed to fetch the request count from the mock API — it may be dead, unreachable, or timed out. See the Node error above." >&2
	exit 1
fi

if ! [[ "$REQUEST_COUNT" =~ ^[0-9]+$ ]]; then
	echo "Got a non-numeric response when checking the mock API's request count: '${REQUEST_COUNT}'" >&2
	exit 1
fi

if [ "$REQUEST_COUNT" -lt 1 ]; then
	echo "The mock API never actually received a POST /v1/emails request." >&2
	exit 1
fi

echo "Confirmed the mock API received ${REQUEST_COUNT} request(s)."
echo "Zip install verification passed: the plugin activates cleanly from a real zip, sends through the API backend, and records the delivery log row correctly."
