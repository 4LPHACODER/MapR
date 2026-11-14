<script setup>
import { computed, inject, nextTick, onBeforeUnmount, onMounted, ref, watch } from 'vue';
import { useRoute } from 'vue-router';
import MapIcon from '../../shared/components/MapIcon.vue';
import ReportPdfViewer from '../../shared/components/ReportPdfViewer.vue';

const apiBase = inject('apiBase');
const route = useRoute();
const report = ref(null);
const phase = ref('loading');
const error = ref('');
const downloadError = ref('');
const busy = ref(false);
const menuOpen = ref(false);
const viewer = ref(null);
const menuButton = ref(null);
const wideReview = ref(typeof window !== 'undefined' && window.matchMedia('(min-width: 1024px)').matches);
const reviewOpen = ref(false);
const historyOpen = ref(false);
let stopReviewMedia = () => {};

const reportId = computed(() => (route.params.id ? String(route.params.id) : ''));

const backTarget = computed(() => {
    const from = typeof route.query.from === 'string' ? route.query.from : '';
    if (from.startsWith('/reports')) return from;
    return '/reports/submitted';
});

const documentUrl = computed(() => (
    reportId.value
        ? `/bdrrm/reports/${reportId.value}/preview?format=pdf&inline=1`
        : ''
));

const downloadUrl = computed(() => (
    reportId.value
        ? `/bdrrm/reports/${reportId.value}/preview?format=pdf`
        : ''
));

const openFlags = computed(() => Number(report.value?.open_flags || 0));
const panelOpen = computed(() => wideReview.value || reviewOpen.value);

const signatureSlots = computed(() => report.value?.signature_slots || []);
const signaturePreview = computed(() => signatureSlots.value.slice(0, 4));
const signatureExtra = computed(() => Math.max(0, signatureSlots.value.length - 4));
const signaturesOpen = ref(false);
const visibleSignatures = computed(() => (signaturesOpen.value ? signatureSlots.value : signaturePreview.value));
const captainSigned = computed(() => signatureSlots.value.some((slot) => slot.role === 'barangay_signer' && slot.signed));
const signedCount = computed(() => Number(report.value?.signature_signed_count ?? signatureSlots.value.filter((slot) => slot.signed).length));

watch(captainSigned, (signed) => {
    if (signed && signatureSlots.value.length > 4) {
        signaturesOpen.value = true;
    }
}, { immediate: true });
watch(signatureSlots, (slots) => {
    if (captainSigned.value && slots.length > 4) {
        signaturesOpen.value = true;
    }
});

const historyNewest = computed(() => [...(report.value?.timeline || [])]
    .map((event) => ({
        title: event.summary || event.action_type || 'Activity',
        at: event.created_at,
        detail: event.summary && event.action_type && event.summary !== event.action_type
            ? event.action_type
            : null,
    }))
    .reverse());
const historyVisible = computed(() => (historyOpen.value ? historyNewest.value : historyNewest.value.slice(0, 3)));
const hiddenHistory = computed(() => Math.max(0, historyNewest.value.length - 3));

const mapLink = computed(() => {
    if (!report.value?.disaster_incident_id) return null;
    return {
        path: '/map',
        query: {
            incident_id: String(report.value.disaster_incident_id),
        },
    };
});

function formatWhen(value) {
    if (!value) return '—';
    const date = new Date(String(value).includes('T') ? value : `${String(value).replace(' ', 'T')}Z`);
    if (Number.isNaN(date.getTime())) return value;
    return `${new Intl.DateTimeFormat('en-PH', {
        timeZone: 'Asia/Manila',
        day: 'numeric',
        month: 'short',
        year: 'numeric',
        hour: 'numeric',
        minute: '2-digit',
    }).format(date)} PHT`;
}

async function load() {
    phase.value = 'loading';
    error.value = '';
    report.value = null;
    try {
        if (!reportId.value) {
            phase.value = 'error';
            error.value = 'This barangay report could not be found.';
            return;
        }
        const { data } = await window.axios.get(`${apiBase}/reports/${reportId.value}`);
        report.value = data.data;
        phase.value = 'ready';
        await nextTick();
        if (route.query.panel === 'history') showHistory();
    } catch (exception) {
        phase.value = 'error';
        error.value = exception.response?.status === 403
            ? 'This barangay report is outside your assignment.'
            : exception.response?.status === 404
                ? 'This barangay report could not be found.'
                : 'This barangay report could not be loaded.';
    }
}

async function downloadPdf() {
    if (busy.value || !report.value) return;
    busy.value = true;
    downloadError.value = '';
    try {
        const response = await window.axios.get(downloadUrl.value, { responseType: 'blob' });
        const type = response.headers['content-type'] || '';
        if (!type.includes('pdf')) throw new Error('The download was not a PDF.');
        const blob = new Blob([response.data], { type: 'application/pdf' });
        const url = URL.createObjectURL(blob);
        const link = document.createElement('a');
        link.href = url;
        link.download = `MapR_Barangay_Report_${report.value.reference}.pdf`;
        document.body.appendChild(link);
        link.click();
        link.remove();
        URL.revokeObjectURL(url);
    } catch (exception) {
        downloadError.value = exception.response?.data?.message || 'The PDF could not be downloaded.';
    } finally {
        busy.value = false;
    }
}

function printReport() {
    window.open(downloadUrl.value, '_blank', 'noopener');
}

function showHistory() {
    menuOpen.value = false;
    reviewOpen.value = true;
    nextTick(() => document.getElementById('bdrrm-barangay-review-history')?.scrollIntoView({ block: 'start' }));
}

function onPanelToggle(event) {
    if (!wideReview.value) reviewOpen.value = event.target.open;
}

async function toggleActions() {
    menuOpen.value = !menuOpen.value;
    if (!menuOpen.value) return;
    await nextTick();
    document.querySelector('#bdrrm-barangay-action-menu [role="menuitem"]:not(:disabled)')?.focus();
}

function closeActions(restore = true) {
    menuOpen.value = false;
    if (restore) menuButton.value?.focus();
}

function onActionMenuKey(event) {
    const items = [...event.currentTarget.querySelectorAll('[role="menuitem"]:not(:disabled)')];
    const index = items.indexOf(document.activeElement);
    if (event.key === 'Escape') {
        event.preventDefault();
        closeActions();
        return;
    }
    if ((event.key !== 'ArrowDown' && event.key !== 'ArrowUp') || !items.length) return;
    event.preventDefault();
    const next = event.key === 'ArrowDown' ? (index + 1) % items.length : (index - 1 + items.length) % items.length;
    items[next]?.focus();
}

function onDocumentPointer(event) {
    if (!menuOpen.value) return;
    const menu = document.getElementById('bdrrm-barangay-action-menu');
    if (menu?.contains(event.target) || menuButton.value?.contains(event.target)) return;
    closeActions(false);
}

function onDocumentKey(event) {
    if (event.key === 'Escape' && menuOpen.value) closeActions();
}

watch(reportId, () => {
    menuOpen.value = false;
    load();
}, { immediate: true });

onMounted(() => {
    const media = window.matchMedia('(min-width: 1024px)');
    const sync = () => {
        wideReview.value = media.matches;
    };
    sync();
    media.addEventListener('change', sync);
    stopReviewMedia = () => media.removeEventListener('change', sync);
    document.addEventListener('pointerdown', onDocumentPointer);
    document.addEventListener('keydown', onDocumentKey);
});

onBeforeUnmount(() => {
    stopReviewMedia();
    document.removeEventListener('pointerdown', onDocumentPointer);
    document.removeEventListener('keydown', onDocumentKey);
});
</script>

<template>
    <section class="bdrrm-submissions bdrrm-submission-detail">
        <p v-if="phase === 'loading'" class="submission-status">Loading barangay report…</p>
        <div v-else-if="phase === 'error'" class="submission-status" role="alert">
            <p>{{ error }}</p>
            <RouterLink class="submission-back" :to="backTarget">Back to Submitted Reports</RouterLink>
        </div>
        <template v-else-if="report">
            <header class="bdrrm-detail-toolbar">
                <RouterLink class="submission-back" :to="backTarget">
                    <MapIcon name="chevron" />
                    Back to Submitted Reports
                </RouterLink>
                <div class="bdrrm-detail-identity">
                    <h2>{{ report.reference }}</h2>
                    <span class="submission-badge" :data-status="report.status">{{ report.label }}</span>
                </div>
                <div class="bdrrm-detail-actions">
                    <button type="button" class="submission-quiet" :disabled="busy" @click="downloadPdf">
                        <MapIcon name="download" /> {{ busy ? 'Preparing PDF…' : 'Download PDF' }}
                    </button>
                    <button type="button" class="submission-quiet" @click="printReport">
                        <MapIcon name="printer" /> Print
                    </button>
                    <div class="submission-overflow">
                        <button
                            ref="menuButton"
                            type="button"
                            class="submission-quiet"
                            aria-haspopup="menu"
                            :aria-expanded="menuOpen"
                            aria-controls="bdrrm-barangay-action-menu"
                            @click="toggleActions"
                        >
                            Actions <MapIcon name="chevron" class="submission-chevron-down" />
                        </button>
                        <div v-if="menuOpen" id="bdrrm-barangay-action-menu" class="submission-menu" role="menu" @keydown="onActionMenuKey">
                            <button type="button" role="menuitem" :disabled="busy" @click="downloadPdf(); closeActions(false)">
                                <MapIcon name="download" /> Download PDF
                            </button>
                            <button type="button" role="menuitem" @click="printReport(); closeActions(false)">
                                <MapIcon name="printer" /> Print
                            </button>
                            <RouterLink v-if="mapLink" role="menuitem" :to="mapLink" @click="closeActions(false)">
                                <MapIcon name="pin" /> View linked location
                            </RouterLink>
                            <button v-else type="button" role="menuitem" disabled>No linked incident map</button>
                            <button type="button" role="menuitem" @click="showHistory">
                                <MapIcon name="history" /> Review history
                            </button>
                        </div>
                    </div>
                </div>
            </header>
            <p v-if="downloadError" class="submission-alert" role="alert">{{ downloadError }}</p>
            <div class="bdrrm-review-layout">
                <ReportPdfViewer ref="viewer" presentation="review" :document-url="documentUrl" />
                <details class="bdrrm-review-disclosure" :open="panelOpen" @toggle="onPanelToggle">
                    <summary>
                        <span>Review</span>
                        <span class="submission-badge" :data-status="report.status">{{ report.label }}</span>
                        <span>{{ captainSigned ? 'Captain endorsed' : 'Awaiting captain endorsement' }}</span>
                        <span>{{ openFlags }} open {{ openFlags === 1 ? 'flag' : 'flags' }}</span>
                    </summary>
                    <div class="bdrrm-review-body">
                        <section class="bdrrm-review-section">
                            <h3><MapIcon name="clipboard" /> Review status</h3>
                            <span class="submission-badge" :data-status="report.status">{{ report.label }}</span>
                            <p v-if="report.barangay_name || report.incident_name" class="submission-note">
                                {{ [report.barangay_name, report.incident_name].filter(Boolean).join(' · ') }}
                            </p>
                            <p v-if="report.lineage?.resident_report_reference || report.resident_reference" class="submission-note">
                                Source {{ report.lineage?.resident_report_reference || report.resident_reference }}
                            </p>
                            <p v-if="report.lineage?.resident_reference && report.lineage.resident_reference !== 'Not recorded'" class="submission-note">
                                Resident {{ report.lineage.resident_reference }}
                            </p>
                            <p v-if="report.lineage?.building_reference && report.lineage.building_reference !== 'Not recorded'" class="submission-note">
                                Building {{ report.lineage.building_reference }}
                            </p>
                            <p v-if="report.submitted_at" class="submission-note">Forwarded {{ formatWhen(report.submitted_at) }}</p>
                            <p v-if="report.return_reason">{{ report.return_reason }}</p>
                        </section>
                        <section class="bdrrm-review-section">
                            <h3><MapIcon name="check" /> Signatures</h3>
                            <p class="submission-note">{{ signedCount }} completed</p>
                            <ul v-if="visibleSignatures.length" class="submission-timeline">
                                <li v-for="slot in visibleSignatures" :key="`${slot.role}-${slot.title}`">
                                    <span class="submission-dot" aria-hidden="true"></span>
                                    <strong>{{ slot.label }}</strong>
                                    <span>{{ slot.status }}</span>
                                    <p v-if="slot.name">{{ slot.name }}</p>
                                    <p v-if="slot.caption" class="submission-note">{{ slot.caption }}</p>
                                </li>
                            </ul>
                            <p v-else class="submission-note">No signatures recorded yet.</p>
                            <button
                                v-if="signatureExtra > 0"
                                type="button"
                                class="submission-quiet"
                                @click="signaturesOpen = !signaturesOpen"
                            >
                                {{ signaturesOpen ? 'Show fewer signatures' : `View all signatures (${signatureSlots.length})` }}
                            </button>
                        </section>
                        <section class="bdrrm-review-section">
                            <h3><MapIcon name="flag" /> Flags</h3>
                            <p>{{ openFlags }} open {{ openFlags === 1 ? 'flag' : 'flags' }}</p>
                            <ul v-if="openFlags > 0" class="submission-timeline">
                                <li v-for="flag in (report.flags || []).filter((item) => item.status === 'open')" :key="flag.id">
                                    <span class="submission-dot" aria-hidden="true"></span>
                                    <strong>{{ flag.category }}</strong>
                                    <p>{{ flag.reason }}</p>
                                </li>
                            </ul>
                            <p v-else class="submission-note">No open flags</p>
                        </section>
                        <section id="bdrrm-barangay-review-history" class="bdrrm-review-section">
                            <h3><MapIcon name="history" /> History</h3>
                            <ol v-if="historyVisible.length" class="submission-timeline">
                                <li v-for="(event, index) in historyVisible" :key="`${event.at}-${index}`">
                                    <span class="submission-dot" aria-hidden="true"></span>
                                    <strong>{{ event.title }}</strong>
                                    <span>{{ formatWhen(event.at) }}</span>
                                    <p v-if="event.detail">{{ event.detail }}</p>
                                </li>
                            </ol>
                            <p v-else class="submission-note">No history events yet.</p>
                            <button v-if="hiddenHistory > 0" type="button" class="submission-quiet" @click="historyOpen = !historyOpen">
                                {{ historyOpen ? 'Hide earlier events' : `Show earlier events (${hiddenHistory})` }}
                            </button>
                        </section>
                        <section class="bdrrm-review-section">
                            <h3><MapIcon name="pin" /> Location</h3>
                            <RouterLink v-if="mapLink" class="submission-quiet" :to="mapLink">
                                <MapIcon name="pin" /> View linked incident on map
                            </RouterLink>
                            <p v-else class="submission-note">No linked incident for map navigation</p>
                        </section>
                    </div>
                </details>
            </div>
        </template>
    </section>
</template>
