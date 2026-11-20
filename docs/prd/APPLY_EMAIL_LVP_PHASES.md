# Apply + Email LVP Phases

Living tracker for Joinera’s **Hybrid** apply + mailbox path.  
Companion docs (do **not** replace): `docs/api/IMPLEMENTATION_PHASES.md`, `docs/admin/IMPLEMENTATION_PHASES.md`.  
Product decision docs: `docs/prd/PRD.md` (Q8 GMX v1), `docs/prd/PRD.fa.md`.

Last evidence pass: **2026-11-11** (GMX attach, IMAP/SMTP adapters, Staff messages/reply, Admin profile/CV + mailbox live preferred; Listing PRD fields extended; Application pipeline still MISSING — next love-path gap = Match → Application **auto-queue**, not GMX rebuild, not Recommended swipe).

---

## How to use this file

### Status legend

| Status | Meaning |
|--------|---------|
| **DONE** | Real implementation in `api/` (persisted + tested) **or** explicitly finished production contract. |
| **NEEDS_REFACTOR** | Exists but wrong shape, stale, duplicated, or blocked by seam debt. |
| **MISSING** | Required for the love path; not in repo yet. |
| **MOCK_OK** | Admin (or local provider) mock is allowed **for now**; contract must stay production-shaped. |

### Priority rule

Earlier phase unlocks later phase. Do not reorder without updating **Depends on** and this section.

Language: **Candidate** / Staff / Admin. Never “seeker”.

This is an **LVP** tracker (lovable production path), not an MVP cut plan.

---

## Love path (definition of done for users)

- Admin creates Candidate
- Admin creates a **GMX** account outside Joinera and pastes email + password into Admin
- Profile approved once; identity readiness becomes ready when GMX credentials verify
- Applications enter paced Mode B apply queue (GMX address on **company** apply forms; boards = intake only per PRD 1.8)
- On CAPTCHA / 2FA / login wall / unfillable company form → pause + Staff alert (no silent fail, no captcha crack)
- Staff resolves (break-glass / manual complete on company URL as needed) with audit
- System IMAP-syncs employer mail from GMX into Joinera DB (Candidate has no ops inbox)
- AI drafts first; Staff takes hard cases; replies send via same GMX SMTP
- Candidate experiences “Joinera did it for me” without Gmail connect

---

## Current snapshot

| Area | Status | Evidence (path) | Notes |
|------|--------|-----------------|-------|
| Auth + roles (user/staff/admin) | DONE | `api/routes/api.php`, `api/tests/Feature/AuthTest.php` | Sanctum session; Admin creates Staff |
| Profile / CV / parse / priorities | DONE | `api/routes/api.php` profile routes; feature tests | Fixture parser OK |
| One-time profile approval | DONE | `POST users/{user}/profile/approvals` | Staff/Admin |
| Mailbox + GMX credentials (v1 lock) | DONE | `AttachGmxMailbox.php`, `PUT users/{user}/mailbox/credentials`, `ApplicantTest.php`, `MailboxTest.php` | Admin paste GMX email+password; create leaves mailbox pending; `StartMailbox` deprecated leftover |
| Mailbox messages + reply API | DONE | `StaffMailboxController` messages/show/reply; `api/routes/api.php`; `MailboxTest.php` | Staff/Admin only; persisted `MailboxMessage` |
| Site identities (encrypted secrets) | DONE | `SiteIdentityController`, `SiteIdentityTest.php` | `paused` column exists; not full block pipeline |
| Identity readiness | DONE | `ResolveIdentityReadiness.php`, readiness routes | |
| Intake / listings | DONE | intake models + `ListingImportController`; `IntakeTest.php` | Fixture adapters; `joinera.sites.v1` empty until Q12 |
| Matches → Application auto-queue | MISSING | no `Application` / `Match` in `api/` | Planned API P0-10 + P0-12 (P0-11 swipe dropped / PRD 1.7) |
| Paced apply jobs + anti-block limits | MISSING | no `SubmitApplication` / limiter | Planned API P0-14–P0-16 |
| BlockEvent + Staff alerts API | MISSING | no BlockEvent in `api/` | Admin mock has kinds |
| Break-glass + audit + manual complete API | MISSING | no Laravel routes | Admin mock A0-06 |
| AI draft / handoff | MISSING | — | Staff reply API live; AI draft/handoff not built |
| Admin shell + nav IA | MOCK_OK | `admin/src/config/nav-config.ts`, `admin/src/lib/mock/SEAM.md` | Ops IA present; demo routes may remain on disk |
| Admin applicants + create + approve | DONE | `admin/src/features/users/api/service.ts` → `/api/v1/applicants*` | Live Sanctum; profile field patch + CV upload live per `SEAM.md` |
| Admin queue / blocks | MOCK_OK | `admin/src/features/ops-queue/**`, `mock-api-ops-queue.server.ts` | Statuses + `BlockKind` shaped for contracts |
| Admin break-glass / audit / manual complete | MOCK_OK | `break-glass-dialog.tsx`, `manual-complete-button.tsx` | SEAM: in-memory only |
| Admin mailbox directory (identity) | DONE | `admin/src/features/mailbox/api/service.ts` | Directory from applicants API |
| Admin mailbox threads / Staff reply | DONE | `features/mailbox/api/service.ts`, `SEAM.md` | Live preferred; mock hybrid fallback only on 404/501/503/unavailable |
| Admin ops reports | MOCK_OK | `admin/src/features/ops-reports/**` | No `GET /api/v1/ops/reports` yet |
| Admin ↔ API HTTP seam | DONE | `joinera-client.server.ts`, `SEAM.md`, `JOINERA_API_URL` | Hybrid live/mock matrix |
| Applicant Staff API | DONE | `ApplicantController`, `ApplicantTest.php` | `GET/POST /api/v1/applicants` |
| API phases doc header | DONE | `docs/api/IMPLEMENTATION_PHASES.md` intro | Status note 2026-11-11 (P0-11 dropped) |
| Admin phases doc wording | NEEDS_REFACTOR | `docs/admin/IMPLEMENTATION_PHASES.md` | Historical A0 body still says “seekers” |
| Playwright / browser worker package | MISSING | not in repo | MOCK_OK as adapter until verified |
| GMX IMAP sync + SMTP reply | DONE | `GmxImapSync.php`, `GmxSmtpMailer.php`, sync jobs/route, `MailboxTest.php` | Adapters + fake driver in tests; live GMX login still mock-until-verified |
| User Panel (Candidate UI) | MISSING | no app directory | Out of Staff love-path; PRD says no Candidate login in v1 |

---

## Mock boundaries

### MUST stay mock until verified

- Playwright / browser **submit worker** (Mode B) — product target: company-site AI form-discover (PRD 1.8); interim board adapters may still exist in code
- CAPTCHA / 2FA / login-wall **detector** implementation
- Live **GMX IMAP/SMTP** login with stored credentials (local/fake injectors OK until verified)
- **AI reply engine** (model/vendor)
- Production site adapters (`joinera.sites.v1` / Q12)

### MUST NOT stay mock forever

- Application **status** machine and Staff queue UX
- **Block kinds** + pause semantics (`captcha` \| `two_factor` \| `login_wall`)
- Mailbox **message** list/thread/reply contract
- Break-glass **audit** rules (reason required; secret never in audit list)
- Per-Candidate **GMX email + encrypted password** in Admin (never `@apply.joinera.de`)
- Admin create-Candidate → enter GMX credentials → ready mailbox **product flow**

### Contract lock (mocks must honor)

From admin ops types + API plans (keep stable):

- **ApplicationStatus:** `matched` \| `queued` \| `preparing_docs` \| `applying` \| `submitted` \| `reply_received` \| `interview` \| `rejected` \| `offer`  
  Evidence: PRD 1.7 / TRD status pipeline; API P0-12 in `docs/api/IMPLEMENTATION_PHASES.md`. Admin mock enum may lag until Application ships — align on first Application API (see Refactor queue).
- **AttemptKind:** `retryable` \| `challenge` \| `hard_fail` \| `success` \| `null`
- **BlockKind:** `captcha` \| `two_factor` \| `login_wall` + `blockPaused`
- **MailboxIdentity states:** `pending` \| `ready` \| `failed`  
  Evidence: API mailbox model/tests; admin fixtures
- **Address shape:** per-Candidate **GMX** address on allowed hosts (e.g. `gmx.de` / `gmx.net`); never `@apply.joinera.de`  
  Evidence: `GmxAddressValidator.php`, `AttachGmxMailbox.php`, `docs/prd/PRD.md` Q8 (GMX), admin mailbox fixtures
- **Break-glass:** reason required; one-shot secret in response; audit without secret; fail closed if audit write fails  
  Evidence: `SEAM.md`, ops-queue mock service
- **Roles:** `user` \| `staff` \| `admin`; panel is Staff/Admin only

---

## Phases (ordered)

### Phase 1 — Foundations already real

- **Goal:** Applicant identity + mailbox policy + site logins + readiness are trustworthy building blocks for Hybrid.
- **Priority:** P0
- **Depends on:** none
- **Status now:** DONE

**Work items**

- [DONE] Auth, roles, Staff user create — `api/routes/api.php`, `AuthTest.php`
- [DONE] Profile, CV, parse, priorities, one-time approval — profile controllers + tests
- [DONE] Mailbox start/show + domain candidate rules — `StartMailbox.php`, `ApplyAddressNormalizer.php`
- [DONE] Site identities + readiness — `SiteIdentity*`, `ResolveIdentityReadiness.php`
- [DONE] Intake listings foundation — intake + `POST listings/import`
- [DONE] Admin applicants / approval screens live via Phase 2 seam — `admin/src/features/users/api/service.ts`
- [DONE] Local/Fake mailbox providers remain for dev/tests (not production GMX IMAP) — `LocalMailboxProvider.php`, `FakeMailboxProvider.php`, `joinera.php`

**Acceptance**

- [x] `POST/GET /api/v1/mailbox` still rejects Joinera-branded / Gmail-style ownership assumptions (`MailboxTest` green, 2026-11-09)
- [x] Readiness still requires ready mailbox + healthy unpaused site (`IdentityReadinessTest` green, 2026-11-09)
- [x] Phase 1 foundations verified; Admin demo of fixtures alone superseded by Phase 2 live directory

**Out of scope**

- Live GMX IMAP/SMTP, apply submit worker, message inbox API

---

### Phase 2 — Admin ↔ API seam

- **Goal:** Staff work against real applicant/mailbox/approval data where contracts already exist, without breaking SEAM for missing APIs.
- **Priority:** P0
- **Depends on:** Phase 1
- **Status now:** DONE (hybrid)

**Work items**

- [DONE] Laravel client + Sanctum sign-in bridge — `admin/src/lib/api/joinera-client.server.ts`, `features/auth/api/service.ts`
- [DONE] Staff applicants list/show DTO API — `ApplicantController`, `BuildApplicantDirectoryItem`, `ApplicantTest.php`
- [DONE] Admin list/detail/approve/create wired — `features/users/api/service.ts`
- [DONE] Admin create leaves mailbox pending; Admin attaches GMX via `PUT users/{user}/mailbox/credentials` — `POST /api/v1/applicants`, `AttachGmxMailbox`
- [DONE] Mailbox directory identity from API — `features/mailbox/api/service.ts`
- [MOCK_OK] Queue/blocks/break-glass/reports remain mock
- [DONE] Applicant profile field patch + CV upload live — `PUT/POST users/{user}/profile*`, `SEAM.md`
- [DONE] Admin mailbox threads / reply live-preferred (mock hybrid fallback) — `features/mailbox/api/service.ts`
- [DONE] Refresh stale intro in `docs/api/IMPLEMENTATION_PHASES.md`
- [DONE] `SEAM.md` live-vs-mock matrix + `JOINERA_API_URL` in `admin/env.example.txt`
- [REFACTOR] Historical “seekers” wording remains in `docs/admin/IMPLEMENTATION_PHASES.md` A0 narrative

**Acceptance**

- [x] Approve + applicant/mailbox identity work Admin → Laravel (`ApplicantTest` + wired services)
- [x] `SEAM.md` documents live vs mock
- [x] Applicant DTO has no secret/password fields

**Out of scope**

- Implementing Application/BlockEvent APIs (Phase 3–4)
- Rewriting unrelated demo folders

---

### Phase 3 — Application auto-queue + paced apply contracts

- **Goal:** Every apply is a visible, paced pipeline row Staff can trust — Match → Application **auto-queue** (Staff force-queue skipped only); even if the browser worker is still mocked. No Recommended swipe in v1.
- **Priority:** P0
- **Depends on:** Phase 1; Phase 2 helpful for Staff UX on real applicants
- **Status now:** partial (Admin MOCK_OK; API MISSING)

**Work items**

- [MISSING] Match + auto-queue (hard bans industry / not-DE / missing Schulabschluss; soft language/city Admin-listed only) — API P0-10 (`docs/api/IMPLEMENTATION_PHASES.md`). P0-11 Recommended swipe is **dropped / superseded**.
- [MISSING] `Application` model + legal status transitions including `preparing_docs` — API P0-12
- [MISSING] Apply queue claim/idempotency/retries — API P0-15
- [MISSING] Human-like anti-block daily/hourly limits — API P0-14
- [MISSING] Mode B submit port (no per-job approval) — API P0-16
- [MOCK_OK] Playwright/worker adapter with fixture “submit” until real sites verified
- [MOCK_OK] Admin queue UI statuses/filters — already shaped in `ops-queue/api/types.ts`
- [REFACTOR] Align admin status enum with API when Application ships (include any planned display-only extras carefully)

**Acceptance**

- [ ] Creating a queued application is idempotent on user+listing+site
- [ ] Illegal status jumps rejected
- [ ] Admin queue can render API rows without inventing new status strings
- [ ] Worker may be fake, but every attempt writes `ApplyAttempt` kind

**Out of scope**

- Captcha solvers, mass unthrottled server apply, Gmail send

---

### Phase 4 — Block / CAPTCHA pause + Staff break-glass + audit

- **Goal:** When apply hits a wall, the product pauses honestly and Staff can unblock without losing trust.
- **Priority:** P0
- **Depends on:** Phase 3
- **Status now:** partial (Admin MOCK_OK; API MISSING; `site_identities.paused` exists)

**Work items**

- [DONE] `paused` column on site identities (readiness input) — migration + readiness rules
- [MISSING] `BlockEvent` + Staff alerts API — API P0-17
- [MISSING] Wire pause on `challenge` / block kinds; resume rules
- [MISSING] Break-glass reveal + `StaffAccessAudit` + manual complete — API P0-18
- [MISSING] Never return site secret on normal Staff site-identity GET (already true for list; keep locked)
- [MOCK_OK] Admin blocks page, break-glass dialog, audit table, manual complete — `ops-queue` mocks + `SEAM.md` fixtures (app 106 audit_failed)
- [MOCK_OK] CAPTCHA detector behind a port until Q18 solver policy is product-locked

**Acceptance**

- [ ] Open block ⇒ `blockPaused=true` and Staff alert row
- [ ] Break-glass without reason fails; audit never stores plaintext secret
- [ ] Manual complete `applying`+block/`hard_fail` → `submitted` idempotently
- [ ] Admin UI can swap service layer to API without redesigning BlockKind

**Out of scope**

- Cracking CAPTCHA, silent retries past challenge, applicant-facing secret UI

---

### Phase 5 — Real mailbox messages (inbound / outbound)

- **Goal:** Employer replies live in Joinera’s Staff mailbox for that applicant’s real-looking address.
- **Priority:** P0
- **Depends on:** Phase 1 mailbox identity; Phase 2 for Admin wiring
- **Status now:** mostly DONE (API + Admin live-preferred; live GMX login still mock-until-verified)

**Work items**

- [DONE] Staff `GET/POST users/{user}/mailbox/messages*` handlers — `StaffMailboxController`, `api/routes/api.php`
- [DONE] Message persistence + inbound sync port (`GmxImapSync`, sync jobs/route per `docs/prd/PRD.md` Q8)
- [MOCK_OK] Local/fake inbound injector for dev/tests before live GMX IMAP verified
- [DONE] Staff-only read/reply authorization (Q9: Candidate does not operate inbox)
- [DONE] Admin mailbox inbox UI + reply live-preferred — `features/mailbox/api/service.ts` (mock hybrid fallback only)
- [REFACTOR] Ensure Admin fixtures never use `@apply.joinera.de`

**Acceptance**

- [x] Inbound fake message appears on Staff mailbox for Candidate GMX address (`MailboxTest` sync/list)
- [x] Candidate has no token/login; Staff/Admin only list/reply
- [ ] Reply sends via **verified live** GMX SMTP using stored credentials; failure is visible to Staff (adapters exist; live login mock-until-verified)

**Out of scope**

- AI auto-send without Staff path (Phase 6)
- Applicant self-serve webmail

---

### Phase 6 — AI-assist then Staff inbox handoff

- **Goal:** Easy employer threads get a fast AI draft; hard cases escalate cleanly to Staff.
- **Priority:** P1
- **Depends on:** Phase 5
- **Status now:** not started

**Work items**

- [MISSING] Message classification + draft generation port (vendor MOCK_OK)
- [MISSING] States: `ai_drafted` / `needs_staff` / `staff_replied` (exact names TBD — stop and ask before inventing if conflicting with PRD)
- [MISSING] Staff UI: accept/edit/send AI draft; claim hard thread
- [MOCK_OK] AI engine itself until quality bar is proven
- [MISSING] Guardrails: no silent send on interview/offer without Staff policy lock

**Acceptance**

- [ ] Routine reply can be drafted without Staff typing from scratch
- [ ] Hard/sensitive thread always lands in Staff queue
- [ ] Audit/trace shows AI vs Staff actor on outbound

**Out of scope**

- Fully autonomous negotiation without Staff
- Training proprietary models in-house

---

### Phase 7 — Production hardening

- **Goal:** Hybrid path is safe, paced, measurable, and cost-aware enough to love at real volume.
- **Priority:** P1–P2
- **Depends on:** Phases 3–6 as relevant
- **Status now:** partial (GMX adapters + Candidate delete clear done; apply/ops/PDF/TTL open)

**Work items**

- [DONE] GMX credential storage + IMAP sync + SMTP send adapters — `AttachGmxMailbox`, `GmxImapSync`, `GmxSmtpMailer` (live login still mock-until-verified; failed login/sync → Staff alert still open)
- [MISSING] Apply rate limits / anti-block caps enforced in production config — API P0-14
- [MISSING] Ops reports API — API P0-20; Admin reports currently MOCK_OK
- [MISSING] PDF per-apply pipeline — API P0-13 (needed for real submits)
- [DONE] Clear stored mailbox messages + credentials on Candidate delete — `ApplicantTest` delete cases
- [MISSING] `[uncertain]` Message retention TTL — API P0-21 / Q11 still open — do not invent TTL
- [REFACTOR] Empty `joinera.sites.v1` until Q12 decided — `api/config/joinera.php`
- [REFACTOR] Remove deprecated `StartMailbox` / registrar leftovers from mailbox code (PRD Q8 GMX attach is v1)

**Acceptance**

- [x] GMX credential path documented + fail-closed when unset/invalid (`AttachGmxMailbox`, Admin attach; passwords never listed)
- [ ] Ops report shows queue/blocks/mailbox health without payment/price copy
- [x] GMX passwords never shown as plain text in list APIs

**Out of scope**

- Commercial pricing UI, Mailgun+domain auto-provision, Gmail OAuth as core identity

---

## Refactor queue (ordered)

1. **Admin phases “seekers” wording** — Why: product language is Candidate. Risk: copy/IA confusion. Evidence: `docs/admin/IMPLEMENTATION_PHASES.md`.
2. **Deprecated `StartMailbox` / registrar leftovers** — Why: GMX attach (`AttachGmxMailbox`) is the v1 path; create must stay pending until Admin credentials. Risk: agents rewire create→`StartMailbox`. Evidence: `StartMailbox.php` `@deprecated`.
3. **Align admin ApplicationStatus with API enum on first Application ship** — Why: contract lock now includes `matched` + `preparing_docs`; mock may still omit them. Risk: Staff UI breaks on swap. Evidence: `ops-queue/api/types.ts` vs API P0-12 / PRD 1.7.
4. **SiteIdentity `paused` vs BlockEvent** — Why: pause flag exists without event/alert writers. Risk: readiness says blocked with no Staff queue item. Evidence: readiness service + missing BlockEvent.
5. **Staff operators UI still mock** — Why: Admin staff create/list does not call `POST /api/v1/staff-users`. Risk: dual operator stores. Evidence: `accounts.server.ts`, `SEAM.md`.

---

## Missing queue (ordered)

Do next = top.

1. `Application` + Match → Application **auto-queue** (Phase 3 / API P0-10 + P0-12; not P0-11 swipe)
2. Apply job + limits + Mode B submit port with mocked worker (Phase 3 / P0-14–16)
3. BlockEvent + alerts + break-glass/manual-complete/audit API (Phase 4 / P0-17–18)
4. AI draft + Staff handoff states (Phase 6)
5. Ops reports + PDF pipeline + Q11 retention TTL (Phase 7 remaining; live GMX login stays mock-until-verified)

---

## Do-not-build-yet

- Gmail / Google connect as the **core** apply identity
- Mailgun + per-Candidate domain auto-provision (not v1)
- Mass server-side apply **without** pause on CAPTCHA/login
- Captcha-solving / ban-evasion tooling
- Candidate self-serve ops panel (queue, break-glass, raw inbox ops)
- Assist-only “open Apply link” as the **LVP love path** (fallback decision only — not Phase 1 destination)
- Shared `@apply.joinera.de` (or any Joinera-branded apply domain)
- Payment / price / “free” copy in Staff or Candidate apply flows
- Inventing Q12 site brand list or Q11 retention TTL without PM lock
- Marking Admin screens DONE because UI exists while `SEAM.md` mocks back them

---

## Agent update protocol

When a Cursor agent finishes work that touches Hybrid apply/email:

1. Update **Current snapshot** row(s): flip status, set Evidence path(s), adjust Notes.
2. Check/uncheck the matching Phase work item tag (`[DONE]` / `[REFACTOR]` / `[MISSING]` / `[MOCK_OK]`).
3. If a MISSING item becomes DONE, remove it from **Missing queue** (or strike-through with date).
4. If new debt appears, append to **Refactor queue** with why + risk + evidence.
5. Set **Last evidence pass** date at the top to today (`YYYY-MM-DD`).
6. Do **not** rewrite `docs/api/IMPLEMENTATION_PHASES.md` or `docs/admin/IMPLEMENTATION_PHASES.md` unless the task explicitly says so; link/cite them instead.
7. Do **not** mark MOCK_OK items DONE until the real API/provider is wired and verified.
8. If blocked by open questions (Q12/Q11/Q18/vendor), write `[uncertain]` and stop rather than inventing policy.

---

## Evidence index (quick)

| Topic | Paths |
|-------|--------|
| API routes | `api/routes/api.php` |
| Applicants API | `api/app/Http/Controllers/ApplicantController.php`, `api/tests/Feature/ApplicantTest.php` |
| Mailbox config | `api/config/joinera.php` |
| Domain/address rules | `api/app/Support/GmxAddressValidator.php`, `api/app/Services/AttachGmxMailbox.php` (legacy: `StartMailbox.php` deprecated) |
| GMX IMAP/SMTP | `api/app/Services/Gmx/GmxImapSync.php`, `GmxSmtpMailer.php` |
| Staff mailbox messages | `api/app/Http/Controllers/StaffMailboxController.php`, `api/tests/Feature/MailboxTest.php` |
| Admin seam | `admin/src/lib/mock/SEAM.md` |
| Admin API client | `admin/src/lib/api/joinera-client.server.ts` |
| Admin nav | `admin/src/config/nav-config.ts` |
| Mock stores | `admin/src/constants/mock-api-users.server.ts`, `mock-api-ops-queue.server.ts`, `mock-api-mailbox.server.ts`, `mock-api-ops-reports.server.ts` |
| Ops contracts | `admin/src/features/ops-queue/api/types.ts` |
| API phase plan | `docs/api/IMPLEMENTATION_PHASES.md` (P0-01…P0-21) |
| Admin phase plan | `docs/admin/IMPLEMENTATION_PHASES.md` (A0-01…A0-11; hybrid note at top) |
| Mailbox product lock | `docs/prd/PRD.md` Q8 (GMX) |
