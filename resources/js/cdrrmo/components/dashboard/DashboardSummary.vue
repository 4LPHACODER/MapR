<script setup>
import MapIcon from '../../../shared/components/MapIcon.vue';
defineProps({ summary: { type: Object, required: true } });
const cards = [
    { key: 'reported_buildings', icon: 'building', scope: 'Incident-linked', tone: 'blue' },
    { key: 'field_verified_assessments', icon: 'pin-check', scope: 'Across the registry', tone: 'green' },
    { key: 'field_reports_awaiting_review', icon: 'clipboard-list', scope: 'Submitted for review', tone: 'amber' },
    { key: 'consolidated_reports_awaiting', icon: 'files', scope: 'Across all incidents', tone: 'navy' },
];
const number = new Intl.NumberFormat('en-PH');
</script>

<template>
    <dl class="cdrrmo-dash-summary" aria-label="Assessment totals">
        <div v-for="card in cards" :key="card.key" class="cdrrmo-dash-metric" :class="`is-${card.tone}`">
            <dt><span class="cdrrmo-dash-metric-icon"><MapIcon :name="card.icon" /></span><span>{{ summary[card.key]?.label }}</span></dt>
            <dd><strong>{{ number.format(summary[card.key]?.value ?? 0) }}</strong><p>{{ summary[card.key]?.note || card.scope }}</p></dd>
        </div>
    </dl>
</template>
