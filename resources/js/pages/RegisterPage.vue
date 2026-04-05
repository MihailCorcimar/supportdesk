<script setup>
import { reactive, ref } from 'vue';
import { RouterLink, useRouter } from 'vue-router';
import { useAuthStore } from '../stores/auth';

const router = useRouter();
const auth = useAuthStore();

const form = reactive({
    name: '',
    email: '',
    password: '',
    password_confirmation: '',
});

const loading = ref(false);
const error = ref('');

const submit = async () => {
    loading.value = true;
    error.value = '';

    try {
        await auth.register(form);
        await router.push({ name: 'tickets.index' });
    } catch (exception) {
        error.value = exception?.response?.data?.message
            || exception?.response?.data?.errors?.email?.[0]
            || exception?.response?.data?.errors?.password?.[0]
            || 'Nao foi possivel criar conta.';
    } finally {
        loading.value = false;
    }
};
</script>

<template>
    <section class="auth-card">
        <h1>Criar conta</h1>
        <p class="muted">Novo utilizador entra como cliente por defeito.</p>

        <p class="error" v-if="error">{{ error }}</p>

        <form @submit.prevent="submit" class="form-grid">
            <label>
                Nome
                <input v-model="form.name" type="text" required maxlength="255" />
            </label>

            <label>
                Email
                <input v-model="form.email" type="email" required />
            </label>

            <label>
                Palavra-passe
                <input v-model="form.password" type="password" required minlength="8" />
            </label>

            <label>
                Confirmar palavra-passe
                <input v-model="form.password_confirmation" type="password" required minlength="8" />
            </label>

            <button type="submit" :disabled="loading">
                {{ loading ? 'A criar conta...' : 'Criar conta' }}
            </button>
        </form>

        <p class="alt-link">
            Ja tens conta?
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
    color: #475569;
}

.alt-link a {
    color: #1F4E79;
    text-decoration: none;
    font-weight: 600;
}
</style>
