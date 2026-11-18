<script setup>
import { inject } from 'vue';
import MapWorkspace from '../../shared/components/MapWorkspace.vue';

const apiBase = inject('apiBase');
</script>

<template>
    <section class="bdrrm-map" aria-labelledby="barangay-map-title">
        <MapWorkspace :api-base="apiBase" :allow-impact="false" scope-barangays resident-submissions community-layers action-menu single-clear hide-households>
            <template #heading>
                <div class="min-w-0">
                    <h2 id="barangay-map-title" class="text-2xl font-bold leading-tight tracking-tight">Barangay Map</h2>
                    <p class="mt-1 text-sm leading-snug text-slate-600">Explore assigned barangays, registry locations, and resident reports.</p>
                </div>
            </template>
        </MapWorkspace>
    </section>
</template>
