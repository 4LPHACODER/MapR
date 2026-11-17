---
version: alpha
name: MapR
description: Shared workspace navigation for Tandag City's disaster reporting workspace
colors:
  primary: '#2563eb'
  navy: '#123b70'
  canvas: '#f5f7fb'
  surface: '#ffffff'
  ink: '#172033'
  muted: '#64748b'
  line: '#e2e8f0'
  selection: '#eff6ff'
typography:
  sans:
    fontFamily: 'Roboto, Arial, sans-serif'
rounded:
  sm: '0.5rem'
  DEFAULT: '0.5rem'
  lg: '1rem'
spacing:
  sidebar-expanded: '15.5rem'
  sidebar-rail: '4.5rem'
components:
  sidebar: {}
  button: {}
---

# MapR Design System

## Overview

### Creative North Star

A quiet civic mapping workspace: legible blue navigation, precise alignment, and a narrow blue marker identifying the current task.

### Product context and register

MapR supports disaster reporting and assessment in Tandag City, Philippines. The Resident workspace helps residents submit photos, track reports, and consult hazard maps (README.md and docs/resident-report-flow.md). This is product UI, with English labels matching existing routes. Desktop uses a sidebar, tablets an icon rail, and phones bottom navigation. Preserve the established Roboto typography and blue identity; avoid promotional decoration inside working screens.

Runtime CSS remains canonical. This document mirrors existing resident tokens and the sidebar and header overlay decisions. Token mapping: resources/css/shared/layout.css `.resident-app` variables → desktop media rules → shared WorkspaceSidebar.vue, WorkspaceHeader.vue, and WorkspaceLayout.vue. Resident page styles in resources/css/resident/app.css consume those shell variables. The resident header uses the maintained PrimeVue Popover for anchored geometry and dismissal; its resident-specific view and class tokens live beside the resident shell styles. All authenticated Vue workspaces use this resident-based shell. resources/js/shared/navigation.js owns role-specific destinations, while each App retains its existing domain providers. Shared workspace CSS continues to own staff page components.

## Colors

White surfaces sit on the pale canvas. Navy identifies the brand, ink carries primary text, and muted text supports secondary labels. Blue provides focus; the pale blue selection surface and a vertical marker identify the active route without relying on color alone. Preserve existing application semantic colors elsewhere. Dashboard correction orange (#c2410c) and finalized/clear green (#047857) are established semantic exceptions to the base palette. Roboto remains the intentional application typeface throughout the dashboard and its portaled controls.

## Typography

Use the existing Roboto/Arial stack. Sidebar navigation is 13px, medium weight, with a 20px line height. The MapR wordmark is 18px bold; workspace and account context use 11px. Account names truncate with the full name available through the identity title.

## Layout

Resident desktop navigation expands to 248px and collapses to 72px. Keep navigation rows 36px high in both states with 4px gaps, 12px outer gutters, and fixed 20px icons. Icons stay at the same horizontal coordinate during collapse. Brand and header heights remain aligned at 80px. The account footer retains its geometry in both states. The sidebar owns vertical overflow on short screens; the content retains document scrolling. At 768–1023px use the rail; below 768px keep the established bottom navigation.

## Elevation & Depth

Use a thin right border on the sidebar, without a floating shadow. Existing cards retain their established soft shadow. Selected navigation uses a tonal surface, not elevation.

## Shapes

Use the shared sm radius (8px) for resident surfaces and controls. Runtime mapping: PrimeVue borderRadius.sm → --p-border-radius-sm → --resident-radius and --resident-control. Dashboard cards, summary strip, buttons, and incident dialog use this token. Preserve round avatars and semantic status pills.

## Components

### Foundational visual states

Navigation uses native router links with accessible names and current-page semantics. Hover uses a pale surface, pressed uses the border tone, and keyboard focus uses a visible blue outline. Disabled and loading states remain owned by existing PrimeVue controls.

### Buttons and actions

Sign out remains a native POST form with CSRF, presented through the existing PrimeVue Button. Keep its 36px geometry consistent with navigation in both sidebar states. The header toggle remains the canonical collapse control.

### Navigation and data display

ResidentSidebar.vue owns resident navigation labels, brand, and account footer. ResidentLayout.vue owns collapse state; ResidentHeader.vue exposes the toggle and expanded state. Keep all routes accessible in both states. Native title hints supplement persistent accessible labels for icon navigation.

### Forms and overlays

The resident header uses PrimeVue Popover for anchored account and notification views, including outside-click dismissal, Escape handling, portal placement, and focus trapping. The popovers mount inside `.resident-app` so portal content inherits resident tokens and has an opaque white surface. PrimeVue owns their stacking and anchor gap. Header buttons retain their 32px geometry. Popover action rows use compact padding and a 36px minimum height; the profile action uses a 28px icon container. The views use a white identity surface, compact icon-led links, and a centered empty state; headings carry the hierarchy without eyebrow labels. Reduced-motion preferences disable the popover and link transitions. Existing shared UI owners in resources/js/shared/ui continue to own feedback. See docs/resident-report-flow.md for reporting behavior.

### Iconography

Reuse MapIcon with fixed 20px sidebar icons. Keep text in the DOM during collapse so layout and accessible names remain stable.

### Motion

Sidebar width and content offset share `--resident-sidebar-duration` (280ms) and `--resident-sidebar-ease` (cubic-bezier(0.22, 1, 0.36, 1)). Labels fade over 140ms and move 6px; expansion delays their entrance 70ms. CSS transitions reverse naturally when interrupted. Respect prefers-reduced-motion by disabling these transitions. Never swap display or centering during collapse.

### Resident dashboard

The dashboard prioritizes report status and next steps. Use one divided summary strip followed by a full-width Recent reports table. Group the correction/draft panel and incident updates below the table, with activity charts last. Dashboard styles live in resources/css/resident/dashboard.css, imported after the resident shell. Reuse resident palette variables and established correction orange and finalized green. Dashboard actions use the canonical PrimeVue Button with size="small", 32px minimum height, and 8px radius, including router navigation rendered through Button. Keep the report table full width at every breakpoint. On phones, stack the labeled search and filter controls and contain horizontal table scrolling within its card; supporting panels stack below. Dashboard controls and the incident dialog omit outer focus rings at the user’s request. Blue field borders, tonal summary/table/pagination backgrounds, underlined report links, and button fill changes retain keyboard focus feedback. Scope the same rule to the dashboard’s portaled Select overlays. PrimeVue DataTable and Column own semantic headers, keyboard sorting, pagination, and page-size selection. PrimeVue Select and InputText own the status, updated-period, and search controls. Use compact 13px table text, 12px column labels, divided rows, and the resident palette/radius tokens. The table defaults to five rows sorted by latest update; its incident/location column provides the main reading hierarchy. Dashboard rhythm uses route-scoped --dashboard-gap and --dashboard-panel-padding (20px desktop, 16px phones). Keep the greeting at 24px/32px, section titles at 15px/22px, body and controls at 13px/20px, and metadata at 12px/18px. Search and Select controls share an explicit 32px desktop height; coarse-pointer filters retain 44px. Supporting panels use plain divided headings and a compact definition list for incident dates. Preserve the full-width ledger’s flush edges at every breakpoint.

### Resident community map

The map keeps the resident palette, Roboto hierarchy, and shared 8px surface/control radius. Its distinctive element is the geographic context card: a quiet white label above the basemap that reflects the selected hazard or area. Runtime ownership: resources/css/resident/map.css, imported by resources/js/resident.js after the resident shell, consumes the existing `--resident-*` tokens; Mapbox retains ownership of geographic rendering and attribution. This file also owns the map route's responsive layout, without changing sibling page scrolling.

Desktop presents a unified action bar, base-map shortcuts, and an initially open layers panel. Below 1024px the panel starts closed; phones use a scrollable bottom sheet with an explicit expansion control. Keep the nearest-site action prominent, location/expand actions accessible, and reference-data guidance visible below the map. Layer, site, and routing controls retain their existing data sources, errors, recovery, and focus restoration. Use PrimeVue Button with `size="small"` and the dashboard’s 32px minimum-height recipe throughout the map controls, including link actions. PrimeVue Select owns hazard, area, and searchable site selection, popup placement, keyboard navigation, filtering, and clearing. Map controls and their portaled Select popups omit outer focus rings at the user’s request; preserve PrimeVue focus border colors and selected/hover backgrounds. Search inputs use the PrimeVue small input class with a 32px minimum height. Reuse MapIcon; hazard legend colors retain their domain meanings.

Map motion uses route-scoped tokens in map.css: 260ms entrances, 170ms exits, and the existing resident deceleration curve. Panels move from their nearest map edge; disclosures animate grid rows; full-map expansion uses an interruptible FLIP transform with Mapbox resized to its final dimensions. Closed content becomes inert as soon as dismissal starts. Reduced motion removes spatial transitions and map expansion animation. Keep control color feedback at 140ms and avoid looping or page-load effects.

Device location waits up to 20 seconds for fresh readings with reported accuracy of 100 meters or better. Show the reported accuracy with the location pin. If only a broader estimate is available, show its uncertainty area and recovery guidance without a location pin; do not use it as a hazard-check or routing origin. Cancel acquisition on navigation and superseding requests. A map-style change must not restore a stale location pin.

### Resident incident report

The reporting route uses the existing civic palette and Roboto stack. A PrimeVue Stepper with StepList, Step, StepPanels, and StepPanel owns the Details → Photos → Review sequence. Forward movement validates and saves through the existing report API; earlier steps remain available. The form is one white working surface beside a quiet requirements checklist. The map stays inside the location section at a practical height, with an explicit expansion action. Below 1200px the checklist follows the form; document scrolling owns the whole workflow.

Runtime ownership: resources/css/resident/report.css consumes the resident shell tokens and scopes report sizing and focus treatment. PrimeVue Button, AutoComplete, DatePicker, Select, Textarea, Checkbox, FileUpload, ProgressBar, Message, Tag, and Dialog own the corresponding controls. Use size="small" with a 32px minimum height for buttons and single-line fields, 8px radii, and 13px control text. Report secondary text uses the existing PrimeVue slate-600 token for sufficient contrast on the pale canvas. All report controls omit outer focus rings at the user's request; focus borders, button fill changes, and popup option backgrounds identify keyboard focus. Keep this exception scoped to reporting and its overlays. Existing shared LocationPinMap and SignaturePad retain map and signature behavior; their actions use small PrimeVue buttons.

The incident AutoComplete sits inside PrimeVue InputGroup. The group owns one shared border, 32px height, white surface, 8px outer radius, and the focus/invalid treatment; the input and dropdown trigger have no independent outlines or outer radii. Keep the trigger on the same surface with only a quiet internal separator. Report helper text and step hints use 12px, field labels and controls 13px, section headings 14px, and the step heading 18px. Separate form sections with 24px vertical spacing and use 16px gaps inside field groups.

The report DatePicker owns a compact, route-scoped 288px popup rather than stretching to the date field. Use 28px days and navigation, 13px date text, 10px panel padding, and a divided 24-hour time row. Hour/minute values sit beside 24px increment and decrement buttons. Keep the native PrimeVue month/year switching, keyboard selection, collision handling, and focus restoration. The popup uses `resident-report-calendar` in report.css; other calendars retain their existing geometry.

Step motion marks occasional pointer-driven changes of state. The incoming panel uses WAAPI with opacity and a 6px translation over 180ms, reusing `--resident-sidebar-ease`; a superseding change cancels the previous animation. Keyboard-driven step changes and form popups open instantly. Reduced motion uses a 100ms opacity-only transition. Motion tokens live beside the report styles as `--report-motion-step` and `--report-motion-reduced`; form fields, evidence, and checklist rows remain static.

The report API remains authoritative for draft, correction, photo, and submission requirements (docs/resident-report-flow.md, app/Http/Controllers/Resident/ReportController.php, and tests/Feature/ResidentReportTest.php). AutoComplete allows both an active incident selection and a typed incident; DatePicker uses the existing local date conversion helpers. The established PrimeVue confirmation service guards unsaved in-app navigation. Initial loading has a reserved region and retry; save and upload failures preserve entered data. Photo uploads retain per-file progress, preview, retry, removal, and the 2–8 photo limit. Saving a draft shows inline feedback; submission keeps the existing tracking destination. Shared feedback remains owned by MaprFeedbackHost.vue.

### Resident reports

Reports use a single bordered ledger with compact rows, rather than separate elevated cards. Reference and incident lead each row; status and updated time provide context. On phones, rows stack into a readable sequence with actions below. Returned reports use their correction status badge and explicit Revise Report action for emphasis. Preserve Roboto, resident palette variables, shared radius, and PrimeVue `size="small"` controls with a 32px minimum height. Text fields and the status Select omit outer focus rings and retain a blue focus border at the user's request. The Select popup keeps a tonal keyboard highlight. Other controls retain visible keyboard focus. Empty and loading states reserve a 20rem working region; empty states center the existing icon, title, guidance, and action. Secondary report text consumes the established PrimeVue slate-600 token.

Runtime ownership: resources/css/resident/reports.css consumes the resident shell variables and is imported after app.css. PrimeVue Select, DatePicker, Menu, Paginator, and shared ConfirmationService own composite behavior; StatusBadge owns status presentation. Search is URL-backed, IME-safe, and debounced by 300ms, with immediate Enter and clear. The API returns the complete filtered array; the list displays ten records per local page without changing the server contract. See UX-CONTRACT.md for ownership and behavior. Report filters, action menus, and calendars open instantly through PrimeVue transition pass-through; repeated and keyboard-driven actions receive no entrance/exit animation. Report rows remain static. Reduced motion also stops the loading spinner while preserving its text status. The date-filter disclosure is the motion exception: pointer activation expands measured height and fades over 200ms, collapsing over 150ms with the existing resident sidebar easing curve. WAAPI captures the painted frame for interruptible reversals; open height returns to auto so responsive reflow remains natural. Keyboard/assistive activation stays instant. Reduced motion uses a 100ms opacity-only fade. Closing content becomes inert and aria-hidden immediately; calendar popups retain their separate instant behavior. Date filters use labeled From/To fields with explicit calendar buttons, a format hint, and Apply/Cancel/Clear dates actions. Apply closes the panel and restores trigger focus; Cancel restores committed values; Clear dates leaves search and status intact. Desktop keeps 32px controls. Coarse pointers use 44px date controls and 16px editable text to avoid Safari input zoom. Narrow phones stack fields, with a centered, bounded, scrollable PrimeVue calendar; short coarse-pointer landscape viewports use the same calendar geometry. Preserve document scrolling and browser zoom.

### Resident profile

The profile uses a compact identity strip above two visible panels: read-only account information and an editable contact number. Remove the section filter so residents can understand the whole account at a glance. Account corrections direct residents to barangay staff; sign out remains an explicit native CSRF-protected POST action. Preserve the existing contact and photo API permissions and validation.

Runtime ownership: resources/css/resident/profile.css consumes resident palette, radius, and motion variables. PrimeVue InputText and Button use size="small", 32px controls, and 13px text; the camera action is 28px inside the 56px avatar. Keep the profile within a 60rem reading width. The resident name is the single content heading at 18px/24px; section headings use 14px/20px, fields and values 13px/20px, and supporting text 12px/18px. Account rows use 10px vertical padding, panels 20px padding (16px on phones), and panels stay 16px apart. Identity metadata is plain text, with no role badge or decorative heading icons. Avoid repeating the shell title, camera instructions, and signed-in context. The account panel and contact panel stack on phones with natural document scrolling. Profile controls and photo overlays omit outer focus rings at the user's request. Blue borders, button fills, and menu highlights identify keyboard focus. Keep this exception within the profile workflow. Success and error text use darker semantic tokens for legibility at the compact size.

PrimeVue Menu owns photo actions, keyboard navigation, dismissal, and trigger focus restoration. PrimeVue Dialog owns photo preview, focus trapping, and Escape; shared ConfirmationService guards unsaved contact changes. Photo menus open instantly. Occasional pointer-selected photo previews use opacity and scale(0.96) over 220ms with --resident-sidebar-ease; exit follows the same path and retains preview geometry until after dismissal. Reduced motion uses opacity only over 100ms; keyboard-selected previews open instantly. Account data stays static. Loading has a reserved status region and retry, contact errors preserve entered data and focus the field, and photo removal failures appear inline.

### CDRRMO dashboard

The city assessment overview uses a compact operational hierarchy: a labeled filter panel, a divided four-metric strip, the assessment map, then the review ledger beside recent activity. Keep the existing civic blue identity and Roboto stack. Use 24px/32px for the content heading, 15px/22px for panel headings, 13px/20px for body and controls, and 12px for labels and metadata. Distinguish incident-linked metrics from registry-wide totals through scope captions; preserve server labels and notes.

Runtime ownership: resources/css/cdrrmo/dashboard.css consumes the canonical resident shell variables from shared/layout.css and semantic PrimeVue tokens from shared/ui/maprPreset.js. The route owns 20px panel spacing and padding (16px on phones), 8px radii through resident tokens, and small PrimeVue controls with a 32px desktop height. Coarse pointers retain 44px targets and 16px editable inputs. Dashboard controls, embedded map controls, and the dashboard's portaled Select and DatePicker panels omit outer focus rings at the user's request. Border colors, button fills, link underlines, and tonal option/calendar highlights retain keyboard feedback. Keep the exception scoped to this dashboard and its authored overlays.

DashboardFilters, DashboardSummary, DashboardReviewQueue, and DashboardActivity own the distinct dashboard sections. Reuse the shared MapWorkspace and AreaSummaryPanel with their compact variant; the dashboard's incident and barangay filters also own the embedded map scope. PrimeVue Button, Select, DatePicker, Checkbox, InputText, DataTable, Column, Tag, Message, and ProgressSpinner own corresponding controls and states. The map toolbar groups basemap/search separately from map actions. Keep its selection summary beside the map on wide desktops and below it on smaller screens. The review ledger displays five rows per page within the server's bounded oldest-first preview. Recent activity scrolls inside its panel; document scrolling owns the dashboard. Preserve map legend and registry caveats. Group report-preparation guidance with its header action, align metric labels at the top, and retain tabular timestamp numerals. The focus exception resets PrimeVue component wrappers as well as the focused input; retain the blue field border and tonal/underlined action focus.

Dashboard motion serves feedback and state indication. Filters, calendars, report rows, totals, and keyboard actions stay instant. Pointer presses use a restrained scale(0.97) CSS transition over 160ms with the existing resident easing. The compact map layer disclosure uses interruptible WAAPI height/opacity transitions: 200ms opening, 150ms closing, with --resident-sidebar-ease. Capture the current painted frame before reversing, restore auto height after settling, and make closed content inert immediately. Reduced motion removes press scaling and uses a 100ms opacity-only disclosure. Route-scoped --cdrrmo-dash-motion-feedback/expand/collapse/reduced tokens in dashboard.css own those durations; MapWorkspace consumes them only in its compact variant. No dashboard entrance or decorative motion.

### CDRRMO assessment map

The full map leads with the geographic workspace. Keep its filters together in a labeled control panel, map tools in a separate action row, and selected-area details beside the map on wide screens or below it on narrower screens. Reuse the civic blue palette, Roboto, and resident surface/radius tokens. Use 13px/20px control and body text, 12px labels, 14px control-group headings, and a 16px map-to-detail gap. Desktop controls are 40px high; coarse-pointer controls retain 44px targets. The basemap remains the visual signature, with no decorative data or new map claims.

Runtime ownership: shared `MapWorkspace.vue` keeps map data, selection, and tool behavior; `AreaSummaryPanel.vue` owns selected-area details; PrimeVue owns Select, InputText, Checkbox, and Button behavior. The `is-cdrrmo-map` variant is detected only for the standalone CDRRMO `/map` route. `resources/css/cdrrmo/map.css`, imported by `resources/js/cdrrmo.js` after shared workspace and dashboard styles, consumes the canonical variables from `resources/css/shared/layout.css` and PrimeVue tokens from `resources/js/shared/ui/maprPreset.js`. It owns the full-page toolbar grouping, map/detail proportions, and responsive stack without changing embedded or other role maps. Select labels are associated with their triggers; PrimeVue owns popup geometry, keyboard navigation, and selection.

Map fields, selects, checkboxes, buttons, map controls, and the Select popup omit outer focus rings at the user's request. Preserve keyboard feedback through blue field borders, tonal selection/highlight backgrounds, and underlined action labels. Keep these overrides scoped to the standalone map and its `cdrrmo-map-overlay` popup.

The standalone map is an inline-size query container so its filter and detail layouts respond to the available content width when the sidebar expands. Below 1000px of content width, basemap/incident/barangay use three columns with a full-width search row, and details stack below the map; below 600px, filters use two columns with full-width basemap/search rows. Each map instance uses Vue-generated IDs for field labels and the layer disclosure. Detail controls share the route's 40px geometry; checked checkboxes preserve their filled state during keyboard focus.

Filter rows use content height, 36px desktop fields, 12px labels, and 8px row gaps. The search wrapper resets the shared horizontal flex basis when nested inside a column field, preventing it from inflating the filter panel. The Map filters heading and Clear filters action share a compact header row. The incident-linked checkbox appears only after an incident is selected, labeled “Only show households linked to this incident”; it occupies a 24px desktop row and retains a 44px touch target on coarse pointers. All filter fields retain 44px touch targets, and the reset action remains inside the filter panel.

### CDRRMO incidents

The incident registry uses a divided three-metric overview, a labeled filter panel, and a five-column ledger. Group the reference, incident name, disaster type, and demo marker in one incident cell; retain barangays, status, occurrence, and actions as distinct columns. The incident name opens its details. Keep the existing civic blue, Roboto, 24px/32px page heading, 15px/22px panel headings, 13px/20px body and controls, and 12px metadata. Use 20px section spacing, 8px surface/control radii, and 32px small controls, matching the CDRRMO dashboard. Coarse pointers retain 44px actions and 16px editable input text.

Runtime ownership: `resources/css/cdrrmo/incidents.css`, imported by `resources/js/cdrrmo.js`, consumes the resident shell tokens in shared/layout.css and semantic PrimeVue tokens in shared/ui/maprPreset.js. IncidentSummary, IncidentFilters, IncidentTable, IncidentDetailDrawer, IncidentStatusDialog, IncidentFormFields, and IncidentBarangayPicker separate presentation from the page coordinator and useIncidentRegistry composable. PrimeVue owns fields, selects, checkbox selection, the semantic table, server paginator, popup menu, modal dialogs, and detail drawer. Document scrolling owns the page; only the table scrolls horizontally on narrow screens. The modal body scrolls internally while footer actions remain available.

All incident controls and their authored overlays omit outer focus rings at the user's request. Blue field borders, underlined/fill-changing buttons, tonal menu/calendar options, and checkbox borders retain keyboard feedback. The exception stays scoped to `.cdrrmo-incidents` and `.cdrrmo-incidents-overlay`; system focus outlines remain available in forced-colors mode. Popups open instantly, and reduced motion removes surface transitions. Preserve the server summary scope note and demo markers. Counts follow search, disaster type, and barangay filters, independently of the status filter and current page.

The occurrence picker uses PrimeVue's authored calendar and 24-hour time controls, with values serialized through shared/ui/dates.js as Philippine wall-clock time. Direct date typing is disabled because the installed PrimeVue 4.5.5 parser cannot parse a 24-hour value without an AM/PM suffix; calendar and time controls retain keyboard operation. The API remains authoritative for creation, editing, associated barangays, and status changes. Keep its confirmation that closing an incident does not approve or complete its reports.

### CDRRMO Residents

The Residents registry extends the existing civic workspace with a scoped indigo accent; the shared blue shell remains unchanged. Runtime ownership: `resources/css/cdrrmo/residents.css`, imported by `resources/js/cdrrmo.js`, maps PrimeVue indigo tokens to `--registry-primary`, `--registry-primary-hover`, `--registry-wash`, and `--registry-focus`. Component-token overrides apply only within `.cdrrmo-residents` and `.cdrrmo-residents-overlay`. Source CSS and shared PrimeVue tokens remain canonical; this is a route variant, not a replacement global palette.

Use Roboto with a 24px/32px page heading, 14px/22px section headings, 13px/20px body and controls, and 12px/18px labels and metadata. Keep 8px radii, 16px section gaps, 32px small controls, and 28px row icon actions. Coarse pointers expand primary controls and row actions to 44px and editable input text to 16px. Three compact summary cards precede visibly labeled filters and a full-width divided ledger. Combine resident name and reference in one cell; keep household, barangay, report, incident inclusion, and actions distinct. References remain legible and use tabular numerals. Summary text distinguishes registered records, reported household size counted once per household (including missing-size caveats), and incident-dependent potentially affected lists, which never imply confirmed harm.

Container queries reflow filters at 900px and compact the summary into label/value rows at 560px. Document scrolling owns the page; only the ledger scrolls horizontally. Phone form fields stack, dialog bodies scroll, and footer actions remain available. Reuse `ResidentDetailDrawer.vue` with route-scoped styling; other consumers retain their existing appearance. PrimeVue Dialog, Select, InputText, DatePicker, Textarea, Checkbox, Menu, DataTable, and Paginator retain composite-control behavior. Household search uses an explicit inline result choice rather than accepting typed text as a household selection.

Residents controls and authored overlays omit outer focus rings and shadows at the user's request. Subtle indigo field borders, tonal checkbox/menu/selection fills, button fills, and underlined actions identify keyboard focus. Keep system outlines available in forced-colors mode. Popups open instantly; reduced motion removes transitions. Preserve neutral routine actions, distinct archive danger styling, loading/empty/error/retry states, and location-unavailable explanations. Server ordering, pagination, selection, forms, archive, incident inclusion, and map/report destinations follow the contract in `UX-CONTRACT.md`.

### Content and data visualization

Keep established route labels and plain action verbs. The sidebar adds no charts, domain claims, or numeric formatting.

## Do's and Don'ts

- Keep navigation compact and preserve icon coordinates across both states.
- Reuse resident color and motion variables rather than duplicating timing values.
- Do not unmount labels or the account footer during collapse.
- Keep role-specific destinations, badges, and reporting permissions in the shared navigation configuration; preserve the resident mobile reporting action.
- Staff mobile navigation opens a PrimeVue Drawer with library-owned focus trapping, Escape, outside dismissal, and focus restoration.
