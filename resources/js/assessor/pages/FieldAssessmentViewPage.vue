<script setup>
import { computed, inject, onMounted, ref } from 'vue';
import { useRoute } from 'vue-router';
import { RouterLink } from 'vue-router';
import ReportPdfViewer from '../../shared/components/ReportPdfViewer.vue';

const apiBase = inject('apiBase');
const route = useRoute();
const assessment = ref(null);
const phase = ref('loading');
const error = ref('');
const id = computed(() => String(route.params.id || ''));
const documentUrl = computed(() => `${apiBase}/assessments/${id.value}/preview?format=pdf&inline=1`);

onMounted(async () => {
    try {
        const response = await window.axios.get(`${apiBase}/api/assessments/${id.value}`);
        assessment.value = response.data.data || response.data;
        phase.value = 'ready';
    } catch (exception) {
        error.value = exception.response?.data?.message || 'This field assessment could not be opened.';
        phase.value = 'error';
    }
});
</script>

<template>
    <section class="bdrrm-report-view">
        <p v-if="phase === 'loading'">Loading field assessment…</p>
        <p v-else-if="phase === 'error'">{{ error }}</p>
        <template v-else>
            <header class="report-view-toolbar">
                <RouterLink to="/reports/field">Back to Field Assessments</RouterLink>
                <h1>{{ assessment.reference }}</h1>
                <p>{{ assessment.label }}</p>
                <a :href="`${apiBase}/assessments/${id}/preview?format=pdf`">Download PDF</a>
                <RouterLink :to="`/assessments/${id}`">Edit</RouterLink>
            </header>
            <div class="report-view-layout">
                <ReportPdfViewer :document-url="documentUrl" />
                <aside>
                    <p><strong>Status</strong><br>{{ assessment.label }}</p>
                    <p v-if="assessment.barangay_report"><strong>Source report</strong><br>{{ assessment.barangay_report.reference }}</p>
                    <p v-if="assessment.incident"><strong>Incident</strong><br>{{ assessment.incident.name }}</p>
                    <p v-if="assessment.building"><strong>Building</strong><br>{{ assessment.building.reference }}</p>
                    <p v-if="assessment.household"><strong>Household</strong><br>{{ assessment.household.reference }}</p>
                    <p><strong>Open flags</strong><br>{{ assessment.open_flags || 0 }}</p>
                </aside>
            </div>
        </template>
    </section>
</template>
