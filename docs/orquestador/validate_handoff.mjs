#!/usr/bin/env node
/**
 * Deterministic gate for audit/orchestrator_handoff.json.
 *
 * WHY THIS EXISTS
 *   Several automated review rounds spent a model call to report things a
 *   script can decide: invalid JSON, a review_head that is not a real commit,
 *   a malformed timestamp, a task field holding prose instead of an
 *   identifier, dropped history. Those are not judgement calls. This runs
 *   first and for free; an AI review is for substantive delivery.
 *
 * WHAT IT DELIBERATELY DOES NOT DO
 *   It never reports PASS for something it did not actually test. A check that
 *   cannot run says NOT CHECKED. An earlier version of this file inferred
 *   "review_head has no verdict yet" by scraping 40-hex strings out of a prose
 *   note; the note contained none, so it returned an empty set and printed
 *   PASS while verifying nothing - on a pointer that did in fact already have
 *   a published verdict. A false PASS is worse than a missing check, because
 *   it is trusted.
 *
 * WHY NODE
 *   It must run where the repository is checked out, which is the Windows dev
 *   machine: node is present there, python is not. No dependencies.
 *
 * USAGE
 *   node docs/orquestador/validate_handoff.mjs
 *   node docs/orquestador/validate_handoff.mjs --github      # exact verdict check over the API
 *   node docs/orquestador/validate_handoff.mjs --parked      # pointer intentionally not advanced
 *   node docs/orquestador/validate_handoff.mjs --head <sha>  # also require this review_head
 *   node docs/orquestador/validate_handoff.mjs --branch <b> # the branch the EXECUTOR watches
 *   node docs/orquestador/validate_handoff.mjs --docs        # limited resume-path document checks
 *   node docs/orquestador/validate_handoff.mjs --strict      # warnings become failures
 *
 * NO CREDENTIALS
 *   The --github check reads the issue ANONYMOUSLY, which is possible because
 *   the repository is public. No token is read, passed in argv, or printed. If
 *   the repository is ever made private this check must be reworked, and it
 *   will fail loudly rather than silently degrade.
 *
 * EXIT CODES
 *   0 all checks passed   1 at least one failed   2 could not run
 */

import { execFileSync } from 'node:child_process';
import { createHash } from 'node:crypto';
import { readFileSync, existsSync } from 'node:fs';

const HANDOFF = 'audit/orchestrator_handoff.json';
const SHA_RE = /^[0-9a-f]{40}$/;
const TS_RE = /^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$/;

// Must match github.comment_marker_prefix in the executor's config. If they
// diverge, the --github check looks for a marker that is never published, so
// it is asserted against the real thread rather than assumed.
const MARKER_PREFIX = 'cpv-orchestrator-review';

const SECRET_PATTERNS = [
  [/ghp_[A-Za-z0-9]{20}/, 'classic GitHub PAT'],
  [/github_pat_[A-Za-z0-9_]{20}/, 'fine-grained GitHub PAT'],
  [/sk-ant-[A-Za-z0-9-]{20}/, 'Anthropic API key'],
  [/-----BEGIN [A-Z ]*PRIVATE KEY/, 'private key'],
  [/postgres(?:ql)?:\/\/[^\s"]*:[^\s"]*@/, 'connection string with password'],
];

const args = process.argv.slice(2);
const flag = (n) => args.includes(n);
const strict = flag('--strict');
const useGithub = flag('--github');
const parked = flag('--parked');
const checkDocs = flag('--docs');
const expectedHead = args[args.indexOf('--head') + 1] && args.includes('--head')
  ? args[args.indexOf('--head') + 1] : null;
const branchArg = args.includes('--branch') && args[args.indexOf('--branch') + 1]
  ? args[args.indexOf('--branch') + 1] : null;

let failures = 0, warnings = 0, unchecked = 0;
const pass = (m) => console.log('  [PASS] ' + m);
const fail = (m) => { failures++; console.log('  [FAIL] ' + m); };
const skip = (m) => { unchecked++; console.log('  [NOT CHECKED] ' + m); };
const warn = (m) => {
  warnings++;
  if (strict) { failures++; console.log('  [FAIL] (strict) ' + m); }
  else console.log('  [WARN] ' + m);
};
const section = (m) => console.log('\n== ' + m);
const note = (m) => console.log('         ' + m);

const git = (...a) => execFileSync('git', a, { encoding: 'utf8', maxBuffer: 64 * 1024 * 1024 }).trim();
const gitQ = (...a) => { try { return git(...a); } catch { return null; } };

// --- 1. parse ---------------------------------------------------------------

section('1. File parses');
let raw;
try { raw = readFileSync(HANDOFF, 'utf8'); }
catch (err) { console.error('cannot read ' + HANDOFF + ': ' + err.message); process.exit(2); }

let handoff;
try {
  handoff = JSON.parse(raw);
  pass('valid JSON, ' + Object.keys(handoff).length + ' top-level keys, ' +
       raw.split('\n').length + ' lines');
} catch (err) {
  fail('not valid JSON: ' + err.message);
  console.log('\nnothing else can be checked until it parses.');
  process.exit(1);
}

// --- 2. secret pattern scan -------------------------------------------------

section('2. Secret pattern scan');
let matched = 0;
for (const [re, label] of SECRET_PATTERNS) {
  if (re.test(raw)) { fail('matches the ' + label + ' pattern'); matched++; }
}
if (!matched) {
  pass(SECRET_PATTERNS.length + ' known secret patterns did not match');
  note('This is a PATTERN SCAN, not proof that no secret of any shape is present.');
  note('A credential in an unrecognised format would not be caught here.');
}

// --- 3. review pointer ------------------------------------------------------

// --- the branch the EXECUTOR watches, which is not necessarily the checkout --
//
// The publication marker is sha256(repo|task|BRANCH|sha): the branch is PART OF
// IT. An earlier version read the branch from `git rev-parse --abbrev-ref HEAD`,
// so running this from any other local branch - a test-runner branch, say -
// computed a marker that is never published and reported "review_head has NO
// verdict". That is a false FAIL, the mirror image of the false PASS this
// script exists to prevent, and in the other direction it would invite
// publishing a duplicate request for a pointer that already has a verdict.
//
// Authority order: explicit flag, then the handoff's own field, then the
// checkout - and the last one warns, because it is a guess about what the
// executor watches.
const checkoutBranch = gitQ('rev-parse', '--abbrev-ref', 'HEAD');
const reviewBranch = branchArg || handoff.review_branch || checkoutBranch;
const branchSource = branchArg ? '--branch'
  : handoff.review_branch ? 'handoff.review_branch'
  : 'the current checkout';

section('3. Review pointer');
note('review branch: ' + (reviewBranch || '(unknown)') + '  [from ' + branchSource + ']');
if (branchSource === 'the current checkout') {
  warn('the review branch was taken from the current checkout (' + checkoutBranch + '). ' +
       'The marker in section 4 is branch-dependent, so pass --branch or set ' +
       'handoff.review_branch to make this exact rather than inferred.');
}
const head = handoff.review_head;
let headOk = false;
if (typeof head !== 'string' || !SHA_RE.test(head)) {
  fail('review_head is not a 40-character hex sha: ' + JSON.stringify(head));
} else {
  headOk = true;
  pass('review_head is a well-formed sha');
  if (gitQ('cat-file', '-t', head) !== 'commit') {
    fail('review_head ' + head.slice(0, 12) + ' is not a commit in this repository');
    headOk = false;
  } else {
    pass('review_head resolves to a real commit');
    // Reachability must be judged on the branch the reviewer will audit, not on
    // whatever is checked out. origin/<branch> is preferred: it is what the
    // executor fetches.
    const branchRef = ['refs/remotes/origin/' + reviewBranch, 'refs/heads/' + reviewBranch]
      .find((r) => reviewBranch && gitQ('rev-parse', '--verify', '--quiet', r));
    if (!branchRef) {
      skip('cannot verify reachability: neither origin/' + reviewBranch + ' nor ' +
           reviewBranch + ' exists locally. Run `git fetch origin ' + reviewBranch + '`.');
    } else {
      const isAncestor = (() => {
        try { git('merge-base', '--is-ancestor', head, branchRef); return true; } catch { return false; }
      })();
      if (isAncestor || gitQ('rev-parse', branchRef) === head) {
        pass('review_head is reachable from ' + branchRef.replace('refs/remotes/', '').replace('refs/heads/', ''));
      } else {
        fail('review_head is not an ancestor of ' + branchRef +
             '; the reviewer would audit a commit outside the branch it watches');
      }
    }
  }
  if (expectedHead) {
    if (head === expectedHead) pass('review_head matches the expected ' + expectedHead.slice(0, 12));
    else fail('review_head is ' + head.slice(0, 12) + ', expected ' + expectedHead.slice(0, 12));
  }
}

const VALID_STATES = ['IN_PROGRESS', 'READY_FOR_REVIEW', 'WAITING_ORCHESTRATOR_REVIEW',
                      'CORRECTIONS_REQUIRED', 'PASS_CLOSED', 'OWNER_GATE_REQUIRED', 'BLOCKED_EXTERNAL'];
if (VALID_STATES.includes(handoff.review_state)) pass('review_state is ' + handoff.review_state);
else fail('review_state ' + JSON.stringify(handoff.review_state) + ' is not one of ' + VALID_STATES.join(', '));

// --- 4. does the pointer already have a verdict? ----------------------------
// Exact, not inferred. The executor derives its publication marker as
// sha256(repo|task|branch|sha) truncated to 32 hex chars, so the same marker
// is recomputed here and searched for in the real thread. Reproduction of
// three known markers was verified before this check was trusted.

section('4. Verdict already published for review_head?');
const repoSlug = (() => {
  const url = gitQ('remote', 'get-url', 'origin') || '';
  const m = url.match(/[:/]([^/]+\/[^/]+?)(?:\.git)?$/);
  return m ? m[1] : null;
})();
const task = handoff.review_task;
let marker = null;
if (headOk && repoSlug && reviewBranch && typeof task === 'string' && task) {
  marker = MARKER_PREFIX + ':' + createHash('sha256')
    .update([repoSlug, task, reviewBranch, head].join('|'), 'utf8')
    .digest('hex').slice(0, 32);
  note('expected marker: ' + marker);
  note('computed over repo|task|branch|sha with branch=' + reviewBranch +
       ' [from ' + branchSource + ']');
}

if (!marker) {
  skip('cannot compute the marker without a valid head, repo slug, branch and task');
} else if (!useGithub) {
  skip('publication state NOT CHECKED. This requires reading the issue; pass --github.');
  note('A previous version of this script inferred it from prose and printed a');
  note('false PASS. It now refuses to guess.');
} else {
  const issue = (handoff.review_issue && Number.isInteger(handoff.review_issue))
    ? handoff.review_issue : 2;
  let found = null, scanned = 0, apiError = null;
  try {
    for (let page = 1; page <= 20; page++) {
      const batch = await ghGet('/repos/' + repoSlug + '/issues/' + issue +
                                '/comments?per_page=100&page=' + page);
      if (!Array.isArray(batch) || batch.length === 0) break;
      scanned += batch.length;
      for (const c of batch) if ((c.body || '').includes(marker)) found = c.id;
      if (batch.length < 100) break;
    }
  } catch (err) { apiError = err.message; }

  if (apiError) {
    skip('could not read the issue anonymously: ' + apiError);
    note('If the repository was made private this check must be reworked.');
  } else if (found) {
    note('scanned ' + scanned + ' comments; marker found in comment ' + found);
    if (parked) pass('review_head already has verdict ' + found +
                     ', and --parked says that is intentional');
    else fail('review_head ' + head.slice(0, 12) + ' already has published verdict ' + found +
              '. No new review will be requested and the loop stalls silently. ' +
              'Advance the pointer, or pass --parked if this is deliberate.');
  } else {
    note('scanned ' + scanned + ' comments; marker not present');
    if (parked) fail('--parked was passed, but review_head has NO verdict: this is a ' +
                     'pending request, not a parked pointer');
    else pass('no verdict for review_head yet: a review will be requested');
  }
}

// --- 5. task identifier -----------------------------------------------------

section('5. review_task is an identifier, not prose');
if (typeof task !== 'string' || !task.trim()) fail('review_task is missing or empty');
else if (task.length > 48)
  fail('review_task is ' + task.length + ' characters. The executor correlates a published ' +
       'verdict by looking for this value in comment bodies, so prose here makes that ' +
       'test unsatisfiable - it caused a round to be reviewed twice.');
else if (/[.;]|\s{2,}/.test(task) || task.split(/\s+/).length > 4)
  fail('review_task looks like prose: ' + JSON.stringify(task));
else pass('review_task is ' + JSON.stringify(task) + ' (' + task.length + ' chars)');

// --- 6. timestamp -----------------------------------------------------------

section('6. review_requested_at');
const ts = handoff.review_requested_at;
if (typeof ts !== 'string' || !TS_RE.test(ts))
  fail('review_requested_at is not UTC ISO-8601 (YYYY-MM-DDTHH:MM:SSZ): ' + JSON.stringify(ts));
else {
  const when = Date.parse(ts);
  if (Number.isNaN(when)) fail('review_requested_at does not parse: ' + ts);
  else {
    const ageHours = (Date.now() - when) / 3.6e6;
    if (ageHours < -0.5) fail('review_requested_at is ' + Math.abs(ageHours).toFixed(1) + 'h in the future');
    else if (ageHours > 24 * 14) warn('review_requested_at is ' + Math.round(ageHours / 24) + ' days old');
    else pass('review_requested_at is ' + ts + ' (' + ageHours.toFixed(1) + 'h ago)');
  }
}

// --- 7. consumed verdict ----------------------------------------------------

section('7. Consumed verdict');
const lastId = handoff.last_orchestrator_comment_id;
if (!Number.isInteger(lastId) || lastId <= 0)
  fail('last_orchestrator_comment_id is not a positive integer: ' + JSON.stringify(lastId));
else pass('last_orchestrator_comment_id is ' + lastId);

const consumed = handoff.last_orchestrator_verdict_consumed;
if (typeof consumed !== 'string' || consumed.length < 40)
  fail('last_orchestrator_verdict_consumed is missing or too short to be a record');
else if (Number.isInteger(lastId) && !consumed.includes(String(lastId)))
  fail('the narrative does not mention ' + lastId + ', so text and id disagree about the round');
else pass('narrative names the consumed verdict');

// --- 8. history retention ---------------------------------------------------

section('8. History retention');
const supersededIds = Object.keys(handoff)
  .filter((k) => k.startsWith('superseded_verdict_consumed_'))
  .map((k) => k.replace('superseded_verdict_consumed_', ''));
pass('superseded entries present for: ' + (supersededIds.join(', ') || 'none'));

const shallow = gitQ('rev-parse', '--is-shallow-repository');
if (shallow === 'true') {
  skip('repository is a SHALLOW clone, so earlier revisions are absent and the ' +
       'history scan cannot certify complete retention. Re-run in a full clone.');
} else {
  const log = gitQ('log', '--format=%H', '--', HANDOFF);
  if (log === null) skip('cannot read git history for ' + HANDOFF);
  else {
    const revs = log.split('\n').filter(Boolean);
    const seen = new Set();
    let unreadable = 0;
    for (const sha of revs) {
      const blob = gitQ('show', sha + ':' + HANDOFF);
      if (!blob) { unreadable++; continue; }
      try {
        const id = JSON.parse(blob).last_orchestrator_comment_id;
        if (Number.isInteger(id)) seen.add(String(id));
      } catch { unreadable++; }
    }
    if (unreadable) warn(unreadable + ' of ' + revs.length +
                         ' historical revisions could not be parsed; retention is partial');
    const missing = [...seen].filter((id) => id !== String(lastId) && !supersededIds.includes(id));
    if (missing.length === 0)
      pass('every verdict id ever consumed (' + seen.size + ' across ' + revs.length +
           ' revisions) is current or retained');
    else
      fail('ids dropped without retention: ' + missing.join(', ') +
           '. Each consumed verdict must stay recorded under superseded_verdict_consumed_<id>.');
    note('LIMIT: this proves the IDS and KEYS are retained. It does NOT verify that');
    note('the retained TEXT equals the original narrative; comparing text would');
    note('require pinning each original blob, which this check does not do.');
  }
}

// --- 9. hold consistency, in the handoff only -------------------------------

section('9. Hold consistency (handoff fields only)');
const parallel = handoff.parallel_tasks || {};
const statuses = Object.entries(parallel).map(([n, v]) => [n, (v && v.status) || '']);
if (statuses.some(([, s]) => /ACTIVE|PENDING_DEV/.test(s)))
  fail('a parallel task still reads as active: ' + statuses.map(([n, s]) => n + '=' + s).join(', '));
else if (statuses.length) pass('parallel tasks: ' + statuses.map(([n, s]) => n + '=' + s).join(', '));

if (handoff.current_hold) pass('current_hold is recorded');
else warn('no current_hold field; if a task is held, say so where a resuming session reads it');

if (/ACTIVE_PARALLEL_DEVELOPMENT/.test(String(handoff.current_status || '')))
  fail('current_status claims active parallel development while TASK-0010 is held');
else pass('current_status is ' + handoff.current_status);
note('SCOPE: this section reads the HANDOFF only. It does not certify the');
note('documentation set. Pass --docs for the limited document checks.');

// --- 10. resume-path documents, limited -------------------------------------

section('10. Resume-path documents (limited, opt-in)');
const DOCS = ['docs/orquestador/current_task.md', 'docs/orquestador/SESSION_RESUME.md'];
if (!checkDocs) {
  skip('document checks NOT CHECKED; pass --docs to run them');
} else {
  for (const file of DOCS) {
    if (!existsSync(file)) { fail(file + ' is missing'); continue; }
    const text = readFileSync(file, 'utf8');
    // Only consider lines outside blocks whose heading marks them historical.
    const lines = text.split('\n');
    let historical = false, hits = [];
    for (let i = 0; i < lines.length; i++) {
      const line = lines[i];
      if (/^#{1,3}\s/.test(line)) {
        historical = /hist[oó]ric|superad|cerrad|closed|pre-APPLY|pre-TASK/i.test(line);
      }
      if (historical) continue;
      if (/ACTIVE\s*\/\s*PARALLEL DEVELOPMENT|ACTIVE_PARALLEL_DEVELOPMENT/.test(line))
        hits.push((i + 1) + ': ' + line.trim().slice(0, 90));
      if (/^#\s*Tarea activa\s*$/.test(line))
        hits.push((i + 1) + ': ' + line.trim());
    }
    if (hits.length) {
      fail(file + ' presents held work as active, outside any historical section:');
      for (const h of hits) note(h);
    } else {
      pass(file + ': no active-TASK-0010 claim outside historical sections');
    }
  }
  note('LIMIT: a heuristic over heading labels and two exact phrases. It cannot');
  note('detect a contradiction phrased differently, and it is not a substitute');
  note('for review of the documentation set.');
}

// --- verdict ----------------------------------------------------------------

console.log('\n' + '-'.repeat(68));
console.log('failures: ' + failures + '   warnings: ' + warnings +
            '   not checked: ' + unchecked + (strict ? '   (strict)' : ''));
if (failures === 0) {
  console.log('RESULT: PASS' + (unchecked ? ' (with ' + unchecked + ' check(s) NOT CHECKED)' : ''));
  process.exit(0);
}
console.log('RESULT: FAIL - fix the above before spending a review');
process.exit(1);

// ----------------------------------------------------------------------------

function ghGet(path) {
  // Anonymous. No token is read, passed or printed; the repository is public.
  return new Promise((resolve, reject) => {
    import('node:https').then(({ default: https }) => {
      const req = https.get(
        { host: 'api.github.com', path,
          headers: { 'User-Agent': 'cpv-handoff-validator', Accept: 'application/vnd.github+json' } },
        (res) => {
          let body = '';
          res.on('data', (d) => { body += d; });
          res.on('end', () => {
            if (res.statusCode !== 200) return reject(new Error('HTTP ' + res.statusCode));
            try { resolve(JSON.parse(body)); } catch (e) { reject(e); }
          });
        });
      req.on('error', reject);
      req.setTimeout(30000, () => req.destroy(new Error('timeout')));
    }).catch(reject);
  });
}
