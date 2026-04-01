<script setup>
import { reactive, ref } from 'vue';
import { RouterLink } from 'vue-router';
import api from '../api/client';

const form = reactive({
    email: '',
});

const loading = ref(false);
const error = ref('');
const success = ref('');

const submit = async () => {
    loading.value = true;
    error.value = '';
    success.value = '';

    try {
        const response = await api.post('/forgot-password', form);
        success.value = response?.data?.message || 'Se a conta existir, enviamos um link de recuperação.';
    } catch (exception) {
        error.value = exception?.response?.data?.message
            || exception?.response?.data?.errors?.email?.[0]
            || 'Não foi possível enviar o link de recuperação.';
    } finally {
        loading.value = false;
    }
};
</script>

<template>
    <section class="auth-card">
        <h1>Recuperar palavra-passe</h1>
        <p class="muted">Introduz o teu email para receber o link de recuperação.</p>

        <p class="error" v-if="error">{{ error }}</p>
        <p class="success" v-if="success">{{ success }}</p>

        <form @submit.prevent="submit" class="form-grid">
            <label>
                Email
                <input v-model="form.email" type="email" required />
            </label>

            <button type="submit" :disabled="loading">
                {{ loading ? 'A enviar...' : 'Enviar link' }}
            </button>
        </form>

        <p class="alt-link">
            Lembraste da palavra-passe?
            <RouterLink :to="{ name: 'login' }">Entrar</RouterLink>
        </p>
    </section>
</template>

<style scoped>
.auth-card {
    width: min(460px, 100%);
    margin: 4rem auto;
    background: #fff;
    border: 1px solid #dbe4ee;
    border-radius: 14px;
    padding: 1.1rem;
}

h1 {
    margin: 0 0 0.35rem;
}

.muted {
    color: #475569;
}

.error {
    border: 1px solid #fecaca;
    background: #fef2f2;
    color: #991b1b;
    border-radius: 8px;
    padding: 0.6rem;
}

.success {
    border: 1px solid #a7f3d0;
    background: #ecfdf5;
    color: #065f46;
    border-radius: 8px;
    padding: 0.6rem;
}

.form-grid {
    display: grid;
    gap: 0.8rem;
}

label {
    display: grid;
    gap: 0.3rem;
    color: #334155;
    font-size: 0.92rem;
}

input {
    border: 1px solid #dbe4ee;
    border-radius: 8px;
    padding: 0.55rem 0.62rem;
    font: inherit;
}

button {
    border: 1px solid #0f766e;
    background: #0f766e;
    color: #fff;
    border-radius: 8px;
    padding: 0.55rem 0.7rem;
    cursor: pointer;
}

button[disabled] {
    opacity: 0.6;
    cursor: wait;
}

.alt-link {
    margin: 0.9rem 0 0;
    color: #475569;
}

.alt-link a {
    color: #0f766e;
    text-decoration: none;
    font-weight: 600;
}
</style>
