import 'primeicons/primeicons.css';
import { createApp } from 'vue';
import LoginForm from './auth/LoginForm.vue';
import { installPrimeVue } from './shared/ui/installPrimeVue';

const root = document.getElementById('login-app');
const { action, email, remember, emailError, passwordError, rememberError } = root.dataset;

installPrimeVue(createApp(LoginForm, {
    action,
    csrf: document.querySelector('meta[name="csrf-token"]').content,
    email,
    remember: remember === '1',
    serverErrors: { email: emailError, password: passwordError, remember: rememberError },
})).mount(root);
