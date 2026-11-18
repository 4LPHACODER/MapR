<script setup>
import { computed, ref } from 'vue';
import Button from 'primevue/button';
import Checkbox from 'primevue/checkbox';
import Column from 'primevue/column';
import DataTable from 'primevue/datatable';
import Menu from 'primevue/menu';
import Paginator from 'primevue/paginator';
import Select from 'primevue/select';
import Skeleton from 'primevue/skeleton';
import Tag from 'primevue/tag';
import MapIcon from '../../../shared/components/MapIcon.vue';
const props = defineProps({ page: { type: Object, default: null }, selected: { type: Object, required: true }, loading: Boolean, error: { type: String, default: '' }, incidentId: { type: [String, Number], default: '' }, actionBusy: Boolean, readOnly: Boolean });
const emit = defineEmits(['toggle', 'toggle-page', 'page', 'retry', 'detail', 'edit', 'reports', 'map', 'archive', 'restore']);
const rows = computed(() => props.page?.data || []);
const pageSizes = [10, 25, 50];
const controlSize = computed(() => props.readOnly ? 'small' : undefined);
const overlayClass = computed(() => props.readOnly ? 'cdrrmo-residents-overlay barangay-residents-overlay' : 'cdrrmo-residents-overlay');
const pageSelected = computed(() => rows.value.length > 0 && rows.value.every(row => props.selected[row.id]));
const partiallySelected = computed(() => !pageSelected.value && rows.value.some(row => props.selected[row.id]));
const menu = ref(null);
const target = ref(null);
const menuOpen = ref(false);
let trigger;
const menuItems = computed(() => {
    if (!target.value) return [];
    const reports = { label: 'Related reports', command: () => emit('reports', target.value, trigger) };
    if (props.readOnly) return [reports];
    return [
        { label: 'Edit resident', disabled: props.actionBusy, command: () => emit('edit', target.value, trigger) },
        reports,
        { separator: true },
        target.value.residency_status === 'active'
            ? { label: 'Archive Resident', class: 'registry-danger-action', command: () => emit('archive', target.value, trigger) }
            : { label: 'Restore resident', disabled: props.actionBusy, command: () => emit('restore', target.value) },
    ];
});
const tableProps = { role: 'table', 'aria-label': 'Residents registry' };
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
        <DataTable v-else :value="rows" :loading="loading" :size="controlSize" data-key="id" class="registry-table" :table-props="tableProps">
            <Column class="registry-checkbox-column">
                <template #header>
                    <Checkbox :model-value="pageSelected" binary :size="controlSize" :indeterminate="partiallySelected" aria-label="Select this page" @update:model-value="$emit('toggle-page', $event)" />
                    <span v-if="readOnly" aria-hidden="true" class="registry-mobile-label">Select this page</span>
                </template>
                <template #body="{ data }"><Checkbox :model-value="!!selected[data.id]" binary :size="controlSize" :aria-label="`Select ${data.display_name}`" @update:model-value="$emit('toggle', data)" /></template>
            </Column>
            <Column header="Resident" class="registry-name-column"><template #body="{ data }"><div class="registry-name-cell"><Button :label="data.display_name" size="small" text class="registry-name-button" @click="$emit('detail', data, $event)" /><span class="registry-reference">{{ data.registry_reference }}</span><Tag v-if="data.residency_status === 'inactive'" value="Archived" severity="secondary" /></div></template></Column>
            <Column header="Household" class="registry-household-column"><template #body="{ data }"><div class="registry-household-cell"><small v-if="readOnly" class="registry-mobile-label" aria-hidden="true">Household</small><span>{{ data.household_reference || 'Not recorded' }}</span><small>{{ data.building_reference || 'No building' }}</small></div></template></Column>
            <Column field="barangay" header="Barangay" class="registry-barangay-column"><template #body="{ data }"><span v-if="readOnly" class="registry-mobile-label" aria-hidden="true">Barangay</span>{{ data.barangay || 'Not recorded' }}</template></Column>
            <Column :header="readOnly ? 'Reports' : 'Linked reports'" class="registry-report-column">
                <template #body="{ data }">
                    <Button v-if="readOnly" :aria-label="`Related reports for ${data.display_name}: ${data.report_count || 0}`" size="small" text severity="secondary" class="registry-report-button" @click="$emit('reports', data, $event.currentTarget)">
                        <Tag :value="data.report_count ? `${data.report_count} ${data.report_count === 1 ? 'report' : 'reports'}` : 'No reports'" :severity="data.report_count ? 'info' : 'secondary'" />
                    </Button>
                    <Button v-else :label="data.report_count ? `${data.report_count} ${data.report_count === 1 ? 'report' : 'reports'}` : 'No reports'" :aria-label="`Related reports for ${data.display_name}: ${data.report_count || 0}`" size="small" text severity="secondary" class="registry-report-button" @click="$emit('reports', data, $event.currentTarget)"><template #icon><MapIcon name="file-text" /></template></Button>
                </template>
            </Column>
            <Column header="Incident status" class="registry-incident-column"><template #body="{ data }"><span v-if="readOnly" class="registry-mobile-label" aria-hidden="true">Incident status</span><Tag v-if="incidentId" :value="data.potentially_affected ? 'Potentially affected' : 'Not listed'" :severity="data.potentially_affected ? 'warn' : 'secondary'" /><span v-else class="registry-muted">{{ readOnly ? 'No incident' : 'No incident selected' }}</span></template></Column>
            <Column header="Actions" class="registry-actions-column"><template #body="{ data }"><div class="registry-row-actions">
                <Button v-tooltip.top="'View details'" :aria-label="`View details for ${data.display_name}`" size="small" severity="secondary" text @click="$emit('detail', data, $event)"><MapIcon name="eye" /></Button>
                <span v-tooltip.top="data.map_available ? 'View on map' : data.map_limitation" class="registry-action-hint" :title="!data.map_available ? data.map_limitation : undefined"><Button :aria-label="data.map_available ? `View ${data.display_name} on map` : data.map_limitation" :disabled="!data.map_available || actionBusy" size="small" severity="secondary" text @click="$emit('map', data)"><MapIcon name="pin" /></Button></span>
                <Button v-if="readOnly" v-tooltip.top="'Related reports'" title="Related reports" :aria-label="`View reports for ${data.display_name}`" size="small" severity="secondary" text @click="$emit('reports', data, $event.currentTarget)"><MapIcon name="file" /></Button>
                <Button v-else v-tooltip.top="'Edit resident'" :aria-label="`Edit ${data.display_name}`" :disabled="actionBusy" size="small" severity="secondary" text @click="$emit('edit', data, $event.currentTarget)"><MapIcon name="pencil" /></Button>
                <Button v-tooltip.top="'More actions'" :aria-label="`More actions for ${data.display_name}`" size="small" severity="secondary" text aria-haspopup="menu" aria-controls="registry-row-menu" :aria-expanded="menuOpen && target?.id === data.id" @click="openMenu($event, data)"><MapIcon name="more" /></Button>
            </div></template></Column>
        </DataTable>
        <footer class="registry-pagination">
            <span role="status">{{ loading ? 'Loading resident records…' : error ? 'Resident records unavailable' : range }}</span>
            <Paginator
                v-if="page?.meta?.total"
                :first="(page.meta.current_page - 1) * page.meta.per_page"
                :rows="page.meta.per_page"
                :total-records="page.meta.total"
                :rows-per-page-options="pageSizes"
                :template="readOnly ? 'PrevPageLink CurrentPageReport NextPageLink' : 'RowsPerPageDropdown PrevPageLink CurrentPageReport NextPageLink'"
                current-page-report-template="{currentPage} of {totalPages}"
                :pt="{ pcRowPerPageDropdown: { panel: { class: 'cdrrmo-residents-overlay' }, transition: { css: false } } }"
                :inert="!!error || (!readOnly && loading)"
                @page="$emit('page', $event)"
            >
                <template v-if="readOnly" #start>
                    <div class="registry-page-size">
                        <span aria-hidden="true">Rows</span>
                        <Select :model-value="page.meta.per_page" :options="pageSizes" size="small" aria-label="Rows per page" :panel-class="overlayClass" :pt="{ transition: { css: false } }" @update:model-value="$emit('page', { page: 0, rows: $event })" />
                    </div>
                </template>
            </Paginator>
        </footer>
        <Menu id="registry-row-menu" ref="menu" :model="menuItems" popup :class="[overlayClass, 'registry-action-menu']" :pt="{ transition: { css: false } }" @show="menuOpen = true" @hide="menuOpen = false" />
    </section>
</template>
