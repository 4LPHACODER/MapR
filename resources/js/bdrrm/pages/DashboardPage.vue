<script setup>
import { computed, inject, onMounted, ref } from 'vue';
import Button from 'primevue/button';
import Message from 'primevue/message';
import Select from 'primevue/select';
import Skeleton from 'primevue/skeleton';
import StatusBadge from '../../resident/components/StatusBadge.vue';
import MapIcon from '../../shared/components/MapIcon.vue';
import MapWorkspace from '../../shared/components/MapWorkspace.vue';

const apiBase = inject('apiBase');
const officerName = inject('officerName', ref(''));
const dashboard = ref(null);
const selectedBarangay = ref('');
const loading = ref(true);
const error = ref('');
const selectedReportId = ref(null);
const fullMapTo = computed(() => (selectedReportId.value
    ? { path: '/map', query: { resident_report_id: String(selectedReportId.value) } }
    : '/map'));
let requestSerial = 0;

const cardMeta = {
    submissions: { icon: 'reports', hint: 'Awaiting BDRRM review.', to: '/submissions', tint: 'blue' },
    drafts: { icon: 'file-pen', hint: 'Continue preparing reports.', to: '/reports/drafts', tint: 'violet' },
    returned: { icon: 'undo', hint: 'Updates required.', to: '/reports/returned', tint: 'orange' },
    submitted: { icon: 'send', hint: 'Signed or sent onward for review.', to: '/reports/submitted', tint: 'indigo' },
};

const greeting = computed(() => dashboard.value?.officer_name || officerName.value || 'there');
const barangays = computed(() => dashboard.value?.barangays || []);
const allBarangaysLabel = 'All assigned barangays';
const barangayOptions = computed(() => [
    { label: allBarangaysLabel, value: '' },
    ...barangays.value.map(({ id, name }) => ({ label: name, value: String(id) })),
]);
const assignedNames = computed(() => {
    if (!barangays.value.length) return 'No barangay is assigned.';
    return barangays.value.map((barangay) => barangay.name).join(', ');
});
const cards = computed(() => (dashboard.value?.cards || []).map((card) => ({
    ...card,
    ...(cardMeta[card.key] || { icon: 'reports', hint: '', to: '/submissions', tint: 'blue' }),
})));
const statuses = computed(() => dashboard.value?.resident_status || []);
const statusTotal = computed(() => statuses.value.reduce((sum, row) => sum + (Number(row.count) || 0), 0));
const donutRadius = 46;
const donutCircumference = 2 * Math.PI * donutRadius;
const donutSegments = computed(() => {
    const total = statusTotal.value;
    if (!total) return [];
    const slices = statuses.value.filter((row) => Number(row.count) > 0);
    const gap = slices.length > 1 ? 3 : 0;
    const usable = donutCircumference - slices.length * gap;
    let cursor = 0;
    return slices.map((row) => {
        const length = ((Number(row.count) || 0) / total) * usable;
        const segment = {
            key: row.key,
            dash: gap ? `${length} ${donutCircumference - length}` : undefined,
            offset: -cursor,
        };
        cursor += length + gap;
        return segment;
    });
});
const months = computed(() => dashboard.value?.resident_months || { available: false, has_history: false, months: [] });
const monthMax = computed(() => Math.max(1, ...(months.value.months || []).map((row) => Number(row.count) || 0)));
const initialLoading = computed(() => loading.value && !dashboard.value);
const attention = computed(() => dashboard.value?.attention || []);
const recent = computed(() => dashboard.value?.recent_submissions || []);
const activity = computed(() => dashboard.value?.activity || []);

const activityIcons = {
    resident_report_submitted: 'send',
    resident_report_returned: 'undo',
    resident_report_accepted: 'check',
    barangay_report_submitted: 'send',
    barangay_report_returned: 'undo',
};

function formatWhen(value) {
    if (!value) return 'Time unavailable';
    const date = new Date(String(value).includes('T') ? value : `${String(value).replace(' ', 'T')}Z`);
    if (Number.isNaN(date.getTime())) return 'Time unavailable';
    return `${new Intl.DateTimeFormat('en-PH', {
        timeZone: 'Asia/Manila',
        day: 'numeric',
        month: 'short',
        year: 'numeric',
        hour: 'numeric',
        minute: '2-digit',
    }).format(date)} PHT`;
}

function statusPercent(count) {
    const total = statusTotal.value;
    if (!total) return '0%';
    const value = (Number(count) || 0) / total * 100;
    return `${Number.isInteger(value) ? value : value.toFixed(1)}%`;
}

function columnHeight(count) {
    return `${(Number(count) / monthMax.value) * 100}%`;
}

async function load() {
    const serial = ++requestSerial;
    loading.value = true;
    error.value = '';
    try {
        const params = {};
        if (selectedBarangay.value) params.barangay_id = selectedBarangay.value;
        const response = await window.axios.get(`${apiBase}/dashboard`, { params });
        if (serial !== requestSerial) return;
        dashboard.value = response.data;
    } catch (exception) {
        if (serial !== requestSerial) return;
        dashboard.value = null;
        error.value = exception.response?.status === 403
            ? 'That barangay is outside your assignment.'
            : 'The dashboard could not be loaded.';
    } finally {
        if (serial === requestSerial) loading.value = false;
    }
}

onMounted(load);
</script>

<template>
    <section class="bdrrm-dashboard grid gap-4" aria-labelledby="bdrrm-dashboard-heading" :aria-busy="loading">
        <header class="bdrrm-dash-header flex flex-wrap items-end justify-between gap-x-6 gap-y-3">
            <div class="min-w-0">
                <h2 id="bdrrm-dashboard-heading">Barangay Response Dashboard</h2>
                <p class="bdrrm-dash-lead mt-1">Review resident submissions and monitor your barangay reports.</p>
                <div class="bdrrm-dash-context mt-2 flex flex-wrap items-center gap-x-3 gap-y-1">
                    <span>Hello, {{ greeting }}</span>
                    <span v-if="dashboard" class="flex min-w-0 items-start gap-1">
                        <MapIcon name="pin" />
                        <span>{{ assignedNames }}</span>
                    </span>
                </div>
            </div>
            <div class="bdrrm-dash-header-actions flex min-w-0 flex-wrap items-end gap-2">
                <div v-if="barangays.length > 1" class="bdrrm-dash-filter grid min-w-0 gap-1">
                    <span id="bdrrm-dashboard-barangay-label">Assigned barangay</span>
                    <Select
                        v-model="selectedBarangay"
                        aria-labelledby="bdrrm-dashboard-barangay-label"
                        :options="barangayOptions"
                        option-label="label"
                        option-value="value"
                        :placeholder="allBarangaysLabel"
                        size="small"
                        append-to="self"
                        panel-class="bdrrm-dash-filter-panel"
                        :pt="{ transition: { css: false } }"
                        @change="load"
                    />
                </div>
                <Button as="router-link" to="/submissions" size="small" label="Review Submissions">
                    <template #icon><MapIcon name="clipboard-check" /></template>
                </Button>
            </div>
        </header>

        <Message v-if="error" severity="error" size="small">
            <div class="flex flex-wrap items-center gap-3">
                <span>{{ error }}</span>
                <Button type="button" size="small" severity="danger" outlined label="Retry" @click="load" />
            </div>
        </Message>

        <div v-if="initialLoading" class="bdrrm-dash-cards grid gap-3" aria-hidden="true">
            <Skeleton v-for="slot in 4" :key="slot" height="7rem" animation="none" />
        </div>
        <div v-else-if="dashboard" class="bdrrm-dash-cards grid gap-3">
            <router-link v-for="card in cards" :key="card.key" class="bdrrm-dash-card grid gap-2" :to="card.to">
                <div class="flex items-center gap-2.5">
                    <span class="bdrrm-dash-icon" :class="`is-${card.tint}`"><MapIcon :name="card.icon" /></span>
                    <strong class="mr-auto">{{ card.count }}</strong>
                    <MapIcon class="bdrrm-dash-chevron" name="chevron" />
                </div>
                <div class="min-w-0">
                    <span class="bdrrm-dash-card-label">{{ card.label }}</span>
                    <span class="bdrrm-dash-muted">{{ card.hint }}</span>
                </div>
            </router-link>
        </div>

        <div class="bdrrm-dash-grid grid gap-4" :class="{ 'has-overview': initialLoading || dashboard }">
            <section class="bdrrm-dash-panel bdrrm-dash-map" aria-labelledby="bdrrm-map-heading">
                <header class="bdrrm-dash-panel-head">
                    <h3 id="bdrrm-map-heading">Reported locations</h3>
                    <Button as="router-link" :to="fullMapTo" size="small" severity="secondary" outlined label="Open full map">
                        <template #icon><MapIcon name="external" /></template>
                    </Button>
                </header>
                <MapWorkspace
                    preview
                    compact
                    resident-submissions
                    scope-barangays
                    :allow-impact="false"
                    :api-base="apiBase"
                    :barangay-id="selectedBarangay"
                    @select-report="selectedReportId = $event"
                />
            </section>

            <template v-if="initialLoading || dashboard">
                <section class="bdrrm-dash-panel" aria-labelledby="bdrrm-status-heading">
                    <header class="bdrrm-dash-panel-head">
                        <h3 id="bdrrm-status-heading">Resident report status</h3>
                    </header>
                    <Skeleton v-if="initialLoading" height="12rem" animation="none" aria-hidden="true" />
                    <div v-else class="bdrrm-dash-status grid content-start gap-3">
                        <div class="bdrrm-dash-donut relative mx-auto">
                            <svg viewBox="0 0 108 108" aria-hidden="true">
                                <circle cx="54" cy="54" :r="donutRadius" fill="none" stroke="var(--resident-line)" stroke-width="10" />
                                <g transform="rotate(-90 54 54)">
                                    <circle
                                        v-for="segment in donutSegments"
                                        :key="segment.key"
                                        :class="`is-${segment.key}`"
                                        cx="54"
                                        cy="54"
                                        :r="donutRadius"
                                        fill="none"
                                        stroke="var(--status-color)"
                                        stroke-width="10"
                                        :stroke-dasharray="segment.dash"
                                        :stroke-dashoffset="segment.offset"
                                    />
                                </g>
                            </svg>
                            <div class="absolute inset-0 grid place-content-center text-center">
                                <strong class="bdrrm-dash-total">{{ statusTotal }}</strong>
                                <span class="bdrrm-dash-muted">Total reports</span>
                            </div>
                        </div>
                        <ul class="bdrrm-dash-legend" aria-label="Report status breakdown">
                            <li v-for="row in statuses" :key="row.key">
                                <span class="bdrrm-dash-swatch" :class="`is-${row.key}`" aria-hidden="true"></span>
                                <span>{{ row.label }}</span>
                                <strong class="tabular-nums">{{ row.count }}</strong>
                                <span class="bdrrm-dash-percent tabular-nums">{{ statusPercent(row.count) }}</span>
                            </li>
                        </ul>
                        <p v-if="!statusTotal" class="bdrrm-dash-muted text-center">No resident submissions in this area.</p>
                    </div>
                </section>

                <section class="bdrrm-dash-panel" aria-labelledby="bdrrm-attention-heading">
                    <header class="bdrrm-dash-panel-head">
                        <h3 id="bdrrm-attention-heading">Needs attention</h3>
                    </header>
                    <Skeleton v-if="initialLoading" height="8rem" animation="none" aria-hidden="true" />
                    <div v-else-if="!attention.length" class="bdrrm-dash-empty">
                        <span class="bdrrm-dash-icon"><MapIcon name="circle-check" /></span>
                        <p>Nothing needs your attention right now.</p>
                    </div>
                    <ul v-else class="bdrrm-dash-list">
                        <li v-for="item in attention" :key="`${item.kind}-${item.id}`" class="bdrrm-dash-row">
                            <span class="bdrrm-dash-icon" :class="item.kind === 'barangay' ? 'is-orange' : 'is-blue'">
                                <MapIcon :name="item.kind === 'barangay' ? 'undo' : item.kind === 'barangay-draft' ? 'file-pen' : 'clipboard-check'" />
                            </span>
                            <div class="grid min-w-0 gap-0.5">
                                <strong>{{ item.reference }}</strong>
                                <span class="bdrrm-dash-muted">{{ item.type }} · {{ item.detail }}</span>
                            </div>
                            <Button as="router-link" :to="item.path" size="small" severity="secondary" outlined :label="item.action" :aria-label="`${item.action} ${item.reference}`" />
                        </li>
                    </ul>
                </section>

                <section class="bdrrm-dash-panel" aria-labelledby="bdrrm-months-heading">
                    <header class="bdrrm-dash-panel-head">
                        <h3 id="bdrrm-months-heading">Submissions by month</h3>
                        <span v-if="months.available && months.has_history" class="bdrrm-dash-muted">Last {{ months.months.length }} months</span>
                    </header>
                    <Skeleton v-if="initialLoading" height="8rem" animation="none" aria-hidden="true" />
                    <div v-else-if="!months.available || !months.has_history" class="bdrrm-dash-empty">
                        <span class="bdrrm-dash-icon"><MapIcon name="calendar" /></span>
                        <p>{{ months.available ? 'No resident submissions have been recorded yet.' : 'Monthly counts are unavailable because submission times are not recorded.' }}</p>
                    </div>
                    <ul v-else class="bdrrm-dash-columns">
                        <li v-for="month in months.months" :key="month.key" class="min-w-0 text-center">
                            <div class="bdrrm-dash-column-track" aria-hidden="true" :style="{ '--bar-height': columnHeight(month.count) }">
                                <span class="bdrrm-dash-column-bar"></span>
                                <strong class="bdrrm-dash-column-value tabular-nums">{{ month.count }}</strong>
                            </div>
                            <span class="bdrrm-dash-month">{{ month.label }}</span>
                            <span class="sr-only">{{ month.count }} submissions</span>
                        </li>
                    </ul>
                </section>

                <section class="bdrrm-dash-panel" aria-labelledby="bdrrm-recent-heading">
                    <header class="bdrrm-dash-panel-head">
                        <h3 id="bdrrm-recent-heading">Recent submissions</h3>
                        <Button as="router-link" to="/submissions" size="small" severity="secondary" text>
                            View all <MapIcon name="chevron" />
                        </Button>
                    </header>
                    <Skeleton v-if="initialLoading" height="8rem" animation="none" aria-hidden="true" />
                    <div v-else-if="!recent.length" class="bdrrm-dash-empty">
                        <span class="bdrrm-dash-icon"><MapIcon name="reports" /></span>
                        <p>No resident submissions yet.</p>
                    </div>
                    <ul v-else class="bdrrm-dash-list">
                        <li v-for="row in recent" :key="row.id" class="bdrrm-dash-row bdrrm-dash-submission">
                            <div class="grid min-w-0 justify-items-start gap-0.5">
                                <strong>{{ row.resident_name || 'Resident' }}</strong>
                                <span class="bdrrm-dash-muted">{{ row.incident_name || 'Incident' }} · {{ row.reported_location || 'Location not written' }}</span>
                                <span class="bdrrm-dash-muted">{{ formatWhen(row.submitted_at) }}</span>
                            </div>
                            <StatusBadge :label="row.label" />
                            <Button as="router-link" :to="row.path" size="small" severity="secondary" outlined :label="row.action" :aria-label="`${row.action} ${row.reference || 'resident submission'}`" />
                        </li>
                    </ul>
                </section>

                <section class="bdrrm-dash-panel" :class="{ 'self-start': !initialLoading && !activity.length }" aria-labelledby="bdrrm-activity-heading">
                    <header class="bdrrm-dash-panel-head">
                        <h3 id="bdrrm-activity-heading">Recent activity</h3>
                    </header>
                    <Skeleton v-if="initialLoading" height="8rem" animation="none" aria-hidden="true" />
                    <div v-else-if="!activity.length" class="bdrrm-dash-empty">
                        <span class="bdrrm-dash-icon"><MapIcon name="history" /></span>
                        <p>No activity is recorded.</p>
                    </div>
                    <ul v-else class="bdrrm-dash-list">
                        <li v-for="item in activity" :key="item.id" class="bdrrm-dash-row bdrrm-dash-activity">
                            <span class="bdrrm-dash-icon"><MapIcon :name="activityIcons[item.action] || 'clock'" /></span>
                            <div class="grid min-w-0 gap-0.5">
                                <p>{{ item.summary }}</p>
                                <p class="bdrrm-dash-muted">
                                    <router-link :to="item.path">{{ item.reference }}</router-link> · {{ formatWhen(item.at) }}
                                </p>
                            </div>
                        </li>
                    </ul>
                </section>
            </template>
        </div>
    </section>
</template>
