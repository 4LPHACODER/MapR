import { computed, onBeforeUnmount, reactive, ref, watch } from 'vue';
import { useRoute, useRouter } from 'vue-router';

const filterKeys = ['search', 'disaster_type_id', 'incident_status', 'barangay_id'];
const stringValue = (value) => typeof value === 'string' ? value : '';
function readQuery(query) {
    return {
        search: stringValue(query.search),
        disaster_type_id: /^\d+$/.test(query.disaster_type_id) ? String(query.disaster_type_id) : '',
        incident_status: ['active', 'closed'].includes(query.incident_status) ? query.incident_status : '',
        barangay_id: /^\d+$/.test(query.barangay_id) ? String(query.barangay_id) : '',
        per_page: [10, 25, 50].includes(Number(query.per_page)) ? Number(query.per_page) : 25,
        page: /^\d+$/.test(query.page) ? Math.max(1, Number(query.page)) : 1,
    };
}

export function useIncidentRegistry() {
    const route = useRoute();
    const router = useRouter();
    const filters = reactive(readQuery(route.query));
    const rows = ref([]);
    const meta = ref({ current_page: 1, last_page: 1, total: 0, per_page: 25 });
    const summary = ref(null);
    const summaryLoading = ref(true);
    const summaryError = ref('');
    const loading = ref(true);
    const error = ref('');
    const hasFilters = computed(() => filterKeys.some((key) => filters[key]));
    let ticket = 0;
    let controller;

    function syncQuery() {
        const query = { ...route.query };
        for (const key of [...filterKeys, 'page', 'per_page']) {
            const value = filters[key];
            if (!value || (key === 'page' && value === 1) || (key === 'per_page' && value === 25)) delete query[key];
            else query[key] = String(value);
        }
        return router.replace({ query });
    }
    async function reload() {
        const current = ++ticket;
        controller?.abort();
        controller = new AbortController();
        const signal = controller.signal;
        const params = Object.fromEntries(Object.entries(filters).filter(([, value]) => value !== ''));
        const { incident_status, page, per_page, ...summaryParams } = params;
        loading.value = true;
        error.value = '';
        summaryError.value = '';
        summaryLoading.value = true;
        const summaryRequest = window.axios.get('/cdrrmo/api/incidents/summary', { params: summaryParams, signal })
            .then(({ data }) => { if (current === ticket) summary.value = data; })
            .catch(() => { if (current === ticket) summaryError.value = 'Incident counts and filter options could not be loaded.'; })
            .finally(() => { if (current === ticket) summaryLoading.value = false; });
        const listRequest = window.axios.get('/cdrrmo/api/incidents', { params, signal })
            .then(async ({ data }) => {
                if (current !== ticket) return;
                rows.value = data.data || [];
                meta.value = data.meta || meta.value;
                if (filters.page > meta.value.last_page) {
                    filters.page = Math.max(1, meta.value.last_page);
                    await syncQuery();
                    if (current === ticket) reload();
                }
            })
            .catch(() => { if (current === ticket) error.value = 'Disaster incidents could not be loaded. Try again.'; })
            .finally(() => { if (current === ticket) loading.value = false; });
        await Promise.all([summaryRequest, listRequest]);
    }
    async function updateFilters(patch) {
        Object.assign(filters, patch, { page: 1 });
        const before = JSON.stringify(route.query);
        await syncQuery();
        if (JSON.stringify(route.query) === before) reload();
    }
    async function setPage({ page, rows: perPage }) {
        Object.assign(filters, { page: page + 1, per_page: perPage });
        await syncQuery();
    }
    watch(() => route.query, (query) => {
        Object.assign(filters, readQuery(query));
        reload();
    }, { immediate: true });
    onBeforeUnmount(() => { ticket++; controller?.abort(); });
    return { rows, meta, summary, summaryLoading, summaryError, loading, error, filters, hasFilters, updateFilters, reload, setPage };
}
