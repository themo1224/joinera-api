# Apply CEO / PO demo walkthrough

**Audience:** CEO + Product Owner (~5–8 min)  
**Prep:** API + Admin running; Staff/Admin login; seed demo personas once.  
**Product lock (PRD 1.8):** production apply = **company site** (AI + Playwright).  
**Interim live Azubiyo board path** (proposed → Queue selected → docs → Mode B): see [AZUBIYO_LOVE_PATH_RUNBOOK.md](./AZUBIYO_LOVE_PATH_RUNBOOK.md).

```bash
cd api
php artisan db:seed --class=ApplyDemoSeeder
```

Opt-in only — not part of default `DatabaseSeeder`. Safe to re-run (resets demo applicants’ applications).

---

## Click path

1. **`/dashboard/overview`** — Funnel + attention KPIs (`queued`, `applying`, `openPauseCount`, `hardFailAttempts`). Confirm the strip is non-empty.
2. **`/dashboard/queue`** — Status strip; scan demo rows (Submitted, Docs Gated, Challenge, Retryable, Hard Fail).
3. **Open each application detail** — Status, docs flags / `docsFailureCode`, and **Apply attempts** list (success / retryable / challenge / hard_fail).
4. **Pause persona → `/dashboard/blocks`** (or Overview deep link) — Open captcha block for Demo Challenge; prove the stop is visible, not a silent hang.
5. **Close line:** every persona either reaches `submitted` or stops with a visible reason.

---

## Personas seeded

| Label | Email | What to show |
| --- | --- | --- |
| Demo Submitted | `demo.apply.a@joinera.test` | `submitted` + success attempt |
| Demo Docs Gated | `demo.apply.b@joinera.test` | `applying` + `missing_base_cv`, no CV |
| Demo Challenge | `demo.apply.c@joinera.test` | challenge attempt + open block + paused identity |
| Demo Retryable | `demo.apply.d@joinera.test` | `applying` + retryable attempt |
| Demo Hard Fail | `demo.apply.e@joinera.test` | `applying` + hard_fail attempt |

Funnel fillers: Demo Queued / Demo Preparing.
