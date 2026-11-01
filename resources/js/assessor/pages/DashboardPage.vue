<script setup>
import { inject, onMounted, reactive, ref, watch } from 'vue';
import { useRouter } from 'vue-router';
import MapIcon from '../../shared/components/MapIcon.vue';
import MapWorkspace from '../../shared/components/MapWorkspace.vue';

const apiBase = inject('apiBase');
const router = useRouter();
const error = ref('');
const loading = ref(false);
const payload = ref(null);
const registry = ref(null);
const actionError = ref('');
const menuId = ref(null);
const filters = reactive({
    incident_id: '',
    barangay_id: '',
    period_start: '',
    period_end: '',
    assessment_status: '',
});
const workloadIcons = {
    review: 'clipboard',
    drafts: 'file-pen',
    returned: 'undo',
    submitted: 'send',
};

onMounted(loadAssessor);

watch(filters, () => {
    loadAssessor();
}, { deep: true });

async function loadAssessor() {
    loading.value = true;
    error.value = '';
    const params = Object.fromEntries(Object.entries(filters).filter(([, value]) => value !== '' && value != null));
    const registryParams = {
        barangay_id: filters.barangay_id || undefined,
        incident_id: filters.incident_id || undefined,
    };
    try {
        const [dashboard, summary] = await Promise.all([
            window.axios.get(`${apiBase}/dashboard`, { params }),
            window.axios.get(`${apiBase}/residents/summary`, { params: registryParams }),
        ]);
        payload.value = dashboard.data;
        registry.value = summary.data;
    } catch (exception) {
        error.value = exception.response?.data?.message || 'The assessor dashboard could not be loaded.';
    } finally {
        loading.value = false;
    }
}

function openCard(card) {
    const path = {
        review: '/reports/barangay',
        drafts: '/reports/drafts',
        returned: '/reports/returned',
        submitted: '/reports/submitted',
    }[card.key];
    if (path) router.push(path);
}

function clearFilters() {
    Object.assign(filters, {
        incident_id: '',
        barangay_id: '',
        period_start: '',
        period_end: '',
        assessment_status: '',
    });
}

function formatCount(value) {
    if (value === null || value === undefined || value === '') return '—';
    return Number(value).toLocaleString('en-PH');
}

function formatWhen(value) {
    if (!value) return '—';
    const date = new Date(String(value).includes('T') ? value : `${String(value).replace(' ', 'T')}Z`);
    if (Number.isNaN(date.getTime())) return value;
    return new Intl.DateTimeFormat('en-PH', {
        timeZone: 'Asia/Manila',
        day: 'numeric',
        month: 'short',
        year: 'numeric',
    }).format(date);
}

function previewHref(row) {
    if (row.kind === 'field_assessment') return `/rdana/assessments/${row.id}/preview`;
    return `/rdana/reports/barangay/${row.id}/preview`;
}

function openLocation(row) {
    menuId.value = null;
    router.push({
        path: '/map',
        query: {
            incident_id: row.incident_id || undefined,
            search: row.building_reference || undefined,
        },
    });
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
    if (row.draft_id) router.push(`/assessments/${row.draft_id}`);
}
</script>

<template>
    <section class="cdrrmo-dashboard">
        <header class="cdrrmo-dashboard-header">
            <div>
                <h2 class="cdrrmo-page-title">Assessor Dashboard</h2>
                <p class="cdrrmo-page-lead">Review building assessments and resident records across the four study barangays.</p>
            </div>
            <div class="cdrrmo-dashboard-actions">
                <button type="button" class="mapr-button mapr-button-secondary" @click="loadAssessor">
                    <MapIcon name="refresh" /> Refresh
                </button>
                <router-link class="mapr-button mapr-button-primary" to="/map">
                    <MapIcon name="map" /> Open Assessment Map
                </router-link>
                <router-link v-if="payload?.resume_draft" class="mapr-button mapr-button-secondary" :to="`/assessments/${payload.resume_draft.id}`">
                    <MapIcon name="pencil" /> Resume draft
                </router-link>
            </div>
        </header>

        <form class="cdrrmo-filters" @submit.prevent="loadAssessor">
            <select v-model="filters.incident_id" aria-label="Registry or incident">
                <option value="">Registry view</option>
                <option v-for="incident in payload?.incidents || []" :key="incident.id" :value="String(incident.id)">{{ incident.incident_name }}</option>
            </select>
            <select v-model="filters.barangay_id" aria-label="Barangay">
                <option value="">All four barangays</option>
                <option v-for="barangay in payload?.barangays || []" :key="barangay.id" :value="String(barangay.id)">{{ barangay.name }}</option>
            </select>
            <select v-model="filters.assessment_status" aria-label="Assessment status">
                <option value="">Reports awaiting assessment</option>
                <option value="draft">Field assessments in draft</option>
                <option value="returned">Returned for correction</option>
                <option value="submitted">Submitted field assessments</option>
            </select>
            <input v-model="filters.period_start" type="date" aria-label="Report period start">
            <input v-model="filters.period_end" type="date" aria-label="Report period end">
            <button type="button" class="mapr-button mapr-button-secondary" @click="clearFilters">Clear filters</button>
        </form>
        <p class="cdrrmo-muted">{{ payload?.registry_note || 'Resident totals stay available when no incident is selected. The date range applies to report counts.' }}</p>
        <p v-if="error" class="cdrrmo-error" role="alert">{{ error }}</p>
        <p v-if="loading">Loading dashboard…</p>

        <template v-else-if="payload">
            <div class="cdrrmo-stat-grid" aria-label="Registry summary">
                <button type="button" class="cdrrmo-stat-card" @click="router.push({ path: '/residents', query: { barangay_id: filters.barangay_id || undefined } })">
                    <p><MapIcon name="users" /> Registered residents</p>
                    <strong>{{ formatCount(registry?.registered_residents) }}</strong>
                    <span class="cdrrmo-muted">Active records in this registry filter</span>
                </button>
                <article class="cdrrmo-stat-card">
                    <p><MapIcon name="home" /> Total household size</p>
                    <strong>{{ formatCount(registry?.total_household_size) }}</strong>
                    <span class="cdrrmo-muted">{{ registry?.household_size_partial ? `${registry.households_missing_size} households have no reported size` : 'Reported size, once per household' }}</span>
                </article>
                <article v-if="payload.mapped_buildings !== null" class="cdrrmo-stat-card">
                    <p><MapIcon name="building" /> Mapped buildings</p>
                    <strong>{{ formatCount(payload.mapped_buildings) }}</strong>
                    <span class="cdrrmo-muted">Building records with a valid source location</span>
                </article>
                <article class="cdrrmo-stat-card">
                    <p><MapIcon name="user-alert" /> Potentially affected residents</p>
                    <strong v-if="registry?.incident_required">Select an incident</strong>
                    <strong v-else>{{ formatCount(registry?.potentially_affected) }}</strong>
                    <span class="cdrrmo-muted">Incident list, not confirmed harm</span>
                </article>
            </div>

            <div class="cdrrmo-stat-grid" aria-label="Assessment workload">
                <button v-for="card in payload.cards" :key="card.key" type="button" class="cdrrmo-stat-card" @click="openCard(card)">
                    <p><MapIcon :name="workloadIcons[card.key] || 'clipboard'" /> {{ card.label }}</p>
                    <strong>{{ formatCount(card.count) }}</strong>
                </button>
            </div>

            <div class="cdrrmo-dashboard-map">
                <h3 class="cdrrmo-section-title">Assessment map</h3>
                <MapWorkspace embedded :api-base="apiBase" :allow-impact="false" scope-barangays />
            </div>

            <div class="cdrrmo-dashboard-bottom">
                <section class="cdrrmo-review-panel" aria-labelledby="assessor-queue-heading">
                    <header class="cdrrmo-review-header">
                        <div>
                            <h3 id="assessor-queue-heading" class="cdrrmo-section-title">Reports awaiting assessment</h3>
                            <p class="cdrrmo-muted">Initial barangay reports in the study barangays. Editing a field assessment still requires that it be assigned to this account.</p>
                        </div>
                        <router-link class="mapr-button mapr-button-secondary" to="/reports/barangay">All barangay reports</router-link>
                    </header>
                    <p v-if="actionError" class="cdrrmo-error" role="alert">{{ actionError }}</p>
                    <div class="cdrrmo-table-wrap">
                        <table class="cdrrmo-table">
                            <thead>
                                <tr>
                                    <th>Report reference</th>
                                    <th>Incident</th>
                                    <th>Barangay</th>
                                    <th>Submitted by</th>
                                    <th>Received</th>
                                    <th>Status</th>
                                    <th><span class="sr-only">Actions</span></th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr v-if="!payload.review_queue.length"><td colspan="7">No reports match this queue.</td></tr>
                                <tr v-for="row in payload.review_queue" :key="`${row.kind}-${row.id}`">
                                    <td>{{ row.reference }}</td>
                                    <td>{{ row.incident || '—' }}</td>
                                    <td>{{ row.barangay || '—' }}</td>
                                    <td>{{ row.submitted_by }}</td>
                                    <td>{{ formatWhen(row.received_at) }}</td>
                                    <td>{{ row.label || row.status }}</td>
                                    <td class="report-row-actions">
                                        <a class="residents-icon-btn" :href="previewHref(row)" target="_blank" rel="noopener" title="View report" aria-label="View report"><MapIcon name="eye" /></a>
                                        <button v-if="row.kind === 'barangay_report' && !row.draft_id" type="button" class="residents-icon-btn" title="Review and start assessment" aria-label="Review and start assessment" @click="startReview(row)"><MapIcon name="clipboard-check" /></button>
                                        <button v-if="row.draft_id" type="button" class="residents-icon-btn" title="Resume draft" aria-label="Resume draft" @click="resume(row)"><MapIcon name="pencil" /></button>
                                        <button type="button" class="residents-icon-btn" title="Open linked location" aria-label="Open linked location" @click="openLocation(row)"><MapIcon name="pin" /></button>
                                        <div class="residents-menu">
                                            <button type="button" class="residents-icon-btn" title="More actions" aria-label="More actions" :aria-expanded="menuId === row.id" @click.stop="menuId = menuId === row.id ? null : row.id"><MapIcon name="more" /></button>
                                            <div v-if="menuId === row.id" class="residents-menu-panel" role="menu">
                                                <a role="menuitem" :href="previewHref(row)" target="_blank" rel="noopener">Open A4 preview</a>
                                                <a role="menuitem" :href="`${previewHref(row)}?format=pdf`">Download PDF</a>
                                                <button v-if="row.kind === 'field_assessment'" type="button" role="menuitem" @click="router.push(`/assessments/${row.id}`)">Open assessment</button>
                                            </div>
                                        </div>
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </section>

                <aside class="cdrrmo-panel">
                    <h3 class="cdrrmo-section-title">Four-barangay overview</h3>
                    <div class="cdrrmo-table-wrap">
                        <table class="cdrrmo-table">
                            <thead>
                                <tr>
                                    <th>Barangay</th>
                                    <th>Residents</th>
                                    <th>Household population</th>
                                    <th>Mapped buildings</th>
                                    <th>Awaiting review</th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr v-for="row in payload.overview" :key="row.id">
                                    <td>{{ row.name }}</td>
                                    <td>{{ formatCount(row.registered_residents) }}</td>
                                    <td>
                                        {{ formatCount(row.household_population) }}
                                        <span v-if="row.households_missing_size" class="cdrrmo-muted">{{ row.households_missing_size }} missing a size</span>
                                    </td>
                                    <td>{{ row.mapped_buildings === null ? '—' : formatCount(row.mapped_buildings) }}</td>
                                    <td>{{ formatCount(row.reports_awaiting_review) }}</td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                    <h3 class="cdrrmo-section-title">Recent assessment activity</h3>
                    <ul v-if="payload.recent_activity.length" class="cdrrmo-activity-list">
                        <li v-for="(item, index) in payload.recent_activity" :key="index">
                            <strong>{{ item.reference }}</strong>
                            <span>{{ item.status }} · {{ item.barangay || 'Barangay not recorded' }}</span>
                            <time>{{ formatWhen(item.updated_at) }}</time>
                        </li>
                    </ul>
                    <p v-else class="cdrrmo-muted">No field assessments are assigned to this account yet.</p>
                </aside>
            </div>
        </template>
    </section>
</template>
