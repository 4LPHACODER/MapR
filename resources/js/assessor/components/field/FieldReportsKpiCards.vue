<script setup>
import Skeleton from 'primevue/skeleton';
import MapIcon from '../../../shared/components/MapIcon.vue';

defineProps({
    kpis: { type: Object, default: () => ({}) },
    loading: { type: Boolean, default: false },
    formatWhen: { type: Function, required: true },
});
</script>

<template>
    <div v-if="loading" class="field-kpi-grid" aria-busy="true">
        <Skeleton v-for="n in 4" :key="n" height="7.5rem" border-radius="0.9rem" />
    </div>
    <div v-else class="field-kpi-grid" aria-label="Report overview">
        <article class="field-kpi-card">
            <header>
                <span class="field-kpi-icon" aria-hidden="true"><MapIcon name="clipboard-check" /></span>
                <span>Reviewed Reports</span>
            </header>
            <strong>{{ kpis.reviewed_reports || 0 }}</strong>
            <p v-if="!(kpis.reviewed_reports > 0)" class="field-kpi-detail">No reviewed assessments available</p>
            <p v-else class="field-kpi-detail">
                {{ kpis.barangays_represented || 0 }} barangay{{ (kpis.barangays_represented || 0) === 1 ? '' : 's' }} represented
            </p>
            <footer v-if="kpis.awaiting_consolidation > 0" class="field-kpi-footer">
                <span class="field-chip">{{ kpis.awaiting_consolidation }} awaiting consolidation</span>
            </footer>
        </article>

        <article class="field-kpi-card">
            <header>
                <span class="field-kpi-icon" aria-hidden="true"><MapIcon name="folder-open" /></span>
                <span>Consolidated Reports</span>
            </header>
            <strong>{{ kpis.packages_generated || 0 }}</strong>
            <p v-if="!(kpis.packages_generated > 0)" class="field-kpi-detail">No packages generated yet</p>
            <p v-else class="field-kpi-detail">Last consolidation {{ formatWhen(kpis.last_consolidation_at) }}</p>
            <footer v-if="kpis.packages_ready_for_submission > 0" class="field-kpi-footer">
                <span class="field-chip">{{ kpis.packages_ready_for_submission }} ready for submission</span>
            </footer>
        </article>

        <article class="field-kpi-card">
            <header>
                <span class="field-kpi-icon" aria-hidden="true"><MapIcon name="users-round" /></span>
                <span>Resident Submissions</span>
            </header>
            <strong>{{ kpis.resident_submissions || 0 }}</strong>
            <p v-if="!(kpis.resident_submissions > 0)" class="field-kpi-detail">No linked resident submissions</p>
            <p v-else class="field-kpi-detail">
                {{ kpis.resident_complete || 0 }} complete · {{ kpis.resident_needing_review || 0 }} needing review
            </p>
        </article>

        <article class="field-kpi-card">
            <header>
                <span class="field-kpi-icon" aria-hidden="true"><MapIcon name="send" /></span>
                <span>Submitted to CDRRMO</span>
            </header>
            <strong>{{ kpis.packages_submitted || 0 }}</strong>
            <p v-if="!(kpis.packages_submitted > 0)" class="field-kpi-detail">No packages submitted yet</p>
            <p v-else class="field-kpi-detail">Last submission {{ formatWhen(kpis.last_submission_at) }}</p>
            <footer v-if="kpis.packages_pending_ack > 0" class="field-kpi-footer">
                <span class="field-chip field-chip--warn">{{ kpis.packages_pending_ack }} pending acknowledgement</span>
            </footer>
        </article>
    </div>
</template>
