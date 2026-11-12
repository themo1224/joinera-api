# Arbeitsagentur.de intake fixtures

**Capture date:** 2026-11-12 (trimmed JSON for Plans 4A–4E).

**Source key:** `arbeitsagentur.de` (exact).

Trimmed JSON for PHPUnit Fake HTTP. Jobsuche + Ausbildungssuche public REST shapes. Do not scrape HTML. Do not use Candidate / SiteIdentity sessions.

## Endpoints

| Channel | Purpose | Example URL | Fixture |
|---------|---------|-------------|---------|
| jobs | Search page 1 | `.../jobboerse/jobsuche-service/pc/v6/jobs?angebotsart=1&page=1&size=25` | `jobs_search_page_1.json` |
| jobs | Search page 2 | `...?page=2` | `jobs_search_page_2.json` |
| jobs | Search empty | `...?page≥3` | `jobs_search_empty.json` |
| jobs | Detail | `.../pc/v4/jobdetails/{base64(refnr)}` | `jobs_detail_sample.json` |
| ausbildung | Search page 1 (API page=0) | `.../infosysbub/absuche/pc/v1/ausbildungsangebot?sty=0&page=0&size=20` | `ausbildung_search_page_1.json` |
| ausbildung | Search page 2 (API page=1) | `...?page=1` | `ausbildung_search_page_2.json` |
| ausbildung | Search empty (API page≥2) | `...?page≥2` | `ausbildung_search_empty.json` |
| ausbildung | Detail | `.../pc/v1/ausbildungsangebot/{id}` | `ausbildung_detail_sample.json` |

Fake root: `tests/Fixtures/intake/arbeitsagentur.de`.

**Auth (server env only):** `X-API-Key` — jobs `jobboerse-jobsuche`, ausbildung `infosysbub-absuche` (public clientIds, not Candidate credentials).

**Pagination:** `max_pages` applies **per channel** (default 5). Jobs API `page` starts at 1; Ausbildungssuche API `page` starts at 0 (adapter page N → API page N-1). Stops on empty page or zero new `external_id`s.

## external_id

| Channel | Rule | Examples |
|---------|------|----------|
| jobs | `refnr` (alias `referenznummer`) | `10000-1184867112-S`, `10001-1002716922-S` |
| ausbildung | termin `id` as string | `17885`, `17886` |

Do **not** use Jobsuche `hashId` as `external_id`. Skip rows without id.

## kind

| Channel | `kind` |
|---------|--------|
| Jobbörse search (`angebotsart=1`) | `job` |
| Ausbildungssuche | `ausbildung` |

## Public listing URLs

| Channel | Rule |
|---------|------|
| jobs | `externeUrl` if present; else `{portal}/jobsuche/jobdetail/{hashId}` |
| ausbildung | `{portal}/ausbildungssuche/ausbildungsangebot/{id}` |

## Re-capture locally (manual curl only)

```bash
curl -H "X-API-Key: jobboerse-jobsuche" -o jobs_search_page_1.json \
  "https://rest.arbeitsagentur.de/jobboerse/jobsuche-service/pc/v6/jobs?angebotsart=1&page=1&size=25"
# detail: base64(refnr) e.g. 10000-1184867112-S → MTAwMDAtMTE4NDg2NzExMi1T
curl -H "X-API-Key: jobboerse-jobsuche" -o jobs_detail_sample.json \
  "https://rest.arbeitsagentur.de/jobboerse/jobsuche-service/pc/v4/jobdetails/MTAwMDAtMTE4NDg2NzExMi1T"

curl -H "X-API-Key: infosysbub-absuche" -H "Accept: application/hal+json" -o ausbildung_search_page_1.json \
  "https://rest.arbeitsagentur.de/infosysbub/absuche/pc/v1/ausbildungsangebot?sty=0&page=0&size=20"
curl -H "X-API-Key: infosysbub-absuche" -H "Accept: application/json" -o ausbildung_detail_sample.json \
  "https://rest.arbeitsagentur.de/infosysbub/absuche/pc/v1/ausbildungsangebot/17885"
```

Trim large facetten blocks before committing. Do not commit cookies or Candidate credentials. CI always uses Fake HTTP (`APP_ENV=testing` or `JOINERA_ARBEITSAGENTUR_DE_HTTP=fake`).

**Enabled** via `JOINERA_SITES_V1` (default `ausbildung.de,azubiyo,arbeitsagentur.de`) and registered in `SiteIntakeAdapterRegistry`. Add-site checklist: see comment above `sites.v1` in `api/config/joinera.php`.
