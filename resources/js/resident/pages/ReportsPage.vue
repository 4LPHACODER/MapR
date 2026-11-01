<script setup>
import { computed, inject, nextTick, onBeforeUnmount, onMounted, ref, watch } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import MapIcon from '../../shared/components/MapIcon.vue';
import StatusBadge from '../components/StatusBadge.vue';
import EmptyState from '../components/EmptyState.vue';
import ConfirmDialog from '../components/ConfirmDialog.vue';
import { formatWhen } from '../format';

const apiBase = inject('apiBase');
const route = useRoute();
const router = useRouter();
const reports = ref([]);
const error = ref('');
const loading = ref(true);
const searchInput = ref('');
const showDates = ref(false);
const dateError = ref('');
const filters = ref({ search: '', status: '', from: '', to: '' });
const dateDraft = ref({ from: '', to: '' });
const pendingDelete = ref(null);
const actionMessage = ref('');
const openReport = ref(null);
const menuTrigger = ref(null);
const menuEl = ref(null);
const menuPosition = ref({ top: 0, left: 0 });
const statuses = [
    { value: '', label: 'All reports' },
    { value: 'draft', label: 'Draft' },
    { value: 'submitted', label: 'Pending BDRRM Review' },
    { value: 'under_review', label: 'Under review' },
    { value: 'reviewed', label: 'Reviewed' },
    { value: 'returned', label: 'Needs Correction' },
];
let requestId = 0;
let searchTimer = null;

const queryActive = computed(() => {
    const current = readQuery(route.query);
    return Boolean(current.search || current.status || current.from || current.to);
});
const filtersActive = computed(() => Boolean(
    searchInput.value.trim()
    || filters.value.status
    || filters.value.from
    || filters.value.to
    || dateDraft.value.from
    || dateDraft.value.to,
));

function readQuery(query) {
    return {
        search: String(query.search || ''),
        status: String(query.status || ''),
        from: String(query.from || ''),
        to: String(query.to || ''),
    };
}

function queryFromFilters() {
    const query = {};
    if (filters.value.search) query.search = filters.value.search;
    if (filters.value.status) query.status = filters.value.status;
    if (filters.value.from) query.from = filters.value.from;
    if (filters.value.to) query.to = filters.value.to;
    return query;
}

function paramsFrom(query) {
    const current = readQuery(query);
    const params = {};
    if (current.search) params.search = current.search;
    if (current.status) params.status = current.status;
    if (current.from) params.from = current.from;
    if (current.to) params.to = current.to;
    return params;
}

function sameQuery(query) {
    const current = readQuery(query);
    return current.search === filters.value.search
        && current.status === filters.value.status
        && current.from === filters.value.from
        && current.to === filters.value.to;
}

async function load() {
    const id = ++requestId;
    error.value = '';
    loading.value = true;
    try {
        const response = await window.axios.get(`${apiBase}/reports`, { params: paramsFrom(route.query) });
        if (id !== requestId) return;
        reports.value = response.data.data || [];
    } catch (exception) {
        if (id !== requestId) return;
        reports.value = [];
        error.value = exception.response?.data?.message || 'Reports could not be loaded.';
    } finally {
        if (id === requestId) loading.value = false;
    }
}

function commitQuery() {
    if (sameQuery(route.query)) return;
    router.replace({ query: queryFromFilters() });
}

function onSearchInput() {
    clearTimeout(searchTimer);
    searchTimer = setTimeout(() => {
        searchTimer = null;
        const next = searchInput.value.trim();
        if (next === readQuery(route.query).search) return;
        filters.value.search = next;
        commitQuery();
    }, 300);
}

function onStatusChange() {
    commitQuery();
}

function applyDates() {
    const from = dateDraft.value.from;
    const to = dateDraft.value.to;
    if (from && to && from > to) {
        dateError.value = 'From must be on or before To.';
        return;
    }
    dateError.value = '';
    filters.value.from = from;
    filters.value.to = to;
    commitQuery();
}

function clearFilters() {
    clearTimeout(searchTimer);
    searchTimer = null;
    searchInput.value = '';
    filters.value = { search: '', status: '', from: '', to: '' };
    dateDraft.value = { from: '', to: '' };
    dateError.value = '';
    showDates.value = false;
    commitQuery();
}

function viewTo(report) {
    return { path: `/reports/${report.id}`, query: { ...route.query } };
}

function primaryAction(report) {
    if (report.status === 'draft') return { label: 'Continue Draft', icon: 'pencil', to: `/report/${report.id}` };
    return { label: 'View Report', icon: 'eye', to: viewTo(report) };
}

function secondaryAction(report) {
    if (report.editable && report.status === 'returned') {
        return { label: 'Revise Report', icon: 'pencil', to: `/report/${report.id}` };
    }
    return null;
}

function menuItems(report) {
    return [
        report.editable
            ? { key: 'edit', label: 'Edit', icon: 'pencil', to: `/report/${report.id}` }
            : { key: 'edit', label: 'Edit', icon: 'pencil' },
        { key: 'delete', label: 'Delete', icon: 'eraser', danger: true },
        { key: 'track', label: 'Track Status', icon: 'history', to: { path: `/reports/${report.id}/track`, query: { ...route.query } } },
    ];
}

function placeMenu(fallbackHeight = 0, fallbackWidth = 0) {
    const button = menuTrigger.value;
    if (!button) return;
    const rect = button.getBoundingClientRect();
    const menuHeight = menuEl.value?.offsetHeight || fallbackHeight;
    const menuWidth = menuEl.value?.offsetWidth || fallbackWidth;
    const nav = document.querySelector('.resident-bottom-nav');
    const navVisible = nav && getComputedStyle(nav).display !== 'none';
    const limit = navVisible ? nav.getBoundingClientRect().top : window.innerHeight;
    let top = rect.bottom + 8;
    if (top + menuHeight > limit - 8) top = rect.top - menuHeight - 8;
    top = Math.max(72, top);
    let left = rect.right - menuWidth;
    left = Math.max(8, Math.min(left, window.innerWidth - menuWidth - 8));
    menuPosition.value = { top, left };
}

async function toggleMenu(report, event) {
    actionMessage.value = '';
    if (openReport.value?.id === report.id) {
        closeMenu(true);
        return;
    }
    menuTrigger.value = event.currentTarget;
    placeMenu(168, 220);
    openReport.value = report;
    await nextTick();
    placeMenu();
    menuEl.value?.querySelector('[role="menuitem"]')?.focus();
}

function closeMenu(restoreFocus) {
    const trigger = menuTrigger.value;
    openReport.value = null;
    if (restoreFocus) trigger?.focus();
}

function onMenuKeydown(event) {
    const items = [...(menuEl.value?.querySelectorAll('[role="menuitem"]') || [])];
    const index = items.indexOf(document.activeElement);
    if (event.key === 'Escape') {
        event.preventDefault();
        closeMenu(true);
        return;
    }
    if (event.key === 'ArrowDown') {
        event.preventDefault();
        items[index < 0 ? 0 : (index + 1) % items.length]?.focus();
    } else if (event.key === 'ArrowUp') {
        event.preventDefault();
        items[index < 0 ? items.length - 1 : (index - 1 + items.length) % items.length]?.focus();
    } else if (event.key === 'Home') {
        event.preventDefault();
        items[0]?.focus();
    } else if (event.key === 'End') {
        event.preventDefault();
        items[items.length - 1]?.focus();
    }
}

function onPointerDown(event) {
    if (!openReport.value) return;
    if (menuEl.value?.contains(event.target) || menuTrigger.value?.contains(event.target)) return;
    closeMenu(false);
}

function onViewport() {
    if (openReport.value) placeMenu();
}

function chooseMenu(item, report) {
    closeMenu(false);
    if (item.key === 'edit') {
        actionMessage.value = report.edit_message || 'Editing is unavailable while this report is under review.';
        return;
    }
    if (report.status !== 'draft') {
        actionMessage.value = 'Only a draft can be deleted.';
        return;
    }
    actionMessage.value = '';
    pendingDelete.value = report;
}

async function confirmDelete() {
    const report = pendingDelete.value;
    pendingDelete.value = null;
    if (!report) return;
    await window.axios.delete(`${apiBase}/reports/${report.id}`);
    await load();
}

watch(() => JSON.stringify(readQuery(route.query)), () => {
    const next = readQuery(route.query);
    const datesChanged = next.from !== filters.value.from || next.to !== filters.value.to;
    filters.value = { ...next, search: searchTimer ? filters.value.search : next.search };
    if (!searchTimer) searchInput.value = next.search;
    if (datesChanged) {
        dateDraft.value = { from: next.from, to: next.to };
        if (next.from || next.to) showDates.value = true;
    }
    load();
}, { immediate: true });

onMounted(() => {
    window.addEventListener('pointerdown', onPointerDown);
    window.addEventListener('resize', onViewport);
    window.addEventListener('scroll', onViewport, true);
});

onBeforeUnmount(() => {
    requestId += 1;
    clearTimeout(searchTimer);
    window.removeEventListener('pointerdown', onPointerDown);
    window.removeEventListener('resize', onViewport);
    window.removeEventListener('scroll', onViewport, true);
});
</script>

<template>
    <section class="resident-reports resident-stack">
        <header class="resident-list-head">
            <p class="resident-lead">Search your drafts and submitted reports.</p>
            <router-link class="resident-primary" to="/report"><MapIcon name="camera" /> New Report</router-link>
        </header>
        <div class="resident-reports-filters">
            <div class="resident-reports-toolbar">
                <label class="resident-reports-search">
                    <span>Search</span>
                    <span class="resident-reports-search-field">
                        <MapIcon name="search" />
                        <input v-model="searchInput" type="search" placeholder="Reference or incident" @input="onSearchInput">
                    </span>
                </label>
                <div class="resident-reports-tools">
                    <label class="resident-reports-status">
                        <span>Status</span>
                        <select v-model="filters.status" @change="onStatusChange">
                            <option v-for="option in statuses" :key="option.label" :value="option.value">{{ option.label }}</option>
                        </select>
                    </label>
                    <button type="button" class="resident-reports-dates" :aria-expanded="showDates" aria-controls="resident-report-dates" @click="showDates = !showDates">
                        <MapIcon name="clock" /> Dates
                    </button>
                    <button v-if="filtersActive" type="button" class="resident-reports-clear" @click="clearFilters">Clear filters</button>
                </div>
            </div>
            <div v-show="showDates" id="resident-report-dates" class="resident-reports-dates-panel">
                <label>From
                    <input v-model="dateDraft.from" type="date">
                </label>
                <label>To
                    <input v-model="dateDraft.to" type="date">
                </label>
                <button type="button" class="resident-primary" @click="applyDates">Apply dates</button>
                <p v-if="dateError" class="resident-error">{{ dateError }}</p>
            </div>
        </div>
        <div v-if="loading" class="resident-reports-list" aria-hidden="true">
            <div v-for="item in 3" :key="item" class="resident-reports-card resident-reports-skeleton"></div>
        </div>
        <p v-if="loading" class="resident-reports-sr">Loading reports…</p>
        <div v-else-if="error" class="resident-reports-error" role="alert">
            <p class="resident-error">{{ error }}</p>
            <button type="button" class="resident-secondary" @click="load">Retry</button>
        </div>
        <EmptyState v-else-if="!reports.length && !queryActive" icon="file" title="No reports yet.">
            <p>Drafts and submitted reports will show up here.</p>
        </EmptyState>
        <EmptyState v-else-if="!reports.length" icon="search" title="No reports match your filters.">
            <button type="button" class="resident-secondary" @click="clearFilters">Clear filters</button>
        </EmptyState>
        <template v-else>
        <p v-if="actionMessage" class="resident-reports-note" role="status">{{ actionMessage }}</p>
        <div class="resident-reports-list">
            <article v-for="report in reports" :key="report.id" class="resident-card resident-reports-card">
                <span class="resident-reports-mark" aria-hidden="true"><MapIcon name="file" /></span>
                <div class="resident-reports-main">
                    <div class="resident-reports-heading">
                        <strong class="resident-reports-ref">{{ report.reference }}</strong>
                        <StatusBadge :label="report.label" />
                    </div>
                    <p class="resident-reports-incident"><MapIcon name="disaster" /> <span>{{ report.incident_name || report.incident_type || 'Incident' }}</span></p>
                    <p v-if="report.reported_location" class="resident-reports-meta"><MapIcon name="pin" /> <span>{{ report.reported_location }}</span></p>
                    <p v-if="report.updated_at" class="resident-reports-meta"><MapIcon name="clock" /> <span>{{ formatWhen(report.updated_at) }}</span></p>
                </div>
                <div class="resident-reports-actions">
                    <router-link class="resident-primary" :to="primaryAction(report).to">
                        <MapIcon :name="primaryAction(report).icon" /> {{ primaryAction(report).label }}
                    </router-link>
                    <router-link v-if="secondaryAction(report)" class="resident-secondary" :to="secondaryAction(report).to">
                        <MapIcon :name="secondaryAction(report).icon" /> {{ secondaryAction(report).label }}
                    </router-link>
                    <button
                        type="button"
                        class="resident-reports-more"
                        aria-haspopup="menu"
                        :aria-expanded="openReport?.id === report.id"
                        :aria-label="`More actions for ${report.reference}`"
                        @click="toggleMenu(report, $event)"
                    >
                        <MapIcon name="more" />
                    </button>
                </div>
            </article>
        </div>
        </template>
        <Teleport to="body">
            <div
                v-if="openReport"
                ref="menuEl"
                class="resident-reports-menu"
                role="menu"
                :style="{ top: `${menuPosition.top}px`, left: `${menuPosition.left}px` }"
                @keydown="onMenuKeydown"
            >
                <template v-for="item in menuItems(openReport)" :key="item.key">
                    <router-link v-if="item.to" role="menuitem" :to="item.to" @click="closeMenu(false)">
                        <MapIcon :name="item.icon" /> {{ item.label }}
                    </router-link>
                    <button v-else type="button" role="menuitem" :class="{ 'is-danger': item.danger }" @click="chooseMenu(item, openReport)">
                        <MapIcon :name="item.icon" /> {{ item.label }}
                    </button>
                </template>
            </div>
        </Teleport>
        <ConfirmDialog
            v-if="pendingDelete"
            title="Delete draft"
            :message="`Delete draft ${pendingDelete.reference}? This cannot be undone.`"
            confirm-label="Delete draft"
            @confirm="confirmDelete"
            @cancel="pendingDelete = null"
        />
    </section>
</template>
