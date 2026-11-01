<script setup>
import { inject, onMounted, ref } from 'vue';
import { useRouter } from 'vue-router';

const apiBase = inject('apiBase');
const router = useRouter();
const cards = ref([]);
const error = ref('');

onMounted(load);

async function load() {
    try {
        cards.value = (await window.axios.get(`${apiBase}/summary`)).data.cards || [];
    } catch (exception) {
        error.value = exception.response?.data?.message || 'The workspace summary could not be loaded.';
    }
}

function openCard(card) {
    const path = {
        submissions: '/submissions',
        drafts: '/reports/drafts',
        returned: '/reports/returned',
        submitted: '/reports/submitted',
    }[card.key];
    if (path) router.push(path);
}
</script>

<template>
    <section class="field-dashboard">
        <h2 class="cdrrmo-page-title">BDRRM Dashboard</h2>
        <p class="cdrrmo-page-lead">Assigned work only. Counts come from reports you are allowed to open.</p>
        <p v-if="error" class="cdrrmo-error">{{ error }}</p>
        <div class="cdrrmo-stat-grid">
            <button v-for="card in cards" :key="card.key" type="button" class="cdrrmo-stat-card" @click="openCard(card)">
                <p>{{ card.label }}</p>
                <strong>{{ card.count }}</strong>
            </button>
        </div>
        <p class="cdrrmo-actions">
            <router-link class="mapr-button mapr-button-primary" to="/map">Open map</router-link>
        </p>
    </section>
</template>
