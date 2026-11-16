#!/usr/bin/env bash
# TASK-0028: snapshot de solo lectura (pg_dump -Fc -n public) y clon aislado sin puertos publicados.
set -euo pipefail
TS=$(date -u +%Y%m%dT%H%M%SZ)
D=/root/backups/cira-t28-$TS
mkdir -p "$D"; chmod 700 "$D"; echo "$D" > /root/backups/cira-t28-latest.txt
cd /opt/perfilafiliados
ENVF="$D/db.env"; umask 077
grep -E '^DB_PGSQL_(HOST|PORT|DATABASE|USERNAME|PASSWORD|SSLMODE)=' .env | tr -d '\r"' | sed -E 's/^DB_PGSQL_HOST=/PGHOST=/; s/^DB_PGSQL_PORT=/PGPORT=/; s/^DB_PGSQL_DATABASE=/PGDATABASE=/; s/^DB_PGSQL_USERNAME=/PGUSER=/; s/^DB_PGSQL_PASSWORD=/PGPASSWORD=/; s/^DB_PGSQL_SSLMODE=/PGSSLMODE=/' > "$ENVF"
git rev-parse HEAD > "$D/git_head.txt"
# 1) Volcado: solo lectura sobre la BD compartida.
docker run --rm --env-file "$ENVF" -v "$D":/out postgres:17 sh -c 'pg_dump -Fc -n public -f /out/public.dump && psql -Atc "select now()" > /out/dump_at.txt && psql -At -F"|" -c "select '"'"'empresas'"'"', count(*) from empresas union all select '"'"'empresa_experiencias'"'"', count(*) from empresa_experiencias union all select '"'"'empresa_taxonomy_category'"'"', count(*) from empresa_taxonomy_category union all select '"'"'empresa_search_documents'"'"', count(*) from empresa_search_documents union all select '"'"'cira_search_log'"'"', count(*) from cira_search_log union all select '"'"'taxonomy_term_cpv_relations'"'"', count(*) from taxonomy_term_cpv_relations order by 1" > /out/live_counts.txt' 2> "$D/pg_dump.err"
sha256sum "$D/public.dump" > "$D/public.dump.sha256"
rm -f "$ENVF"
# 2) Clon aislado: red propia, sin puertos publicados, ICU en-US como en vivo.
docker network inspect cpvt28 >/dev/null 2>&1 || docker network create cpvt28 >/dev/null
docker rm -f cpvt28-db >/dev/null 2>&1 || true
docker run -d --name cpvt28-db --network cpvt28 -e POSTGRES_PASSWORD=${CLONE_PG_PASSWORD:?defina CLONE_PG_PASSWORD} -e POSTGRES_DB=clone \
  -e POSTGRES_INITDB_ARGS="--locale-provider=icu --icu-locale=en-US --encoding=UTF8 --lc-collate=en_US.UTF-8 --lc-ctype=en_US.UTF-8" \
  pgvector/pgvector:pg17 >/dev/null
for i in $(seq 1 60); do [ "$(docker logs cpvt28-db 2>&1 | grep -c 'ready to accept connections')" -ge 2 ] && break; sleep 2; done
docker exec cpvt28-db psql -U postgres -d clone -qc "create schema if not exists extensions; create extension if not exists vector schema public; create extension if not exists pg_trgm schema public; create extension if not exists unaccent schema public; create extension if not exists pgcrypto schema extensions; create extension if not exists \"uuid-ossp\" schema extensions; do \$\$ begin if not exists (select from pg_roles where rolname='anon') then create role anon; end if; if not exists (select from pg_roles where rolname='authenticated') then create role authenticated; end if; if not exists (select from pg_roles where rolname='service_role') then create role service_role; end if; end \$\$;"
docker cp "$D/public.dump" cpvt28-db:/tmp/public.dump
docker exec cpvt28-db sh -c 'pg_restore -U postgres -d clone --no-owner --no-acl /tmp/public.dump' > "$D/restore.log" 2>&1 || true
docker exec cpvt28-db rm -f /tmp/public.dump
docker exec cpvt28-db psql -U postgres -d clone -At -F"|" -c "select 'empresas', count(*) from empresas union all select 'empresa_experiencias', count(*) from empresa_experiencias union all select 'empresa_taxonomy_category', count(*) from empresa_taxonomy_category union all select 'empresa_search_documents', count(*) from empresa_search_documents union all select 'cira_search_log', count(*) from cira_search_log union all select 'taxonomy_term_cpv_relations', count(*) from taxonomy_term_cpv_relations order by 1" > "$D/clone_counts.txt"
echo "DIR=$D"; echo "dump_at=$(cat "$D/dump_at.txt")"; cat "$D/public.dump.sha256" | cut -c1-16; echo "pg_dump.err bytes: $(wc -c < "$D/pg_dump.err")"; echo "restore errors: $(grep -c 'error:' "$D/restore.log" || true)"; diff "$D/live_counts.txt" "$D/clone_counts.txt" && echo "COUNTS_MATCH"; cat "$D/clone_counts.txt"
