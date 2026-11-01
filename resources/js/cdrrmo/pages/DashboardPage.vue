<script setup>
import { computed, inject, onMounted, reactive, ref, watch } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import MapWorkspace from '../../shared/components/MapWorkspace.vue';

const route = useRoute();
const router = useRouter();
const reportContext = inject('reportContext');

const loading = ref(true);
const error = ref('');
const payload = ref(null);
const filters = reactive({
    incident_id: '',
    barangay_id: '',
    period_start: '',
    period_end: '',
    assessment_status: '',
});

const cardOrder = [
    'reported_buildings',
    'field_verified_assessments',
    'field_reports_awaiting_review',
    'consolidated_reports_awaiting',
];

const prepareHint = computed(() => {
    if (!payload.value?.prepare_consolidated?.enabled) {
        return payload.value?.prepare_consolidated?.reason || 'An active disaster incident is required.';
    }
    return '';
});

const canPrepare = computed(() => payload.value?.prepare_consolidated?.enabled && reportContext.state.hasPolygon);

async function loadDashboard() {
    loading.value = true;
    error.value = '';
    try {
        const params = Object.fromEntries(Object.entries(filters).filter(([, v]) => v !== '' && v != null));
        const { data } = await window.axios.get('/cdrrmo/api/workspace/dashboard', { params });
        payload.value = data;
        const knownIds = new Set((data.incidents || []).map((incident) => String(incident.id)));
        if (filters.incident_id && !knownIds.has(String(filters.incident_id))) {
            filters.incident_id = '';
        }
    } catch {
        error.value = 'The disaster assessment dashboard could not load authorized data.';
    } finally {
        loading.value = false;
    }
}

function syncRouteQuery() {
    const query = {};
    for (const [key, value] of Object.entries(filters)) {
        if (value) query[key] = value;
    }
    router.replace({ query });
}

function resetFilters() {
    Object.assign(filters, { incident_id: '', barangay_id: '', period_start: '', period_end: '', assessment_status: '' });
    syncRouteQuery();
    loadDashboard();
}

async function prepareConsolidated() {
    if (!canPrepare.value) return;
    await reportContext.storeReportContext();
    reportContext.openReportPreview();
}

function formatWhen(value) {
    if (!value) return '—';
    const date = new Date(value);
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

onMounted(() => {
    Object.assign(filters, {
        incident_id: route.query.incident_id || '',
        barangay_id: route.query.barangay_id || '',
        period_start: route.query.period_start || '',
        period_end: route.query.period_end || '',
        assessment_status: route.query.assessment_status || '',
    });
    loadDashboard();
});

watch(filters, () => {
    syncRouteQuery();
    loadDashboard();
}, { deep: true });
</script>

<template>
    <section class="cdrrmo-dashboard">
        <header class="cdrrmo-dashboard-header">
            <div>
                <h2 class="cdrrmo-page-title">Disaster Assessment Dashboard</h2>
                <p class="cdrrmo-page-lead">Review assessments, verify mapped locations, and consolidate resident-impact estimates.</p>
            </div>
            <div class="cdrrmo-dashboard-actions">
                <button
                    type="button"
                    class="mapr-button mapr-button-primary"
                    :disabled="!canPrepare"
                    :title="prepareHint"
                    @click="prepareConsolidated"
                >
                    Prepare Consolidated Report
                </button>
                <p v-if="prepareHint" class="cdrrmo-muted cdrrmo-prepare-hint">{{ prepareHint }}</p>
            </div>
        </header>

        <p v-if="loading">Loading dashboard…</p>
        <p v-else-if="error" class="cdrrmo-error">{{ error }}</p>
        <template v-else-if="payload">
            <div v-if="payload.selected_incident" class="cdrrmo-incident-banner">
                <div>
                    <strong>{{ payload.selected_incident.incident_name }}</strong>
                    <span class="cdrrmo-pill">{{ payload.selected_incident.incident_reference }}</span>
                    <span class="cdrrmo-pill">{{ payload.selected_incident.incident_status }}</span>
                </div>
                <p class="cdrrmo-muted">Last updated {{ formatWhen(payload.generated_at) }}</p>
            </div>

            <form class="cdrrmo-filters" @submit.prevent="loadDashboard">
                <select v-model="filters.incident_id" aria-label="Disaster incident">
                    <option value="">All incidents</option>
                    <option v-for="inc in payload.incidents" :key="inc.id" :value="String(inc.id)">{{ inc.incident_name }}</option>
                </select>
                <select v-model="filters.barangay_id" aria-label="Barangay">
                    <option value="">All four barangays</option>
                    <option v-for="b in payload.barangays" :key="b.id" :value="String(b.id)">{{ b.barangay_name }}</option>
                </select>
                <input v-model="filters.period_start" type="date" aria-label="Reporting period start">
                <input v-model="filters.period_end" type="date" aria-label="Reporting period end">
                <select v-model="filters.assessment_status" aria-label="Assessment status">
                    <option value="">Any assessment status</option>
                    <option value="draft">Draft</option>
                    <option value="review">Under review</option>
                    <option value="signed">Signed</option>
                </select>
                <button type="button" class="mapr-button mapr-button-secondary" @click="resetFilters">Reset filters</button>
                <button type="button" class="mapr-button mapr-button-secondary" @click="loadDashboard">Refresh</button>
            </form>
            <p class="cdrrmo-muted">{{ payload.registry_note }}</p>

            <div class="cdrrmo-stat-grid">
                <article v-for="key in cardOrder" :key="key" class="cdrrmo-stat-card">
                    <p>{{ payload.summary[key]?.label }}</p>
                    <strong>{{ payload.summary[key]?.value ?? 0 }}</strong>
                    <span v-if="payload.summary[key]?.note" class="cdrrmo-muted">{{ payload.summary[key].note }}</span>
                </article>
            </div>

            <div class="cdrrmo-dashboard-map">
                <h3 class="cdrrmo-section-title">Assessment map</h3>
                <MapWorkspace embedded />
            </div>

            <div class="cdrrmo-dashboard-bottom">
                <section class="cdrrmo-review-panel" aria-labelledby="cdrrmo-review-heading">
                    <header class="cdrrmo-review-header">
                        <div>
                            <h3 id="cdrrmo-review-heading" class="cdrrmo-section-title">Reports awaiting CDRRMO review</h3>
                            <p class="cdrrmo-muted">Submitted field assessments, oldest first. Drafts stay with the assessor until they are submitted.</p>
                        </div>
                        <router-link class="mapr-button mapr-button-secondary" to="/reports/field">All field reports</router-link>
                    </header>
                    <p v-if="payload.reports_awaiting_review.length" class="cdrrmo-review-count">
                        {{ payload.summary.field_reports_awaiting_review?.value ?? payload.reports_awaiting_review.length }} waiting
                    </p>
                    <ul v-if="payload.reports_awaiting_review.length" class="cdrrmo-review-list">
                        <li v-for="row in payload.reports_awaiting_review" :key="row.id">
                            <div class="cdrrmo-review-main">
                                <strong>{{ row.assessment_reference || row.report_reference }}</strong>
                                <span class="cdrrmo-status cdrrmo-status-submitted">{{ row.status_label || 'Submitted' }}</span>
                            </div>
                            <p>{{ row.barangay_name }} · {{ row.assessor_name }}</p>
                            <p class="cdrrmo-muted">{{ row.incident_name }} · Barangay report {{ row.report_reference }}</p>
                            <div class="cdrrmo-review-foot">
                                <time :datetime="row.submitted_at || row.updated_at">Submitted {{ formatWhen(row.submitted_at || row.updated_at) }}</time>
                                <router-link :to="{ path: '/reports/field', query: { reference: row.assessment_reference || row.report_reference } }">Open report</router-link>
                            </div>
                        </li>
                    </ul>
                    <p v-else class="cdrrmo-review-empty">No submitted field assessments are waiting for CDRRMO review.</p>
                </section>
                <aside class="cdrrmo-panel cdrrmo-activity-feed">
                    <h3 class="cdrrmo-section-title">Recent activity</h3>
                    <ul v-if="payload.recent_activity.length" class="cdrrmo-activity-list">
                        <li v-for="(item, index) in payload.recent_activity" :key="index">
                            <strong>{{ item.actor_name }}</strong>
                            <span>{{ item.summary }}</span>
                            <time>{{ formatWhen(item.created_at) }}</time>
                        </li>
                    </ul>
                    <p v-else class="cdrrmo-muted">No recent activity has been recorded yet.</p>
                </aside>
            </div>
        </template>
    </section>
</template>
