<script setup>
import Button from 'primevue/button';
import MapIcon from '../../../shared/components/MapIcon.vue';
defineProps({ summary: { type: Object, default: null }, inclusion: { type: String, default: '' }, status: { type: String, default: 'active' }, incidentId: { type: [String, Number], default: '' } });
defineEmits(['registered', 'affected']);
function count(value) { return value === null || value === undefined ? 'Not available' : Number(value).toLocaleString('en-PH'); }
</script>

<template>
    <div class="registry-summary" aria-label="Resident summary">
        <Button size="small" unstyled class="registry-metric" :class="{ 'is-selected': !inclusion && status === 'active' }" :aria-pressed="!inclusion && status === 'active'" title="Show active registered residents" @click="$emit('registered')">
            <span class="registry-metric-label"><MapIcon name="users" />Registered Residents</span>
            <strong>{{ count(summary?.registered_residents) }}</strong>
            <span class="registry-metric-note">Active records in this scope</span>
        </Button>
        <article class="registry-metric">
            <span class="registry-metric-label"><MapIcon name="home" />Total Household Size</span>
            <strong>{{ count(summary?.total_household_size) }}</strong>
            <span class="registry-metric-note">{{ summary?.household_size_partial ? `${summary.households_missing_size} households have no reported size` : 'Reported size, once per household' }}</span>
        </article>
        <Button size="small" unstyled class="registry-metric" :class="{ 'is-selected': inclusion === 'included' }" :aria-pressed="inclusion === 'included'" :disabled="!incidentId" title="Filter the selected incident's potentially affected list" @click="$emit('affected')">
            <span class="registry-metric-label"><MapIcon name="pin-check" />Potentially Affected</span>
            <strong :class="{ 'registry-metric-prompt': !incidentId }">{{ !incidentId ? 'Select an incident' : count(summary?.potentially_affected) }}</strong>
            <span class="registry-metric-note">Incident list, not confirmed harm</span>
        </Button>
    </div>
</template>
