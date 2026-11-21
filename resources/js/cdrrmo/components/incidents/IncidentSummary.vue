<script setup>
import Button from 'primevue/button';
import Message from 'primevue/message';
import MapIcon from '../../../shared/components/MapIcon.vue';
defineProps({ summary: { type: Object, default: null }, loading: Boolean, error: { type: String, default: '' } });
defineEmits(['retry']);
const metrics = [
    { key: 'total', label: 'Total incidents', icon: 'disaster', hint: 'Recorded disaster events' },
    { key: 'active', label: 'Active incidents', icon: 'pin-check', hint: 'Open for incident reporting' },
    { key: 'closed', label: 'Closed incidents', icon: 'history', hint: 'Retained in the incident record' },
];
</script>

<template>
    <section class="incident-overview" aria-label="Incident summary" :aria-busy="loading">
        <dl class="incident-metrics">
            <div v-for="metric in metrics" :key="metric.key" class="incident-metric" :class="`is-${metric.key}`">
                <dt><span class="incident-metric-icon"><MapIcon :name="metric.icon" /></span>{{ metric.label }}</dt>
                <dd>{{ loading ? '…' : error ? 'N/A' : summary?.counts?.[metric.key]?.toLocaleString('en-PH') ?? '…' }}</dd>
                <p>{{ metric.hint }}</p>
            </div>
        </dl>
        <div class="incident-summary-scope">
            <Message v-if="error" severity="error" size="small" :closable="false"><span>{{ error }}</span> <Button label="Retry" size="small" text @click="$emit('retry')" /></Message>
            <p v-else><MapIcon name="alert-circle" />{{ loading ? 'Loading incident summary…' : summary?.scope_note }}</p>
        </div>
    </section>
</template>
