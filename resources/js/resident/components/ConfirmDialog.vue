<script setup>
import { onMounted, onUnmounted, ref } from 'vue';

defineProps({
    title: { type: String, required: true },
    message: { type: String, required: true },
    confirmLabel: { type: String, default: 'Confirm' },
});
const emit = defineEmits(['confirm', 'cancel']);
const dialog = ref(null);
const cancelButton = ref(null);

function onKeydown(event) {
    if (event.key === 'Escape') emit('cancel');
}

onMounted(() => {
    dialog.value?.showModal();
    cancelButton.value?.focus();
    window.addEventListener('keydown', onKeydown);
});
onUnmounted(() => window.removeEventListener('keydown', onKeydown));
</script>

<template>
    <dialog ref="dialog" class="resident-dialog" aria-modal="true" @cancel.prevent="emit('cancel')">
        <h2>{{ title }}</h2>
        <p>{{ message }}</p>
        <div class="resident-dialog-actions">
            <button ref="cancelButton" type="button" class="resident-secondary" @click="emit('cancel')">Cancel</button>
            <button type="button" class="resident-primary" @click="emit('confirm')">{{ confirmLabel }}</button>
        </div>
    </dialog>
</template>
