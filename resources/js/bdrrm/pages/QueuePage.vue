<script setup>
import { computed, inject, nextTick, onBeforeUnmount, onMounted, ref, watch } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import Button from 'primevue/button';
import DatePicker from 'primevue/datepicker';
import IconField from 'primevue/iconfield';
import InputIcon from 'primevue/inputicon';
import InputText from 'primevue/inputtext';
import Menu from 'primevue/menu';
import Message from 'primevue/message';
import Select from 'primevue/select';
import Skeleton from 'primevue/skeleton';
import Tag from 'primevue/tag';
import MapIcon from '../../shared/components/MapIcon.vue';
import { dateToIso, isoToDate } from '../../shared/ui/dates';

const apiBase = inject('apiBase');
const route = useRoute();
const router = useRouter();
const rows = ref([]);
const meta = ref({ current_page: 1, last_page: 1, per_page: 10, total: 0 });
const counts = ref(null);
const countsError = ref('');
const error = ref('');
const loading = ref(false);
const loadedQueue = ref('');
const correctionMenu = ref(null);
const correctionMenuRow = ref(null);
const search = ref('');
const incidentId = ref('');
const barangayId = ref('');
const stage = ref('');
const sort = ref('updated_at');
const direction = ref('desc');
const dateFrom = ref('');
const dateTo = ref('');
const page = ref(1);
const perPage = ref(10);
const incidents = ref([]);
const barangays = ref([]);
let requestId = 0;
let searchTimer;
let composing = false;
let syncing = false;
const ready = ref(false);

const queues = [
    { key: 'drafts', status: 'draft', label: 'Draft Reports', to: '/reports/drafts', icon: 'file-pen' },
    { key: 'returned', status: 'returned', label: 'Returned for Correction', to: '/reports/returned', icon: 'undo' },
    { key: 'submitted', status: 'handed-off', label: 'Submitted Reports', to: '/reports/submitted', icon: 'send' },
];

const current = computed(() => queues.find((queue) => queue.status === route.meta.status) || queues[0]);
const isDraft = computed(() => current.value.status === 'draft');
const isSubmitted = computed(() => current.value.status === 'handed-off');
const isReturned = computed(() => current.value.status === 'returned');
const selectOverlay = computed(() => isReturned.value ? undefined : 'queue-select-overlay');
const dateLabel = computed(() => {
    if (current.value.status === 'returned') return 'Returned date';
    if (current.value.status === 'handed-off') return 'Submitted date';
    return 'Last updated';
});
const dateFromKey = computed(() => {
    if (current.value.status === 'returned') return 'returned_from';
    if (current.value.status === 'handed-off') return 'submitted_from';
    return 'updated_from';
});
const dateToKey = computed(() => dateFromKey.value.replace('_from', '_to'));
const filtersActive = computed(() => Boolean(search.value.trim() || incidentId.value || barangayId.value || stage.value || dateFrom.value || dateTo.value || sort.value !== 'updated_at' || direction.value !== 'desc'));
const queueCountKey = computed(() => current.value.key);

const queueLoaded = computed(() => loadedQueue.value === current.value.status);
const queueRows = computed(() => queueLoaded.value ? rows.value : []);
const incidentOptions = computed(() => [
    { label: 'All incidents', value: '' },
    ...incidents.value.map((incident) => ({ label: incident.incident_name, value: String(incident.id) })),
]);
const barangayOptions = computed(() => [
    { label: isSubmitted.value ? 'All barangays' : 'All assigned barangays', value: '' },
    ...barangays.value.map((barangay) => ({ label: barangay.name, value: String(barangay.id) })),
]);
const reviewStatusOptions = [
    { label: 'All statuses', value: '' },
    { label: 'Awaiting the next office', value: 'review' },
    { label: 'Signed', value: 'signed' },
    { label: 'Submitted', value: 'submitted' },
];
const sortOptions = computed(() => [
    { label: 'Last updated', value: 'updated_at' },
    ...(isReturned.value || sort.value === 'returned_at' ? [{ label: 'Returned date', value: 'returned_at' }] : []),
    ...(isSubmitted.value || sort.value === 'submitted_at' ? [{ label: 'Submitted date', value: 'submitted_at' }] : []),
    { label: 'Reference', value: 'report_reference' },
]);
const directionOptions = computed(() => [
    { label: sort.value === 'report_reference' ? 'Z to A' : 'Newest first', value: 'desc' },
    { label: sort.value === 'report_reference' ? 'A to Z' : 'Oldest first', value: 'asc' },
]);
const calendarNavigation = { size: 'small', severity: 'secondary', text: true };

function statusSeverity(status) {
    if (status === 'returned') return 'warn';
    if (status === 'signed') return 'secondary';
    return 'info';
}

function formatWhen(value) {
    if (!value) return '';
    const date = new Date(String(value).includes('T') ? value : `${String(value).replace(' ', 'T')}Z`);
    if (Number.isNaN(date.getTime())) return String(value);
    return `${new Intl.DateTimeFormat('en-PH', {
        timeZone: 'Asia/Manila',
        day: 'numeric',
        month: 'short',
        year: 'numeric',
        hour: 'numeric',
        minute: '2-digit',
    }).format(date)} PHT`;
}

function readRoute() {
    syncing = true;
    search.value = typeof route.query.q === 'string' ? route.query.q : '';
    incidentId.value = typeof route.query.incident_id === 'string' ? route.query.incident_id : '';
    barangayId.value = typeof route.query.barangay_id === 'string' ? route.query.barangay_id : '';
    stage.value = ['review', 'signed', 'submitted'].includes(route.query.stage) ? route.query.stage : '';
    sort.value = ['updated_at', 'submitted_at', 'returned_at', 'report_reference'].includes(route.query.sort) ? route.query.sort : 'updated_at';
    direction.value = route.query.direction === 'asc' ? 'asc' : 'desc';
    dateFrom.value = typeof route.query[dateFromKey.value] === 'string' ? route.query[dateFromKey.value] : '';
    dateTo.value = typeof route.query[dateToKey.value] === 'string' ? route.query[dateToKey.value] : '';
    page.value = Number.parseInt(route.query.page, 10) > 0 ? Number.parseInt(route.query.page, 10) : 1;
    perPage.value = [10, 25, 50].includes(Number(route.query.per_page)) ? Number(route.query.per_page) : 10;
    syncing = false;
}

function queryObject(nextPage = page.value) {
    const query = {};
    if (search.value.trim()) query.q = search.value.trim();
    if (incidentId.value) query.incident_id = incidentId.value;
    if (barangayId.value) query.barangay_id = barangayId.value;
    if (current.value.status === 'handed-off' && stage.value) query.stage = stage.value;
    if (sort.value !== 'updated_at') query.sort = sort.value;
    if (direction.value === 'asc') query.direction = 'asc';
    if (dateFrom.value) query[dateFromKey.value] = dateFrom.value;
    if (dateTo.value) query[dateToKey.value] = dateTo.value;
    if (nextPage > 1) query.page = String(nextPage);
    if (perPage.value !== 10) query.per_page = String(perPage.value);
    return query;
}

function pushQuery(resetPage = false) {
    clearTimeout(searchTimer);
    const query = queryObject(resetPage ? 1 : page.value);
    const target = router.resolve({ path: route.path, query }).fullPath;
    if (target === route.fullPath) return;
    router.replace({ path: route.path, query });
}

async function loadCounts() {
    try {
        const { data } = await window.axios.get(`${apiBase}/navigation`);
        counts.value = data;
        countsError.value = '';
    } catch {
        countsError.value = 'Queue counts could not be loaded.';
    }
}

async function loadOptions() {
    try {
        const summary = await window.axios.get(`${apiBase}/summary`);
        barangays.value = summary.data.barangays || [];
        incidents.value = (await window.axios.get(`${apiBase}/incidents`)).data.data || [];
    } catch {
        barangays.value = [];
        incidents.value = [];
    }
}

async function load() {
    const ticket = ++requestId;
    const queueStatus = current.value.status;
    loading.value = true;
    try {
        const params = {
            status: queueStatus === 'handed-off' && stage.value ? stage.value : queueStatus,
            per_page: perPage.value,
            page: page.value,
            sort: sort.value,
            direction: direction.value,
        };
        if (search.value.trim()) params.q = search.value.trim();
        if (incidentId.value) params.incident_id = incidentId.value;
        if (barangayId.value) params.barangay_id = barangayId.value;
        if (dateFrom.value) params[dateFromKey.value] = dateFrom.value;
        if (dateTo.value) params[dateToKey.value] = dateTo.value;
        const { data } = await window.axios.get(`${apiBase}/reports`, { params });
        if (ticket !== requestId) return;
        rows.value = data.data || [];
        meta.value = data.meta || meta.value;
        error.value = '';
        loadedQueue.value = queueStatus;
    } catch (exception) {
        if (ticket !== requestId) return;
        error.value = exception.response?.data?.message || 'The report list could not be loaded.';
    } finally {
        if (ticket === requestId) loading.value = false;
    }
}

function openReport(row, panel) {
    const query = { from: route.fullPath };
    if (panel) query.panel = panel;
    const readOnly = !['draft', 'returned'].includes(row.status);
    router.push({ path: readOnly ? `/reports/${row.id}/view` : `/reports/${row.id}`, query });
}

function previewHref(row) {
    return `/bdrrm/reports/${row.id}/preview`;
}

const correctionActions = computed(() => {
    const row = correctionMenuRow.value;
    if (!row) return [];
    return [
        { label: 'Preview Report', icon: 'eye', url: previewHref(row) },
        { label: 'Download PDF', icon: 'download', url: `${previewHref(row)}?format=pdf` },
        { label: 'View Review History', icon: 'history', command: () => openReport(row, 'history') },
    ];
});

async function toggleCorrectionMenu(event, row) {
    const target = event.currentTarget;
    if (correctionMenuRow.value) {
        const sameRow = correctionMenuRow.value.id === row.id;
        correctionMenu.value.hide();
        if (sameRow) return;
        await nextTick();
    }
    correctionMenuRow.value = row;
    correctionMenu.value.show(event, target);
}

function clearFilters() {
    search.value = '';
    incidentId.value = '';
    barangayId.value = '';
    stage.value = '';
    sort.value = 'updated_at';
    direction.value = 'desc';
    dateFrom.value = '';
    dateTo.value = '';
    pushQuery(true);
}

function focusQueueSearch() {
    document.getElementById('report-queue-search')?.focus();
}

function clearSearch() {
    search.value = '';
    pushQuery(true);
    focusQueueSearch();
}

function scheduleSearch() {
    clearTimeout(searchTimer);
    if (!composing) searchTimer = setTimeout(() => pushQuery(true), 300);
}

function submitSearch(event) {
    if (composing || event.isComposing) return;
    event.preventDefault();
    pushQuery(true);
}

watch(search, () => {
    if (ready.value && !syncing) scheduleSearch();
}, { flush: 'sync' });

watch(() => route.fullPath, async () => {
    if (route.meta.source !== 'reports') return;
    clearTimeout(searchTimer);
    composing = false;
    correctionMenu.value?.hide();
    readRoute();
    await load();
});

onMounted(async () => {
    readRoute();
    ready.value = true;
    await Promise.all([loadOptions(), loadCounts(), load()]);
});

onBeforeUnmount(() => {
    clearTimeout(searchTimer);
});
</script>

<template>
    <section class="bdrrm-report-queue @container grid min-w-0 gap-4 text-sm text-surface-900"
        :class="{ 'bdrrm-drafts': isDraft, 'bdrrm-submitted': isSubmitted }">
        <header class="flex flex-wrap items-start justify-between gap-x-6 gap-y-3">
            <div class="min-w-0">
                <h2 class="text-2xl leading-tight font-bold tracking-tight">{{ route.meta.title }}</h2>
                <p class="mt-1 text-surface-600">
                    {{ isSubmitted ? 'Track the review status of your submitted barangay reports.' : isDraft
                        ? 'Find and continue preparing your unfinished barangay reports.'
                        : 'Review requested changes, update your reports, and resubmit them for review.' }}
                </p>
            </div>
            <Button as="router-link" to="/reports/new" label="Create Report" size="small" class="ms-auto">
                <template #icon><MapIcon name="plus" /></template>
            </Button>
        </header>

        <nav aria-label="Report queues" class="grid gap-2 @xl:gap-3"
            :class="isDraft ? '@min-[21rem]:grid-cols-3' : 'grid-cols-3'">
            <router-link v-for="queue in queues" :key="queue.key" :to="queue.to"
                class="correction-summary grid min-w-0 content-start gap-1.5 rounded-lg border border-surface-200 bg-surface-0 p-2.5 text-inherit no-underline hover:border-primary-300 @xl:p-3.5">
                <template v-if="isDraft">
                    <span class="grid size-8 shrink-0 place-items-center rounded-md"
                        :class="{ 'bg-indigo-100 text-indigo-800': queue.key === 'drafts', 'bg-amber-50 text-amber-800': queue.key === 'returned', 'bg-blue-50 text-blue-800': queue.key === 'submitted' }">
                        <MapIcon :name="queue.icon" />
                    </span>
                    <span class="min-w-0 text-xs leading-tight font-medium wrap-anywhere @xl:text-sm">{{ queue.label }}</span>
                    <strong class="text-xl leading-none font-bold tabular-nums @xl:text-2xl">{{ counts?.[queue.key] ?? '—' }}</strong>
                </template>
                <template v-else>
                    <span class="flex items-center justify-between gap-1.5">
                        <strong class="text-2xl leading-none font-bold tabular-nums">{{ counts?.[queue.key] ?? '—' }}</strong>
                        <MapIcon :name="queue.icon" class="text-primary-700" />
                    </span>
                    <span class="text-xs leading-tight font-medium wrap-anywhere @xl:text-sm">{{ queue.label }}</span>
                </template>
            </router-link>
        </nav>
        <Message v-if="countsError" severity="warn" size="small">
            {{ countsError }}
            <Button type="button" label="Retry" size="small" severity="secondary" text @click="loadCounts" />
        </Message>

        <form :aria-label="`Filter ${current.label.toLowerCase()}`" novalidate
            class="grid min-w-0 gap-2.5 rounded-lg border border-surface-200 bg-surface-0 p-3 @xl:p-3.5"
            @submit.prevent="!composing && pushQuery(true)">
            <div class="grid min-w-0 grid-cols-2 gap-x-3 gap-y-2.5"
                :class="isSubmitted
                    ? ['@min-[40rem]:grid-cols-3', barangays.length > 1
                        ? '@min-[68rem]:grid-cols-[minmax(0,1.6fr)_minmax(0,1fr)_minmax(0,1.2fr)_minmax(0,1fr)_minmax(0,0.9fr)_minmax(0,1.25fr)]'
                        : '@min-[57rem]:grid-cols-[minmax(0,1.6fr)_minmax(0,1.15fr)_minmax(0,1.25fr)_minmax(0,1fr)_minmax(0,0.85fr)]']
                    : '@min-[46rem]:grid-cols-[minmax(13rem,1.65fr)_minmax(9rem,1.15fr)_minmax(8rem,1fr)_minmax(7rem,0.85fr)]'">
                <div class="grid min-w-0 gap-1" :class="isSubmitted && barangays.length <= 1
                    ? 'col-span-2 @min-[57rem]:col-span-1'
                    : isSubmitted ? 'col-span-2 @min-[40rem]:col-span-1' : 'col-span-2 @min-[32rem]:col-span-1'">
                    <label for="report-queue-search" class="text-xs font-semibold text-surface-700">Search reports</label>
                    <IconField>
                        <InputIcon><MapIcon name="search" /></InputIcon>
                        <InputText id="report-queue-search" v-model="search" size="small" class="w-full" type="search"
                            maxlength="80" autocomplete="off" :placeholder="isSubmitted ? 'Reference or keyword' : 'Reference, incident, or barangay'"
                            :class="{ 'queue-search-active': !isReturned && search }"
                            @keydown.enter="submitSearch"
                            @compositionstart="composing = true; scheduleSearch()" @compositionend="composing = false; scheduleSearch()" />
                        <Button v-if="!isReturned && search" class="queue-search-clear absolute inset-y-0 end-0" type="button"
                            aria-label="Clear search" size="small" severity="secondary" text @click="clearSearch">
                            <template #icon><MapIcon name="x" /></template>
                        </Button>
                    </IconField>
                </div>
                <div class="grid min-w-0 gap-1" :class="{ 'col-span-2 @min-[32rem]:col-span-1': !isSubmitted }">
                    <label id="correction-incident-label" for="correction-incident" class="text-xs font-semibold text-surface-700">Incident</label>
                    <Select v-model="incidentId" input-id="correction-incident" aria-labelledby="correction-incident-label"
                        :options="incidentOptions" option-label="label" option-value="value" placeholder="All incidents" size="small" fluid
                        :overlay-class="selectOverlay" @change="pushQuery(true)" />
                </div>
                <div v-if="isSubmitted" class="grid min-w-0 gap-1">
                    <label id="review-status-label" for="review-status" class="text-xs font-semibold text-surface-700">Review status</label>
                    <Select v-model="stage" input-id="review-status" aria-labelledby="review-status-label"
                        :options="reviewStatusOptions" option-label="label" option-value="value" placeholder="All statuses" size="small" fluid
                        :overlay-class="selectOverlay" @change="pushQuery(true)" />
                </div>
                <div class="grid min-w-0 gap-1">
                    <label id="correction-sort-label" for="correction-sort" class="text-xs font-semibold text-surface-700">Sort by</label>
                    <Select v-model="sort" input-id="correction-sort" aria-labelledby="correction-sort-label"
                        :options="sortOptions" option-label="label" option-value="value" size="small" fluid
                        :overlay-class="selectOverlay" @change="pushQuery(true)" />
                </div>
                <div class="grid min-w-0 gap-1">
                    <label id="correction-order-label" for="correction-order" class="text-xs font-semibold text-surface-700">Sort direction</label>
                    <Select v-model="direction" input-id="correction-order" aria-labelledby="correction-order-label"
                        :options="directionOptions" option-label="label" option-value="value" size="small" fluid
                        :overlay-class="selectOverlay" @change="pushQuery(true)" />
                </div>
                <div v-if="barangays.length > 1" class="col-span-2 grid min-w-0 gap-1 @min-[32rem]:col-span-1">
                    <label id="correction-barangay-label" for="correction-barangay" class="text-xs font-semibold text-surface-700">Barangay</label>
                    <Select v-model="barangayId" input-id="correction-barangay" aria-labelledby="correction-barangay-label"
                        :options="barangayOptions" option-label="label" option-value="value" :placeholder="barangayOptions[0].label"
                        size="small" fluid :overlay-class="selectOverlay" @change="pushQuery(true)" />
                </div>
            </div>
            <div class="grid min-w-0 grid-cols-[minmax(0,1fr)_auto] items-start gap-x-3 border-t border-surface-200 pt-2">
                <details :key="current.key" class="correction-dates min-w-0">
                    <summary class="inline-flex cursor-pointer list-none items-center gap-2 rounded-lg text-xs font-medium text-primary-800">
                        <MapIcon name="calendar" />
                        {{ dateLabel }}
                        <span v-if="dateFrom || dateTo" class="rounded-sm bg-primary-50 px-1.5 text-primary-900">Set</span>
                        <MapIcon name="chevron" class="correction-date-chevron" />
                    </summary>
                    <div class="mt-2 grid max-w-sm gap-2 @min-[28rem]:grid-cols-2">
                        <div class="grid min-w-0 gap-1">
                            <label for="correction-from" class="text-xs font-semibold text-surface-700">From</label>
                            <DatePicker input-id="correction-from" :aria-label="`${dateLabel} from`" :model-value="isoToDate(dateFrom)"
                                size="small" fluid date-format="M d, yy" placeholder="Start date" show-icon icon-display="input"
                                show-button-bar :navigator-button-props="calendarNavigation" panel-class="correction-calendar"
                                @update:model-value="dateFrom = dateToIso($event); pushQuery(true)" />
                        </div>
                        <div class="grid min-w-0 gap-1">
                            <label for="correction-to" class="text-xs font-semibold text-surface-700">To</label>
                            <DatePicker input-id="correction-to" :aria-label="`${dateLabel} to`" :model-value="isoToDate(dateTo)"
                                size="small" fluid date-format="M d, yy" placeholder="End date" show-icon icon-display="input"
                                show-button-bar :navigator-button-props="calendarNavigation" panel-class="correction-calendar"
                                @update:model-value="dateTo = dateToIso($event); pushQuery(true)" />
                        </div>
                    </div>
                </details>
                <Button v-if="filtersActive" type="button" label="Clear filters" size="small" severity="secondary" text
                    @click="clearFilters(); focusQueueSearch()">
                    <template #icon><MapIcon name="filter-reset" /></template>
                </Button>
            </div>
        </form>

        <section :aria-label="current.label" class="grid min-w-0 gap-2.5">
            <p v-if="queueLoaded" class="text-xs text-surface-600 tabular-nums" role="status">
                <strong class="font-semibold text-surface-800">{{ meta.total }} matching {{ meta.total === 1 ? 'report' : 'reports' }}</strong>
                <template v-if="filtersActive && counts"> · {{ counts[queueCountKey] }} in queue</template>
            </p>
            <Message v-if="error && !loading" severity="error" size="small">
                {{ error }}
                <Button type="button" label="Retry" size="small" severity="secondary" text @click="load" />
            </Message>
            <div v-if="loading && !queueRows.length" class="grid gap-2.5" aria-busy="true">
                <p class="sr-only" role="status">Loading reports…</p>
                <Skeleton v-for="slot in 3" :key="slot" height="7rem" border-radius="0.5rem" animation="none" aria-hidden="true" />
            </div>
            <div v-else-if="queueRows.length" class="grid gap-2.5" :aria-busy="loading">
                <article v-for="row in queueRows" :key="row.id"
                    class="grid min-w-0 gap-3 rounded-lg border border-surface-200 bg-surface-0 p-3 wrap-anywhere @xl:p-4 @min-[35rem]:grid-cols-[minmax(0,1fr)_auto]">
                    <div class="min-w-0">
                        <div class="flex flex-wrap items-center gap-x-2.5 gap-y-1">
                            <h3 class="text-base leading-tight font-semibold tabular-nums">{{ row.reference }}</h3>
                            <Tag :value="row.label" :severity="statusSeverity(row.status)" :data-status="row.status" />
                        </div>
                        <p class="mt-1 font-medium text-surface-800">{{ row.incident_name || 'Incident not recorded' }}</p>
                        <div class="correction-meta mt-1.5 flex flex-wrap gap-x-4 gap-y-1 text-xs text-surface-600">
                            <span><MapIcon name="pin" />{{ row.barangay_name || 'Barangay not recorded' }}</span>
                            <span v-if="isSubmitted"><MapIcon name="calendar" />Submitted: {{ formatWhen(row.submitted_at) || 'Date not recorded' }}</span>
                            <span v-else><MapIcon name="clock" />Last updated: {{ formatWhen(row.updated_at) || 'Not recorded' }}</span>
                            <span v-if="isReturned && row.returned_at"><MapIcon name="calendar" />Returned: {{ formatWhen(row.returned_at) }}</span>
                            <span v-if="row.resident_reference">
                                <MapIcon name="link" />
                                <span v-if="!isReturned">Resident report {{ row.resident_reference }}</span>
                                <template v-else>{{ row.resident_reference }}</template>
                            </span>
                            <span v-if="row.signature_recorded"><MapIcon name="pen" />Signature recorded</span>
                        </div>
                        <p v-if="!isReturned && row.description" class="mt-2 text-xs leading-relaxed text-surface-600">{{ row.description }}</p>
                    </div>
                    <div v-if="isReturned" class="correction-feedback rounded-md px-3 py-2 @min-[35rem]:col-span-2">
                        <h4 class="text-xs font-semibold">Reviewer feedback</h4>
                        <p class="mt-0.5 leading-snug whitespace-pre-wrap text-surface-800">{{ row.return_reason || 'Correction feedback is not available.' }}</p>
                    </div>
                    <p v-if="isReturned && row.description" class="text-xs text-surface-600 @min-[35rem]:col-span-2">{{ row.description }}</p>
                    <div class="flex flex-wrap items-center gap-1.5 self-start @min-[35rem]:col-start-2 @min-[35rem]:row-start-1"
                        :class="{ 'border-t border-surface-200 pt-2.5 @min-[35rem]:border-t-0 @min-[35rem]:pt-0': !isReturned }">
                        <Button type="button" :label="isSubmitted ? 'View Report' : isDraft ? 'Continue Editing' : 'Review Corrections'" size="small" @click="openReport(row)">
                            <template #icon><MapIcon :name="isSubmitted ? 'eye' : 'pencil'" /></template>
                        </Button>
                        <Button type="button" :aria-label="'More actions for ' + row.reference"
                            aria-haspopup="menu" aria-controls="correction-menu"
                            :aria-expanded="correctionMenuRow?.id === row.id" size="small" severity="secondary" outlined
                            @click="toggleCorrectionMenu($event, row)">
                            <template #icon><MapIcon name="more" /></template>
                        </Button>
                    </div>
                </article>
            </div>
            <div v-else-if="queueLoaded && !error"
                class="grid justify-items-center gap-1 rounded-lg border border-surface-200 bg-surface-0 px-4 py-8 text-center @xl:py-10">
                <span class="mb-2 grid size-11 place-items-center rounded-xl bg-primary-50 text-primary-800">
                    <MapIcon :name="filtersActive ? 'search' : isReturned ? 'clipboard-check' : current.icon" class="correction-empty-icon" />
                </span>
                <h3 class="text-base leading-tight font-semibold">
                    {{ filtersActive ? 'No matching reports' : isSubmitted ? 'No submitted reports' : isDraft ? 'No draft reports' : 'No reports need correction' }}
                </h3>
                <p class="text-surface-600">
                    {{ filtersActive ? 'Adjust the search or filters to find a report.' : isSubmitted
                        ? 'Reports sent for review will appear here with their recorded status.' : isDraft
                        ? 'Reports you save will appear here until they are submitted.'
                        : 'Reports returned by reviewers will appear here with their feedback.' }}
                </p>
                <Button v-if="isDraft && filtersActive" type="button" class="mt-3" label="Clear filters" size="small"
                    severity="secondary" outlined @click="clearFilters(); focusQueueSearch()" />
                <Button v-else-if="isDraft" as="router-link" to="/reports/new" class="mt-3" label="Create Report" size="small">
                    <template #icon><MapIcon name="plus" /></template>
                </Button>
                <div v-else-if="!filtersActive" class="mt-3 flex flex-wrap justify-center gap-2">
                    <Button as="router-link" to="/reports/drafts" label="View Drafts" size="small" severity="secondary" outlined />
                    <Button v-if="isReturned" as="router-link" to="/reports/submitted" label="Submitted Reports" size="small" severity="secondary" outlined />
                </div>
            </div>
            <nav v-if="queueLoaded && meta.last_page > 1" aria-label="Report pages"
                class="flex flex-wrap items-center justify-between gap-2 text-xs text-surface-600">
                <div class="flex flex-wrap items-center gap-2">
                    <Button type="button" label="Previous" size="small" severity="secondary" outlined :disabled="page <= 1" @click="page -= 1; pushQuery()" />
                    <span class="tabular-nums">Page {{ meta.current_page }} of {{ meta.last_page }}</span>
                    <Button type="button" label="Next" size="small" severity="secondary" outlined :disabled="page >= meta.last_page" @click="page += 1; pushQuery()" />
                </div>
                <Select v-model="perPage" aria-label="Rows per page" :options="[10, 25, 50]" size="small"
                    :overlay-class="selectOverlay" @change="pushQuery(true)" />
            </nav>
        </section>
        <Menu id="correction-menu" ref="correctionMenu" :model="correctionActions" aria-label="Report actions"
            class="correction-menu" popup @hide="correctionMenuRow = null">
            <template #itemicon="{ item }"><MapIcon :name="item.icon" /></template>
        </Menu>
    </section>
</template>

<style scoped>
.bdrrm-report-queue {
    --correction-control: 2.25rem;
}

.correction-summary[aria-current="page"] {
    border-color: var(--p-indigo-300);
    background: var(--p-indigo-50);
    color: var(--p-indigo-900);
    box-shadow: inset 0 2px var(--p-indigo-500);
}

.bdrrm-report-queue :deep(.cdrrmo-icon),
:global(.correction-menu .cdrrmo-icon) {
    width: 1rem;
    height: 1rem;
}

.bdrrm-report-queue .correction-empty-icon {
    width: 1.375rem;
    height: 1.375rem;
}

.correction-meta > span {
    display: inline-flex;
    min-width: 0;
    align-items: center;
    gap: 0.25rem;
}

.correction-meta :deep(.cdrrmo-icon),
.correction-dates .correction-date-chevron {
    width: 0.875rem;
    height: 0.875rem;
}

.correction-dates > summary {
    min-height: var(--correction-control);
}

.correction-dates > summary::-webkit-details-marker {
    display: none;
}

.correction-dates[open] .correction-date-chevron {
    transform: rotate(90deg);
}

.correction-feedback {
    background: var(--p-amber-50);
    color: var(--p-amber-900);
}

.bdrrm-report-queue :deep(:is(.p-button, .p-inputtext, .p-select)) {
    min-height: var(--correction-control);
    border-radius: var(--p-border-radius-sm);
    font-size: 0.8125rem;
}

.bdrrm-report-queue :deep(.p-button) {
    white-space: nowrap;
}

.bdrrm-report-queue :deep(.p-button-icon-only) {
    width: var(--correction-control);
    padding-inline: 0;
}

.bdrrm-report-queue :deep(:is(.p-inputtext, .p-select)) {
    box-shadow: none;
}

.bdrrm-report-queue :deep(:is(.p-inputtext:focus, .p-select.p-focus, .p-select:focus-within)) {
    border-color: var(--p-indigo-500);
    outline: none;
    box-shadow: none;
}

.bdrrm-report-queue :deep(.p-select-label:focus) {
    outline: none;
}

.bdrrm-report-queue :deep(.p-select-dropdown) {
    width: 2rem;
}

.bdrrm-submitted :deep(.p-select-fluid) {
    min-width: 0;
}

.bdrrm-submitted :deep(.p-tag[data-status="review"]) {
    background: var(--p-indigo-50);
    color: var(--p-indigo-900);
}

.bdrrm-report-queue :deep(.p-tag) {
    --p-tag-font-size: 0.75rem;
    --p-tag-padding: 0.125rem 0.5rem;
}

:global(.correction-menu) {
    --p-menu-shadow: 0 2px 8px rgb(15 23 42 / 8%);
    font-size: 0.8125rem;
}

:global(.correction-calendar.p-datepicker-panel) {
    --p-datepicker-date-width: 2rem;
    --p-datepicker-date-height: 2rem;
    --p-datepicker-panel-padding: 0.5rem;
    min-width: 0 !important;
    max-width: calc(100vw - 1rem);
    font-size: 0.8125rem;
}

.bdrrm-drafts .correction-summary {
    grid-template-columns: auto minmax(0, 1fr) auto;
    align-items: center;
    column-gap: 0.625rem;
}

:is(.bdrrm-drafts, .bdrrm-submitted) .correction-summary:focus-visible,
:is(.bdrrm-drafts, .bdrrm-submitted) .correction-dates > summary:focus-visible {
    outline: 2px solid var(--p-indigo-600);
    outline-offset: 2px;
}

:is(.bdrrm-drafts, .bdrrm-submitted) :deep(.p-button) {
    padding-block: 0.3125rem;
}

:is(.bdrrm-drafts, .bdrrm-submitted) :deep(:is(.p-inputtext, .p-select-label)) {
    padding-block: 0.3125rem;
    line-height: 1.25rem;
    box-shadow: none;
}

.bdrrm-report-queue :deep(.p-inputtext.queue-search-active) {
    padding-inline-end: var(--correction-control);
}

:is(.bdrrm-drafts, .bdrrm-submitted) :deep(.p-inputtext::-webkit-search-cancel-button) {
    display: none;
}

.queue-search-clear:focus-visible {
    outline-offset: -3px;
}

.correction-meta :deep(.cdrrmo-icon) {
    flex-shrink: 0;
}

:global(.queue-select-overlay.p-select-overlay) {
    --p-select-overlay-border-radius: var(--p-border-radius-sm);
    --p-select-overlay-shadow: 0 3px 12px rgb(15 23 42 / 8%);
    width: 0;
    font-size: 0.8125rem;
}

:global(.queue-select-overlay .p-select-option) {
    white-space: normal;
    overflow-wrap: anywhere;
}

@container (width < 35rem) {
    :is(.bdrrm-drafts, .bdrrm-submitted) > header {
        display: grid;
        grid-template-columns: minmax(0, 1fr) auto;
        gap: 0.25rem 0.75rem;
    }

    :is(.bdrrm-drafts, .bdrrm-submitted) > header > div {
        display: contents;
    }

    :is(.bdrrm-drafts, .bdrrm-submitted) > header h2 {
        align-self: center;
    }

    :is(.bdrrm-drafts, .bdrrm-submitted) > header p {
        grid-area: 2 / 1 / auto / -1;
        margin-block-start: 0;
    }
}

@container (21rem <= width < 28rem) {
    .bdrrm-drafts .correction-summary {
        grid-template-columns: auto minmax(0, 1fr);
    }

    .bdrrm-drafts .correction-summary > span:nth-child(2) {
        grid-area: 2 / 1 / auto / -1;
    }

    .bdrrm-drafts .correction-summary > strong {
        text-align: end;
    }
}

@container (min-width: 28rem) {
    .bdrrm-drafts .correction-summary {
        grid-template-columns: auto minmax(0, 1fr);
        grid-template-rows: auto auto;
        align-content: center;
    }

    .bdrrm-drafts .correction-summary > :first-child {
        grid-row: span 2;
    }

    .bdrrm-drafts .correction-summary > strong {
        grid-row: 1;
        grid-column: 2;
    }
}

@media (pointer: coarse) {
    .bdrrm-report-queue {
        --correction-control: 2.75rem;
    }

    .bdrrm-report-queue :deep(.p-inputtext) {
        font-size: 1rem;
    }

    :global(.queue-select-overlay .p-select-option) {
        min-height: 2.75rem;
    }
}

@media (forced-colors: active) {
    .bdrrm-report-queue :deep(:is(.p-inputtext:focus-visible, .p-select:focus-within)) {
        outline: 1px solid Highlight;
        outline-offset: 1px;
    }
}
</style>
