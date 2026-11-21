<script setup>
import { ref, watch } from 'vue';

const props = defineProps({
    name: { type: String, default: '' },
    src: { type: String, default: '' },
});

const failed = ref(false);
const initials = () => {
    const parts = String(props.name || '').trim().split(/\s+/).filter(Boolean);
    return parts.length ? parts.slice(0, 2).map((part) => part[0]?.toUpperCase() || '').join('') : 'R';
};

watch(() => props.src, () => {
    failed.value = false;
});
</script>

<template>
    <span class="resident-avatar">
        <img v-if="src && !failed" :src="src" alt="" @error="failed = true">
        <template v-else>{{ initials() }}</template>
    </span>
</template>
