<script setup>
import { computed, onMounted, onBeforeUnmount, ref } from 'vue';
import { useRoute } from 'vue-router';
import MapIcon from '../../shared/components/MapIcon.vue';

const logoUrl = '/images/mapr-logo.png';

const props = defineProps({
    homeUrl: { type: String, default: '/' },
    userName: { type: String, default: '' },
    userEmail: { type: String, default: '' },
    logoutUrl: { type: String, default: '/logout' },
    csrfToken: { type: String, default: '' },
    open: { type: Boolean, default: false },
    collapsed: { type: Boolean, default: false },
});

defineEmits(['close', 'toggle-collapse']);

const route = useRoute();
const reportsOpen = ref(true);
const badges = ref({ barangay_reports: 0, field_assessments: 0, consolidated_reports: 0, badge_meaning: '' });
const badgeMeaning = computed(() => badges.value.badge_meaning || 'Drafts, returned reports, and reports awaiting review. Submitted, signed, and finalized reports stay in the list and are not included.');

const initials = computed(() => {
    const parts = props.userName.trim().split(/\s+/).filter(Boolean);
    return parts.length ? parts.slice(0, 2).map((p) => p[0]?.toUpperCase() || '').join('') : 'MA';
});

const pendingTotal = computed(() => badges.value.field_assessments + badges.value.barangay_reports + badges.value.consolidated_reports);

async function loadBadges() {
    try {
        const { data } = await window.axios.get('/cdrrmo/api/nav-badges');
        badges.value = data;
    } catch {
        /* badges optional */
    }
}

onMounted(() => {
    loadBadges();
    window.addEventListener('mapr-nav-refresh', loadBadges);
});
onBeforeUnmount(() => window.removeEventListener('mapr-nav-refresh', loadBadges));
</script>

<template>
    <aside
        class="cdrrmo-sidebar"
        :class="{ 'is-open': open, 'is-collapsed': collapsed }"
        aria-label="CDRRMO navigation"
    >
        <div class="cdrrmo-sidebar-top">
            <a :href="homeUrl" class="cdrrmo-brand" @click="$emit('close')">
                <img :src="logoUrl" alt="MapR logo" width="288" height="287">
                <span v-if="!collapsed">MapR CDRRMO</span>
            </a>
            <button type="button" class="cdrrmo-collapse-btn" :aria-label="collapsed ? 'Expand sidebar' : 'Collapse sidebar'" @click="$emit('toggle-collapse')">
                <MapIcon name="collapse" />
            </button>
        </div>

        <nav class="cdrrmo-nav">
            <p v-if="!collapsed" class="cdrrmo-nav-heading">Overview</p>
            <router-link to="/" @click="$emit('close')">
                <MapIcon name="dashboard" /><span v-if="!collapsed">Dashboard</span>
            </router-link>
            <router-link to="/map" @click="$emit('close')">
                <MapIcon name="map" /><span v-if="!collapsed">Assessment Map</span>
            </router-link>
            <router-link to="/incidents" @click="$emit('close')">
                <MapIcon name="disaster" /><span v-if="!collapsed">Disaster Incidents</span>
            </router-link>
            <router-link to="/residents" @click="$emit('close')">
                <MapIcon name="users" /><span v-if="!collapsed">Residents</span>
            </router-link>

            <p v-if="!collapsed" class="cdrrmo-nav-heading">Reports</p>
            <button type="button" class="cdrrmo-nav-toggle" :aria-expanded="reportsOpen" @click="reportsOpen = !reportsOpen">
                <MapIcon name="reports" /><span v-if="!collapsed">Reports</span>
                <span v-if="!collapsed && pendingTotal" class="cdrrmo-badge" :title="badgeMeaning">{{ pendingTotal }}</span>
                <MapIcon v-if="!collapsed" name="chevron" class="cdrrmo-chevron" :class="{ 'is-open': reportsOpen }" />
            </button>
            <div v-show="reportsOpen && !collapsed" class="cdrrmo-nav-sub">
                <router-link to="/reports/barangay" @click="$emit('close')">
                    Barangay Reports
                    <span v-if="badges.barangay_reports" class="cdrrmo-badge" :title="badgeMeaning">{{ badges.barangay_reports }}</span>
                </router-link>
                <router-link to="/reports/field" @click="$emit('close')">
                    Field Assessment Reports
                    <span v-if="badges.field_assessments" class="cdrrmo-badge" :title="badgeMeaning">{{ badges.field_assessments }}</span>
                </router-link>
                <router-link to="/reports/consolidated" @click="$emit('close')">
                    Consolidated Reports
                    <span v-if="badges.consolidated_reports" class="cdrrmo-badge" :title="badgeMeaning">{{ badges.consolidated_reports }}</span>
                </router-link>
                <router-link :to="{ path: '/map', query: { mode: 'estimate' } }" @click="$emit('close')">
                    <MapIcon name="impact" /> Create Impact Report
                </router-link>
            </div>

            <p v-if="!collapsed" class="cdrrmo-nav-heading">Administration</p>
            <router-link to="/users" @click="$emit('close')">
                <MapIcon name="user-cog" /><span v-if="!collapsed">User Management</span>
            </router-link>
            <router-link to="/activity" @click="$emit('close')">
                <MapIcon name="history" /><span v-if="!collapsed">Activity Log</span>
            </router-link>
        </nav>

        <div class="cdrrmo-sidebar-account">
            <div class="cdrrmo-sidebar-user">
                <div class="cdrrmo-avatar" aria-hidden="true">{{ initials }}</div>
                <div v-if="!collapsed" class="cdrrmo-user-meta">
                    <strong>{{ userName }}</strong>
                    <span :title="userEmail">{{ userEmail }}</span>
                </div>
            </div>
            <router-link v-if="!collapsed" to="/profile" class="cdrrmo-account-link" @click="$emit('close')">
                <MapIcon name="user" /> Profile
            </router-link>
            <form v-if="!collapsed" method="POST" :action="logoutUrl" class="cdrrmo-account-link">
                <input type="hidden" name="_token" :value="csrfToken">
                <button type="submit"><MapIcon name="logout" /> Sign out</button>
            </form>
        </div>
    </aside>
</template>
