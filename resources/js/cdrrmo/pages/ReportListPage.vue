<script setup>
import { computed, inject, onMounted, reactive, ref, watch } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import MapIcon from '../../shared/components/MapIcon.vue';
import ReviewSignDialog from '../../shared/components/ReviewSignDialog.vue';

const route = useRoute();
const router = useRouter();
const userName = inject('cdrrmoUserName', '');
const userEmail = inject('cdrrmoUserEmail', '');
const loading = ref(true);
const error = ref('');
const notice = ref('');
const rows = ref([]);
const summary = ref({ total: 0, awaiting_review: 0, awaiting_signature: 0, finalized: 0 });
const filterOptions = ref({ incidents: [], barangays: [] });
const meta = ref({ total: 0, current_page: 1, last_page: 1, per_page: 20 });
const signRow = ref(null);
const menuId = ref(null);
const fileMessage = ref('');
const flagRow = ref(null);
const flagForm = reactive({ category: 'Review', reason: '' });
const flagError = ref('');
const requestId = ref(0);

const reportType = computed(() => route.meta.reportType || 'barangay');
const title = computed(() => route.meta.title || 'Reports');
const apiPath = computed(() => `/cdrrmo/api/reports/${reportType.value}`);
const capacity = computed(() => {
    if (userEmail === 'lestertalan@mapr_admin.com' || userEmail === 'lestertalan@mapr.com') return { role: 'noted_by', position: 'LDRRMO II', path: 'note' };
    if (userEmail === 'reyveen.geli@mapr.test') return { role: 'approved_by', position: 'Head, CDRRMO', path: 'approve' };
    return null;
});
const filters = reactive({
    q: '',
    incident_id: '',
    barangay_id: '',
    workflow_status: '',
    signature_status: '',
    flag_status: '',
    date_from: '',
    date_to: '',
    sort: 'updated',
    direction: 'desc',
    page: 1,
});

function readQuery() {
    filters.q = route.query.q || '';
    filters.incident_id = route.query.incident_id || '';
    filters.barangay_id = route.query.barangay_id || '';
    filters.workflow_status = route.query.workflow_status || '';
    filters.signature_status = route.query.signature_status || '';
    filters.flag_status = route.query.flag_status || '';
    filters.date_from = route.query.date_from || '';
    filters.date_to = route.query.date_to || '';
    filters.sort = route.query.sort || 'updated';
    filters.direction = route.query.direction || 'desc';
    filters.page = Number(route.query.page || 1);
}

function queryParams() {
    const params = {};
    Object.entries(filters).forEach(([key, value]) => {
        if (value !== '' && value !== null && value !== undefined) params[key] = value;
    });
    return params;
}

async function load() {
    const current = ++requestId.value;
    loading.value = true;
    error.value = '';
    try {
        const { data } = await window.axios.get(apiPath.value, { params: queryParams() });
        if (current !== requestId.value) return;
        rows.value = data.data || [];
        meta.value = data.meta || meta.value;
        summary.value = data.summary || summary.value;
        filterOptions.value = data.filters || { incidents: [], barangays: [] };
    } catch {
        if (current !== requestId.value) return;
        error.value = 'Reports could not be loaded.';
    } finally {
        if (current === requestId.value) loading.value = false;
    }
}

function apply(page = 1) {
    filters.page = page;
    const query = {};
    Object.entries(filters).forEach(([key, value]) => {
        if (value !== '' && value !== null && !(key === 'page' && Number(value) === 1) && !(key === 'sort' && value === 'updated') && !(key === 'direction' && value === 'desc')) {
            query[key] = String(value);
        }
    });
    router.replace({ query });
}

function clearFilters() {
    Object.assign(filters, {
        q: '', incident_id: '', barangay_id: '', workflow_status: '', signature_status: '', flag_status: '', date_from: '', date_to: '', sort: 'updated', direction: 'desc', page: 1,
    });
    router.replace({ query: {} });
}

function openReport(row) {
    menuId.value = null;
    router.push({ path: `/reports/document/${reportType.value}/${row.id}`, query: { return: route.fullPath } });
}

function previewFile(row) {
    menuId.value = null;
    if (row.attachment?.download_path) {
        const name = (row.attachment.filename || '').toLowerCase();
        if (name.endsWith('.pdf')) {
            window.open(row.attachment.download_path, '_blank', 'noopener');
            return;
        }
        fileMessage.value = `${row.reference} has an original ${row.attachment.filename}. A DOCX preview converter is not available, so the file is offered as a download. The original is not changed.`;
        return;
    }
    if (row.preview_path) {
        window.open(row.preview_path, '_blank', 'noopener');
        return;
    }
    fileMessage.value = `${row.reference} has no original file attached. Open the structured report to review the saved version.`;
}

function downloadReport(row) {
    menuId.value = null;
    if (row.attachment?.download_path) {
        window.location.assign(row.attachment.download_path);
        return;
    }
    if (row.preview_path) {
        window.location.assign(`${row.preview_path}?format=pdf`);
        return;
    }
    fileMessage.value = `${row.reference} has no file available for download.`;
}

function subjectType() {
    if (reportType.value === 'field') return 'field_assessment';
    if (reportType.value === 'consolidated') return 'consolidated_report';
    return 'barangay_report';
}

async function saveFlag() {
    flagError.value = '';
    if (!flagRow.value || flagForm.reason.trim().length < 10) {
        flagError.value = 'Enter a reason of at least 10 characters.';
        return;
    }
    try {
        await window.axios.post('/cdrrmo/api/flags', {
            subject_type: subjectType(),
            subject_id: flagRow.value.id,
            category: flagForm.category,
            reason: flagForm.reason,
        });
        flagRow.value = null;
        flagForm.reason = '';
        notice.value = 'Flag recorded.';
        window.dispatchEvent(new Event('mapr-nav-refresh'));
        await load();
    } catch (exception) {
        flagError.value = exception.response?.data?.message || 'The flag could not be saved.';
    }
}

async function confirmSign(payload) {
    const row = signRow.value;
    signRow.value = null;
    if (!row || !capacity.value) return;
    try {
        await window.axios.post(`/cdrrmo/api/consolidated/${row.id}/${capacity.value.path}`, payload);
        notice.value = 'Signature recorded.';
        window.dispatchEvent(new Event('mapr-nav-refresh'));
        await load();
    } catch (exception) {
        error.value = exception.response?.data?.message || exception.response?.data?.errors?.password?.[0] || 'The signature was not recorded.';
    }
}

onMounted(() => {
    readQuery();
    load();
});
watch(() => route.fullPath, () => {
    if (route.meta.reportType) {
        readQuery();
        load();
    }
});
</script>

<template>
    <section class="report-admin">
        <header class="report-admin-head">
            <div>
                <h2 class="cdrrmo-page-title">{{ title }}</h2>
                <p class="cdrrmo-page-lead">Review reports, inspect supporting documents, and track approvals.</p>
            </div>
            <div class="report-admin-head-actions">
                <button type="button" class="mapr-button mapr-button-secondary" @click="load"><MapIcon name="refresh" /> Refresh</button>
                <router-link v-if="reportType === 'consolidated'" class="mapr-button mapr-button-primary" :to="{ path: '/map', query: { mode: 'estimate' } }"><MapIcon name="plus" /> Create Report</router-link>
            </div>
        </header>

        <div class="cdrrmo-stat-grid">
            <button type="button" class="cdrrmo-stat-card" @click="clearFilters"><MapIcon name="file" /><p>Total reports</p><strong>{{ summary.total }}</strong></button>
            <button type="button" class="cdrrmo-stat-card" @click="filters.workflow_status = 'review'; apply()"><MapIcon name="clipboard" /><p>Awaiting review</p><strong>{{ summary.awaiting_review }}</strong></button>
            <button type="button" class="cdrrmo-stat-card" @click="filters.signature_status = 'unsigned'; apply()"><MapIcon name="pen" /><p>Awaiting signature</p><strong>{{ summary.awaiting_signature }}</strong><span>Not the same as submitted</span></button>
            <button type="button" class="cdrrmo-stat-card" @click="filters.workflow_status = reportType === 'consolidated' ? 'finalized' : 'submitted'; apply()"><MapIcon name="check" /><p>{{ reportType === 'consolidated' ? 'Finalized' : 'Submitted' }}</p><strong>{{ summary.finalized }}</strong></button>
        </div>

        <form class="report-filter-bar" @submit.prevent="apply(1)">
            <label>Search <input v-model="filters.q" type="search" placeholder="Reference or title"></label>
            <label>Incident
                <select v-model="filters.incident_id">
                    <option value="">All incidents</option>
                    <option v-for="item in filterOptions.incidents" :key="item.id" :value="item.id">{{ item.name }}</option>
                </select>
            </label>
            <label v-if="reportType !== 'consolidated'">Barangay
                <select v-model="filters.barangay_id">
                    <option value="">All barangays</option>
                    <option v-for="item in filterOptions.barangays" :key="item.id" :value="item.id">{{ item.name }}</option>
                </select>
            </label>
            <label>Status
                <select v-model="filters.workflow_status">
                    <option value="">All statuses</option>
                    <option value="draft">Draft</option>
                    <option value="review">Awaiting review</option>
                    <option value="signed">Signed</option>
                    <option value="submitted">Submitted</option>
                    <option value="returned">Returned for correction</option>
                    <option v-if="reportType === 'consolidated'" value="finalized">Finalized</option>
                </select>
            </label>
            <label>Signature
                <select v-model="filters.signature_status">
                    <option value="">Any signature</option>
                    <option value="unsigned">Awaiting signature</option>
                    <option value="partial">Partially signed</option>
                    <option value="complete">Signed</option>
                </select>
            </label>
            <label>Flags
                <select v-model="filters.flag_status">
                    <option value="">Any flag</option>
                    <option value="open">Open flags</option>
                    <option value="none">No open flags</option>
                </select>
            </label>
            <label>From <input v-model="filters.date_from" type="date"></label>
            <label>To <input v-model="filters.date_to" type="date"></label>
            <button type="submit" class="mapr-button mapr-button-primary">Apply</button>
            <button type="button" class="mapr-button mapr-button-secondary" @click="clearFilters"><MapIcon name="filter-reset" /> Clear filters</button>
        </form>

        <p v-if="notice" class="cdrrmo-muted" role="status">{{ notice }}</p>
        <p v-if="fileMessage" class="cdrrmo-muted" role="status">{{ fileMessage }} <button type="button" class="mapr-button mapr-button-secondary" @click="fileMessage = ''">Dismiss</button></p>
        <p v-if="error" class="cdrrmo-error" role="alert">{{ error }} <button type="button" class="mapr-button mapr-button-secondary" @click="load">Retry</button></p>
        <p v-if="loading" class="report-loading">Loading reports…</p>

        <div v-else class="cdrrmo-table-wrap">
            <table v-if="rows.length" class="cdrrmo-table report-admin-table">
                <thead>
                    <tr>
                        <th><button type="button" @click="filters.sort = 'reference'; filters.direction = filters.direction === 'asc' ? 'desc' : 'asc'; apply(filters.page)">Reference</button></th>
                        <th>Incident</th>
                        <th>{{ reportType === 'consolidated' ? 'Scope' : 'Barangay' }}</th>
                        <th>Submitted by</th>
                        <th>Status</th>
                        <th>Signature</th>
                        <th><button type="button" @click="filters.sort = 'updated'; filters.direction = filters.direction === 'asc' ? 'desc' : 'asc'; apply(filters.page)">Updated</button></th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-for="row in rows" :key="row.id">
                        <td>
                            <button type="button" class="report-ref-button" @click="openReport(row)">{{ row.reference }}</button>
                            <span>{{ row.title }}</span>
                        </td>
                        <td>{{ row.incident_name }}</td>
                        <td>{{ row.barangay_name }}</td>
                        <td>{{ row.submitted_by }}</td>
                        <td><span class="report-status" :data-status="row.workflow_status">{{ row.workflow_label }}</span></td>
                        <td>{{ row.signature_label }}<span v-if="row.open_flags"> · {{ row.open_flags }} open flag{{ row.open_flags === 1 ? '' : 's' }}</span></td>
                        <td>{{ row.updated_label }}</td>
                        <td class="report-row-actions">
                            <button type="button" class="residents-icon-btn" title="View report" aria-label="View report" @click="openReport(row)"><MapIcon name="eye" /></button>
                            <button type="button" class="residents-icon-btn" title="Preview original file" aria-label="Preview original file" @click="previewFile(row)"><MapIcon name="search" /></button>
                            <button type="button" class="residents-icon-btn" title="Download" aria-label="Download" @click="downloadReport(row)"><MapIcon name="download" /></button>
                            <button type="button" class="residents-icon-btn" title="More actions" aria-label="More actions" :aria-expanded="menuId === row.id" @click="menuId = menuId === row.id ? null : row.id"><MapIcon name="more" /></button>
                            <div v-if="menuId === row.id" class="report-menu" role="menu">
                                <button v-if="reportType === 'consolidated' && capacity && row.workflow_status !== 'finalized'" type="button" role="menuitem" @click="signRow = row; menuId = null"><MapIcon name="pen" /> Review &amp; Sign</button>
                                <button type="button" role="menuitem" @click="flagRow = row; menuId = null"><MapIcon name="flag" /> Flag for review</button>
                                <button type="button" role="menuitem" @click="openReport(row)"><MapIcon name="history" /> Version history</button>
                                <p v-if="reportType !== 'consolidated'" role="note">Editing and signing stay with the preparing office.</p>
                            </div>
                        </td>
                    </tr>
                </tbody>
            </table>
            <p v-else class="cdrrmo-muted">No reports match these filters.</p>
        </div>

        <div v-if="!loading && meta.last_page > 1" class="cdrrmo-actions">
            <button type="button" class="mapr-button mapr-button-secondary" :disabled="meta.current_page <= 1" @click="apply(meta.current_page - 1)">Previous</button>
            <span class="cdrrmo-muted">Page {{ meta.current_page }} of {{ meta.last_page }}</span>
            <button type="button" class="mapr-button mapr-button-secondary" :disabled="meta.current_page >= meta.last_page" @click="apply(meta.current_page + 1)">Next</button>
        </div>

        <div v-if="flagRow" class="residents-drawer-backdrop" @click.self="flagRow = null">
            <form class="review-sign-dialog" @submit.prevent="saveFlag">
                <h3>Flag {{ flagRow.reference }}</h3>
                <label>Category <input v-model="flagForm.category" maxlength="80" required></label>
                <label>Reason <textarea v-model="flagForm.reason" rows="4" minlength="10" required></textarea></label>
                <p v-if="flagError" class="cdrrmo-error">{{ flagError }}</p>
                <div class="cdrrmo-actions">
                    <button type="button" class="mapr-button mapr-button-secondary" @click="flagRow = null">Cancel</button>
                    <button type="submit" class="mapr-button mapr-button-primary">Save flag</button>
                </div>
            </form>
        </div>

        <ReviewSignDialog
            :open="!!signRow"
            :reference="signRow?.reference || ''"
            :name="userName"
            :position="capacity?.position || ''"
            :capacity="capacity?.role || ''"
            @close="signRow = null"
            @confirm="confirmSign"
        />
    </section>
</template>
