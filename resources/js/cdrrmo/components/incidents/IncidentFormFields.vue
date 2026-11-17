<script setup>
import DatePicker from 'primevue/datepicker';
import InputText from 'primevue/inputtext';
import Select from 'primevue/select';
import Textarea from 'primevue/textarea';
import { dateToLocalDateTime, localDateTimeToDate } from '../../../shared/ui/dates.js';
const form = defineModel({ type: Object, required: true });
defineProps({ types: { type: Array, default: () => [] }, errors: { type: Object, default: () => ({}) }, reference: { type: String, default: '' }, disabled: Boolean });
const statuses = [{ label: 'Active', value: 'active' }, { label: 'Closed', value: 'closed' }];
</script>

<template>
    <div class="incident-form-fields">
        <div class="incident-field">
            <label for="incident-name">Incident Name <span aria-hidden="true">*</span></label>
            <InputText id="incident-name" v-model="form.incident_name" autofocus required maxlength="191" placeholder="Name of the disaster event" autocomplete="off" size="small" :disabled="disabled" :invalid="!!errors.incident_name" :aria-invalid="!!errors.incident_name" :aria-describedby="errors.incident_name ? 'incident-name-error' : undefined" />
            <small v-if="errors.incident_name" id="incident-name-error" class="incident-field-error">{{ errors.incident_name[0] }}</small>
        </div>
        <div class="incident-field">
            <label id="incident-type-label" for="incident-type">Disaster Type <span aria-hidden="true">*</span></label>
            <Select input-id="incident-type" aria-labelledby="incident-type-label" v-model="form.disaster_type_id" :options="types.map((type) => ({ label: type.name, value: String(type.id) }))" option-label="label" option-value="value" placeholder="Choose a disaster type" size="small" :disabled="disabled" :invalid="!!errors.disaster_type_id" :aria-invalid="!!errors.disaster_type_id" :aria-describedby="errors.disaster_type_id ? 'incident-type-error' : undefined" panel-class="cdrrmo-incidents-overlay" :pt="{ transition: { css: false }, label: { 'aria-required': true, 'aria-describedby': errors.disaster_type_id ? 'incident-type-error' : undefined } }" />
            <small v-if="errors.disaster_type_id" id="incident-type-error" class="incident-field-error">{{ errors.disaster_type_id[0] }}</small>
        </div>
        <div class="incident-field">
            <label for="incident-occurred">Date and Time of Occurrence <span aria-hidden="true">*</span></label>
            <DatePicker input-id="incident-occurred" :model-value="localDateTimeToDate(form.occurred_at)" date-format="dd M yy" show-time hour-format="24" :manual-input="false" show-icon icon-display="input" placeholder="Select date and time" size="small" :disabled="disabled" :invalid="!!errors.occurred_at" panel-class="cdrrmo-incidents-overlay incident-calendar" :pt="{ transition: { css: false }, pcInputText: { root: { 'aria-required': true, 'aria-invalid': !!errors.occurred_at, 'aria-describedby': `incident-time-help${errors.occurred_at ? ' incident-time-error' : ''}` } } }" @update:model-value="form.occurred_at = dateToLocalDateTime($event)" />
            <small id="incident-time-help" class="incident-help">Philippine time (Asia/Manila)</small>
            <small v-if="errors.occurred_at" id="incident-time-error" class="incident-field-error">{{ errors.occurred_at[0] }}</small>
        </div>
        <div class="incident-field">
            <label id="incident-status-label" for="incident-status">Status <span aria-hidden="true">*</span></label>
            <Select input-id="incident-status" aria-labelledby="incident-status-label" v-model="form.incident_status" :options="statuses" option-label="label" option-value="value" size="small" :disabled="disabled" :invalid="!!errors.incident_status" :aria-invalid="!!errors.incident_status" :aria-describedby="errors.incident_status ? 'incident-status-error' : undefined" panel-class="cdrrmo-incidents-overlay" :pt="{ transition: { css: false }, label: { 'aria-required': true, 'aria-describedby': errors.incident_status ? 'incident-status-error' : undefined } }" />
            <small v-if="errors.incident_status" id="incident-status-error" class="incident-field-error">{{ errors.incident_status[0] }}</small>
        </div>
        <div v-if="reference" class="incident-field incident-full-field"><label for="incident-reference">Incident reference</label><InputText id="incident-reference" :model-value="reference" readonly size="small" /></div>
        <div class="incident-field incident-full-field">
            <label for="incident-description">Description</label>
            <Textarea id="incident-description" v-model="form.description" auto-resize rows="3" maxlength="5000" placeholder="What happened, and which area needs attention?" :disabled="disabled" :invalid="!!errors.description" :aria-invalid="!!errors.description" :aria-describedby="errors.description ? 'incident-description-error' : undefined" />
            <small v-if="errors.description" id="incident-description-error" class="incident-field-error">{{ errors.description[0] }}</small>
        </div>
    </div>
</template>
