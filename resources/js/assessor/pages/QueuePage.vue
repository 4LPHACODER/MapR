<script setup>
import { computed, inject, nextTick, onBeforeUnmount, onMounted, reactive, ref, watch } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import Button from 'primevue/button';
import Checkbox from 'primevue/checkbox';
import Column from 'primevue/column';
import DataTable from 'primevue/datatable';
import Dialog from 'primevue/dialog';
import IconField from 'primevue/iconfield';
import InputIcon from 'primevue/inputicon';
import InputText from 'primevue/inputtext';
import Menu from 'primevue/menu';
import Message from 'primevue/message';
import Paginator from 'primevue/paginator';
import Select from 'primevue/select';
import SelectButton from 'primevue/selectbutton';
import Skeleton from 'primevue/skeleton';
import Tag from 'primevue/tag';
import Textarea from 'primevue/textarea';
import MapIcon from '../../shared/components/MapIcon.vue';

const apiBase = inject('apiBase');
const roleCode = inject('roleCode', '');
const route = useRoute();
const router = useRouter();

const pageSize = 10;
const supervisor = ['rdana_admin_officer', 'rdana_city_assessor'].includes(roleCode);
const emptyMeta = { current_page: 1, last_page: 1, per_page: pageSize, total: 0 };
const defaultOrder = 'updated_at:desc';
const orders = [
    { label: 'Latest update', value: defaultOrder },
    { label: 'Oldest update', value: 'updated_at:asc' },
    { label: 'Latest submission', value: 'submitted_at:desc' },
    { label: 'Oldest submission', value: 'submitted_at:asc' },
    { label: 'Reference (A–Z)', value: 'report_reference:asc' },
    { label: 'Reference (Z–A)', value: 'report_reference:desc' },
];
const progressOptions = [
    { label: 'Any progress', value: '' },
    { label: 'Not opened', value: 'not_opened' },
];
const emptyLabels = {
    unassigned: 'No unassigned barangay reports',
    pending: 'No pending assessments',
    completed: 'No completed assessments awaiting verification',
    verified: 'No verified reports awaiting review',
};

const rows = ref([]);
const meta = ref(emptyMeta);
const counts = ref(null);
const assessors = ref({ total: 0, with_active: 0, without_active: 0 });
const assessorDirectory = ref([]);
const barangays = ref([]);
const error = ref('');
const loading = ref(true);
const actionError = ref('');
const canAssign = ref(supervisor);
const isAdminOfficer = computed(() => roleCode === 'rdana_admin_officer');
const isCityAssessor = computed(() => roleCode === 'rdana_city_assessor');
const queue = ref('');
const selectedIds = ref([]);
const assignOpen = ref(false);
const reassignMode = ref(false);
const manageOpen = ref(false);
const manageLoading = ref(false);
const exporting = ref(false);
const assignmentRows = ref([]);
const assignees = ref([]);
const selectedAssessorId = ref(null);
const assignRemarks = ref('');
const replacedReason = ref('');
const assignError = ref('');
const assigning = ref(false);
const loadingAssignees = ref(false);
const startingId = ref(null);
const rowMenu = ref(null);
const menuRow = ref(null);
const menuVisible = ref(false);
let searchTimer;
let composing = false;
let requestId = 0;
let personnelRequest = 0;

const isBarangay = computed(() => route.meta.source === 'barangay');
const canSelect = computed(() => canAssign.value && (queue.value === 'unassigned' || queue.value === 'pending'));
const filters = reactive({ search: '', barangay: '', progress: '', order: defaultOrder });
const filtersActive = computed(() => Boolean(filters.search.trim() || filters.barangay || filters.progress || filters.order !== defaultOrder));
const thirdQueue = computed(() => (isCityAssessor.value ? 'verified' : 'completed'));
const queueOptions = computed(() => [
    ...(canAssign.value ? [{ value: 'unassigned', label: 'Unassigned', shortLabel: 'Unassigned' }] : []),
    { value: 'pending', label: 'Pending Assessment', shortLabel: 'Pending' },
    isCityAssessor.value
        ? { value: 'verified', label: 'Verified Reports', shortLabel: 'Verified' }
        : { value: 'completed', label: 'Completed Assessments', shortLabel: 'Completed' },
]);
const summaryCards = computed(() => {
    if (!canAssign.value) return [];
    const thirdKey = thirdQueue.value;
    return [
        {
            key: 'unassigned',
            label: 'Unassigned Reports',
            value: counts.value?.unassigned ?? 0,
            hint: 'Reports awaiting assignment to an assessor.',
            icon: 'clipboard-list',
            action: 'View Unassigned',
        },
        {
            key: 'pending',
            label: 'Pending Assessment',
            value: counts.value?.pending ?? 0,
            hint: 'Assigned reports still in assessment.',
            icon: 'clock-3',
            action: 'View Pending',
        },
        {
            key: thirdKey,
            label: isCityAssessor.value ? 'Verified Reports' : 'Completed Assessments',
            value: counts.value?.[thirdKey] ?? 0,
            hint: isCityAssessor.value
                ? 'Verified assessments ready for review.'
                : 'Submitted assessments awaiting verification.',
            icon: 'clipboard-check',
            action: isCityAssessor.value ? 'View Verified' : 'View Completed',
        },
        {
            key: 'assessors',
            label: 'Assessors',
            value: assessors.value.total,
            hint: `Assigned: ${assessors.value.with_active} · Unassigned: ${assessors.value.without_active}`,
            icon: 'users-round',
            action: 'Manage Assessors',
            manage: true,
        },
    ];
});
const barangayOptions = computed(() => [
    { label: 'All assigned', value: '' },
    ...barangays.value.map(({ id, name }) => ({ label: name, value: String(id) })),
]);
const pageSelected = computed(() => rows.value.length > 0 && selectedIds.value.length === rows.value.length);
const first = computed(() => (meta.value.current_page - 1) * meta.value.per_page);
const eligibleAssignees = computed(() => {
    if (!reassignMode.value) return assignees.value;
    const blocked = new Set(assignmentRows.value.map((row) => Number(row.assignee_user_id)).filter((id) => id > 0));
    return assignees.value.filter((person) => !blocked.has(Number(person.id)));
});
const assignValid = computed(() => {
    if (selectedAssessorId.value === null || !assignmentRows.value.length) return false;
    if (reassignMode.value) return replacedReason.value.trim().length > 0;
    return true;
});
const assignActionLabel = computed(() => {
    const count = assignmentRows.value.length;
    if (reassignMode.value) {
        return count === 1 ? 'Confirm reassignment' : `Reassign ${count} reports`;
    }
    return `Assign ${count} ${count === 1 ? 'report' : 'reports'}`;
});
const rowActions = computed(() => {
    const row = menuRow.value;
    if (!row) return [];
    const items = [
        { label: 'View details', icon: 'eye', command: () => router.push(detailHref(row)) },
        { label: 'Download PDF', icon: 'download', url: `${previewHref(row)}?format=pdf` },
        { label: 'View location', icon: 'pin', command: () => openLocation(row) },
        { label: 'View signatures', icon: 'file-check-2', url: previewHref(row) },
    ];
    if (canAssign.value && queue.value === 'pending' && row.can_reassign) {
        items.unshift({ label: 'Reassign assessment', icon: 'user-round-pen', command: () => openReassign(row) });
    }
    return items;
});
const dateFormat = new Intl.DateTimeFormat('en-PH', {
    timeZone: 'Asia/Manila',
    day: 'numeric',
    month: 'short',
    year: 'numeric',
    hour: 'numeric',
    minute: '2-digit',
});
const costFormat = new Intl.NumberFormat('en-PH', { style: 'currency', currency: 'PHP' });

function formatWhen(value) {
    if (!value) return 'Not recorded';
    const date = new Date(String(value).includes('T') ? value : `${String(value).replace(' ', 'T')}Z`);
    return Number.isNaN(date.getTime()) ? String(value) : dateFormat.format(date);
}

function previewHref(row) {
    return isBarangay.value ? `/rdana/reports/barangay/${row.id}/preview` : `/rdana/assessments/${row.id}/preview`;
}

function detailHref(row, query = {}) {
    return { path: `/reports/barangay/${row.id}`, query };
}

function statusSeverity(row) {
    if (row.verification_label === 'Returned for Correction') return 'warn';
    if (row.assessment_workflow_stage === 'forwarded_cdrrmo' || row.assessment_workflow_stage === 'ready_for_consolidation') return 'success';
    if (row.assessment_progress === 'not_opened') return 'secondary';
    return 'info';
}

function routeQueue() {
    const requested = route.query.queue;
    if (requested === 'pending' || requested === 'completed' || requested === 'verified' || requested === 'unassigned') {
        if (requested === 'verified' && !isCityAssessor.value) return isAdminOfficer.value ? 'completed' : 'pending';
        if (requested === 'completed' && isCityAssessor.value) return 'verified';
        if (requested === 'unassigned' && !canAssign.value) return 'pending';
        return requested;
    }
    return canAssign.value ? 'unassigned' : 'pending';
}

function setQueue(next) {
    if (!next || next === queue.value) return;
    queue.value = next;
    filters.progress = '';
    load(1);
}

function onSummaryCard(card) {
    if (card.manage) {
        openManageAssessors();
        return;
    }
    setQueue(card.key);
}

async function clearFilters() {
    Object.assign(filters, { search: '', barangay: '', progress: '', order: defaultOrder });
    await nextTick();
    load(1);
}

function toggleRow(id) {
    selectedIds.value = selectedIds.value.includes(id)
        ? selectedIds.value.filter((item) => item !== id)
        : [...selectedIds.value, id];
}

function togglePage() {
    selectedIds.value = pageSelected.value ? [] : rows.value.map((row) => row.id);
}

function openRow(row) {
    router.push(`/assessments/${row.id}`);
}

function openLocation(row) {
    router.push({ path: '/map', query: { incident_id: row.disaster_incident_id || undefined } });
}

async function openMenu(event, row) {
    const sameRow = menuRow.value?.id === row.id;
    const trigger = event.currentTarget;
    menuRow.value = row;
    if (sameRow || !menuVisible.value) {
        rowMenu.value.toggle(event);
        return;
    }
    rowMenu.value.hide();
    await nextTick();
    rowMenu.value?.show(event, trigger);
}

async function openAssessment(row) {
    const assessmentId = row.draft_id || row.assessment_id;
    if (assessmentId) {
        router.push(`/assessments/${assessmentId}`);
        return;
    }
    if (startingId.value !== null) return;

    startingId.value = row.id;
    actionError.value = '';
    try {
        const { data } = await window.axios.post(`${apiBase}/barangay-reports/${row.id}/start`);
        const id = data.data?.id;
        if (id) router.push(`/assessments/${id}`);
    } catch (exception) {
        actionError.value = exception.response?.data?.message || 'This report cannot be assessed from this account.';
    } finally {
        startingId.value = null;
    }
}

async function openAssign(row = null) {
    await openAssignmentModal(row, false);
}

async function openReassign(row = null) {
    await openAssignmentModal(row, true);
}

async function openAssignmentModal(row = null, asReassign = false) {
    assignmentRows.value = row
        ? [row]
        : rows.value.filter((report) => selectedIds.value.includes(report.id));
    if (!assignmentRows.value.length) return;

    const barangayIds = [...new Set(assignmentRows.value.map((report) => report.barangay_id).filter(Boolean))];
    const current = ++personnelRequest;
    reassignMode.value = asReassign;
    assignError.value = '';
    assignRemarks.value = '';
    replacedReason.value = '';
    selectedAssessorId.value = null;
    assignees.value = [];
    assignOpen.value = true;
    loadingAssignees.value = true;

    if (asReassign && barangayIds.length > 1) {
        assignError.value = 'Select reports from one barangay.';
        loadingAssignees.value = false;
        return;
    }

    try {
        const responses = await Promise.all(barangayIds.map((barangay_id) => window.axios.get(`${apiBase}/personnel`, {
            params: { barangay_id, role: 'rdana' },
        })));
        if (current !== personnelRequest) return;

        const [firstBarangay = [], ...otherBarangays] = responses.map(({ data }) => data.data || []);
        assignees.value = firstBarangay.filter((person) =>
            otherBarangays.every((options) => options.some(({ id }) => id === person.id)),
        );
        if (!(asReassign ? eligibleAssignees.value : assignees.value).length) {
            assignError.value = 'No RDANA assessor is eligible for the selected reports.';
        }
    } catch (exception) {
        if (current === personnelRequest) {
            assignError.value = exception.response?.data?.message || 'Assessor personnel could not be loaded.';
        }
    } finally {
        if (current === personnelRequest) loadingAssignees.value = false;
    }
}

async function confirmAssign() {
    if (!assignValid.value || assigning.value) return;
    assignError.value = '';
    assigning.value = true;
    try {
        const payload = {
            assignee_user_id: Number(selectedAssessorId.value),
            report_ids: assignmentRows.value.map(({ id }) => Number(id)),
            remarks: assignRemarks.value.trim() || undefined,
        };
        if (reassignMode.value) {
            payload.replaced_reason = replacedReason.value.trim();
        }
        await window.axios.post(`${apiBase}/barangay-reports/assign`, payload);
        assignOpen.value = false;
        reassignMode.value = false;
        selectedIds.value = [];
        setQueue('pending');
        window.dispatchEvent(new Event('mapr-nav-refresh'));
    } catch (exception) {
        assignError.value = exception.response?.data?.message
            || (reassignMode.value ? 'Reassignment could not be saved.' : 'Assignment could not be saved.');
    } finally {
        assigning.value = false;
    }
}

async function openManageAssessors() {
    manageOpen.value = true;
    manageLoading.value = true;
    try {
        const { data } = await window.axios.get(`${apiBase}/personnel`, {
            params: { role: 'rdana', summary: 1 },
        });
        assessorDirectory.value = data.data || [];
        if (data.summary) {
            assessors.value = {
                total: data.summary.total ?? 0,
                with_active: data.summary.with_active ?? 0,
                without_active: data.summary.without_active ?? 0,
            };
        }
    } catch (exception) {
        actionError.value = exception.response?.data?.message || 'Assessor directory could not be loaded.';
        manageOpen.value = false;
    } finally {
        manageLoading.value = false;
    }
}

async function exportReports() {
    if (exporting.value || !canAssign.value) return;
    exporting.value = true;
    actionError.value = '';
    try {
        const [sort, direction] = filters.order.split(':');
        const response = await window.axios.get(`${apiBase}/barangay-reports/export`, {
            params: {
                queue: queue.value,
                search: filters.search.trim() || undefined,
                barangay_id: filters.barangay || undefined,
                assessment_status: queue.value === 'pending' ? filters.progress || undefined : undefined,
                sort,
                direction,
            },
            responseType: 'blob',
        });
        const url = URL.createObjectURL(response.data);
        const link = document.createElement('a');
        link.href = url;
        link.download = `barangay-reports-${queue.value}.csv`;
        link.click();
        URL.revokeObjectURL(url);
    } catch (exception) {
        actionError.value = exception.response?.data?.message || 'The report list could not be exported.';
    } finally {
        exporting.value = false;
    }
}

async function load(page = meta.value.current_page) {
    const current = ++requestId;
    clearTimeout(searchTimer);
    loading.value = true;
    error.value = '';
    actionError.value = '';
    selectedIds.value = [];
    const barangay = isBarangay.value;
    const [sort, direction] = filters.order.split(':');
    const path = barangay ? '/barangay-reports' : '/assessments';
    const params = barangay
        ? {
            queue: queue.value,
            search: filters.search.trim() || undefined,
            barangay_id: filters.barangay || undefined,
            assessment_status: queue.value === 'pending' ? filters.progress || undefined : undefined,
            sort,
            direction,
            page,
            per_page: pageSize,
        }
        : { status: route.meta.status || undefined, per_page: pageSize };

    try {
        const { data } = await window.axios.get(`${apiBase}${path}`, { params });
        if (current !== requestId) return;

        if (barangay && page > data.meta?.last_page) {
            return load(data.meta.last_page);
        }
        rows.value = data.data || [];
        meta.value = data.meta || emptyMeta;
        if (barangay) {
            counts.value = data.counts || null;
            assessors.value = data.assessors || { total: 0, with_active: 0, without_active: 0 };
            barangays.value = data.barangays || [];
            canAssign.value = data.can_assign ?? canAssign.value;
            queue.value = data.queue || queue.value;
        }
    } catch (exception) {
        if (current === requestId) {
        error.value = exception.response?.data?.message || 'The report list could not be loaded.';
        }
    } finally {
        if (current === requestId) loading.value = false;
    }
}

function scheduleSearch() {
    clearTimeout(searchTimer);
    if (!composing) searchTimer = setTimeout(() => load(1), 300);
}

function syncRoute() {
    if (isBarangay.value) {
        queue.value = routeQueue();
        if (queue.value !== 'pending') filters.progress = '';
    }
    load(1);
}

onMounted(syncRoute);
watch(() => route.fullPath, syncRoute);
watch(() => filters.search, scheduleSearch);
onBeforeUnmount(() => {
    clearTimeout(searchTimer);
    requestId++;
    personnelRequest++;
});
</script>

<template>
    <section v-if="isBarangay" class="rdana-report-queue" aria-label="Barangay report queues">
        <header class="rdana-queue-heading">
            <div>
                <h2>Barangay Reports</h2>
                <p>Manage barangay submissions, assessor assignments, assessment verification, and report review.</p>
            </div>
            <div class="rdana-queue-heading-actions">
                <Button type="button" size="small" severity="secondary" outlined label="Refresh" :disabled="loading" @click="load()">
                    <template #icon><MapIcon name="refresh" /></template>
                </Button>
                <Button
                    v-if="canAssign"
                    type="button"
                    size="small"
                    severity="secondary"
                    outlined
                    label="Export report list"
                    :loading="exporting"
                    @click="exportReports"
                >
                    <template #icon><MapIcon name="download" /></template>
                </Button>
                <Button
                    v-if="canAssign"
                    type="button"
                    size="small"
                    label="Manage assessors"
                    @click="openManageAssessors"
                >
                    <template #icon><MapIcon name="users-round" /></template>
                </Button>
            </div>
        </header>

        <div v-if="summaryCards.length" class="rdana-summary-cards">
            <button
                v-for="card in summaryCards"
                :key="card.key"
                type="button"
                class="rdana-summary-card"
                :class="{ 'is-active': !card.manage && queue === card.key }"
                :aria-pressed="!card.manage && queue === card.key"
                @click="onSummaryCard(card)"
            >
                <span class="rdana-summary-icon" aria-hidden="true"><MapIcon :name="card.icon" /></span>
                <span class="rdana-summary-copy">
                    <span class="rdana-summary-label">{{ card.label }}</span>
                    <strong>{{ card.value }}</strong>
                    <span class="rdana-summary-hint">{{ card.hint }}</span>
                    <span class="rdana-summary-action">{{ card.action }}</span>
                </span>
            </button>
        </div>

        <div class="rdana-report-panel">
            <div class="rdana-queue-nav">
                <SelectButton
                    :model-value="queue"
                    :options="queueOptions"
                    option-label="label"
                    option-value="value"
                    :allow-empty="false"
                    aria-label="Report queue"
                    size="small"
                    class="rdana-queue-options"
                    @update:model-value="setQueue"
                >
                    <template #option="{ option }">
                        <span class="rdana-queue-full-label">{{ option.label }}</span>
                        <span class="rdana-queue-short-label">{{ option.shortLabel || option.label }}</span>
                        <span v-if="counts" class="rdana-queue-count">{{ counts[option.value] }}</span>
                    </template>
                </SelectButton>
                <Button type="button" size="small" severity="secondary" text label="Refresh" aria-label="Refresh" class="rdana-queue-refresh" :disabled="loading" @click="load()">
                    <template #icon><MapIcon name="refresh" /></template>
                </Button>
            </div>

            <form class="rdana-queue-filters" role="search" aria-label="Filter barangay reports" @submit.prevent="load(1)">
                <div class="rdana-search-field">
                    <label for="rdana-report-search">Search</label>
                    <IconField>
                        <InputIcon><MapIcon name="search" /></InputIcon>
                        <InputText
                            id="rdana-report-search"
                            v-model="filters.search"
                            type="search"
                            size="small"
                            maxlength="80"
                            autocomplete="off"
                            placeholder="Reference, incident or barangay"
                            @compositionstart="composing = true"
                            @compositionend="composing = false"
                        />
                    </IconField>
                </div>
                <div>
                    <label id="rdana-report-barangay-label" for="rdana-report-barangay">Barangay</label>
                    <Select
                        input-id="rdana-report-barangay"
                        aria-labelledby="rdana-report-barangay-label"
                        v-model="filters.barangay"
                        :options="barangayOptions"
                        option-label="label"
                        option-value="value"
                        placeholder="All assigned"
                        overlay-class="rdana-queue-overlay"
                        size="small"
                        @change="load(1)"
                    />
                </div>
                <div v-if="queue === 'pending'">
                    <label id="rdana-report-progress-label" for="rdana-report-progress">Progress</label>
                    <Select
                        input-id="rdana-report-progress"
                        aria-labelledby="rdana-report-progress-label"
                        v-model="filters.progress"
                        :options="progressOptions"
                        option-label="label"
                        option-value="value"
                        placeholder="Any progress"
                        overlay-class="rdana-queue-overlay"
                        size="small"
                        @change="load(1)"
                    />
                </div>
                <div>
                    <label id="rdana-report-order-label" for="rdana-report-order">Sort by</label>
                    <Select
                        input-id="rdana-report-order"
                        aria-labelledby="rdana-report-order-label"
                        v-model="filters.order"
                        :options="orders"
                        option-label="label"
                        option-value="value"
                        overlay-class="rdana-queue-overlay"
                        size="small"
                        @change="load(1)"
                    />
                </div>
                <Button v-if="filtersActive" type="button" size="small" severity="secondary" text label="Clear filters" @click="clearFilters" />
            </form>

            <div v-if="canSelect && selectedIds.length" class="rdana-queue-selection">
                <strong aria-live="polite">{{ selectedIds.length }} selected</strong>
                <Button v-if="queue === 'unassigned'" type="button" size="small" label="Assign selected" @click="openAssign()">
                    <template #icon><MapIcon name="user-round-plus" /></template>
                </Button>
                <Button v-else-if="queue === 'pending'" type="button" size="small" label="Reassign selected" @click="openReassign()">
                    <template #icon><MapIcon name="user-round-pen" /></template>
                </Button>
                <Button type="button" size="small" severity="secondary" text label="Clear selection" @click="selectedIds = []" />
            </div>

            <Message v-if="actionError" severity="error" size="small" class="rdana-queue-message">{{ actionError }}</Message>
            <div v-if="loading" class="rdana-queue-loading" role="status" aria-label="Loading barangay reports">
                <div v-for="index in 4" :key="index">
                    <Skeleton width="36%" height="0.75rem" />
                    <Skeleton width="22%" height="0.75rem" />
                    <Skeleton width="16%" height="0.75rem" />
                </div>
            </div>
            <div v-else-if="error" class="rdana-queue-state" role="alert">
                <strong>{{ error }}</strong>
                <Button type="button" size="small" label="Retry" @click="load()" />
            </div>
            <div v-else-if="!rows.length" class="rdana-queue-state" role="status">
                <MapIcon name="file-text" />
                <strong>{{ filtersActive ? 'No reports match these filters' : emptyLabels[queue] }}</strong>
                <Button v-if="!filtersActive && queue === 'unassigned' && counts?.pending" type="button" size="small" severity="secondary" outlined label="View pending assessments" @click="setQueue('pending')" />
            </div>
            <DataTable
                v-else
                :value="rows"
                data-key="id"
                size="small"
                row-hover
                :row-class="(row) => ({ 'rdana-row-selected': selectedIds.includes(row.id) })"
                :pt="{ table: { 'aria-label': 'Barangay reports' } }"
                class="rdana-queue-table"
                :class="{ 'rdana-queue-selectable': canSelect }"
            >
                <Column v-if="canSelect" class="rdana-queue-checkbox">
                    <template #header>
                        <Checkbox
                            binary size="small"
                            input-id="rdana-select-page"
                            :model-value="pageSelected"
                            :indeterminate="selectedIds.length > 0 && !pageSelected"
                            aria-label="Select all reports on this page"
                            @update:model-value="togglePage"
                        />
                        <label for="rdana-select-page" class="rdana-mobile-label" aria-hidden="true">Select page</label>
                    </template>
                    <template #body="{ data: row }">
                        <Checkbox
                            binary size="small"
                            :model-value="selectedIds.includes(row.id)"
                            :aria-label="`Select ${row.reference}`"
                            @update:model-value="toggleRow(row.id)"
                        />
                    </template>
                </Column>
                <Column header="Report reference" class="rdana-queue-report">
                    <template #body="{ data: row }">
                        <RouterLink :to="detailHref(row)" class="rdana-reference">{{ row.reference }}</RouterLink>
                        <span class="rdana-queue-subtext">{{ row.incident_name || 'Incident not recorded' }}</span>
                    </template>
                </Column>
                <Column header="Barangay">
                    <template #body="{ data: row }">
                        {{ row.barangay_name || 'Not recorded' }}
                        <span v-if="row.reporting_resident || row.submitted_by" class="rdana-queue-subtext">{{ row.reporting_resident || row.submitted_by }}</span>
                    </template>
                </Column>
                <Column v-if="queue !== 'unassigned'" header="Assigned assessor">
                    <template #body="{ data: row }">
                        <span class="rdana-mobile-label" aria-hidden="true">Assessor</span>
                        {{ row.assignee_name || 'Unassigned' }}
                        <span v-if="row.assigned_at" class="rdana-queue-subtext">{{ formatWhen(row.assigned_at) }}</span>
                    </template>
                </Column>
                <Column header="Submitted" class="rdana-queue-date">
                    <template #body="{ data: row }">
                        <span class="rdana-mobile-label" aria-hidden="true">Submitted</span>
                        {{ formatWhen(row.submitted_at) }}
                    </template>
                </Column>
                <Column v-if="queue !== 'unassigned'" header="Assessment status" class="rdana-queue-status">
                    <template #body="{ data: row }">
                        <Tag :value="row.verification_label || row.label" :severity="statusSeverity(row)" />
                    </template>
                </Column>
                <Column header="Last updated" class="rdana-queue-date">
                    <template #body="{ data: row }">
                        <span class="rdana-mobile-label" aria-hidden="true">Updated</span>
                        {{ formatWhen(row.updated_at) }}
                    </template>
                </Column>
                <Column v-if="queue === 'completed' || queue === 'verified'" header="Damage">
                    <template #body="{ data: row }">
                        <span class="rdana-mobile-label" aria-hidden="true">Damage</span>
                        {{ row.building_status_label || '—' }}
                        <span v-if="row.estimated_cost_php != null" class="rdana-queue-subtext rdana-queue-amount">{{ costFormat.format(row.estimated_cost_php) }}</span>
                    </template>
                </Column>
                <Column v-if="queue === 'completed'" header="Verification" class="rdana-queue-verify-cell">
                    <template #body="{ data: row }">
                        <span class="rdana-mobile-label" aria-hidden="true">Verification</span>
                        <Button
                            v-if="row.can_verify_report"
                            as="RouterLink"
                            :to="detailHref(row, { from: 'completed', sign: 'verify-officer' })"
                            size="small"
                            outlined
                            label="Verify Report"
                        >
                            <template #icon><MapIcon name="shield-check" /></template>
                        </Button>
                        <span v-else class="rdana-queue-subtext">—</span>
                    </template>
                </Column>
                <Column class="rdana-queue-action-cell">
                    <template #header><span class="sr-only">Actions</span></template>
                    <template #body="{ data: row }">
                        <div class="rdana-queue-row-actions">
                            <Button v-if="queue === 'unassigned' && canAssign" type="button" size="small" outlined label="Assign assessor" @click="openAssign(row)" />
                            <Button v-else-if="queue === 'pending' && canAssign && row.can_reassign" type="button" size="small" outlined label="Reassign assessment" @click="openReassign(row)">
                                <template #icon><MapIcon name="user-round-pen" /></template>
                            </Button>
                            <Button v-else-if="queue === 'pending' && row.can_open_assessment" type="button" size="small" outlined label="Assess report" :loading="startingId === row.id" :disabled="startingId !== null" @click="openAssessment(row)" />
                            <Button v-else-if="row.can_review_report" as="RouterLink" :to="detailHref(row, { from: 'verified' })" size="small" outlined label="Review report" />
                            <Button v-else-if="queue === 'verified' || queue === 'completed'" as="RouterLink" :to="detailHref(row, { from: queue })" size="small" severity="secondary" outlined label="View details" />
                            <Button
                                type="button"
                                size="small"
                                severity="secondary"
                                text
                                class="rdana-queue-more"
                                :aria-label="`Actions for ${row.reference}`"
                                :aria-expanded="menuVisible && menuRow?.id === row.id"
                                aria-haspopup="menu"
                                aria-controls="rdana-report-actions"
                                @click="openMenu($event, row)"
                            >
                                <MapIcon name="more" />
                            </Button>
                        </div>
                    </template>
                </Column>
            </DataTable>

            <footer v-if="!error && rows.length" class="rdana-queue-footer">
                <span aria-live="polite">{{ first + 1 }}–{{ first + rows.length }} of {{ meta.total }} {{ meta.total === 1 ? 'report' : 'reports' }}</span>
                <Paginator
                    v-if="meta.last_page > 1"
                    :first="first"
                    :rows="meta.per_page"
                    :total-records="meta.total"
                    :page-link-size="3"
                    template="PrevPageLink PageLinks NextPageLink"
                    @page="load($event.page + 1)"
                />
            </footer>
        </div>

        <Menu id="rdana-report-actions" ref="rowMenu" :model="rowActions" popup class="rdana-queue-overlay" aria-label="Report actions" @show="menuVisible = true" @hide="menuVisible = false">
            <template #item="{ item, props }">
                <a v-if="item.url" v-bind="props.action" :href="item.url">
                    <MapIcon :name="item.icon" />
                    <span>{{ item.label }}</span>
                </a>
                <a v-else v-bind="props.action" href="#" @click.prevent="item.command?.()">
                    <MapIcon :name="item.icon" />
                    <span>{{ item.label }}</span>
                </a>
            </template>
        </Menu>

        <Dialog
            v-model:visible="assignOpen"
            :header="reassignMode ? 'Reassign assessment' : 'Assign RDANA assessor'"
            modal
            :draggable="false"
            :closable="!assigning"
            :close-on-escape="!assigning"
            :close-button-props="{ size: 'small', severity: 'secondary', text: true }"
            class="rdana-assign-dialog"
            @hide="reassignMode = false"
        >
            <form id="rdana-assign-form" class="rdana-assign-form" @submit.prevent="confirmAssign">
                <div v-if="reassignMode && assignmentRows.length === 1" class="rdana-assign-summary">
                    <dl>
                        <div><dt>Reference</dt><dd>{{ assignmentRows[0].reference }}</dd></div>
                        <div><dt>Incident</dt><dd>{{ assignmentRows[0].incident_name || '—' }}</dd></div>
                        <div><dt>Barangay</dt><dd>{{ assignmentRows[0].barangay_name || '—' }}</dd></div>
                        <div><dt>Submitted</dt><dd>{{ formatWhen(assignmentRows[0].submitted_at) }}</dd></div>
                        <div><dt>Status</dt><dd>{{ assignmentRows[0].verification_label || assignmentRows[0].label || 'Pending' }}</dd></div>
                        <div><dt>Current assessor</dt><dd>{{ assignmentRows[0].assignee_name || '—' }}</dd></div>
                        <div><dt>Assigned</dt><dd>{{ formatWhen(assignmentRows[0].assigned_at) }}</dd></div>
                    </dl>
                </div>
                <div v-else class="rdana-assign-list">
                    <table>
                        <caption class="sr-only">{{ reassignMode ? 'Reports to reassign' : 'Reports to assign' }}</caption>
                        <thead>
                            <tr>
                                <th scope="col">Report</th>
                                <th scope="col">Barangay</th>
                                <th scope="col">Incident</th>
                                <th v-if="reassignMode" scope="col">Current assessor</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-for="row in assignmentRows" :key="row.id">
                                <td>{{ row.reference }}</td>
                                <td>{{ row.barangay_name || '—' }}</td>
                                <td>{{ row.incident_name || '—' }}</td>
                                <td v-if="reassignMode">{{ row.assignee_name || '—' }}</td>
                            </tr>
                        </tbody>
                    </table>
                </div>
                <div class="rdana-assign-field">
                    <label id="rdana-assessor-label" for="rdana-assessor">{{ reassignMode ? 'New assessor' : 'Assessor' }}</label>
                    <Select
                        input-id="rdana-assessor"
                        aria-labelledby="rdana-assessor-label"
                        v-model="selectedAssessorId"
                        :options="eligibleAssignees"
                        option-label="name"
                        option-value="id"
                        :placeholder="reassignMode ? 'Search RDANA assessor personnel…' : 'Select an assessor'"
                        filter
                        filter-placeholder="Search by name"
                        :pt="{ pcFilter: { root: { class: 'p-inputtext-sm', 'aria-label': 'Search assessors' } } }"
                        :loading="loadingAssignees"
                        :disabled="assigning || loadingAssignees || !eligibleAssignees.length"
                        overlay-class="rdana-queue-overlay"
                        size="small"
                    >
                        <template #option="{ option }">
                            <div>
                                <strong>{{ option.name }}</strong>
                                <span class="rdana-assessor-workload">{{ option.pending_count || 0 }} pending · {{ option.assessing_count || 0 }} assessing</span>
                            </div>
                        </template>
                    </Select>
                </div>
                <div v-if="reassignMode" class="rdana-assign-field">
                    <label for="rdana-replaced-reason">Reason for reassignment</label>
                    <Textarea id="rdana-replaced-reason" v-model="replacedReason" size="small" rows="3" maxlength="500" required :disabled="assigning" placeholder="Explain why this assessment is being reassigned…" />
                </div>
                <div v-else class="rdana-assign-field">
                    <label for="rdana-assignment-remarks">Remarks <span>(optional)</span></label>
                    <Textarea id="rdana-assignment-remarks" v-model="assignRemarks" size="small" rows="3" maxlength="500" :disabled="assigning" />
                </div>
                <Message v-if="assignError" severity="error" size="small">{{ assignError }}</Message>
            </form>
            <template #footer>
                <Button type="button" size="small" severity="secondary" text label="Cancel" :disabled="assigning" @click="assignOpen = false" />
                <Button type="submit" form="rdana-assign-form" size="small" :label="assignActionLabel" :loading="assigning" :disabled="!assignValid" />
            </template>
        </Dialog>

        <Dialog
            v-model:visible="manageOpen"
            header="Manage assessors"
            modal
            :draggable="false"
            :close-button-props="{ size: 'small', severity: 'secondary', text: true }"
            class="rdana-assign-dialog rdana-manage-dialog"
        >
            <div v-if="manageLoading" class="rdana-queue-loading" role="status">
                <Skeleton width="60%" height="0.75rem" />
                <Skeleton width="80%" height="0.75rem" />
            </div>
            <div v-else-if="!assessorDirectory.length" class="rdana-queue-state">
                <strong>No RDANA assessors are assigned to your barangays.</strong>
            </div>
            <div v-else class="rdana-assign-list">
                <table>
                    <caption class="sr-only">RDANA assessor personnel</caption>
                    <thead>
                        <tr>
                            <th scope="col">Assessor</th>
                            <th scope="col">Barangays</th>
                            <th scope="col">Active</th>
                            <th scope="col">Completed</th>
                            <th scope="col">Status</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="person in assessorDirectory" :key="person.id">
                            <td>
                                <strong>{{ person.name }}</strong>
                                <span v-if="person.job_title" class="rdana-queue-subtext">{{ person.job_title }}</span>
                            </td>
                            <td>{{ (person.barangays || []).map((item) => item.name).join(', ') || '—' }}</td>
                            <td>{{ person.active_count || 0 }}</td>
                            <td>{{ person.completed_count || 0 }}</td>
                            <td>{{ person.has_active_assignment ? 'Has active assignments' : 'No active assignments' }}</td>
                        </tr>
                    </tbody>
                </table>
            </div>
            <template #footer>
                <Button type="button" size="small" severity="secondary" text label="Close" @click="manageOpen = false" />
            </template>
        </Dialog>
    </section>

    <section v-else>
        <p v-if="loading">Loading reports…</p>
        <p v-if="error" class="cdrrmo-error">{{ error }}</p>
        <table v-if="!loading" class="cdrrmo-table">
            <thead>
                <tr>
                    <th>Reference</th>
                    <th>Status</th>
                    <th>Updated</th>
                    <th><span class="sr-only">Actions</span></th>
                </tr>
            </thead>
            <tbody>
                <tr v-if="!rows.length"><td colspan="4">No reports in this queue.</td></tr>
                <tr v-for="row in rows" :key="row.id">
                    <td>{{ row.reference }}</td>
                    <td>{{ row.label }}</td>
                    <td>{{ row.updated_at }}</td>
                    <td class="report-row-actions">
                        <button type="button" class="residents-icon-btn" title="View" aria-label="View" @click="openRow(row)"><MapIcon name="eye" /></button>
                        <a v-if="previewHref(row)" class="residents-icon-btn" :href="previewHref(row)" target="_blank" rel="noopener" title="Preview file" aria-label="Preview file"><MapIcon name="file" /></a>
                        <a v-if="previewHref(row)" class="residents-icon-btn" :href="`${previewHref(row)}?format=pdf`" title="Download" aria-label="Download"><MapIcon name="reports" /></a>
                        <button v-if="row.status === 'draft' || row.status === 'returned'" type="button" class="residents-icon-btn" title="Edit" aria-label="Edit" @click="openRow(row)"><MapIcon name="pencil" /></button>
                    </td>
                </tr>
            </tbody>
        </table>
    </section>
</template>

<style scoped>
.rdana-report-queue {
    min-width: 0;
    display: grid;
    gap: 1rem;
    color: var(--resident-ink);
    font-size: 0.8125rem;
    line-height: 1.5;
}

.rdana-queue-heading {
    display: flex;
    flex-wrap: wrap;
    align-items: flex-start;
    justify-content: space-between;
    gap: 1rem;
}

.rdana-queue-heading h2 {
    margin: 0;
    font-size: 1.25rem;
    color: var(--resident-navy);
}

.rdana-queue-heading p {
    margin: 0.25rem 0 0;
    max-width: 42rem;
    color: var(--p-slate-600);
}

.rdana-queue-heading-actions {
    display: flex;
    flex-wrap: wrap;
    gap: 0.5rem;
}

.rdana-summary-cards {
    display: grid;
    grid-template-columns: repeat(4, minmax(0, 1fr));
    gap: 0.75rem;
}

.rdana-summary-card {
    display: flex;
    gap: 0.75rem;
    align-items: flex-start;
    min-height: 7.5rem;
    padding: 0.875rem;
    border: 1px solid var(--resident-line);
    border-radius: var(--resident-radius);
    background: #fff;
    box-shadow: 0 1px 2px rgba(15, 23, 42, 0.04);
    text-align: left;
    cursor: pointer;
}

.rdana-summary-card:hover,
.rdana-summary-card.is-active {
    border-color: color-mix(in srgb, var(--resident-blue) 45%, var(--resident-line));
    background: var(--resident-wash);
}

.rdana-summary-icon {
    display: grid;
    place-items: center;
    width: 2.25rem;
    height: 2.25rem;
    border-radius: 0.5rem;
    background: var(--resident-wash);
    color: var(--resident-blue);
}

.rdana-summary-copy {
    display: grid;
    gap: 0.2rem;
    min-width: 0;
}

.rdana-summary-label {
    color: var(--p-slate-600);
    font-size: 0.75rem;
    font-weight: 600;
}

.rdana-summary-copy strong {
    font-size: 1.375rem;
    font-variant-numeric: tabular-nums;
    color: var(--resident-navy);
}

.rdana-summary-hint,
.rdana-summary-action {
    color: var(--p-slate-500);
    font-size: 0.75rem;
}

.rdana-summary-action {
    color: var(--resident-blue);
    font-weight: 600;
}

.rdana-assign-summary dl {
    display: grid;
    grid-template-columns: repeat(2, minmax(0, 1fr));
    gap: 0.75rem;
    margin: 0 0 1rem;
}

.rdana-assign-summary dt {
    color: var(--p-slate-500);
    font-size: 0.6875rem;
    text-transform: uppercase;
    letter-spacing: 0.02em;
}

.rdana-assign-summary dd {
    margin: 0.15rem 0 0;
    font-weight: 600;
}

.rdana-report-panel {
    border: 1px solid var(--resident-line);
    border-radius: var(--resident-radius);
    background: var(--resident-card);
}

@media (max-width: 1100px) {
    .rdana-summary-cards {
        grid-template-columns: repeat(2, minmax(0, 1fr));
    }
}

@media (max-width: 640px) {
    .rdana-summary-cards {
        grid-template-columns: 1fr;
    }
}

.rdana-queue-nav {
    display: flex;
    flex-wrap: wrap;
    align-items: center;
    justify-content: space-between;
    gap: 0.375rem 1rem;
    padding: 0 0.875rem;
    border-bottom: 1px solid var(--resident-line);
}

.rdana-queue-options {
    display: flex;
    flex-wrap: wrap;
    gap: 0 0.75rem;
}

.rdana-queue-options :deep(.p-togglebutton) {
    min-height: 3rem;
    padding: 0.5rem 0.375rem;
    border: 0;
    border-bottom: 2px solid transparent;
    border-radius: 0;
    background: transparent;
    color: var(--p-slate-600);
    font-weight: 500;
}

.rdana-queue-options :deep(.p-togglebutton-content) {
    gap: 0.375rem;
    padding: 0;
    background: transparent;
    box-shadow: none;
}

.rdana-queue-options :deep(.p-togglebutton-checked) {
    border-bottom-color: var(--resident-blue);
    color: var(--resident-blue-hover);
    font-weight: 600;
}

.rdana-queue-options :deep(.p-togglebutton:focus-visible),
.rdana-queue-options :deep(.p-togglebutton:hover) {
    background: var(--resident-wash);
    color: var(--resident-navy);
}

.rdana-queue-count {
    min-width: 1.25rem;
    padding-inline: 0.25rem;
    border-radius: var(--resident-radius);
    background: var(--p-slate-100);
    color: var(--p-slate-600);
    font-size: 0.6875rem;
    font-variant-numeric: tabular-nums;
    text-align: center;
}

.rdana-queue-short-label,
.rdana-mobile-label {
    display: none;
}

.rdana-queue-options :deep(.p-togglebutton-checked) .rdana-queue-count {
    background: var(--resident-wash);
    color: var(--resident-blue-hover);
}

.rdana-queue-filters {
    display: flex;
    flex-wrap: wrap;
    align-items: end;
    gap: 0.625rem 0.75rem;
    padding: 0.875rem;
    border-bottom: 1px solid var(--resident-line);
}

.rdana-queue-filters > div {
    flex: 1 1 9.5rem;
    min-width: 0;
    max-width: 14rem;
}

.rdana-queue-filters > .rdana-search-field {
    flex-basis: 15rem;
    max-width: 23rem;
}

.rdana-queue-filters label,
:global(.rdana-assign-field label) {
    display: block;
    margin-bottom: 0.25rem;
    color: var(--p-slate-700);
    font-size: 0.75rem;
    font-weight: 600;
}

.rdana-queue-filters :deep(.p-inputtext),
.rdana-queue-filters :deep(.p-select),
:global(.rdana-assign-field > .p-select),
:global(.rdana-assign-field > .p-textarea) {
    width: 100%;
}

.rdana-queue-selection {
    display: flex;
    flex-wrap: wrap;
    align-items: center;
    gap: 0.5rem 0.75rem;
    padding: 0.5rem 0.875rem;
    border-bottom: 1px solid var(--p-blue-100);
    background: var(--resident-wash);
    color: var(--resident-navy);
}

.rdana-queue-state {
    display: grid;
    min-height: 14rem;
    padding: 1.5rem;
    place-content: center;
    justify-items: center;
    gap: 0.625rem;
    text-align: center;
}

.rdana-queue-state > svg {
    width: 1.5rem;
    height: 1.5rem;
    color: var(--p-slate-500);
}

.rdana-queue-loading > div {
    display: flex;
    justify-content: space-between;
    align-items: center;
    min-height: 3.375rem;
    padding: 0.875rem;
}

.rdana-queue-loading > div:not(:last-child) {
    border-bottom: 1px solid var(--resident-line);
}

.rdana-queue-message {
    margin: 0.875rem;
}

.rdana-queue-table :deep(.p-datatable-table) {
    min-width: 45rem;
}

.rdana-queue-table :deep(.p-datatable-thead > tr > th) {
    padding: 0.5rem 0.875rem;
    border-color: var(--resident-line);
    background: var(--p-slate-50);
    color: var(--p-slate-600);
    font-size: 0.75rem;
    font-weight: 600;
}

.rdana-queue-table :deep(.p-datatable-tbody > tr > td) {
    padding: 0.625rem 0.875rem;
    border-color: var(--resident-line);
    vertical-align: middle;
    overflow-wrap: anywhere;
}

.rdana-queue-table :deep(.p-datatable-tbody > tr:last-child > td) {
    border-bottom: 0;
}

.rdana-queue-table :deep(.rdana-row-selected > td) {
    background: var(--resident-wash);
}

.rdana-queue-table :deep(.rdana-queue-checkbox) {
    width: 2.625rem;
    padding-right: 0;
}

.rdana-queue-table :deep(.rdana-queue-report) {
    min-width: 10rem;
}

.rdana-queue-table :deep(.rdana-queue-status) {
    width: 20%;
}

.rdana-queue-table :deep(.rdana-queue-date),
.rdana-queue-amount,
.rdana-queue-footer {
    font-variant-numeric: tabular-nums;
}

.rdana-reference {
    color: var(--resident-blue-hover);
    font-weight: 600;
    text-underline-offset: 0.15rem;
}

.rdana-reference:hover,
.rdana-reference:focus-visible {
    outline: none;
    text-decoration: underline;
}

.rdana-queue-subtext {
    display: block;
    margin-top: 0.125rem;
    color: var(--p-slate-600);
    font-size: 0.75rem;
}

.rdana-queue-table :deep(.rdana-queue-status .p-tag) {
    justify-content: start;
    padding: 0.125rem 0.4375rem;
    border-radius: var(--resident-radius);
    font-size: 0.6875rem;
    line-height: 1.375;
}

.rdana-queue-verify-cell {
    width: 9.5rem;
    white-space: nowrap;
}

.rdana-queue-verify-cell .p-button {
    white-space: nowrap;
}

.rdana-queue-row-actions {
    display: flex;
    justify-content: flex-end;
    align-items: center;
    gap: 0.25rem;
    white-space: nowrap;
}

.rdana-queue-more {
    width: 2rem;
    padding-inline: 0;
}

.rdana-queue-footer {
    display: flex;
    flex-wrap: wrap;
    align-items: center;
    justify-content: space-between;
    gap: 0.5rem;
    min-height: 2.75rem;
    padding: 0.375rem 0.875rem;
    border-top: 1px solid var(--resident-line);
    color: var(--p-slate-600);
    font-size: 0.75rem;
}

.rdana-queue-footer :deep(.p-paginator) {
    gap: 0.25rem;
    padding: 0;
}

.rdana-queue-footer :deep(.p-paginator button) {
    min-width: 2rem;
    height: 2rem;
}

:global(.rdana-assign-dialog) {
    width: min(35rem, calc(100vw - 2rem));
    border-radius: var(--p-border-radius-sm);
}

:global(.rdana-assign-dialog .p-dialog-header) {
    padding: 0.875rem 1.25rem;
    border-bottom: 1px solid var(--p-slate-200);
}

:global(.rdana-assign-dialog .p-dialog-title) {
    font-size: 1rem;
    font-weight: 600;
}

:global(.rdana-assign-dialog .p-dialog-content),
:global(.rdana-assign-dialog .p-dialog-footer) {
    padding: 1rem 1.25rem;
    font-size: 0.8125rem;
}

:global(.rdana-assign-dialog .p-dialog-footer) {
    border-top: 1px solid var(--p-slate-200);
}

:global(.rdana-assign-form) {
    display: grid;
    gap: 1rem;
}

:global(.rdana-assign-list) {
    max-height: 11rem;
    overflow: auto;
    border: 1px solid var(--p-slate-200);
    border-radius: var(--p-border-radius-sm);
}

:global(.rdana-assign-list table) {
    width: 100%;
    border-collapse: collapse;
}

:global(.rdana-assign-list :is(th, td)) {
    padding: 0.375rem 0.625rem;
    text-align: start;
    overflow-wrap: anywhere;
}

:global(.rdana-assign-list th) {
    background: var(--p-slate-50);
    color: var(--p-slate-600);
    font-size: 0.75rem;
    font-weight: 600;
}

:global(.rdana-assign-list td) {
    border-top: 1px solid var(--p-slate-200);
}

:global(.rdana-assign-field label > span),
:global(.rdana-assessor-workload) {
    color: var(--p-slate-600);
    font-size: 0.75rem;
    font-weight: 400;
}

:global(.rdana-assessor-workload) {
    display: block;
}

:global(.rdana-queue-overlay :is(.p-menu-item-link, .p-select-option)) {
    font-size: 0.8125rem;
}

:global(.rdana-queue-overlay .p-menu-item-link) {
    gap: 0.5rem;
}

.rdana-report-queue :deep(.cdrrmo-icon),
:global(.rdana-queue-overlay .cdrrmo-icon) {
    flex: none;
    width: 1rem;
    height: 1rem;
}

.rdana-report-queue :deep(:is(.p-button, .p-inputtext, .p-select)),
:global(.rdana-assign-dialog :is(.p-button, .p-select)) {
    min-height: 2rem;
    border-radius: var(--p-border-radius-sm);
    font-size: 0.8125rem;
}

.rdana-report-queue :deep(:is(.p-button, .p-togglebutton, .p-inputtext, .p-select, .p-checkbox-box, .p-paginator button):focus-visible),
.rdana-report-queue :deep(:is(.p-select, .p-checkbox).p-focus),
.rdana-report-queue :deep(.p-checkbox-input:focus-visible + .p-checkbox-box),
:global(.rdana-assign-dialog :is(.p-button, .p-select, .p-textarea):focus-visible),
:global(.rdana-assign-dialog .p-select.p-focus),
:global(.rdana-queue-overlay :is(.p-menu-list, .p-select-list, .p-select-filter):focus),
:global(.rdana-queue-overlay .p-menu-item-link:focus-visible) {
    outline: none;
    box-shadow: none;
}

.rdana-report-queue :deep(:is(.p-inputtext:focus, .p-select.p-focus)),
:global(.rdana-assign-dialog :is(.p-select.p-focus, .p-textarea:focus)),
:global(.rdana-queue-overlay .p-select-filter:focus) {
    border-color: var(--p-blue-600);
    background: var(--p-blue-50);
}

.rdana-report-queue :deep(.p-checkbox-input:focus-visible + .p-checkbox-box) {
    border-color: var(--p-blue-700);
}

.rdana-report-queue :deep(.p-checkbox:not(.p-checkbox-checked) .p-checkbox-input:focus-visible + .p-checkbox-box),
.rdana-report-queue :deep(:is(.p-button-outlined, .p-button-text, .p-paginator button):focus-visible),
:global(.rdana-assign-dialog .p-button-text:focus-visible) {
    background: var(--p-blue-100);
    color: var(--p-blue-800);
}

.rdana-report-queue :deep(.p-button:not(.p-button-outlined, .p-button-text):focus-visible),
:global(.rdana-assign-dialog .p-button:not(.p-button-text):focus-visible) {
    background: var(--p-blue-700);
}

.rdana-report-queue :deep(.p-checkbox-checked .p-checkbox-input:focus-visible + .p-checkbox-box) {
    background: var(--p-blue-700);
}

@media (max-width: 767px) {
    .rdana-queue-nav {
        gap: 0 0.5rem;
        padding: 0.25rem 0.75rem;
    }

    .rdana-queue-full-label,
    .rdana-queue-refresh :deep(.p-button-label) {
        display: none;
    }

    .rdana-queue-short-label {
        display: inline;
    }

    .rdana-queue-refresh {
        width: 2rem;
        padding-inline: 0;
    }

    .rdana-queue-options {
        gap: 0 0.25rem;
    }

    .rdana-queue-options :deep(.p-togglebutton) {
        min-height: 2.5rem;
        font-size: 0.75rem;
    }

    .rdana-queue-filters {
        padding: 0.75rem;
    }

    .rdana-queue-filters > .rdana-search-field {
        flex-basis: 100%;
        max-width: none;
    }

    .rdana-queue-table :deep(.p-datatable-table),
    .rdana-queue-table :deep(.p-datatable-tbody) {
        display: block;
        min-width: 0;
    }

    .rdana-queue-table :deep(.p-datatable-thead th:not(.rdana-queue-checkbox)) {
        position: absolute;
        width: 1px;
        height: 1px;
        padding: 0;
        overflow: hidden;
        clip-path: inset(50%);
        white-space: nowrap;
    }

    .rdana-queue-table :deep(.p-datatable-thead),
    .rdana-queue-selectable :deep(.p-datatable-thead tr) {
        display: block;
    }

    .rdana-queue-selectable :deep(.p-datatable-thead th.rdana-queue-checkbox) {
        display: flex;
        align-items: center;
        gap: 0.5rem;
        width: 100%;
        padding: 0.5rem 0.75rem;
    }

    .rdana-queue-table :deep(.p-datatable-tbody > tr) {
        display: grid;
        position: relative;
        grid-template-columns: repeat(2, minmax(0, 1fr));
        gap: 0.5rem 0.75rem;
        padding: 0.75rem;
    }

    .rdana-queue-table :deep(.p-datatable-tbody > tr:not(:last-child)) {
        border-bottom: 1px solid var(--resident-line);
    }

    .rdana-queue-table :deep(.p-datatable-tbody > tr > td) {
        display: block;
        width: auto;
        min-width: 0;
        padding: 0;
        border: 0;
    }

    .rdana-queue-table :deep(.p-datatable-tbody > .rdana-row-selected) {
        background: var(--resident-wash);
    }

    .rdana-queue-table :deep(td:is(.rdana-queue-report, .rdana-queue-action-cell)) {
        grid-column: 1 / -1;
    }

    .rdana-queue-selectable :deep(td.rdana-queue-report) {
        padding-right: 2rem;
    }

    .rdana-queue-table :deep(td.rdana-queue-checkbox) {
        position: absolute;
        inset: 0.75rem 0.75rem auto auto;
    }

    .rdana-queue-row-actions {
        justify-content: space-between;
    }

    .rdana-queue-more {
        margin-inline-start: auto;
    }

    .rdana-mobile-label {
        display: block;
        color: var(--p-slate-600);
        font-size: 0.6875rem;
        font-weight: 500;
    }
}

@media (forced-colors: active) {
    .rdana-queue-options :deep(.p-togglebutton-checked) {
        border-bottom-color: Highlight;
    }

    .rdana-report-queue :deep(:is(.p-button, .p-togglebutton, .p-paginator button):focus-visible),
    :global(.rdana-assign-dialog .p-button:focus-visible) {
        text-decoration: underline;
    }

    .rdana-report-queue :deep(:is(.p-inputtext:focus, .p-select.p-focus)),
    .rdana-report-queue :deep(.p-checkbox-input:focus-visible + .p-checkbox-box),
    :global(.rdana-assign-dialog :is(.p-select.p-focus, .p-textarea:focus)),
    :global(.rdana-queue-overlay .p-select-filter:focus) {
        border-color: Highlight;
    }
}
</style>
