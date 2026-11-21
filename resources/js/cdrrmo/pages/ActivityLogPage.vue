<script setup>
import { computed, onUnmounted, ref, watch } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import Button from 'primevue/button';
import Column from 'primevue/column';
import DataTable from 'primevue/datatable';
import Message from 'primevue/message';
import Paginator from 'primevue/paginator';
import ProgressSpinner from 'primevue/progressspinner';
import Tag from 'primevue/tag';
import MapIcon from '../../shared/components/MapIcon.vue';
import '../../../css/cdrrmo/activity.css';

const route = useRoute();
const router = useRouter();
const pageSize = 10;
const rows = ref([]);
const meta = ref({ total: 0, current_page: 1, last_page: 1 });
const loading = ref(true);
const error = ref(false);
let controller;

const dateFormat = new Intl.DateTimeFormat('en-PH', {
    timeZone: 'Asia/Manila', day: 'numeric', month: 'short', year: 'numeric',
});
const timeFormat = new Intl.DateTimeFormat('en-PH', {
    timeZone: 'Asia/Manila', hour: '2-digit', minute: '2-digit', second: '2-digit', hourCycle: 'h23',
});
const numberFormat = new Intl.NumberFormat('en-PH');
const first = computed(() => (meta.value.current_page - 1) * pageSize);

function present(row) {
    // The API returns SQL timestamps in the application's UTC timezone.
    const date = row.created_at && new Date(`${row.created_at.replace(' ', 'T')}Z`);
    const hasDate = date && Number.isFinite(date.getTime());
    const action = row.action_type.replaceAll('_', ' ');
    return {
        ...row,
        day: hasDate ? dateFormat.format(date) : 'Date unavailable',
        time: hasDate ? timeFormat.format(date) : '—',
        datetime: hasDate ? date.toISOString() : undefined,
        action: action.charAt(0).toUpperCase() + action.slice(1),
    };
}

function pageTo(page, replace = false) {
    const location = { query: { ...route.query, page: page > 1 ? String(page) : undefined } };
    return replace ? router.replace(location) : router.push(location);
}

async function load() {
    controller?.abort();
    controller = new AbortController();
    const { signal } = controller;
    const requested = Number(route.query.page);
    const page = Number.isSafeInteger(requested) && requested > 0 ? requested : 1;
    loading.value = true;
    error.value = false;

    try {
        const { data } = await window.axios.get('/cdrrmo/api/activity', {
            params: { page, per_page: pageSize },
            signal,
        });
        if (signal.aborted) return;
        if (page > data.meta.last_page) {
            const failure = await pageTo(data.meta.last_page, true);
            if (failure && !signal.aborted) error.value = true;
            return;
        }
        rows.value = data.data.map(present);
        meta.value = data.meta;
    } catch {
        if (!signal.aborted) error.value = true;
    } finally {
        if (!signal.aborted) loading.value = false;
    }
}

watch(() => route.query.page, load, { immediate: true });
onUnmounted(() => controller?.abort());
</script>

<template>
    <section class="cdrrmo-activity" aria-labelledby="activity-title">
        <header class="activity-heading">
            <h2 id="activity-title">Activity Log</h2>
            <p>Review recorded changes to reports, incidents, locations, and accounts.</p>
        </header>

        <section class="activity-ledger" aria-labelledby="activity-ledger-title">
            <header class="activity-ledger-header">
                <div>
                    <h3 id="activity-ledger-title">Recorded activity</h3>
                    <p>Newest first · Philippine time (PHT)</p>
                </div>
                <Button label="Refresh" severity="secondary" outlined size="small" :aria-disabled="loading" @click="!loading && load()">
                    <template #icon><MapIcon name="refresh" /></template>
                </Button>
            </header>

            <div v-if="error" class="activity-state">
                <Message severity="error" size="small" :closable="false">Activity history could not be loaded.</Message>
                <p>Use Refresh to try again.</p>
            </div>
            <div v-else-if="loading && !rows.length" class="activity-state" role="status">
                <ProgressSpinner aria-hidden="true" />
                <p>Loading activity…</p>
            </div>
            <div v-else-if="!rows.length" class="activity-state" role="status">
                <span class="activity-empty-icon"><MapIcon name="history" /></span>
                <h4>No activity recorded yet</h4>
                <p>Workspace changes will appear here as they are recorded.</p>
            </div>
            <template v-else>
                <DataTable :value="rows" :loading="loading" :aria-busy="loading" data-key="id" size="small" row-group-mode="subheader" group-rows-by="day" :table-props="{ 'aria-label': 'Workspace activity' }">
                    <template #groupheader="{ data }">
                        <span class="activity-day"><MapIcon name="calendar" />{{ data.day }}</span>
                    </template>
                    <Column field="day" />
                    <Column header="Time" header-class="activity-time-column">
                        <template #body="{ data }">
                            <time v-if="data.datetime" :datetime="data.datetime">{{ data.time }}</time>
                            <span v-else aria-label="Time unavailable">—</span>
                        </template>
                    </Column>
                    <Column header="Activity">
                        <template #body="{ data }">
                            <div class="activity-event">
                                <Tag :value="data.action" severity="info" />
                                <p>{{ data.summary }}</p>
                                <span class="activity-mobile-actor">By {{ data.actor_name }}</span>
                            </div>
                        </template>
                    </Column>
                    <Column field="actor_name" header="Recorded by" header-class="activity-actor-column" body-class="activity-actor-column" />
                </DataTable>

                <footer class="activity-ledger-footer">
                    <span role="status">{{ numberFormat.format(first + 1) }}–{{ numberFormat.format(first + rows.length) }} of {{ numberFormat.format(meta.total) }} {{ meta.total === 1 ? 'event' : 'events' }}</span>
                    <Paginator v-if="meta.last_page > 1" :first="first" :rows="pageSize" :total-records="meta.total" template="PrevPageLink CurrentPageReport NextPageLink" current-page-report-template="Page {currentPage} of {totalPages}" @page="pageTo($event.page + 1)" />
                </footer>
            </template>
        </section>
    </section>
</template>
