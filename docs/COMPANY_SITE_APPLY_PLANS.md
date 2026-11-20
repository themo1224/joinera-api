# Company-site apply — 35 plans

**Purpose:** Named roadmap for auto-apply on company career sites (not job boards).  
**Audience:** Product / founders / Staff — non-technical.  
**Rule:** Boards find jobs. Company sites get the application. Staff helps only when a site blocks us.

**Lock:** PRD 1.8 (2026-11-18)

---

## Phase 1 — Wire-up  
*Connect the pipeline so we aim at the company site, not the board.*

| # | Plan name | Short description |
| --- | --- | --- |
| 1 | Company link first | Every job remembers the company’s own apply page. **Done (2026-11-18):** intake prefers off-board `apply_url`; Upsert upgrades on re-see. |
| 2 | Candidate pack ready | Profile, CV, and cover letter travel with each apply. **Done (2026-11-18):** Mode B context includes `candidate` pack + existing CV/cover artifacts. |
| 3 | Stop board apply | We no longer treat board “easy apply” as the main path. **Done (2026-11-19):** claim rejects board-only URLs (`missing_company_apply_url`); no longer requires board `SiteIdentity`; worker board adapters need `ALLOW_BOARD_APPLY=1`. |
| 4 | Open company door | The apply bot starts on the company page, every time. **Done (2026-11-19):** `runCompanySiteApply` `goto(apply_url)` with no board login; context exposes `apply_target`. |

---

## Phase 2 — Form brain  
*Teach the bot to understand what each company asks for.*

| # | Plan name | Short description |
| --- | --- | --- |
| 5 | See the page | The bot looks at the live apply page like a person would. **Done (2026-11-19):** accessibility snapshot + form HTML snippet via `seeThePage`. |
| 6 | List the questions | It names every field the company wants filled. **Done (2026-11-19):** `listQuestions` returns label/type/required/options per control. |
| 7 | Spot uploads | It knows when a CV, cover letter, or other file is required. **Done (2026-11-19):** `spotUploads` classifies file inputs as `cv` / `cover` / `other`. |
| 8 | Match our answers | It maps each question to the candidate’s real data. **Done (2026-11-20):** `matchAnswers` maps name/email/city/CV/cover/consent from the candidate pack + artifacts. |
| 9 | Flag missing info | If something is unknown, it stops instead of guessing badly. **Done (2026-11-20):** required unknowns → `missing_info:…` hard-fail; no invented answers. |
| 10 | Save the plan | The field plan is stored so we can replay or debug it. **Done (2026-11-20):** worker returns `form_plan`; Laravel stores it on `apply_attempts.form_plan`. |

---

## Phase 3 — Fill + submit  
*Fill a normal one-page form and send it.*

| # | Plan name | Short description |
| --- | --- | --- |
| 11 | Type the answers | The bot fills text boxes with the right candidate info. |
| 12 | Choose options | It picks dropdowns, checkboxes, and radio buttons correctly. |
| 13 | Attach documents | It uploads CV, cover letter, and needed files. |
| 14 | Hit submit | It presses the company’s apply / send button. |
| 15 | Confirm success | It checks that the site accepted the application. |
| 16 | Record the result | Success or failure is saved on the application record. |

---

## Phase 4 — Multi-step  
*Handle longer flows: next pages, accounts, and odd company wizards.*

| # | Plan name | Short description |
| --- | --- | --- |
| 17 | Next-page flows | The bot continues when the form has several steps. |
| 18 | Keep context | Answers from step 1 still make sense on step 2. |
| 19 | Login walls | If the company forces an account, we detect it cleanly. |
| 20 | Soft account create | When signup is simple, we try it with the GMX apply email. |
| 21 | Privacy / consent | Cookie banners and “I agree” boxes get handled. |
| 22 | Required traps | Hidden required fields and errors are found and fixed. |
| 23 | Weird layouts | Unusual pages still get a best-effort fill path. |
| 24 | End-of-flow proof | Final “thank you” / confirmation screens are recognized. |

---

## Phase 5 — Ops / Plan B  
*When automation cannot finish, Staff can take over safely.*

| # | Plan name | Short description |
| --- | --- | --- |
| 25 | Pause on blocks | Captcha, codes, or hard walls pause the run — no silent fail. |
| 26 | Alert Staff | Someone on the team gets a clear “needs help” signal. |
| 27 | Manual finish link | Staff opens the same company page and finishes by hand. |
| 28 | Safe secrets | Passwords and logins stay protected and audited. |
| 29 | Retry smart | Soft failures can retry; hard ones do not loop forever. |

---

## Phase 6 — Production harden  
*Make it reliable enough for real daily volume.*

| # | Plan name | Short description |
| --- | --- | --- |
| 30 | Real-site practice | We test against common German company career systems. |
| 31 | Failure map | Every fail gets a clear reason Staff can understand. |
| 32 | Speed & limits | Applies stay paced so we do not look like spam bots. |
| 33 | Watch the numbers | Success, block, and Staff-help rates are visible. |
| 34 | Staging soak | Run many real-shaped applies before full go-live. |
| 35 | Go-live checklist | Short launch list: what must be green before production. |

---

## Plan notes (3–10)

### 3 — Stop board apply
- **Claim:** approved profile + ready GMX + **company** `apply_url`. Board `SiteIdentity` is not required.
- **Legacy opt-in:** `JOINERA_MODE_B_ALLOW_BOARD_APPLY` / worker `ALLOW_BOARD_APPLY=1` for board love-path probes only.
- **Worker:** default route is company-site; board adapters stay behind the allow flag.
- **Evidence:** `ClaimApplyingApplication`, `SubmitApplicationClaimTest`, `board-hosts.js`, `submit.js`.

### 4 — Open company door
- Context includes `apply_target` (`company` \| `board` \| `missing`).
- `company-site.js` navigates to company `apply_url` first — never board login.
- **Evidence:** `InternalModeBContextController`, `runCompanySiteApply`, mock fixture `fixtures/mock-company-site/`.

### 5 — See the page
- After load: title, URL, accessibility snapshot, form HTML snippet.
- Challenge heuristics run before inspect.
- **Evidence:** `seeThePage` in `form-inspect.js`.

### 6 — List the questions
- Stable field list: label, type, required, options (selects), accept (files).
- Prefers accessible name / associated `<label>`.
- **Evidence:** `listQuestions`, company-site mock smoke.

### 7 — Spot uploads
- File inputs classified `cv` / `cover` / `other` from German/English labels (`Lebenslauf`, `Anschreiben`, …).
- **Evidence:** `spotUploads`, `npm run test:form-inspect` + `test:company-site-mock`.

### 8 — Match our answers
- Maps fields to candidate pack (name split, email, city, about, …) and artifacts (CV/cover).
- Privacy/consent checkboxes map to an explicit `consent` answer (not invented profile data).
- **Evidence:** `form-match.js` `matchAnswers`, `npm run test:form-match`.

### 9 — Flag missing info
- Required fields without a real mapping → `missing` (never invent phone/other uploads).
- Runner detail `missing_info:Label|…` when `ready` is false.
- **Evidence:** `flagMissingInfo`, company-site mock incomplete pack case.

### 10 — Save the plan
- Worker returns `form_plan` (fields, uploads, answers, missing, ready).
- API stores JSON on `apply_attempts.form_plan` for Staff replay/debug.
- Ready-but-unfilled stop reason: `form_plan_ready` (fill is plans 11+).
- **Evidence:** migration `add_form_plan_to_apply_attempts`, `ModeBFormPlanPersistTest`.

---

## Summary

| Phase | Name | Plans |
| --- | --- | ---: |
| 1 | Wire-up | 4 |
| 2 | Form brain | 6 |
| 3 | Fill + submit | 6 |
| 4 | Multi-step | 8 |
| 5 | Ops / Plan B | 5 |
| 6 | Production harden | 6 |
| | **Total** | **35** |

**Flow in one line:** find job on boards → open company site → understand form → fill & submit → Staff only if blocked → harden for production.
