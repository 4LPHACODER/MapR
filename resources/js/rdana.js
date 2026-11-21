import './bootstrap';
import { createApp } from 'vue';
import { installPrimeVue } from './shared/ui/installPrimeVue';
import { createRouter, createWebHistory } from 'vue-router';
import FieldApp from './assessor/App.vue';
import DashboardPage from './assessor/pages/DashboardPage.vue';
import MapPage from './assessor/pages/MapPage.vue';
import QueuePage from './assessor/pages/QueuePage.vue';
import BarangayReportDetailPage from './assessor/pages/BarangayReportDetailPage.vue';
import FieldAssessmentDetailPage from './assessor/pages/FieldAssessmentDetailPage.vue';
import RegistryPage from './assessor/pages/RegistryPage.vue';
import ResidentsPage from './assessor/pages/ResidentsPage.vue';
import BuildingsPage from './assessor/pages/BuildingsPage.vue';
import ProfilePage from './assessor/pages/ProfilePage.vue';
import '../css/shared/workspace.css';
import '../css/bdrrm/reports.css';
import '../css/bdrrm/submissions.css';
import 'mapbox-gl/dist/mapbox-gl.css';
import '@mapbox/mapbox-gl-draw/dist/mapbox-gl-draw.css';

const root = document.getElementById('rdana-app');

if (root) {
    const token = document.querySelector('meta[name="csrf-token"]')?.content;
    if (token && window.axios) {
        window.axios.defaults.headers.common['X-CSRF-TOKEN'] = token;
        window.axios.defaults.headers.common['X-Requested-With'] = 'XMLHttpRequest';
    }

    const roleCode = root.dataset.roleCode || '';
    const router = createRouter({
        history: createWebHistory('/rdana'),
        routes: [
            { path: '/', component: DashboardPage, meta: { title: 'Assessor Dashboard', compact: true } },
            { path: '/map', component: MapPage, meta: { title: 'Assessment Map', compact: true } },
            { path: '/buildings', component: BuildingsPage, meta: { title: 'Building Records', compact: true } },
            // Kept for deep links; removed from sidebar navigation.
            { path: '/incidents', component: RegistryPage, meta: { title: 'Disaster Incidents', source: 'incidents' } },
            { path: '/residents', component: ResidentsPage, meta: { title: 'Residents' } },
            { path: '/reports/barangay', component: QueuePage, meta: { title: 'Barangay Reports', source: 'barangay', status: '', compact: true } },
            { path: '/reports/barangay/:id', component: BarangayReportDetailPage, meta: { title: 'Barangay Report' } },
            { path: '/reports/field', component: QueuePage, meta: { title: 'Field Assessment Reports', source: 'field', status: '' } },
            { path: '/reports/drafts', component: QueuePage, meta: { title: 'Drafts', source: 'field', status: 'draft' } },
            { path: '/reports/returned', component: QueuePage, meta: { title: 'Returned for Correction', source: 'field', status: 'returned' } },
            { path: '/reports/submitted', component: QueuePage, meta: { title: 'Submitted Reports', source: 'field', status: 'submitted' } },
            { path: '/assessments/:id/view', component: FieldAssessmentDetailPage, meta: { title: 'Field Assessment', kind: 'assessment' } },
            { path: '/assessments/:id', component: FieldAssessmentDetailPage, meta: { title: 'Field Assessment', kind: 'assessment' } },
            { path: '/profile', component: ProfilePage, meta: { title: 'Profile' } },
        ],
    });

    installPrimeVue(createApp(FieldApp, {
        userName: root.dataset.userName || '',
        userEmail: root.dataset.userEmail || '',
        jobTitle: root.dataset.jobTitle || '',
        logoutUrl: root.dataset.logoutUrl || '/logout',
        homeUrl: root.dataset.homeUrl || '/',
        csrfToken: token || '',
    }))
        .use(router)
        .provide('apiBase', '/rdana/api')
        .provide('roleCode', roleCode)
        .provide('mapboxToken', root.dataset.mapboxToken || '')
        .provide('mapboxStyle', root.dataset.mapboxStyle || '')
        .provide('floodClasses', JSON.parse(root.dataset.floodClasses || '{}'))
        .mount(root);
}
