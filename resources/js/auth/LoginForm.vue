<script setup>
import { nextTick, onMounted, reactive, ref } from 'vue';
import Button from 'primevue/button';
import Checkbox from 'primevue/checkbox';
import InputGroup from 'primevue/inputgroup';
import InputGroupAddon from 'primevue/inputgroupaddon';
import InputText from 'primevue/inputtext';
import Message from 'primevue/message';

const props = defineProps({
    action: String,
    csrf: String,
    email: String,
    remember: Boolean,
    serverErrors: Object,
});

const form = ref(null);
const errors = reactive({ ...props.serverErrors });
const visible = ref(false);
const submitting = ref(false);

onMounted(() => form.value.querySelector('[aria-invalid="true"]')?.focus());

async function submit(event) {
    if (submitting.value) {
        event.preventDefault();
        return;
    }

    const invalid = [...event.currentTarget.querySelectorAll('input:invalid')];
    for (const input of invalid) {
        errors[input.name] = input.validationMessage;
    }

    if (invalid.length) {
        event.preventDefault();
        await nextTick();
        invalid[0].focus();
        return;
    }

    submitting.value = true;
}
</script>

<template>
    <form ref="form" class="login-form" method="post" :action="action" novalidate @submit="submit">
        <input type="hidden" name="_token" :value="csrf">

        <div class="login-field">
            <label for="email">Email address</label>
            <InputText
                id="email"
                name="email"
                type="email"
                size="small"
                fluid
                required
                maxlength="191"
                autocomplete="username"
                :default-value="email"
                :invalid="Boolean(errors.email)"
                :aria-describedby="errors.email ? 'email-error' : undefined"
                @input="errors.email = ''"
            />
            <Message v-if="errors.email" id="email-error" severity="error" variant="simple" size="small">
                {{ errors.email }}
            </Message>
        </div>

        <div class="login-field">
            <label for="password">Password</label>
            <InputGroup class="login-password">
                <InputText
                    id="password"
                    name="password"
                    :type="visible ? 'text' : 'password'"
                    size="small"
                    required
                    autocomplete="current-password"
                    :invalid="Boolean(errors.password)"
                    :aria-describedby="errors.password ? 'password-error' : undefined"
                    @input="errors.password = ''"
                />
                <InputGroupAddon>
                    <Button
                        type="button"
                        size="small"
                        severity="secondary"
                        variant="text"
                        :icon="visible ? 'pi pi-eye-slash' : 'pi pi-eye'"
                        :aria-label="visible ? 'Hide password' : 'Show password'"
                        aria-controls="password"
                        @click="visible = !visible"
                    />
                </InputGroupAddon>
            </InputGroup>
            <Message v-if="errors.password" id="password-error" severity="error" variant="simple" size="small">
                {{ errors.password }}
            </Message>
        </div>

        <div>
            <div class="login-remember">
                <Checkbox
                    input-id="remember"
                    name="remember"
                    value="1"
                    size="small"
                    binary
                    :default-value="remember"
                    :invalid="Boolean(errors.remember)"
                    :pt="{ input: { 'aria-describedby': errors.remember ? 'remember-error' : undefined } }"
                    @change="errors.remember = ''"
                />
                <label for="remember">Remember me</label>
            </div>
            <Message v-if="errors.remember" id="remember-error" severity="error" variant="simple" size="small">
                {{ errors.remember }}
            </Message>
        </div>

        <Button
            class="login-submit"
            type="submit"
            size="small"
            fluid
            :label="submitting ? 'Signing in…' : 'Sign in'"
            :loading="submitting"
        />
    </form>
</template>
