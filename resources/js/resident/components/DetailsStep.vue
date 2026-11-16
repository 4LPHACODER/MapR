<script setup>
import { computed, inject, nextTick, ref } from 'vue';
import AutoComplete from 'primevue/autocomplete';
import Checkbox from 'primevue/checkbox';
import DatePicker from 'primevue/datepicker';
import InputGroup from 'primevue/inputgroup';
import Select from 'primevue/select';
import Textarea from 'primevue/textarea';
import LocationPinMap from '../../shared/components/LocationPinMap.vue';
import MapIcon from '../../shared/components/MapIcon.vue';
import { dateToLocalDateTime, localDateTimeToDate } from '../../shared/ui/dates.js';

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
const suggestions = ref([]);
const selected = computed(
    () => props.incidents.find((incident) => String(incident.id) === String(props.form.disaster_incident_id)) || null,
);
const buildingOptions = computed(() =>
    (props.profile.linked_buildings || []).map((building) => ({
        label: building.building_reference || `Building #${building.building_id}`,
        value: String(building.building_id),
    })),
);

function suggest({ query }) {
    const search = query.trim().toLowerCase();
    suggestions.value = props.incidents
        .filter((incident) => incident.name.toLowerCase().includes(search))
        .map((incident) => incident.name);
}

function updateIncident(value) {
    const text = String(value || '');
    props.form.incident_text = text;
    const match = props.incidents.find((incident) => incident.name.trim().toLowerCase() === text.trim().toLowerCase());
    props.form.disaster_incident_id = match?.id || '';
    props.form.reported_incident_type = match ? '' : text;
}

function updateAffectedBuilding(value) {
    props.form.affected_building_id = value ?? '';
    props.form.confirm_affected_building = Boolean(props.form.affected_building_id);
}

async function fitObservedCalendar() {
    await nextTick();
    const panel = document
        .getElementById('resident-observed-at')
        ?.closest('.p-datepicker')
        ?.querySelector('.resident-report-calendar');
    if (!panel) return;
    const viewport = window.visualViewport;
    const top = (viewport?.offsetTop || 0) + 8;
    const bottom = (viewport?.offsetTop || 0) + (viewport?.height || window.innerHeight) - 8;
    panel.style.maxHeight = `${bottom - top}px`;
    const bounds = panel.getBoundingClientRect();
    const fittedTop = Math.max(top, Math.min(bounds.top, bottom - bounds.height));
    // PrimeVue chooses the anchor side; keep the resulting panel inside a short visual viewport.
    if (fittedTop !== bounds.top) panel.style.top = `${panel.offsetTop + fittedTop - bounds.top}px`;
}

function focusField(name) {
    if (name === 'pin') {
        pinWrap.value?.scrollIntoView({ block: 'center' });
        pinWrap.value?.querySelector('button')?.focus();
        return;
    }
    if (name === 'incident') {
        incidentEl.value?.$el?.querySelector('input')?.focus();
        return;
    }
    const field = { location: locationEl, description: descriptionEl }[name];
    field?.value?.$el?.focus();
}

defineExpose({ focusField });
</script>

<template>
    <section class="resident-fields report-details-step">
        <header class="report-step-intro">
            <h3 class="resident-step-heading" tabindex="-1">Incident details</h3>
            <p>
                Tell your barangay what happened and where.
                <span>Fields marked <span class="report-required">*</span> are required.</span>
            </p>
        </header>
        <section class="report-form-section" aria-labelledby="report-incident-heading">
            <h4 id="report-incident-heading">What happened?</h4>
            <div class="resident-field-pair">
                <div class="report-field">
                    <label for="resident-incident" class="resident-req">Incident</label>
                    <InputGroup class="report-incident-group" :class="{ 'is-invalid': errors.incident }">
                        <AutoComplete
                            ref="incidentEl"
                            inputId="resident-incident"
                            :modelValue="form.incident_text"
                            :suggestions="suggestions"
                            size="small"
                            dropdown
                            completeOnFocus
                            :delay="0"
                            appendTo="self"
                            :inputProps="{
                                maxlength: 120,
                                required: true,
                                'aria-describedby': errors.incident
                                    ? 'resident-incident-error'
                                    : 'resident-incident-help',
                            }"
                            :invalid="!!errors.incident"
                            placeholder="Select or type an incident"
                            :pt="{
                                overlay: { class: 'resident-report-overlay' },
                                dropdown: { 'aria-label': 'Show incident suggestions' },
                                transition: { css: false },
                            }"
                            @complete="suggest"
                            @update:modelValue="updateIncident"
                        />
                    </InputGroup>
                    <small v-if="errors.incident" id="resident-incident-error" class="resident-error" role="alert">{{
                        errors.incident
                    }}</small>
                    <small v-else id="resident-incident-help">Choose an active incident or enter your own.</small>
                </div>
                <div class="report-field">
                    <label for="resident-observed-at">Observed at <span class="report-optional">Optional</span></label>
                    <DatePicker
                        inputId="resident-observed-at"
                        :modelValue="localDateTimeToDate(form.observed_at)"
                        dateFormat="M d, yy"
                        showTime
                        hourFormat="24"
                        showIcon
                        iconDisplay="input"
                        size="small"
                        appendTo="self"
                        placeholder="Date and time"
                        :navigatorButtonProps="{ size: 'small', severity: 'secondary', text: true }"
                        :timepickerButtonProps="{ size: 'small', severity: 'secondary', text: true }"
                        :pt="{
                            panel: { class: 'resident-report-overlay resident-report-calendar' },
                            selectMonth: { 'aria-label': null, title: 'Choose month' },
                            selectYear: { 'aria-label': null, title: 'Choose year' },
                            day: ({ context }) => ({
                                role: 'button',
                                'aria-selected': null,
                                'aria-pressed': context.selected,
                            }),
                            timePicker: { role: 'group', 'aria-label': 'Time in 24-hour format' },
                            transition: { css: false },
                        }"
                        @show="fitObservedCalendar"
                        @update:modelValue="(value) => (form.observed_at = dateToLocalDateTime(value))"
                    />
                    <label v-if="form.observed_at" class="resident-check" for="resident-observed-approximate"
                        ><Checkbox
                            inputId="resident-observed-approximate"
                            v-model="form.observed_at_approximate"
                            binary
                            size="small"
                        />Time is approximate</label
                    >
                </div>
            </div>
            <p v-if="selected" class="report-field-note">
                {{ selected.type || 'Incident' }} · Official date: {{ selected.occurred_at || 'Not recorded' }}
            </p>
            <div class="report-field">
                <label for="resident-description" class="resident-req">Describe the incident and visible damage</label>
                <Textarea
                    id="resident-description"
                    ref="descriptionEl"
                    v-model="form.incident_description"
                    maxlength="5000"
                    rows="4"
                    required
                    autoResize
                    size="small"
                    :invalid="!!errors.description"
                    :aria-describedby="
                        errors.description
                            ? 'resident-description-error resident-description-count'
                            : 'resident-description-count'
                    "
                    placeholder="For example: Floodwater entered the ground floor and damaged the walls and doors."
                />
                <div class="report-field-meta">
                    <small
                        v-if="errors.description"
                        id="resident-description-error"
                        class="resident-error"
                        role="alert"
                        >{{ errors.description }}</small><small
                        id="resident-description-count"
                        class="resident-wizard-count"
                        >{{ form.incident_description.length }} / 5,000</small
                    >
                </div>
            </div>
        </section>
        <section class="report-form-section" aria-labelledby="report-location-heading">
            <div class="report-section-heading">
                <h4 id="report-location-heading">Where did it happen?</h4>
                <span><MapIcon name="pin" />{{ profile.barangay_name || 'Barangay not assigned' }}</span>
            </div>
            <div class="report-field">
                <label for="resident-location" class="resident-req">Purok, street, or landmark</label>
                <Textarea
                    id="resident-location"
                    ref="locationEl"
                    v-model="form.reported_location"
                    maxlength="2000"
                    rows="2"
                    required
                    autoResize
                    size="small"
                    :invalid="!!errors.location"
                    :aria-describedby="errors.location ? 'resident-location-error' : undefined"
                    placeholder="Include a nearby landmark to help staff find the building."
                />
                <small v-if="errors.location" id="resident-location-error" class="resident-error" role="alert">{{
                    errors.location
                }}</small>
            </div>
            <div
                ref="pinWrap"
                class="report-pin-field"
                :aria-describedby="errors.pin ? 'resident-pin-error' : 'resident-pin-help'"
            >
                <p id="resident-pin-help">
                    Place the pin on the affected building, then select <strong>Confirm pin</strong>.
                </p>
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
                <small v-if="errors.pin" id="resident-pin-error" class="resident-error" role="alert">{{
                    errors.pin
                }}</small>
            </div>
        </section>
        <section class="report-form-section" aria-labelledby="report-building-heading">
            <div class="report-section-heading">
                <h4 id="report-building-heading">Registered building</h4>
                <span class="report-optional">From your registry record</span>
            </div>
            <div v-if="(profile.linked_buildings || []).length" class="report-building">
                <template v-if="profile.linked_buildings.length === 1">
                    <dl>
                        <div>
                            <dt>Building reference</dt>
                            <dd>{{ profile.linked_buildings[0].building_reference || '—' }}</dd>
                        </div>
                        <div>
                            <dt>Household reference</dt>
                            <dd>{{ profile.linked_buildings[0].household_reference || '—' }}</dd>
                        </div>
                        <div v-if="profile.linked_buildings[0].address">
                            <dt>Registered address</dt>
                            <dd>{{ profile.linked_buildings[0].address }}</dd>
                        </div>
                    </dl>
                </template>
                <div v-else class="report-field">
                    <label for="resident-affected-building">Affected building</label>
                    <Select
                        inputId="resident-affected-building"
                        :modelValue="form.affected_building_id || null"
                        :options="buildingOptions"
                        optionLabel="label"
                        optionValue="value"
                        placeholder="Select a building"
                        showClear
                        size="small"
                        appendTo="self"
                        :pt="{ overlay: { class: 'resident-report-overlay' }, transition: { css: false } }"
                        @update:modelValue="updateAffectedBuilding"
                    />
                </div>
                <label class="resident-check" for="resident-confirm-affected-building"
                    ><Checkbox
                        inputId="resident-confirm-affected-building"
                        v-model="form.confirm_affected_building"
                        binary
                        size="small"
                        :disabled="!form.affected_building_id"
                    />This is the building affected by the incident.</label
                >
                <p v-if="!form.confirm_affected_building" class="report-field-note">
                    For a different building, leave this unchecked. Staff will verify the building link.
                </p>
            </div>
            <p v-else class="report-field-note">
                No verified building is linked to your registry record. You can still report; staff will verify the
                building link.
            </p>
        </section>
        <p class="report-reporter">
            <MapIcon name="user" /><span
                >Reported by <strong>{{ profile.name }}</strong><small
                    >{{ profile.email
                    }}<template v-if="profile.contact_number"> · {{ profile.contact_number }}</template></small
                ></span
            >
        </p>
    </section>
</template>
