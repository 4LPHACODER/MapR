<script setup>
import { inject, onMounted, ref, watch } from 'vue';
import { useRoute } from 'vue-router';
import MapIcon from '../../shared/components/MapIcon.vue';
import ResidentAvatar from './ResidentAvatar.vue';
import ResidentReportButton from './ResidentReportButton.vue';
import { residentAccount } from '../accountState';

defineProps({
    title: { type: String, default: '' },
    userName: { type: String, default: '' },
});

const apiBase = inject('apiBase');
const route = useRoute();
const notesOpen = ref(false);
const accountOpen = ref(false);
const attention = ref([]);
async function load() {
    try {
        attention.value = (await window.axios.get(`${apiBase}/dashboard`)).data.attention || [];
    } catch {
        attention.value = [];
    }
}

onMounted(load);
watch(() => route.fullPath, () => {
    notesOpen.value = false;
    accountOpen.value = false;
    load();
});
</script>

<template>
    <header class="resident-header">
        <h1>{{ title }}</h1>
        <div class="resident-header-tools">
            <ResidentReportButton />
            <div class="resident-notify">
                <button type="button" aria-label="Notifications" :aria-expanded="notesOpen" @click="notesOpen = !notesOpen; accountOpen = false">
                    <MapIcon name="bell" />
                    <span v-if="attention.length" class="resident-notify-count">{{ attention.length }}</span>
                </button>
                <div v-if="notesOpen" class="resident-notify-panel" role="region" aria-label="Notifications">
                    <p v-if="!attention.length">No updates need your attention.</p>
                    <router-link v-for="item in attention" :key="item.id" :to="`/reports/${item.id}/track`" @click="notesOpen = false">
                        <strong>{{ item.reference }}</strong>
                        <span>{{ item.reason }}</span>
                    </router-link>
                </div>
            </div>
            <div class="resident-account-menu">
                <button type="button" :aria-label="`Account menu for ${userName || 'resident'}`" :aria-expanded="accountOpen" @click="accountOpen = !accountOpen; notesOpen = false">
                    <ResidentAvatar :name="userName" :src="residentAccount.photoUrl || ''" />
                </button>
                <div v-if="accountOpen" class="resident-notify-panel">
                    <p>{{ userName }}</p>
                    <router-link to="/profile" @click="accountOpen = false">My Profile</router-link>
                </div>
            </div>
        </div>
    </header>
</template>
