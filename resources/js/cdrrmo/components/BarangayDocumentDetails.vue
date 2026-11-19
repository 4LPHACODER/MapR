<script setup>
import { computed, ref } from 'vue';
import Accordion from 'primevue/accordion';
import AccordionContent from 'primevue/accordioncontent';
import AccordionHeader from 'primevue/accordionheader';
import AccordionPanel from 'primevue/accordionpanel';
import Button from 'primevue/button';
import InputText from 'primevue/inputtext';
import Message from 'primevue/message';
import Tag from 'primevue/tag';
import Textarea from 'primevue/textarea';

const props = defineProps({
    record: { type: Object, required: true },
    title: { type: String, required: true },
    statusTone: { type: String, required: true },
    linked: { type: Array, required: true },
    checklist: { type: Array, required: true },
    error: { type: String, default: '' },
    message: { type: String, default: '' },
});
defineEmits(['add-flag', 'resolve-flag']);
const reason = defineModel('reason', { type: String, required: true });
const resolution = defineModel('resolution', { type: String, required: true });
const expandedChecklist = ref([]);
const completeCount = computed(() => props.checklist.filter(item => item.state === 'complete').length);

const overview = computed(() => [
    ['Type', props.title],
    ['Barangay', props.record.barangay?.name || 'Not recorded'],
    ['Incident', props.record.incident?.name || props.record.incident?.reference || 'Not recorded'],
    ['Author', props.record.assessor?.name || props.record.author || 'Not recorded'],
]);
const indicators = {
    complete: { label: 'Complete', icon: 'pi-check-circle', tone: 'text-emerald-700' },
    pending: { label: 'Pending', icon: 'pi-clock', tone: 'text-indigo-700' },
    locked: { label: 'Restricted', icon: 'pi-lock', tone: 'text-surface-600' },
    attention: { label: 'Needs review', icon: 'pi-exclamation-circle', tone: 'text-amber-800' },
};
</script>

<template>
    <aside id="barangay-document-details" class="barangay-document-details grid min-w-0 gap-3" aria-label="Report details">
        <div class="min-w-0 divide-y divide-surface-200 rounded-lg border border-surface-200 bg-white">
            <section class="px-3.5 py-3" aria-labelledby="barangay-overview-title">
                <h3 id="barangay-overview-title" class="mb-2 text-sm font-semibold">Overview</h3>
                <dl class="barangay-document-metadata grid gap-y-1">
                    <div class="barangay-document-reference mb-1 rounded-md bg-surface-50 px-2.5 py-1.5">
                        <dt>Reference</dt>
                        <dd class="font-semibold text-indigo-800 tabular-nums">{{ record.reference || 'Not recorded' }}</dd>
                    </div>
                    <div v-for="[label, value] in overview" :key="label">
                        <dt>{{ label }}</dt>
                        <dd :class="{ 'text-surface-600': value === 'Not recorded' }">{{ value }}</dd>
                    </div>
                    <div class="mt-1 border-t border-surface-200 pt-2"><dt>Workflow status</dt><dd><Tag :value="record.label" :severity="statusTone" /></dd></div>
                    <div>
                        <dt>Updated</dt>
                        <dd>
                            <span class="font-medium">Version {{ record.version || 1 }}</span>
                            <span class="block text-xs text-surface-600 tabular-nums">{{ record.updated_at || 'Not recorded' }}</span>
                        </dd>
                    </div>
                </dl>
            </section>

            <section class="px-3.5 py-2.5" aria-labelledby="barangay-records-title">
                <div class="mb-1 flex flex-wrap items-center justify-between gap-x-1">
                    <h3 id="barangay-records-title" class="text-sm font-semibold">Linked records</h3>
                    <Button as="router-link" to="/map" label="View on Map" icon="pi pi-map-marker" size="small" severity="secondary" text />
                </div>
                <dl class="barangay-document-metadata grid gap-y-1">
                    <div v-for="[label, value, known] in linked" :key="label">
                        <dt>{{ label }}</dt>
                        <dd class="flex items-start gap-1.5" :class="known ? 'font-medium' : 'text-surface-600'">
                            <i v-if="known" class="pi pi-link shrink-0 pt-1 text-xs text-primary-700" aria-hidden="true"></i>
                            <span><span v-if="known" class="sr-only">Linked </span>{{ value }}</span>
                        </dd>
                    </div>
                </dl>
            </section>
        </div>

        <section class="min-w-0 rounded-lg border border-surface-200 bg-white px-3.5 pt-3 pb-1.5" aria-labelledby="barangay-checklist-title">
            <div class="mb-1 flex flex-wrap items-baseline justify-between gap-x-2">
                <h3 id="barangay-checklist-title" class="text-sm font-semibold">Checklist</h3>
                <span v-if="checklist.length" class="text-xs text-surface-600 tabular-nums">{{ completeCount }} of {{ checklist.length }} complete</span>
            </div>
            <Accordion v-if="checklist.length" v-model:value="expandedChecklist" multiple class="barangay-document-checklist">
                <AccordionPanel v-for="item in checklist" :key="item.key" :value="item.key">
                    <AccordionHeader>
                        <i class="pi shrink-0" :class="[indicators[item.state].icon, indicators[item.state].tone]" aria-hidden="true"></i>
                        <span class="min-w-0 flex-1 text-start">{{ item.label }}</span>
                        <span class="text-xs font-medium" :class="indicators[item.state].tone">{{ indicators[item.state].label }}</span>
                    </AccordionHeader>
                    <AccordionContent :pt="{ transition: { css: false } }">
                        <p>{{ item.detail }}</p>
                    </AccordionContent>
                </AccordionPanel>
            </Accordion>
            <p v-else class="pb-1.5 text-surface-600">No checklist results are recorded.</p>
        </section>

        <section class="min-w-0 rounded-lg border border-surface-200 bg-white px-3.5 py-3" aria-labelledby="barangay-history-title">
            <h3 id="barangay-history-title" class="mb-2 text-sm font-semibold">Workflow and history</h3>
            <ul v-if="record.timeline?.length" class="grid gap-2">
                <li v-for="(event, index) in record.timeline" :key="index" class="min-w-0 wrap-anywhere border-s-2 border-surface-200 ps-2">
                    <span class="block font-medium">{{ event.summary || event.action_type }}</span>
                    <span class="block text-xs text-surface-600">{{ event.created_at }}</span>
                </li>
            </ul>
            <p v-else class="text-surface-600">No workflow history is recorded.</p>

            <div v-for="flag in record.flags || []" :key="flag.id" class="mt-3 min-w-0 wrap-anywhere border-t border-surface-200 pt-3">
                <div class="flex flex-wrap items-center gap-2">
                    <strong class="font-medium">{{ flag.category }}</strong>
                    <Tag :value="flag.status" :severity="flag.status === 'open' ? 'warn' : 'secondary'" />
                </div>
                <p class="mt-1 text-surface-600">{{ flag.reason }}</p>
                <form v-if="flag.status === 'open'" class="mt-2 grid gap-1.5" novalidate @submit.prevent="$emit('resolve-flag', flag)">
                    <label :for="`barangay-flag-resolution-${flag.id}`" class="text-xs font-medium text-surface-600">Resolution</label>
                    <InputText :id="`barangay-flag-resolution-${flag.id}`" v-model="resolution" size="small" fluid required />
                    <Button type="submit" label="Resolve" class="justify-self-start" size="small" severity="secondary" outlined />
                </form>
            </div>

            <Message v-if="error" severity="error" size="small" class="mt-3">{{ error }}</Message>
            <Message v-else-if="message" severity="success" size="small" class="mt-3">{{ message }}</Message>
            <form class="mt-3 grid gap-1.5 border-t border-surface-200 pt-3" novalidate @submit.prevent="$emit('add-flag')">
                <label for="barangay-document-flag-reason" class="text-xs font-medium text-surface-600">Flag reason</label>
                <Textarea id="barangay-document-flag-reason" v-model="reason" class="resize-none" size="small" rows="2" minlength="3" auto-resize fluid required />
                <Button type="submit" label="Flag for review" icon="pi pi-flag" class="justify-self-start" size="small" severity="secondary" outlined />
            </form>
        </section>
    </aside>
</template>
