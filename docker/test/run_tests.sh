#!/usr/bin/env bash
#
# Isolated PHPUnit run on the existing Contabo host.
#
# Runs the suite at an exact candidate SHA against a disposable PostgreSQL,
# in a compose project of its own, without touching the live application.
#
#   ./docker/test/run_tests.sh <sha> [--suite Unit|Feature] [--filter <pattern>]
#
# WHAT MAKES IT SAFE, and each of these is asserted rather than asserted-about:
#   * Its own compose project (cpvtest), so up/down cannot recreate or stop the
#     live perfilafiliados app or nginx.
#   * The disposable workspace is a fresh fetch of the candidate SHA. No path
#     under /opt/perfilafiliados is mounted, and that is PROVEN from
#     `docker inspect` output rather than claimed - including bootstrap/cache,
#     which has already caused live HTTP 500s when a test container wrote
#     package-discovery cache through the shared mount.
#   * Dependencies, storage, bootstrap/cache and vendor are created inside the
#     workspace, so the live app's copies are never read or written.
#   * The fixture database publishes no ports and starts empty; the pre-flight
#     refuses to migrate unless every resolved connection points at it.
#   * Staging is smoke-checked before and after, so a regression caused by the
#     test run would be visible instead of assumed absent.
#   * Teardown removes only resources labelled for this run.
#
# NOT SAFE TO ASSUME: this script does not probe isolation by writing into the
# live bootstrap/cache. Proving a guard by exercising the dangerous path on a
# production host is not a test, it is the incident.
#
set -uo pipefail

SHA="${1:-}"
shift || true
SUITE=""
FILTER=""
while [ $# -gt 0 ]; do
  case "$1" in
    --suite)  SUITE="${2:-}"; shift 2 ;;
    --filter) FILTER="${2:-}"; shift 2 ;;
    *) echo "unknown argument: $1" >&2; exit 2 ;;
  esac
done

[ -n "$SHA" ] || { echo "usage: $0 <sha> [--suite Unit|Feature] [--filter <pattern>]" >&2; exit 2; }
case "$SHA" in *[!0-9a-f]*|"") echo "sha must be lowercase hex" >&2; exit 2 ;; esac
[ "${#SHA}" -eq 40 ] || { echo "sha must be 40 characters" >&2; exit 2; }

REPO_URL=https://github.com/estebanjvasquez/PerfilAfiliadosCPV.git
PROJECT=cpvtest
COMPOSE_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
RUN_ID="$(date -u +%Y%m%dT%H%M%SZ)-${SHA:0:12}"
WORKSPACE="/root/cpv-test/${RUN_ID}"
ARTIFACTS="/root/cpv-test/artifacts/${RUN_ID}"
LIVE_APP_DIR=/opt/perfilafiliados
STAGING_URL=https://pruebas.camarapetrolera.app/

say()  { printf '  %s\n' "$*"; }
step() { printf '\n== %s\n' "$*"; }
note() { printf '         %s\n' "$*"; }
die()  { printf '\nFAILED: %s\n' "$*" >&2; teardown; exit 1; }

PASSED=0; FAILED=0
ok()  { PASSED=$((PASSED+1)); printf '  [PASS] %s\n' "$*"; }
bad() { FAILED=$((FAILED+1)); printf '  [FAIL] %s\n' "$*"; }

compose() {
  docker compose -p "$PROJECT" -f "$COMPOSE_DIR/docker-compose.test.yml" "$@"
}

teardown() {
  step "Teardown (only resources belonging to this run)"
  compose down -v --remove-orphans >/dev/null 2>&1 || true
  say "compose project $PROJECT removed, including its volumes"
  # The live stack must be untouched. Report it rather than trusting it.
  local live
  live=$(docker compose -p perfilafiliados -f "$LIVE_APP_DIR/docker-compose.yml" ps \
         --format '{{.Service}}:{{.Status}}' 2>/dev/null | tr '\n' ' ')
  say "live stack: ${live:-unreadable}"
  if [ -n "${KEEP_WORKSPACE:-}" ]; then
    say "workspace kept at $WORKSPACE (KEEP_WORKSPACE set)"
  else
    rm -rf "$WORKSPACE"
    say "workspace $WORKSPACE removed"
  fi
  say "artifacts kept at $ARTIFACTS"
}

[ "$(id -u)" = 0 ] || die "must run as root"
command -v docker >/dev/null || die "docker not found"

mkdir -p "$ARTIFACTS"
exec > >(tee -a "$ARTIFACTS/run.log") 2>&1

printf 'CPV isolated test run\n'
printf '  run id   : %s\n' "$RUN_ID"
printf '  sha      : %s\n' "$SHA"
printf '  workspace: %s\n' "$WORKSPACE"
printf '  artifacts: %s\n' "$ARTIFACTS"

# --- 1. staging smoke, before ----------------------------------------------

step "1. Staging smoke BEFORE"
SMOKE_BEFORE=$(curl -s -o /dev/null -w '%{http_code}' --max-time 25 "$STAGING_URL" || echo 000)
say "GET $STAGING_URL -> $SMOKE_BEFORE"
[ "$SMOKE_BEFORE" = "200" ] && ok "staging healthy before the run" \
                            || bad "staging returned $SMOKE_BEFORE before the run"

# --- 2. disposable workspace at the exact sha -------------------------------

step "2. Disposable workspace at the exact candidate SHA"
rm -rf "$WORKSPACE"; mkdir -p "$WORKSPACE"
git -C "$WORKSPACE" init -q
git -C "$WORKSPACE" remote add origin "$REPO_URL"
# Public repository, so no credential is used or needed here.
git -C "$WORKSPACE" fetch -q --depth 1 origin "$SHA" || die "cannot fetch $SHA"
git -C "$WORKSPACE" checkout -q FETCH_HEAD
ACTUAL=$(git -C "$WORKSPACE" rev-parse HEAD)
[ "$ACTUAL" = "$SHA" ] && ok "workspace is at $SHA" \
                       || die "workspace is at $ACTUAL, expected $SHA"
case "$WORKSPACE" in "$LIVE_APP_DIR"*) die "workspace overlaps the live app" ;; esac
ok "workspace path is disjoint from $LIVE_APP_DIR"

# Writable directories Laravel needs, created inside the workspace.
mkdir -p "$WORKSPACE/storage/framework/"{cache/data,sessions,views} \
         "$WORKSPACE/storage/logs" "$WORKSPACE/bootstrap/cache"
chmod -R 777 "$WORKSPACE/storage" "$WORKSPACE/bootstrap/cache"
ok "workspace has its own storage and bootstrap/cache"

# No live .env is copied. Ephemeral credentials for this run only.
export CPV_TEST_WORKSPACE="$WORKSPACE"
export CPV_TEST_DB_PASSWORD="$(head -c 24 /dev/urandom | od -An -tx1 | tr -d ' \n')"
export CPV_TEST_APP_KEY="base64:$(head -c 32 /dev/urandom | base64)"
ok "ephemeral APP_KEY and fixture DB password generated for this run only"
note "Not a secret change to any real system: both exist only in this process"
note "and the disposable containers, and die with them. Never written to disk."
[ -f "$WORKSPACE/.env" ] && die "workspace unexpectedly contains a .env"
ok "no .env in the workspace: configuration comes from the environment only"

# --- 3. build and start ------------------------------------------------------

step "3. Build the test image and start the fixture database"
compose build testapp >"$ARTIFACTS/build.log" 2>&1 || {
  tail -25 "$ARTIFACTS/build.log"; die "test image build failed (see build.log)"; }
ok "test image built (log: $ARTIFACTS/build.log)"
compose up -d testdb >/dev/null 2>&1 || die "cannot start the fixture database"
for _ in $(seq 1 40); do
  state=$(compose ps --format '{{.Service}}:{{.Health}}' 2>/dev/null | grep '^testdb:' || true)
  case "$state" in *healthy*) break ;; esac
  sleep 3
done
case "$(compose ps --format '{{.Service}}:{{.Health}}' | grep '^testdb:')" in
  *healthy*) ok "fixture database is healthy" ;;
  *) die "fixture database never became healthy" ;;
esac

# --- 4. prove the isolation, from docker's own view -------------------------

step "4. Mount and port isolation, from docker inspect"
compose run --rm --no-deps -T testapp true >/dev/null 2>&1 || true
CID=$(docker ps -aq --filter "label=com.docker.compose.project=$PROJECT" | head -1)
if [ -z "$CID" ]; then
  bad "no container found to inspect"
else
  docker inspect "$CID" --format '{{range .Mounts}}{{.Type}} {{.Source}} -> {{.Destination}} rw={{.RW}}
{{end}}' > "$ARTIFACTS/mounts.txt"
  say "mounts:"; sed 's/^/           /' "$ARTIFACTS/mounts.txt"
  if grep -q "$LIVE_APP_DIR" "$ARTIFACTS/mounts.txt"; then
    bad "a mount references $LIVE_APP_DIR"
  else
    ok "no mount references $LIVE_APP_DIR"
  fi
  for forbidden in "$LIVE_APP_DIR/bootstrap/cache" "$LIVE_APP_DIR/storage" \
                   "$LIVE_APP_DIR/public" "$LIVE_APP_DIR/.env"; do
    grep -q "$forbidden" "$ARTIFACTS/mounts.txt" && bad "mounts include $forbidden"
  done
  ok "none of the live app's storage, bootstrap/cache, public or .env is mounted"
fi
docker inspect "$(docker ps -q --filter "label=com.docker.compose.project=$PROJECT" \
  --filter "label=com.docker.compose.service=testdb")" \
  --format '{{json .NetworkSettings.Ports}}' > "$ARTIFACTS/db_ports.json" 2>/dev/null || true
say "fixture db published ports: $(cat "$ARTIFACTS/db_ports.json" 2>/dev/null || echo unknown)"
if grep -qE '"HostPort"' "$ARTIFACTS/db_ports.json" 2>/dev/null; then
  bad "the fixture database publishes a host port"
else
  ok "the fixture database publishes no host port"
fi

# --- 5. dependencies, inside the workspace ----------------------------------

step "5. Install dependencies INSIDE the workspace (dev included)"
compose run --rm -T testapp composer install --prefer-dist --no-interaction --no-scripts \
  >"$ARTIFACTS/composer.log" 2>&1 || { tail -20 "$ARTIFACTS/composer.log"; die "composer install failed"; }
[ -x "$WORKSPACE/vendor/bin/phpunit" ] && ok "phpunit present in the workspace vendor" \
                                       || die "phpunit missing after composer install"
compose run --rm -T testapp php artisan package:discover --ansi \
  >>"$ARTIFACTS/composer.log" 2>&1 || die "package:discover failed"
ok "package discovery written inside the workspace"
note "This is the step that once wrote through a SHARED bootstrap/cache mount"
note "and produced live HTTP 500s. Here the target is $WORKSPACE/bootstrap/cache."
ls -la "$WORKSPACE/bootstrap/cache" | sed 's/^/           /' | head -6

# --- 6. the pre-flight guard -------------------------------------------------

step "6. Verify EVERY resolved connection BEFORE migrating"
compose run --rm -T testapp php docker/test/verify_connections.php testdb cpv_test \
  2>&1 | tee "$ARTIFACTS/connections.txt"
if grep -q '^SAFE:' "$ARTIFACTS/connections.txt"; then
  ok "pre-flight passed: all connections point at the disposable fixture"
else
  bad "pre-flight refused to proceed"
  die "connection verification failed; nothing was migrated"
fi

# --- 7. migrate --------------------------------------------------------------

step "7. Migrate the fixture database"
compose run --rm -T testapp php artisan db:skip-mysql-only-view-migrations \
  >"$ARTIFACTS/migrate.log" 2>&1 || die "db:skip-mysql-only-view-migrations failed"
compose run --rm -T testapp php artisan migrate --force --no-interaction \
  >>"$ARTIFACTS/migrate.log" 2>&1 || { tail -30 "$ARTIFACTS/migrate.log"; die "migrate failed"; }
TABLES=$(compose run --rm -T testapp php -r '
require "vendor/autoload.php"; $a=require "bootstrap/app.php";
$a->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
echo DB::select("select count(*) n from information_schema.tables where table_schema=\x27public\x27")[0]->n;' 2>/dev/null | tr -d '\r')
ok "migrations applied; $TABLES tables in the fixture database"

# --- 8. the suite ------------------------------------------------------------

step "8. Run PHPUnit"
ARGS=(vendor/bin/phpunit --do-not-cache-result)
[ -n "$SUITE" ]  && ARGS+=(--testsuite "$SUITE")
[ -n "$FILTER" ] && ARGS+=(--filter "$FILTER")
say "command: ${ARGS[*]}"
set +o pipefail
compose run --rm -T testapp "${ARGS[@]}" 2>&1 | tee "$ARTIFACTS/phpunit.txt"
PHPUNIT_EXIT=${PIPESTATUS[0]}
set -o pipefail
say "phpunit exit status: $PHPUNIT_EXIT"
tail -5 "$ARTIFACTS/phpunit.txt" | sed 's/^/           /'
[ "$PHPUNIT_EXIT" = "0" ] && ok "suite exited 0" \
                          || bad "suite exited $PHPUNIT_EXIT (recorded, not hidden)"

# --- 9. staging smoke, after -------------------------------------------------

step "9. Staging smoke AFTER"
SMOKE_AFTER=$(curl -s -o /dev/null -w '%{http_code}' --max-time 25 "$STAGING_URL" || echo 000)
say "GET $STAGING_URL -> $SMOKE_AFTER"
[ "$SMOKE_AFTER" = "200" ] && ok "staging still healthy after the run" \
                           || bad "staging returned $SMOKE_AFTER after the run"

# --- report ------------------------------------------------------------------

{
  echo "run_id=$RUN_ID"
  echo "sha=$SHA"
  echo "phpunit_exit=$PHPUNIT_EXIT"
  echo "fixture_tables=$TABLES"
  echo "staging_before=$SMOKE_BEFORE"
  echo "staging_after=$SMOKE_AFTER"
  echo "checks_passed=$PASSED"
  echo "checks_failed=$FAILED"
  echo "php_version=$(compose run --rm -T testapp php -r 'echo PHP_VERSION;' 2>/dev/null | tr -d '\r')"
  echo "extensions=$(compose run --rm -T testapp php -r 'echo implode(",",array_intersect(["pdo_pgsql","intl","zip","gd","bcmath"],get_loaded_extensions()));' 2>/dev/null | tr -d '\r')"
} > "$ARTIFACTS/summary.txt"

teardown

printf '\n%s\n' "$(printf '%.0s-' $(seq 1 70))"
cat "$ARTIFACTS/summary.txt"
printf '%s\n' "$(printf '%.0s-' $(seq 1 70))"
if [ "$FAILED" -eq 0 ] && [ "$PHPUNIT_EXIT" = "0" ]; then
  echo "RESULT: PASS - isolated suite green and staging unaffected"
  exit 0
fi
echo "RESULT: see above. Isolation checks failed: $FAILED, phpunit exit: $PHPUNIT_EXIT"
exit 1
