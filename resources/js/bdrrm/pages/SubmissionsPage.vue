<script setup>
import { computed, inject, nextTick, onBeforeUnmount, onMounted, ref, watch } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import MapIcon from '../../shared/components/MapIcon.vue';

const apiBase = inject('apiBase');
const roleCode = inject('roleCode', '');
const route = useRoute();
const router = useRouter();
const rows = ref([]);
const meta = ref({ current_page: 1, last_page: 1, per_page: 10, total: 0 });
const counts = ref({ total: 0, pending: 0, reviewed: 0, checked: 0, unassigned: 0 });
const search = ref('');
const status = ref('');
const queue = ref('unassigned');
const submittedFrom = ref('');
const submittedTo = ref('');
const page = ref(1);
const perPage = ref(10);
const phase = ref('loading');
const error = ref('');
const openMenu = ref(null);
const selectedIds = ref([]);
const assignOpen = ref(false);
const assignees = ref([]);
const assigneeId = ref('');
const assignRemarks = ref('');
const replacedReason = ref('');
const assignError = ref('');
const assigning = ref(false);
const loadingAssignees = ref(false);
let applying = false;

const canAssign = computed(() => ['barangay_secretary', 'barangay_captain'].includes(roleCode));
const isCaptain = computed(() => roleCode === 'barangay_captain');
const isSecretary = computed(() => roleCode === 'barangay_secretary');
const isPersonnel = computed(() => roleCode === 'bdrrm');
const officeQueue = computed(() => (isCaptain.value ? 'checked' : 'reviewed'));
const officeQueueLabel = computed(() => (isCaptain.value ? 'Checked Reports' : 'Reviewed Reports'));
const selectedRows = computed(() => rows.value.filter((row) => selectedIds.value.includes(row.id)));
const needsReason = computed(() => selectedRows.value.some((row) => row.assignee_name));
const pageSelected = computed(() => rows.value.length > 0 && rows.value.every((row) => selectedIds.value.includes(row.id)));
const showStatus = computed(() => queue.value === 'pending' || queue.value === 'reviewed' || queue.value === 'checked' || (isPersonnel.value && ['pending', 'reviewed'].includes(queue.value)));
const statusChoices = computed(() => {
    if (queue.value === 'checked' || (queue.value === 'reviewed' && isCaptain.value)) {
        return [
            { value: '', label: 'All Checked Reports' },
            { value: 'secretary_checked', label: 'Secretary Checked' },
            { value: 'awaiting_endorsement', label: 'Awaiting Captain Endorsement' },
        ];
    }
    if (queue.value === 'reviewed') {
        return [
            { value: '', label: 'All Reviewed Reports' },
            { value: 'next_office', label: 'Next Office' },
        ];
    }
    if (queue.value === 'pending' || isPersonnel.value) {
        return [
            { value: '', label: 'All Pending Reviews' },
            { value: 'not_opened', label: 'Not Opened' },
            { value: 'reviewing', label: 'Reviewing' },
        ];
    }
    return [];
});
const filtersActive = computed(() => search.value.trim() !== '' || status.value !== '' || submittedFrom.value !== '' || submittedTo.value !== '');
const rangeStart = computed(() => (meta.value.total === 0 ? 0 : ((meta.value.current_page - 1) * meta.value.per_page) + 1));
const rangeEnd = computed(() => Math.min(meta.value.current_page * meta.value.per_page, meta.value.total));
const countLabel = computed(() => `${meta.value.total} ${meta.value.total === 1 ? 'report' : 'reports'}`);
const selectedLabel = computed(() => `${selectedIds.value.length} ${selectedIds.value.length === 1 ? 'report' : 'reports'} selected`);

const summaryCards = computed(() => {
    if (isPersonnel.value) {
        return [
            { key: 'pending', icon: 'clock-3', title: 'My Pending Reviews', value: counts.value.pending, note: 'Assigned and still open', tone: 'pending' },
            { key: 'reviewed', icon: 'clipboard-check', title: 'My Reviewed Reports', value: counts.value.reviewed, note: 'Reviews you completed', tone: 'reviewed' },
        ];
    }
    if (!canAssign.value) return [];
    return [
        { key: 'unassigned', icon: 'user-round-x', title: 'Unassigned Reports', value: counts.value.unassigned, note: 'No active reviewer', tone: 'unassigned' },
        { key: 'pending', icon: 'clock-3', title: 'Pending Review', value: counts.value.pending, note: 'Assigned and unfinished', tone: 'pending' },
        {
            key: officeQueue.value,
            icon: 'clipboard-check',
            title: officeQueueLabel.value,
            value: isCaptain.value ? (counts.value.checked || counts.value.reviewed) : counts.value.reviewed,
            note: isCaptain.value ? 'Checked and signed by the Barangay Secretary.' : 'Reviewed by BDRRM and awaiting Secretary checking.',
            tone: 'reviewed',
        },
        { key: 'total', icon: 'files', title: 'Total Submissions', value: counts.value.total, note: 'In your barangay', tone: 'total' },
    ];
});

function formatWhen(value) {
    if (!value) return '—';
    const date = new Date(String(value).includes('T') ? value : `${String(value).replace(' ', 'T')}Z`);
    if (Number.isNaN(date.getTime())) return value;
    return `${new Intl.DateTimeFormat('en-PH', {
        timeZone: 'Asia/Manila',
        day: 'numeric',
        month: 'short',
        year: 'numeric',
        hour: 'numeric',
        minute: '2-digit',
    }).format(date)} PHT`;
}

function readRoute() {
    search.value = typeof route.query.q === 'string' ? route.query.q : '';
    submittedFrom.value = typeof route.query.submitted_from === 'string' ? route.query.submitted_from : '';
    submittedTo.value = typeof route.query.submitted_to === 'string' ? route.query.submitted_to : '';
    const requested = typeof route.query.status === 'string' ? route.query.status : '';
    if (canAssign.value) {
        const allowed = ['pending', 'reviewed', 'unassigned', 'checked'];
        let nextQueue = allowed.includes(route.query.queue) ? route.query.queue : 'unassigned';
        if (isCaptain.value && nextQueue === 'reviewed') nextQueue = 'checked';
        if (isSecretary.value && nextQueue === 'checked') nextQueue = 'reviewed';
        queue.value = nextQueue;
        if (queue.value === 'pending' && ['not_opened', 'reviewing', 'submitted', 'under_review'].includes(requested)) {
            status.value = requested === 'submitted' ? 'not_opened' : (requested === 'under_review' ? 'reviewing' : requested);
        } else if (queue.value === 'reviewed' && ['next_office'].includes(requested)) {
            status.value = requested;
        } else if (queue.value === 'checked' && ['secretary_checked', 'awaiting_endorsement'].includes(requested)) {
            status.value = requested;
        } else {
            status.value = '';
        }
    } else if (requested === 'reviewed' || route.query.queue === 'reviewed') {
        queue.value = 'reviewed';
        status.value = ['next_office', 'secretary_checked'].includes(requested) ? requested : '';
    } else {
        queue.value = 'pending';
        status.value = ['not_opened', 'reviewing', 'submitted', 'under_review'].includes(requested)
            ? (requested === 'submitted' ? 'not_opened' : (requested === 'under_review' ? 'reviewing' : requested))
            : '';
    }
    const nextPage = Number(route.query.page);
    page.value = Number.isInteger(nextPage) && nextPage > 0 ? nextPage : 1;
    const nextPer = Number(route.query.per_page);
    perPage.value = [10, 25, 50].includes(nextPer) ? nextPer : 10;
}

function queryFromState() {
    const query = {};
    if (search.value.trim()) query.q = search.value.trim();
    if (canAssign.value) query.queue = queue.value;
    else if (queue.value === 'reviewed') query.status = 'reviewed';
    if (showStatus.value && status.value) query.status = status.value;
    if (submittedFrom.value) query.submitted_from = submittedFrom.value;
    if (submittedTo.value) query.submitted_to = submittedTo.value;
    if (page.value > 1) query.page = String(page.value);
    if (perPage.value !== 10) query.per_page = String(perPage.value);
    return query;
}

async function load() {
    phase.value = rows.value.length ? 'refreshing' : 'loading';
    error.value = '';
    try {
        const params = { page: page.value, per_page: perPage.value };
        if (canAssign.value) params.queue = queue.value;
        if (queue.value === 'reviewed' || queue.value === 'checked') {
            params.status = status.value || (queue.value === 'checked' ? 'secretary_checked' : 'reviewed');
        } else if (status.value) {
            params.status = status.value;
        } else if (!canAssign.value) {
            params.status = 'pending';
        }
        if (search.value.trim()) params.search = search.value.trim();
        if (submittedFrom.value) params.submitted_from = submittedFrom.value;
        if (submittedTo.value) params.submitted_to = submittedTo.value;
        const { data } = await window.axios.get(`${apiBase}/submissions`, { params });
        rows.value = data.data || [];
        meta.value = data.meta || meta.value;
        counts.value = { ...counts.value, ...(data.counts || {}) };
        selectedIds.value = selectedIds.value.filter((id) => rows.value.some((row) => row.id === id));
        phase.value = 'ready';
    } catch {
        rows.value = [];
        phase.value = 'error';
        error.value = 'The submission list could not be loaded.';
    }
}

async function pushQuery(resetPage) {
    if (resetPage) page.value = 1;
    openMenu.value = null;
    const query = queryFromState();
    const target = router.resolve({ path: '/submissions', query }).fullPath;
    if (route.fullPath === target) {
        await load();
        return;
    }
    applying = true;
    await router.replace({ path: '/submissions', query });
    applying = false;
    readRoute();
    await load();
}

function clearFilters() {
    search.value = '';
    status.value = '';
    submittedFrom.value = '';
    submittedTo.value = '';
    pushQuery(true);
}

function detailQuery(extra = {}) {
    return { ...route.query, ...extra };
}

function closeMenu(restore = true) {
    const current = openMenu.value;
    openMenu.value = null;
    if (!restore || current == null) return;
    document.getElementById(`submission-menu-${current}`)?.focus();
}

async function toggleMenu(id) {
    if (openMenu.value === id) {
        closeMenu();
        return;
    }
    openMenu.value = id;
    await nextTick();
    document.querySelector(`#submission-menu-panel-${id} [role="menuitem"]`)?.focus();
}

function onMenuKeydown(event) {
    const items = [...event.currentTarget.querySelectorAll('[role="menuitem"]:not(:disabled)')];
    const index = items.indexOf(document.activeElement);
    if (event.key === 'Escape') {
        event.preventDefault();
        closeMenu();
        return;
    }
    if (event.key !== 'ArrowDown' && event.key !== 'ArrowUp') return;
    event.preventDefault();
    if (!items.length) return;
    const next = event.key === 'ArrowDown' ? (index + 1) % items.length : (index - 1 + items.length) % items.length;
    items[next]?.focus();
}

function onDocumentPointer(event) {
    if (openMenu.value == null) return;
    const panel = document.getElementById(`submission-menu-panel-${openMenu.value}`);
    const button = document.getElementById(`submission-menu-${openMenu.value}`);
    if (panel?.contains(event.target) || button?.contains(event.target)) return;
    closeMenu(false);
}

function onDocumentKey(event) {
    if (event.key === 'Escape' && assignOpen.value) {
        assignOpen.value = false;
        return;
    }
    if (event.key === 'Escape' && openMenu.value != null) closeMenu();
}

watch(() => route.fullPath, async () => {
    if (route.path !== '/submissions' || applying) return;
    readRoute();
    await load();
}, { immediate: true });

function toggleRow(id) {
    selectedIds.value = selectedIds.value.includes(id)
        ? selectedIds.value.filter((item) => item !== id)
        : selectedIds.value.concat(id);
}

function togglePage() {
    selectedIds.value = pageSelected.value ? [] : rows.value.map((row) => row.id);
}

function setQueue(next) {
    if (next === 'total' || queue.value === next) return;
    let resolved = next;
    if (isCaptain.value && resolved === 'reviewed') resolved = 'checked';
    if (isSecretary.value && resolved === 'checked') resolved = 'reviewed';
    if (queue.value === resolved) return;
    queue.value = resolved;
    status.value = '';
    selectedIds.value = [];
    pushQuery(true);
}

function badgeStatus(row) {
    if (row.status === 'returned') return 'returned';
    if (row.review_state === 'next_office' || row.review_state === 'secretary_checked' || row.status === 'reviewed') return 'reviewed';
    if (row.review_state === 'reviewing') return 'under_review';
    if (row.review_state === 'not_opened') return 'submitted';
    return 'submitted';
}

function badgeLabel(row) {
    return row.assignment_label || row.label || 'Unassigned';
}

function reviewerLine(row) {
    if (!row.assignee_name) return 'No reviewer assigned';
    const prefix = row.status === 'reviewed' || row.review_state === 'next_office' || row.review_state === 'secretary_checked'
        ? 'Reviewed by'
        : 'Assigned to';
    const when = row.assigned_at ? ` · ${formatWhen(row.assigned_at)}` : '';
    return `${prefix} ${row.assignee_name}${when}`;
}

function checkerLine(row) {
    if (!row.secretary_name) return '';
    const when = row.secretary_checked_at ? ` · ${formatWhen(row.secretary_checked_at)}` : '';
    return `Checked by ${row.secretary_name}${when}`;
}

function primaryLabel() {
    if (queue.value === 'checked') return 'Endorse Report';
    if (queue.value === 'reviewed') return 'Check Report';
    return 'View Report';
}

function primaryIcon() {
    if (queue.value === 'checked') return 'file-check-2';
    if (queue.value === 'reviewed') return 'clipboard-check';
    return 'eye';
}

function emptyCopy() {
    if (filtersActive.value) return 'No results matching these filters.';
    if (isPersonnel.value) return 'No reports are currently assigned to you.';
    if (queue.value === 'checked') return 'No secretary-checked reports are awaiting endorsement.';
    if (queue.value === 'reviewed') return 'No reviewed resident reports are awaiting Secretary checking.';
    if (queue.value === 'unassigned') return 'All eligible resident submissions have been assigned.';
    return 'No resident submissions are awaiting BDRRM review.';
}

function showAssignAction(row) {
    return canAssign.value && (queue.value === 'unassigned' || row.review_state === 'unassigned');
}

function showReassignAction(row) {
    return canAssign.value && (queue.value === 'pending' || ['not_opened', 'reviewing'].includes(row.review_state));
}

async function openAssign(row) {
    if (row) selectedIds.value = [row.id];
    if (!selectedIds.value.length) return;
    assignError.value = '';
    assigneeId.value = '';
    assignRemarks.value = '';
    replacedReason.value = '';
    loadingAssignees.value = true;
    assignOpen.value = true;
    try {
        const barangayId = selectedRows.value[0]?.barangay_id;
        const response = await window.axios.get(`${apiBase}/personnel`, { params: { barangay_id: barangayId, role: 'bdrrm' } });
        assignees.value = response.data.data || [];
    } catch {
        assignees.value = [];
        assignError.value = 'BDRRM personnel could not be loaded.';
    } finally {
        loadingAssignees.value = false;
    }
}

async function saveAssign() {
    assignError.value = '';
    assigning.value = true;
    try {
        await window.axios.post(`${apiBase}/submissions/assign`, {
            submission_ids: selectedIds.value,
            assignee_user_id: Number(assigneeId.value),
            remarks: assignRemarks.value,
            replaced_reason: needsReason.value ? replacedReason.value : null,
        });
        assignOpen.value = false;
        selectedIds.value = [];
        await load();
    } catch (exception) {
        assignError.value = exception.response?.data?.message || 'Assignment could not be saved.';
    } finally {
        assigning.value = false;
    }
}

onMounted(() => {
    document.addEventListener('pointerdown', onDocumentPointer);
    document.addEventListener('keydown', onDocumentKey);
});

onBeforeUnmount(() => {
    document.removeEventListener('pointerdown', onDocumentPointer);
    document.removeEventListener('keydown', onDocumentKey);
});
</script>

<template>
    <section class="bdrrm-submissions">
        <header class="submission-heading">
            <div class="submission-heading-title">
                <span class="submission-heading-icon" aria-hidden="true"><MapIcon name="clipboard-list" /></span>
                <div>
                    <h2>{{ isPersonnel ? 'My Assigned Resident Submissions' : 'Resident Submissions' }}</h2>
                    <p>{{ isPersonnel ? 'Reports assigned to you for review.' : 'Assign, monitor, and review disaster incident reports submitted by residents.' }}</p>
                </div>
            </div>
            <div class="submission-heading-actions">
                <p v-if="phase === 'ready' || phase === 'refreshing'" class="submission-count">{{ countLabel }}</p>
                <button type="button" class="submission-quiet" :disabled="phase === 'loading'" @click="load">
                    <MapIcon name="refresh" /> Refresh
                </button>
                <button
                    v-if="canAssign"
                    type="button"
                    class="submission-primary"
                    :disabled="!selectedIds.length"
                    :title="selectedIds.length ? 'Assign the selected reports' : 'Select reports to assign'"
                    @click="openAssign()"
                >
                    <MapIcon name="user-round-plus" /> Assign BDRRM Personnel
                </button>
            </div>
        </header>

        <div v-if="summaryCards.length" class="submission-summaries" :class="{ 'is-pair': summaryCards.length === 2 }">
            <button
                v-for="card in summaryCards"
                :key="card.key"
                type="button"
                class="submission-summary"
                :data-tone="card.tone"
                :aria-pressed="card.key !== 'total' && queue === card.key"
                :disabled="card.key === 'total'"
                @click="setQueue(card.key)"
            >
                <span class="submission-summary-icon" aria-hidden="true"><MapIcon :name="card.icon" /></span>
                <span>
                    <span class="submission-summary-label">{{ card.title }}</span>
                    <strong>{{ card.value }}</strong>
                    <span class="submission-summary-note">{{ card.note }}</span>
                </span>
            </button>
        </div>

        <div class="submission-queues" role="tablist" :aria-label="isPersonnel ? 'My review queues' : 'Submission queues'">
            <button v-if="canAssign" type="button" role="tab" :aria-selected="queue === 'unassigned'" :class="{ 'is-active': queue === 'unassigned' }" @click="setQueue('unassigned')">
                Unassigned <span>{{ counts.unassigned }}</span>
            </button>
            <button type="button" role="tab" :aria-selected="queue === 'pending'" :class="{ 'is-active': queue === 'pending' }" @click="setQueue('pending')">
                {{ isPersonnel ? 'My Pending Reviews' : 'Pending Review' }} <span>{{ counts.pending }}</span>
            </button>
            <button
                v-if="isPersonnel || isSecretary"
                type="button"
                role="tab"
                :aria-selected="queue === 'reviewed'"
                :class="{ 'is-active': queue === 'reviewed' }"
                @click="setQueue('reviewed')"
            >
                {{ isPersonnel ? 'My Reviewed Reports' : 'Reviewed Reports' }} <span>{{ counts.reviewed }}</span>
            </button>
            <button
                v-if="isCaptain"
                type="button"
                role="tab"
                :aria-selected="queue === 'checked'"
                :class="{ 'is-active': queue === 'checked' }"
                @click="setQueue('checked')"
            >
                Checked Reports <span>{{ counts.checked || counts.reviewed }}</span>
            </button>
        </div>

        <form class="submission-filter-bar" @submit.prevent="pushQuery(true)">
            <label class="submission-search">
                <span>Search</span>
                <span class="submission-search-field">
                    <MapIcon name="search" />
                    <input v-model="search" type="search" maxlength="80" autocomplete="off" placeholder="Search report reference, resident name, or incident...">
                </span>
            </label>
            <label v-if="showStatus && statusChoices.length" class="submission-status-filter">
                <span>Status</span>
                <span class="submission-search-field">
                    <MapIcon name="list-filter" />
                    <select v-model="status" @change="pushQuery(true)">
                        <option v-for="choice in statusChoices" :key="choice.value || 'all'" :value="choice.value">{{ choice.label }}</option>
                    </select>
                </span>
            </label>
            <details class="submission-dates">
                <summary><MapIcon name="calendar-days" /> Submission date</summary>
                <div>
                    <label>
                        <span>From</span>
                        <input v-model="submittedFrom" type="date" @change="pushQuery(true)">
                    </label>
                    <label>
                        <span>To</span>
                        <input v-model="submittedTo" type="date" @change="pushQuery(true)">
                    </label>
                </div>
            </details>
            <button type="submit" class="submission-primary"><MapIcon name="search" /> Search</button>
            <button v-if="filtersActive" type="button" class="submission-quiet" @click="clearFilters"><MapIcon name="x" /> Clear filters</button>
        </form>

        <div v-if="canAssign && (selectedIds.length || rows.length)" class="submission-toolbar">
            <button type="button" class="submission-quiet" :disabled="!rows.length" @click="togglePage">{{ pageSelected ? 'Clear page' : 'Select page' }}</button>
            <template v-if="selectedIds.length">
                <strong>{{ selectedLabel }}</strong>
                <button type="button" class="submission-primary" @click="openAssign()"><MapIcon name="user-round-plus" /> Assign Personnel</button>
                <button type="button" class="submission-quiet" @click="selectedIds = []">Clear selection</button>
            </template>
        </div>

        <div v-if="phase === 'loading'" class="submission-list" aria-busy="true">
            <p class="visually-hidden">Loading submissions…</p>
            <article v-for="slot in 3" :key="slot" class="submission-card submission-skeleton" aria-hidden="true">
                <span class="submission-card-main">
                    <span class="submission-skeleton-icon"></span>
                    <span class="submission-skeleton-copy"><span></span><span></span><span></span></span>
                </span>
            </article>
        </div>
        <div v-else-if="phase === 'error'" class="submission-status" role="alert">
            <p>{{ error }}</p>
            <button type="button" class="submission-primary" @click="load">Retry</button>
        </div>
        <div v-else-if="rows.length === 0" class="submission-status">
            <p>{{ emptyCopy() }}</p>
        </div>
        <ul v-else class="submission-list" :aria-busy="phase === 'refreshing'">
            <li v-for="row in rows" :key="row.id">
                <article class="submission-card">
                    <div class="submission-card-main">
                        <label v-if="canAssign" class="submission-check">
                            <input type="checkbox" :checked="selectedIds.includes(row.id)" :aria-label="`Select ${row.reference}`" @change="toggleRow(row.id)">
                        </label>
                        <div class="submission-card-icon" aria-hidden="true"><MapIcon name="file-text" /></div>
                        <div class="submission-card-copy">
                            <h3>{{ row.reference }}</h3>
                            <p class="submission-resident">{{ row.resident_name || 'Name unavailable' }}</p>
                            <p class="submission-incident">{{ row.incident_name || 'Incident' }}</p>
                            <p class="submission-meta">
                                <span><MapIcon name="pin" /> {{ row.barangay_name || 'Assigned barangay' }}<template v-if="row.reported_location"> · {{ row.reported_location }}</template></span>
                                <span><MapIcon name="calendar-days" /> Submitted {{ formatWhen(row.submitted_at) }}</span>
                                <span v-if="row.review_state !== 'unassigned'"><MapIcon name="user-round" /> {{ reviewerLine(row) }}</span>
                                <span v-if="checkerLine(row)"><MapIcon name="pen-line" /> {{ checkerLine(row) }}</span>
                            </p>
                            <p v-if="row.open_flags > 0" class="submission-flag"><MapIcon name="flag" /> Open flag</p>
                        </div>
                    </div>
                    <span class="submission-badge" :data-status="badgeStatus(row)">{{ badgeLabel(row) }}</span>
                    <div class="submission-card-actions">
                        <button
                            v-if="showAssignAction(row)"
                            type="button"
                            class="submission-primary"
                            title="Assign BDRRM personnel"
                            @click="openAssign(row)"
                        >
                            <MapIcon name="user-round-plus" /> Assign Personnel
                        </button>
                        <button
                            v-if="showReassignAction(row)"
                            type="button"
                            class="submission-quiet"
                            title="Reassign BDRRM personnel"
                            @click="openAssign(row)"
                        >
                            <MapIcon name="user-round-pen" /> Reassign
                        </button>
                        <RouterLink
                            :class="showAssignAction(row) ? 'submission-quiet' : 'submission-primary'"
                            :to="{ path: `/submissions/${row.id}`, query: detailQuery() }"
                            :title="primaryLabel()"
                        >
                            <MapIcon :name="primaryIcon()" /> {{ primaryLabel() }}
                        </RouterLink>
                        <div class="submission-overflow">
                            <button
                                :id="`submission-menu-${row.id}`"
                                type="button"
                                class="submission-quiet submission-icon-button"
                                aria-label="More actions"
                                title="More actions"
                                aria-haspopup="menu"
                                :aria-expanded="openMenu === row.id"
                                :aria-controls="`submission-menu-panel-${row.id}`"
                                @click="toggleMenu(row.id)"
                            >
                                <MapIcon name="more" />
                            </button>
                            <div
                                v-if="openMenu === row.id"
                                :id="`submission-menu-panel-${row.id}`"
                                class="submission-menu"
                                role="menu"
                                @keydown="onMenuKeydown"
                            >
                                <button v-if="showReassignAction(row)" type="button" role="menuitem" @click="openAssign(row); closeMenu(false)">
                                    <MapIcon name="user-round-pen" /> Reassign personnel
                                </button>
                                <RouterLink v-if="row.has_point" role="menuitem" :to="{ path: '/map', query: { lng: String(row.longitude), lat: String(row.latitude) } }" @click="closeMenu(false)">
                                    <MapIcon name="pin" /> View reported location
                                </RouterLink>
                                <p v-else class="submission-disabled">No reported map point</p>
                                <RouterLink role="menuitem" :to="{ path: `/submissions/${row.id}`, query: detailQuery({ panel: 'flag' }) }" @click="closeMenu(false)">
                                    <MapIcon name="flag" /> Flag an issue
                                </RouterLink>
                                <RouterLink v-if="row.reviewable" role="menuitem" :to="{ path: `/submissions/${row.id}`, query: detailQuery({ panel: 'return' }) }" @click="closeMenu(false)">
                                    <MapIcon name="undo" /> Return for correction
                                </RouterLink>
                            </div>
                        </div>
                    </div>
                </article>
            </li>
        </ul>

        <div v-if="phase !== 'loading' && phase !== 'error' && meta.total > 0" class="submission-pager">
            <div class="submission-pager-meta">
                <span>Showing {{ rangeStart }}–{{ rangeEnd }} of {{ meta.total }} reports</span>
                <label>
                    <span>Rows</span>
                    <select v-model.number="perPage" @change="pushQuery(true)">
                        <option :value="10">10</option>
                        <option :value="25">25</option>
                        <option :value="50">50</option>
                    </select>
                </label>
            </div>
            <div class="submission-pager-nav">
                <button type="button" class="submission-quiet" :disabled="meta.current_page <= 1" @click="page = meta.current_page - 1; pushQuery(false)">Previous</button>
                <span>Page {{ meta.current_page }} of {{ meta.last_page }}</span>
                <button type="button" class="submission-quiet" :disabled="meta.current_page >= meta.last_page" @click="page = meta.current_page + 1; pushQuery(false)">Next</button>
            </div>
        </div>

        <div v-if="assignOpen" class="assign-modal" role="presentation" @click.self="assignOpen = false">
            <form class="assign-panel" role="dialog" aria-modal="true" aria-labelledby="assign-title" @submit.prevent="saveAssign">
                <h2 id="assign-title">Assign BDRRM Personnel</h2>
                <p>{{ selectedRows.length }} {{ selectedRows.length === 1 ? 'report' : 'reports' }} selected</p>
                <ul>
                    <li v-for="row in selectedRows" :key="row.id">{{ row.reference }} · {{ row.resident_name || 'Resident' }}</li>
                </ul>
                <p v-if="loadingAssignees" class="submission-note">Loading personnel…</p>
                <label>
                    <span>BDRRM personnel</span>
                    <select v-model="assigneeId" :disabled="loadingAssignees" required>
                        <option value="">Select one officer</option>
                        <option v-for="person in assignees" :key="person.id" :value="person.id">
                            {{ person.name }} · {{ person.pending_count }} pending · {{ person.reviewed_count }} reviewed
                        </option>
                    </select>
                </label>
                <label>
                    <span>Remarks</span>
                    <input v-model="assignRemarks" maxlength="500" placeholder="Optional">
                </label>
                <label v-if="needsReason">
                    <span>Reason for replacing the current reviewer</span>
                    <input v-model="replacedReason" maxlength="500" required>
                </label>
                <p v-if="assignError" class="submission-alert" role="alert">{{ assignError }}</p>
                <div class="bdrrm-dialog-actions">
                    <button type="button" class="submission-quiet" @click="assignOpen = false">Cancel</button>
                    <button type="submit" class="submission-primary" :disabled="!assigneeId || assigning || loadingAssignees">{{ assigning ? 'Assigning…' : 'Confirm Assignment' }}</button>
                </div>
            </form>
        </div>
    </section>
</template>
