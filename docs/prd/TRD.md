# Joinera Job Apply — TRD

**Version:** 1.6 · **English** · **Audience:** Engineering  
**Related:** [PRD](./PRD.md) · [Apply email LVP phases](./APPLY_EMAIL_LVP_PHASES.md) · [فارسی](./TRD.fa.md)  
**Intake/match/apply lock:** aligns with PRD 1.8 (2026-11-18) — boards = intake; Mode B = company-site AI form-discover + Playwright.

---

## Posture

1. Low AI/API cost; more code/complexity OK.  
2. Queues, idempotent applies, retries, observability, per-Candidate isolation.  
3. Scale from current manual volume without rewrite.  
4. Software first; Staff Plan B = fallback.  
5. Anti-block is **hard**.

---

## Context

```text
Staff / Admin Panel → API/BFF → Services
  Auth · Profile/CV Parse · Identity · Intake · Match/Queue
  Doc PDF · Apply Workers · Alerts · Reporting
  (no billing and no payment copy)

External: job boards (Q12) · GMX IMAP/SMTP · LLM/PDF
```

Landing stays marketing; Admin panel is Staff/Admin only (no Candidate panel). Modular monolith OK if workers + tenancy stay clean.

---

## Services

| Service | Does |
| --- | --- |
| Auth & RBAC | Staff/Admin sessions only; Candidate is a `role=user` **record**, not a panel login |
| Profile + CV Parse | Structured profile; Staff upload/parse → editable fields; one-time approval gate |
| Identity | Per-Candidate GMX mailbox credentials + per-site secrets/sessions; break-glass ACL |
| Intake + Browser fleet | **Once daily** scrape **new** listings by external job id from DE **boards** (discovery + company apply URL); isolated contexts ≠ apply sessions |
| Match | Rules filter (hard bans) → AI rank inside eligible → `proposed`; Staff Queue selected / force-queue |
| Apply Queue | Mode B; statuses include `preparing_docs`; caps (Q17); idempotency; pause on blocks |
| Doc | PDF gen + cost metadata (Q20 modes); runs while status `preparing_docs` |
| Apply Worker | **Company-site Mode B:** Playwright opens listing company apply URL → generic form-discover → AI maps Candidate+docs → fill/upload/submit. **Must** attach CV + per-apply cover whenever those slots exist (never skip as “optional”); attach passport/language/education when slots match; Ready GMX apply email only. Empty CV/cover upload on a present slot = hard fail. No board Easy-Apply primary path. No per-employer adapters. |
| Inbox/Sync | GMX IMAP sync stores messages in DB; Staff/Admin read/reply via GMX SMTP. Candidates cannot. |
| Ops / Reporting / Notify | Plan B, metrics, alerts; soft-match signals visible in Admin (no auto-ban until PM) |
| Billing | Not in the product. No price, payment, or “free” copy. |

---

## Data (logical)

**Entities:** User (Candidate record \| Staff \| Admin) · Profile · CvAsset · MailboxIdentity · SiteIdentity · Listing · Match · Application · DocArtifact · ApplyAttempt · StaffAccessAudit · BlockEvent

**Listing (minimum fields):** title · company · location/city · listing URL · **company apply URL** (required for Mode B when distinct) · description · source site · external job id · kind (`job` \| `ausbildung`) · language requirement (nullable) · posted/found date

**Pipeline:** `matched → queued → preparing_docs → applying → submitted → reply_received → interview → rejected | offer`

- `matched → queued` is **automatic** for eligible Candidates (no Staff swipe/approve step).  
- `queued → preparing_docs` while Q20 PDFs generate; then `applying`.  
- Server-side transition checks.  
- GMX credentials + stored messages deleted **only** when Staff/Admin deletes the Candidate — never because an application was rejected.  
- Idempotency: `user + listing + site`. Intake dedupe: **source + external job id**.

---

## Requirements by area

### Intake & match
- **Once daily** DE scrape of fixed **big boards** (Q12: Ausbildung.de, Azubiyo, arbeitsagentur.de — Jobbörse + Ausbildungssuche) for **discovery only**. Persist **company apply URL** when distinct from listing URL. Insert **only new** external job ids. Jobs + Ausbildung. No Staff curated paste/import in v1. Do **not** use boards as the Mode B submit target.  
- Non-DE off (Q33 locked false). Intake browsers ≠ apply Candidate sessions.  
- Eligible Candidates only: **ready GMX** + **complete profile** (+ one-time profile approval before apply claim). Board `SiteIdentity` is **not** a claim gate for company-site apply.  
- **Hard never-match (auto, no AI debate):** outside Candidate industry; listing not in Germany; listing requires Schulabschluss/degree Candidate lacks.  
- **Soft signals (Admin list only until PM):** language-level mismatch; city/province mismatch — **do not** auto-exclude yet.  
- Flow: rules filter → AI ranks eligible set → `proposed`. Staff **Queue selected** / **force-queue**. No `recommended` swipe surface in v1. Re-match on profile/listing change must not create duplicate Applications (idempotency key).

### Auto-apply & anti-block
- Dequeue: `preparing_docs` complete → `applying` → `submitted` / fail.  
- Apply session uses Candidate GMX + profile/docs on the **company** form; isolate browser context per Candidate. Never share cookies across Candidates.  
- Human-like: delay jitter, real navigation, warm sessions, per-Candidate concurrency.  
- **Company-site worker:** open company apply URL → discover fields (DOM/a11y; vision if needed) → AI maps Candidate + Q20 PDFs → Playwright fill/upload/submit → parse confirmation. **No** board Easy-Apply primary path. **No** coded adapter per employer. Missing company apply URL → fail closed / Staff `external_apply` handoff.  
- Success: site confirmation reference **or** clean submit without error.  
- CAPTCHA/2FA/login wall / unfillable form → `BlockEvent`, pause path, alert Staff (Q18 min).  
- Caps via env (Q17): `JOINERA_APPLY_CAP` (int, default 10) and `JOINERA_APPLY_CAP_WINDOW` = `daily` \| `weekly`. Queue selected / force-queue MUST respect the active window per Candidate. Prefer spreading listings across Candidates (avoid every Candidate on one listing). Not 1000 concurrent (Q31).  
- Plan B: audited break-glass + manual complete on company URL.

### Docs
- PDF only. Same category as profile: reuse base CV + new cover. Different category: new CV + new cover. **Interim:** empty listing category → CV + cover (Azubiyo / Ausbildung.de do not populate category yet). **Later (PRD Q-Listing-Category):** intake must map a real category/occupation field; then unknown can stop again if needed.  

- Status `preparing_docs` while artifacts generate. Cache base CV/templates; skip LLM when category matches. **No** per-document / per-cover Staff approval in v1. Cost meta stays internal; never shown as price.

### Email & security
- **v1 mailbox = GMX (PRD Q8).** Admin creates the GMX account outside Joinera and stores email + password on `MailboxIdentity` (encrypted). States: `pending` \| `ready` \| `failed`. System IMAP-syncs inbound into DB and sends Staff/Admin replies via GMX SMTP. Not Mailgun domain auto-provision, not Gmail OAuth, not `@apply.joinera.de`.  
- Delete GMX credentials + message rows only on Candidate delete by Staff/Admin. Provider GMX account is not auto-deleted.  
- Candidates never sync or read mail. Staff and Admin may. No Candidate OAuth.  
- Secrets in KMS/vault; plaintext only via audited break-glass.  
- No cross-Candidate cookies/storage. Encrypt at rest; TLS; least privilege workers; rate-limit auth/break-glass; minimize PII in logs. GDPR retention TTL = Q11 (open).

### API & panels
- Versioned HTTPS JSON API (Laravel); panel = **React + TypeScript + Tailwind + shadcn/ui**. **No Vue.** **No Candidate panel.**  
- RBAC: `staff` \| `admin` for panel; Candidate `user` records exist for apply identity only.  
- Idempotency-Key on enqueue / force-queue.  
- Events: listing.created, application.status_changed, identity.blocked.  
- Admin/Staff: Candidates, one-time profile approval, GMX attach, mailbox sync/read/reply, auto-queue + failures, force-queue, soft-match Admin list (display), blocks, break-glass+audit, metrics, roles, delete Candidate. No price/payment/“free” copy.

### Scale, observe, test
- Not sized for 1000 concurrent day one (Q31). Horizontal workers; indexes on user+status, external job ids, idempotency keys.  
- Metrics: intake, queue depth, `preparing_docs` lag, success/block/Staff rates, doc/AI cost, apply lag. Trace enqueue→doc→apply.  
- Tests: unit (hard-match rules/status/idempotency) · company-site form-discover fakes · queue/PDF · E2E staging · RBAC/delete · light load.

---

## TBD config hooks

| TBD | Flag |
| --- | --- |
| Q7 | **Decided.** `profile.readiness_gate` = `once`. Staff or Admin approve one time. Later profile edits do not re-open the gate. |
| Q8 | **Decided (2026-11-10).** `mailbox.mode` = `gmx_manual`. Admin pastes GMX email + password. IMAP sync + SMTP reply. Not Mailgun/domain auto-provision, not Gmail, not `apply.joinera.de`. |
| Q9 | **Decided.** Candidates cannot read or reply. No Candidate panel inbox. Staff and Admin can read and reply. |
| Q11 | **Ownership decided.** Mail data Joinera stores is under Joinera ops control. Retention TTL still open. |
| Q12 | **Decided (apply target 2026-11-18).** Intake `sites.v1[]` = `ausbildung.de`, `azubiyo`, `arbeitsagentur.de`. Mode B target = company apply URL from listing. |
| Q17 | **Decided (2026-11-12).** Env: `JOINERA_APPLY_CAP` (default 10) + `JOINERA_APPLY_CAP_WINDOW` (`daily` \| `weekly`). Per-Candidate Application count in that window; tailored docs per Application. |
| Q18 | **Min locked.** detect → pause → alert Staff. Richer `challenge.policy` still open. |
| Q20 | **Decided (+ interim 2026-11-15).** Same category: base CV + new cover. Different: new CV + new cover. Empty category: interim CV + cover until Q-Listing-Category. |
| Q-Listing-Category | **Later.** Fill `listing.category` from Azubiyo / Ausbildung.de (board field or title/occupation derivation). |
| Q31 | worker pool / SLOs still open (not 1000 concurrent day one). |
| Q33 | **Decided.** `sites.allow_non_de` = false. DE only. |
| Q-Match-Hard | **Decided.** Auto bans: industry mismatch; not Germany; missing required Schulabschluss/degree. |
| Q-Match-Soft | **Open — ask PM.** Language-level + city/province: Admin-visible only; no auto-exclude yet. |
| Q-Match-Who | **Decided.** Rules → AI rank → auto `queued`. Ready GMX + complete profile. Staff force-queue allowed. |
| Q-Apply-Path | **Decided (2026-11-18).** Company-site Mode B: AI form-discover + Playwright; boards intake-only; Staff Plan B on block. |
| Q-Apply-Success | **Decided.** Confirmation **or** clean submit OK. |
| Q-Commercial | **Decided.** No checkout/subscription. API and panels must not mention price, payment, or that the service is free. |
