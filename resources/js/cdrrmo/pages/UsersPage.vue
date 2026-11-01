<script setup>
import { onMounted, ref } from 'vue';

const rows = ref([]);
const loading = ref(true);
const error = ref('');
const message = ref('');

async function load() {
    loading.value = true;
    try {
        const { data } = await window.axios.get('/cdrrmo/api/users');
        rows.value = data.data;
    } catch {
        error.value = 'User accounts could not be loaded.';
    } finally {
        loading.value = false;
    }
}

async function toggleStatus(user) {
    message.value = '';
    const next = user.account_status === 'active' ? 'inactive' : 'active';
    try {
        await window.axios.patch(`/cdrrmo/api/users/${user.id}/status`, { account_status: next });
        user.account_status = next;
        message.value = `Account ${user.email} is now ${next}.`;
    } catch (err) {
        message.value = err.response?.data?.message || 'Status update failed.';
    }
}

onMounted(load);
</script>

<template>
    <section>
        <h2 class="cdrrmo-page-title">User Management</h2>
        <p class="cdrrmo-page-lead">Manage activation and barangay assignment for the four MapR roles. Passwords are never shown.</p>
        <p v-if="message" class="cdrrmo-muted">{{ message }}</p>
        <p v-if="loading">Loading…</p>
        <p v-else-if="error" class="cdrrmo-error">{{ error }}</p>
        <div v-else class="cdrrmo-table-wrap">
            <table class="cdrrmo-table">
                <thead>
                    <tr>
                        <th>Name</th>
                        <th>Email</th>
                        <th>Role</th>
                        <th>Status</th>
                        <th>Barangay</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-for="user in rows" :key="user.id">
                        <td>{{ user.first_name }} {{ user.last_name }}</td>
                        <td>{{ user.email }}</td>
                        <td>{{ user.role?.name || user.role?.code || '—' }}</td>
                        <td>{{ user.account_status }}</td>
                        <td>{{ user.barangay_name || '—' }}</td>
                        <td>
                            <button type="button" class="mapr-button mapr-button-secondary" @click="toggleStatus(user)">
                                {{ user.account_status === 'active' ? 'Deactivate' : 'Activate' }}
                            </button>
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>
    </section>
</template>
