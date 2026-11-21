<script setup>
import { computed, inject, nextTick, onMounted, onUnmounted, ref, watch } from 'vue';
import MapIcon from './MapIcon.vue';
import SignaturePad from '../../resident/components/SignaturePad.vue';

const props = defineProps({
    open: { type: Boolean, default: false },
    reference: { type: String, default: '' },
    name: { type: String, default: '' },
    position: { type: String, default: '' },
    capacity: { type: String, default: '' },
    title: { type: String, default: 'Add Electronic Signature' },
    subtitle: {
        type: String,
        default: 'Draw or upload your signature to complete your authorized report endorsement.',
    },
    /** When true, require draw/upload (or saved profile signature) before confirm. */
    captureSignature: { type: Boolean, default: false },
});
const emit = defineEmits(['close', 'confirm']);

const apiBase = inject('apiBase', '');
const password = ref('');
const confirmed = ref(false);
const error = ref('');
const showPassword = ref(false);
const tab = ref('draw');
const step = ref('capture');
const pad = ref(null);
const fileInput = ref(null);
const uploadPreview = ref('');
const uploadFile = ref(null);
const previewUrl = ref('');
const useSaved = ref(false);
const hasSavedSignature = ref(false);
const savedPreviewUrl = ref('');
const signingAt = ref('');
let objectUrls = [];

const canContinue = computed(() => {
    if (!props.captureSignature) return true;
    if (useSaved.value && hasSavedSignature.value) return true;
    if (tab.value === 'draw') return Boolean(pad.value?.hasInk?.());
    return Boolean(uploadFile.value);
});

function trackUrl(url) {
    if (url) objectUrls.push(url);
    return url;
}

function revokeTracked() {
    objectUrls.forEach((url) => URL.revokeObjectURL(url));
    objectUrls = [];
}

watch(() => props.open, async (open) => {
    if (!open) {
        resetState();
        return;
    }
    password.value = '';
    confirmed.value = false;
    error.value = '';
    showPassword.value = false;
    tab.value = 'draw';
    step.value = 'capture';
    useSaved.value = false;
    uploadFile.value = null;
    uploadPreview.value = '';
    previewUrl.value = '';
    signingAt.value = '';
    if (props.captureSignature) {
        await loadSavedSignature();
        await nextTick();
        pad.value?.clearDrawing?.();
    }
});

async function loadSavedSignature() {
    hasSavedSignature.value = false;
    savedPreviewUrl.value = '';
    if (!apiBase) return;
    try {
        const { data } = await window.axios.get(`${apiBase}/signature`);
        if (data?.signature?.preview_url) {
            hasSavedSignature.value = true;
            savedPreviewUrl.value = data.signature.preview_url;
        }
    } catch {
        hasSavedSignature.value = false;
    }
}

function resetState() {
    revokeTracked();
    password.value = '';
    confirmed.value = false;
    error.value = '';
    uploadFile.value = null;
    uploadPreview.value = '';
    previewUrl.value = '';
    useSaved.value = false;
    step.value = 'capture';
}

function onKeydown(event) {
    if (!props.open) return;
    if (event.key === 'Escape') {
        event.preventDefault();
        emit('close');
    }
}

onMounted(() => window.addEventListener('keydown', onKeydown));
onUnmounted(() => {
    window.removeEventListener('keydown', onKeydown);
    resetState();
});

function selectTab(next) {
    tab.value = next;
    useSaved.value = false;
    error.value = '';
}

function chooseSaved() {
    if (!hasSavedSignature.value) {
        error.value = 'No saved signature is available on your profile.';
        return;
    }
    useSaved.value = true;
    uploadFile.value = null;
    uploadPreview.value = '';
    pad.value?.clearDrawing?.();
    error.value = '';
}

function onFileChange(event) {
    const file = event.target.files?.[0];
    event.target.value = '';
    error.value = '';
    useSaved.value = false;
    if (!file) return;
    const allowed = ['image/png', 'image/jpeg', 'image/jpg'];
    if (!allowed.includes(file.type) || file.size < 1 || file.size > 2 * 1024 * 1024) {
        error.value = 'Upload a PNG or JPEG signature image up to 2 MB.';
        return;
    }
    uploadFile.value = file;
    if (uploadPreview.value) URL.revokeObjectURL(uploadPreview.value);
    uploadPreview.value = trackUrl(URL.createObjectURL(file));
}

function removeUpload() {
    if (uploadPreview.value) URL.revokeObjectURL(uploadPreview.value);
    uploadPreview.value = '';
    uploadFile.value = null;
}

async function goPreview() {
    error.value = '';
    if (!props.captureSignature) {
        step.value = 'confirm';
        return;
    }
    if (useSaved.value && hasSavedSignature.value) {
        previewUrl.value = savedPreviewUrl.value;
        signingAt.value = new Date().toLocaleString();
        step.value = 'confirm';
        return;
    }
    if (tab.value === 'upload') {
        if (!uploadFile.value) {
            error.value = 'Choose a signature image before continuing.';
            return;
        }
        previewUrl.value = uploadPreview.value;
        signingAt.value = new Date().toLocaleString();
        step.value = 'confirm';
        return;
    }
    if (!pad.value?.hasInk?.()) {
        error.value = 'Draw your signature before continuing.';
        return;
    }
    const blob = await pad.value.exportPng();
    if (!blob) {
        error.value = 'The signature could not be read.';
        return;
    }
    if (previewUrl.value && previewUrl.value.startsWith('blob:')) URL.revokeObjectURL(previewUrl.value);
    previewUrl.value = trackUrl(URL.createObjectURL(blob));
    uploadFile.value = new File([blob], 'signature.png', { type: 'image/png' });
    signingAt.value = new Date().toLocaleString();
    step.value = 'confirm';
}

async function submit() {
    if (!confirmed.value || password.value === '') {
        error.value = 'Confirm the statement and enter your account password.';
        return;
    }
    if (props.captureSignature && !canContinue.value && step.value === 'capture') {
        error.value = 'Draw a signature, upload an image, or use your saved signature.';
        return;
    }
    if (props.captureSignature && step.value === 'capture') {
        await goPreview();
        if (step.value !== 'confirm') return;
    }
    if (props.captureSignature && step.value === 'confirm' && !useSaved.value && !uploadFile.value && !pad.value?.hasInk?.()) {
        error.value = 'A signature image is required.';
        return;
    }

    const payload = {
        confirmed: true,
        password: password.value,
        use_saved_signature: Boolean(useSaved.value),
        signatureFile: useSaved.value ? null : uploadFile.value,
    };
    emit('confirm', payload);
    password.value = '';
}

function backToEdit() {
    step.value = 'capture';
    error.value = '';
}
</script>

<template>
    <div
        v-if="open"
        class="residents-dialog-backdrop"
        @click.self="emit('close')"
    >
        <section
            class="cdrrmo-panel review-sign-dialog"
            :class="{ 'review-sign-dialog--wide': captureSignature }"
            role="dialog"
            aria-modal="true"
            aria-labelledby="review-sign-title"
        >
            <header class="review-sign-dialog__header">
                <h3 id="review-sign-title">{{ step === 'confirm' ? 'Confirm Electronic Signature' : title }}</h3>
                <p>{{ step === 'confirm' ? 'Review your signature and authorize its application to this report.' : subtitle }}</p>
            </header>

            <dl class="review-sign-dialog__meta">
                <div>
                    <dt>Reference</dt>
                    <dd>{{ reference || '—' }}</dd>
                </div>
                <div>
                    <dt>Name</dt>
                    <dd>{{ name || '—' }}</dd>
                </div>
                <div>
                    <dt>Position</dt>
                    <dd>{{ position || '—' }}</dd>
                </div>
                <div>
                    <dt>Capacity</dt>
                    <dd>{{ capacity || '—' }}</dd>
                </div>
            </dl>

            <template v-if="captureSignature && step === 'capture'">
                <div class="review-sign-dialog__tabs" role="tablist">
                    <button type="button" role="tab" :aria-selected="tab === 'draw'" @click="selectTab('draw')">
                        <MapIcon name="pen-line" /> Draw Signature
                    </button>
                    <button type="button" role="tab" :aria-selected="tab === 'upload'" @click="selectTab('upload')">
                        <MapIcon name="download" /> Upload Signature
                    </button>
                </div>

                <div v-show="tab === 'draw'" class="review-sign-dialog__panel">
                    <SignaturePad
                        ref="pad"
                        manual
                        label="Draw Signature"
                        lead="Sign using your mouse, finger, or stylus. Clear or undo before continuing if needed."
                    />
                </div>

                <div v-show="tab === 'upload'" class="review-sign-dialog__panel review-sign-dialog__upload">
                    <input
                        ref="fileInput"
                        type="file"
                        class="sr-only"
                        accept="image/png,image/jpeg,image/jpg"
                        @change="onFileChange"
                    >
                    <button type="button" class="review-sign-dialog__drop" @click="fileInput?.click()">
                        <MapIcon name="file" />
                        <strong>Choose signature image</strong>
                        <span>PNG or JPEG · Up to 2 MB · Transparent PNG preferred</span>
                    </button>
                    <img v-if="uploadPreview" :src="uploadPreview" alt="Uploaded signature preview" class="signature-preview">
                    <div class="review-sign-dialog__inline-actions">
                        <button v-if="uploadFile" type="button" class="mapr-button mapr-button-secondary" @click="removeUpload">Remove Image</button>
                    </div>
                </div>

                <div v-if="hasSavedSignature" class="review-sign-dialog__saved">
                    <img v-if="savedPreviewUrl" :src="savedPreviewUrl" alt="Saved profile signature" class="signature-preview">
                    <button
                        type="button"
                        class="mapr-button mapr-button-secondary"
                        :aria-pressed="useSaved"
                        @click="chooseSaved"
                    >
                        Use saved signature
                    </button>
                    <span v-if="useSaved" class="review-sign-dialog__saved-note">Saved signature selected.</span>
                </div>
            </template>

            <template v-if="!captureSignature || step === 'confirm'">
                <div v-if="captureSignature" class="review-sign-dialog__preview-block">
                    <h4>Signature Preview</h4>
                    <img v-if="previewUrl" :src="previewUrl" alt="Signature preview" class="signature-preview">
                    <p class="review-sign-dialog__saved-note">Signed at {{ signingAt || '—' }}</p>
                </div>

                <label class="bdrrm-confirm review-sign-dialog__confirm">
                    <input v-model="confirmed" type="checkbox">
                    <span>
                        I confirm that this is my electronic signature and authorize its application to the specified report in my assigned signing capacity.
                    </span>
                </label>

                <label class="review-sign-dialog__password">
                    <span>Account password</span>
                    <div class="review-sign-dialog__password-row">
                        <input
                            v-model="password"
                            :type="showPassword ? 'text' : 'password'"
                            autocomplete="current-password"
                            @keydown.enter.prevent="submit"
                        >
                        <button
                            type="button"
                            class="review-sign-dialog__toggle"
                            :aria-label="showPassword ? 'Hide password' : 'Show password'"
                            :aria-pressed="showPassword"
                            @click="showPassword = !showPassword"
                        >
                            <MapIcon name="eye" />
                        </button>
                    </div>
                </label>
            </template>

            <p v-if="error" class="cdrrmo-error" role="alert">{{ error }}</p>

            <div class="cdrrmo-actions review-sign-dialog__actions">
                <button type="button" class="mapr-button mapr-button-secondary" @click="emit('close')">Cancel</button>
                <button
                    v-if="captureSignature && step === 'confirm'"
                    type="button"
                    class="mapr-button mapr-button-secondary"
                    @click="backToEdit"
                >
                    Back to Edit
                </button>
                <button
                    v-if="captureSignature && step === 'capture'"
                    type="button"
                    class="mapr-button mapr-button-primary"
                    @click="goPreview"
                >
                    Preview Signature
                </button>
                <button
                    v-else
                    type="button"
                    class="mapr-button mapr-button-primary"
                    @click="submit"
                >
                    {{ captureSignature ? 'Confirm & Sign' : 'Review and Sign' }}
                </button>
            </div>
        </section>
    </div>
</template>

<style scoped>
.review-sign-dialog--wide {
    width: min(36rem, 100%);
    max-width: 40rem;
}

.review-sign-dialog__tabs {
    display: flex;
    flex-wrap: wrap;
    gap: 0.4rem;
}

.review-sign-dialog__tabs button {
    display: inline-flex;
    align-items: center;
    gap: 0.4rem;
    border: 1px solid #cbd5e1;
    border-radius: 999px;
    background: #f8fafc;
    color: #334155;
    font: inherit;
    font-size: 0.8125rem;
    font-weight: 600;
    padding: 0.4rem 0.75rem;
    cursor: pointer;
}

.review-sign-dialog__tabs button[aria-selected='true'] {
    background: #1e3a8a;
    border-color: #1e3a8a;
    color: #fff;
}

.review-sign-dialog__panel {
    display: grid;
    gap: 0.65rem;
}

.review-sign-dialog__upload {
    gap: 0.75rem;
}

.review-sign-dialog__drop {
    display: grid;
    justify-items: center;
    gap: 0.35rem;
    width: 100%;
    padding: 1.1rem 1rem;
    border: 1px dashed #94a3b8;
    border-radius: 0.85rem;
    background: #f8fafc;
    color: #475569;
    font: inherit;
    cursor: pointer;
    text-align: center;
}

.review-sign-dialog__drop strong {
    color: #0f172a;
}

.review-sign-dialog__drop span {
    font-size: 0.78rem;
}

.review-sign-dialog__inline-actions,
.review-sign-dialog__saved {
    display: flex;
    flex-wrap: wrap;
    align-items: center;
    gap: 0.5rem;
}

.review-sign-dialog__saved-note {
    color: #64748b;
    font-size: 0.8125rem;
}

.review-sign-dialog__preview-block h4 {
    margin: 0 0 0.35rem;
    font-size: 0.9rem;
    color: #123b70;
}

.signature-preview {
    display: block;
    max-width: 100%;
    max-height: 7rem;
    object-fit: contain;
    background: #fff;
    border: 1px solid #e2e8f0;
    border-radius: 0.5rem;
    padding: 0.35rem;
}
</style>
