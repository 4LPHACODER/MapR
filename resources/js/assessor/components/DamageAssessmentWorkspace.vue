<script setup>
import { computed, inject, reactive, ref, watch } from 'vue';
import MapIcon from '../../shared/components/MapIcon.vue';
import ResolveBuildingLinkDialog from './ResolveBuildingLinkDialog.vue';

const props = defineProps({
    reportId: { type: [String, Number], required: true },
    open: { type: Boolean, default: false },
});

const emit = defineEmits(['close', 'saved']);

const apiBase = inject('apiBase');
const loading = ref(false);
const classifying = ref(false);
const calculating = ref(false);
const busy = ref(false);
const error = ref('');
const context = ref(null);
const calcResult = ref(null);
const historyOpen = ref(false);
const history = ref([]);
const editingClass = ref(false);
const previewPhoto = ref(null);
const resolveOpen = ref(false);
const costAdjusted = ref(false);
const costNeedsReview = ref(false);
const autoClassifyAttempted = ref(false);
let calcTimer = null;

const form = reactive({
    id: null,
    building_id: '',
    ai_prediction_id: null,
    proposed_damage_class_id: '',
    confirmed_damage_class_id: '',
    classification_source: 'manual',
    correction_reason: '',
    occupancy_key: '',
    construction_type: '',
    construction_grade: '',
    total_floor_area_m2: '',
    assessed_floor_area_m2: '',
    estimated_cost_php: '',
    cost_adjustment_reason: '',
    rate_source_note: '',
    remarks: '',
});

const selectedBuilding = computed(() => (context.value?.buildings || []).find((row) => String(row.id) === String(form.building_id)));
const reportPhotos = computed(() => context.value?.photos || []);
const buildingPredictions = computed(() => {
    const all = context.value?.predictions || [];
    if (!form.building_id) return all;
    return all.filter((row) => !row.building_id || String(row.building_id) === String(form.building_id));
});
const latestPrediction = computed(() => buildingPredictions.value.find((row) => row.prediction_status === 'succeeded') || buildingPredictions.value[0] || null);
const selectedClass = computed(() => (context.value?.damage_classes || []).find((row) => String(row.id) === String(form.confirmed_damage_class_id)));
const proposedClass = computed(() => (context.value?.damage_classes || []).find((row) => String(row.id) === String(form.proposed_damage_class_id)));
const buildingStatus = computed(() => selectedClass.value?.display_label || proposedClass.value?.display_label || 'Awaiting Classification');
const sourceLabel = computed(() => {
    if (!form.confirmed_damage_class_id && !form.proposed_damage_class_id) return 'Awaiting Classification';
    if (form.classification_source === 'ai') return 'AI-Generated';
    if (form.classification_source === 'ai_corrected') return 'Manually edited (AI original preserved)';
    return 'Manually edited';
});
const classTone = computed(() => {
    const code = selectedClass.value?.class_code || proposedClass.value?.class_code || '';
    if (code === 'totally_damaged') return 'totally';
    if (code === 'partially_damaged') return 'partial';
    if (code === 'not_affected') return 'none';
    return 'neutral';
});
const linkagePending = computed(() => !(context.value?.buildings || []).length);
const canSave = computed(() => Boolean(form.building_id && form.confirmed_damage_class_id));
const canConfirm = computed(() => {
    if (!canSave.value) return false;
    if (form.classification_source === 'ai_corrected' && form.correction_reason.trim() === '') return false;
    const status = calcResult.value?.cost_status;
    const hasFinal = form.estimated_cost_php !== '' && form.estimated_cost_php !== null;
    if (costAdjusted.value) {
        return hasFinal && form.cost_adjustment_reason.trim() !== '';
    }
    return Boolean(calcResult.value && ['calculated', 'zero_confirmed'].includes(status));
});

function formatMoney(value) {
    if (value === null || value === undefined || Number.isNaN(Number(value))) return null;
    return `₱${Number(value).toLocaleString('en-PH', { minimumFractionDigits: 2, maximumFractionDigits: 2 })}`;
}

function formatWhen(value) {
    if (!value) return '—';
    const date = new Date(String(value).includes('T') ? value : `${String(value).replace(' ', 'T')}Z`);
    if (Number.isNaN(date.getTime())) return value;
    return new Intl.DateTimeFormat('en-PH', {
        timeZone: 'Asia/Manila',
        day: 'numeric',
        month: 'short',
        year: 'numeric',
        hour: 'numeric',
        minute: '2-digit',
    }).format(date);
}

function resetForm() {
    Object.assign(form, {
        id: null,
        building_id: '',
        ai_prediction_id: null,
        proposed_damage_class_id: '',
        confirmed_damage_class_id: '',
        classification_source: 'manual',
        correction_reason: '',
        occupancy_key: '',
        construction_type: '',
        construction_grade: '',
        total_floor_area_m2: '',
        assessed_floor_area_m2: '',
        estimated_cost_php: '',
        cost_adjustment_reason: '',
        rate_source_note: '',
        remarks: '',
    });
    calcResult.value = null;
    editingClass.value = false;
    previewPhoto.value = null;
    costAdjusted.value = false;
    costNeedsReview.value = false;
    autoClassifyAttempted.value = false;
    historyOpen.value = false;
    history.value = [];
    resolveOpen.value = false;
}

function applyAssessment(row) {
    if (!row) return;
    form.id = row.id;
    form.building_id = String(row.building_id);
    form.ai_prediction_id = row.ai_prediction_id;
    form.proposed_damage_class_id = row.proposed_damage_class_id ? String(row.proposed_damage_class_id) : '';
    form.confirmed_damage_class_id = row.confirmed_damage_class_id ? String(row.confirmed_damage_class_id) : '';
    form.classification_source = row.classification_source || 'manual';
    form.correction_reason = row.correction_reason || '';
    form.occupancy_key = row.occupancy_key || '';
    form.construction_type = row.construction_type || '';
    form.construction_grade = row.construction_grade || '';
    form.total_floor_area_m2 = row.total_floor_area_m2 ?? '';
    form.assessed_floor_area_m2 = row.assessed_floor_area_m2 ?? '';
    form.estimated_cost_php = row.estimated_cost_php ?? '';
    form.cost_adjustment_reason = row.cost_adjustment_reason || '';
    form.rate_source_note = row.rate_source_note || '';
    form.remarks = row.remarks || '';
    costAdjusted.value = Boolean(row.cost_is_adjusted);
    costNeedsReview.value = false;
    calcResult.value = {
        estimated_cost_php: row.calculated_estimated_cost_php ?? row.estimated_cost_php,
        cost_status: row.cost_status,
        unit_cost_php: row.unit_cost_php,
        rate_source: row.rate_source,
        criteria_version: row.criteria_version,
        missing: row.cost_status === 'pending_inputs' ? ['review_inputs'] : [],
        breakdown: row.breakdown || [],
        message: row.cost_status === 'pending_inputs'
            ? 'Estimated Cost Assessment: Pending Required Data'
            : `Calculated Estimated Cost: ${formatMoney(row.calculated_estimated_cost_php ?? row.estimated_cost_php)}`,
    };
}

async function loadContext({ skipAutoClassify = false } = {}) {
    loading.value = true;
    error.value = '';
    try {
        const { data } = await window.axios.get(`${apiBase}/barangay-reports/${props.reportId}/damage-assessment`);
        context.value = data.data;
        const buildings = context.value.buildings || [];
        if (!form.building_id && buildings.length) {
            form.building_id = String(buildings[0].id);
        }
        const current = (context.value.current_assessments || []).find((row) => String(row.building_id) === String(form.building_id));
        if (current) applyAssessment(current);
        else if (latestPrediction.value?.predicted_damage_class_id) {
            form.proposed_damage_class_id = String(latestPrediction.value.predicted_damage_class_id);
            form.confirmed_damage_class_id = String(latestPrediction.value.predicted_damage_class_id);
            form.ai_prediction_id = latestPrediction.value.id;
            form.classification_source = 'ai';
        }
        if (!skipAutoClassify) {
            await maybeAutoClassify();
        }
    } catch (exception) {
        error.value = exception.response?.data?.message
            || (exception.response?.status === 403 ? 'This report is outside your assignment.' : 'The assessment workspace could not be loaded.');
    } finally {
        loading.value = false;
    }
}

async function maybeAutoClassify() {
    if (autoClassifyAttempted.value || !form.building_id || classifying.value) return;
    if (!(reportPhotos.value || []).length) return;
    if (latestPrediction.value?.prediction_status === 'succeeded' && latestPrediction.value?.predicted_damage_class_id) {
        if (!form.confirmed_damage_class_id) {
            form.proposed_damage_class_id = String(latestPrediction.value.predicted_damage_class_id);
            form.confirmed_damage_class_id = String(latestPrediction.value.predicted_damage_class_id);
            form.ai_prediction_id = latestPrediction.value.id;
            form.classification_source = 'ai';
        }
        return;
    }
    autoClassifyAttempted.value = true;
    await runClassify({ silent: true });
}

async function loadHistory() {
    historyOpen.value = true;
    try {
        const { data } = await window.axios.get(`${apiBase}/barangay-reports/${props.reportId}/damage-assessment/history`);
        history.value = data.data || [];
    } catch {
        history.value = [];
    }
}

async function runClassify({ silent = false } = {}) {
    if (!form.building_id || classifying.value) return;
    classifying.value = true;
    if (!silent) error.value = '';
    try {
        const { data } = await window.axios.post(`${apiBase}/barangay-reports/${props.reportId}/damage-assessment/classify`, {
            building_id: Number(form.building_id),
        });
        await loadContext({ skipAutoClassify: true });
        const succeeded = (data.data || []).find((row) => row.prediction_status === 'succeeded');
        if (succeeded?.predicted_damage_class_id) {
            form.proposed_damage_class_id = String(succeeded.predicted_damage_class_id);
            if (!form.confirmed_damage_class_id || form.classification_source === 'ai') {
                form.confirmed_damage_class_id = String(succeeded.predicted_damage_class_id);
                form.classification_source = 'ai';
            }
            form.ai_prediction_id = succeeded.id;
            editingClass.value = false;
        } else if (!silent) {
            error.value = (data.data || [])[0]?.failure_reason
                || data.message
                || 'Classification could not be completed. You can edit Building Status manually.';
        }
    } catch (exception) {
        if (!silent) {
            error.value = exception.response?.data?.message
                || 'Classification service is unavailable. You can edit Building Status manually.';
        }
        await loadContext({ skipAutoClassify: true });
    } finally {
        classifying.value = false;
    }
}

async function recalculate({ fromCriteria = false } = {}) {
    calculating.value = true;
    if (!fromCriteria) error.value = '';
    try {
        const { data } = await window.axios.post(`${apiBase}/barangay-reports/${props.reportId}/damage-assessment/calculate`, {
            occupancy_key: form.occupancy_key || null,
            construction_type: form.construction_type || null,
            construction_grade: form.construction_grade || null,
            total_floor_area_m2: form.total_floor_area_m2 === '' ? null : form.total_floor_area_m2,
            assessed_floor_area_m2: form.assessed_floor_area_m2 === '' ? null : form.assessed_floor_area_m2,
            damage_class_code: selectedClass.value?.class_code || null,
            rate_source_note: form.rate_source_note || null,
        });
        calcResult.value = data.data;
        const calculated = data.data?.estimated_cost_php;
        if (!costAdjusted.value) {
            form.estimated_cost_php = calculated ?? '';
            costNeedsReview.value = false;
        } else if (calculated !== null && calculated !== undefined) {
            costNeedsReview.value = true;
        }
    } catch (exception) {
        if (!fromCriteria) {
            error.value = exception.response?.data?.message || 'The cost could not be calculated.';
        }
    } finally {
        calculating.value = false;
    }
}

function scheduleRecalculate() {
    clearTimeout(calcTimer);
    calcTimer = setTimeout(() => recalculate({ fromCriteria: true }), 350);
}

function onClassChange() {
    const proposed = form.proposed_damage_class_id;
    const confirmed = form.confirmed_damage_class_id;
    if (proposed && confirmed && proposed !== confirmed) {
        form.classification_source = form.ai_prediction_id ? 'ai_corrected' : 'manual';
    } else if (proposed && confirmed && proposed === confirmed && form.ai_prediction_id) {
        form.classification_source = 'ai';
    } else {
        form.classification_source = form.ai_prediction_id ? 'ai_corrected' : 'manual';
    }
    if (selectedClass.value?.class_code === 'not_affected') {
        form.assessed_floor_area_m2 = 0;
    } else if (selectedClass.value?.class_code === 'totally_damaged' && form.total_floor_area_m2 !== '') {
        form.assessed_floor_area_m2 = form.total_floor_area_m2;
    }
    scheduleRecalculate();
}

function onFinalCostInput() {
    const calculated = calcResult.value?.estimated_cost_php;
    if (calculated === null || calculated === undefined || form.estimated_cost_php === '') {
        costAdjusted.value = form.estimated_cost_php !== '' && (calculated === null || calculated === undefined);
        return;
    }
    costAdjusted.value = Math.abs(Number(form.estimated_cost_php) - Number(calculated)) > 0.009;
    if (!costAdjusted.value) {
        form.cost_adjustment_reason = '';
        costNeedsReview.value = false;
    }
}

function useCalculatedAmount() {
    form.estimated_cost_php = calcResult.value?.estimated_cost_php ?? '';
    costAdjusted.value = false;
    form.cost_adjustment_reason = '';
    costNeedsReview.value = false;
}

async function saveAssessment() {
    if (busy.value || !canSave.value) return;
    if (form.classification_source === 'ai_corrected' && form.correction_reason.trim() === '') {
        error.value = 'Provide a reason when editing an AI classification.';
        editingClass.value = true;
        return;
    }
    if (costAdjusted.value && form.cost_adjustment_reason.trim() === '') {
        error.value = 'Provide a reason when adjusting the estimated cost assessment.';
        return;
    }
    busy.value = true;
    error.value = '';
    try {
        await recalculate({ fromCriteria: true });
        const draft = await window.axios.post(`${apiBase}/barangay-reports/${props.reportId}/damage-assessment`, payload());
        applyAssessment(draft.data.data);
        if (canConfirm.value) {
            const { data } = await window.axios.post(`${apiBase}/barangay-reports/${props.reportId}/damage-assessment/${draft.data.data.id}/confirm`);
            applyAssessment(data.data);
            emit('saved', data.data);
            emit('close');
            return;
        }
        emit('saved', draft.data.data);
    } catch (exception) {
        error.value = exception.response?.data?.message
            || 'The assessment could not be saved.';
    } finally {
        busy.value = false;
    }
}

function payload() {
    return {
        id: form.id,
        building_id: Number(form.building_id),
        ai_prediction_id: form.ai_prediction_id,
        proposed_damage_class_id: form.proposed_damage_class_id ? Number(form.proposed_damage_class_id) : null,
        confirmed_damage_class_id: form.confirmed_damage_class_id ? Number(form.confirmed_damage_class_id) : null,
        classification_source: form.classification_source,
        correction_reason: form.correction_reason || null,
        occupancy_key: form.occupancy_key || null,
        construction_type: form.construction_type || null,
        construction_grade: form.construction_grade || null,
        total_floor_area_m2: form.total_floor_area_m2 === '' ? null : form.total_floor_area_m2,
        assessed_floor_area_m2: form.assessed_floor_area_m2 === '' ? null : form.assessed_floor_area_m2,
        estimated_cost_php: form.estimated_cost_php === '' ? null : form.estimated_cost_php,
        cost_is_adjusted: costAdjusted.value,
        cost_adjustment_reason: costAdjusted.value ? form.cost_adjustment_reason : null,
        rate_source_note: form.rate_source_note || null,
        damage_class_code: selectedClass.value?.class_code || null,
        remarks: form.remarks || null,
    };
}

async function onBuildingLinked() {
    resolveOpen.value = false;
    autoClassifyAttempted.value = false;
    await loadContext();
}

watch(() => props.open, async (value) => {
    if (!value) return;
    resetForm();
    await loadContext();
});

watch(() => form.building_id, async (value, previous) => {
    if (!props.open || !value || value === previous || !context.value) return;
    const current = (context.value.current_assessments || []).find((row) => String(row.building_id) === String(value));
    if (current) applyAssessment(current);
});

watch(
    () => [form.occupancy_key, form.construction_type, form.construction_grade, form.total_floor_area_m2, form.assessed_floor_area_m2],
    () => {
        if (!props.open || loading.value) return;
        scheduleRecalculate();
    },
);
</script>

<template>
    <dialog class="damage-assessment-dialog" :open="open" @cancel.prevent="emit('close')">
        <div class="damage-assessment-shell">
            <header class="damage-assessment-header">
                <div>
                    <h2>Building Damage Assessment</h2>
                    <p v-if="context" class="submission-note">
                        <strong>{{ context.report.reference }}</strong>
                        · {{ context.report.barangay?.name || '—' }}
                        · {{ context.report.incident?.name || '—' }}
                    </p>
                </div>
                <button type="button" class="submission-quiet" @click="emit('close')"><MapIcon name="x" /> Close</button>
            </header>

            <p v-if="loading" class="submission-status">Loading assessment workspace…</p>
            <p v-if="error" class="submission-alert" role="alert">{{ error }}</p>

            <template v-if="!loading && context">
                <section class="damage-assessment-section">
                    <h3><MapIcon name="building" /> Building information</h3>
                    <dl class="damage-info-grid">
                        <div><dt>Report</dt><dd>{{ context.report.reference }}</dd></div>
                        <div><dt>Barangay</dt><dd>{{ context.report.barangay?.name || '—' }}</dd></div>
                        <div><dt>Incident</dt><dd>{{ context.report.incident?.name || '—' }}</dd></div>
                        <div><dt>Resident</dt><dd>{{ context.linkage?.resident_name || '—' }}</dd></div>
                        <div><dt>Household</dt><dd>{{ context.linkage?.household_reference || '—' }}</dd></div>
                        <div><dt>Building</dt><dd>{{ selectedBuilding?.building_reference || context.linkage?.building_reference || '—' }}</dd></div>
                    </dl>
                    <template v-if="linkagePending">
                        <p class="submission-alert">Building record requires verification.</p>
                        <button type="button" class="submission-quiet" @click="resolveOpen = true">
                            Resolve building link
                        </button>
                    </template>
                    <label v-else-if="(context.buildings || []).length > 1">
                        <span>Assessed building</span>
                        <select v-model="form.building_id">
                            <option v-for="building in context.buildings" :key="building.id" :value="String(building.id)">
                                {{ building.building_reference || `Building #${building.id}` }}
                            </option>
                        </select>
                    </label>
                </section>

                <section class="damage-assessment-section">
                    <h3><MapIcon name="camera" /> Building Damage Photographs</h3>
                    <div v-if="reportPhotos.length" class="damage-photo-gallery">
                        <figure v-for="photo in reportPhotos" :key="photo.id">
                            <button type="button" class="damage-photo-thumb" @click="previewPhoto = photo">
                                <img :src="photo.url" :alt="photo.original_filename || `Photo ${photo.number}`">
                            </button>
                            <figcaption>
                                <strong>Photo {{ photo.number }}</strong>
                                <span>{{ photo.original_filename || `Photo #${photo.id}` }}</span>
                            </figcaption>
                        </figure>
                    </div>
                    <p v-else class="submission-note">No photographs are attached to this report.</p>
                </section>

                <section class="damage-assessment-section">
                    <h3><MapIcon name="clipboard-check" /> AI Damage Classification</h3>
                    <div class="damage-class-card damage-class-card-row" :data-tone="classTone">
                        <div>
                            <p class="submission-note">Building Status</p>
                            <strong>{{ classifying ? 'Classifying…' : buildingStatus }}</strong>
                            <p class="submission-note">
                                Source: {{ latestPrediction?.architecture || 'EfficientNetB0' }}
                                · {{ sourceLabel }}
                            </p>
                        </div>
                        <button type="button" class="submission-quiet" :disabled="!(context.damage_classes || []).length" @click="editingClass = !editingClass">
                            <MapIcon name="pencil" /> Edit Classification
                        </button>
                    </div>
                    <div v-if="editingClass" class="damage-edit-class">
                        <label>
                            <span>Building Status</span>
                            <select v-model="form.confirmed_damage_class_id" @change="onClassChange">
                                <option value="">Select class</option>
                                <option v-for="row in context.damage_classes" :key="row.id" :value="String(row.id)">{{ row.display_label }}</option>
                            </select>
                        </label>
                        <label v-if="form.classification_source === 'ai_corrected' || (form.ai_prediction_id && form.proposed_damage_class_id !== form.confirmed_damage_class_id)">
                            <span>Reason for change</span>
                            <textarea v-model="form.correction_reason" rows="3" maxlength="5000" required />
                        </label>
                    </div>
                </section>

                <section class="damage-assessment-section">
                    <h3><MapIcon name="clipboard" /> Damage assessment criteria</h3>
                    <div class="damage-form-grid">
                        <label>
                            <span>Occupancy / use</span>
                            <select v-model="form.occupancy_key">
                                <option value="">Select occupancy</option>
                                <option v-for="row in context.schedule.occupancies" :key="row.key" :value="row.key">{{ row.label }}</option>
                            </select>
                        </label>
                        <label>
                            <span>Construction type</span>
                            <select v-model="form.construction_type">
                                <option value="">Select type</option>
                                <option v-for="row in context.schedule.construction_types" :key="row.key" :value="row.key">{{ row.label }}</option>
                            </select>
                        </label>
                        <label>
                            <span>Valuation grade</span>
                            <select v-model="form.construction_grade">
                                <option value="">Select grade</option>
                                <option v-for="grade in context.schedule.grades" :key="grade" :value="grade">{{ grade }}</option>
                            </select>
                        </label>
                        <label>
                            <span>Total floor area (m²)</span>
                            <input v-model="form.total_floor_area_m2" type="number" min="0" step="0.01">
                        </label>
                        <label>
                            <span>Assessed damaged floor area (m²)</span>
                            <input v-model="form.assessed_floor_area_m2" type="number" min="0" step="0.01">
                        </label>
                    </div>
                    <div class="damage-cost-inline">
                        <label>
                            <span>Estimated Cost Assessment</span>
                            <div class="damage-cost-input">
                                <span>₱</span>
                                <input
                                    v-model="form.estimated_cost_php"
                                    type="number"
                                    min="0"
                                    step="0.01"
                                    :placeholder="calcResult?.cost_status === 'pending_inputs' ? 'Pending Required Data' : '0.00'"
                                    @input="onFinalCostInput"
                                >
                            </div>
                        </label>
                        <p v-if="calculating" class="submission-note">Updating calculated estimate…</p>
                        <p v-else-if="calcResult?.cost_status === 'pending_inputs'" class="submission-note">{{ calcResult.message }}</p>
                        <p v-else-if="calcResult?.estimated_cost_php != null" class="submission-note">
                            Calculated: {{ formatMoney(calcResult.estimated_cost_php) }}
                            <button v-if="costAdjusted || costNeedsReview" type="button" class="submission-quiet" @click="useCalculatedAmount">Use Calculated Amount</button>
                        </p>
                        <p v-if="costNeedsReview" class="submission-alert">Criteria changed. Review the adjusted amount or use the calculated estimate.</p>
                        <label v-if="costAdjusted">
                            <span>Adjustment reason</span>
                            <textarea v-model="form.cost_adjustment_reason" rows="2" maxlength="5000" required />
                        </label>
                    </div>
                    <label>
                        <span>Assessor remarks</span>
                        <textarea v-model="form.remarks" rows="3" maxlength="5000" />
                    </label>
                </section>

                <section class="damage-assessment-section">
                    <div class="bdrrm-dialog-actions">
                        <button type="button" class="submission-primary" :disabled="busy || !canSave" @click="saveAssessment">
                            <MapIcon name="save" /> {{ busy ? 'Saving…' : 'Save Assessment' }}
                        </button>
                        <button type="button" class="submission-quiet" @click="loadHistory"><MapIcon name="history" /> View History</button>
                        <button type="button" class="submission-quiet" @click="emit('close')">Close</button>
                    </div>
                    <p v-if="linkagePending" class="submission-note">Resolve the building link before saving a building-specific assessment.</p>
                    <div v-if="historyOpen" class="damage-history">
                        <h4>Assessment history</h4>
                        <ul>
                            <li v-for="row in history" :key="row.id">
                                <strong>{{ row.building_reference || row.building_id }}</strong>
                                · {{ row.assessment_status }}
                                · {{ row.confirmed_label || row.proposed_label || '—' }}
                                · {{ row.cost_status === 'pending_inputs' && !row.cost_is_adjusted ? 'Pending cost' : formatMoney(row.estimated_cost_php) }}
                                · {{ formatWhen(row.updated_at) }}
                            </li>
                        </ul>
                    </div>
                </section>
            </template>
        </div>

        <div v-if="previewPhoto" class="damage-photo-lightbox" @click.self="previewPhoto = null">
            <button type="button" class="submission-quiet damage-photo-lightbox-close" @click="previewPhoto = null">
                <MapIcon name="x" /> Close
            </button>
            <img :src="previewPhoto.url" :alt="previewPhoto.original_filename || `Photo ${previewPhoto.number}`">
            <p>{{ previewPhoto.original_filename || `Photo ${previewPhoto.number}` }}</p>
        </div>

        <ResolveBuildingLinkDialog
            :report-id="reportId"
            :open="resolveOpen"
            @close="resolveOpen = false"
            @linked="onBuildingLinked"
        />
    </dialog>
</template>
