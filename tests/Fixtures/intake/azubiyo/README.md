# Azubiyo intake fixtures

**Capture date:** 2026-11-12 (trimmed fixtures for Plans 3A–3C).

**Source key:** `azubiyo` (exact).

Trimmed HTML for PHPUnit Fake HTTP. List cards aligned to live SSR (`job-offer-teaser`). Detail uses live-shaped JobPosting JSON-LD + apply link. Do not copy Ausbildung.de selectors.

## URL patterns (provisional public)

| Purpose | Example URL | Fixture file |
|---------|-------------|--------------|
| Search (Stellenmarkt) | `https://www.azubiyo.de/stellenmarkt/` | `search_page_1.html` |
| Search page N | `https://www.azubiyo.de/stellenmarkt/{n}/` | `search_page_1.html` (Fake maps stellenmarkt/ausbildung to page 1 until Plan 3D) |
| Ausbildung listing | `https://www.azubiyo.de/ausbildung/` | `search_page_1.html` |
| Detail (with education) | `https://www.azubiyo.de/stellenanzeigen/{slug}_{id}/` | `detail_sample.html` |
| Detail (no education) | Fake path override | `detail_without_education.html` |

Fake root: `tests/Fixtures/intake/azubiyo`.

## external_id (Plan 3B/3C)

Trailing token after the last `_` on `/stellenanzeigen/{slug}_{id}/`, lowercased.

Regex: `#/stellenanzeigen/[^/]+_([a-z0-9]+)/?$#i`

Examples: `66195a74`, `bga9a4a4`. Do **not** use JobPosting `identifier` (`AZY-…`) as `external_id`. Cards without a trailing id are skipped.

## List selectors (live-aligned)

| Field | Selector / derivation |
|-------|------------------------|
| Card | `div.job-offer-teaser` |
| Link | `a[href*="/stellenanzeigen/"]` |
| Title | `span.h3` inside the link |
| Company | `span.text-secondary` inside the link |
| City | last `ul.list-unstyled li` text (PLZ + Ort); skip date-only lines |

## Detail selectors (Plan 3C)

| Field | Source |
|-------|--------|
| title / company / city / country / description / posted_at / education | `application/ld+json` `@type=JobPosting` |
| title fallback | `//h1` |
| company / city fallback | `[data-azy-company]` / `[data-azy-city]` (strip trailing `, Deutschland`) then list item |
| description fallback | `[data-azy-description]` |
| education fallback | text matching `Schulabschluss:\s*(…)` |
| apply_url | first `a` whose text contains `Bewerben` / `Jetzt bewerben` and href contains `bewerben` (≠ listing URL) |

## Re-capture locally (manual curl only)

```bash
curl -L -A "JoineraFixtureCapture/1.0" -o search_page_1.html "https://www.azubiyo.de/stellenmarkt/"
curl -L -A "JoineraFixtureCapture/1.0" -o detail_sample.html "https://www.azubiyo.de/stellenanzeigen/<slug>_<id>/"
```

Trim huge logo/SVG/nav blocks before committing; keep JobPosting JSON-LD and apply links. Do not commit cookies or credentials. CI always uses Fake HTTP (`APP_ENV=testing` or `JOINERA_AZUBIYO_HTTP=fake`).

**Not enabled** in production `joinera.sites.v1` / default registry until Plan 3D.
