# Demo truth runbook — Joinera Admin + API

**Audience:** stakeholders (~10–15 min)  
**When:** presentation day  
**Rules:** No product code changes during the session. Do **not** debug GMX IMAP/TLS. Claim only what is live.

**Locked sources:** [admin/src/lib/mock/SEAM.md](../admin/src/lib/mock/SEAM.md) · [admin/src/config/nav-config.ts](../admin/src/config/nav-config.ts) · [api/routes/api.php](../api/routes/api.php) · [docs/prd/PRD.md](prd/PRD.md) Goals/non-goals + Decisions

---

## Preflight (before audience enters)

1. API running; Admin `JOINERA_API_URL` set; mailbox mode allows Admin attach (`JOINERA_MAILBOX_MODE=gmx_manual` or equivalent per SEAM).
2. Sign in once as Admin: `admin@joinera.test` / `password`. Confirm session holds.
3. Pick **one** Candidate path:
   - Prefer an existing Candidate with mailbox already `ready` if you will open threads.
   - Or create + attach during the demo and **stop at status** if sync is flaky.
4. Sidebar only: Operations + Governance from nav. Do not Cmd+K into starter routes.
5. Keep the kill list (below) visible.

**Stop conditions:** 5xx on applicants / profile / credentials → abort that step. Empty or suspicious mailbox threads after attach → close inbox, do not chase IMAP.

---

## Live path (Show)

| # | Action | Route / UI | API proof | One-line talk |
| --- | --- | --- | --- | --- |
| 1 | Sign in | Auth | `POST /api/v1/login`, `GET /api/v1/me` | Only Staff/Admin log in; Candidate has no panel (Q-Access). |
| 2 | Open directory | `/dashboard/applicants` | `GET /api/v1/applicants` | Live Candidate directory. |
| 3 | Create Candidate | Applicants → create | `POST /api/v1/applicants` | Name + optional ops email; no Candidate password. Docs arrive via WhatsApp; Staff uploads here. |
| 4 | Edit profile + CV | Applicants `$id` | `PUT /api/v1/users/{user}/profile`, `POST …/profile/cv` | Structured fields + CV upload are live. |
| 5 | Approve profile | Same | `POST …/profile/approvals` | One-time approval (Q7); then apply may start. |
| 6 | Attach GMX | Detail / mailbox attach | `PUT …/mailbox/credentials` (Admin) | Admin creates GMX outside; paste email + password; password never shown again. |
| 7 | Mailbox directory | `/dashboard/mailbox` | Applicants + `GET …/mailbox` meta | Dedicated apply inbox per Candidate. |
| 8 | Inbox (optional) | `/dashboard/mailbox/$applicantId` | `GET/POST …/mailbox/messages*` | **Only** if status is `ready` and threads look real. Else skip. |

---

## Skip (do not click)

| Item | Why |
| --- | --- |
| Overview (`/dashboard/overview`) | Mock ops reports |
| Reports (`/dashboard/reports`) | Mock ops reports |
| Queue (`/dashboard/queue`) | Mock pipeline UI; not wired to live matches |
| Blocks (`/dashboard/blocks`) | Mock break-glass / anti-block |
| Audit (`/dashboard/audit`) | Mock governance trail |
| Staff (`/dashboard/staff`) | Mock operators UI; not Laravel `POST /api/v1/staff-users` |
| Document templates (`/dashboard/document-templates`) | Local studio; not apply PDF generate API |
| **Do not open:** `/dashboard/forms*`, `/product*`, `/kanban`, `/chat`, `/ai-chat`, `/notifications`, `/react-query`, `/elements/icons`, `/dashboard/users` | Starter junk; not in nav |

---

## Say “coming” (if asked — do not open UI to fake it)

| Topic | Line |
| --- | --- |
| Auto match / queue / force-queue | Daily DE scrape + auto-queue is next. API has `GET /api/v1/matches` and force-queue; the Admin **queue screen is still mock**. |
| Mode B apply | Product target (PRD 1.8): **company-site** AI form-discover + Playwright. Today’s worker still has **interim board** adapters (Azubiyo / BA); company-site path not shipped. Internal Mode B APIs exist; no Staff end-to-end apply console in nav yet. |
| Per-apply CV/cover PDFs | Generate/download APIs exist under `applications/.../docs*`. Document-templates is **not** that pipeline. |
| Board auto-register / verify | After **company-site** Mode B ships (PRD). Board login is not the primary apply gate. |
| Candidate login / in-product pay | Explicit non-goals. Money outside the product. Do not call the service free. |

---

## Narrative arc (claim this much, no more)

1. Ops-only product (Staff/Admin).
2. Candidate is a managed record: create → profile/CV → one-time approve.
3. GMX apply identity is Admin-attached (Q8).
4. Mailbox identity is visible; live threads only if stable.
5. Match → queue → tailored PDFs → **company-site** Mode B → board register (optional later) → pay UI = roadmap.

---

## Five lies never to imply

1. **Queue** = production auto-apply pipeline.
2. **Blocks** = live CAPTCHA/2FA break-glass.
3. **Overview / Reports** = real ops metrics.
4. **Audit** = authoritative production trail.
5. **Mailbox threads** or **Staff operators** page = necessarily live (hybrid mock / mock accounts).

---

## Phase 1 inventory (quick reference)

| Area | Admin route/nav | Live / Mock / Partial / Missing | API route(s) | Demo | Risk if opened by mistake |
| --- | --- | --- | --- | --- | --- |
| Sign-in | (auth) | Live | `login`, `logout`, `me` | Show | Low |
| Overview | `/dashboard/overview` | Mock | — | Skip | Fake ops health |
| Applicants | `/dashboard/applicants` | Live | `applicants*` | Show | Low if API up |
| Profile / CV / approve | Applicants detail | Live | `users/{user}/profile*`, `…/cv`, `…/approvals` | Show | Low |
| GMX attach | Detail / mailbox | Live | `PUT …/mailbox/credentials` | Show | Medium if mode wrong |
| Mailbox directory | `/dashboard/mailbox` | Live | applicants + `…/mailbox` | Show | Low |
| Mailbox messages | `…/mailbox/$id` | Partial | `…/mailbox/messages*` | Show only if ready | **High** — mock fallback |
| Queue | `/dashboard/queue` | Mock | matches API unwired in UI | Say coming | **High** |
| Blocks | `/dashboard/blocks` | Mock | — | Skip | **High** |
| Reports | `/dashboard/reports` | Mock | — | Skip | **High** |
| Document templates | `/dashboard/document-templates` | Partial (local) | docs APIs ≠ this page | Skip / coming | Medium |
| Audit | `/dashboard/audit` | Mock | — | Skip | **High** |
| Staff | `/dashboard/staff` | Mock | `POST staff-users` unused by UI | Skip | Medium |
| Identity readiness | Applicants badges | Partial | `…/identity/readiness` | Soft show | Medium |
| Site identities | no nav | Partial / Missing UI | `…/site-identities` | Say coming | Medium |
| Matches / force-queue | no nav | Missing Admin UI | `matches*` | Say coming | — |
| Application docs | no nav | Missing Admin UI | `applications/.../docs*` | Say coming | — |
| Listing import | no nav | Missing UI (PRD non-goal) | `POST listings/import` | Skip | Contradicts scrape-only |
| Mode B internal | no Admin UI | Partial API | `internal/mode-b/...` | Say coming | High if pitched as console |
| Starter junk | forms/product/kanban/chat/ai-chat/notifications | Template | — | **Do not open** | **High** |
