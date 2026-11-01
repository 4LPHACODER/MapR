import './bootstrap';
import { createApp } from 'vue';
import { createRouter, createWebHistory } from 'vue-router';
import FieldApp from './bdrrm/App.vue';
import DashboardPage from './bdrrm/pages/DashboardPage.vue';
import MapPage from './bdrrm/pages/MapPage.vue';
import QueuePage from './bdrrm/pages/QueuePage.vue';
import ReportPage from './bdrrm/pages/ReportPage.vue';
import RegistryPage from './bdrrm/pages/RegistryPage.vue';
import ProfilePage from './bdrrm/pages/ProfilePage.vue';
import '../css/shared/workspace.css';
import 'mapbox-gl/dist/mapbox-gl.css';
import '@mapbox/mapbox-gl-draw/dist/mapbox-gl-draw.css';

const root = document.getElementById('bdrrm-app');

if (root) {
    const token = document.querySelector('meta[name="csrf-token"]')?.content;
    if (token && window.axios) {
        window.axios.defaults.headers.common['X-CSRF-TOKEN'] = token;
        window.axios.defaults.headers.common['X-Requested-With'] = 'XMLHttpRequest';
    }

    const router = createRouter({
        history: createWebHistory('/bdrrm'),
        routes: [
            { path: '/', component: DashboardPage, meta: { title: 'BDRRM Dashboard' } },
            { path: '/map', component: MapPage, meta: { title: 'Barangay Map' } },
            { path: '/submissions', component: RegistryPage, meta: { title: 'Resident Submissions', source: 'submissions' } },
            { path: '/buildings', component: RegistryPage, meta: { title: 'Building Records', source: 'buildings' } },
            { path: '/reports/new', component: ReportPage, meta: { title: 'Create Barangay Report', kind: 'create' } },
            { path: '/reports/drafts', component: QueuePage, meta: { title: 'Draft Reports', source: 'reports', status: 'draft' } },
            { path: '/reports/returned', component: QueuePage, meta: { title: 'Returned for Correction', source: 'reports', status: 'returned' } },
            { path: '/reports/submitted', component: QueuePage, meta: { title: 'Submitted Reports', source: 'reports', status: 'handed-off' } },
            { path: '/reports/:id', component: ReportPage, meta: { title: 'Barangay Report', kind: 'report' } },
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
        .provide('apiBase', '/bdrrm/api')
        .provide('mapboxToken', root.dataset.mapboxToken || '')
        .provide('mapboxStyle', root.dataset.mapboxStyle || '')
        .provide('floodClasses', JSON.parse(root.dataset.floodClasses || '{}'))
        .mount(root);
}
