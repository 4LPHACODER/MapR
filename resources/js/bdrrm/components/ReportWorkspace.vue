<script setup>
import { computed, inject, ref, watch } from 'vue';
import { RouterLink } from 'vue-router';
import Button from 'primevue/button';
import Dialog from 'primevue/dialog';
import InputText from 'primevue/inputtext';
import Menu from 'primevue/menu';
import Tag from 'primevue/tag';
import Textarea from 'primevue/textarea';
import MapIcon from '../../shared/components/MapIcon.vue';
import BarangayReportPreview from './BarangayReportPreview.vue';

const props = defineProps({
    record: { type: Object, required: true },
    form: { type: Object, required: true },
    apiBase: { type: String, required: true },
    saving: { type: Boolean, default: false },
    saveState: { type: String, default: 'Saved' },
    detailsOpen: { type: Boolean, default: false },
    backTo: { type: String, default: '/reports/drafts' },
});
const emit = defineEmits(['save', 'sign', 'submit', 'upload', 'flag', 'resolve-flag']);
const roleCode = inject('roleCode', '');
const editable = computed(() => roleCode === 'bdrrm' && props.record.can_edit);
const downloadUrl = computed(() => `/bdrrm/reports/${props.record.id}/preview?format=pdf`);
const documentUrl = computed(() => `${downloadUrl.value}&inline=1`);
const reportPreview = ref(null);
const photoReportId = computed(() => props.record.barangay_report?.id || props.record.id);
const statusSeverity = computed(() => ({ returned: 'warn', draft: 'secondary', finalized: 'success' })[props.record.status] || 'info');
const backLabel = computed(() => {
    if (props.backTo.startsWith('/reports/returned')) return 'Back to returned reports';
    if (props.backTo.startsWith('/reports/submitted')) return 'Back to submitted reports';
    return 'Back to drafts';
});
const flagForm = ref({ category: 'Review', reason: '' });
const resolution = ref('');
const openFlag = computed(() => (props.record.flags || []).find((flag) => flag.status === 'open'));
const checklist = computed(() => props.record.checklist || []);
const completeCount = computed(() => checklist.value.filter(({ met }) => met).length);
const history = ref([]);
const future = ref([]);
const previewPhoto = ref(null);
const photoInput = ref(null);
const documentMenu = ref(null);
const documentMenuOpen = ref(false);
const documentActions = computed(() => [
    { label: 'Print', icon: 'printer', command: () => window.open(documentUrl.value, '_blank', 'noopener') },
    { label: 'Download PDF', icon: 'download', href: downloadUrl.value },
    { label: 'Download original attachment', icon: 'download', visible: Boolean(props.record.attachment),
        href: props.record.attachment ? `/${props.record.attachment.download_path}` : undefined },
    { separator: true, visible: editable.value },
    { label: 'Forward to the next office', icon: 'send', visible: editable.value, disabled: props.saving, command: () => emit('submit') },
]);

function remember() {
    history.value.push(props.form.report_description || '');
    future.value = [];
}

function undo() {
    if (!history.value.length) return;
    future.value.push(props.form.report_description || '');
    props.form.report_description = history.value.pop();
}

function redo() {
    if (!future.value.length) return;
    history.value.push(props.form.report_description || '');
    props.form.report_description = future.value.pop();
}

function photoUrl(photo) {
    return `${props.apiBase}/reports/${photoReportId.value}/photos/${photo.id}`;
}

function formatWhen(value) {
    if (!value) return '';
    const date = new Date(String(value).includes('T') ? value : `${String(value).replace(' ', 'T')}Z`);
    if (Number.isNaN(date.getTime())) return String(value);
    return `${new Intl.DateTimeFormat('en-PH', {
        timeZone: 'Asia/Manila',
        day: 'numeric',
        month: 'short',
        year: 'numeric',
        hour: 'numeric',
        minute: '2-digit',
    }).format(date)} PHT`;
}

watch(() => props.detailsOpen, (open) => {
    if (open) requestAnimationFrame(() => document.getElementById('report-history')?.scrollIntoView({ block: 'nearest' }));
}, { immediate: true });

watch(() => props.record, (record, previous) => {
    if (record.id === previous.id) reportPreview.value?.reload();
});
</script>

<template>
    <div class="draft-workspace grid min-w-0 gap-4 text-[13px] leading-5 text-surface-800">
        <nav class="draft-nav flex flex-wrap items-center justify-between gap-2" aria-label="Report navigation">
            <RouterLink :to="backTo" class="draft-back"><MapIcon name="chevron" /> {{ backLabel }}</RouterLink>
            <a href="#draft-review" class="draft-review-link">Go to review</a>
        </nav>

        <header class="draft-surface draft-overview">
            <div class="draft-heading">
                <div class="min-w-0">
                    <div class="flex flex-wrap items-center gap-x-3 gap-y-1.5">
                        <h2 class="min-w-0 text-2xl leading-tight font-bold wrap-anywhere">{{ record.reference }}</h2>
                        <Tag :value="record.label" :severity="statusSeverity" />
                    </div>
                    <p v-if="record.signing_action === 'endorse'" class="draft-muted mt-1">Endorsing sends this report to RDANA for assessment.</p>
                </div>
                <div class="draft-primary-actions">
                    <span v-if="editable" class="draft-muted" role="status">{{ saveState }}</span>
                    <Button v-if="editable" size="small" label="Save draft" :severity="record.signing_action ? 'secondary' : undefined"
                        :outlined="Boolean(record.signing_action)" :loading="saving" @click="emit('save')">
                        <template #icon><MapIcon name="save" /></template>
                    </Button>
                    <Button v-if="record.signing_action" size="small" :label="record.signing_action === 'endorse' ? 'Endorse & sign' : 'Review & sign'"
                        :disabled="saving" @click="emit('sign')">
                        <template #icon><MapIcon name="pen-line" /></template>
                    </Button>
                </div>
            </div>
            <dl class="draft-facts">
                <div><dt>Incident</dt><dd>{{ record.incident?.name || record.incident_name || 'Not recorded' }}</dd></div>
                <div><dt>Barangay</dt><dd>{{ record.barangay?.name || record.barangay_name || 'Not recorded' }}</dd></div>
                <div><dt>Building</dt><dd>{{ record.building_reference || 'Not linked' }}</dd></div>
                <div v-if="record.updated_at"><dt>Last updated</dt><dd>{{ formatWhen(record.updated_at) }}</dd></div>
            </dl>
        </header>

        <div class="draft-columns">
            <div class="grid min-w-0 gap-4">
                <section class="draft-surface" aria-labelledby="draft-document-title">
                    <header class="draft-section-heading">
                        <h3 id="draft-document-title"><MapIcon name="file-text" /> Report document</h3>
                        <div class="draft-tools">
                            <Button v-if="editable" as="a" href="#draft-details" size="small" severity="secondary" text label="Edit details" />
                            <Button as="a" :href="documentUrl" target="_blank" rel="noopener" size="small" severity="secondary" outlined label="Open PDF">
                                <template #icon><MapIcon name="eye" /></template>
                            </Button>
                            <Button size="small" severity="secondary" outlined aria-label="More document actions" aria-haspopup="menu"
                                aria-controls="draft-document-menu" :aria-expanded="documentMenuOpen" @click="documentMenu?.toggle($event)">
                                <template #icon><MapIcon name="more" /></template>
                            </Button>
                            <Menu id="draft-document-menu" ref="documentMenu" :model="documentActions" popup aria-label="Document actions"
                                @show="documentMenuOpen = true" @hide="documentMenuOpen = false">
                                <template #item="{ item, props: itemProps }">
                                    <a v-bind="itemProps.action" :href="item.href">
                                        <MapIcon :name="item.icon" class="size-4" />
                                        <span>{{ item.label }}</span>
                                    </a>
                                </template>
                            </Menu>
                        </div>
                    </header>
                    <BarangayReportPreview ref="reportPreview" :document-url="documentUrl" />
                </section>

                <section class="draft-surface" aria-labelledby="draft-details">
                    <header class="draft-section-heading">
                        <h3 id="draft-details" tabindex="-1"><MapIcon name="clipboard" /> Report details</h3>
                        <Button :as="RouterLink" to="/map" size="small" severity="secondary" outlined label="View on map">
                            <template #icon><MapIcon name="pin" /></template>
                        </Button>
                    </header>
                    <section v-if="editable || record.predictions?.length" class="draft-review-group">
                        <div v-if="editable" class="draft-field">
                            <div class="draft-section-line">
                                <label for="draft-description">Observations</label>
                                <div class="draft-tools" role="group" aria-label="Description history">
                                    <Button size="small" severity="secondary" text label="Undo" :disabled="saving || !history.length" @click="undo" />
                                    <Button size="small" severity="secondary" text label="Redo" :disabled="saving || !future.length" @click="redo" />
                                </div>
                            </div>
                            <Textarea id="draft-description" v-model="form.report_description" rows="4" fluid :disabled="saving"
                                aria-describedby="draft-preview-note" @focus="remember" />
                            <p id="draft-preview-note" class="draft-muted">Save the draft to update the PDF.</p>
                        </div>
                        <p v-if="record.predictions?.length" class="draft-muted">
                            Preliminary classification · {{ record.predictions[0].prediction_status }}<template v-if="record.predictions[0].failure_reason">. {{ record.predictions[0].failure_reason }}</template>
                        </p>
                    </section>
                    <section class="draft-review-group">
                        <div class="draft-section-line">
                            <h4>Supporting evidence</h4>
                            <Button v-if="editable" size="small" severity="secondary" outlined label="Add photo" :disabled="saving"
                                @click="photoInput?.click()">
                                <template #icon><MapIcon name="camera" /></template>
                            </Button>
                            <input v-if="editable" ref="photoInput" type="file" accept="image/jpeg,image/png,image/webp" hidden
                                @change="emit('upload', $event)">
                        </div>
                        <div v-if="record.photos?.length" class="draft-photos">
                            <figure v-for="photo in record.photos" :key="photo.id">
                                <button type="button" :aria-label="'View ' + photo.original_filename" @click="previewPhoto = photo">
                                    <img :src="photoUrl(photo)" alt="" loading="lazy">
                                </button>
                                <figcaption class="draft-muted">{{ photo.original_filename }}</figcaption>
                            </figure>
                        </div>
                        <p v-else class="draft-muted">No photographs attached.</p>
                    </section>
                </section>
            </div>

            <aside class="draft-surface draft-review" aria-labelledby="draft-review">
                <header class="draft-section-heading">
                    <h3 id="draft-review" tabindex="-1"><MapIcon name="clipboard-check" /> Report review</h3>
                </header>
                <section v-if="record.status === 'returned' || record.return_reason" class="draft-review-group draft-feedback">
                    <h4>Correction feedback</h4>
                    <p class="whitespace-pre-wrap">{{ record.return_reason || 'Correction feedback is not available.' }}</p>
                    <p v-if="record.returned_at" class="draft-muted">{{ formatWhen(record.returned_at) }}</p>
                </section>
                <section v-if="checklist.length" class="draft-review-group">
                    <div class="draft-section-line">
                        <h4>Signing checklist</h4>
                        <Tag :value="`${completeCount}/${checklist.length} complete`" :severity="completeCount === checklist.length ? 'success' : 'warn'" />
                    </div>
                    <ul class="draft-checklist">
                        <li v-for="item in checklist" :key="item.key" :data-complete="item.met">
                            <MapIcon :name="item.met ? 'circle-check' : 'alert-circle'" />
                            <div>
                                <span class="sr-only">{{ item.met ? 'Complete' : 'Needs attention' }}</span>
                                <strong>{{ item.label }}</strong>
                                <p class="draft-muted">{{ item.detail }}</p>
                            </div>
                        </li>
                    </ul>
                </section>
                <section class="draft-review-group">
                    <h4>Linked records</h4>
                    <dl class="draft-facts draft-linked">
                        <div><dt>Resident submission</dt><dd>{{ record.resident_reference || 'Not linked' }}</dd></div>
                        <div><dt>Incident reference</dt><dd>{{ record.incident?.reference || 'Not linked' }}</dd></div>
                    </dl>
                </section>
                <details class="draft-review-group draft-disclosure" :open="Boolean(record.open_flags)">
                    <summary>Flags <Tag v-if="record.open_flags" :value="`${record.open_flags} open`" severity="warn" /></summary>
                    <ul v-if="record.flags?.length" class="draft-flags">
                        <li v-for="flag in record.flags" :key="flag.id">
                            <strong>{{ flag.category }}</strong> <Tag :value="flag.status" :severity="flag.status === 'open' ? 'warn' : 'secondary'" />
                            <p>{{ flag.reason }}</p>
                            <p v-if="flag.resolution" class="draft-muted">Resolution · {{ flag.resolution }}</p>
                        </li>
                    </ul>
                    <p v-else class="draft-muted">No flags recorded.</p>
                    <form class="draft-flag-form" @submit.prevent="emit('flag', { ...flagForm }); flagForm.reason = ''">
                        <div class="draft-field">
                            <label for="draft-flag-category">Category</label>
                            <InputText id="draft-flag-category" v-model="flagForm.category" size="small" fluid maxlength="80" required />
                        </div>
                        <div class="draft-field">
                            <label for="draft-flag-reason">Reason</label>
                            <Textarea id="draft-flag-reason" v-model="flagForm.reason" rows="2" size="small" fluid minlength="10" required />
                        </div>
                        <Button type="submit" size="small" severity="secondary" outlined label="Add flag" :disabled="saving" />
                    </form>
                    <form v-if="openFlag" class="draft-flag-form" @submit.prevent="emit('resolve-flag', { id: openFlag.id, resolution })">
                        <div class="draft-field">
                            <label for="draft-flag-resolution">Resolution for {{ openFlag.category }}</label>
                            <Textarea id="draft-flag-resolution" v-model="resolution" rows="2" size="small" fluid minlength="10" required />
                        </div>
                        <Button type="submit" size="small" severity="secondary" outlined label="Resolve open flag" :disabled="saving" />
                    </form>
                </details>
                <details id="report-history" class="draft-review-group draft-disclosure" :open="detailsOpen">
                    <summary>Review history</summary>
                    <ol v-if="record.timeline?.length" class="draft-history">
                        <li v-for="(event, index) in record.timeline" :key="index">
                            <p>{{ event.summary }}</p>
                            <time class="draft-muted">{{ formatWhen(event.created_at) }}</time>
                        </li>
                    </ol>
                    <p v-else class="draft-muted">No workflow events yet.</p>
                    <p class="draft-muted">Version {{ record.version || 1 }}</p>
                    <RouterLink v-if="record.supersedes_id" :to="'/reports/' + record.supersedes_id + '/view'" class="draft-back">View previous report</RouterLink>
                </details>
            </aside>
        </div>

        <Dialog :visible="Boolean(previewPhoto)" modal header="Evidence photograph" :style="{ width: 'min(50rem, calc(100% - 2rem))' }"
            @update:visible="previewPhoto = null">
            <figure v-if="previewPhoto" class="draft-photo-preview">
                <img :src="photoUrl(previewPhoto)" :alt="previewPhoto.original_filename">
                <figcaption>{{ previewPhoto.original_filename }}</figcaption>
            </figure>
        </Dialog>
    </div>
</template>

<style scoped>
.draft-workspace {
    container-type: inline-size;
    --draft-control: 2.25rem;
    overflow-wrap: anywhere;
}

.draft-workspace .p-button {
    min-height: var(--draft-control);
    border-radius: var(--p-border-radius-sm);
}

.draft-workspace :deep(.cdrrmo-icon) {
    width: 1rem;
    height: 1rem;
    flex: none;
}

.draft-nav a,
.draft-back {
    display: inline-flex;
    align-items: center;
    gap: 0.25rem;
    min-height: var(--draft-control);
    color: var(--resident-navy);
    font-weight: 600;
}

.draft-nav a:hover,
.draft-back:hover { text-decoration: underline; }
.draft-nav svg { transform: rotate(180deg); }

.draft-surface {
    min-width: 0;
    border: 1px solid var(--p-surface-200);
    border-radius: var(--p-border-radius-sm);
    background: var(--p-surface-0);
}

.draft-overview { padding: 1rem; }

.draft-heading,
.draft-primary-actions,
.draft-section-heading,
.draft-section-line,
.draft-tools {
    display: flex;
    flex-wrap: wrap;
    align-items: center;
    gap: 0.5rem;
}

.draft-heading,
.draft-section-heading,
.draft-section-line { justify-content: space-between; }
.draft-heading { gap: 0.75rem 1rem; }

.draft-overview .draft-facts {
    margin-block-start: 0.875rem;
    padding-block-start: 0.75rem;
    border-top: 1px solid var(--p-surface-200);
}

.draft-facts {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(min(100%, 9rem), 1fr));
    gap: 0.625rem 1rem;
}

.draft-muted,
.draft-facts dt {
    color: var(--p-surface-600);
    font-size: 0.75rem;
}

.draft-facts dd {
    font-weight: 500;
    overflow-wrap: anywhere;
}

.draft-columns {
    display: grid;
    grid-template-columns: minmax(0, 1fr);
    align-items: start;
    gap: 1rem;
}

.draft-section-heading {
    min-height: 3.25rem;
    padding: 0.5rem 0.875rem;
    border-bottom: 1px solid var(--p-surface-200);
}

.draft-section-heading h3 {
    display: flex;
    align-items: center;
    gap: 0.5rem;
    font-size: 0.9375rem;
    font-weight: 700;
    scroll-margin-top: 6rem;
}

.draft-section-heading h3 svg { color: var(--resident-navy); }

.draft-review-group {
    display: grid;
    gap: 0.5rem;
    padding-block: 0.875rem;
}

.draft-review-group + .draft-review-group { border-top: 1px solid var(--p-surface-200); }

.draft-review-group h4,
.draft-disclosure summary {
    font-size: 0.875rem;
    font-weight: 700;
}

.draft-field,
.draft-flag-form {
    display: grid;
    gap: 0.375rem;
}

.draft-field label { font-weight: 600; }
.draft-field textarea { resize: vertical; }
.draft-flag-form .p-button { justify-self: start; }

.draft-photos {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(min(100%, 9rem), 1fr));
    gap: 0.75rem;
}

.draft-photos button {
    display: block;
    width: 100%;
    overflow: hidden;
    border: 1px solid var(--p-surface-200);
    border-radius: var(--p-border-radius-sm);
    cursor: zoom-in;
}

.draft-photos img {
    width: 100%;
    aspect-ratio: 4 / 3;
    object-fit: cover;
}

.draft-review-group { padding-inline: 0.875rem; }
.draft-feedback { background: var(--p-amber-50); }
.draft-linked { grid-template-columns: 1fr; }

.draft-checklist,
.draft-flags,
.draft-history {
    display: grid;
    gap: 0.625rem;
    padding: 0;
    list-style: none;
}

.draft-checklist li {
    display: grid;
    grid-template-columns: auto minmax(0, 1fr);
    gap: 0.5rem;
}

.draft-checklist svg { margin-top: 0.125rem; color: var(--p-amber-700); }
.draft-checklist li[data-complete="true"] svg { color: var(--p-emerald-700); }

.draft-disclosure summary {
    min-height: var(--draft-control);
    cursor: pointer;
}

.draft-disclosure summary :deep(.p-tag) { margin-inline-start: 0.25rem; }
.draft-photo-preview { display: grid; gap: 0.5rem; overflow-wrap: anywhere; }
.draft-photo-preview img { max-height: 70dvh; margin-inline: auto; object-fit: contain; }

@container (min-width: 52rem) {
    .draft-columns { grid-template-columns: minmax(0, 1fr) minmax(18rem, 21rem); }
    .draft-review-link { display: none !important; }
}

@media (pointer: coarse) {
    .draft-workspace { --draft-control: 2.75rem; }
    .draft-field :is(.p-inputtext, .p-textarea) { font-size: 1rem; }
}
</style>
