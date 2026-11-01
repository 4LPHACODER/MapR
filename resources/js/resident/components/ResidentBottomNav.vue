<script setup>
import MapIcon from '../../shared/components/MapIcon.vue';
import ResidentReportButton from './ResidentReportButton.vue';
</script>

<template>
    <nav class="resident-bottom-nav" aria-label="Resident navigation">
        <router-link to="/" active-class="" exact-active-class="router-link-active"><MapIcon name="home" /><span>Home</span></router-link>
        <router-link to="/map"><MapIcon name="map" /><span>Map</span></router-link>
        <div class="resident-bottom-slot">
            <ResidentReportButton />
        </div>
        <router-link to="/reports"><MapIcon name="reports" /><span>My Reports</span></router-link>
        <router-link to="/profile"><MapIcon name="user" /><span>Profile</span></router-link>
    </nav>
</template>
