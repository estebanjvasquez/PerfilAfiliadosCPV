#!/usr/bin/env node
/**
 * Deterministic gate for audit/orchestrator_handoff.json.
 *
 * WHY THIS EXISTS
 *   Four of the automated review rounds spent a model call to report things a
 *   script can decide: invalid JSON, a review_head that is not a real commit,
 *   a malformed timestamp, a task field holding prose instead of an
 *   identifier, and superseded narratives dropped instead of preserved. Those
 *   are not judgement calls. Paying for inference to find them is waste, and
 *   it delays the work that actually needs judgement.
 *
 *   So: this runs first and for free. An AI review is for substantive
 *   deliveries - code, test evidence, governance decisions - not bookkeeping.
 *
 * WHY NODE
 *   It has to run where the repository is checked out, which is the Windows
 *   dev machine: node is present there and python is not. The reverse is true
 *   on the VPS. No dependencies, so it works on either if node exists.
 *
 * USAGE
 *   node docs/orquestador/validate_handoff.mjs              # validate working tree
 *   node docs/orquestador/validate_handoff.mjs --head <sha> # also require this review_head
 *   node docs/orquestador/validate_handoff.mjs --strict     # treat warnings as failures
 *
 * EXIT CODES
 *   0 all checks passed   1 at least one check failed   2 could not run
 */

import { execFileSync } from 'node:child_process';
import { readFileSync } from 'node:fs';

const HANDOFF = 'audit/orchestrator_handoff.json';
const SHA_RE = /^[0-9a-f]{40}$/;
const TS_RE = /^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$/;

// Patterns that must never appear in a file that lives in a PUBLIC repository.
const SECRET_PATTERNS = [
  [/ghp_[A-Za-z0-9]{20}/, 'classic GitHub PAT'],
  [/github_pat_[A-Za-z0-9_]{20}/, 'fine-grained GitHub PAT'],
  [/sk-ant-[A-Za-z0-9-]{20}/, 'Anthropic API key'],
  [/-----BEGIN [A-Z ]*PRIVATE KEY/, 'private key'],
  [/postgres(?:ql)?:\/\/[^\s"]*:[^\s"]*@/, 'connection string with password'],
];

const args = process.argv.slice(2);
const strict = args.includes('--strict');
const headIdx = args.indexOf('--head');
const expectedHead = headIdx >= 0 ? args[headIdx + 1] : null;

let failures = 0;
let warnings = 0;

const pass = (m) => console.log('  [PASS] ' + m);
const fail = (m) => { failures++; console.log('  [FAIL] ' + m); };
const warn = (m) => {
  warnings++;
  if (strict) { failures++; console.log('  [FAIL] (strict) ' + m); }
  else console.log('  [WARN] ' + m);
};
const section = (m) => console.log('\n== ' + m);

function git(...a) {
  return execFileSync('git', a, { encoding: 'utf8', maxBuffer: 64 * 1024 * 1024 }).trim();
}
function gitQuiet(...a) {
  try { return git(...a); } catch { return null; }
}

// --- load -------------------------------------------------------------------

section('1. File parses');
let raw;
try {
  raw = readFileSync(HANDOFF, 'utf8');
} catch (err) {
  console.error('cannot read ' + HANDOFF + ': ' + err.message);
  process.exit(2);
}

let handoff;
try {
  handoff = JSON.parse(raw);
  pass('valid JSON, ' + Object.keys(handoff).length + ' top-level keys, ' +
       raw.split('\n').length + ' lines');
} catch (err) {
  fail('not valid JSON: ' + err.message);
  console.log('\nnothing else can be checked until the file parses.');
  process.exit(1);
}

// --- secrets ----------------------------------------------------------------

section('2. No secrets (this repository is public)');
let leaked = 0;
for (const [re, label] of SECRET_PATTERNS) {
  if (re.test(raw)) { fail('possible ' + label + ' present'); leaked++; }
}
if (!leaked) pass('none of the ' + SECRET_PATTERNS.length + ' secret patterns match');

// --- review pointer ---------------------------------------------------------

section('3. Review pointer');
const head = handoff.review_head;
if (typeof head !== 'string' || !SHA_RE.test(head)) {
  fail('review_head is not a 40-character hex sha: ' + JSON.stringify(head));
} else {
  pass('review_head is a well-formed sha');

  const type = gitQuiet('cat-file', '-t', head);
  if (type !== 'commit') {
    fail('review_head ' + head.slice(0, 12) + ' is not a commit in this repository');
  } else {
    pass('review_head resolves to a real commit');

    // It must be reachable from the current branch, otherwise the reviewer is
    // pointed at something that is not part of this line of work.
    const branch = gitQuiet('rev-parse', '--abbrev-ref', 'HEAD');
    if (gitQuiet('merge-base', '--is-ancestor', head, 'HEAD') === null &&
        gitQuiet('rev-parse', 'HEAD') !== head) {
      fail('review_head is not an ancestor of ' + branch + '; the reviewer would ' +
           'audit a commit outside this branch');
    } else {
      pass('review_head is reachable from ' + branch);
    }

    // A pointer that still names an already-reviewed SHA stalls the loop
    // silently, which is the failure mode this whole design guards against.
    const published = collectPublishedHeads(handoff);
    if (published.has(head)) {
      warn('review_head ' + head.slice(0, 12) + ' already has a published verdict; ' +
           'no new review will be requested until it is advanced');
    } else {
      pass('review_head has no verdict recorded in this file');
    }
  }

  if (expectedHead) {
    if (head === expectedHead) pass('review_head matches the expected ' + expectedHead.slice(0, 12));
    else fail('review_head is ' + head.slice(0, 12) + ', expected ' + expectedHead.slice(0, 12));
  }
}

const state = handoff.review_state;
const VALID_STATES = ['IN_PROGRESS', 'READY_FOR_REVIEW', 'WAITING_ORCHESTRATOR_REVIEW',
                      'CORRECTIONS_REQUIRED', 'PASS_CLOSED', 'OWNER_GATE_REQUIRED',
                      'BLOCKED_EXTERNAL'];
if (VALID_STATES.includes(state)) pass('review_state is ' + state);
else fail('review_state ' + JSON.stringify(state) + ' is not one of ' + VALID_STATES.join(', '));

// --- task identifier --------------------------------------------------------

section('4. review_task is an identifier, not prose');
const task = handoff.review_task;
if (typeof task !== 'string' || !task.trim()) {
  fail('review_task is missing or empty');
} else if (task.length > 48) {
  fail('review_task is ' + task.length + ' characters. The executor correlates a ' +
       'published verdict by looking for this value in comment bodies, so prose here ' +
       'makes that test unsatisfiable - it caused a round to be reviewed twice.');
} else if (/[.;]|\s{2,}/.test(task) || task.split(/\s+/).length > 4) {
  fail('review_task looks like prose: ' + JSON.stringify(task));
} else {
  pass('review_task is ' + JSON.stringify(task) + ' (' + task.length + ' chars)');
}

// --- timestamp --------------------------------------------------------------

section('5. review_requested_at');
const ts = handoff.review_requested_at;
if (typeof ts !== 'string' || !TS_RE.test(ts)) {
  fail('review_requested_at is not UTC ISO-8601 (YYYY-MM-DDTHH:MM:SSZ): ' + JSON.stringify(ts));
} else {
  const when = Date.parse(ts);
  if (Number.isNaN(when)) {
    fail('review_requested_at does not parse as a date: ' + ts);
  } else {
    const ageHours = (Date.now() - when) / 3.6e6;
    if (ageHours < -0.5) fail('review_requested_at is in the future by ' +
                              Math.abs(ageHours).toFixed(1) + 'h');
    else if (ageHours > 24 * 14) warn('review_requested_at is ' + Math.round(ageHours / 24) +
                                      ' days old; is this pointer still current?');
    else pass('review_requested_at is ' + ts + ' (' + ageHours.toFixed(1) + 'h ago)');
  }
}

// --- consumed verdict -------------------------------------------------------

section('6. Consumed verdict');
const lastId = handoff.last_orchestrator_comment_id;
if (!Number.isInteger(lastId) || lastId <= 0) {
  fail('last_orchestrator_comment_id is not a positive integer: ' + JSON.stringify(lastId));
} else {
  pass('last_orchestrator_comment_id is ' + lastId);
}
const consumed = handoff.last_orchestrator_verdict_consumed;
if (typeof consumed !== 'string' || consumed.length < 40) {
  fail('last_orchestrator_verdict_consumed is missing or too short to be a record');
} else if (Number.isInteger(lastId) && !consumed.includes(String(lastId))) {
  fail('last_orchestrator_verdict_consumed does not mention ' + lastId +
       ', so the narrative and the id disagree about which round this is');
} else {
  pass('narrative names the consumed verdict');
}

// --- history preservation ---------------------------------------------------
// This is the check that replaces finding 1 of review 6143456158: every
// verdict this file has ever consumed must still be recorded somewhere, so
// fixing one gap cannot silently open another.

section('7. History preservation');
const superseded = Object.keys(handoff)
  .filter((k) => k.startsWith('superseded_verdict_consumed_'))
  .map((k) => k.replace('superseded_verdict_consumed_', ''));
pass('superseded narratives present for: ' + (superseded.join(', ') || 'none'));

const historical = gitQuiet('log', '--format=%H', '--', HANDOFF);
if (historical === null) {
  warn('cannot read git history for ' + HANDOFF + '; history check skipped');
} else {
  const seen = new Set();
  for (const sha of historical.split('\n').filter(Boolean)) {
    const blob = gitQuiet('show', sha + ':' + HANDOFF);
    if (!blob) continue;
    let old;
    try { old = JSON.parse(blob); } catch { continue; }
    const id = old.last_orchestrator_comment_id;
    if (Number.isInteger(id)) seen.add(String(id));
  }
  const missing = [...seen].filter(
    (id) => id !== String(lastId) && !superseded.includes(id));
  if (missing.length === 0) {
    pass('every verdict ever consumed (' + seen.size + ') is either current or preserved');
  } else {
    fail('narratives dropped without preservation for verdict(s): ' + missing.join(', ') +
         '. Each consumed verdict must stay recorded under ' +
         'superseded_verdict_consumed_<id>, so closing one gap cannot open another.');
  }
}

// --- hold consistency -------------------------------------------------------

section('8. Hold consistency');
const parallel = handoff.parallel_tasks || {};
const heldStatuses = Object.entries(parallel)
  .map(([name, v]) => [name, (v && v.status) || '']);
const anyActive = heldStatuses.some(([, s]) => /ACTIVE|PENDING_DEV/.test(s));
if (anyActive) {
  fail('a parallel task still reads as active: ' +
       heldStatuses.map(([n, s]) => n + '=' + s).join(', '));
} else if (heldStatuses.length) {
  pass('parallel tasks: ' + heldStatuses.map(([n, s]) => n + '=' + s).join(', '));
}
if (handoff.current_hold) pass('current_hold is recorded');
else warn('no current_hold field; if a task is held, say so where a resuming session reads it');

if (/ACTIVE_PARALLEL_DEVELOPMENT/.test(String(handoff.current_status || ''))) {
  fail('current_status claims active parallel development while TASK-0010 is held');
} else {
  pass('current_status is ' + handoff.current_status);
}

// --- verdict ----------------------------------------------------------------

console.log('\n' + '-'.repeat(66));
console.log('failures: ' + failures + '   warnings: ' + warnings +
            (strict ? '   (strict: warnings count as failures)' : ''));
if (failures === 0) {
  console.log('RESULT: PASS - deterministic checks satisfied, ready for AI review');
  process.exit(0);
}
console.log('RESULT: FAIL - fix the above before spending a review');
process.exit(1);

// ----------------------------------------------------------------------------

function collectPublishedHeads(h) {
  // Verdict ids are recorded, not the SHAs they covered, so this is best
  // effort: it only knows the head that the current pointer already consumed.
  const out = new Set();
  const note = String(h.review_head_note || '');
  for (const m of note.matchAll(/\b[0-9a-f]{40}\b/g)) out.add(m[0]);
  return out;
}
