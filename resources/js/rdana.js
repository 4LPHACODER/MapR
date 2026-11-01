import './bootstrap';
import { createApp } from 'vue';
import { createRouter, createWebHistory } from 'vue-router';
import FieldApp from './assessor/App.vue';
import DashboardPage from './assessor/pages/DashboardPage.vue';
import MapPage from './assessor/pages/MapPage.vue';
import QueuePage from './assessor/pages/QueuePage.vue';
import ReportPage from './assessor/pages/ReportPage.vue';
import RegistryPage from './assessor/pages/RegistryPage.vue';
import ResidentsPage from './assessor/pages/ResidentsPage.vue';
import ProfilePage from './assessor/pages/ProfilePage.vue';
import '../css/shared/workspace.css';
import 'mapbox-gl/dist/mapbox-gl.css';
import '@mapbox/mapbox-gl-draw/dist/mapbox-gl-draw.css';

const root = document.getElementById('rdana-app');

if (root) {
    const token = document.querySelector('meta[name="csrf-token"]')?.content;
    if (token && window.axios) {
        window.axios.defaults.headers.common['X-CSRF-TOKEN'] = token;
        window.axios.defaults.headers.common['X-Requested-With'] = 'XMLHttpRequest';
    }

    const router = createRouter({
        history: createWebHistory('/rdana'),
        routes: [
            { path: '/', component: DashboardPage, meta: { title: 'Assessor Dashboard' } },
            { path: '/map', component: MapPage, meta: { title: 'Assessment Map' } },
            { path: '/incidents', component: RegistryPage, meta: { title: 'Disaster Incidents', source: 'incidents' } },
            { path: '/residents', component: ResidentsPage, meta: { title: 'Residents' } },
            { path: '/reports/barangay', component: QueuePage, meta: { title: 'Initial Barangay Reports', source: 'barangay', status: '' } },
            { path: '/reports/field', component: QueuePage, meta: { title: 'Field Assessments', source: 'field', status: '' } },
            { path: '/reports/drafts', component: QueuePage, meta: { title: 'Drafts', source: 'field', status: 'draft' } },
            { path: '/reports/returned', component: QueuePage, meta: { title: 'Returned for Correction', source: 'field', status: 'returned' } },
            { path: '/reports/submitted', component: QueuePage, meta: { title: 'Submitted Reports', source: 'field', status: 'submitted' } },
            { path: '/assessments/:id', component: ReportPage, meta: { title: 'Field Assessment', kind: 'assessment' } },
            { path: '/profile', component: ProfilePage, meta: { title: 'Profile' } },
        ],
    });

    createApp(FieldApp, {
        userName: root.dataset.userName || '',
        userEmail: root.dataset.userEmail || '',
        jobTitle: root.dataset.jobTitle || '',
        logoutUrl: root.dataset.logoutUrl || '/logout',
        homeUrl: root.dataset.homeUrl || '/',
        csrfToken: token || '',
    })
        .use(router)
        .provide('apiBase', '/rdana/api')
        .provide('mapboxToken', root.dataset.mapboxToken || '')
        .provide('mapboxStyle', root.dataset.mapboxStyle || '')
        .provide('floodClasses', JSON.parse(root.dataset.floodClasses || '{}'))
        .mount(root);
}
