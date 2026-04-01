<script setup>
import { computed, onMounted, ref } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import api from '../api/client';

const route = useRoute();
const router = useRouter();

const loading = ref(true);
const error = ref('');
const user = ref(null);

const userId = computed(() => Number(route.params.id));

const roleLabel = computed(() => {
    if (!user.value) return '-';

    if (user.value.role === 'operator') {
        return user.value.is_admin ? 'Operador admin' : 'Operador';
    }

    return 'Cliente';
});

const statusLabel = computed(() => {
    if (!user.value) return '-';
    return user.value.is_active ? 'Ativo' : 'Inativo';
});

const accessSummary = computed(() => {
    if (!user.value) return '-';

    if (user.value.role === 'operator') {
        if (!user.value.inboxes?.length) return 'Sem inboxes';

        return user.value.inboxes
            .map((inbox) => `${inbox.name}${inbox.can_manage_users ? ' (gestão)' : ''}`)
            .join(' | ');
    }

    return user.value.primary_contact?.entity?.name || 'Sem entidade';
});

const formatDate = (value) => {
    if (!value) return '-';
    return new Date(value).toLocaleString('pt-PT');
};

const loadUser = async () => {
    loading.value = true;
    error.value = '';

    try {
        const response = await api.get(`/users/${userId.value}`);
        user.value = response.data.data;
    } catch (exception) {
        error.value = exception?.response?.data?.message || 'Não foi possível carregar detalhes do utilizador.';
    } finally {
        loading.value = false;
    }
};

const goBack = async () => {
    await router.push({ name: 'users.index' });
};

const openTicketsFromUser = async () => {
    if (!user.value) return;

    await router.push({
        name: 'tickets.index',
        query: {
            created_by_user_id: String(user.value.id),
        },
    });
};

onMounted(loadUser);
</script>

<template>
    <section class="page">
        <div class="header-row">
            <button type="button" class="ghost back-btn" @click="goBack">
                &larr; Lista de utilizadores
            </button>
            <button v-if="user" type="button" class="btn-inline" @click="openTicketsFromUser">
                Ver tickets do utilizador
            </button>
        </div>

        <article class="card">
            <p v-if="loading" class="muted">A carregar...</p>
            <p v-else-if="error" class="error">{{ error }}</p>

            <template v-else-if="user">
                <header class="user-header">
                    <h1>{{ user.name }}</h1>
                    <p class="muted">{{ user.email }}</p>
                </header>

                <div class="stats-grid">
                    <div class="stat-card">
                        <span class="label">Perfil</span>
                        <strong>{{ roleLabel }}</strong>
                    </div>
                    <div class="stat-card">
                        <span class="label">Estado</span>
                        <strong>{{ statusLabel }}</strong>
                    </div>
                    <div class="stat-card">
                        <span class="label">Tickets criados</span>
                        <strong>{{ user.tickets_count ?? 0 }}</strong>
                    </div>
                    <div class="stat-card">
                        <span class="label">Tickets atribuídos</span>
                        <strong>{{ user.assigned_tickets_count ?? 0 }}</strong>
                    </div>
                    <div class="stat-card">
                        <span class="label">Contactos</span>
                        <strong>{{ user.contacts_count ?? 0 }}</strong>
                    </div>
                    <div class="stat-card stat-card-full">
                        <span class="label">Acessos</span>
                        <strong>{{ accessSummary }}</strong>
                    </div>
                </div>

                <section class="list-section">
                    <h2>Tickets criados recentemente</h2>
                    <table class="table" v-if="(user.recent_created_tickets || []).length">
                        <thead>
                            <tr>
                                <th>Ticket</th>
                                <th>Assunto</th>
                                <th>Estado</th>
                                <th>Prioridade</th>
                                <th>Inbox</th>
                                <th>Entidade</th>
                                <th>Criado</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-for="ticket in user.recent_created_tickets" :key="ticket.id">
                                <td>
                                    <RouterLink :to="{ name: 'tickets.show', params: { id: ticket.id } }" class="ticket-link">
                                        {{ ticket.ticket_number }}
                                    </RouterLink>
                                </td>
                                <td>{{ ticket.subject }}</td>
                                <td>{{ ticket.status }}</td>
                                <td>{{ ticket.priority }}</td>
                                <td>{{ ticket.inbox?.name || '-' }}</td>
                                <td>{{ ticket.entity?.name || '-' }}</td>
                                <td>{{ formatDate(ticket.created_at) }}</td>
                            </tr>
                        </tbody>
                    </table>
                    <p v-else class="muted">Sem tickets criados por este utilizador.</p>
                </section>
            </template>
        </article>
    </section>
</template>

<style scoped>
.page { display: grid; gap: 1rem; }
.header-row { display: flex; align-items: center; justify-content: space-between; gap: 0.8rem; flex-wrap: wrap; }

.card {
    border: 1px solid #dbe4ee;
    background: #fff;
    border-radius: 12px;
    padding: 0.95rem;
    display: grid;
    gap: 0.9rem;
}

.user-header h1 { margin: 0; }
.user-header p { margin: 0.25rem 0 0; }

.stats-grid {
    display: grid;
    grid-template-columns: repeat(3, minmax(0, 1fr));
    gap: 0.65rem;
}

.stat-card {
    border: 1px solid #e2e8f0;
    border-radius: 10px;
    background: #f8fafc;
    padding: 0.65rem;
    display: grid;
    gap: 0.25rem;
}

.stat-card-full { grid-column: 1 / -1; }
.label { font-size: 0.82rem; color: #64748b; }

.list-section { display: grid; gap: 0.5rem; }
.list-section h2 { margin: 0; font-size: 1.05rem; }

.table { width: 100%; border-collapse: collapse; }
th, td {
    border-bottom: 1px solid #e2e8f0;
    text-align: left;
    padding: 0.55rem 0.45rem;
}

.ticket-link {
    color: #0f766e;
    text-decoration: none;
    font-weight: 600;
}

.ticket-link:hover { text-decoration: underline; }

button {
    border: 1px solid #0f766e;
    background: #0f766e;
    color: #fff;
    border-radius: 8px;
    padding: 0.5rem 0.65rem;
    cursor: pointer;
}

button.ghost {
    border-color: #cbd5e1;
    background: #fff;
    color: #0f172a;
}

.error {
    border: 1px solid #fecaca;
    background: #fef2f2;
    color: #991b1b;
    border-radius: 8px;
    padding: 0.65rem;
}

.muted { color: #475569; }

@media (max-width: 960px) {
    .stats-grid { grid-template-columns: 1fr; }
}
</style>
