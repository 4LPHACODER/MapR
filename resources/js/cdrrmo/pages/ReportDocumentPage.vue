<script setup>
import { computed, nextTick, onMounted, reactive, ref, watch } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import Button from 'primevue/button';
import Menu from 'primevue/menu';
import Message from 'primevue/message';
import Skeleton from 'primevue/skeleton';
import Tag from 'primevue/tag';
import MapIcon from '../../shared/components/MapIcon.vue';
import BarangayDocumentDetails from '../components/BarangayDocumentDetails.vue';
import ReportDocumentViewer from '../components/ReportDocumentViewer.vue';
import 'primeicons/primeicons.css';
import '../../../css/cdrrmo/barangay-document.css';

const route = useRoute();
const router = useRouter();
const record = ref(null);
const error = ref('');
const message = ref('');
const detailsOpen = ref(true);
const menuOpen = ref(false);
const flagForm = reactive({ category: 'Review', reason: '' });
const resolution = ref('');
const viewer = ref(null);
const actions = ref(null);
const actionsOpen = ref(false);
let recordRequest = 0;

const kind = computed(() => route.params.kind);
const barangay = computed(() => kind.value === 'barangay');
const listPath = computed(() => `/reports/${kind.value === 'field' ? 'field' : (kind.value === 'consolidated' ? 'consolidated' : 'barangay')}`);
const title = computed(() => {
    if (kind.value === 'field') return 'Field Assessment Report';
    if (kind.value === 'consolidated') return 'Consolidated Report';
    return 'Barangay Report';
});
const hasOriginal = computed(() => Boolean(record.value?.attachment?.preview_path));
const mode = computed(() => {
    if (route.query.mode === 'generated') return 'generated';
    if (route.query.mode === 'original' && hasOriginal.value) return 'original';
    return hasOriginal.value ? 'original' : 'generated';
});
const generatedUrl = computed(() => {
    const id = record.value?.id;
    if (!id) return '';
    if (kind.value === 'field') return `/cdrrmo/reports/field/${id}/preview?format=pdf&inline=1`;
    if (kind.value === 'consolidated') return `/cdrrmo/reports/consolidated/${id}/preview?format=pdf&inline=1`;
    return `/cdrrmo/reports/barangay/${id}/preview?format=pdf&inline=1`;
});
const sourceUrl = computed(() => (mode.value === 'original' ? record.value?.attachment?.preview_path || '' : generatedUrl.value));
const downloadUrl = computed(() => (mode.value === 'original' ? record.value?.attachment?.download_path || '' : `${generatedUrl.value.replace('&inline=1', '')}`));
const statusTones = { draft: 'secondary', review: 'info', returned: 'warn', submitted: 'success', signed: 'success', finalized: 'success' };
const statusTone = computed(() => statusTones[record.value?.status] || 'info');
const documentActions = computed(() => [
    { label: 'Print', icon: 'pi pi-print', disabled: !viewer.value?.canPrint, command: () => nextTick(() => viewer.value?.printDocument()) },
    { label: 'Download this version', icon: 'pi pi-download', url: downloadUrl.value, disabled: !downloadUrl.value },
]);
const checklistLabels = {
    fields: 'Required fields',
    evidence: 'Supporting evidence',
    verification: 'Location verification',
    predecessor: 'Previous signatures',
    authority: 'Signing authority',
    flags: 'Blocking flags',
    current: 'Current version',
};
const checklist = computed(() => (record.value?.checklist || []).map(item => ({
    key: item.key,
    label: checklistLabels[item.key] || item.label,
    state: checklistState(item),
    detail: checklistDetail(item),
})));

const linked = computed(() => {
    const attachment = record.value?.attachment || {};
    return [
        ['Source report', record.value?.barangay_report?.reference || 'Not recorded', Boolean(record.value?.barangay_report?.reference)],
        ['Building', linkText(record.value?.building?.reference, attachment.source_building_reference, attachment.resolved_building_id), Boolean(record.value?.building?.id || attachment.resolved_building_id)],
        ['Household', linkText(record.value?.household?.reference, attachment.source_household_reference, attachment.resolved_household_id), Boolean(record.value?.household?.id || attachment.resolved_household_id)],
        ['Resident', linkText(null, attachment.source_resident_reference, attachment.resolved_resident_id), Boolean(attachment.resolved_resident_id)],
    ];
});

function linkText(linkedReference, sourceReference, resolvedId) {
    if (linkedReference && resolvedId) return linkedReference;
    if (linkedReference) return linkedReference;
    if (sourceReference && !resolvedId) return `${sourceReference} — stated in the original document, not linked`;
    if (sourceReference) return sourceReference;
    return 'Not recorded';
}

function checklistState(item) {
    if (item.met) return 'complete';
    if (item.key === 'authority') return 'locked';
    return 'attention';
}

function checklistDetail(item) {
    if (item.key === 'evidence' && record.value?.attachment && !item.met) {
        return 'Photographs in the original document are not separate verified evidence records.';
    }
    if (item.key === 'authority' && !item.met) return 'Not permitted for this account.';
    return item.detail;
}

async function load() {
    const request = ++recordRequest;
    error.value = '';
    const { data } = await window.axios.get(`/cdrrmo/api/reports/${kind.value}/${route.params.id}`);
    if (request === recordRequest) record.value = data.data;
}

function goBack() {
    router.push(route.query.return || listPath.value);
}

function setMode(next) {
    router.replace({ query: { ...route.query, mode: next } });
}

async function addFlag() {
    if (flagForm.reason.trim().length < 3) {
        error.value = 'A flag needs a reason.';
        return;
    }
    const subject = kind.value === 'field' ? 'field_assessment' : (kind.value === 'barangay' ? 'barangay_report' : 'consolidated_report');
    try {
        await window.axios.post('/cdrrmo/api/flags', { subject_type: subject, subject_id: record.value.id, ...flagForm });
        flagForm.reason = '';
        await load();
        message.value = 'Flag recorded.';
    } catch (exception) {
        error.value = exception.response?.data?.message || 'The flag could not be saved.';
    }
}

async function resolveFlag(flag) {
    if (!resolution.value.trim()) {
        error.value = 'Record how the flag was resolved.';
        return;
    }
    try {
        await window.axios.post(`/cdrrmo/api/flags/${flag.id}/resolve`, { resolution: resolution.value });
        resolution.value = '';
        await load();
        message.value = 'Flag resolved.';
    } catch (exception) {
        error.value = exception.response?.data?.message || 'The flag could not be resolved.';
    }
}

async function loadPage() {
    const id = route.params.id;
    const reportKind = kind.value;
    if (!id || !reportKind) return;
    try {
        await load();
    } catch (exception) {
        if (id === route.params.id && reportKind === kind.value) {
            error.value = exception.response?.data?.message || 'This report could not be opened.';
        }
    }
}

onMounted(loadPage);
watch([kind, () => route.params.id], () => {
    record.value = null;
    message.value = '';
    loadPage();
});
</script>

<template>
    <section :class="barangay ? 'barangay-document grid min-w-0 gap-3' : 'report-document-page'">
        <header v-if="barangay" class="barangay-document-header grid min-w-0 gap-2">
            <Button label="Back to Reports" icon="pi pi-arrow-left" class="-ms-2 justify-self-start" size="small" severity="secondary" text @click="goBack" />
            <div class="flex min-w-0 flex-wrap items-end justify-between gap-x-4 gap-y-3">
                <div class="min-w-0">
                    <h2 class="text-xl leading-7 font-semibold tracking-tight">{{ title }}</h2>
                    <div v-if="record" class="mt-1.5 flex flex-wrap items-center gap-x-2.5 gap-y-1.5 text-xs">
                        <span class="min-w-0 wrap-anywhere font-semibold text-indigo-800 tabular-nums">{{ record.reference }}</span>
                        <Tag :value="record.label" :severity="statusTone" />
                        <span class="text-surface-600">Version {{ record.version || 1 }}</span>
                    </div>
                    <p v-else-if="!error" class="mt-1 text-xs text-surface-600" role="status">Loading report…</p>
                </div>
                <div v-if="record" class="flex min-w-0 flex-wrap items-center gap-1.5">
                    <div v-if="hasOriginal" class="flex flex-wrap gap-0.5 rounded-lg border border-surface-200 bg-white p-0.5" role="group" aria-label="Document version">
                        <Button label="Original document" size="small" severity="secondary" text :aria-pressed="mode === 'original'" @click="setMode('original')" />
                        <Button label="Generated report" size="small" severity="secondary" text :aria-pressed="mode === 'generated'" @click="setMode('generated')" />
                    </div>
                    <Button
                        size="small" severity="secondary" outlined icon="pi pi-list"
                        :label="detailsOpen ? 'Hide details' : 'Show details'" :aria-expanded="detailsOpen"
                        aria-controls="barangay-document-details" @click="detailsOpen = !detailsOpen"
                    />
                    <Button
                        v-tooltip.bottom="'More actions'" size="small" severity="secondary" outlined icon="pi pi-ellipsis-h"
                        aria-label="More actions" aria-haspopup="menu" aria-controls="barangay-document-actions_list"
                        :aria-expanded="actionsOpen" @click="actions.toggle($event)"
                    />
                    <Menu id="barangay-document-actions" ref="actions" class="barangay-document-actions-menu" :model="documentActions" popup
                        @show="actionsOpen = true" @hide="actionsOpen = false" />
                </div>
            </div>
        </header>
        <header v-else class="report-document-header">
            <div>
                <button type="button" class="resident-action resident-action-secondary" @click="route.query.return ? router.push(route.query.return) : router.push(listPath)">Back to Reports</button>
                <h2>{{ title }}</h2>
                <p>{{ record?.reference || 'Loading report…' }}</p>
            </div>
            <div class="report-document-meta">
                <span v-if="record" class="report-status" :data-status="record.status">{{ record.label }}</span>
                <span v-if="record">Version {{ record.version || 1 }}</span>
                <div v-if="hasOriginal" class="report-mode-switch" role="group" aria-label="Document mode">
                    <button type="button" :aria-pressed="mode === 'original'" @click="setMode('original')">Original Document</button>
                    <button type="button" :aria-pressed="mode === 'generated'" @click="setMode('generated')">Generated Report</button>
                </div>
            </div>
            <button type="button" class="residents-icon-btn" :title="detailsOpen ? 'Hide details' : 'Show details'" :aria-label="detailsOpen ? 'Hide details' : 'Show details'" @click="detailsOpen = !detailsOpen"><MapIcon name="panel" /></button>
            <button type="button" class="residents-icon-btn" title="More actions" aria-label="More actions" @click="menuOpen = !menuOpen"><MapIcon name="more" /></button>
            <div v-if="menuOpen" class="report-menu" role="menu">
                <button type="button" role="menuitem" @click="viewer?.printDocument()">Print</button>
                <a v-if="downloadUrl" role="menuitem" :href="downloadUrl">Download this version</a>
            </div>
        </header>
        <template v-if="barangay">
            <Message v-if="error && !record" severity="error" size="small" class="barangay-document-feedback">
                <span>{{ error }}</span>
                <Button type="button" label="Retry" size="small" severity="secondary" text class="ms-2" @click="loadPage" />
            </Message>
        </template>
        <template v-else>
            <p v-if="error" class="cdrrmo-error" role="alert">{{ error }} <button type="button" class="resident-action resident-action-secondary" @click="load">Retry</button></p>
            <p v-if="message" class="cdrrmo-muted" role="status">{{ message }}</p>
        </template>
        <div :class="[barangay ? 'barangay-document-layout grid min-w-0 items-start gap-3' : 'report-document-layout', { 'details-hidden': !detailsOpen }]">
            <ReportDocumentViewer
                v-if="record"
                ref="viewer"
                :source-url="sourceUrl"
                :download-url="downloadUrl"
                :mode-label="mode === 'original' ? 'Original document' : 'Generated report'"
                :presentation="barangay ? 'barangay' : ''"
            />
            <Skeleton v-else-if="barangay && !error" height="28rem" class="barangay-document-loading" aria-hidden="true" />
            <BarangayDocumentDetails v-if="barangay && record" v-show="detailsOpen"
                v-model:reason="flagForm.reason" v-model:resolution="resolution"
                :record="record" :title="title" :status-tone="statusTone" :linked="linked" :checklist="checklist"
                :error="error" :message="message"
                @add-flag="addFlag" @resolve-flag="resolveFlag"
            />
            <aside v-else-if="detailsOpen && record" class="report-details-panel">
                <section>
                    <h3>Overview</h3>
                    <dl>
                        <div><dt>Reference</dt><dd>{{ record.reference }}</dd></div>
                        <div><dt>Type</dt><dd>{{ title }}</dd></div>
                        <div><dt>Barangay</dt><dd>{{ record.barangay?.name || 'Not recorded' }}</dd></div>
                        <div><dt>Incident</dt><dd>{{ record.incident?.name || record.incident?.reference || 'Not recorded' }}</dd></div>
                        <div><dt>Author</dt><dd>{{ record.assessor?.name || record.author || 'Not recorded' }}</dd></div>
                        <div><dt>Workflow</dt><dd>{{ record.label }}</dd></div>
                        <div><dt>Updated</dt><dd>Version {{ record.version || 1 }} · {{ record.updated_at || 'Not recorded' }}</dd></div>
                    </dl>
                </section>
                <section>
                    <h3>Linked records</h3>
                    <ul>
                        <li v-for="[label, value, known] in linked" :key="label">
                            <span>{{ label }}</span>
                            <strong>{{ value }}</strong>
                            <em v-if="!known && value !== 'Not recorded'">Not a verified link</em>
                        </li>
                    </ul>
                    <router-link class="resident-action resident-action-secondary" to="/map"><MapIcon name="pin" /> View on Map</router-link>
                </section>
                <section>
                    <h3>Checklist</h3>
                    <div v-for="item in record.checklist || []" :key="item.key" class="report-check-row" :data-state="checklistState(item)">
                        <MapIcon :name="checklistState(item) === 'complete' ? 'check' : (checklistState(item) === 'locked' ? 'lock' : 'warning')" />
                        <details>
                            <summary>{{ checklistLabels[item.key] || item.label }}</summary>
                            <p>{{ checklistDetail(item) }}</p>
                        </details>
                    </div>
                </section>
                <section>
                    <h3>Workflow and history</h3>
                    <ul class="report-history">
                        <li v-for="(event, index) in record.timeline || []" :key="index">
                            <strong>{{ event.summary || event.action_type }}</strong>
                            <span>{{ event.created_at }}</span>
                        </li>
                        <li v-if="!(record.timeline || []).length">No workflow history is recorded.</li>
                    </ul>
                    <div v-for="flag in record.flags || []" :key="flag.id" class="report-flag-row">
                        <strong>{{ flag.category }} · {{ flag.status }}</strong>
                        <p>{{ flag.reason }}</p>
                        <form v-if="flag.status === 'open'" @submit.prevent="resolveFlag(flag)">
                            <input v-model="resolution" required placeholder="Resolution">
                            <button type="submit" class="resident-action resident-action-secondary">Resolve</button>
                        </form>
                    </div>
                    <form class="report-flag-form" @submit.prevent="addFlag">
                        <label>Flag reason
                            <textarea v-model="flagForm.reason" required minlength="3" rows="2"></textarea>
                        </label>
                        <button type="submit" class="resident-action resident-action-secondary"><MapIcon name="flag" /> Flag for review</button>
                    </form>
                </section>
            </aside>
        </div>
    </section>
</template>
