<script setup>
import { ref } from 'vue';
import MapIcon from '../../../shared/components/MapIcon.vue';

const props = defineProps({
    history: { type: Array, default: () => [] },
    filter: { type: String, default: 'all' },
    formatWhen: { type: Function, required: true },
});

const emit = defineEmits(['update:filter']);
const expanded = ref(null);

const filters = [
    { id: 'all', label: 'All' },
    { id: 'assessment', label: 'Assessment' },
    { id: 'verification', label: 'Verification' },
    { id: 'review', label: 'Review' },
    { id: 'export', label: 'Export' },
    { id: 'submission', label: 'Submission' },
];

function iconFor(action) {
    const key = String(action || '').toLowerCase();
    if (key.includes('export')) return 'file-spreadsheet';
    if (key.includes('submit') || key.includes('forward') || key.includes('package')) return 'send';
    if (key.includes('verif')) return 'shield-check';
    if (key.includes('approv') || key.includes('review')) return 'circle-check';
    if (key.includes('return')) return 'rotate-ccw';
    if (key.includes('assess')) return 'clipboard-check';
    return 'history';
}

function labelFor(action) {
    const raw = String(action || 'Activity');
    return raw
        .replace(/_/g, ' ')
        .replace(/\b\w/g, (char) => char.toUpperCase());
}

function toggle(id) {
    expanded.value = expanded.value === id ? null : id;
}
</script>

<template>
    <section id="field-reports-history" class="field-panel" aria-label="Report history">
        <header class="field-section-heading field-section-heading--split">
            <div>
                <h2>Report History</h2>
                <p>Persisted verification, review, consolidation, and CDRRMO submission activity.</p>
            </div>
            <div class="field-tabs" role="tablist">
                <button
                    v-for="item in filters"
                    :key="item.id"
                    type="button"
                    role="tab"
                    :aria-selected="filter === item.id"
                    @click="emit('update:filter', item.id)"
                >{{ item.label }}</button>
            </div>
        </header>

        <ol v-if="history.length" class="field-history">
            <li v-for="event in history" :key="event.id">
                <span class="field-history__icon" aria-hidden="true">
                    <MapIcon :name="iconFor(event.action_type)" />
                </span>
                <div class="field-history__body">
                    <button type="button" class="field-history__toggle" @click="toggle(event.id)">
                        <strong>{{ labelFor(event.action_type) }}</strong>
                        <span>{{ event.actor_name || 'System' }} · {{ formatWhen(event.created_at) }}</span>
                    </button>
                    <p v-if="expanded === event.id || !event.summary">{{ event.summary || 'No additional remarks recorded.' }}</p>
                    <p v-else class="field-history__preview">{{ event.summary }}</p>
                </div>
            </li>
        </ol>
        <div v-else class="field-empty field-empty--compact">
            <MapIcon name="history" />
            <strong>No history events</strong>
            <span>Activity will appear here after assessments are verified, reviewed, exported, or submitted.</span>
        </div>
    </section>
</template>
