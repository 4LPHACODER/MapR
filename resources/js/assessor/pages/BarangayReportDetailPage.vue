<script setup>
import { computed, inject, nextTick, onBeforeUnmount, onMounted, ref, watch } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import MapIcon from '../../shared/components/MapIcon.vue';
import ReportPdfViewer from '../../shared/components/ReportPdfViewer.vue';
import ReviewSignDialog from '../../shared/components/ReviewSignDialog.vue';
import DamageAssessmentWorkspace from '../components/DamageAssessmentWorkspace.vue';

const apiBase = inject('apiBase');
const roleCode = inject('roleCode', '');
const route = useRoute();
const router = useRouter();
const report = ref(null);
const draftId = ref(null);
const phase = ref('loading');
const error = ref('');
const actionError = ref('');
const downloadError = ref('');
const busy = ref(false);
const menuOpen = ref(false);
const assessmentOpen = ref(false);
const damageSummary = ref(null);
const viewer = ref(null);
const flagDialog = ref(null);
const returnDialog = ref(null);
const resolveDialog = ref(null);
const statusDialog = ref(null);
const costDialog = ref(null);
const resolveTarget = ref(null);
const flagCategory = ref('Photographs');
const flagSection = ref('');
const flagReason = ref('');
const returnReason = ref('');
const resolution = ref('');
const reviseStatusId = ref('');
const reviseCost = ref('');
const reviseReason = ref('');
const damageClasses = ref([]);
const signOpen = ref(false);
const signMode = ref('verify-officer');
const documentNonce = ref(0);
const profile = ref(null);
const menuButton = ref(null);
const wideReview = ref(typeof window !== 'undefined' && window.matchMedia('(min-width: 1024px)').matches);
const reviewOpen = ref(false);
const historyOpen = ref(false);
let stopReviewMedia = () => {};

const reportId = computed(() => (route.params.id ? String(route.params.id) : ''));
const isSupervisor = computed(() => ['rdana_admin_officer', 'rdana_city_assessor'].includes(roleCode || profile.value?.role_code || ''));
const fromCompleted = computed(() => route.query.from === 'completed');

const documentUrl = computed(() => (
    reportId.value
        ? `/rdana/reports/barangay/${reportId.value}/preview?format=pdf&inline=1&v=${documentNonce.value}`
        : ''
));

const downloadUrl = computed(() => (
    reportId.value
        ? `/rdana/reports/barangay/${reportId.value}/preview?format=pdf&v=${documentNonce.value}`
        : ''
));

const statusLabel = computed(() => {
    if (report.value?.verification_label) return report.value.verification_label;
    const status = report.value?.status;
    if (status === 'review') return 'Barangay Report';
    if (status === 'returned') return 'Returned for Correction';
    return report.value?.label || '—';
});

const canReturnBarangay = computed(() => report.value?.status === 'review' && !report.value?.assessment_id);
const canReturnAssessment = computed(() => Boolean(isSupervisor.value && report.value?.can_return_assessment && report.value?.assessment_id));
const canReturn = computed(() => canReturnAssessment.value || canReturnBarangay.value);
const canAssess = computed(() => !isSupervisor.value && report.value?.status === 'review');
const canVerifyOfficer = computed(() => roleCode === 'rdana_admin_officer' && Boolean(report.value?.can_verify_officer));
const canForwardCity = computed(() => roleCode === 'rdana_city_assessor' && Boolean(report.value?.can_forward_city));
const canReviseDamage = computed(() => Boolean(isSupervisor.value && report.value?.can_revise_damage));
const openFlags = computed(() => Number(report.value?.open_flags || 0));
const panelOpen = computed(() => wideReview.value || reviewOpen.value);
const backLink = computed(() => (
    fromCompleted.value
        ? { path: '/reports/barangay', query: { queue: 'completed' } }
        : { path: '/reports/barangay' }
));
const backLabel = computed(() => (fromCompleted.value ? 'Back to Completed Assessments' : 'Back to Barangay Reports'));
const activeDamage = computed(() => damageSummary.value || report.value?.damage_summary || null);

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

function formatMoney(value) {
    if (value === null || value === undefined || Number.isNaN(Number(value))) return null;
    return `₱${Number(value).toLocaleString('en-PH', { minimumFractionDigits: 2, maximumFractionDigits: 2 })}`;
}

async function loadDraftId(reference) {
    draftId.value = null;
    if (!reference) return;
    try {
        const { data } = await window.axios.get(`${apiBase}/barangay-reports`, {
            params: { search: reference, per_page: 50 },
        });
        const match = (data.data || []).find((row) => Number(row.id) === Number(reportId.value));
        draftId.value = match?.draft_id ? Number(match.draft_id) : null;
    } catch {
        draftId.value = null;
    }
}

async function loadDamageSummary() {
    damageSummary.value = null;
    if (!reportId.value) return;
    try {
        const { data } = await window.axios.get(`${apiBase}/barangay-reports/${reportId.value}/damage-assessment`);
        const current = data.data?.current_assessments || [];
        damageSummary.value = current.find((row) => row.assessment_status === 'confirmed')
            || current.find((row) => row.assessment_status === 'draft')
            || null;
    } catch {
        damageSummary.value = null;
    }
}

function onAssessmentSaved(row) {
    damageSummary.value = row;
    viewer.value?.reload();
}

async function load() {
    phase.value = 'loading';
    error.value = '';
    actionError.value = '';
    report.value = null;
    draftId.value = null;
    try {
        if (!reportId.value) {
            phase.value = 'error';
            error.value = 'This barangay report could not be found.';
            return;
        }
        const { data } = await window.axios.get(`${apiBase}/barangay-reports/${reportId.value}`);
        report.value = data.data;
        phase.value = 'ready';
        await loadDraftId(report.value?.reference);
        await loadDamageSummary();
        await nextTick();
        openRequestedPanel();
    } catch (exception) {
        phase.value = 'error';
        error.value = exception.response?.status === 403
            ? 'This barangay report is outside your assignment.'
            : exception.response?.status === 404
                ? 'This barangay report could not be found.'
                : 'This barangay report could not be loaded.';
    }
}

async function reloadReport() {
    const { data } = await window.axios.get(`${apiBase}/barangay-reports/${reportId.value}`);
    report.value = data.data;
    await loadDraftId(report.value?.reference);
    await viewer.value?.reload();
}

function openRequestedPanel() {
    if (route.query.panel === 'flag') flagDialog.value?.showModal();
    if (route.query.panel === 'return' && canReturn.value) returnDialog.value?.showModal();
    if (route.query.sign === 'verify-officer' && canVerifyOfficer.value) {
        openSign('verify-officer');
        const nextQuery = { ...route.query };
        delete nextQuery.sign;
        router.replace({ path: route.path, query: nextQuery });
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

async function startAssessment() {
    if (busy.value || !report.value) return;
    busy.value = true;
    actionError.value = '';
    try {
        const { data } = await window.axios.post(`${apiBase}/barangay-reports/${report.value.id}/start`);
        const id = data.data?.id;
        if (id) router.push(`/assessments/${id}`);
    } catch (exception) {
        actionError.value = exception.response?.data?.message || 'This report cannot be assessed from this account.';
    } finally {
        busy.value = false;
    }
}

function resumeAssessment() {
    if (!draftId.value) return;
    router.push(`/assessments/${draftId.value}`);
}

async function submitFlag() {
    if (busy.value || !report.value) return;
    const typed = flagReason.value;
    busy.value = true;
    actionError.value = '';
    try {
        await window.axios.post(`${apiBase}/flags`, {
            subject_type: 'barangay_report',
            subject_id: report.value.id,
            category: flagCategory.value,
            section: flagSection.value.trim() || null,
            reason: typed.trim(),
        });
        flagReason.value = '';
        flagSection.value = '';
        flagDialog.value?.close();
        await reloadReport();
    } catch (exception) {
        flagReason.value = typed;
        actionError.value = exception.response?.data?.message || 'The flag could not be saved.';
    } finally {
        busy.value = false;
    }
}

function openResolve(flag) {
    resolveTarget.value = flag;
    resolution.value = '';
    resolveDialog.value?.showModal();
}

async function resolveFlag() {
    if (busy.value || !resolveTarget.value) return;
    const typed = resolution.value;
    busy.value = true;
    actionError.value = '';
    try {
        await window.axios.post(`${apiBase}/flags/${resolveTarget.value.id}/resolve`, { resolution: typed.trim() });
        resolveDialog.value?.close();
        resolution.value = '';
        await reloadReport();
    } catch (exception) {
        resolution.value = typed;
        actionError.value = exception.response?.data?.errors?.resolution?.[0]
            || exception.response?.data?.message
            || 'The flag could not be resolved.';
    } finally {
        busy.value = false;
    }
}

async function returnReport() {
    if (busy.value || !canReturn.value) return;
    const typed = returnReason.value;
    busy.value = true;
    actionError.value = '';
    try {
        if (canReturnAssessment.value && report.value.assessment_id) {
            await window.axios.post(`${apiBase}/assessments/${report.value.assessment_id}/return`, {
                return_reason: typed.trim(),
            });
        } else {
            await window.axios.post(`${apiBase}/barangay-reports/${report.value.id}/return`, {
                return_reason: typed.trim(),
            });
        }
        returnDialog.value?.close();
        returnReason.value = '';
        await reloadReport();
        await loadDamageSummary();
    } catch (exception) {
        returnReason.value = typed;
        const validation = exception.response?.data?.errors?.return_reason?.[0];
        actionError.value = validation || exception.response?.data?.message || 'The report could not be returned.';
    } finally {
        busy.value = false;
    }
}

function openAssessReport() {
    closeActions(false);
    if (draftId.value || report.value?.assessment_id) {
        router.push(`/assessments/${draftId.value || report.value.assessment_id}`);
        return;
    }
    startAssessment();
}

function openSign(mode) {
    signMode.value = mode;
    signOpen.value = true;
    closeActions(false);
}

async function confirmSign(payload) {
    if (!report.value?.assessment_id) return;
    signOpen.value = false;
    busy.value = true;
    actionError.value = '';
    try {
        const path = signMode.value === 'forward'
            ? `${apiBase}/assessments/${report.value.assessment_id}/forward`
            : `${apiBase}/assessments/${report.value.assessment_id}/verify-officer`;
        const body = new FormData();
        body.append('confirmed', payload?.confirmed ? '1' : '0');
        body.append('password', payload?.password || '');
        if (payload?.use_saved_signature) {
            body.append('use_saved_signature', '1');
        } else if (payload?.signatureFile) {
            body.append('signature', payload.signatureFile, payload.signatureFile.name || 'signature.png');
            body.append('use_saved_signature', '0');
        }
        await window.axios.post(path, body);
        documentNonce.value += 1;
        await reloadReport();
        await loadDamageSummary();
        viewer.value?.reload();
    } catch (exception) {
        actionError.value = exception.response?.data?.message || 'The signature could not be recorded.';
    } finally {
        busy.value = false;
    }
}

async function loadDamageClasses() {
    try {
        const { data } = await window.axios.get(`${apiBase}/damage-classes`);
        damageClasses.value = data.data || [];
    } catch {
        damageClasses.value = [];
    }
}

function openStatusRevise() {
    reviseStatusId.value = String(activeDamage.value?.confirmed_damage_class_id || '');
    reviseReason.value = '';
    statusDialog.value?.showModal();
    closeActions(false);
}

function openCostRevise() {
    reviseCost.value = activeDamage.value?.estimated_cost_php != null ? String(activeDamage.value.estimated_cost_php) : '';
    reviseReason.value = '';
    costDialog.value?.showModal();
    closeActions(false);
}

async function submitStatusRevise() {
    if (busy.value || !report.value) return;
    busy.value = true;
    actionError.value = '';
    try {
        await window.axios.post(`${apiBase}/barangay-reports/${report.value.id}/damage-assessment/revise`, {
            confirmed_damage_class_id: Number(reviseStatusId.value),
            correction_reason: reviseReason.value.trim(),
        });
        statusDialog.value?.close();
        await reloadReport();
        await loadDamageSummary();
        viewer.value?.reload();
    } catch (exception) {
        actionError.value = exception.response?.data?.message || 'Building status could not be updated.';
    } finally {
        busy.value = false;
    }
}

async function submitCostRevise() {
    if (busy.value || !report.value) return;
    busy.value = true;
    actionError.value = '';
    try {
        await window.axios.post(`${apiBase}/barangay-reports/${report.value.id}/damage-assessment/revise`, {
            estimated_cost_php: Number(reviseCost.value),
            correction_reason: reviseReason.value.trim(),
        });
        costDialog.value?.close();
        await reloadReport();
        await loadDamageSummary();
        viewer.value?.reload();
    } catch (exception) {
        actionError.value = exception.response?.data?.message || 'Estimated cost could not be updated.';
    } finally {
        busy.value = false;
    }
}

function printReport() {
    window.open(documentUrl.value, '_blank', 'noopener');
}

function showHistory() {
    menuOpen.value = false;
    reviewOpen.value = true;
    nextTick(() => document.getElementById('assessor-review-history')?.scrollIntoView({ block: 'start' }));
}

function onPanelToggle(event) {
    if (!wideReview.value) reviewOpen.value = event.target.open;
}

async function toggleActions() {
    menuOpen.value = !menuOpen.value;
    if (!menuOpen.value) return;
    await nextTick();
    document.querySelector('#assessor-action-menu [role="menuitem"]:not(:disabled)')?.focus();
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
    const menu = document.getElementById('assessor-action-menu');
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

onMounted(async () => {
    const media = window.matchMedia('(min-width: 1024px)');
    const sync = () => {
        wideReview.value = media.matches;
    };
    sync();
    media.addEventListener('change', sync);
    stopReviewMedia = () => media.removeEventListener('change', sync);
    document.addEventListener('pointerdown', onDocumentPointer);
    document.addEventListener('keydown', onDocumentKey);
    try {
        profile.value = (await window.axios.get(`${apiBase}/profile`)).data;
    } catch {
        profile.value = null;
    }
    await loadDamageClasses();
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
            <RouterLink class="submission-back" to="/reports/barangay">Back to barangay reports</RouterLink>
        </div>
        <template v-else-if="report">
            <header class="bdrrm-detail-toolbar">
                <RouterLink class="submission-back" :to="backLink">
                    <MapIcon name="chevron" />
                    {{ backLabel }}
                </RouterLink>
                <div class="bdrrm-detail-identity">
                    <h2>{{ report.reference }}</h2>
                    <span class="submission-badge" :data-status="report.assessment_workflow_stage || report.status">{{ statusLabel }}</span>
                </div>
                <div class="bdrrm-detail-actions">
                    <button type="button" class="submission-quiet" :disabled="busy" @click="downloadPdf">
                        <MapIcon name="download" /> {{ busy ? 'Preparing PDF…' : 'Download PDF' }}
                    </button>
                    <button type="button" class="submission-quiet" @click="printReport">
                        <MapIcon name="printer" /> Print
                    </button>
                    <button
                        v-if="canAssess"
                        type="button"
                        class="submission-primary"
                        @click="openAssessReport"
                    >
                        <MapIcon name="clipboard-check" /> Assess Report
                    </button>
                    <button
                        v-else-if="canVerifyOfficer"
                        type="button"
                        class="submission-primary"
                        :disabled="busy"
                        @click="openSign('verify-officer')"
                    >
                        <MapIcon name="shield-check" /> Verify and Sign
                    </button>
                    <button
                        v-else-if="canForwardCity"
                        type="button"
                        class="submission-primary"
                        :disabled="busy"
                        @click="openSign('forward')"
                    >
                        <MapIcon name="pen-line" /> Review Report
                    </button>
                    <div class="submission-overflow">
                        <button
                            ref="menuButton"
                            type="button"
                            class="submission-quiet"
                            aria-haspopup="menu"
                            :aria-expanded="menuOpen"
                            aria-controls="assessor-action-menu"
                            @click="toggleActions"
                        >
                            Actions <MapIcon name="chevron" class="submission-chevron-down" />
                        </button>
                        <div v-if="menuOpen" id="assessor-action-menu" class="submission-menu" role="menu" @keydown="onActionMenuKey">
                            <button
                                v-if="canAssess"
                                type="button"
                                role="menuitem"
                                @click="openAssessReport"
                            >
                                <MapIcon name="clipboard-check" /> Assess Report
                            </button>
                            <button
                                v-if="canVerifyOfficer"
                                type="button"
                                role="menuitem"
                                :disabled="busy"
                                @click="openSign('verify-officer')"
                            >
                                <MapIcon name="shield-check" /> Verify and Sign
                            </button>
                            <button
                                v-if="canForwardCity"
                                type="button"
                                role="menuitem"
                                :disabled="busy"
                                @click="openSign('forward')"
                            >
                                <MapIcon name="pen-line" /> Review Report
                            </button>
                            <button type="button" role="menuitem" @click="flagDialog?.showModal(); closeActions(false)">
                                <MapIcon name="flag" /> Flag Report
                            </button>
                            <button
                                type="button"
                                role="menuitem"
                                :disabled="!canReturn"
                                :title="canReturn ? undefined : 'Only assessments awaiting supervisory review can be returned.'"
                                @click="returnDialog?.showModal(); closeActions(false)"
                            >
                                <MapIcon name="rotate-ccw" /> Return for Correction
                            </button>
                            <button
                                v-if="canReviseDamage"
                                type="button"
                                role="menuitem"
                                @click="openStatusRevise"
                            >
                                <MapIcon name="pencil" /> Edit Building Status
                            </button>
                            <button
                                v-if="canReviseDamage"
                                type="button"
                                role="menuitem"
                                @click="openCostRevise"
                            >
                                <MapIcon name="calculator" /> Edit Estimated Cost
                            </button>
                            <RouterLink v-if="mapLink" role="menuitem" :to="mapLink" @click="closeActions(false)">
                                <MapIcon name="pin" /> View location
                            </RouterLink>
                            <button v-else type="button" role="menuitem" disabled>No linked incident map</button>
                            <button type="button" role="menuitem" @click="showHistory">
                                <MapIcon name="history" /> View History
                            </button>
                        </div>
                    </div>
                </div>
            </header>
            <p v-if="downloadError" class="submission-alert" role="alert">{{ downloadError }}</p>
            <p v-if="actionError" class="submission-alert" role="alert">{{ actionError }}</p>
            <div class="bdrrm-review-layout">
                <ReportPdfViewer ref="viewer" presentation="review" :document-url="documentUrl" />
                <details class="bdrrm-review-disclosure" :open="panelOpen" @toggle="onPanelToggle">
                    <summary>
                        <span>Review</span>
                        <span class="submission-badge" :data-status="report.assessment_workflow_stage || report.status">{{ statusLabel }}</span>
                        <span>{{ report.assignee_name ? `Assessor ${report.assignee_name}` : (captainSigned ? 'Captain endorsed' : 'Awaiting captain endorsement') }}</span>
                        <span>{{ openFlags }} open {{ openFlags === 1 ? 'flag' : 'flags' }}</span>
                    </summary>
                    <div class="bdrrm-review-body">
                        <section class="bdrrm-review-section">
                            <h3><MapIcon name="clipboard" /> Review status</h3>
                            <span class="submission-badge" :data-status="report.assessment_workflow_stage || report.status">{{ statusLabel }}</span>
                            <p v-if="report.assignee_name" class="submission-note">Assigned Assessor: {{ report.assignee_name }}</p>
                            <p v-if="report.assessment_return_reason || report.return_reason">{{ report.assessment_return_reason || report.return_reason }}</p>
                            <p v-else-if="report.barangay_name || report.incident_name" class="submission-note">
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
                            <h3><MapIcon name="clipboard-check" /> Building Damage Assessment</h3>
                            <template v-if="activeDamage">
                                <p><strong>Building Status</strong></p>
                                <span class="submission-badge" :data-status="activeDamage.assessment_status">
                                    {{ activeDamage.confirmed_label || activeDamage.proposed_label || '—' }}
                                </span>
                                <p><strong>Estimated Cost Assessment</strong></p>
                                <p>
                                    <template v-if="activeDamage.cost_status === 'pending_inputs'">Pending Required Assessment Data</template>
                                    <template v-else>{{ formatMoney(activeDamage.estimated_cost_php) || '—' }}</template>
                                </p>
                                <p v-if="report.assignee_name || activeDamage.assessed_by_name" class="submission-note">
                                    Assessed By · {{ report.assignee_name || activeDamage.assessed_by_name }}
                                </p>
                                <p class="submission-note">
                                    Assessment Date
                                    <span v-if="activeDamage.confirmed_at || activeDamage.updated_at">
                                        · {{ formatWhen(activeDamage.confirmed_at || activeDamage.updated_at) }}
                                    </span>
                                </p>
                                <button v-if="canAssess" type="button" class="submission-quiet" @click="assessmentOpen = true">
                                    {{ activeDamage.assessment_status === 'draft' ? 'Edit Assessment' : 'View Assessment' }}
                                </button>
                            </template>
                            <template v-else>
                                <p class="submission-note">No assessment recorded yet.</p>
                            </template>
                        </section>
                        <section class="bdrrm-review-section">
                            <h3><MapIcon name="flag" /> Flags</h3>
                            <p>{{ openFlags }} open {{ openFlags === 1 ? 'flag' : 'flags' }}</p>
                            <ul v-if="openFlags > 0" class="submission-timeline">
                                <li v-for="flag in (report.flags || []).filter((item) => item.status === 'open')" :key="flag.id">
                                    <span class="submission-dot" aria-hidden="true"></span>
                                    <strong>{{ flag.category }}</strong>
                                    <p>{{ flag.reason }}</p>
                                    <button type="button" class="submission-quiet" :disabled="busy" @click="openResolve(flag)">Resolve</button>
                                </li>
                            </ul>
                            <p v-else class="submission-note">No open flags</p>
                        </section>
                        <section id="assessor-review-history" class="bdrrm-review-section">
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
            <dialog ref="flagDialog" class="bdrrm-dialog" @close="actionError = ''">
                <form @submit.prevent="submitFlag">
                    <h3>Flag an issue</h3>
                    <p class="submission-note">{{ report.reference }}</p>
                    <p v-if="actionError" class="submission-alert" role="alert">{{ actionError }}</p>
                    <label>
                        <span>Category</span>
                        <select v-model="flagCategory">
                            <option>Photographs</option>
                            <option>Description</option>
                            <option>Location</option>
                            <option>Other</option>
                        </select>
                    </label>
                    <label>
                        <span>Section or photo, if useful</span>
                        <input v-model="flagSection" maxlength="120" autocomplete="off">
                    </label>
                    <label>
                        <span>What needs attention?</span>
                        <textarea v-model="flagReason" rows="4" maxlength="2000" required />
                    </label>
                    <div class="bdrrm-dialog-actions">
                        <button type="submit" class="submission-primary" :disabled="busy" :aria-busy="busy">{{ busy ? 'Saving…' : 'Save flag' }}</button>
                        <button type="button" class="submission-quiet" @click="flagDialog?.close()">Cancel</button>
                    </div>
                </form>
            </dialog>
            <dialog ref="resolveDialog" class="bdrrm-dialog">
                <form @submit.prevent="resolveFlag">
                    <h3>Resolve flag</h3>
                    <p class="submission-note">{{ report.reference }}</p>
                    <p v-if="actionError" class="submission-alert" role="alert">{{ actionError }}</p>
                    <label>
                        <span>Resolution</span>
                        <textarea v-model="resolution" rows="4" maxlength="2000" required />
                    </label>
                    <div class="bdrrm-dialog-actions">
                        <button type="submit" class="submission-primary" :disabled="busy" :aria-busy="busy">{{ busy ? 'Saving…' : 'Save resolution' }}</button>
                        <button type="button" class="submission-quiet" @click="resolveDialog?.close()">Cancel</button>
                    </div>
                </form>
            </dialog>
            <dialog ref="returnDialog" class="bdrrm-dialog" @close="actionError = ''">
                <form @submit.prevent="returnReport">
                    <h3>Return Assessment for Correction</h3>
                    <p class="submission-note">{{ report.reference }}</p>
                    <p v-if="report.assignee_name" class="submission-note">Assigned Assessor: {{ report.assignee_name }}</p>
                    <p v-if="actionError" class="submission-alert" role="alert">{{ actionError }}</p>
                    <label>
                        <span>Required correction reason</span>
                        <textarea v-model="returnReason" rows="5" maxlength="5000" required />
                    </label>
                    <div class="bdrrm-dialog-actions">
                        <button type="submit" class="submission-primary" :disabled="busy || !canReturn" :aria-busy="busy">{{ busy ? 'Returning…' : 'Return for Correction' }}</button>
                        <button type="button" class="submission-quiet" @click="returnDialog?.close()">Cancel</button>
                    </div>
                </form>
            </dialog>
            <dialog ref="statusDialog" class="bdrrm-dialog" @close="actionError = ''">
                <form @submit.prevent="submitStatusRevise">
                    <h3>Edit Building Status</h3>
                    <p class="submission-note">{{ report.reference }}</p>
                    <p class="submission-note">Current: {{ activeDamage?.confirmed_label || '—' }}</p>
                    <p v-if="actionError" class="submission-alert" role="alert">{{ actionError }}</p>
                    <label>
                        <span>New Building Status</span>
                        <select v-model="reviseStatusId" required>
                            <option disabled value="">Choose status</option>
                            <option v-for="item in damageClasses" :key="item.id" :value="String(item.id)">{{ item.class_name }}</option>
                        </select>
                    </label>
                    <label>
                        <span>Reason for Modification</span>
                        <textarea v-model="reviseReason" rows="4" maxlength="2000" required />
                    </label>
                    <div class="bdrrm-dialog-actions">
                        <button type="submit" class="submission-primary" :disabled="busy" :aria-busy="busy">{{ busy ? 'Saving…' : 'Save Building Status' }}</button>
                        <button type="button" class="submission-quiet" @click="statusDialog?.close()">Cancel</button>
                    </div>
                </form>
            </dialog>
            <dialog ref="costDialog" class="bdrrm-dialog" @close="actionError = ''">
                <form @submit.prevent="submitCostRevise">
                    <h3>Edit Estimated Cost Assessment</h3>
                    <p class="submission-note">{{ report.reference }}</p>
                    <p class="submission-note">Calculated: {{ formatMoney(activeDamage?.calculated_estimated_cost_php) || '—' }}</p>
                    <p class="submission-note">Current Recorded: {{ formatMoney(activeDamage?.estimated_cost_php) || '—' }}</p>
                    <p v-if="actionError" class="submission-alert" role="alert">{{ actionError }}</p>
                    <label>
                        <span>Revised Estimated Cost</span>
                        <input v-model="reviseCost" type="number" min="0" step="0.01" required>
                    </label>
                    <label>
                        <span>Reason for Adjustment</span>
                        <textarea v-model="reviseReason" rows="4" maxlength="2000" required />
                    </label>
                    <div class="bdrrm-dialog-actions">
                        <button type="submit" class="submission-primary" :disabled="busy" :aria-busy="busy">{{ busy ? 'Saving…' : 'Save Estimated Cost' }}</button>
                        <button type="button" class="submission-quiet" @click="costDialog?.close()">Cancel</button>
                    </div>
                </form>
            </dialog>
            <ReviewSignDialog
                :open="signOpen"
                capture-signature
                :reference="report.reference || ''"
                :name="profile?.name || ''"
                :position="profile?.job_title || profile?.role || ''"
                :capacity="signMode === 'forward' ? 'approved_by' : 'verified_by'"
                @close="signOpen = false"
                @confirm="confirmSign"
            />
            <DamageAssessmentWorkspace
                :report-id="reportId"
                :open="assessmentOpen"
                @close="assessmentOpen = false"
                @saved="onAssessmentSaved"
            />
        </template>
    </section>
</template>
