<script setup>
import Button from 'primevue/button';
import Checkbox from 'primevue/checkbox';
const selected = defineModel({ type: Array, required: true });
defineProps({ barangays: { type: Array, default: () => [] }, error: { type: String, default: '' }, disabled: Boolean });
</script>

<template>
    <fieldset class="incident-barangay-picker" :aria-describedby="`incident-barangay-help${error ? ' incident-barangay-error' : ''}`">
        <legend>Associated barangays <span aria-hidden="true">*</span></legend>
        <p id="incident-barangay-help" class="incident-help">Selecting a barangay links it to this incident. It does not mark residents or buildings as affected.</p>
        <div class="incident-barangay-toolbar"><span>{{ selected.length }} of {{ barangays.length }} selected</span><div><Button label="Select all" size="small" severity="secondary" text :disabled="disabled || selected.length === barangays.length" @click="selected = barangays.map((row) => Number(row.id))" /><Button label="Clear selection" size="small" severity="secondary" text :disabled="disabled || !selected.length" @click="selected = []" /></div></div>
        <div class="incident-barangay-options">
            <label v-for="barangay in barangays" :key="barangay.id" :for="`incident-barangay-${barangay.id}`" class="incident-barangay-option" :class="{ 'is-selected': selected.includes(Number(barangay.id)) }">
                <Checkbox v-model="selected" :input-id="`incident-barangay-${barangay.id}`" :value="Number(barangay.id)" :invalid="!!error" :disabled="disabled" :pt="{ input: { 'aria-invalid': !!error, 'aria-describedby': error ? 'incident-barangay-error' : undefined } }" />
                <span>{{ barangay.name }}</span>
            </label>
        </div>
        <small v-if="error" id="incident-barangay-error" class="incident-field-error">{{ error }}</small>
    </fieldset>
</template>
