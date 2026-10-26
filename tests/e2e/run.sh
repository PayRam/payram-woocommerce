#!/bin/sh
# End-to-end webhook tests for payram-woocommerce, in throwaway containers.
#
#   tests/e2e/run.sh [plugin-dir]      (default: this repository)
#
# Starts MariaDB, WordPress (official image, Apache) with WooCommerce and the
# plugin mounted read-only, and a stand-in PayRam (payram_stand_in.py), all on
# an internal Docker network: nothing inside can reach the internet, and
# WordPress also blocks external HTTP. Runs webhook_tests.php with WooCommerce
# order storage both ways (posts tables, then HPOS), fails if any check fails
# or WordPress logged a PHP notice, and removes every container, volume and
# network it created, by name, on exit.
#
# Needs docker and curl. WooCommerce is fetched once from downloads.wordpress.org,
# checked file by file against wordpress.org's published sha256 list, and kept
# in $PAYRAM_E2E_CACHE (default ~/.cache/payram-woo-e2e).
set -eu

HERE=$(cd "$(dirname "$0")" && pwd)
PLUGIN=$(cd "${1:-$HERE/../..}" && pwd)
CACHE=${PAYRAM_E2E_CACHE:-${XDG_CACHE_HOME:-$HOME/.cache}/payram-woo-e2e}
WC_VERSION=${WC_VERSION:-11.2.1}
WP_IMAGE=${WP_IMAGE:-public.ecr.aws/docker/library/wordpress:7.1.3-php8.5-apache}
CLI_IMAGE=${CLI_IMAGE:-public.ecr.aws/docker/library/wordpress:cli}
DB_IMAGE=${DB_IMAGE:-public.ecr.aws/docker/library/mariadb:lts}
PY_IMAGE=${PY_IMAGE:-public.ecr.aws/docker/library/python:3-alpine}
P=payram-woo-e2e
DBENV="-e WORDPRESS_DB_HOST=$P-db -e WORDPRESS_DB_USER=wp -e WORDPRESS_DB_PASSWORD=wp-test-only -e WORDPRESS_DB_NAME=wp -e WORDPRESS_DEBUG=1"

cleanup() {
	for c in $P-cli $P-wp $P-payram $P-db; do docker rm -f "$c" >/dev/null 2>&1 || true; done
	for v in $P-html $P-db-data; do docker volume rm "$v" >/dev/null 2>&1 || true; done
	docker network rm $P-net >/dev/null 2>&1 || true
}

fetch_woocommerce() {
	[ -f "$CACHE/woocommerce-$WC_VERSION/woocommerce/woocommerce.php" ] && return 0
	mkdir -p "$CACHE"
	[ -f "$CACHE/woocommerce.$WC_VERSION.zip" ] ||
		curl -fsSL -o "$CACHE/woocommerce.$WC_VERSION.zip" "https://downloads.wordpress.org/plugin/woocommerce.$WC_VERSION.zip"
	[ -f "$CACHE/woocommerce.$WC_VERSION.json" ] ||
		curl -fsSL -o "$CACHE/woocommerce.$WC_VERSION.json" "https://downloads.wordpress.org/plugin-checksums/woocommerce/$WC_VERSION.json"
	docker run --rm --network none --user "$(id -u):$(id -g)" -v "$CACHE":/c "$PY_IMAGE" python -c '
import hashlib, json, os, shutil, sys, zipfile
v = sys.argv[1]
dest = "/c/woocommerce-" + v
tmp = dest + ".partial"
shutil.rmtree(tmp, ignore_errors=True)
zipfile.ZipFile("/c/woocommerce." + v + ".zip").extractall(tmp)
want = json.load(open("/c/woocommerce." + v + ".json"))["files"]
root = os.path.join(tmp, "woocommerce")
seen, bad = set(), []
for dp, _, files in os.walk(root):
    for f in files:
        rel = os.path.relpath(os.path.join(dp, f), root)
        seen.add(rel)
        got = hashlib.sha256(open(os.path.join(dp, f), "rb").read()).hexdigest()
        exp = want.get(rel, {}).get("sha256")
        if got != exp and not (isinstance(exp, list) and got in exp):
            bad.append(rel)
missing = sorted(set(want) - seen)
if bad or missing:
    print("WooCommerce " + v + " does not match wordpress.org checksums:", bad[:5], missing[:5])
    sys.exit(1)
os.rename(tmp, dest)
print("WooCommerce " + v + ": " + str(len(seen)) + " files match wordpress.org sha256 checksums")
' "$WC_VERSION"
}

wp_cli() {
	docker run --rm --name $P-cli --network $P-net --user 33:33 --volumes-from $P-wp $DBENV -e HOME=/tmp "$CLI_IMAGE" wp "$@"
}

wait_for() {
	i=0
	until "$@" >/dev/null 2>&1; do
		i=$((i + 1))
		[ $i -gt 90 ] && { echo "timed out waiting for: $*"; return 1; }
		sleep 1
	done
}

start() {
	docker network create --internal $P-net >/dev/null
	docker volume create $P-db-data >/dev/null
	docker volume create $P-html >/dev/null
	docker run -d --name $P-db --network $P-net -v $P-db-data:/var/lib/mysql \
		-e MARIADB_DATABASE=wp -e MARIADB_USER=wp -e MARIADB_PASSWORD=wp-test-only -e MARIADB_ROOT_PASSWORD=root-test-only \
		"$DB_IMAGE" >/dev/null
	docker run -d --name $P-payram --network $P-net -v "$HERE/payram_stand_in.py":/app/payram_stand_in.py:ro \
		"$PY_IMAGE" python -u /app/payram_stand_in.py >/dev/null
	docker run -d --name $P-wp --network $P-net -v $P-html:/var/www/html \
		-v "$CACHE/woocommerce-$WC_VERSION/woocommerce":/var/www/html/wp-content/plugins/woocommerce:ro \
		-v "$PLUGIN":/var/www/html/wp-content/plugins/payram-woocommerce:ro \
		-v "$HERE":/tests:ro \
		$DBENV \
		-e WORDPRESS_CONFIG_EXTRA="define('WP_HTTP_BLOCK_EXTERNAL', true); define('WP_ACCESSIBLE_HOSTS', '$P-payram,$P-wp'); define('WP_DEBUG_LOG', true); define('WP_DEBUG_DISPLAY', false); define('DISABLE_WP_CRON', true);" \
		"$WP_IMAGE" >/dev/null
	wait_for docker exec $P-db mariadb -uwp -pwp-test-only -e 'select 1' wp
	wait_for docker exec $P-wp test -f /var/www/html/wp-config.php
}

# Any PHP error or notice that names this plugin (its files or its text
# domain) fails the run. Others come from WordPress or WooCommerce themselves
# (for example WooCommerce loading its own translations early during a
# background request) and are printed without failing.
check_debug_log() {
	log=$(docker exec $P-wp sh -c 'cat /var/www/html/wp-content/debug.log 2>/dev/null' || true)
	if [ -z "$log" ]; then
		echo "debug.log empty (WP_DEBUG on)"
		return 0
	fi
	if printf '%s\n' "$log" | grep -qi payram; then
		echo "PHP errors or notices from this plugin:"
		printf '%s\n' "$log" | grep -i payram | head -50
		return 1
	fi
	echo "debug.log: nothing from this plugin; WordPress/WooCommerce logged:"
	printf '%s\n' "$log" | cut -c1-200 | sort | uniq -c | head -20
}

trap cleanup EXIT INT TERM
cleanup
fetch_woocommerce
start
wp_cli core install --url=http://$P-wp --title="PayRam e2e" --admin_user=admin --admin_password=admin-test-only \
	--admin_email=admin@example.invalid --skip-email >/dev/null
wp_cli plugin activate woocommerce payram-woocommerce >/dev/null
wp_cli option update woocommerce_currency USD >/dev/null
docker exec $P-wp php -r 'echo "server PHP " . PHP_VERSION . "\n";'

status=0
wp_cli wc hpos disable >/dev/null 2>&1 || true
wp_cli eval-file /tests/webhook_tests.php || status=1
wp_cli wc hpos sync >/dev/null
wp_cli wc hpos enable >/dev/null
wp_cli eval-file /tests/webhook_tests.php || status=1
check_debug_log || status=1
[ $status -eq 0 ] && echo "ALL PASSED (HPOS off and on)" || echo "FAILURES (see above)"
exit $status
