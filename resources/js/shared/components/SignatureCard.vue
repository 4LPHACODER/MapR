<script setup>
import { onMounted, ref } from 'vue';

const props = defineProps({
    apiBase: { type: String, required: true },
});

const signature = ref(null);
const message = ref('');
const error = ref('');

async function load() {
    const { data } = await window.axios.get(`${props.apiBase}/signature`);
    signature.value = data.signature;
}

async function upload(event) {
    error.value = '';
    message.value = '';
    const file = event.target.files?.[0];
    event.target.value = '';
    if (!file) return;
    const body = new FormData();
    body.append('signature', file);
    try {
        const { data } = await window.axios.post(`${props.apiBase}/signature`, body);
        signature.value = data.signature;
        message.value = 'The electronic-signature image is saved on your profile. It does not sign a report.';
    } catch (exception) {
        error.value = exception.response?.data?.message || 'The electronic-signature image was not accepted.';
    }
}

async function remove() {
    error.value = '';
    const { data } = await window.axios.delete(`${props.apiBase}/signature`);
    signature.value = data.signature;
    message.value = 'The current electronic-signature image was removed. Signed reports keep the version they used.';
}

onMounted(load);
</script>

<template>
    <section class="cdrrmo-panel cdrrmo-profile-card">
        <h3>Signature</h3>
        <p>Electronic-signature image. Uploading, replacing, or removing this image does not sign a report.</p>
        <p v-if="signature">A PNG or JPEG image is on file ({{ signature.width }} by {{ signature.height }} pixels).</p>
        <p v-else class="cdrrmo-muted">No electronic-signature image is on file. An empty profile can still review a report.</p>
        <img v-if="signature" :src="`${apiBase}/signature/preview`" alt="Electronic-signature image" class="signature-preview">
        <div class="cdrrmo-actions">
            <label class="mapr-button mapr-button-secondary">
                {{ signature ? 'Replace image' : 'Upload image' }}
                <input type="file" accept="image/png,image/jpeg" hidden @change="upload">
            </label>
            <button v-if="signature" type="button" class="mapr-button mapr-button-secondary" @click="remove">Remove image</button>
        </div>
        <p v-if="message">{{ message }}</p>
        <p v-if="error" class="cdrrmo-error">{{ error }}</p>
    </section>
</template>
