<script setup>
import { computed, inject, nextTick, onBeforeUnmount, ref, watch } from 'vue';
import { RouterLink, useRoute, useRouter } from 'vue-router';
import mapboxgl from 'mapbox-gl';
import Button from 'primevue/button';
import Column from 'primevue/column';
import DataTable from 'primevue/datatable';
import Drawer from 'primevue/drawer';
import IconField from 'primevue/iconfield';
import InputIcon from 'primevue/inputicon';
import InputText from 'primevue/inputtext';
import Paginator from 'primevue/paginator';
import Select from 'primevue/select';
import Skeleton from 'primevue/skeleton';
import Tag from 'primevue/tag';
import MapIcon from '../../shared/components/MapIcon.vue';
import '../../../css/rdana/buildings.css';

const apiBase = inject('apiBase');
const mapboxToken = inject('mapboxToken');
const mapboxStyle = inject('mapboxStyle');
const route = useRoute();
const router = useRouter();

const rows = ref([]);
const meta = ref({ current_page: 1, last_page: 1, per_page: 10, total: 0, authorized_total: 0 });
const summary = ref({ total: 0, with_recorded_location: 0, with_households: 0, with_reports: 0, authorized_total: 0 });
const barangays = ref([]);
const search = ref('');
const barangayId = ref('');
const location = ref('');
const verification = ref('');
const households = ref('');
const reports = ref('');
const sort = ref('reference');
const page = ref(1);
const perPage = ref(10);
const phase = ref('loading');
const refreshing = ref(false);
const error = ref('');
const hasLoaded = ref(false);
const detail = ref(null);
const detailHouseholds = ref([]);
const detailError = ref('');
const detailLoading = ref(false);
const showMoreFilters = ref(false);
const registrySearch = ref(null);
const miniMapEl = ref(null);

let applying = false;
let requestTicket = 0;
let detailTicket = 0;
let searchTimer;
let detailTrigger = null;
let miniMap = null;

const filtersActive = computed(() => search.value.trim() !== ''
    || barangayId.value !== ''
    || location.value !== ''
    || verification.value !== ''
    || households.value !== ''
    || reports.value !== ''
    || sort.value === 'newest');
const rangeStart = computed(() => (meta.value.total === 0 ? 0 : ((meta.value.current_page - 1) * meta.value.per_page) + 1));
const rangeEnd = computed(() => Math.min(meta.value.current_page * meta.value.per_page, meta.value.total));
const buildingId = computed(() => typeof route.query.building === 'string' ? route.query.building : '');
const scopeLabel = computed(() => barangays.value.length === 1 ? barangays.value[0].name : 'Assigned barangays');
const barangayOptions = computed(() => [
    { value: '', label: 'All assigned barangays' },
    ...barangays.value.map(row => ({ value: String(row.id), label: row.name })),
]);
const extraFilterCount = computed(() => [verification.value, households.value, reports.value, sort.value === 'newest'].filter(Boolean).length);
const noAssignment = computed(() => phase.value === 'ready' && barangays.value.length === 0);
const noBuildings = computed(() => phase.value === 'ready' && barangays.value.length > 0 && summary.value.authorized_total === 0);
const noMatches = computed(() => phase.value === 'ready' && summary.value.authorized_total > 0 && meta.value.total === 0);

const locationLabels = { unverified: 'Unverified', verified: 'Verified', rejected: 'Rejected' };
const locationSeverity = { unverified: 'secondary', verified: 'success', rejected: 'danger' };
const locationOptions = [
    { value: '', label: 'All locations' },
    { value: 'recorded', label: 'Recorded point' },
    { value: 'missing', label: 'No recorded point' },
];
const verificationOptions = [
    { value: '', label: 'All statuses' },
    ...Object.entries(locationLabels).map(([value, label]) => ({ value, label })),
];
const householdOptions = [
    { value: '', label: 'All buildings' },
    { value: 'with', label: 'With linked households' },
    { value: 'without', label: 'Without linked households' },
];
const reportOptions = [
    { value: '', label: 'All buildings' },
    { value: 'with', label: 'With linked reports' },
    { value: 'without', label: 'Without linked reports' },
];
const sortOptions = [
    { value: 'reference', label: 'Reference' },
    { value: 'newest', label: 'Newest' },
];
const reportLabels = {
    submitted: 'Submitted',
    under_review: 'Under review',
    reviewed: 'Accepted',
    returned: 'Returned',
    review: 'In review',
    signed: 'Signed',
    draft: 'Draft',
};

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

function statusLabel(value) {
    return locationLabels[value] || 'Unverified';
}

function reportLabel(value) {
    return reportLabels[value] || value || 'Report';
}

function locationNote(record) {
    if (record?.map_available) return 'Point from the source registry. See the verification status above.';
    if (record?.map_unavailable_reason === 'invalid_geometry') return 'The stored coordinates are not a valid study-area point.';
    return 'This building has no usable recorded point.';
}

function readRoute() {
    search.value = typeof route.query.q === 'string' ? route.query.q : '';
    barangayId.value = typeof route.query.barangay_id === 'string' ? route.query.barangay_id : '';
    location.value = ['recorded', 'missing'].includes(route.query.location) ? route.query.location : '';
    verification.value = ['unverified', 'verified', 'rejected'].includes(route.query.verification) ? route.query.verification : '';
    households.value = ['with', 'without'].includes(route.query.households) ? route.query.households : '';
    reports.value = ['with', 'without'].includes(route.query.reports) ? route.query.reports : '';
    sort.value = route.query.sort === 'newest' ? 'newest' : 'reference';
    const nextPage = Number(route.query.page);
    page.value = Number.isInteger(nextPage) && nextPage > 0 ? nextPage : 1;
    const nextPer = Number(route.query.per_page);
    perPage.value = [10, 25, 50].includes(nextPer) ? nextPer : 10;
}

function queryFromState() {
    const query = {};
    if (search.value.trim()) query.q = search.value.trim();
    if (barangayId.value) query.barangay_id = String(barangayId.value);
    if (location.value) query.location = location.value;
    if (verification.value) query.verification = verification.value;
    if (households.value) query.households = households.value;
    if (reports.value) query.reports = reports.value;
    if (sort.value === 'newest') query.sort = 'newest';
    if (page.value > 1) query.page = String(page.value);
    if (perPage.value !== 10) query.per_page = String(perPage.value);
    if (typeof route.query.building === 'string' && route.query.building !== '') query.building = route.query.building;
    return query;
}

function listParams() {
    const params = { page: page.value, per_page: perPage.value, sort: sort.value };
    if (search.value.trim()) params.q = search.value.trim();
    if (barangayId.value) params.barangay_id = barangayId.value;
    if (location.value) params.location = location.value;
    if (verification.value) params.verification = verification.value;
    if (households.value) params.households = households.value;
    if (reports.value) params.reports = reports.value;
    return params;
}

async function load() {
    const ticket = ++requestTicket;
    if (!hasLoaded.value) phase.value = 'loading';
    else refreshing.value = true;
    try {
        const { data } = await window.axios.get(`${apiBase}/buildings`, { params: listParams() });
        if (ticket !== requestTicket) return;
        if (data.meta?.total && data.meta.current_page > data.meta.last_page) {
            page.value = data.meta.last_page;
            await pushQuery(false);
            return;
        }
        rows.value = data.data || [];
        meta.value = data.meta || meta.value;
        summary.value = data.summary || summary.value;
        barangays.value = data.barangays || [];
        hasLoaded.value = true;
        phase.value = 'ready';
        error.value = '';
    } catch (exception) {
        if (ticket !== requestTicket) return;
        error.value = exception.response?.data?.message || 'The building registry could not be loaded.';
        if (!hasLoaded.value) phase.value = 'error';
    } finally {
        if (ticket === requestTicket) refreshing.value = false;
    }
}

async function pushQuery(resetPage) {
    clearTimeout(searchTimer);
    if (resetPage) page.value = 1;
    const query = queryFromState();
    const target = router.resolve({ path: '/buildings', query }).fullPath;
    if (route.fullPath === target) {
        await load();
        return;
    }
    applying = true;
    await router.replace({ path: '/buildings', query });
    applying = false;
    readRoute();
    await load();
}

function onSearchInput() {
    clearTimeout(searchTimer);
    searchTimer = setTimeout(() => pushQuery(true), 300);
}

function clearFilters() {
    search.value = '';
    barangayId.value = '';
    location.value = '';
    verification.value = '';
    households.value = '';
    reports.value = '';
    sort.value = 'reference';
    registrySearch.value?.$el.focus();
    pushQuery(true);
}

function changePage(event) {
    page.value = event.rows === perPage.value ? event.page + 1 : 1;
    perPage.value = event.rows;
    pushQuery(false);
}

function toggleMetric(key, value) {
    if (key === 'location') location.value = location.value === value ? '' : value;
    if (key === 'households') households.value = households.value === value ? '' : value;
    if (key === 'reports') reports.value = reports.value === value ? '' : value;
    pushQuery(true);
}

function mapTarget(id) {
    return { path: '/map', query: { building_id: String(id) } };
}

function openBuilding(id, event) {
    detailTrigger = event.currentTarget;
    router.replace({ path: '/buildings', query: { ...route.query, building: String(id) } });
}

function closeDetail() {
    const query = { ...route.query };
    delete query.building;
    router.replace({ path: '/buildings', query });
}

function restoreDetailFocus() {
    if (detailTrigger?.isConnected) detailTrigger.focus();
    else registrySearch.value?.$el.focus();
    detailTrigger = null;
}

watch(buildingId, (id) => {
    if (id) {
        loadDetail(id);
    } else {
        detailTicket++;
        detail.value = null;
        detailHouseholds.value = [];
        detailError.value = '';
        detailLoading.value = false;
        destroyMiniMap();
    }
}, { immediate: true });

async function loadDetail(id) {
    const ticket = ++detailTicket;
    detailLoading.value = true;
    detailError.value = '';
    destroyMiniMap();
    try {
        const [buildingRes, householdRes] = await Promise.all([
            window.axios.get(`${apiBase}/buildings/${id}`),
            window.axios.get(`${apiBase}/buildings/${id}/households`),
        ]);
        if (ticket !== detailTicket) return;
        detail.value = buildingRes.data;
        detailHouseholds.value = householdRes.data?.data || [];
        detailLoading.value = false;
        await nextTick();
        try {
            mountMiniMap();
        } catch {
            destroyMiniMap();
        }
    } catch (exception) {
        if (ticket !== detailTicket) return;
        detail.value = null;
        detailHouseholds.value = [];
        destroyMiniMap();
        const status = exception.response?.status;
        detailError.value = status === 403
            ? 'That record is outside your assignment.'
            : 'This building record could not be loaded.';
    } finally {
        if (ticket === detailTicket) detailLoading.value = false;
    }
}

function destroyMiniMap() {
    miniMap?.remove();
    miniMap = null;
}

function mountMiniMap() {
    destroyMiniMap();
    if (!miniMapEl.value || detail.value?.longitude == null || detail.value?.latitude == null || !mapboxToken) return;
    mapboxgl.accessToken = mapboxToken;
    const center = [Number(detail.value.longitude), Number(detail.value.latitude)];
    miniMap = new mapboxgl.Map({
        container: miniMapEl.value,
        style: mapboxStyle || 'mapbox://styles/mapbox/streets-v12',
        center,
        zoom: 16,
        interactive: false,
        attributionControl: true,
    });
    miniMap.on('load', () => {
        if (!miniMap) return;
        new mapboxgl.Marker({ color: getComputedStyle(miniMapEl.value).getPropertyValue('--p-blue-900').trim() }).setLngLat(center).addTo(miniMap);
    });
}

watch(() => {
    const { building, ...filters } = route.query;
    return route.path === '/buildings' ? JSON.stringify(filters) : null;
}, () => {
    if (route.path !== '/buildings' || applying) return;
    readRoute();
    load();
}, { immediate: true });

onBeforeUnmount(() => {
    clearTimeout(searchTimer);
    requestTicket++;
    detailTicket++;
    destroyMiniMap();
});
</script>

<template>
    <section class="rdana-buildings" :aria-busy="phase === 'loading' || refreshing">
        <div v-if="phase === 'ready' && !noAssignment" class="building-snapshot">
            <div class="building-snapshot-heading">
                <h2>{{ scopeLabel }}</h2>
                <p>Registry counts do not measure disaster impact.</p>
            </div>
            <div class="building-metrics" aria-label="Registry totals">
                <div class="building-metric">
                    <strong>{{ summary.total.toLocaleString() }}</strong>
                    <span>{{ filtersActive ? 'Matching buildings' : 'Registered buildings' }}</span>
                </div>
                <Button type="button" size="small" text severity="secondary" class="building-metric"
                    :aria-pressed="location === 'recorded'" @click="toggleMetric('location', 'recorded')">
                    <strong>{{ summary.with_recorded_location.toLocaleString() }}</strong>
                    <span>Recorded points</span>
                </Button>
                <Button type="button" size="small" text severity="secondary" class="building-metric"
                    :aria-pressed="households === 'with'" @click="toggleMetric('households', 'with')">
                    <strong>{{ summary.with_households.toLocaleString() }}</strong>
                    <span>With households</span>
                </Button>
                <Button type="button" size="small" text severity="secondary" class="building-metric"
                    :aria-pressed="reports === 'with'" @click="toggleMetric('reports', 'with')">
                    <strong>{{ summary.with_reports.toLocaleString() }}</strong>
                    <span>With reports</span>
                </Button>
            </div>
        </div>

        <section class="building-registry" aria-label="Building registry">
            <form v-if="!noAssignment" class="building-filters" aria-label="Filter building records" @submit.prevent="pushQuery(true)">
                <div class="building-filter-row">
                    <div class="building-field building-search">
                        <label for="building-search">Search</label>
                        <IconField>
                            <InputIcon><MapIcon name="search" /></InputIcon>
                            <InputText id="building-search" ref="registrySearch" v-model="search" type="search"
                                size="small" maxlength="80" autocomplete="off" placeholder="Reference or address"
                                @update:model-value="onSearchInput" />
                        </IconField>
                    </div>
                    <div v-if="barangays.length > 1" class="building-field">
                        <label id="building-barangay-label" for="building-barangay">Barangay</label>
                        <Select v-model="barangayId" input-id="building-barangay" aria-labelledby="building-barangay-label" placeholder="All assigned barangays" :options="barangayOptions" size="small"
                            option-label="label" option-value="value" overlay-class="building-select-overlay" @change="pushQuery(true)" />
                    </div>
                    <div class="building-field">
                        <label id="building-location-label" for="building-location">Location</label>
                        <Select v-model="location" input-id="building-location" aria-labelledby="building-location-label" placeholder="All locations" :options="locationOptions" size="small"
                            option-label="label" option-value="value" overlay-class="building-select-overlay" @change="pushQuery(true)" />
                    </div>
                    <div class="building-filter-actions">
                        <Button type="button" size="small" outlined severity="secondary"
                            :label="extraFilterCount ? `More filters (${extraFilterCount})` : 'More filters'"
                            :aria-expanded="showMoreFilters" aria-controls="building-extra-filters" @click="showMoreFilters = !showMoreFilters">
                            <template #icon><MapIcon name="list-filter" /></template>
                        </Button>
                        <Button v-if="filtersActive" type="button" size="small" text severity="secondary"
                            label="Clear filters" @click="clearFilters" />
                    </div>
                </div>
                <div v-show="showMoreFilters" id="building-extra-filters" class="building-filter-row building-extra-filters">
                    <div class="building-field">
                        <label id="building-verification-label" for="building-verification">Verification</label>
                        <Select v-model="verification" input-id="building-verification" aria-labelledby="building-verification-label" placeholder="All statuses" :options="verificationOptions" size="small"
                            option-label="label" option-value="value" overlay-class="building-select-overlay" @change="pushQuery(true)" />
                    </div>
                    <div class="building-field">
                        <label id="building-households-label" for="building-households">Households</label>
                        <Select v-model="households" input-id="building-households" aria-labelledby="building-households-label" placeholder="All buildings" :options="householdOptions" size="small"
                            option-label="label" option-value="value" overlay-class="building-select-overlay" @change="pushQuery(true)" />
                    </div>
                    <div class="building-field">
                        <label id="building-reports-label" for="building-reports">Reports</label>
                        <Select v-model="reports" input-id="building-reports" aria-labelledby="building-reports-label" placeholder="All buildings" :options="reportOptions" size="small"
                            option-label="label" option-value="value" overlay-class="building-select-overlay" @change="pushQuery(true)" />
                    </div>
                    <div class="building-field">
                        <label id="building-sort-label" for="building-sort">Sort by</label>
                        <Select v-model="sort" input-id="building-sort" aria-labelledby="building-sort-label" placeholder="Reference" :options="sortOptions" size="small"
                            option-label="label" option-value="value" overlay-class="building-select-overlay" @change="pushQuery(true)" />
                    </div>
                </div>
            </form>

            <div v-if="error" class="building-state building-alert" role="alert">
                <p>{{ error }}</p>
                <Button type="button" size="small" outlined severity="secondary" label="Retry" @click="load" />
            </div>
            <div v-if="phase === 'loading'" class="building-state building-loading" role="status" aria-label="Loading buildings">
                <Skeleton v-for="line in 3" :key="line" height="1.5rem" aria-hidden="true" />
            </div>
            <div v-else-if="noAssignment || noBuildings || noMatches" class="building-state" role="status">
                <h2>{{ noAssignment ? 'No active barangay assignment' : noBuildings ? 'No registered buildings' : 'No matching buildings' }}</h2>
                <p v-if="noBuildings">Your assigned barangays have no buildings in the registry.</p>
                <Button v-if="noMatches" type="button" size="small" text severity="secondary" label="Reset filters" @click="clearFilters" />
            </div>
            <template v-else-if="phase === 'ready'">
                <DataTable :value="rows" data-key="id" size="small" class="building-table"
                    :pt="{ table: { 'aria-label': 'Buildings' } }">
                    <Column field="reference" header="Building">
                        <template #body="{ data }">
                            <Button type="button" text size="small" severity="secondary" class="building-reference"
                                :label="data.reference" aria-haspopup="dialog" @click="openBuilding(data.id, $event)" />
                            <span class="building-sub building-mobile-counts">
                                {{ data.household_count }} {{ data.household_count === 1 ? 'household' : 'households' }}
                                · {{ data.report_count }} {{ data.report_count === 1 ? 'report' : 'reports' }}
                            </span>
                        </template>
                    </Column>
                    <Column field="barangay_name">
                        <template #header>Barangay<span class="building-address-label"> / address</span></template>
                        <template #body="{ data }">
                            <strong>{{ data.barangay_name || 'Assigned barangay' }}</strong>
                            <span class="building-sub">{{ data.address || 'No recorded address' }}</span>
                        </template>
                    </Column>
                    <Column field="location_status" header="Location" body-class="building-location-cell">
                        <template #body="{ data }">
                            <Tag :value="statusLabel(data.location_status)" :severity="locationSeverity[data.location_status] || 'secondary'" />
                            <span class="building-sub">{{ data.location_recorded ? 'Recorded point' : 'No recorded point' }}</span>
                        </template>
                    </Column>
                    <Column field="household_count" header="Households" header-class="building-number" body-class="building-number" />
                    <Column field="report_count" header="Reports" header-class="building-number" body-class="building-number" />
                    <Column header="Map" header-class="building-map-col" body-class="building-map-col">
                        <template #body="{ data }">
                            <Button v-if="data.map_available" :as="RouterLink" :to="mapTarget(data.id)" size="small"
                                text severity="secondary" label="View on map"
                                :aria-label="`View ${data.reference} on map`">
                                <template #icon><MapIcon name="pin" /></template>
                            </Button>
                            <span v-else class="building-sub" aria-label="No map location">—</span>
                        </template>
                    </Column>
                </DataTable>
                <footer class="building-footer">
                    <p aria-live="polite">
                        {{ rangeStart.toLocaleString() }}–{{ rangeEnd.toLocaleString() }} of {{ meta.total.toLocaleString() }}
                        {{ meta.total === 1 ? 'building' : 'buildings' }}
                        <template v-if="filtersActive && meta.total !== summary.authorized_total">
                            <span class="building-sub building-scope-total"> · {{ summary.authorized_total.toLocaleString() }} in assignment</span>
                        </template>
                    </p>
                    <Paginator v-if="meta.total > 10 || perPage !== 10"
                        :first="rangeStart - 1" :rows="meta.per_page" :total-records="meta.total"
                        :rows-per-page-options="[10, 25, 50]" template="PrevPageLink NextPageLink RowsPerPageDropdown"
                        :pt="{ pcRowPerPageDropdown: { root: { 'aria-label': 'Rows per page' }, overlay: { class: 'building-select-overlay' } } }"
                        @page="changePage" />
                </footer>
            </template>
        </section>

        <Drawer :visible="!!buildingId" position="right" class="building-drawer" block-scroll
            aria-labelledby="building-details-heading"
            :close-button-props="{ size: 'small', text: true, severity: 'secondary', 'aria-label': 'Close building details' }"
            @update:visible="closeDetail" @after-hide="restoreDetailFocus">
            <template #header>
                <h2 id="building-details-heading">{{ (detail?.id != null && String(detail.id) === buildingId && detail.reference) || 'Building details' }}</h2>
            </template>
            <div v-if="detailLoading" class="building-loading" role="status" aria-label="Loading building details">
                <Skeleton v-for="line in 3" :key="line" height="1.5rem" aria-hidden="true" />
            </div>
            <div v-else-if="detailError" class="building-alert" role="alert">
                <p>{{ detailError }}</p>
                <Button type="button" size="small" outlined severity="secondary" label="Retry" @click="loadDetail(buildingId)" />
            </div>
            <div v-else-if="detail" class="building-detail">
                <section>
                    <dl>
                        <div v-if="detail.building_name"><dt>Name</dt><dd>{{ detail.building_name }}</dd></div>
                        <div v-if="detail.building_type"><dt>Type</dt><dd>{{ detail.building_type }}</dd></div>
                        <div><dt>Barangay</dt><dd>{{ detail.barangay || 'Assigned barangay' }}</dd></div>
                        <div><dt>Address</dt><dd>{{ detail.address || 'No recorded address' }}</dd></div>
                    </dl>
                </section>
                <section>
                    <div class="building-detail-heading">
                        <h3>Location</h3>
                        <Tag :value="statusLabel(detail.location_status)" :severity="locationSeverity[detail.location_status] || 'secondary'" />
                    </div>
                    <p>{{ locationNote(detail) }}</p>
                    <dl v-if="detail.location_source || detail.location_verified_at">
                        <div v-if="detail.location_source"><dt>Source</dt><dd>{{ detail.location_source }}</dd></div>
                        <div v-if="detail.location_verified_at"><dt>Verified</dt><dd>{{ formatWhen(detail.location_verified_at) }}</dd></div>
                    </dl>
                    <div v-if="detail.map_available && mapboxToken" ref="miniMapEl" class="building-mini-map"
                        role="img" aria-label="Recorded building location"></div>
                    <Button v-if="detail.map_available" :as="RouterLink" :to="mapTarget(detail.id)"
                        size="small" outlined severity="secondary" label="View on assessment map">
                        <template #icon><MapIcon name="pin" /></template>
                    </Button>
                </section>
                <section>
                    <h3>Households</h3>
                    <p>
                        {{ detail.household_count }} active {{ detail.household_count === 1 ? 'household' : 'households' }}
                        <template v-if="detail.resident_count != null">
                            · {{ detail.resident_count }} active resident {{ detail.resident_count === 1 ? 'record' : 'records' }}
                        </template>
                    </p>
                    <ul v-if="detailHouseholds.length">
                        <li v-for="household in detailHouseholds" :key="household.id">
                            <strong>{{ household.household_reference }}</strong>
                            <span v-if="household.source_household_size != null" class="building-sub">Source household size {{ household.source_household_size }}</span>
                        </li>
                    </ul>
                </section>
                <section>
                    <h3>Linked reports</h3>
                    <p v-if="!detail.resident_reports?.length && !detail.barangay_reports?.length">No linked reports.</p>
                    <template v-if="detail.resident_reports?.length">
                        <h4>Resident reports</h4>
                        <ul>
                            <li v-for="report in detail.resident_reports" :key="report.id">
                                <strong>{{ report.reference }}</strong>
                                <span>{{ report.incident_name || 'Incident' }} · {{ reportLabel(report.status) }}</span>
                                <span v-if="report.submitted_at" class="building-sub">{{ formatWhen(report.submitted_at) }}</span>
                            </li>
                        </ul>
                    </template>
                    <template v-if="detail.barangay_reports?.length">
                        <h4>Barangay reports</h4>
                        <ul>
                            <li v-for="report in detail.barangay_reports" :key="report.id">
                                <RouterLink :to="`/reports/barangay/${report.id}`">{{ report.reference }}</RouterLink>
                                <span>{{ report.incident_name || 'Incident' }} · {{ reportLabel(report.status) }}</span>
                                <span v-if="report.submitted_at" class="building-sub">{{ formatWhen(report.submitted_at) }}</span>
                            </li>
                        </ul>
                    </template>
                </section>
                <section>
                    <h3>Assessments</h3>
                    <ul v-if="detail.assessments?.length">
                        <li v-for="(finding, index) in detail.assessments" :key="`${finding.verified_at}-${index}`">
                            <strong>{{ finding.incident_name || 'Incident' }}</strong>
                            <span>{{ finding.damage_class }}</span>
                            <span v-if="finding.verified_at" class="building-sub">{{ formatWhen(finding.verified_at) }}</span>
                        </li>
                    </ul>
                    <p v-else>No recorded assessment.</p>
                </section>
            </div>
        </Drawer>
    </section>
</template>
