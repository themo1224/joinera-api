# API implementation phases

**Status note (2026-11-11):** `api/routes/api.php` exists and is registered from `bootstrap/app.php`. Sanctum auth, profile/CV/parse/priorities/approval, GMX mailbox attach/sync/reply, site identities, readiness, and intake listings (P0-09 PRD minimum fields) are implemented through roughly P0-01…P0-09. Staff applicant directory create/list/show was added for Admin↔API Phase 2 (`GET/POST /api/v1/applicants`, `GET /api/v1/applicants/{user}`). Match + Application auto-queue (P0-10 / P0-12) remain the backlog. **P0-11 Recommended swipe is dropped / superseded by PRD 1.7** — do not build it. The historical “skeleton check” paragraph is obsolete — do not treat missing `api.php` / Sanctum as current truth.

Still open: Q17, Q18, Q31, Q33, mailbox retention TTL, and exact apply local-part pattern (PM). Q12 v1 boards locked: `ausbildung.de`, `azubiyo`, `arbeitsagentur.de`. v1 mail = GMX (Admin-pasted credentials). STACK_AND_COSTS: PostgreSQL, Redis queues, Sanctum, S3-compatible files, Playwright Node.

P0-12 sits before PDFs because a tailored PDF is per application. It only stores the pipeline. Submit, retries, and anti-block stay after PDFs.

## Sequence

1. P0-01 — Signup, session, and role guards
2. P0-02 — CV upload and structured profile
3. P0-03 — CV parse into the editable profile
4. P0-04 — Apply priorities
5. P0-05 — One-time profile approval
6. P0-06 — Per-user apply mailbox
7. P0-07 — Per-user site identity
8. P0-08 — Identity readiness
9. P0-09 — MIX intake for jobs and Ausbildung
10. P0-10 — Auto-queue matches (hard bans C/E/D)
11. P0-11 — Recommended matches + swipe (**dropped / superseded — PRD 1.7**)
12. P0-12 — Application status pipeline
13. P0-13 — Per-apply PDF by job category
14. P0-14 — Human-like anti-block limits
15. P0-15 — Apply queue, idempotency, and retries
16. P0-16 — Mode B submit with no per-job approval
17. P0-17 — Block alert and alternate identity
18. P0-18 — Staff Plan B break-glass and audit
19. P0-19 — User reports
20. P0-20 — Admin/Staff ops reports
21. P0-21 — Self-delete and provisioned mailbox delete

## Phases

### P0-01 — Signup, session, and role guards
- Outcome: A person can register only as User, sign in, and call `/api/v1` routes that accept or reject User, Staff, and Admin.
- Feature IDs: 01, 24.
- Depends on: none.
- In scope: Public signup creates `role=user` only. Login and logout. Authenticated `GET /api/v1/me` returns id, name, email, role. An existing Admin creates Staff or Admin with `POST /api/v1/staff-users` (name, email, password, role). Roles are exactly `user`, `staff`, `admin`. Staff and Admin are not self-serve. First Admin in tests comes from the user factory, not from signup. Passwords stay hashed. Login is rate-limited. Sessions or tokens are the panel credential; `docs/prd/STACK_AND_COSTS.md` names Sanctum, which is not installed. Error codes: `validation_failed`, `invalid_credentials`, `forbidden`.
- Out of scope: Profile fields, mailbox, site logins, email verification, password-reset product flow, panels. No response text about price, payment, subscription, contracts, or the service being free.
- Open questions: Q12, Q17, Q18, Q31, and Q33 do not block auth. Q7, Q8, Q9, Q20, and Q-Commercial are decided and do not change this phase.
- Likely touch: existing `api/bootstrap/app.php`, `api/app/Models/User.php`, `api/database/factories/UserFactory.php`. New migration for `users.role` (do not rewrite `0001_01_01_000000_create_users_table.php`). New `api/routes/api.php`. New controllers, requests, and feature tests under `api/app/` and `api/tests/Feature/`. Follow-up plan stops and asks before `composer require`.
- Acceptance:
  - Pass: signup with name, email, and password returns a User and `GET /me` shows `role=user`.
  - Fail: signup that sends `role=admin` is 422 and creates no admin.
  - Fail: duplicate email is 422.
  - Fail: wrong password is 401 `invalid_credentials`.
  - Fail: User calling `POST /api/v1/staff-users` is 403.
  - Pass: Admin creates a Staff user; that Staff user receives 403 on `POST /api/v1/staff-users`.
  - Pass: Admin creates an Admin.
  - Fail: a burst of failed logins is 429.
- Plan ready when: the follow-up plan can add role-gated `/api/v1` auth without reading the product journeys.

### P0-02 — CV upload and structured profile
- Outcome: A User can store one CV file and the named structured fields, and the profile is incomplete until both exist.
- Feature IDs: 02.
- Depends on: P0-01.
- In scope: `GET/PUT /api/v1/profile` and `POST /api/v1/profile/cv`. Named fields the docs actually use: `industry`, `city`, `province`, `language`. CV file is required as a stored asset (`CvAsset`: owner, disk path, original name, mime, size). Completeness is true only when a CV asset exists and industry, city, province, and language are non-empty. User reads and writes only their own profile. Other users get 404. Staff/Admin may read completeness and the same field names for a user, not another user’s raw file in this phase. Replace CV keeps one current asset and drops the previous file from storage. Allowed upload: PDF (the only document type the product allows). `PUT` with an empty named field is rejected and does not clear a previously stored value.
- Out of scope: Parsing, priorities, matching, generated cover letters.
- Open questions: None name the rest of the CV form. Do not add further required fields. Q20 does not block storage. Q11 does not block; no retention TTL.
- Likely touch: new profile and CV paths under `api/app/`, `api/database/migrations/`, `api/tests/Feature/`. Existing `api/config/filesystems.php` only if the disk name must be read; do not change `.env`.
- Acceptance:
  - Fail: User `PUT` with industry and no CV leaves completeness false.
  - Fail: CV upload without the four named fields leaves completeness false.
  - Pass: PDF CV plus all four fields makes completeness true.
  - Fail: non-PDF upload is 422 and stores nothing.
  - Fail: another User’s `GET /profile` by swapping ids is 404.
  - Fail: empty `language` on a complete profile is 422 and the old language remains.
  - Pass: a second PDF replaces the stored asset; only one current CV remains.
- Plan ready when: a later parse plan can fill these fields without inventing a second profile.

### P0-03 — CV parse into the editable profile
- Outcome: A User can run parse on their current CV, see the named fields filled, and save edits after parse.
- Feature IDs: 03.
- Depends on: P0-02.
- In scope: `POST /api/v1/profile/parse` reads the current `CvAsset` through a parser port and writes industry, city, province, and language only when the parser returns them. User then `PUT`s corrections. Parse failure does not wipe existing fields. Status on the profile: `unparsed`, `parsed`, `parse_failed`. A second parse replaces parser-owned values that the user has not edited since the previous parse; user-edited fields stay. No vendor is named in the PRD. Tests use a fixture parser. The follow-up plan stops and asks before any paid parser SDK. Unreadable CV returns `parse_failed` and 422 `cv_unreadable`.
- Out of scope: Cover letters, apply PDFs, matching, choosing an LLM vendor.
- Open questions: Q20 does not block profile parse. It applies to per-apply documents.
- Likely touch: new parser port and feature tests under `api/app/` and `api/tests/`. No new package.
- Acceptance:
  - Pass: fixture parser fills empty named fields and status becomes `parsed`.
  - Fail: unreadable CV returns 422, status `parse_failed`, and prior industry remains.
  - Pass: User `PUT` changes city; a second parse does not overwrite that city.
  - Fail: User with no CV gets 422 and no parse status change.
  - Fail: another User cannot parse this user’s CV (404).
  - Pass: Staff token is 403 on `POST /profile/parse`.
- Plan ready when: the parse plan can ship the port, the fixture, and the edit lock without a vendor contract.

### P0-04 — Apply priorities
- Outcome: A User has apply priorities in the order industry, then city, then province, and can reorder only those three.
- Feature IDs: 04.
- Depends on: P0-02.
- In scope: `GET/PUT /api/v1/profile/priorities`. On first read, persist the default order `industry`, `city`, `province`. `PUT` accepts a permutation of those three keys only. Emit `profile.priorities_changed` after a saved change. User cannot read or write another user’s priorities.
- Out of scope: Scoring, queue, recommended. The matcher consumes the event later.
- Open questions: None. The default order is locked.
- Likely touch: new priority persistence and tests. Existing profile code from P0-02.
- Acceptance:
  - Pass: first `GET` returns industry, city, province in that order.
  - Pass: `PUT` with province, industry, city persists that order.
  - Fail: a list with a fourth key or a duplicate is 422 and the stored order stays.
  - Fail: another User’s `PUT` is 404.
  - Fail: Staff `PUT` on their own session against a User id is 403.
- Plan ready when: the matcher plan can sort signals from this stored list alone.

### P0-05 — One-time profile approval
- Outcome: Staff or Admin approve a User’s profile once, and later edits do not send it back to pending.
- Feature IDs: none of 01–26. This is decided Q7, formerly P1 profile approval.
- Depends on: P0-02.
- In scope: Profile approval state starts `pending`. `POST /api/v1/users/{user}/profile/approvals` by Staff or Admin sets `approved` once and stores actor and time. A second call stays `approved` and does not add a second requirement. `PUT` profile or parse after approval stays `approved`. User `GET /api/v1/profile` includes `approval=pending|approved`. User cannot approve themselves. This phase does not dequeue applies. No payment or “free” field exists on the profile.
- Out of scope: Approving every apply, approving documents, matching.
- Open questions: Q7 is decided as `once`. Q18 does not block.
- Likely touch: profile persistence from P0-02, new approval route and tests.
- Acceptance:
  - Pass: a new complete profile reads `approval=pending`.
  - Pass: Staff approval makes `GET` return `approved`.
  - Pass: a later `PUT` of city leaves `approval=approved`.
  - Fail: User `POST` of their own approval is 403 and the state stays `pending`.
  - Fail: a second Staff approval does not create a second pending cycle.
  - Pass: Admin may approve. A User calling another user’s approval route is 403.
- Plan ready when: the apply queue can refuse `pending` and allow `approved` without asking Q7 again.

### P0-06 — Per-user apply mailbox (+ per-user domain)
- Outcome: Each User has at most one Joinera-owned apply mailbox on **that user’s own ordinary domain** (prefer `.de`), never shared, and no Gmail account is created. Domain is not Joinera-branded and has no landing site in v1.
- Feature IDs: 05.
- Depends on: P0-01.
- In scope: `MailboxIdentity` bound to one user, storing `domain`, `address`, and state. The mailbox and domain registration belong to Joinera. Unique address and unique domain across users once reserved. `POST /api/v1/mailbox` (or Admin create path) starts setup through ports: `DomainRegistrar` (suggest/check/purchase/DNS) + `MailboxProvider` (Mailgun domain verify + inbound readiness). `GET /api/v1/mailbox` returns state, domain, and address only. States: `pending`, `ready`, `failed`. Port methods here: start, mark ready, mark failed. `deleteProvisioned` is not called. Config: `mailbox.mode` (`managed_provider` or null), `mailbox.provider` (`mailgun_eu` or null), registrar credentials/config (or null). There is **no** shared `mailbox.domain` like `apply.joinera.de`. If mode/provider/registrar is unset, `POST` returns 409 `mailbox_provider_unset` (or `domain_registrar_unset`) and makes no outbound call. Default address shape (until PM locks otherwise): personal-looking local-part on the purchased domain, lowercase ASCII (ä→ae, ö→oe, ü→ue, ß→ss; only letters, digits, dot, hyphen). Example: domain `joerg-mueller.de` → `joerg.mueller@joerg-mueller.de`. If preferred domain is taken, try alternates then `failed` if exhausted. Missing first or last name: 422 `name_required`, nothing stored. Tests move `pending` to `ready` only through port fakes. There is no Gmail, Google OAuth, or user-supplied mailbox mode. User routes for reading or replying to messages are 404. Staff and Admin reading mail is P1, not this phase. Staff/Admin `GET /api/v1/users/{user}/mailbox` returns state, domain, and address, never secrets or registrar credentials.
- Out of scope: Staff inbox UI, site passwords, account delete, break-glass reveal, real Mailgun/registrar HTTP adapters (fake ports OK), inbound webhook, live DNS at a real registrar.
- Open questions: Q8 decided 2026-11-07 (Mailgun EU + per-user non-Joinera domain). Exact local-part pattern still open with PM. This phase ships ports + fakes; real Mailgun/registrar adapters and inbound webhook come with Staff inbox / later wire-up. Q9 decided: users cannot read or reply. Q11 ownership decided; retention TTL stays open.
- Likely touch: new `api/config/joinera.php`, mailbox + domain provision code, feature tests. No Google package. Stop and ask before adding a provider SDK.
- Acceptance:
  - Fail: `POST` while the provider or registrar is unset returns 409 and stores no address/domain.
  - Fail: a request that selects Gmail, a user-owned address, or a Joinera-branded shared domain (`apply.joinera.de`) is 422 and stores nothing.
  - Pass: with port fakes, two users cannot store the same address or the same domain; the second gets 409.
  - Pass: owner `GET` shows domain + address and not a credential or message body.
  - Fail: another User’s mailbox `GET` is 404.
  - Fail: `GET /api/v1/mailbox/messages` and any reply route are 404 for a User.
  - Pass: Staff metadata `GET` has no secret and no message body.
  - Pass: profile name "Jörg Müller-Weiß" with fake free domain yields something like `joerg.mueller@joerg-mueller.de` (not `@apply.joinera.de`).
  - Pass: when preferred domain is taken, fake tries an alternate or ends in `failed` without inventing a Joinera subdomain.
  - Fail: missing last name returns 422 `name_required` and stores nothing.
- Plan ready when: identity readiness can treat `ready` and `failed` without a Gmail integration or shared Joinera apply domain.

### P0-07 — Per-user site identity
- Outcome: A User can store their own login for a configured site, and that login cannot be reused by another user.
- Feature IDs: 06.
- Depends on: P0-01.
- In scope: `SiteIdentity` unique on user + site. `POST /api/v1/site-identities` with `site` and `site_login` plus a secret. Secret encrypted at rest and absent from every JSON response. `GET /api/v1/site-identities` lists site, site_login, and state (`healthy` or `failed`) for the caller. Same `site` + `site_login` on a second user is 409 `shared_site_login`. Unknown site is 422. `sites.v1` empty: `POST` returns 409 `sites_unconfigured` and writes nothing. Intake must not read these rows. Staff/Admin list metadata only.
- Out of scope: Browser sessions, apply submit, break-glass plaintext, block pauses.
- Open questions: Q12 blocks the real site list. Empty `sites.v1` is the seam, not a guess of DE sites. Q18 does not block storage.
- Likely touch: `api/config/joinera.php`, new identity code and tests. Follow-up plan stops and asks before a KMS SDK; Laravel encryption is the local stand-in.
- Acceptance:
  - Fail: empty `sites.v1` yields 409 and zero rows.
  - Pass: with a fixture site id in config, the owner can store a login and `GET` omits the secret.
  - Fail: a second user posting the same site and site_login gets 409.
  - Fail: a site key outside `sites.v1` is 422.
  - Fail: another User cannot `GET` this user’s identities (404).
  - Pass: replacing the secret for the same user and site keeps one row.
- Plan ready when: apply and break-glass can address a user×site row without a shared password pool.

### P0-08 — Identity readiness
- Outcome: A User can read one readiness value: `ready`, `blocked`, or `needs_staff`.
- Feature IDs: 07.
- Depends on: P0-06, P0-07.
- In scope: `GET /api/v1/identity/readiness` for the caller. Rules: `needs_staff` when mailbox is missing, `failed`, or the provider is unset; also when `sites.v1` is empty or the user has no `healthy` site identity. `blocked` when every site identity the user has is paused. `ready` when mailbox is `ready` and at least one site identity is `healthy` and not paused. Pause is an input flag defaulting to false until P0-17 writes it. User cannot `PUT` readiness. Response includes a `reason` code from this list only: `mailbox_provider_unset`, `mailbox_not_ready`, `sites_unconfigured`, `no_healthy_site`, `all_sites_paused`, `ready`. Profile approval is a separate flag from P0-05 and is not folded into this status.
- Out of scope: Creating the pause, sending Staff alerts, provisioning a mailbox, approving the profile.
- Open questions: Q7 is decided and lives in P0-05. Q8 vendor is Mailgun EU; this phase only reads mailbox state and still treats null config as `mailbox_provider_unset`. Q12 is still open.
- Likely touch: new readiness query and tests beside mailbox and site identity.
- Acceptance:
  - Fail: provider unset returns `needs_staff` and `mailbox_provider_unset`.
  - Pass: mailbox `ready` plus one healthy unpaused site returns `ready`.
  - Pass: mailbox `ready` and all sites paused returns `blocked` and `all_sites_paused`.
  - Fail: User `PUT /api/v1/identity/readiness` is 405.
  - Fail: another User cannot read this readiness (404).
  - Pass: Staff can `GET /api/v1/users/{user}/identity/readiness` for support; a User calling that staff path is 403.
- Plan ready when: apply gating can branch on `ready` without re-deriving mailbox rules.

### P0-09 — MIX intake for jobs and Ausbildung
- Outcome: A daily intake run adds only new job and Ausbildung listings from fixed DE boards; a duplicate refreshes `last_seen_at` only. Staff curated paste/import is **not a v1 product path**.
- Feature IDs: 08, 09.
- Depends on: P0-01.
- In scope: `Listing` with `source`, `external_id` nullable, `fingerprint`, `kind` (`job` or `ausbildung`), `category` nullable, title, company, city, url, `apply_url` nullable, `description` nullable, `language_requirement` nullable, `education_requirement` nullable, `country` nullable (persisted when provided), `posted_at` nullable, `first_seen_at`, `last_seen_at`. Optional PRD fields are stored on create when the adapter/row provides them. Dedupe key: `source` + `external_id` when the external id exists, otherwise `source` + `fingerprint` (hash of title, company, city, url). Existing key updates `last_seen_at` only and does not emit `listing.created`. New row emits `listing.created` once. Country outside DE is dropped unless `sites.allow_non_de` is true; default false. Scheduler command walks `sites.v1`. Empty registry completes with a run row and zero listings. One site’s failure still runs the other sites. Each failure is stored on the run. The same site failing two scheduled runs in a row writes an Admin alert record. Legacy Admin-only `POST /api/v1/listings/import` may remain for tests/dev (same table and dedupe, row-level errors) but is **not** a v1 product path — do not delete without asking. Adapter port: `search`, `parseList`, `parseDetail`. Tests use a fixture adapter. Intake execution context has no user id and no `SiteIdentity`. Queues named `intake` and `alerts` are configured. Do not switch the app’s default queue connection in `.env`.
- Out of scope: Matching, apply browsers, real site adapters, non-DE as a default, Staff curated import as a product feature.
- Open questions: **Q12 decided:** `sites.v1` = `ausbildung.de`, `azubiyo`, `arbeitsagentur.de` (Bundesagentur Jobbörse + Ausbildungssuche). Fixture adapter is not registered there. Q33 is not answered. `sites.allow_non_de` defaults false. Q31 does not set a crawl SLO.
- Likely touch: existing `api/routes/console.php`. Listing schema + upsert; intake run and tests. Node Playwright is out of this plan; the port returns fixture JSON.
- Acceptance:
  - Pass: fixture crawl inserts a `job` and an `ausbildung` and emits `listing.created` twice; PRD optional fields round-trip on create when provided.
  - Pass: the same external id on a second run changes `last_seen_at` only and emits no create event.
  - Fail: a curated row with no `kind` is reported on that row and does not roll back a valid sibling row.
  - Fail: a non-DE fixture row is absent when `sites.allow_non_de` is false.
  - Fail: User `POST /listings/import` is 403.
  - Pass: site A throwing still persists a new listing from site B.
  - Pass: an intake payload that includes a user session id is rejected before insert.
- Plan ready when: the matcher can subscribe to `listing.created` and trust dedupe.

### P0-10 — Auto-queue matches (hard bans C/E/D)
- Outcome: Eligible Candidates (ready GMX + complete profile) are **auto-matched** and **auto-queued** for new listings. Staff does **not** pick the normal queue; Staff may **force-queue** a skipped job only.
- Feature IDs: 10, 12.
- Depends on: P0-02, P0-03, P0-04, P0-05, P0-06, P0-09.
- In scope: Recompute on `listing.created` and on profile or priority change for Candidates with complete profile + ready GMX. One `Match` per Candidate + listing. Rules filter first, then AI ranks inside the eligible set. **Hard never-match (auto):** listing outside Candidate **industry**; listing **not in Germany**; listing states a **Schulabschluss/degree** the Candidate lacks (`education_requirement`). Hard-ineligible rows are excluded / skipped and do not become applications. **Soft / Admin-listed only (do not auto-exclude yet):** language-level mismatch; city/province preference mismatch — surface for Admin/PM until Q-Match-Soft locks. Auto-promote eligible matches to `queued` (application create follows P0-12). Staff force-queue endpoint for skipped jobs. Queue name `match` is configured. This phase does not submit applications.
- Out of scope: Recommended swipe UI, Candidate login / Candidate match `GET`, PDF, browser submit, treating language or city as hard bans.
- Open questions: Q7 one-time profile approval is required before apply starts. Exact AI rank vendor/scoring stays open. Q17 does not block matching.
- Likely touch: new match code and tests. Consumers of events from P0-04 and P0-09. Staff/Admin force-queue + queue visibility APIs (not Candidate panel).
- Acceptance:
  - Pass: same industry, DE listing, education OK → auto `queued` for an eligible Candidate (ready GMX + complete + approved profile).
  - Fail: industry mismatch creates no auto-queue (hard ban).
  - Fail: non-DE listing creates no auto-queue (hard ban).
  - Fail: listing `education_requirement` the Candidate lacks creates no auto-queue (hard ban).
  - Pass: language mismatch alone does **not** hard-exclude (soft / Admin-listed only).
  - Fail: incomplete profile or non-ready GMX creates no match.
  - Pass: a second `listing.created` for the same pair keeps one match row.
  - Pass: Staff force-queue moves a skipped match into `queued`.
  - Fail: a queued match has no side effect that sets an application status to `submitted`.
- Plan ready when: the pipeline plan can open an application from auto-queue (and Staff force-queue) only.

### P0-11 — Recommended matches + swipe — **DROPPED / SUPERSEDED (PRD 1.7)**
- Outcome: **Not in v1.** PRD 1.7 removes Recommended swipe and Staff hand-picking the normal apply queue. System **auto-queues**; Staff force-queues skipped jobs only (see P0-10 / P0-12).
- Feature IDs: 11 (historical).
- Depends on: n/a — do not implement.
- In scope: None. Do not add `recommended` / `dismissed` surfaces, swipe routes, or Candidate match UI.
- Out of scope: Everything in this former phase.
- Open questions: Soft language/city remain Admin-visible lists until PM locks Q-Match-Soft — that is **not** a swipe product.
- Likely touch: None. Leave sequence number for historical Feature ID mapping only.
- Acceptance: N/A — agents must not build P0-11.
- Plan ready when: N/A.

### P0-12 — Application status pipeline
- Outcome: An auto-queued (or Staff force-queued) match becomes one application, and only legal status moves succeed. Pipeline includes `preparing_docs`.
- Feature IDs: 20.
- Depends on: P0-10.
- In scope: Create `Application` when a match is auto-queued (or Staff force-queues), unique on Candidate + listing + site (`site` is `listing.source`). Status pipeline: `matched → queued → preparing_docs → applying → submitted → reply_received → interview → rejected | offer`. `matched → queued` is automatic for eligible rows; Staff does not approve that step. System transitions include `queued` → `preparing_docs` (Q20 cover/CV PDFs) → `applying` → `submitted`. Any skip, any move backward, and any client `POST` of a status is rejected. Enum includes the full pipeline; no public Candidate writer. `ApplyAttempt` is not written here. Emit `application.status_changed` with from, to, and application id. Staff/Admin `GET` applications for ops. Idempotent create: the same pair returns the existing id.
- Out of scope: Retries, PDF generation internals (P0-13), browser submit, Staff manual complete, mailbox delete on `rejected`, Recommended swipe.
- Open questions: Post-submit statuses have no automatic writer in this phase. Staff mailbox read/reply is already live (GMX); this phase still adds no public writer for `reply_received`, `interview`, `rejected`, or `offer`.
- Likely touch: new application persistence and tests. Match writer from P0-10.
- Acceptance:
  - Pass: a new auto-queued match yields one application in `queued`, and a repeat yields the same id.
  - Pass: Staff force-queue of a skipped match yields one application.
  - Fail: system move `queued` → `submitted` (skipping `preparing_docs` / `applying`) is rejected and the status stays `queued`.
  - Pass: system move `queued` → `preparing_docs` → `applying` → `submitted` emits status events in order.
  - Fail: Candidate (role `user`) `POST /api/v1/applications/{id}/status` is 403.
  - Fail: another Candidate’s application `GET` is 404.
  - Pass: moving an application to `rejected` is impossible through the public API, and the Candidate’s mailbox row is untouched.
- Plan ready when: PDF and apply plans can key off application id and status (`preparing_docs` before browser apply).

### P0-13 — Per-apply PDF by job category
- Outcome: An application in `applying` gets a new cover, and a new CV only when the listing category differs from the profile industry.
- Feature IDs: 15, 16.
- Depends on: P0-12, P0-02, P0-09.
- In scope: Docs job on queue `docs`. Input is application id. Compare `profile.industry` with `listing.category`. Equal and both known: reuse the stored base CV PDF and generate a cover PDF only. Different and both known: generate a new CV PDF and a cover PDF. Either value missing: stop with `category_unknown`, no file, no guessed category. PDF bytes only. `DocArtifact` stores application id, kind (`cv` or `cover`), rule used (`cover_only` or `cv_and_cover`), cache hit, and internal cost fields that may be null. Those cost fields are never returned to a User and are never described as a price. Template fill that reuses the base CV sets `cache_hit=true`. Regenerating is rate-limited. Owner can download their PDF. No per-document approve route. Non-PDF output is discarded and the job fails `non_pdf`. No response mentions payment or that the service is free.
- Out of scope: Submitting the file to a site, picking a model vendor, Staff document approval, naming a price.
- Open questions: Q20 is decided. Do not add a global mode switch that ignores category.
- Likely touch: `api/config/joinera.php`, new doc job and tests, filesystem disk already used for CVs, listing category from P0-09. Stop and ask before an LLM or PDF package.
- Acceptance:
  - Pass: industry `IT` and listing category `IT` stores a new cover and reuses the base CV.
  - Pass: industry `IT` and listing category `nursing` stores a new CV and a new cover.
  - Fail: a listing with no category finishes `category_unknown` and stores nothing.
  - Fail: a generator that returns HTML stores nothing and fails `non_pdf`.
  - Fail: another User’s download is 404.
  - Fail: `POST /api/v1/applications/{id}/docs/approve` is 404.
  - Fail: the User download response contains no price, payment, or free flag.
  - Fail: User cannot generate docs for an application still in `queued` (409).
- Plan ready when: the submit plan can require the PDFs for that category rule, or a `category_unknown` stop.

### P0-14 — Human-like anti-block limits
- Outcome: A worker either receives an apply slot inside configured limits or a specific denial a User can read.
- Feature IDs: 17.
- Depends on: P0-07, P0-09.
- In scope: Limiter port checked before apply work. Layers, all config-null by default: `caps.daily`, `caps.weekly`, max concurrent applies per user, min/max jitter seconds per user×site, max applies per hour per user×site, cooldown after pause, max concurrent sessions per site, max applies per minute per site, one intake wave per site per day. Null layer: `GET /api/v1/apply-limits` shows that layer `configured=false` and the limiter does not invent a number. Tests set numbers and prove denial. Jitter delay is a value inside the configured band, and two draws are not assumed equal; a fixed delay equal to only the minimum on every call fails the test when the band has a range. Browser profile key is `user + site` and is never a global key. Intake crawl ticket cannot carry a `SiteIdentity` id. Cooldown denial code `site_cooldown`. Cap denial code `cap_reached`. Queues are not drained here.
- Out of scope: CAPTCHA solving, Playwright stealth plugins, panel caps screen (P1 feature 04), live site traffic.
- Open questions: Q17 is not answered. Null caps are inactive layers, not an unlimited product policy. Q18 is not this phase. Q31 sets no pool size; do not add a 1000-worker design.
- Likely touch: `api/config/joinera.php`, new limiter and `GET` route, tests. Redis is the target counter store; tests may use the array cache. Stop and ask before changing `QUEUE_CONNECTION` or adding a Redis client.
- Acceptance:
  - Pass: null caps return `configured=false` and a slot request is allowed.
  - Fail: with `caps.daily=1`, the second slot for that user on the same day is `cap_reached`.
  - Fail: a delay outside the configured jitter band is rejected by the limiter’s own check.
  - Fail: an intake ticket that references a `SiteIdentity` is denied `intake_user_session`.
  - Pass: profile key for user 1 site A differs from user 2 site A and from a key with no user.
  - Fail: during cooldown the next slot is `site_cooldown`.
  - Fail: another User cannot read this user’s `GET /apply-limits` (404).
- Plan ready when: the queue plan can call one slot method and surface its denial code.

### P0-15 — Apply queue, idempotency, and retries
- Outcome: A queued application is claimed once, retried only when the failure is retryable, and never duplicated.
- Feature IDs: 19.
- Depends on: P0-05, P0-08, P0-12, P0-14.
- In scope: `SubmitApplicationJob` on queue `apply`. Claim moves `queued` → `applying` only when profile approval is `approved`, readiness for that site is `ready` or the user-level readiness is `ready` with that site healthy, the limiter allows a slot, and the application is not already `submitted`. Otherwise the job releases or stops with `profile_not_approved`, the limiter code, or the readiness code, and leaves status `queued`. `ApplyAttempt` records `retryable`, `challenge`, `hard_fail`, or leaves the submit result to P0-16. Retryable: same application id, backoff release, no second application. Already `submitted`: job returns without a new attempt. Hard fail (`job_closed`): attempt `hard_fail`, no further release, status stays `applying`. Do not write `rejected`. Natural idempotency key is user + listing + site. A client enqueue, if called, requires header `Idempotency-Key`; the same key returns the original application. There is no per-job approval route.
- Out of scope: Browser submit, PDF generation, Staff alerts, mapping hard fail onto `rejected`.
- Open questions: Q17 is enforced only through P0-14. Q18’s challenge policy is P0-17; this phase only stores `challenge` and stops. Q12 does not add a site adapter here.
- Likely touch: new job and attempt persistence; application transitions from P0-12; limiter from P0-14.
- Acceptance:
  - Pass: one queued application becomes `applying` and a second concurrent claim does not create another application.
  - Fail: readiness `needs_staff` leaves status `queued` and writes no `submitted`.
  - Fail: approval `pending` leaves status `queued` with `profile_not_approved`.
  - Pass: a retryable failure releases the same application id once and keeps a single row.
  - Pass: a job for an already `submitted` application adds no attempt.
  - Fail: `job_closed` stores `hard_fail`, does not release, and status is not `rejected`.
  - Fail: enqueue without `Idempotency-Key` is 422.
  - Fail: User `POST` that would force `applying` is 403.
- Plan ready when: the submit plan can continue from a single `applying` attempt.

### P0-16 — Mode B submit with no per-job approval
- Outcome: The system submits one Candidate’s application and reaches `submitted` only after a confirmation, with no per-job approval.
- Feature IDs: 13, 14.
- Depends on: P0-07, P0-13, P0-15.
- **Product lock (PRD 1.8 / 2026-11-18):** Mode B target = **company apply URL** via generic AI form-discover + Playwright. Boards are intake only. Interim code may still use board adapters; new work MUST prefer company-site path.
- In scope: Extend `SubmitApplicationJob` after the claim. Generate docs via P0-13. If docs return `category_unknown` or `non_pdf`, stop, leave `applying`, do not call the browser. Call the worker with `user_id`, `site_id` (listing source for observability), and `application_id`. Worker opens **company apply URL**, discovers fields, maps Candidate+PDFs, fill/upload/submit, parse confirmation. Missing company URL → fail closed / Staff `external_apply` handoff (not board Easy-Apply). Fixture adapter in tests. Success: status `submitted`, store confirmation reference on the attempt. The worker must refuse a session whose user id does not match the application. Per-job and per-document approval do not exist. One-time profile approval is already required by P0-15. `POST /api/v1/applications/{id}/approve` is 404. Challenge result: attempt `challenge`, no `submitted`, no loop. Retryable browser errors use the P0-15 retry path. Board `SiteIdentity` is **not** required to claim company-site apply.
- Out of scope: Staff notification, alternate identity, CAPTCHA bypass, per-employer coded adapters.
- Open questions: Q18’s minimum in this phase is stop on challenge. Richer playbooks stay open with Q18. Q20 is the category rule from P0-13.
- Likely touch: job from P0-15, doc service from P0-13, Mode B port + company-site form agent + fixture test. Playwright Node is a separate process.
- Acceptance:
  - Pass: fixture confirmation moves the application to `submitted` and stores the confirmation reference.
  - Fail: `category_unknown` does not call the worker.
  - Fail: a worker result for a different user id does not submit and returns `identity_mismatch`.
  - Fail: the per-job approve route is 404.
  - Fail: challenge result leaves status not `submitted`.
  - Pass: a retry after `submitted` does not call the worker again.
  - Fail: the worker input contains no second user’s session id.
- Plan ready when: company-site form agent can be extended without inventing one adapter per employer.

### P0-17 — Block alert and alternate identity
- Outcome: A challenge pauses that user on that site, alerts Staff, and lets the user attach a replacement identity without deleting the mailbox.
- Feature IDs: 18.
- Depends on: P0-06, P0-07, P0-08, P0-16.
- In scope: On attempt `challenge`, write `BlockEvent` (user, site, application, observed kind `captcha`, `two_factor`, or `login_wall`). Pause that `SiteIdentity`. Recalculate readiness. Write an alert record on queue `alerts` for Staff. Do not retry that pair inside the cooldown from P0-14. `POST /api/v1/site-identities/{id}/alternate` by the owner creates a new pending identity for the same site and leaves the paused row in place. Mailbox row is not deleted. If `mailbox.mode` is null, a mailbox-replacement request returns 409 `mailbox_provider_unset` and the Staff alert still exists. Other healthy sites for the same user still return apply slots. User cannot delete the `BlockEvent` or clear the pause.
- Out of scope: Solving the challenge, guided rotation UI (P1), inbox.
- Open questions: Q18’s minimum is locked: detect, pause, alert, Staff or reprovision. Do not add a solver. The fuller CAPTCHA policy stays open. Q8 vendor is Mailgun EU; do not create a Gmail account on this path.
- Likely touch: site identity and readiness from earlier phases, new block and alert records, tests.
- Acceptance:
  - Pass: a challenge attempt creates one `BlockEvent`, pauses that site, and writes one Staff alert.
  - Fail: the paused user×site slot is `site_cooldown` or paused, and a second healthy site for that user is not paused.
  - Pass: alternate creates a new identity row and the mailbox address is unchanged.
  - Fail: mailbox replacement while mode is null is 409, and the alert remains.
  - Fail: User `DELETE` of the block event is 403 or 405.
  - Fail: another User cannot post alternate on this identity (404).
  - Pass: application status is not `submitted` and is not `rejected` solely because of the challenge.
- Plan ready when: Plan B can find paused users from `BlockEvent` rows.

### P0-18 — Staff Plan B break-glass and audit
- Outcome: Staff or Admin can reveal one user’s site secret and mark the stuck application submitted, and every reveal has an audit row.
- Feature IDs: 21, 26.
- Depends on: P0-07, P0-12, P0-17.
- In scope: `POST /api/v1/site-identities/{id}/break-glass` with a reason. Caller role must be `staff` or `admin`. Rate-limited. The secret is returned only if `StaffAccessAudit` is stored in the same transaction (actor, target user, site, application id nullable, reason, time). If the audit write fails, the response has no secret. `POST /api/v1/applications/{id}/manual-complete` by Staff or Admin moves `applying` to `submitted` when a `BlockEvent` or `hard_fail` attempt exists, is idempotent when already `submitted`, and does not call mailbox delete. User is 403 on both routes. Audit `GET` is Staff/Admin only and contains no secret. `retention.staff_audit_ttl` null means no purge job.
- Out of scope: GDPR export, inbox, end-user credential display, inventing a retention period.
- Open questions: Q11 does not set a TTL. Null `retention.staff_audit_ttl` is not a legal answer. Q18 is already handled by the pause this phase finishes manually.
- Likely touch: site identity decryption path, new audit persistence, tests. Existing rate-limiter style from P0-01.
- Acceptance:
  - Fail: User break-glass is 403 and writes no audit.
  - Pass: Staff break-glass returns the secret and exactly one audit row for that call.
  - Fail: when the audit store throws, the response body has no secret and no partial audit.
  - Pass: manual complete from `applying` sets `submitted`; a repeat stays `submitted` and does not add a second confirmation.
  - Fail: manual complete does not remove the mailbox.
  - Fail: User `GET` of the audit list is 403.
  - Fail: break-glass over the rate limit is 429 and adds no audit.
- Plan ready when: ops can trace every plaintext read to one audit row.

### P0-19 — User reports
- Outcome: A User can read counts of their own pipeline and nothing about other users.
- Feature IDs: 22.
- Depends on: P0-12.
- In scope: `GET /api/v1/reports/me`. Counts by application status, including zeros. Includes `listing.kind` breakdown for `job` and `ausbildung`. Includes time from account creation to first `submitted` when both timestamps exist; otherwise null. No other user ids in the payload. Staff and Admin use the ops report, not this route, and receive 403 here. No AI-cost series.
- Out of scope: Ops funnel, cost dashboards (P1), CSV export, GDPR export (P1).
- Open questions: Q31 does not add targets. Success-metric targets in the PRD are TBD, so this route returns counts only.
- Likely touch: new report query and test. Applications from P0-12.
- Acceptance:
  - Pass: a user with no applications gets zeros and null time-to-first-submitted.
  - Pass: one `submitted` job and one `queued` ausbildung increment those two buckets only.
  - Fail: the payload has no other user’s id or email.
  - Fail: Staff token on this route is 403.
  - Fail: another user cannot pass a user id to this route (no such parameter; a forged query id is ignored or 422).
- Plan ready when: the user panel can render this JSON without a second query contract.

### P0-20 — Admin/Staff ops reports
- Outcome: Staff or Admin can read intake, queue, block, and doc-cost figures; a User cannot.
- Feature IDs: 23.
- Depends on: P0-09, P0-15, P0-17, P0-13.
- In scope: `GET /api/v1/ops/reports`. Intake: last run per site, new listing count, error count. Apply: count in `queued` and `applying`, count of `submitted` versus attempts with `hard_fail` or `retryable`. Blocks: open pause count per site and user ids that are paused. Funnel: counts by pipeline status. Doc cost: sum of stored minor-unit costs, plus a null when no artifact has a cost. No secrets, no mailbox tokens, no site passwords. User is 403.
- Out of scope: Break-glass action, any payment or price language, panel charts. Doc-cost figures are internal sums for Staff and are never shown to Users.
- Open questions: Q20 is not re-opened by the cost sum. Q12 empty registry returns an empty intake list, not fake sites.
- Likely touch: new ops query and test, reading run, attempt, block, and artifact tables.
- Acceptance:
  - Fail: User token is 403.
  - Pass: Staff token sees a site run with its new-count and a paused user id.
  - Pass: two doc artifacts with costs sum to the reported minor units.
  - Fail: the JSON contains no secret, password, or token field.
  - Pass: with no listings, intake is an empty list and queue counts are zero.
  - Fail: Admin response does not include another product’s guessed site name when `sites.v1` is empty.
- Plan ready when: an ops client can poll this route for the INTAKE_AND_APPLY admin list.

### P0-21 — Self-delete and provisioned mailbox delete
- Outcome: A User can delete their own account, and a provisioned mailbox is deleted only on that path.
- Feature IDs: 25.
- Depends on: P0-01, P0-06.
- In scope: `DELETE /api/v1/account` with confirmation equal to the account email. Mismatch is 422 and deletes nothing. Caller must be that User. Staff and Admin cannot call this route for another user. If the mailbox record is marked provisioned, call `MailboxProvider.deleteProvisioned` **and** `DomainRegistrar.release` (or equivalent: stop DNS/mail, cancel/abandon renew per policy) and remove the account only after both succeed. Provider/registrar failure: 409 `deletion_incomplete`, account, mailbox, and domain row remain; the job may retry. If the mailbox is absent or not provisioned, delete the local account and local mailbox/domain row without provider delete. After success, the session is invalid. A second call is 401. Site identities and applications for that user are removed with the account. `rejected` applications do not call `deleteProvisioned`. Provider delete is not available to break-glass. For Mailgun EU, `deleteProvisioned` deactivates receiving for that domain/address, deletes stored messages and attachments, and keeps a tombstone so the address is never reissued.
- Out of scope: Admin user-delete, GDPR export, user inbox, choosing the mailbox vendor, building a public landing on the domain.
- Open questions: Q8 is Mailgun EU + per-user domain. Delete calls provider/registrar ports only when the row is marked provisioned. Q11 retention TTL is still open. This phase adds no grace period and no export.
- Likely touch: mailbox port from P0-06, user deletion, feature tests. Do not edit `docs/prd/*`.
- Acceptance:
  - Fail: wrong confirmation is 422 and the user still authenticates.
  - Pass: a provisioned mailbox fake-delete success removes the user and a later `GET /me` is 401.
  - Fail: provider delete failure keeps the user and the mailbox, and returns 409.
  - Fail: setting an application to a terminal attempt or leaving it unsubmitted does not call `deleteProvisioned`.
  - Fail: Staff `DELETE /api/v1/users/{user}/account` is 404 or 403 and the mailbox remains.
  - Pass: a mailbox that was never provisioned deletes the local user and records zero provider delete calls.
  - Fail: break-glass response from P0-18 still does not offer a delete-mailbox action.
  - Fail: after delete, a new user with the same name does not receive the old address or the old domain.
  - Pass: successful delete invokes domain release as well as mailbox delete on the fakes.
- Plan ready when: a test can prove rejection never removes the mailbox/domain and self-delete does.

## Later

P1, not phased:

- Staff and Admin mailbox read and reply. Users never.
- Auto reply detection. Not decided.
- Caps UI. Q17 still open.
- CAPTCHA/2FA playbooks. Q18 still open.
- Guided identity rotation.
- Internal doc-cost views for Staff only. Never show money to Users.
- Offer → immigration handoff flag.
- More curated sources.
- GDPR export. Retention TTL still open.
- Staff claim/assign failed jobs.

Not in the product: subscription, checkout, contracts, and any copy that says the service is free.

P2, not phased: Mode A per-job approval, per-doc admin approval, non-DE sites (Q33), non-PDF, Farsi UI, full immigration CRM, employer portal, native apps, heavy ML ranking, shared multi-user profiles.

## Coverage check

| Feature id | Phase id |
| --- | --- |
| 01 | P0-01 |
| 02 | P0-02 |
| 03 | P0-03 |
| 04 | P0-04 |
| 05 | P0-06 |
| 06 | P0-07 |
| 07 | P0-08 |
| 08 | P0-09 |
| 09 | P0-09 |
| 10 | P0-10 |
| 11 | P0-11 |
| 12 | P0-10 |
| 13 | P0-16 |
| 14 | P0-16 |
| 15 | P0-13 |
| 16 | P0-13 |
| 17 | P0-14 |
| 18 | P0-17 |
| 19 | P0-15 |
| 20 | P0-12 |
| 21 | P0-18 |
| 22 | P0-19 |
| 23 | P0-20 |
| 24 | P0-01 |
| 25 | P0-21 |
| 26 | P0-18 |

Every P0 id 01–26 is placed once. None are unplaced.

## Done when

- The coverage table has each P0 id 01–26 once.
- Every phase passes the size rules.
- Order follows FEATURE_LIST build order: Auth/Profile → Email/Identity → Intake/Match → PDFs → Auto-apply/Anti-block → Plan B → Reports → Delete lifecycle. Those buckets are split. P0-05 is the one-time profile approval required before dequeue. P0-12 is the application ledger required by PDFs and does not submit.
- Q7, Q8 (Mailgun EU + per-user domain), Q9, Q11 ownership, Q20, and Q-Commercial are decided. Q12, Q17, Q18, Q31, Q33, local-part pattern, and retention TTL stay open.
- No application code was changed.
