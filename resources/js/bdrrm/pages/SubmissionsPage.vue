<script setup>
import { computed, inject, nextTick, ref, watch } from 'vue';
import { RouterLink, useRoute, useRouter } from 'vue-router';
import Button from 'primevue/button';
import Checkbox from 'primevue/checkbox';
import DatePicker from 'primevue/datepicker';
import Dialog from 'primevue/dialog';
import IconField from 'primevue/iconfield';
import InputIcon from 'primevue/inputicon';
import InputText from 'primevue/inputtext';
import Menu from 'primevue/menu';
import Message from 'primevue/message';
import Paginator from 'primevue/paginator';
import Select from 'primevue/select';
import SelectButton from 'primevue/selectbutton';
import Tag from 'primevue/tag';
import { dateToIso, isoToDate } from '../../shared/ui/dates';
import MapIcon from '../../shared/components/MapIcon.vue';

const apiBase = inject('apiBase');
const roleCode = inject('roleCode', '');
const route = useRoute();
const router = useRouter();
const rows = ref([]);
const meta = ref({ current_page: 1, last_page: 1, per_page: 10, total: 0 });
const counts = ref({ total: 0, pending: 0, reviewed: 0, checked: 0, unassigned: 0 });
const search = ref('');
const status = ref('');
const queue = ref('unassigned');
const submittedFrom = ref('');
const submittedTo = ref('');
const page = ref(1);
const perPage = ref(10);
const phase = ref('loading');
const error = ref('');
const rowMenu = ref(null);
const menuRow = ref(null);
const selectedIds = ref([]);
const assignOpen = ref(false);
const assignees = ref([]);
const assigneeId = ref(null);
const assignRemarks = ref('');
const replacedReason = ref('');
const assignError = ref('');
const assigning = ref(false);
const loadingAssignees = ref(false);
let applying = false;

const canAssign = computed(() => ['barangay_secretary', 'barangay_captain'].includes(roleCode));
const isCaptain = computed(() => roleCode === 'barangay_captain');
const isSecretary = computed(() => roleCode === 'barangay_secretary');
const isPersonnel = computed(() => roleCode === 'bdrrm');
const officeQueue = computed(() => (isCaptain.value ? 'checked' : 'reviewed'));
const officeQueueLabel = computed(() => (isCaptain.value ? 'Checked Reports' : 'Reviewed Reports'));
const selectedRows = computed(() => rows.value.filter((row) => selectedIds.value.includes(row.id)));
const needsReason = computed(() => selectedRows.value.some((row) => row.assignee_name));
const pageSelected = computed(() => rows.value.length > 0 && rows.value.every((row) => selectedIds.value.includes(row.id)));
const showStatus = computed(() => queue.value === 'pending' || queue.value === 'reviewed' || queue.value === 'checked' || (isPersonnel.value && ['pending', 'reviewed'].includes(queue.value)));
const statusChoices = computed(() => {
    if (queue.value === 'checked' || (queue.value === 'reviewed' && isCaptain.value)) {
        return [
            { value: '', label: 'All Checked Reports' },
            { value: 'secretary_checked', label: 'Secretary Checked' },
            { value: 'awaiting_endorsement', label: 'Awaiting Captain Endorsement' },
        ];
    }
    if (queue.value === 'reviewed') {
        return [
            { value: '', label: 'All Reviewed Reports' },
            { value: 'next_office', label: 'Next Office' },
        ];
    }
    if (queue.value === 'pending' || isPersonnel.value) {
        return [
            { value: '', label: 'All Pending Reviews' },
            { value: 'not_opened', label: 'Not Opened' },
            { value: 'reviewing', label: 'Reviewing' },
        ];
    }
    return [];
});
const filtersActive = computed(() => search.value.trim() !== '' || status.value !== '' || submittedFrom.value !== '' || submittedTo.value !== '');
const rangeStart = computed(() => (meta.value.total === 0 ? 0 : ((meta.value.current_page - 1) * meta.value.per_page) + 1));
const rangeEnd = computed(() => Math.min(meta.value.current_page * meta.value.per_page, meta.value.total));
const countLabel = computed(() => `${meta.value.total} ${meta.value.total === 1 ? 'report' : 'reports'}`);
const selectedLabel = computed(() => `${selectedIds.value.length} ${selectedIds.value.length === 1 ? 'report' : 'reports'} selected`);

const summaryCards = computed(() => {
    if (isPersonnel.value) {
        return [
            { key: 'pending', icon: 'clock-3', title: 'My Pending Reviews', value: counts.value.pending, note: 'Assigned and still open', tone: 'pending' },
            { key: 'reviewed', icon: 'clipboard-check', title: 'My Reviewed Reports', value: counts.value.reviewed, note: 'Reviews you completed', tone: 'reviewed' },
        ];
    }
    if (!canAssign.value) return [];
    return [
        { key: 'unassigned', icon: 'user-round-x', title: 'Unassigned Reports', value: counts.value.unassigned, note: 'No active reviewer', tone: 'unassigned' },
        { key: 'pending', icon: 'clock-3', title: 'Pending Review', value: counts.value.pending, note: 'Assigned and unfinished', tone: 'pending' },
        {
            key: officeQueue.value,
            icon: 'clipboard-check',
            title: officeQueueLabel.value,
            value: isCaptain.value ? (counts.value.checked || counts.value.reviewed) : counts.value.reviewed,
            note: isCaptain.value ? 'Signed by the secretary' : 'Awaiting secretary check',
            tone: 'reviewed',
        },
        { key: 'total', icon: 'files', title: 'Total Submissions', value: counts.value.total, note: 'In your barangay', tone: 'total' },
    ];
});

function formatWhen(value) {
    if (!value) return '—';
    const date = new Date(String(value).includes('T') ? value : `${String(value).replace(' ', 'T')}Z`);
    if (Number.isNaN(date.getTime())) return value;
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
    search.value = typeof route.query.q === 'string' ? route.query.q : '';
    submittedFrom.value = typeof route.query.submitted_from === 'string' ? route.query.submitted_from : '';
    submittedTo.value = typeof route.query.submitted_to === 'string' ? route.query.submitted_to : '';
    const requested = typeof route.query.status === 'string' ? route.query.status : '';
    if (canAssign.value) {
        const allowed = ['pending', 'reviewed', 'unassigned', 'checked'];
        let nextQueue = allowed.includes(route.query.queue) ? route.query.queue : 'unassigned';
        if (isCaptain.value && nextQueue === 'reviewed') nextQueue = 'checked';
        if (isSecretary.value && nextQueue === 'checked') nextQueue = 'reviewed';
        queue.value = nextQueue;
        if (queue.value === 'pending' && ['not_opened', 'reviewing', 'submitted', 'under_review'].includes(requested)) {
            status.value = requested === 'submitted' ? 'not_opened' : (requested === 'under_review' ? 'reviewing' : requested);
        } else if (queue.value === 'reviewed' && ['next_office'].includes(requested)) {
            status.value = requested;
        } else if (queue.value === 'checked' && ['secretary_checked', 'awaiting_endorsement'].includes(requested)) {
            status.value = requested;
        } else {
            status.value = '';
        }
    } else if (requested === 'reviewed' || route.query.queue === 'reviewed') {
        queue.value = 'reviewed';
        status.value = ['next_office', 'secretary_checked'].includes(requested) ? requested : '';
    } else {
        queue.value = 'pending';
        status.value = ['not_opened', 'reviewing', 'submitted', 'under_review'].includes(requested)
            ? (requested === 'submitted' ? 'not_opened' : (requested === 'under_review' ? 'reviewing' : requested))
            : '';
    }
    const nextPage = Number(route.query.page);
    page.value = Number.isInteger(nextPage) && nextPage > 0 ? nextPage : 1;
    const nextPer = Number(route.query.per_page);
    perPage.value = [10, 25, 50].includes(nextPer) ? nextPer : 10;
}

function queryFromState() {
    const query = {};
    if (search.value.trim()) query.q = search.value.trim();
    if (canAssign.value) query.queue = queue.value;
    else if (queue.value === 'reviewed') query.status = 'reviewed';
    if (showStatus.value && status.value) query.status = status.value;
    if (submittedFrom.value) query.submitted_from = submittedFrom.value;
    if (submittedTo.value) query.submitted_to = submittedTo.value;
    if (page.value > 1) query.page = String(page.value);
    if (perPage.value !== 10) query.per_page = String(perPage.value);
    return query;
}

async function load() {
    phase.value = rows.value.length ? 'refreshing' : 'loading';
    error.value = '';
    try {
        const params = { page: page.value, per_page: perPage.value };
        if (canAssign.value) params.queue = queue.value;
        if (queue.value === 'reviewed' || queue.value === 'checked') {
            params.status = status.value || (queue.value === 'checked' ? 'secretary_checked' : 'reviewed');
        } else if (status.value) {
            params.status = status.value;
        } else if (!canAssign.value) {
            params.status = 'pending';
        }
        if (search.value.trim()) params.search = search.value.trim();
        if (submittedFrom.value) params.submitted_from = submittedFrom.value;
        if (submittedTo.value) params.submitted_to = submittedTo.value;
        const { data } = await window.axios.get(`${apiBase}/submissions`, { params });
        rows.value = data.data || [];
        meta.value = data.meta || meta.value;
        counts.value = { ...counts.value, ...(data.counts || {}) };
        selectedIds.value = selectedIds.value.filter((id) => rows.value.some((row) => row.id === id));
        phase.value = 'ready';
    } catch {
        rows.value = [];
        phase.value = 'error';
        error.value = 'The submission list could not be loaded.';
    }
}

async function pushQuery(resetPage) {
    if (resetPage) page.value = 1;
    rowMenu.value?.hide();
    const query = queryFromState();
    const target = router.resolve({ path: '/submissions', query }).fullPath;
    if (route.fullPath === target) {
        await load();
        return;
    }
    applying = true;
    await router.replace({ path: '/submissions', query });
    applying = false;
    readRoute();
    await load();
}

function clearSearch() {
    search.value = '';
    document.getElementById('submission-search')?.focus();
    pushQuery(true);
}

function clearFilters() {
    search.value = '';
    status.value = '';
    submittedFrom.value = '';
    submittedTo.value = '';
    pushQuery(true);
}

function detailQuery(extra = {}) {
    return { ...route.query, ...extra };
}

async function toggleMenu(event, row) {
    const target = event.currentTarget;
    if (menuRow.value) {
        const sameRow = menuRow.value.id === row.id;
        rowMenu.value.hide();
        if (sameRow) return;
        await nextTick();
    }
    menuRow.value = row;
    rowMenu.value.show(event, target);
}

watch(() => route.fullPath, async () => {
    if (route.path !== '/submissions' || applying) return;
    readRoute();
    await load();
}, { immediate: true });

function toggleRow(id) {
    selectedIds.value = selectedIds.value.includes(id)
        ? selectedIds.value.filter((item) => item !== id)
        : selectedIds.value.concat(id);
}

function togglePage() {
    selectedIds.value = pageSelected.value ? [] : rows.value.map((row) => row.id);
}

function setQueue(next) {
    if (next === 'total' || queue.value === next) return;
    let resolved = next;
    if (isCaptain.value && resolved === 'reviewed') resolved = 'checked';
    if (isSecretary.value && resolved === 'checked') resolved = 'reviewed';
    if (queue.value === resolved) return;
    queue.value = resolved;
    status.value = '';
    selectedIds.value = [];
    pushQuery(true);
}

function badgeSeverity(row) {
    if (row.status === 'returned' || row.review_state === 'unassigned') return 'warn';
    if (row.review_state === 'next_office' || row.review_state === 'secretary_checked' || row.status === 'reviewed') return 'success';
    return 'info';
}

function badgeLabel(row) {
    return row.assignment_label || row.label || 'Unassigned';
}

function reviewerLine(row) {
    if (!row.assignee_name) return 'No reviewer assigned';
    const prefix = row.status === 'reviewed' || row.review_state === 'next_office' || row.review_state === 'secretary_checked'
        ? 'Reviewed by'
        : 'Assigned to';
    const when = row.assigned_at ? ` · ${formatWhen(row.assigned_at)}` : '';
    return `${prefix} ${row.assignee_name}${when}`;
}

function checkerLine(row) {
    if (!row.secretary_name) return '';
    const when = row.secretary_checked_at ? ` · ${formatWhen(row.secretary_checked_at)}` : '';
    return `Checked by ${row.secretary_name}${when}`;
}

function primaryLabel() {
    if (queue.value === 'checked') return 'Endorse Report';
    if (queue.value === 'reviewed') return 'Check Report';
    return 'View Report';
}

function primaryIcon() {
    if (queue.value === 'checked') return 'file-check-2';
    if (queue.value === 'reviewed') return 'clipboard-check';
    return 'eye';
}

function emptyCopy() {
    if (filtersActive.value) return 'Clear filters or try a different search.';
    if (isPersonnel.value) return queue.value === 'reviewed' ? 'Reports you finish reviewing will appear here.' : 'New assignments will appear here.';
    if (queue.value === 'checked') return 'No secretary-checked reports are awaiting endorsement.';
    if (queue.value === 'reviewed') return 'No reviewed resident reports are awaiting Secretary checking.';
    if (queue.value === 'unassigned') return 'New reports appear here until a reviewer is assigned.';
    return 'No resident submissions are awaiting BDRRM review.';
}

function showAssignAction(row) {
    return canAssign.value && (queue.value === 'unassigned' || row.review_state === 'unassigned');
}

function showReassignAction(row) {
    return canAssign.value && (queue.value === 'pending' || ['not_opened', 'reviewing'].includes(row.review_state));
}

async function openAssign(row) {
    if (row) selectedIds.value = [row.id];
    if (!selectedIds.value.length) return;
    assignError.value = '';
    assigneeId.value = null;
    assignRemarks.value = '';
    replacedReason.value = '';
    loadingAssignees.value = true;
    assignOpen.value = true;
    try {
        const barangayId = selectedRows.value[0]?.barangay_id;
        const response = await window.axios.get(`${apiBase}/personnel`, { params: { barangay_id: barangayId, role: 'bdrrm' } });
        assignees.value = response.data.data || [];
    } catch {
        assignees.value = [];
        assignError.value = 'BDRRM personnel could not be loaded.';
    } finally {
        loadingAssignees.value = false;
    }
}

async function saveAssign() {
    if (assigning.value || loadingAssignees.value || !assigneeId.value || (needsReason.value && !replacedReason.value)) return;
    assignError.value = '';
    assigning.value = true;
    try {
        await window.axios.post(`${apiBase}/submissions/assign`, {
            submission_ids: selectedIds.value,
            assignee_user_id: Number(assigneeId.value),
            remarks: assignRemarks.value,
            replaced_reason: needsReason.value ? replacedReason.value : null,
        });
        assignOpen.value = false;
        selectedIds.value = [];
        await load();
    } catch (exception) {
        assignError.value = exception.response?.data?.message || 'Assignment could not be saved.';
    } finally {
        assigning.value = false;
    }
}

const queueOptions = computed(() => summaryCards.value
    .filter((card) => card.key !== 'total')
    .map((card) => ({ ...card, label: card.key === 'unassigned' ? 'Unassigned' : card.title })));
const assigneeOptions = computed(() => assignees.value.map((person) => ({
    id: person.id,
    label: `${person.name} · ${person.pending_count} pending · ${person.reviewed_count} reviewed`,
})));
const menuItems = computed(() => {
    const row = menuRow.value;
    if (!row) return [];
    return [
        { label: row.has_point ? 'View reported location' : 'No reported map point', icon: 'pin', disabled: !row.has_point,
            to: row.has_point ? { path: '/map', query: { lng: String(row.longitude), lat: String(row.latitude) } } : null },
        { label: 'Flag an issue', icon: 'flag', to: { path: `/submissions/${row.id}`, query: detailQuery({ panel: 'flag' }) } },
        { label: 'Return for correction', icon: 'undo', visible: row.reviewable,
            to: { path: `/submissions/${row.id}`, query: detailQuery({ panel: 'return' }) } },
    ];
});
const overlay = { transition: { css: false } };
const selectPt = { ...overlay, overlay: { class: 'submission-select-overlay' } };
const calendarButtons = { size: 'small', severity: 'secondary', text: true };
const pageSizes = [10, 25, 50];
</script>

<template>
    <section class="bdrrm-submissions grid min-w-0 gap-4">
        <header class="flex flex-wrap items-end justify-between gap-x-5 gap-y-3">
            <div class="min-w-0">
                <div class="flex flex-wrap items-center gap-x-3 gap-y-1">
                    <h2 class="text-2xl leading-8 font-bold">{{ isPersonnel ? 'My Assigned Resident Submissions' : 'Resident Submissions' }}</h2>
                    <Tag v-if="phase === 'ready' || phase === 'refreshing'" :value="countLabel" severity="secondary" />
                </div>
                <p class="mt-0.5 text-surface-600">{{ isPersonnel ? 'Review and track resident incident reports assigned to you.' : 'Assign, review, and track disaster incident reports submitted by residents.' }}</p>
            </div>
            <div class="submission-heading-actions flex flex-wrap items-center gap-2">
                <Button label="Refresh" size="small" severity="secondary" outlined :loading="phase === 'refreshing'" :disabled="phase === 'loading'" @click="load">
                    <template #icon><MapIcon name="refresh" /></template>
                </Button>
                <Button v-if="canAssign" label="Assign BDRRM Personnel" size="small" :disabled="!selectedIds.length"
                    :title="selectedIds.length ? 'Assign the selected reports' : 'Select reports to assign'" @click="openAssign()">
                    <template #icon><MapIcon name="user-round-plus" /></template>
                </Button>
            </div>
        </header>

        <div v-if="summaryCards.length" class="submission-summaries grid grid-cols-2 gap-2.5" :class="{ 'is-pair': summaryCards.length === 2 }">
            <component v-for="card in summaryCards" :key="card.key" :is="card.key === 'total' ? 'div' : 'button'"
                :type="card.key === 'total' ? undefined : 'button'" class="submission-summary"
                :data-tone="card.tone" :aria-pressed="card.key === 'total' ? undefined : queue === card.key" @click="setQueue(card.key)">
                <span class="submission-summary-label font-medium">{{ card.title }}</span>
                <span class="submission-summary-icon"><MapIcon :name="card.icon" /></span>
                <strong class="text-2xl leading-7 tabular-nums">{{ card.value }}</strong>
                <span class="submission-summary-note text-xs text-surface-600">{{ card.note }}</span>
            </component>
        </div>

        <div class="submission-controls">
            <div class="border-b border-surface-200 px-3 pt-2.5 sm:px-4">
                <span id="submission-queues-label" class="sr-only">{{ isPersonnel ? 'My review queues' : 'Submission queues' }}</span>
                <SelectButton class="submission-queues" :model-value="queue" :options="queueOptions" option-label="label"
                    option-value="key" :allow-empty="false" aria-labelledby="submission-queues-label" size="small" @update:model-value="setQueue">
                    <template #option="{ option }">
                        <span>{{ option.label }}</span>
                        <Tag :value="String(option.value)" severity="secondary" />
                    </template>
                </SelectButton>
            </div>
            <form class="submission-filter-bar flex flex-wrap items-end gap-x-3 gap-y-2.5 px-3 py-3 sm:px-4" novalidate @submit.prevent="pushQuery(true)">
                <div class="submission-search grid min-w-0 gap-1">
                    <label for="submission-search">Search reports</label>
                    <IconField>
                        <InputIcon><MapIcon name="search" /></InputIcon>
                        <InputText id="submission-search" v-model="search" size="small" class="w-full" type="search" maxlength="80"
                            autocomplete="off" placeholder="Reference, resident, or incident" :class="{ 'has-query': search }" />
                        <Button v-if="search" class="absolute inset-y-0 end-0" type="button" aria-label="Clear search"
                            size="small" severity="secondary" text @click="clearSearch">
                            <template #icon><MapIcon name="x" /></template>
                        </Button>
                    </IconField>
                </div>
                <div v-if="showStatus && statusChoices.length" class="submission-status-filter grid min-w-0 gap-1">
                    <label id="submission-status-filter-label" for="submission-status-filter">Status</label>
                    <Select v-model="status" input-id="submission-status-filter" aria-labelledby="submission-status-filter-label" size="small" :options="statusChoices"
                        option-label="label" option-value="value" :pt="selectPt" @change="pushQuery(true)" />
                </div>
                <fieldset class="submission-dates min-w-0">
                    <legend class="mb-1">Submission date</legend>
                    <div class="grid grid-cols-2 gap-2">
                        <DatePicker input-id="submission-date-from" :model-value="isoToDate(submittedFrom)" size="small" fluid
                            date-format="M d, yy" placeholder="From date" aria-label="Submitted from"
                            show-icon icon-display="input" :manual-input="false" show-button-bar :today-button-props="calendarButtons"
                            :clear-button-props="calendarButtons" :navigator-button-props="calendarButtons" panel-class="submission-calendar" :pt="overlay"
                            @update:model-value="submittedFrom = dateToIso($event); pushQuery(true)" />
                        <DatePicker input-id="submission-date-to" :model-value="isoToDate(submittedTo)" size="small" fluid
                            date-format="M d, yy" placeholder="To date" aria-label="Submitted to"
                            show-icon icon-display="input" :manual-input="false" show-button-bar :today-button-props="calendarButtons"
                            :clear-button-props="calendarButtons" :navigator-button-props="calendarButtons" panel-class="submission-calendar" :pt="overlay"
                            @update:model-value="submittedTo = dateToIso($event); pushQuery(true)" />
                    </div>
                </fieldset>
                <div class="flex flex-wrap items-center gap-2">
                    <Button type="submit" size="small" label="Search" />
                    <Button v-if="filtersActive" type="button" size="small" label="Clear filters" severity="secondary" text @click="clearFilters" />
                </div>
            </form>
        </div>

        <div v-if="canAssign && rows.length" class="submission-toolbar flex flex-wrap items-center gap-x-3 gap-y-1.5">
            <label class="inline-flex cursor-pointer items-center gap-2 font-medium">
                <Checkbox :model-value="pageSelected" binary size="small" :indeterminate="!!selectedIds.length && !pageSelected"
                    @update:model-value="togglePage" />
                Select page
            </label>
            <span v-if="selectedIds.length" class="font-semibold" role="status">{{ selectedLabel }}</span>
            <template v-if="selectedIds.length">
                <Button size="small" label="Assign Personnel" @click="openAssign()">
                    <template #icon><MapIcon name="user-round-plus" /></template>
                </Button>
                <Button size="small" label="Clear selection" severity="secondary" text @click="selectedIds = []" />
            </template>
        </div>

        <div v-if="phase === 'loading'" class="submission-state" role="status">
            <MapIcon name="files" />
            <p class="font-medium">Loading submissions…</p>
        </div>
        <div v-else-if="phase === 'error'" class="submission-state" role="alert">
            <MapIcon name="alert-circle" />
            <p class="font-medium">{{ error }}</p>
            <Button size="small" label="Retry" outlined severity="secondary" @click="load" />
        </div>
        <div v-else-if="rows.length === 0" class="submission-state" role="status">
            <MapIcon name="clipboard-list" />
            <h3 class="text-base font-semibold">{{ filtersActive ? 'No matching submissions' : 'No submissions in this queue' }}</h3>
            <p class="text-surface-600">{{ emptyCopy() }}</p>
            <Button v-if="filtersActive" size="small" label="Clear filters" outlined severity="secondary" @click="clearFilters" />
        </div>
        <ul v-else class="grid gap-2.5" :aria-busy="phase === 'refreshing'" aria-label="Resident submissions">
            <li v-for="row in rows" :key="row.id">
                <article class="submission-card" :class="{ 'is-selected': selectedIds.includes(row.id) }">
                    <Checkbox v-if="canAssign" class="submission-row-check" :model-value="selectedIds.includes(row.id)"
                        binary size="small" :aria-label="`Select ${row.reference}`" @update:model-value="toggleRow(row.id)" />
                    <div class="submission-card-copy grid min-w-0 gap-1">
                        <div class="flex flex-wrap items-center gap-x-2.5 gap-y-1">
                            <h3 class="font-semibold text-deep tabular-nums">{{ row.reference }}</h3>
                            <Tag :value="badgeLabel(row)" :severity="badgeSeverity(row)" />
                            <Tag v-if="row.open_flags > 0" value="Open flag" severity="danger">
                                <MapIcon name="flag" /> Open flag
                            </Tag>
                        </div>
                        <p class="text-sm">
                            <strong class="font-semibold">{{ row.resident_name || 'Name unavailable' }}</strong>
                            <span class="text-surface-600"> · {{ row.incident_name || 'Incident' }}</span>
                        </p>
                        <div class="submission-meta flex flex-wrap gap-x-4 gap-y-1 text-xs text-surface-600">
                            <span><MapIcon name="pin" /> <span>{{ row.barangay_name || 'Assigned barangay' }}<template v-if="row.reported_location"> · {{ row.reported_location }}</template></span></span>
                            <span><MapIcon name="calendar-days" /> <span>Submitted {{ formatWhen(row.submitted_at) }}</span></span>
                        </div>
                        <div v-if="row.review_state !== 'unassigned' || checkerLine(row)" class="submission-meta flex flex-wrap gap-x-4 gap-y-1 text-xs text-surface-600">
                            <span v-if="row.review_state !== 'unassigned'"><MapIcon name="user-round" /> <span>{{ reviewerLine(row) }}</span></span>
                            <span v-if="checkerLine(row)"><MapIcon name="pen-line" /> <span>{{ checkerLine(row) }}</span></span>
                        </div>
                    </div>
                    <div class="submission-card-actions flex min-w-0 flex-wrap items-center gap-1.5">
                        <Button v-if="showAssignAction(row)" label="Assign Personnel" size="small" @click="openAssign(row)">
                            <template #icon><MapIcon name="user-round-plus" /></template>
                        </Button>
                        <Button v-if="showReassignAction(row)" label="Reassign" size="small" severity="secondary" outlined @click="openAssign(row)">
                            <template #icon><MapIcon name="user-round-pen" /></template>
                        </Button>
                        <Button :as="RouterLink" :to="{ path: `/submissions/${row.id}`, query: detailQuery() }" :label="primaryLabel()"
                            size="small" :outlined="showAssignAction(row)" :severity="showAssignAction(row) ? 'secondary' : undefined">
                            <template #icon><MapIcon :name="primaryIcon()" /></template>
                        </Button>
                        <Button aria-label="More actions" v-tooltip.top="'More actions'" size="small" severity="secondary" text
                            aria-haspopup="menu" :aria-expanded="menuRow?.id === row.id" aria-controls="submission-row-menu"
                            @click="toggleMenu($event, row)">
                            <template #icon><MapIcon name="more" /></template>
                        </Button>
                    </div>
                </article>
            </li>
        </ul>

        <footer v-if="phase !== 'loading' && phase !== 'error' && meta.total > 0" class="submission-pager flex flex-wrap items-center justify-between gap-x-4 gap-y-2">
            <p class="text-surface-600 tabular-nums" role="status">Showing {{ rangeStart }}–{{ rangeEnd }} of {{ meta.total }} reports</p>
            <div class="flex flex-wrap items-center gap-x-3 gap-y-2">
                <Paginator :first="(meta.current_page - 1) * meta.per_page" :rows="meta.per_page" :total-records="meta.total"
                    template="PrevPageLink CurrentPageReport NextPageLink" current-page-report-template="Page {currentPage} of {totalPages}"
                    @page="page = $event.page + 1; pushQuery(false)" />
                <div class="flex items-center gap-2">
                    <label id="submission-page-size-label" for="submission-page-size">Rows per page</label>
                    <Select v-model="perPage" input-id="submission-page-size" aria-labelledby="submission-page-size-label" size="small" :options="pageSizes" :pt="selectPt" @change="pushQuery(true)" />
                </div>
            </div>
        </footer>

        <Menu id="submission-row-menu" ref="rowMenu" :model="menuItems" class="submission-action-menu" :pt="overlay"
            aria-label="Report actions" popup @hide="menuRow = null">
            <template #item="{ item, props }">
                <RouterLink v-if="item.to" v-bind="props.action" :to="item.to"><MapIcon :name="item.icon" /> {{ item.label }}</RouterLink>
                <span v-else v-bind="props.action"><MapIcon :name="item.icon" /> {{ item.label }}</span>
            </template>
        </Menu>

        <Dialog v-if="canAssign" v-model:visible="assignOpen" modal :dismissable-mask="!assigning" :closable="!assigning" :close-on-escape="!assigning"
            header="Assign BDRRM Personnel" class="submission-assignment" :close-button-props="{ size: 'small', severity: 'secondary', text: true }" :pt="overlay">
            <template #default>
                <form id="submission-assignment-form" class="grid gap-3" novalidate @submit.prevent="saveAssign">
                    <div class="rounded-md border border-surface-200 bg-surface-50 px-3 py-2">
                        <p class="font-semibold">{{ selectedLabel }}</p>
                        <ul class="mt-1 max-h-28 space-y-1 overflow-auto text-xs text-surface-600">
                            <li v-for="row in selectedRows" :key="row.id">{{ row.reference }} · {{ row.resident_name || 'Resident' }}</li>
                        </ul>
                    </div>
                    <div class="grid min-w-0 gap-1">
                        <label id="submission-assignee-label" for="submission-assignee">BDRRM personnel</label>
                        <Select v-model="assigneeId" input-id="submission-assignee" aria-labelledby="submission-assignee-label" size="small" :options="assigneeOptions" option-label="label"
                            option-value="id" placeholder="Select one officer" :loading="loadingAssignees" :disabled="loadingAssignees || assigning"
                            :pt="selectPt" :aria-required="true" />
                    </div>
                    <div class="grid min-w-0 gap-1">
                        <label for="submission-assignment-remarks">Remarks</label>
                        <InputText id="submission-assignment-remarks" v-model="assignRemarks" size="small" maxlength="500" placeholder="Optional" :disabled="assigning" />
                    </div>
                    <div v-if="needsReason" class="grid min-w-0 gap-1">
                        <label for="submission-assignment-reason">Reason for replacing the current reviewer</label>
                        <InputText id="submission-assignment-reason" v-model="replacedReason" size="small" maxlength="500" required :disabled="assigning" />
                    </div>
                    <Message v-if="assignError" severity="error" size="small" :closable="false">{{ assignError }}</Message>
                </form>
            </template>
            <template #footer>
                <Button size="small" label="Cancel" severity="secondary" outlined :disabled="assigning" @click="assignOpen = false" />
                <Button size="small" label="Confirm Assignment" type="submit" form="submission-assignment-form" :loading="assigning"
                    :disabled="!assigneeId || loadingAssignees || (needsReason && !replacedReason)" />
            </template>
        </Dialog>
    </section>
</template>
