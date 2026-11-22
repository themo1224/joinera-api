# Company-site staging soak (Plan 34)

Run many real-shaped applies against mock ATS fixtures before go-live.

## Prerequisites

- Node 20+ and Playwright browsers installed in `browser-worker/`
- No live company submits in this soak (fixtures only)

## Commands

```bash
cd browser-worker
npm run test:company-site-soak
```

Optional:

```bash
COMPANY_SITE_SOAK_ROUNDS=3 COMPANY_SITE_SOAK_MIN_SUCCESS_RATE=0.8 npm run test:company-site-soak
```

Also keep the deterministic suites green:

```bash
npm run test:form-inspect
npm run test:form-match
npm run test:form-fill
npm run test:safe-secrets
npm run test:pacing
npm run test:company-site-mock
npm run test:company-site-ats
```

## Pass bar

- Every challenge fixture returns the expected `challenge` detail
- Success fixtures meet `COMPANY_SITE_SOAK_MIN_SUCCESS_RATE` (default **0.7**)
- Exit code **0** only when the soak summary `pass` is true

## Live inspect (optional, not part of CI soak)

```bash
COMPANY_SITE_LIVE=1 npm run probe:company-site-live
```

Requires `apply_url` values in `fixtures/real-sites-catalog.json` live rows. **Inspect only — never submits.**
