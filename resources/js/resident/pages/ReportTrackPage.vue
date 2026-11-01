<script setup>
import { inject, onMounted, ref } from 'vue';
import { useRoute } from 'vue-router';
import StatusBadge from '../components/StatusBadge.vue';
import ReviewTimeline from '../components/ReviewTimeline.vue';

const apiBase = inject('apiBase');
const route = useRoute();
const report = ref(null);
const error = ref('');

onMounted(async () => {
    try {
        report.value = (await window.axios.get(`${apiBase}/reports/${route.params.id}`)).data.data;
    } catch (exception) {
        error.value = exception.response?.data?.message || 'This report could not be opened.';
    }
});
</script>

<template>
    <article v-if="report" class="resident-detail resident-stack">
        <header class="resident-card">
            <div>
                <h2>{{ report.reference }}</h2>
                <StatusBadge :label="report.label" />
            </div>
            <router-link class="resident-secondary" :to="{ path: `/reports/${report.id}`, query: route.query }">View report</router-link>
        </header>
        <section class="resident-card">
            <h3>Progress</h3>
            <ReviewTimeline :report="report" />
        </section>
        <p v-if="report.status === 'returned'" class="resident-card-actions">
            <router-link v-if="report.editable" class="resident-primary" :to="`/report/${report.id}`">Revise Report</router-link>
        </p>
    </article>
    <p v-else-if="error" class="resident-error">{{ error }}</p>
    <p v-else>Loading the timeline…</p>
</template>
