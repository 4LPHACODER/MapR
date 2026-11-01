<script setup>
import { statusClass } from '../format';

defineProps({
    label: { type: String, default: '' },
});
</script>

<template>
    <span class="resident-status" :class="statusClass(label)">{{ label }}</span>
</template>
