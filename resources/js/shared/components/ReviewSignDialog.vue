<script setup>
import { ref, watch } from 'vue';

const props = defineProps({
    open: { type: Boolean, default: false },
    reference: { type: String, default: '' },
    name: { type: String, default: '' },
    position: { type: String, default: '' },
    capacity: { type: String, default: '' },
});
const emit = defineEmits(['close', 'confirm']);
const password = ref('');
const confirmed = ref(false);
const error = ref('');

watch(() => props.open, (open) => {
    if (!open) return;
    password.value = '';
    confirmed.value = false;
    error.value = '';
});

function submit() {
    if (!confirmed.value || password.value === '') {
        error.value = 'Confirm the review and enter the account password.';
        return;
    }
    emit('confirm', { confirmed: true, password: password.value });
    password.value = '';
}
</script>

<template>
    <div v-if="open" class="residents-drawer-backdrop">
        <section class="cdrrmo-panel review-sign-dialog" role="dialog" aria-modal="true" aria-labelledby="review-sign-title">
            <h3 id="review-sign-title">Review and Sign</h3>
            <p><strong>Reference:</strong> {{ reference }}</p>
            <p><strong>Name:</strong> {{ name }}</p>
            <p><strong>Position:</strong> {{ position || '—' }}</p>
            <p><strong>Capacity:</strong> {{ capacity }}</p>
            <label><input v-model="confirmed" type="checkbox"> I confirm this review and sign action.</label>
            <label>Account password <input v-model="password" type="password" autocomplete="current-password"></label>
            <p v-if="error" class="cdrrmo-error">{{ error }}</p>
            <div class="cdrrmo-actions">
                <button type="button" class="mapr-button mapr-button-secondary" @click="emit('close')">Cancel</button>
                <button type="button" class="mapr-button mapr-button-primary" @click="submit">Review and Sign</button>
            </div>
        </section>
    </div>
</template>
