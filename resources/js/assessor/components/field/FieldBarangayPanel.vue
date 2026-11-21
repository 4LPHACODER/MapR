<script setup>
import Button from 'primevue/button';
import MapIcon from '../../../shared/components/MapIcon.vue';
import FieldAssessmentMap from './FieldAssessmentMap.vue';

const props = defineProps({
    card: { type: Object, required: true },
    mapPayload: { type: Object, required: true },
    canExport: { type: Boolean, default: false },
    canSubmit: { type: Boolean, default: false },
    exporting: { type: Boolean, default: false },
    formatWhen: { type: Function, required: true },
});

const emit = defineEmits(['view-reports', 'download-excel', 'view-map', 'submit']);

function statusLabel(card) {
    return card.consolidation_status_label
        || ({
            empty: 'No Reviewed Reports',
            ready: 'Ready for Consolidation',
            submitted: 'Submitted',
        }[card.consolidation_status] || 'No Reviewed Reports');
}

function submitDisabledReason(card) {
    if (!props.canSubmit) return 'Only authorized city assessors can submit packages.';
    if (!card.barangay_id) return 'Barangay is not linked in the registry.';
    if (!card.can_submit) return 'No eligible reviewed reports are ready to submit.';
    return '';
}
</script>

<template>
    <article class="field-barangay-card">
        <header class="field-barangay-card__header">
            <h3>
                <span class="field-barangay-card__pin" aria-hidden="true"><MapIcon name="pin" /></span>
                {{ card.barangay_name }}
            </h3>
            <span
                class="field-badge"
                :data-status="card.consolidation_status"
                :title="statusLabel(card)"
            >{{ statusLabel(card) }}</span>
        </header>

        <div class="field-mini-map">
            <FieldAssessmentMap
                v-if="card.barangay_id && card.has_boundary"
                :interactive="false"
                :show-expand="true"
                :boundaries="mapPayload.boundaries"
                :markers="mapPayload.markers"
                :selected-barangay-id="card.barangay_id"
                @expand="emit('view-map', card.barangay_id)"
            />
            <div v-else class="field-map-unavailable">
                <MapIcon name="map" />
                <strong>{{ card.barangay_id ? 'Geometry unavailable' : 'Barangay not linked' }}</strong>
                <span>
                    {{ card.has_boundary === false
                        ? 'Verified barangay geometry is not available in the registry.'
                        : 'This barangay record is not linked in the registry.' }}
                </span>
            </div>
        </div>

        <template v-if="card.report_count > 0">
            <div class="field-stat-tiles" aria-label="Barangay statistics">
                <div class="field-stat-tile">
                    <span>Reviewed Reports</span>
                    <strong>{{ card.report_count }}</strong>
                </div>
                <div class="field-stat-tile">
                    <span>Assessed Buildings</span>
                    <strong>{{ card.assessed_buildings }}</strong>
                </div>
                <div class="field-stat-tile">
                    <span>Resident Submissions</span>
                    <strong>{{ card.resident_submissions }}</strong>
                </div>
                <div class="field-stat-tile">
                    <span>Damage Summary</span>
                    <strong>{{ card.partially_damaged }} partial · {{ card.totally_damaged }} total</strong>
                </div>
            </div>
            <dl class="field-meta-list">
                <div><dt>Latest report</dt><dd>{{ card.latest_assessment_reference || 'Not Recorded' }}</dd></div>
                <div><dt>Verified by</dt><dd>{{ card.verified_by || 'Not Recorded' }}</dd></div>
                <div><dt>Reviewed by</dt><dd>{{ card.reviewed_by || 'Not Recorded' }}</dd></div>
                <div><dt>Last updated</dt><dd>{{ formatWhen(card.last_updated_at) }}</dd></div>
            </dl>
        </template>
        <div v-else class="field-empty field-empty--compact">
            <MapIcon name="clipboard-check" />
            <strong>No Reviewed Reports Yet</strong>
            <span>Reports will appear after verification and authorized review.</span>
        </div>

        <footer class="field-card-actions field-card-actions--footer">
            <div class="field-card-actions__left">
                <Button
                    type="button"
                    size="small"
                    severity="secondary"
                    outlined
                    label="View Reports"
                    :disabled="!card.barangay_id"
                    @click="emit('view-reports', card.barangay_id)"
                >
                    <template #icon><MapIcon name="eye" /></template>
                </Button>
                <Button
                    type="button"
                    size="small"
                    severity="secondary"
                    outlined
                    label="Download Excel"
                    :disabled="!canExport || !card.barangay_id"
                    :loading="exporting"
                    @click="emit('download-excel', card.barangay_id)"
                >
                    <template #icon><MapIcon name="file-spreadsheet" /></template>
                </Button>
                <Button
                    type="button"
                    size="small"
                    severity="secondary"
                    outlined
                    label="View Map"
                    :disabled="!card.barangay_id"
                    @click="emit('view-map', card.barangay_id)"
                >
                    <template #icon><MapIcon name="map" /></template>
                </Button>
            </div>
            <div class="field-card-actions__right">
                <Button
                    type="button"
                    size="small"
                    label="Submit Report"
                    class="field-submit-btn"
                    :disabled="!canSubmit || !card.can_submit"
                    :title="submitDisabledReason(card) || 'Submit this barangay package to CDRRMO'"
                    :aria-label="submitDisabledReason(card) || 'Submit Report'"
                    @click="emit('submit', card.barangay_id)"
                >
                    <template #icon><MapIcon name="send" /></template>
                </Button>
            </div>
        </footer>
    </article>
</template>
