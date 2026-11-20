# Admin panel implementation phases

**Status note (2026-11-09):** A0 mock track below is historical. Phase 2 hybrid seam is live for auth + applicants + mailbox directory identity — see `admin/src/lib/mock/SEAM.md` and `docs/prd/APPLY_EMAIL_LVP_PHASES.md`. Queue/blocks/break-glass/mailbox messages/reports remain mock. Product language is **applicant** (not “seeker”).

Original A0 rule was mock-only inside `admin/`. That rule is superseded for the live areas listed in `SEAM.md`. Staff and Admin share most ops screens; Admin-only gates are called out. Never show price, payment, subscription, or “free” copy. Applicants never use this panel.

## Sequence

1. A0-01 — Joinera shell and mock seam
2. A0-02 — Mock Staff/Admin auth and route guards
3. A0-03 — Applicants directory (users list + detail)
4. A0-04 — One-time profile approval
5. A0-05 — Queue, failures, and blocks ops
6. A0-06 — Break-glass, manual complete, and audit
7. A0-07 — Apply mailbox read and reply
8. A0-08 — Ops reports overview
9. A0-09 — Staff and Admin account management
10. A0-10 — Admin provisions base applicants
11. A0-11 — Staff/Admin complete applicant profile (mock)

## Phases

### A0-01 — Joinera shell and mock seam
- Outcome: The panel reads as Joinera Staff/Admin chrome and documents one reusable mock-service pattern before feature screens land.
- Feature IDs: none — template/platform seam.
- Depends on: none.
- In scope: Rename product strings (title, sidebar brand, SEO helpers) away from the template demo. Rewrite `nav-config.ts` to Joinera ops IA stubs only: Overview, Seekers, Queue, Blocks, Mailbox, Reports, Staff (Admin). Remove Product, Kanban, Chat, AI Chat, Forms, Icons, React Query from nav (do not rebuild those demos as product). Keep the existing server-fn + `*.server.ts` mock style; add a short `admin/src/lib/mock/` (or equivalent) note/helper only if it reduces copy-paste for later fixtures. Confirm `env.example` stays session-only — no Laravel URL vars.
- Out of scope: Role auth, real screens beyond placeholder routes that 404 or show “coming in A0-xx”, deleting every demo feature folder (nav removal is enough unless a demo route is the default landing), any `api/` package work.
- Open questions: Q12/Q17/Q18/Q31/Q33 do not block shell. Brand colours/logo assets not specified — use a minimal Joinera label; stop and ask before buying fonts or design packs.
- Likely touch: `admin/package.json` name, `admin/src/config/nav-config.ts`, `admin/src/components/layout/*` brand bits, `admin/src/lib/seo.ts`, `admin/src/routes/dashboard/index.tsx` redirect target, `admin/env.example.txt` (docs only if needed). Optional stub route files under `admin/src/routes/dashboard/`.
- Acceptance:
  - Pass: signed-in dashboard sidebar shows Joinera ops items and none of Product/Kanban/Chat/Forms/React Query.
  - Pass: document title / app chrome says Joinera (or agreed short name), not “tanstack-dashboard” demo branding.
  - Fail: any new env key pointing at Laravel/`api/` appears in `env.example` or committed env samples.
  - Pass: users feature still runs on in-memory mock (smoke) so the seam was not replaced with HTTP-to-backend.
- Plan ready when: A0-02 can add roles without redesigning navigation IA.

### A0-02 — Mock Staff/Admin auth and route guards
- Outcome: Only Staff or Admin can enter the dashboard; mock seeded accounts sign in with a `role` on the session.
- Feature IDs: 24.
- Depends on: A0-01.
- Aligns with API shape: P0-01 (`role` = `user` \| `staff` \| `admin`; Staff/Admin not self-serve).
- In scope: Extend `SessionUser` with `role: 'staff' | 'admin'` (panel never issues `user`). Seed at least one Staff and one Admin in `accounts.server.ts` with known demo passwords (document in README or auth constants). Sign-in succeeds only for those roles. Remove or disable public Staff/Admin sign-up from the panel (User signup is User Panel, not this app). `dashboard` `beforeLoad` rejects unsigned-in and rejects `role=user` if such a session could exist. Optional: hide Admin-only nav items (Staff) via role on nav items.
- Out of scope: Laravel Sanctum, OAuth, email verification, password-reset product flow, creating Staff via UI (A0-09).
- Open questions: None for mock auth. Q-Commercial: no payment copy on auth screens.
- Likely touch: `admin/src/features/auth/api/types.ts`, `accounts.server.ts`, `service.ts`, `middleware.ts`, `constants.ts`, `sign-in` / `sign-up` routes and views, `admin/src/routes/dashboard.tsx`, possibly `use-nav.ts` / nav item `roles`.
- Acceptance:
  - Pass: Admin seed signs in; `getCurrentUser` returns `role=admin`; dashboard loads.
  - Pass: Staff seed signs in; dashboard loads.
  - Fail: credentials for a mock `user`-role account (if present in store) cannot open `/dashboard/*` (redirect or 403 equivalent).
  - Fail: sign-up flow does not create a new Staff/Admin used for ops (route removed, disabled, or always errors).
  - Fail: signed-out visit to `/dashboard/overview` redirects to sign-in.
- Plan ready when: feature server fns can trust `context.user.role` from `authMiddleware`.

### A0-03 — Seekers directory (users list + detail)
- Outcome: Staff/Admin can list and open Joinera seekers (role=user) with ops-relevant mock fields, replacing the template CRM-style users demo.
- Feature IDs: 24 (directory), supports J1/J2 ops visibility.
- Depends on: A0-02.
- Aligns with API shape: users list + `GET` user summary fields from P0-01/02/05/06/08 (id, name, email, profile completeness, `approval`, mailbox state/domain/address, identity readiness) — mock only.
- In scope: Replace `features/users` mock fixtures with seeker rows (`role=user` only in this list). Columns/filters: search, approval `pending|approved`, readiness `ready|blocked|needs_staff`, mailbox `pending|ready|failed`. Detail view: same fields plus apply **domain** when present; no site passwords; no payment fields; no Joinera-branded apply domains in fixtures. Keep server-fn + React Query + data-table URL search pattern. Strip template “create arbitrary role user” that conflicts with seeker-only directory (Staff create moves to A0-09).
- Out of scope: Profile approve action (A0-04), break-glass secrets, mailbox message bodies (A0-07), editing seeker profile as if User Panel.
- Open questions: Exact extra profile fields beyond industry/city/province/language — do not invent; show only fields named in PRD/API phases.
- Likely touch: `admin/src/features/users/**`, `admin/src/constants/mock-api-users.server.ts`, `admin/src/routes/dashboard/users.tsx`, optional `users/$userId` route.
- Acceptance:
  - Pass: table shows seeded seekers with approval and readiness; filter `approval=pending` returns only pending.
  - Pass: detail shows mailbox state/domain/address when ready and never a password/secret or `@apply.joinera.de`.
  - Fail: list includes Product demo semantics or Pokémon/react-query demo data.
  - Fail: UI copy mentions price, payment, or that the service is free.
  - Pass: Staff and Admin both can open the directory.
- Plan ready when: A0-04 can call approve on a pending seeker id from this detail/list.

### A0-04 — One-time profile approval
- Outcome: Staff or Admin can approve a seeker’s profile once in the UI; mock state stays `approved` after later profile field edits in the fixture.
- Feature IDs: none of 01–26 — Q7 one-time approval (v1).
- Depends on: A0-03.
- Aligns with API shape: P0-05 `POST .../profile/approvals`.
- In scope: Approve action on pending seekers. Persist actor id/email and timestamp on the mock user. Second approve is idempotent (stays approved, no “re-pending”). Mock “edit profile fields” helper or admin-visible note proving edits do not reset approval (fixture mutation is enough). Users (seekers) cannot hit this from the panel. No per-apply or per-doc approval UI.
- Out of scope: Starting the apply queue, document approval, payment gates.
- Open questions: Q7 decided `once`. Q18 does not block.
- Likely touch: `features/users` mutations/service/mock store, detail or row action UI, maybe small audit fields on the user fixture.
- Acceptance:
  - Pass: pending seeker → Approve → UI shows `approved` with actor and time.
  - Pass: second Approve leaves a single approved state (no pending cycle).
  - Pass: mock profile field change after approval leaves `approved`.
  - Fail: no control that “approves this application” or “approves PDF”.
  - Pass: Staff and Admin can approve.
- Plan ready when: queue ops can show `profile_not_approved` vs approved seekers without re-litigating Q7.

### A0-05 — Queue, failures, and blocks ops
- Outcome: Staff/Admin can browse mock applications by pipeline status and open block/failure alerts that need human attention.
- Feature IDs: 18, 19, 20 (ops view); P1-10 claim/assign stays out unless a light “owner” field is free — default out.
- Depends on: A0-02 (A0-03 useful for linking to seeker).
- Aligns with API shape: P0-12 statuses; P0-15/17 failure and `BlockEvent` / Staff alert records.
- In scope: New feature module (e.g. `features/ops-queue`) with mock applications: statuses `matched → approved/queued → applying → submitted → reply_received → interview → rejected | offer` as displayable; filters for `queued`, `applying`, `hard_fail` / retryable attempts, open blocks (`captcha` \| `two_factor` \| `login_wall`). List + detail: seeker link, site id label, status, last attempt kind, block pause flag. Seed enough rows to exercise filters. Nav “Queue” / “Blocks” can be one route with tabs if that fits one plan better than two routes.
- Out of scope: Break-glass reveal (A0-06), real Playwright, caps config UI (P1), inventing Q12 site brand list beyond opaque `site_id` labels in fixtures.
- Open questions: Q18 minimum is detect/pause/alert — UI shows alert; solvers out of scope. Q12 does not require real site names.
- Likely touch: new `admin/src/features/ops-queue/**`, mock `*.server.ts`, routes under `admin/src/routes/dashboard/`, `nav-config.ts`.
- Acceptance:
  - Pass: filter `applying` + open blocks shows seeded blocked rows.
  - Pass: detail shows status and block kind without site password.
  - Fail: User-role session cannot load these routes.
  - Fail: UI offers “approve document before send” or Mode A per-job approve.
  - Pass: Staff and Admin both can view.
- Plan ready when: A0-06 can target a blocked/`hard_fail` application id from this list.

### A0-06 — Break-glass, manual complete, and audit
- Outcome: Staff/Admin can reveal one mock site secret with a reason, mark a stuck application `submitted`, and see an audit list that never re-shows the secret.
- Feature IDs: 21, 26.
- Depends on: A0-05.
- Aligns with API shape: P0-18 break-glass + manual-complete + `StaffAccessAudit`.
- In scope: Break-glass dialog (reason required) returns secret **once** in the UI response and appends an audit row (actor, target user, site, application id, reason, time). If mock “audit write fails” path is tested, secret must not appear. Manual complete: from `applying` with block or `hard_fail` → `submitted`, idempotent if already `submitted`; does not delete mailbox. Audit table/page: Staff/Admin; columns without secret. Simple client-side rate-limit or mock 429 after N reveals is enough to document the rule.
- Out of scope: Showing break-glass to end users, mailbox delete, GDPR export, retention purge job.
- Open questions: Q11 retention TTL open — no purge UI. Do not invent TTL.
- Likely touch: `features/ops-queue` or `features/break-glass/**`, audit mock store, detail actions on application/identity.
- Acceptance:
  - Pass: Staff break-glass with reason shows secret once and adds exactly one audit row.
  - Fail: audit list contains the secret plaintext.
  - Pass: manual complete moves seeded `applying`+block row to `submitted`; second call stays `submitted`.
  - Fail: manual complete removes or clears mailbox address on the seeker fixture.
  - Fail: break-glass without reason does not reveal or audit.
  - Pass: Admin can perform the same actions.
- Plan ready when: ops can demo Plan B without backend secrets infrastructure.

### A0-07 — Apply mailbox read and reply
- Outcome: Staff/Admin can open a seeker’s apply mailbox threads in a mock inbox and send a reply that appears in the thread; seekers have no access path in this app.
- Feature IDs: P1-01 (FEATURE_LIST P1).
- Depends on: A0-03 (seeker + mailbox address context).
- Aligns with API shape: future Staff mailbox read/reply (API doc lists as P1, not P0); mock `GET/POST` style under users/{id}/mailbox/messages.
- In scope: Inbox UI per seeker (from detail or Mailbox nav): show the seeker’s **ordinary domain + apply address** (not `@apply.joinera.de`); list threads/messages; read body; reply as Staff/Admin; append to mock store. Only `ready` mailboxes have messages; `pending`/`failed` show state (including domain/DNS failure) without message list. No user-facing inbox routes. No landing-site UI. Retention TTL not implemented — optional banner “retention TBD” is enough if needed; do not invent a number.
- Out of scope: Auto reply detection (P1-02), user read/reply, provider vendor SDK, connecting Laravel, domain registrar purchase UI.
- Open questions: Q9 decided Staff/Admin only. Q8 decided Mailgun EU + per-user domain — fixtures use personal-looking `@….de` addresses. Q11 retention open.
- Likely touch: `admin/src/features/mailbox/**`, mock messages store, routes, link from seeker detail, `nav-config.ts`.
- Acceptance:
  - Pass: Admin opens seeker with `mailbox=ready` and reads at least one seeded message.
  - Pass: Staff reply appears in the thread after submit.
  - Fail: no UI that lets a `role=user` session read mail in this app.
  - Fail: mailbox view exposes provider API tokens or break-glass site passwords.
  - Pass: seeker with `mailbox=pending` shows pending, not fake messages.
- Plan ready when: J5 reply handling can be demoed for ops without the real provider.

### A0-08 — Ops reports overview
- Outcome: Staff/Admin see a mock ops report replacing the template “sales” overview: intake, queue, blocks, funnel — no end-user pricing.
- Feature IDs: 23.
- Depends on: A0-05 (shared fixture vocabulary); A0-02.
- Aligns with API shape: P0-20 `GET /api/v1/ops/reports`.
- In scope: Replace overview dashboard widgets with ops metrics from a mock report service: intake last-run counts per `site_id`, queue counts (`queued`/`applying`/`submitted`/`hard_fail`), open pauses, funnel by pipeline status. Internal doc-cost sum may appear as an ops-only integer/null **without** currency language or “price” labels (P1-06 full AI cost dashboards can wait; if shown, label as internal cost units only). User reports (`/reports/me`) are out — that is User Panel.
- Out of scope: Real charts wired to Laravel, payment KPIs, public marketing analytics, caps UI (Q17).
- Open questions: Success metric targets TBD — show counts only, no fake OKRs.
- Likely touch: `admin/src/features/overview/**` or `features/ops-reports/**`, `routes/dashboard/overview.tsx`, mock report `*.server.ts`, remove recent-sales demo framing.
- Acceptance:
  - Pass: overview shows non-zero seeded funnel/queue figures for Staff.
  - Fail: page contains price, payment, subscription, or “free” copy.
  - Fail: payload/UI includes site passwords or mailbox tokens.
  - Pass: Admin sees the same report; signed-out cannot.
  - Fail: overview still sells template “recent sales” demo as Joinera product truth.
- Plan ready when: leadership can click one ops homepage without backend reporting tables.

### A0-09 — Staff and Admin account management
- Outcome: Admin can create Staff or Admin mock accounts for the panel; Staff cannot create elevated users.
- Feature IDs: 24.
- Depends on: A0-02.
- Aligns with API shape: P0-01 `POST /api/v1/staff-users`.
- In scope: Admin-only “Staff” nav screen: list panel operators (`staff`/`admin`), create with name, email, password, role `staff|admin`. Mock store shared with auth accounts so new users can sign in. Staff visiting create/list management gets forbidden empty state or redirect. No self-serve public registration for these roles (already A0-02). Do not allow assigning `role=user` here.
- Out of scope: Seeker account delete (J6 is user self-delete), SSO, connecting Laravel.
- Open questions: None.
- Likely touch: `admin/src/features/staff-users/**` or extend users carefully separated from seekers, `accounts.server.ts`, Admin-gated route + nav `roles: ['admin']`.
- Acceptance:
  - Pass: Admin creates Staff; that email/password signs in with `role=staff`.
  - Pass: Admin creates Admin.
  - Fail: Staff session cannot create Staff/Admin (UI hidden and server fn rejects).
  - Fail: create with `role=user` is rejected.
  - Fail: duplicate email does not overwrite an existing account silently without error.
- Plan ready when: mock ops staffing works without the real `staff-users` API.

### A0-10 — Admin create-command provisions base seekers
- Outcome: Admin issues a **create command** only (not a hand-built mailbox). Mock creates the seeker account shell and starts mailbox/domain provision in `pending`; the seeker completes profile later in the User Panel. Seekers do not self-register in this admin app.
- Feature IDs: 24 (roles / provisioning); supports J1 ops intake + Q8 create path.
- Depends on: A0-02, A0-03.
- Aligns with API shape: Admin-provisioned user credentials (mock) that will trigger domain+mailbox provision on the API; not `POST /staff-users`.
- In scope: Admin-only Create seeker on the Seekers directory (name, login email, password). Dual-write auth account (`role=user`) + seeker row shell (`approval=pending`, incomplete profile, mailbox `pending`, domain empty or candidate). UI copy: Admin “issues create”; system provisions user + apply domain/email (mock may immediately leave mailbox `pending`). Duplicate login email rejected. Panel sign-in for `role=user` stays forbidden (A0-02). Staff cannot create. Do not invent Joinera-branded apply addresses in fixtures (`@apply.joinera.de` forbidden).
- Out of scope: User Panel screens, Laravel, live registrar/Mailgun calls, email verification/invite mail, creating `staff`/`admin` (A0-09), waiting for real DNS in the mock, profile approve-as-part-of-create, seeker delete, landing page builder.
- Open questions: Exact apply local-part pattern still open with PM; mock may use `firstname.lastname@{slug}.de` style.
- Likely touch: `accounts.server.ts`, `mock-api-users.server.ts`, `features/users/**` create dialog, `docs/admin/IMPLEMENTATION_PHASES.md` (this phase).
- Acceptance:
  - Pass: Admin creates seeker; row appears in Seekers with incomplete shell and mailbox `pending` (not hand-filled ready address).
  - Pass: auth store has matching email/`role=user`; User Panel credential check succeeds; admin panel sign-in with those credentials is forbidden.
  - Fail: create UI asks Admin to paste a Gmail password or pick `@apply.joinera.de`.
  - Fail: duplicate email overwrites silently.
  - Fail: Staff can create (UI + server).
  - Fail: create flow assigns `staff`/`admin`.
- Plan ready when: ops can issue create for a seeker without hand-building the apply mailbox.

### A0-11 — Staff/Admin complete seeker profile (mock)
- Outcome: Staff and Admin can fill a seeker’s structured profile on seeker detail; a CV file input marks mock `hasCv` without uploading bytes or calling Laravel.
- Feature IDs: supports J1 / P0-02 field set.
- Depends on: A0-03, A0-04.
- Aligns with API shape: named fields `industry`, `city`, `province`, `language` + CV completeness; mock CV mark only (no multipart).
- In scope: Editable form on seeker detail for the four fields (Staff + Admin). PDF file input that calls a mock `markCvOnFile` (no file payload). Completeness = CV + four non-empty fields. Q7: edits after approve stay approved. Remove simulate-edit demo control.
- Out of scope: Real file storage/S3, CV parse, priorities editor, User Panel, inventing extra required fields, name/email edit here.
- Open questions: None for this mock.
- Likely touch: `seeker-detail.tsx`, `mock-api-users.server.ts`, users service/mutations, `IMPLEMENTATION_PHASES.md`.
- Acceptance:
  - Pass: Staff/Admin save four fields; detail/list update; complete when CV marked + four filled.
  - Pass: choosing a PDF in the input sets `hasCv` without uploading content.
  - Pass: approved seeker stays approved after edit.
  - Fail: real multipart upload or Laravel URL appears.
- Plan ready when: ops can complete a provisioned seeker shell without User Panel.

## Later (not phased here)

- Swap mock server fns → Joinera Laravel `/api/v1` (separate track).
- Caps UI (P1-03, Q17), CAPTCHA playbooks (P1-04, Q18), guided identity rotation (P1-05).
- Full AI cost dashboards (P1-06), Staff claim/assign board (P1-10), GDPR export (P1-09).
- Connectors registry / non-DE sites (Q12/Q33) beyond opaque fixture ids.
- User Panel (seeker-facing) — different app.

Not in the product: subscription, checkout, contracts, and any copy that says the service is free.

## Coverage check

| Ops need | Phase |
| --- | --- |
| Shell + mock seam | A0-01 |
| Roles Staff/Admin panel auth | A0-02, A0-09 |
| Seekers directory | A0-03 |
| One-time profile approval (Q7) | A0-04 |
| Queue / failures / blocks | A0-05 |
| Break-glass + audit (21, 26) | A0-06 |
| Mailbox read/reply (P1-01) | A0-07 |
| Ops reports (23) | A0-08 |
| Staff user admin | A0-09 |
| Admin provisions base seekers | A0-10 |
| Staff/Admin complete seeker profile | A0-11 |

Demo template Product/Kanban/Chat/Forms/Pokémon are removed from IA in A0-01 and are not rebuilt.

## Done when

- This file is the phase list for mock-only admin work; **no Joinera `api/` connection** is scheduled.
- Eleven phases, each sized for one Cursor Plan session.
- Decided product rules (Q7, Q8 GMX, Q9 mailbox, Q-Commercial, Mode B company-site apply per PRD 1.8 / no per-doc approve) are respected in scope/out-of-scope lines.
- No application code was changed by authoring this document.
