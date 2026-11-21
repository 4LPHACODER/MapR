<script setup>
import { computed, nextTick, onBeforeUnmount, onMounted, ref, watch } from 'vue';
import { getDocument, GlobalWorkerOptions } from 'pdfjs-dist';
import pdfWorker from 'pdfjs-dist/build/pdf.worker.min.mjs?url';
import Button from 'primevue/button';
import Message from 'primevue/message';
import MapIcon from './MapIcon.vue';

GlobalWorkerOptions.workerSrc = pdfWorker;

const props = defineProps({
    documentUrl: { type: String, required: true },
    presentation: { type: String, default: '' },
    fitOnOpen: { type: Boolean, default: false },
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
const resident = computed(() => props.presentation === 'resident');
const zoomPercent = computed(() => Math.round(absoluteScale.value * 100));
let pdfDocument = null;
let renderToken = 0;
let observer = null;
let rendering = false;
let renderAgain = false;
let hostWidth = 0;
let reviewReady = false;
let loadingTask = null;

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
    loadingTask?.destroy();
    loadingTask = null;
    pdfDocument = null;
    pageCount.value = 0;
    pdfError.value = '';
    pdfLoading.value = true;
    reviewReady = false;
    if (resident.value) stage.value?.replaceChildren();
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
        if (token !== renderToken) return;
        loadingTask = getDocument({ data });
        const document = await loadingTask.promise;
        if (token !== renderToken) return;
        pdfDocument = document;
        pageCount.value = pdfDocument.numPages;
        pageNumber.value = 1;
        pdfLoading.value = false;
        await nextTick();
        await renderPages(token);
    } catch (exception) {
        if (token !== renderToken) return;
        pdfLoading.value = false;
        const message = await readError(exception);
        if (token !== renderToken) return;
        pdfError.value = message;
        emit('error', message);
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
        if (!resident.value) stage.value.replaceChildren();
        const hostStyles = host.value ? getComputedStyle(host.value) : null;
        const padX = hostStyles ? (parseFloat(hostStyles.paddingLeft) || 0) + (parseFloat(hostStyles.paddingRight) || 0) : 24;
        const slack = review.value ? 4 : 0;
        const availableWidth = (host.value?.clientWidth || 320) - padX - slack;
        const width = resident.value
            ? Math.max(1, Math.min(794, availableWidth))
            : Math.max(props.fitOnOpen ? 1 : 280, availableWidth);
        hostWidth = host.value?.clientWidth || width;
        let reviewScale = absoluteScale.value;
        const firstPage = resident.value ? pageNumber.value : 1;
        const lastPage = resident.value ? firstPage : pdfDocument.numPages;
        for (let number = firstPage; number <= lastPage; number += 1) {
            if (token !== renderToken) return;
            const page = await pdfDocument.getPage(number);
            if (token !== renderToken) return;
            const base = page.getViewport({ scale: 1 });
            const fitted = width / base.width;
            let scale = fitWidth.value ? fitted : fitted * zoom.value;
            if (review.value) {
                const measured = host.value?.clientWidth || 0;
                if (!reviewReady && measured >= 64) {
                    fitting.value = props.fitOnOpen || window.matchMedia('(max-width: 1023px)').matches;
                    absoluteScale.value = fitting.value ? fitted : Math.min(1, fitted);
                    reviewReady = true;
                }
                if (reviewReady && fitting.value) absoluteScale.value = fitted;
                reviewScale = !reviewReady || fitting.value ? fitted : absoluteScale.value;
                scale = reviewScale;
            }
            const viewport = page.getViewport({ scale });
            const canvas = document.createElement('canvas');
            const pixelRatio = resident.value ? Math.min(window.devicePixelRatio || 1, 2) : 1;
            canvas.width = Math.floor(viewport.width * pixelRatio);
            canvas.height = Math.floor(viewport.height * pixelRatio);
            canvas.className = 'resident-report-sheet';
            canvas.dataset.page = String(number);
            canvas.setAttribute('role', 'img');
            canvas.setAttribute('aria-label', `Report page ${number} of ${pdfDocument.numPages}`);
            if (resident.value) canvas.style.width = `${Math.floor(viewport.width)}px`;
            else stage.value.appendChild(canvas);
            await page.render({
                canvasContext: canvas.getContext('2d'),
                viewport,
                transform: pixelRatio === 1 ? null : [pixelRatio, 0, 0, pixelRatio, 0, 0],
            }).promise;
            if (resident.value && token === renderToken && number === pageNumber.value) stage.value.replaceChildren(canvas);
        }
    } catch (exception) {
        if (token !== renderToken) return;
        if (!resident.value) throw exception;
        const message = await readError(exception);
        if (token === renderToken) pdfError.value = message;
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
            absoluteScale.value = Math.min(2.5, Math.max(props.fitOnOpen ? 0.2 : 0.75, current * factor));
        }
        await renderPages(renderToken);
        return;
    }
    fitWidth.value = nextFit;
    if (nextFit && resident.value) {
        zoom.value = 1;
        if (host.value) host.value.scrollLeft = 0;
    }
    if (!nextFit) zoom.value = Math.min(2.5, Math.max(0.75, zoom.value * factor));
    await renderPages(renderToken);
}

function changePage(offset) {
    pageNumber.value += offset;
    renderPages(renderToken);
}

function onScroll() {
    if (resident.value) return;
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

onMounted(() => {
    loadPdf();
    observer = new ResizeObserver(() => {
        const width = host.value?.clientWidth || 0;
        if (review.value && !reviewReady) {
            renderPages(renderToken);
            return;
        }
        const watchingFit = resident.value || (review.value ? fitting.value : fitWidth.value);
        if (!watchingFit || !pdfDocument || rendering || Math.abs(width - hostWidth) < 1) return;
        renderPages(renderToken);
    });
    if (host.value) observer.observe(host.value);
});

onBeforeUnmount(() => {
    renderToken += 1;
    observer?.disconnect();
    loadingTask?.destroy();
});

defineExpose({ reload: loadPdf });
</script>

<template>
    <div class="resident-report-view">
        <div v-if="resident" class="report-preview-bar">
            <h3>PDF preview</h3>
            <template v-if="pageCount && !pdfError">
                <div class="report-preview-pager" role="group" aria-label="Document pages">
                    <Button size="small" text severity="secondary" aria-label="Previous page" :disabled="pageNumber === 1" @click="changePage(-1)">
                        <MapIcon name="chevron" class="resident-back-icon" />
                    </Button>
                    <span role="status">Page {{ pageNumber }} of {{ pageCount }}</span>
                    <Button size="small" text severity="secondary" aria-label="Next page" :disabled="pageNumber === pageCount" @click="changePage(1)">
                        <MapIcon name="chevron" />
                    </Button>
                </div>
                <div class="report-preview-scale" role="group" aria-label="Document zoom">
                    <Button size="small" text severity="secondary" aria-label="Zoom out" :disabled="zoom <= 0.75" @click="setZoom(false, 0.8)">
                        <MapIcon name="zoom-out" />
                    </Button>
                    <Button size="small" text severity="secondary" label="Fit width" :aria-pressed="fitWidth" @click="setZoom(true, 1)" />
                    <Button size="small" text severity="secondary" aria-label="Zoom in" :disabled="zoom >= 2.5" @click="setZoom(false, 1.25)">
                        <MapIcon name="zoom-in" />
                    </Button>
                </div>
            </template>
        </div>
        <div v-if="resident && pdfError" class="report-detail-state">
            <Message severity="error" role="alert">{{ pdfError }}</Message>
            <Button size="small" outlined @click="loadPdf">Reload preview</Button>
        </div>
        <p v-else-if="pdfError" class="resident-error" role="alert">{{ pdfError }}</p>
        <div v-if="!resident" class="resident-report-zoom" role="toolbar" aria-label="Document zoom">
            <template v-if="review">
                <button type="button" aria-label="Zoom out" @click="setZoom(false, 0.85)"><MapIcon name="zoom-out" /></button>
                <span :aria-label="`Zoom ${zoomPercent} percent`">{{ zoomPercent }}%</span>
                <button type="button" aria-label="Zoom in" @click="setZoom(false, 1.15)"><MapIcon name="zoom-in" /></button>
                <button type="button" aria-label="Fit page width" :aria-pressed="fitting" @click="setZoom(true, 1)">Fit width</button>
            </template>
            <template v-else>
                <button type="button" aria-label="Zoom out" @click="setZoom(false, 0.85)"><MapIcon name="zoom-out" /></button>
                <button type="button" aria-label="Fit page width" @click="setZoom(true, 1)">Fit width</button>
                <button type="button" aria-label="Zoom in" @click="setZoom(false, 1.15)"><MapIcon name="zoom-in" /></button>
            </template>
            <span v-if="pageCount">Page {{ pageNumber }} of {{ pageCount }}</span>
        </div>
        <div v-show="!resident || !pdfError" ref="host" class="resident-report-host"
            :tabindex="(resident || fitOnOpen) && pageCount ? 0 : undefined" :role="resident || fitOnOpen ? 'region' : undefined"
            :aria-label="resident ? 'PDF page' : fitOnOpen ? 'PDF pages' : undefined" @scroll="onScroll">
            <p v-if="pdfLoading" role="status">Preparing the report…</p>
            <div ref="stage" class="resident-report-stage"></div>
        </div>
    </div>
</template>
