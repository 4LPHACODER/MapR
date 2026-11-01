import './bootstrap';
import { createApp } from 'vue';
import App from './landing/App.vue';

document.documentElement.classList.add('js');

const root = document.getElementById('app');

if (root) {
    createApp(App, {
        signInUrl: root.dataset.signInUrl || '',
        dashboardUrl: root.dataset.dashboardUrl || '',
    }).mount(root);
}
