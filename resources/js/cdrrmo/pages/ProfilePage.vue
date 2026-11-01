<script setup>
import { inject } from 'vue';
import SignatureCard from '../../shared/components/SignatureCard.vue';

const userName = inject('cdrrmoUserName', '');
const userEmail = inject('cdrrmoUserEmail', '');
</script>

<template>
    <section>
        <h2 class="cdrrmo-page-title">Profile</h2>
        <p class="cdrrmo-page-lead">Signed-in CDRRMO workspace account (distinct from resident registry records).</p>
        <div class="cdrrmo-panel cdrrmo-profile-card">
            <p><strong>Name:</strong> {{ userName }}</p>
            <p><strong>Email:</strong> {{ userEmail }}</p>
            <p class="cdrrmo-muted">Contact your system administrator to update role or barangay assignment.</p>
        </div>
        <SignatureCard api-base="/cdrrmo/api" />
    </section>
</template>
