<script setup>
import { computed, reactive, ref } from 'vue';
import { RouterLink, useRoute, useRouter } from 'vue-router';
import api from '../api/client';

const route = useRoute();
const router = useRouter();

const token = computed(() => {
    const value = route.query.token;
    return typeof value === 'string' ? value : '';
});

const form = reactive({
    email: typeof route.query.email === 'string' ? route.query.email : '',
    password: '',
    password_confirmation: '',
});

const loading = ref(false);
const error = ref('');
const success = ref('');

const submit = async () => {
    loading.value = true;
    error.value = '';
    success.value = '';

    if (!token.value) {
        error.value = 'Link de recuperação inválido.';
        loading.value = false;
        return;
    }

    try {
        const response = await api.post('/reset-password', {
            token: token.value,
            email: form.email,
            password: form.password,
            password_confirmation: form.password_confirmation,
        });

        success.value = response?.data?.message || 'Palavra-passe atualizada com sucesso.';

        setTimeout(async () => {
            await router.push({ name: 'login' });
        }, 800);
    } catch (exception) {
        error.value = exception?.response?.data?.message
            || exception?.response?.data?.errors?.email?.[0]
            || exception?.response?.data?.errors?.password?.[0]
            || 'Não foi possível redefinir a palavra-passe.';
    } finally {
        loading.value = false;
    }
};
</script>

<template>
    <section class="auth-card">
        <h1>Redefinir palavra-passe</h1>
        <p class="muted">Define uma nova palavra-passe para entrar no Supportdesk.</p>

        <p class="error" v-if="error">{{ error }}</p>
        <p class="success" v-if="success">{{ success }}</p>

        <form @submit.prevent="submit" class="form-grid">
            <label>
                Email
                <input v-model="form.email" type="email" required />
            </label>

            <label>
                Nova palavra-passe
                <input v-model="form.password" type="password" required minlength="8" />
            </label>

            <label>
                Confirmar nova palavra-passe
                <input v-model="form.password_confirmation" type="password" required minlength="8" />
            </label>

            <button type="submit" :disabled="loading">
                {{ loading ? 'A atualizar...' : 'Atualizar palavra-passe' }}
            </button>
        </form>

        <p class="alt-link">
            <RouterLink :to="{ name: 'login' }">Voltar ao login</RouterLink>
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
    border: 1px solid #c8d8ea;
    background: #EDF3FA;
    color: #1F4E79;
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
    border: 1px solid #1F4E79;
    background: #1F4E79;
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
}

.alt-link a {
    color: #1F4E79;
    text-decoration: none;
    font-weight: 600;
}
</style>
