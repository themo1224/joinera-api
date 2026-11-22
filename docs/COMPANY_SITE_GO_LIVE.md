# Company-site go-live checklist (Plan 35)

Short launch list. Every row must be green before production Mode B volume.

## Worker

- [ ] `npm run test:form-inspect`
- [ ] `npm run test:form-match`
- [ ] `npm run test:form-fill`
- [ ] `npm run test:safe-secrets`
- [ ] `npm run test:pacing`
- [ ] `npm run test:company-site-mock`
- [ ] `npm run test:company-site-ats`
- [ ] `npm run test:company-site-soak` (Plan 34 pass)

## API

- [ ] `php artisan test --filter=ModeB` (or full `composer test` if time allows)
- [ ] Targeted: `ModeBChallengeBlockTest`, `ModeBFormPlanPersistTest`, `ApplySlotLimiterTest`, `StaffOpsReportTest`, `StaffApplicationRetryApplyTest`, `ModeBFailureCatalogTest`
- [ ] `JOINERA_MODE_B_DRIVER=http`
- [ ] `JOINERA_MODE_B_WORKER_URL` points at the worker
- [ ] `JOINERA_MODE_B_WORKER_SECRET` matches worker `MODE_B_WORKER_SECRET` (env only — never commit)
- [ ] Anti-block layers reviewed: leave null to keep inactive, or set jitter / hourly / cooldown intentionally

## Worker runtime

- [ ] `MODE_B_WORKER_MODE=live`
- [ ] Optional pacing: `MODE_B_PACING_MIN_MS` / `MODE_B_PACING_MAX_MS`
- [ ] `ALLOW_BOARD_APPLY=0` (company-site only unless a board probe is approved)

## Ops / Staff

- [ ] Overview shows success / challenge / Staff-help signals (Plan 33)
- [ ] Blocks list shows `failureLabel` + **Open company apply page** (Plans 27/31)
- [ ] Staff know: open Blocks → finish by hand on company URL → retry when cleared

## Staging proof

- [ ] Staging soak green (`docs/COMPANY_SITE_STAGING_SOAK.md`)
- [ ] At least one end-to-end apply on staging with a real-shaped company mock (or approved live inspect)
- [ ] Challenge path verified once (captcha / login wall → Staff alert → manual finish link)

## Go / no-go

**Go** only if every checkbox above is green and no open P0 Mode B defects remain.

**No-go** if soak fails, secrets mismatch, board apply is accidentally enabled, or Staff cannot see needs-help + company URL.
