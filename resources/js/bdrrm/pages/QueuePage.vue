<script setup>
import { computed, inject, onBeforeUnmount, onMounted, ref, watch } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import MapIcon from '../../shared/components/MapIcon.vue';

const apiBase = inject('apiBase');
const route = useRoute();
const router = useRouter();
const rows = ref([]);
const meta = ref({ current_page: 1, last_page: 1, per_page: 10, total: 0 });
const counts = ref(null);
const countsError = ref('');
const error = ref('');
const loading = ref(false);
const loadedOnce = ref(false);
const menuId = ref(null);
const search = ref('');
const incidentId = ref('');
const barangayId = ref('');
const stage = ref('');
const sort = ref('updated_at');
const direction = ref('desc');
const dateFrom = ref('');
const dateTo = ref('');
const page = ref(1);
const perPage = ref(10);
const incidents = ref([]);
const barangays = ref([]);
let requestId = 0;
let searchTimer;
let applying = false;
let syncing = false;
const ready = ref(false);

const queues = [
    { key: 'drafts', status: 'draft', label: 'Draft Reports', hint: 'Not submitted yet.', to: '/reports/drafts', icon: 'file-pen' },
    { key: 'returned', status: 'returned', label: 'Returned for Correction', hint: 'Updates requested.', to: '/reports/returned', icon: 'undo' },
    { key: 'submitted', status: 'handed-off', label: 'Submitted Reports', hint: 'Sent onward for review.', to: '/reports/submitted', icon: 'send' },
];

const current = computed(() => queues.find((queue) => queue.status === route.meta.status) || queues[0]);
const dateLabel = computed(() => {
    if (current.value.status === 'returned') return 'Returned date';
    if (current.value.status === 'handed-off') return 'Submitted date';
    return 'Last updated';
});
const dateFromKey = computed(() => {
    if (current.value.status === 'returned') return 'returned_from';
    if (current.value.status === 'handed-off') return 'submitted_from';
    return 'updated_from';
});
const dateToKey = computed(() => dateFromKey.value.replace('_from', '_to'));
const filtersActive = computed(() => Boolean(search.value.trim() || incidentId.value || barangayId.value || stage.value || dateFrom.value || dateTo.value || sort.value !== 'updated_at' || direction.value !== 'desc'));
const queueCountKey = computed(() => current.value.key);

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

function stamp(row) {
    if (current.value.status === 'returned') {
        return row.returned_at ? { label: 'Returned', value: formatWhen(row.returned_at) } : { label: 'Returned', value: 'Returned date is not recorded' };
    }
    if (current.value.status === 'handed-off') {
        return row.submitted_at ? { label: 'Submitted', value: formatWhen(row.submitted_at) } : { label: 'Submitted', value: 'Submitted date is not recorded' };
    }
    return { label: 'Last updated', value: row.updated_at ? formatWhen(row.updated_at) : 'Not recorded' };
}

function readRoute() {
    syncing = true;
    search.value = typeof route.query.q === 'string' ? route.query.q : '';
    incidentId.value = typeof route.query.incident_id === 'string' ? route.query.incident_id : '';
    barangayId.value = typeof route.query.barangay_id === 'string' ? route.query.barangay_id : '';
    stage.value = ['review', 'signed', 'submitted'].includes(route.query.stage) ? route.query.stage : '';
    sort.value = ['updated_at', 'submitted_at', 'returned_at', 'report_reference'].includes(route.query.sort) ? route.query.sort : 'updated_at';
    direction.value = route.query.direction === 'asc' ? 'asc' : 'desc';
    dateFrom.value = typeof route.query[dateFromKey.value] === 'string' ? route.query[dateFromKey.value] : '';
    dateTo.value = typeof route.query[dateToKey.value] === 'string' ? route.query[dateToKey.value] : '';
    page.value = Number.parseInt(route.query.page, 10) > 0 ? Number.parseInt(route.query.page, 10) : 1;
    perPage.value = [10, 25, 50].includes(Number(route.query.per_page)) ? Number(route.query.per_page) : 10;
    syncing = false;
}

function queryObject(nextPage = page.value) {
    const query = {};
    if (search.value.trim()) query.q = search.value.trim();
    if (incidentId.value) query.incident_id = incidentId.value;
    if (barangayId.value) query.barangay_id = barangayId.value;
    if (current.value.status === 'handed-off' && stage.value) query.stage = stage.value;
    if (sort.value !== 'updated_at') query.sort = sort.value;
    if (direction.value === 'asc') query.direction = 'asc';
    if (dateFrom.value) query[dateFromKey.value] = dateFrom.value;
    if (dateTo.value) query[dateToKey.value] = dateTo.value;
    if (nextPage > 1) query.page = String(nextPage);
    if (perPage.value !== 10) query.per_page = String(perPage.value);
    return query;
}

function pushQuery(resetPage = false) {
    const query = queryObject(resetPage ? 1 : page.value);
    const target = router.resolve({ path: route.path, query }).fullPath;
    if (target === route.fullPath) return;
    applying = true;
    router.replace({ path: route.path, query });
}

async function loadCounts() {
    try {
        const { data } = await window.axios.get(`${apiBase}/navigation`);
        counts.value = data;
        countsError.value = '';
    } catch {
        countsError.value = 'Queue counts could not be loaded.';
    }
}

async function loadOptions() {
    try {
        const summary = await window.axios.get(`${apiBase}/summary`);
        barangays.value = summary.data.barangays || [];
        incidents.value = (await window.axios.get(`${apiBase}/incidents`)).data.data || [];
    } catch {
        barangays.value = [];
        incidents.value = [];
    }
}

async function load() {
    const ticket = ++requestId;
    loading.value = true;
    try {
        const params = {
            status: current.value.status === 'handed-off' && stage.value ? stage.value : current.value.status,
            per_page: perPage.value,
            page: page.value,
            sort: sort.value,
            direction: direction.value,
        };
        if (search.value.trim()) params.q = search.value.trim();
        if (incidentId.value) params.incident_id = incidentId.value;
        if (barangayId.value) params.barangay_id = barangayId.value;
        if (dateFrom.value) params[dateFromKey.value] = dateFrom.value;
        if (dateTo.value) params[dateToKey.value] = dateTo.value;
        const { data } = await window.axios.get(`${apiBase}/reports`, { params });
        if (ticket !== requestId) return;
        rows.value = data.data || [];
        meta.value = data.meta || meta.value;
        error.value = '';
        loadedOnce.value = true;
    } catch (exception) {
        if (ticket !== requestId) return;
        error.value = exception.response?.data?.message || 'The report list could not be loaded.';
    } finally {
        if (ticket === requestId) loading.value = false;
    }
}

function openReport(row, panel) {
    menuId.value = null;
    const query = { from: route.fullPath };
    if (panel) query.panel = panel;
    const readOnly = !['draft', 'returned'].includes(row.status);
    router.push({ path: readOnly ? `/reports/${row.id}/view` : `/reports/${row.id}`, query });
}

function previewHref(row) {
    return `/bdrrm/reports/${row.id}/preview`;
}

function clearFilters() {
    search.value = '';
    incidentId.value = '';
    barangayId.value = '';
    stage.value = '';
    sort.value = 'updated_at';
    direction.value = 'desc';
    dateFrom.value = '';
    dateTo.value = '';
    pushQuery(true);
}

function onDocumentPointer(event) {
    if (!event.target.closest('.report-actions')) menuId.value = null;
}

function onDocumentKey(event) {
    if (event.key === 'Escape') menuId.value = null;
}

watch(search, (value, previous) => {
    if (!ready.value || syncing || value === previous) return;
    clearTimeout(searchTimer);
    searchTimer = setTimeout(() => pushQuery(true), 300);
});

watch(() => route.fullPath, async () => {
    if (!route.path.startsWith('/reports/')) return;
    if (applying) {
        applying = false;
    }
    readRoute();
    await load();
});

onMounted(async () => {
    document.addEventListener('pointerdown', onDocumentPointer);
    document.addEventListener('keydown', onDocumentKey);
    readRoute();
    ready.value = true;
    await Promise.all([loadOptions(), loadCounts(), load()]);
});

onBeforeUnmount(() => {
    clearTimeout(searchTimer);
    document.removeEventListener('pointerdown', onDocumentPointer);
    document.removeEventListener('keydown', onDocumentKey);
});
</script>

<template>
    <section class="bdrrm-reports">
        <header class="report-page-heading">
            <div class="report-page-title">
                <span class="report-mark"><MapIcon :name="current.icon" /></span>
                <div>
                    <h2>{{ route.meta.title }}</h2>
                    <p v-if="current.status === 'draft'">Continue preparing reports that have not been submitted.</p>
                    <p v-else-if="current.status === 'returned'">Review feedback and update reports returned for correction.</p>
                    <p v-else>View submitted reports and follow their recorded review status.</p>
                </div>
            </div>
            <router-link class="report-primary" to="/reports/new"><MapIcon name="plus" /> Create Report</router-link>
        </header>

        <div class="report-summary-row">
            <router-link v-for="queue in queues" :key="queue.key" class="report-summary-card" :class="{ 'is-active': queue.status === current.status }" :to="queue.to">
                <span class="report-meta"><MapIcon :name="queue.icon" /> {{ queue.label }}</span>
                <strong v-if="counts">{{ counts[queue.key] }}</strong>
                <strong v-else>—</strong>
                <span class="report-note">{{ queue.hint }}</span>
            </router-link>
        </div>
        <p v-if="countsError" class="cdrrmo-error" role="alert">
            {{ countsError }}
            <button type="button" @click="loadCounts">Retry</button>
        </p>

        <form class="report-filter-card" @submit.prevent="pushQuery(true)">
            <label class="report-search">
                <span>Search</span>
                <span class="report-search-field">
                    <MapIcon name="search" />
                    <input v-model="search" type="search" maxlength="80" autocomplete="off" placeholder="Reference, incident, or barangay" aria-label="Search reports">
                </span>
            </label>
            <label>
                <span>Incident</span>
                <select v-model="incidentId" aria-label="Incident" @change="pushQuery(true)">
                    <option value="">All incidents</option>
                    <option v-for="incident in incidents" :key="incident.id" :value="String(incident.id)">{{ incident.incident_name }}</option>
                </select>
            </label>
            <label v-if="barangays.length > 1">
                <span>Barangay</span>
                <select v-model="barangayId" aria-label="Barangay" @change="pushQuery(true)">
                    <option value="">All assigned barangays</option>
                    <option v-for="barangay in barangays" :key="barangay.id" :value="String(barangay.id)">{{ barangay.name }}</option>
                </select>
            </label>
            <label v-if="current.status === 'handed-off'">
                <span>Review status</span>
                <select v-model="stage" aria-label="Review status" @change="pushQuery(true)">
                    <option value="">All submitted reports</option>
                    <option value="review">Awaiting the next office</option>
                    <option value="signed">Signed</option>
                    <option value="submitted">Submitted</option>
                </select>
            </label>
            <label>
                <span>Sort</span>
                <select v-model="sort" aria-label="Sort" @change="pushQuery(true)">
                    <option value="updated_at">Last updated</option>
                    <option v-if="current.status === 'returned'" value="returned_at">Returned date</option>
                    <option v-if="current.status === 'handed-off'" value="submitted_at">Submitted date</option>
                    <option value="report_reference">Reference</option>
                </select>
            </label>
            <label>
                <span class="visually-hidden">Sort direction</span>
                <select v-model="direction" aria-label="Sort direction" @change="pushQuery(true)">
                    <option value="desc">Newest first</option>
                    <option value="asc">Oldest first</option>
                </select>
            </label>
            <details class="report-dates">
                <summary><MapIcon name="filter-reset" /> {{ dateLabel }}</summary>
                <div class="report-actions-row">
                    <label>
                        <span>From</span>
                        <input v-model="dateFrom" type="date" :aria-label="`${dateLabel} from`" @change="pushQuery(true)">
                    </label>
                    <label>
                        <span>To</span>
                        <input v-model="dateTo" type="date" :aria-label="`${dateLabel} to`" @change="pushQuery(true)">
                    </label>
                </div>
            </details>
            <button v-if="filtersActive" type="button" class="report-quiet" @click="clearFilters">Clear filters</button>
        </form>

        <p v-if="loadedOnce" class="report-note">
            {{ meta.total }} matching {{ meta.total === 1 ? 'report' : 'reports' }}.
            <template v-if="filtersActive && counts">{{ counts[queueCountKey] }} in this queue before filters.</template>
        </p>

        <div v-if="loading && !rows.length" class="report-list" aria-busy="true">
            <p class="visually-hidden">Loading reports…</p>
            <article v-for="slot in 3" :key="slot" class="report-card report-skeleton" aria-hidden="true">
                <span></span>
                <span></span>
                <span></span>
            </article>
        </div>
        <p v-else-if="error && !rows.length" class="cdrrmo-error" role="alert">
            {{ error }}
            <button type="button" @click="load">Retry</button>
        </p>
        <template v-else>
            <p v-if="error" class="cdrrmo-error" role="alert">
                {{ error }}
                <button type="button" @click="load">Retry</button>
            </p>
            <div v-if="!rows.length" class="report-section">
                <template v-if="filtersActive">
                    <h3>No matching reports</h3>
                    <p class="report-note">No reports match the current search or filters.</p>
                    <button type="button" @click="clearFilters">Clear filters</button>
                </template>
                <template v-else-if="current.status === 'returned'">
                    <h3>No reports need correction.</h3>
                    <p class="report-note">Reports returned by reviewers will appear here with their feedback.</p>
                    <div class="report-actions-row">
                        <router-link class="report-quiet" to="/reports/drafts">View Drafts</router-link>
                        <router-link class="report-quiet" to="/reports/submitted">Submitted Reports</router-link>
                    </div>
                </template>
                <template v-else-if="current.status === 'handed-off'">
                    <h3>No submitted reports</h3>
                    <p class="report-note">Reports sent for review will appear here with their recorded status.</p>
                    <div class="report-actions-row">
                        <router-link class="report-primary" to="/reports/new"><MapIcon name="plus" /> Create Report</router-link>
                        <router-link class="report-quiet" to="/reports/drafts">View Drafts</router-link>
                    </div>
                </template>
                <template v-else>
                    <h3>No draft reports</h3>
                    <p class="report-note">Reports you save will appear here until they are submitted.</p>
                    <router-link class="report-primary" to="/reports/new"><MapIcon name="plus" /> Create Report</router-link>
                </template>
            </div>
            <div v-else class="report-list" :aria-busy="loading">
                <article v-for="row in rows" :key="row.id" class="report-card" :class="{ 'is-returned': row.status === 'returned' }">
                    <div class="report-card-top">
                        <div class="report-identity">
                            <span class="report-mark"><MapIcon name="file" /></span>
                            <div>
                                <h3>{{ row.reference }}</h3>
                                <p>{{ row.incident_name || 'Incident not recorded' }}</p>
                                <p class="report-meta">
                                    <span><MapIcon name="pin" /> {{ row.barangay_name || 'Barangay not recorded' }}</span>
                                    <span><MapIcon :name="current.status === 'draft' ? 'clock' : 'calendar'" /> {{ stamp(row).label }}: {{ stamp(row).value }}</span>
                                    <span v-if="row.resident_reference"><MapIcon name="link" /> {{ row.resident_reference }}</span>
                                    <span v-if="row.signature_recorded"><MapIcon name="pen" /> Signature recorded</span>
                                </p>
                                <p v-if="row.description">{{ row.description }}</p>
                                <p v-if="row.status === 'returned'">{{ row.return_reason || 'Correction feedback is not available.' }}</p>
                            </div>
                        </div>
                        <span class="report-badge" :data-status="row.status">{{ row.label }}</span>
                    </div>
                    <div class="report-actions">
                        <button v-if="row.status === 'draft'" type="button" class="report-primary" @click="openReport(row)"><MapIcon name="pencil" /> Continue Editing</button>
                        <button v-else-if="row.status === 'returned'" type="button" class="report-primary" @click="openReport(row)"><MapIcon name="undo" /> Review and Correct</button>
                        <button v-else type="button" class="report-primary" @click="openReport(row)"><MapIcon name="eye" /> View Report</button>
                        <button type="button" :aria-expanded="menuId === row.id" aria-label="More actions" @click="menuId = menuId === row.id ? null : row.id"><MapIcon name="more" /></button>
                        <div v-if="menuId === row.id" class="report-menu" role="menu">
                            <a role="menuitem" :href="previewHref(row)"><MapIcon name="eye" /> Preview Report</a>
                            <a role="menuitem" :href="`${previewHref(row)}?format=pdf`"><MapIcon name="download" /> Download PDF</a>
                            <button type="button" role="menuitem" @click="openReport(row, 'history')"><MapIcon name="history" /> View Review History</button>
                        </div>
                    </div>
                </article>
            </div>
            <div v-if="meta.last_page > 1" class="report-pager">
                <button type="button" :disabled="page <= 1" @click="page -= 1; pushQuery(false)">Previous</button>
                <span>Page {{ meta.current_page }} of {{ meta.last_page }}</span>
                <button type="button" :disabled="page >= meta.last_page" @click="page += 1; pushQuery(false)">Next</button>
                <label>
                    <span class="visually-hidden">Rows per page</span>
                    <select v-model.number="perPage" aria-label="Rows per page" @change="pushQuery(true)">
                        <option :value="10">10</option>
                        <option :value="25">25</option>
                        <option :value="50">50</option>
                    </select>
                </label>
            </div>
        </template>
    </section>
</template>
