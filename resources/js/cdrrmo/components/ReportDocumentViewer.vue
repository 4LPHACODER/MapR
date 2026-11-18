<script setup>
import { computed, nextTick, onBeforeUnmount, onMounted, ref, watch } from 'vue';
import { getDocument, GlobalWorkerOptions } from 'pdfjs-dist';
import pdfWorker from 'pdfjs-dist/build/pdf.worker.min.mjs?url';
import Button from 'primevue/button';
import ProgressSpinner from 'primevue/progressspinner';
import MapIcon from '../../shared/components/MapIcon.vue';

GlobalWorkerOptions.workerSrc = pdfWorker;

const props = defineProps({
    sourceUrl: { type: String, default: '' },
    downloadUrl: { type: String, default: '' },
    modeLabel: { type: String, default: 'Document' },
    presentation: { type: String, default: '' },
});

const host = ref(null);
const stage = ref(null);
const status = ref('idle');
const message = ref('');
const pageCount = ref(0);
const pageNumber = ref(1);
const zoom = ref(1);
const fitWidth = ref(true);
const compact = computed(() => props.presentation === 'barangay');
const drawing = ref(false);
const canPrint = computed(() => status.value === 'ready' && !drawing.value);
let pdfDocument = null;
let pdfTask = null;
let renderToken = 0;
let drawToken = 0;
let widthObserver = null;
let lastWidth = 0;

function releaseDocument() {
    const previous = pdfTask;
    pdfTask = null;
    pdfDocument = null;
    if (previous) void previous.destroy().catch(() => {});
}

async function load() {
    const token = ++renderToken;
    drawToken++;
    releaseDocument();
    drawing.value = false;
    stage.value?.replaceChildren();
    pageCount.value = 0;
    pageNumber.value = 1;
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
        if (token !== renderToken) return;
        pdfTask = getDocument({ data });
        const pdf = await pdfTask.promise;
        if (token !== renderToken) return;
        pdfDocument = pdf;
        pageCount.value = pdfDocument.numPages;
        await nextTick();
        await renderPages();
    } catch (exception) {
        const detail = await readError(exception);
        if (token === renderToken) {
            message.value = detail;
            status.value = 'error';
        }
    }
}

function fittedWidth() {
    const width = host.value?.clientWidth || 800;
    if (!compact.value) return Math.max(280, width - 48);
    const padding = getComputedStyle(stage.value);
    return Math.max(1, width - parseFloat(padding.paddingLeft) - parseFloat(padding.paddingRight));
}

async function renderPages() {
    const pdf = pdfDocument;
    if (!pdf || !stage.value) return;
    const token = ++drawToken;
    const width = fittedWidth();
    const factor = fitWidth.value ? 1 : zoom.value;
    const pages = document.createDocumentFragment();
    drawing.value = true;
    lastWidth = width;
    try {
        for (let number = 1; number <= pdf.numPages; number += 1) {
            const page = await pdf.getPage(number);
            if (token !== drawToken) return;
            const base = page.getViewport({ scale: 1 });
            const viewport = page.getViewport({ scale: width / base.width * factor });
            const canvas = document.createElement('canvas');
            // Keep browser print output legible even when the preview is fitted to a phone.
            const outputScale = compact.value ? Math.max(1, 960 / viewport.width) : 1;
            canvas.width = Math.floor(viewport.width * outputScale);
            canvas.height = Math.floor(viewport.height * outputScale);
            if (compact.value) canvas.style.setProperty('--report-sheet-width', `${Math.floor(viewport.width)}px`);
            canvas.className = 'report-sheet';
            canvas.dataset.page = String(number);
            canvas.setAttribute('role', 'img');
            canvas.setAttribute('aria-label', `${props.modeLabel} page ${number} of ${pdf.numPages}`);
            const transform = outputScale === 1 ? null : [outputScale, 0, 0, outputScale, 0, 0];
            await page.render({ canvasContext: canvas.getContext('2d'), viewport, transform }).promise;
            if (token !== drawToken) return;
            pages.appendChild(canvas);
        }
        const current = pageNumber.value;
        stage.value.replaceChildren(pages);
        status.value = 'ready';
        if (compact.value) showPage(current, 'instant');
    } catch (exception) {
        const detail = await readError(exception);
        if (token === drawToken) {
            message.value = detail;
            status.value = 'error';
        }
    } finally {
        if (token === drawToken) drawing.value = false;
    }
}

function showPage(number, behavior = window.matchMedia('(prefers-reduced-motion: reduce)').matches ? 'instant' : 'smooth') {
    const next = Math.min(pageCount.value, Math.max(1, number));
    pageNumber.value = next;
    const sheet = stage.value?.querySelector(`[data-page="${next}"]`);
    if (!sheet) return;
    if (compact.value) {
        const viewer = host.value;
        const inset = parseFloat(getComputedStyle(stage.value).paddingTop);
        const top = next === 1 ? 0 : sheet.getBoundingClientRect().top - viewer.getBoundingClientRect().top + viewer.scrollTop - inset;
        viewer.scrollTo({ top, behavior });
    } else {
        sheet.scrollIntoView({ block: 'start', behavior });
    }
}

async function setZoom(nextFit, factor) {
    fitWidth.value = nextFit;
    if (!nextFit) zoom.value = Math.min(2.5, Math.max(0.6, zoom.value * factor));
    else if (compact.value) zoom.value = 1;
    await renderPages();
    if (!compact.value) showPage(pageNumber.value);
}

function onScroll() {
    if (!pageCount.value || status.value !== 'ready') return;
    const sheets = [...(stage.value?.querySelectorAll('.report-sheet') || [])];
    const top = host.value?.getBoundingClientRect().top || 0;
    let current = 1;
    sheets.forEach((sheet) => {
        if (sheet.getBoundingClientRect().top - top < 80) current = Number(sheet.dataset.page);
    });
    const atEnd = host.value.scrollTop > 0 && host.value.scrollHeight - host.value.clientHeight - host.value.scrollTop <= 1;
    pageNumber.value = compact.value && atEnd ? pageCount.value : current;
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
    if (compact.value && !canPrint.value) return;
    window.print();
}

watch(() => props.sourceUrl, () => load(), { immediate: true });
onMounted(() => {
    widthObserver = new ResizeObserver(() => {
        if (compact.value && pdfDocument && Math.abs(fittedWidth() - lastWidth) >= 1) {
            void renderPages();
        }
    });
    if (host.value) widthObserver.observe(host.value);
});
onBeforeUnmount(() => {
    renderToken += 1;
    drawToken++;
    widthObserver?.disconnect();
    releaseDocument();
});

defineExpose({ printDocument, reload: load, canPrint });
</script>

<template>
    <section v-if="compact" class="barangay-pdf grid min-w-0 grid-rows-[auto_minmax(0,1fr)] overflow-hidden rounded-lg border border-surface-200 bg-surface-100" aria-label="Document preview">
        <div class="barangay-pdf-toolbar flex flex-wrap items-center justify-between gap-x-2 gap-y-1 border-b border-surface-200 bg-white px-2 py-1.5">
            <div class="flex min-w-0 items-center gap-0.5" role="group" aria-label="Zoom controls">
                <Button v-tooltip.bottom="'Zoom out'" size="small" severity="secondary" text icon="pi pi-search-minus" aria-label="Zoom out"
                    :disabled="!canPrint || (!fitWidth && zoom <= 0.6)" @click="setZoom(false, 0.85)" />
                <Button v-tooltip.bottom="'Zoom in'" size="small" severity="secondary" text icon="pi pi-search-plus" aria-label="Zoom in"
                    :disabled="!canPrint || (!fitWidth && zoom >= 2.5)" @click="setZoom(false, 1.15)" />
                <Button v-tooltip.bottom="'Fit width'" size="small" severity="secondary" text :disabled="!canPrint" :aria-pressed="fitWidth"
                    aria-label="Fit width" @click="setZoom(true, 1)">
                    <i class="pi pi-arrows-h" aria-hidden="true"></i><span class="barangay-pdf-button-label">Fit width</span>
                </Button>
            </div>
            <div class="flex items-center gap-0.5" role="group" aria-label="Page navigation">
                <Button v-tooltip.bottom="'Previous page'" size="small" severity="secondary" text icon="pi pi-chevron-left" aria-label="Previous page"
                    :disabled="!canPrint || pageNumber <= 1" @click="showPage(pageNumber - 1)" />
                <span class="min-w-10 px-1 text-center text-xs font-medium whitespace-nowrap text-surface-600 tabular-nums" role="status">
                    <template v-if="pageCount"><span class="sr-only">Page </span>{{ pageNumber }}<span aria-hidden="true"> / </span><span class="sr-only"> of </span>{{ pageCount }}</template>
                    <template v-else>—</template>
                </span>
                <Button v-tooltip.bottom="'Next page'" size="small" severity="secondary" text icon="pi pi-chevron-right" aria-label="Next page"
                    :disabled="!canPrint || pageNumber >= pageCount" @click="showPage(pageNumber + 1)" />
            </div>
            <div class="flex items-center gap-0.5" role="group" aria-label="Document actions">
                <Button v-if="downloadUrl" v-tooltip.bottom="'Download this version'" as="a" :href="downloadUrl" size="small" severity="secondary" text aria-label="Download this version">
                    <i class="pi pi-download" aria-hidden="true"></i><span class="barangay-pdf-button-label">Download</span>
                </Button>
                <Button v-tooltip.bottom="'Print'" size="small" severity="secondary" text aria-label="Print" :disabled="!canPrint" @click="printDocument">
                    <i class="pi pi-print" aria-hidden="true"></i><span class="barangay-pdf-button-label">Print</span>
                </Button>
            </div>
        </div>
        <div ref="host" class="barangay-pdf-host min-h-0 min-w-0 overflow-auto" tabindex="0" role="region"
            :aria-label="`${modeLabel} pages`" :aria-busy="status === 'loading' || drawing" @scroll="onScroll">
            <div v-if="status === 'loading'" class="barangay-pdf-state" role="status">
                <ProgressSpinner class="h-6! w-6!" aria-hidden="true" />
                <p>Preparing the {{ modeLabel.toLowerCase() }}…</p>
            </div>
            <div v-else-if="status === 'error'" class="barangay-pdf-state" role="alert">
                <p>{{ message }}</p>
                <div class="flex flex-wrap justify-center gap-2">
                    <Button size="small" severity="secondary" outlined label="Retry" icon="pi pi-refresh" @click="load" />
                    <Button v-if="downloadUrl" as="a" :href="downloadUrl" size="small" severity="secondary" text label="Download this version" />
                </div>
            </div>
            <p v-else-if="status === 'empty'" class="barangay-pdf-state" role="status">{{ message }}</p>
            <div ref="stage" class="barangay-pdf-stage flex min-w-full flex-col items-center gap-3"></div>
        </div>
    </section>
    <div v-else class="report-viewer">
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
