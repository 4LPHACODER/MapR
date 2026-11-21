<script setup>
import { computed, nextTick, onMounted, ref, watch } from 'vue';
import Button from 'primevue/button';
import Column from 'primevue/column';
import DataTable from 'primevue/datatable';
import InputText from 'primevue/inputtext';
import Message from 'primevue/message';
import Paginator from 'primevue/paginator';
import ProgressSpinner from 'primevue/progressspinner';
import Select from 'primevue/select';
import Tag from 'primevue/tag';
import { useConfirm } from 'primevue/useconfirm';
import MapIcon from '../../shared/components/MapIcon.vue';

const confirm = useConfirm();
const rows = ref([]);
const barangayNames = ref(new Map());
const loading = ref(true);
const loadError = ref('');
const feedback = ref(null);
const updatingId = ref(null);
const searchInput = ref(null);
const search = ref('');
const role = ref(null);
const status = ref(null);
const first = ref(0);
const pageSize = 10;
const statusOptions = [
    { label: 'All statuses', value: null },
    { label: 'Active', value: 'active' },
    { label: 'Inactive', value: 'inactive' },
];

const roleOptions = computed(() => {
    const roles = new Map(rows.value.filter(user => user.role).map(user => [user.role_id, user.role]));
    const options = Array.from(roles, ([value, item]) => ({ label: item.name || item.code, value }));
    options.sort((a, b) => a.label.localeCompare(b.label, 'en-PH'));
    return [{ label: 'All roles', value: null }, ...options];
});
const metrics = computed(() => {
    const total = rows.value.length;
    const active = rows.value.filter(user => user.account_status === 'active').length;

    return [
        { label: 'Total users', value: total, icon: 'users', tone: 'total' },
        { label: 'Active users', value: active, icon: 'circle-check', tone: 'active' },
        { label: 'Inactive users', value: total - active, icon: 'user-round-x', tone: 'inactive' },
    ];
});

const hasFilters = computed(() => Boolean(search.value || role.value !== null || status.value));
const filteredRows = computed(() => {
    const query = search.value.trim().toLowerCase();
    return rows.value.filter(user =>
        (role.value === null || role.value === user.role_id)
        && (!status.value || status.value === user.account_status)
        && (!query || `${userName(user)} ${user.email}`.toLowerCase().includes(query)));
});
const total = computed(() => filteredRows.value.length);
const visibleRows = computed(() => filteredRows.value.slice(first.value, first.value + pageSize));
const range = computed(() => total.value
    ? `${formatCount(first.value + 1)}–${formatCount(first.value + visibleRows.value.length)} of ${formatCount(total.value)} ${total.value === 1 ? 'user' : 'users'}`
    : '0 users');

watch([search, role, status], () => { first.value = 0; });
watch(total, (count) => {
    first.value = Math.min(first.value, Math.max(0, Math.ceil(count / pageSize) - 1) * pageSize);
});

function userName(user) {
    return [user.first_name, user.last_name].filter(Boolean).join(' ');
}

function formatCount(value) {
    return value.toLocaleString('en-PH');
}

function barangayLabel(user) {
    if (user.barangay_name) return user.barangay_name;
    if (user.barangay_id == null) return 'Unassigned';
    return barangayNames.value.get(String(user.barangay_id)) || `Barangay #${user.barangay_id}`;
}

function focusSearch() {
    searchInput.value?.$el.focus();
}

function clearSearch() {
    search.value = '';
    focusSearch();
}

function clearFilters() {
    search.value = '';
    role.value = null;
    status.value = null;
    focusSearch();
}

async function load() {
    loading.value = true;
    loadError.value = '';
    feedback.value = null;
    try {
        const { data } = await window.axios.get('/cdrrmo/api/users');
        if (!Array.isArray(data?.data)) throw new Error('Unexpected user list response');
        rows.value = data.data;
        if (rows.value.some(user => user.barangay_id != null && !user.barangay_name)) {
            const catalog = await window.axios.get('/cdrrmo/api/summary').catch(() => null);
            if (Array.isArray(catalog?.data?.barangays)) {
                barangayNames.value = new Map(catalog.data.barangays.map(({ id, name }) => [String(id), name]));
            }
        }
    } catch {
        loadError.value = 'User accounts could not be loaded.';
    } finally {
        loading.value = false;
    }
}

function requestStatusChange(user, event) {
    if (loading.value || updatingId.value !== null) return;

    const trigger = event.currentTarget;
    const deactivating = user.account_status === 'active';
    const action = deactivating ? 'Deactivate' : 'Activate';
    const next = deactivating ? 'inactive' : 'active';
    const consequence = deactivating ? 'This account will no longer be able to sign in.' : 'This will restore sign-in access for this account.';

    trigger.focus();
    confirm.require({
        target: trigger,
        header: `${action} account?`,
        message: `${action} ${user.email}? ${consequence}`,
        acceptLabel: action,
        rejectLabel: 'Cancel',
        defaultFocus: 'reject',
        closeOnEscape: true,
        acceptProps: { size: 'small', severity: deactivating ? 'danger' : 'success', class: 'user-status-confirm' },
        rejectProps: { size: 'small', severity: 'secondary', outlined: true },
        accept: () => toggleStatus(user, next, trigger),
    });
}

async function toggleStatus(user, next, trigger) {
    if (updatingId.value !== null) return;

    updatingId.value = user.id;
    feedback.value = null;
    try {
        await window.axios.patch(`/cdrrmo/api/users/${user.id}/status`, { account_status: next });
        user.account_status = next;
        feedback.value = { severity: 'success', text: `Account ${user.email} is now ${next}.` };
    } catch (err) {
        feedback.value = {
            severity: 'error',
            text: (err.response?.status < 500 && err.response.data?.message) || 'Status update failed. Try again.',
        };
    } finally {
        updatingId.value = null;
        await nextTick();
        const focused = document.activeElement;
        if (focused === document.body || (!trigger.isConnected && focused?.closest('.p-confirmdialog'))) {
            if (trigger.isConnected) trigger.focus();
            else focusSearch();
        }
    }
}

onMounted(load);
</script>

<template>
    <section class="user-management @container grid min-w-0 gap-4 text-[13px]/5 text-surface-900" aria-labelledby="user-management-title">
        <header class="flex flex-wrap items-center justify-between gap-x-4 gap-y-2">
            <div>
                <h2 id="user-management-title" class="text-2xl/8 font-semibold tracking-tight">User Management</h2>
                <p class="mt-0.5 text-surface-600">View roles and barangay assignments, and manage account access.</p>
            </div>
            <Button label="Refresh" size="small" severity="secondary" outlined :disabled="loading || updatingId !== null" @click="load">
                <template #icon><MapIcon name="refresh" /></template>
            </Button>
        </header>

        <dl class="grid auto-rows-fr grid-cols-3 gap-2 @min-[32rem]:gap-3" aria-label="User account totals" :aria-busy="loading">
            <div v-for="metric in metrics" :key="metric.tone"
                class="user-panel grid min-w-0 grid-cols-[auto_minmax(0,1fr)] items-center gap-x-2 gap-y-0.5 p-2.5 @min-[32rem]:gap-x-3 @min-[32rem]:px-4 @min-[32rem]:py-3">
                <span class="user-metric-icon grid place-items-center @max-[19rem]:hidden @min-[32rem]:row-span-2" :data-tone="metric.tone"><MapIcon :name="metric.icon" /></span>
                <dt class="col-span-2 row-start-2 text-xs/4 font-medium text-surface-600 @min-[32rem]:col-span-1 @min-[32rem]:col-start-2 @min-[32rem]:row-start-1">{{ metric.label }}</dt>
                <dd class="col-start-2 row-start-1 text-xl/6 font-semibold tabular-nums @max-[19rem]:col-[1/-1] @min-[32rem]:row-start-2 @min-[32rem]:text-2xl/7">
                    {{ loading || loadError ? '—' : formatCount(metric.value) }}
                </dd>
            </div>
        </dl>

        <Message v-if="feedback" :severity="feedback.severity" size="small" class="wrap-anywhere" closable @close="feedback = null">
            {{ feedback.text }}
        </Message>

        <section class="user-panel min-w-0" aria-labelledby="user-accounts-title" :aria-busy="loading">
            <header class="px-3.5 py-3 @min-[32rem]:px-4">
                <h3 id="user-accounts-title" class="text-[15px]/5 font-semibold">User accounts</h3>
                <div class="user-filters mt-2.5 grid grid-cols-2 items-end gap-x-3 gap-y-2" role="group" aria-label="Filter user accounts">
                    <div class="user-field user-search-field col-span-2">
                        <label for="user-search">Search users</label>
                        <div class="user-search">
                            <MapIcon name="search" />
                            <InputText id="user-search" ref="searchInput" v-model="search" size="small" placeholder="Name or email" autocomplete="off" />
                            <Button v-if="search" size="small" severity="secondary" text aria-label="Clear search" title="Clear search" @click="clearSearch">
                                <MapIcon name="x" />
                            </Button>
                        </div>
                    </div>
                    <div class="user-field">
                        <label id="user-role-label" for="user-role">Role</label>
                        <Select input-id="user-role" v-model="role" :options="roleOptions" option-label="label" option-value="value" placeholder="All roles"
                            aria-labelledby="user-role-label" size="small" append-to="self" overlay-class="user-management-select" :pt="{ transition: { css: false } }" />
                    </div>
                    <div class="user-field">
                        <label id="user-status-label" for="user-status">Status</label>
                        <Select input-id="user-status" v-model="status" :options="statusOptions" option-label="label" option-value="value" placeholder="All statuses"
                            aria-labelledby="user-status-label" size="small" append-to="self" overlay-class="user-management-select" :pt="{ transition: { css: false } }" />
                    </div>
                    <Button class="user-clear-filters col-span-2 justify-self-end" label="Clear filters" size="small" severity="secondary" outlined :disabled="!hasFilters" @click="clearFilters" />
                </div>
            </header>

            <div v-if="loading" class="user-table-state" role="status">
                <ProgressSpinner class="user-spinner" aria-hidden="true" />
                <p>Loading user accounts…</p>
            </div>
            <div v-else-if="loadError" class="user-table-state">
                <MapIcon name="alert-circle" />
                <p class="font-medium text-surface-900" role="alert">{{ loadError }}</p>
                <Button label="Retry" size="small" severity="secondary" outlined @click="load" />
            </div>
            <div v-else-if="!visibleRows.length" class="user-table-state">
                <span class="user-metric-icon grid place-items-center"><MapIcon :name="hasFilters ? 'search' : 'users'" /></span>
                <div role="status">
                    <h4 class="text-sm font-semibold text-surface-900">{{ hasFilters ? 'No matching users' : 'No user accounts' }}</h4>
                    <p class="mt-0.5">{{ hasFilters ? 'Try a different name or email, or clear the filters.' : 'User accounts will appear here when available.' }}</p>
                </div>
                <Button v-if="hasFilters" label="Clear filters" size="small" severity="secondary" outlined @click="clearFilters" />
            </div>
            <template v-else>
                <p class="user-table-hint px-4 pb-2 text-xs text-surface-600">Scroll horizontally to see all columns.</p>
                <DataTable :value="visibleRows" size="small" data-key="id"
                    :table-props="{ 'aria-label': 'User accounts' }"
                    :pt="{ tableContainer: { role: 'region', tabindex: 0, 'aria-label': 'Scrollable user accounts table' } }">
                    <Column header="User" class="user-identity-column">
                        <template #body="{ data }">
                            <strong class="block font-semibold text-surface-900">{{ userName(data) || 'Name unavailable' }}</strong>
                            <span class="block text-xs text-surface-600">{{ data.email }}</span>
                        </template>
                    </Column>
                    <Column header="Role">
                        <template #body="{ data }">
                            <Tag v-if="data.role" :value="data.role.name || data.role.code" severity="secondary" class="user-role-tag" />
                            <span v-else class="text-surface-600">Unassigned</span>
                        </template>
                    </Column>
                    <Column header="Barangay" class="min-w-25">
                        <template #body="{ data }"><span :class="{ 'text-surface-600': !data.barangay_name && data.barangay_id == null }">{{ barangayLabel(data) }}</span></template>
                    </Column>
                    <Column header="Status">
                        <template #body="{ data }">
                            <Tag :value="data.account_status === 'active' ? 'Active' : 'Inactive'" :severity="data.account_status === 'active' ? 'success' : 'secondary'" class="whitespace-nowrap" rounded />
                        </template>
                    </Column>
                    <Column header="Actions" class="user-action-column">
                        <template #body="{ data }">
                            <Button :label="data.account_status === 'active' ? 'Deactivate' : 'Activate'" size="small" text class="user-status-action"
                                :severity="data.account_status === 'active' ? 'secondary' : undefined"
                                :aria-label="`${data.account_status === 'active' ? 'Deactivate' : 'Activate'} ${data.email}`"
                                :aria-busy="updatingId === data.id"
                                :loading="updatingId === data.id" :disabled="updatingId !== null"
                                @click="requestStatusChange(data, $event)">
                                <template #icon><MapIcon :name="data.account_status === 'active' ? 'user-round-x' : 'user-round-plus'" /></template>
                            </Button>
                        </template>
                    </Column>
                </DataTable>
            </template>

            <footer v-if="!loading && !loadError" class="user-pagination flex min-h-12 flex-wrap items-center justify-between gap-x-4 gap-y-1 px-4 py-2 text-xs text-surface-600">
                <span role="status">{{ range }}</span>
                <Paginator v-if="total > pageSize" :first="first" :rows="pageSize" :total-records="total"
                    template="PrevPageLink CurrentPageReport NextPageLink" current-page-report-template="Page {currentPage} of {totalPages}"
                    @update:first="first = $event" />
            </footer>
        </section>
    </section>
</template>

<style scoped>
.user-panel {
    background: var(--resident-card);
    border: 1px solid var(--resident-line);
    border-radius: var(--resident-radius);
}

.user-management :deep(.cdrrmo-icon) {
    width: 16px;
    height: 16px;
    flex: none;
}

.user-metric-icon {
    width: 30px;
    height: 30px;
    border-radius: var(--p-border-radius-sm);
    color: var(--p-primary-700);
    background: var(--p-primary-50);
}

.user-metric-icon[data-tone="active"] { color: var(--p-emerald-700); background: var(--p-emerald-50); }
.user-metric-icon[data-tone="inactive"] { color: var(--p-indigo-700); background: var(--p-indigo-50); }

.user-field {
    display: grid;
    min-width: 0;
    gap: 4px;
}

.user-field label {
    color: var(--p-surface-600);
    font-size: 12px;
    font-weight: 500;
}

.user-management :deep(.p-button),
.user-management :deep(:is(.p-inputtext, .p-select)) {
    --user-control-height: 32px;
    border-radius: var(--p-border-radius-sm);
    font-size: 13px;
    line-height: 20px;
}

.user-management :deep(.p-button) {
    min-height: var(--user-control-height);
    padding: 5px 10px;
}

.user-management :deep(.p-button:not(:disabled)),
.user-management :deep(.p-select:not(.p-disabled)) { cursor: pointer; }

.user-management :deep(:is(.p-inputtext, .p-select)) {
    width: 100%;
    min-width: 0;
    min-height: var(--user-control-height);
    box-shadow: none;
}

.user-management :deep(:is(.p-inputtext, .p-select-label)) { padding: 5px 10px; }
.user-management :deep(.p-select) { position: relative; }
.user-management :deep(.p-select-dropdown) { width: 28px; }

.user-management :deep(:is(.p-inputtext:focus, .p-select.p-focus, .p-select-label:focus-visible)) {
    outline: none;
    box-shadow: none;
    border-color: var(--p-primary-600);
}

.user-search { position: relative; }

.user-search :deep(> .cdrrmo-icon) {
    position: absolute;
    inset-inline-start: 10px;
    top: 50%;
    transform: translateY(-50%);
    color: var(--p-surface-500);
    pointer-events: none;
}

.user-search :deep(.p-inputtext) { padding-inline: 32px 36px; }

.user-search :deep(.p-button) {
    position: absolute;
    inset-inline-end: 2px;
    top: 50%;
    transform: translateY(-50%);
    width: 28px;
    min-height: 28px;
    padding: 0;
}

.user-management :deep(.user-management-select) {
    width: calc(100% + 2px);
    margin-inline-start: -1px;
    border-radius: var(--p-border-radius-sm);
    font-size: 13px;
}

.user-management :deep(.user-management-select .p-select-option) { padding: 7px 10px; white-space: normal; }
:global(.p-confirmdialog:has(.user-status-confirm) .p-confirmdialog-message) { overflow-wrap: anywhere; }

.user-management :deep(.p-datatable-table-container) {
    overflow-x: auto;
    border-top: 1px solid var(--resident-line);
}

.user-management :deep(.p-datatable-table-container:focus-visible) { outline-offset: -2px; }
.user-management :deep(.p-datatable-table) { min-width: 42rem; }

.user-management :deep(.p-datatable-header-cell),
.user-management :deep(.p-datatable-tbody > tr > td) {
    padding: 7px 12px;
    border-color: var(--resident-line);
    overflow-wrap: anywhere;
}

.user-management :deep(.p-datatable-header-cell) {
    background: var(--resident-canvas);
    color: var(--p-surface-600);
    font-size: 12px;
}

.user-management :deep(:is(th, td):first-child) { padding-inline-start: 16px; }
.user-management :deep(:is(th, td):last-child) { padding-inline-end: 16px; }
.user-management :deep(.p-datatable-tbody > tr:last-child > td) { border-bottom: 0; }
.user-management :deep(.p-datatable-tbody > tr:hover) { background: var(--p-surface-50); }
.user-management :deep(.user-identity-column) { width: 33%; }
.user-management :deep(.p-tag) { padding: 2px 8px; font-size: 11px; line-height: 16px; }
.user-management :deep(.user-role-tag) { color: var(--p-indigo-800); background: var(--p-indigo-50); }
.user-management :deep(.user-action-column) { width: 8.25rem; text-align: end; }
.user-management :deep(.user-action-column .p-datatable-column-header-content) { justify-content: flex-end; }
.user-management :deep(.user-status-action) { white-space: nowrap; }

.user-pagination, .user-table-state { border-top: 1px solid var(--resident-line); }
.user-pagination :deep(.p-paginator) { padding: 0; gap: 4px; font: inherit; }
.user-pagination :deep(.p-paginator-current) { min-width: auto; padding-inline: 6px; }
.user-pagination :deep(:is(.p-paginator-prev, .p-paginator-next)) { width: 32px; height: 32px; }

.user-table-state {
    display: grid;
    place-content: center;
    justify-items: center;
    min-height: 180px;
    gap: 8px;
    padding: 16px;
    color: var(--p-surface-600);
    text-align: center;
}

.user-spinner { width: 28px; height: 28px; }

@container (width >= 42rem) {
    .user-filters { grid-template-columns: minmax(12rem, 2fr) minmax(0, 1fr) minmax(0, 1fr) auto; }
    .user-search-field, .user-clear-filters { grid-column: auto; }
    .user-table-hint { display: none; }
}

@media (pointer: coarse) {
    .user-management :deep(:is(.p-button, .p-inputtext, .p-select)) { --user-control-height: 44px; }
    .user-management :deep(.p-inputtext) { font-size: 16px; }
    .user-search :deep(.p-button) { width: 44px; min-height: 44px; }
    .user-search :deep(.p-inputtext) { padding-inline-end: 48px; }
    .user-pagination :deep(:is(.p-paginator-prev, .p-paginator-next)) { width: 44px; height: 44px; }
}

@media (forced-colors: active) {
    .user-management :deep(:is(.p-inputtext:focus, .p-select.p-focus)) { border-color: Highlight; }
}
</style>
