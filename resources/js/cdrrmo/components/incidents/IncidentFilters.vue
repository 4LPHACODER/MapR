<script setup>
import { computed, onBeforeUnmount, ref, watch } from 'vue';
import Button from 'primevue/button';
import InputText from 'primevue/inputtext';
import Select from 'primevue/select';
import MapIcon from '../../../shared/components/MapIcon.vue';
const props = defineProps({ modelValue: { type: Object, required: true }, types: { type: Array, default: () => [] }, barangays: { type: Array, default: () => [] }, hasFilters: Boolean });
const emit = defineEmits(['update:modelValue', 'reset']);
const search = ref(props.modelValue.search);
const searchInput = ref(null);
let timer;
let composing = false;
const typeOptions = computed(() => [{ label: 'All disaster types', value: '' }, ...props.types.map((row) => ({ label: row.name, value: String(row.id) }))]);
const barangayOptions = computed(() => [{ label: 'All barangays', value: '' }, ...props.barangays.map((row) => ({ label: row.name, value: String(row.id) }))]);
const statuses = [{ label: 'All statuses', value: '' }, { label: 'Active', value: 'active' }, { label: 'Closed', value: 'closed' }];
const overlay = 'cdrrmo-incidents-overlay';
watch(() => props.modelValue.search, (value) => { clearTimeout(timer); search.value = value; });
function commit(patch = {}) {
    clearTimeout(timer);
    if (composing) return;
    emit('update:modelValue', { search: search.value, ...patch });
}
function schedule() {
    clearTimeout(timer);
    if (!composing) timer = setTimeout(() => commit(), 300);
}
function clearSearch() { search.value = ''; commit(); searchInput.value?.$el?.focus(); }
function reset() { clearTimeout(timer); search.value = ''; emit('reset'); }
onBeforeUnmount(() => clearTimeout(timer));
</script>

<template>
    <form class="incident-filter-panel" novalidate aria-labelledby="incident-filter-title" @submit.prevent="commit()">
        <div class="incident-panel-heading">
            <h3 id="incident-filter-title"><MapIcon name="list-filter" />Filter incidents</h3>
            <Button label="Clear filters" size="small" severity="secondary" text :disabled="!hasFilters && !search" @click="reset" />
        </div>
        <div class="incident-filter-fields">
            <div class="incident-field incident-search-field">
                <label for="incident-search">Search incidents</label>
                <div class="incident-search">
                    <MapIcon name="search" />
                    <InputText id="incident-search" ref="searchInput" v-model="search" size="small" placeholder="Incident name or reference" autocomplete="off" @input="schedule" @compositionstart="composing = true; clearTimeout(timer)" @compositionend="composing = false; schedule()" />
                    <Button v-if="search" aria-label="Clear search" title="Clear search" severity="secondary" text size="small" @click="clearSearch"><MapIcon name="x" /></Button>
                </div>
            </div>
            <div class="incident-field">
                <label id="incident-type-filter-label" for="incident-type-filter">Disaster type</label>
                <Select input-id="incident-type-filter" aria-labelledby="incident-type-filter-label" :model-value="modelValue.disaster_type_id" :options="typeOptions" option-label="label" option-value="value" placeholder="All disaster types" size="small" :panel-class="overlay" :pt="{ transition: { css: false } }" @update:model-value="commit({ disaster_type_id: $event })" />
            </div>
            <div class="incident-field">
                <label id="incident-status-filter-label" for="incident-status-filter">Status</label>
                <Select input-id="incident-status-filter" aria-labelledby="incident-status-filter-label" :model-value="modelValue.incident_status" :options="statuses" option-label="label" option-value="value" placeholder="All statuses" size="small" :panel-class="overlay" :pt="{ transition: { css: false } }" @update:model-value="commit({ incident_status: $event })" />
            </div>
            <div class="incident-field">
                <label id="incident-barangay-filter-label" for="incident-barangay-filter">Barangay</label>
                <Select input-id="incident-barangay-filter" aria-labelledby="incident-barangay-filter-label" :model-value="modelValue.barangay_id" :options="barangayOptions" option-label="label" option-value="value" placeholder="All barangays" size="small" :panel-class="overlay" :pt="{ transition: { css: false } }" @update:model-value="commit({ barangay_id: $event })" />
            </div>
        </div>
    </form>
</template>
