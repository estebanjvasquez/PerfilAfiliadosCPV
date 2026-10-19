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
# --ux runs the browser check of the rendered authenticated page; --skip-suite
# leaves PHPUnit out. Together they give the UX evidence without rerunning the
# whole suite, which review 6171023438 explicitly did not want for screenshots.
UX=no
# --ux-script <file.mjs> elige el chequeo de navegador (por defecto ux_check.mjs,
# el del manual). Implica --ux. Solo nombres de archivo de docker/test/.
UX_SCRIPT=ux_check.mjs
# --ux-seed <file.php> (CPV-TAXONOMY-MAP) corre ese PHP de docker/test/ dentro de la app
# desechable, despues de crear los usuarios sinteticos y antes de servir la app: datos
# SINTETICOS de tamano real para medir la pagina (nunca datos copiados de una base viva).
UX_SEED=""
SKIP_SUITE=no
PREFLIGHT=""
while [ $# -gt 0 ]; do
  case "$1" in
    --suite)  SUITE="${2:-}"; shift 2 ;;
    --filter) FILTER="${2:-}"; shift 2 ;;
    --ux)         UX=yes; shift ;;
    --ux-script)  UX=yes; UX_SCRIPT="${2:-}"; shift 2 ;;
    --ux-seed)    UX=yes; UX_SEED="${2:-}"; shift 2 ;;
    --skip-suite) SKIP_SUITE=yes; shift ;;
    # Ejecuta un script de preflight de SOLO LECTURA del workspace contra la base
    # desechable. Existe para que un preflight que se va a entregar para una base
    # viva se haya ejecutado al menos una vez en algun lado seguro.
    --preflight)  PREFLIGHT="${2:-}"; shift 2 ;;
    *) echo "unknown argument: $1" >&2; exit 2 ;;
  esac
done

case "$UX_SCRIPT" in
  *[!a-z0-9_.]*|"") echo "--ux-script must be a plain file name in docker/test/" >&2; exit 2 ;;
  *.mjs) ;;
  *) echo "--ux-script must be a .mjs file" >&2; exit 2 ;;
esac
case "$UX_SEED" in
  "") ;;
  *[!a-z0-9_.]*) echo "--ux-seed must be a plain file name in docker/test/" >&2; exit 2 ;;
  *.php) ;;
  *) echo "--ux-seed must be a .php file" >&2; exit 2 ;;
esac
[ -n "$SHA" ] || { echo "usage: $0 <sha> [--suite Unit|Feature] [--filter <pattern>] [--ux] [--ux-script <f.mjs>] [--ux-seed <f.php>] [--skip-suite]" >&2; exit 2; }
case "$SHA" in *[!0-9a-f]*|"") echo "sha must be lowercase hex" >&2; exit 2 ;; esac
[ "${#SHA}" -eq 40 ] || { echo "sha must be 40 characters" >&2; exit 2; }

REPO_URL=https://github.com/estebanjvasquez/PerfilAfiliadosCPV.git
PROJECT=cpvtest
COMPOSE_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
RUN_ID="$(date -u +%Y%m%dT%H%M%SZ)-${SHA:0:12}"
# HOST PATHS COME FROM THE ENVIRONMENT, AND THAT IS A RULE, NOT A PREFERENCE.
#
#   This repository is PUBLIC. PROJECT_KNOWLEDGE.md's visibility notice lets
#   already-published infrastructure identifiers stay but forbids adding new
#   ones - system users, database hosts, internal paths. Verdict 6152642797
#   reported exactly that against the audit document; the same rule applies
#   here, in the same public repository, on a different branch. Measured at
#   99e603cb, the scratch root under the host account had 0 prior occurrences,
#   so hard-coding it here was new internal detail.
#
#   CPV_TEST_ROOT therefore has NO default: a missing value is a loud failure,
#   never a silent fallback to somebody's home directory. The live application
#   directory keeps its default because it is already published 13 times,
#   including in .github/workflows/deploy-contabo.yml.
TEST_ROOT="${CPV_TEST_ROOT:?set CPV_TEST_ROOT to a scratch directory for test workspaces, outside this repository}"
case "$TEST_ROOT" in
  /*) : ;;
  *) echo "CPV_TEST_ROOT must be an absolute path, got: $TEST_ROOT" >&2; exit 2 ;;
esac
WORKSPACE="${TEST_ROOT}/${RUN_ID}"
ARTIFACTS="${TEST_ROOT}/artifacts/${RUN_ID}"
LIVE_APP_DIR="${CPV_LIVE_APP_DIR:-/opt/perfilafiliados}"
STAGING_URL=https://pruebas.camarapetrolera.app/

say()  { printf '  %s\n' "$*"; }
step() { printf '\n== %s\n' "$*"; }
# Renders every page of every PDF in an ARTIFACTS directory as a PNG (poppler's pdftoppm, from the test image). Only
# that directory is mounted, with no network: the files are synthetic evidence of this run.
render_pdf_pages() {
  local dir="$1"
  docker run --rm --network none -v "$dir:/d" -w /d cpvtest-php:8.2 \
    sh -c 'for f in *.pdf; do pdftoppm -r 70 -png "$f" "${f%.pdf}-page" || echo "render failed: $f"; done' \
    >"$dir/render.log" 2>&1 || true
  say "PDF pages rendered in $(basename "$dir")/: $(ls "$dir"/*.pdf 2>/dev/null | wc -l) PDF(s), $(ls "$dir"/*-page-*.png 2>/dev/null | wc -l) page image(s)"
}
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
# Loading fixture DATA is a FOURTH group, counted apart from building the
# SCHEMA. They fail for unrelated reasons and the distinction is the whole
# question of this round: a schema that rebuilds but has no rows, and a schema
# that rebuilds and is then populated, produce the same `migrate_ok=yes` and
# very different suites. Collapsing them would hide which one happened.
FIX_PASSED=0; FIX_FAILED=0
fok()  { FIX_PASSED=$((FIX_PASSED+1)); printf '  [FIXTURE PASS] %s\n' "$*"; }
fbad() { FIX_FAILED=$((FIX_FAILED+1)); printf '  [FIXTURE FAIL] %s\n' "$*"; }

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
#
# TWO ATTEMPTS, and the second one is not a retry of the same thing.
#
# `--prefer-dist` downloads zipballs from api.github.com, and when GitHub returns
# a 500 for one package composer aborts the whole install with "Source fallback
# is disabled. Not trying alternative sources." That happened for real, twice in
# a row for phpunit/php-text-template, on a tree that had installed fine an hour
# earlier - so it is not a flake to shrug at and not a defect in the code under
# test. It is a dependency of the HARNESS on a third-party registry being up.
#
# The fallback clones from source instead. Same packages, same composer.lock,
# same resolved versions - only the transport differs, so it cannot change what
# is installed. It is slower, which is why it is the fallback and not the
# default.
#
# The composer cache lives in TEST_ROOT, OUTSIDE the workspace, so it survives
# the per-run teardown and a package fetched once is never fetched again. That
# is also what makes the harness mostly immune to this class of outage. It is a
# cache of public packages, nothing run-specific, so sharing it across runs
# leaks nothing between them: the workspace, the database and the credentials
# are still created fresh every time.
COMPOSER_CACHE="${TEST_ROOT}/composer-cache"
mkdir -p "$COMPOSER_CACHE"
composer_install() {
  compose run --rm -T \
    -v "$COMPOSER_CACHE:/composer-cache" \
    -e COMPOSER_CACHE_DIR=/composer-cache \
    testapp composer install --no-interaction --no-scripts "$@"
}
if composer_install --prefer-dist >"$ARTIFACTS/composer.log" 2>&1; then
  ok "dependencies installed from dist"
else
  finding "composer --prefer-dist failed; retrying with source fallback (registry problem, not the code under test)"
  grep -oE 'could not be downloaded \([^)]*\)|HTTP/[0-9.]+ [0-9]{3}' "$ARTIFACTS/composer.log" \
    | sort -u | head -3 | sed 's/^/           /'
  if composer_install >>"$ARTIFACTS/composer.log" 2>&1; then
    ok "dependencies installed with source fallback (same composer.lock, same versions)"
  else
    tail -20 "$ARTIFACTS/composer.log"
    die "composer install failed from dist AND from source"
  fi
fi
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
#
#     A SHA THAT ALREADY CONTAINS A PATCH IS NOT AN ERROR, and telling the two
#     cases apart matters: `apply --reverse --check` succeeding means the change
#     is already in the tree. Without this the harness could only ever test the
#     integration branch, and the whole point of preparing the corrections on an
#     isolated branch is to run this same harness against it and see the
#     preparation phase shrink to nothing. Three outcomes, each reported as
#     itself: applied here, already present at this SHA, or does not fit - and
#     only the third is fatal.
SCHEMA_PATCHES="defer_four_fks.patch align_permission_migration_to_v6.patch portable_index_check.patch"
APPLIED_PATCHES=""
PRESENT_PATCHES=""
for name in $SCHEMA_PATCHES; do
  PATCH="$COMPOSE_DIR/schema_fix/$name"
  [ -f "$PATCH" ] || die "schema_fix/$name is missing"
  if git -C "$WORKSPACE" apply --check "$PATCH" 2>>"$ARTIFACTS/schema_fix.log"; then
    git -C "$WORKSPACE" apply "$PATCH" || die "$name passed --check but failed to apply"
    APPLIED_PATCHES="${APPLIED_PATCHES}${APPLIED_PATCHES:+,}$name"
    pok "applied $name"
  elif git -C "$WORKSPACE" apply --reverse --check "$PATCH" 2>>"$ARTIFACTS/schema_fix.log"; then
    PRESENT_PATCHES="${PRESENT_PATCHES}${PRESENT_PATCHES:+,}$name"
    pok "$name is ALREADY PRESENT at $SHA - nothing to apply"
  else
    pbad "$name neither applies nor is already present at $SHA - NOT forced"
    tail -6 "$ARTIFACTS/schema_fix.log" | sed 's/^/           /'
    die "refusing to mangle the workspace; regenerate the patch for this SHA"
  fi
done
[ -n "$PRESENT_PATCHES" ] && note "already in the code under test: $PRESENT_PATCHES"
# Only the migrations, so the record is the patches' effect and not the mode
# changes that preparing a writable workspace makes.
git -C "$WORKSPACE" diff -- database/migrations > "$ARTIFACTS/schema_fix_applied.diff"
say "net effect on migrations: $(git -C "$WORKSPACE" diff --shortstat -- database/migrations | sed 's/^ *//')"
note "Applied to $WORKSPACE only. No existing database and no branch is touched."
if [ -n "$APPLIED_PATCHES" ]; then
  note "The suite below therefore runs $SHA PLUS: $APPLIED_PATCHES"
else
  note "NOTHING was patched: the suite below runs $SHA exactly as committed."
fi

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
#
#   MEASURED, NOT ASSUMED, at each SHA. The indispensable file is the one that
#   CREATES the table the six migrations reference: supplier_categories, which
#   2026_09_07_090000 then renames to taxonomy_categories. If this SHA already
#   carries it, the branch is self-contained for this purpose and nothing is
#   borrowed - which is exactly what an isolated branch carrying the dependency
#   should demonstrate. The check is for the creating migration, not for a
#   non-empty directory, because the integration branch HAS that directory: it
#   holds the 2026_09_07 rework alone, whose own docblock says it assumes those
#   tables already exist.
SIBLING_BRANCH=feature/supplhi-postgres-buscador
CATEGORY_CREATOR=$(grep -rl "create('supplier_categories'" "$WORKSPACE/database/migrations/" 2>/dev/null | head -1)
if [ -n "$CATEGORY_CREATOR" ]; then
  SIBLING_SHA="not needed at this SHA"
  BOOTSTRAP_COUNT=$(ls "$WORKSPACE"/database/migrations/pgsql/*.php 2>/dev/null | wc -l | tr -d ' ')
  pok "the category-creating migration is ALREADY at this SHA ($(basename "$CATEGORY_CREATOR"))"
  note "nothing borrowed from $SIBLING_BRANCH; database/migrations/pgsql holds $BOOTSTRAP_COUNT file(s)"
  ls "$WORKSPACE"/database/migrations/pgsql/*.php | xargs -n1 basename > "$ARTIFACTS/pgsql_bootstrap_migrations.txt"
elif git -C "$WORKSPACE" fetch -q --depth 1 origin "$SIBLING_BRANCH" 2>>"$ARTIFACTS/schema_fix.log"; then
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
#
#     ASK FIRST, because there are now two ways they can get there. On the
#     integration branch the deferral is a PATCH, so nothing re-creates them and
#     this SQL must run. On a branch that carries the correction as a committed
#     migration, that migration already created them during the replay - and
#     restore_four_fks.sql deliberately has no IF NOT EXISTS (an
#     already-present constraint on a fixture that started empty means
#     something else created it), so running it anyway would abort the
#     preparation phase on the branch that needs it least.
#
#     The question goes to the catalogue, not to a variable this script set.
FK_RESTORE=skipped
if [ "$MIGRATE_OK" = "yes" ]; then
  FKS_PRESENT=$(compose exec -T testdb psql -tAq -U cpv_test -d cpv_test -c \
    "select count(*) from pg_constraint where contype='f' and conname in (
       'empresas_city_id_foreign','sustainabilities_areas_id_foreign',
       'infratypes_infrasectors_id_foreign',
       'infrafacilities_infraregion_infrasystem_id_foreign')" 2>/dev/null | tr -d '\r[:space:]')
  if [ "$FKS_PRESENT" = "4" ]; then
    FK_RESTORE="not needed (the replay itself created all four)"
    pok "the four deferred foreign keys exist already after the replay - no out-of-band SQL run"
  elif [ "$FKS_PRESENT" = "0" ]; then
    if compose exec -T testdb psql -v ON_ERROR_STOP=1 -U cpv_test -d cpv_test \
         < "$COMPOSE_DIR/schema_fix/restore_four_fks.sql" \
         >"$ARTIFACTS/schema_fix_sql.log" 2>&1; then
      FK_RESTORE="restore_four_fks.sql"
      pok "the four deferred foreign keys were re-created with Laravel's own names"
    else
      pbad "re-creating the deferred foreign keys FAILED"
      tail -6 "$ARTIFACTS/schema_fix_sql.log" | sed 's/^/           /'
      MIGRATE_OK=no
    fi
  else
    pbad "found $FKS_PRESENT of the 4 deferred foreign keys - a partial state neither path produces"
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
  # The GOVERNED VOCABULARY, from the project's own versioned command.
  #
  # Six tests were failing with reason UNKNOWN_OR_INACTIVE_RELATION_TYPE, and a
  # seventh (corpus case K) on a zero intent-contamination penalty. Neither is a
  # code defect: CanonicalConceptBuilderService::validateConceptRelationProposal
  # looks the relation type up in taxonomy_concept_relation_types and refuses
  # what the catalogue does not list, and the penalty is computed from
  # taxonomy_intent_markers. On an empty database both tables are empty, so
  # every proposal is correctly refused and no term can be contaminated.
  #
  # taxonomy:seed-concept-vocabulary is the PROJECT'S definition of those three
  # catalogues - 9 concept types, 5 relation types, 9 intent markers, all
  # literal in app/Console/Commands/SeedTaxonomyConceptVocabulary.php - and it
  # is insertOrIgnore by natural key, so it never overwrites an edited row.
  # Nothing here is invented by this script.
  for cmd in taxonomy:seed-concept-vocabulary; do
    if compose run --rm -T testapp php artisan "$cmd" \
         >>"$ARTIFACTS/seed.log" 2>&1; then
      pok "ran $cmd (the project's own versioned catalogue)"
    else
      SEED_OK=no
      pbad "$cmd failed"
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

# --- 7-bis. FIXTURE LOAD -----------------------------------------------------
#
# ITS OWN PHASE AND ITS OWN COUNTERS, for the same reason schema preparation is
# separate from isolation: a suite that needed rows loaded for it must never be
# reportable as a suite that needed nothing. "fixtures" is the third preparation
# group - schema, catalogues, fixture data - and the report keeps the three
# apart.
#
# WHAT IS LOADED AND WHERE IT COMES FROM
#   Nine tests state a data precondition in their own failure message: two want
#   at least one and at least two companies, five want 18 reference categories,
#   and two corpus cases want a category TREE deep enough to have a leaf, a
#   sibling and a second family under the same group. None of them creates that
#   data; all of them use DatabaseTransactions, so they expect it to be there
#   already and roll back only what they add themselves.
#
#   docker/test/fixtures/synthetic_fixture.sql is a hand-written versioned
#   definition: every value is literal in the file, the codes are ZZTEST-
#   prefixed so no row can be mistaken for the real reference catalogue, and the
#   companies carry invalid-by-construction RIFs. NOTHING IS COPIED FROM ANY
#   EXISTING DATABASE - not from the shared Supabase database, not from staging.
#   The file refuses to run unless it is connected to the disposable `cpv_test`
#   database AND both tables are empty, and it asserts the loaded shape itself,
#   so a partial load is a failure here and not a puzzling test failure later.
#
#   Authorised by the owner decision of 2026-10-14, for the disposable test
#   PostgreSQL only.
FIXTURE_LOADED=no
if [ "$MIGRATE_OK" = "yes" ]; then
  step "7-bis. FIXTURE LOAD (its own phase: not schema, not the suite)"
  FIXTURE_SQL="$COMPOSE_DIR/fixtures/synthetic_fixture.sql"
  [ -f "$FIXTURE_SQL" ] || die "fixtures/synthetic_fixture.sql is missing"
  if compose exec -T testdb psql -v ON_ERROR_STOP=1 -U cpv_test -d cpv_test \
       < "$FIXTURE_SQL" >"$ARTIFACTS/fixture_load.log" 2>&1; then
    FIXTURE_LOADED=yes
    fok "synthetic fixture loaded and its own shape assertions passed"
    grep -E 'NOTICE|fixture ok' "$ARTIFACTS/fixture_load.log" | head -3 | sed 's/^/           /'
  else
    fbad "the synthetic fixture FAILED to load - the data preconditions are NOT met"
    tail -8 "$ARTIFACTS/fixture_load.log" | sed 's/^/           /'
  fi

  # The preconditions, asked the way the TESTS ask them rather than the way the
  # fixture states them. A fixture that asserts its own counts proves it loaded;
  # this proves the thing the tests actually read.
  compose exec -T testdb psql -tAq -U cpv_test -d cpv_test \
    >"$ARTIFACTS/fixture_preconditions.txt" 2>&1 <<'PRECHECK'
\echo '-- precondition: >= 1 empresa (predict_affected_companies_counts_direct_companies_deduplicated_by_empresa_id)'
select case when count(*) >= 1 then 'PASS' else 'FAIL' end || '  empresas >= 1  (have ' || count(*) || ')' from empresas;
\echo '-- precondition: >= 2 empresas (predict_affected_companies_breaks_down_direct_evidence_by_origen)'
select case when count(*) >= 2 then 'PASS' else 'FAIL' end || '  empresas >= 2  (have ' || count(*) || ')' from empresas;
\echo '-- precondition: >= 18 categories with a code (TaxonomyConceptExplorerTest::existingCategoryIds)'
select case when count(*) >= 18 then 'PASS' else 'FAIL' end || '  categories with code >= 18  (have ' || count(*) || ')' from taxonomy_categories where code is not null;
\echo '-- precondition: >= 1 level-2 category (ValidationCorpusTest::anyLeafCategoryId)'
select case when count(*) >= 1 then 'PASS' else 'FAIL' end || '  level-2 categories >= 1  (have ' || count(*) || ')' from taxonomy_categories where level = 2;
\echo '-- precondition: the first level-2 category has a sibling (siblingCategoryUnderSameFamily)'
select case when count(*) >= 1 then 'PASS' else 'FAIL' end || '  siblings of the first leaf  (have ' || count(*) || ')'
from taxonomy_categories
where parent_id = (select parent_id from taxonomy_categories where level = 2 order by id limit 1)
  and id <> (select id from taxonomy_categories where level = 2 order by id limit 1);
\echo '-- precondition: a second family under the same group has a child (categoryInDifferentFamilySameGroup)'
select case when count(*) >= 1 then 'PASS' else 'FAIL' end || '  children of the other family  (have ' || count(*) || ')'
from taxonomy_categories
where parent_id in (
  select f.id from taxonomy_categories f
  where f.parent_id = (select parent_id from taxonomy_categories
                        where id = (select parent_id from taxonomy_categories where level = 2 order by id limit 1))
    and f.id <> (select parent_id from taxonomy_categories where level = 2 order by id limit 1));
\echo '-- precondition: the relation-type catalogue is populated and active (validateConceptRelationProposal)'
select case when count(*) >= 5 then 'PASS' else 'FAIL' end || '  active relation types >= 5  (have ' || count(*) || ')' from taxonomy_concept_relation_types where active;
\echo '-- precondition: intent markers exist (corpus case K intent_contamination_penalty)'
select case when count(*) >= 1 then 'PASS' else 'FAIL' end || '  intent markers >= 1  (have ' || count(*) || ')' from taxonomy_intent_markers;
PRECHECK
  PRE_FAIL=$(grep -c '^FAIL' "$ARTIFACTS/fixture_preconditions.txt" 2>/dev/null || true)
  PRE_PASS=$(grep -c '^PASS' "$ARTIFACTS/fixture_preconditions.txt" 2>/dev/null || true)
  sed 's/^/           /' "$ARTIFACTS/fixture_preconditions.txt"
  if [ "${PRE_FAIL:-1}" = "0" ] && [ "${PRE_PASS:-0}" -ge 8 ]; then
    fok "all $PRE_PASS declared test preconditions are satisfied by the fixture"
  else
    fbad "$PRE_FAIL declared precondition(s) NOT satisfied (fixture_preconditions.txt)"
  fi
fi

if [ "$MIGRATE_OK" = "yes" ]; then
  # 7.8 WHAT DATA THE FIXTURE ACTUALLY HOLDS, measured rather than assumed.
  #     Several tests state their own data preconditions in their failure
  #     messages ("Se necesita al menos una empresa real en la BD de pruebas",
  #     "Precondición: hacen falta 18 categorías reales de referencia"), so the
  #     row counts are part of the evidence: they say what those tests had.
  compose run --rm -T testapp php -r '
require "vendor/autoload.php"; $a=require "bootstrap/app.php";
$a->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
foreach (["empresas","taxonomy_categories","taxonomy_terms","taxonomy_canonical_concepts",
          "taxonomy_term_cpv_relations","users","permissions","roles",
          "taxonomy_concept_types","taxonomy_concept_relation_types","taxonomy_intent_markers",
          "empresa_taxonomy_category"] as $t) {
    try { printf("%-30s %d\n", $t, DB::table($t)->count()); }
    catch (Throwable $e) { printf("%-30s (unreadable)\n", $t); }
}' 2>/dev/null | tr -d '\r' > "$ARTIFACTS/fixture_rows.txt"
  say "fixture data inventory (rows):"
  sed 's/^/           /' "$ARTIFACTS/fixture_rows.txt"
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

PHPUNIT_EXIT=skipped
SUITES_RUN="not run (--skip-suite)"
if [ "$SKIP_SUITE" = "yes" ]; then
step "8. PHPUnit SKIPPED (--skip-suite)"
note "Requested explicitly. The suite result below is 'not run', never 'green':"
note "a run that did not execute the suite must not read like a run that did."
else
step "8. Run PHPUnit"
# The summary line has reported "PHPUnit Deprecations: 1" on every run of this
# project and nothing said WHICH one, so review 6167931953 asked for it to be
# identified. TWO different flags, and the distinction is the whole reason the
# first attempt printed nothing: --display-deprecations shows deprecations
# triggered BY TEST CODE, while a "PHPUnit deprecation" is deprecated PHPUnit
# usage (configuration, attributes) and needs --display-phpunit-deprecations.
# Both only add output; neither changes a result.
#
# MEMORY (2026-10-16, CPV-FIRST-SAVE-POSTGRES-COMPATIBILITY): the full suite runs in ONE php process.
# At 515 tests it peaked at 123 MB against the CLI default of 128 MB; at 533 it died with "Allowed memory
# size of 134217728 bytes exhausted" after 428 passing tests. That is a limit of this harness, not a
# test result, so the runner raises it for phpunit only. The project's phpunit.xml is not touched.
ARGS=(php -d memory_limit=512M vendor/bin/phpunit --do-not-cache-result --display-deprecations --display-phpunit-deprecations)
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
# The suite's outcome belongs to the TEST group, so it must not touch the
# isolation counters. It did, and the run then printed "isolation: FAIL (1 of
# 19)" for a run in which every isolation assertion passed and only the suite
# was red - exactly the conflation the three-group split exists to prevent.
if [ "$PHPUNIT_EXIT" = "0" ]; then
  say "[SUITE GREEN] phpunit exited 0"
else
  say "[SUITE RED] phpunit exited $PHPUNIT_EXIT (recorded, not hidden)"
fi
# PDF evidence (CPV-COMPLETE-COMPANY-PDF): tests that read a generated PDF with pdftotext leave it, and its text, in
# storage/app/pdf_evidence of the disposable workspace. Kept with the artifacts, each page rendered as a PNG.
if [ -d "$WORKSPACE/storage/app/pdf_evidence" ]; then
  mkdir -p "$ARTIFACTS/pdf"
  cp -r "$WORKSPACE/storage/app/pdf_evidence/." "$ARTIFACTS/pdf/"
  render_pdf_pages "$ARTIFACTS/pdf"
fi
fi

# --- 8-ter. UX / BROWSER CHECK (its own group) -------------------------------
#
# Requested by review 6171023438: the contract's responsive and accessibility
# requirements were implemented and never exercised, so they were not acceptance
# evidence. This drives a REAL browser against the REAL authenticated Filament
# page, in this run's disposable stack - not a standalone HTML file, which the
# review rightly says must not be called authenticated verification.
#
# No separate staging deployment: `php artisan serve` runs inside the same
# throwaway container, on the private compose network, and publishes no host
# port. The browser is a sibling container on that network. Both die with the
# run.
#
# SESSION_DRIVER is overridden to `file` for this phase only. The compose file
# sets `array`, which is right for PHPUnit and useless for a browser: an
# in-memory session does not survive the redirect after login, so the affiliate
# could never actually reach the manual.
UX_PASSED=0; UX_FAILED=0
uxok()  { UX_PASSED=$((UX_PASSED+1)); printf '  [UX PASS] %s\n' "$*"; }
uxbad() { UX_FAILED=$((UX_FAILED+1)); printf '  [UX FAIL] %s\n' "$*"; }

if [ "$UX" = "yes" ] && [ "$MIGRATE_OK" = "yes" ]; then
  step "8-ter. UX check in a real browser (its own group)"
  mkdir -p "$ARTIFACTS/ux"

  # Synthetic affiliate and company, created here and nowhere else. No customer
  # data: the password is generated for this run and dies with it.
  UX_PASSWORD="$(head -c 18 /dev/urandom | od -An -tx1 | tr -d ' \n')"
  UX_EMAIL="zzz-ux-affiliate@example.test"
  UX_ADMIN_EMAIL="zzz-ux-admin@example.test"
  [ -f "$COMPOSE_DIR/$UX_SCRIPT" ] || uxbad "browser check script not found: $UX_SCRIPT"
  if compose run --rm -T \
       -e CPV_UX_EMAIL="$UX_EMAIL" -e CPV_UX_PASSWORD="$UX_PASSWORD" \
       -e CPV_UX_ADMIN_EMAIL="$UX_ADMIN_EMAIL" \
       testapp php -r '
require "vendor/autoload.php"; $a=require "bootstrap/app.php";
$a->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
$u = App\Models\User::firstOrCreate(
  ["email" => getenv("CPV_UX_EMAIL")],
  ["name" => "ZZZ UX Afiliado", "password" => Illuminate\Support\Facades\Hash::make(getenv("CPV_UX_PASSWORD")),
   "email_verified_at" => now()]
);
$u->forceFill(["password" => Illuminate\Support\Facades\Hash::make(getenv("CPV_UX_PASSWORD")), "email_verified_at" => now()])->save();
// Empresa::created() reads Auth::User()->id to write the empresa_user link, so
// creating a company with nobody logged in dies with "Attempt to read property
// id on null". Logging the synthetic user in first is what that hook expects,
// and it also makes the link for us. (That the hook is hostile to any
// non-HTTP context is a pre-existing property of the model, not this round.)
Illuminate\Support\Facades\Auth::login($u);
$e = App\Models\Empresa::firstOrCreate(["rif" => "J-00000000-9"], ["name" => "ZZZ UX Empresa Ficticia"]);
echo "ux user id=", $u->id, " empresa id=", $e->id,
     " linked=", (int) $e->users()->where("users.id", $u->id)->exists(), "\n";
// Super Admin sintetico (TASK-0010C): las pantallas de solicitudes son solo suyas.
$admin = App\Models\User::firstOrCreate(
  ["email" => getenv("CPV_UX_ADMIN_EMAIL")],
  ["name" => "ZZZ UX Super Admin", "password" => Illuminate\Support\Facades\Hash::make(getenv("CPV_UX_PASSWORD")),
   "email_verified_at" => now()]
);
$admin->forceFill(["password" => Illuminate\Support\Facades\Hash::make(getenv("CPV_UX_PASSWORD")), "email_verified_at" => now()])->save();
$admin->assignRole(Spatie\Permission\Models\Role::findOrCreate(config("filament-shield.super_admin.name"), "web"));
echo "ux admin id=", $admin->id, "\n";
// Una solicitud sintetica FALLIDA por falta de destinatario, como la #1 de staging, solo si
// el codigo bajo prueba tiene TASK-0010C. Transporte "array": nada sale del contenedor.
if (class_exists(App\Models\TaxonomyCategoryRequestDeliveryAttempt::class)) {
  App\Models\TaxonomySelectionSettings::current()->update(["category_request_recipient_email" => null]);
  $r = app(App\Services\TaxonomyCategoryRequestService::class)->submit($e, $u, [
    "necesidad" => "ZZZ inspeccion sintetica de recipientes a presion",
    "justificacion" => "CAPACIDAD_ESPECIALIZADA",
    "detalle" => "Solicitud sintetica creada por el arnes para el chequeo de navegador.",
    "terminos_probados" => "zzz, sintetico",
  ]);
  echo "ux request id=", $r->id, " delivery=", $r->delivery_status, " error=", $r->delivery_error, "\n";
}
' >"$ARTIFACTS/ux/seed_user.log" 2>&1; then
    uxok "synthetic affiliate and company created for the browser check"
    sed 's/^/           /' "$ARTIFACTS/ux/seed_user.log" | tail -2
  else
    uxbad "could not create the synthetic affiliate"
    tail -6 "$ARTIFACTS/ux/seed_user.log" | sed 's/^/           /'
  fi

  # Optional synthetic data for this browser check (--ux-seed). Runs as the app,
  # inside the disposable stack, reading the file on stdin: nothing from a live
  # database, nothing persisted outside this run.
  # The served host is fixed before the seed, so a seed can sign a URL for it (the
  # verification link of CPV-VERIFICATION-FLOW-AND-COMPLETION). The APP_KEY is
  # ephemeral: such a link is worthless once this run ends.
  UX_SERVER="${PROJECT}-uxserve-${RUN_ID}"
  if [ -n "$UX_SEED" ]; then
    if [ ! -f "$COMPOSE_DIR/$UX_SEED" ]; then
      uxbad "ux seed not found: $UX_SEED"
    elif compose run --rm -T -e CPV_UX_BASE_URL="http://${UX_SERVER}:8000" testapp php <"$COMPOSE_DIR/$UX_SEED" >"$ARTIFACTS/ux/ux_seed.log" 2>&1; then
      uxok "synthetic ux seed applied: $UX_SEED"
      tail -3 "$ARTIFACTS/ux/ux_seed.log" | sed 's/^/           /'
    else
      uxbad "ux seed failed: $UX_SEED"
      tail -8 "$ARTIFACTS/ux/ux_seed.log" | sed 's/^/           /'
    fi
  fi

  # Publish Filament's own CSS/JS into the workspace's public/ before serving.
  #
  # Without this the browser gets the page with only PARTIALLY styled panel
  # chrome, which is honest about geometry but misleading as a picture: the
  # first screenshots showed an unstyled sidebar. `filament:assets` is what a
  # deployment runs, so running it here makes the evidence representative
  # instead of an artefact of a workspace that never had an asset step.
  #
  # STATED LIMIT, since it is not the whole story: this publishes the package's
  # prebuilt assets. It does NOT run a frontend build (`npm run build`), so a
  # custom compiled theme is still absent, and the screenshots are therefore
  # close to but not byte-identical with a built deployment.
  if compose run --rm -T testapp php artisan filament:assets \
       >"$ARTIFACTS/ux/filament_assets.log" 2>&1; then
    uxok "Filament assets published into the workspace public/ (no frontend build)"
  else
    uxbad "filament:assets failed; the screenshots will show unstyled chrome"
    tail -5 "$ARTIFACTS/ux/filament_assets.log" | sed 's/^/           /'
  fi

  # Serve the app inside the workspace container, detached, no host port.
  UX_SERVER="${PROJECT}-uxserve-${RUN_ID}"
  docker rm -f "$UX_SERVER" >/dev/null 2>&1 || true
  # CACHE_DRIVER=file too: `php artisan serve` handles each request in a new process, so the
  # compose default (`array`) forgets rate-limiter hits between clicks and a throttle could
  # never be seen in the browser.
  compose run -d --name "$UX_SERVER" -e SESSION_DRIVER=file -e CACHE_DRIVER=file -T testapp \
    php artisan serve --host=0.0.0.0 --port=8000 >/dev/null 2>&1 || true
  # Wait for it, from inside the network rather than guessing with a sleep.
  UX_UP=no
  for _ in $(seq 1 30); do
    if docker run --rm --network "${PROJECT}_cpvtest_internal" curlimages/curl:8.11.1 \
         -s -o /dev/null --max-time 3 "http://${UX_SERVER}:8000/admin/login" 2>/dev/null; then
      UX_UP=yes; break
    fi
    sleep 2
  done
  if [ "$UX_UP" = "yes" ]; then
    uxok "the application is serving inside the disposable stack (no host port published)"
  else
    uxbad "the application never came up; the browser check cannot run"
    docker logs "$UX_SERVER" 2>&1 | tail -15 | sed 's/^/           /'
  fi

  # The playwright image ships the BROWSERS (/ms-playwright) but not the npm
  # package, so `import { chromium } from 'playwright'` fails with
  # ERR_MODULE_NOT_FOUND. Install it once into a cache in TEST_ROOT, pinned to
  # the image's own version so the client and the browsers cannot drift, and
  # mount it read-only afterwards. Same reasoning as the composer cache: outside
  # the workspace, so teardown does not throw it away.
  PW_VERSION=1.49.1
  PW_CACHE="${TEST_ROOT}/playwright-node"
  if [ ! -d "$PW_CACHE/node_modules/playwright" ]; then
    mkdir -p "$PW_CACHE"
    if docker run --rm -v "$PW_CACHE:/pw" -w /pw \
         mcr.microsoft.com/playwright:v${PW_VERSION}-jammy \
         npm install --silent --no-audit --no-fund "playwright@${PW_VERSION}" \
         >"$ARTIFACTS/ux/playwright_install.log" 2>&1; then
      uxok "playwright client ${PW_VERSION} installed into the cache"
    else
      uxbad "could not install the playwright client"
      tail -8 "$ARTIFACTS/ux/playwright_install.log" | sed 's/^/           /'
      UX_UP=no
    fi
  else
    uxok "playwright client ${PW_VERSION} already cached"
  fi

  if [ "$UX_UP" = "yes" ]; then
    set +e
    # The script is mounted NEXT TO the cached node_modules, not into /t.
    # NODE_PATH does not apply to ESM `import`: resolution walks up from the
    # importing module's own directory looking for node_modules, so a script in
    # /t could never see a package in /pwnm no matter what NODE_PATH said.
    docker run --rm \
      --network "${PROJECT}_cpvtest_internal" \
      -v "$PW_CACHE/node_modules:/pw/node_modules:ro" \
      -v "$COMPOSE_DIR/$UX_SCRIPT:/pw/ux_check.mjs:ro" \
      -v "$ARTIFACTS/ux:/out" \
      -w /pw \
      -e BASE_URL="http://${UX_SERVER}:8000" \
      -e UX_EMAIL="$UX_EMAIL" -e UX_PASSWORD="$UX_PASSWORD" \
      -e UX_ADMIN_EMAIL="$UX_ADMIN_EMAIL" \
      mcr.microsoft.com/playwright:v${PW_VERSION}-jammy \
      node /pw/ux_check.mjs >"$ARTIFACTS/ux/ux_check.log" 2>&1
    UX_EXIT=$?
    set -e
    sed 's/^/           /' "$ARTIFACTS/ux/ux_check.log"
    if [ "$UX_EXIT" = "0" ]; then
      uxok "browser checks passed (see ux/ux_results.json and the screenshots)"
    else
      uxbad "browser checks reported failures (exit $UX_EXIT) - see ux/ux_results.json"
    fi
    ls -1 "$ARTIFACTS/ux" | sed 's/^/           /'
    # Files the browser downloaded (CPV-COMPLETE-COMPANY-PDF): every page rendered for review.
    if ls "$ARTIFACTS/ux"/*.pdf >/dev/null 2>&1; then
      render_pdf_pages "$ARTIFACTS/ux"
    fi
  fi

  docker rm -f "$UX_SERVER" >/dev/null 2>&1 || true
fi

# --- 8-bis. TIMESTAMP RESOLUTION PROBE ---------------------------------------
#
# WHY THIS EXISTS. Three tests assert that a taxonomy change made after a review
# is visible in the supersession delta, and the delta derives that from
# `where('created_at', '>', $reviewedAt)`. Those three were the ones left failing
# once the schema and the fixture were right, and one of them is intermittent -
# it failed on 2026-10-13, passed on 2026-10-14 at the same SHA.
#
# An intermittent failure is worth nothing as evidence until the mechanism is
# measured, so this probe asks the question directly and WITHOUT WRITING A ROW:
# does a value captured microseconds after another compare as strictly greater,
# once it has gone through the binding format Laravel uses for this connection?
#
# It touches no table. It is a measurement, not a test, and it runs after the
# suite so it can never affect it.
step "8-bis. Timestamp resolution probe (a measurement, not a test)"
compose run --rm -T testapp php -r '
require "vendor/autoload.php"; $a = require "bootstrap/app.php";
$a->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
$c   = DB::connection("pgsql");
$fmt = $c->getQueryGrammar()->getDateFormat();
$t1  = now(); usleep(2000); $t2 = now();
printf("binding date format for this connection : %s\n", $fmt);
printf("php captured t1                         : %s\n", $t1->format("Y-m-d H:i:s.u"));
printf("php captured t2 (2ms later)             : %s\n", $t2->format("Y-m-d H:i:s.u"));
printf("t2 is after t1 in PHP                   : %s\n", $t2 > $t1 ? "yes" : "no");
$b1 = $t1->format($fmt); $b2 = $t2->format($fmt);
printf("what the query builder actually binds   : %s  |  %s\n", $b1, $b2);
$visible = $c->select("select (?::timestamp > ?::timestamp) as v", [$b2, $b1])[0]->v;
printf("postgres says bound t2 > bound t1       : %s\n", $visible ? "yes" : "NO");
printf("\nCONSEQUENCE: a row written in the same WHOLE SECOND as the review is\n");
printf("invisible to where(\"created_at\", \">\", reviewed_at). The column holds\n");
printf("microseconds; the binding format drops them before they are stored.\n");
' >"$ARTIFACTS/timestamp_probe.txt" 2>&1 || true
sed 's/^/           /' "$ARTIFACTS/timestamp_probe.txt"

# --- 8-quinquies. DEPLOY PREFLIGHT, DRY-RUN AGAINST THE DISPOSABLE DATABASE ---
#
# A read-only preflight written to be run against a LIVE database should not be
# handed over having never executed anywhere. This runs it against the fixture.
#
# It also REFUSES a script that is not read-only: a preflight that can mutate is
# not a preflight, and catching that here is cheaper than catching it in
# production.
if [ -n "$PREFLIGHT" ] && [ "$MIGRATE_OK" = "yes" ]; then
  step "8-quinquies. Deploy preflight, executed against the disposable fixture"
  PF="$WORKSPACE/$PREFLIGHT"
  if [ ! -f "$PF" ]; then
    pbad "preflight script not found at $PREFLIGHT inside the workspace"
  elif grep -niE '^[[:space:]]*(insert|update|delete|alter|drop|create|truncate|grant|revoke)[[:space:]]' "$PF" >"$ARTIFACTS/preflight_mutation_scan.txt"; then
    pbad "preflight is NOT read-only - it contains statements that could mutate:"
    sed 's/^/           /' "$ARTIFACTS/preflight_mutation_scan.txt" | head -5
  else
    pok "preflight contains no mutating statement (read-only, as a preflight must be)"
    if compose exec -T testdb psql -v ON_ERROR_STOP=1 -U cpv_test -d cpv_test \
         < "$PF" >"$ARTIFACTS/preflight_output.txt" 2>&1; then
      pok "preflight ran against the disposable fixture without error"
      PF_STOP=$(grep -c 'PARAR' "$ARTIFACTS/preflight_output.txt" || true)
      say "verdicts mentioning PARAR in the fixture: ${PF_STOP:-0} (the fixture is a FRESH build, so some differ from a live database by construction)"
      grep -E 'veredicto|PARAR|OK' "$ARTIFACTS/preflight_output.txt" | head -12 | sed 's/^/           /'
    else
      pbad "the preflight script FAILED to execute - it cannot be handed over like this"
      tail -8 "$ARTIFACTS/preflight_output.txt" | sed 's/^/           /'
    fi
  fi
fi

# --- 8-quater. NO TAXONOMY MUTATION -------------------------------------------
#
# Instruction 6173818866 step 5 asks the integrated checks to show no taxonomy
# mutations. "No test wrote to the governed tables" is a claim worth measuring
# rather than asserting: the suite exercises the category-request flow and the
# whole point of TASK-0010A is that a request NEVER creates or publishes a
# category.
#
# The counts are taken from the fixture AFTER everything has run and compared
# with what the fixture phase loaded. Tests use DatabaseTransactions and roll
# back, so the expectation is equality - a difference means something escaped a
# transaction, which is exactly what this is here to catch.
if [ "$MIGRATE_OK" = "yes" ] && [ "$FIXTURE_LOADED" = "yes" ]; then
  step "8-quater. Governed taxonomy tables unchanged by the run"
  compose run --rm -T testapp php -r '
require "vendor/autoload.php"; $a=require "bootstrap/app.php";
$a->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
// Rows written ON PURPOSE by --ux-seed carry source_version "ux-synthetic-*". They are
// committed seed data, not a test escaping its transaction, so they are left out of this
// comparison and counted on their own (stderr -> taxonomy_seed_rows.txt).
$seed = fn () => DB::table("taxonomy_categories")->where("source_version", "like", "ux-synthetic-%")->select("id");
foreach (["taxonomy_categories","taxonomy_category_translations","taxonomy_term_cpv_relations",
          "taxonomy_canonical_concepts","taxonomy_term_concepts","taxonomy_concept_relations",
          "empresa_taxonomy_category"] as $t) {
    try {
        $q = DB::table($t);
        if ($t === "taxonomy_categories") { $q->where(fn ($w) => $w->whereNull("source_version")->orWhere("source_version", "not like", "ux-synthetic-%")); }
        if ($t === "taxonomy_category_translations") { $q->whereNotIn("category_id", $seed()); }
        printf("%-34s %d\n", $t, $q->count());
    }
    catch (Throwable $e) { printf("%-34s (unreadable)\n", $t); }
}
try {
    fprintf(STDERR, "ux_seed_taxonomy_categories %d\nux_seed_translations %d\n",
        $seed()->count(), DB::table("taxonomy_category_translations")->whereIn("category_id", $seed())->count());
} catch (Throwable $e) {}
' 2>"$ARTIFACTS/taxonomy_seed_rows.txt" | tr -d '\r' > "$ARTIFACTS/taxonomy_after_suite.txt"
  sed 's/^/           /' "$ARTIFACTS/taxonomy_after_suite.txt"
  grep '^ux_seed_' "$ARTIFACTS/taxonomy_seed_rows.txt" 2>/dev/null | sed 's/^/           (excluded, written on purpose by --ux-seed) /'

  # taxonomy_categories is the one the fixture populated; the rest started empty
  # and must still be empty. Compared against fixture_rows.txt, not retyped.
  CAT_BEFORE=$(awk '$1=="taxonomy_categories"{print $2}' "$ARTIFACTS/fixture_rows.txt")
  CAT_AFTER=$(awk '$1=="taxonomy_categories"{print $2}' "$ARTIFACTS/taxonomy_after_suite.txt")
  if [ "$CAT_BEFORE" = "$CAT_AFTER" ]; then
    pok "taxonomy_categories unchanged by the suite ($CAT_AFTER rows, as the fixture loaded)"
  else
    pbad "taxonomy_categories CHANGED: $CAT_BEFORE -> $CAT_AFTER (something escaped a test transaction)"
  fi
  LEAKED=$(awk '$1!="taxonomy_categories" && $2!=0 && $2!="(unreadable)"{print $1"="$2}' "$ARTIFACTS/taxonomy_after_suite.txt" | tr '\n' ' ')
  if [ -z "$LEAKED" ]; then
    pok "every other governed taxonomy table is still empty: nothing was published"
  else
    pbad "governed taxonomy rows left behind: $LEAKED"
  fi
fi

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
  echo "schema_fix_applied=${APPLIED_PATCHES:-(none: already in the code under test)}"
  echo "schema_fix_already_present=${PRESENT_PATCHES:-(none)}"
  echo "deferred_fks_restored_by=$FK_RESTORE"
  echo "pgsql_bootstrap_from=$SIBLING_BRANCH@$SIBLING_SHA"
  echo "pgsql_bootstrap_migrations=${BOOTSTRAP_COUNT:-0}"
  echo "migration_ordering_violations=$DEPS_VIOLATIONS"
  echo "migrate_ok=$MIGRATE_OK"
  echo "prep_passed=$PREP_PASSED"
  echo "prep_failed=$PREP_FAILED"
  echo "fixture_tables=$TABLES"
  echo "-- fixture load --"
  echo "fixture_loaded=$FIXTURE_LOADED"
  echo "fixture_source=docker/test/fixtures/synthetic_fixture.sql (synthetic, no copied data)"
  echo "preconditions_passed=${PRE_PASS:-0}"
  echo "preconditions_failed=${PRE_FAIL:-unknown}"
  if [ "$UX" = "yes" ]; then
    echo "-- ux / browser --"
    echo "ux_checked=yes"
    echo "ux_passed=$UX_PASSED"
    echo "ux_failed=$UX_FAILED"
    echo "ux_viewports=1440x900,820x1180,390x844"
    echo "ux_schemes=light,dark"
    echo "ux_served=php artisan serve inside the disposable container, private network, no host port"
  fi
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
[ "$FIX_FAILED" -eq 0 ]  && F="PASS ($FIX_PASSED checks)"  || F="FAIL ($FIX_FAILED of $((FIX_PASSED+FIX_FAILED)))"
# NOT RUN is its own verdict. With --skip-suite this printed
# "RESULT test suite : RED (exit skipped)", which is worse than useless: it
# reads as a failing suite to anyone skimming, when in fact no suite ran at all.
# A run that did not execute the suite must not look like one that did, in
# either direction.
if [ "$PHPUNIT_EXIT" = "skipped" ]; then T="NOT RUN"
elif [ "$PHPUNIT_EXIT" = "0" ];      then T="GREEN"
else                                      T="RED (exit $PHPUNIT_EXIT)"
fi
if [ -n "$APPLIED_PATCHES" ]; then
  PREP_NOTE="patch applied; $DEPS_VIOLATIONS ordering violation(s) deferred"
else
  PREP_NOTE="no patch needed; the corrections are committed at this SHA"
fi
echo "RESULT  isolation      : $I"
echo "RESULT  schema prep    : $P   [$PREP_NOTE]"
echo "RESULT  fixture load   : $F   [$FIXTURE_LOADED; ${PRE_PASS:-0} precondition(s) satisfied]"
echo "RESULT  test suite     : $T   [$SUITES_RUN]"
if [ "$UX" = "yes" ]; then
  [ "$UX_FAILED" -eq 0 ] && U="PASS ($UX_PASSED checks)" || U="FAIL ($UX_FAILED of $((UX_PASSED+UX_FAILED)))"
  echo "RESULT  ux / browser   : $U   [real authenticated page, 3 widths x 2 schemes]"
fi
echo "RESULT  findings in the repository under test: $FINDINGS"
# Exit codes say WHICH group failed, because "the suite is red" and "the suite
# never had a schema" need different work and must not share an exit code:
#   0  all four groups good
#   3  isolation held, the SCHEMA could not be prepared - the suite result says
#      nothing about the code
#   4  isolation held and the schema was built, but the FIXTURE did not load or
#      a declared precondition is unmet - the suite ran against data it says it
#      needs and does not have, so its data-dependent failures are expected
#   1  anything else, the ordinary red suite included
#   5  every other group held but a UX/browser check failed
#
# A skipped suite does not count as green: with --skip-suite the only way to
# exit 0 is for every group that DID run to pass.
SUITE_OK=no
[ "$PHPUNIT_EXIT" = "0" ] && SUITE_OK=yes
[ "$PHPUNIT_EXIT" = "skipped" ] && SUITE_OK=skipped
if [ "$FAILED" -eq 0 ] && [ "$PREP_FAILED" -eq 0 ] && [ "$FIX_FAILED" -eq 0 ] \
   && [ "$UX_FAILED" -eq 0 ] && [ "$SUITE_OK" != "no" ]; then exit 0; fi
if [ "$FAILED" -eq 0 ] && [ "$PREP_FAILED" -gt 0 ]; then exit 3; fi
if [ "$FAILED" -eq 0 ] && [ "$FIX_FAILED" -gt 0 ]; then exit 4; fi
if [ "$FAILED" -eq 0 ] && [ "$UX_FAILED" -gt 0 ] && [ "$SUITE_OK" != "no" ]; then exit 5; fi
exit 1
