<script setup>
import { inject, onBeforeUnmount, onMounted, ref } from 'vue';

const props = defineProps({
    recorded: { type: Boolean, default: false },
    manual: { type: Boolean, default: false },
    label: { type: String, default: 'Resident Electronic Signature' },
    lead: { type: String, default: 'Draw your signature below using your finger, mouse, or stylus.' },
});
const emit = defineEmits(['ready', 'busy']);

const apiBase = inject('apiBase');
const canvas = ref(null);
const saving = ref(false);
const status = ref('');
const error = ref('');
let strokes = [];
let current = null;
let drawing = false;
let loaded = null;
let observer = null;

function hasInk() {
    return strokes.some((stroke) => stroke.length > 1);
}

function point(event) {
    const rect = canvas.value.getBoundingClientRect();
    const width = rect.width || 1;
    const height = rect.height || 1;
    return {
        x: Math.min(1, Math.max(0, (event.clientX - rect.left) / width)),
        y: Math.min(1, Math.max(0, (event.clientY - rect.top) / height)),
    };
}

function paint() {
    const el = canvas.value;
    if (!el) return;
    const width = el.clientWidth;
    const height = el.clientHeight;
    if (width < 16 || height < 16) return;
    let ratio = window.devicePixelRatio || 1;
    ratio = Math.min(ratio, 2000 / width, 1000 / height);
    el.width = Math.round(width * ratio);
    el.height = Math.round(height * ratio);
    const context = el.getContext('2d');
    context.setTransform(ratio, 0, 0, ratio, 0, 0);
    context.fillStyle = '#ffffff';
    context.fillRect(0, 0, width, height);
    if (!strokes.length && loaded) context.drawImage(loaded, 0, 0, width, height);
    context.strokeStyle = '#172033';
    context.lineWidth = 2.5;
    context.lineCap = 'round';
    context.lineJoin = 'round';
    strokes.forEach((stroke) => {
        if (!stroke.length) return;
        context.beginPath();
        context.moveTo(stroke[0].x * width, stroke[0].y * height);
        stroke.forEach((entry) => context.lineTo(entry.x * width, entry.y * height));
        context.stroke();
    });
}

function start(event) {
    if (!canvas.value || (event.button != null && event.button !== 0)) return;
    canvas.value.setPointerCapture(event.pointerId);
    drawing = true;
    loaded = null;
    current = [point(event)];
    strokes.push(current);
    paint();
}

function move(event) {
    if (!drawing || !current) return;
    current.push(point(event));
    paint();
}

function end() {
    if (!drawing) return;
    drawing = false;
    current = null;
    if (!props.manual && hasInk()) save();
}

async function drawPreview(url) {
    const response = await window.axios.get(url, { responseType: 'blob' });
    const bitmap = await createImageBitmap(response.data);
    loaded = bitmap;
    strokes = [];
    paint();
}

async function save() {
    if (!hasInk() || saving.value || !canvas.value) return;
    saving.value = true;
    error.value = '';
    emit('busy', true);
    try {
        const blob = await new Promise((resolve) => canvas.value.toBlob(resolve, 'image/png'));
        if (!blob) throw new Error('The signature could not be saved.');
        const body = new FormData();
        body.append('signature', blob, 'signature.png');
        await window.axios.post(`${apiBase}/signature`, body);
        status.value = 'Signature saved.';
        emit('ready', true);
    } catch (exception) {
        status.value = '';
        error.value = exception.response?.data?.message || 'The signature could not be saved.';
        emit('ready', false);
    } finally {
        saving.value = false;
        emit('busy', false);
    }
}

function clearDrawing() {
    strokes = [];
    current = null;
    loaded = null;
    status.value = '';
    error.value = '';
    emit('ready', false);
    paint();
}

async function clearPad() {
    clearDrawing();
    if (props.manual) return;
    try {
        await window.axios.delete(`${apiBase}/signature`);
    } catch (exception) {
        error.value = exception.response?.data?.message || 'The signature could not be cleared.';
    }
}

function exportPng() {
    return new Promise((resolve) => {
        if (!canvas.value || !hasInk()) {
            resolve(null);
            return;
        }
        canvas.value.toBlob((blob) => resolve(blob), 'image/png');
    });
}

defineExpose({
    hasInk: () => hasInk(),
    exportPng,
    clearDrawing,
});

onMounted(async () => {
    paint();
    if (canvas.value) {
        observer = new ResizeObserver(() => paint());
        observer.observe(canvas.value);
    }
    if (props.manual) return;
    try {
        const signature = (await window.axios.get(`${apiBase}/signature`)).data.signature;
        if (signature?.preview_url) {
            await drawPreview(signature.preview_url);
            status.value = 'Signature saved.';
            emit('ready', true);
        }
    } catch {
        emit('ready', false);
    }
});

onBeforeUnmount(() => {
    observer?.disconnect();
    observer = null;
});
</script>

<template>
    <section class="resident-signature" aria-labelledby="resident-signature-label">
        <h3 id="resident-signature-label">{{ label }}</h3>
        <p class="resident-lead">{{ lead }}</p>
        <p v-if="recorded" class="resident-muted">The original signature remains on record.</p>
        <canvas
            ref="canvas"
            :aria-label="label"
            @pointerdown.prevent="start"
            @pointermove.prevent="move"
            @pointerup="end"
            @pointercancel="end"
        ></canvas>
        <div class="resident-signature-actions">
            <button type="button" class="resident-secondary" :disabled="saving" @click="clearPad">Clear Signature</button>
            <button v-if="error" type="button" class="resident-primary" :disabled="saving" @click="save">Retry</button>
        </div>
        <p v-if="saving" class="resident-muted" role="status">Saving signature…</p>
        <p v-else-if="status" class="resident-ok" role="status">{{ status }}</p>
        <p v-if="error" class="resident-error" role="alert">{{ error }}</p>
    </section>
</template>
