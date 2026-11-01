import './bootstrap';
import { createApp } from 'vue';
import { createRouter, createWebHistory } from 'vue-router';
import CdrrmoApp from './cdrrmo/App.vue';
import DashboardPage from './cdrrmo/pages/DashboardPage.vue';
import MapWorkspace from './shared/components/MapWorkspace.vue';
import ResidentsPage from './cdrrmo/pages/ResidentsPage.vue';
import IncidentsPage from './cdrrmo/pages/IncidentsPage.vue';
import ReportListPage from './cdrrmo/pages/ReportListPage.vue';
import ReportDocumentPage from './cdrrmo/pages/ReportDocumentPage.vue';
import UsersPage from './cdrrmo/pages/UsersPage.vue';
import ActivityLogPage from './cdrrmo/pages/ActivityLogPage.vue';
import ProfilePage from './cdrrmo/pages/ProfilePage.vue';
import '../css/shared/workspace.css';
import 'mapbox-gl/dist/mapbox-gl.css';
import '@mapbox/mapbox-gl-draw/dist/mapbox-gl-draw.css';

document.documentElement.classList.add('js');

const root = document.getElementById('cdrrmo-app');

if (root) {
    const token = document.querySelector('meta[name="csrf-token"]')?.content;
    if (token && window.axios) {
        window.axios.defaults.headers.common['X-CSRF-TOKEN'] = token;
        window.axios.defaults.headers.common['X-Requested-With'] = 'XMLHttpRequest';
    }

    const router = createRouter({
        history: createWebHistory('/cdrrmo'),
        routes: [
            { path: '/', name: 'dashboard', component: DashboardPage, meta: { title: 'Disaster Assessment Dashboard' } },
            { path: '/map', name: 'map', component: MapWorkspace, meta: { title: 'Assessment Map' } },
            { path: '/residents', name: 'residents', component: ResidentsPage, meta: { title: 'Residents' } },
            { path: '/incidents', name: 'incidents', component: IncidentsPage, meta: { title: 'Disaster Incidents' } },
            { path: '/reports/barangay', name: 'reports-barangay', component: ReportListPage, meta: { title: 'Barangay Reports', reportType: 'barangay' } },
            { path: '/reports/field', name: 'reports-field', component: ReportListPage, meta: { title: 'Field Assessment Reports', reportType: 'field' } },
            { path: '/reports/consolidated', name: 'reports-consolidated', component: ReportListPage, meta: { title: 'Consolidated Reports', reportType: 'consolidated' } },
            { path: '/reports/document/:kind/:id', name: 'reports-document', component: ReportDocumentPage, meta: { title: 'Report' } },
            { path: '/users', name: 'users', component: UsersPage, meta: { title: 'User Management' } },
            { path: '/activity', name: 'activity', component: ActivityLogPage, meta: { title: 'Activity Log' } },
            { path: '/profile', name: 'profile', component: ProfilePage, meta: { title: 'Profile' } },
        ],
    });

    createApp(CdrrmoApp, {
        userName: root.dataset.userName || '',
        userEmail: root.dataset.userEmail || '',
        mapboxToken: root.dataset.mapboxToken || '',
        mapboxStyle: root.dataset.mapboxStyle || '',
        floodClasses: JSON.parse(root.dataset.floodClasses || '{}'),
        logoutUrl: root.dataset.logoutUrl || '/logout',
        homeUrl: root.dataset.homeUrl || '/',
        csrfToken: token || '',
    })
        .use(router)
        .provide('mapboxToken', root.dataset.mapboxToken || '')
        .provide('mapboxStyle', root.dataset.mapboxStyle || '')
        .provide('floodClasses', JSON.parse(root.dataset.floodClasses || '{}'))
        .provide('cdrrmoUserName', root.dataset.userName || '')
        .provide('cdrrmoUserEmail', root.dataset.userEmail || '')
        .mount(root);
}
