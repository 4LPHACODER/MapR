<script setup>
import MapIcon from '../../shared/components/MapIcon.vue';
import ResidentAvatar from './ResidentAvatar.vue';
import { residentAccount } from '../accountState';

const logoUrl = '/images/mapr-logo.png';
defineProps({
    homeUrl: { type: String, default: '/' },
    userName: { type: String, default: '' },
    jobTitle: { type: String, default: 'Resident' },
    logoutUrl: { type: String, default: '/logout' },
    csrfToken: { type: String, default: '' },
    collapsed: { type: Boolean, default: false },
});
defineEmits(['toggle-collapse']);
</script>

<template>
    <aside class="resident-sidebar" :class="{ 'is-collapsed': collapsed }" aria-label="Resident navigation">
        <div class="resident-brand">
            <a :href="homeUrl">
                <img :src="logoUrl" alt="MapR logo" width="40" height="40">
                <span class="resident-label">MapR Resident</span>
            </a>
            <button type="button" class="resident-collapse" :aria-label="collapsed ? 'Expand sidebar' : 'Collapse sidebar'" @click="$emit('toggle-collapse')">
                <MapIcon name="panel" />
            </button>
        </div>
        <nav>
            <router-link to="/" active-class="" exact-active-class="router-link-active"><MapIcon name="dashboard" /><span class="resident-label">Dashboard</span></router-link>
            <router-link to="/map"><MapIcon name="map" /><span class="resident-label">Map</span></router-link>
            <router-link to="/report"><MapIcon name="camera" /><span class="resident-label">Report Incident</span></router-link>
            <router-link to="/reports"><MapIcon name="reports" /><span class="resident-label">My Reports</span></router-link>
            <router-link to="/profile"><MapIcon name="user" /><span class="resident-label">My Profile</span></router-link>
        </nav>
        <div class="resident-account-area">
            <p class="resident-label"><ResidentAvatar :name="userName" :src="residentAccount.photoUrl || ''" /> {{ userName || jobTitle }}</p>
            <form method="POST" :action="logoutUrl">
                <input type="hidden" name="_token" :value="csrfToken">
                <button type="submit">
                    <MapIcon name="logout" />
                    <span class="resident-label">Sign out</span>
                </button>
            </form>
        </div>
    </aside>
</template>
