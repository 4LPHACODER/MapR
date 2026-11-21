<script setup>
import { computed, inject, nextTick, onBeforeUnmount, onMounted, reactive, ref, watch } from 'vue';
import { RouterLink, useRoute } from 'vue-router';
import MapIcon from '../../shared/components/MapIcon.vue';
import ReportPdfViewer from '../../shared/components/ReportPdfViewer.vue';
import ReviewSignDialog from '../../shared/components/ReviewSignDialog.vue';
import DamageAssessmentWorkspace from '../components/DamageAssessmentWorkspace.vue';

const apiBase = inject('apiBase');
const roleCode = inject('roleCode', '');
const route = useRoute();

const assessment = ref(null);
const profile = ref(null);
const phase = ref('loading');
const error = ref('');
const actionError = ref('');
const downloadError = ref('');
const busy = ref(false);
const savingDraft = ref(false);
const menuOpen = ref(false);
const assessmentOpen = ref(false);
const editOpen = ref(false);
const viewer = ref(null);
const menuButton = ref(null);
const flagDialog = ref(null);
const returnDialog = ref(null);
const resolveDialog = ref(null);
const resolveTarget = ref(null);
const flagCategory = ref('Photographs');
const flagSection = ref('');
const flagReason = ref('');
const returnReason = ref('');
const resolution = ref('');
const signOpen = ref(false);
const signMode = ref('submit-assessed');
const documentNonce = ref(0);
const signaturesOpen = ref(false);
const historyOpen = ref(false);
const reviewOpen = ref(false);
const wideReview = ref(typeof window !== 'undefined' && window.matchMedia('(min-width: 1024px)').matches);
let stopReviewMedia = () => {};

const editForm = reactive({
    findings: '',
    location_verified: false,
    household_link_verified: false,
    verification_notes: '',
    building_id: '',
    damage_class_id: '',
});

const assessmentId = computed(() => (route.params.id ? String(route.params.id) : ''));
const documentUrl = computed(() => (
    assessmentId.value
        ? `/rdana/assessments/${assessmentId.value}/preview?format=pdf&inline=1&v=${documentNonce.value}`
        : ''
));
const downloadUrl = computed(() => (
    assessmentId.value
        ? `/rdana/assessments/${assessmentId.value}/preview?format=pdf&v=${documentNonce.value}`
        : ''
));
const statusLabel = computed(() => assessment.value?.label || '—');
const canEdit = computed(() => Boolean(assessment.value?.can_edit));
const canSubmitAssessed = computed(() => Boolean(assessment.value?.can_submit_assessed && roleCode === 'rdana'));
const canVerifyOfficer = computed(() => Boolean(assessment.value?.can_verify_officer && roleCode === 'rdana_admin_officer'));
const canForwardCity = computed(() => Boolean(assessment.value?.can_forward_city && roleCode === 'rdana_city_assessor'));
const canReturn = computed(() => Boolean(assessment.value?.can_return_assessment));
const canSign = computed(() => canSubmitAssessed.value || canVerifyOfficer.value || canForwardCity.value);
const signLabel = computed(() => {
    if (canSubmitAssessed.value) return 'Review & Sign Assessment';
    if (canVerifyOfficer.value) return 'Verify and Sign';
    if (canForwardCity.value) return 'Review Report';
    return 'Review & Sign';
});
const openFlags = computed(() => Number(assessment.value?.open_flags || 0));
const panelOpen = computed(() => wideReview.value || reviewOpen.value);
const sourceReportId = computed(() => assessment.value?.barangay_report?.id || assessment.value?.barangay_report_id || null);
const activeDamage = computed(() => assessment.value?.damage_summary || null);
const signatureSlots = computed(() => assessment.value?.signature_slots || []);
const signaturePreview = computed(() => signatureSlots.value.slice(0, 4));
const signatureExtra = computed(() => Math.max(0, signatureSlots.value.length - 4));
const visibleSignatures = computed(() => (signaturesOpen.value ? signatureSlots.value : signaturePreview.value));
const signedCount = computed(() => Number(
    assessment.value?.signature_signed_count
    ?? signatureSlots.value.filter((slot) => slot.signed).length,
));
const checklist = computed(() => assessment.value?.checklist || []);
const historyNewest = computed(() => [...(assessment.value?.timeline || [])]
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

function hydrateEditForm(record) {
    const verification = (record?.verifications || [])[0] || null;
    editForm.findings = record?.findings || '';
    editForm.location_verified = Boolean(verification?.location_verified);
    editForm.household_link_verified = Boolean(verification?.household_link_verified);
    editForm.verification_notes = verification?.verification_notes || '';
    editForm.building_id = verification?.building_id || record?.building?.id || '';
    editForm.damage_class_id = verification?.verified_damage_class_id || '';
}

async function load() {
    phase.value = 'loading';
    error.value = '';
    actionError.value = '';
    assessment.value = null;
    try {
        if (!assessmentId.value) {
            phase.value = 'error';
            error.value = 'This field assessment could not be found.';
            return;
        }
        const { data } = await window.axios.get(`${apiBase}/assessments/${assessmentId.value}`);
        assessment.value = data.data;
        hydrateEditForm(assessment.value);
        phase.value = 'ready';
        await nextTick();
        if (route.query.panel === 'edit' && canEdit.value) {
            editOpen.value = true;
        }
    } catch (exception) {
        phase.value = 'error';
        error.value = exception.response?.status === 403
            ? 'This field assessment is outside your assignment.'
            : exception.response?.status === 404
                ? 'This field assessment could not be found.'
                : 'This field assessment could not be loaded.';
    }
}

async function reloadAssessment() {
    const { data } = await window.axios.get(`${apiBase}/assessments/${assessmentId.value}`);
    assessment.value = data.data;
    hydrateEditForm(assessment.value);
    await viewer.value?.reload();
}

async function downloadPdf() {
    if (busy.value || !assessment.value) return;
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
        link.download = `MapR_Field_Assessment_${assessment.value.reference}.pdf`;
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
    window.open(documentUrl.value, '_blank', 'noopener');
}

function openEdit() {
    if (!canEdit.value) return;
    hydrateEditForm(assessment.value);
    editOpen.value = true;
    closeActions(false);
}

function openDamageWorkspace() {
    if (!sourceReportId.value) return;
    assessmentOpen.value = true;
    closeActions(false);
}

function onAssessmentSaved() {
    reloadAssessment();
}

function resolveSignMode() {
    const capacity = profile.value?.signing_capacity;
    if (capacity === 'assessed_by' || canSubmitAssessed.value) return 'submit-assessed';
    if (capacity === 'verified_by' || canVerifyOfficer.value) return 'verify-officer';
    if (capacity === 'approved_by' || canForwardCity.value) return 'forward';
    return 'submit-assessed';
}

function openSign(mode = null) {
    signMode.value = mode || resolveSignMode();
    signOpen.value = true;
    closeActions(false);
}

async function confirmSign(payload) {
    if (!assessment.value) return;
    signOpen.value = false;
    busy.value = true;
    actionError.value = '';
    try {
        const path = signMode.value === 'forward'
            ? `${apiBase}/assessments/${assessment.value.id}/forward`
            : signMode.value === 'verify-officer'
                ? `${apiBase}/assessments/${assessment.value.id}/verify-officer`
                : `${apiBase}/assessments/${assessment.value.id}/submit-assessed`;
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
        await reloadAssessment();
    } catch (exception) {
        actionError.value = exception.response?.data?.message || 'The signature could not be recorded.';
    } finally {
        busy.value = false;
    }
}

async function saveDraft() {
    if (!assessment.value || savingDraft.value) return;
    savingDraft.value = true;
    actionError.value = '';
    try {
        await window.axios.patch(`${apiBase}/assessments/${assessment.value.id}/findings`, {
            findings: editForm.findings,
            updated_at: assessment.value.updated_at,
        });
        if (editForm.building_id && editForm.damage_class_id) {
            await window.axios.post(`${apiBase}/assessments/${assessment.value.id}/verifications`, {
                building_id: Number(editForm.building_id),
                location_verified: editForm.location_verified,
                household_link_verified: editForm.household_link_verified,
                verification_notes: editForm.verification_notes || null,
                verified_damage_class_id: Number(editForm.damage_class_id),
            });
        }
        await reloadAssessment();
    } catch (exception) {
        actionError.value = exception.response?.data?.message || 'The draft could not be saved.';
    } finally {
        savingDraft.value = false;
    }
}

async function submitFlag() {
    if (busy.value || !assessment.value) return;
    const typed = flagReason.value;
    busy.value = true;
    actionError.value = '';
    try {
        await window.axios.post(`${apiBase}/flags`, {
            subject_type: 'field_assessment',
            subject_id: assessment.value.id,
            category: flagCategory.value,
            section: flagSection.value.trim() || null,
            reason: typed.trim(),
        });
        flagReason.value = '';
        flagSection.value = '';
        flagDialog.value?.close();
        await reloadAssessment();
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
        await reloadAssessment();
    } catch (exception) {
        resolution.value = typed;
        actionError.value = exception.response?.data?.errors?.resolution?.[0]
            || exception.response?.data?.message
            || 'The flag could not be resolved.';
    } finally {
        busy.value = false;
    }
}

async function returnAssessment() {
    if (busy.value || !canReturn.value || !assessment.value) return;
    const typed = returnReason.value;
    busy.value = true;
    actionError.value = '';
    try {
        await window.axios.post(`${apiBase}/assessments/${assessment.value.id}/return`, {
            return_reason: typed.trim(),
        });
        returnDialog.value?.close();
        returnReason.value = '';
        await reloadAssessment();
    } catch (exception) {
        returnReason.value = typed;
        const validation = exception.response?.data?.errors?.return_reason?.[0];
        actionError.value = validation || exception.response?.data?.message || 'The assessment could not be returned.';
    } finally {
        busy.value = false;
    }
}

function showHistory() {
    menuOpen.value = false;
    reviewOpen.value = true;
    nextTick(() => document.getElementById('fa-review-history')?.scrollIntoView({ block: 'start' }));
}

function onPanelToggle(event) {
    if (!wideReview.value) reviewOpen.value = event.target.open;
}

async function toggleActions() {
    menuOpen.value = !menuOpen.value;
    if (!menuOpen.value) return;
    await nextTick();
    document.querySelector('#fa-action-menu [role="menuitem"]:not(:disabled)')?.focus();
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
    const menu = document.getElementById('fa-action-menu');
    if (menu?.contains(event.target) || menuButton.value?.contains(event.target)) return;
    closeActions(false);
}

function onDocumentKey(event) {
    if (event.key === 'Escape' && menuOpen.value) closeActions();
}

watch(assessmentId, () => {
    menuOpen.value = false;
    editOpen.value = false;
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
});

onBeforeUnmount(() => {
    stopReviewMedia();
    document.removeEventListener('pointerdown', onDocumentPointer);
    document.removeEventListener('keydown', onDocumentKey);
});
</script>

<template>
    <section class="bdrrm-submissions bdrrm-submission-detail">
        <p v-if="phase === 'loading'" class="submission-status">Loading field assessment…</p>
        <div v-else-if="phase === 'error'" class="submission-status" role="alert">
            <p>{{ error }}</p>
            <RouterLink class="submission-back" to="/reports/field">Back to Field Assessment Reports</RouterLink>
        </div>
        <template v-else-if="assessment">
            <header class="bdrrm-detail-toolbar">
                <RouterLink class="submission-back" to="/reports/field">
                    <MapIcon name="chevron" />
                    Back to Field Assessment Reports
                </RouterLink>
                <div class="bdrrm-detail-identity">
                    <h2>{{ assessment.reference }}</h2>
                    <span class="submission-badge" :data-status="assessment.workflow_stage || assessment.status">{{ statusLabel }}</span>
                </div>
                <div class="bdrrm-detail-actions">
                    <button
                        v-if="canEdit"
                        type="button"
                        class="submission-quiet"
                        @click="openEdit"
                    >
                        <MapIcon name="pencil" /> Edit Assessment
                    </button>
                    <button type="button" class="submission-quiet" :disabled="busy" @click="downloadPdf">
                        <MapIcon name="download" /> {{ busy ? 'Preparing PDF…' : 'Download PDF' }}
                    </button>
                    <button type="button" class="submission-quiet" @click="printReport">
                        <MapIcon name="printer" /> Print
                    </button>
                    <button
                        v-if="canEdit && sourceReportId"
                        type="button"
                        class="submission-quiet"
                        @click="openDamageWorkspace"
                    >
                        <MapIcon name="clipboard-check" /> Generate Damage Assessment
                    </button>
                    <button
                        v-if="canSign"
                        type="button"
                        class="submission-primary"
                        :disabled="busy"
                        @click="openSign()"
                    >
                        <MapIcon :name="canSubmitAssessed ? 'clipboard-check' : 'shield-check'" /> {{ signLabel }}
                    </button>
                    <div class="submission-overflow">
                        <button
                            ref="menuButton"
                            type="button"
                            class="submission-quiet"
                            aria-haspopup="menu"
                            :aria-expanded="menuOpen"
                            aria-controls="fa-action-menu"
                            @click="toggleActions"
                        >
                            Actions <MapIcon name="chevron" class="submission-chevron-down" />
                        </button>
                        <div v-if="menuOpen" id="fa-action-menu" class="submission-menu" role="menu" @keydown="onActionMenuKey">
                            <RouterLink
                                v-if="sourceReportId"
                                role="menuitem"
                                :to="`/reports/barangay/${sourceReportId}`"
                                @click="closeActions(false)"
                            >
                                <MapIcon name="file" /> View Source Report
                            </RouterLink>
                            <button type="button" role="menuitem" @click="flagDialog?.showModal(); closeActions(false)">
                                <MapIcon name="flag" /> Flag Assessment
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
                        <span class="submission-badge" :data-status="assessment.workflow_stage || assessment.status">{{ statusLabel }}</span>
                        <span>{{ assessment.assessor?.name ? `Assessor ${assessment.assessor.name}` : 'Unassigned' }}</span>
                        <span>{{ openFlags }} open {{ openFlags === 1 ? 'flag' : 'flags' }}</span>
                    </summary>
                    <div class="bdrrm-review-body">
                        <section class="bdrrm-review-section">
                            <h3><MapIcon name="clipboard" /> Report status</h3>
                            <span class="submission-badge" :data-status="assessment.workflow_stage || assessment.status">{{ statusLabel }}</span>
                            <p class="submission-note">{{ assessment.reference }}</p>
                            <p v-if="assessment.assessor?.name" class="submission-note">Assigned Assessor: {{ assessment.assessor.name }}</p>
                            <p v-if="assessment.updated_at" class="submission-note">Updated {{ formatWhen(assessment.updated_at) }}</p>
                            <p v-if="assessment.return_reason">{{ assessment.return_reason }}</p>
                        </section>
                        <section class="bdrrm-review-section">
                            <h3><MapIcon name="file" /> Source report</h3>
                            <p v-if="assessment.barangay_report?.reference"><strong>{{ assessment.barangay_report.reference }}</strong></p>
                            <p v-if="assessment.resident_report?.reference || assessment.lineage?.resident_report_reference" class="submission-note">
                                Resident report {{ assessment.resident_report?.reference || assessment.lineage?.resident_report_reference }}
                            </p>
                            <p class="submission-note">
                                Source signing {{ assessment.barangay_report?.signed ? 'complete' : 'incomplete' }}
                            </p>
                            <RouterLink
                                v-if="sourceReportId"
                                class="submission-quiet"
                                :to="`/reports/barangay/${sourceReportId}`"
                            >
                                View Source Report
                            </RouterLink>
                        </section>
                        <section class="bdrrm-review-section">
                            <h3><MapIcon name="pin" /> Linked records</h3>
                            <p class="submission-note">Resident {{ assessment.resident?.reference || assessment.lineage?.resident_reference || 'Not recorded' }}</p>
                            <p class="submission-note">Household {{ assessment.household?.reference || assessment.lineage?.household_reference || 'Not recorded' }}</p>
                            <p class="submission-note">Building {{ assessment.building?.reference || assessment.lineage?.building_reference || 'Not recorded' }}</p>
                            <p class="submission-note">Barangay {{ assessment.barangay?.name || 'Not recorded' }}</p>
                        </section>
                        <section class="bdrrm-review-section">
                            <h3><MapIcon name="clipboard-check" /> Building Damage Assessment</h3>
                            <template v-if="activeDamage">
                                <p><strong>Building Status</strong></p>
                                <span class="submission-badge" :data-status="activeDamage.assessment_status">
                                    {{ activeDamage.building_status || '—' }}
                                </span>
                                <p><strong>Estimated Cost Assessment</strong></p>
                                <p>
                                    <template v-if="activeDamage.cost_status === 'pending_inputs'">Pending Required Assessment Data</template>
                                    <template v-else>{{ formatMoney(activeDamage.estimated_cost_php) || '—' }}</template>
                                </p>
                                <p class="submission-note">Status · {{ activeDamage.assessment_status }}</p>
                                <p v-if="activeDamage.updated_at" class="submission-note">Updated {{ formatWhen(activeDamage.updated_at) }}</p>
                            </template>
                            <p v-else class="submission-note">No assessment recorded yet.</p>
                            <button v-if="canEdit && sourceReportId" type="button" class="submission-quiet" @click="openDamageWorkspace">
                                {{ activeDamage?.assessment_status === 'draft' ? 'Edit Assessment' : 'Open Damage Assessment' }}
                            </button>
                        </section>
                        <section class="bdrrm-review-section">
                            <h3><MapIcon name="check" /> Signatures</h3>
                            <p class="submission-note">{{ signedCount }} completed</p>
                            <ul v-if="visibleSignatures.length" class="submission-timeline">
                                <li v-for="slot in visibleSignatures" :key="`${slot.role}-${slot.title}`">
                                    <span class="submission-dot" aria-hidden="true"></span>
                                    <strong>{{ slot.label || slot.title }}</strong>
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
                            <h3><MapIcon name="clipboard" /> Checklist before signing</h3>
                            <ul v-if="checklist.length" class="submission-timeline">
                                <li v-for="item in checklist" :key="item.key">
                                    <span class="submission-dot" aria-hidden="true"></span>
                                    <strong>{{ item.label }}</strong>
                                    <span>{{ item.met ? 'Met' : 'Pending' }}</span>
                                    <p v-if="item.detail" class="submission-note">{{ item.detail }}</p>
                                </li>
                            </ul>
                            <p v-else class="submission-note">No checklist items available.</p>
                        </section>
                        <section class="bdrrm-review-section">
                            <h3><MapIcon name="flag" /> Flags</h3>
                            <p>{{ openFlags }} open {{ openFlags === 1 ? 'flag' : 'flags' }}</p>
                            <ul v-if="openFlags > 0" class="submission-timeline">
                                <li v-for="flag in (assessment.flags || []).filter((item) => item.status === 'open')" :key="flag.id">
                                    <span class="submission-dot" aria-hidden="true"></span>
                                    <strong>{{ flag.category }}</strong>
                                    <p>{{ flag.reason }}</p>
                                    <button type="button" class="submission-quiet" :disabled="busy" @click="openResolve(flag)">Resolve</button>
                                </li>
                            </ul>
                            <p v-else class="submission-note">No open flags</p>
                        </section>
                        <section id="fa-review-history" class="bdrrm-review-section">
                            <h3><MapIcon name="history" /> History</h3>
                            <ol v-if="historyVisible.length" class="submission-timeline">
                                <li v-for="(event, index) in historyVisible" :key="`${event.at}-${index}`">
                                    <span class="submission-dot" aria-hidden="true"></span>
                                    <strong>{{ event.title }}</strong>
                                    <span>{{ formatWhen(event.at) }}</span>
                                    <p v-if="event.detail" class="submission-note">{{ event.detail }}</p>
                                </li>
                            </ol>
                            <p v-else class="submission-note">No history recorded yet.</p>
                            <button
                                v-if="hiddenHistory > 0"
                                type="button"
                                class="submission-quiet"
                                @click="historyOpen = !historyOpen"
                            >
                                {{ historyOpen ? 'Show fewer events' : `Show ${hiddenHistory} more` }}
                            </button>
                        </section>
                    </div>
                </details>
            </div>
        </template>

        <dialog v-if="editOpen" class="bdrrm-dialog" open @close="editOpen = false">
            <form @submit.prevent="saveDraft">
                <h3>Edit Assessment</h3>
                <p class="submission-note">{{ assessment.reference }}</p>
                <p v-if="actionError" class="submission-alert" role="alert">{{ actionError }}</p>
                <label>
                    <span>Assessor findings</span>
                    <textarea v-model="editForm.findings" rows="5" maxlength="8000" />
                </label>
                <fieldset>
                    <legend>Location verification</legend>
                    <label class="mapr-check">
                        <input v-model="editForm.location_verified" type="checkbox">
                        Location checked
                    </label>
                    <label class="mapr-check">
                        <input v-model="editForm.household_link_verified" type="checkbox">
                        Household link checked
                    </label>
                    <label>
                        <span>Verification remarks</span>
                        <textarea v-model="editForm.verification_notes" rows="3" maxlength="5000" />
                    </label>
                    <label>
                        <span>Building ID</span>
                        <input v-model="editForm.building_id" type="number" min="1">
                    </label>
                    <label>
                        <span>Verified damage class ID</span>
                        <input v-model="editForm.damage_class_id" type="number" min="1">
                    </label>
                    <p class="submission-note">Use Building Damage Assessment for building status and estimated cost. Provide building and class IDs only when saving verification here.</p>
                </fieldset>
                <div class="bdrrm-dialog-actions">
                    <button type="button" class="submission-quiet" @click="openDamageWorkspace">Building Damage Assessment</button>
                    <button type="submit" class="submission-primary" :disabled="savingDraft" :aria-busy="savingDraft">
                        {{ savingDraft ? 'Saving…' : 'Save Draft' }}
                    </button>
                    <button type="button" class="submission-quiet" @click="editOpen = false">Close</button>
                </div>
            </form>
        </dialog>

        <dialog ref="flagDialog" class="bdrrm-dialog">
            <form @submit.prevent="submitFlag">
                <h3>Flag assessment</h3>
                <p v-if="actionError" class="submission-alert" role="alert">{{ actionError }}</p>
                <label>
                    <span>Category</span>
                    <select v-model="flagCategory">
                        <option>Photographs</option>
                        <option>Location</option>
                        <option>Findings</option>
                        <option>Signatures</option>
                        <option>Other</option>
                    </select>
                </label>
                <label>
                    <span>Section</span>
                    <input v-model="flagSection" type="text" maxlength="120">
                </label>
                <label>
                    <span>Reason</span>
                    <textarea v-model="flagReason" rows="4" required minlength="10" />
                </label>
                <div class="bdrrm-dialog-actions">
                    <button type="submit" class="submission-primary" :disabled="busy">Save flag</button>
                    <button type="button" class="submission-quiet" @click="flagDialog?.close()">Cancel</button>
                </div>
            </form>
        </dialog>

        <dialog ref="returnDialog" class="bdrrm-dialog" @close="actionError = ''">
            <form @submit.prevent="returnAssessment">
                <h3>Return for correction</h3>
                <p v-if="actionError" class="submission-alert" role="alert">{{ actionError }}</p>
                <label>
                    <span>Reason</span>
                    <textarea v-model="returnReason" rows="4" required minlength="10" />
                </label>
                <div class="bdrrm-dialog-actions">
                    <button type="submit" class="submission-primary" :disabled="busy || !canReturn">Return assessment</button>
                    <button type="button" class="submission-quiet" @click="returnDialog?.close()">Cancel</button>
                </div>
            </form>
        </dialog>

        <dialog ref="resolveDialog" class="bdrrm-dialog">
            <form @submit.prevent="resolveFlag">
                <h3>Resolve flag</h3>
                <p v-if="actionError" class="submission-alert" role="alert">{{ actionError }}</p>
                <label>
                    <span>Resolution</span>
                    <textarea v-model="resolution" rows="4" required minlength="5" />
                </label>
                <div class="bdrrm-dialog-actions">
                    <button type="submit" class="submission-primary" :disabled="busy">Resolve</button>
                    <button type="button" class="submission-quiet" @click="resolveDialog?.close()">Cancel</button>
                </div>
            </form>
        </dialog>

        <DamageAssessmentWorkspace
            v-if="sourceReportId"
            :report-id="sourceReportId"
            :open="assessmentOpen"
            @close="assessmentOpen = false"
            @saved="onAssessmentSaved"
        />

        <ReviewSignDialog
            :open="signOpen"
            capture-signature
            :reference="assessment?.reference || ''"
            :name="profile?.name || ''"
            :position="profile?.job_title || profile?.role || ''"
            :capacity="signMode === 'forward' ? 'approved_by' : (signMode === 'verify-officer' ? 'verified_by' : 'assessed_by')"
            @close="signOpen = false"
            @confirm="confirmSign"
        />
    </section>
</template>
