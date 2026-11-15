---
version: alpha
name: MapR
description: Resident navigation for Tandag City's disaster reporting workspace
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

Runtime CSS remains canonical. This document mirrors existing resident tokens and the sidebar and header overlay decisions. Token mapping: resources/css/resident/app.css `.resident-app` variables → desktop media rules → ResidentSidebar.vue, ResidentHeader.vue, and ResidentLayout.vue. The resident header uses the maintained PrimeVue Popover for anchored geometry and dismissal; its resident-specific view and class tokens live beside the resident shell styles. Shared workspace CSS owns the separate staff shells; their dimensions are not redefined here.

## Colors

White surfaces sit on the pale canvas. Navy identifies the brand, ink carries primary text, and muted text supports secondary labels. Blue provides focus; the pale blue selection surface and a vertical marker identify the active route without relying on color alone. Preserve existing application semantic colors elsewhere.

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

The dashboard prioritizes report status and next steps. Use one divided summary strip, recent report rows, and a correction/draft panel before incident notices and activity charts. Dashboard styles live in resources/css/resident/dashboard.css, imported after the resident shell. Reuse resident palette variables and established correction orange and finalized green. Dashboard actions use the canonical PrimeVue Button with size="small", 32px minimum height, and 8px radius, including router navigation rendered through Button. On phones, next steps precede recent reports; on wide screens, recent reports sit beside next steps. Keep visible keyboard focus inset within the summary strip.

### Resident community map

The map keeps the resident palette, Roboto hierarchy, and shared 8px surface/control radius. Its distinctive element is the geographic context card: a quiet white label above the basemap that reflects the selected hazard or area. Runtime ownership: resources/css/resident/map.css, imported by resources/js/resident.js after the resident shell, consumes the existing `--resident-*` tokens; Mapbox retains ownership of geographic rendering and attribution. This file also owns the map route's responsive layout, without changing sibling page scrolling.

Desktop presents a unified action bar, base-map shortcuts, and an initially open layers panel. Below 1024px the panel starts closed; phones use a scrollable bottom sheet with an explicit expansion control. Keep the nearest-site action prominent, location/expand actions accessible, and reference-data guidance visible below the map. Layer, site, and routing controls retain their existing data sources, errors, recovery, and focus restoration. Use PrimeVue Button with `size="small"` and the dashboard’s 32px minimum-height recipe throughout the map controls, including link actions. PrimeVue Select owns hazard, area, and searchable site selection, popup placement, keyboard navigation, filtering, and clearing. Map controls and their portaled Select popups omit outer focus rings at the user’s request; preserve PrimeVue focus border colors and selected/hover backgrounds. Search inputs use the PrimeVue small input class with a 32px minimum height. Reuse MapIcon; hazard legend colors retain their domain meanings.

Map motion uses route-scoped tokens in map.css: 260ms entrances, 170ms exits, and the existing resident deceleration curve. Panels move from their nearest map edge; disclosures animate grid rows; full-map expansion uses an interruptible FLIP transform with Mapbox resized to its final dimensions. Closed content becomes inert as soon as dismissal starts. Reduced motion removes spatial transitions and map expansion animation. Keep control color feedback at 140ms and avoid looping or page-load effects.

Device location waits up to 20 seconds for fresh readings with reported accuracy of 100 meters or better. Show the reported accuracy with the location pin. If only a broader estimate is available, show its uncertainty area and recovery guidance without a location pin; do not use it as a hazard-check or routing origin. Cancel acquisition on navigation and superseding requests. A map-style change must not restore a stale location pin.

### Content and data visualization

Keep established route labels and plain action verbs. The sidebar adds no charts, domain claims, or numeric formatting.

## Do's and Don'ts

- Keep navigation compact and preserve icon coordinates across both states.
- Reuse resident color and motion variables rather than duplicating timing values.
- Do not unmount labels or the account footer during collapse.
- Do not change staff navigation or mobile reporting workflows as part of this resident redesign.
