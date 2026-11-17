<script setup>
import { computed, ref, watch } from 'vue';
import Button from 'primevue/button';
import Dialog from 'primevue/dialog';
import Message from 'primevue/message';
const props = defineProps({ target: { type: Object, default: null } });
const emit = defineEmits(['close', 'saved', 'hide']);
const saving = ref(false);
const error = ref('');
const closing = computed(() => props.target?.next === 'closed');
const actionLabel = computed(() => closing.value ? 'Close incident' : 'Reopen incident');
watch(() => props.target, () => { error.value = ''; });
async function save() {
    if (saving.value || !props.target) return;
    saving.value = true;
    error.value = '';
    try {
        await window.axios.patch(`/cdrrmo/api/incidents/${props.target.id}`, { incident_status: props.target.next, confirm_close: closing.value });
        emit('saved', closing.value ? 'Incident closed. Reports were not approved or completed.' : 'Incident reopened.');
    } catch (exception) {
        error.value = exception.response?.data?.message || 'The incident status could not be changed. Try again.';
    } finally { saving.value = false; }
}
</script>

<template>
    <Dialog :visible="!!target" :header="actionLabel" modal :draggable="false" :closable="!saving" :close-on-escape="!saving" class="cdrrmo-incidents-overlay incident-status-dialog" @update:visible="!$event && $emit('close')" @after-hide="$emit('hide')">
        <template v-if="target">
            <p class="incident-confirm-name">{{ target.incident_name }}</p><p class="incident-reference">{{ target.incident_reference }}</p>
            <p v-if="closing" class="incident-confirm-copy">Closing this incident stops new resident reports for it. It does not approve or complete existing reports.</p>
            <p v-else class="incident-confirm-copy">Reopen this incident as an active incident?</p>
            <Message v-if="error" severity="error" :closable="false">{{ error }}</Message>
        </template>
        <template #footer><Button label="Cancel" severity="secondary" outlined size="small" autofocus :disabled="saving" @click="$emit('close')" /><Button :label="actionLabel" size="small" :loading="saving" :disabled="saving" @click="save" /></template>
    </Dialog>
</template>
