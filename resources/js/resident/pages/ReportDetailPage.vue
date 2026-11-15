<script setup>
import { computed, inject, onMounted, ref, watch } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import Button from 'primevue/button';
import Message from 'primevue/message';
import MapIcon from '../../shared/components/MapIcon.vue';
import ReportPdfViewer from '../../shared/components/ReportPdfViewer.vue';
import StatusBadge from '../components/StatusBadge.vue';

const apiBase = inject('apiBase');
const route = useRoute();
const router = useRouter();
const report = ref(null);
const error = ref('');
const pdfError = ref('');
const downloading = ref(false);
const menuOpen = ref(false);

const listTo = computed(() => ({ path: '/reports', query: { ...route.query } }));
const trackTo = computed(() => ({ path: `/reports/${route.params.id}/track`, query: { ...route.query } }));

function backToReports() {
    router.push(Object.keys(route.query).length ? listTo.value : '/reports');
}

async function loadReport() {
    error.value = '';
    try {
        report.value = (await window.axios.get(`${apiBase}/reports/${route.params.id}`)).data.data;
    } catch (exception) {
        report.value = null;
        error.value = exception.response?.data?.message || 'This report could not be opened.';
    }
}

const documentUrl = computed(() => (route.params.id ? `${apiBase}/reports/${route.params.id}/document` : ''));

async function downloadPdf() {
    if (downloading.value || !report.value) return;
    downloading.value = true;
    pdfError.value = '';
    try {
        const response = await window.axios.get(`${apiBase}/reports/${report.value.id}/document`, {
            params: { download: 1 },
            responseType: 'blob',
        });
        const type = response.headers['content-type'] || '';
        if (!type.includes('pdf')) throw new Error('The download was not a PDF.');
        const blob = new Blob([response.data], { type: 'application/pdf' });
        const url = URL.createObjectURL(blob);
        const link = document.createElement('a');
        link.href = url;
        link.download = `MapR_Resident_Report_${report.value.reference}.pdf`;
        document.body.appendChild(link);
        link.click();
        link.remove();
        URL.revokeObjectURL(url);
    } catch (exception) {
        pdfError.value = await readError(exception);
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

watch(() => route.params.id, async () => {
    menuOpen.value = false;
    await loadReport();
});

onMounted(loadReport);
</script>

<template>
    <section class="resident-report-view">
        <Message v-if="error" severity="error" :closable="false" class="resident-error" role="alert">{{ error }}</Message>
        <template v-else-if="report">
            <div class="resident-report-toolbar">
                <Button type="button" class="resident-report-back" @click="backToReports">
                    <MapIcon name="chevron" class="resident-back-icon" /> Back to My Reports
                </Button>
                <div class="resident-report-identity">
                    <strong>{{ report.reference }}</strong>
                    <StatusBadge :label="report.label" />
                </div>
                <div class="resident-report-commands">
                    <router-link v-if="report.editable" class="resident-secondary" :to="`/report/${report.id}`">
                        <MapIcon name="pencil" /> Edit Report
                    </router-link>
                    <Button type="button" class="resident-primary" :disabled="downloading" :aria-busy="downloading" @click="downloadPdf">
                        <MapIcon name="download" /> {{ downloading ? 'Preparing PDF…' : 'Download PDF' }}
                    </Button>
                    <Button type="button" text rounded class="resident-report-more" aria-label="More report actions" aria-haspopup="menu" :aria-expanded="menuOpen" @click="menuOpen = !menuOpen">
                        <template #icon><MapIcon name="more" /></template>
                    </Button>
                    <div v-if="menuOpen" class="resident-report-menu" role="menu">
                        <router-link role="menuitem" :to="trackTo" @click="menuOpen = false"><MapIcon name="history" /> Track</router-link>
                    </div>
                </div>
            </div>
            <p v-if="report.edit_message" class="resident-report-lock">{{ report.edit_message }}</p>
            <Message v-if="pdfError" severity="error" :closable="false" class="resident-error" role="alert">{{ pdfError }}</Message>
            <ReportPdfViewer :document-url="documentUrl" @error="pdfError = $event" />
        </template>
        <p v-else>Loading the report…</p>
    </section>
</template>
