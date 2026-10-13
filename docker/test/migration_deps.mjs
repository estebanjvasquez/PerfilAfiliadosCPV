#!/usr/bin/env node
/**
 * Migration dependency analysis. Deterministic, free, no database involved.
 *
 * ONE QUESTION: replaying database/migrations in filename order into an EMPTY
 * database, does every foreign key reference a table that exists by then?
 * Every violation is a point where the replay aborts.
 *
 *   node docker/test/migration_deps.mjs [database/migrations]
 *
 * EXIT  0 no violations   1 violations found   2 could not run
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

const DIR = process.argv[2] || 'database/migrations';

let files;
try {
  files = readdirSync(DIR).filter((f) => f.endsWith('.php')).sort();
} catch (err) {
  console.error('cannot read ' + DIR + ': ' + err.message);
  process.exit(2);
}

const stripComments = (src) =>
  src.replace(/\r\n?/g, '\n')
     .replace(/\/\*[\s\S]*?\*\//g, (m) => m.replace(/[^\n]/g, ''))
     .split('\n')
     .map((l) => l.replace(/(^|\s)(\/\/|#).*/, '$1'))
     .join('\n');

const source = new Map();
for (const file of files) source.set(file, stripComments(readFileSync(DIR + '/' + file, 'utf8')));

const createdBy = new Map();
for (const [file, src] of source) {
  for (const m of src.matchAll(/Schema::create\(\s*['"]([^'"]+)['"]/g)) {
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
  });
}

const violations = [], unresolved = [], satisfied = [];
for (const r of refs) {
  const creator = r.table ? createdBy.get(r.table) : null;
  if (!creator) unresolved.push(r);
  else if (creator > r.file) violations.push({ ...r, creator });
  else satisfied.push(r);
}

console.log('migrations scanned          : ' + files.length);
console.log('tables created by migrations: ' + createdBy.size);
console.log('foreign key references      : ' + refs.length + ' (' +
            refs.filter((r) => r.resolved === 'inferred').length + ' with an inferred table name)');
console.log('  satisfied at replay time  : ' + satisfied.length);
console.log('  VIOLATIONS                : ' + violations.length);
console.log('  unresolved                : ' + unresolved.length);

if (violations.length) {
  console.log('\nVIOLATIONS - replaying into an empty database aborts at each of these:');
  for (const v of violations) {
    console.log('  ' + v.file + ':' + v.line);
    console.log('      needs "' + v.table + '" via ' + v.how + ' [' + v.resolved + ']');
    console.log('      created later by ' + v.creator);
  }
}
if (unresolved.length) {
  console.log('\nUNRESOLVED - reported, not assumed harmless:');
  for (const u of unresolved) console.log('  ' + u.file + ':' + u.line + '  ' + u.how);
}

console.log('\n' + (violations.length
  ? 'RESULT: ' + violations.length + ' ordering violation(s) in ' +
    new Set(violations.map((v) => v.file)).size + ' file(s).'
  : 'RESULT: no ordering violations found.'));
process.exit(violations.length ? 1 : 0);
