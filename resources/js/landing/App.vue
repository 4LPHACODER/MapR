<script setup>
import { onBeforeUnmount, onMounted, ref } from 'vue';
import SiteHeader from './SiteHeader.vue';
import HeroSection from './HeroSection.vue';
import AboutSection from './AboutSection.vue';
import FeaturesSection from './FeaturesSection.vue';
import WorkflowSection from './WorkflowSection.vue';
import ResponsibleNote from './ResponsibleNote.vue';
import ClosingSection from './ClosingSection.vue';
import SiteFooter from './SiteFooter.vue';

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

const page = ref(null);
let observer;

onMounted(() => {
    const nodes = page.value?.querySelectorAll('[data-reveal]') ?? [];

    if (window.matchMedia('(prefers-reduced-motion: reduce)').matches) {
        nodes.forEach((node) => node.classList.add('is-in'));

        return;
    }

    observer = new IntersectionObserver((entries) => {
        entries.forEach((entry) => {
            if (!entry.isIntersecting) {
                return;
            }

            entry.target.classList.add('is-in');
            observer.unobserve(entry.target);
        });
    }, { threshold: 0.18 });

    nodes.forEach((node) => observer.observe(node));
});

onBeforeUnmount(() => {
    observer?.disconnect();
});
</script>

<template>
    <div id="top" ref="page" class="page">
        <SiteHeader :sign-in-url="signInUrl" :dashboard-url="dashboardUrl" />
        <main id="main">
            <HeroSection :sign-in-url="signInUrl" :dashboard-url="dashboardUrl" />
            <AboutSection />
            <FeaturesSection />
            <WorkflowSection />
            <ResponsibleNote />
            <ClosingSection :sign-in-url="signInUrl" :dashboard-url="dashboardUrl" />
        </main>
        <SiteFooter :sign-in-url="signInUrl" :dashboard-url="dashboardUrl" />
    </div>
</template>

<style scoped>
.page {
    min-height: 100vh;
    background:
        radial-gradient(900px 480px at 82% 12%, rgba(239, 246, 255, 0.95), rgba(239, 246, 255, 0) 68%),
        #ffffff;
    color: #0f172a;
}
</style>
