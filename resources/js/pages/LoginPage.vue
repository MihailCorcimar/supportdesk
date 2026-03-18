<script setup>
import { reactive, ref } from 'vue';
import { useRouter } from 'vue-router';
import { useAuthStore } from '../stores/auth';

const router = useRouter();
const auth = useAuthStore();

const form = reactive({
    email: '',
    password: '',
    remember: false,
});

const loading = ref(false);
const error = ref('');

const submit = async () => {
    loading.value = true;
    error.value = '';

    try {
        await auth.login(form);
        await router.push({ name: 'tickets.index' });
    } catch (exception) {
        error.value = exception?.response?.data?.message
            || exception?.response?.data?.errors?.email?.[0]
            || 'Nao foi possivel autenticar.';
    } finally {
        loading.value = false;
    }
};
</script>

<template>
    <section class="auth-card">
        <h1>Entrar no Supportdesk</h1>
        <p class="muted">Aplicacao de tickets em Vue + Laravel.</p>

        <p class="error" v-if="error">{{ error }}</p>

        <form @submit.prevent="submit" class="form-grid">
            <label>
                Email
                <input v-model="form.email" type="email" required />
            </label>

            <label>
                Palavra-passe
                <input v-model="form.password" type="password" required />
            </label>

            <label class="remember-row">
                <input v-model="form.remember" type="checkbox" />
                Manter sessao iniciada
            </label>

            <button type="submit" :disabled="loading">
                {{ loading ? 'A entrar...' : 'Entrar' }}
            </button>
        </form>
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

.remember-row {
    display: flex;
    align-items: center;
    gap: 0.45rem;
}

.remember-row input {
    width: auto;
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
</style>
