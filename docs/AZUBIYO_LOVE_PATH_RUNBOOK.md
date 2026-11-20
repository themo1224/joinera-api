# Azubiyo love-path runbook

**Goal:** Prove one Candidate through **proposed → Queue selected → docs → Mode B** on **Azubiyo only**.  
**Success:** Application `submitted` **or** a visible challenge/pause with an ApplyAttempt (never a silent hang).  
**Not this run:** Ausbildung.de, arbeitsagentur.de, ApplyDemoSeeder as the proof.

**Product lock (PRD 1.8 / 2026-11-18):** Boards are **intake only**; production Mode B target is the **company apply URL** (AI form-discover + Playwright). This runbook remains the **interim** Azubiyo board-submit proof until the company-site worker ships. Prefer treating Azubiyo `external_apply:` outcomes as the handoff toward that path.

Related: [CEO seeded demo](./APPLY_CEO_DEMO_WALKTHROUGH.md) · [browser-worker README](../browser-worker/README.md) · [PRD](./prd/PRD.md)

---

## Prerequisites

Candidate must have:

1. Profile **approved** + complete  
2. GMX mailbox **ready**  
3. Healthy unpaused **Azubiyo** `SiteIdentity` (`site=azubiyo`)  
4. At least one **`proposed`** match whose listing `source` is `azubiyo`

### SiteIdentity (no Staff create UI yet)

Staff can only list identities. Attach via tinker (replace `USER_ID`, login, password):

```bash
cd d:\dev\VC\joinera\api
php artisan tinker
```

```php
$u = \App\Models\User::find(USER_ID);
\App\Models\SiteIdentity::query()->updateOrCreate(
    ['user_id' => $u->id, 'site' => 'azubiyo'],
    [
        'site_login' => 'candidate@example.com',
        'secret' => 'azubiyo-password',
        'state' => \App\Enums\SiteIdentityState::Healthy,
        'paused' => false,
    ]
);
```

### Env (secrets must match)

**API** (`api/.env`):

```env
QUEUE_CONNECTION=database
JOINERA_MODE_B_DRIVER=http
JOINERA_MODE_B_WORKER_URL=http://127.0.0.1:3911
JOINERA_MODE_B_WORKER_SECRET=dev-shared-secret
# Docs LLM (skip if using fake/tests):
# JOINERA_AI_DRIVER=openrouter
# OPENROUTER_API_KEY=...
```

**Worker** (`browser-worker/.env`):

```env
PORT=3911
MODE_B_WORKER_SECRET=dev-shared-secret
API_BASE_URL=http://127.0.0.1:8000
MODE_B_WORKER_MODE=mock
```

For live board proof, set `MODE_B_WORKER_MODE=live` and restart the worker.

### Four terminals

```bash
# 1 — API
cd d:\dev\VC\joinera\api
php artisan serve

# 2 — queues
cd d:\dev\VC\joinera\api
php artisan queue:work database --queue=docs,apply,alerts,match

# 3 — Admin
cd d:\dev\VC\joinera\admin
npm run dev

# 4 — Mode B worker
cd d:\dev\VC\joinera\browser-worker
npm install
npx playwright install chromium
npm start
```

---

## Preflight

```bash
cd d:\dev\VC\joinera\browser-worker
npm run test:azubiyo-mock
```

Must exit 0 before and after any worker change.

---

## Phase A — Pipeline proof (HTTP + worker `mock`)

Expect: Application **`submitted`** + ApplyAttempt **`success`**.

1. Admin → Candidate detail → **Proposed matches**.  
2. Select one **Azubiyo** row → **Queue selected**.  
3. Admin → `/dashboard/queue` → open the new Application.  
4. Watch status: `queued` → `preparing_docs` → `applying` → `submitted`.  
5. Confirm CV + cover present, `docsFailureCode` null, success attempt listed.

If status sticks on `applying` with a **hard_fail** attempt (`no_healthy_site` / `profile_not_approved`), fix prerequisites and re-queue or regenerate docs / re-dispatch apply — do not leave zero attempts.

### Evidence — Phase A (captured 2026-11-16)

| Gate | Result | Evidence |
| --- | --- | --- |
| Playwright mock smoke | pass | `npm run test:azubiyo-mock` → `outcome=success`, `confirmation_reference=mock-azubiyo-ok` |
| Queue selected → docs → Mode B HTTP | pass | `php artisan test --filter=AzubiyoLovePathPipelineTest` → Application `submitted`, attempt `success`, worker POST includes `site_id=azubiyo` |

| applicationId | status | hasCv | hasCover | attempt kind | stopReason | block open | notes |
| --- | --- | --- | --- | --- | --- | --- | --- |
| (test DB) | submitted | Y | Y | success | — | N | `AzubiyoLovePathPipelineTest` + Http fake worker success |

---

## Phase B — Live Azubiyo board

1. Set worker `MODE_B_WORKER_MODE=live`, restart `npm start`.  
2. Ensure the proposed listing has a real Azubiyo apply URL.  
3. Login URL used by worker: **`https://www.azubiyo.de/anmeldung/`** (not `/login/` — that 404s).  
4. Repeat Queue selected → docs → apply for **one** Azubiyo match.  
5. Acceptable endings:
   - **`submitted`** + success attempt, or  
   - **`applying`** + challenge attempt (`captcha` / `two_factor` / `login_wall`) + open BlockEvent + paused SiteIdentity on `/dashboard/blocks`.

### Evidence — Phase B (captured 2026-11-16)

| Gate | Result | Evidence |
| --- | --- | --- |
| Live login page probe | pass | `npm run probe:azubiyo-live` → `ready_for_login`, title `Anmeldung \| Azubiyo`, email+password fields visible, no captcha on page load |
| Challenge → pause path | pass | `php artisan test --filter=ModeBChallengeBlockTest` → `applying` + challenge attempt + BlockEvent + paused SiteIdentity |
| Full live submit with real Candidate credentials | **not run** | Needs real Azubiyo `SiteIdentity` + listing `apply_url` on a running API/worker stack; probe only confirmed login page is reachable |

| applicationId | status | hasCv | hasCover | attempt kind | stopReason | block open | notes |
| --- | --- | --- | --- | --- | --- | --- | --- |
| — | — | — | — | — | login_form_visible | N | Live probe only (no credentials) |
| (test DB) | applying | Y | Y | challenge | captcha | Y | `ModeBChallengeBlockTest` proves non-silent pause |

---

## Failure modes (honest)

| Symptom | Meaning | Action |
| --- | --- | --- |
| No Application after Queue selected | Cap, non-proposed, or API error | Toast / API 409 body |
| Stuck `queued` / `preparing_docs` | Docs worker / OpenRouter / PDF | Check `docs` queue + `docsFailureCode` |
| `applying` + hard_fail `no_healthy_site` | Missing/paused Azubiyo identity | Attach identity (tinker) |
| `applying` + hard_fail `profile_not_approved` | Approve profile | Admin Approve |
| Challenge + block | CAPTCHA/2FA/login wall | Expected live stop; Staff break-glass later |
| Worker `site_not_implemented` | Non-Azubiyo site | Out of scope for this runbook |

---

## Commands cheat sheet

```bash
# Mock smoke + live login probe
cd d:\dev\VC\joinera\browser-worker
npm run test:azubiyo-mock
npm run probe:azubiyo-live

# Focused API regressions for this path
cd d:\dev\VC\joinera\api
php artisan test --filter=SubmitApplicationClaimTest
php artisan test --filter=AzubiyoLovePathPipelineTest
php artisan test --filter=ModeBChallengeBlockTest
php artisan test --filter=StaffQueueSelectedMatchesTest
```

## Blockers fixed in this pass

1. **Silent claim hang** — `ClaimApplyingApplication` now writes `hard_fail` with `profile_not_approved` / `no_healthy_site` instead of exiting with zero attempts.  
2. **Wrong live login URL** — worker used `https://www.azubiyo.de/login/` (404); corrected to `https://www.azubiyo.de/anmeldung/`.
