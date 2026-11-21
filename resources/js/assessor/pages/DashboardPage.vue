<script setup>
import { computed, inject, onBeforeUnmount, onMounted, reactive, ref, useId, watch } from 'vue';
import { useRouter } from 'vue-router';
import Button from 'primevue/button';
import Column from 'primevue/column';
import DataTable from 'primevue/datatable';
import DatePicker from 'primevue/datepicker';
import Menu from 'primevue/menu';
import Message from 'primevue/message';
import Select from 'primevue/select';
import Skeleton from 'primevue/skeleton';
import Tag from 'primevue/tag';
import MapIcon from '../../shared/components/MapIcon.vue';
import { dateToIso, isoToDate } from '../../shared/ui/dates.js';
import '../../../css/rdana/dashboard.css';

const apiBase = inject('apiBase');
const router = useRouter();
const controlId = useId();
const overlay = 'assessor-dashboard-overlay';
const instant = { transition: { css: false } };
const error = ref('');
const loading = ref(true);
const payload = ref(null);
const registry = ref(null);
const actionError = ref('');
const startingReport = ref(null);
const rowMenu = ref(null);
const menuItems = ref([]);
const menuReference = ref(null);
const queuePage = ref(0);
let pendingRequest;

const defaultFilters = {
    incident_id: '',
    barangay_id: '',
    period_start: '',
    period_end: '',
    assessment_status: '',
};

const filters = reactive({ ...defaultFilters });
const workload = {
    review: { path: '/reports/barangay', label: 'Barangay reports' },
    drafts: { path: '/reports/drafts', label: 'Draft assessments' },
    returned: { path: '/reports/returned', label: 'Returned assessments' },
    submitted: { path: '/reports/submitted', label: 'Submitted assessments' },
};
const queueOptions = [
    { label: 'Barangay reports', value: '', path: workload.review.path },
    { label: 'Draft assessments', value: 'draft', path: workload.drafts.path },
    { label: 'Returned assessments', value: 'returned', path: workload.returned.path },
    { label: 'Submitted assessments', value: 'submitted', path: workload.submitted.path },
];
const statusSeverity = { review: 'info', draft: 'secondary', returned: 'contrast', submitted: 'success' };

const activeQueue = computed(() => queueOptions.find((option) => option.value === filters.assessment_status) || queueOptions[0]);
const incidentOptions = computed(() => [
    { label: 'Registry view', value: '' },
    ...(payload.value?.incidents || []).map((item) => ({ label: item.incident_name, value: String(item.id) })),
]);
const barangayOptions = computed(() => [
    { label: 'All assigned', value: '' },
    ...(payload.value?.barangays || []).map((item) => ({ label: item.name, value: String(item.id) })),
]);
const hasFilters = computed(() => Object.values(filters).some(Boolean));
const registryPath = computed(() => ({ path: '/residents', query: { barangay_id: filters.barangay_id || undefined } }));

onMounted(loadAssessor);
onBeforeUnmount(() => pendingRequest?.abort());
watch(filters, () => {
    queuePage.value = 0;
    loadAssessor();
});

async function loadAssessor() {
    pendingRequest?.abort();
    const controller = new AbortController();
    pendingRequest = controller;
    loading.value = true;
    error.value = '';
    actionError.value = '';
    const params = Object.fromEntries(Object.entries(filters).filter(([, value]) => value));
    const registryParams = {
        barangay_id: filters.barangay_id || undefined,
        incident_id: filters.incident_id || undefined,
    };
    try {
        const [dashboard, summary] = await Promise.all([
            window.axios.get(`${apiBase}/dashboard`, { params, signal: controller.signal }),
            window.axios.get(`${apiBase}/residents/summary`, { params: registryParams, signal: controller.signal }),
        ]);
        if (controller.signal.aborted) return;
        payload.value = dashboard.data;
        registry.value = summary.data;
        if (queuePage.value >= dashboard.data.review_queue.length) queuePage.value = 0;
    } catch (exception) {
        if (controller.signal.aborted) return;
        error.value = exception.response?.data?.message || 'The assessor dashboard could not be loaded.';
    } finally {
        if (!controller.signal.aborted) loading.value = false;
    }
}

function clearFilters() {
    Object.assign(filters, defaultFilters);
}

function formatCount(value) {
    if (value === null || value === undefined || value === '') return '—';
    return Number(value).toLocaleString('en-PH');
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
    }).format(date);
}

function detailHref(row) {
    return row.kind === 'field_assessment' ? `/assessments/${row.id}` : `/reports/barangay/${row.id}`;
}

function showRowMenu(event, row) {
    menuItems.value = [
        {
            label: 'Open linked location',
            icon: 'pin',
            command: () => router.push({
                path: '/map',
                query: { incident_id: row.incident_id || undefined, search: row.building_reference || undefined },
            }),
        },
        {
            label: 'Download PDF',
            icon: 'download',
            url: `/rdana${detailHref(row)}/preview?format=pdf`,
        },
    ];
    menuReference.value = row.reference;
    rowMenu.value.toggle(event);
}

async function startReview(row) {
    actionError.value = '';
    startingReport.value = row.id;
    try {
        const { data } = await window.axios.post(`${apiBase}/barangay-reports/${row.id}/start`);
        const id = data.data?.id;
        if (id) router.push(`/assessments/${id}`);
    } catch (exception) {
        actionError.value = exception.response?.data?.message || 'This report cannot be assessed from this account.';
    } finally {
        startingReport.value = null;
    }
}
</script>

<template>
    <section class="assessor-dashboard">
        <form class="assessor-dashboard-panel assessor-dashboard-filters" @submit.prevent="loadAssessor">
            <header class="assessor-dashboard-heading">
                <h2>Dashboard filters</h2>
                <div class="assessor-dashboard-actions">
                    <Button type="button" size="small" severity="secondary" text label="Refresh" :disabled="loading" @click="loadAssessor"><template #icon="{ class: iconClass }"><MapIcon :class="iconClass" name="refresh" /></template></Button>
                    <Button v-if="payload?.resume_draft" as="router-link" :to="`/assessments/${payload.resume_draft.id}`" size="small" severity="secondary" outlined label="Resume draft" />
                    <Button as="router-link" to="/map" size="small" label="Assessment map"><template #icon="{ class: iconClass }"><MapIcon :class="iconClass" name="map" /></template></Button>
                </div>
            </header>
            <div class="assessor-dashboard-fields">
                <div>
                    <label :id="`${controlId}-incident-label`" :for="`${controlId}-incident`">Incident</label>
                    <Select v-model="filters.incident_id" :input-id="`${controlId}-incident`" :aria-labelledby="`${controlId}-incident-label`" :options="incidentOptions" placeholder="Registry view" option-label="label" option-value="value" size="small" :panel-class="overlay" :pt="instant" />
                </div>
                <div>
                    <label :id="`${controlId}-barangay-label`" :for="`${controlId}-barangay`">Barangay</label>
                    <Select v-model="filters.barangay_id" :input-id="`${controlId}-barangay`" :aria-labelledby="`${controlId}-barangay-label`" :options="barangayOptions" placeholder="All assigned" option-label="label" option-value="value" size="small" :panel-class="overlay" :pt="instant" />
                </div>
                <div>
                    <label :for="`${controlId}-start`">Report period from</label>
                    <DatePicker :model-value="isoToDate(filters.period_start)" :input-id="`${controlId}-start`" :max-date="isoToDate(filters.period_end)" date-format="dd M yy" placeholder="Start date" show-icon icon-display="input" show-button-bar size="small" :panel-class="`${overlay} assessor-dashboard-calendar`" :pt="instant" @update:model-value="filters.period_start = dateToIso($event)" />
                </div>
                <div>
                    <label :for="`${controlId}-end`">Report period to</label>
                    <DatePicker :model-value="isoToDate(filters.period_end)" :input-id="`${controlId}-end`" :min-date="isoToDate(filters.period_start)" date-format="dd M yy" placeholder="End date" show-icon icon-display="input" show-button-bar size="small" :panel-class="`${overlay} assessor-dashboard-calendar`" :pt="instant" @update:model-value="filters.period_end = dateToIso($event)" />
                </div>
                <Button type="button" size="small" severity="secondary" text label="Clear filters" :disabled="!hasFilters" @click="clearFilters" />
            </div>
            <p class="assessor-dashboard-muted">{{ payload?.registry_note || 'The date range applies to report and assessment counts.' }}</p>
        </form>

        <Message v-if="error" severity="error" size="small" role="alert">
            {{ error }}
            <Button type="button" size="small" severity="danger" text label="Retry" @click="loadAssessor" />
        </Message>
        <div v-if="loading && !payload" class="assessor-dashboard-panel assessor-dashboard-loading" role="status">
            <span class="sr-only">Loading dashboard…</span>
            <Skeleton height="1rem" width="40%" />
            <Skeleton height="2.25rem" width="75%" />
            <Skeleton height="3rem" />
        </div>

        <template v-if="payload">
            <section class="assessor-dashboard-panel" aria-labelledby="assessor-workload-heading">
                <header class="assessor-dashboard-heading">
                    <h2 id="assessor-workload-heading">Assessment workload</h2>
                </header>
                <ul class="assessor-dashboard-metrics">
                    <li v-for="card in payload.cards" :key="card.key">
                        <RouterLink v-if="workload[card.key]" :to="workload[card.key].path" class="assessor-dashboard-metric">
                            <span>{{ workload[card.key].label }}</span>
                            <strong>{{ formatCount(card.count) }}</strong>
                            <MapIcon name="chevron" />
                        </RouterLink>
                    </li>
                </ul>
            </section>

            <section class="assessor-dashboard-panel assessor-dashboard-queue" aria-labelledby="assessor-queue-heading" :aria-busy="loading">
                <header class="assessor-dashboard-heading">
                    <div>
                        <h2 id="assessor-queue-heading">Review queue</h2>
                        <p v-if="!filters.assessment_status" class="assessor-dashboard-muted">Assessing a barangay report requires an active assignment to your account.</p>
                    </div>
                    <div class="assessor-dashboard-actions">
                        <Select v-model="filters.assessment_status" :options="queueOptions" placeholder="Barangay reports" option-label="label" option-value="value" aria-label="Queue to preview" size="small" :panel-class="overlay" :pt="instant" />
                        <Button as="router-link" :to="activeQueue.path" :aria-label="`View all ${activeQueue.label.toLowerCase()}`" size="small" severity="secondary" outlined label="View all" icon-pos="right"><template #icon="{ class: iconClass }"><MapIcon :class="iconClass" name="chevron" /></template></Button>
                    </div>
                </header>
                <Message v-if="actionError" severity="error" size="small" role="alert">{{ actionError }}</Message>
                <DataTable
                    v-model:first="queuePage"
                    :value="payload.review_queue"
                    :loading="loading"
                    size="small"
                    :paginator="payload.review_queue.length > 5"
                    :rows="5"
                    paginator-template="PrevPageLink CurrentPageReport NextPageLink"
                    current-page-report-template="{first}–{last} of {totalRecords}"
                    table-class="assessor-dashboard-queue-table"
                    :table-props="{ 'aria-label': activeQueue.label }"
                >
                    <Column header="Reference / incident">
                        <template #body="{ data }">
                            <div class="assessor-dashboard-cell">
                                <RouterLink :to="detailHref(data)">{{ data.reference }}</RouterLink>
                                <span>{{ data.incident || '—' }}</span>
                            </div>
                        </template>
                    </Column>
                    <Column header="Barangay / submitter">
                        <template #body="{ data }">
                            <div class="assessor-dashboard-cell">
                                <strong>{{ data.barangay || '—' }}</strong>
                                <span>{{ data.submitted_by || '—' }}</span>
                            </div>
                        </template>
                    </Column>
                    <Column :header="filters.assessment_status ? 'Updated' : 'Received'">
                        <template #body="{ data }"><time :datetime="data.received_at">{{ formatWhen(data.received_at) }}</time></template>
                    </Column>
                    <Column header="Status">
                        <template #body="{ data }"><Tag :value="data.label || data.status" :severity="statusSeverity[data.status] || 'secondary'" /></template>
                    </Column>
                    <Column :pt="{ columnHeaderContent: { class: 'sr-only' } }" header="Actions">
                        <template #body="{ data }">
                            <div class="assessor-dashboard-row-actions">
                                <Button v-if="data.draft_id" as="router-link" :to="`/assessments/${data.draft_id}`" size="small" severity="secondary" outlined label="Resume" :aria-label="`Resume assessment for ${data.reference}`" />
                                <Button v-else-if="data.kind === 'barangay_report'" type="button" size="small" severity="secondary" outlined label="Assess" :aria-label="`Assess report ${data.reference}`" :loading="startingReport === data.id" :disabled="startingReport !== null" @click="startReview(data)" />
                                <Button type="button" size="small" severity="secondary" text :aria-label="`More actions for ${data.reference}`" aria-haspopup="menu" :aria-expanded="menuReference === data.reference" :aria-controls="`${controlId}-report-actions`" @click="showRowMenu($event, data)"><template #icon="{ class: iconClass }"><MapIcon :class="iconClass" name="more" /></template></Button>
                            </div>
                        </template>
                    </Column>
                    <template #empty><p class="assessor-dashboard-empty">No reports match this queue.</p></template>
                </DataTable>
                <Menu :id="`${controlId}-report-actions`" ref="rowMenu" :model="menuItems" popup :class="overlay" :pt="instant" aria-label="Report actions" @hide="menuReference = null">
                    <template #itemicon="{ item }"><MapIcon :name="item.icon" /></template>
                </Menu>
            </section>

            <section class="assessor-dashboard-panel" aria-labelledby="assessor-registry-heading">
                <header class="assessor-dashboard-heading">
                    <h2 id="assessor-registry-heading">Registry summary</h2>
                    <Button as="router-link" :to="registryPath" size="small" severity="secondary" text label="Resident records" icon-pos="right"><template #icon="{ class: iconClass }"><MapIcon :class="iconClass" name="chevron" /></template></Button>
                </header>
                <dl class="assessor-dashboard-metrics">
                    <div class="assessor-dashboard-metric">
                        <dt>Registered residents</dt>
                        <dd>{{ formatCount(registry?.registered_residents) }}</dd>
                        <dd class="assessor-dashboard-muted">Active registry records</dd>
                    </div>
                    <div class="assessor-dashboard-metric">
                        <dt>Household population</dt>
                        <dd>{{ formatCount(registry?.total_household_size) }}</dd>
                        <dd class="assessor-dashboard-muted">{{ registry?.household_size_partial ? `${formatCount(registry.households_missing_size)} households have no reported size` : 'Reported size, once per household' }}</dd>
                    </div>
                    <div class="assessor-dashboard-metric">
                        <dt>Mapped buildings</dt>
                        <dd>{{ formatCount(payload.mapped_buildings) }}</dd>
                        <dd class="assessor-dashboard-muted">Records with a valid source location</dd>
                    </div>
                    <div class="assessor-dashboard-metric">
                        <dt>Potentially affected residents</dt>
                        <dd v-if="registry?.incident_required" class="assessor-dashboard-metric-prompt">Select an incident</dd>
                        <dd v-else>{{ formatCount(registry?.potentially_affected) }}</dd>
                        <dd class="assessor-dashboard-muted">Incident list, not confirmed harm</dd>
                    </div>
                </dl>
            </section>

            <div class="assessor-dashboard-support">
                <section class="assessor-dashboard-panel" aria-labelledby="assessor-overview-heading">
                    <header class="assessor-dashboard-heading">
                        <div>
                            <h2 id="assessor-overview-heading">Barangay overview</h2>
                            <p class="assessor-dashboard-muted">Totals across all assigned barangays.</p>
                        </div>
                    </header>
                    <DataTable :value="payload.overview" data-key="id" size="small" table-style="min-width: 29rem" :table-props="{ 'aria-labelledby': 'assessor-overview-heading' }" class="assessor-dashboard-overview">
                        <Column header="Barangay" field="name" />
                        <Column header="Residents"><template #body="{ data }">{{ formatCount(data.registered_residents) }}</template></Column>
                        <Column header="Household population">
                            <template #body="{ data }">
                                {{ formatCount(data.household_population) }}
                                <small v-if="data.households_missing_size">{{ formatCount(data.households_missing_size) }} missing a size</small>
                            </template>
                        </Column>
                        <Column header="Mapped buildings"><template #body="{ data }">{{ formatCount(data.mapped_buildings) }}</template></Column>
                        <Column header="Awaiting review"><template #body="{ data }">{{ formatCount(data.reports_awaiting_review) }}</template></Column>
                        <template #empty><p class="assessor-dashboard-empty">No barangays are assigned to this account.</p></template>
                    </DataTable>
                </section>

                <section class="assessor-dashboard-panel" aria-labelledby="assessor-activity-heading">
                    <header class="assessor-dashboard-heading"><h2 id="assessor-activity-heading">Recent assessment activity</h2></header>
                    <ul v-if="payload.recent_activity.length" class="assessor-dashboard-activity">
                        <li v-for="(item, index) in payload.recent_activity" :key="index">
                            <div class="assessor-dashboard-cell">
                                <strong>{{ item.reference }}</strong>
                                <span>{{ item.status }} · {{ item.barangay || 'Barangay not recorded' }}</span>
                            </div>
                            <time :datetime="item.updated_at">{{ formatWhen(item.updated_at) }}</time>
                        </li>
                    </ul>
                    <p v-else class="assessor-dashboard-empty">No recent assessments match the current filters.</p>
                </section>
            </div>
        </template>
    </section>
</template>
