# Resident report UI contract

Scope: `/resident/reports` and its existing report create, detail, and tracking destinations. Domain policy remains in docs/resident-report-flow.md and server policies. English labels follow existing resident workflows.

| Capability | Canonical owner | Contract and variants | Verification |
| --- | --- | --- | --- |
| Select/Listbox | PrimeVue Select through shared installPrimeVue | Authored status popup; no outer focus ring; blue focus border and tonal option highlight; preserve selected, clear, arrow keys, Escape | Browser keyboard and popup checks |
| Date | PrimeVue DatePicker, shared/ui/dates.js | Authored calendar; date-only inclusive updated-at range; drafts commit on Apply and restore trigger focus; Cancel restores committed values; Clear dates preserves search/status; explicit calendar buttons and typed input remain available; invalid order shows inline error | Browser range and popup checks |
| Form | PrimeVue InputText and DatePicker | Visible associated labels; text-field focus uses blue border without outer ring | Browser focus checks |
| Scrollbar | resources/css/shared/workspace.css | Existing global browser baseline remains canonical | Responsive overflow checks |
| Toast | shared/ui/MaprFeedbackHost.vue | Shared feedback host and ConfirmationService; persistent inline list failures remain available for retry | Browser failure checks |
| CRUD | Resident ReportController, policies, report routes | Only drafts delete, with shared confirmation and Cancel initially focused. Prevent duplicate deletion, retain failures, refresh list on success. Drafts continue wizard; returned editable reports revise; submitted reports open detail. | ResidentReportTest and browser checks |
| Menus | PrimeVue Menu | Popup opens instantly and owns keyboard navigation, outside dismissal, and focus restoration; no local ARIA menu implementation | Browser menu checks |
| Search and list state | ReportsPage.vue and Vue Router | Search, status, and dates persist in URL. Debounce search 300ms, suppress during IME composition, ignore stale responses. Enter and clear commit immediately. Local ten-row pagination follows complete-array API; reset page on query change. | Browser query and no-result checks |

Status labels come from the server presentation contract. Filtering never changes lifecycle or edit permissions. Loading, empty, no-result, fetch error, and mutation feedback use the existing shared PrimeVue controls and resident EmptyState. Detail and tracking links preserve filter context. Keep changes scoped to the reports workflow; sibling screens retain their established behavior.


## Resident profile

Scope: `/resident/profile`. `Resident/ProfileController.php` and `Resident/ProfilePhotoController.php` remain authoritative for editable fields, upload validation, and account ownership. The layout shows all information without a section filter. Account fields are read only; corrections go through barangay staff. Only the contact number and profile photo remain editable.

| Capability | Canonical owner | Contract | Verification |
| --- | --- | --- | --- |
| Form | PrimeVue InputText and Button, ProfilePage.vue | Small controls; associated optional contact label; preserve country codes and leading zeros; block duplicate saves; inline error with field focus; save stays on profile; discard restores saved contact | Browser save, validation, pending, discard checks |
| Photo menu | PrimeVue Menu | Instant popup with library keyboard navigation, Escape, outside dismissal, and trigger focus restoration | Browser keyboard check |
| Photo preview | PrimeVue Dialog, ProfilePhotoControl.vue | Library focus trap and Escape; Cancel initially focused; upload progress and retryable errors; preserve preview geometry through exit; clear object URL after hide | Browser preview, cancel, upload, motion checks; ResidentProfilePhotoTest |
| Confirmation | Shared ConfirmationService | Confirm unsaved contact loss on route leave and sign out; Cancel initially focused; sign out retains native CSRF-protected POST | Browser cancellation check |
| Feedback | PrimeVue Message and existing residentAccount state | Persistent inline load/save/photo failures; load retry; photo removal errors remain visible after menu dismissal | Browser failure checks |
| Focus and density | resources/css/resident/profile.css | No outer focus rings on profile controls or photo overlays; blue field/button border, button fill, and tonal menu focus remain visible; natural document scrolling | Browser computed styles and phone overflow |

Photo preview motion marks an occasional state change: pointer selection uses 220ms opacity and scale with the resident easing token; keyboard selection is instant. Reduced motion retains only a 100ms fade. Account values and form data remain static. Backend policy is unchanged.


## Resident dashboard

Scope: `/resident/`. Dashboard summaries remain owned by DashboardController and ResidentReportWorkflow. Recent reports fetch the existing complete, account-scoped `/resident/api/reports?status=all_submitted` array, rather than filtering the dashboard summary's five-row sample. Drafts remain in Your next steps and report history.

| Capability | Canonical owner | Contract | Verification |
| --- | --- | --- | --- |
| Table | PrimeVue DataTable / Column, DashboardReportsTable.vue | Semantic sortable reference, incident, status, and updated columns; latest updated first; five rows by default, with 10/20 choices; library owns keyboard sorting and paginator | Production build; component interaction checks |
| Filters | PrimeVue InputText / Select and Vue Router | Local search matches reference, incident, and location; suppress during IME composition; clear immediately and restore input focus. Status uses server summary buckets. Updated-period choices use rolling 30/90/365-day windows. Search, filters, sort, page, and page size persist in URL; filtering and sorting reset page. No remote search debounce is needed because all filtering uses the loaded array. | Component interaction checks |
| Async states | DashboardReportsTable.vue and PrimeVue Message | Reserved loading, empty/no-result guidance, inline fetch failure with retry; stale/unmounted responses ignored | Component loading, failure, retry checks |
| Focus | resources/css/resident/dashboard.css | Dashboard controls, incident dialog, and dashboard Select overlays omit outer focus rings; field borders, tonal table/pagination/summary fills, button fill changes, and link underlines identify keyboard focus | Scoped CSS review |
| Responsive layout | resources/css/resident/dashboard.css | Full-width bounded table with horizontal overflow; labels remain visible; phone controls wrap; coarse pointers use 44px filter controls and 16px search text. Supporting next steps and incident panels stack below on narrow screens. | Responsive CSS review; live browser when available |

No report lifecycle, permission, or mutation behavior changes. Existing report detail, draft continuation, corrections, and incident details destinations remain authoritative.


## Shared authenticated navigation

Scope: Resident, BDRRM (including secretary/captain), Assessor, and CDRRMO Vue workspaces. Existing route guards and backend policies remain authoritative for access. `resources/js/shared/layouts/WorkspaceLayout.vue` owns the resident-based shell, and `resources/js/shared/navigation.js` owns role-specific links and optional badge endpoints.

- Desktop preserves the resident sidebar, header, collapse behavior, palette, and motion. Staff page components keep their existing styles and report-context providers.
- On phones, residents retain the existing report action and navigation. Staff get four primary destinations and Menu; PrimeVue Drawer owns modal focus, Escape, outside dismissal, and focus restoration. All staff destinations and CSRF-protected sign out remain available in the drawer.
- Shared WorkspaceHeader receives optional notification data and action slots. Only the resident wrapper loads resident notification/account data and supplies ResidentReportButton. Staff never call resident-only APIs through the shell.
- BDRRM resident records remain visible only to secretary/captain roles. Badge data retains the existing role endpoints, refresh event, and server-defined meaning. Failed badge requests leave links usable; stale responses are ignored.
- Each entry point uses the existing installPrimeVue provider and shared feedback host. Route changes close disclosures, refresh badges, and update the document title. Wizard keyboard handling remains resident-scoped.
- Navigation verification: `node --test tests/js/workspace-navigation.test.mjs`; production build verifies every workspace entry point.


## CDRRMO dashboard

Scope: `/cdrrmo/`. WorkspaceDashboardController and existing CDRRMO authorization remain authoritative for available incidents, submitted assessments, totals, registry caveats, and report-preparation eligibility. This redesign does not change lifecycle or server filtering rules.

| Capability | Canonical owner | Contract | Verification |
| --- | --- | --- | --- |
| Select/Listbox | PrimeVue Select | Small authored incident, barangay, and status controls; library keyboard/Escape/selection; dashboard-scoped overlays | Browser popup, keyboard, mobile checks |
| Date | PrimeVue DatePicker and shared/ui/dates.js | Small date-only fields; local date conversion; explicit start/end labels; bounded authored calendar | Browser calendar and narrow viewport |
| Form and filter state | DashboardFilters, DashboardPage, Vue Router | Existing filter parameters persist in URL; route restoration loads current scope; cancel superseded dashboard requests; preserve previous data and map during refresh failures; Retry remains available | Browser filter URL and refresh failure/retry |
| Map | Shared MapWorkspace and AreaSummaryPanel, compact variant | Dashboard incident and barangay own map scope; retain draw/edit/clear, layer toggles, summary, source caveats, and report actions; PrimeVue owns map controls; pointer layer disclosure uses interruptible 200/150ms motion, keyboard is instant, reduced motion is a 100ms fade, closing content becomes inert immediately | Browser map/layers/search clear, existing map tests |
| Review ledger | PrimeVue DataTable and Column | Server supplies at most twenty oldest submitted assessments; display five per local page, preserve server order, link to existing field-report reference filter, show empty state and full-list destination | Browser pagination/empty/navigation |
| Activity | DashboardActivity | Preserve server ordering and bounded preview, expose actor/action/Manila time; scroll within panel and link to activity log | Browser long/empty content |
| Feedback | PrimeVue Message and ProgressSpinner | Reserved initial loading region; inline request/preparation failures; duplicate preparation blocked; map area preserved after preparation failure | Browser loading/failure/retry |
| Scrollbar and focus | Shared workspace.css and dashboard.css | Shared global scrollbar baseline; dashboard density and focus exception documented in DESIGN.md; no outer rings on focused inputs, Select wrappers, Checkbox boxes, or popup controls; border/fill/underline/tonal focus feedback; natural document scrolling | Browser computed wrapper/input style and overflow |
| CRUD | Existing mapReportContext and CDRRMO report endpoints | Preserve area context before opening existing report preview; keep active-incident and polygon prerequisites; no lifecycle changes | CdrrmoWorkspaceTest and existing report tests |

The existing period and assessment-status query controls are preserved. WorkspaceDashboardController currently uses incident and barangay for its summary/review queries; broader server filtering is outside this visual change. Registry-wide verification and consolidated totals retain their existing scope and now show scope captions.

## CDRRMO incident registry

Scope: `/cdrrmo/incidents`. `app/Http/Controllers/Cdrrmo/IncidentController.php`, resident report eligibility rules in `docs/resident-report-flow.md`, and `tests/Feature/IncidentApiTest.php` own lifecycle, association, permission, and Philippine-time behavior. This redesign does not change those rules.

| Capability | Canonical owner | Contract | Verification |
| --- | --- | --- | --- |
| Select/Listbox and menus | PrimeVue Select and Menu | Authored overlays with library keyboard, Escape, outside dismissal, and option highlighting; associated labels; scoped ring-free focus borders/fills | Headless popup width, keyboard, menu, and focus checks |
| Date | PrimeVue DatePicker and shared/ui/dates.js | Calendar and 24-hour time controls; select through picker rather than the installed library's broken 24-hour text parser; preserve Philippine wall-clock values in API payloads | Headless calendar and form payload checks; IncidentApiTest |
| Form | PrimeVue InputText, Select, Textarea, Checkbox, and Dialog | Preserve field order; required-field and server errors stay associated with controls; first invalid field receives focus; values survive failures; duplicate saves blocked; unsaved changes use an app-owned discard dialog | Headless validation, retry, create/edit, and discard checks |
| Table and list state | IncidentTable, useIncidentRegistry, Vue Router | Server pagination at 10/25/50 rows; latest occurrence first; committed search, filters, page, and page size in URL; clamp out-of-range pages; retain loaded geometry during refresh; ignore/cancel superseded fetches | Headless pagination, query, stale, empty, failure, and recovery checks |
| Search | IncidentFilters | Debounce 300ms; IME composition suppresses requests; Enter and clear commit immediately; clear restores input focus | Headless search and clear checks |
| CRUD and status | IncidentController, IncidentFormDialog, IncidentStatusDialog | Save returns to current list and refreshes its scope. Close/reopen requires explicit confirmation. Preserve server errors and confirm_close payload. Closing never approves/completes reports. | IncidentApiTest; headless mocked mutation checks |
| Overlays and feedback | PrimeVue Dialog/Drawer, shared MaprFeedbackHost | Library modal focus trap, Escape, scroll lock; restore trigger focus; details include retry; success uses shared Toast; persistent errors remain inline | Headless details, confirmation, focus, and error checks |
| Scrollbar and layout | Shared workspace baseline; incidents.css geometry | Document scroll, bounded table horizontal overflow, modal body scroll with visible footer; compact desktop and stacked phone filters | Headless narrow viewport and modal checks |
| Focus | incidents.css | No outer focus rings on incident fields, selects, buttons, or overlays. Preserve blue borders, tonal backgrounds, underlined actions, and checked state. Forced-colors keeps system focus. | Computed-style and keyboard checks |


## CDRRMO Residents

Scope: `/cdrrmo/residents` and its existing detail, form, report, archive, restore, map, and incident-inclusion actions. `app/Services/ResidentRegistry.php` and `app/Http/Controllers/Cdrrmo/ResidentController.php` remain authoritative for authorization, validation, ordering, summary meanings, and mutations. No backend, dependency, database, permission, or sibling-page contract changes.

| Capability | Canonical owner | Contract | Verification |
| --- | --- | --- | --- |
| Summary | ResidentsSummary and ResidentRegistry | Registered card selects active records; household size is reported once per household with partial-size caveats; potentially affected requires an incident and filters its inclusion list, without asserting confirmed harm. Missing values remain unavailable rather than fabricated zeroes. | Compiled-asset mocked summary actions; ResidentManagementTest |
| Filters and search | ResidentsFilters and ResidentsPage | Visible associated labels; preserve incident, barangay, report availability, status, inclusion, household/building, and area scope. Search debounces 300ms, suppresses IME requests, and commits on Enter/clear; ignore stale list/summary responses. Scope/filter changes reset page; incident or area changes clear selection. | Mocked filters, IME, stale-search, and scope checks |
| Ledger and selection | PrimeVue DataTable, Checkbox, Paginator; ResidentsTable | Preserve server last-name/first-name order with no new sorting semantics. Server pages offer 10/25/50 rows, default 25. Combine name/reference; retain cross-page selection and existing bulk actions. | Mocked default-25 and 10-row pagination, page-size options, cross-page selection; ResidentManagementTest |
| Form and household choice | PrimeVue Dialog, InputText, DatePicker, Textarea; ResidentFormDialog | Associated labels and inline field errors; typed household search requires an explicit result selection. Preserve create record source, duplicate confirmation, edit correction reason, and updated_at concurrency payload. Block repeated saves, preserve failed values, and confirm discard of changed data. | Mocked create/duplicate/edit and form checks; ResidentManagementTest |
| Archive, restore, incident inclusion | ResidentsDialogs and ResidentRegistry | Archive requires the typed resident reference. Preserve restore, incident add/remove, inclusion basis/reference/notes, validation, and existing API payloads; errors remain recoverable. | Mocked archive/restore/add/remove checks; ResidentManagementTest |
| Detail, reports, and map | Shared ResidentDetailDrawer, ResidentsDialogs, existing reportContext/map navigation | Reuse drawer behavior and report tabs/groups; preserve report destinations, geographic payloads, area/household/building scope, and unavailable-location guidance. Drawer styling changes only inside Residents. | Mocked detail/report tabs and map payload/navigation checks; CdrrmoWorkspaceTest |
| Composite controls and feedback | PrimeVue Select, Menu, Dialog, DatePicker, Message | Library owns popup keyboard/dismissal and modal focus behavior; restore action focus after dialogs. Popups remain immediate. Keep explicit loading, empty, inline error/retry, and persistent inline success feedback. | Mocked popup/workflow, error/retry, and empty-state checks |
| Focus and responsive density | residents.css, scoped to .cdrrmo-residents / .cdrrmo-residents-overlay | Indigo variant only; shared shell stays blue. No outer focus rings/shadows; subtle indigo borders, fills, and underlines retain keyboard feedback, with forced-colors outlines. Use 32px controls/28px row icons, 44px primary touch targets, and 16px editable coarse-pointer text. Document scroll, table-only horizontal overflow, stacked phone fields, bounded dialogs with available footer actions. | Mocked widths 320/390/768/1024; coarse-pointer geometry and input-size checks |

Verification for this redesign: production build passed; `ResidentManagementTest` and `CdrrmoWorkspaceTest` passed together (13 tests, 93 assertions). Headless workflow checks used compiled assets and mocked API responses; they verify frontend behavior and payloads, not real API browser mutations. Review captures: `.impeccable/review/desktop.png`, `mobile.png`, `form.png`, and `mobile-form.png`.
