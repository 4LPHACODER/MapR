<script setup>
import { computed, inject, onBeforeUnmount, onMounted, reactive, ref, watch } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import MapIcon from '../../shared/components/MapIcon.vue';
import AssessorCombobox from '../components/AssessorCombobox.vue';

const apiBase = inject('apiBase');
const roleCode = inject('roleCode', '');
const route = useRoute();
const router = useRouter();
const rows = ref([]);
const meta = ref({ current_page: 1, last_page: 1, per_page: 10, total: 0 });
const counts = ref({ total: 0, unassigned: 0, pending: 0, completed: 0, awaiting: 0, returned: 0, processed: 0 });
const barangays = ref([]);
const error = ref('');
const loading = ref(true);
const menuId = ref(null);
const actionError = ref('');
const lastUpdated = ref(null);
const canAssign = ref(false);
const queue = ref('unassigned');
const selectedIds = ref([]);
const selectedMeta = ref({});
const assignOpen = ref(false);
const assignees = ref([]);
const selectedAssessorId = ref(null);
const assignRemarks = ref('');
const assignError = ref('');
const assigning = ref(false);
const loadingAssignees = ref(false);
let searchTimer;

const isBarangay = computed(() => route.meta.source === 'barangay');
const isSupervisor = computed(() => canAssign.value);

const filters = reactive({
    search: '',
    barangay_id: '',
    assignee_user_id: '',
    assessment_status: '',
    sort: 'updated_at',
    direction: 'desc',
});

const summaryCards = computed(() => {
    if (!isBarangay.value) {
        return [];
    }
    const cards = [];
    if (isSupervisor.value) {
        cards.push({
            key: 'unassigned',
            label: 'Unassigned Reports',
            value: counts.value.unassigned,
            icon: 'user-round-x',
            hint: 'No active RDANA assessor assignment.',
        });
    }
    cards.push(
        {
            key: 'pending',
            label: 'Pending Assessment',
            value: counts.value.pending,
            icon: 'clock-3',
            hint: 'Assigned and still in progress.',
        },
        {
            key: 'completed',
            label: 'Completed Assessments',
            value: counts.value.completed,
            icon: 'clipboard-check',
            hint: 'Assessor signed and submitted.',
        },
        {
            key: 'total',
            label: 'Total Barangay Reports',
            value: counts.value.total,
            icon: 'files',
            hint: 'Eligible reports in your scope.',
        },
    );
    return cards;
});

const filtersActive = computed(() => Boolean(
    filters.search.trim()
    || filters.barangay_id
    || filters.assignee_user_id
    || filters.assessment_status
    || filters.sort !== 'updated_at'
    || filters.direction !== 'desc',
));

const selectedRows = computed(() => selectedIds.value.map((id) => {
    const live = rows.value.find((row) => row.id === id);
    const cached = selectedMeta.value[id];
    return live || cached || { id, reference: `#${id}`, barangay_name: '—', incident_name: null, barangay_id: null };
}));
const pageSelected = computed(() => rows.value.length > 0 && rows.value.every((row) => selectedIds.value.includes(row.id)));
const selectedAssessor = computed(() => assignees.value.find((row) => Number(row.id) === Number(selectedAssessorId.value)) || null);
const assignValid = computed(() => (
    selectedIds.value.length > 0
    && selectedAssessorId.value !== null
    && selectedAssessorId.value !== ''
    && Boolean(selectedAssessor.value)
));
const assignActionLabel = computed(() => {
    const count = selectedIds.value.length;
    return count === 1 ? 'Assign 1 Report' : `Assign ${count} Reports`;
});

function rememberRows(list) {
    const next = { ...selectedMeta.value };
    for (const row of list) {
        next[row.id] = {
            id: row.id,
            reference: row.reference,
            barangay_name: row.barangay_name,
            barangay_id: row.barangay_id,
            incident_name: row.incident_name,
        };
    }
    selectedMeta.value = next;
}

function clearSelection() {
    selectedIds.value = [];
    selectedMeta.value = {};
}

function formatWhen(value) {
    if (!value) return 'Not recorded';
    const date = new Date(String(value).includes('T') ? value : `${String(value).replace(' ', 'T')}Z`);
    if (Number.isNaN(date.getTime())) return String(value);
    return new Intl.DateTimeFormat('en-PH', {
        timeZone: 'Asia/Manila',
        day: 'numeric',
        month: 'short',
        year: 'numeric',
        hour: 'numeric',
        minute: '2-digit',
    }).format(date);
}

function previewHref(row) {
    if (isBarangay.value) {
        return `/rdana/reports/barangay/${row.id}/preview`;
    }
    return `/rdana/assessments/${row.id}/preview`;
}

function detailHref(row, query = {}) {
    return {
        path: `/reports/barangay/${row.id}`,
        query,
    };
}

function verifyHref(row) {
    return detailHref(row, { from: 'completed' });
}

function openVerify(row) {
    menuId.value = null;
    router.push(verifyHref(row));
}

function setQueue(next) {
    if (next === 'total') return;
    if (next === 'unassigned' && !isSupervisor.value) return;
    queue.value = next;
    clearSelection();
    load();
}

function clearFilters() {
    filters.search = '';
    filters.barangay_id = '';
    filters.assignee_user_id = '';
    filters.assessment_status = '';
    filters.sort = 'updated_at';
    filters.direction = 'desc';
}

function toggleRow(id) {
    if (selectedIds.value.includes(id)) {
        selectedIds.value = selectedIds.value.filter((item) => item !== id);
        const next = { ...selectedMeta.value };
        delete next[id];
        selectedMeta.value = next;
        return;
    }
    const row = rows.value.find((item) => item.id === id);
    if (row) rememberRows([row]);
    selectedIds.value = [...selectedIds.value, id];
}

function togglePage() {
    if (pageSelected.value) {
        const pageIds = new Set(rows.value.map((row) => row.id));
        selectedIds.value = selectedIds.value.filter((id) => !pageIds.has(id));
        const next = { ...selectedMeta.value };
        pageIds.forEach((id) => { delete next[id]; });
        selectedMeta.value = next;
        return;
    }
    rememberRows(rows.value);
    const merged = new Set([...selectedIds.value, ...rows.value.map((row) => row.id)]);
    selectedIds.value = [...merged];
}

function openReport(row) {
    menuId.value = null;
    if (isBarangay.value) {
        router.push(detailHref(row));
        return;
    }
    router.push(`/assessments/${row.id}`);
}

function openLocation(row) {
    menuId.value = null;
    router.push({
        path: '/map',
        query: {
            incident_id: row.disaster_incident_id || undefined,
        },
    });
}

function openRow(row) {
    openReport(row);
}

async function startReview(row) {
    menuId.value = null;
    actionError.value = '';
    try {
        const { data } = await window.axios.post(`${apiBase}/barangay-reports/${row.id}/start`);
        const id = data.data?.id;
        if (id) router.push(`/assessments/${id}`);
    } catch (exception) {
        actionError.value = exception.response?.data?.message || 'This report cannot be assessed from this account.';
    }
}

function resume(row) {
    menuId.value = null;
    const id = row.draft_id || row.assessment_id;
    if (id) router.push(`/assessments/${id}`);
}

function openAssessment(row) {
    menuId.value = null;
    if (row.draft_id || row.assessment_id) {
        router.push(`/assessments/${row.draft_id || row.assessment_id}`);
        return;
    }
    startReview(row);
}

async function openAssign(row = null) {
    menuId.value = null;
    assignError.value = '';
    assignRemarks.value = '';
    selectedAssessorId.value = null;
    assignees.value = [];
    if (row) {
        selectedIds.value = [row.id];
        rememberRows([row]);
    }
    if (!selectedIds.value.length) {
        assignError.value = 'Select at least one unassigned report.';
        assignOpen.value = true;
        return;
    }
    assignOpen.value = true;
    loadingAssignees.value = true;
    try {
        const barangayId = selectedRows.value.find((item) => item.barangay_id)?.barangay_id
            || filters.barangay_id
            || barangays.value[0]?.id;
        const response = await window.axios.get(`${apiBase}/personnel`, {
            params: { barangay_id: barangayId, role: 'rdana' },
        });
        assignees.value = response.data.data || [];
        if (!assignees.value.length) {
            assignError.value = 'No eligible RDANA Assessor Personnel were found for this barangay.';
        }
    } catch (exception) {
        assignError.value = exception.response?.data?.message || 'Assessor personnel could not be loaded.';
    } finally {
        loadingAssignees.value = false;
    }
}

async function confirmAssign() {
    assignError.value = '';
    if (!assignValid.value) {
        assignError.value = 'Select an assessor and at least one report before confirming.';
        return;
    }
    assigning.value = true;
    try {
        const payload = {
            assignee_user_id: Number(selectedAssessorId.value),
            report_ids: selectedIds.value.map((id) => Number(id)),
            remarks: assignRemarks.value.trim() || undefined,
        };
        await window.axios.post(`${apiBase}/barangay-reports/assign`, payload);
        assignOpen.value = false;
        clearSelection();
        selectedAssessorId.value = null;
        queue.value = 'pending';
        await load();
    } catch (exception) {
        assignError.value = exception.response?.data?.message || 'Assignment could not be saved.';
    } finally {
        assigning.value = false;
    }
}

async function load() {
    loading.value = true;
    error.value = '';
    actionError.value = '';
    const path = isBarangay.value ? `${apiBase}/barangay-reports` : `${apiBase}/assessments`;
    const params = isBarangay.value
        ? {
            queue: queue.value,
            search: filters.search.trim() || undefined,
            barangay_id: filters.barangay_id || undefined,
            assignee_user_id: filters.assignee_user_id || undefined,
            assessment_status: filters.assessment_status || undefined,
            sort: filters.sort,
            direction: filters.direction,
            per_page: 10,
        }
        : { status: route.meta.status || undefined, per_page: 10 };
    try {
        const { data } = await window.axios.get(path, { params });
        rows.value = data.data || [];
        meta.value = data.meta || meta.value;
        if (isBarangay.value) {
            counts.value = { ...counts.value, ...(data.counts || {}) };
            barangays.value = data.barangays || [];
            if (typeof data.can_assign === 'boolean') {
                canAssign.value = data.can_assign;
            }
            if (data.queue && ['unassigned', 'pending', 'completed'].includes(data.queue)) {
                queue.value = data.queue;
            }
        }
        lastUpdated.value = new Date();
    } catch (exception) {
        error.value = exception.response?.data?.message || 'The report list could not be loaded.';
    } finally {
        loading.value = false;
    }
}

function scheduleSearch() {
    clearTimeout(searchTimer);
    searchTimer = setTimeout(() => {
        if (isBarangay.value) load();
    }, 300);
}

onMounted(async () => {
    try {
        const profile = await window.axios.get(`${apiBase}/profile`);
        canAssign.value = ['rdana_admin_officer', 'rdana_city_assessor'].includes(profile.data.role_code);
        queue.value = canAssign.value ? (route.query.queue || 'unassigned') : 'pending';
    } catch {
        canAssign.value = ['rdana_admin_officer', 'rdana_city_assessor'].includes(roleCode);
        queue.value = canAssign.value ? 'unassigned' : 'pending';
    }
    await load();
});
watch(() => route.fullPath, () => {
    if (!isBarangay.value) load();
});
watch(() => [filters.barangay_id, filters.assignee_user_id, filters.assessment_status, filters.sort, filters.direction], () => {
    if (!isBarangay.value) return;
    load();
});
watch(() => filters.search, () => {
    if (!isBarangay.value) return;
    scheduleSearch();
});
onBeforeUnmount(() => clearTimeout(searchTimer));
</script>

<template>
    <section v-if="isBarangay" class="bdrrm-submissions">
        <header class="submission-heading">
            <div class="submission-heading-title">
                <span class="submission-heading-icon" aria-hidden="true"><MapIcon name="file-text" /></span>
                <div>
                    <h2>Barangay Reports</h2>
                    <p>Assign RDANA assessors, monitor building damage assessments, and review barangay reports from authorized study barangays.</p>
                </div>
            </div>
            <div class="submission-heading-actions">
                <p v-if="lastUpdated" class="submission-count">Updated {{ formatWhen(lastUpdated.toISOString()) }}</p>
                <button type="button" class="submission-quiet" :disabled="loading" @click="load">
                    <MapIcon name="refresh" /> Refresh
                </button>
                <button
                    v-if="canAssign"
                    type="button"
                    class="submission-primary"
                    :disabled="!selectedIds.length && queue !== 'unassigned'"
                    @click="openAssign()"
                >
                    <MapIcon name="user-round-plus" /> Assign RDANA Personnel
                </button>
            </div>
        </header>

        <div class="submission-summaries">
            <button
                v-for="card in summaryCards"
                :key="card.key"
                type="button"
                class="submission-summary"
                :data-tone="card.key"
                :aria-pressed="card.key !== 'total' && queue === card.key"
                :disabled="card.key === 'total'"
                @click="setQueue(card.key)"
            >
                <span class="submission-summary-icon" aria-hidden="true"><MapIcon :name="card.icon" /></span>
                <span>
                    <span class="submission-summary-label">{{ card.label }}</span>
                    <strong>{{ card.value }}</strong>
                    <span class="submission-summary-note">{{ card.hint }}</span>
                </span>
            </button>
        </div>

        <div class="submission-queues" role="tablist" aria-label="Barangay report queues">
            <button
                v-if="canAssign"
                type="button"
                role="tab"
                :aria-selected="queue === 'unassigned'"
                :class="{ 'is-active': queue === 'unassigned' }"
                @click="setQueue('unassigned')"
            >
                Unassigned <span>{{ counts.unassigned }}</span>
            </button>
            <button type="button" role="tab" :aria-selected="queue === 'pending'" :class="{ 'is-active': queue === 'pending' }" @click="setQueue('pending')">
                Pending Assessment <span>{{ counts.pending }}</span>
            </button>
            <button type="button" role="tab" :aria-selected="queue === 'completed'" :class="{ 'is-active': queue === 'completed' }" @click="setQueue('completed')">
                Completed Assessments <span>{{ counts.completed }}</span>
            </button>
        </div>

        <form class="submission-filter-bar" @submit.prevent="load">
            <label class="submission-search">
                <span>Search</span>
                <span class="submission-search-field">
                    <MapIcon name="search" />
                    <input v-model="filters.search" type="search" maxlength="80" autocomplete="off" placeholder="Search report reference, incident, or barangay...">
                </span>
            </label>
            <label>
                <span>Barangay</span>
                <select v-model="filters.barangay_id">
                    <option value="">All assigned barangays</option>
                    <option v-for="barangay in barangays" :key="barangay.id" :value="String(barangay.id)">{{ barangay.name }}</option>
                </select>
            </label>
            <label v-if="queue !== 'unassigned'">
                <span>Progress</span>
                <select v-model="filters.assessment_status">
                    <option value="">All statuses</option>
                    <option value="not_opened">Not Opened</option>
                    <option value="assessing">Assessing</option>
                    <option value="draft">Assessment Draft</option>
                </select>
            </label>
            <label>
                <span>Sort</span>
                <select v-model="filters.sort">
                    <option value="updated_at">Latest updated</option>
                    <option value="submitted_at">Submission date</option>
                    <option value="report_reference">Report reference</option>
                </select>
            </label>
            <label>
                <span>Direction</span>
                <select v-model="filters.direction">
                    <option value="desc">Newest first</option>
                    <option value="asc">Oldest first</option>
                </select>
            </label>
            <button type="submit" class="submission-primary"><MapIcon name="search" /> Search</button>
            <button v-if="filtersActive" type="button" class="submission-quiet" @click="clearFilters"><MapIcon name="x" /> Clear filters</button>
        </form>

        <div v-if="canAssign && queue === 'unassigned' && (selectedIds.length || rows.length)" class="submission-toolbar">
            <button type="button" class="submission-quiet" :disabled="!rows.length" @click="togglePage">{{ pageSelected ? 'Clear page' : 'Select page' }}</button>
            <template v-if="selectedIds.length">
                <strong>{{ selectedIds.length }} {{ selectedIds.length === 1 ? 'report' : 'reports' }} selected</strong>
                <button type="button" class="submission-primary" @click="openAssign()"><MapIcon name="user-round-plus" /> Assign RDANA Personnel</button>
                <button type="button" class="submission-quiet" @click="clearSelection">Clear selection</button>
            </template>
        </div>

        <p v-if="actionError" class="cdrrmo-error" role="alert">{{ actionError }}</p>
        <div v-if="loading" class="submission-status"><p>Loading barangay reports…</p></div>
        <div v-else-if="error" class="submission-status" role="alert">
            <p>{{ error }}</p>
            <button type="button" class="submission-primary" @click="load">Retry</button>
        </div>
        <div v-else-if="!rows.length" class="submission-status">
            <p v-if="queue === 'unassigned'">No unassigned barangay reports in your scope.</p>
            <p v-else-if="queue === 'pending'">No pending assessments.</p>
            <p v-else>No completed assessments yet.</p>
        </div>
        <ul v-else class="submission-list">
            <li v-for="row in rows" :key="row.id">
                <article class="submission-card">
                    <div class="submission-card-main">
                        <label v-if="canAssign && queue === 'unassigned'" class="submission-check">
                            <input type="checkbox" :checked="selectedIds.includes(row.id)" :aria-label="`Select ${row.reference}`" @change="toggleRow(row.id)">
                        </label>
                        <div class="submission-card-icon" aria-hidden="true"><MapIcon name="file-text" /></div>
                        <div class="submission-card-copy">
                            <h3>{{ row.reference }}</h3>
                            <p class="submission-incident">{{ row.incident_name || 'Incident not recorded' }}</p>
                            <p class="submission-meta">
                                <span><MapIcon name="pin" /> {{ row.barangay_name || 'Barangay' }}</span>
                                <span v-if="row.reporting_resident"><MapIcon name="user-round" /> {{ row.reporting_resident }}</span>
                                <span><MapIcon name="calendar-days" /> {{ formatWhen(row.submitted_at) }}</span>
                                <span v-if="row.assignee_name"><MapIcon name="user-round" /> {{ queue === 'completed' ? 'Assessed By' : 'Assigned' }}: {{ row.assignee_name }}</span>
                            </p>
                            <p v-if="queue === 'completed' && (row.building_status_label || row.estimated_cost_php != null)" class="submission-meta">
                                <span v-if="row.building_status_label"><MapIcon name="building" /> {{ row.building_status_label }}</span>
                                <span v-if="row.estimated_cost_php != null"><MapIcon name="calculator" /> ₱{{ Number(row.estimated_cost_php).toLocaleString('en-PH', { minimumFractionDigits: 2, maximumFractionDigits: 2 }) }}</span>
                            </p>
                        </div>
                    </div>
                    <span class="submission-badge" :data-status="row.assessment_progress || row.status">{{ row.verification_label || row.label }}</span>
                    <div class="submission-card-actions">
                        <button
                            v-if="canAssign && queue === 'unassigned'"
                            type="button"
                            class="submission-primary"
                            @click="openAssign(row)"
                        >
                            <MapIcon name="user-round-plus" /> Assign Assessor
                        </button>
                        <button
                            v-if="queue === 'pending'"
                            type="button"
                            class="submission-primary"
                            @click="openAssessment(row)"
                        >
                            <MapIcon name="clipboard-check" /> Assess Report
                        </button>
                        <button
                            v-if="canAssign && queue === 'completed'"
                            type="button"
                            class="submission-primary"
                            @click="openVerify(row)"
                        >
                            <MapIcon name="shield-check" /> Verify Report
                        </button>
                        <RouterLink
                            v-else-if="queue === 'completed' || queue === 'pending' || queue === 'unassigned'"
                            class="submission-quiet"
                            :to="detailHref(row)"
                        >
                            <MapIcon name="eye" /> View Report
                        </RouterLink>
                        <a class="submission-quiet" :href="`${previewHref(row)}?format=pdf`"><MapIcon name="download" /> PDF</a>
                        <button type="button" class="submission-quiet submission-icon-button" aria-label="More" @click="menuId = menuId === row.id ? null : row.id">
                            <MapIcon name="more" />
                        </button>
                        <div v-if="menuId === row.id" class="report-menu" role="menu">
                            <button type="button" role="menuitem" @click="openLocation(row)">View Location</button>
                            <a role="menuitem" :href="previewHref(row)">View Signatures</a>
                            <button type="button" role="menuitem" @click="openReport(row)">Report History</button>
                        </div>
                    </div>
                </article>
            </li>
        </ul>

        <div v-if="assignOpen" class="assign-modal" role="presentation" @click.self="assignOpen = false">
            <div class="assign-panel assign-panel-wide" role="dialog" aria-modal="true" aria-labelledby="rdana-assign-title">
                <header class="assign-panel-header">
                    <div>
                        <h2 id="rdana-assign-title">Assign RDANA Assessor Personnel</h2>
                        <p>Assign selected barangay damage reports to an authorized RDANA assessor.</p>
                    </div>
                    <button type="button" class="submission-icon-button" aria-label="Close" @click="assignOpen = false">
                        <MapIcon name="x" />
                    </button>
                </header>

                <section class="assign-section">
                    <h3><MapIcon name="files" /> Selected Barangay Reports</h3>
                    <p class="assign-count"><strong>{{ selectedIds.length }}</strong> {{ selectedIds.length === 1 ? 'Report Selected' : 'Reports Selected' }}</p>
                    <div class="assign-report-list" tabindex="0">
                        <table>
                            <thead>
                                <tr>
                                    <th>Report Reference</th>
                                    <th>Barangay</th>
                                    <th>Incident</th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr v-for="row in selectedRows" :key="row.id">
                                    <td>{{ row.reference }}</td>
                                    <td>{{ row.barangay_name || '—' }}</td>
                                    <td>{{ row.incident_name || '—' }}</td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </section>

                <section class="assign-section">
                    <AssessorCombobox
                        v-model="selectedAssessorId"
                        :options="assignees"
                        :loading="loadingAssignees"
                        :disabled="assigning || !selectedIds.length"
                        placeholder="Search assessor by name..."
                    />
                </section>

                <section v-if="selectedAssessor" class="assign-section assign-summary">
                    <h3><MapIcon name="clipboard-list" /> Assignment Summary</h3>
                    <dl>
                        <div>
                            <dt>Selected Assessor</dt>
                            <dd>{{ selectedAssessor.name }}</dd>
                        </div>
                        <div>
                            <dt>Reports to Assign</dt>
                            <dd>{{ selectedIds.length }}</dd>
                        </div>
                        <div>
                            <dt>Assignment Type</dt>
                            <dd>Barangay Damage Assessment</dd>
                        </div>
                    </dl>
                </section>

                <label class="assign-remarks">
                    <span><MapIcon name="pen-line" /> Assignment Remarks (Optional)</span>
                    <textarea
                        v-model="assignRemarks"
                        rows="3"
                        maxlength="500"
                        placeholder="Enter instructions for the assigned assessor..."
                        :disabled="assigning"
                    />
                </label>

                <p v-if="assignError" class="cdrrmo-error" role="alert">{{ assignError }}</p>
                <div class="bdrrm-dialog-actions assign-panel-footer">
                    <button type="button" class="submission-quiet" :disabled="assigning" @click="assignOpen = false">Cancel</button>
                    <button type="button" class="submission-primary" :disabled="!assignValid || assigning" @click="confirmAssign">
                        <MapIcon name="circle-check" />
                        {{ assigning ? 'Assigning…' : assignActionLabel }}
                    </button>
                </div>
            </div>
        </div>
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
