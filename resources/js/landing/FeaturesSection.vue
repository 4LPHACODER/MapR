<script setup>
const features = [
    {
        title: 'Incident Reporting',
        copy: 'Capture building observations and photographs for barangay review.',
        icon: 'camera',
    },
    {
        title: 'AI-Assisted Classification',
        copy: 'Support initial assessment with preliminary classifications of visible building damage.',
        icon: 'spark',
    },
    {
        title: 'Geospatial Assessment',
        copy: 'Select assessment areas and summarize potentially affected households and residents using verified locations and authorized records.',
        icon: 'map',
    },
    {
        title: 'Verified Reports',
        copy: 'Bring reviewed findings together through barangay assessment, field verification, and consolidated reporting.',
        icon: 'check',
    },
];
</script>

<template>
    <section id="features" class="section" aria-labelledby="features-title">
        <div class="mapr-wrap">
            <div class="intro" data-reveal>
                <p class="kicker">Features</p>
                <h2 id="features-title">What the assessment workflow covers</h2>
            </div>
            <div class="grid">
                <article v-for="(feature, index) in features" :key="feature.title" class="card" data-reveal :style="{ '--reveal': index }">
                    <span class="icon" aria-hidden="true">
                        <svg v-if="feature.icon === 'camera'" viewBox="0 0 24 24">
                            <path d="M4 8.5h3l1.4-2h7.2L17 8.5h3v9H4v-9Z" />
                            <circle cx="12" cy="13" r="2.6" />
                        </svg>
                        <svg v-else-if="feature.icon === 'spark'" viewBox="0 0 24 24">
                            <path d="M12 3.5 13.6 9 19 10.5 13.6 12 12 17.5 10.4 12 5 10.5 10.4 9 12 3.5Z" />
                            <path d="M18 15.5 18.7 17.3 20.5 18 18.7 18.7 18 20.5 17.3 18.7 15.5 18 17.3 17.3 18 15.5Z" />
                        </svg>
                        <svg v-else-if="feature.icon === 'map'" viewBox="0 0 24 24">
                            <path d="M9 5.5 4.5 7.2v11.3L9 16.8l6 2.2 4.5-1.7V6l-4.5 1.7L9 5.5Z" />
                            <path d="M9 5.8v11M15 7.7v11" />
                        </svg>
                        <svg v-else viewBox="0 0 24 24">
                            <path d="M7 4.5h8.2L19 8.2V19.5H7V4.5Z" />
                            <path d="M15 4.7V8.4h3.6" />
                            <path d="m9.2 13.2 1.8 1.8 3.8-4" />
                        </svg>
                    </span>
                    <h3>{{ feature.title }}</h3>
                    <p>{{ feature.copy }}</p>
                </article>
            </div>
        </div>
    </section>
</template>

<style scoped>
.section {
    padding: 2rem 0 3rem;
}

.kicker {
    margin: 0 0 0.6rem;
    color: #2563eb;
    font-size: 0.85rem;
    font-weight: 700;
    letter-spacing: 0.06em;
    text-transform: uppercase;
}

h2 {
    margin: 0;
    color: #0f172a;
    font-size: clamp(1.75rem, 3vw, 2.5rem);
    font-weight: 700;
    letter-spacing: -0.02em;
}

.grid {
    display: grid;
    gap: 1rem;
    margin-top: 1.6rem;
}

.card {
    border: 1px solid #e2e8f0;
    border-radius: 1.25rem;
    background: #ffffff;
    padding: 1.25rem;
    box-shadow: 0 10px 30px rgba(15, 23, 42, 0.04);
    transition: transform 180ms ease, box-shadow 180ms ease;
}

.card:hover {
    transform: translateY(-3px);
    box-shadow: 0 16px 34px rgba(37, 99, 235, 0.1);
}

.icon {
    display: grid;
    width: 2.6rem;
    height: 2.6rem;
    place-items: center;
    border-radius: 0.8rem;
    background: #eff6ff;
    color: #2563eb;
}

.icon svg {
    width: 1.35rem;
    height: 1.35rem;
    fill: none;
    stroke: currentColor;
    stroke-width: 1.7;
    stroke-linecap: round;
    stroke-linejoin: round;
}

h3 {
    margin: 0.9rem 0 0;
    color: #0f172a;
    font-size: 1.15rem;
}

p {
    margin: 0.45rem 0 0;
    color: #64748b;
    line-height: 1.6;
}

@media (min-width: 800px) {
    .grid {
        grid-template-columns: 1fr 1fr;
    }
}

@media (prefers-reduced-motion: reduce) {
    .card:hover {
        transform: none;
    }
}
</style>
