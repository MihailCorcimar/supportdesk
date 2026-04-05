<script setup>
import { onMounted, reactive, ref } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import api from '../api/client';

const route = useRoute();
const router = useRouter();

const token = ref('');
const invite = ref(null);
const loading = ref(true);
const saving = ref(false);
const error = ref('');
const success = ref('');

const form = reactive({
    password: '',
    password_confirmation: '',
});

const loadInvite = async () => {
    loading.value = true;
    error.value = '';

    token.value = typeof route.query.token === 'string' ? route.query.token : '';

    if (!token.value) {
        error.value = 'Token de convite em falta.';
        loading.value = false;
        return;
    }

    try {
        const response = await api.get(`/invites/${token.value}`);
        invite.value = response.data.data;
    } catch (exception) {
        error.value = exception?.response?.data?.message || 'Convite invalido ou expirado.';
    } finally {
        loading.value = false;
    }
};

const submit = async () => {
    saving.value = true;
    error.value = '';
    success.value = '';

    try {
        await api.post('/invites/accept', {
            token: token.value,
            password: form.password,
            password_confirmation: form.password_confirmation,
        });

        success.value = 'Password definida com sucesso. Ja podes iniciar sessao.';
        setTimeout(() => {
            router.push({ name: 'login' });
        }, 1200);
    } catch (exception) {
        error.value = exception?.response?.data?.message
            || exception?.response?.data?.errors?.password?.[0]
            || 'Nao foi possivel aceitar o convite.';
    } finally {
        saving.value = false;
    }
};

onMounted(loadInvite);
</script>

<template>
    <section class="auth-card">
        <h1>Aceitar convite</h1>

        <p v-if="loading" class="muted">A carregar convite...</p>

        <template v-else>
            <p v-if="invite" class="muted">
                Conta: <strong>{{ invite.user_email }}</strong>
            </p>

            <p v-if="error" class="error">{{ error }}</p>
            <p v-if="success" class="success">{{ success }}</p>

            <form v-if="invite" @submit.prevent="submit" class="form-grid">
                <label>
                    Nova palavra-passe
                    <input v-model="form.password" type="password" required minlength="8" />
                </label>

                <label>
                    Confirmar palavra-passe
                    <input v-model="form.password_confirmation" type="password" required minlength="8" />
                </label>

                <button type="submit" :disabled="saving">
                    {{ saving ? 'A guardar...' : 'Ativar conta' }}
                </button>
            </form>
        </template>
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
</style>
