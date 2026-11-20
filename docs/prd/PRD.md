# Joinera Job Apply Platform — PRD

**Version:** 1.8  
**Related:** [TRD](./TRD.md) · [Apply email LVP phases](./APPLY_EMAIL_LVP_PHASES.md) · [فارسی](./PRD.fa.md)  
**Context:** Landing at [joinera.de/joinera-farsi](https://www.joinera.de/joinera-farsi). No apply product yet.  
**Language:** Candidate (never “User” / “seeker” for the person being applied for). Only Staff and Admin log in.  
**Intake/match/apply lock:** 2026-11-18 (boards = intake only; Mode B applies on **company career sites** via AI form-discover + Playwright; Staff Plan B on block).

---

## Summary

Ops platform that **auto-applies** to German **jobs + Ausbildung** for Candidates (~80% Iranian, any country welcome). **Staff/Admin only** use the product — Candidates have **no login and no panel**. Staff receives documents via **WhatsApp**, creates the Candidate, uploads docs; the system classifies them, builds CV/cover (CV may be missing at start), and produces a combined application PDF. **Admin** creates a **GMX** apply mailbox outside Joinera, pastes email + password into Admin. Daily scrape pulls new DE board listings (**discovery only**); **rules + AI match** eligible Candidates (ready GMX + complete profile) into **`proposed`** matches. Staff/Admin **select a batch on the Candidate profile** and **Queue selected** into docs → apply. Staff may still **force-queue** a skipped job. System builds per-apply PDFs, then Mode B opens the **company career / apply URL**, AI-discovers the form, fills/uploads from Candidate profile + PDFs, and submits — **not** via board Easy-Apply. On block: pause + Staff alert (Plan B). GMX sync stores employer mail; AI then Staff reply. Money is handled **outside** the product; do not describe the service as free.

---

## Problem & audience

Manual high-volume German applications are slow: many sites, accounts, docs, replies, and block risk from shared inboxes. Joinera does this by hand today; the product must automate Staff’s workflow.

| Who | Role |
| --- | --- |
| **Candidate** | Job/Ausbildung person Joinera applies for. A **record** Staff manages — **not** a system user. No login. |
| **Staff** | Onboard Candidates (docs, priorities, approval watch); pick proposed matches on the Candidate profile and Queue selected; force-queue skipped jobs; fix blocks; mailbox read/reply; watch failures. |
| **Admin** | Candidates, GMX credentials, connectors, Staff, reports, ops. Same break-glass class as Staff. |

---

## Goals & non-goals (v1)

**Must have:** (1) Staff/Admin create Candidate + WhatsApp-sourced document upload · (2) Classify/sort docs, completeness flags, build CV/cover + combined apply PDF · (3) Per-Candidate **GMX** apply email (Admin-created; credentials in Admin) · (4) GMX login/sync → store messages in DB; re-sync new mail · (5) Daily DE board intake + match to `proposed` + Staff Queue selected on Candidate profile (Staff force-queue + failure watch) · (6) Full auto-apply Mode B on **company sites** (AI form-discover + Playwright fill/upload/submit; Staff Plan B on block) · (7) Tailored PDF per apply (Q20) · (8) Admin/Staff ops reports · (9) Human-like anti-block (hard).

**Not in v1:** Candidate login / Candidate panel · Recommended swipe UI · Staff curated job paste/import · Per-job/doc approval before send · **Submitting via board Easy-Apply as the primary path** · Shared site/email accounts · Auto-provision Mailgun/domains · **Auto-register Candidate accounts on job boards** (site signup) · Non-PDF final apply packs · Inventing TBD answers as facts · In-product checkout · Auto hard-ban on language or city until PM locks Q-Match-Soft · Coded one-adapter-per-employer (too many unique company forms).

**Later (after company-site Mode B auto-apply ships):** Automatically **register** each Candidate on the configured **boards** (Ausbildung.de, Azubiyo, arbeitsagentur.de, …) only if product still needs board logins for intake enrichment — not for primary apply. That later plan MUST also cover **email verification**: boards send confirm-registration mail to the Candidate’s GMX apply inbox → GMX sync → **AI + browser** clicks the verify link. Order: **company-site auto-apply first**, then **board auto-register + auto-verify** if still needed. Until then, board `SiteIdentity` is optional for apply; missing board login MUST NOT block company-site apply when a company apply URL + ready GMX + approved profile exist.

---

## Roles (short)

- **Candidate:** Data only — profile, docs, priorities (set by Staff), apply identity, pipeline. No product access.
- **Staff:** Create/manage Candidates; upload docs; set priorities; select proposed matches on the Candidate profile and Queue selected; watch failures / mailbox; force-queue skipped jobs when needed; fix blocks via break-glass; delete Candidate (clears Joinera mail data/credentials). Mode B stays default.
- **Admin:** Governance, connectors, ops health, Staff accounts; **creates GMX accounts outside** and **enters GMX credentials** in Admin; same ops powers as Staff including delete. Soft match signals (language/city) listed in Admin until PM decides auto-filter.

---

## Journeys

| # | Flow |
| --- | --- |
| **J1** | Staff gets Candidate docs via **WhatsApp** → Staff/Admin **creates Candidate** → uploads all raw documents (CV if available, certificates, passport, language certificates, etc.) → system **classifies/sorts**, checks completeness, **flags missing** → system **creates CV and motivation letter** from those docs (CV is not always provided) → system produces **one combined application PDF** (CV + cover + attachments) → Staff/Admin **approve profile once** (Q7) |
| **J2** | **Admin** creates a **GMX** mailbox outside Joinera → pastes **email + password** into Admin for that Candidate (`pending` → `ready` / `failed`) → Mode B applies use that address → system **logs into GMX**, reads mail, **stores messages in the DB**, and on new mail **connects again**, reads, and stores · AI drafts then Staff replies via the same GMX account · per-site identity never shared · Candidate never reads or replies |
| **J3** | **Once daily** scrape of **new** DE jobs + Ausbildung from **fixed big boards** (external job id dedupe; scrape only — no Staff paste) → rules filter → AI ranks eligible Candidates (ready GMX + complete profile) → **`proposed`**. Staff/Admin select a batch on the Candidate profile → **Queue selected** → `queued`. No Recommended swipe. Staff may **force-queue** a skipped job. |
| **J4** | `queued` → `preparing_docs` (Q20 cover/CV PDFs) → Mode B opens **company apply URL** → AI discovers form fields/files → maps Candidate profile + PDFs → Playwright fill/upload/submit → `submitted` when site confirmation **or** clean submit OK · on CAPTCHA/2FA/login wall / unfillable form: **pause + alert Staff** (Plan B) |
| **J5** | Replies (synced from GMX) → interview → rejected \| offer |
| **J6** | **Staff or Admin deletes** the Candidate → Joinera deletes stored **GMX credentials + message rows** (**only** then for Joinera mail data — rejection of an application never clears them). The **GMX account itself is not auto-deleted**; Admin removes it manually outside if needed |

**Status pipeline:** `matched → queued → preparing_docs → applying → submitted → reply_received → interview → rejected | offer`  
(Match rows: hard-eligible → `proposed`; Staff **Queue selected** (or force-queue skipped) → match `queued` and Application `matched → queued`.)

---

## Requirements (testable)

### Core
1. Staff or Admin creates the Candidate record (no Candidate auth). **Admin** creates the GMX account outside Joinera and enters email + password in Admin (no auto-provision of domains/Mailgun).  
2. Staff uploads raw documents received via WhatsApp. System classifies and sorts them, checks completeness, and flags missing items. System builds CV and motivation letter from those documents (CV may be absent). System produces one combined application PDF (CV + cover + attachments). Structured profile fields remain editable by Staff.  
3. Priorities stored; **set by Staff**; default industry → city → province.  
4. Jobs **and** Ausbildung — **Germany only** (scrape and apply geography).  
5. Mode B: system submits; **no** per-apply doc approval before send. One-time profile approval (Q7) still required before apply starts.  
6. Application docs = **PDF only**, tailored per apply (Q20).  
7. Status pipeline as above; idempotent applies (no duplicate submit on retry).  
8. Admin/Staff ops reports (no Candidate-facing reports).  
9. Login roles: **Staff**, **Admin** only. Candidate is not a login role.

### Email & identity
10. Dedicated **GMX** apply email per Candidate — **no** shared inbox.  
11. Apply identity = Candidate **GMX** address + profile/docs on the **company** form. **No** shared inboxes. Board `SiteIdentity` is **not** required to claim a company-site apply. If a company ATS forces account signup, treat like a block → pause + Staff Plan B (or later company-account automation).  
12. Block (CAPTCHA / 2FA / login wall / unfillable dynamic form) → **pause + alert Staff**. Staff Plan B (break-glass / manual complete on company URL) as needed.  
13. When Staff or Admin deletes the Candidate, Joinera deletes stored GMX credentials and mailbox message rows. Rejection of an application does not. Joinera does **not** delete the GMX account at the provider (manual outside).  
14. Staff/Admin break-glass only; never exposed to Candidates. GMX passwords are stored encrypted; never shown in normal list views.  
15. **v1 mail = GMX.** Admin creates the account outside and stores credentials in Admin. System uses them to (a) put that address on **company** applications and (b) log in, read mail, persist messages in the DB, and re-sync when new mail arrives. AI drafts then Staff/Admin send replies via the same GMX account inside Joinera. Not Gmail, not the Candidate’s own personal email, not `@apply.joinera.de`. Retention TTL stays open.

### Intake, match, apply
16. **Once daily** browser scrape adds **only new** listings keyed by **external job id**. Sources = **fixed big boards** for **discovery** (listings + company apply URL). **Germany only** — jobs and Ausbildung. **No** Staff curated paste/import in v1. Do **not** scrape arbitrary company career indexes as the intake source.  
17. Intake boards = Q12: Ausbildung.de + Azubiyo + **arbeitsagentur.de** (Bundesagentur Jobbörse + Ausbildungssuche). **Apply target** = company career/apply URL resolved from the listing (not board Easy-Apply). Non-DE scrape off (Q33).  
18. **Listing fields (minimum):** title, company, location/city, listing URL, **company apply URL** (required for Mode B when different from listing URL), description, source site, external job id, job vs Ausbildung, language requirement if present, posted/found date.  
19. Matcher: **rules first**, then **AI ranks** inside the eligible set. Auto-match only Candidates with **ready GMX + complete profile**.  
20. **Hard never-match (auto):** outside Candidate **industry**; listing **not in Germany**; listing states a **Schulabschluss/degree** the Candidate lacks.  
21. **Soft / Admin-listed only (ask PM — do not auto-exclude yet):** language-level mismatch; city/province preference mismatch.  
22. **No Recommended swipe.** Hard-eligible matches land as **`proposed`**. Staff/Admin multi-select on the Candidate profile and **Queue selected** → `queued` → `preparing_docs` while cover/CV generate → `applying`. Staff may **force-queue** a skipped job. No per-job cover approval.  
23. Apply path = **company-site Mode B**: Playwright opens company apply URL → **generic** form discovery (DOM/accessibility snapshot; vision if needed) → AI maps Candidate profile + Q20 PDFs → browser fill/upload/submit. **Do not** prefer board Easy-Apply. **Do not** ship one coded adapter per employer. Map/fill **whatever that company form asks** (fields + files). Missing company apply URL → fail closed / Staff handoff (`external_apply`), not board submit.  
24. Success = site **confirmation** **or** clean submit without error.  
25. Anti-block is **hard** (jitter, real sessions, per-Candidate isolation, retries, observability). Prefer software; Staff Plan B if automation fails.  
26. Per-Candidate apply volume is **env-configurable** (Q17): a numeric cap (default **10** Applications per Candidate per window) and a window mode of **`daily` or `weekly`** (also from env). **Queue selected** and force-queue MUST stop creating new `queued` Applications for that Candidate once the cap is reached in the active window. Different Candidates get different job sets (do not pile every Candidate onto one listing). Global worker pool stays small (not 1000) — Q31.  
27. CAPTCHA/2FA/login wall / unfillable form policy = Q18 minimum: detect, pause, alert Staff.

### Documents & Staff panel
28. Same job category as the profile: keep the base CV and write a new cover. A different category: write a new CV and a new cover. Optimize for low AI use (more code OK). **Interim (2026-11-15):** if listing `category` is empty (Azubiyo / Ausbildung.de intake currently hardcodes null), generate **CV + cover** instead of stopping with `category_unknown`. **Later (Q-Listing-Category):** map a real occupation/category field from Azubiyo / Ausbildung.de (or derive from title/occupation) so same-vs-different Q20 rules work without guessing.  

29. ~~User Panel~~ **Removed.** Candidates have no product UI.  
30. Admin/Staff panel: Candidates, document upload/classification, one-time profile approval, **GMX credential entry** (Admin), mailbox sync status, **proposed-match picker on Candidate profile**, queue / failures, soft-match signals for PM (language/city — display only until locked), blocks, audited break-glass, funnel, roles, delete Candidate. Staff and Admin can read and reply on the apply mailbox inside Joinera.

### Commercial
31. No checkout, subscription, or payment UI in the product. Money is handled outside the product (e.g. support / WhatsApp). Do not describe the service as free.

---

## Decisions

| ID | Decision |
| --- | --- |
| Q7 | Staff or Admin approve the profile **once**. After that, apply may start. Edits do not require a new approval. |
| Q8 | **Decided (2026-11-10).** **GMX** is the v1 apply-mail lock. **Admin** creates one GMX account per Candidate outside Joinera and enters email + password in Admin. Used for apply identity **and** login/sync of mail into the DB (re-sync new mail); AI then Staff reply via the same account. Not Gmail / Candidate’s own email / `apply.joinera.de`. |
| Q9 | Candidates cannot read or reply (and have no login). Only Staff and Admin can, inside Joinera. |
| Q11 | Mail data Joinera stores is under Joinera ops control. How long messages are kept is still open. The GMX account at the provider is created/deleted manually by Admin. |
| Q20 | Same job category (for example IT): do not rewrite the CV; change the cover letter. A different category: change the CV too. **Interim:** empty listing category → generate CV + cover (do not stop). **Later:** restore strict unknown handling once intake fills category (Q-Listing-Category). |
| Q-Listing-Category **(2026-11-15 later)** | Azubiyo + Ausbildung.de adapters currently set `listing.category = null`. **Later step:** map a real board field into `category`, or derive from title/occupation, so match industry hard-rules and Q20 cover-only vs CV+cover work without interim fallbacks. |
| Q-Commercial | Money stays **outside** the product. No in-product payment. Do not say the service is free. |
| Q-Access **(2026-11-10)** | Only Staff and Admin use the system. Rename seeker to **Candidate** (record, not login). No Candidate panel. |
| Q-Onboarding **(2026-11-10)** | Documents arrive via WhatsApp to Staff; Staff uploads; system classifies, flags gaps, builds CV/cover + combined PDF. |
| Q-Delete **(2026-11-10; updated)** | Staff or Admin may delete a Candidate; Joinera clears GMX credentials + stored messages. GMX account at provider is **not** auto-deleted. |
| Q-Swipe **(2026-11-10; updated 2026-11-16)** | Recommended swipe dropped. Eligible matches become **`proposed`**. Staff/Admin pick the normal queue on the Candidate profile (**Queue selected**). Staff may force-queue a skipped job. |
| Q33 **(2026-11-11)** | **DE only.** Scrape jobs + Ausbildung in Germany only. Non-DE off. |
| Q12 **(2026-11-12; apply target updated 2026-11-18)** | **Intake boards:** **Ausbildung.de** + **Azubiyo** + **arbeitsagentur.de** (Jobbörse + Ausbildungssuche). **Apply:** company career/apply URL from each listing — not board Easy-Apply. |
| Q-Intake **(2026-11-11)** | Once daily. New = new **external job id**. Scrape only — no Staff paste/import. Jobs + Ausbildung. |
| Q-Listing-Fields **(2026-11-11)** | Minimum store: title, company, location/city, listing URL, apply URL (if different), description, source site, external job id, job vs Ausbildung, language requirement if present, posted/found date. |
| Q-Match-Hard **(2026-11-11)** | Auto never-match: **(C)** outside Candidate industry · **(E)** job not in Germany · **(D)** listing requires Schulabschluss/degree Candidate lacks. |
| Q-Match-Soft **(2026-11-11 open — ask PM)** | **(A) language-level** and **(B) city/province** mismatch: list/visible for Admin; **do not** auto-exclude until PM decides. |
| Q-Match-Who **(2026-11-11; updated 2026-11-16)** | Match only Candidates with ready GMX + complete profile. Rules filter → AI rank → **`proposed`** → Staff Queue selected → `queued` → `preparing_docs` → `applying`. |
| Q-Apply-Path **(2026-11-18)** | **Company-site Mode B:** Playwright + AI form-discover → map Candidate+docs → fill/upload/submit on employer site. Boards are intake only. No board Easy-Apply as primary path. No per-employer coded adapters. On block/unfillable: pause + Staff Plan B. |
| Q-Site-Register **(2026-11-13 later; re-scoped 2026-11-18)** | **Not in v1.** After **company-site** auto-apply works, optionally auto-register/verify **board** accounts if still needed for intake — not for primary apply. Do not block company-site apply on missing board `SiteIdentity`. |
| Q-Apply-Success **(2026-11-11)** | Success = site confirmation **or** clean submit without error. |
| Q-Staff-Ops **(2026-11-11; updated 2026-11-16)** | Staff onboard Candidates, select proposed matches on the Candidate profile, and watch failures/mailbox/blocks. Matcher still proposes; Staff chooses which enter the apply queue. |
| Q17 **(2026-11-12)** | Per-Candidate Application cap is **dynamic from env** (default **10**). Cap window is also **dynamic from env**: `daily` **or** `weekly`. Meaning: each Candidate may have up to N distinct job Applications in that window so AI can send tailored CV/cover per job — not N CVs stored on the profile, and not all Candidates applying to one listing. |
| Q18 **(2026-11-11 min)** | Detect CAPTCHA/2FA/login wall → pause + alert Staff. Richer challenge policy still open. |
| Q31 **(open)** | Worker pool / SLOs open; not sized for 1000 concurrent day one. |
