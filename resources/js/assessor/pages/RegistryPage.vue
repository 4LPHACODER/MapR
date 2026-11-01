<script setup>
import { inject, onMounted, ref } from 'vue';
import { useRoute } from 'vue-router';

const apiBase = inject('apiBase');
const route = useRoute();
const rows = ref([]);
const error = ref('');
const isIncidents = route.meta.source === 'incidents';

function formatWhen(value) {
    if (!value) return '—';
    const date = new Date(value.includes('T') ? value : value.replace(' ', 'T') + 'Z');
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

onMounted(async () => {
    const source = route.meta.source;
    const path = {
        incidents: `${apiBase}/incidents`,
        residents: `${apiBase}/residents`,
        submissions: `${apiBase}/submissions`,
        buildings: `${apiBase}/buildings`,
    }[source];
    try {
        rows.value = (await window.axios.get(path, { params: { per_page: 10 } })).data.data || [];
    } catch (exception) {
        error.value = exception.response?.data?.message || 'These records could not be loaded.';
    }
});
</script>

<template>
    <section>
        <p class="cdrrmo-page-lead">Read only within your assignment. These records are not an official impact estimate.</p>
        <p v-if="error" class="cdrrmo-error">{{ error }}</p>
        <div v-else-if="isIncidents" class="cdrrmo-table-wrap">
            <table v-if="rows.length" class="cdrrmo-table">
                <thead>
                    <tr>
                        <th>Incident</th>
                        <th>Reference</th>
                        <th>Status</th>
                        <th>When</th>
                        <th>Barangay</th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-for="row in rows" :key="row.id">
                        <td>{{ row.incident_name }}</td>
                        <td>{{ row.incident_reference || '—' }}</td>
                        <td>{{ row.incident_status }}</td>
                        <td>{{ formatWhen(row.occurred_at) }}</td>
                        <td>{{ row.barangay_name || '—' }}</td>
                    </tr>
                </tbody>
            </table>
            <p v-else class="cdrrmo-review-empty">No incident is assigned to this account.</p>
        </div>
        <template v-else>
            <ul v-if="rows.length">
                <li v-for="row in rows" :key="row.id">
                    {{ row.incident_name || row.reference || row.registry_reference || row.household_reference || row.id }}
                    <span v-if="row.status"> — {{ row.status }}</span>
                </li>
            </ul>
            <p v-else class="cdrrmo-review-empty">No records are assigned to this account.</p>
        </template>
    </section>
</template>
