<script setup>
import { computed, inject, nextTick, onMounted, ref, watch } from 'vue';
import { useRouter } from 'vue-router';
import Button from 'primevue/button';
import Dialog from 'primevue/dialog';
import Message from 'primevue/message';
import MapIcon from '../../shared/components/MapIcon.vue';
import FieldAssessmentMap from '../components/field/FieldAssessmentMap.vue';
import FieldBarangayPanel from '../components/field/FieldBarangayPanel.vue';
import FieldConsolidationPanel from '../components/field/FieldConsolidationPanel.vue';
import FieldReportsHistory from '../components/field/FieldReportsHistory.vue';
import FieldReportsKpiCards from '../components/field/FieldReportsKpiCards.vue';
import FieldReportsTable from '../components/field/FieldReportsTable.vue';
import '../../../css/rdana/field-reports.css';

const apiBase = inject('apiBase');
const router = useRouter();
const loading = ref(true);
const error = ref('');
const notice = ref('');
const dashboard = ref(null);
const reports = ref([]);
const history = ref([]);
const mapData = ref({ boundaries: { type: 'FeatureCollection', features: [] }, markers: { type: 'FeatureCollection', features: [] } });
const tab = ref('ready');
const search = ref('');
const historyFilter = ref('all');
const selectedBarangayId = ref(null);
const overviewMap = ref(null);
const submitOpen = ref(false);
const submitBusy = ref(false);
const submitError = ref('');
const submitPreview = ref(null);
const submitScope = ref('consolidated');
const submitBarangayId = ref(null);
const exporting = ref(false);
const previewOpen = ref(false);

const kpis = computed(() => dashboard.value?.kpis || {});
const barangays = computed(() => dashboard.value?.barangays || []);
const exports = computed(() => dashboard.value?.exports || { consolidated: null, barangays: [] });
const canExport = computed(() => !!dashboard.value?.can_export);
const canSubmit = computed(() => !!dashboard.value?.can_submit);
const canSubmitPackage = computed(() => canSubmit.value && (kpis.value.awaiting_consolidation || 0) > 0);

async function loadAll() {
    loading.value = true;
    error.value = '';
    try {
        const [dash, map, reportRows, historyRows] = await Promise.all([
            window.axios.get(`${apiBase}/field-consolidation`),
            window.axios.get(`${apiBase}/field-consolidation/map`, { params: selectedBarangayId.value ? { barangay_id: selectedBarangayId.value } : {} }),
            window.axios.get(`${apiBase}/field-consolidation/reports`, { params: { tab: tab.value, search: search.value || undefined, barangay_id: selectedBarangayId.value || undefined } }),
            window.axios.get(`${apiBase}/field-consolidation/history`, { params: { filter: historyFilter.value, barangay_id: selectedBarangayId.value || undefined } }),
        ]);
        dashboard.value = dash.data.data;
        mapData.value = map.data.data;
        reports.value = reportRows.data.data || [];
        history.value = historyRows.data.data || [];
    } catch (exception) {
        error.value = exception.response?.data?.message || 'Field Assessment Reports could not be loaded.';
    } finally {
        loading.value = false;
    }
}

async function refreshReports() {
    const { data } = await window.axios.get(`${apiBase}/field-consolidation/reports`, {
        params: { tab: tab.value, search: search.value || undefined, barangay_id: selectedBarangayId.value || undefined },
    });
    reports.value = data.data || [];
}

async function refreshHistory() {
    const { data } = await window.axios.get(`${apiBase}/field-consolidation/history`, {
        params: { filter: historyFilter.value, barangay_id: selectedBarangayId.value || undefined },
    });
    history.value = data.data || [];
}

async function refreshMap() {
    const { data } = await window.axios.get(`${apiBase}/field-consolidation/map`, {
        params: selectedBarangayId.value ? { barangay_id: selectedBarangayId.value } : {},
    });
    mapData.value = data.data;
}

function statusSeverity(status) {
    if (status === 'submitted' || status === 'forwarded_cdrrmo') return 'success';
    if (status === 'ready' || status === 'ready_for_consolidation') return 'info';
    return 'secondary';
}

function formatWhen(value) {
    if (!value) return 'Not Recorded';
    try {
        return new Intl.DateTimeFormat(undefined, { dateStyle: 'medium', timeStyle: 'short' }).format(new Date(value));
    } catch {
        return value;
    }
}

function scrollTo(id) {
    document.getElementById(id)?.scrollIntoView({ behavior: 'smooth', block: 'start' });
}

function viewReports(barangayId) {
    selectedBarangayId.value = barangayId;
    tab.value = 'ready';
    nextTick(() => scrollTo('field-reports-table'));
}

function viewMap(barangayId) {
    selectedBarangayId.value = barangayId;
    nextTick(() => {
        refreshMap();
        scrollTo('field-assessment-map');
        overviewMap.value?.resetView?.();
    });
}

async function downloadExcel(scope, barangayId = null) {
    if (!canExport.value) return;
    exporting.value = true;
    notice.value = '';
    error.value = '';
    try {
        const response = await window.axios.get(`${apiBase}/field-consolidation/export`, {
            params: { scope, barangay_id: barangayId || undefined },
            responseType: 'blob',
        });
        const blob = new Blob([response.data], { type: response.headers['content-type'] });
        const url = URL.createObjectURL(blob);
        const anchor = document.createElement('a');
        const disposition = response.headers['content-disposition'] || '';
        const match = disposition.match(/filename="?([^"]+)"?/i);
        anchor.href = url;
        anchor.download = match?.[1] || (scope === 'consolidated' ? 'MapR_Consolidated_Field_Assessment_Reports.xlsx' : 'MapR_Field_Assessment.xlsx');
        anchor.click();
        URL.revokeObjectURL(url);
        notice.value = 'Excel workbook downloaded.';
        await loadAll();
    } catch (exception) {
        error.value = exception.response?.data?.message || 'The Excel workbook could not be generated.';
    } finally {
        exporting.value = false;
    }
}

async function openSubmit(scope, barangayId = null) {
    submitScope.value = scope;
    submitBarangayId.value = barangayId;
    submitError.value = '';
    submitPreview.value = null;
    submitOpen.value = true;
    try {
        const { data } = await window.axios.post(`${apiBase}/field-consolidation/submit/preview`, {
            scope,
            barangay_id: barangayId || undefined,
        });
        submitPreview.value = data.data;
    } catch (exception) {
        submitError.value = exception.response?.data?.message || 'Submission preview failed.';
    }
}

async function confirmSubmit() {
    submitBusy.value = true;
    submitError.value = '';
    try {
        const { data } = await window.axios.post(`${apiBase}/field-consolidation/submit`, {
            scope: submitScope.value,
            barangay_id: submitBarangayId.value || undefined,
            confirmed: 1,
        });
        submitOpen.value = false;
        notice.value = `Submitted package ${data.data.package_reference} to CDRRMO.`;
        await loadAll();
    } catch (exception) {
        submitError.value = exception.response?.data?.message || 'Submission failed.';
    } finally {
        submitBusy.value = false;
    }
}

function openAssessment(id) {
    router.push(`/assessments/${id}`);
}

function mapForBarangay(barangayId) {
    return {
        boundaries: {
            type: 'FeatureCollection',
            features: (mapData.value.boundaries?.features || []).filter((feature) => Number(feature.properties?.barangay_id) === Number(barangayId)),
        },
        markers: {
            type: 'FeatureCollection',
            features: (mapData.value.markers?.features || []).filter((feature) => Number(feature.properties?.barangay_id) === Number(barangayId)),
        },
    };
}

function resetOverview() {
    selectedBarangayId.value = null;
    nextTick(() => overviewMap.value?.resetView?.());
}

onMounted(loadAll);
watch([tab, selectedBarangayId], refreshReports);
watch(historyFilter, refreshHistory);
watch(selectedBarangayId, refreshMap);
</script>

<template>
    <section class="field-reports" aria-label="Field Assessment Reports">
        <header class="field-reports-header">
            <div>
                <h1>Field Assessment Reports</h1>
                <p>Consolidate reviewed barangay assessments, manage official reports, and submit verified documentation to CDRRMO.</p>
            </div>
            <div class="field-reports-toolbar" role="toolbar" aria-label="Field assessment actions">
                <Button type="button" size="small" severity="secondary" outlined :loading="loading" @click="loadAll">
                    <template #icon><MapIcon name="refresh" /></template>
                    Refresh
                </Button>
                <Button type="button" size="small" severity="secondary" outlined @click="scrollTo('field-reports-history')">
                    <template #icon><MapIcon name="history" /></template>
                    View History
                </Button>
                <Button type="button" size="small" severity="secondary" outlined :disabled="!canExport" :loading="exporting" @click="downloadExcel('consolidated')">
                    <template #icon><MapIcon name="file-spreadsheet" /></template>
                    Export Excel
                </Button>
                <Button type="button" size="small" class="field-toolbar-primary" :disabled="!canSubmitPackage" @click="openSubmit('consolidated')">
                    <template #icon><MapIcon name="send" /></template>
                    Submit to CDRRMO
                </Button>
            </div>
        </header>

        <Message v-if="notice" severity="success" :closable="false">{{ notice }}</Message>
        <Message v-if="error" severity="error" :closable="false">{{ error }}</Message>

        <FieldReportsKpiCards :kpis="kpis" :loading="loading" :format-when="formatWhen" />

        <section class="field-panel" aria-label="Barangay Assessment">
            <header class="field-section-heading">
                <h2>Barangay Assessment</h2>
                <p>View and manage reviewed field assessment reports across Bag-ong Lungsod, Bungtod, Mabua, and Telaje.</p>
            </header>
            <div class="field-barangay-grid">
                <FieldBarangayPanel
                    v-for="card in barangays"
                    :key="card.barangay_name"
                    :card="card"
                    :map-payload="mapForBarangay(card.barangay_id)"
                    :can-export="canExport"
                    :can-submit="canSubmit"
                    :exporting="exporting"
                    :format-when="formatWhen"
                    @view-reports="viewReports"
                    @download-excel="downloadExcel('barangay', $event)"
                    @view-map="viewMap"
                    @submit="openSubmit('barangay', $event)"
                />
            </div>
        </section>

        <section id="field-assessment-map" class="field-panel" aria-label="Geographic overview">
            <header class="field-section-heading field-section-heading--split">
                <div>
                    <h2>Geographic Overview</h2>
                    <p>Map of reviewed assessment records across the four selected barangays.</p>
                </div>
                <div class="field-reports-toolbar">
                    <Button type="button" size="small" severity="secondary" outlined label="Reset View" @click="resetOverview" />
                    <Button
                        type="button"
                        size="small"
                        severity="secondary"
                        outlined
                        label="Open Full Map"
                        @click="router.push({ path: '/map', query: selectedBarangayId ? { barangay_id: selectedBarangayId } : {} })"
                    />
                </div>
            </header>
            <FieldAssessmentMap
                ref="overviewMap"
                class="field-overview-map"
                height="380px"
                :boundaries="mapData.boundaries"
                :markers="mapData.markers"
                :selected-barangay-id="selectedBarangayId"
                @select-barangay="selectedBarangayId = $event"
                @select-assessment="openAssessment"
            />
        </section>

        <FieldConsolidationPanel
            :exports="exports"
            :can-export="canExport"
            :can-submit-package="canSubmitPackage"
            :exporting="exporting"
            :format-when="formatWhen"
            @export-consolidated="downloadExcel('consolidated')"
            @export-barangay="downloadExcel('barangay', $event)"
            @preview="previewOpen = true"
            @view-history="scrollTo('field-reports-history')"
            @submit="openSubmit('consolidated')"
        />

        <FieldReportsTable
            :reports="reports"
            :tab="tab"
            :search="search"
            :selected-barangay-id="selectedBarangayId"
            :loading="loading"
            :format-when="formatWhen"
            :status-severity="statusSeverity"
            @update:tab="tab = $event"
            @update:search="search = $event"
            @search="refreshReports"
            @clear-barangay="selectedBarangayId = null"
            @open="openAssessment"
        />

        <FieldReportsHistory
            :history="history"
            :filter="historyFilter"
            :format-when="formatWhen"
            @update:filter="historyFilter = $event"
        />

        <Dialog v-model:visible="previewOpen" modal header="Export Preview" :style="{ width: 'min(32rem, 96vw)' }">
            <dl class="field-meta-list">
                <div><dt>Coverage</dt><dd>Bag-ong Lungsod, Bungtod, Mabua, Telaje</dd></div>
                <div><dt>Eligible reports</dt><dd>{{ exports.consolidated?.eligible_count || 0 }}</dd></div>
                <div><dt>Barangay workbooks</dt><dd>{{ exports.barangays?.length || 0 }}</dd></div>
                <div><dt>Last consolidated export</dt><dd>{{ formatWhen(exports.consolidated?.generated_at) }}</dd></div>
                <div><dt>Awaiting consolidation</dt><dd>{{ kpis.awaiting_consolidation || 0 }}</dd></div>
            </dl>
            <p class="field-kpi-detail" style="margin-top:0.75rem">
                Preview uses the current eligible snapshot. Generate Excel to download the official workbook.
            </p>
            <template #footer>
                <Button type="button" size="small" severity="secondary" text label="Close" @click="previewOpen = false" />
                <Button type="button" size="small" label="Export Excel" :disabled="!canExport" :loading="exporting" @click="previewOpen = false; downloadExcel('consolidated')" />
            </template>
        </Dialog>

        <Dialog v-model:visible="submitOpen" modal header="Submit Field Assessment Report" :style="{ width: 'min(32rem, 96vw)' }" :closable="!submitBusy">
            <div v-if="!submitPreview && !submitError" class="field-empty field-empty--compact">Preparing submission preview…</div>
            <Message v-if="submitError" severity="error" :closable="false">{{ submitError }}</Message>
            <dl v-if="submitPreview" class="field-meta-list">
                <div><dt>Coverage</dt><dd>{{ submitPreview.barangay_name }}</dd></div>
                <div><dt>Included reports</dt><dd>{{ submitPreview.assessment_count }}</dd></div>
                <div><dt>Resident submissions</dt><dd>{{ submitPreview.resident_submission_count }}</dd></div>
                <div><dt>Latest verification</dt><dd>{{ formatWhen(submitPreview.latest_verification_at) }}</dd></div>
                <div><dt>Latest review</dt><dd>{{ formatWhen(submitPreview.latest_review_at) }}</dd></div>
                <div><dt>Destination</dt><dd>{{ submitPreview.destination }}</dd></div>
            </dl>
            <template #footer>
                <Button type="button" size="small" severity="secondary" text label="Cancel" :disabled="submitBusy" @click="submitOpen = false" />
                <Button type="button" size="small" label="Submit to CDRRMO" :loading="submitBusy" :disabled="!submitPreview" @click="confirmSubmit" />
            </template>
        </Dialog>
    </section>
</template>
