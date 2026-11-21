<script setup>
import { computed, inject, nextTick, onBeforeUnmount, onMounted, reactive, ref, watch } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import MapIcon from '../../shared/components/MapIcon.vue';
import ResidentDetailDrawer from '../../shared/components/ResidentDetailDrawer.vue';
import ResidentFormDialog from '../components/ResidentFormDialog.vue';
import Button from 'primevue/button';
import Message from 'primevue/message';
import ResidentsSummary from '../components/residents/ResidentsSummary.vue';
import ResidentsFilters from '../components/residents/ResidentsFilters.vue';
import ResidentsTable from '../components/residents/ResidentsTable.vue';
import ResidentsDialogs from '../components/residents/ResidentsDialogs.vue';

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
const actionBusy = ref(false);
const optionsError = ref('');
let composing = false;
let reportsTicket = 0;
let actionTrigger = null;
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
    report_availability: 'all',
    area_scope: route.query.area === '1',
    inclusion: '',
    quality: '',
    status: 'active',
    per_page: 25,
    page: 1,
    ...window.history.state?.residentRegistryFilters,
});

const selectedList = computed(() => Object.values(selected.value));
const selectedIds = computed(() => selectedList.value.map((row) => row.id));
const selectionHasLocation = computed(() => selectedList.value.some((row) => row.map_available));
const pageRows = computed(() => page.value?.data || []);
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

function togglePage(checked) {
    const next = { ...selected.value };
    if (checked) pageRows.value.forEach((row) => { next[row.id] = row; });
    else pageRows.value.forEach((row) => { delete next[row.id]; });
    selected.value = next;
}

function goToPage(nextPage) {
    filters.page = nextPage;
    reloadList();
}

async function openDetail(row, event) {
    const ticket = ++detailTicket;
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

async function startEdit(row, trigger) {
    if (actionBusy.value) return;
    actionTrigger = trigger || detailTrigger.value || document.activeElement;
    actionBusy.value = true;
    mapNotice.value = '';
    try {
        editing.value = row.first_name ? row : (await window.axios.get(`${apiBase}/residents/${row.id}`)).data;
        detail.value = null;
        formOpen.value = true;
    } catch { mapNotice.value = 'Resident details could not be loaded. Try editing again.'; }
    finally { actionBusy.value = false; }
}

function restoreActionFocus() { nextTick(() => actionTrigger?.isConnected && actionTrigger.focus()); }

function startAdd(event) {
    actionTrigger = event?.currentTarget || document.activeElement;
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
    if (actionBusy.value) return;
    const list = Array.isArray(rows) ? rows : [rows];
    mapNotice.value = '';
    actionBusy.value = true;
    try {
        const { data } = await window.axios.post(`${apiBase}/residents/map-focus`, { resident_ids: list.map((row) => row.id) });
        if (data.missing) mapNotice.value = `${data.missing} selected resident${data.missing === 1 ? '' : 's'} have no usable location.`;
        if (data.mapped) router.push({ path: '/map', query: { focus: 'residents' } });
    } catch { mapNotice.value = 'The selected residents could not be shown on the map. Try again.'; }
    finally { actionBusy.value = false; }
}

async function openReports(row, trigger) {
    const ticket = ++reportsTicket;
    if (!reportsOpen.value) actionTrigger = trigger || document.activeElement;
    reportsResident.value = row;
    reports.value = null;
    reportsError.value = '';
    reportsOpen.value = true;
    try {
        const data = (await window.axios.get(`${apiBase}/residents/${row.id}/reports`, {
            params: { incident_id: filters.incident_id || undefined },
        })).data;
        if (ticket === reportsTicket) reports.value = data;
    } catch {
        if (ticket !== reportsTicket) return;
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
    rememberFilters();
    router.push({
        path: item.open_path,
        query: { return: route.fullPath },
        state: { fromResidentRegistry: true },
    });
}

function rememberFilters() {
    window.history.replaceState({ ...window.history.state, residentRegistryFilters: { ...filters } }, '');
}

function startArchive(row, trigger) {
    actionTrigger = trigger || document.activeElement;
    archiveTarget.value = row;
    archiveReference.value = '';
    archiveError.value = '';
}

async function confirmArchive() {
    if (actionBusy.value) return;
    actionBusy.value = true;
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
    } finally { actionBusy.value = false; }
}

async function restoreResident(row) {
    if (actionBusy.value) return;
    actionBusy.value = true;
    mapNotice.value = '';
    try {
        await window.axios.post(`${apiBase}/residents/${row.id}/restore`);
        notice.value = 'Resident restored.';
        reloadAll();
    } catch { mapNotice.value = 'The resident could not be restored. Try again.'; }
    finally { actionBusy.value = false; }
}

function openInclusion(event) {
    actionTrigger = event?.currentTarget || document.activeElement;
    inclusionError.value = '';
    inclusionForm.basis = 'manual_review';
    inclusionForm.reference = '';
    inclusionForm.notes = '';
    inclusionOpen.value = true;
}

async function confirmInclusion(remove = false) {
    if (actionBusy.value) return;
    inclusionError.value = '';
    if (!filters.incident_id) {
        inclusionError.value = 'An incident is required before residents can be added to the potentially affected list.';
        return;
    }
    actionBusy.value = true;
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
        if (remove) mapNotice.value = inclusionError.value;
    } finally { actionBusy.value = false; }
}

function updateFilters(patch) { Object.assign(filters, patch); }
function clearRegistryScope() {
    filters.household_id = '';
    filters.building_id = '';
    router.replace({ query: { ...route.query, household_id: undefined, building_id: undefined } });
}
function submitSearch() {
    clearTimeout(searchTimer);
    if (composing) return;
    filters.page = 1;
    reloadAll();
}
function onComposition(value) {
    composing = value;
    clearTimeout(searchTimer);
    if (!value) searchTimer = setTimeout(submitSearch, 300);
}
function paginate(event) {
    if (event.rows !== filters.per_page) filters.per_page = event.rows;
    else goToPage(event.page + 1);
}
async function loadOptions() {
    optionsError.value = '';
    try {
        const { data } = await window.axios.get(`${apiBase}/summary`);
        barangays.value = data.barangays || [];
        incidents.value = (await window.axios.get(`${apiBase}/incidents`)).data.data || [];
    } catch { optionsError.value = 'Some filter options could not be loaded. Refresh to try again.'; }
}
function refresh() { loadOptions(); reloadAll(); }

watch(filters, () => {
    if (route.path === '/residents' && window.history.state?.residentRegistryFilters) rememberFilters();
});

watch(() => filters.search, () => {
    if (!ready.value || composing) return;
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
    await loadOptions();
    await loadAreaContext();
    if (route.query.area === '1' || areaContext.value?.active) filters.area_scope = true;
    ready.value = true;
    await reloadAll();
});

onBeforeUnmount(() => {
    listTicket++;
    summaryTicket++;
    detailTicket++;
    reportsTicket++;
    clearTimeout(searchTimer);
});
</script>

<template>
    <section class="cdrrmo-residents" aria-labelledby="residents-title">
        <header class="registry-page-heading">
            <div><h2 id="residents-title">Residents</h2><p>Manage authorized resident records and review incident-linked information.</p></div>
            <div class="registry-heading-actions"><Button label="Add Resident" size="small" @click="startAdd"><template #icon><MapIcon name="plus" /></template></Button><Button label="Refresh" size="small" severity="secondary" outlined :disabled="loading" @click="refresh"><template #icon><MapIcon name="refresh" /></template></Button></div>
        </header>
        <Message v-if="notice" severity="success" :closable="false">{{ notice }}</Message>
        <Message v-if="mapNotice" severity="warn" :closable="false">{{ mapNotice }}</Message>
        <ResidentsSummary :summary="summary" :inclusion="filters.inclusion" :status="filters.status" :incident-id="filters.incident_id" @registered="showRegistered" @affected="showAffected" />
        <ResidentsFilters :model-value="filters" :incidents="incidents" :barangays="barangays" @update:model-value="updateFilters" @clear="clearFilters" @submit="submitSearch" @composition="onComposition" />
        <Message v-if="optionsError" severity="warn" :closable="false">{{ optionsError }} <Button label="Retry" size="small" text @click="loadOptions" /></Message>
        <div v-if="filters.household_id || filters.building_id" class="registry-scope-note"><MapIcon name="home" /><span>Showing the {{ filters.household_id ? 'household' : 'building' }} selected from the registry.</span><Button label="Clear scope" size="small" severity="secondary" text @click="clearRegistryScope" /></div>
        <div v-if="filters.area_scope && areaContext?.active" class="registry-area-banner"><div><strong>{{ areaContext.label }}</strong><p>Barangays: {{ areaContext.barangays?.join(', ') || 'Not recorded' }}</p></div><div><Button as="router-link" to="/map" label="Return to map" size="small" severity="secondary" outlined /><Button label="Clear area filter" size="small" severity="secondary" text @click="clearAreaFilter" /></div></div>
        <div v-if="selectedIds.length" class="registry-selection" role="region" aria-label="Selected residents"><div class="registry-selection-heading"><strong>{{ selectedIds.length }} selected</strong><Button label="Clear selection" size="small" severity="secondary" text @click="clearSelection" /></div><div class="registry-selection-actions"><Button label="Add to incident list" size="small" :disabled="actionBusy" @click="openInclusion" /><Button label="Remove from incident list" size="small" severity="secondary" outlined :disabled="!filters.incident_id || actionBusy" @click="confirmInclusion(true)" /><Button v-if="selectionHasLocation" label="View Selected on Map" size="small" severity="secondary" outlined :disabled="actionBusy" @click="viewOnMap(selectedList)"><template #icon><MapIcon name="pin" /></template></Button></div><p v-if="!filters.incident_id">An incident is required before residents can be added to the potentially affected list.</p></div>
        <ResidentsTable :page="page" :selected="selected" :loading="loading" :error="error" :incident-id="filters.incident_id" :action-busy="actionBusy" @toggle="toggleRow" @toggle-page="togglePage" @page="paginate" @retry="reloadAll" @detail="openDetail" @edit="startEdit" @reports="openReports" @map="viewOnMap" @archive="startArchive" @restore="restoreResident" />
        <ResidentDetailDrawer :open="!!detail" :resident="detail" :incident-id="filters.incident_id" :allow-edit="true" @close="closeDetail" @retry="detail && openDetail(detail)" @edit="startEdit" @view-map="viewOnMap" @open-report="openReport" />
        <ResidentFormDialog :open="formOpen" :resident="editing" @close="formOpen = false" @saved="onSaved" @hide="!detail && restoreActionFocus()" />
        <ResidentsDialogs :archive-target="archiveTarget" v-model:archive-reference="archiveReference" :archive-error="archiveError" :inclusion-open="inclusionOpen" :inclusion-form="inclusionForm" :inclusion-error="inclusionError" :incident-id="filters.incident_id" :incident-name="incidentName" :selected-count="selectedIds.length" :selected-barangays="selectedBarangays" :reports-open="reportsOpen" :reports-resident="reportsResident" :reports="reports" :reports-error="reportsError" :report-groups="reportGroups" :reports-empty="reportsEmpty" :busy="actionBusy" @close-archive="archiveTarget = null" @archive="confirmArchive" @close-inclusion="inclusionOpen = false" @inclusion="confirmInclusion(false)" @close-reports="reportsTicket++; reportsOpen = false" @retry-reports="openReports(reportsResident)" @report="openReport" @hide="restoreActionFocus" />
    </section>
</template>
