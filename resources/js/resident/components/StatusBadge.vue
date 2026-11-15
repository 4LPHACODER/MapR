<script setup>
import Tag from 'primevue/tag';
import { statusClass } from '../format';

defineProps({
    label: { type: String, default: '' },
});

function statusSeverity(label) {
    const severityByClass = {
        'is-draft': 'secondary',
        'is-correction': 'contrast',
        'is-review': 'info',
        'is-assessor': 'warn',
        'is-cdrrmo': 'info',
        'is-final': 'success',
    };

    return severityByClass[statusClass(label)] || 'info';
}
</script>

<template>
    <Tag :value="label" :severity="statusSeverity(label)" rounded class="resident-status-tag" />
</template>
