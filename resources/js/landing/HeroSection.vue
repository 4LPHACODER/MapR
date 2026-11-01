<script setup>
import HeroCarousel from './HeroCarousel.vue';

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
    <section class="hero" aria-labelledby="hero-title">
        <div class="mapr-wrap hero-grid">
            <div class="copy rise">
                <p class="eyebrow">Smart post-disaster assessment platform</p>
                <h1 id="hero-title">
                    Smarter Damage Assessment.
                    <span>Better-Informed Communities.</span>
                </h1>
                <p class="support">
                    MapR brings together AI-assisted building damage classification, interactive geographic mapping, and authorized resident records to support organized post-disaster assessment and field verification.
                </p>
                <div class="actions">
                    <a class="mapr-button mapr-button-primary" :href="dashboardUrl || signInUrl">Get Started</a>
                    <a class="mapr-button mapr-button-secondary" href="#features">Explore Features</a>
                </div>
                <p class="location">Designed to support disaster assessment workflows in Tandag City, Surigao del Sur.</p>
            </div>
            <div class="visual rise rise-delay">
                <div class="accent accent-a" aria-hidden="true"></div>
                <div class="accent accent-b" aria-hidden="true"></div>
                <HeroCarousel />
            </div>
        </div>
    </section>
</template>

<style scoped>
.hero {
    padding: 1.5rem 0 2.5rem;
}

.hero-grid {
    display: grid;
    align-items: center;
    gap: 2.5rem;
    min-width: 0;
}

.hero-grid > * {
    min-width: 0;
}

.eyebrow {
    margin: 0 0 1rem;
    color: #2563eb;
    font-size: 0.82rem;
    font-weight: 700;
    letter-spacing: 0.08em;
    text-transform: uppercase;
}

h1 {
    margin: 0;
    color: #0f172a;
    font-family: inherit;
    font-size: clamp(2.5rem, 4vw, 4rem);
    font-weight: 700;
    letter-spacing: -0.03em;
    line-height: 1.12;
}

h1 span {
    display: block;
    color: #2563eb;
}

.support {
    max-width: 38rem;
    margin: 1rem 0 0;
    color: #64748b;
    font-size: 1.0625rem;
    line-height: 1.6;
}

.actions {
    display: flex;
    flex-wrap: wrap;
    gap: 0.75rem;
    margin-top: 1.6rem;
}

.location {
    margin: 1.15rem 0 0;
    color: #64748b;
    font-size: 0.95rem;
}

.visual {
    position: relative;
    min-width: 0;
    overflow: hidden;
}

.accent {
    position: absolute;
    z-index: 0;
    border-radius: 999px;
    background: #eff6ff;
}

.accent-a {
    top: 8%;
    right: 4%;
    width: 46%;
    height: 28%;
}

.accent-b {
    bottom: 12%;
    left: 0;
    width: 34%;
    height: 22%;
    background: #dbeafe;
}

.visual :deep(.carousel) {
    position: relative;
    z-index: 1;
}

@media (min-width: 1024px) {
    .hero {
        padding: 1.75rem 0 2.75rem;
    }

    .hero-grid {
        grid-template-columns: minmax(0, 1.05fr) minmax(0, 0.95fr);
        gap: 2.25rem;
    }
}
</style>
