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
| 11 | Type the answers | The bot fills text boxes with the right candidate info. **Done (2026-11-20):** `typeAnswers` fills mapped text/textarea/email/tel/url/number/date via Playwright `fill`. |
| 12 | Choose options | It picks dropdowns, checkboxes, and radio buttons correctly. **Done (2026-11-20):** `chooseOptions` handles select/checkbox/radio + consent checks. |
| 13 | Attach documents | It uploads CV, cover letter, and needed files. **Done (2026-11-20):** `attachDocuments` downloads artifacts and `setInputFiles` on mapped file inputs. |
| 14 | Hit submit | It presses the company’s apply / send button. **Done (2026-11-20):** `hitSubmit` clicks apply/send submit controls. |
| 15 | Confirm success | It checks that the site accepted the application. **Done (2026-11-20):** `confirmSuccess` reads `[data-confirmation]` / thank-you text. |
| 16 | Record the result | Success or failure is saved on the application record. **Done (2026-11-20):** worker `outcome: success` + `form_plan`; Laravel marks Submitted and stores confirmation + plan. |

---

## Phase 4 — Multi-step  
*Handle longer flows: next pages, accounts, and odd company wizards.*

| # | Plan name | Short description |
| --- | --- | --- |
| 17 | Next-page flows | The bot continues when the form has several steps. **Done (2026-11-20):** step loop with Next/Weiter vs final Absenden; max 5 steps; `form_plan.steps` audit. |
| 18 | Keep context | Answers from step 1 still make sense on step 2. **Done (2026-11-20):** `mergePriorAnswers` + `answers_all`; overlapping fields reuse prior values. |
| 19 | Login walls | If the company forces an account, we detect it cleanly. **Done (2026-11-20):** `detectChallenge` login heuristics; per-step check; `login_wall` challenge. |
| 20 | Soft account create | When signup is simple, we try it with the GMX apply email. **Done (2026-11-20):** one soft signup with GMX email + session password; login-only still hard-stops. |
| 21 | Privacy / consent | Cookie banners and “I agree” boxes get handled. **Done (2026-11-20):** `dismissCookieConsent` before inspect/fill; in-form consent via Plans 8/12 + soft-signup helper. |
| 22 | Required traps | Hidden required fields and errors are found and fixed. **Done (2026-11-20):** `listHiddenRequired` + one post-submit trap retry; never invent answers. |
| 23 | Weird layouts | Unusual pages still get a best-effort fill path. **Done (2026-11-21):** open shadow + formless/contenteditable locate; same-origin apply iframe pick; `layout_fallbacks` audit. |
| 24 | End-of-flow proof | Final “thank you” / confirmation screens are recognized. **Done (2026-11-21):** ranked visible markers + apply-sent text + URL; FP guard; `form_plan.confirmation` audit. |

---

## Phase 5 — Ops / Plan B  
*When automation cannot finish, Staff can take over safely.*

| # | Plan name | Short description |
| --- | --- | --- |
| 25 | Pause on blocks | Captcha, codes, or hard walls pause the run — no silent fail. **Done (2026-11-21):** `detectChallenge` OTP/captcha; `cookie_wall` when CMP undismissable; `form_plan.block`; open `BlockEvent.resolved_at` (no SiteIdentity required). |
| 26 | Alert Staff | Someone on the team gets a clear “needs help” signal. **Done (2026-11-21):** `AdminAlert` payload `needs_help` + message; block ops item `needsHelp` / `needsHelpMessage`. |
| 27 | Manual finish link | Staff opens the same company page and finishes by hand. **Done (2026-11-21):** `companyApplyUrl` on block + application ops items; Blocks + Queue UI “Open company apply page”. |
| 28 | Safe secrets | Passwords and logins stay protected and audited. **Done (2026-11-21):** soft-signup password session-only; worker `scrubApplyResult` + Laravel `ScrubSecrets`; break-glass audit unchanged. |
| 29 | Retry smart | Soft failures can retry; hard ones do not loop forever. **Done (2026-11-21):** job retries only `ModeBRetryableException`; challenge/hard stop; company retry without SiteIdentity; clears open blocks. |

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

## Plan notes (3–29)

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
- **Evidence:** migration `add_form_plan_to_apply_attempts`, `ModeBFormPlanPersistTest`.

### 11 — Type the answers
- Mapped `text` / `textarea` / `email` / `tel` / `url` / `number` / `date` → Playwright `fill`.
- Skips select/checkbox/radio/file/consent (plans 12–13).
- **Evidence:** `form-fill.js` `typeAnswers`, `npm run test:form-fill`.

### 12 — Choose options
- `selectOption` by label/value; checkboxes/radios/`consent` via `check`.
- Locates by id, then name, then label (covers privacy without HTML id).
- **Evidence:** `chooseOptions`, mock `#stadt` + Datenschutz checkbox.

### 13 — Attach documents
- Downloads artifact URLs to a temp dir, then `setInputFiles` on mapped CV/cover inputs.
- **Evidence:** `attachDocuments`, company-site mock artifact server.

### 14 — Hit submit
- Clicks `[data-testid="apply-submit"]` / submit buttons / common DE-EN labels.
- **Evidence:** `hitSubmit`.

### 15 — Confirm success
- Prefers `[data-confirmation]`; falls back to thank-you / eingegangen text.
- Challenge markers on the result page still return `challenge`.
- **Evidence:** `confirmSuccess`, mock `success.html` → `company-mock-1`.

### 16 — Record the result
- Worker returns `outcome: success` + `confirmation_reference` + `form_plan` (incl. `fill` audit).
- Laravel `markSubmitted`: app → Submitted; attempt kind success; stores confirmation + form_plan.
- Fail paths unchanged: `missing_info`, challenge, retryable.
- **Evidence:** `RunModeBSubmitAfterDocsReady`, `ModeBFormPlanPersistTest::test_success_persists_form_plan_and_confirmation`, `npm run test:company-site-mock`.

### 17 — Next-page flows
- After filling the visible step: confirmation → else Next/Weiter → else final Absenden/Submit.
- On Next: reload/settle, re-inspect, rematch, fill again (max 5 steps).
- One-page applies unchanged; 2-step mock proves the loop.
- Stop if navigation missing (`step_nav_not_found`) or cap hit (`max_steps_exceeded`).
- `form_plan.steps` audits each page (feeds Plan 18; does not replace it).
- **Evidence:** `classifyStepAction` / `hitNext`, company-site step loop, `npm run test:form-fill` + `test:company-site-mock` (one-page + two-step).

### 18 — Keep context
- Depends on Plan 17 step loop; feeds clearer `form_plan` into later Phase 4 stops.
- Carry mapped/consent answers across steps (stable key: name → id → source).
- Each page: rematch from candidate pack, then merge prior answers before fill/ready.
- Prefer prior value on overlap; never invent for new required unknowns (Plan 9).
- `form_plan.answers` = current step; `form_plan.answers_all` = cumulative for Staff.
- **Evidence:** `mergePriorAnswers`, company-site context upsert, step2 overlapping email fixture, `npm run test:form-match` + `test:company-site-mock`.

### 19 — Login walls
- Detect company pages that require login/account (password + Anmelden/Login/Registrieren cues).
- Stop with `challenge` / `login_wall`; do not invent credentials (signup is Plan 20).
- Re-check every step so walls after Next are caught; keep captcha/`data-challenge` behavior.
- Laravel already records challenge + BlockEvent — no API contract change.
- **Evidence:** `detectChallenge` login heuristics, company-site per-step check, `login-wall.html`, `npm run test:form-inspect` + `test:company-site-mock`.

### 20 — Soft account create
- On login wall: if page is simple signup (Registrieren / Create account), try once with GMX `apply_email`.
- Session-only generated password; never persist or return the secret (Plan 28 later).
- Login-only Anmelden pages still hard-stop as `login_wall`.
- Failure / verify-email / captcha → `challenge` (`soft_signup_failed` or existing kinds).
- **Evidence:** `trySoftAccountCreate`, company-site soft-signup branch, soft-signup mock, `npm run test:company-site-mock`.

### 21 — Privacy / consent
- Dismiss cookie/CMP overlays before inspect/fill (and again each step / after nav); prefer necessary cookies, else accept-all.
- In-form Datenschutz/AGB still via Plan 8/12 `consent` answers; soft-signup reuses `checkFormConsentBoxes`.
- Best-effort only — no `cookie_wall` challenge (Plan 25). Audit: `form_plan.cookie_consent`.
- `listQuestions` skips CMP container inputs so banner widgets are not apply fields.
- **Evidence:** `dismissCookieConsent`, `cookie-banner.html`, `npm run test:form-inspect` + `test:company-site-mock`.

### 22 — Required traps
- Empty required `type=hidden` inputs listed separately (not via `listQuestions`); map `source`/`job_id`/… from `application_id` only.
- Pre-fill mapped hiddens; if still missing → `missing_info` (Plan 9).
- After failed Submit/Next while still on form: `detectFormErrors` → one re-inspect/rematch/re-fill/resubmit.
- Never invent values for revealed required fields — rematch from candidate pack only.
- Audit: `form_plan.required_traps` (`detected`, `fixed`, `attempts`, `fields`).
- **Evidence:** `required-traps.js`, `required-traps.html`, `npm run test:form-inspect` + `test:company-site-mock`.

### 23 — Weird layouts
- `listQuestions` walks open shadow roots (depth ≤ 2); sibling labels; formless `[role=form]` snippet.
- `locateControl` fallbacks: role/placeholder/sibling; contenteditable via `pressSequentially`.
- `pickApplyFrame` for same-origin apply iframes; `stillOnForm` widened for formless submit cues.
- Audit: `form_plan.layout_fallbacks` (`shadow_used`, `iframe_src`, `locate_strategies`).
- Non-goals: closed shadow, cross-origin embeds, full ATS widgets.
- **Evidence:** `weird-layouts.js`, `weird-no-form.html`, `weird-shadow.html`, `npm run test:form-inspect` + `test:company-site-mock`.

### 24 — End-of-flow proof
- Ranked `confirmSuccess`: visible data markers → tight DE/EN apply-sent phrases → URL `/success|/danke|…`.
- Rejects weak text while apply submit/next still visible (footer “Vielen Dank” false positives).
- Confirm on top-level page then work frame; open-shadow marker/text scan (depth ≤ 2).
- Audit: `form_plan.confirmation` (`detected`, `method`, `reference_source`, `page_url`).
- Boards (`azubiyo` / `arbeitsagentur`) reuse shared helper.
- **Evidence:** `confirmSuccess`, `success-text-only.html`, `apply-thanks-boilerplate.html`, `npm run test:form-fill` + `test:company-site-mock`.

### 25 — Pause on blocks
- Detect captcha widgets, OTP/2FA code walls, login walls, and undismissable cookie/CMP overlays.
- Return `outcome: challenge` immediately — never invent answers or continue past a hard wall.
- Audit: `form_plan.block` (`kind`, `page_url`, `paused`).
- Laravel: persist `form_plan` on challenge; open `BlockEvent` with `resolved_at` null (works without board `SiteIdentity`); still pause identity when present.
- New kind: `cookie_wall` (deferred from Plan 21).
- **Evidence:** `detectChallenge` / `isCookieBannerVisible`, captcha/two-factor/cookie-wall fixtures, `ResolveOpenBlockEvents`, `ModeBChallengeBlockTest`, `StaffBlockEventListTest`, `npm run test:form-inspect` + `test:company-site-mock`.

### 26 — Alert Staff
- Challenge → `RecordApplyChallengeAlert` writes `AdminAlert` with `needs_help: true` and a plain-English message (who / job / kind / site).
- Ops blocks list exposes `needsHelp` + `needsHelpMessage` so Staff see an open pause without digging.
- **Evidence:** `RecordApplyChallengeAlert`, `BuildBlockAlertOpsItem`, `ModeBChallengeBlockTest`, `StaffBlockEventListTest`.

### 27 — Manual finish link
- Same company `apply_url` (fallback listing URL) on block alerts and application ops payloads as `companyApplyUrl`.
- Staff UI: Blocks row action + Queue application detail CTA open that URL in a new tab.
- **Evidence:** `BuildBlockAlertOpsItem`, `BuildApplicationOpsItem`, admin Blocks/Queue UI, `StaffBlockEventListTest`.

### 28 — Safe secrets
- Soft-signup password stays session-only and is never returned in worker results (Plan 20).
- Worker `scrubApplyResult` + Laravel `ScrubSecrets` redact password/secret keys before persistence.
- Board break-glass reveal remains reason-required + audited (`BreakGlassRevealSiteSecret` / `StaffAccessAudit`).
- **Evidence:** `safe-secrets.js`, `ScrubSecrets`, `npm run test:safe-secrets`, `ScrubSecretsTest`.

### 29 — Retry smart
- Soft: `outcome: retryable` → `ModeBRetryableException` → job `tries=3` with backoff.
- Hard / challenge: persist attempt and return (no throw) so the job does not loop.
- Staff retry: company-site applies work without board `SiteIdentity`; clears open `BlockEvent`s and unpauses identity when present.
- **Evidence:** `SubmitApplicationJob`, `RetryApplyApplication`, `ResolveOpenBlockEvents`, `StaffApplicationRetryApplyTest`.

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
