<script setup>
import MapIcon from '../../shared/components/MapIcon.vue';

defineProps({
    icon: { type: String, default: 'file' },
    title: { type: String, required: true },
});
</script>

<template>
    <div class="resident-empty">
        <span class="resident-summary-icon" aria-hidden="true"><MapIcon :name="icon" /></span>
        <p>{{ title }}</p>
        <slot />
    </div>
</template>
