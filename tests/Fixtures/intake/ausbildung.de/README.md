# Ausbildung.de intake fixtures

**Capture date:** 2026-11-11 (trimmed fixtures mirroring live SSR / JobPosting markup for Plans 2B–2C).

**Source key:** `ausbildung.de` (exact).

## URL patterns

| Purpose | Example URL | Fixture file |
|---------|-------------|--------------|
| Search / list | `https://www.ausbildung.de/suche/` | `search_page_1.html` |
| Detail (with education) | `https://www.ausbildung.de/stellen/{slug}-{uuid}/` | `detail_sample.html` |
| Detail (no education) | same pattern; Fake path override | `detail_without_education.html` |

`external_id` = trailing UUID on `/stellen/{slug}-{uuid}/` (list URL). Do **not** use JobPosting `identifier.value` (different UUID on the live site).

## List selectors (Plan 2B)

| Field | Selector / derivation |
|-------|------------------------|
| Card | `[data-testid="jp-card"]` |
| Link | `a[href*="/stellen/"]` |
| Title | `[data-testid="jp-title"]` |
| Company | `[data-testid="jp-customer"]` (strip leading `bei`) |
| City | `[data-testid="jp-branches"]` (text before ` + ` when present) |

## Detail selectors (Plan 2C)

| Field | Source |
|-------|--------|
| title / company / city / country / description / posted_at / education | `application/ld+json` `@type=JobPosting` |
| education fallback | fact box title `Schulabschluss` → sibling value |
| apply_url | `a[data-testid="application-button"]` href (if ≠ listing url) |
| language_requirement / category | null unless clearly labeled (do not invent) |

## Re-capture locally (manual curl only)

Keep at most one search page and one detail page. Trim scripts, ads, and tracking before committing.

```bash
curl -L -A "JoineraFixtureCapture/1.0" -o search_page_1.html "https://www.ausbildung.de/suche/"
curl -L -A "JoineraFixtureCapture/1.0" -o detail_sample.html "https://www.ausbildung.de/stellen/<slug>-<uuid>/"
```

Do not commit cookies, auth headers, or credentials. No Playwright for list/detail intake in 2C.
