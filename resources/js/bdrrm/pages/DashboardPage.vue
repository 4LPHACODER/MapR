<script setup>
import { computed, inject, onMounted, ref } from 'vue';
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
    drafts: { icon: 'file-pen', hint: 'Continue preparing reports.', to: '/reports/drafts', tint: 'amber' },
    returned: { icon: 'undo', hint: 'Updates required.', to: '/reports/returned', tint: 'orange' },
    submitted: { icon: 'send', hint: 'Signed or sent onward for review.', to: '/reports/submitted', tint: 'navy' },
};

const greeting = computed(() => dashboard.value?.officer_name || officerName.value || 'there');
const barangays = computed(() => dashboard.value?.barangays || []);
const assignedNames = computed(() => {
    if (!barangays.value.length) return 'No barangay is assigned.';
    return barangays.value.map((barangay) => barangay.name).join(', ');
});
const cards = computed(() => (dashboard.value?.cards || []).map((card) => ({
    ...card,
    ...(cardMeta[card.key] || { icon: 'reports', hint: '', to: '/submissions', tint: 'blue' }),
})));
const statusColors = {
    submitted: '#2563eb',
    under_review: '#1e3a8a',
    reviewed: '#15803d',
    returned: '#c2410c',
};
const statuses = computed(() => dashboard.value?.resident_status || []);
const statusTotal = computed(() => statuses.value.reduce((sum, row) => sum + (Number(row.count) || 0), 0));
const statusHasData = computed(() => statusTotal.value > 0);
const statusSummary = computed(() => statuses.value.map((row) => `${row.label} ${row.count} (${statusPercent(row.count)})`).join(', '));
const donutRadius = 46;
const donutCircumference = 2 * Math.PI * donutRadius;
const donutSegments = computed(() => {
    const total = statusTotal.value;
    if (!total) return [];
    const slices = statuses.value.filter((row) => Number(row.count) > 0);
    if (slices.length === 1) {
        return [{ ...slices[0], color: statusColors[slices[0].key] || '#2563eb', full: true }];
    }
    const gap = 14;
    const usable = donutCircumference - slices.length * gap;
    let cursor = 0;
    return slices.map((row) => {
        const length = ((Number(row.count) || 0) / total) * usable;
        const segment = {
            ...row,
            color: statusColors[row.key] || '#2563eb',
            full: false,
            dash: `${length} ${donutCircumference - length}`,
            offset: -cursor,
        };
        cursor += length + gap;
        return segment;
    });
});
const months = computed(() => dashboard.value?.resident_months || { available: false, has_history: false, months: [] });
const monthMax = computed(() => Math.max(1, ...(months.value.months || []).map((row) => Number(row.count) || 0)));
const monthSummary = computed(() => (months.value.months || []).map((row) => `${row.label} ${row.count}`).join(', '));
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
    <section class="bdrrm-dashboard" :aria-busy="loading ? 'true' : 'false'">
        <header class="bdrrm-dash-header">
            <div class="bdrrm-dash-intro">
                <h2>Barangay Response Dashboard</h2>
                <p class="bdrrm-dash-hello">Hello, {{ greeting }}</p>
                <p class="bdrrm-dash-place">
                    <MapIcon name="pin" />
                    <span>{{ assignedNames }}</span>
                </p>
                <p class="bdrrm-dash-lead">Review resident submissions and monitor your barangay reports.</p>
            </div>
            <div class="bdrrm-dash-header-actions">
                <label v-if="barangays.length > 1" class="bdrrm-dash-filter">
                    <span>Assigned barangay</span>
                    <select v-model="selectedBarangay" @change="load">
                        <option value="">All assigned barangays</option>
                        <option v-for="barangay in barangays" :key="barangay.id" :value="String(barangay.id)">{{ barangay.name }}</option>
                    </select>
                </label>
                <router-link class="bdrrm-dash-primary" to="/submissions">Review Submissions</router-link>
            </div>
        </header>

        <p v-if="error" class="bdrrm-dash-error" role="alert">
            <span>{{ error }}</span>
            <button type="button" @click="load">Retry</button>
        </p>

        <div v-if="loading && !dashboard" class="bdrrm-dash-cards" aria-hidden="true">
            <span v-for="slot in 4" :key="slot" class="bdrrm-dash-skeleton"></span>
        </div>
        <div v-else-if="dashboard" class="bdrrm-dash-cards">
            <router-link v-for="card in cards" :key="card.key" class="bdrrm-dash-card" :to="card.to">
                <span class="bdrrm-dash-icon" :class="`is-${card.tint}`"><MapIcon :name="card.icon" /></span>
                <span class="bdrrm-dash-card-copy">
                    <span class="bdrrm-dash-card-label">{{ card.label }}</span>
                    <strong>{{ card.count }}</strong>
                    <span class="bdrrm-dash-card-hint">{{ card.hint }}</span>
                </span>
                <MapIcon name="chevron" />
            </router-link>
        </div>

        <div class="bdrrm-dash-split">
            <section class="bdrrm-dash-panel bdrrm-dash-map" aria-labelledby="bdrrm-map-heading">
                <div class="bdrrm-dash-section-head">
                    <h3 id="bdrrm-map-heading">Reported locations</h3>
                    <router-link :to="fullMapTo">Open Full Map</router-link>
                </div>
                <MapWorkspace
                    preview
                    resident-submissions
                    scope-barangays
                    :allow-impact="false"
                    :api-base="apiBase"
                    :barangay-id="selectedBarangay"
                    @select-report="selectedReportId = $event"
                />
            </section>
            <div class="bdrrm-dash-side">
                <section class="bdrrm-dash-panel" aria-labelledby="bdrrm-status-heading">
                    <h3 id="bdrrm-status-heading"><MapIcon name="reports" /> Resident report status</h3>
                    <p class="bdrrm-dash-note">Current status of resident reports in your assigned area.</p>
                    <div v-if="loading && !dashboard" class="bdrrm-dash-skeleton bdrrm-dash-skeleton-block"></div>
                    <p v-else-if="!statusHasData" class="bdrrm-dash-empty"><MapIcon name="reports" /> No resident submissions to chart.</p>
                    <div v-else class="bdrrm-dash-donut">
                        <svg viewBox="0 0 120 120" role="img" :aria-label="`Resident report status. ${statusSummary}.`">
                            <g transform="rotate(-90 60 60)">
                                <circle
                                    v-for="segment in donutSegments"
                                    :key="segment.key"
                                    cx="60"
                                    cy="60"
                                    r="46"
                                    fill="none"
                                    :stroke="segment.color"
                                    stroke-width="12"
                                    :stroke-linecap="segment.full ? 'butt' : 'round'"
                                    :stroke-dasharray="segment.full ? undefined : segment.dash"
                                    :stroke-dashoffset="segment.full ? undefined : segment.offset"
                                />
                            </g>
                            <text x="60" y="58" text-anchor="middle" fill="#0f172a" font-size="26" font-weight="700">{{ statusTotal }}</text>
                            <text x="60" y="74" text-anchor="middle" fill="#64748b" font-size="11" font-weight="600">Total</text>
                        </svg>
                        <ul class="bdrrm-dash-legend">
                            <li v-for="row in statuses" :key="row.key">
                                <span class="bdrrm-dash-swatch" :class="`is-${row.key}`" aria-hidden="true"></span>
                                <span>{{ row.label }}</span>
                                <strong>{{ row.count }} · {{ statusPercent(row.count) }}</strong>
                            </li>
                        </ul>
                    </div>
                    <p v-if="statusHasData" class="bdrrm-sr">Resident report status. {{ statusSummary }}.</p>
                </section>
                <section class="bdrrm-dash-panel bdrrm-dash-attention" aria-labelledby="bdrrm-attention-heading">
                    <h3 id="bdrrm-attention-heading"><MapIcon name="alert-circle" /> Needs attention</h3>
                    <div v-if="loading && !dashboard" class="bdrrm-dash-skeleton bdrrm-dash-skeleton-block"></div>
                    <p v-else-if="!attention.length" class="bdrrm-dash-empty">Nothing needs your attention right now.</p>
                    <ul v-else class="bdrrm-dash-list">
                        <li v-for="item in attention" :key="`${item.kind}-${item.id}`">
                            <div>
                                <strong>{{ item.reference }}</strong>
                                <span>{{ item.type }}</span>
                                <span>{{ item.detail }}</span>
                            </div>
                            <router-link :to="item.path">{{ item.action }}</router-link>
                        </li>
                    </ul>
                </section>
            </div>
        </div>

        <section class="bdrrm-dash-panel" aria-labelledby="bdrrm-months-heading">
                <h3 id="bdrrm-months-heading"><MapIcon name="clock" /> Submissions by month</h3>
                <div v-if="loading && !dashboard" class="bdrrm-dash-skeleton bdrrm-dash-skeleton-block"></div>
                <p v-else-if="!months.available" class="bdrrm-dash-empty"><MapIcon name="clock" /> Monthly counts are unavailable because submission times are not recorded.</p>
                <p v-else-if="!months.has_history" class="bdrrm-dash-empty"><MapIcon name="clock" /> No resident submissions have been recorded yet.</p>
                <div v-else class="bdrrm-dash-columns">
                    <div v-for="month in months.months" :key="month.key" class="bdrrm-dash-column">
                        <strong>{{ month.count }}</strong>
                        <span class="bdrrm-dash-column-track" aria-hidden="true"><span v-if="Number(month.count) > 0" :style="{ height: columnHeight(month.count) }"></span></span>
                        <span>{{ month.label }}</span>
                    </div>
                </div>
                <p v-if="months.available && months.has_history" class="bdrrm-sr">Submissions by month. {{ monthSummary }}.</p>
            </section>

        <div class="bdrrm-dash-lower">
            <section class="bdrrm-dash-panel" aria-labelledby="bdrrm-recent-heading">
                <div class="bdrrm-dash-section-head">
                    <h3 id="bdrrm-recent-heading"><MapIcon name="file-pen" /> Recent submissions</h3>
                    <router-link to="/submissions">View All</router-link>
                </div>
                <div v-if="loading && !dashboard" class="bdrrm-dash-skeleton bdrrm-dash-skeleton-block"></div>
                <p v-else-if="!recent.length" class="bdrrm-dash-empty">No resident submissions yet.</p>
                <ul v-else class="bdrrm-dash-list">
                    <li v-for="row in recent" :key="row.id">
                        <div>
                            <strong>{{ row.resident_name || 'Resident' }}</strong>
                            <span>{{ row.incident_name || 'Incident' }}</span>
                            <span>{{ row.reported_location || 'Location not written' }}</span>
                            <span>{{ formatWhen(row.submitted_at) }}</span>
                            <span class="bdrrm-dash-label">{{ row.label }}</span>
                        </div>
                        <router-link :to="row.path">{{ row.action }}</router-link>
                    </li>
                </ul>
            </section>
            <section class="bdrrm-dash-panel bdrrm-dash-activity" aria-labelledby="bdrrm-activity-heading">
                <h3 id="bdrrm-activity-heading"><MapIcon name="clock" /> Recent activity</h3>
                <div v-if="loading && !dashboard" class="bdrrm-dash-skeleton bdrrm-dash-skeleton-block"></div>
                <p v-else-if="!activity.length" class="bdrrm-dash-empty">No activity is recorded.</p>
                <ul v-else class="bdrrm-dash-activity-list">
                    <li v-for="item in activity" :key="item.id">
                        <MapIcon :name="activityIcons[item.action] || 'clock'" />
                        <div>
                            <p>{{ item.summary }}</p>
                            <p><router-link :to="item.path">{{ item.reference }}</router-link> · {{ formatWhen(item.at) }}</p>
                        </div>
                    </li>
                </ul>
            </section>
        </div>
    </section>
</template>
