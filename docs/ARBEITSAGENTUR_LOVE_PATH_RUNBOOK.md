# Arbeitsagentur.de love-path runbook

**Goal:** Prove one Candidate through **proposed → Queue selected → docs → Mode B** on **arbeitsagentur.de**.  
**Success:** Application `submitted` **or** a visible challenge/pause with an ApplyAttempt (never a silent hang).  
**Not this run:** Azubiyo / Ausbildung.de as the proof (see [Azubiyo runbook](./AZUBIYO_LOVE_PATH_RUNBOOK.md)).

**Product lock (PRD 1.8 / 2026-11-18):** Boards are **intake only**; production Mode B target is the **company apply URL** (AI form-discover + Playwright). This runbook is the **interim** BA board-submit proof until the company-site worker ships.

Related: [browser-worker README](../browser-worker/README.md) · [PRD](./prd/PRD.md)

---

## Prerequisites

Candidate must have:

1. Profile **approved** + complete  
2. GMX mailbox **ready**  
3. Healthy unpaused **arbeitsagentur.de** `SiteIdentity`  
4. At least one **`proposed`** match whose listing `source` is `arbeitsagentur.de`

### SiteIdentity (no Staff create UI yet)

```bash
cd d:\dev\VC\joinera\api
php artisan tinker
```

```php
$u = \App\Models\User::find(USER_ID);
\App\Models\SiteIdentity::query()->updateOrCreate(
    ['user_id' => $u->id, 'site' => 'arbeitsagentur.de'],
    [
        'site_login' => 'candidate-username',
        'secret' => 'candidate-password',
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
```

**Worker** (`browser-worker/.env`):

```env
PORT=3911
MODE_B_WORKER_SECRET=dev-shared-secret
API_BASE_URL=http://127.0.0.1:8000
MODE_B_WORKER_MODE=mock
ARBEITSAGENTUR_LOGIN_URL=https://www.arbeitsagentur.de/login
```

For live board proof, set `MODE_B_WORKER_MODE=live` and restart the worker.

### Four terminals

Same as Azubiyo: API (`php artisan serve`), queues (`docs,apply,...`), Admin (`npm run dev`), Mode B worker (`npm start` after `npx playwright install chromium`).

---

## Preflight

```bash
cd d:\dev\VC\joinera\browser-worker
npm run test:arbeitsagentur-mock
```

Must exit 0 before and after any worker change.

---

## Phase A — Pipeline proof (HTTP + worker `mock`)

Expect: Application **`submitted`** + ApplyAttempt **`success`**.

1. Admin → Candidate detail → **Proposed matches**.  
2. Select one **arbeitsagentur.de** row → **Queue selected**.  
3. Admin → `/dashboard/queue` → open the new Application.  
4. Watch status: `queued` → `preparing_docs` → `applying` → `submitted`.  
5. Confirm CV + cover present, success attempt listed.

### Evidence — Phase A (API)

```bash
cd d:\dev\VC\joinera\api
php artisan test --filter=ArbeitsagenturDeLovePathPipelineTest
```

Worker mock smoke:

```bash
cd d:\dev\VC\joinera\browser-worker
npm run test:arbeitsagentur-mock
# expect outcome=success, confirmation_reference=mock-arbeitsagentur-ok
```

---

## Phase B — Live board

1. Set worker `MODE_B_WORKER_MODE=live`, restart `npm start`.  
2. Ensure the proposed listing has a real Jobbörse / Ausbildungssuche apply URL.  
3. Login URL default: **`https://www.arbeitsagentur.de/login`** (override with `ARBEITSAGENTUR_LOGIN_URL`).  
4. Repeat Queue selected → docs → apply for **one** arbeitsagentur.de match.  
5. Acceptable endings:
   - **`submitted`** + success attempt, or  
   - **`applying`** + challenge attempt (`captcha` / `two_factor` / `login_wall`) + open BlockEvent + paused SiteIdentity.

Live login probe (no credentials):

```bash
cd d:\dev\VC\joinera\browser-worker
npm run probe:arbeitsagentur-live
```

**Note:** Live BA apply is often a multi-step Bewerbungsmappe / portal send. If file inputs or a clear confirmation are missing, the worker **fails closed** with `challenge` / `retryable` rather than inventing answers.

---

## Commands cheat sheet

```bash
cd d:\dev\VC\joinera\browser-worker
npm run test:arbeitsagentur-mock
npm run probe:arbeitsagentur-live

cd d:\dev\VC\joinera\api
php artisan test --filter=ArbeitsagenturDeLovePathPipelineTest
```
