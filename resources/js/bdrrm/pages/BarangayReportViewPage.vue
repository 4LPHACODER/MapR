<script setup>
import { computed, inject, nextTick, ref, watch } from 'vue';
import { RouterLink, useRoute } from 'vue-router';
import Button from 'primevue/button';
import Message from 'primevue/message';
import Tag from 'primevue/tag';
import MapIcon from '../../shared/components/MapIcon.vue';
import BarangayReportPreview from '../components/BarangayReportPreview.vue';

const apiBase = inject('apiBase');
const route = useRoute();
const report = ref(null);
const error = ref('');
const downloadError = ref('');
const loadingAttempt = ref(0);
const busy = ref(false);
const historyHeading = ref(null);
const signaturesOpen = ref(false);
const historyOpen = ref(false);

const reportId = computed(() => String(route.params.id || ''));
const backTarget = computed(() => {
    const from = typeof route.query.from === 'string' ? route.query.from : '';
    return from.startsWith('/reports') ? from : '/reports/submitted';
});
const downloadUrl = computed(() => `/bdrrm/reports/${reportId.value}/preview?format=pdf`);
const documentUrl = computed(() => `${downloadUrl.value}&inline=1`);
const statusSeverity = computed(() => {
    if (report.value?.status === 'returned') return 'warn';
    if (report.value?.status === 'signed') return 'secondary';
    return 'info';
});

const signatureSlots = computed(() => report.value?.signature_slots || []);
const visibleSignatures = computed(() => (signaturesOpen.value ? signatureSlots.value : signatureSlots.value.slice(0, 4)));
const signedCount = computed(() => report.value?.signature_signed_count ?? signatureSlots.value.filter((slot) => slot.signed).length);
const openFlags = computed(() => Number(report.value?.open_flags || 0));
const historyNewest = computed(() => [...(report.value?.timeline || [])].reverse());
const historyVisible = computed(() => (historyOpen.value ? historyNewest.value : historyNewest.value.slice(0, 3)));
const hiddenHistory = computed(() => Math.max(0, historyNewest.value.length - 3));

const mapLink = computed(() => {
    if (!report.value?.disaster_incident_id) return null;
    return {
        path: '/map',
        query: { incident_id: String(report.value.disaster_incident_id) },
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

watch([reportId, loadingAttempt], async ([id], _, onCleanup) => {
    const request = new AbortController();
    onCleanup(() => request.abort());
    error.value = '';
    report.value = null;
    downloadError.value = '';
    historyOpen.value = false;
    try {
        const { data } = await window.axios.get(`${apiBase}/reports/${id}`, { signal: request.signal });
        report.value = data.data;
        signaturesOpen.value = signatureSlots.value.some((slot) => slot.role === 'barangay_signer' && slot.signed);
        await nextTick();
        if (!request.signal.aborted && route.query.panel === 'history') showHistory();
    } catch (exception) {
        if (request.signal.aborted) return;
        const status = exception.response?.status;
        if (status === 403) error.value = 'This barangay report is outside your assignment.';
        else if (status === 404) error.value = 'This barangay report could not be found.';
        else error.value = 'This barangay report could not be loaded.';
    }
}, { immediate: true });

async function downloadPdf() {
    if (busy.value || !report.value) return;
    const currentReport = report.value;
    busy.value = true;
    downloadError.value = '';
    try {
        const response = await window.axios.get(downloadUrl.value, { responseType: 'blob' });
        if (!(response.headers['content-type'] || '').includes('pdf')) throw new Error('The download was not a PDF.');
        const url = URL.createObjectURL(response.data);
        const link = document.createElement('a');
        link.href = url;
        link.download = `MapR_Barangay_Report_${currentReport.reference}.pdf`;
        document.body.appendChild(link);
        link.click();
        link.remove();
        URL.revokeObjectURL(url);
    } catch {
        if (report.value === currentReport) downloadError.value = 'The PDF could not be downloaded.';
    } finally {
        busy.value = false;
    }
}

function showHistory() {
    historyHeading.value?.focus({ preventScroll: true });
    historyHeading.value?.scrollIntoView({ block: 'start' });
}
</script>

<template>
    <article class="barangay-view">
        <Button :as="RouterLink" :to="backTarget" size="small" text class="barangay-view-back">
            <MapIcon name="chevron" /> Back to Submitted Reports
        </Button>

        <div v-if="error" class="barangay-view-card barangay-view-state">
            <Message severity="error" role="alert">{{ error }}</Message>
            <Button size="small" outlined label="Try again" @click="loadingAttempt++" />
        </div>
        <p v-else-if="!report" class="barangay-view-card barangay-view-state" role="status">Loading barangay report…</p>

        <template v-else>
            <header class="barangay-view-header">
                <div class="barangay-view-title">
                    <p>{{ report.reference }}</p>
                    <Tag :value="report.label" :severity="statusSeverity" />
                    <h2>{{ report.incident_name || 'Barangay incident report' }}</h2>
                </div>
                <div class="barangay-view-actions">
                    <Button size="small" label="Download PDF" :loading="busy" @click="downloadPdf">
                        <template v-if="!busy" #icon><MapIcon name="download" /></template>
                    </Button>
                    <Button as="a" :href="documentUrl" target="_blank" rel="noopener" size="small" severity="secondary" outlined>
                        <MapIcon name="printer" /> Print<span class="visually-hidden"> PDF (opens in a new tab)</span>
                    </Button>
                    <Button size="small" severity="secondary" text aria-label="Review history" @click="showHistory">
                        <MapIcon name="history" /> History
                    </Button>
                </div>
            </header>

            <Message v-if="downloadError" severity="error" role="alert">{{ downloadError }}</Message>
            <div class="barangay-view-layout">
                <section class="barangay-view-card" aria-labelledby="barangay-view-document">
                    <h3 id="barangay-view-document" class="barangay-view-card-title"><MapIcon name="file" /> Report preview</h3>
                    <BarangayReportPreview :document-url="documentUrl" />
                </section>

                <aside class="barangay-view-review" aria-label="Report review">
                    <section class="barangay-view-card">
                        <h3 class="barangay-view-card-title"><MapIcon name="clipboard" /> Report details</h3>
                        <div class="barangay-view-body">
                            <dl class="barangay-view-facts">
                                <div v-if="report.barangay_name">
                                    <dt>Barangay</dt>
                                    <dd>{{ report.barangay_name }}</dd>
                                </div>
                                <div v-if="report.submitted_at">
                                    <dt>Forwarded</dt>
                                    <dd>{{ formatWhen(report.submitted_at) }}</dd>
                                </div>
                                <div v-if="report.lineage?.resident_report_reference || report.resident_reference">
                                    <dt>Source report</dt>
                                    <dd>{{ report.lineage?.resident_report_reference || report.resident_reference }}</dd>
                                </div>
                                <div v-if="report.lineage?.resident_reference && report.lineage.resident_reference !== 'Not recorded'">
                                    <dt>Resident reference</dt>
                                    <dd>{{ report.lineage.resident_reference }}</dd>
                                </div>
                                <div v-if="report.lineage?.building_reference && report.lineage.building_reference !== 'Not recorded'">
                                    <dt>Building reference</dt>
                                    <dd>{{ report.lineage.building_reference }}</dd>
                                </div>
                            </dl>
                            <Message v-if="report.return_reason" size="small" severity="warn">{{ report.return_reason }}</Message>
                            <Button v-if="mapLink" :as="RouterLink" :to="mapLink" size="small" severity="secondary" outlined>
                                <MapIcon name="pin" /> View linked incident on map
                            </Button>
                            <p v-else class="barangay-view-muted">No linked incident for map navigation.</p>
                        </div>
                    </section>

                    <section class="barangay-view-card">
                        <div class="barangay-view-card-title">
                            <h3><MapIcon name="check" /> Signatures</h3>
                            <span v-if="signatureSlots.length" class="barangay-view-muted">{{ signedCount }} of {{ signatureSlots.length }} completed</span>
                        </div>
                        <ul v-if="visibleSignatures.length" id="barangay-view-signatures" class="barangay-view-list">
                            <li v-for="slot in visibleSignatures" :key="`${slot.role}-${slot.title}`">
                                <div class="barangay-view-row-heading">
                                    <strong>{{ slot.label }}</strong>
                                    <Tag :value="slot.status" :severity="slot.signed ? 'success' : 'secondary'" />
                                </div>
                                <p v-if="slot.name && slot.name !== slot.label">{{ slot.name }}</p>
                                <p v-if="slot.caption" class="barangay-view-muted">{{ slot.caption }}</p>
                            </li>
                        </ul>
                        <p v-else class="barangay-view-body barangay-view-muted">No signatures recorded yet.</p>
                        <div v-if="signatureSlots.length > 4" class="barangay-view-footer">
                            <Button size="small" text :aria-expanded="signaturesOpen" aria-controls="barangay-view-signatures"
                                :label="signaturesOpen ? 'Show fewer signatures' : `View all signatures (${signatureSlots.length})`"
                                @click="signaturesOpen = !signaturesOpen" />
                        </div>
                    </section>

                    <section class="barangay-view-card">
                        <div class="barangay-view-card-title">
                            <h3><MapIcon name="flag" /> Flags</h3>
                            <Tag v-if="openFlags" :value="`${openFlags} open`" severity="warn" />
                        </div>
                        <ul v-if="openFlags" class="barangay-view-list">
                            <li v-for="flag in (report.flags || []).filter((item) => item.status === 'open')" :key="flag.id">
                                <strong>{{ flag.category }}</strong>
                                <p>{{ flag.reason }}</p>
                            </li>
                        </ul>
                        <p v-else class="barangay-view-body barangay-view-muted">No open flags.</p>
                    </section>

                    <section class="barangay-view-card">
                        <h3 ref="historyHeading" tabindex="-1" class="barangay-view-card-title"><MapIcon name="history" /> Review history</h3>
                        <ol v-if="historyVisible.length" id="barangay-view-history" class="barangay-view-list">
                            <li v-for="(event, index) in historyVisible" :key="`${event.created_at}-${index}`">
                                <strong>{{ event.summary || event.action_type || 'Activity' }}</strong>
                                <p v-if="event.created_at" class="barangay-view-muted">{{ formatWhen(event.created_at) }}</p>
                                <p v-if="event.summary && event.action_type && event.summary !== event.action_type">{{ event.action_type }}</p>
                            </li>
                        </ol>
                        <p v-else class="barangay-view-body barangay-view-muted">No history events yet.</p>
                        <div v-if="hiddenHistory" class="barangay-view-footer">
                            <Button size="small" text :aria-expanded="historyOpen" aria-controls="barangay-view-history"
                                :label="historyOpen ? 'Hide earlier events' : `Show earlier events (${hiddenHistory})`"
                                @click="historyOpen = !historyOpen" />
                        </div>
                    </section>
                </aside>
            </div>
        </template>
    </article>
</template>

<style scoped>
.barangay-view {
    display: grid;
    gap: 1rem;
    max-width: 90rem;
    margin-inline: auto;
    container: barangay-view / inline-size;
    color: var(--resident-ink);
    font-size: 0.8125rem;
    line-height: 1.5;
}

.barangay-view :deep(.p-button) {
    --p-button-border-radius: var(--resident-control);
    min-height: 2.25rem;
}

.barangay-view :deep(.cdrrmo-icon) {
    width: 1rem;
    height: 1rem;
    flex: none;
}

.barangay-view-back {
    justify-self: start;
    margin-inline-start: -0.5rem;
}

.barangay-view-back :deep(svg) { transform: rotate(180deg); }

.barangay-view-header,
.barangay-view-actions,
.barangay-view-title {
    display: flex;
    flex-wrap: wrap;
    align-items: center;
    gap: 0.5rem;
    min-width: 0;
}

.barangay-view-header {
    justify-content: space-between;
    align-items: flex-end;
    gap: 0.75rem 1rem;
}

.barangay-view-title p {
    color: var(--resident-navy);
    font-size: 0.75rem;
    font-weight: 700;
    font-variant-numeric: tabular-nums;
    overflow-wrap: anywhere;
}

.barangay-view-title h2 {
    flex-basis: 100%;
    color: var(--resident-ink);
    font-size: clamp(1.375rem, 3cqi, 1.75rem);
    font-weight: 700;
    line-height: 1.2;
    letter-spacing: -0.02em;
    overflow-wrap: anywhere;
}

.barangay-view-layout,
.barangay-view-review {
    display: grid;
    grid-template-columns: minmax(0, 1fr);
    align-items: start;
    gap: 1rem;
    min-width: 0;
}

.barangay-view-card {
    min-width: 0;
    border: 1px solid var(--resident-line);
    border-radius: var(--resident-radius);
    background: var(--resident-card);
}

.barangay-view-card-title {
    display: flex;
    flex-wrap: wrap;
    justify-content: space-between;
    align-items: center;
    gap: 0.375rem 0.625rem;
    min-height: 3rem;
    padding: 0.625rem 1rem;
    border-bottom: 1px solid var(--resident-line);
    color: var(--resident-navy);
}

.barangay-view-card-title:is(h3),
.barangay-view-card-title h3 {
    display: flex;
    align-items: center;
    justify-content: start;
    gap: 0.5rem;
    font-size: 0.9375rem;
    font-weight: 700;
}

.barangay-view-card-title[tabindex] { scroll-margin-block-start: 6rem; }

.barangay-view-body {
    display: grid;
    justify-items: start;
    gap: 0.75rem;
    padding: 0.875rem 1rem;
}

.barangay-view-facts {
    display: grid;
    gap: 0.625rem;
    overflow-wrap: anywhere;
}

.barangay-view-facts dt,
.barangay-view-muted {
    color: var(--p-slate-600, #475569);
    font-size: 0.75rem;
}

.barangay-view-facts dd {
    margin: 0.0625rem 0 0;
    font-weight: 500;
}

.barangay-view-list {
    margin: 0;
    padding: 0;
    list-style: none;
}

.barangay-view-list li {
    display: grid;
    gap: 0.1875rem;
    padding: 0.625rem 1rem;
    overflow-wrap: anywhere;
}

.barangay-view-list li + li,
.barangay-view-footer {
    border-top: 1px solid var(--resident-line);
}

.barangay-view-row-heading {
    display: flex;
    flex-wrap: wrap;
    justify-content: space-between;
    align-items: center;
    gap: 0.125rem 0.5rem;
}

.barangay-view-footer { padding: 0.25rem 0.5rem; }

.barangay-view-state {
    display: grid;
    place-content: center;
    justify-items: center;
    gap: 0.75rem;
    min-height: 12rem;
    padding: 1rem;
}

.barangay-view :deep(:focus-visible) {
    outline: 2px solid var(--resident-blue);
    outline-offset: 2px;
}

@container barangay-view (min-width: 54rem) {
    .barangay-view-layout {
        grid-template-columns: minmax(0, 1fr) minmax(18rem, 28%);
    }
}

@media (max-width: 767px), (pointer: coarse) {
    .barangay-view :deep(.p-button) { min-height: 2.75rem; }
}
</style>
