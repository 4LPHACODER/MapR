<script setup>
import { computed, ref } from 'vue';
import MapIcon from '../../shared/components/MapIcon.vue';

const props = defineProps({
    record: { type: Object, required: true },
    form: { type: Object, required: true },
    profile: { type: Object, default: null },
    classes: { type: Array, default: () => [] },
    apiBase: { type: String, required: true },
    saving: { type: Boolean, default: false },
    saveState: { type: String, default: 'Saved' },
    editing: { type: Boolean, default: false },
    zoom: { type: String, default: '100%' },
    detailsOpen: { type: Boolean, default: false },
    menuOpen: { type: Boolean, default: false },
});
const emit = defineEmits(['save', 'sign', 'submit', 'verify', 'upload', 'return-report', 'flag', 'resolve-flag', 'update:editing', 'update:zoom', 'update:detailsOpen', 'update:menuOpen']);
const flagForm = ref({ category: 'Review', reason: '' });
const resolution = ref('');

const history = ref([]);
const future = ref([]);
const previewPhoto = ref(null);
const narrative = computed(() => 'findings');
const readOnly = computed(() => !props.record.can_edit || (!props.editing && ['signed', 'submitted', 'finalized'].includes(props.record.status)));
const title = computed(() => 'Field Assessment Report');
const instruction = computed(() => 'Review the assigned assessment. Verification and approval are separate signatures.');
const scale = computed(() => ({ '75%': 0.75, '100%': 1, '125%': 1.25, '150%': 1.5, 'Fit width': 1 }[props.zoom] || 1));
const canSign = computed(() => props.profile?.signing_capacity === 'verified_by' || props.profile?.signing_capacity === 'approved_by');
const previewHref = computed(() => `/rdana/assessments/${props.record.id}/preview`);
const photoReportId = computed(() => props.record.barangay_report?.id || props.record.id);

function remember() {
    history.value.push(props.form[narrative.value] || '');
    future.value = [];
}

function undo() {
    if (!history.value.length) return;
    future.value.push(props.form[narrative.value] || '');
    props.form[narrative.value] = history.value.pop();
}

function redo() {
    if (!future.value.length) return;
    history.value.push(props.form[narrative.value] || '');
    props.form[narrative.value] = future.value.pop();
}

function photoUrl(photo) {
    return `${props.apiBase}/reports/${photoReportId.value}/photos/${photo.id}`;
}

const signatureSlots = computed(() => [
    { role: 'verified_by', title: 'Verified by', name: 'Mark L. Merillo', position: 'Administrative Officer III' },
    { role: 'approved_by', title: 'Approved by', name: 'Engr. Lovy R. Dedicatoria, LAOO II', position: 'Acting City Assessor' },
]);

function printPreview() {
    if (previewHref.value) window.open(previewHref.value, '_blank', 'noopener');
}

function signatureState(role) {
    const row = (props.record.signatures || []).find((item) => item.signature_role === role && item.mechanism === 'authenticated_e_signature');
    if (!row) return { label: 'Awaiting signature', signed: false };
    if (row.signature_statement) return { label: row.signature_statement, signed: false };
    return { label: `Signed ${row.signed_at}`, signed: true };
}
</script>

<template>
    <div class="report-workspace">
        <header class="report-workspace-header">
            <div>
                <p class="report-breadcrumb">Reports / {{ record.reference }}</p>
                <h2>{{ title }}</h2>
                <p>{{ instruction }}</p>
            </div>
            <div class="report-workspace-actions">
                <span class="report-badge">{{ record.label }}</span>
                <span>v{{ record.version || 1 }}</span>
                <span>{{ saveState }}</span>
                <button v-if="record.can_edit && readOnly" type="button" class="mapr-button mapr-button-secondary" @click="emit('update:editing', true)">Edit</button>
                <button v-if="record.can_edit" type="button" class="mapr-button mapr-button-secondary" :disabled="saving || readOnly" @click="emit('save')">Save Draft</button>
                <a v-if="previewHref" class="mapr-button mapr-button-secondary" :href="previewHref" target="_blank" rel="noopener">Preview</a>
                <button v-if="previewHref" type="button" class="mapr-button mapr-button-secondary" @click="printPreview">Print</button>
                <button v-if="canSign" type="button" class="mapr-button mapr-button-primary" :disabled="saving" @click="emit('sign')">Review &amp; Sign</button>
                <button type="button" class="mapr-button mapr-button-secondary" aria-label="More actions" @click="emit('update:menuOpen', !menuOpen)">More</button>
                <div v-if="menuOpen" class="report-menu" role="menu">
                    <a v-if="previewHref" role="menuitem" :href="`${previewHref}?format=pdf`">Download PDF</a>
                    <a v-if="record.attachment" role="menuitem" :href="`/${record.attachment.download_path}`">Download original attachment</a>
                    <p v-else role="menuitem">No original DOCX is attached. A structured preview does not rewrite an uploaded file.</p>
                    <button v-if="record.can_edit" type="button" role="menuitem" @click="emit('submit')">Forward to the next office</button>
                </div>
            </div>
        </header>
        <div class="report-workspace-toolbar">
            <button type="button" :disabled="!history.length" @click="undo">Undo</button>
            <button type="button" :disabled="!future.length" @click="redo">Redo</button>
            <label>Zoom
                <select :value="zoom" @change="emit('update:zoom', $event.target.value)">
                    <option>Fit width</option>
                    <option>75%</option>
                    <option>100%</option>
                    <option>125%</option>
                    <option>150%</option>
                </select>
            </label>
            <button type="button" class="report-details-toggle" @click="emit('update:detailsOpen', !detailsOpen)">Report details</button>
        </div>
        <div class="report-workspace-body">
            <div class="report-canvas">
                <article class="report-paper" :style="{ transform: `scale(${scale})` }">
                    <header class="report-paper-head">
                        <img :src="'/images/mapr-logo.png'" alt="MapR">
                        <div>
                            <h3>{{ title }}</h3>
                            <p>MapR · Tandag City</p>
                        </div>
                    </header>
                    <table>
                        <tbody>
                            <tr><th>Report reference</th><td>{{ record.reference }}</td><th>Status</th><td>{{ record.label }}</td></tr>
                            <tr><th>Incident</th><td>{{ record.incident?.reference || 'Not recorded' }}</td><th>Disaster</th><td>{{ record.incident?.disaster_type || 'Not recorded' }}</td></tr>
                            <tr><th>Source report</th><td>{{ record.barangay_report?.reference || 'Not recorded' }}</td><th>Barangay</th><td>{{ record.barangay?.name || 'Not recorded' }}</td></tr>
                            <tr><th>Assessor</th><td>{{ record.assessor?.name || profile?.name || 'Not recorded' }}</td><th>Building</th><td>{{ record.building?.reference || 'Not recorded' }}</td></tr>
                        </tbody>
                    </table>
                    <section>
                        <h4>1. Building and location verification</h4>
                        <p>Household: {{ record.household?.reference || 'Not linked' }}</p>
                        <router-link class="mapr-button mapr-button-secondary" to="/map"><MapIcon name="pin" /> View on Map</router-link>
                        <template v-if="!readOnly">
                            <label><input v-model="form.location_verified" type="checkbox"> Location checked</label>
                            <label><input v-model="form.household_link_verified" type="checkbox"> Household link checked</label>
                            <label>Location remarks
                                <textarea v-model="form.verification_notes" rows="2"></textarea>
                            </label>
                            <button type="button" class="mapr-button mapr-button-secondary" :disabled="saving" @click="emit('verify')">Save verification</button>
                        </template>
                        <p v-else>Location remarks: {{ form.verification_notes || 'Not recorded' }}</p>
                    </section>
                    <section>
                        <h4>2. Damage assessment</h4>
                        <label v-if="!readOnly">Assessor finding
                            <select v-model="form.verified_damage_class_id" :disabled="readOnly">
                                <option value="">Choose a field damage class</option>
                                <option v-for="item in classes" :key="item.id" :value="item.id">{{ item.class_name }}</option>
                            </select>
                        </label>
                        <p v-if="record.predictions?.length">Preliminary classification: {{ record.predictions[0].prediction_status }}. {{ record.predictions[0].failure_reason || '' }}</p>
                        <label>Observations
                            <textarea v-if="!readOnly" v-model="form[narrative]" rows="4" @focus="remember"></textarea>
                            <p v-else>{{ form[narrative] || 'Not recorded' }}</p>
                        </label>
                    </section>
                    <section>
                        <h4>3. Supporting evidence</h4>
                        <div class="report-photos">
                            <figure v-for="photo in record.photos || []" :key="photo.id">
                                <button type="button" @click="previewPhoto = photoUrl(photo)"><img :src="photoUrl(photo)" :alt="photo.original_filename"></button>
                                <figcaption>{{ photo.original_filename }}</figcaption>
                            </figure>
                            <p v-if="!(record.photos || []).length">No photograph is attached to this report.</p>
                        </div>
                    </section>
                    <section>
                        <h4>4. Corrections and follow-up</h4>
                        <p v-if="record.return_reason">Returned for correction: {{ record.return_reason }}</p>
                        <label v-if="!readOnly">Return reason
                            <textarea v-model="form.return_reason" rows="2" :disabled="saving"></textarea>
                        </label>
                        <button v-if="record.can_edit" type="button" class="mapr-button mapr-button-secondary" :disabled="saving" @click="emit('return-report')">Return source report</button>
                    </section>
                    <section class="report-sign-block">
                        <h4>5. Signatures</h4>
                        <div v-for="slot in signatureSlots" :key="slot.role">
                            <p><strong>{{ slot.title }}</strong><br>{{ slot.name }}<br>{{ slot.position }}</p>
                            <p>{{ signatureState(slot.role).label }}</p>
                        </div>
                    </section>
                </article>
            </div>
            <aside class="report-details" :class="{ 'is-open': detailsOpen }">
                <section>
                    <h3><MapIcon name="file" /> Source report</h3>
                    <p>{{ record.barangay_report?.reference || 'No source report' }}</p>
                    <p>{{ record.barangay_report?.signed ? 'Signed source' : (record.barangay_report?.label || 'No signature recorded') }}</p>
                </section>
                <section>
                    <h3><MapIcon name="link" /> Linked records</h3>
                    <p>Incident: {{ record.incident?.reference || 'Not linked' }}</p>
                    <p>Barangay: {{ record.barangay?.name || 'Not linked' }}</p>
                    <p>Building: {{ record.building?.reference || 'Not linked' }}</p>
                    <p>Household: {{ record.household?.reference || 'Not linked' }}</p>
                </section>
                <section>
                    <h3><MapIcon name="clipboard" /> Checklist before signing</h3>
                    <ul>
                        <li v-for="item in record.checklist || []" :key="item.key">
                            {{ item.met ? 'Complete' : 'Incomplete' }} — {{ item.label }}. {{ item.detail }}
                        </li>
                    </ul>
                </section>
                <section>
                    <h3>Workflow</h3>
                    <ul>
                        <li v-for="(event, index) in record.timeline || []" :key="index">{{ event.summary }} · {{ event.created_at }}</li>
                        <li v-if="!(record.timeline || []).length">No workflow event is recorded yet.</li>
                    </ul>
                </section>
                <section>
                    <h3><MapIcon name="flag" /> Flags</h3>
                    <p>{{ record.open_flags || 0 }} open</p>
                    <ul>
                        <li v-for="flag in record.flags || []" :key="flag.id">
                            <strong>{{ flag.status }}</strong> — {{ flag.category }}: {{ flag.reason }}
                            <p v-if="flag.resolution">Resolution: {{ flag.resolution }}</p>
                        </li>
                    </ul>
                    <form @submit.prevent="emit('flag', { ...flagForm }); flagForm.reason = ''">
                        <label>Category <input v-model="flagForm.category" maxlength="80" required></label>
                        <label>Reason <textarea v-model="flagForm.reason" rows="2" minlength="10" required></textarea></label>
                        <button type="submit" class="mapr-button mapr-button-secondary" :disabled="saving">Add flag</button>
                    </form>
                    <form @submit.prevent="emit('resolve-flag', { id: (record.flags || []).find((flag) => flag.status === 'open')?.id, resolution })">
                        <label>Resolution <textarea v-model="resolution" rows="2" minlength="10" required></textarea></label>
                        <button type="submit" class="mapr-button mapr-button-secondary" :disabled="saving || !(record.flags || []).some((flag) => flag.status === 'open')">Resolve open flag</button>
                    </form>
                </section>
                <section>
                    <h3><MapIcon name="history" /> Version history</h3>
                    <p>Version {{ record.version || 1 }}</p>
                    <p v-if="record.supersedes_id">This revision follows version {{ record.supersedes_id }}. Open that record to view it read-only.</p>
                </section>
            </aside>
        </div>
        <div v-if="previewPhoto" class="residents-drawer-backdrop" @click="previewPhoto = null">
            <img class="report-photo-full" :src="previewPhoto" alt="Evidence photograph">
        </div>
    </div>
</template>
