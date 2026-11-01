<script setup>
import { computed, inject, nextTick, onBeforeUnmount, ref, watch } from 'vue';
import MapIcon from './MapIcon.vue';

const apiBase = inject('apiBase', '/cdrrmo/api');
const props = defineProps({
    open: { type: Boolean, default: false },
    resident: { type: Object, default: null },
    incidentId: { type: [String, Number], default: '' },
    allowEdit: { type: Boolean, default: true },
});

const emit = defineEmits(['close', 'edit', 'retry', 'open-report', 'view-map']);
const panel = ref(null);
const closeButton = ref(null);
const section = ref('overview');
const sourceOpen = ref(false);
const reports = ref(null);
const reportsError = ref('');
const reportsLoading = ref(false);
let reportTicket = 0;

const initials = computed(() => {
    const parts = String(props.resident?.display_name || '').trim().split(/\s+/).filter(Boolean);
    return parts.slice(0, 2).map((part) => part[0] || '').join('').toUpperCase() || 'R';
});
const statusLabel = computed(() => (props.resident?.residency_status === 'inactive' ? 'Archived' : 'Active'));
const reportGroups = computed(() => {
    if (!reports.value) return [];
    return [
        ['Submitted by the linked account', reports.value.submitted_by_account || []],
        ['Household or building reports', reports.value.household_or_building || []],
        ['Estimate or consolidated membership', reports.value.estimate_or_consolidated || []],
    ];
});
const reportTotal = computed(() => reportGroups.value.reduce((sum, [, rows]) => sum + rows.length, 0));

function blank(value) {
    const text = value === null || value === undefined ? '' : String(value).trim();
    return text === '' ? 'Not recorded' : text;
}

function formatDate(value) {
    if (!value) return 'Not recorded';
    const date = new Date(value);
    if (Number.isNaN(date.getTime())) return String(value);
    return new Intl.DateTimeFormat('en-GB', {
        timeZone: 'Asia/Manila',
        day: '2-digit',
        month: 'short',
        year: 'numeric',
        hour: 'numeric',
        minute: '2-digit',
        hour12: true,
    }).format(date).replace(',', '') + ' PHT';
}

function focusable() {
    if (!panel.value) return [];
    return [...panel.value.querySelectorAll('button, a[href], input, select, textarea, [tabindex]:not([tabindex="-1"])')]
        .filter((node) => !node.disabled && node.offsetParent !== null);
}

async function loadReports() {
    if (!props.resident?.id || props.resident.loading) {
        reports.value = null;
        return;
    }
    const ticket = ++reportTicket;
    const id = props.resident.id;
    reportsLoading.value = true;
    reportsError.value = '';
    try {
        const { data } = await window.axios.get(`${apiBase}/residents/${id}/reports`, {
            params: { incident_id: props.incidentId || undefined },
        });
        if (ticket !== reportTicket || props.resident?.id !== id) return;
        reports.value = data;
    } catch {
        if (ticket === reportTicket) reportsError.value = 'Related reports could not be loaded.';
    } finally {
        if (ticket === reportTicket) reportsLoading.value = false;
    }
}

watch(() => props.open, async (isOpen) => {
    document.body.style.overflow = isOpen ? 'hidden' : '';
    if (!isOpen) return;
    section.value = 'overview';
    sourceOpen.value = false;
    await nextTick();
    closeButton.value?.focus();
});

watch(() => props.resident?.id, () => {
    section.value = 'overview';
    reports.value = null;
    reportsError.value = '';
    if (props.open) loadReports();
});

watch(() => props.incidentId, () => {
    if (props.open && props.resident?.id) loadReports();
});

function onKeydown(event) {
    if (event.key === 'Escape') {
        event.preventDefault();
        emit('close');
        return;
    }
    if (event.key !== 'Tab') return;
    const nodes = focusable();
    if (!nodes.length) return;
    const first = nodes[0];
    const last = nodes[nodes.length - 1];
    if (event.shiftKey && document.activeElement === first) {
        event.preventDefault();
        last.focus();
    } else if (!event.shiftKey && document.activeElement === last) {
        event.preventDefault();
        first.focus();
    }
}

onBeforeUnmount(() => {
    document.body.style.overflow = '';
});
</script>

<template>
    <div v-if="open && resident" class="residents-drawer-backdrop" @keydown="onKeydown" @click.self="emit('close')">
        <aside ref="panel" class="residents-drawer" role="dialog" aria-modal="true" aria-labelledby="resident-detail-title">
            <header class="resident-drawer-header">
                <span class="resident-avatar" aria-hidden="true">{{ initials }}</span>
                <div class="resident-drawer-title">
                    <h3 id="resident-detail-title">{{ resident.display_name || 'Resident' }}</h3>
                    <p>{{ resident.registry_reference || 'Not recorded' }}</p>
                </div>
                <span v-if="!resident.loading" class="resident-status" :data-status="resident.residency_status">{{ statusLabel }}</span>
                <button ref="closeButton" type="button" class="resident-close" aria-label="Close resident details" @click="emit('close')">
                    <MapIcon name="x" />
                </button>
            </header>

            <div class="resident-drawer-body">
                <p v-if="resident.loading" class="cdrrmo-muted">Loading resident details…</p>
                <p v-else-if="resident.error" class="cdrrmo-error" role="alert">
                    This resident could not be loaded.
                    <button type="button" class="resident-action resident-action-secondary" @click="emit('retry')">Retry</button>
                </p>
                <template v-else>
                    <div class="resident-tabs" role="tablist" aria-label="Resident details">
                        <button type="button" role="tab" :aria-selected="section === 'overview'" @click="section = 'overview'">Overview</button>
                        <button type="button" role="tab" :aria-selected="section === 'household'" @click="section = 'household'">Household</button>
                        <button type="button" role="tab" :aria-selected="section === 'reports'" @click="section = 'reports'; loadReports()">Reports</button>
                        <button v-if="resident.history?.length" type="button" role="tab" :aria-selected="section === 'history'" @click="section = 'history'">History</button>
                    </div>

                    <section v-if="section === 'overview'" class="resident-section">
                        <dl class="resident-fields">
                            <div><dt>Barangay</dt><dd>{{ blank(resident.barangay) }}</dd></div>
                            <div><dt>Sex</dt><dd>{{ blank(resident.sex) }}</dd></div>
                            <div><dt>Birth date</dt><dd>{{ blank(resident.birth_date) }}</dd></div>
                            <div><dt>Record status</dt><dd>{{ statusLabel }}</dd></div>
                            <div>
                                <dt>Household reference</dt>
                                <dd>
                                    <button v-if="resident.map_available && resident.household_reference" type="button" class="resident-report-link" @click="emit('view-map', resident)">{{ resident.household_reference }}</button>
                                    <template v-else>{{ blank(resident.household_reference) }}</template>
                                </dd>
                            </div>
                            <div>
                                <dt>Building reference</dt>
                                <dd>
                                    <button v-if="resident.map_available && resident.building_reference" type="button" class="resident-report-link" @click="emit('view-map', resident)">{{ resident.building_reference }}</button>
                                    <template v-else>{{ blank(resident.building_reference) }}</template>
                                </dd>
                            </div>
                        </dl>
                        <p v-if="resident.potentially_affected !== null" class="cdrrmo-muted">{{ resident.potentially_affected ? 'Potentially affected for the selected incident.' : 'Not listed for the selected incident.' }}</p>
                        <details class="resident-source" :open="sourceOpen" @toggle="sourceOpen = $event.target.open">
                            <summary>Source information</summary>
                            <p>Record source: {{ blank(resident.record_source) }}</p>
                        </details>
                    </section>

                    <section v-else-if="section === 'household'" class="resident-section">
                        <p v-if="!resident.household">Not recorded</p>
                        <template v-else>
                            <div class="resident-metrics">
                                <article>
                                    <MapIcon name="home" />
                                    <span>Reported household size</span>
                                    <strong>{{ resident.household.source_household_size ?? 'Not recorded' }}</strong>
                                </article>
                                <article>
                                    <MapIcon name="users" />
                                    <span>Linked resident records</span>
                                    <strong>{{ resident.household.linked_resident_count }}</strong>
                                </article>
                                <article v-if="resident.household.family_count !== null && resident.household.family_count !== undefined">
                                    <MapIcon name="family" />
                                    <span>Family count</span>
                                    <strong>{{ resident.household.family_count }}</strong>
                                </article>
                            </div>
                            <p v-if="resident.household.roster_incomplete" class="cdrrmo-muted">Reported household size includes members not individually listed in this registry.</p>
                            <h4>Recorded members</h4>
                            <ul v-if="resident.household.members?.length" class="resident-members">
                                <li v-for="member in resident.household.members" :key="member.id" :class="{ 'is-current': member.current }">
                                    <strong>{{ member.display_name }}</strong>
                                    <span>{{ member.registry_reference }}</span>
                                    <em v-if="member.current">Viewing</em>
                                </li>
                            </ul>
                            <p v-else class="cdrrmo-muted">No linked resident records are available.</p>
                            <button v-if="resident.map_available" type="button" class="resident-action resident-action-secondary" @click="emit('view-map', resident)">
                                <MapIcon name="pin" /> View Household on Map
                            </button>
                            <p v-else class="cdrrmo-muted">{{ resident.map_limitation || 'No usable location is recorded.' }}</p>
                        </template>
                    </section>

                    <section v-else-if="section === 'reports'" class="resident-section">
                        <p>{{ reportsLoading ? 'Loading reports…' : `${reportTotal} accessible related report${reportTotal === 1 ? '' : 's'}` }}{{ incidentId ? ' in the selected incident.' : '.' }}</p>
                        <p v-if="reportsError" class="cdrrmo-error" role="alert">{{ reportsError }} <button type="button" class="resident-action resident-action-secondary" @click="loadReports">Retry</button></p>
                        <p v-else-if="!reportsLoading && reportTotal === 0" class="cdrrmo-muted">No reports in this scope.</p>
                        <template v-else-if="!reportsLoading">
                            <section v-for="[label, rows] in reportGroups" :key="label">
                                <h4>{{ label }}</h4>
                                <ul v-if="rows.length" class="resident-members">
                                    <li v-for="item in rows" :key="item.report_reference + item.report_type">
                                        <button v-if="item.open_path" type="button" class="resident-report-link" @click="emit('open-report', item)">{{ item.report_reference }}</button>
                                        <strong v-else>{{ item.report_reference }}</strong>
                                        <span>{{ item.report_type }} · {{ item.status }} · {{ formatDate(item.prepared_at) }}</span>
                                    </li>
                                </ul>
                                <p v-else class="cdrrmo-muted">None in this group.</p>
                            </section>
                        </template>
                    </section>

                    <section v-else class="resident-section">
                        <ul class="resident-members">
                            <li v-for="(event, index) in resident.history" :key="index">
                                <strong>{{ event.action }}</strong>
                                <span>{{ event.summary }}</span>
                                <span>{{ formatDate(event.created_at) }}</span>
                            </li>
                        </ul>
                    </section>
                </template>
            </div>

            <footer class="resident-drawer-footer">
                <button type="button" class="resident-action resident-action-secondary" @click="emit('close')">Close</button>
                <button v-if="allowEdit && !resident.loading && !resident.error" type="button" class="resident-action resident-action-primary" @click="emit('edit', resident)">
                    <MapIcon name="pencil" /> Edit Resident
                </button>
            </footer>
        </aside>
    </div>
</template>
