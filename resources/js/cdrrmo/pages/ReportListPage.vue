<script setup>
import { computed, inject, onMounted, reactive, ref, watch } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import ReviewSignDialog from '../../shared/components/ReviewSignDialog.vue';
import ReportReviewList from '../components/ReportReviewList.vue';

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
const hasLoaded = ref(false);
const appliedFilters = ref({});
const signRow = ref(null);
const fileMessage = ref('');
const flagRow = ref(null);
const flagForm = reactive({ category: 'Review', reason: '' });
const flagError = ref('');
const flagSaving = ref(false);
const requestId = ref(0);

const reportType = computed(() => route.meta.reportType || 'barangay');
const invalidFlagField = computed(() => {
    if (!flagForm.category.trim()) return 'category';
    if (flagForm.reason.trim().length < 10) return 'reason';
    return null;
});
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
    const params = queryParams();
    appliedFilters.value = params;
    loading.value = true;
    error.value = '';
    try {
        const { data } = await window.axios.get(apiPath.value, { params });
        if (current !== requestId.value) return;
        rows.value = data.data || [];
        meta.value = data.meta || meta.value;
        summary.value = data.summary || summary.value;
        filterOptions.value = data.filters || { incidents: [], barangays: [] };
        hasLoaded.value = true;
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
    if (reportType.value !== 'barangay' && !Object.keys(route.query).length
        && Object.entries(appliedFilters.value).some(([key, value]) => value !== filters[key])) {
        load();
        return;
    }
    router.replace({ query: {} });
}

function openReport(row) {
    router.push({ path: `/reports/document/${reportType.value}/${row.id}`, query: { return: route.fullPath } });
}

function previewFile(row) {
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
    const kind = reportType.value;
    if (flagSaving.value) return;
    flagError.value = '';
    if (!flagRow.value || invalidFlagField.value) {
        flagError.value = invalidFlagField.value === 'category'
            ? 'Enter a category.'
            : 'Enter a reason of at least 10 characters.';
        return;
    }
    const target = flagRow.value;
    flagSaving.value = true;
    try {
        await window.axios.post('/cdrrmo/api/flags', {
            subject_type: subjectType(),
            subject_id: flagRow.value.id,
            category: flagForm.category,
            reason: flagForm.reason,
        });
        window.dispatchEvent(new Event('mapr-nav-refresh'));
        if (reportType.value !== kind || flagRow.value !== target) return;
        flagRow.value = null;
        flagForm.reason = '';
        notice.value = 'Flag recorded.';
        await load();
    } catch (exception) {
        if (reportType.value === kind && flagRow.value === target) {
            flagError.value = exception.response?.data?.message || 'The flag could not be saved.';
        }
    } finally {
        flagSaving.value = false;
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
watch(reportType, () => {
    rows.value = [];
    summary.value = { total: 0, awaiting_review: 0, awaiting_signature: 0, finalized: 0 };
    filterOptions.value = { incidents: [], barangays: [] };
    meta.value = { total: 0, current_page: 1, last_page: 1, per_page: 20 };
    hasLoaded.value = false;
    appliedFilters.value = {};
    signRow.value = null;
    flagRow.value = null;
    flagError.value = '';
    notice.value = '';
    fileMessage.value = '';
});
watch(() => route.fullPath, () => {
    if (route.meta.reportType) {
        readQuery();
        load();
    }
});
</script>

<template>
    <ReportReviewList
        :key="reportType"
        :kind="reportType"
        :filters="filters"
        :applied-filters="appliedFilters"
        :flag-form="flagForm"
        :rows="rows"
        :meta="meta"
        :summary="summary"
        :filter-options="filterOptions"
        :loading="loading"
        :has-loaded="hasLoaded"
        :error="error"
        :notice="notice"
        :file-message="fileMessage"
        :flag-row="flagRow"
        :flag-error="flagError"
        :invalid-flag-field="invalidFlagField"
        :flag-saving="flagSaving"
        :can-sign="!!capacity"
        @update:filters="Object.assign(filters, $event)"
        @update:flag-form="Object.assign(flagForm, $event)"
        @apply="apply"
        @clear="clearFilters"
        @refresh="load"
        @open="openReport"
        @preview="previewFile"
        @download="downloadReport"
        @dismiss-file-message="fileMessage = ''"
        @flag="flagRow = $event; flagError = ''"
        @close-flag="flagRow = null"
        @save-flag="saveFlag"
        @sign="signRow = $event"
    >
        <ReviewSignDialog
            :open="!!signRow"
            :reference="signRow?.reference || ''"
            :name="userName"
            :position="capacity?.position || ''"
            :capacity="capacity?.role || ''"
            @close="signRow = null"
            @confirm="confirmSign"
        />
    </ReportReviewList>
</template>
