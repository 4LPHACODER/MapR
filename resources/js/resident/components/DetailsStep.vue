<script setup>
import { computed, inject, ref } from 'vue';
import LocationPinMap from '../../shared/components/LocationPinMap.vue';
import MapIcon from '../../shared/components/MapIcon.vue';

const props = defineProps({
    form: { type: Object, required: true },
    incidents: { type: Array, default: () => [] },
    profile: { type: Object, default: () => ({}) },
    errors: { type: Object, default: () => ({}) },
});
defineEmits(['confirm-pin', 'clear-pin']);

const mapboxToken = inject('mapboxToken');
const mapboxStyle = inject('mapboxStyle');
const incidentEl = ref(null);
const locationEl = ref(null);
const descriptionEl = ref(null);
const pinWrap = ref(null);
const selected = computed(() => props.incidents.find((incident) => String(incident.id) === String(props.form.disaster_incident_id)) || null);

function focusField(name) {
    if (name === 'pin') {
        pinWrap.value?.scrollIntoView({ block: 'center' });
        pinWrap.value?.querySelector('input, button')?.focus();
        return;
    }
    const field = { incident: incidentEl, location: locationEl, description: descriptionEl }[name];
    field?.value?.focus();
}

defineExpose({ focusField });
</script>

<template>
    <section class="resident-fields">
        <h2 class="resident-step-heading" tabindex="-1">Tell us what happened</h2>
        <p class="resident-lead">Provide the incident details, then add photos of the affected building.</p>
        <h3 class="resident-section-title"><MapIcon name="disaster" /> Incident information</h3>
        <div class="resident-field-pair">
            <label><span class="resident-req">Incident</span>
                <select id="resident-incident" ref="incidentEl" v-model="form.disaster_incident_id" required>
                    <option value="">Select an incident</option>
                    <option v-for="incident in incidents" :key="incident.id" :value="incident.id">{{ incident.name }}</option>
                </select>
                <span v-if="errors.incident" class="resident-error">{{ errors.incident }}</span>
            </label>
            <label>Observed at
                <input v-model="form.observed_at" type="datetime-local">
            </label>
        </div>
        <p v-if="selected" class="resident-muted">{{ selected.type || 'Incident' }} · official date {{ selected.occurred_at || 'not recorded' }}</p>
        <label v-if="form.observed_at" class="resident-check">
            <input v-model="form.observed_at_approximate" type="checkbox"> Time is approximate
        </label>
        <h3 class="resident-section-title"><MapIcon name="pin" /> Incident location</h3>
        <p><span>Barangay</span> {{ profile.barangay_name || 'Not assigned' }}</p>
        <label><span class="resident-req">Purok, street, or landmark</span>
            <textarea id="resident-location" ref="locationEl" v-model="form.reported_location" maxlength="2000" rows="3" required placeholder="Purok, street, and landmark"></textarea>
            <span v-if="errors.location" class="resident-error">{{ errors.location }}</span>
        </label>
        <div ref="pinWrap">
            <LocationPinMap
                :token="mapboxToken"
                :style-url="mapboxStyle"
                :latitude="form.latitude"
                :longitude="form.longitude"
                :center-latitude="profile.home_latitude"
                :center-longitude="profile.home_longitude"
                @confirm="$emit('confirm-pin', $event)"
                @clear="$emit('clear-pin')"
            />
            <p v-if="errors.pin" class="resident-error">{{ errors.pin }}</p>
        </div>
        <h3 class="resident-section-title"><MapIcon name="file" /> Description</h3>
        <label><span class="resident-req">What happened?</span>
            <textarea id="resident-description" ref="descriptionEl" v-model="form.incident_description" maxlength="5000" rows="5" required placeholder="Describe what happened and the visible damage to the house or building."></textarea>
            <span class="resident-wizard-count">{{ form.incident_description.length }} / 5000</span>
            <span v-if="errors.description" class="resident-error">{{ errors.description }}</span>
        </label>
        <p class="resident-muted">Reporter: {{ profile.name }} · {{ profile.email }}<span v-if="profile.contact_number"> · {{ profile.contact_number }}</span></p>
    </section>
</template>
