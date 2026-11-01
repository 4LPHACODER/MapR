<script setup>
import { inject, onMounted, ref } from 'vue';
import SignatureCard from '../../shared/components/SignatureCard.vue';

const apiBase = inject('apiBase');
const profile = ref(null);

onMounted(async () => {
    profile.value = (await window.axios.get(`${apiBase}/profile`)).data;
});
</script>

<template>
    <section v-if="profile">
        <h2>{{ profile.name }}</h2>
        <p>{{ profile.job_title || profile.role }}</p>
        <p>{{ profile.email }}</p>
        <SignatureCard :api-base="apiBase" />
    </section>
</template>
