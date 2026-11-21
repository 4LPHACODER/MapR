<script setup>
import { computed, ref } from 'vue';
import Button from 'primevue/button';
import Column from 'primevue/column';
import DataTable from 'primevue/datatable';
import DatePicker from 'primevue/datepicker';
import Dialog from 'primevue/dialog';
import InputText from 'primevue/inputtext';
import Menu from 'primevue/menu';
import Message from 'primevue/message';
import Paginator from 'primevue/paginator';
import ProgressSpinner from 'primevue/progressspinner';
import Select from 'primevue/select';
import Skeleton from 'primevue/skeleton';
import Tag from 'primevue/tag';
import Textarea from 'primevue/textarea';
import MapIcon from '../../shared/components/MapIcon.vue';
import { dateToIso, isoToDate } from '../../shared/ui/dates.js';
import '../../../css/cdrrmo/report-review-list.css';

const props = defineProps({
    kind: { type: String, required: true },
    filters: { type: Object, required: true },
    appliedFilters: { type: Object, required: true },
    filterOptions: { type: Object, required: true },
    rows: { type: Array, required: true },
    meta: { type: Object, required: true },
    summary: { type: Object, required: true },
    loading: Boolean,
    hasLoaded: Boolean,
    error: { type: String, default: '' },
    notice: { type: String, default: '' },
    fileMessage: { type: String, default: '' },
    flagRow: { type: Object, default: null },
    flagForm: { type: Object, required: true },
    flagError: { type: String, default: '' },
    invalidFlagField: { type: String, default: null },
    flagSaving: Boolean,
    canSign: Boolean,
});
const emit = defineEmits([
    'update:filters', 'update:flag-form', 'apply', 'clear', 'refresh',
    'open', 'preview', 'download', 'dismiss-file-message', 'flag', 'close-flag', 'save-flag', 'sign',
]);

const isField = computed(() => props.kind === 'field');
const isConsolidated = computed(() => props.kind === 'consolidated');
const compact = computed(() => isField.value || isConsolidated.value);
const overlay = computed(() => isConsolidated.value
    ? 'report-review-list-overlay consolidated-report-overlay'
    : isField.value ? 'report-review-list-overlay field-report-overlay' : 'report-review-list-overlay');
const searchInput = ref(null);
const clearFiltersButton = ref(null);
const actionsMenu = ref(null);
const menuRow = ref(null);
const menuOpen = ref(false);
let menuTrigger;
let flagTrigger;
const menuItems = computed(() => [
    ...(isConsolidated.value && props.canSign && menuRow.value && menuRow.value.workflow_status !== 'finalized'
        ? [{ label: 'Review & Sign', icon: 'pen', command: () => emit('sign', menuRow.value) }]
        : []),
    {
        label: 'Flag for review',
        icon: 'flag',
        command: () => {
            flagTrigger = menuTrigger;
            emit('flag', menuRow.value);
        },
    },
    { label: 'Version history', icon: 'history', command: () => emit('open', menuRow.value) },
]);

const metrics = computed(() => [
    { key: 'total', label: 'Total reports', icon: 'file' },
    { key: 'awaiting_review', label: 'Awaiting review', icon: 'clipboard', field: 'workflow_status', value: 'review' },
    {
        key: 'awaiting_signature', label: 'Awaiting signature', icon: 'pen', field: 'signature_status', value: 'unsigned',
        hint: 'Signature and submission are tracked separately.',
    },
    isConsolidated.value
        ? { key: 'finalized', label: 'Finalized', icon: 'check', field: 'workflow_status', value: 'finalized' }
        : { key: 'finalized', label: 'Submitted', icon: 'check', field: 'workflow_status', value: 'submitted' },
]);
const flagsField = { key: 'flag_status', label: 'Flags', options: choices([
    ['Any flag', ''], ['Open flags', 'open'], ['No open flags', 'none'],
]) };
const filterFields = computed(() => [
    { key: 'incident_id', label: 'Incident', options: listOptions('All incidents', props.filterOptions.incidents) },
    ...(!isConsolidated.value ? [{ key: 'barangay_id', label: 'Barangay', options: listOptions('All barangays', props.filterOptions.barangays) }] : []),
    { key: 'workflow_status', label: 'Status', options: choices([
        ['All statuses', ''], ['Draft', 'draft'], ['Awaiting review', 'review'],
        ['Signed', 'signed'], ['Submitted', 'submitted'], ['Returned for correction', 'returned'],
        ...(isConsolidated.value ? [['Finalized', 'finalized']] : []),
    ]) },
    { key: 'signature_status', label: 'Signature', options: choices([
        ['Any signature', ''], ['Awaiting signature', 'unsigned'], ['Partially signed', 'partial'], ['Signed', 'complete'],
    ]) },
    ...(compact.value ? [flagsField] : []),
    { key: 'date_from', label: isField.value ? 'From Date' : 'Updated from', date: true, calendarLabel: 'Choose start date' },
    { key: 'date_to', label: isField.value ? 'To Date' : 'Updated to', date: true, calendarLabel: 'Choose end date' },
    ...(compact.value ? [] : [flagsField]),
]);
const calendarButton = { size: 'small', severity: 'secondary', text: true };
const listTitle = computed(() => isConsolidated.value ? 'Consolidated report' : isField.value ? 'Field assessment report' : 'Barangay report');
const first = computed(() => (props.meta.current_page - 1) * props.meta.per_page);
const hasFilters = computed(() => {
    const filters = compact.value ? props.appliedFilters : props.filters;
    return Boolean(filters.q || filterFields.value.some(({ key }) => filters[key]));
});
const emptyState = computed(() => {
    if (props.summary.total === 0) {
        return {
            icon: 'file',
            title: `No ${listTitle.value.toLowerCase()}s yet`,
            description: isConsolidated.value
                ? 'Use Create Report to start a consolidated report from the assessment map.'
                : 'Field assessment reports will appear here once they are available.',
        };
    }
    if (props.meta.total > 0) {
        return {
            icon: 'file',
            title: 'No reports on this page',
            description: 'Go to the first page to see available reports.',
        };
    }
    return {
        icon: 'search',
        title: 'No matching reports',
        description: 'Try adjusting or clearing your filters to see more results.',
    };
});
const statusTones = { finalized: 'success', submitted: 'success', returned: 'contrast', draft: 'secondary', review: 'info', signed: 'success' };
const rowActions = [
    { action: 'open', label: 'View report', icon: 'eye' },
    { action: 'preview', label: 'Preview original file', icon: 'search' },
    { action: 'download', label: 'Download', icon: 'download' },
];

function choices(entries) {
    return entries.map(([label, value]) => ({ label, value }));
}

function listOptions(label, items) {
    return [{ label, value: '' }, ...items.map(({ id, name }) => ({ label: name, value: String(id) }))];
}

function clearSearch() {
    emit('update:filters', { q: '' });
    searchInput.value?.$el?.focus();
}

function clearFromEmptyState() {
    emit('clear');
    clearFiltersButton.value?.$el?.focus();
}

function selectMetric(metric) {
    if (!metric.field) {
        emit('clear');
        return;
    }
    emit('update:filters', { [metric.field]: metric.value });
    emit('apply');
}

function sortReports({ sortField }) {
    emit('update:filters', { sort: sortField, direction: props.filters.direction === 'asc' ? 'desc' : 'asc' });
    emit('apply', props.filters.page);
}

function openMenu(event, row) {
    menuTrigger = event.currentTarget;
    menuRow.value = row;
    actionsMenu.value.toggle(event);
}

function restoreFlagFocus() {
    flagTrigger?.focus();
    flagTrigger = null;
}

function submitFlag(event) {
    emit('save-flag');
    if (props.invalidFlagField) {
        event.currentTarget.elements.namedItem(props.invalidFlagField)?.focus();
    }
}
</script>

<template>
    <section class="report-review-list" :class="{ 'field-report-list': isField, 'consolidated-report-list': isConsolidated }">
        <header class="report-review-list-heading">
            <h2>{{ $route.meta.title }}</h2>
            <p v-if="isConsolidated">Review consolidated assessments, inspect supporting documents, and track approvals.</p>
            <p v-else>{{ isField ? 'Review reports, inspect supporting documents, and track approvals.' : 'Review submissions, supporting files, and signatures.' }}</p>
            <div v-if="isConsolidated" class="col-span-full flex flex-wrap gap-2 pt-1 @min-[560px]:col-start-2 @min-[560px]:row-span-2 @min-[560px]:row-start-1 @min-[560px]:justify-end @min-[560px]:pt-0">
                <Button label="Refresh" size="small" severity="secondary" outlined :disabled="loading" @click="$emit('refresh')">
                    <template #icon><MapIcon name="refresh" /></template>
                </Button>
                <Button as="router-link" :to="{ path: '/map', query: { mode: 'estimate' } }" label="Create Report" size="small">
                    <template #icon><MapIcon name="plus" /></template>
                </Button>
            </div>
            <Button v-else label="Refresh" size="small" severity="secondary" outlined :disabled="loading" @click="$emit('refresh')">
                <template #icon><MapIcon name="refresh" /></template>
            </Button>
        </header>

        <div
            :class="isConsolidated
                ? 'grid auto-rows-fr grid-cols-2 gap-2.5 @min-[680px]:grid-cols-4'
                : isField ? 'grid auto-rows-fr gap-2.5 sm:grid-cols-2 lg:@min-[700px]:grid-cols-4' : 'report-review-list-summary'"
            role="group"
            :aria-label="compact ? `${listTitle} totals` : 'Report totals across all barangays'"
            :aria-busy="compact && loading"
        >
            <Button
                v-for="metric in metrics"
                :key="metric.key"
                type="button"
                text
                severity="secondary"
                size="small"
                class="review-report-metric"
                :class="{ 'is-filtered': metric.field && filters[metric.field] === metric.value }"
                :data-metric="compact ? metric.key : undefined"
                :aria-pressed="compact && metric.field ? filters[metric.field] === metric.value : undefined"
                :disabled="compact && loading"
                :title="metric.hint"
                :aria-description="metric.hint"
                @click="selectMetric(metric)"
            >
                <span class="review-report-metric-icon"><MapIcon :name="metric.icon" /></span>
                <span class="review-report-metric-copy">
                    <span>{{ metric.label }}</span>
                    <Skeleton v-if="compact && !hasLoaded && loading" width="2rem" height="26px" aria-hidden="true" />
                    <strong v-else>{{ compact && !hasLoaded ? '—' : summary[metric.key].toLocaleString('en-PH') }}</strong>
                </span>
            </Button>
        </div>

        <form class="review-report-filters" novalidate aria-labelledby="review-report-filter-title" @submit.prevent="$emit('apply')">
            <header class="review-report-panel-heading">
                <h3 id="review-report-filter-title"><MapIcon name="list-filter" /> Filter reports</h3>
                <Button v-if="!compact" label="Clear filters" size="small" severity="secondary" text @click="$emit('clear')" />
            </header>
            <div :class="isConsolidated
                ? 'mt-2.5 grid grid-cols-2 gap-x-3 gap-y-2.5 @min-[560px]:grid-cols-3 @min-[800px]:grid-cols-4'
                : isField ? 'mt-2.5 grid gap-x-3 gap-y-2.5 sm:grid-cols-2 lg:@min-[700px]:grid-cols-4' : 'review-report-filter-grid'"
            >
                <div class="review-report-field" :class="{ 'review-report-search-field': !compact, 'col-span-2 @min-[560px]:col-span-1': isConsolidated }">
                    <label for="review-report-search">Search</label>
                    <div class="review-report-search">
                        <MapIcon name="search" />
                        <InputText
                            id="review-report-search"
                            ref="searchInput"
                            :model-value="filters.q"
                            size="small"
                            :placeholder="isField ? 'Reference or title' : 'Reference, title, or incident'"
                            @keydown.enter="$event.isComposing && $event.preventDefault()"
                            @update:model-value="$emit('update:filters', { q: $event })"
                        />
                        <Button v-if="filters.q" size="small" severity="secondary" text aria-label="Clear search" title="Clear search" @click="clearSearch">
                            <MapIcon name="x" />
                        </Button>
                    </div>
                </div>
                <div v-for="field in filterFields" :key="field.key" class="review-report-field" :class="{ '@max-[320px]:col-span-2': isConsolidated && field.date }">
                    <label :id="`review-report-label-${field.key}`" :for="`review-report-filter-${field.key}`">{{ field.label }}</label>
                    <DatePicker
                        v-if="field.date"
                        :input-id="`review-report-filter-${field.key}`"
                        :model-value="isoToDate(filters[field.key])"
                        size="small"
                        date-format="mm/dd/yy"
                        placeholder="MM/DD/YYYY"
                        show-icon
                        :show-on-focus="false"
                        show-button-bar
                        :navigator-button-props="calendarButton"
                        :today-button-props="calendarButton"
                        :clear-button-props="calendarButton"
                        :panel-class="`${overlay} report-review-list-calendar`"
                        :pt="{
                            transition: { css: false },
                            dropdown: { 'aria-label': field.calendarLabel },
                            panel: { 'aria-label': field.calendarLabel },
                            pcInputText: { root: { inputmode: 'text' } },
                        }"
                        @update:model-value="$emit('update:filters', { [field.key]: dateToIso($event) })"
                    >
                        <template #dropdownicon><MapIcon name="calendar" /></template>
                    </DatePicker>
                    <Select
                        v-else
                        :input-id="`review-report-filter-${field.key}`"
                        :aria-labelledby="`review-report-label-${field.key}`"
                        :model-value="compact ? filters[field.key] || null : filters[field.key]"
                        :options="field.options"
                        option-label="label"
                        option-value="value"
                        :placeholder="field.options[0].label"
                        size="small"
                        :panel-class="overlay"
                        :pt="{ transition: { css: false } }"
                        @update:model-value="$emit('update:filters', { [field.key]: $event })"
                    />
                </div>
                <div v-if="isConsolidated" class="col-span-2 grid grid-cols-2 gap-2 self-end @min-[480px]:flex @min-[480px]:justify-end @min-[800px]:col-span-1">
                    <Button ref="clearFiltersButton" label="Clear filters" size="small" severity="secondary" outlined @click="$emit('clear')" />
                    <Button type="submit" label="Apply" size="small" :disabled="loading" />
                </div>
                <Button v-else-if="!compact" type="submit" label="Apply filters" size="small" class="review-report-filter-submit" />
            </div>
            <div v-if="isField" class="mt-3 grid grid-cols-2 gap-2 sm:flex sm:justify-end">
                <Button ref="clearFiltersButton" label="Clear filters" size="small" severity="secondary" outlined @click="$emit('clear')" />
                <Button type="submit" label="Apply filters" size="small" :disabled="loading" />
            </div>
        </form>

        <Message v-if="notice" severity="success" size="small">{{ notice }}</Message>
        <Message v-if="fileMessage" severity="info" size="small" closable @close="$emit('dismiss-file-message')">{{ fileMessage }}</Message>

        <section class="review-report-ledger" aria-labelledby="review-report-ledger-title" :aria-busy="loading">
            <header class="review-report-panel-heading">
                <h3 id="review-report-ledger-title">Report records</h3>
                <span class="review-report-muted">Philippine time (PHT)</span>
            </header>
            <div v-if="error" :class="compact && rows.length
                ? 'flex flex-wrap items-center justify-between gap-2 px-4 pb-3'
                : 'review-report-state'"
            >
                <Message severity="error" size="small">
                    {{ error }}
                    <span v-if="compact && rows.length"> Showing the last loaded reports.</span>
                    <span v-else-if="compact"> Please try again.</span>
                </Message>
                <Button label="Retry" size="small" severity="secondary" outlined @click="$emit('refresh')" />
            </div>
            <div v-if="!error && compact && loading && !rows.length" :class="isField ? 'field-report-loading' : 'consolidated-report-loading'" role="status">
                <span class="sr-only">{{ hasLoaded ? 'Updating reports…' : 'Loading reports…' }}</span>
                <div v-for="row in 3" :key="row" class="grid grid-cols-3 items-center gap-4 px-4 py-4 sm:grid-cols-4" aria-hidden="true">
                    <Skeleton height="0.75rem" width="75%" />
                    <Skeleton height="0.75rem" />
                    <Skeleton height="0.75rem" width="70%" />
                    <Skeleton height="0.75rem" width="60%" class="hidden sm:block" />
                </div>
            </div>
            <div v-else-if="!error && loading && !rows.length" class="review-report-state" role="status">
                <ProgressSpinner aria-hidden="true" />
                <p>Loading reports…</p>
            </div>
            <div v-else-if="!error && compact && !rows.length" class="review-report-state" :class="isField ? 'field-report-empty' : 'consolidated-report-empty'">
                <span class="review-report-metric-icon" aria-hidden="true"><MapIcon :name="emptyState.icon" /></span>
                <div role="status" class="grid justify-items-center gap-1">
                    <h4 class="text-sm font-semibold text-[var(--p-text-color)]">{{ emptyState.title }}</h4>
                    <p>{{ emptyState.description }}</p>
                </div>
                <div v-if="meta.total > 0 || hasFilters" class="flex flex-wrap justify-center gap-2">
                    <Button v-if="meta.total > 0" label="Go to first page" size="small" severity="secondary" outlined @click="$emit('apply', 1)" />
                    <Button v-if="hasFilters" label="Clear filters" size="small" severity="secondary" outlined @click="clearFromEmptyState" />
                </div>
            </div>
            <div v-else-if="!error && !rows.length" class="review-report-state" role="status">
                <span class="review-report-metric-icon"><MapIcon :name="hasFilters ? 'search' : 'file'" /></span>
                <h4>{{ hasFilters ? 'No matching reports' : 'No reports on this page' }}</h4>
            </div>
            <DataTable
                v-else-if="rows.length && (!error || compact)"
                :value="rows"
                data-key="id"
                size="small"
                lazy
                :loading="loading"
                :sort-field="filters.sort"
                :sort-order="filters.direction === 'asc' ? 1 : -1"
                :table-props="{ 'aria-label': `${listTitle} records` }"
                :pt="{
                    tableContainer: { tabindex: 0, role: 'region', 'aria-label': 'Report table' },
                    mask: { role: 'status', 'aria-label': 'Updating reports' },
                }"
                @sort="sortReports"
            >
                <Column field="reference" header="Reference" sortable class="review-report-reference-column">
                    <template #body="{ data }">
                        <Button :label="data.reference" text size="small" class="review-report-reference-link" @click="$emit('open', data)" />
                        <span class="review-report-muted review-report-subline">{{ data.title }}</span>
                    </template>
                </Column>
                <Column field="incident_name" header="Incident" />
                <Column field="barangay_name" :header="isConsolidated ? 'Scope' : 'Barangay'" />
                <Column field="submitted_by" header="Submitted by" />
                <Column field="workflow_label" header="Status">
                    <template #body="{ data }">
                        <Tag :value="data.workflow_label" :severity="statusTones[data.workflow_status] || 'info'" />
                    </template>
                </Column>
                <Column field="signature_label" header="Signature">
                    <template #body="{ data }">
                        {{ data.signature_label }}
                        <span v-if="data.open_flags" class="review-report-subline review-report-open-flags">
                            {{ data.open_flags }} open {{ Number(data.open_flags) === 1 ? 'flag' : 'flags' }}
                        </span>
                    </template>
                </Column>
                <Column field="updated" header="Updated" sortable class="review-report-updated-column">
                    <template #body="{ data }">{{ data.updated_label }}</template>
                </Column>
                <Column header="Actions" class="review-report-actions-column">
                    <template #body="{ data }">
                        <div class="review-report-row-actions">
                            <Button v-for="item in rowActions" :key="item.action" v-tooltip.top="isConsolidated ? item.label : undefined" size="small" severity="secondary" text
                                :aria-label="`${item.label}: ${data.reference}`" :title="isConsolidated ? undefined : item.label" @click="$emit(item.action, data)">
                                <MapIcon :name="item.icon" />
                            </Button>
                            <Button v-tooltip.top="isConsolidated ? 'More actions' : undefined" size="small" severity="secondary" text
                                :aria-label="`More actions: ${data.reference}`" :title="isConsolidated ? undefined : 'More actions'"
                                aria-haspopup="menu" aria-controls="review-report-actions_list"
                                :aria-expanded="menuOpen && menuRow?.id === data.id" @click="openMenu($event, data)">
                                <MapIcon name="more" />
                            </Button>
                        </div>
                    </template>
                </Column>
            </DataTable>
            <footer v-if="compact || ((!loading || rows.length) && !error)" class="review-report-ledger-footer review-report-muted">
                <span v-if="rows.length || (!loading && !error)" role="status">
                    <template v-if="rows.length">{{ first + 1 }}–{{ first + rows.length }} of </template>{{ meta.total.toLocaleString('en-PH') }} {{ meta.total === 1 ? 'report' : 'reports' }}
                </span>
                <Paginator v-if="meta.last_page > 1 && (rows.length || !error)" :first="first" :rows="meta.per_page" :total-records="meta.total"
                    template="PrevPageLink CurrentPageReport NextPageLink" current-page-report-template="Page {currentPage} of {totalPages}"
                    @page="$emit('apply', $event.page + 1)" />
            </footer>
        </section>

        <Menu id="review-report-actions" ref="actionsMenu" :model="menuItems" popup aria-label="Report actions"
            :class="overlay" :pt="{ transition: { css: false } }" @show="menuOpen = true" @hide="menuOpen = false">
            <template #itemicon="{ item }"><MapIcon :name="item.icon" /></template>
            <template v-if="!isConsolidated" #end><p class="review-report-menu-note">Editing and signing stay with the preparing office.</p></template>
        </Menu>

        <Dialog
            :visible="!!flagRow"
            :header="'Flag ' + (flagRow?.reference || 'report')"
            :class="[overlay, 'review-report-flag-dialog']"
            :close-button-props="{ size: 'small' }"
            :draggable="false"
            modal
            block-scroll
            :closable="!flagSaving"
            :close-on-escape="!flagSaving"
            :dismissable-mask="!flagSaving"
            @update:visible="!$event && $emit('close-flag')"
            @after-hide="restoreFlagFocus"
        >
            <form id="review-report-flag-form" class="review-report-flag-fields" novalidate @submit.prevent="submitFlag">
                <div class="review-report-field">
                    <label for="review-report-flag-category">Category</label>
                    <InputText id="review-report-flag-category" name="category" :model-value="flagForm.category" size="small" maxlength="80" required
                        :aria-invalid="Boolean(flagError && invalidFlagField === 'category')"
                        :aria-describedby="flagError && invalidFlagField === 'category' ? 'review-report-flag-error' : undefined"
                        :disabled="flagSaving" @update:model-value="$emit('update:flag-form', { category: $event })" />
                </div>
                <div class="review-report-field">
                    <label for="review-report-flag-reason">Reason</label>
                    <Textarea id="review-report-flag-reason" name="reason" :model-value="flagForm.reason" size="small" rows="4" minlength="10" required
                        autofocus auto-resize :disabled="flagSaving"
                        :aria-invalid="Boolean(flagError && invalidFlagField === 'reason')"
                        :aria-describedby="flagError && invalidFlagField === 'reason' ? 'review-report-flag-error' : 'review-report-flag-hint'"
                        @update:model-value="$emit('update:flag-form', { reason: $event })" />
                    <p id="review-report-flag-hint" class="review-report-muted">Enter at least 10 characters.</p>
                </div>
                <Message v-if="flagError" id="review-report-flag-error" severity="error" size="small">{{ flagError }}</Message>
            </form>
            <template #footer>
                <Button label="Cancel" size="small" severity="secondary" outlined :disabled="flagSaving" @click="$emit('close-flag')" />
                <Button label="Save flag" size="small" type="submit" form="review-report-flag-form" :loading="flagSaving" />
            </template>
        </Dialog>
        <slot />
    </section>
</template>
