<script setup>
import { inject, ref } from 'vue';
import { useRouter } from 'vue-router';

const router = useRouter();
const reportContext = inject('reportContext');
const open = ref(false);

async function previewSelection() {
    if (!reportContext.state.hasPolygon) {
        return;
    }

    await reportContext.storeReportContext();
    reportContext.openReportPreview();
}
</script>

<template>
    <div>
        <button type="button" class="cdrrmo-nav-toggle" :aria-expanded="open" @click="open = !open">Reports</button>
        <div v-show="open" class="cdrrmo-nav-sub">
            <button type="button" @click="router.push({ path: '/map', query: { mode: 'estimate' } })">Create draft impact report</button>
            <button
                type="button"
                :disabled="!reportContext.state.hasPolygon"
                :class="{ 'is-disabled': !reportContext.state.hasPolygon }"
                @click="previewSelection"
            >
                Preview current selection
            </button>
            <span class="is-disabled">Report history — Coming soon</span>
        </div>
    </div>
</template>
