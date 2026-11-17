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
