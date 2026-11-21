<script setup>
import { computed, nextTick, onBeforeUnmount, ref, useId, watch } from 'vue';
import Button from 'primevue/button';
import Checkbox from 'primevue/checkbox';
import Dialog from 'primevue/dialog';
import InputText from 'primevue/inputtext';
import Message from 'primevue/message';
import SignaturePad from '../../resident/components/SignaturePad.vue';

const props = defineProps({
    open: { type: Boolean, default: false },
    reference: { type: String, default: '' },
    name: { type: String, default: '' },
    position: { type: String, default: '' },
    capacity: { type: String, default: '' },
    signaturePreviewUrl: { type: String, default: '' },
    busy: { type: Boolean, default: false },
    serverError: { type: String, default: '' },
});
const emit = defineEmits(['close', 'confirm']);
const id = useId();
const passwordId = `${id}-password`;
const confirmationId = `${id}-confirm`;
const confirmationError = ref(false);
const passwordError = ref(false);
const signatureError = ref('');
const confirmed = ref(false);
const pad = ref(null);
const uploadInput = ref(null);
const uploadedSignature = ref(null);
const uploadedPreview = ref('');
const useSaved = ref(false);
const previewReady = ref(false);
const previewUrl = computed(() => uploadedPreview.value || (useSaved.value ? props.signaturePreviewUrl : ''));
const preparing = ref(false);
const working = computed(() => props.busy || preparing.value);
const title = computed(() => props.signaturePreviewUrl ? 'Endorse and sign' : 'Review and sign');
const positionLabel = computed(() => props.position || ({
    barangay_signer: 'Barangay Captain',
    assessed_by: 'Assessor',
    verified_by: 'Verifying officer',
    noted_by: 'Reviewing officer',
    approved_by: 'Approving officer',
})[props.capacity]);

watch(() => props.open, () => {
    confirmed.value = false;
    confirmationError.value = false;
    passwordError.value = false;
    signatureError.value = '';
    useSaved.value = false;
    previewReady.value = false;
    clearUpload();
});

function clearUpload() {
    if (uploadedPreview.value) URL.revokeObjectURL(uploadedPreview.value);
    uploadedPreview.value = '';
    uploadedSignature.value = null;
}

onBeforeUnmount(clearUpload);

function selectUpload(event) {
    const file = event.target.files?.[0];
    event.target.value = '';
    if (!file) return;
    if (!['image/png', 'image/jpeg'].includes(file.type) || file.size === 0 || file.size > 2 * 1024 * 1024) {
        signatureError.value = 'Choose a PNG or JPEG signature image up to 2 MB.';
        return;
    }

    clearUpload();
    useSaved.value = false;
    previewReady.value = false;
    signatureError.value = '';
    uploadedSignature.value = file;
    uploadedPreview.value = URL.createObjectURL(file);
}

function drawSignature() {
    clearUpload();
    useSaved.value = false;
    previewReady.value = false;
    signatureError.value = '';
}

function toggleSignatureSource() {
    signatureError.value = '';
    previewReady.value = false;
    useSaved.value = !useSaved.value;
}

function previewFailed() {
    const uploaded = Boolean(uploadedSignature.value);
    drawSignature();
    signatureError.value = uploaded
        ? 'This image could not be opened. Try another PNG or JPEG.'
        : 'Your saved signature could not be loaded. Draw or upload a signature, or try loading it again.';
}

async function submit(event) {
    if (working.value) return;
    const form = event.currentTarget;
    const password = form.elements.namedItem('password');
    passwordError.value = password !== null && password.value === '';
    confirmationError.value = !confirmed.value;
    signatureError.value = '';
    if (passwordError.value || confirmationError.value) {
        const field = passwordError.value ? passwordId : confirmationId;
        await nextTick();
        document.getElementById(field)?.focus();
        return;
    }
    if (password) {
        emit('confirm', { confirmed: true, password: password.value });
        password.value = '';
        return;
    }

    preparing.value = true;
    try {
        const signature = useSaved.value ? null : (uploadedSignature.value || await pad.value?.exportPng());
        if (!signature && !previewReady.value) {
            signatureError.value = 'Draw, upload, or choose a saved signature.';
            return;
        }
        const body = new FormData();
        body.append('confirmed', '1');
        body.append('use_saved_signature', useSaved.value ? '1' : '0');
        if (signature) body.append('signature', signature, uploadedSignature.value?.name || 'signature.png');
        emit('confirm', body);
    } finally {
        preparing.value = false;
    }
}
</script>

<template>
    <Dialog :visible="open" :header="title" modal :closable="!working" :close-on-escape="!working"
        :style="{ width: 'min(30rem, calc(100% - 2rem))' }" @update:visible="emit('close')">
        <form class="sign-form" novalidate @submit.prevent="submit">
            <dl class="sign-record">
                <div>
                    <dt>Report reference</dt>
                    <dd>{{ reference }}</dd>
                </div>
                <div>
                    <dt>Signing as</dt>
                    <dd>{{ name }}<span v-if="positionLabel && positionLabel !== name"> · {{ positionLabel }}</span></dd>
                </div>
            </dl>
            <template v-if="signaturePreviewUrl">
                <p>Endorsing sends this report to RDANA for assessment.</p>
                <input ref="uploadInput" type="file" accept="image/png,image/jpeg" hidden @change="selectUpload">
                <SignaturePad v-show="!previewUrl" ref="pad" manual label="Draw your signature"
                    lead="Sign using a mouse, stylus, or touch." @pointerup="signatureError = ''">
                    <template #actions>
                        <Button type="button" size="small" severity="secondary" outlined label="Upload signature"
                            :disabled="working" @click="uploadInput?.click()" />
                        <Button type="button" size="small" severity="secondary" outlined label="Use saved signature"
                            :disabled="working" @click="toggleSignatureSource" />
                    </template>
                </SignaturePad>
                <div v-if="previewUrl" class="sign-preview">
                    <img :key="previewUrl" :src="previewUrl" :alt="uploadedSignature ? 'Uploaded signature' : 'Saved signature'"
                        @load="previewReady = true" @error="previewFailed">
                    <p v-if="!previewReady" role="status">Loading signature…</p>
                </div>
                <div v-if="previewUrl" class="sign-signature-actions">
                    <Button type="button" size="small" severity="secondary" outlined label="Draw a signature"
                        :disabled="working" @click="drawSignature" />
                    <Button type="button" size="small" severity="secondary" outlined :label="uploadedSignature ? 'Replace upload' : 'Upload signature'"
                        :disabled="working" @click="uploadInput?.click()" />
                </div>
                <p class="sign-upload-hint">PNG or JPEG · Up to 2 MB</p>
                <Message v-if="signatureError" size="small" severity="error" variant="simple">{{ signatureError }}</Message>
            </template>
            <div v-else class="sign-field">
                <label :for="passwordId">Account password</label>
                <InputText :id="passwordId" name="password" type="password" autocomplete="current-password" size="small"
                    fluid required autofocus :disabled="working" :invalid="passwordError"
                    :aria-describedby="passwordError ? `${passwordId}-error` : undefined" @input="passwordError = false" />
                <Message v-if="passwordError" :id="`${passwordId}-error`" size="small" severity="error" variant="simple">
                    Enter your account password.
                </Message>
            </div>
            <div class="sign-confirmation">
                <Checkbox v-model="confirmed" :input-id="confirmationId" binary :invalid="confirmationError" :disabled="working"
                    :pt="{ input: { 'aria-describedby': confirmationError ? `${confirmationId}-error` : undefined } }"
                    @update:model-value="confirmationError = false" />
                <label :for="confirmationId">I confirm that I reviewed this report.</label>
            </div>
            <Message v-if="confirmationError" :id="`${confirmationId}-error`" size="small" severity="error" variant="simple">
                Confirm that you reviewed the report before signing.
            </Message>
            <Message v-if="serverError" size="small" severity="error">{{ serverError }}</Message>
            <div class="sign-actions">
                <Button type="button" size="small" label="Cancel" severity="secondary" outlined :disabled="working" @click="emit('close')" />
                <Button type="submit" size="small" :label="title" :loading="working" :disabled="Boolean(previewUrl) && !previewReady" />
            </div>
        </form>
    </Dialog>
</template>

<style scoped>
.sign-form {
    display: grid;
    gap: 1rem;
    color: var(--p-surface-800);
    font-size: 0.875rem;
    line-height: 1.4;
    overflow-wrap: anywhere;
}

.sign-record {
    display: grid;
    gap: 0.625rem;
    padding: 0.75rem 0.875rem;
    border: 1px solid var(--p-surface-200);
    border-radius: var(--p-border-radius-sm);
    background: var(--p-surface-50);
}

.sign-record dt {
    color: var(--p-surface-600);
    font-size: 0.75rem;
}

.sign-record dd,
.sign-field label {
    font-weight: 600;
}

.sign-field,
.sign-form :deep(.resident-signature) {
    display: grid;
    gap: 0.375rem;
}

.sign-form :deep(.resident-signature h3) {
    font-size: inherit;
    font-weight: 600;
}

.sign-form :deep(.resident-signature canvas),
.sign-preview {
    width: 100%;
    height: 7.5rem;
    border: 1px solid var(--p-surface-300);
    border-radius: var(--p-border-radius-sm);
    background: var(--p-surface-0);
}

.sign-form :deep(.resident-signature canvas) {
    touch-action: none;
}

.sign-preview {
    display: grid;
    place-items: center;
    overflow: hidden;
}

.sign-preview img {
    width: 100%;
    height: 100%;
    object-fit: contain;
}

.sign-preview:has(p) img {
    visibility: hidden;
}

.sign-preview > * {
    grid-area: 1 / 1;
}

.sign-upload-hint {
    color: var(--p-surface-600);
    font-size: 0.75rem;
}

.sign-signature-actions,
.sign-form :deep(.resident-signature-actions) {
    display: flex;
    flex-wrap: wrap;
    gap: 0.5rem;
}

.sign-confirmation {
    display: flex;
    align-items: center;
    gap: 0.625rem;
}

.sign-confirmation label {
    cursor: pointer;
}

.sign-actions {
    display: flex;
    flex-wrap: wrap;
    justify-content: flex-end;
    gap: 0.5rem;
    padding-top: 0.875rem;
    border-top: 1px solid var(--p-surface-200);
}

.sign-form :is(.p-button, .p-inputtext),
.sign-form :deep(.resident-signature-actions .p-button) {
    min-height: 2.25rem;
    border-radius: var(--p-border-radius-sm);
}

@media (pointer: coarse) {
    .sign-form :is(.p-button, .p-inputtext),
    .sign-form :deep(.resident-signature-actions .p-button) {
        min-height: 2.75rem;
    }

    .sign-field .p-inputtext {
        font-size: 1rem;
    }
}
</style>
