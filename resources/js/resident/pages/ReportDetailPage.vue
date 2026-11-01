<script setup>
import { computed, inject, nextTick, onBeforeUnmount, onMounted, ref, watch } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import { getDocument, GlobalWorkerOptions } from 'pdfjs-dist';
import pdfWorker from 'pdfjs-dist/build/pdf.worker.min.mjs?url';
import MapIcon from '../../shared/components/MapIcon.vue';
import StatusBadge from '../components/StatusBadge.vue';

GlobalWorkerOptions.workerSrc = pdfWorker;

const apiBase = inject('apiBase');
const route = useRoute();
const router = useRouter();
const report = ref(null);
const error = ref('');
const pdfError = ref('');
const pdfLoading = ref(true);
const downloading = ref(false);
const menuOpen = ref(false);
const host = ref(null);
const stage = ref(null);
const pageCount = ref(0);
const pageNumber = ref(1);
const zoom = ref(1);
const fitWidth = ref(true);
let pdfDocument = null;
let renderToken = 0;
let observer = null;
let rendering = false;
let renderAgain = false;
let hostWidth = 0;

const listTo = computed(() => ({ path: '/reports', query: { ...route.query } }));
const trackTo = computed(() => ({ path: `/reports/${route.params.id}/track`, query: { ...route.query } }));

function backToReports() {
    router.push(Object.keys(route.query).length ? listTo.value : '/reports');
}

async function loadReport() {
    error.value = '';
    try {
        report.value = (await window.axios.get(`${apiBase}/reports/${route.params.id}`)).data.data;
    } catch (exception) {
        report.value = null;
        error.value = exception.response?.data?.message || 'This report could not be opened.';
    }
}

async function loadPdf() {
    const token = ++renderToken;
    pdfDocument?.destroy();
    pdfDocument = null;
    pageCount.value = 0;
    pdfError.value = '';
    pdfLoading.value = true;
    try {
        const response = await window.axios.get(`${apiBase}/reports/${route.params.id}/document`, { responseType: 'blob' });
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
    try {
        stage.value.replaceChildren();
        const width = Math.max(280, (host.value?.clientWidth || 320) - 24);
        hostWidth = host.value?.clientWidth || width;
        for (let number = 1; number <= pdfDocument.numPages; number += 1) {
            if (token !== renderToken) return;
            const page = await pdfDocument.getPage(number);
            const base = page.getViewport({ scale: 1 });
            const fitted = width / base.width;
            const scale = fitWidth.value ? fitted : fitted * zoom.value;
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
        const width = host.value?.clientWidth || 0;
        const widthChanged = token === renderToken && fitWidth.value && Math.abs(width - hostWidth) >= 1;
        if (renderAgain) await renderPages(renderToken);
        else if (widthChanged && !followUp) await renderPages(renderToken, true);
    }
}

async function setZoom(nextFit, factor) {
    fitWidth.value = nextFit;
    if (!nextFit) zoom.value = Math.min(2.5, Math.max(0.75, zoom.value * factor));
    await renderPages(renderToken);
}

function showPage(number) {
    const next = Math.min(pageCount.value, Math.max(1, number));
    pageNumber.value = next;
    stage.value?.querySelector(`[data-page="${next}"]`)?.scrollIntoView({ block: 'start' });
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

async function downloadPdf() {
    if (downloading.value || !report.value) return;
    downloading.value = true;
    pdfError.value = '';
    try {
        const response = await window.axios.get(`${apiBase}/reports/${report.value.id}/document`, {
            params: { download: 1 },
            responseType: 'blob',
        });
        const type = response.headers['content-type'] || '';
        if (!type.includes('pdf')) throw new Error('The download was not a PDF.');
        const blob = new Blob([response.data], { type: 'application/pdf' });
        const url = URL.createObjectURL(blob);
        const link = document.createElement('a');
        link.href = url;
        link.download = `MapR_Resident_Report_${report.value.reference}.pdf`;
        document.body.appendChild(link);
        link.click();
        link.remove();
        URL.revokeObjectURL(url);
    } catch (exception) {
        pdfError.value = await readError(exception);
    } finally {
        downloading.value = false;
    }
}

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

watch(() => route.params.id, async () => {
    menuOpen.value = false;
    await loadReport();
    await loadPdf();
});

onMounted(async () => {
    await loadReport();
    await loadPdf();
    observer = new ResizeObserver(() => {
        const width = host.value?.clientWidth || 0;
        if (!fitWidth.value || !pdfDocument || rendering || Math.abs(width - hostWidth) < 1) return;
        renderPages(renderToken);
    });
    if (host.value) observer.observe(host.value);
});
onBeforeUnmount(() => {
    renderToken += 1;
    observer?.disconnect();
    pdfDocument?.destroy();
});
</script>

<template>
    <section class="resident-report-view">
        <p v-if="error" class="resident-error" role="alert">{{ error }}</p>
        <template v-else-if="report">
            <div class="resident-report-toolbar">
                <button type="button" class="resident-report-back" @click="backToReports">
                    <MapIcon name="chevron" class="resident-back-icon" /> Back to My Reports
                </button>
                <div class="resident-report-identity">
                    <strong>{{ report.reference }}</strong>
                    <StatusBadge :label="report.label" />
                </div>
                <div class="resident-report-commands">
                    <router-link v-if="report.editable" class="resident-secondary" :to="`/report/${report.id}`">
                        <MapIcon name="pencil" /> Edit Report
                    </router-link>
                    <button type="button" class="resident-primary" :disabled="downloading" :aria-busy="downloading" @click="downloadPdf">
                        <MapIcon name="download" /> {{ downloading ? 'Preparing PDF…' : 'Download PDF' }}
                    </button>
                    <button type="button" class="resident-report-more" aria-label="More report actions" aria-haspopup="menu" :aria-expanded="menuOpen" @click="menuOpen = !menuOpen">
                        <MapIcon name="more" />
                    </button>
                    <div v-if="menuOpen" class="resident-report-menu" role="menu">
                        <router-link role="menuitem" :to="trackTo" @click="menuOpen = false"><MapIcon name="history" /> Track</router-link>
                    </div>
                </div>
            </div>
            <p v-if="report.edit_message" class="resident-report-lock">{{ report.edit_message }}</p>
            <p v-if="pdfError" class="resident-error" role="alert">{{ pdfError }}</p>
            <div class="resident-report-zoom" role="toolbar" aria-label="Document zoom">
                <button type="button" aria-label="Zoom out" @click="setZoom(false, 0.85)"><MapIcon name="zoom-out" /></button>
                <button type="button" aria-label="Fit page width" @click="setZoom(true, 1)">Fit width</button>
                <button type="button" aria-label="Zoom in" @click="setZoom(false, 1.15)"><MapIcon name="zoom-in" /></button>
                <span v-if="pageCount">Page {{ pageNumber }} of {{ pageCount }}</span>
            </div>
            <div ref="host" class="resident-report-host" @scroll="onScroll">
                <p v-if="pdfLoading">Preparing the report…</p>
                <div ref="stage" class="resident-report-stage"></div>
            </div>
        </template>
        <p v-else>Loading the report…</p>
    </section>
</template>
