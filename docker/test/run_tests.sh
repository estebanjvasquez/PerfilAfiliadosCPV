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

PASSED=0; FAILED=0; FINDINGS=0
ok()  { PASSED=$((PASSED+1)); printf '  [PASS] %s\n' "$*"; }
bad() { FAILED=$((FAILED+1)); printf '  [FAIL] %s\n' "$*"; }
# A FINDING is a defect in the repository under test, not a failure of the
# isolation this script asserts. Kept in its own counter so a real finding can
# never be read as "the isolation did not hold", and so a run that found
# something cannot be reported as a clean pass either.
finding() { FINDINGS=$((FINDINGS+1)); printf '  [FINDING] %s\n' "$*"; }
# Schema preparation is a THIRD result group, counted apart from the isolation
# assertions and from the suite. A green suite that needed a prepared schema
# must never read as a suite that needed nothing.
PREP_PASSED=0; PREP_FAILED=0
pok()  { PREP_PASSED=$((PREP_PASSED+1)); printf '  [PREP PASS] %s\n' "$*"; }
pbad() { PREP_FAILED=$((PREP_FAILED+1)); printf '  [PREP FAIL] %s\n' "$*"; }

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

# The verifier is TOOLING and is injected, not taken from the candidate SHA:
# a candidate that predates this test runner would not contain it, and the
# guard must not depend on the code it is guarding.
cp "$COMPOSE_DIR/verify_connections.php" "$WORKSPACE/cpv_verify_connections.php"
ok "connection verifier injected into the workspace (not taken from the SHA)"

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

# WHY THIS IS WRITTEN DEFENSIVELY
#   The first version of this step inspected whichever container `docker ps -aq`
#   returned after a one-off `compose run --rm ... true`. That command failed for
#   an unrelated reason, the container was gone by the time it was inspected, and
#   the mount listing came back EMPTY - at which point "no mount references the
#   live app" and "none of the live app's directories are mounted" both passed on
#   an empty string. An absence of evidence printed as proof of isolation.
#   So: the listing must be non-empty, and the expected mount must be present by
#   exact source and destination. Nothing here passes by default.
assert_mounts() {
  # $1 = artifact file, $2 = what produced it (for the message)
  local file="$1" what="$2" binds
  [ -s "$file" ] || { bad "$what: mount listing is EMPTY - cannot conclude isolation"
                      die "refusing to treat an empty docker inspect as proof"; }
  say "mounts ($what):"; sed 's/^/           /' "$file"

  grep -q "bind $WORKSPACE -> /app " "$file" \
    || { bad "$what: the disposable workspace is not mounted at /app"
         die "the container under test is not running this run's workspace"; }

  binds=$(grep -c '^bind ' "$file" || true)
  [ "$binds" = "1" ] || { bad "$what: $binds bind mounts, expected exactly 1"
                          die "an unexpected host path is mounted into the test container"; }
  ok "$what: exactly one bind mount, and it is this run's workspace at /app"

  grep -q "$LIVE_APP_DIR" "$file" && { bad "$what: a mount references $LIVE_APP_DIR"
                                       die "the live application directory is mounted"; }
  ok "$what: no mount references $LIVE_APP_DIR (storage, bootstrap/cache, public, .env included)"
}

# A container that STAYS inspectable: `command: sleep infinity` keeps it up, and
# its mounts come from the same service definition every `compose run` uses.
compose up -d testapp >/dev/null 2>&1 || die "cannot start the test application container"
CID=$(compose ps -q testapp 2>/dev/null | head -1)
[ -n "$CID" ] || die "no test application container to inspect"
docker inspect "$CID" --format '{{range .Mounts}}{{.Type}} {{.Source}} -> {{.Destination}} rw={{.RW}}
{{end}}' | grep -v '^[[:space:]]*$' > "$ARTIFACTS/mounts.txt"
assert_mounts "$ARTIFACTS/mounts.txt" "testapp service container"
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
compose run --rm -T testapp php cpv_verify_connections.php testdb cpv_test \
  2>&1 | tee "$ARTIFACTS/connections.txt"
if grep -q '^SAFE:' "$ARTIFACTS/connections.txt"; then
  ok "pre-flight passed: all connections point at the disposable fixture"
else
  bad "pre-flight refused to proceed"
  die "connection verification failed; nothing was migrated"
fi

# --- 7. SCHEMA PREPARATION ---------------------------------------------------
#
# ITS OWN PHASE, ON PURPOSE. Isolation, schema preparation and test results are
# three separate groups of evidence with three separate counters, so a suite
# that needed a prepared schema can never be reported as one that needed
# nothing.
#
# WHY PREPARATION IS NEEDED AT ALL
#   Replaying the migration set into an empty database aborts: four foreign keys
#   reference tables that later migrations create. Measured, not assumed - see
#   migration_deps.txt in this run's artifacts, and schema_fix/README.md for the
#   four violations and the proposed minimal correction.
#
#   The correction preserves every constraint: it defers exactly four
#   declarations and re-creates them from SQL once their tables exist, with
#   Laravel's own constraint names. No foreign key is disabled, no migration is
#   reordered or renamed, and nothing is applied to any existing database - the
#   patch touches the disposable workspace and the SQL touches the disposable
#   fixture.
#
#   CONSEQUENCE TO READ HONESTLY: the suite then runs the candidate SHA PLUS
#   this patch. That is stated in the log, in summary.txt and in the artifacts,
#   because it is not the same thing as running the SHA alone.

step "7. SCHEMA PREPARATION (its own phase: not isolation, not the suite)"

# 7.1 The dependency analysis, at the candidate SHA. node does not exist on this
#     host, so it runs in a throwaway container, read-only, over the workspace.
DEPS_VIOLATIONS=unknown
if docker run --rm \
     -v "$WORKSPACE:/w:ro" \
     -v "$COMPOSE_DIR/migration_deps.mjs:/t/deps.mjs:ro" \
     node:20-alpine node /t/deps.mjs /w/database/migrations \
     >"$ARTIFACTS/migration_deps.txt" 2>&1; then
  DEPS_VIOLATIONS=0
  pok "migration dependency analysis: no ordering violations at this SHA"
elif [ -s "$ARTIFACTS/migration_deps.txt" ] && grep -q 'VIOLATIONS' "$ARTIFACTS/migration_deps.txt"; then
  DEPS_VIOLATIONS=$(grep -c 'created later by' "$ARTIFACTS/migration_deps.txt" || true)
  finding "$DEPS_VIOLATIONS migration ordering violation(s) at this SHA (migration_deps.txt)"
  grep -A3 'VIOLATIONS -' "$ARTIFACTS/migration_deps.txt" | sed 's/^/           /' | head -8
else
  pbad "dependency analysis did not run; see migration_deps.txt"
  tail -3 "$ARTIFACTS/migration_deps.txt" 2>/dev/null | sed 's/^/           /'
fi

# 7.2 The proposed minimal corrections, applied to the WORKSPACE ONLY.
#     Two patches, kept separate because they are two independent defects with
#     two different rationales, and the owner may want to promote one and not
#     the other. Each is --check'ed first: a patch that does not fit this SHA is
#     a loud failure, never a forced apply against a mangled tree.
SCHEMA_PATCHES="defer_four_fks.patch align_permission_migration_to_v6.patch portable_index_check.patch"
APPLIED_PATCHES=""
for name in $SCHEMA_PATCHES; do
  PATCH="$COMPOSE_DIR/schema_fix/$name"
  [ -f "$PATCH" ] || die "schema_fix/$name is missing"
  if git -C "$WORKSPACE" apply --check "$PATCH" 2>>"$ARTIFACTS/schema_fix.log"; then
    git -C "$WORKSPACE" apply "$PATCH" || die "$name passed --check but failed to apply"
    APPLIED_PATCHES="${APPLIED_PATCHES}${APPLIED_PATCHES:+,}$name"
    pok "applied $name"
  else
    pbad "$name does not apply at $SHA - NOT forced"
    tail -6 "$ARTIFACTS/schema_fix.log" | sed 's/^/           /'
    die "refusing to mangle the workspace; regenerate the patch for this SHA"
  fi
done
# Only the migrations, so the record is the patches' effect and not the mode
# changes that preparing a writable workspace makes.
git -C "$WORKSPACE" diff -- database/migrations > "$ARTIFACTS/schema_fix_applied.diff"
say "net effect on migrations: $(git -C "$WORKSPACE" diff --shortstat -- database/migrations | sed 's/^ *//')"
note "Applied to $WORKSPACE only. No existing database and no branch is touched."
note "The suite below therefore runs $SHA PLUS: $APPLIED_PATCHES"

# 7.3 THE INTEGRATION BRANCH'S MIGRATION SET IS NOT SELF-CONTAINED.
#
#   Six migrations reference taxonomy_categories, and NOTHING at this SHA
#   creates it. The migrations that do - 7 Phase-4 table migrations and the 13
#   PostgreSQL view migrations - live in database/migrations/pgsql/ on
#   origin/feature/supplhi-postgres-buscador, a branch that is NOT merged into
#   this one. At this SHA that directory holds only the 2026_09_07 rework,
#   whose own docblock says it assumes those tables already exist.
#
#   So this is not schema that has to be invented: it is a branch that is not
#   merged. The fixture is therefore built from the UNION of both sets, which is
#   what was actually done against Supabase - Laravel sorts migrations by
#   filename across every --path, so dating puts them in the historical order:
#   base tables (2022-) -> pgsql views (2026_08_31) -> Phase 4 (2026_09_01) ->
#   rework (2026_09_07) -> the migrations that reference them (2026_09_11+).
#
#   DECLARED, not hidden: the fixture schema comes from two branches. The
#   sibling SHA is recorded below and in summary.txt. Deciding whether that
#   branch should be merged is the owner's call, not this script's.
SIBLING_BRANCH=feature/supplhi-postgres-buscador
if git -C "$WORKSPACE" fetch -q --depth 1 origin "$SIBLING_BRANCH" 2>>"$ARTIFACTS/schema_fix.log"; then
  SIBLING_SHA=$(git -C "$WORKSPACE" rev-parse FETCH_HEAD)
  if git -C "$WORKSPACE" checkout -q FETCH_HEAD -- database/migrations/pgsql 2>>"$ARTIFACTS/schema_fix.log"; then
    BOOTSTRAP_COUNT=$(ls "$WORKSPACE"/database/migrations/pgsql/*.php 2>/dev/null | wc -l | tr -d ' ')
    pok "pgsql bootstrap migrations taken from $SIBLING_BRANCH @ ${SIBLING_SHA:0:12}: $BOOTSTRAP_COUNT files"
    ls "$WORKSPACE"/database/migrations/pgsql/*.php | xargs -n1 basename > "$ARTIFACTS/pgsql_bootstrap_migrations.txt"
    head -3 "$ARTIFACTS/pgsql_bootstrap_migrations.txt" | sed 's/^/           /'
    [ "$BOOTSTRAP_COUNT" -gt 3 ] && note "...and $((BOOTSTRAP_COUNT - 3)) more, listed in pgsql_bootstrap_migrations.txt"
  else
    pbad "could not take database/migrations/pgsql from $SIBLING_BRANCH"
    SIBLING_SHA=unavailable
  fi
else
  pbad "could not fetch $SIBLING_BRANCH; the fixture will be missing taxonomy_categories"
  SIBLING_SHA=unavailable
fi

# 7.4 `migrate:install` FIRST, and this is a finding rather than a detail.
# db:skip-mysql-only-view-migrations reads `select max(batch) from migrations`,
# so it cannot run on a database that has never been migrated - yet its own
# docblock says to run it once per NEW pgsql database (test, production). On the
# shared Supabase database the table already existed, which is why nobody hit
# this; on a genuinely empty fixture it fails with
# `relation "migrations" does not exist`. migrate:install creates only the
# migrations repository table, so the skip command has somewhere to record.
compose run --rm -T testapp php artisan migrate:install \
  >"$ARTIFACTS/migrate.log" 2>&1 || die "migrate:install failed"
pok "migrations table created (the empty fixture had none)"
compose run --rm -T testapp php artisan db:skip-mysql-only-view-migrations \
  >>"$ARTIFACTS/migrate.log" 2>&1 || die "db:skip-mysql-only-view-migrations failed"
note "STATED LIMIT of this fixture: the 15 MySQL-only view migrations are marked"
note "as run, so those views do NOT exist here. In Supabase they were created by"
note "another mechanism. No test references them (grep over tests/ and app/), but"
note "the fixture is therefore not view-for-view identical to the shared database."
#
# 7.4 The replay itself. A FAILURE HERE IS A FINDING, NOT A WORKAROUND CUE.
#
#   WITHOUT the deferral patch, this aborts at
#   2022_05_31_223828_create_empresas_table, which declares
#   foreign('city_id')->references('id')->on('cities') while
#   2022_07_28_183312_create_cities_table sorts later, and there is no
#   database/schema dump to short-circuit the replay.
#
#   MEASURED ON POSTGRESQL 16.15, which is what this fixture runs. Whether
#   other engines reject the same declaration is NOT established by this run
#   and is not claimed: the earlier wording said "in any engine", which was
#   reasoning about foreign key behaviour, not evidence from a measurement.
#
#   Still not worked around: reordering or renaming migrations, dropping a
#   constraint or disabling foreign keys would be editing the code under test
#   to manufacture a green. The patch defers four declarations and SQL
#   re-creates all four below, which is why 7.6 asks the catalogue instead of
#   trusting that.
#
MIGRATE_OK=yes
if ! compose run --rm -T testapp php artisan migrate --force --no-interaction \
       --path=database/migrations --path=database/migrations/pgsql \
       >>"$ARTIFACTS/migrate.log" 2>&1; then
  MIGRATE_OK=no
  finding "migrate FAILED even with the deferral patch applied - recorded, not worked around"
  grep -oE 'SQLSTATE\[[^]]*\][^(]*' "$ARTIFACTS/migrate.log" | head -3 | sed 's/^/           /'
  pbad "the fixture schema is incomplete; the suite below cannot be read as a code result"
else
  pok "all migrations replayed into the empty fixture"
fi

# 7.5 Re-create the four deferred foreign keys, now that their tables exist.
if [ "$MIGRATE_OK" = "yes" ]; then
  if compose exec -T testdb psql -v ON_ERROR_STOP=1 -U cpv_test -d cpv_test \
       < "$COMPOSE_DIR/schema_fix/restore_four_fks.sql" \
       >"$ARTIFACTS/schema_fix_sql.log" 2>&1; then
    pok "the four deferred foreign keys were re-created with Laravel's own names"
  else
    pbad "re-creating the deferred foreign keys FAILED"
    tail -6 "$ARTIFACTS/schema_fix_sql.log" | sed 's/^/           /'
    MIGRATE_OK=no
  fi
fi

# 7.6 Ask the CATALOGUE whether integrity survived. Fails closed: the SQL raises
#     on a missing constraint, so this cannot pass by omission.
if [ "$MIGRATE_OK" = "yes" ]; then
  if compose exec -T testdb psql -v ON_ERROR_STOP=1 -U cpv_test -d cpv_test \
       < "$COMPOSE_DIR/schema_fix/verify_constraints.sql" \
       >"$ARTIFACTS/constraints.txt" 2>&1; then
    pok "catalogue confirms all four constraints exist, with their definitions"
    grep -E 'foreign_keys_total|FOREIGN KEY|BASE TABLE|VIEW' "$ARTIFACTS/constraints.txt" \
      | head -8 | sed 's/^/           /'
  else
    pbad "constraint verification FAILED - the schema is missing a foreign key"
    tail -8 "$ARTIFACTS/constraints.txt" | sed 's/^/           /'
    MIGRATE_OK=no
  fi
fi

# 7.7 DATA THE SUITE ASSUMES EXISTS, which is a finding in its own right.
#
#   With the schema complete the dominant failure became
#   `PermissionDoesNotExist: There is no permission named
#   update_taxonomy::candidate::concept::link for guard web` - 265 errors of it.
#   The tests assume the Shield-style permission catalogue is already in the
#   database. No test creates it, and DatabaseSeeder does not call the seeders
#   that do, so a freshly migrated database cannot satisfy the suite.
#
#   These are the PROJECT'S OWN seeders, both idempotent (firstOrCreate), run
#   explicitly here. No permission, role or fixture row is invented by this
#   script.
if [ "$MIGRATE_OK" = "yes" ]; then
  SEED_OK=yes
  for seeder in Phase3PermissionsSeeder TaxonomyV2PermissionsSeeder; do
    if compose run --rm -T testapp php artisan db:seed --class="$seeder" --force \
         >>"$ARTIFACTS/seed.log" 2>&1; then
      pok "seeded $seeder"
    else
      SEED_OK=no
      pbad "$seeder failed"
      tail -5 "$ARTIFACTS/seed.log" | sed 's/^/           /'
    fi
  done
  if [ "$SEED_OK" = "yes" ]; then
    COUNTS=$(compose run --rm -T testapp php -r '
require "vendor/autoload.php"; $a=require "bootstrap/app.php";
$a->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
printf("%d permissions, %d roles",
  DB::table("permissions")->count(), DB::table("roles")->count());' 2>/dev/null | tr -d '\r')
    say "permission catalogue: $COUNTS"
  fi
fi

TABLES=$(compose run --rm -T testapp php -r '
require "vendor/autoload.php"; $a=require "bootstrap/app.php";
$a->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
echo DB::select("select count(*) n from information_schema.tables where table_schema=\x27public\x27")[0]->n;' 2>/dev/null | tr -d '\r')
if [ "$MIGRATE_OK" = "yes" ]; then
  pok "fixture schema ready: $TABLES tables"
else
  say "fixture reached $TABLES tables before the failure (partial schema)"
fi

# --- 8. the suite ------------------------------------------------------------

step "8. Run PHPUnit"
ARGS=(vendor/bin/phpunit --do-not-cache-result)
#
# WHICH SUITES CAN HONESTLY RUN
#   17 test files use DatabaseTransactions, which wraps each test in a
#   transaction and rolls it back - it does NOT create tables. Only one uses
#   RefreshDatabase. So the Feature suite assumes a schema that already exists.
#   WHAT THAT DOES AND DOES NOT ESTABLISH: those tests need a pre-existing
#   schema, and phpunit.xml's commented-out sqlite override establishes that
#   DB_CONNECTION comes from the environment. Neither identifies which database
#   any historical run actually used - an earlier version of this comment
#   asserted it was the shared Supabase one, which was inference presented as
#   fact. The historical 197/197 stands as evidence of that execution; it is
#   simply not evidence that the migrations can rebuild an empty database.
#   With a partial schema, running Feature would produce a wall of
#   "relation does not exist" errors that say nothing about the code. That is
#   reported as BLOCKED by the migration defect rather than run for appearances.
SUITES_RUN="Unit+Feature (full suite)"
[ -n "$SUITE" ]  && SUITES_RUN="$SUITE suite only (requested)"
[ -n "$FILTER" ] && SUITES_RUN="$SUITES_RUN, filter $FILTER"
if [ -z "$SUITE" ] && [ -z "$FILTER" ] && [ "$MIGRATE_OK" != "yes" ]; then
  SUITE=Unit
  SUITES_RUN="Unit only (Feature BLOCKED by the migration-order defect)"
  note "Feature suite BLOCKED: it needs the pre-existing schema that the"
  note "migration set cannot build. Running the Unit suite, which does not."
fi
[ -n "$SUITE" ]  && ARGS+=(--testsuite "$SUITE")
[ -n "$FILTER" ] && ARGS+=(--filter "$FILTER")
say "command: ${ARGS[*]}"
# Named and NOT --rm, so the container that actually ran the suite survives long
# enough to be inspected. The step-4 evidence is from the service container; this
# is from the one that ran the tests, which is what the isolation claim is about.
PHPUNIT_CONTAINER="${PROJECT}-phpunit-${RUN_ID}"
docker rm -f "$PHPUNIT_CONTAINER" >/dev/null 2>&1 || true
set +o pipefail
compose run --name "$PHPUNIT_CONTAINER" -T testapp "${ARGS[@]}" 2>&1 | tee "$ARTIFACTS/phpunit.txt"
PHPUNIT_EXIT=${PIPESTATUS[0]}
set -o pipefail
docker inspect "$PHPUNIT_CONTAINER" --format '{{range .Mounts}}{{.Type}} {{.Source}} -> {{.Destination}} rw={{.RW}}
{{end}}' 2>/dev/null | grep -v '^[[:space:]]*$' > "$ARTIFACTS/mounts_phpunit.txt" || true
assert_mounts "$ARTIFACTS/mounts_phpunit.txt" "the container that ran PHPUnit"
docker rm -f "$PHPUNIT_CONTAINER" >/dev/null 2>&1 || true
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
  echo "suites_run=$SUITES_RUN"
  echo "findings=$FINDINGS"
  echo "-- isolation --"
  echo "isolation_passed=$PASSED"
  echo "isolation_failed=$FAILED"
  echo "-- schema preparation --"
  echo "schema_fix_applied=$APPLIED_PATCHES"
  echo "pgsql_bootstrap_from=$SIBLING_BRANCH@$SIBLING_SHA"
  echo "pgsql_bootstrap_migrations=${BOOTSTRAP_COUNT:-0}"
  echo "migration_ordering_violations=$DEPS_VIOLATIONS"
  echo "migrate_ok=$MIGRATE_OK"
  echo "prep_passed=$PREP_PASSED"
  echo "prep_failed=$PREP_FAILED"
  echo "fixture_tables=$TABLES"
  echo "-- tests --"
  echo "staging_before=$SMOKE_BEFORE"
  echo "staging_after=$SMOKE_AFTER"
  echo "php_version=$(compose run --rm -T testapp php -r 'echo PHP_VERSION;' 2>/dev/null | tr -d '\r')"
  echo "extensions=$(compose run --rm -T testapp php -r 'echo implode(",",array_intersect(["pdo_pgsql","intl","zip","gd","bcmath"],get_loaded_extensions()));' 2>/dev/null | tr -d '\r')"
} > "$ARTIFACTS/summary.txt"

teardown

printf '\n%s\n' "$(printf '%.0s-' $(seq 1 70))"
cat "$ARTIFACTS/summary.txt"
printf '%s\n' "$(printf '%.0s-' $(seq 1 70))"
# THE THREE GROUPS ARE REPORTED SEPARATELY, never collapsed into one verdict.
# A green suite that needed a prepared schema is not the same result as a green
# suite that needed nothing, and a finding is not an isolation failure.
[ "$FAILED" -eq 0 ]      && I="PASS ($PASSED checks)"      || I="FAIL ($FAILED of $((PASSED+FAILED)))"
[ "$PREP_FAILED" -eq 0 ] && P="PASS ($PREP_PASSED steps)"  || P="FAIL ($PREP_FAILED of $((PREP_PASSED+PREP_FAILED)))"
[ "$PHPUNIT_EXIT" = "0" ] && T="GREEN"                     || T="RED (exit $PHPUNIT_EXIT)"
echo "RESULT  isolation      : $I"
echo "RESULT  schema prep    : $P   [patch applied; $DEPS_VIOLATIONS ordering violation(s) deferred]"
echo "RESULT  test suite     : $T   [$SUITES_RUN]"
echo "RESULT  findings in the repository under test: $FINDINGS"
# Exit: 0 all three good, 3 isolation held but the schema could not be prepared
# so the suite result says nothing about the code, 1 anything else.
if [ "$FAILED" -eq 0 ] && [ "$PREP_FAILED" -eq 0 ] && [ "$PHPUNIT_EXIT" = "0" ]; then exit 0; fi
if [ "$FAILED" -eq 0 ] && [ "$PREP_FAILED" -gt 0 ]; then exit 3; fi
exit 1
