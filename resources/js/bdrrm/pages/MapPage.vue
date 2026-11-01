<script setup>
import { inject } from 'vue';
import MapWorkspace from '../../shared/components/MapWorkspace.vue';

const apiBase = inject('apiBase');
</script>

<template>
    <section>
        <h2 class="cdrrmo-page-title">Barangay Map</h2>
        <p class="cdrrmo-page-lead">Satellite view of the barangays in your assignment.</p>
        <MapWorkspace :api-base="apiBase" :allow-impact="false" scope-barangays />
    </section>
</template>
