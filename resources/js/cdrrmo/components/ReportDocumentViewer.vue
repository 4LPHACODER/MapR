<script setup>
import { nextTick, onBeforeUnmount, ref, watch } from 'vue';
import { getDocument, GlobalWorkerOptions } from 'pdfjs-dist';
import pdfWorker from 'pdfjs-dist/build/pdf.worker.min.mjs?url';
import MapIcon from '../../shared/components/MapIcon.vue';

GlobalWorkerOptions.workerSrc = pdfWorker;

const props = defineProps({
    sourceUrl: { type: String, default: '' },
    downloadUrl: { type: String, default: '' },
    modeLabel: { type: String, default: 'Document' },
});

const host = ref(null);
const stage = ref(null);
const status = ref('idle');
const message = ref('');
const pageCount = ref(0);
const pageNumber = ref(1);
const zoom = ref(1);
const fitWidth = ref(true);
let pdfDocument = null;
let renderToken = 0;

async function load() {
    const token = ++renderToken;
    pdfDocument = null;
    pageCount.value = 0;
    message.value = '';
    if (!props.sourceUrl) {
        status.value = 'empty';
        message.value = 'No document is available in this mode.';
        return;
    }
    status.value = 'loading';
    try {
        const response = await window.axios.get(props.sourceUrl, { responseType: 'blob' });
        if (token !== renderToken) return;
        const type = response.headers['content-type'] || '';
        if (!type.includes('pdf')) {
            throw new Error('The preview response was not a PDF.');
        }
        const data = new Uint8Array(await response.data.arrayBuffer());
        pdfDocument = await getDocument({ data }).promise;
        if (token !== renderToken) return;
        pageCount.value = pdfDocument.numPages;
        pageNumber.value = 1;
        status.value = 'ready';
        await nextTick();
        await renderPages(token);
    } catch (exception) {
        if (token !== renderToken) return;
        status.value = 'error';
        message.value = await readError(exception);
    }
}

async function renderPages(token) {
    if (!pdfDocument || !stage.value) return;
    stage.value.replaceChildren();
    const width = Math.max(280, (host.value?.clientWidth || 800) - 48);
    for (let number = 1; number <= pdfDocument.numPages; number += 1) {
        if (token !== renderToken) return;
        const page = await pdfDocument.getPage(number);
        const base = page.getViewport({ scale: 1 });
        const fitted = width / base.width;
        const scale = fitWidth.value ? fitted : fitted * zoom.value;
        const viewport = page.getViewport({ scale });
        const canvas = document.createElement('canvas');
        canvas.width = viewport.width;
        canvas.height = viewport.height;
        canvas.className = 'report-sheet';
        canvas.dataset.page = String(number);
        canvas.setAttribute('role', 'img');
        canvas.setAttribute('aria-label', `${props.modeLabel} page ${number} of ${pdfDocument.numPages}`);
        stage.value.appendChild(canvas);
        await page.render({ canvasContext: canvas.getContext('2d'), viewport }).promise;
    }
}

function showPage(number) {
    const next = Math.min(pageCount.value, Math.max(1, number));
    pageNumber.value = next;
    stage.value?.querySelector(`[data-page="${next}"]`)?.scrollIntoView({ block: 'start', behavior: window.matchMedia('(prefers-reduced-motion: reduce)').matches ? 'auto' : 'smooth' });
}

async function setZoom(nextFit, factor) {
    fitWidth.value = nextFit;
    if (!nextFit) zoom.value = Math.min(2.5, Math.max(0.6, zoom.value * factor));
    const token = renderToken;
    await renderPages(token);
    showPage(pageNumber.value);
}

function onScroll() {
    const sheets = [...(stage.value?.querySelectorAll('.report-sheet') || [])];
    const top = host.value?.getBoundingClientRect().top || 0;
    let current = 1;
    sheets.forEach((sheet) => {
        if (sheet.getBoundingClientRect().top - top < 80) current = Number(sheet.dataset.page);
    });
    pageNumber.value = current;
}

async function readError(exception) {
    const data = exception?.response?.data;
    if (data instanceof Blob) {
        try {
            const parsed = JSON.parse(await data.text());
            return parsed.message || 'The original document could not be converted.';
        } catch {
            return 'The original document could not be converted.';
        }
    }
    return exception?.response?.data?.message || exception?.message || 'The document could not be displayed.';
}

function printDocument() {
    window.print();
}

watch(() => props.sourceUrl, () => load(), { immediate: true });
onBeforeUnmount(() => {
    renderToken += 1;
    pdfDocument?.destroy();
});

defineExpose({ printDocument, reload: load });
</script>

<template>
    <div class="report-viewer">
        <div class="report-viewer-toolbar" role="toolbar" aria-label="Document pages">
            <button type="button" title="Zoom out" aria-label="Zoom out" @click="setZoom(false, 0.85)"><MapIcon name="zoom-out" /></button>
            <button type="button" title="Zoom in" aria-label="Zoom in" @click="setZoom(false, 1.15)"><MapIcon name="zoom-in" /></button>
            <button type="button" title="Fit width" aria-label="Fit width" @click="setZoom(true, 1)">Fit width</button>
            <button type="button" title="Previous page" aria-label="Previous page" :disabled="pageNumber <= 1" @click="showPage(pageNumber - 1)"><MapIcon name="chevron" class="is-left" /></button>
            <span v-if="pageCount">Page {{ pageNumber }} of {{ pageCount }}</span>
            <span v-else>Page —</span>
            <button type="button" title="Next page" aria-label="Next page" :disabled="pageNumber >= pageCount" @click="showPage(pageNumber + 1)"><MapIcon name="chevron" /></button>
            <a v-if="downloadUrl" :href="downloadUrl" title="Download" aria-label="Download this version"><MapIcon name="download" /> Download</a>
            <button type="button" title="Print" aria-label="Print" @click="printDocument"><MapIcon name="printer" /> Print</button>
        </div>
        <div ref="host" class="report-viewer-host" @scroll="onScroll">
            <p v-if="status === 'loading'" class="cdrrmo-muted">Preparing the {{ modeLabel.toLowerCase() }}…</p>
            <div v-else-if="status === 'error'" class="report-viewer-error" role="alert">
                <p>{{ message }}</p>
                <button type="button" class="resident-action resident-action-secondary" @click="load">Retry</button>
                <a v-if="downloadUrl" :href="downloadUrl">Download original</a>
            </div>
            <p v-else-if="status === 'empty'" class="cdrrmo-muted">{{ message }}</p>
            <div ref="stage" class="report-viewer-stage"></div>
        </div>
    </div>
</template>
