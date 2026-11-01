<script setup>
import { computed, inject, nextTick, onBeforeUnmount, onMounted, reactive, ref, watch } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import MapIcon from '../../shared/components/MapIcon.vue';
import ResidentDetailDrawer from '../../shared/components/ResidentDetailDrawer.vue';
import ResidentFormDialog from '../components/ResidentFormDialog.vue';

const route = useRoute();
const router = useRouter();
const reportContext = inject('reportContext');
const apiBase = inject('apiBase', '/cdrrmo/api');

const barangays = ref([]);
const incidents = ref([]);
const page = ref(null);
const summary = ref(null);
const areaContext = ref(null);
const loading = ref(true);
const error = ref('');
const notice = ref('');
const mapNotice = ref('');
const selected = ref({});
const openMenuId = ref(null);
const detail = ref(null);
const formOpen = ref(false);
const editing = ref(null);
const archiveTarget = ref(null);
const archiveReference = ref('');
const archiveError = ref('');
const inclusionOpen = ref(false);
const inclusionError = ref('');
const inclusionForm = reactive({ basis: 'manual_review', reference: '', notes: '' });
const reportsOpen = ref(false);
const reports = ref(null);
const reportsError = ref('');
const reportsResident = ref(null);
let detailTicket = 0;
const detailTrigger = ref(null);
let listTicket = 0;
let summaryTicket = 0;
let searchTimer = null;
const ready = ref(false);

const filters = reactive({
    search: '',
    barangay_id: '',
    household_id: route.query.household_id || '',
    building_id: route.query.building_id || '',
    incident_id: '',
    area_scope: route.query.area === '1',
    inclusion: '',
    quality: '',
    status: 'active',
    per_page: 25,
    page: 1,
});

const selectedList = computed(() => Object.values(selected.value));
const selectedIds = computed(() => selectedList.value.map((row) => row.id));
const pageRows = computed(() => page.value?.data || []);
const pageSelected = computed(() => pageRows.value.length > 0 && pageRows.value.every((row) => selected.value[row.id]));
const selectionHasLocation = computed(() => selectedList.value.some((row) => row.map_available));
const rangeLabel = computed(() => {
    const meta = page.value?.meta;
    if (!meta?.total) return '0 residents';
    const start = (meta.current_page - 1) * meta.per_page + 1;
    const end = Math.min(meta.total, start + pageRows.value.length - 1);
    return `${start}–${end} of ${meta.total}`;
});
function formatCount(value) {
    if (value === null || value === undefined || value === '') return '—';
    const number = Number(value);
    return Number.isFinite(number) ? number.toLocaleString('en-US') : String(value);
}
const incidentName = computed(() => incidents.value.find((item) => String(item.id) === String(filters.incident_id))?.incident_name || '');
const selectedBarangays = computed(() => [...new Set(selectedList.value.map((row) => row.barangay).filter(Boolean))]);

function scopeParams() {
    return {
        search: filters.search || undefined,
        barangay_id: filters.barangay_id || undefined,
        household_id: filters.household_id || undefined,
        building_id: filters.building_id || undefined,
        incident_id: filters.incident_id || undefined,
        area_scope: filters.area_scope ? 1 : undefined,
        status: filters.status,
        report_availability: filters.report_availability === 'all' ? undefined : filters.report_availability,
        inclusion: filters.inclusion || undefined,
    };
}

async function loadSummary() {
    const ticket = ++summaryTicket;
    try {
        const { data } = await window.axios.get(`${apiBase}/residents/summary`, { params: scopeParams() });
        if (ticket === summaryTicket) summary.value = data;
    } catch {
        if (ticket === summaryTicket) summary.value = null;
    }
}

async function loadResidents() {
    const ticket = ++listTicket;
    loading.value = true;
    error.value = '';
    try {
        const { data } = await window.axios.get(`${apiBase}/residents`, {
            params: {
                ...scopeParams(),
                inclusion: filters.inclusion || undefined,
                status: filters.status,
                report_availability: filters.report_availability === 'all' ? undefined : filters.report_availability,
                per_page: filters.per_page,
                page: filters.page,
            },
        });
        if (ticket !== listTicket) return;
        page.value = data;
    } catch {
        if (ticket === listTicket) error.value = 'Resident records could not be loaded.';
    } finally {
        if (ticket === listTicket) loading.value = false;
    }
}

function reloadList() {
    loadResidents();
}

function reloadAll() {
    loadSummary();
    loadResidents();
}

async function loadAreaContext() {
    try {
        areaContext.value = (await window.axios.get(`${apiBase}/area-context`)).data;
        if (!areaContext.value.active) filters.area_scope = false;
    } catch {
        areaContext.value = null;
    }
}

function clearSelection() {
    selected.value = {};
    openMenuId.value = null;
}

function showRegistered() {
    filters.inclusion = '';
    filters.report_availability = 'all';
    filters.status = 'active';
    filters.household_id = '';
    filters.building_id = '';
    filters.page = 1;
    router.replace({ query: filters.area_scope ? { area: '1' } : {} });
    reloadList();
}

function showAffected() {
    if (!filters.incident_id) return;
    filters.inclusion = filters.inclusion === 'included' ? '' : 'included';
    filters.page = 1;
    reloadList();
}

function clearFilters() {
    Object.assign(filters, {
        search: '',
        barangay_id: '',
        household_id: '',
        building_id: '',
        incident_id: '',
        inclusion: '',
        status: 'active',
        report_availability: 'all',
        page: 1,
    });
    clearSelection();
    router.replace({ query: {} });
    reloadAll();
}

async function clearAreaFilter() {
    await reportContext.clearAreaContext();
    filters.area_scope = false;
    areaContext.value = null;
    clearSelection();
    router.replace({ query: { ...route.query, area: undefined } });
    filters.page = 1;
    reloadAll();
}

function toggleRow(row) {
    const next = { ...selected.value };
    if (next[row.id]) delete next[row.id];
    else next[row.id] = row;
    selected.value = next;
}

function togglePage(event) {
    const next = { ...selected.value };
    if (event.target.checked) pageRows.value.forEach((row) => { next[row.id] = row; });
    else pageRows.value.forEach((row) => { delete next[row.id]; });
    selected.value = next;
}

function goToPage(nextPage) {
    filters.page = nextPage;
    reloadList();
}

async function openDetail(row, event) {
    const ticket = ++detailTicket;
    openMenuId.value = null;
    detailTrigger.value = event?.currentTarget || null;
    detail.value = {
        id: row.id,
        display_name: row.display_name,
        registry_reference: row.registry_reference,
        loading: true,
    };
    try {
        const { data } = await window.axios.get(`${apiBase}/residents/${row.id}`, {
            params: { incident_id: filters.incident_id || undefined },
        });
        if (ticket !== detailTicket) return;
        detail.value = data;
    } catch {
        if (ticket !== detailTicket) return;
        detail.value = {
            id: row.id,
            display_name: row.display_name,
            registry_reference: row.registry_reference,
            error: true,
        };
    }
}

function closeDetail() {
    detailTicket += 1;
    detail.value = null;
    const trigger = detailTrigger.value;
    detailTrigger.value = null;
    nextTick(() => trigger?.focus());
}

async function startEdit(row) {
    openMenuId.value = null;
    editing.value = row.first_name ? row : (await window.axios.get(`${apiBase}/residents/${row.id}`)).data;
    detail.value = null;
    formOpen.value = true;
}

function startAdd() {
    editing.value = null;
    formOpen.value = true;
}

function onSaved() {
    const saved = editing.value;
    formOpen.value = false;
    editing.value = null;
    notice.value = 'Resident record saved.';
    reloadAll();
    if (saved?.id) {
        openDetail({
            id: saved.id,
            display_name: saved.display_name,
            registry_reference: saved.registry_reference,
        });
    }
}

async function viewOnMap(rows) {
    const list = Array.isArray(rows) ? rows : [rows];
    mapNotice.value = '';
    const { data } = await window.axios.post(`${apiBase}/residents/map-focus`, {
        resident_ids: list.map((row) => row.id),
    });
    if (data.missing) {
        mapNotice.value = `${data.missing} selected resident${data.missing === 1 ? '' : 's'} have no usable location.`;
    }
    if (!data.mapped) return;
    router.push({ path: '/map', query: { focus: 'residents' } });
}

async function openReports(row) {
    openMenuId.value = null;
    reportsResident.value = row;
    reports.value = null;
    reportsError.value = '';
    reportsOpen.value = true;
    try {
        reports.value = (await window.axios.get(`${apiBase}/residents/${row.id}/reports`, {
            params: { incident_id: filters.incident_id || undefined },
        })).data;
    } catch {
        reportsError.value = 'Related reports could not be loaded.';
    }
}

const reportGroups = computed(() => {
    if (!reports.value) return [];
    return [
        ['Submitted by the linked account', reports.value.submitted_by_account || []],
        ['Household or building reports', reports.value.household_or_building || []],
        ['Estimate or consolidated membership', reports.value.estimate_or_consolidated || []],
    ];
});

const reportsEmpty = computed(() => reportGroups.value.every(([, rows]) => rows.length === 0));

function openReport(item) {
    if (!item.open_path) return;
    router.push({ path: item.open_path, query: { return: route.fullPath } });
}

function startArchive(row) {
    openMenuId.value = null;
    archiveTarget.value = row;
    archiveReference.value = '';
    archiveError.value = '';
}

async function confirmArchive() {
    archiveError.value = '';
    try {
        await window.axios.post(`${apiBase}/residents/${archiveTarget.value.id}/archive`, {
            confirm_reference: archiveReference.value,
        });
        const id = archiveTarget.value.id;
        archiveTarget.value = null;
        const next = { ...selected.value };
        delete next[id];
        selected.value = next;
        notice.value = 'Resident archived.';
        reloadAll();
    } catch (exception) {
        archiveError.value = exception.response?.data?.message || 'The resident could not be archived.';
    }
}

async function restoreResident(row) {
    openMenuId.value = null;
    await window.axios.post(`${apiBase}/residents/${row.id}/restore`);
    notice.value = 'Resident restored.';
    reloadAll();
}

function openInclusion() {
    inclusionError.value = '';
    inclusionForm.basis = 'manual_review';
    inclusionForm.reference = '';
    inclusionForm.notes = '';
    inclusionOpen.value = true;
}

async function confirmInclusion(remove = false) {
    inclusionError.value = '';
    if (!filters.incident_id) {
        inclusionError.value = 'An incident is required before residents can be added to the potentially affected list.';
        return;
    }
    try {
        if (remove) {
            await window.axios.post(`${apiBase}/residents/inclusions/remove`, {
                incident_id: Number(filters.incident_id),
                resident_ids: selectedIds.value,
            });
            notice.value = 'Residents removed from this incident list.';
        } else {
            await window.axios.post(`${apiBase}/residents/inclusions`, {
                incident_id: Number(filters.incident_id),
                resident_ids: selectedIds.value,
                inclusion_basis: inclusionForm.basis,
                reference: inclusionForm.reference || null,
                notes: inclusionForm.notes || null,
            });
            notice.value = 'Residents added to the potentially affected list for this incident.';
        }
        inclusionOpen.value = false;
        clearSelection();
        reloadAll();
    } catch (exception) {
        inclusionError.value = exception.response?.data?.message || 'The incident list could not be updated.';
    }
}

function onDocumentClick(event) {
    if (!event.target.closest('.residents-menu')) openMenuId.value = null;
}

function onKeydown(event) {
    if (event.key !== 'Escape') return;
    if (formOpen.value || detail.value || archiveTarget.value || inclusionOpen.value || reportsOpen.value) return;
    openMenuId.value = null;
}

watch(() => filters.search, () => {
    if (!ready.value) return;
    clearTimeout(searchTimer);
    searchTimer = setTimeout(() => {
        filters.page = 1;
        reloadAll();
    }, 300);
});

watch(() => [filters.barangay_id, filters.incident_id, filters.household_id, filters.building_id, filters.area_scope], (value, previous) => {
    if (!ready.value) return;
    if (value[1] !== previous?.[1] || value[4] !== previous?.[4]) clearSelection();
    if (!filters.incident_id && filters.inclusion) filters.inclusion = '';
    filters.page = 1;
    reloadAll();
});

watch(() => [filters.inclusion, filters.status, filters.per_page, filters.report_availability], () => {
    if (!ready.value) return;
    filters.page = 1;
    reloadAll();
});

onMounted(async () => {
    document.addEventListener('click', onDocumentClick);
    document.addEventListener('keydown', onKeydown);
    const summaryRes = await window.axios.get(`${apiBase}/summary`);
    barangays.value = summaryRes.data.barangays || [];
    try {
        incidents.value = (await window.axios.get(`${apiBase}/incidents`)).data.data || [];
    } catch {
        incidents.value = [];
    }
    await loadAreaContext();
    if (route.query.area === '1' || areaContext.value?.active) filters.area_scope = true;
    ready.value = true;
    await reloadAll();
});

onBeforeUnmount(() => {
    document.removeEventListener('click', onDocumentClick);
    document.removeEventListener('keydown', onKeydown);
    clearTimeout(searchTimer);
});
</script>

<template>
    <section class="residents-page">
        <header class="residents-header">
            <div>
                <h2 class="cdrrmo-page-title">Residents</h2>
                <p class="cdrrmo-page-lead">Manage authorized resident records and review incident-linked information.</p>
            </div>
            <div class="residents-header-actions">
                <button type="button" class="mapr-button mapr-button-primary" @click="startAdd">
                    <MapIcon name="plus" /> Add Resident
                </button>
                <button type="button" class="mapr-button mapr-button-secondary" @click="reloadAll">
                    <MapIcon name="refresh" /> Refresh
                </button>
                <button v-if="selectionHasLocation" type="button" class="mapr-button mapr-button-secondary" @click="viewOnMap(selectedList)">
                    <MapIcon name="pin" /> View Selected on Map
                </button>
            </div>
        </header>

        <p v-if="notice" class="residents-notice" role="status">{{ notice }}</p>
        <p v-if="mapNotice" class="residents-warning" role="status">{{ mapNotice }}</p>

        <div class="residents-summary-grid" aria-label="Resident summary">
            <button type="button" class="cdrrmo-stat-card residents-summary-card" :class="{ 'is-active': !filters.inclusion && filters.status === 'active' }" @click="showRegistered">
                <p><MapIcon name="users" /> Registered residents</p>
                <strong>{{ formatCount(summary?.registered_residents) }}</strong>
                <span class="cdrrmo-muted">Active records in this scope</span>
            </button>
            <article class="cdrrmo-stat-card residents-summary-card is-static">
                <p><MapIcon name="home" /> Total household size</p>
                <strong>{{ formatCount(summary?.total_household_size) }}</strong>
                <span class="cdrrmo-muted">{{ summary?.household_size_partial ? `${summary.households_missing_size} households have no reported size` : 'Reported size, once per household' }}</span>
            </article>
            <button type="button" class="cdrrmo-stat-card residents-summary-card" :disabled="!filters.incident_id" :class="{ 'is-active': filters.inclusion === 'included' }" @click="showAffected">
                <p><MapIcon name="pin-check" /> Potentially affected</p>
                <strong v-if="summary?.incident_required">Select an incident</strong>
                <strong v-else>{{ formatCount(summary?.potentially_affected) }}</strong>
                <span class="cdrrmo-muted">Incident list, not confirmed harm</span>
            </button>
        </div>

        <form class="cdrrmo-filters" @submit.prevent="reloadAll">
            <select v-model="filters.incident_id" aria-label="Registry or incident">
                <option value="">Registry view</option>
                <option v-for="incident in incidents" :key="incident.id" :value="String(incident.id)">{{ incident.incident_name }}</option>
            </select>
            <select v-model="filters.barangay_id" aria-label="Barangay">
                <option value="">All four barangays</option>
                <option v-for="barangay in barangays" :key="barangay.id" :value="String(barangay.id)">{{ barangay.name }}</option>
            </select>
            <input v-model="filters.search" type="search" placeholder="Name, resident reference, or household reference" aria-label="Search residents">
            <select v-if="filters.incident_id" v-model="filters.inclusion" aria-label="Incident inclusion">
                <option value="">All inclusion states</option>
                <option value="included">Potentially affected</option>
                <option value="not_included">Not listed</option>
            </select>
            <select v-model="filters.report_availability" aria-label="Report availability">
                <option value="all">All residents</option>
                <option value="with">With reports</option>
                <option value="without">Without reports</option>
            </select>
            <select v-model="filters.status" aria-label="Record status">
                <option value="active">Active records</option>
                <option value="inactive">Archived records</option>
                <option value="all">Active and archived</option>
            </select>
            <button type="button" class="mapr-button mapr-button-secondary" @click="clearFilters">Clear filters</button>
        </form>

        <div v-if="filters.area_scope && areaContext?.active" class="cdrrmo-area-banner">
            <div>
                <strong>{{ areaContext.label }}</strong>
                <p class="cdrrmo-muted">Barangays: {{ areaContext.barangays?.join(', ') || '—' }}</p>
            </div>
            <div class="cdrrmo-actions">
                <router-link class="mapr-button mapr-button-secondary" to="/map">Return to map</router-link>
                <button type="button" class="mapr-button mapr-button-secondary" @click="clearAreaFilter">Clear area filter</button>
            </div>
        </div>

        <div v-if="selectedIds.length" class="residents-toolbar" role="region" aria-label="Selected residents">
            <p>{{ selectedIds.length }} selected</p>
            <div class="cdrrmo-actions">
                <button type="button" class="mapr-button mapr-button-primary" @click="openInclusion">Add to incident list</button>
                <button type="button" class="mapr-button mapr-button-secondary" :disabled="!filters.incident_id" @click="confirmInclusion(true)">Remove from incident list</button>
                <button v-if="selectionHasLocation" type="button" class="mapr-button mapr-button-secondary" @click="viewOnMap(selectedList)">View Selected on Map</button>
                <button type="button" class="mapr-button mapr-button-secondary" @click="clearSelection">Clear selection</button>
            </div>
            <p v-if="!filters.incident_id" class="cdrrmo-muted">An incident is required before residents can be added to the potentially affected list.</p>
        </div>

        <div v-if="loading && !page" class="residents-skeleton" aria-hidden="true">
            <span></span><span></span><span></span><span></span><span></span><span></span>
        </div>
        <p v-else-if="error" class="cdrrmo-error" role="alert">{{ error }} <button type="button" class="resident-action resident-action-secondary" @click="reloadAll">Retry</button></p>
        <p v-else-if="!pageRows.length && page?.meta?.total === 0 && !page?.meta?.registry_loaded">
            Registry not loaded. Run <code>php artisan mapr:bootstrap-local-data</code> after migrate and seed.
        </p>
        <p v-else-if="!pageRows.length">No authorized resident records match these filters.</p>

        <div v-else class="cdrrmo-table-wrap residents-table-wrap">
            <table class="cdrrmo-table residents-table">
                <thead>
                    <tr>
                        <th>
                            <input :checked="pageSelected" type="checkbox" aria-label="Select this page" @change="togglePage">
                        </th>
                        <th>Resident</th>
                        <th>Resident reference</th>
                        <th>Household</th>
                        <th>Barangay</th>
                        <th>Reports</th>
                        <th>Incident status</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-for="row in pageRows" :key="row.id">
                        <td>
                            <input :checked="!!selected[row.id]" type="checkbox" :aria-label="`Select ${row.display_name}`" @change="toggleRow(row)">
                        </td>
                        <td>
                            {{ row.display_name }}
                        </td>
                        <td>{{ row.registry_reference }}</td>
                        <td>
                            {{ row.household_reference || '—' }}
                            <span class="residents-sub">{{ row.building_reference || 'No building' }}</span>
                        </td>
                        <td>{{ row.barangay || '—' }}</td>
                        <td>
                            <button type="button" class="residents-count" @click="openReports(row)">
                                <MapIcon name="file" />
                                {{ row.report_count === 1 ? '1 report' : (row.report_count ? `${row.report_count} reports` : 'No reports') }}
                            </button>
                        </td>
                        <td>{{ filters.incident_id ? (row.potentially_affected ? 'Potentially affected' : 'Not listed') : '—' }}</td>
                        <td>
                            <div class="residents-actions">
                                <button type="button" class="residents-icon-btn" title="View details" aria-label="View details" @click="openDetail(row, $event)">
                                    <MapIcon name="eye" />
                                </button>
                                <button type="button" class="residents-icon-btn" title="View on map" :aria-label="row.map_available ? 'View on map' : row.map_limitation" :disabled="!row.map_available" @click="viewOnMap(row)">
                                    <MapIcon name="pin" />
                                </button>
                                <button type="button" class="residents-icon-btn residents-action-secondary" title="Edit resident" aria-label="Edit resident" @click="startEdit(row)">
                                    <MapIcon name="pencil" />
                                </button>
                                <button type="button" class="residents-icon-btn residents-action-secondary" title="Related reports" aria-label="Related reports" @click="openReports(row)">
                                    <MapIcon name="file" />
                                </button>
                                <div class="residents-menu">
                                    <button type="button" class="residents-icon-btn" title="More actions" aria-label="More actions" :aria-expanded="openMenuId === row.id" aria-haspopup="menu" @click.stop="openMenuId = openMenuId === row.id ? null : row.id">
                                        <MapIcon name="more" />
                                    </button>
                                    <div v-if="openMenuId === row.id" class="residents-menu-panel" role="menu">
                                        <button type="button" class="residents-menu-narrow" role="menuitem" @click="startEdit(row)">Edit resident</button>
                                        <button type="button" class="residents-menu-narrow" role="menuitem" @click="openReports(row)">Related reports</button>
                                        <button v-if="row.residency_status === 'active'" type="button" role="menuitem" @click="startArchive(row)">Archive Resident</button>
                                        <button v-else type="button" role="menuitem" @click="restoreResident(row)">Restore resident</button>
                                    </div>
                                </div>
                            </div>
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>

        <div v-if="page?.meta?.total" class="residents-pager">
            <span>{{ rangeLabel }}</span>
            <label>
                Rows
                <select v-model.number="filters.per_page" aria-label="Rows per page">
                    <option :value="10">10</option>
                    <option :value="25">25</option>
                    <option :value="50">50</option>
                </select>
            </label>
            <div class="cdrrmo-actions">
                <button type="button" class="mapr-button mapr-button-secondary" :disabled="page.meta.current_page <= 1" @click="goToPage(page.meta.current_page - 1)">Previous</button>
                <span>Page {{ page.meta.current_page }} of {{ page.meta.last_page }}</span>
                <button type="button" class="mapr-button mapr-button-secondary" :disabled="page.meta.current_page >= page.meta.last_page" @click="goToPage(page.meta.current_page + 1)">Next</button>
            </div>
        </div>

        <ResidentDetailDrawer
            :open="!!detail"
            :resident="detail"
            :incident-id="filters.incident_id"
            :allow-edit="true"
            @close="closeDetail"
            @retry="detail && openDetail(detail)"
            @edit="startEdit"
            @view-map="viewOnMap"
            @open-report="openReport"
        />
        <ResidentFormDialog :open="formOpen" :resident="editing" @close="formOpen = false" @saved="onSaved" />

        <div v-if="archiveTarget" class="residents-dialog-backdrop">
            <form class="residents-dialog" role="dialog" aria-modal="true" aria-labelledby="archive-title" @submit.prevent="confirmArchive">
                <h3 id="archive-title">Archive Resident</h3>
                <p>Archiving hides {{ archiveTarget.registry_reference }} from the active registry. The record is not deleted.</p>
                <label>
                    Type the resident reference to confirm
                    <input v-model="archiveReference" required :placeholder="archiveTarget.registry_reference" autocomplete="off">
                </label>
                <p v-if="archiveError" class="cdrrmo-error" role="alert">{{ archiveError }}</p>
                <div class="cdrrmo-actions">
                    <button type="submit" class="mapr-button mapr-button-primary">Archive Resident</button>
                    <button type="button" class="mapr-button mapr-button-secondary" @click="archiveTarget = null">Cancel</button>
                </div>
            </form>
        </div>

        <div v-if="inclusionOpen" class="residents-dialog-backdrop">
            <form class="residents-dialog" role="dialog" aria-modal="true" aria-labelledby="inclusion-title" @submit.prevent="confirmInclusion(false)">
                <h3 id="inclusion-title">Add to potentially affected list</h3>
                <p>This list is incident-specific. It does not confirm harm or change saved estimates.</p>
                <dl class="residents-detail-list">
                    <div><dt>Incident</dt><dd>{{ incidentName || 'Select an incident' }}</dd></div>
                    <div><dt>Residents</dt><dd>{{ selectedIds.length }}</dd></div>
                    <div><dt>Barangays</dt><dd>{{ selectedBarangays.join(', ') || '—' }}</dd></div>
                </dl>
                <label>
                    Basis
                    <select v-model="inclusionForm.basis">
                        <option value="manual_review">Manual review</option>
                        <option value="selected_area">Selected area</option>
                        <option value="authorized_report">Authorized report</option>
                    </select>
                </label>
                <label>
                    Reference
                    <input v-model="inclusionForm.reference" maxlength="191">
                </label>
                <label>
                    Notes
                    <textarea v-model="inclusionForm.notes" rows="3" maxlength="1000"></textarea>
                </label>
                <p v-if="inclusionError" class="cdrrmo-error" role="alert">{{ inclusionError }}</p>
                <div class="cdrrmo-actions">
                    <button type="submit" class="mapr-button mapr-button-primary" :disabled="!filters.incident_id">Confirm inclusion</button>
                    <button type="button" class="mapr-button mapr-button-secondary" @click="inclusionOpen = false">Cancel</button>
                </div>
            </form>
        </div>

        <div v-if="reportsOpen" class="residents-dialog-backdrop">
            <div class="residents-dialog" role="dialog" aria-modal="true" aria-labelledby="reports-title">
                <h3 id="reports-title">Related reports</h3>
                <p class="cdrrmo-muted">{{ reportsResident?.display_name }} · {{ reportsResident?.registry_reference }}</p>
                <p v-if="reportsError" class="cdrrmo-error" role="alert">{{ reportsError }}</p>
                <p v-else-if="!reports">Loading related reports…</p>
                <p v-else-if="reportsEmpty">No related reports are available for this resident.</p>
                <template v-else>
                    <section v-for="[label, rows] in reportGroups" :key="label">
                        <h4>{{ label }}</h4>
                        <ul v-if="rows.length" class="residents-history">
                            <li v-for="item in rows" :key="item.report_reference + item.report_type">
                                <strong>{{ item.report_reference }}</strong>
                                <span>{{ item.report_type }} · {{ item.incident }} · {{ item.relationship }} · {{ item.status }}</span>
                                <button v-if="item.open_path" type="button" class="mapr-button mapr-button-secondary" @click="openReport(item)">Open report</button>
                            </li>
                        </ul>
                        <p v-else class="cdrrmo-muted">None in this group.</p>
                    </section>
                </template>
                <button type="button" class="mapr-button mapr-button-secondary" @click="reportsOpen = false">Close</button>
            </div>
        </div>
    </section>
</template>
