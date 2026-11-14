<script setup>
import { computed, inject, nextTick, onBeforeUnmount, onMounted, ref, watch } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import mapboxgl from 'mapbox-gl';
import MapIcon from '../../shared/components/MapIcon.vue';

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
const compact = ref(typeof window !== 'undefined' && window.matchMedia('(max-width: 767px)').matches);
const sheetEl = ref(null);
const closeButton = ref(null);
const miniMapEl = ref(null);

let applying = false;
let requestTicket = 0;
let detailTicket = 0;
let searchTimer;
let detailTrigger = null;
let stopMedia = () => {};
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
const authorizedLabel = computed(() => `${summary.value.authorized_total} ${summary.value.authorized_total === 1 ? 'building' : 'buildings'}`);
const noAssignment = computed(() => phase.value === 'ready' && barangays.value.length === 0);
const noBuildings = computed(() => phase.value === 'ready' && barangays.value.length > 0 && summary.value.authorized_total === 0);
const noMatches = computed(() => phase.value === 'ready' && summary.value.authorized_total > 0 && meta.value.total === 0);

const locationLabels = { unverified: 'Unverified', verified: 'Verified', rejected: 'Rejected' };
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
    if (record?.map_available) return 'Source-recorded point. A recorded point is not a verified location.';
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
    await syncDetail();
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
    pushQuery(true);
}

function toggleCard(key, value) {
    if (key === 'location') location.value = location.value === value ? '' : value;
    if (key === 'households') households.value = households.value === value ? '' : value;
    if (key === 'reports') reports.value = reports.value === value ? '' : value;
    pushQuery(true);
}

function mapTarget(id) {
    return { path: '/map', query: { building_id: String(id) } };
}

async function openBuilding(id, event) {
    detailTrigger = event?.currentTarget || null;
    const query = queryFromState();
    query.building = String(id);
    applying = true;
    await router.replace({ path: '/buildings', query });
    applying = false;
    await syncDetail();
    await nextTick();
    closeButton.value?.focus();
}

async function closeDetail() {
    const query = queryFromState();
    delete query.building;
    applying = true;
    await router.replace({ path: '/buildings', query });
    applying = false;
    detail.value = null;
    detailHouseholds.value = [];
    destroyMiniMap();
    await nextTick();
    detailTrigger?.focus();
}

async function syncDetail() {
    const id = typeof route.query.building === 'string' ? route.query.building : '';
    if (id === '') {
        detail.value = null;
        detailHouseholds.value = [];
        destroyMiniMap();
        return;
    }
    await loadDetail(id);
}

async function loadDetail(id) {
    const ticket = ++detailTicket;
    detailLoading.value = true;
    detailError.value = '';
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
        closeButton.value?.focus();
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
        new mapboxgl.Marker({ color: '#1e3a8a' }).setLngLat(center).addTo(miniMap);
    });
}

function onSheetKey(event) {
    if (event.key === 'Escape') {
        event.preventDefault();
        event.stopPropagation();
        closeDetail();
        return;
    }
    if (event.key !== 'Tab' || !sheetEl.value) return;
    const items = [...sheetEl.value.querySelectorAll('a, button, input, select, textarea')].filter((item) => !item.disabled);
    if (!items.length) return;
    const first = items[0];
    const last = items[items.length - 1];
    if (event.shiftKey && document.activeElement === first) {
        event.preventDefault();
        last.focus();
    } else if (!event.shiftKey && document.activeElement === last) {
        event.preventDefault();
        first.focus();
    }
}

watch(() => route.fullPath, async () => {
    if (route.path !== '/buildings' || applying) return;
    readRoute();
    await load();
    await syncDetail();
}, { immediate: true });

onMounted(() => {
    const media = window.matchMedia('(max-width: 767px)');
    const sync = () => {
        compact.value = media.matches;
    };
    sync();
    media.addEventListener('change', sync);
    stopMedia = () => media.removeEventListener('change', sync);
});

onBeforeUnmount(() => {
    clearTimeout(searchTimer);
    stopMedia();
    destroyMiniMap();
});
</script>

<template>
    <section class="bdrrm-buildings" :aria-busy="refreshing">
        <header class="building-heading">
            <div class="building-title">
                <span class="building-title-icon" aria-hidden="true"><MapIcon name="building" /></span>
                <div>
                    <h2>Building Records</h2>
                    <p>View registered buildings, linked households, locations, and disaster assessment records within authorized barangays.</p>
                </div>
            </div>
            <p class="building-assignment">
                <MapIcon name="pin" />
                <span v-if="barangays.length === 1">{{ barangays[0].name }}</span>
                <label v-else-if="barangays.length > 1">
                    <span class="visually-hidden">Assigned barangay</span>
                    <select v-model="barangayId" @change="pushQuery(true)">
                        <option value="">All assigned barangays</option>
                        <option v-for="row in barangays" :key="row.id" :value="String(row.id)">{{ row.name }}</option>
                    </select>
                </label>
                <span v-else-if="phase === 'ready'">No active barangay assignment</span>
                <span v-if="phase === 'ready'">{{ authorizedLabel }}</span>
            </p>
        </header>
        <p class="building-notice">Building registry for your assigned barangays. Registry totals do not represent disaster impact.</p>

        <div v-if="phase === 'ready'" class="building-summary">
            <article class="building-metric">
                <MapIcon name="building" />
                <p>Total Buildings</p>
                <strong>{{ summary.total }}</strong>
                <span>Matching the current filters.</span>
            </article>
            <button type="button" class="building-metric" :aria-pressed="location === 'recorded'" @click="toggleCard('location', 'recorded')">
                <MapIcon name="pin" />
                <p>Buildings with Recorded Locations</p>
                <strong>{{ summary.with_recorded_location }}</strong>
                <span>A recorded point is not a verified location.</span>
            </button>
            <button type="button" class="building-metric" :aria-pressed="households === 'with'" @click="toggleCard('households', 'with')">
                <MapIcon name="family" />
                <p>Buildings with Linked Households</p>
                <strong>{{ summary.with_households }}</strong>
                <span>At least one active household.</span>
            </button>
            <button type="button" class="building-metric" :aria-pressed="reports === 'with'" @click="toggleCard('reports', 'with')">
                <MapIcon name="reports" />
                <p>Buildings with Linked Reports</p>
                <strong>{{ summary.with_reports }}</strong>
                <span>Resident or barangay report link.</span>
            </button>
        </div>

        <form class="building-filters" @submit.prevent="pushQuery(true)">
            <label class="building-search">
                <span>Search</span>
                <span class="building-search-field">
                    <MapIcon name="search" />
                    <input v-model="search" type="search" maxlength="80" autocomplete="off" placeholder="Search building reference or location" @input="onSearchInput">
                </span>
            </label>
            <label v-if="barangays.length > 1" class="building-select">
                <span>Barangay</span>
                <select v-model="barangayId" @change="pushQuery(true)">
                    <option value="">All assigned barangays</option>
                    <option v-for="row in barangays" :key="`filter-${row.id}`" :value="String(row.id)">{{ row.name }}</option>
                </select>
            </label>
            <label class="building-select">
                <span>Location</span>
                <select v-model="location" @change="pushQuery(true)">
                    <option value="">All locations</option>
                    <option value="recorded">With a recorded location</option>
                    <option value="missing">Without a recorded location</option>
                </select>
            </label>
            <details class="building-more">
                <summary>More filters</summary>
                <div>
                    <label>
                        <span>Verification</span>
                        <select v-model="verification" @change="pushQuery(true)">
                            <option value="">All statuses</option>
                            <option value="unverified">Unverified</option>
                            <option value="verified">Verified</option>
                            <option value="rejected">Rejected</option>
                        </select>
                    </label>
                    <label>
                        <span>Households</span>
                        <select v-model="households" @change="pushQuery(true)">
                            <option value="">All buildings</option>
                            <option value="with">With linked households</option>
                            <option value="without">Without linked households</option>
                        </select>
                    </label>
                    <label>
                        <span>Reports</span>
                        <select v-model="reports" @change="pushQuery(true)">
                            <option value="">All buildings</option>
                            <option value="with">With linked reports</option>
                            <option value="without">Without linked reports</option>
                        </select>
                    </label>
                    <label>
                        <span>Sort</span>
                        <select v-model="sort" @change="pushQuery(true)">
                            <option value="reference">Reference</option>
                            <option value="newest">Newest</option>
                        </select>
                    </label>
                </div>
            </details>
            <button v-if="filtersActive" type="button" class="building-quiet" @click="clearFilters">Clear filters</button>
        </form>

        <p v-if="error && phase !== 'error'" class="building-alert" role="alert">
            {{ error }}
            <button type="button" class="building-quiet" @click="load">Retry</button>
        </p>

        <div v-if="phase === 'loading'" aria-busy="true">
            <p class="visually-hidden">Loading buildings…</p>
            <div v-if="!compact" class="building-table-wrap">
                <table class="building-table">
                    <thead>
                        <tr>
                            <th scope="col">Building</th>
                            <th scope="col">Barangay</th>
                            <th scope="col">Recorded location</th>
                            <th scope="col">Location status</th>
                            <th scope="col">Households</th>
                            <th scope="col">Reports</th>
                            <th scope="col">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="slot in 3" :key="slot" class="building-skeleton">
                            <td colspan="7"><span></span></td>
                        </tr>
                    </tbody>
                </table>
            </div>
            <div v-else class="building-list">
                <article v-for="slot in 3" :key="slot" class="building-skeleton" aria-hidden="true">
                    <span></span>
                    <span></span>
                    <span></span>
                </article>
            </div>
        </div>
        <div v-else-if="phase === 'error'" class="building-state" role="alert">
            <p>{{ error }}</p>
            <button type="button" class="building-primary" @click="load">Retry</button>
        </div>
        <p v-else-if="noAssignment" class="building-state">No active barangay assignment.</p>
        <p v-else-if="noBuildings" class="building-state">No buildings are recorded in your assigned barangays.</p>
        <p v-else-if="noMatches" class="building-state">No buildings match these filters.</p>
        <template v-else>
            <div v-if="!compact" class="building-table-wrap">
                <table class="building-table">
                    <thead>
                        <tr>
                            <th scope="col">Building</th>
                            <th scope="col">Barangay</th>
                            <th scope="col">Recorded location</th>
                            <th scope="col">Location status</th>
                            <th scope="col">Households</th>
                            <th scope="col">Reports</th>
                            <th scope="col">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="row in rows" :key="row.id">
                            <td>
                                <button type="button" class="building-reference" @click="openBuilding(row.id, $event)">
                                    <MapIcon name="building" />
                                    {{ row.reference }}
                                </button>
                            </td>
                            <td>{{ row.barangay_name || 'Assigned barangay' }}</td>
                            <td>{{ row.address || 'No recorded address' }}</td>
                            <td>
                                <span class="building-badge" :data-status="row.location_status">{{ statusLabel(row.location_status) }}</span>
                                <span class="building-sub">{{ row.location_recorded ? 'Recorded point' : 'No recorded point' }}</span>
                            </td>
                            <td class="building-count">{{ row.household_count }}</td>
                            <td class="building-count">{{ row.report_count }}</td>
                            <td class="building-actions">
                                <button type="button" class="building-primary" @click="openBuilding(row.id, $event)">
                                    <MapIcon name="eye" /> View Details
                                </button>
                                <RouterLink v-if="row.map_available" class="building-quiet" :to="mapTarget(row.id)">
                                    <MapIcon name="map" /> View on Map
                                </RouterLink>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
            <ul v-else class="building-list">
                <li v-for="row in rows" :key="row.id">
                    <article class="building-record">
                        <div class="building-record-top">
                            <button type="button" class="building-reference" @click="openBuilding(row.id, $event)">
                                <MapIcon name="building" />
                                {{ row.reference }}
                            </button>
                            <span class="building-badge" :data-status="row.location_status">{{ statusLabel(row.location_status) }}</span>
                        </div>
                        <p>{{ row.barangay_name || 'Assigned barangay' }}</p>
                        <p class="building-sub">{{ row.address || 'No recorded address' }}</p>
                        <p class="building-sub">{{ row.location_recorded ? 'Recorded point' : 'No recorded point' }}</p>
                        <p class="building-record-counts">
                            <span>{{ row.household_count }} {{ row.household_count === 1 ? 'household' : 'households' }}</span>
                            <span>{{ row.report_count }} {{ row.report_count === 1 ? 'report' : 'reports' }}</span>
                        </p>
                        <div class="building-actions">
                            <button type="button" class="building-primary" @click="openBuilding(row.id, $event)">
                                <MapIcon name="eye" /> View Details
                            </button>
                            <RouterLink v-if="row.map_available" class="building-quiet" :to="mapTarget(row.id)">
                                <MapIcon name="map" /> View on Map
                            </RouterLink>
                        </div>
                    </article>
                </li>
            </ul>
            <div v-if="meta.total > 0" class="building-pager">
                <div>
                    <span>Showing {{ rangeStart }}–{{ rangeEnd }} of {{ meta.total }} buildings</span>
                    <span v-if="filtersActive"> of {{ summary.authorized_total }} in your assignment</span>
                    <label>
                        <span class="visually-hidden">Rows</span>
                        <select v-model.number="perPage" @change="pushQuery(true)">
                            <option :value="10">10</option>
                            <option :value="25">25</option>
                            <option :value="50">50</option>
                        </select>
                    </label>
                </div>
                <div>
                    <button type="button" class="building-quiet" :disabled="meta.current_page <= 1" @click="page = meta.current_page - 1; pushQuery(false)">Previous</button>
                    <span>Page {{ meta.current_page }} of {{ meta.last_page }}</span>
                    <button type="button" class="building-quiet" :disabled="meta.current_page >= meta.last_page" @click="page = meta.current_page + 1; pushQuery(false)">Next</button>
                </div>
            </div>
        </template>

        <div
            v-if="detail || detailLoading || detailError"
            ref="sheetEl"
            class="building-sheet"
            role="dialog"
            aria-modal="true"
            aria-labelledby="building-sheet-title"
            tabindex="-1"
            @keydown="onSheetKey"
        >
            <header class="building-sheet-bar">
                <div>
                    <p class="building-sub">Building record</p>
                    <h2 id="building-sheet-title">{{ detail?.reference || 'Building record' }}</h2>
                </div>
                <button ref="closeButton" type="button" class="building-quiet building-icon" aria-label="Close building details" @click="closeDetail">
                    <MapIcon name="x" />
                </button>
            </header>
            <p v-if="detailLoading" class="building-state">Loading building…</p>
            <div v-else-if="detailError" class="building-state" role="alert">
                <p>{{ detailError }}</p>
                <button type="button" class="building-primary" @click="syncDetail">Retry</button>
            </div>
            <div v-else-if="detail" class="building-sheet-body">
                <section>
                    <h3><MapIcon name="building" /> Building</h3>
                    <dl>
                        <div><dt>Reference</dt><dd>{{ detail.reference }}</dd></div>
                        <div v-if="detail.building_name"><dt>Name</dt><dd>{{ detail.building_name }}</dd></div>
                        <div v-if="detail.building_type"><dt>Type</dt><dd>{{ detail.building_type }}</dd></div>
                        <div><dt>Barangay</dt><dd>{{ detail.barangay || 'Assigned barangay' }}</dd></div>
                        <div><dt>Address</dt><dd>{{ detail.address || 'No recorded address' }}</dd></div>
                        <div v-if="detail.location_source"><dt>Source</dt><dd>{{ detail.location_source }}</dd></div>
                        <div v-if="detail.location_verified_at"><dt>Verified</dt><dd>{{ formatWhen(detail.location_verified_at) }}</dd></div>
                    </dl>
                </section>
                <section>
                    <h3><MapIcon name="pin" /> Location</h3>
                    <p><span class="building-badge" :data-status="detail.location_status">{{ statusLabel(detail.location_status) }}</span></p>
                    <p>{{ locationNote(detail) }}</p>
                    <div v-if="detail.map_available" ref="miniMapEl" class="building-mini-map" role="img" aria-label="Recorded building location"></div>
                    <RouterLink v-if="detail.map_available" class="building-quiet" :to="mapTarget(detail.id)">
                        <MapIcon name="map" /> View on Barangay Map
                    </RouterLink>
                </section>
                <section>
                    <h3><MapIcon name="family" /> Households</h3>
                    <p>{{ detail.household_count }} active {{ detail.household_count === 1 ? 'household' : 'households' }}</p>
                    <p v-if="detail.resident_count != null" class="building-sub">{{ detail.resident_count }} active resident {{ detail.resident_count === 1 ? 'record' : 'records' }}</p>
                    <ul v-if="detailHouseholds.length">
                        <li v-for="household in detailHouseholds" :key="household.id">
                            <strong>{{ household.household_reference }}</strong>
                            <span v-if="household.source_household_size != null">Source household size {{ household.source_household_size }}</span>
                        </li>
                    </ul>
                    <p v-else class="building-sub">No active households are linked to this building.</p>
                </section>
                <section>
                    <h3><MapIcon name="reports" /> Reports</h3>
                    <h4>Resident reports</h4>
                    <ul v-if="detail.resident_reports?.length">
                        <li v-for="report in detail.resident_reports" :key="`resident-${report.id}`">
                            <RouterLink :to="`/submissions/${report.id}`">{{ report.reference }}</RouterLink>
                            <span>{{ report.incident_name || 'Incident' }}</span>
                            <span>{{ reportLabel(report.status) }}</span>
                            <span v-if="report.submitted_at">{{ formatWhen(report.submitted_at) }}</span>
                        </li>
                    </ul>
                    <p v-else class="building-sub">No linked resident reports.</p>
                    <h4>Barangay reports</h4>
                    <ul v-if="detail.barangay_reports?.length">
                        <li v-for="report in detail.barangay_reports" :key="`barangay-${report.id}`">
                            <RouterLink :to="`/reports/${report.id}`">{{ report.reference }}</RouterLink>
                            <span>{{ report.incident_name || 'Incident' }}</span>
                            <span>{{ reportLabel(report.status) }}</span>
                        </li>
                    </ul>
                    <p v-else class="building-sub">No linked barangay reports.</p>
                </section>
                <section>
                    <h3><MapIcon name="clipboard-check" /> Assessment</h3>
                    <ul v-if="detail.assessments?.length">
                        <li v-for="(finding, index) in detail.assessments" :key="`${finding.verified_at}-${index}`">
                            <strong>{{ finding.incident_name || 'Incident' }}</strong>
                            <span>{{ finding.damage_class }}</span>
                            <span v-if="finding.verified_at">{{ formatWhen(finding.verified_at) }}</span>
                        </li>
                    </ul>
                    <p v-else>Not assessed.</p>
                </section>
            </div>
        </div>
    </section>
</template>
