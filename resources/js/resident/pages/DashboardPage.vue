<script setup>
import { computed, inject, onMounted, ref } from 'vue';
import MapIcon from '../../shared/components/MapIcon.vue';
import SummaryCard from '../components/SummaryCard.vue';
import StatusBadge from '../components/StatusBadge.vue';
import { formatWhen } from '../format';

const apiBase = inject('apiBase');
const data = ref(null);
const error = ref('');
const updates = ref([]);
const updatesLoading = ref(true);
const updatesError = ref(false);
const showAllUpdates = ref(false);
const selectedUpdate = ref(null);
const detailsDialog = ref(null);
const visibleUpdates = computed(() => (showAllUpdates.value ? updates.value : updates.value.slice(0, 1)));

const statusMax = computed(() => {
    const counts = data.value?.counts;
    if (!counts) return 1;
    return Math.max(1, counts.under_review || 0, counts.needs_correction || 0, counts.finalized || 0);
});

const statusBars = computed(() => {
    if (!data.value) return [];
    const counts = data.value.counts;
    const scale = statusMax.value;
    return [
        { label: 'Under Review', count: counts.under_review, accent: 'blue', width: ((counts.under_review || 0) / scale) * 100 },
        { label: 'Needs Correction', count: counts.needs_correction, accent: 'orange', width: ((counts.needs_correction || 0) / scale) * 100 },
        { label: 'Finalized', count: counts.finalized, accent: 'green', width: ((counts.finalized || 0) / scale) * 100 },
    ];
});

const activityMax = computed(() => Math.max(1, ...(data.value?.activity || []).map((item) => Number(item.count) || 0)));

const statusSummary = computed(() => statusBars.value.map((bar) => `${bar.label} ${bar.count}`).join(', '));
const activitySummary = computed(() => (data.value?.activity || []).map((item) => `${item.label} ${item.count}`).join(', '));

function incidentLabel(report) {
    return report.incident_type || report.incident_name || '—';
}

function whenLabel(report) {
    return formatWhen(report.submitted_at || report.updated_at) || '—';
}

async function loadUpdates() {
    updatesError.value = false;
    updatesLoading.value = true;
    try {
        updates.value = (await window.axios.get(`${apiBase}/incident-updates`)).data.data || [];
    } catch {
        updatesError.value = true;
        updates.value = [];
    } finally {
        updatesLoading.value = false;
    }
}

function openDetails(item) {
    selectedUpdate.value = item;
    detailsDialog.value?.showModal();
}

function closeDetails() {
    detailsDialog.value?.close();
    selectedUpdate.value = null;
}

onMounted(async () => {
    loadUpdates();
    try {
        data.value = (await window.axios.get(`${apiBase}/dashboard`)).data;
    } catch (exception) {
        error.value = exception.response?.data?.message || 'The dashboard could not be loaded.';
    }
});
</script>

<template>
    <section v-if="data" class="resident-dashboard">
        <header class="resident-dashboard-intro">
            <div class="resident-dashboard-intro-copy">
                <h2 class="resident-dashboard-hello">Hello, {{ data.first_name || data.greeting_name }}</h2>
                <p v-if="data.barangay_name" class="resident-dashboard-place">
                    <MapIcon name="pin" />
                    <span>{{ data.barangay_name }}</span>
                </p>
                <p class="resident-dashboard-lead">Report incidents and track the progress of your submissions.</p>
                <p v-if="!data.reporting_open" class="resident-reason">Your barangay is not assigned yet. Ask barangay staff to assign it before reporting.</p>
            </div>
            <div v-if="data.reporting_open || data.draft" class="resident-dashboard-actions">
                <router-link v-if="data.reporting_open" class="resident-primary" to="/report">
                    <MapIcon name="camera" /> Report an Incident
                </router-link>
                <router-link v-if="data.draft" class="resident-secondary" :to="`/report/${data.draft.id}`">
                    <MapIcon name="pencil" /> Continue Draft
                </router-link>
            </div>
        </header>

        <section class="resident-card resident-dashboard-panel resident-dashboard-updates" aria-labelledby="resident-updates-heading">
            <h3 id="resident-updates-heading" class="resident-dashboard-title">
                <MapIcon name="disaster" /> Disaster Incident Updates
            </h3>
            <div v-if="updatesLoading" class="resident-dashboard-skeleton" aria-busy="true" aria-label="Loading incident updates">
                <span></span>
                <span></span>
            </div>
            <p v-else-if="updatesError" class="resident-dashboard-update-error">
                Unable to load incident updates.
                <button type="button" @click="loadUpdates">Retry</button>
            </p>
            <p v-else-if="!updates.length" class="resident-dashboard-quiet">
                <span>No disaster incident updates for your barangay at this time.</span>
            </p>
            <template v-else>
                <article
                    v-for="item in visibleUpdates"
                    :key="item.id"
                    class="resident-dashboard-update"
                    :class="{ 'is-active': item.status === 'active' }"
                >
                    <div class="resident-dashboard-update-copy">
                        <div class="resident-dashboard-update-head">
                            <strong>{{ item.name }}</strong>
                            <span class="resident-dashboard-incident-status" :class="item.status === 'active' ? 'is-active' : 'is-closed'">{{ item.status_label }}</span>
                        </div>
                        <p v-if="item.disaster_type">{{ item.disaster_type }}</p>
                        <p v-if="item.coverage_label">{{ item.coverage_label }}</p>
                        <p v-if="item.occurred_at">Occurred {{ formatWhen(item.occurred_at) }}</p>
                        <p v-if="item.published_at">Published {{ formatWhen(item.published_at) }}</p>
                        <p v-if="item.updated_at">Updated {{ formatWhen(item.updated_at) }}</p>
                        <p v-if="item.description" class="resident-dashboard-update-description">{{ item.description }}</p>
                        <p class="resident-dashboard-attribution">Published by CDRRMO</p>
                    </div>
                    <div class="resident-dashboard-update-actions">
                        <button type="button" class="resident-dashboard-view" @click="openDetails(item)">View Details</button>
                        <router-link v-if="data.reporting_open" class="resident-secondary" to="/report">
                            <MapIcon name="camera" /> Report an Incident
                        </router-link>
                    </div>
                </article>
                <button v-if="updates.length > 1" type="button" class="resident-dashboard-textlink" @click="showAllUpdates = !showAllUpdates">
                    {{ showAllUpdates ? 'Show less' : 'Show more' }}
                </button>
            </template>
        </section>

        <dialog ref="detailsDialog" class="resident-dashboard-dialog" aria-labelledby="resident-update-dialog-title" @close="selectedUpdate = null">
            <template v-if="selectedUpdate">
                <h3 id="resident-update-dialog-title">{{ selectedUpdate.name }}</h3>
                <p><span class="resident-dashboard-incident-status" :class="selectedUpdate.status === 'active' ? 'is-active' : 'is-closed'">{{ selectedUpdate.status_label }}</span></p>
                <p v-if="selectedUpdate.disaster_type">{{ selectedUpdate.disaster_type }}</p>
                <p v-if="selectedUpdate.coverage_label">{{ selectedUpdate.coverage_label }}</p>
                <p v-if="selectedUpdate.occurred_at">Occurred {{ formatWhen(selectedUpdate.occurred_at) }}</p>
                <p v-if="selectedUpdate.published_at">Published {{ formatWhen(selectedUpdate.published_at) }}</p>
                <p v-if="selectedUpdate.updated_at">Updated {{ formatWhen(selectedUpdate.updated_at) }}</p>
                <p>{{ selectedUpdate.description || 'No description was included with this update.' }}</p>
                <p class="resident-dashboard-attribution">Published by CDRRMO</p>
                <div class="resident-dashboard-update-actions">
                    <router-link v-if="data.reporting_open" class="resident-secondary" to="/report" @click="closeDetails">
                        <MapIcon name="camera" /> Report an Incident
                    </router-link>
                    <button type="button" class="resident-dashboard-view" @click="closeDetails">Close</button>
                </div>
            </template>
        </dialog>

        <div class="resident-dashboard-counts">
            <router-link to="/reports">
                <SummaryCard label="Total Submitted" :count="data.counts.submitted" icon="file" accent="blue" />
            </router-link>
            <router-link to="/reports">
                <SummaryCard label="Under Review" :count="data.counts.under_review" icon="clock" accent="amber" />
            </router-link>
            <router-link to="/reports?status=returned">
                <SummaryCard label="Needs Correction" :count="data.counts.needs_correction" icon="alert-circle" accent="orange" />
            </router-link>
            <router-link to="/reports">
                <SummaryCard label="Finalized" :count="data.counts.finalized" icon="circle-check" accent="green" />
            </router-link>
        </div>

        <section v-if="data.counts.submitted" class="resident-dashboard-charts">
            <section class="resident-card resident-dashboard-panel" aria-labelledby="resident-status-heading">
                <h3 id="resident-status-heading" class="resident-dashboard-title">
                    <MapIcon name="reports" /> Report status
                </h3>
                <ul class="resident-dashboard-bars" :aria-label="`Report status. ${statusSummary}.`">
                    <li v-for="bar in statusBars" :key="bar.label">
                        <span class="resident-dashboard-bar-label">{{ bar.label }}</span>
                        <span class="resident-dashboard-track" aria-hidden="true">
                            <span :class="`is-${bar.accent}`" :style="{ width: `${bar.width}%` }"></span>
                        </span>
                        <strong>{{ bar.count }}</strong>
                    </li>
                </ul>
            </section>
            <section class="resident-card resident-dashboard-panel" aria-labelledby="resident-activity-heading">
                <h3 id="resident-activity-heading" class="resident-dashboard-title">
                    <MapIcon name="clock" /> Six-month activity
                </h3>
                <ul class="resident-dashboard-months" :aria-label="`Six-month activity. ${activitySummary}.`">
                    <li v-for="month in data.activity" :key="month.label">
                        <span class="resident-dashboard-bar-label">{{ month.label }}</span>
                        <span class="resident-dashboard-track" aria-hidden="true">
                            <span :style="{ width: `${((Number(month.count) || 0) / activityMax) * 100}%` }"></span>
                        </span>
                        <strong>{{ month.count }}</strong>
                    </li>
                </ul>
            </section>
        </section>
        <section v-else class="resident-card resident-dashboard-overview" aria-labelledby="resident-overview-heading">
            <span class="resident-dashboard-overview-icon" aria-hidden="true"><MapIcon name="reports" /></span>
            <div>
                <h3 id="resident-overview-heading">Your reporting overview</h3>
                <p>Your reporting activity will appear here after your first submission.</p>
                <p>Your first submitted report starts your status overview and six-month activity charts.</p>
            </div>
        </section>

        <div class="resident-dashboard-lower">
            <section class="resident-card resident-dashboard-panel" aria-labelledby="resident-recent-heading">
                <div class="resident-dashboard-card-head">
                    <h3 id="resident-recent-heading" class="resident-dashboard-title">
                        <MapIcon name="reports" /> Recent reports
                    </h3>
                    <router-link class="resident-dashboard-textlink" to="/reports">View all</router-link>
                </div>
                <p v-if="!data.recent.length" class="resident-dashboard-quiet">
                    <MapIcon name="file" />
                    <span>No submitted reports yet.</span>
                </p>
                <article v-for="report in data.recent" :key="report.id" class="resident-dashboard-row">
                    <div class="resident-dashboard-row-id">
                        <strong>{{ report.reference || '—' }}</strong>
                        <StatusBadge :label="report.label" />
                    </div>
                    <p>{{ incidentLabel(report) }}</p>
                    <p>{{ report.reported_location || '—' }}</p>
                    <p class="resident-dashboard-when">{{ whenLabel(report) }}</p>
                    <router-link class="resident-dashboard-view" :to="`/reports/${report.id}`">View</router-link>
                </article>
            </section>
            <section class="resident-card resident-dashboard-panel" aria-labelledby="resident-attention-heading">
                <h3 id="resident-attention-heading" class="resident-dashboard-title">
                    <MapIcon name="alert-circle" /> Needs your attention
                </h3>
                <p v-if="!data.attention.length" class="resident-dashboard-quiet">
                    <span>Nothing needs your attention.</span>
                </p>
                <article v-for="item in data.attention" :key="item.id" class="resident-dashboard-attention">
                    <strong>{{ item.reference || '—' }}</strong>
                    <p v-if="item.reason">{{ item.reason }}</p>
                    <router-link class="resident-dashboard-revise" :to="`/report/${item.id}`">Revise and Resubmit</router-link>
                </article>
            </section>
        </div>
    </section>
    <p v-else-if="error" class="resident-error">{{ error }}</p>
    <p v-else>Loading your dashboard…</p>
</template>
