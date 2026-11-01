import { reactive, readonly } from 'vue';

const state = reactive({
    hasPolygon: false,
    coordinates: null,
    spatialRule: 'includes_boundary',
    summary: null,
    summaryLoading: false,
});

let summaryTimer;
let summaryRequest = 0;

export function useMapReportContext() {
    function setPolygonContext(coordinates, spatialRule = 'includes_boundary') {
        state.coordinates = coordinates;
        state.spatialRule = spatialRule;
        state.hasPolygon = Array.isArray(coordinates) && coordinates.length >= 4;
        scheduleSummaryRefresh();
    }

    function clearPolygonContext() {
        state.coordinates = null;
        state.hasPolygon = false;
        state.spatialRule = 'includes_boundary';
        state.summary = null;
    }

    function scheduleSummaryRefresh() {
        if (!state.hasPolygon || !window.axios) {
            state.summary = null;
            return;
        }

        clearTimeout(summaryTimer);
        const requestId = ++summaryRequest;
        summaryTimer = setTimeout(async () => {
            state.summaryLoading = true;
            try {
                const { data } = await window.axios.post('/cdrrmo/api/estimates/preview', {
                    coordinates: state.coordinates,
                    spatial_rule: state.spatialRule,
                });
                if (requestId === summaryRequest) {
                    state.summary = data;
                }
            } catch {
                if (requestId === summaryRequest) {
                    state.summary = null;
                }
            } finally {
                if (requestId === summaryRequest) {
                    state.summaryLoading = false;
                }
            }
        }, 350);
    }

    async function storeReportContext() {
        if (!state.hasPolygon || !window.axios) {
            return false;
        }

        const { data } = await window.axios.post('/cdrrmo/api/reports/context', {
            coordinates: state.coordinates,
            spatial_rule: state.spatialRule,
        });

        if (data.summary) {
            state.summary = data.summary;
        }

        return true;
    }

    async function clearAreaContext() {
        if (window.axios) {
            await window.axios.post('/cdrrmo/api/area-context/clear');
        }
        clearPolygonContext();
    }

    function openReportPreview() {
        window.open('/cdrrmo/reports/preview', '_blank', 'noopener');
    }

    function openResidentsWithArea(router) {
        router.push({ path: '/residents', query: { area: '1' } });
    }

    return {
        state: readonly(state),
        setPolygonContext,
        clearPolygonContext,
        storeReportContext,
        clearAreaContext,
        openReportPreview,
        openResidentsWithArea,
        refreshSummary: scheduleSummaryRefresh,
    };
}
