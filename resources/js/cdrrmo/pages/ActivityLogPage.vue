<script setup>
import { onMounted, ref } from 'vue';

const rows = ref([]);
const meta = ref({ total: 0, current_page: 1, last_page: 1 });
const loading = ref(true);
const error = ref('');

async function load(page = 1) {
    loading.value = true;
    try {
        const { data } = await window.axios.get('/cdrrmo/api/activity', { params: { page } });
        rows.value = data.data;
        meta.value = data.meta;
    } catch {
        error.value = 'Activity history could not be loaded.';
    } finally {
        loading.value = false;
    }
}

onMounted(() => load());
</script>

<template>
    <section>
        <h2 class="cdrrmo-page-title">Activity Log</h2>
        <p class="cdrrmo-page-lead">Audited workspace actions such as location reviews, incident updates, and account changes.</p>
        <p v-if="loading">Loading…</p>
        <p v-else-if="error" class="cdrrmo-error">{{ error }}</p>
        <template v-else>
            <div class="cdrrmo-table-wrap">
                <table v-if="rows.length" class="cdrrmo-table">
                    <thead>
                        <tr>
                            <th>When</th>
                            <th>Actor</th>
                            <th>Action</th>
                            <th>Summary</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="row in rows" :key="row.id">
                            <td>{{ row.created_at }}</td>
                            <td>{{ row.actor_name }}</td>
                            <td>{{ row.action_type }}</td>
                            <td>{{ row.summary }}</td>
                        </tr>
                    </tbody>
                </table>
                <p v-else class="cdrrmo-muted">No activity events are stored yet. Run migrations for activity_events or perform workspace actions.</p>
            </div>
        </template>
    </section>
</template>
