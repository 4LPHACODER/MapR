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
const menuOpen = ref(false);
const highlight = ref(0);
const filtered = computed(() => {
    const query = String(props.form.incident_text || '').trim().toLowerCase();
    const rows = props.incidents.filter((incident) => !query || String(incident.name || '').toLowerCase().includes(query));
    return rows.slice(0, 8);
});
const selected = computed(() => props.incidents.find((incident) => String(incident.id) === String(props.form.disaster_incident_id)) || null);

function applyIncidentText() {
    const text = String(props.form.incident_text || '').trim();
    const match = props.incidents.find((incident) => String(incident.name || '').trim().toLowerCase() === text.toLowerCase());
    if (match) {
        props.form.disaster_incident_id = match.id;
        props.form.reported_incident_type = '';
        return;
    }
    props.form.disaster_incident_id = '';
    props.form.reported_incident_type = text;
}

function onIncidentInput() {
    menuOpen.value = true;
    highlight.value = 0;
    applyIncidentText();
}

function choose(incident) {
    props.form.incident_text = incident.name;
    props.form.disaster_incident_id = incident.id;
    props.form.reported_incident_type = '';
    menuOpen.value = false;
}

function chooseHighlighted() {
    const incident = filtered.value[highlight.value];
    if (incident) choose(incident);
    else menuOpen.value = false;
}

function moveHighlight(step) {
    if (!filtered.value.length) return;
    menuOpen.value = true;
    highlight.value = (highlight.value + step + filtered.value.length) % filtered.value.length;
}

function closeMenu() {
    window.setTimeout(() => {
        menuOpen.value = false;
    }, 120);
}

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
            <label class="resident-incident-combo"><span class="resident-req">Incident</span>
                <input
                    id="resident-incident"
                    ref="incidentEl"
                    v-model="form.incident_text"
                    role="combobox"
                    aria-autocomplete="list"
                    aria-controls="resident-incident-list"
                    :aria-expanded="menuOpen ? 'true' : 'false'"
                    maxlength="120"
                    required
                    autocomplete="off"
                    placeholder="Select or type an incident"
                    @focus="menuOpen = true"
                    @input="onIncidentInput"
                    @blur="closeMenu"
                    @keydown.down.prevent="moveHighlight(1)"
                    @keydown.up.prevent="moveHighlight(-1)"
                    @keydown.enter.prevent="chooseHighlighted"
                    @keydown.escape="menuOpen = false"
                >
                <ul v-if="menuOpen && filtered.length" id="resident-incident-list" class="resident-incident-menu" role="listbox">
                    <li v-for="(incident, index) in filtered" :key="incident.id" role="presentation">
                        <button type="button" role="option" :aria-selected="index === highlight" :class="{ 'is-active': index === highlight }" @mousedown.prevent="choose(incident)">
                            {{ incident.name }}
                        </button>
                    </li>
                </ul>
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
        <h3 class="resident-section-title"><MapIcon name="home" /> Linked Building Information</h3>
        <div v-if="(profile.linked_buildings || []).length" class="resident-linked-building">
            <template v-if="(profile.linked_buildings || []).length === 1">
                <p><strong>Building Reference</strong><br>{{ profile.linked_buildings[0].building_reference || '—' }}</p>
                <p><strong>Household Reference</strong><br>{{ profile.linked_buildings[0].household_reference || '—' }}</p>
                <p><strong>Barangay</strong><br>{{ profile.linked_buildings[0].barangay_name || profile.barangay_name || '—' }}</p>
                <p v-if="profile.linked_buildings[0].address"><strong>Registered Building Location</strong><br>{{ profile.linked_buildings[0].address }}</p>
                <p class="resident-muted">Linkage: Verified Registry Association</p>
                <label class="resident-check">
                    <input v-model="form.confirm_affected_building" type="checkbox">
                    This is the building affected by the incident.
                </label>
            </template>
            <template v-else>
                <p class="resident-muted">Select the verified building affected by this incident.</p>
                <label>
                    <span class="resident-req">Affected building</span>
                    <select v-model="form.affected_building_id" @change="form.confirm_affected_building = Boolean(form.affected_building_id)">
                        <option value="">Select a building</option>
                        <option
                            v-for="building in profile.linked_buildings"
                            :key="building.building_id"
                            :value="String(building.building_id)"
                        >
                            {{ building.building_reference || `Building #${building.building_id}` }}
                        </option>
                    </select>
                </label>
                <label class="resident-check">
                    <input v-model="form.confirm_affected_building" type="checkbox" :disabled="!form.affected_building_id">
                    Confirm this is the building affected by the incident.
                </label>
            </template>
            <p v-if="!form.confirm_affected_building" class="resident-muted">
                If this is a different structure, leave confirmation unchecked. Building linkage will require verification.
            </p>
        </div>
        <p v-else class="resident-muted">
            No verified building association is linked to your registry record. You can still submit this report; building linkage will require verification.
        </p>
        <h3 class="resident-section-title"><MapIcon name="file" /> Description</h3>
        <label><span class="resident-req">What happened?</span>
            <textarea id="resident-description" ref="descriptionEl" v-model="form.incident_description" maxlength="5000" rows="5" required placeholder="Describe what happened and the visible damage to the house or building."></textarea>
            <span class="resident-wizard-count">{{ form.incident_description.length }} / 5000</span>
            <span v-if="errors.description" class="resident-error">{{ errors.description }}</span>
        </label>
        <p class="resident-muted">Reporter: {{ profile.name }} · {{ profile.email }}<span v-if="profile.contact_number"> · {{ profile.contact_number }}</span></p>
    </section>
</template>
