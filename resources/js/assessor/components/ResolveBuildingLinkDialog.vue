<script setup>
import { inject, ref, watch } from 'vue';
import MapIcon from '../../shared/components/MapIcon.vue';

const props = defineProps({
    reportId: { type: [String, Number], required: true },
    open: { type: Boolean, default: false },
});
const emit = defineEmits(['close', 'linked']);

const apiBase = inject('apiBase');
const query = ref('');
const results = ref([]);
const searching = ref(false);
const linking = ref(false);
const error = ref('');

async function search() {
    searching.value = true;
    error.value = '';
    try {
        const { data } = await window.axios.get(`${apiBase}/barangay-reports/${props.reportId}/damage-assessment/buildings`, {
            params: { q: query.value || undefined },
        });
        results.value = data.data || [];
    } catch (exception) {
        error.value = exception.response?.data?.message || 'Building search failed.';
        results.value = [];
    } finally {
        searching.value = false;
    }
}

async function linkBuilding(buildingId) {
    if (linking.value) return;
    linking.value = true;
    error.value = '';
    try {
        await window.axios.post(`${apiBase}/barangay-reports/${props.reportId}/damage-assessment/link-building`, {
            building_id: Number(buildingId),
            tag_untagged_photos: true,
        });
        emit('linked');
    } catch (exception) {
        error.value = exception.response?.data?.message || 'The building could not be linked.';
    } finally {
        linking.value = false;
    }
}

watch(() => props.open, (value) => {
    if (!value) return;
    query.value = '';
    results.value = [];
    error.value = '';
    search();
});
</script>

<template>
    <dialog class="damage-resolve-dialog" :open="open" @cancel.prevent="emit('close')">
        <div class="damage-resolve-shell">
            <header class="damage-assessment-header">
                <div>
                    <h3>Resolve building link</h3>
                    <p class="submission-note">Select an existing building from this report barangay. Do not invent a new record.</p>
                </div>
                <button type="button" class="submission-quiet" @click="emit('close')"><MapIcon name="x" /> Close</button>
            </header>
            <p v-if="error" class="submission-alert" role="alert">{{ error }}</p>
            <div class="damage-link-building">
                <label>
                    <span>Search existing building</span>
                    <input v-model="query" type="search" maxlength="80" placeholder="Reference or address" @keyup.enter="search">
                </label>
                <button type="button" class="submission-quiet" :disabled="searching" @click="search">
                    {{ searching ? 'Searching…' : 'Search' }}
                </button>
            </div>
            <ul v-if="results.length" class="damage-building-results">
                <li v-for="building in results" :key="building.id">
                    <span>{{ building.building_reference || `Building #${building.id}` }}</span>
                    <button
                        type="button"
                        class="submission-quiet"
                        :disabled="linking || building.linked"
                        @click="linkBuilding(building.id)"
                    >
                        {{ building.linked ? 'Already linked' : (linking ? 'Linking…' : 'Link building') }}
                    </button>
                </li>
            </ul>
            <p v-else class="submission-note">No matching buildings in this barangay.</p>
        </div>
    </dialog>
</template>
