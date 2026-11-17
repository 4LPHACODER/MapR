<script setup>
import { computed } from 'vue';
import Button from 'primevue/button';
import DatePicker from 'primevue/datepicker';
import Select from 'primevue/select';
import MapIcon from '../../../shared/components/MapIcon.vue';
import { dateToIso, isoToDate } from '../../../shared/ui/dates.js';

const props = defineProps({
    modelValue: { type: Object, required: true },
    incidents: { type: Array, default: () => [] },
    barangays: { type: Array, default: () => [] },
});
const emit = defineEmits(['update:modelValue', 'reset']);
const incidentOptions = computed(() => [{ label: 'All incidents', value: '' }, ...props.incidents.map((row) => ({ label: row.incident_name, value: String(row.id) }))]);
const barangayOptions = computed(() => [{ label: 'All barangays', value: '' }, ...props.barangays.map((row) => ({ label: row.barangay_name, value: String(row.id) }))]);
const statusOptions = [{ label: 'Any status', value: '' }, { label: 'Draft', value: 'draft' }, { label: 'Under review', value: 'review' }, { label: 'Signed', value: 'signed' }];
const hasFilters = computed(() => Object.values(props.modelValue).some(Boolean));
const overlayClass = 'cdrrmo-dashboard-overlay';
function update(key, value) { emit('update:modelValue', { ...props.modelValue, [key]: value ?? '' }); }
</script>

<template>
    <form class="cdrrmo-dash-filters" novalidate @submit.prevent>
        <div class="cdrrmo-dash-filter-title"><MapIcon name="list-filter" /><span>Filter overview</span><Button type="button" size="small" severity="secondary" text label="Reset filters" :disabled="!hasFilters" @click="$emit('reset')" /></div>
        <div class="cdrrmo-dash-filter-fields">
            <div class="cdrrmo-dash-field">
                <label for="cdrrmo-filter-incident">Disaster incident</label>
                <Select input-id="cdrrmo-filter-incident" placeholder="All incidents" :model-value="modelValue.incident_id" :options="incidentOptions" option-label="label" option-value="value" size="small" :pt="{ transition: { css: false } }" :panel-class="overlayClass" @update:model-value="update('incident_id', $event)" />
            </div>
            <div class="cdrrmo-dash-field">
                <label for="cdrrmo-filter-barangay">Barangay</label>
                <Select input-id="cdrrmo-filter-barangay" placeholder="All barangays" :model-value="modelValue.barangay_id" :options="barangayOptions" option-label="label" option-value="value" size="small" :pt="{ transition: { css: false } }" :panel-class="overlayClass" @update:model-value="update('barangay_id', $event)" />
            </div>
            <div class="cdrrmo-dash-field">
                <label for="cdrrmo-filter-start">Period from</label>
                <DatePicker input-id="cdrrmo-filter-start" :model-value="isoToDate(modelValue.period_start)" :max-date="isoToDate(modelValue.period_end)" date-format="dd M yy" placeholder="Start date" show-icon icon-display="input" show-button-bar size="small" :pt="{ transition: { css: false } }" :panel-class="`${overlayClass} cdrrmo-dashboard-calendar`" @update:model-value="update('period_start', dateToIso($event))" />
            </div>
            <div class="cdrrmo-dash-field">
                <label for="cdrrmo-filter-end">Period to</label>
                <DatePicker input-id="cdrrmo-filter-end" :model-value="isoToDate(modelValue.period_end)" :min-date="isoToDate(modelValue.period_start)" date-format="dd M yy" placeholder="End date" show-icon icon-display="input" show-button-bar size="small" :pt="{ transition: { css: false } }" :panel-class="`${overlayClass} cdrrmo-dashboard-calendar`" @update:model-value="update('period_end', dateToIso($event))" />
            </div>
            <div class="cdrrmo-dash-field">
                <label for="cdrrmo-filter-status">Assessment status</label>
                <Select input-id="cdrrmo-filter-status" placeholder="Any status" :model-value="modelValue.assessment_status" :options="statusOptions" option-label="label" option-value="value" size="small" :pt="{ transition: { css: false } }" :panel-class="overlayClass" @update:model-value="update('assessment_status', $event)" />
            </div>
        </div>
    </form>
</template>
