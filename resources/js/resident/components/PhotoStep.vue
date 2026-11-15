<script setup>
import { computed, ref } from 'vue';
import Button from 'primevue/button';
import Dialog from 'primevue/dialog';
import MapIcon from '../../shared/components/MapIcon.vue';

const props = defineProps({
    photos: { type: Array, default: () => [] },
    pending: { type: Array, default: () => [] },
    progress: { type: Object, default: () => ({}) },
    reportId: { type: [Number, String], default: null },
});
const emit = defineEmits(['add', 'remove-server', 'remove-pending', 'retry']);
const over = ref(false);
const enlargeVisible = ref(false);
const enlarged = ref(null);
const counter = computed(() => {
    const count = props.photos.length;
    if (count === 0) return `${count} of 8 photos · Minimum 2 required`;
    if (count === 1) return `${count} of 8 photos · Add 1 more photo`;
    return `${count} of 8 photos · Minimum requirement met`;
});

function drop(event) {
    over.value = false;
    emit('add', { target: { files: event.dataTransfer?.files, value: '' } }, 'upload');
}

function enlarge(src, name) {
    enlarged.value = { src, name };
    enlargeVisible.value = true;
}
</script>

<template>
    <section>
        <h2 class="resident-step-heading" tabindex="-1">Add incident photos</h2>
        <p class="resident-lead">Add at least 2 clear photos of the affected building.</p>
        <ul class="resident-wizard-guidance">
            <li>One photo showing the overall building.</li>
            <li>One photo showing visible damage from another angle or a closer view.</li>
            <li>Capture only from a safe location.</li>
        </ul>
        <div class="resident-drop" :class="{ 'is-over': over }" @dragover.prevent="over = true" @dragleave="over = false" @drop.prevent="drop">
            <div class="resident-photo-actions">
                <label class="resident-primary">
                    <MapIcon name="camera" /> Take Photo
                    <!-- native-ok: hidden file input preserves the camera capture drop-zone control. -->
                    <input type="file" accept="image/jpeg,image/png,image/webp" capture="environment" @change="emit('add', $event, 'capture')">
                </label>
                <label class="resident-secondary">
                    <MapIcon name="download" /> Upload Photos
                    <!-- native-ok: hidden file input preserves multi-file selection for the custom drop zone. -->
                    <input type="file" accept="image/jpeg,image/png,image/webp" multiple @change="emit('add', $event, 'upload')">
                </label>
            </div>
        </div>
        <p class="resident-wizard-counter">{{ counter }}</p>
        <ul class="resident-photo-list">
            <li v-for="(photo, index) in photos" :key="`saved-${photo.id}`">
                <img :src="`/resident/api/reports/${reportId}/photos/${photo.id}`" :alt="photo.name || 'Incident photo'">
                <span>{{ index + 1 }}. {{ photo.name || 'Photo' }}</span>
                <Button
                    type="button"
                    :label="`Preview ${photo.name || 'photo ' + (index + 1)}`"
                    severity="secondary"
                    variant="outlined"
                    @click="enlarge(`/resident/api/reports/${reportId}/photos/${photo.id}`, photo.name)"
                >Preview</Button>
                <Button
                    type="button"
                    :label="`Remove ${photo.name || 'photo ' + (index + 1)}`"
                    severity="danger"
                    @click="emit('remove-server', photo)"
                >Remove</Button>
            </li>
            <li v-for="(file, index) in pending" :key="file.key">
                <img :src="file.preview" :alt="file.file.name">
                <span>{{ file.file.name }}</span>
                <span v-if="file.uploading">{{ progress[file.key] != null ? `${progress[file.key]}%` : 'Uploading…' }}</span>
                <p v-if="file.error" class="resident-photo-error">{{ file.error }}</p>
                <Button
                    v-if="file.error"
                    type="button"
                    :label="`Retry ${file.file.name}`"
                    severity="secondary"
                    variant="outlined"
                    @click="emit('retry', file.key)"
                >Retry</Button>
                <Button
                    type="button"
                    :label="`Remove ${file.file.name}`"
                    severity="danger"
                    @click="emit('remove-pending', index)"
                >Remove</Button>
            </li>
        </ul>
        <Dialog
            v-model:visible="enlargeVisible"
            class="resident-wizard-dialog"
            aria-label="Photo preview"
            modal
            :draggable="false"
            :closable="false"
            @hide="enlarged = null"
        >
            <template v-if="enlarged">
                <img :src="enlarged.src" :alt="enlarged.name || 'Incident photo'">
                <Button type="button" label="Close" severity="secondary" variant="outlined" class="resident-secondary" @click="enlargeVisible = false" />
            </template>
        </Dialog>
    </section>
</template>
