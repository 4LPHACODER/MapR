<script setup>
import MapIcon from '../../shared/components/MapIcon.vue';

defineProps({
    label: { type: String, required: true },
    count: { type: Number, default: 0 },
    icon: { type: String, required: true },
    accent: { type: String, default: 'blue' },
});
</script>

<template>
    <article class="resident-summary" :class="`is-${accent}`">
        <span class="resident-summary-icon" aria-hidden="true"><MapIcon :name="icon" /></span>
        <span>{{ label }}</span>
        <strong>{{ count }}</strong>
    </article>
</template>
