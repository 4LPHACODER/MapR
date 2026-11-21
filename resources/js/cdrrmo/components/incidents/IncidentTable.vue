<script setup>
import { computed, ref } from 'vue';
import Button from 'primevue/button';
import Column from 'primevue/column';
import DataTable from 'primevue/datatable';
import Menu from 'primevue/menu';
import Message from 'primevue/message';
import Paginator from 'primevue/paginator';
import ProgressSpinner from 'primevue/progressspinner';
import Tag from 'primevue/tag';
import MapIcon from '../../../shared/components/MapIcon.vue';

const props = defineProps({ rows: { type: Array, default: () => [] }, meta: { type: Object, required: true }, loading: Boolean, error: { type: String, default: '' }, hasFilters: Boolean, canCreate: Boolean, editLoadingId: { type: Number, default: null } });
const emit = defineEmits(['retry', 'create', 'reset', 'page', 'detail', 'edit', 'map', 'status']);
const menu = ref(null);
const menuTarget = ref(null);
let menuOpener;
const menuItems = computed(() => [{ label: menuTarget.value?.incident_status === 'active' ? 'Close incident' : 'Reopen incident', command: () => emit('status', menuTarget.value, menuTarget.value.incident_status === 'active' ? 'closed' : 'active', menuOpener) }]);
const range = computed(() => {
    if (!props.meta.total) return '0 incidents';
    const start = (props.meta.current_page - 1) * props.meta.per_page + 1;
    return `${start}–${Math.min(props.meta.total, start + props.rows.length - 1)} of ${props.meta.total.toLocaleString('en-PH')} incidents`;
});
function openMenu(event, row) { menuTarget.value = row; menuOpener = event.currentTarget; menu.value.toggle(event); }
</script>

<template>
    <section class="incident-ledger" aria-labelledby="incident-ledger-title" :aria-busy="loading">
        <header class="incident-panel-heading incident-ledger-heading">
            <div><h3 id="incident-ledger-title">Incident records</h3><p>Latest occurrences first · Philippine time (PHT)</p></div>
            <Button label="Refresh" size="small" severity="secondary" outlined :disabled="loading" @click="$emit('retry')"><template #icon><MapIcon name="refresh" /></template></Button>
        </header>
        <div v-if="error" class="incident-table-state" role="alert">
            <Message severity="error" :closable="false">{{ error }}</Message>
            <Button label="Try again" severity="secondary" outlined size="small" @click="$emit('retry')" />
        </div>
        <div v-else-if="loading && !rows.length" class="incident-table-state" role="status"><ProgressSpinner aria-label="Loading incidents" /><p>Loading incidents…</p></div>
        <div v-else-if="!rows.length" class="incident-table-state" role="status">
            <span class="incident-empty-icon"><MapIcon :name="hasFilters ? 'search' : 'disaster'" /></span>
            <h4>{{ hasFilters ? 'No matching incidents' : 'No disaster incidents yet' }}</h4>
            <p>{{ hasFilters ? 'Try another search or clear the filters to see more incidents.' : 'Add an incident to link disaster events with their barangays.' }}</p>
            <Button v-if="hasFilters" label="Clear filters" severity="secondary" outlined size="small" @click="$emit('reset')" />
            <Button v-else label="Add Incident" size="small" :disabled="!canCreate" @click="$emit('create')" />
        </div>
        <DataTable v-else :value="rows" :loading="loading" data-key="id" class="incident-data-table" :table-props="{ 'aria-label': 'Disaster incidents' }">
            <Column header="Incident" class="incident-name-column">
                <template #body="{ data }">
                    <div class="incident-name-cell">
                        <span class="incident-reference">{{ data.incident_reference }}</span>
                        <Button :label="data.incident_name" text size="small" class="incident-name-link" @click="$emit('detail', data)" />
                        <div class="incident-type-line"><span>{{ data.disaster_type || 'Type not recorded' }}</span><Tag v-if="data.is_demo" value="Demo data" severity="secondary" /></div>
                    </div>
                </template>
            </Column>
            <Column header="Associated barangays" class="incident-barangays-column"><template #body="{ data }"><span class="incident-barangay-names">{{ data.barangays?.map((barangay) => barangay.name).join(', ') || 'Not recorded' }}</span></template></Column>
            <Column header="Status"><template #body="{ data }"><Tag :value="data.incident_status === 'closed' ? 'Closed' : 'Active'" :severity="data.incident_status === 'closed' ? 'secondary' : 'info'" class="incident-status-tag"><template #icon><MapIcon :name="data.incident_status === 'closed' ? 'lock' : 'circle-check'" /></template></Tag></template></Column>
            <Column header="Occurrence"><template #body="{ data }"><span class="incident-occurrence">{{ data.occurred_at_display }}</span></template></Column>
            <Column header="Actions" class="incident-actions-column">
                <template #body="{ data }">
                    <div class="incident-row-actions">
                        <Button :aria-label="`Edit ${data.incident_reference}`" title="Edit incident" severity="secondary" text size="small" :disabled="!!editLoadingId" :loading="editLoadingId === data.id" @click="$emit('edit', data)"><MapIcon name="pencil" /></Button>
                        <Button :aria-label="`Open Assessment Map for ${data.incident_reference}`" title="Open Assessment Map" severity="secondary" text size="small" @click="$emit('map', data)"><MapIcon name="map" /></Button>
                        <Button :aria-label="`More actions for ${data.incident_reference}`" title="More actions" severity="secondary" text size="small" aria-haspopup="menu" aria-controls="incident-actions-menu" @click="openMenu($event, data)"><MapIcon name="more" /></Button>
                    </div>
                </template>
            </Column>
        </DataTable>
        <footer class="incident-ledger-footer">
            <span role="status">{{ loading ? 'Loading incident records…' : error ? 'Incident records unavailable' : range }}</span>
            <Paginator v-if="meta.total" :first="(meta.current_page - 1) * meta.per_page" :rows="meta.per_page" :total-records="meta.total" :rows-per-page-options="[10, 25, 50]" template="RowsPerPageDropdown PrevPageLink CurrentPageReport NextPageLink" current-page-report-template="{currentPage} of {totalPages}" :pt="{ pcRowPerPageDropdown: { panel: { class: 'cdrrmo-incidents-overlay' }, transition: { css: false } } }" :class="{ 'is-loading': loading || error }" :inert="loading || !!error" @page="$emit('page', $event)" />
        </footer>
        <Menu id="incident-actions-menu" ref="menu" :model="menuItems" popup class="cdrrmo-incidents-overlay incident-actions-menu" :pt="{ transition: { css: false } }" />
    </section>
</template>
