# Post-deploy follow-up for candidate 0010 (2026-10-15)

- **Instruction:** Issue #2 comment `6187768951`. It closes the factual post-deploy report of `6174797031` before TASK-0010C starts.
- **Staging:** still on `9b447eb15e80bddc4958430a8fc60acf5150ff4d`. No new push, deploy or workflow run.
- **How it was read:** `docs/deploy/postdeploy_followup_requests_0010.sql` (sha256 `e4269e6f…c6e4`) with the same runner, as `www-data`, inside a READ ONLY transaction ending in ROLLBACK. Only aggregates were read: no request text and no personal data.

## What changed after the deploy report
After the deploy the owner submitted **request #1** from the affiliate form. The deploy report's assumption of an empty request table no longer holds. That is legitimate activity and was neither deleted nor touched.

| Evidence | Value |
|---|---|
| Requests | **1** (id 1) |
| Review status | `pending_review` |
| Notice status | **`failed`**, code **`recipient_not_configured`** |
| Recipient saved on the request / send date | none / none |
| Recipient configured in the singleton | **no** (0 of 1 rows) |
| Mail transport on staging | **`MAIL_MAILER=log`**, the only key read. There are no SMTP values to read. |
| Laravel log | 1 `WARNING` at 2026-10-15 08:16:02, "registrada sin correo - destinatario no configurado". Only the prefix was read. |

The owner's screenshot ("Solicitud registrada, aviso por correo pendiente") matches exactly what the database records. This is now confirmed by the status code in the database, not only by the message on screen.

## Protected counts vs the deploy baseline
Everything is identical (767 / 406 / 163 / 10 / 82 / 3497 / 15 / 1 / 145 / 9749 / 1893 / 366, and the ledger at 129/59). The only difference is `taxonomy_category_requests` going 0 → 1, which is request #1.

## Notes
- Even if a recipient were configured, staging **would not send** mail, because the transport is `log`. A send under that transport would still mark `sent`. That misleading behaviour is fixed by TASK-0010C, which is **not deployed**.
- Artifacts: `audit/integration_candidate_0010/staging_deploy/followup_2026-10-15/`
