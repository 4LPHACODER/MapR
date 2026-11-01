<script setup>
import { computed } from 'vue';
import { formatWhen } from '../format';

const props = defineProps({
    report: { type: Object, required: true },
});

const stages = computed(() => {
    const events = props.report.timeline || [];
    const at = (title) => events.find((event) => event.title === title)?.at || '';
    const correction = events.find((event) => event.title === 'Needs correction');
    const submitted = at('Submitted to BDRRM');
    const accepted = at('Accepted by BDRRM');
    const assessor = at('Sent for assessor review');
    const cdrrmo = at('Assessment sent to CDRRMO');
    const finalized = at('Finalized');
    const label = props.report.label;
    const mark = (done, current) => (done ? 'done' : current ? 'current' : 'pending');

    return [
        {
            title: 'Submitted',
            at: submitted,
            state: mark(Boolean(submitted), false),
        },
        {
            title: 'BDRRM Review',
            at: accepted,
            detail: label === 'Needs Correction' ? (correction?.detail || props.report.return_reason) : '',
            state: mark(Boolean(accepted) && label !== 'Needs Correction', label === 'Needs Correction' || (Boolean(submitted) && !accepted)),
        },
        {
            title: 'Assessor Review',
            at: assessor,
            state: mark(Boolean(assessor) && Boolean(cdrrmo || finalized), label === 'Pending Assessor Review'),
        },
        {
            title: 'CDRRMO Review',
            at: cdrrmo,
            state: mark(Boolean(cdrrmo) && label === 'Finalized', label === 'Pending CDRRMO Review'),
        },
        {
            title: 'Finalized',
            at: finalized,
            state: mark(Boolean(finalized), false),
        },
    ];
});
</script>

<template>
    <ol class="resident-stages">
        <li v-for="stage in stages" :key="stage.title" :class="`is-${stage.state}`">
            <strong>{{ stage.title }}</strong>
            <span>{{ stage.state === 'done' ? 'Completed' : stage.state === 'current' ? 'Current' : 'Pending' }}</span>
            <time v-if="stage.at">{{ formatWhen(stage.at) }}</time>
            <p v-if="stage.detail">{{ stage.detail }}</p>
        </li>
    </ol>
</template>
