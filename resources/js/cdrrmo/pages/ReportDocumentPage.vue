<script setup>
import { computed, onMounted, reactive, ref, watch } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import MapIcon from '../../shared/components/MapIcon.vue';
import ReportDocumentViewer from '../components/ReportDocumentViewer.vue';

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

const kind = computed(() => route.params.kind);
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
const checklistLabels = {
    fields: 'Required fields',
    evidence: 'Supporting evidence',
    verification: 'Location verification',
    predecessor: 'Previous signatures',
    authority: 'Signing authority',
    flags: 'Blocking flags',
    current: 'Current version',
};

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
    error.value = '';
    const { data } = await window.axios.get(`/cdrrmo/api/reports/${kind.value}/${route.params.id}`);
    record.value = data.data;
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

onMounted(async () => {
    try {
        await load();
    } catch (exception) {
        error.value = exception.response?.data?.message || 'This report could not be opened.';
    }
});
watch(() => route.params.id, () => load().catch(() => {}));
</script>

<template>
    <section class="report-document-page">
        <header class="report-document-header">
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
        <p v-if="error" class="cdrrmo-error" role="alert">{{ error }} <button type="button" class="resident-action resident-action-secondary" @click="load">Retry</button></p>
        <p v-if="message" class="cdrrmo-muted" role="status">{{ message }}</p>
        <div class="report-document-layout" :class="{ 'details-hidden': !detailsOpen }">
            <ReportDocumentViewer
                v-if="record"
                ref="viewer"
                :source-url="sourceUrl"
                :download-url="downloadUrl"
                :mode-label="mode === 'original' ? 'Original document' : 'Generated report'"
            />
            <aside v-if="detailsOpen && record" class="report-details-panel">
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
