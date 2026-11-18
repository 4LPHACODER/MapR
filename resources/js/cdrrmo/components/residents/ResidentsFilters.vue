<script setup>
import { computed, ref } from 'vue';
import Button from 'primevue/button';
import InputText from 'primevue/inputtext';
import Select from 'primevue/select';
import MapIcon from '../../../shared/components/MapIcon.vue';
const props = defineProps({ modelValue: { type: Object, required: true }, incidents: { type: Array, default: () => [] }, barangays: { type: Array, default: () => [] }, barangayRegistry: Boolean });
const emit = defineEmits(['update:modelValue', 'clear', 'submit', 'composition']);
const searchInput = ref(null);
const incidentOptions = computed(() => [{ label: 'Registry view', value: '' }, ...props.incidents.map(row => ({ label: row.incident_name, value: String(row.id) }))]);
const allBarangays = computed(() => props.barangayRegistry ? 'All assigned' : 'All four barangays');
const barangayOptions = computed(() => [{ label: allBarangays.value, value: '' }, ...props.barangays.map(row => ({ label: row.name, value: String(row.id) }))]);
const panelClass = computed(() => props.barangayRegistry ? 'cdrrmo-residents-overlay barangay-residents-overlay' : 'cdrrmo-residents-overlay');
const inclusionOptions = [{ label: 'All inclusion states', value: '' }, { label: 'Potentially affected', value: 'included' }, { label: 'Not listed', value: 'not_included' }];
const reportOptions = [{ label: 'All residents', value: 'all' }, { label: 'With reports', value: 'with' }, { label: 'Without reports', value: 'without' }];
const statusOptions = [{ label: 'Active records', value: 'active' }, { label: 'Archived records', value: 'inactive' }, { label: 'Active and archived', value: 'all' }];
const active = computed(() => props.modelValue.search || props.modelValue.incident_id || props.modelValue.barangay_id || props.modelValue.household_id || props.modelValue.building_id || props.modelValue.inclusion || props.modelValue.status !== 'active' || props.modelValue.report_availability !== 'all');
function update(key, value) { emit('update:modelValue', { [key]: value ?? '' }); }
function clearSearch() { update('search', ''); emit('submit'); searchInput.value?.$el?.focus(); }
</script>

<template>
    <form class="registry-filters" novalidate aria-labelledby="resident-filters-title" @submit.prevent="$emit('submit')">
        <div class="registry-section-heading"><h3 id="resident-filters-title"><MapIcon name="list-filter" />Search and filters</h3><Button label="Clear Filters" size="small" severity="secondary" text :disabled="!active" @click="$emit('clear')" /></div>
        <div class="registry-filter-grid">
            <div class="registry-field registry-view-field">
                <label id="registry-view-label" for="registry-view">{{ barangayRegistry ? 'Registry or incident' : 'Registry view' }}</label>
                <Select input-id="registry-view" aria-labelledby="registry-view-label" :model-value="modelValue.incident_id" :options="incidentOptions" option-label="label" option-value="value" placeholder="Registry view" size="small" :panel-class="panelClass" :pt="{ transition: { css: false } }" @update:model-value="update('incident_id', $event)" />
            </div>
            <div class="registry-field registry-barangay-field">
                <label id="registry-barangay-label" for="registry-barangay">Barangay</label>
                <Select input-id="registry-barangay" aria-labelledby="registry-barangay-label" :model-value="modelValue.barangay_id" :options="barangayOptions" option-label="label" option-value="value" :placeholder="allBarangays" size="small" :panel-class="panelClass" :pt="{ transition: { css: false } }" @update:model-value="update('barangay_id', $event)" />
            </div>
            <div class="registry-field registry-search-field">
                <label for="registry-search">Search residents</label>
                <div class="registry-search"><MapIcon name="search" /><InputText id="registry-search" ref="searchInput" :model-value="modelValue.search" placeholder="Name, resident or household reference" size="small" autocomplete="off" @update:model-value="update('search', $event)" @compositionstart="$emit('composition', true)" @compositionend="$emit('composition', false)" /><Button v-if="modelValue.search" size="small" severity="secondary" text aria-label="Clear resident search" title="Clear search" @click="clearSearch"><MapIcon name="x" /></Button></div>
            </div>
            <div v-if="modelValue.incident_id" class="registry-field registry-inclusion-field">
                <label id="registry-inclusion-label" for="registry-inclusion">Incident inclusion</label>
                <Select input-id="registry-inclusion" aria-labelledby="registry-inclusion-label" :model-value="modelValue.inclusion" :options="inclusionOptions" option-label="label" option-value="value" placeholder="All inclusion states" size="small" :panel-class="panelClass" :pt="{ transition: { css: false } }" @update:model-value="update('inclusion', $event)" />
            </div>
            <div class="registry-field registry-reports-field">
                <label id="registry-reports-label" for="registry-reports">Report availability</label>
                <Select input-id="registry-reports" aria-labelledby="registry-reports-label" :model-value="modelValue.report_availability" :options="reportOptions" option-label="label" option-value="value" placeholder="All residents" size="small" :panel-class="panelClass" :pt="{ transition: { css: false } }" @update:model-value="update('report_availability', $event)" />
            </div>
            <div class="registry-field registry-status-field">
                <label id="registry-status-label" for="registry-status">Record status</label>
                <Select input-id="registry-status" aria-labelledby="registry-status-label" :model-value="modelValue.status" :options="statusOptions" option-label="label" option-value="value" size="small" :panel-class="panelClass" :pt="{ transition: { css: false } }" @update:model-value="update('status', $event)" />
            </div>
        </div>
    </form>
</template>
