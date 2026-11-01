<script setup>
import { computed } from 'vue';

const props = defineProps({
    title: { type: String, default: '' },
    userName: { type: String, default: '' },
    jobTitle: { type: String, default: '' },
});
defineEmits(['open-menu']);

const initials = computed(() => {
    const parts = props.userName.trim().split(/\s+/).filter(Boolean);
    return parts.length ? parts.slice(0, 2).map((part) => part[0]?.toUpperCase() || '').join('') : 'MR';
});
</script>

<template>
    <header class="cdrrmo-header">
        <button type="button" class="cdrrmo-menu-btn" aria-label="Open navigation menu" @click="$emit('open-menu')">Menu</button>
        <h1>{{ title }}</h1>
        <div class="field-identity">
            <span class="cdrrmo-avatar" aria-hidden="true">{{ initials }}</span>
            <span>
                <strong>{{ userName }}</strong>
                <small>{{ jobTitle }}</small>
            </span>
        </div>
    </header>
</template>
