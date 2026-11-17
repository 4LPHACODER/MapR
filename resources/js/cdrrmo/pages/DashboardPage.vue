<script setup>
import { computed, inject, onBeforeUnmount, reactive, ref, watch } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import Button from 'primevue/button';
import Message from 'primevue/message';
import ProgressSpinner from 'primevue/progressspinner';
import Tag from 'primevue/tag';
import DashboardFilters from '../components/dashboard/DashboardFilters.vue';
import DashboardSummary from '../components/dashboard/DashboardSummary.vue';
import DashboardReviewQueue from '../components/dashboard/DashboardReviewQueue.vue';
import DashboardActivity from '../components/dashboard/DashboardActivity.vue';
import MapIcon from '../../shared/components/MapIcon.vue';
import MapWorkspace from '../../shared/components/MapWorkspace.vue';

const route = useRoute();
const router = useRouter();
const reportContext = inject('reportContext');

const loading = ref(true);
const error = ref('');
const payload = ref(null);
const filterKeys = ['incident_id', 'barangay_id', 'period_start', 'period_end', 'assessment_status'];
const filters = reactive(Object.fromEntries(filterKeys.map((key) => [key, typeof route.query[key] === 'string' ? route.query[key] : ''])));
const preparing = ref(false);
const prepareError = ref('');
const inputMethod = ref('keyboard');
let requestController;

const prepareHint = computed(() => {
    if (!payload.value?.prepare_consolidated?.enabled) {
        return payload.value?.prepare_consolidated?.reason || 'An active disaster incident is required.';
    }
    return reportContext.state.hasPolygon ? '' : 'Draw an area or select a barangay on the map to prepare a report.';
});

const canPrepare = computed(() => payload.value?.prepare_consolidated?.enabled && reportContext.state.hasPolygon);

async function loadDashboard() {
    requestController?.abort();
    const controller = new AbortController();
    requestController = controller;
    loading.value = true;
    error.value = '';
    try {
        const params = Object.fromEntries(Object.entries(filters).filter(([, value]) => value !== '' && value != null));
        const { data } = await window.axios.get('/cdrrmo/api/workspace/dashboard', { params, signal: controller.signal });
        if (controller.signal.aborted) return;
        payload.value = data;
        const knownIds = new Set((data.incidents || []).map((incident) => String(incident.id)));
        if (filters.incident_id && !knownIds.has(String(filters.incident_id))) {
            filters.incident_id = '';
            syncRouteQuery();
        }
    } catch {
        if (!controller.signal.aborted) error.value = 'The disaster assessment dashboard could not load authorized data. Try again.';
    } finally {
        if (!controller.signal.aborted) loading.value = false;
    }
}

function syncRouteQuery() {
    const query = { ...route.query };
    filterKeys.forEach((key) => delete query[key]);
    for (const [key, value] of Object.entries(filters)) {
        if (value) query[key] = value;
    }
    router.replace({ query });
}

function resetFilters() {
    Object.assign(filters, { incident_id: '', barangay_id: '', period_start: '', period_end: '', assessment_status: '' });
}

async function prepareConsolidated() {
    if (!canPrepare.value || preparing.value || loading.value) return;
    preparing.value = true;
    prepareError.value = '';
    try {
        if (await reportContext.storeReportContext()) reportContext.openReportPreview();
    } catch {
        prepareError.value = 'The report could not be prepared. Your selected area is preserved. Try again.';
    } finally {
        preparing.value = false;
    }
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

watch(() => route.query, (query) => {
    filterKeys.forEach((key) => { filters[key] = typeof query[key] === 'string' ? query[key] : ''; });
    loadDashboard();
}, { immediate: true });

watch(filters, syncRouteQuery, { deep: true });
onBeforeUnmount(() => requestController?.abort());
</script>

<template>
    <section
        class="cdrrmo-dashboard"
        :data-input-method="inputMethod"
        :aria-busy="loading"
        aria-labelledby="cdrrmo-dashboard-title"
        @pointerdown.capture="inputMethod = 'pointer'"
        @keydown.capture="inputMethod = 'keyboard'"
    >
        <header class="cdrrmo-dash-heading">
            <div>
                <h2 id="cdrrmo-dashboard-title">Assessment overview</h2>
                <p>Monitor field assessments, inspect locations, and prepare consolidated reports.</p>
            </div>
            <div class="cdrrmo-dash-primary-actions">
                <div class="cdrrmo-dash-heading-actions">
                    <Button type="button" size="small" severity="secondary" outlined :loading="loading" :disabled="loading" label="Refresh" @click="loadDashboard">
                        <template #icon><MapIcon name="refresh" /></template>
                    </Button>
                    <Button type="button" size="small" :loading="preparing" :disabled="!canPrepare || loading || preparing" aria-describedby="cdrrmo-prepare-hint" label="Prepare consolidated report" @click="prepareConsolidated">
                        <template #icon><MapIcon name="file-pen" /></template>
                    </Button>
                </div>
                <p v-show="prepareHint" id="cdrrmo-prepare-hint" class="cdrrmo-dash-prepare-hint">
                    <MapIcon name="alert-circle" />
                    <span>{{ prepareHint }}</span>
                </p>
            </div>
        </header>
        <Message v-if="prepareError" severity="error" :closable="false" size="small">{{ prepareError }}</Message>
        <Message v-if="error" severity="error" :closable="false" size="small">
            <div class="cdrrmo-dash-error">{{ error }}<Button type="button" label="Retry" size="small" severity="danger" text :disabled="loading" @click="loadDashboard" /></div>
        </Message>
        <div v-if="loading && !payload" class="cdrrmo-dash-loading" role="status">
            <ProgressSpinner aria-hidden="true" />
            <span>Loading assessment overview…</span>
        </div>
        <template v-if="payload">
            <DashboardFilters :model-value="filters" @update:model-value="Object.assign(filters, $event)" :incidents="payload.incidents" :barangays="payload.barangays" @reset="resetFilters" />
            <div class="cdrrmo-dash-context">
                <div><MapIcon name="disaster" /><strong>{{ payload.selected_incident?.incident_name || 'All incidents' }}</strong><Tag v-if="payload.selected_incident" :value="payload.selected_incident.incident_status" severity="info" /><span v-if="payload.selected_incident">{{ payload.selected_incident.incident_reference }}</span></div>
                <span role="status">{{ loading ? 'Updating overview…' : `Updated ${formatWhen(payload.generated_at)}` }}</span>
            </div>
            <DashboardSummary :summary="payload.summary" />
            <section class="cdrrmo-dash-panel cdrrmo-dash-map" aria-labelledby="cdrrmo-map-heading">
                <header class="cdrrmo-dash-panel-heading">
                    <div><h3 id="cdrrmo-map-heading">Assessment map</h3><p>Inspect source-recorded locations or select an area for an impact estimate.</p></div>
                    <Button as="router-link" to="/map" size="small" severity="secondary" outlined label="Open full map"><template #icon><MapIcon name="external" /></template></Button>
                </header>
                <MapWorkspace embedded compact />
            </section>
            <div class="cdrrmo-dash-lower">
                <DashboardReviewQueue :reports="payload.reports_awaiting_review" :total="payload.summary.field_reports_awaiting_review?.value || 0" :format-when="formatWhen" />
                <DashboardActivity :items="payload.recent_activity" :format-when="formatWhen" />
            </div>
            <p class="cdrrmo-dash-registry-note"><MapIcon name="shield-check" />{{ payload.registry_note }}</p>
        </template>
    </section>
</template>
