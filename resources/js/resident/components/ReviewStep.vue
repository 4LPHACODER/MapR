<script setup>
import MapIcon from '../../shared/components/MapIcon.vue';
import SignaturePad from './SignaturePad.vue';

defineProps({
    form: { type: Object, required: true },
    photos: { type: Array, default: () => [] },
    incidentName: { type: String, default: '' },
    incidentType: { type: String, default: '' },
    occurredAt: { type: String, default: '' },
    profile: { type: Object, default: () => ({}) },
    reportId: { type: [Number, String], default: null },
    recorded: { type: Boolean, default: false },
});
defineEmits(['edit', 'signature-ready', 'signature-busy']);
</script>

<template>
    <section class="resident-review">
        <h2 class="resident-step-heading" tabindex="-1">Review your report</h2>
        <p class="resident-lead">Your report will be checked by BDRRM, assessed by the Assessor, and reviewed by CDRRMO.</p>
        <article>
            <header><h3>Incident information</h3><button type="button" @click="$emit('edit', 1)"><MapIcon name="pencil" /> Edit Details</button></header>
            <p>{{ incidentName || 'No incident selected' }}</p>
            <p v-if="incidentType">{{ incidentType }}</p>
            <p v-if="occurredAt">Official date {{ occurredAt }}</p>
            <p v-if="form.observed_at">Observed {{ form.observed_at }}<span v-if="form.observed_at_approximate"> (approximate)</span></p>
        </article>
        <article>
            <header><h3>Location</h3><button type="button" @click="$emit('edit', 1)"><MapIcon name="pencil" /> Edit Details</button></header>
            <p>{{ profile.barangay_name }}</p>
            <p>{{ form.reported_location || 'No location description' }}</p>
            <p v-if="form.latitude != null">Pin confirmed.</p>
            <p v-else>No confirmed pin</p>
        </article>
        <article>
            <header><h3>What happened</h3><button type="button" @click="$emit('edit', 1)"><MapIcon name="pencil" /> Edit Details</button></header>
            <p>{{ form.incident_description || 'No description' }}</p>
        </article>
        <article>
            <header><h3>Attached photos</h3><button type="button" @click="$emit('edit', 2)"><MapIcon name="pencil" /> Edit Photos</button></header>
            <p>{{ photos.length }} photo{{ photos.length === 1 ? '' : 's' }}</p>
            <ul v-if="photos.length" class="resident-photo-list">
                <li v-for="(photo, index) in photos" :key="photo.id">
                    <img :src="`/resident/api/reports/${reportId}/photos/${photo.id}`" :alt="photo.name || 'Incident photo'">
                    <span>{{ index + 1 }}. {{ photo.name || 'Photo' }}</span>
                </li>
            </ul>
        </article>
        <label class="resident-check">
            <input v-model="form.confirmed" type="checkbox"> The location, description, and photographs are accurate.
        </label>
        <SignaturePad :recorded="recorded" @ready="$emit('signature-ready', $event)" @busy="$emit('signature-busy', $event)" />
    </section>
</template>
