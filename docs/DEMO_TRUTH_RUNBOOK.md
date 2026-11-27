# Demo truth runbook — Joinera Admin + API

**Audience:** stakeholders (~10–15 min)  
**When:** presentation day  
**Rules:** No product code changes during the session. Do **not** debug GMX IMAP/TLS. Claim only what is live.

**Locked sources:** [admin/src/lib/mock/SEAM.md](../admin/src/lib/mock/SEAM.md) · [admin/src/config/nav-config.ts](../admin/src/config/nav-config.ts) · [api/routes/api.php](../api/routes/api.php) · [docs/prd/PRD.md](prd/PRD.md) Goals/non-goals + Decisions

---

## Local ports (Windows reserved-range note)

On this machine, Hyper-V often blocks **3306** and **8000**. Use:

| Service | Command | URL |
| --- | --- | --- |
| MySQL (Laragon) | `mysqld` with `--port=33060` | `127.0.0.1:33060` |
| API | `php artisan serve --host=127.0.0.1 --port=8080` | http://127.0.0.1:8080 |
| Queue | `php artisan queue:work database --queue=intake,match,docs,apply,alerts,default` | — |
| Admin | `bun run dev -- --host 127.0.0.1 --port 3000` | http://127.0.0.1:3000 |

Match `api/.env` `DB_PORT` / `APP_URL` and `admin/.env.local` `JOINERA_API_URL` to those ports. Seed demo personas once: `php artisan db:seed --class=ApplyDemoSeeder`.

---

## Preflight (before audience enters)

1. API `/up` returns 200; Admin `JOINERA_API_URL` points at the API; mailbox mode allows Admin attach (`JOINERA_MAILBOX_MODE=gmx_manual`).
2. Sign in once as Admin: `admin@joinera.test` / `password`. Confirm session holds.
3. Confirm Overview KPIs are non-empty (ApplyDemoSeeder or live data).
4. Pick **one** Candidate path for mailbox:
   - Prefer an existing Candidate with mailbox already `ready` if you will open threads.
   - Or create + attach during the demo and **stop at status** if sync is flaky.
5. Sidebar only: Operations + Governance from nav. Do not Cmd+K into starter routes.
6. Keep the kill list (below) visible. Do not open the notification bell (template mock).

**Stop conditions:** 5xx on applicants / profile / credentials → abort that step. Empty or suspicious mailbox threads after attach → close inbox, do not chase IMAP.

---

## Live path (Show)

| # | Action | Route / UI | API proof | One-line talk |
| --- | --- | --- | --- | --- |
| 1 | Sign in | Auth | `POST /api/v1/login`, `GET /api/v1/me` | Only Staff/Admin log in; Candidate has no panel (Q-Access). |
| 2 | Overview | `/dashboard/overview` | `GET /api/v1/ops/reports` | Live attention strip: queued, applying, Staff help, pauses, hard fails. |
| 3 | Open directory | `/dashboard/applicants` | `GET /api/v1/applicants` | Live Candidate directory. |
| 4 | Create Candidate | Applicants → create | `POST /api/v1/applicants` | Name + optional ops email; no Candidate password. Docs arrive via WhatsApp; Staff uploads here. |
| 5 | Edit profile + CV | Applicants `$id` | `PUT /api/v1/users/{user}/profile`, `POST …/profile/cv` | Structured fields + CV upload are live. |
| 6 | Approve profile | Same | `POST …/profile/approvals` | One-time approval (Q7); then apply may start. |
| 7 | Queue | `/dashboard/queue` | `GET /api/v1/applications` | Live pipeline rows + status counts; open a detail for docs. |
| 8 | Queue detail / docs | Queue `$id` | `POST/GET …/applications/{id}/docs/*` | Generate/download when status allows. |
| 9 | Reports (optional) | `/dashboard/reports` | `GET /api/v1/ops/reports` | Same live ops report, deeper intake + funnel. |
| 10 | Attach GMX | Detail / mailbox attach | `PUT …/mailbox/credentials` (Admin) | Admin creates GMX outside; paste email + password; password never shown again. |
| 11 | Mailbox directory | `/dashboard/mailbox` | Applicants + `GET …/mailbox` meta | Dedicated apply inbox per Candidate. |
| 12 | Inbox (optional) | `/dashboard/mailbox/$applicantId` | `GET/POST …/mailbox/messages*` | **Only** if status is `ready` and threads look real. Else skip. |

CEO apply-persona shortcut: [APPLY_CEO_DEMO_WALKTHROUGH.md](./APPLY_CEO_DEMO_WALKTHROUGH.md) (Overview → Queue → Blocks for seeded personas).

---

## Skip (do not click)

| Item | Why |
| --- | --- |
| Audit (`/dashboard/audit`) | Mock list — break-glass writes live audit ids that **won’t** appear here |
| Queue **Mark submitted** / manual-complete | Mock — does not update the live pipeline |
| Staff (`/dashboard/staff`) | Mock operators UI; create does not mint Laravel logins |
| Document templates (`/dashboard/document-templates`) | Local studio; not apply PDF generate API |
| `/about`, Terms, Privacy | Fine if asked; not the product story |
| **Do not open:** `/dashboard/forms*`, `/product*`, `/kanban`, `/chat`, `/ai-chat`, `/notifications`, `/react-query`, `/elements/icons`, `/dashboard/users` | Starter junk; not in nav |

**Blocks** (`/dashboard/blocks`) is **live** for list + break-glass — safe for the CEO walkthrough. Do not then open Audit expecting the same event.

---

## Say “coming” (if asked — do not open UI to fake it)

| Topic | Line |
| --- | --- |
| Auto match / scrape cadence | Daily DE scrape continues; force-queue from skipped matches is live on Queue. |
| Mode B company-site apply | Product target (PRD 1.8): AI form-discover + Playwright. Worker + Mode B APIs exist; claim only what you can show end-to-end today. |
| Board auto-register / verify | After company-site Mode B is the primary path. |
| Candidate login / in-product pay | Explicit non-goals. Money outside the product. Do not call the service free. |

---

## Narrative arc (claim this much, no more)

1. Ops-only product (Staff/Admin).
2. Candidate is a managed record: create → profile/CV → one-time approve.
3. Live queue + ops reports show pipeline truth (seeded demo personas for the room).
4. GMX apply identity is Admin-attached (Q8).
5. Mailbox identity is visible; live threads only if stable.
6. Mode B company-site apply + board register (optional later) + pay UI = roadmap unless you have a live proof today.

---

## Five lies never to imply

1. **Blocks / Audit / Staff operators page** = necessarily production-backed UI (still mock in panel).
2. **Notification bell** = live ops alerts.
3. **Document templates** gallery = the per-apply PDF generate pipeline (that lives on application docs APIs).
4. **Mailbox threads** = always live (hybrid mock fallback if message APIs unavailable).
5. **Cmd+K starter routes** = product.

---

## Phase inventory (quick reference)

| Area | Admin route/nav | Live / Mock / Partial | API route(s) | Demo |
| --- | --- | --- | --- | --- |
| Sign-in | (auth) | Live | `login`, `logout`, `me` | Show |
| Overview | `/dashboard/overview` | Live | `ops/reports` | Show |
| Applicants | `/dashboard/applicants` | Live | `applicants*` | Show |
| Profile / CV / approve | Applicants detail | Live | `users/{user}/profile*`, `…/cv`, `…/approvals` | Show |
| Queue | `/dashboard/queue` | Live | `applications*`, docs, skipped matches force-queue | Show |
| Reports | `/dashboard/reports` | Live | `ops/reports` | Show |
| GMX attach | Detail / mailbox | Live | `PUT …/mailbox/credentials` | Show |
| Mailbox directory | `/dashboard/mailbox` | Live | applicants + `…/mailbox` | Show |
| Mailbox messages | `…/mailbox/$id` | Partial | `…/mailbox/messages*` | Show only if ready |
| Blocks | `/dashboard/blocks` | Live list + break-glass | `ops/blocks`, `…/break-glass` | Show (CEO walkthrough) |
| Manual-complete | Queue detail | Mock | — | Skip |
| Document templates | `/dashboard/document-templates` | Partial (local) | docs APIs ≠ this page | Skip / coming |
| Audit | `/dashboard/audit` | Mock | — | Skip |
| Staff | `/dashboard/staff` | Mock | `POST staff-users` unused by UI | Skip |
| Starter junk | forms/product/kanban/chat/… | Template | — | **Do not open** |
