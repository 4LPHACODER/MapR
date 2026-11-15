<script setup>
import { computed } from 'vue';
import { useRoute } from 'vue-router';
import SubmissionDetailPage from './SubmissionDetailPage.vue';

const route = useRoute();
const residentReport = computed(() => route.query.report_type === 'resident'
    && typeof route.query.report_id === 'string'
    && route.query.report_id !== '');
</script>

<template>
    <SubmissionDetailPage v-if="residentReport" />
    <section v-else class="bdrrm-submissions">
        <h2 class="cdrrmo-page-title">Reports</h2>
        <p class="cdrrmo-page-lead">Open a report queue, or return here from a selected resident report.</p>
        <p><router-link to="/reports/drafts">Draft reports</router-link></p>
        <p><router-link to="/reports/returned">Returned for correction</router-link></p>
        <p><router-link to="/reports/submitted">Submitted reports</router-link></p>
    </section>
</template>
