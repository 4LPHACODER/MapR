import './bootstrap';
import { createApp } from 'vue';
import { createRouter, createWebHistory } from 'vue-router';
import ResidentApp from './resident/App.vue';
import DashboardPage from './resident/pages/DashboardPage.vue';
import HazardMapPage from './resident/pages/HazardMapPage.vue';
import ReportWizardPage from './resident/pages/ReportWizardPage.vue';
import ReportsPage from './resident/pages/ReportsPage.vue';
import ReportDetailPage from './resident/pages/ReportDetailPage.vue';
import ReportTrackPage from './resident/pages/ReportTrackPage.vue';
import ProfilePage from './resident/pages/ProfilePage.vue';
import '../css/shared/workspace.css';
import '../css/resident/app.css';

const root = document.getElementById('resident-app');

if (root) {
    const token = document.querySelector('meta[name="csrf-token"]')?.content;
    if (token && window.axios) {
        window.axios.defaults.headers.common['X-CSRF-TOKEN'] = token;
        window.axios.defaults.headers.common['X-Requested-With'] = 'XMLHttpRequest';
    }

    const router = createRouter({
        history: createWebHistory('/resident'),
        routes: [
            { path: '/', component: DashboardPage, meta: { title: 'Dashboard' } },
            { path: '/map', component: HazardMapPage, meta: { title: 'Map' } },
            { path: '/report/:id?', component: ReportWizardPage, meta: { title: 'Report', wizard: true } },
            { path: '/reports', component: ReportsPage, meta: { title: 'My Reports' } },
            { path: '/reports/:id', component: ReportDetailPage, meta: { title: 'Report' } },
            { path: '/reports/:id/track', component: ReportTrackPage, meta: { title: 'Status' } },
            { path: '/profile', component: ProfilePage, meta: { title: 'Profile' } },
        ],
    });

    createApp(ResidentApp, {
        userName: root.dataset.userName || '',
        userEmail: root.dataset.userEmail || '',
        jobTitle: root.dataset.jobTitle || 'Resident',
        logoutUrl: root.dataset.logoutUrl || '/logout',
        homeUrl: root.dataset.homeUrl || '/',
        csrfToken: token || '',
    })
        .use(router)
        .provide('apiBase', '/resident/api')
        .provide('mapboxToken', root.dataset.mapboxToken || '')
        .provide('mapboxStyle', root.dataset.mapboxStyle || '')
        .mount(root);
}
