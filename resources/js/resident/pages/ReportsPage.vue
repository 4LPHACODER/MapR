<script setup>
import { computed, inject, nextTick, onBeforeUnmount, ref, watch } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import Button from 'primevue/button';
import DatePicker from 'primevue/datepicker';
import IconField from 'primevue/iconfield';
import InputIcon from 'primevue/inputicon';
import Menu from 'primevue/menu';
import Paginator from 'primevue/paginator';
import ProgressSpinner from 'primevue/progressspinner';
import { useConfirm } from 'primevue/useconfirm';
import InputText from 'primevue/inputtext';
import Message from 'primevue/message';
import Select from 'primevue/select';
import MapIcon from '../../shared/components/MapIcon.vue';
import StatusBadge from '../components/StatusBadge.vue';
import EmptyState from '../components/EmptyState.vue';
import { formatWhen } from '../format';
import { dateToIso, isoToDate } from '../../shared/ui/dates';

const apiBase = inject('apiBase');
const confirm = useConfirm();
// Repeated filter/menu use, including keyboard activation, stays immediate.
const instantOverlay = { transition: { css: false } };
const route = useRoute();
const router = useRouter();
const reports = ref([]);
const error = ref('');
const loading = ref(true);
const searchInput = ref('');
const showDates = ref(false);
const dateError = ref('');
const datesTrigger = ref(null);
const animateDates = ref(false);
const dateAnimations = new WeakMap();
const datesApplied = computed(() => Boolean(filters.value.from || filters.value.to));
const filters = ref({ search: '', status: '', from: '', to: '' });
const dateDraft = ref({ from: '', to: '' });
const pendingDelete = ref(null);
const actionMessage = ref('');
const reportMenu = ref(null);
const searchField = ref(null);
const first = ref(0);
const deleting = ref(false);
const composing = ref(false);
const pageReports = computed(() => reports.value.slice(first.value, first.value + 10));
const activeReport = ref(null);
const reportActions = computed(() => activeReport.value ? [
    { label: 'Track Status', command: () => router.push({ path: `/reports/${activeReport.value.id}/track`, query: { ...route.query } }) },
    ...(activeReport.value.editable ? [{ label: 'Edit', command: () => router.push(`/report/${activeReport.value.id}`) }] : []),
    ...(activeReport.value.status === 'draft' ? [{ separator: true }, { label: 'Delete draft', class: 'reports-delete-action', command: () => chooseMenu(activeReport.value) }] : []),
] : []);
const statuses = [
    { value: 'all_submitted', label: 'All submitted reports' },
    { value: 'in_review', label: 'All review stages' },
    { value: 'finalized', label: 'Finalized' },
    { value: 'draft', label: 'Draft' },
    { value: 'submitted', label: 'Pending BDRRM Review' },
    { value: 'under_review', label: 'Under review' },
    { value: 'reviewed', label: 'Reviewed' },
    { value: 'returned', label: 'Needs Correction' },
];
let requestId = 0;
let searchTimer = null;

const selectedStatus = computed({
    get: () => filters.value.status || null,
    set: (value) => {
        filters.value.status = value || '';
        onStatusChange();
    },
});
const fromDate = computed({
    get: () => isoToDate(dateDraft.value.from),
    set: (value) => { dateDraft.value.from = dateToIso(value); },
});
const toDate = computed({
    get: () => isoToDate(dateDraft.value.to),
    set: (value) => { dateDraft.value.to = dateToIso(value); },
});

const queryActive = computed(() => {
    const current = readQuery(route.query);
    return Boolean(current.search || current.status || current.from || current.to);
});
const filtersActive = computed(() => Boolean(
    searchInput.value.trim()
    || filters.value.status
    || filters.value.from
    || filters.value.to
    || dateDraft.value.from
    || dateDraft.value.to,
));

function readQuery(query) {
    return {
        search: String(query.search || ''),
        status: String(query.status || ''),
        from: String(query.from || ''),
        to: String(query.to || ''),
    };
}

function queryFromFilters() {
    const query = {};
    if (filters.value.search) query.search = filters.value.search;
    if (filters.value.status) query.status = filters.value.status;
    if (filters.value.from) query.from = filters.value.from;
    if (filters.value.to) query.to = filters.value.to;
    return query;
}

function paramsFrom(query) {
    const current = readQuery(query);
    const params = {};
    if (current.search) params.search = current.search;
    if (current.status) params.status = current.status;
    if (current.from) params.from = current.from;
    if (current.to) params.to = current.to;
    return params;
}

function sameQuery(query) {
    const current = readQuery(query);
    return current.search === filters.value.search
        && current.status === filters.value.status
        && current.from === filters.value.from
        && current.to === filters.value.to;
}

async function load() {
    const id = ++requestId;
    error.value = '';
    loading.value = true;
    try {
        const response = await window.axios.get(`${apiBase}/reports`, { params: paramsFrom(route.query) });
        if (id !== requestId) return;
        reports.value = response.data.data || [];
        first.value = Math.min(first.value, Math.max(0, Math.ceil(reports.value.length / 10) - 1) * 10);
    } catch (exception) {
        if (id !== requestId) return;
        reports.value = [];
        error.value = exception.response?.data?.message || 'Reports could not be loaded.';
    } finally {
        if (id === requestId) loading.value = false;
    }
}

function commitQuery() {
    if (sameQuery(route.query)) return;
    router.replace({ query: queryFromFilters() });
}

function onSearchInput() {
    clearTimeout(searchTimer);
    if (composing.value) return;
    searchTimer = setTimeout(() => {
        searchTimer = null;
        const next = searchInput.value.trim();
        if (next === readQuery(route.query).search) return;
        filters.value.search = next;
        commitQuery();
    }, 300);
}

function submitSearch(event) {
    if (composing.value || event?.isComposing) return;
    clearTimeout(searchTimer);
    searchTimer = null;
    filters.value.search = searchInput.value.trim();
    commitQuery();
}

async function clearSearch() {
    searchInput.value = '';
    submitSearch();
    await nextTick();
    searchField.value?.$el?.focus();
}

function onStatusChange() {
    commitQuery();
}

// Capture the painted frame before cancelling so fast reversals never restart.
function prepareDateMotion(element) {
    const height = element.getBoundingClientRect().height;
    const opacity = height ? getComputedStyle(element).opacity : '0';
    const running = dateAnimations.get(element);
    if (running) {
        running.onfinish = null;
        running.cancel();
        dateAnimations.delete(element);
    }
    element.style.height = `${height}px`;
    element.style.opacity = opacity;
}

function transitionDates(element, done, opening) {
    if (!animateDates.value || window.matchMedia('(prefers-reduced-motion: reduce)').matches) {
        // Reduced motion keeps a brief fade, without moving the report list.
        element.style.height = '';
        if (!animateDates.value) {
            element.style.opacity = '';
            done();
            return;
        }
    }
    const reduced = window.matchMedia('(prefers-reduced-motion: reduce)').matches;
    const targetHeight = element.firstElementChild.getBoundingClientRect().height;
    const frames = reduced
        ? [{ opacity: element.style.opacity }, { opacity: opening ? 1 : 0 }]
        : [{ height: element.style.height, opacity: element.style.opacity }, { height: `${opening ? targetHeight : 0}px`, opacity: opening ? 1 : 0 }];
    const animation = element.animate(frames, {
        duration: reduced ? 100 : opening ? 200 : 150,
        easing: getComputedStyle(element).getPropertyValue('--resident-sidebar-ease').trim(),
    });
    dateAnimations.set(element, animation);
    animation.onfinish = () => {
        dateAnimations.delete(element);
        element.style.height = '';
        element.style.opacity = '';
        done();
    };
}

function toggleDates(event) {
    animateDates.value = Boolean(event?.detail);
    if (!showDates.value) {
        dateDraft.value = { from: filters.value.from, to: filters.value.to };
        dateError.value = '';
    }
    showDates.value = !showDates.value;
}

async function closeDates(event) {
    animateDates.value = Boolean(event?.detail);
    dateDraft.value = { from: filters.value.from, to: filters.value.to };
    dateError.value = '';
    showDates.value = false;
    await nextTick();
    datesTrigger.value?.$el?.focus();
}

function clearDates() {
    dateDraft.value = { from: '', to: '' };
    filters.value.from = '';
    filters.value.to = '';
    dateError.value = '';
    commitQuery();
}

function applyDates(event) {
    const from = dateDraft.value.from;
    const to = dateDraft.value.to;
    if (from && to && from > to) {
        dateError.value = 'From must be on or before To.';
        return;
    }
    dateError.value = '';
    filters.value.from = from;
    filters.value.to = to;
    commitQuery();
    closeDates(event);
}

function clearFilters(event) {
    animateDates.value = Boolean(event?.detail);
    clearTimeout(searchTimer);
    searchTimer = null;
    searchInput.value = '';
    filters.value = { search: '', status: '', from: '', to: '' };
    dateDraft.value = { from: '', to: '' };
    dateError.value = '';
    showDates.value = false;
    commitQuery();
}

function viewTo(report) {
    return { path: `/reports/${report.id}`, query: { ...route.query } };
}

function primaryAction(report) {
    if (report.status === 'draft') return { label: 'Continue Draft', icon: 'pencil', to: `/report/${report.id}` };
    return { label: 'View Report', icon: 'eye', to: viewTo(report) };
}

function secondaryAction(report) {
    if (report.editable && report.status === 'returned') {
        return { label: 'Revise Report', icon: 'pencil', to: `/report/${report.id}` };
    }
    return null;
}

function toggleMenu(report, event) {
    activeReport.value = report;
    reportMenu.value.toggle(event);
}

function chooseMenu(report) {
    if (deleting.value || report.status !== 'draft') return;
    actionMessage.value = '';
    pendingDelete.value = report;
    confirm.require({
        header: 'Delete draft',
        message: `Delete draft ${report.reference}? This cannot be undone.`,
        acceptLabel: 'Delete draft',
        rejectLabel: 'Cancel',
        defaultFocus: 'reject',
        acceptProps: { severity: 'danger', size: 'small' },
        rejectProps: { severity: 'secondary', outlined: true, size: 'small' },
        accept: confirmDelete,
        reject: () => { pendingDelete.value = null; },
        onHide: () => { pendingDelete.value = null; },
    });
}

async function confirmDelete() {
    const report = pendingDelete.value;
    pendingDelete.value = null;
    if (!report) return;
    deleting.value = true;
    try {
        await window.axios.delete(`${apiBase}/reports/${report.id}`);
        actionMessage.value = `Draft ${report.reference} deleted.`;
        await load();
    } catch (exception) {
        actionMessage.value = exception.response?.data?.message || 'Draft could not be deleted. Try again from its actions menu.';
    } finally {
        deleting.value = false;
    }
}

watch(() => JSON.stringify(readQuery(route.query)), () => {
    first.value = 0;
    const next = readQuery(route.query);
    const datesChanged = next.from !== filters.value.from || next.to !== filters.value.to;
    filters.value = { ...next, search: searchTimer ? filters.value.search : next.search };
    if (!searchTimer) searchInput.value = next.search;
    if (datesChanged) {
        dateDraft.value = { from: next.from, to: next.to };
        if (next.from || next.to) showDates.value = true;
    }
    load();
}, { immediate: true });

onBeforeUnmount(() => {
    requestId += 1;
    clearTimeout(searchTimer);

});
</script>

<template>
    <section class="resident-reports resident-stack" aria-label="My reports">
        <header class="reports-intro">
            <div>
                <h2>Report history</h2>
                <p>Follow your submissions, resume drafts, and make corrections.</p>
            </div>
            <Button as="router-link" to="/report" size="small"><MapIcon name="plus" /> New Report</Button>
        </header>

        <div class="reports-workspace">
            <div class="reports-toolbar">
                <div class="reports-search">
                    <label for="reports-search">Search reports</label>
                    <IconField>
                        <InputIcon><MapIcon name="search" /></InputIcon>
                        <InputText id="reports-search" ref="searchField" v-model="searchInput" size="small" type="search" placeholder="Reference or incident"
                            @input="onSearchInput" @keydown.enter="submitSearch"
                            @compositionstart="composing = true" @compositionend="composing = false; onSearchInput()" />
                        <Button v-if="searchInput" size="small" text severity="secondary" class="reports-search-clear" aria-label="Clear search" @click="clearSearch"><MapIcon name="x" /></Button>
                    </IconField>
                </div>
                <div class="reports-status-filter">
                    <label for="reports-status">Status</label>
                    <Select v-model="selectedStatus" input-id="reports-status" aria-label="Status" size="small" :options="statuses" option-label="label" option-value="value" placeholder="All reports" show-clear panel-class="reports-select-panel" :pt="instantOverlay" />
                </div>
                <Button ref="datesTrigger" size="small" severity="secondary" outlined :aria-expanded="showDates" aria-controls="report-dates" :class="{ 'is-active': showDates || datesApplied }" @click="toggleDates"><MapIcon name="calendar" /> {{ datesApplied ? 'Dates applied' : 'Dates' }}</Button>
                <Button size="small" text severity="secondary" :disabled="!filtersActive" @click="clearFilters">Reset</Button>
            </div>
            <Transition :css="false" @before-enter="prepareDateMotion" @enter="(element, done) => transitionDates(element, done, true)" @enter-cancelled="prepareDateMotion" @before-leave="prepareDateMotion" @leave="(element, done) => transitionDates(element, done, false)" @leave-cancelled="prepareDateMotion">
                <div v-show="showDates" id="report-dates" class="reports-date-disclosure" :inert="!showDates" :aria-hidden="!showDates">
            <section class="reports-date-panel" aria-labelledby="reports-date-title">
                <div class="reports-date-heading">
                    <div>
                        <h3 id="reports-date-title">Updated date</h3>
                        <p id="reports-date-hint">Include reports updated within this range. Use MM/DD/YYYY.</p>
                    </div>
                    <Button size="small" text severity="secondary" :disabled="!dateDraft.from && !dateDraft.to && !datesApplied" @click="clearDates">Clear dates</Button>
                </div>
                <div class="reports-date-range">
                    <div class="reports-date-field">
                        <label for="reports-from">From</label>
                        <DatePicker v-model="fromDate" input-id="reports-from" size="small" panel-class="reports-calendar-panel" :pt="{ ...instantOverlay, dropdown: { 'aria-label': 'Choose start date' } }" date-format="mm/dd/yy" placeholder="MM/DD/YYYY" show-icon :show-on-focus="false" show-button-bar :today-button-props="{ size: 'small', severity: 'secondary', text: true }" :clear-button-props="{ size: 'small', severity: 'secondary', text: true }" :invalid="!!dateError" :input-props="{ inputmode: 'text', enterkeyhint: 'next', 'aria-describedby': dateError ? 'reports-date-hint reports-date-error' : 'reports-date-hint' }" />
                    </div>
                    <span class="reports-date-separator" aria-hidden="true">–</span>
                    <div class="reports-date-field">
                        <label for="reports-to">To</label>
                        <DatePicker v-model="toDate" input-id="reports-to" size="small" panel-class="reports-calendar-panel" :pt="{ ...instantOverlay, dropdown: { 'aria-label': 'Choose end date' } }" date-format="mm/dd/yy" placeholder="MM/DD/YYYY" show-icon :show-on-focus="false" show-button-bar :today-button-props="{ size: 'small', severity: 'secondary', text: true }" :clear-button-props="{ size: 'small', severity: 'secondary', text: true }" :invalid="!!dateError" :input-props="{ inputmode: 'text', enterkeyhint: 'done', 'aria-describedby': dateError ? 'reports-date-hint reports-date-error' : 'reports-date-hint' }" />
                    </div>
                    <div class="reports-date-actions">
                        <Button size="small" severity="secondary" text @click="closeDates">Cancel</Button>
                        <Button size="small" @click="applyDates">Apply dates</Button>
                    </div>
                </div>
                <Message v-if="dateError" id="reports-date-error" severity="error" :closable="false">{{ dateError }}</Message>
            </section>
                </div>
            </Transition>
            <div class="reports-list-caption">
                <p role="status">{{ loading ? 'Loading reports…' : `${reports.length} ${reports.length === 1 ? 'report' : 'reports'}${queryActive ? ' found' : ''}` }}</p>
                <span>Latest updated first</span>
            </div>
            <Message v-if="actionMessage" severity="info" :closable="false" class="reports-feedback">{{ actionMessage }}</Message>
            <div v-if="loading" class="reports-state" role="status" aria-label="Loading reports" aria-busy="true"><ProgressSpinner style="width: 24px; height: 24px" stroke-width="4" /><p>Getting your reports…</p></div>
            <div v-else-if="error" class="reports-state">
                <Message severity="error" :closable="false">{{ error }}</Message>
                <Button size="small" severity="secondary" outlined @click="load">Retry</Button>
            </div>
            <EmptyState v-else-if="!reports.length" :icon="queryActive ? 'search' : 'file'" :title="queryActive ? 'No matching reports' : 'Your reports start here'">
                <p>{{ queryActive ? 'Try another reference, incident, or date range.' : 'Create a report to share what happened in your area.' }}</p>
                <Button v-if="queryActive" size="small" severity="secondary" outlined @click="clearFilters">Clear filters</Button>
                <Button v-else as="router-link" to="/report" size="small">New Report</Button>
            </EmptyState>
            <template v-else>
                <div class="reports-column-head" aria-hidden="true"><span>Report / incident</span><span>Status</span><span>Last updated</span><span>Actions</span></div>
                <ol class="reports-ledger" aria-label="Reports, latest updated first">
                    <li v-for="report in pageReports" :key="report.id" class="reports-row" :class="{ 'needs-correction': report.status === 'returned' }">
                        <div class="reports-identity">
                            <router-link :to="viewTo(report)" class="reports-reference">{{ report.reference }}</router-link>
                            <p class="reports-incident">{{ report.incident_name || report.incident_type || 'Incident' }}</p>
                            <p v-if="report.reported_location" class="reports-location"><MapIcon name="pin" /><span>{{ report.reported_location }}</span></p>
                        </div>
                        <div class="reports-status"><StatusBadge :label="report.label" /></div>
                        <p class="reports-updated"><span class="reports-mobile-label">Updated </span>{{ report.updated_at ? formatWhen(report.updated_at) : 'Not available' }}</p>
                        <div class="reports-actions">
                            <Button as="router-link" :to="secondaryAction(report)?.to || primaryAction(report).to" size="small" :severity="report.status === 'returned' || report.status === 'draft' ? 'primary' : 'secondary'" outlined>
                                <MapIcon :name="secondaryAction(report)?.icon || primaryAction(report).icon" />{{ secondaryAction(report)?.label || primaryAction(report).label }}
                            </Button>
                            <Button size="small" text severity="secondary" class="reports-more" aria-haspopup="menu" aria-controls="report-actions" :aria-label="`Actions for ${report.reference}`" :disabled="deleting" @click="toggleMenu(report, $event)"><MapIcon name="more" /></Button>
                        </div>
                    </li>
                </ol>
                <footer class="reports-footer"><span>Showing {{ first + 1 }}–{{ Math.min(first + 10, reports.length) }} of {{ reports.length }}</span><Paginator v-if="reports.length > 10" v-model:first="first" :rows="10" :total-records="reports.length" template="PrevPageLink PageLinks NextPageLink" /></footer>
            </template>
        </div>
        <Menu id="report-actions" ref="reportMenu" :model="reportActions" class="reports-action-menu" :pt="instantOverlay" popup />
    </section>
</template>
