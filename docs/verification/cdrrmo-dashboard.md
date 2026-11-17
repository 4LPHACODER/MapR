# CDRRMO dashboard polish verification

Scope: `/cdrrmo/`, its dashboard sections, and the shared map's compact variant.

## Changes

- Organize filters, summary totals, assessment map, review queue, and activity into separate dashboard components.
- Reuse the civic palette, Roboto typography, shared radius tokens, and PrimeVue small controls with 32px desktop geometry. Preserve larger coarse-pointer controls.
- Remove outer focus rings within the dashboard and its Select/calendar overlays. Retain field borders, button fills, link underlines, and tonal keyboard highlights.
- Replace the shared map and selection panel's native controls with PrimeVue. Keep other map instances at their default density.
- Share dashboard incident/barangay scope with the embedded map. Preserve existing report destinations and authorization.
- Keep previous content mounted during refresh, cancel superseded requests, and provide inline failure/retry feedback.

## Evidence

- `docker compose exec -T vite npm run build`: passed. Existing large-chunk warning remains.
- `docker compose exec -T app php artisan test --compact --filter=CdrrmoWorkspaceTest`: 7 tests, 24 assertions passed.
- `docker compose exec -T vite npm run test:map`: 17 tests passed.
- `npm run ui:inventory -- --file=<component>` inside the Vite container: zero native controls in DashboardPage, MapWorkspace, and AreaSummaryPanel.
- Premium strict static audit: zero findings; JSON retained at `/tmp/mapr-cdrrmo-dashboard-audit.json`.
- `designmd lint DESIGN.md`: zero errors; six existing orphaned-token warnings.
- `git diff --check`: passed.

Local Chromium browser verification uses the actual Vue application, PrimeVue theme, and shared map with fixture API responses and a local Mapbox style. Desktop viewport: 1440 × 1100; phone viewport: 390 × 844. Verified small-control heights, long incident names, Select width and keyboard/Escape behavior, calendar popup, URL filters, review pagination, map rendering and layers, polygon drawing and report-preparation eligibility, search clearing with focus restoration, narrow-screen overflow, empty states, refresh failure/retry with preserved content, and no page errors. Screenshots and temporary runner are retained under `/tmp/mapr-cdrrmo-ui/`. The signed-in production-data workflow is not part of this fixture verification.

## Follow-up polish and motion

- Tighten header hierarchy by grouping preparation guidance beneath its action. Align summary labels, retain timestamp numerals, and use the shared radius on map basemap controls.
- Fix the remaining Select ring at its source: PrimeVue paints focus on its wrapper even when only the inner input has DOM focus. Reset wrapper outlines/shadows and component ring tokens within the dashboard; retain blue borders and tonal keyboard feedback.
- Add pointer-only 160ms press feedback and a compact map layer disclosure using the existing easing. Opening takes 200ms; closing takes 150ms. Rapid reversals preserve the painted frame. Keyboard activation is instant. Closed content becomes inert immediately, and reduced motion uses only a 100ms fade.
- Chromium verifies every dashboard filter's input and wrapper focus styles, checkbox focus, popup widths, 32px controls, pointer/keyboard disclosure, interruption, reduced-motion keyframes, desktop/mobile overflow, pagination, polygon selection, refresh failure/retry, and empty states. No page errors. Runner: `/tmp/mapr-cdrrmo-ui/polish-check.mjs`.
- Production build and seven CDRRMO workspace tests pass. Impeccable's mechanical scan found one advisory about the previous 5px basemap-button radius; replaced it with the existing radius token. No new dependency or server behavior change.

## Existing limitations

WorkspaceDashboardController filters summary/review queries by incident and barangay. The existing period and assessment-status controls and query parameters remain available, but their server filtering is unchanged. Registry-wide verification and consolidated totals now state their scope explicitly. This visual change does not alter report lifecycle, data eligibility, or geographic policy.
