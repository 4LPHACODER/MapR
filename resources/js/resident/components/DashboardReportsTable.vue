<script setup>
import { computed, inject, nextTick, onBeforeUnmount, onMounted, ref, watch } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import Button from 'primevue/button';
import Column from 'primevue/column';
import DataTable from 'primevue/datatable';
import IconField from 'primevue/iconfield';
import InputIcon from 'primevue/inputicon';
import InputText from 'primevue/inputtext';
import Message from 'primevue/message';
import Select from 'primevue/select';
import MapIcon from '../../shared/components/MapIcon.vue';
import StatusBadge from './StatusBadge.vue';
import { formatWhen } from '../format';

const apiBase = inject('apiBase');
const route = useRoute();
const router = useRouter();
const reports = ref([]);
const loading = ref(true);
const error = ref('');
const searchField = ref(null);
const searchInput = ref(String(route.query.search || ''));
const composing = ref(false);
let requestId = 0;
const statuses = [
    { label: 'All statuses', value: '' },
    { label: 'Under review', value: 'under_review' },
    { label: 'Needs correction', value: 'needs_correction' },
    { label: 'Finalized', value: 'finalized' },
];
const periods = [
    { label: 'Any time', value: '' },
    { label: 'Last 30 days', value: '30' },
    { label: 'Last 90 days', value: '90' },
    { label: 'Last year', value: '365' },
];
const sortFields = ['reference', 'incident_title', 'label', 'updated_at'];
const search = computed(() => String(route.query.search || ''));
const status = computed(() => statuses.some((item) => item.value === route.query.status) ? route.query.status : '');
const period = computed(() => periods.some((item) => item.value === route.query.period) ? route.query.period : '');
const sortField = computed(() => sortFields.includes(route.query.sort) ? route.query.sort : 'updated_at');
const sortOrder = computed(() => route.query.order === 'asc' ? 1 : -1);
const rows = computed(() => [5, 10, 20].includes(Number(route.query.rows)) ? Number(route.query.rows) : 5);
const filtersActive = computed(() => Boolean(search.value || status.value || period.value));
const filtered = computed(() => {
    const query = search.value.trim().toLocaleLowerCase('en-PH');
    const cutoff = period.value ? Date.now() - Number(period.value) * 86400000 : null;
    return reports.value.filter((report) => {
        const haystack = [report.reference, report.incident_title, report.reported_location].join(' ').toLocaleLowerCase('en-PH');
        return (!query || haystack.includes(query))
            && (!status.value || report.bucket === status.value)
            && (!cutoff || new Date(String(report.updated_at || '').replace(' ', 'T')).getTime() >= cutoff);
    });
});
const first = computed(() => {
    const page = Math.max(1, Math.floor(Number(route.query.page) || 1));
    return Math.min(page - 1, Math.max(0, Math.ceil(filtered.value.length / rows.value) - 1)) * rows.value;
});

function commit(values) {
    const query = { ...route.query, page: undefined, ...values };
    for (const key of Object.keys(query)) if (!query[key]) delete query[key];
    router.replace({ query });
}
function searchReports() {
    if (!composing.value) commit({ search: searchInput.value });
}
async function clearSearch() {
    searchInput.value = '';
    commit({ search: '' });
    await nextTick();
    searchField.value?.$el?.focus();
}
function resetFilters() {
    searchInput.value = '';
    commit({ search: '', status: '', period: '' });
}
async function load() {
    const id = ++requestId;
    loading.value = true;
    error.value = '';
    try {
        // The dashboard summary contains only five rows. Use the complete owned list
        // so search, filters, sorting, and pagination cover all submitted reports.
        const response = await window.axios.get(`${apiBase}/reports`, { params: { status: 'all_submitted' } });
        if (id !== requestId) return;
        reports.value = (response.data.data || []).map((report) => ({
            ...report,
            incident_title: report.incident_name || report.incident_type || 'Incident',
        }));
    } catch (exception) {
        if (id === requestId) error.value = exception.response?.data?.message || 'Your reports could not be loaded. Try again.';
    } finally {
        if (id === requestId) loading.value = false;
    }
}
watch(search, (value) => { searchInput.value = value; });
onMounted(load);
onBeforeUnmount(() => { requestId += 1; });
</script>

<template>
    <section class="resident-card resident-dashboard-reports" aria-labelledby="resident-recent-heading">
        <header class="dashboard-reports-heading">
            <div><h3 id="resident-recent-heading">Recent reports</h3><p>Track your submissions and their latest progress.</p></div>
            <Button as="router-link" to="/reports" size="small" text>View all reports <MapIcon name="chevron" /></Button>
        </header>
        <div class="dashboard-reports-toolbar">
            <div class="dashboard-reports-search">
                <label for="dashboard-report-search">Search reports</label>
                <IconField>
                    <InputIcon><MapIcon name="search" /></InputIcon>
                    <InputText id="dashboard-report-search" ref="searchField" v-model="searchInput" size="small" type="search" placeholder="Reference, incident, or location"
                        @input="searchReports" @keydown.enter="searchReports" @compositionstart="composing = true" @compositionend="composing = false; searchReports()" />
                    <Button v-if="searchInput" size="small" text severity="secondary" class="dashboard-search-clear" aria-label="Clear report search" @click="clearSearch"><MapIcon name="x" /></Button>
                </IconField>
            </div>
            <div class="dashboard-reports-filter"><label for="dashboard-report-status">Status</label><Select input-id="dashboard-report-status" :model-value="status" :options="statuses" option-label="label" option-value="value" size="small" panel-class="resident-dashboard-select-panel" :pt="{ transition: { css: false } }" @update:model-value="commit({ status: $event })" /></div>
            <div class="dashboard-reports-filter"><label for="dashboard-report-period">Last updated</label><Select input-id="dashboard-report-period" :model-value="period" :options="periods" option-label="label" option-value="value" size="small" panel-class="resident-dashboard-select-panel" :pt="{ transition: { css: false } }" @update:model-value="commit({ period: $event })" /></div>
            <Button size="small" text severity="secondary" :disabled="!filtersActive" @click="resetFilters">Reset</Button>
        </div>
        <div class="dashboard-reports-caption"><span role="status">{{ loading ? 'Loading reports…' : `${filtered.length} ${filtered.length === 1 ? 'report' : 'reports'}${filtersActive ? ' found' : ' submitted'}` }}</span><span>Sort by clicking a column heading</span></div>
        <div v-if="loading" class="dashboard-table-state" role="status" aria-busy="true"><MapIcon name="reports" /><p>Getting your reports…</p></div>
        <div v-else-if="error" class="dashboard-table-state"><Message severity="error" :closable="false">{{ error }}</Message><Button size="small" severity="secondary" outlined @click="load">Retry</Button></div>
        <DataTable v-else :value="filtered" data-key="id" size="small" class="dashboard-reports-table" paginator :rows="rows" :first="first" :rows-per-page-options="[5, 10, 20]"
            :pt="{ pcPaginator: { pcRowPerPageDropdown: { overlay: { class: 'resident-dashboard-select-panel' } } } }"
            :sort-field="sortField" :sort-order="sortOrder" :table-props="{ 'aria-label': 'Recent submitted reports' }" :table-style="{ minWidth: '46rem' }"
            paginator-template="CurrentPageReport PrevPageLink PageLinks NextPageLink RowsPerPageDropdown" current-page-report-template="{first}–{last} of {totalRecords}"
            @sort="commit({ sort: $event.sortField, order: $event.sortOrder === 1 ? 'asc' : 'desc' })"
            @page="commit({ page: String($event.page + 1), rows: String($event.rows) })">
            <Column field="reference" header="Report reference" sortable><template #body="{ data: report }"><router-link :to="`/reports/${report.id}`" class="dashboard-report-reference">{{ report.reference || 'View report' }}</router-link></template></Column>
            <Column field="incident_title" header="Incident / location" sortable><template #body="{ data: report }"><div class="dashboard-report-incident"><strong>{{ report.incident_title }}</strong><span>{{ report.reported_location || 'Location not provided' }}</span></div></template></Column>
            <Column field="label" header="Status" sortable><template #body="{ data: report }"><StatusBadge :label="report.label" /></template></Column>
            <Column field="updated_at" header="Last updated" sortable><template #body="{ data: report }"><span class="dashboard-report-date">{{ formatWhen(report.updated_at) || '—' }}</span></template></Column>
            <Column header="Action"><template #body="{ data: report }"><Button as="router-link" :to="`/reports/${report.id}`" size="small" text :aria-label="`View report ${report.reference || ''}`">View <MapIcon name="chevron" /></Button></template></Column>
            <template #empty><div class="dashboard-table-state"><MapIcon :name="filtersActive ? 'search' : 'file'" /><strong>{{ filtersActive ? 'No matching reports' : 'No submitted reports yet' }}</strong><p>{{ filtersActive ? 'Try a different search or clear your filters.' : 'Your reports will appear here after you submit them.' }}</p><Button v-if="filtersActive" size="small" severity="secondary" outlined @click="resetFilters">Clear filters</Button></div></template>
        </DataTable>
    </section>
</template>
