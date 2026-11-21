<script setup>
import { onMounted, ref } from 'vue';
import { useRouter } from 'vue-router';
import Button from 'primevue/button';
import Dialog from 'primevue/dialog';
import Message from 'primevue/message';
import Tag from 'primevue/tag';
import Textarea from 'primevue/textarea';
import MapIcon from '../../shared/components/MapIcon.vue';

const router = useRouter();
const loading = ref(true);
const error = ref('');
const notice = ref('');
const rows = ref([]);
const selected = ref(null);
const detailOpen = ref(false);
const returnOpen = ref(false);
const returnReason = ref('');
const busy = ref(false);

async function load() {
    loading.value = true;
    error.value = '';
    try {
        const { data } = await window.axios.get('/cdrrmo/api/reports/field-packages');
        rows.value = data.data || [];
    } catch (exception) {
        error.value = exception.response?.data?.message || 'Field packages could not be loaded.';
    } finally {
        loading.value = false;
    }
}

function severity(status) {
    if (status === 'acknowledged') return 'success';
    if (status === 'submitted') return 'info';
    if (status === 'returned') return 'warn';
    return 'secondary';
}

function formatWhen(value) {
    if (!value) return '—';
    try {
        return new Intl.DateTimeFormat(undefined, { dateStyle: 'medium', timeStyle: 'short' }).format(new Date(value));
    } catch {
        return value;
    }
}

async function openDetail(id) {
    const { data } = await window.axios.get(`/cdrrmo/api/reports/field-packages/${id}`);
    selected.value = data.data;
    detailOpen.value = true;
}

async function acknowledge() {
    if (!selected.value) return;
    busy.value = true;
    try {
        await window.axios.post(`/cdrrmo/api/reports/field-packages/${selected.value.id}/acknowledge`, {});
        notice.value = `Acknowledged ${selected.value.reference}.`;
        detailOpen.value = false;
        await load();
    } catch (exception) {
        error.value = exception.response?.data?.message || 'Acknowledgement failed.';
    } finally {
        busy.value = false;
    }
}

async function returnPackage() {
    if (!selected.value) return;
    busy.value = true;
    try {
        await window.axios.post(`/cdrrmo/api/reports/field-packages/${selected.value.id}/return`, {
            return_reason: returnReason.value,
        });
        notice.value = `Returned ${selected.value.reference}.`;
        returnOpen.value = false;
        detailOpen.value = false;
        returnReason.value = '';
        await load();
    } catch (exception) {
        error.value = exception.response?.data?.message || 'Return failed.';
    } finally {
        busy.value = false;
    }
}

function download(id) {
    window.open(`/cdrrmo/api/reports/field-packages/${id}/download`, '_blank');
}

onMounted(load);
</script>

<template>
    <section class="cdrrmo-page" aria-label="Field assessment packages">
        <header class="report-workspace-header">
            <div>
                <h2>Field Packages</h2>
                <p class="report-breadcrumb">Packages submitted from RDANA Field Assessment Reports.</p>
            </div>
            <Button type="button" size="small" severity="secondary" outlined label="Refresh" @click="load">
                <template #icon><MapIcon name="refresh" /></template>
            </Button>
        </header>

        <Message v-if="notice" severity="success" :closable="false">{{ notice }}</Message>
        <Message v-if="error" severity="error" :closable="false">{{ error }}</Message>
        <p v-if="loading">Loading packages…</p>
        <div v-else-if="!rows.length" class="field-empty">No field packages have been submitted yet.</div>
        <table v-else class="cdrrmo-table">
            <thead>
                <tr>
                    <th>Package</th>
                    <th>Coverage</th>
                    <th>Assessments</th>
                    <th>Submitted By</th>
                    <th>Submitted At</th>
                    <th>Status</th>
                    <th><span class="sr-only">Actions</span></th>
                </tr>
            </thead>
            <tbody>
                <tr v-for="row in rows" :key="row.id">
                    <td>
                        <button type="button" class="report-ref-button" @click="openDetail(row.id)">{{ row.reference }}</button>
                    </td>
                    <td>{{ row.scope === 'consolidated' ? 'Four barangays' : (row.barangay_name || 'Barangay') }}</td>
                    <td>{{ row.assessment_count }}</td>
                    <td>{{ row.submitted_by || '—' }}</td>
                    <td>{{ formatWhen(row.submitted_at) }}</td>
                    <td><Tag :value="row.status" :severity="severity(row.status)" /></td>
                    <td class="report-row-actions">
                        <button type="button" class="residents-icon-btn" title="View" aria-label="View" @click="openDetail(row.id)"><MapIcon name="eye" /></button>
                        <button type="button" class="residents-icon-btn" title="Download Excel" aria-label="Download Excel" @click="download(row.id)"><MapIcon name="download" /></button>
                    </td>
                </tr>
            </tbody>
        </table>

        <Dialog v-model:visible="detailOpen" modal header="Field package" :style="{ width: 'min(36rem, 96vw)' }">
            <dl v-if="selected" class="resident-fields">
                <div><dt>Reference</dt><dd>{{ selected.reference }}</dd></div>
                <div><dt>Coverage</dt><dd>{{ selected.scope === 'consolidated' ? 'Four barangays' : (selected.barangay_name || 'Barangay') }}</dd></div>
                <div><dt>Submitted by</dt><dd>{{ selected.submitted_by || '—' }}</dd></div>
                <div><dt>Submitted at</dt><dd>{{ formatWhen(selected.submitted_at) }}</dd></div>
                <div><dt>Status</dt><dd>{{ selected.status }}</dd></div>
                <div><dt>Included assessments</dt><dd>{{ (selected.items || []).map((item) => item.assessment_reference).join(', ') || '—' }}</dd></div>
                <div><dt>Excel</dt><dd>{{ selected.export?.filename || 'Not Available' }}</dd></div>
            </dl>
            <template #footer>
                <Button type="button" size="small" severity="secondary" text label="Close" @click="detailOpen = false" />
                <Button v-if="selected?.export" type="button" size="small" outlined label="Download Excel" @click="download(selected.id)" />
                <Button v-if="selected?.status === 'submitted'" type="button" size="small" label="Acknowledge Receipt" :loading="busy" @click="acknowledge" />
                <Button v-if="selected && ['submitted','acknowledged'].includes(selected.status)" type="button" size="small" severity="warn" outlined label="Return" @click="returnOpen = true" />
            </template>
        </Dialog>

        <Dialog v-model:visible="returnOpen" modal header="Return field package" :style="{ width: 'min(28rem, 96vw)' }">
            <label>
                <span>Return reason</span>
                <Textarea v-model="returnReason" rows="4" maxlength="2000" />
            </label>
            <template #footer>
                <Button type="button" size="small" severity="secondary" text label="Cancel" @click="returnOpen = false" />
                <Button type="button" size="small" severity="warn" label="Return for Correction" :loading="busy" :disabled="returnReason.trim().length < 10" @click="returnPackage" />
            </template>
        </Dialog>
    </section>
</template>
