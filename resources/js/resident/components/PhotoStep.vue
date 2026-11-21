<script setup>
import { computed, ref } from 'vue';
import Button from 'primevue/button';
import Dialog from 'primevue/dialog';
import FileUpload from 'primevue/fileupload';
import ProgressBar from 'primevue/progressbar';
import MapIcon from '../../shared/components/MapIcon.vue';

const props = defineProps({
    photos: { type: Array, default: () => [] },
    pending: { type: Array, default: () => [] },
    progress: { type: Object, default: () => ({}) },
    reportId: { type: [Number, String], default: null },
    busy: { type: Boolean, default: false },
});
const emit = defineEmits(['add', 'remove-server', 'remove-pending', 'retry']);
const upload = ref(null);
const camera = ref(null);
const enlargeVisible = ref(false);
const enlarged = ref(null);
const full = computed(() => props.photos.length + props.pending.length >= 8);
const counter = computed(() =>
    props.photos.length < 2
        ? `${props.photos.length} of 8 photos · ${2 - props.photos.length} more required`
        : `${props.photos.length} of 8 photos · Ready for review`,
);

function selectFiles(event, source) {
    emit('add', { target: { files: [...event.files], value: '' } }, source);
    // The report owns upload progress and retries, rather than FileUpload's temporary queue.
    (source === 'capture' ? camera : upload).value?.clear();
}

function enlarge(photo) {
    enlarged.value = { src: `/resident/api/reports/${props.reportId}/photos/${photo.id}`, name: photo.name };
    enlargeVisible.value = true;
}
</script>

<template>
    <section class="report-photo-step">
        <header class="report-step-intro">
            <h3 class="resident-step-heading" tabindex="-1">Photos of the damage</h3>
            <p>Add 2–8 clear photos. Only take photos from a safe location.</p>
        </header>
        <div class="report-photo-guide">
            <div>
                <MapIcon name="home" /><span>Overall view<small>Show the affected home or building.</small></span>
            </div>
            <div>
                <MapIcon name="camera" /><span>Closer view<small>Show visible damage from another angle.</small></span>
            </div>
        </div>
        <FileUpload
            ref="upload"
            :pt="{ root: { class: 'report-upload' } }"
            accept="image/jpeg,image/png,image/webp"
            multiple
            auto
            customUpload
            :disabled="busy || full"
            @uploader="selectFiles($event, 'upload')"
        >
            <template #header="{ chooseCallback }">
                <Button
                    type="button"
                    label="Choose photos"
                    size="small"
                    :disabled="busy || full"
                    @click="chooseCallback"
                    ><template #icon><MapIcon name="download" /></template
                ></Button>
                <FileUpload
                    ref="camera"
                    mode="basic"
                    chooseLabel="Take a photo"
                    accept="image/jpeg,image/png,image/webp"
                    auto
                    customUpload
                    :disabled="busy || full"
                    :chooseButtonProps="{ size: 'small', severity: 'secondary', outlined: true }"
                    :pt="{ input: { capture: 'environment' } }"
                    @uploader="selectFiles($event, 'capture')"
                    ><template #chooseicon><MapIcon name="camera" /></template
                ></FileUpload>
            </template>
            <template #empty
                ><div class="report-upload-empty">
                    <MapIcon name="camera" /><strong>{{
                        full ? 'Photo limit reached' : 'Drop photos here or choose files above'
                    }}</strong
                    ><small>JPEG, PNG, or WebP · Up to 10 MB each</small>
                </div></template
            >
        </FileUpload>
        <p class="resident-wizard-counter" role="status">{{ counter }}</p>
        <ul class="resident-photo-list">
            <li v-for="(photo, index) in photos" :key="`saved-${photo.id}`">
                <img
                    :src="`/resident/api/reports/${reportId}/photos/${photo.id}`"
                    :alt="photo.name || 'Incident photo'"
                />
                <span class="report-photo-name" :title="photo.name">{{ photo.name || `Photo ${index + 1}` }}</span>
                <div class="report-photo-row-actions">
                    <Button
                        type="button"
                        label="Preview"
                        :aria-label="`Preview ${photo.name || 'photo ' + (index + 1)}`"
                        size="small"
                        severity="secondary"
                        text
                        @click="enlarge(photo)"
                    />
                    <Button
                        type="button"
                        label="Remove"
                        :aria-label="`Remove ${photo.name || 'photo ' + (index + 1)}`"
                        size="small"
                        severity="danger"
                        text
                        :disabled="busy"
                        @click="emit('remove-server', photo)"
                    />
                </div>
            </li>
            <li v-for="(file, index) in pending" :key="file.key">
                <img :src="file.preview" :alt="file.file.name" />
                <span class="report-photo-name" :title="file.file.name">{{ file.file.name }}</span>
                <ProgressBar
                    v-if="file.uploading"
                    :value="progress[file.key] || 0"
                    :aria-label="`Uploading ${file.file.name}`"
                />
                <p v-if="file.error" class="resident-photo-error" role="alert">{{ file.error }}</p>
                <div class="report-photo-row-actions">
                    <Button
                        v-if="file.error"
                        type="button"
                        label="Retry"
                        :aria-label="`Retry ${file.file.name}`"
                        size="small"
                        severity="secondary"
                        text
                        :disabled="busy"
                        @click="emit('retry', file.key)"
                    />
                    <Button
                        type="button"
                        label="Remove"
                        :aria-label="`Remove ${file.file.name}`"
                        size="small"
                        severity="danger"
                        text
                        :disabled="file.uploading || busy"
                        @click="emit('remove-pending', index)"
                    />
                </div>
            </li>
        </ul>
        <Dialog
            v-model:visible="enlargeVisible"
            class="resident-wizard-dialog resident-report-dialog"
            header="Photo preview"
            modal
            dismissableMask
            :draggable="false"
            :closeButtonProps="{ size: 'small' }"
            @hide="enlarged = null"
        >
            <img v-if="enlarged" :src="enlarged.src" :alt="enlarged.name || 'Incident photo'" />
            <template #footer
                ><Button
                    type="button"
                    label="Close"
                    size="small"
                    severity="secondary"
                    outlined
                    @click="enlargeVisible = false"
            /></template>
        </Dialog>
    </section>
</template>
