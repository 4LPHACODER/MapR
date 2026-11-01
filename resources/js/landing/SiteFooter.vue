<script setup>
import { navItems } from './nav';

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

const logoUrl = '/images/mapr-logo.png';
const year = new Date().getFullYear();
</script>

<template>
    <footer class="footer">
        <div class="mapr-wrap footer-grid">
            <div>
                <img :src="logoUrl" alt="MapR" width="288" height="287">
                <p>Building Damage Classification and Geospatial Resident Impact Estimation</p>
                <p>Tandag City, Surigao del Sur</p>
            </div>
            <nav aria-label="Footer">
                <a v-for="item in navItems" :key="item.href" :href="item.href">{{ item.label }}</a>
                <a v-if="dashboardUrl" :href="dashboardUrl">Open Dashboard</a>
                <a v-else :href="signInUrl">Login</a>
            </nav>
        </div>
        <div class="mapr-wrap legal">© {{ year }} MapR</div>
    </footer>
</template>

<style scoped>
.footer {
    border-top: 1px solid #e2e8f0;
    padding: 2.2rem 0 1.4rem;
    background: #ffffff;
}

.footer-grid {
    display: grid;
    gap: 1.5rem;
}

img {
    width: auto;
    height: 4.2rem;
}

p {
    margin: 0.35rem 0 0;
    max-width: 28rem;
    color: #64748b;
    line-height: 1.5;
}

nav {
    display: flex;
    flex-wrap: wrap;
    gap: 0.8rem 1.2rem;
    align-content: start;
}

a {
    color: #0f172a;
    font-weight: 600;
    text-decoration: none;
}

a:hover {
    color: #2563eb;
}

.legal {
    margin-top: 1.5rem;
    color: #64748b;
    font-size: 0.92rem;
}

@media (min-width: 800px) {
    .footer-grid {
        grid-template-columns: 1.4fr 1fr;
    }
}
</style>
