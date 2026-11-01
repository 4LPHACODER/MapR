<template>
    <section id="about" class="section" aria-labelledby="about-title">
        <div class="mapr-wrap narrow" data-reveal>
            <p class="kicker">About</p>
            <h2 id="about-title">From field observations to informed decisions.</h2>
            <p>
                MapR organizes resident reports, preliminary damage classifications, field-verified findings, and geographic summaries in one assessment workflow. It helps authorized personnel review information and prepare reports for coordinated disaster response.
            </p>
        </div>
    </section>
</template>

<style scoped>
.section {
    padding: 1rem 0 3rem;
}

.narrow {
    max-width: 46rem;
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
    line-height: 1.15;
}

p:last-child {
    margin: 1rem 0 0;
    color: #64748b;
    font-size: 1.0625rem;
    line-height: 1.6;
}
</style>
