<script setup>
import { computed, onMounted, ref } from 'vue';
import MapIcon from '../../shared/components/MapIcon.vue';

const logoUrl = '/images/mapr-logo.png';
const props = defineProps({
    homeUrl: { type: String, default: '/' },
    userName: { type: String, default: '' },
    userEmail: { type: String, default: '' },
    jobTitle: { type: String, default: '' },
    logoutUrl: { type: String, default: '/logout' },
    csrfToken: { type: String, default: '' },
    open: { type: Boolean, default: false },
    collapsed: { type: Boolean, default: false },
});
defineEmits(['close', 'toggle-collapse']);

const badges = ref({});
const reportsOpen = ref(true);
const initials = computed(() => {
    const parts = props.userName.trim().split(/\s+/).filter(Boolean);
    return parts.length ? parts.slice(0, 2).map((part) => part[0]?.toUpperCase() || '').join('') : 'MA';
});

onMounted(async () => {
    try {
        badges.value = (await window.axios.get('/bdrrm/api/navigation')).data;
    } catch {
        badges.value = {};
    }
});
</script>

<template>
    <aside class="cdrrmo-sidebar" :class="{ 'is-open': open, 'is-collapsed': collapsed }" aria-label="BDRRM navigation">
        <div class="cdrrmo-sidebar-top">
            <a :href="homeUrl" class="cdrrmo-brand" @click="$emit('close')">
                <img :src="logoUrl" alt="MapR logo" width="288" height="287">
                <span v-if="!collapsed">MapR BDRRM</span>
            </a>
            <button type="button" class="cdrrmo-collapse-btn" :aria-label="collapsed ? 'Expand sidebar' : 'Collapse sidebar'" @click="$emit('toggle-collapse')">
                <MapIcon name="collapse" />
            </button>
        </div>
        <nav class="cdrrmo-nav">
            <router-link to="/" @click="$emit('close')"><MapIcon name="dashboard" /><span v-if="!collapsed">Dashboard</span></router-link>
            <router-link to="/map" @click="$emit('close')">
                <MapIcon name="map" /><span v-if="!collapsed">Barangay Map</span>
            </router-link>
            <router-link to="/submissions" @click="$emit('close')">
                <MapIcon name="users" /><span v-if="!collapsed">Resident Submissions</span>
                <span v-if="!collapsed && badges.submissions" class="cdrrmo-badge">{{ badges.submissions }}</span>
            </router-link>
            <router-link to="/buildings" @click="$emit('close')"><MapIcon name="pin" /><span v-if="!collapsed">Building Records</span></router-link>
            <button type="button" class="cdrrmo-nav-toggle" :aria-expanded="reportsOpen" @click="reportsOpen = !reportsOpen">
                <MapIcon name="reports" /><span v-if="!collapsed">Reports</span>
            </button>
            <div v-show="reportsOpen && !collapsed" class="cdrrmo-nav-sub">
                <router-link to="/reports/new" @click="$emit('close')">Create Barangay Report</router-link>
                <router-link to="/reports/drafts" @click="$emit('close')">Draft Reports <span v-if="badges.drafts" class="cdrrmo-badge">{{ badges.drafts }}</span></router-link>
                <router-link to="/reports/returned" @click="$emit('close')">Returned for Correction <span v-if="badges.returned" class="cdrrmo-badge">{{ badges.returned }}</span></router-link>
                <router-link to="/reports/submitted" @click="$emit('close')">Submitted Reports <span v-if="badges.submitted" class="cdrrmo-badge">{{ badges.submitted }}</span></router-link>
            </div>
            <router-link to="/profile" @click="$emit('close')"><MapIcon name="user" /><span v-if="!collapsed">Profile</span></router-link>
        </nav>
        <div class="cdrrmo-sidebar-account">
            <div class="cdrrmo-sidebar-user">
                <div class="cdrrmo-avatar" aria-hidden="true">{{ initials }}</div>
                <div v-if="!collapsed" class="cdrrmo-user-meta">
                    <strong>{{ userName }}</strong>
                    <span>{{ jobTitle || userEmail }}</span>
                </div>
            </div>
            <form v-if="!collapsed" method="POST" :action="logoutUrl" class="cdrrmo-account-link">
                <input type="hidden" name="_token" :value="csrfToken">
                <button type="submit"><MapIcon name="logout" /> Sign out</button>
            </form>
        </div>
    </aside>
</template>
