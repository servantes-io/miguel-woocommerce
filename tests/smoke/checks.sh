#!/bin/sh
#
# Runs in the curl container of docker-compose.smoke.yml against the shop tests/smoke/install.sh
# set up: the pages a shop and its admin open, and Miguel's REST API with a real coupon preview.
set -eu

SITE=http://wordpress
JAR=/tmp/cookies
BODY=/tmp/body
failures=0

fail() {
    echo "FAIL: $1" >&2
    failures=$((failures + 1))
}

# check NAME EXPECTED_STATUS URL [curl args...]: the status, and no PHP error on the page.
check() {
    name=$1 expected=$2 url=$3
    shift 3
    status=$(curl -s -o "$BODY" -w '%{http_code}' -b "$JAR" "$@" "$url") || status=000
    if [ "$status" != "$expected" ]; then
        fail "$name: HTTP $status, expected $expected"
        head -c 2000 "$BODY" >&2 || true
        echo >&2
    elif grep -qiE 'critical error on this website|fatal error|parse error' "$BODY"; then
        fail "$name: PHP error in the response"
        grep -iE -m 3 'critical error|fatal error|parse error' "$BODY" >&2
    else
        echo "ok: $name"
    fi
}

# body_has NAME TEXT: the last checked response contains TEXT.
body_has() {
    if grep -qF -- "$2" "$BODY"; then
        echo "ok: $1"
    else
        fail "$1: response lacks $2"
        head -c 2000 "$BODY" >&2
        echo >&2
    fi
}

tries=0
until curl -s -o /dev/null "$SITE/wp-login.php"; do
    tries=$((tries + 1))
    [ "$tries" -lt 60 ] || { echo "The shop did not come up" >&2; exit 1; }
    sleep 1
done

check "front page" 200 "$SITE/"
check "shop page" 200 "$SITE/?post_type=product"

# Log in; WordPress wants its test cookie first.
curl -s -o /dev/null -c "$JAR" "$SITE/wp-login.php"
check "log in" 302 "$SITE/wp-login.php" -c "$JAR" --data-urlencode log=admin --data-urlencode pwd=smoke \
    -d testcookie=1 --data-urlencode "redirect_to=$SITE/wp-admin/"
check "dashboard" 200 "$SITE/wp-admin/"
check "plugins" 200 "$SITE/wp-admin/plugins.php"
body_has "Miguel is active" "plugins.php?action=deactivate&amp;plugin=miguel%2Fmiguel.php"
check "add plugins" 200 "$SITE/wp-admin/plugin-install.php"
check "Miguel settings" 200 "$SITE/wp-admin/admin.php?page=wc-settings&tab=miguel"
check "payments settings" 200 "$SITE/wp-admin/admin.php?page=wc-settings&tab=checkout"

check "REST index" 200 "$SITE/?rest_route=/"
body_has "REST has miguel/v1" '"miguel\/v1"'

AUTH="Authorization: Bearer smoke-token"
check "products" 200 "$SITE/?rest_route=/miguel/v1/products" -H "$AUTH"
check "product code map" 200 "$SITE/?rest_route=/miguel/v1/product-code-map" -H "$AUTH"
check "delivery methods" 200 "$SITE/?rest_route=/miguel/v1/delivery-methods" -H "$AUTH"

PREVIEW='{"code":"smoke10","email":"reader@example.com","line_items":[{"product_code":"SMOKE-BOOK","quantity":1}]}'
check "coupon preview without a token" 401 "$SITE/?rest_route=/miguel/v1/coupons/preview" \
    -H 'Content-Type: application/json' -d "$PREVIEW"
check "coupon preview" 200 "$SITE/?rest_route=/miguel/v1/coupons/preview" -H "$AUTH" \
    -H 'Content-Type: application/json' -d "$PREVIEW"
body_has "coupon applied" '"applied":true'

if [ "$failures" -ne 0 ]; then
    echo "$failures smoke check(s) failed" >&2
    exit 1
fi
echo "All smoke checks passed"
