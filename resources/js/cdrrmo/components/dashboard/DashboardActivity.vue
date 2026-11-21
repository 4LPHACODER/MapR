<script setup>
import Button from 'primevue/button';
import MapIcon from '../../../shared/components/MapIcon.vue';
defineProps({ items: { type: Array, default: () => [] }, formatWhen: { type: Function, required: true } });
</script>

<template>
    <section class="cdrrmo-dash-panel cdrrmo-dash-activity" aria-labelledby="cdrrmo-activity-heading">
        <header class="cdrrmo-dash-panel-heading"><div><h3 id="cdrrmo-activity-heading">Recent activity</h3><p>Latest updates across the workspace.</p></div><Button as="router-link" to="/activity" label="View log" size="small" text severity="secondary" /></header>
        <ol v-if="items.length" class="cdrrmo-dash-timeline">
            <li v-for="(item, index) in items" :key="`${item.created_at}-${index}`"><span class="cdrrmo-dash-activity-icon"><MapIcon :name="item.action_type === 'building_location_review' ? 'pin-check' : 'history'" /></span><div><strong>{{ item.actor_name }}</strong><p>{{ item.summary }}</p><time :datetime="item.created_at">{{ formatWhen(item.created_at) }}</time></div></li>
        </ol>
        <div v-else class="cdrrmo-dash-empty"><MapIcon name="history" /><strong>No recent activity</strong><p>Report and location updates will appear here.</p></div>
    </section>
</template>
