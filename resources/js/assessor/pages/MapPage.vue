<script setup>
import { inject } from 'vue';
import MapWorkspace from '../../shared/components/MapWorkspace.vue';
import '../../../css/rdana/map.css';

const apiBase = inject('apiBase');
</script>

<template>
    <section class="rdana-map" aria-label="Assessment map workspace">
        <MapWorkspace :api-base="apiBase" :allow-impact="false" scope-barangays action-menu single-clear />
    </section>
</template>
