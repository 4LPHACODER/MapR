<script setup>
import { computed, onBeforeUnmount, onMounted, reactive, ref, watch } from 'vue';
import { useRouter } from 'vue-router';
import IncidentFormDialog from '../components/IncidentFormDialog.vue';
import MapIcon from '../../shared/components/MapIcon.vue';

const router = useRouter();
const rows = ref([]);
const meta = ref({ current_page: 1, last_page: 1, total: 0, per_page: 25 });
const summary = ref(null);
const loading = ref(true);
const error = ref('');
const notice = ref('');
const formOpen = ref(false);
const editing = ref(null);
const detail = ref(null);
const openMenuId = ref(null);
const statusTarget = ref(null);
const statusError = ref('');
const addButton = ref(null);
const returnFocus = ref(null);
const ready = ref(false);
let listTicket = 0;
let searchTimer = null;

const filters = reactive({
    search: '',
    disaster_type_id: '',
    incident_status: '',
    barangay_id: '',
    per_page: 25,
    page: 1,
});

const types = computed(() => summary.value?.disaster_types || []);
const barangays = computed(() => summary.value?.barangays || []);
const rangeLabel = computed(() => {
    if (!meta.value.total) return '0 incidents';
    const start = (meta.value.current_page - 1) * meta.value.per_page + 1;
    const end = Math.min(meta.value.total, start + rows.value.length - 1);
    return `${start}–${end} of ${meta.value.total}`;
});

function params(includeStatus = true) {
    return {
        search: filters.search || undefined,
        disaster_type_id: filters.disaster_type_id || undefined,
        barangay_id: filters.barangay_id || undefined,
        incident_status: includeStatus ? (filters.incident_status || undefined) : undefined,
    };
}

async function loadSummary() {
    try {
        summary.value = (await window.axios.get('/cdrrmo/api/incidents/summary', { params: params(false) })).data;
    } catch {
        summary.value = summary.value;
    }
}

async function loadIncidents() {
    const ticket = ++listTicket;
    loading.value = true;
    error.value = '';
    try {
        const { data } = await window.axios.get('/cdrrmo/api/incidents', {
            params: { ...params(true), per_page: filters.per_page, page: filters.page },
        });
        if (ticket !== listTicket) return;
        rows.value = data.data || [];
        meta.value = data.meta || meta.value;
    } catch {
        if (ticket === listTicket) error.value = 'Disaster incidents could not be loaded.';
    } finally {
        if (ticket === listTicket) loading.value = false;
    }
}

function reload() {
    loadSummary();
    loadIncidents();
}

function openCreate(event) {
    returnFocus.value = event?.currentTarget || addButton.value;
    editing.value = null;
    formOpen.value = true;
}

async function openEdit(row, event) {
    openMenuId.value = null;
    returnFocus.value = event?.currentTarget || returnFocus.value;
    editing.value = row.occurred_at_local ? row : (await window.axios.get(`/cdrrmo/api/incidents/${row.id}`)).data;
    detail.value = null;
    formOpen.value = true;
}

async function openDetail(row) {
    openMenuId.value = null;
    detail.value = (await window.axios.get(`/cdrrmo/api/incidents/${row.id}`)).data;
}

function openMap(row) {
    router.push({ path: '/map', query: { incident_id: row.id } });
}

function openReport(path) {
    if (path) router.push(path);
}

function restoreFocus() {
    returnFocus.value?.focus?.();
}

function onSaved(message) {
    formOpen.value = false;
    editing.value = null;
    notice.value = message;
    reload();
    restoreFocus();
}

function onFormClose() {
    formOpen.value = false;
    editing.value = null;
    restoreFocus();
}

function askStatus(row, next) {
    openMenuId.value = null;
    statusError.value = '';
    statusTarget.value = { ...row, next };
}

async function confirmStatus() {
    statusError.value = '';
    try {
        await window.axios.patch(`/cdrrmo/api/incidents/${statusTarget.value.id}`, {
            incident_status: statusTarget.value.next,
            confirm_close: statusTarget.value.next === 'closed',
        });
        notice.value = statusTarget.value.next === 'closed'
            ? 'Incident closed. Reports were not approved or completed.'
            : 'Incident reopened.';
        statusTarget.value = null;
        reload();
    } catch (exception) {
        statusError.value = exception.response?.data?.message || 'The incident status could not be changed.';
    }
}

function clearFilters() {
    Object.assign(filters, { search: '', disaster_type_id: '', incident_status: '', barangay_id: '', page: 1 });
    reload();
}

function goToPage(page) {
    filters.page = page;
    loadIncidents();
}

function onDocumentClick(event) {
    if (!event.target.closest('.incident-menu')) openMenuId.value = null;
}

watch(() => filters.search, () => {
    if (!ready.value) return;
    clearTimeout(searchTimer);
    searchTimer = setTimeout(() => {
        filters.page = 1;
        reload();
    }, 300);
});

watch(() => [filters.disaster_type_id, filters.incident_status, filters.barangay_id, filters.per_page], () => {
    if (!ready.value) return;
    filters.page = 1;
    reload();
});

onMounted(async () => {
    document.addEventListener('click', onDocumentClick);
    ready.value = true;
    await reload();
});

onBeforeUnmount(() => {
    document.removeEventListener('click', onDocumentClick);
    clearTimeout(searchTimer);
});
</script>

<template>
    <section class="incidents-page">
        <header class="incidents-header">
            <div>
                <h2 class="cdrrmo-page-title">Disaster Incidents</h2>
                <p class="cdrrmo-page-lead">Manage disaster events and their associated barangays.</p>
            </div>
            <button ref="addButton" type="button" class="mapr-button mapr-button-primary" @click="openCreate">
                <MapIcon name="plus" /> Add Incident
            </button>
        </header>

        <p v-if="notice" class="incidents-notice" role="status">{{ notice }}</p>

        <div class="incidents-summary" aria-label="Incident summary">
            <article class="cdrrmo-stat-card">
                <p><MapIcon name="disaster" /> Total Incidents</p>
                <strong>{{ summary?.counts?.total ?? '—' }}</strong>
            </article>
            <article class="cdrrmo-stat-card">
                <p><MapIcon name="pin-check" /> Active Incidents</p>
                <strong>{{ summary?.counts?.active ?? '—' }}</strong>
            </article>
            <article class="cdrrmo-stat-card">
                <p><MapIcon name="history" /> Closed Incidents</p>
                <strong>{{ summary?.counts?.closed ?? '—' }}</strong>
            </article>
        </div>
        <p class="incident-help">{{ summary?.scope_note || 'Counts include every incident, not only this page.' }}</p>

        <form class="cdrrmo-filters" @submit.prevent="reload">
            <input v-model="filters.search" type="search" placeholder="Incident name or reference" aria-label="Search incidents">
            <select v-model="filters.disaster_type_id" aria-label="Disaster type">
                <option value="">All disaster types</option>
                <option v-for="type in types" :key="type.id" :value="String(type.id)">{{ type.name }}</option>
            </select>
            <select v-model="filters.incident_status" aria-label="Incident status">
                <option value="">All statuses</option>
                <option value="active">Active</option>
                <option value="closed">Closed</option>
            </select>
            <select v-model="filters.barangay_id" aria-label="Barangay">
                <option value="">All barangays</option>
                <option v-for="barangay in barangays" :key="barangay.id" :value="String(barangay.id)">{{ barangay.name }}</option>
            </select>
            <button type="button" class="mapr-button mapr-button-secondary" @click="clearFilters">Clear filters</button>
        </form>

        <p v-if="loading && !rows.length">Loading incidents…</p>
        <p v-else-if="error" class="cdrrmo-error" role="alert">{{ error }}</p>
        <div v-else-if="!rows.length" class="incidents-empty">
            <p>No disaster incidents match these filters.</p>
            <button type="button" class="mapr-button mapr-button-primary" @click="openCreate">
                <MapIcon name="plus" /> Add Incident
            </button>
        </div>

        <div v-else class="cdrrmo-table-wrap">
            <table class="cdrrmo-table incidents-table">
                <thead>
                    <tr>
                        <th>Incident reference</th>
                        <th>Incident</th>
                        <th>Associated barangays</th>
                        <th>Status</th>
                        <th>Occurrence</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-for="row in rows" :key="row.id">
                        <td>{{ row.incident_reference }}</td>
                        <td>
                            <strong>{{ row.incident_name }}</strong>
                            <span class="incidents-sub">{{ row.disaster_type || 'Type not recorded' }}</span>
                            <span v-if="row.is_demo" class="incidents-demo">Demo data</span>
                        </td>
                        <td>{{ row.barangays?.map((barangay) => barangay.name).join(', ') || '—' }}</td>
                        <td><span class="incidents-status" :class="`is-${row.incident_status}`">{{ row.incident_status === 'closed' ? 'Closed' : 'Active' }}</span></td>
                        <td>{{ row.occurred_at_display }}</td>
                        <td>
                            <div class="incidents-actions">
                                <button type="button" class="incident-icon-btn" title="View incident" aria-label="View incident" @click="openDetail(row)">
                                    <MapIcon name="eye" />
                                </button>
                                <button type="button" class="incident-icon-btn" title="Edit incident" aria-label="Edit incident" @click="openEdit(row, $event)">
                                    <MapIcon name="pencil" />
                                </button>
                                <button type="button" class="incident-icon-btn" title="Open Assessment Map" aria-label="Open Assessment Map" @click="openMap(row)">
                                    <MapIcon name="pin" />
                                </button>
                                <div class="incident-menu">
                                    <button type="button" class="incident-icon-btn" title="More actions" aria-label="More actions" :aria-expanded="openMenuId === row.id" aria-haspopup="menu" @click.stop="openMenuId = openMenuId === row.id ? null : row.id">
                                        <MapIcon name="more" />
                                    </button>
                                    <div v-if="openMenuId === row.id" class="incident-menu-panel" role="menu">
                                        <button v-if="row.incident_status === 'active'" type="button" role="menuitem" @click="askStatus(row, 'closed')">Close incident</button>
                                        <button v-else type="button" role="menuitem" @click="askStatus(row, 'active')">Reopen incident</button>
                                    </div>
                                </div>
                            </div>
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>

        <div v-if="meta.total" class="incidents-pager">
            <span>{{ rangeLabel }}</span>
            <label>
                Rows
                <select v-model.number="filters.per_page" aria-label="Rows per page">
                    <option :value="10">10</option>
                    <option :value="25">25</option>
                    <option :value="50">50</option>
                </select>
            </label>
            <div class="cdrrmo-actions">
                <button type="button" class="mapr-button mapr-button-secondary" :disabled="meta.current_page <= 1" @click="goToPage(meta.current_page - 1)">Previous</button>
                <span>Page {{ meta.current_page }} of {{ meta.last_page }}</span>
                <button type="button" class="mapr-button mapr-button-secondary" :disabled="meta.current_page >= meta.last_page" @click="goToPage(meta.current_page + 1)">Next</button>
            </div>
        </div>

        <IncidentFormDialog
            :open="formOpen"
            :incident="editing"
            :types="types"
            :barangays="barangays"
            @close="onFormClose"
            @saved="onSaved"
        />

        <div v-if="detail" class="incident-drawer-backdrop" @keydown.esc="detail = null">
            <aside class="incident-drawer" role="dialog" aria-modal="true" aria-labelledby="incident-detail-title">
                <header class="incidents-header">
                    <div>
                        <h3 id="incident-detail-title">{{ detail.incident_name }}</h3>
                        <p class="cdrrmo-muted">{{ detail.incident_reference }}</p>
                    </div>
                    <button type="button" class="mapr-button mapr-button-secondary" @click="detail = null">Close</button>
                </header>
                <p v-if="detail.is_demo" class="incidents-demo">Demo data</p>
                <dl class="incident-detail-list">
                    <div><dt>Disaster type</dt><dd>{{ detail.disaster_type || '—' }}</dd></div>
                    <div><dt>Status</dt><dd>{{ detail.incident_status === 'closed' ? 'Closed' : 'Active' }}</dd></div>
                    <div><dt>Occurrence</dt><dd>{{ detail.occurred_at_display }}</dd></div>
                    <div><dt>Barangays</dt><dd>{{ detail.barangays?.map((barangay) => barangay.name).join(', ') || '—' }}</dd></div>
                </dl>
                <h4>Description</h4>
                <p>{{ detail.description || 'No description was recorded.' }}</p>
                <h4>Related reports</h4>
                <p v-if="!detail.reports?.length">No related reports are available for this incident.</p>
                <ul v-else class="incident-report-list">
                    <li v-for="report in detail.reports" :key="report.reference">
                        <strong>{{ report.reference }}</strong>
                        <span>{{ report.type }}<template v-if="report.context"> · {{ report.context }}</template> · {{ report.status }}</span>
                        <button v-if="report.open_path" type="button" class="mapr-button mapr-button-secondary" @click="openReport(report.open_path)">Open report</button>
                    </li>
                </ul>
                <button type="button" class="mapr-button mapr-button-primary" @click="openEdit(detail)">Edit incident</button>
            </aside>
        </div>

        <div v-if="statusTarget" class="incident-modal-backdrop">
            <form class="incident-modal incident-confirm" role="dialog" aria-modal="true" aria-labelledby="incident-status-title" @submit.prevent="confirmStatus">
                <h3 id="incident-status-title">{{ statusTarget.next === 'closed' ? 'Close incident' : 'Reopen incident' }}</h3>
                <p v-if="statusTarget.next === 'closed'">
                    Close {{ statusTarget.incident_reference }}? Closing does not approve or complete its reports.
                </p>
                <p v-else>Reopen {{ statusTarget.incident_reference }} as an active incident?</p>
                <p v-if="statusError" class="cdrrmo-error" role="alert">{{ statusError }}</p>
                <footer class="incident-modal-footer">
                    <button type="button" class="mapr-button mapr-button-secondary" @click="statusTarget = null">Cancel</button>
                    <button type="submit" class="mapr-button mapr-button-primary">{{ statusTarget.next === 'closed' ? 'Close incident' : 'Reopen incident' }}</button>
                </footer>
            </form>
        </div>
    </section>
</template>
