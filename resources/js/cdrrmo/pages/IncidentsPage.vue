<script setup>
import { computed, nextTick, onBeforeUnmount, ref } from 'vue';
import { useRouter } from 'vue-router';
import Button from 'primevue/button';
import Message from 'primevue/message';
import { useToast } from 'primevue/usetoast';
import MapIcon from '../../shared/components/MapIcon.vue';
import IncidentFormDialog from '../components/IncidentFormDialog.vue';
import IncidentSummary from '../components/incidents/IncidentSummary.vue';
import IncidentFilters from '../components/incidents/IncidentFilters.vue';
import IncidentTable from '../components/incidents/IncidentTable.vue';
import IncidentDetailDrawer from '../components/incidents/IncidentDetailDrawer.vue';
import IncidentStatusDialog from '../components/incidents/IncidentStatusDialog.vue';
import { useIncidentRegistry } from '../composables/useIncidentRegistry.js';

const router = useRouter();
const toast = useToast();
const { rows, meta, summary, summaryLoading, summaryError, loading, error, filters, hasFilters, updateFilters, reload, setPage } = useIncidentRegistry();
const types = computed(() => summary.value?.disaster_types || []);
const barangays = computed(() => summary.value?.barangays || []);
const formOpen = ref(false);
const editing = ref(null);
const detailOpen = ref(false);
const detail = ref(null);
const detailLoading = ref(false);
const detailError = ref('');
const statusTarget = ref(null);
const actionError = ref('');
const editLoadingId = ref(null);
const addButton = ref(null);
let detailTicket = 0;
let editTicket = 0;
let opener = null;

function rememberFocus() { opener = document.activeElement; }
async function restoreFocus() {
    await nextTick();
    const target = opener?.isConnected ? opener : addButton.value?.$el;
    target?.focus();
}
function openCreate() {
    rememberFocus();
    editing.value = null;
    formOpen.value = true;
}
async function openEdit(row) {
    if (editLoadingId.value) return;
    if (!detailOpen.value) rememberFocus();
    const ticket = ++editTicket;
    editLoadingId.value = row.id;
    actionError.value = '';
    try {
        const incident = row.occurred_at_local ? row : (await window.axios.get(`/cdrrmo/api/incidents/${row.id}`)).data;
        if (ticket !== editTicket) return;
        editing.value = incident;
        detailOpen.value = false;
        formOpen.value = true;
    } catch {
        if (ticket === editTicket) actionError.value = 'Incident details could not be loaded. Try editing again.';
    } finally {
        if (ticket === editTicket) editLoadingId.value = null;
    }
}
async function openDetail(row, retry = false) {
    if (!retry) rememberFocus();
    const ticket = ++detailTicket;
    detail.value = row;
    detailOpen.value = true;
    detailLoading.value = true;
    detailError.value = '';
    try {
        const { data } = await window.axios.get(`/cdrrmo/api/incidents/${row.id}`);
        if (ticket === detailTicket) detail.value = data;
    } catch {
        if (ticket === detailTicket) detailError.value = 'Incident details could not be loaded.';
    } finally {
        if (ticket === detailTicket) detailLoading.value = false;
    }
}
function closeDetail() { detailTicket++; detailOpen.value = false; }
function openMap(row) { router.push({ path: '/map', query: { incident_id: row.id } }); }
function onSaved(message) {
    formOpen.value = false;
    toast.add({ severity: 'success', summary: message, life: 4000 });
    reload();
}
function askStatus(row, next, trigger) {
    opener = trigger || document.activeElement;
    statusTarget.value = { ...row, next };
}
function onStatusSaved(message) {
    statusTarget.value = null;
    toast.add({ severity: 'success', summary: message, life: 5000 });
    reload();
}
onBeforeUnmount(() => { detailTicket++; editTicket++; });
</script>

<template>
    <section class="cdrrmo-incidents" aria-labelledby="incidents-title">
        <header class="incident-page-heading">
            <div>
                <h2 id="incidents-title">Disaster Incidents</h2>
                <p>Manage disaster events and their associated barangays.</p>
            </div>
            <Button ref="addButton" label="Add Incident" size="small" :disabled="!summary" @click="openCreate"><template #icon><MapIcon name="plus" /></template></Button>
        </header>
        <IncidentSummary :summary="summary" :loading="summaryLoading" :error="summaryError" @retry="reload" />
        <IncidentFilters :model-value="filters" :types="types" :barangays="barangays" :has-filters="hasFilters" @update:model-value="updateFilters" @reset="updateFilters({ search: '', disaster_type_id: '', incident_status: '', barangay_id: '' })" />
        <Message v-if="actionError" severity="error" :closable="false">{{ actionError }}</Message>
        <IncidentTable :rows="rows" :meta="meta" :loading="loading" :error="error" :has-filters="hasFilters" :can-create="!!summary" :edit-loading-id="editLoadingId" @retry="reload" @create="openCreate" @reset="updateFilters({ search: '', disaster_type_id: '', incident_status: '', barangay_id: '' })" @page="setPage" @detail="openDetail" @edit="openEdit" @map="openMap" @status="askStatus" />
        <IncidentFormDialog :open="formOpen" :incident="editing" :types="types" :barangays="barangays" @close="formOpen = false" @saved="onSaved" @hide="restoreFocus" />
        <IncidentDetailDrawer :open="detailOpen" :incident="detail" :loading="detailLoading" :error="detailError" :editing="!!editLoadingId" @close="closeDetail" @retry="openDetail(detail, true)" @edit="openEdit(detail)" @map="openMap(detail)" @report="router.push($event)" @hide="!formOpen && restoreFocus()" />
        <IncidentStatusDialog :target="statusTarget" @close="statusTarget = null" @saved="onStatusSaved" @hide="restoreFocus" />
    </section>
</template>
