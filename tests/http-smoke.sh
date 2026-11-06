#!/usr/bin/env bash
# End-to-end HTTP smoke test against a running server.
# Usage: tests/http-smoke.sh [base-url]   (default http://127.0.0.1:8080)
# Requires: seeded database + storage/demo-credentials.json from bin/install.php --demo
set -u
BASE="${1:-http://127.0.0.1:8080}"
ROOT="$(cd "$(dirname "$0")/.." && pwd)"
cd "$ROOT"
FAIL=0
pass() { echo "PASS  $1"; }
fail() { echo "FAIL  $1"; FAIL=1; }
expect() { # label, expected, actual
  if [ "$2" = "$3" ]; then pass "$1 ($3)"; else fail "$1 (expected $2, got $3)"; fi
}

expect "home" 200 "$(curl -s -o /dev/null -w '%{http_code}' "$BASE/")"
expect "about" 200 "$(curl -s -o /dev/null -w '%{http_code}' "$BASE/about")"
expect "services" 200 "$(curl -s -o /dev/null -w '%{http_code}' "$BASE/services")"
expect "listings" 200 "$(curl -s -o /dev/null -w '%{http_code}' "$BASE/properties")"
expect "filtered listings" 200 "$(curl -s -o /dev/null -w '%{http_code}' "$BASE/properties?deal=sale&min_price=1000000&sort=price_asc")"
expect "contact" 200 "$(curl -s -o /dev/null -w '%{http_code}' "$BASE/contact")"
expect "login page" 200 "$(curl -s -o /dev/null -w '%{http_code}' "$BASE/login")"
expect "admin redirects when signed out" 303 "$(curl -s -o /dev/null -w '%{http_code}' "$BASE/admin")"
expect "missing page is 404" 404 "$(curl -s -o /dev/null -w '%{http_code}' "$BASE/no-such-page-here")"
expect "sitemap" 200 "$(curl -s -o /dev/null -w '%{http_code}' "$BASE/sitemap.xml")"
expect "robots" 200 "$(curl -s -o /dev/null -w '%{http_code}' "$BASE/robots.txt")"
expect "health" 200 "$(curl -s -o /dev/null -w '%{http_code}' "$BASE/health")"

SLUG="$(php -r 'require "app/bootstrap.php"; echo Database::value("SELECT slug FROM properties WHERE publish_status=\"published\" LIMIT 1");')"
expect "property page" 200 "$(curl -s -o /dev/null -w '%{http_code}' "$BASE/property/$SLUG")"
BRAND_NAME="$(php -r 'require "app/bootstrap.php"; echo setting("brand_name") ?: setting("site_name");')"
curl -s "$BASE/" | grep -qF "$BRAND_NAME" && pass "brand present on home" || fail "brand present on home"
curl -s "$BASE/" | grep -qF "$(php -r 'require "app/bootstrap.php"; echo brand_lead();')" && pass "brand wordmark present on home" || fail "brand wordmark present on home"
curl -s "$BASE/property/$SLUG" | grep -q "amenities-grid" && pass "amenities render" || fail "amenities render"

# Authenticated staff flow (first demo account = super admin)
JAR="$(mktemp)"
TOKEN="$(curl -s -c "$JAR" "$BASE/login" | grep -o 'name="_token" value="[a-f0-9]*"' | grep -o '[a-f0-9]\{32,\}' | head -1)"
read -r EMAIL PASS < <(php -r '$c=json_decode(file_get_contents("storage/demo-credentials.json"),true); $u=$c["super_admin"]; echo $u["email"]," ",$u["password"];')
LOGIN_CODE="$(curl -s -b "$JAR" -c "$JAR" -o /dev/null -w '%{http_code}' --data-urlencode "_token=$TOKEN" --data-urlencode "email=$EMAIL" --data-urlencode "password=$PASS" "$BASE/login")"
expect "staff login" 303 "$LOGIN_CODE"
for p in /admin /admin/properties /admin/properties/new /admin/enquiries /admin/categories /admin/locations /admin/testimonials /admin/users /admin/settings /admin/audit /admin/roadmap /admin/account; do
  expect "admin $p" 200 "$(curl -s -b "$JAR" -o /dev/null -w '%{http_code}' "$BASE$p")"
done
rm -f "$JAR"
exit $FAIL
