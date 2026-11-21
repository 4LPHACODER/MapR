<script setup>
import { computed, inject, ref, watch } from 'vue';
import { useRoute } from 'vue-router';
import Button from 'primevue/button';
import Message from 'primevue/message';
import MapIcon from '../../shared/components/MapIcon.vue';
import ReportPdfViewer from '../../shared/components/ReportPdfViewer.vue';
import ReviewTimeline from '../components/ReviewTimeline.vue';
import StatusBadge from '../components/StatusBadge.vue';
import { formatWhen } from '../format';

const apiBase = inject('apiBase');
const route = useRoute();
const report = ref(null);
const error = ref('');
const pdfError = ref('');
const downloading = ref(false);

const tracking = computed(() => route.name === 'resident-report-track');
const listTo = computed(() => ({ path: '/reports', query: route.query }));
const reportTo = computed(() => ({ path: `/reports/${route.params.id}`, query: route.query }));
const trackTo = computed(() => ({ path: `${reportTo.value.path}/track`, query: route.query }));
const documentUrl = computed(() => `${apiBase}/reports/${report.value.id}/document`);

const loadAttempt = ref(0);
watch([() => route.params.id, loadAttempt], async ([id], _, onCleanup) => {
    const request = new AbortController();
    onCleanup(() => request.abort());
    report.value = null;
    error.value = '';
    pdfError.value = '';
    try {
        const response = await window.axios.get(`${apiBase}/reports/${id}`, { signal: request.signal });
        report.value = response.data.data;
    } catch (exception) {
        if (request.signal.aborted) return;
        error.value = exception.response?.data?.message || 'This report could not be opened.';
    }
}, { immediate: true });

async function downloadPdf() {
    if (downloading.value || !report.value) return;
    const { id, reference } = report.value;
    downloading.value = true;
    pdfError.value = '';
    try {
        const response = await window.axios.get(`${apiBase}/reports/${id}/document`, {
            params: { download: 1 },
            responseType: 'blob',
        });
        const type = response.headers['content-type'] || '';
        if (!type.includes('pdf')) throw new Error('The download was not a PDF.');
        const blob = new Blob([response.data], { type: 'application/pdf' });
        const url = URL.createObjectURL(blob);
        const link = document.createElement('a');
        link.href = url;
        link.download = `MapR_Resident_Report_${reference}.pdf`;
        document.body.appendChild(link);
        link.click();
        link.remove();
        URL.revokeObjectURL(url);
    } catch (exception) {
        const message = await readError(exception);
        if (report.value?.id === id) pdfError.value = message;
    } finally {
        downloading.value = false;
    }
}

async function readError(exception) {
    const data = exception?.response?.data;
    if (data instanceof Blob) {
        try {
            const parsed = JSON.parse(await data.text());
            return parsed.message || 'The report PDF could not be prepared.';
        } catch {
            return 'The report PDF could not be prepared.';
        }
    }
    return exception?.response?.data?.message || exception?.message || 'The report PDF could not be prepared.';
}
</script>

<template>
    <article class="resident-report-detail">
        <header class="report-detail-header">
            <Button as="router-link" :to="listTo" size="small" text class="report-detail-back" aria-label="Back to My Reports">
                <MapIcon name="chevron" class="resident-back-icon" /> My Reports
            </Button>
            <template v-if="report">
                <div class="report-detail-heading">
                    <p>{{ report.reference }}</p>
                    <StatusBadge :label="report.label" />
                    <h2>{{ report.incident_name || report.incident_type || 'Incident report' }}</h2>
                </div>
                <div class="report-detail-actions">
                    <Button v-if="report.editable" as="router-link" :to="`/report/${report.id}`" size="small" class="report-detail-edit">
                        <MapIcon name="pencil" /> {{ report.status === 'returned' ? 'Revise report' : 'Edit report' }}
                    </Button>
                    <Button type="button" size="small" outlined :loading="downloading" label="Download PDF" @click="downloadPdf">
                        <template v-if="!downloading" #icon><MapIcon name="download" /></template>
                    </Button>
                </div>
            </template>
        </header>

        <div v-if="error" class="report-detail-state">
            <Message severity="error" role="alert">{{ error }}</Message>
            <Button size="small" outlined @click="loadAttempt++">Try again</Button>
        </div>
        <div v-else-if="!report" class="report-detail-state" role="status">Loading the report…</div>
        <template v-else>
            <nav class="report-detail-nav" aria-label="Report views">
                <router-link :to="reportTo" active-class="" exact-active-class="is-current">
                    <MapIcon name="file" /> Report preview
                </router-link>
                <router-link :to="trackTo" active-class="" exact-active-class="is-current">
                    <MapIcon name="history" /> Track progress
                </router-link>
            </nav>

            <Message v-if="pdfError" severity="error" role="alert">{{ pdfError }}</Message>

            <div class="report-detail-layout">
                <section class="report-detail-summary" aria-labelledby="report-details-heading">
                    <h3 id="report-details-heading">Report details</h3>
                    <dl>
                        <div v-if="report.barangay_name">
                            <dt>Barangay</dt>
                            <dd>{{ report.barangay_name }}</dd>
                        </div>
                        <div v-if="report.submitted_at">
                            <dt>Submitted</dt>
                            <dd><time :datetime="report.submitted_at">{{ formatWhen(report.submitted_at) }}</time></dd>
                        </div>
                        <div v-if="report.reported_location" class="report-detail-location">
                            <dt>Reported location</dt>
                            <dd>{{ report.reported_location }}</dd>
                        </div>
                        <div>
                            <dt>Photos attached</dt>
                            <dd>{{ report.photo_count }}</dd>
                        </div>
                    </dl>
                    <p v-if="report.edit_message" class="report-detail-lock">
                        <MapIcon name="lock" /> <span>{{ report.edit_message }}</span>
                    </p>
                </section>

                <section v-if="tracking" class="report-detail-progress" aria-labelledby="report-progress-heading">
                    <h3 id="report-progress-heading">Review progress</h3>
                    <ReviewTimeline :report="report" />
                </section>
                <ReportPdfViewer v-else class="report-detail-preview" presentation="resident" :document-url="documentUrl" />
            </div>
        </template>
    </article>
</template>
