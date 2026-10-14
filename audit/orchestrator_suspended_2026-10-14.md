# Orchestrator — SUSPENDED BY OWNER DECISION, 2026-10-14

Checkpoint for the owner decision of 2026-10-14: suspend the autonomous review
loop, stop all Anthropic consumption, return to manual review, and put CPV back
first. Nothing was deleted, no history was rewritten, and no force-push was
made.

**No Anthropic call was made to produce, review or confirm this suspension.**

---

## 1. Consumption stopped, and how it is verified

| | |
|---|---|
| Service | `cpv-orchestrator.service` (systemd, on the staging VPS) |
| State | **inactive** |
| Boot start | **disabled** (the `multi-user.target.wants` link was removed) |
| Processes | **0** matching the executor |
| Stopped at | `2026-10-14T08:13:57Z` (`shutdown_signal 15` → `executor_stopped`) |

**Every activation mechanism, enumerated rather than assumed.** Before stopping,
the host was searched for each one:

| mechanism | found | action |
|---|---|---|
| systemd service, `enabled` at boot | yes | stopped and disabled |
| systemd `Restart=always` | yes | only applies when the process exits by itself, not after an explicit stop; now moot, and the drop-in below blocks a restart anyway |
| systemd timer / path / socket units | none | — |
| `root` crontab, `/etc/cron.d`, `/etc/cron.*`, `/etc/crontab` | none | — |
| `at` queue | none | — |
| `.wants` / `.requires` links from other units | none | the one boot link was removed by `disable` |
| GitHub Actions workflow that could start it | none (the only workflow deploys the app and never touches the executor) | — |

**A later start is refused, not merely discouraged.** `systemctl mask` was tried
first and refused, because a real unit file already occupies the path that
masking needs for its symlink. Instead an **additive, reversible** drop-in was
installed at `cpv-orchestrator.service.d/zz-suspended-by-owner.conf`, which adds
a `ConditionPathExists` on a reactivation marker that does not exist, plus an
`ExecStartPre` that fails. The original unit file is **untouched** (same size
and mtime as before) and nothing was deleted.

Verified by attempting one start — the only one run deliberately:

```
cpv-orchestrator.service … was skipped because of an unmet condition check
(ConditionPathExists=/…/REACTIVATION-AUTHORIZED)
→ is-active: inactive, is-enabled: disabled, processes: 0
```

Because the condition fails before `ExecStart`, the executor's Python never
runs, so **no API call is possible even if someone starts the unit**.

### Two polling intervals observed

The configured poll interval is 120 s. With the service stopped, more than two
intervals were observed: no `cycle_complete`, no `review_request_discovered`,
no `review_starting`, no `executor_started`, and the hourly review-budget
counters unchanged. "No new request" was not accepted as evidence on its own —
the service state, the process list and the journal were each checked.

### One honest correction: the service was briefly restarted by my own check

While verifying, a `systemctl start` was issued expecting the (failed) mask to
refuse it. It did not refuse, so the executor ran from `08:13:18Z` to
`08:13:57Z`. What it did in that window, from its own log and state:

* exactly one cycle, `cycle_complete seconds: 1.53`;
* **no** `review_request_discovered` and **no** `review_starting`;
* the hourly review budget stayed at 3 for the `2026-10-14T06` bucket.

So that restart produced **no Anthropic call**. It is recorded because a
suspension report that hides a restart is worth nothing. No further start was
attempted after the block was in place, beyond the single refusal test above.

### Pending jobs and retries: none to cancel

Read from the durable state rather than assumed: all **10** review requests are
in the terminal status `PUBLISHED`, no lease is held by any worker, and no retry
is queued. Nothing needed cancelling and nothing was written to the database.

If the executor is ever reactivated it will also find nothing to do: the review
pointer names a commit that already has a published verdict, so a reconciliation
cycle is idle by construction.

### Credentials

Untouched, unprinted, uncopied and not in git. They stay in the host's protected
store, readable only by the service account. No provider was changed and no
second API was added.

---

## 2. What was built, preserved

| repository / branch | SHA |
|---|---|
| `PerfilAfiliadosCPV` · `feature/upgrade-filament-v3` (integration) | `df7de15d14d8b78031603bad50f2e76e070f798e` |
| `PerfilAfiliadosCPV` · `infra/contabo-test-runner` (isolated test harness) | `04c1fbf9abcfa6847575368e5761482a8345ff2b` |
| `PerfilAfiliadosCPV` · `knowledge/project-context` | `44db2fa` |
| `PerfilAfiliadosCPV` · `feature/task-0010a-category-request` | `b9aca40` |
| `PerfilAfiliadosCPV` · `feature/task-0010b-online-manual` | `5eee61a` |
| `SISTEG-Agent-Orchestrator` · `feature/cpv-executor-contabo` (private) | `64ecc4bfb1bd22715f79371ea3e2d6c99272a40d` |

All six are pushed. The deployed executor was byte-identical to `64ecc4bf` when
it was stopped: all ten module blob ids matched that commit.

**Last request and comment published:** request id `10`, reviewed commit
`58a6e1a615eb`, verdict `OWNER_GATE_REQUIRED`, Issue #2 comment
**`6162770894`**, published `2026-10-14T06:58:20Z`. Ten reviews were performed
in total, each with a distinct publication marker and exactly one comment —
verified by scanning the live thread.

**Where state and evidence live** (role names, not paths: this repository is
public and the visibility rule forbids adding internal paths):

* executor durable state — SQLite database in the executor's state directory on
  the VPS, holding the 10 requests, the hourly budget and the audit events;
* executor logs — the systemd journal for the unit, retained by the host;
* isolated test-run artifacts — per-run directories under the runner's scratch
  root on the VPS; the checkable subset is committed, redacted, in this
  repository under `audit/isolated_phpunit_2026-10-13/` and
  `audit/isolated_phpunit_2026-10-14/`;
* the test harness, the three schema patches and their proposal — on the runner
  branch at the SHA above, under `docker/test/`;
* the governance record — `audit/orchestrator_handoff.json` and
  `audit/isolated_phpunit_contabo_2026-10-13.md`.

**Nothing deleted:** no repository, branch, artifact, SQLite file, log or script
was removed; no history was rewritten; no force-push was made.

### Reactivating it later — owner decision only

1. Create the reactivation marker file named by the drop-in's
   `ConditionPathExists`, in the executor's config directory.
2. Remove `cpv-orchestrator.service.d/zz-suspended-by-owner.conf`.
3. `systemctl daemon-reload`
4. `systemctl enable --now cpv-orchestrator`

Then check `systemctl is-active`, and expect `executor_started` followed by
`cycle_complete` in the journal. Before reactivating, review the open GATE F
decision below, since a running executor publishes to a public issue.

---

## 3. Status

**SUSPENDED BY OWNER DECISION.** This state is deliberately separate from CPV
development, which continues under manual review: the suspension concerns only
the autonomous loop and its Anthropic consumption.

The loop's own record, kept for whenever it is revisited: it converged over ten
reviews, the last of which accepted every correction of the round before it and
returned `OWNER_GATE_REQUIRED` rather than `PASS`, because what remained was an
owner decision.

### Still open for the owner, carried forward and not acted on

1. **GATE F — internal detail already in public git history.** Executor and
   runner paths, the OS release and a private subnet prefix are reachable in the
   history of `feature/upgrade-filament-v3` at `650db29` and `83c6de7`, and of
   `infra/contabo-test-runner` from `4045faa` through `d17854bd…`. The current
   tree of both branches is clean. Remediation is a history rewrite, a
   visibility change, or explicit acceptance; all three are owner-only, and
   rewriting and force-pushing are prohibited for this agent.
2. Whether to promote the three schema patches into the integration branch.
3. Whether to merge `feature/supplhi-postgres-buscador`, whose absence is why
   the integration branch cannot build an empty database.
4. Synthetic test fixtures — **now authorised** by the owner decision of
   2026-10-14, for the disposable test PostgreSQL only, with no customer data
   and never the shared database as a fixture.
