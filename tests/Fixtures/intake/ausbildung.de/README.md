# Ausbildung.de intake fixtures

**Capture date:** 2026-11-11 (trimmed fixtures for Plans 2A–2D).

**Source key:** `ausbildung.de` (exact).

## URL patterns

| Purpose | Example URL | Fixture file |
|---------|-------------|--------------|
| Search page 1 | `https://www.ausbildung.de/suche/` | `search_page_1.html` |
| Search page N | `https://www.ausbildung.de/suche/?page=N` | `search_page_2.html` (N≥2); empty for N≥3 in Fake |
| Detail (with education) | `https://www.ausbildung.de/stellen/{slug}-{uuid}/` | `detail_sample.html` |
| Detail (no education) | Fake path override | `detail_without_education.html` |

`external_id` = trailing UUID on `/stellen/{slug}-{uuid}/` (list URL). Do **not** use JobPosting `identifier.value`.

**Pagination note:** Fake honors `?page=N`. Live Ausbildung.de SSR currently may ignore `?page=` (same cards); adapter stops when a page adds zero new ids or is empty. Real XHR pagination is a follow-up.

## List selectors

| Field | Selector / derivation |
|-------|------------------------|
| Card | `[data-testid="jp-card"]` |
| Link | `a[href*="/stellen/"]` |
| Title | `[data-testid="jp-title"]` |
| Company | `[data-testid="jp-customer"]` (strip leading `bei`) |
| City | `[data-testid="jp-branches"]` (text before ` + ` when present) |

## Detail selectors

| Field | Source |
|-------|--------|
| title / company / city / country / description / posted_at / education | `application/ld+json` `@type=JobPosting` |
| education fallback | fact box title `Schulabschluss` |
| apply_url | `a[data-testid="application-button"]` |

## Re-capture locally (manual curl only)

```bash
curl -L -A "JoineraFixtureCapture/1.0" -o search_page_1.html "https://www.ausbildung.de/suche/"
curl -L -A "JoineraFixtureCapture/1.0" -o detail_sample.html "https://www.ausbildung.de/stellen/<slug>-<uuid>/"
```

Do not commit cookies or credentials. CI always uses Fake HTTP (`APP_ENV=testing` or `JOINERA_AUSBILDUNG_DE_HTTP=fake`).
