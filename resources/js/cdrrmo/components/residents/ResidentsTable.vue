<script setup>
import { computed, ref } from 'vue';
import Button from 'primevue/button';
import Checkbox from 'primevue/checkbox';
import Column from 'primevue/column';
import DataTable from 'primevue/datatable';
import Menu from 'primevue/menu';
import Paginator from 'primevue/paginator';
import Skeleton from 'primevue/skeleton';
import Tag from 'primevue/tag';
import MapIcon from '../../../shared/components/MapIcon.vue';
const props = defineProps({ page: { type: Object, default: null }, selected: { type: Object, required: true }, loading: Boolean, error: { type: String, default: '' }, incidentId: { type: [String, Number], default: '' }, actionBusy: Boolean });
const emit = defineEmits(['toggle', 'toggle-page', 'page', 'retry', 'detail', 'edit', 'reports', 'map', 'archive', 'restore']);
const rows = computed(() => props.page?.data || []);
const pageSelected = computed(() => rows.value.length > 0 && rows.value.every(row => props.selected[row.id]));
const partiallySelected = computed(() => !pageSelected.value && rows.value.some(row => props.selected[row.id]));
const menu = ref(null);
const target = ref(null);
let trigger;
const menuItems = computed(() => target.value ? [
    { label: 'Edit resident', disabled: props.actionBusy, command: () => emit('edit', target.value, trigger) },
    { label: 'Related reports', command: () => emit('reports', target.value, trigger) },
    { separator: true },
    target.value.residency_status === 'active'
        ? { label: 'Archive Resident', class: 'registry-danger-action', command: () => emit('archive', target.value, trigger) }
        : { label: 'Restore resident', disabled: props.actionBusy, command: () => emit('restore', target.value) },
] : []);
const range = computed(() => {
    const meta = props.page?.meta;
    if (!meta?.total) return '0 residents';
    const first = (meta.current_page - 1) * meta.per_page + 1;
    return `${first}–${Math.min(meta.total, first + rows.value.length - 1)} of ${Number(meta.total).toLocaleString('en-PH')} residents`;
});
function openMenu(event, row) { target.value = row; trigger = event.currentTarget; menu.value.toggle(event); }
</script>

<template>
    <section class="registry-table-panel" aria-labelledby="resident-records-title" :aria-busy="loading">
        <header class="registry-section-heading registry-table-heading"><h3 id="resident-records-title">Resident records</h3><span>Sorted by last name</span></header>
        <div v-if="error" class="registry-table-state" role="alert"><MapIcon name="alert-circle" /><h4>Residents could not be loaded</h4><p>{{ error }}</p><Button label="Retry" size="small" severity="secondary" outlined @click="$emit('retry')" /></div>
        <div v-else-if="loading && !page" class="registry-table-skeleton" role="status" aria-label="Loading resident records"><div v-for="row in 6" :key="row"><Skeleton width="22%" height="16px" /><Skeleton width="18%" height="16px" /><Skeleton width="12%" height="16px" /><Skeleton width="20%" height="16px" /></div></div>
        <div v-else-if="!rows.length" class="registry-table-state" role="status"><MapIcon name="users" /><h4>{{ page?.meta?.registry_loaded === false ? 'Registry not loaded' : 'No matching residents' }}</h4><p>{{ page?.meta?.registry_loaded === false ? 'Resident registry data is not available yet. Contact your system administrator to load the authorized registry.' : 'Try another name or reference, or clear your filters.' }}</p></div>
        <DataTable v-else :value="rows" :loading="loading" data-key="id" class="registry-table" :table-props="{ 'aria-label': 'Residents registry' }">
            <Column class="registry-checkbox-column">
                <template #header><Checkbox :model-value="pageSelected" binary :indeterminate="partiallySelected" aria-label="Select this page" @update:model-value="$emit('toggle-page', $event)" /></template>
                <template #body="{ data }"><Checkbox :model-value="!!selected[data.id]" binary :aria-label="`Select ${data.display_name}`" @update:model-value="$emit('toggle', data)" /></template>
            </Column>
            <Column header="Resident" class="registry-name-column"><template #body="{ data }"><div class="registry-name-cell"><Button :label="data.display_name" size="small" text class="registry-name-button" @click="$emit('detail', data, $event)" /><span class="registry-reference">{{ data.registry_reference }}</span><Tag v-if="data.residency_status === 'inactive'" value="Archived" severity="secondary" /></div></template></Column>
            <Column header="Household" class="registry-household-column"><template #body="{ data }"><div class="registry-household-cell"><span>{{ data.household_reference || 'Not recorded' }}</span><small>{{ data.building_reference || 'No building' }}</small></div></template></Column>
            <Column field="barangay" header="Barangay" class="registry-barangay-column"><template #body="{ data }">{{ data.barangay || 'Not recorded' }}</template></Column>
            <Column header="Linked reports" class="registry-report-column"><template #body="{ data }"><Button :label="data.report_count ? `${data.report_count} ${data.report_count === 1 ? 'report' : 'reports'}` : 'No reports'" :aria-label="`Related reports for ${data.display_name}: ${data.report_count || 0}`" size="small" text severity="secondary" class="registry-report-button" @click="$emit('reports', data, $event.currentTarget)"><template #icon><MapIcon name="file-text" /></template></Button></template></Column>
            <Column header="Incident status" class="registry-incident-column"><template #body="{ data }"><Tag v-if="incidentId" :value="data.potentially_affected ? 'Potentially affected' : 'Not listed'" :severity="data.potentially_affected ? 'warn' : 'secondary'" /><span v-else class="registry-muted">No incident selected</span></template></Column>
            <Column header="Actions" class="registry-actions-column"><template #body="{ data }"><div class="registry-row-actions">
                <Button v-tooltip.top="'View details'" :aria-label="`View details for ${data.display_name}`" size="small" severity="secondary" text @click="$emit('detail', data, $event)"><MapIcon name="eye" /></Button>
                <span v-tooltip.top="data.map_available ? 'View on map' : data.map_limitation" class="registry-action-hint" :title="!data.map_available ? data.map_limitation : undefined"><Button :aria-label="data.map_available ? `View ${data.display_name} on map` : data.map_limitation" :disabled="!data.map_available || actionBusy" size="small" severity="secondary" text @click="$emit('map', data)"><MapIcon name="pin" /></Button></span>
                <Button v-tooltip.top="'Edit resident'" :aria-label="`Edit ${data.display_name}`" :disabled="actionBusy" size="small" severity="secondary" text @click="$emit('edit', data, $event.currentTarget)"><MapIcon name="pencil" /></Button>
                <Button v-tooltip.top="'More actions'" :aria-label="`More actions for ${data.display_name}`" size="small" severity="secondary" text aria-haspopup="menu" aria-controls="registry-row-menu" @click="openMenu($event, data)"><MapIcon name="more" /></Button>
            </div></template></Column>
        </DataTable>
        <footer class="registry-pagination"><span role="status">{{ loading ? 'Loading resident records…' : error ? 'Resident records unavailable' : range }}</span><Paginator v-if="page?.meta?.total" :first="(page.meta.current_page - 1) * page.meta.per_page" :rows="page.meta.per_page" :total-records="page.meta.total" :rows-per-page-options="[10, 25, 50]" template="RowsPerPageDropdown PrevPageLink CurrentPageReport NextPageLink" current-page-report-template="{currentPage} of {totalPages}" :pt="{ pcRowPerPageDropdown: { panel: { class: 'cdrrmo-residents-overlay' }, transition: { css: false } } }" :inert="loading || !!error" @page="$emit('page', $event)" /></footer>
        <Menu id="registry-row-menu" ref="menu" :model="menuItems" popup class="cdrrmo-residents-overlay registry-action-menu" :pt="{ transition: { css: false } }" />
    </section>
</template>
