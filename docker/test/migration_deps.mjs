#!/usr/bin/env node
/**
 * Migration dependency analysis. Deterministic, free, no database involved.
 *
 * ONE QUESTION: replaying database/migrations in filename order into an EMPTY
 * database, does every foreign key reference a table that exists by then?
 * Every violation is a point where the replay aborts.
 *
 *   node docker/test/migration_deps.mjs [dir ...]      # several paths = one set
 *
 * EXIT  0 clean   1 violations or missing tables   2 could not run
 *
 * WHAT IT GETS RIGHT ON PURPOSE, each after getting it wrong first
 *   * Commented-out code is not a reference. A first version reported THREE
 *     violations in create_infrafacilities_table, which has one real reference
 *     and two commented-out ones.
 *   * CRLF matters. In JavaScript regex `\r` is a line terminator that `.` does
 *     not match, so `.*$` never reached the end of a line on a Windows checkout
 *     and not one comment was stripped. That is why the first version's comment
 *     handling silently did nothing.
 *   * `$table->id()->constrained()` is not a foreign key. ColumnDefinition is a
 *     Fluent, so an unknown method call just records an inert attribute. Only
 *     foreignId()/foreignIdFor() columns count as constrained.
 *   * Laravel's pluraliser is inflection-aware: plural('sectors') is 'sectors',
 *     not 'sectorss'. Candidate forms are tried against the tables that
 *     migrations actually create instead of reimplementing the inflector.
 *   * A table name it cannot resolve is reported as UNRESOLVED, never as fine.
 *   * Raw SQL counts. A first version scanned only the Blueprint helpers, so it
 *     missed `category_id BIGINT NOT NULL REFERENCES taxonomy_categories(id)`
 *     inside a DB::statement - and that was precisely the next thing the replay
 *     died on, after this tool had reported four violations and no unresolved
 *     references. `REFERENCES <table>` is now scanned too.
 *   * A table that NO migration in the scanned set creates is reported as
 *     MISSING, separately from an ordering violation, because the remedy is
 *     different: ordering is a date problem, missing is a set problem.
 *
 * STATED LIMITS
 *   Lexical analysis, not execution. `//` inside a string literal truncates the
 *   rest of that line, so a reference sharing a line with such a string can be
 *   missed: it can under-report, never invent. Tables created outside
 *   migrations (views, extensions, anything another mechanism built) are
 *   invisible to it, which is the point - those are exactly what an empty
 *   database does not have.
 */
import { readdirSync, readFileSync } from 'node:fs';

const DIRS = process.argv.slice(2).length ? process.argv.slice(2) : ['database/migrations'];

// Laravel sorts migrations by FILENAME across every registered path, so several
// directories are one ordered set, not several.
let files;
try {
  files = DIRS.flatMap((dir) =>
    readdirSync(dir).filter((f) => f.endsWith('.php')).map((f) => ({ name: f, path: dir + '/' + f })))
    .sort((a, b) => (a.name < b.name ? -1 : a.name > b.name ? 1 : 0));
} catch (err) {
  console.error('cannot read ' + DIRS.join(', ') + ': ' + err.message);
  process.exit(2);
}

const stripComments = (src) =>
  src.replace(/\r\n?/g, '\n')
     .replace(/\/\*[\s\S]*?\*\//g, (m) => m.replace(/[^\n]/g, ''))
     .split('\n')
     .map((l) => l.replace(/(^|\s)(\/\/|#).*/, '$1'))
     .join('\n');

// Keyed by filename, because that is what determines replay order.
const source = new Map();
for (const f of files) source.set(f.name, stripComments(readFileSync(f.path, 'utf8')));

const createdBy = new Map();
for (const [file, src] of source) {
  // Schema::create AND Schema::connection('pgsql')->create: the taxonomy and
  // experiencia migrations use the connection-qualified form, and matching only
  // the bare one reported a table as MISSING while the migration that creates
  // it sat two files earlier in the same directory.
  for (const m of src.matchAll(
    /Schema::(?:connection\(\s*['"][^'"]*['"]\s*\)\s*->)?create\(\s*['"]([^'"]+)['"]/g)) {
    if (!createdBy.has(m[1])) createdBy.set(m[1], file);
  }
  // Raw SQL creates tables too, optionally schema-qualified as public.x.
  for (const m of src.matchAll(
    /CREATE\s+TABLE\s+(?:IF\s+NOT\s+EXISTS\s+)?(?:"?[a-z_][a-z0-9_]*"?\.)?"?([a-z_][a-z0-9_]*)"?/gi)) {
    if (!createdBy.has(m[1])) createdBy.set(m[1], file);
  }
}

const resolveTable = (base) =>
  [base, base + 's', base.replace(/y$/, 'ies'), base.replace(/s$/, '')]
    .find((c) => createdBy.has(c)) ?? null;

const refs = [];
for (const [file, src] of source) {
  src.split('\n').forEach((line, i) => {
    const at = { file, line: i + 1 };
    for (const m of line.matchAll(/->on\(\s*['"]([^'"]+)['"]\s*\)/g)) {
      refs.push({ ...at, table: m[1], how: "references()->on('" + m[1] + "')", resolved: 'explicit' });
    }
    for (const m of line.matchAll(/->constrained\(\s*['"]([^'"]+)['"]/g)) {
      refs.push({ ...at, table: m[1], how: 'constrained("' + m[1] + '")', resolved: 'explicit' });
    }
    if (/->constrained\(\s*\)/.test(line)) {
      const col = line.match(/->foreignId(?:For)?\(\s*['"]([^'"]+)['"]\s*\)/);
      if (!col) return;
      const table = resolveTable(col[1].replace(/_id$/, ''));
      refs.push({
        ...at,
        table,
        how: 'constrained() inferred from column ' + col[1],
        resolved: table ? 'inferred' : 'unresolved',
      });
    }
    // Raw SQL: `... REFERENCES taxonomy_categories(id) ON DELETE CASCADE`.
    for (const m of line.matchAll(/\bREFERENCES\s+"?([a-z_][a-z0-9_]*)"?/gi)) {
      refs.push({ ...at, table: m[1], how: 'raw SQL REFERENCES ' + m[1], resolved: 'explicit' });
    }
  });
}

const violations = [], missing = [], unresolved = [], satisfied = [];
for (const r of refs) {
  if (!r.table) { unresolved.push(r); continue; }
  const creator = createdBy.get(r.table);
  // Two different problems with two different remedies: a table created LATER
  // is a dating problem; a table created NOWHERE in this set is a set problem,
  // which no reordering can fix.
  if (!creator) missing.push(r);
  else if (creator > r.file) violations.push({ ...r, creator });
  else satisfied.push(r);
}

console.log('paths scanned               : ' + DIRS.join(', '));
console.log('migrations scanned          : ' + files.length);
console.log('tables created by migrations: ' + createdBy.size);
console.log('foreign key references      : ' + refs.length + ' (' +
            refs.filter((r) => r.resolved === 'inferred').length + ' with an inferred table name)');
console.log('  satisfied at replay time  : ' + satisfied.length);
console.log('  ORDERING VIOLATIONS       : ' + violations.length);
console.log('  MISSING tables            : ' + missing.length +
            ' (' + new Set(missing.map((m) => m.table)).size + ' distinct)');
console.log('  unresolved                : ' + unresolved.length);

if (violations.length) {
  console.log('\nVIOLATIONS - replaying into an empty database aborts at each of these:');
  for (const v of violations) {
    console.log('  ' + v.file + ':' + v.line);
    console.log('      needs "' + v.table + '" via ' + v.how + ' [' + v.resolved + ']');
    console.log('      created later by ' + v.creator);
  }
}
if (missing.length) {
  console.log('\nMISSING - referenced, but NO scanned migration creates them.');
  console.log('No reordering can fix this: the creating migration is not in this set.');
  const byTable = new Map();
  for (const m of missing) {
    if (!byTable.has(m.table)) byTable.set(m.table, []);
    byTable.get(m.table).push(m.file + ':' + m.line);
  }
  for (const [table, where] of [...byTable].sort()) {
    console.log('  "' + table + '" referenced by ' + where.length + ' place(s):');
    for (const w of where.slice(0, 4)) console.log('      ' + w);
    if (where.length > 4) console.log('      ... and ' + (where.length - 4) + ' more');
  }
}
if (unresolved.length) {
  console.log('\nUNRESOLVED - reported, not assumed harmless:');
  for (const u of unresolved) console.log('  ' + u.file + ':' + u.line + '  ' + u.how);
}

const parts = [];
if (violations.length) parts.push(violations.length + ' ordering violation(s) in ' +
  new Set(violations.map((v) => v.file)).size + ' file(s)');
if (missing.length) parts.push(new Set(missing.map((m) => m.table)).size + ' missing table(s)');
console.log('\nRESULT: ' + (parts.length ? parts.join(', ') + '.' : 'clean - an empty database can be built from this set.'));
process.exit(violations.length || missing.length ? 1 : 0);
