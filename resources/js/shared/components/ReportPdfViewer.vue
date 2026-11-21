<script setup>
import { computed, nextTick, onBeforeUnmount, onMounted, ref, watch } from 'vue';
import { getDocument, GlobalWorkerOptions } from 'pdfjs-dist';
import pdfWorker from 'pdfjs-dist/build/pdf.worker.min.mjs?url';
import MapIcon from './MapIcon.vue';

GlobalWorkerOptions.workerSrc = pdfWorker;

const props = defineProps({
    documentUrl: { type: String, required: true },
    presentation: { type: String, default: '' },
});
const emit = defineEmits(['error']);

const host = ref(null);
const stage = ref(null);
const pageCount = ref(0);
const pageNumber = ref(1);
const zoom = ref(1);
const fitWidth = ref(true);
const fitting = ref(false);
const absoluteScale = ref(1);
const pdfLoading = ref(true);
const pdfError = ref('');
const review = computed(() => props.presentation === 'review');
const zoomPercent = computed(() => Math.round(absoluteScale.value * 100));
let pdfDocument = null;
let renderToken = 0;
let observer = null;
let rendering = false;
let renderAgain = false;
let hostWidth = 0;
let reviewReady = false;

async function readError(exception) {
    const data = exception?.response?.data;
    if (data instanceof Blob) {
        try {
            const parsed = JSON.parse(await data.text());
            return parsed.message || 'The report PDF could not be prepared.';
        } catch {
            return 'The report PDF could not be prepared.';
        }
    }
    return exception?.response?.data?.message || exception?.message || 'The report PDF could not be prepared.';
}

async function loadPdf() {
    const token = ++renderToken;
    pdfDocument?.destroy();
    pdfDocument = null;
    pageCount.value = 0;
    pdfError.value = '';
    pdfLoading.value = true;
    reviewReady = false;
    if (!props.documentUrl) {
        pdfLoading.value = false;
        return;
    }
    try {
        const response = await window.axios.get(props.documentUrl, { responseType: 'blob' });
        if (token !== renderToken) return;
        const type = response.headers['content-type'] || '';
        if (!type.includes('pdf')) throw new Error('The report preview was not a PDF.');
        const data = new Uint8Array(await response.data.arrayBuffer());
        pdfDocument = await getDocument({ data }).promise;
        if (token !== renderToken) return;
        pageCount.value = pdfDocument.numPages;
        pageNumber.value = 1;
        pdfLoading.value = false;
        await nextTick();
        await renderPages(token);
    } catch (exception) {
        if (token !== renderToken) return;
        pdfLoading.value = false;
        pdfError.value = await readError(exception);
        emit('error', pdfError.value);
    }
}

async function renderPages(token, followUp = false) {
    if (!pdfDocument || !stage.value) return;
    if (rendering) {
        renderAgain = true;
        return;
    }
    rendering = true;
    renderAgain = false;
    const hostEl = host.value;
    const canRestore = review.value && hostEl && hostEl.scrollHeight > hostEl.clientHeight + 1;
    const scrollRatio = canRestore ? hostEl.scrollTop / (hostEl.scrollHeight - hostEl.clientHeight) : null;
    try {
        stage.value.replaceChildren();
        const hostStyles = host.value ? getComputedStyle(host.value) : null;
        const padX = hostStyles ? (parseFloat(hostStyles.paddingLeft) || 0) + (parseFloat(hostStyles.paddingRight) || 0) : 24;
        const slack = review.value ? 4 : 0;
        const width = Math.max(280, (host.value?.clientWidth || 320) - padX - slack);
        hostWidth = host.value?.clientWidth || width;
        let reviewScale = absoluteScale.value;
        for (let number = 1; number <= pdfDocument.numPages; number += 1) {
            if (token !== renderToken) return;
            const page = await pdfDocument.getPage(number);
            const base = page.getViewport({ scale: 1 });
            const fitted = width / base.width;
            let scale = fitWidth.value ? fitted : fitted * zoom.value;
            if (review.value) {
                const measured = host.value?.clientWidth || 0;
                if (!reviewReady && measured >= 64) {
                    const narrow = window.matchMedia('(max-width: 1023px)').matches;
                    fitting.value = narrow;
                    absoluteScale.value = fitted;
                    reviewReady = true;
                }
                if (reviewReady && fitting.value) absoluteScale.value = fitted;
                reviewScale = !reviewReady || fitting.value ? fitted : absoluteScale.value;
                scale = reviewScale;
            }
            const viewport = page.getViewport({ scale });
            const canvas = document.createElement('canvas');
            canvas.width = Math.floor(viewport.width);
            canvas.height = Math.floor(viewport.height);
            canvas.className = 'resident-report-sheet';
            canvas.dataset.page = String(number);
            canvas.setAttribute('role', 'img');
            canvas.setAttribute('aria-label', `Report page ${number} of ${pdfDocument.numPages}`);
            stage.value.appendChild(canvas);
            await page.render({ canvasContext: canvas.getContext('2d'), viewport }).promise;
        }
    } finally {
        rendering = false;
        if (scrollRatio != null && hostEl) {
            const max = hostEl.scrollHeight - hostEl.clientHeight;
            hostEl.scrollTop = Math.max(0, scrollRatio * max);
        }
        const width = host.value?.clientWidth || 0;
        const watchingFit = review.value ? fitting.value : fitWidth.value;
        const widthChanged = token === renderToken && watchingFit && Math.abs(width - hostWidth) >= 1;
        if (renderAgain) await renderPages(renderToken);
        else if (widthChanged && !followUp) await renderPages(renderToken, true);
    }
}

async function setZoom(nextFit, factor) {
    if (review.value) {
        if (nextFit) {
            fitting.value = true;
        } else {
            const current = absoluteScale.value;
            fitting.value = false;
            absoluteScale.value = Math.min(2.5, Math.max(0.75, current * factor));
        }
        await renderPages(renderToken);
        return;
    }
    fitWidth.value = nextFit;
    if (!nextFit) zoom.value = Math.min(2.5, Math.max(0.75, zoom.value * factor));
    await renderPages(renderToken);
}

function onScroll() {
    const sheets = [...(stage.value?.querySelectorAll('.resident-report-sheet') || [])];
    const top = host.value?.getBoundingClientRect().top || 0;
    let current = 1;
    sheets.forEach((sheet) => {
        if (sheet.getBoundingClientRect().top - top < 80) current = Number(sheet.dataset.page);
    });
    pageNumber.value = current || 1;
}

watch(() => props.documentUrl, () => {
    loadPdf();
});

onMounted(async () => {
    await loadPdf();
    observer = new ResizeObserver(() => {
        const width = host.value?.clientWidth || 0;
        if (review.value && !reviewReady) {
            renderPages(renderToken);
            return;
        }
        const watchingFit = review.value ? fitting.value : fitWidth.value;
        if (!watchingFit || !pdfDocument || rendering || Math.abs(width - hostWidth) < 1) return;
        renderPages(renderToken);
    });
    if (host.value) observer.observe(host.value);
});

onBeforeUnmount(() => {
    renderToken += 1;
    observer?.disconnect();
    pdfDocument?.destroy();
});

defineExpose({ reload: loadPdf });
</script>

<template>
    <div class="resident-report-view">
        <p v-if="pdfError" class="resident-error" role="alert">{{ pdfError }}</p>
        <div class="resident-report-zoom" role="toolbar" aria-label="Document zoom">
            <template v-if="review">
                <button type="button" aria-label="Zoom out" @click="setZoom(false, 0.85)"><MapIcon name="zoom-out" /></button>
                <span :aria-label="`Zoom ${zoomPercent} percent`">{{ zoomPercent }}%</span>
                <button type="button" aria-label="Zoom in" @click="setZoom(false, 1.15)"><MapIcon name="zoom-in" /></button>
                <button type="button" aria-label="Fit page width" @click="setZoom(true, 1)">Fit width</button>
            </template>
            <template v-else>
                <button type="button" aria-label="Zoom out" @click="setZoom(false, 0.85)"><MapIcon name="zoom-out" /></button>
                <button type="button" aria-label="Fit page width" @click="setZoom(true, 1)">Fit width</button>
                <button type="button" aria-label="Zoom in" @click="setZoom(false, 1.15)"><MapIcon name="zoom-in" /></button>
            </template>
            <span v-if="pageCount">Page {{ pageNumber }} of {{ pageCount }}</span>
        </div>
        <div ref="host" class="resident-report-host" @scroll="onScroll">
            <p v-if="pdfLoading">Preparing the report…</p>
            <div ref="stage" class="resident-report-stage"></div>
        </div>
    </div>
</template>
