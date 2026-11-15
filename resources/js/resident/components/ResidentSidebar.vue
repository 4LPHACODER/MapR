<script setup>
import Button from 'primevue/button';
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
</script>

<template>
    <aside id="resident-sidebar" class="resident-sidebar" :class="{ 'is-collapsed': collapsed }" aria-label="Resident navigation">
        <div class="resident-brand">
            <a :href="homeUrl" aria-label="MapR Resident home" title="MapR Resident home">
                <img :src="logoUrl" alt="MapR logo" width="40" height="40">
                <span class="resident-label resident-brand-copy"><strong>MapR</strong><span>Resident workspace</span></span>
            </a>
        </div>
        <nav aria-label="Main navigation">
            <p class="resident-nav-heading resident-label">Workspace</p>
            <router-link aria-label="Dashboard" title="Dashboard" to="/" active-class="" exact-active-class="router-link-active"><MapIcon name="dashboard" /><span class="resident-label">Dashboard</span></router-link>
            <router-link aria-label="Map" title="Map" to="/map"><MapIcon name="map" /><span class="resident-label">Map</span></router-link>
            <router-link aria-label="Report Incident" title="Report Incident" to="/report"><MapIcon name="camera" /><span class="resident-label">Report Incident</span></router-link>
            <router-link aria-label="My Reports" title="My Reports" to="/reports"><MapIcon name="reports" /><span class="resident-label">My Reports</span></router-link>
            <router-link aria-label="My Profile" title="My Profile" to="/profile"><MapIcon name="user" /><span class="resident-label">My Profile</span></router-link>
        </nav>
        <div class="resident-account-area">
            <div class="resident-account-identity" :title="userName || jobTitle">
                <ResidentAvatar :name="userName" :src="residentAccount.photoUrl || ''" />
                <span class="resident-label resident-account-copy"><strong class="resident-account-name">{{ userName || jobTitle }}</strong><span>{{ jobTitle }}</span></span>
            </div>
            <form novalidate method="POST" :action="logoutUrl">
                <!-- native-ok: hidden CSRF token must accompany the native POST logout form. -->
                <input type="hidden" name="_token" :value="csrfToken">
                <Button
                    type="submit"
                    class="resident-logout"
                    size="small"
                    title="Sign out"
                    severity="secondary"
                    text
                    aria-label="Sign out"
                >
                    <MapIcon name="logout" />
                    <span class="resident-label">Sign out</span>
                </Button>
            </form>
        </div>
    </aside>
</template>
