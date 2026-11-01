<script setup>
import { inject } from 'vue';
import MapWorkspace from '../../shared/components/MapWorkspace.vue';

const apiBase = inject('apiBase');
</script>

<template>
    <section>
        <h2 class="cdrrmo-page-title">Assessment Map</h2>
        <p class="cdrrmo-page-lead">Review building assessments and resident records across the four study barangays.</p>
        <MapWorkspace :api-base="apiBase" :allow-impact="false" scope-barangays />
    </section>
</template>
