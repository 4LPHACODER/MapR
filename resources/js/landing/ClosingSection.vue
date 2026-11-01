<script setup>
defineProps({
    signInUrl: {
        type: String,
        default: '',
    },
    dashboardUrl: {
        type: String,
        default: '',
    },
});
</script>

<template>
    <section class="section" aria-labelledby="closing-title">
        <div class="mapr-wrap">
            <div class="panel" data-reveal>
                <h2 id="closing-title">Bring clarity to your assessment workflow.</h2>
                <a class="mapr-button mapr-button-primary" :href="dashboardUrl || signInUrl">Get Started</a>
            </div>
        </div>
    </section>
</template>

<style scoped>
.section {
    padding: 1rem 0 4rem;
}

.panel {
    display: flex;
    flex-wrap: wrap;
    align-items: center;
    justify-content: space-between;
    gap: 1.25rem;
    border: 1px solid #e2e8f0;
    border-radius: 1.5rem;
    background: #ffffff;
    padding: 1.6rem;
    box-shadow: 0 16px 40px rgba(15, 23, 42, 0.05);
}

h2 {
    margin: 0;
    max-width: 28rem;
    color: #0f172a;
    font-size: clamp(1.75rem, 3vw, 2.5rem);
    font-weight: 700;
    letter-spacing: -0.02em;
    line-height: 1.15;
}
</style>
