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
