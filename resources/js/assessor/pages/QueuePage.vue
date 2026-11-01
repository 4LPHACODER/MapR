<script setup>
import { inject, onMounted, ref, watch } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import MapIcon from '../../shared/components/MapIcon.vue';

const apiBase = inject('apiBase');
const route = useRoute();
const router = useRouter();
const rows = ref([]);
const error = ref('');
const loading = ref(true);
const menuId = ref(null);

async function load() {
    loading.value = true;
    error.value = '';
    const path = route.meta.source === 'barangay' ? `${apiBase}/barangay-reports` : `${apiBase}/assessments`;
    try {
        const { data } = await window.axios.get(path, { params: { status: route.meta.status || undefined, per_page: 10 } });
        rows.value = data.data || [];
    } catch (exception) {
        error.value = exception.response?.data?.message || 'The report list could not be loaded.';
    } finally {
        loading.value = false;
    }
}

function openRow(row) {
    menuId.value = null;
    if (route.meta.source === 'barangay') {
        router.push({ path: '/map', query: { incident_id: row.disaster_incident_id || undefined } });
        return;
    }
    router.push(`/assessments/${row.id}`);
}

function previewHref(row) {
    if (route.meta.source === 'barangay') return '';
    return `/rdana/assessments/${row.id}/preview`;
}

onMounted(load);
watch(() => route.fullPath, load);
</script>

<template>
    <section>
        <p v-if="loading">Loading reports…</p>
        <p v-if="error" class="cdrrmo-error">{{ error }}</p>
        <table v-if="!loading" class="cdrrmo-table">
            <thead>
                <tr>
                    <th>Reference</th>
                    <th>Status</th>
                    <th>Updated</th>
                    <th><span class="sr-only">Actions</span></th>
                </tr>
            </thead>
            <tbody>
                <tr v-if="!rows.length"><td colspan="4">No reports in this queue.</td></tr>
                <tr v-for="row in rows" :key="row.id">
                    <td>{{ row.reference }}</td>
                    <td>{{ row.label }}</td>
                    <td>{{ row.updated_at }}</td>
                    <td class="report-row-actions">
                        <button type="button" class="residents-icon-btn" title="View" aria-label="View" @click="openRow(row)"><MapIcon name="eye" /></button>
                        <a v-if="previewHref(row)" class="residents-icon-btn" :href="previewHref(row)" target="_blank" rel="noopener" title="Preview file" aria-label="Preview file"><MapIcon name="file" /></a>
                        <a v-if="previewHref(row)" class="residents-icon-btn" :href="`${previewHref(row)}?format=pdf`" title="Download" aria-label="Download"><MapIcon name="reports" /></a>
                        <button v-if="row.status === 'draft' || row.status === 'returned'" type="button" class="residents-icon-btn" title="Edit" aria-label="Edit" @click="openRow(row)"><MapIcon name="pencil" /></button>
                        <button type="button" class="residents-icon-btn" title="More" :aria-expanded="menuId === row.id" aria-label="More" @click="menuId = menuId === row.id ? null : row.id"><MapIcon name="more" /></button>
                        <div v-if="menuId === row.id" class="report-menu" role="menu">
                            <button type="button" role="menuitem" @click="openRow(row)">Sign</button>
                            <button type="button" role="menuitem" @click="openRow(row)">Flag</button>
                            <button type="button" role="menuitem" @click="openRow(row)">History</button>
                        </div>
                    </td>
                </tr>
            </tbody>
        </table>
    </section>
</template>
