<script setup>
import { onMounted, reactive, ref } from 'vue';
import { RouterLink } from 'vue-router';
import api from '../api/client';

const loading = ref(false);
const error = ref('');
const tickets = ref([]);
const meta = ref({ current_page: 1, last_page: 1, total: 0 });
const options = ref({
    inboxes: [],
    entities: [],
    operators: [],
    statuses: [],
    types: [],
});

const filters = reactive({
    search: '',
    inbox_id: '',
    status: '',
    assigned_operator_id: '',
    type: '',
    entity_id: '',
});

const statusLabels = {
    open: 'Aberto',
    pending: 'Pendente',
    resolved: 'Resolvido',
    closed: 'Fechado',
};

const priorityLabels = {
    low: 'Baixa',
    medium: 'Media',
    high: 'Alta',
    urgent: 'Urgente',
};

const loadMeta = async () => {
    const response = await api.get('/meta');
    options.value = {
        inboxes: response.data.data.inboxes,
        entities: response.data.data.entities,
        operators: response.data.data.operators,
        statuses: response.data.data.statuses,
        types: response.data.data.types,
    };
};

const loadTickets = async (page = 1) => {
    loading.value = true;
    error.value = '';

    try {
        const params = { page };
        Object.entries(filters).forEach(([key, value]) => {
            if (value !== '' && value !== null) {
                params[key] = value;
            }
        });

        const response = await api.get('/tickets', { params });
        tickets.value = response.data.data;
        meta.value = response.data.meta;
    } catch (exception) {
        error.value = 'Nao foi possivel carregar tickets.';
    } finally {
        loading.value = false;
    }
};

const applyFilters = () => {
    loadTickets(1);
};

const clearFilters = () => {
    Object.keys(filters).forEach((key) => {
        filters[key] = '';
    });
    loadTickets(1);
};

const formatDate = (value) => {
    if (!value) return '-';
    return new Date(value).toLocaleString('pt-PT');
};

onMounted(async () => {
    await loadMeta();
    await loadTickets(1);
});
</script>

<template>
    <section class="page">
        <div class="header-row">
            <h1>Tickets</h1>
            <RouterLink class="btn-primary" :to="{ name: 'tickets.create' }">Criar Ticket</RouterLink>
        </div>

        <article class="card">
            <form @submit.prevent="applyFilters" class="filters">
                <label>
                    Pesquisa
                    <input v-model="filters.search" placeholder="N ticket, assunto, email, entidade" />
                </label>

                <label>
                    Inbox
                    <select v-model="filters.inbox_id">
                        <option value="">Todas</option>
                        <option v-for="inbox in options.inboxes" :key="inbox.id" :value="String(inbox.id)">
                            {{ inbox.name }}
                        </option>
                    </select>
                </label>

                <label>
                    Estado
                    <select v-model="filters.status">
                        <option value="">Todos</option>
                        <option v-for="status in options.statuses" :key="status" :value="status">
                            {{ statusLabels[status] ?? status }}
                        </option>
                    </select>
                </label>

                <label>
                    Tipo
                    <select v-model="filters.type">
                        <option value="">Todos</option>
                        <option v-for="type in options.types" :key="type" :value="type">
                            {{ type }}
                        </option>
                    </select>
                </label>

                <label>
                    Entidade
                    <select v-model="filters.entity_id">
                        <option value="">Todas</option>
                        <option v-for="entity in options.entities" :key="entity.id" :value="String(entity.id)">
                            {{ entity.name }}
                        </option>
                    </select>
                </label>

                <label>
                    Operador
                    <select v-model="filters.assigned_operator_id">
                        <option value="">Todos</option>
                        <option v-for="operator in options.operators" :key="operator.id" :value="String(operator.id)">
                            {{ operator.name }}
                        </option>
                    </select>
                </label>

                <div class="actions">
                    <button type="submit" class="btn-primary">Aplicar filtros</button>
                    <button type="button" class="btn-secondary" @click="clearFilters">Limpar</button>
                </div>
            </form>
        </article>

        <article class="card">
            <p v-if="error" class="error">{{ error }}</p>
            <p v-if="loading" class="muted">A carregar...</p>

            <table v-if="!loading">
                <thead>
                    <tr>
                        <th>N Ticket</th>
                        <th>Assunto</th>
                        <th>Inbox</th>
                        <th>Entidade</th>
                        <th>Estado</th>
                        <th>Prioridade</th>
                        <th>Operador</th>
                        <th>Atualizado</th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-for="ticket in tickets" :key="ticket.id">
                        <td>
                            <RouterLink :to="{ name: 'tickets.show', params: { id: ticket.id } }">
                                {{ ticket.ticket_number }}
                            </RouterLink>
                        </td>
                        <td>{{ ticket.subject }}</td>
                        <td>{{ ticket.inbox?.name ?? '-' }}</td>
                        <td>{{ ticket.entity?.name ?? '-' }}</td>
                        <td>
                            <span class="badge" :class="'badge-' + ticket.status">
                                {{ statusLabels[ticket.status] ?? ticket.status }}
                            </span>
                        </td>
                        <td>{{ priorityLabels[ticket.priority] ?? ticket.priority }}</td>
                        <td>{{ ticket.assigned_operator?.name ?? '-' }}</td>
                        <td>{{ formatDate(ticket.last_activity_at) }}</td>
                    </tr>
                    <tr v-if="!tickets.length">
                        <td colspan="8" class="muted">Sem tickets para os filtros escolhidos.</td>
                    </tr>
                </tbody>
            </table>

            <div class="pager">
                <button
                    class="btn-secondary"
                    :disabled="meta.current_page <= 1"
                    @click="loadTickets(meta.current_page - 1)"
                >
                    Anterior
                </button>
                <span>Pagina {{ meta.current_page }} de {{ meta.last_page }}</span>
                <button
                    class="btn-secondary"
                    :disabled="meta.current_page >= meta.last_page"
                    @click="loadTickets(meta.current_page + 1)"
                >
                    Seguinte
                </button>
            </div>
        </article>
    </section>
</template>

<style scoped>
.page { display: grid; gap: 1rem; }
.header-row {
    display: flex;
    justify-content: space-between;
    align-items: center;
    gap: 0.8rem;
}
h1 { margin: 0; }
.card {
    background: #fff;
    border: 1px solid #dbe4ee;
    border-radius: 12px;
    padding: 0.9rem;
}

.filters {
    display: grid;
    grid-template-columns: repeat(3, minmax(0, 1fr));
    gap: 0.75rem;
}

label {
    display: grid;
    gap: 0.3rem;
    color: #334155;
}

input, select {
    border: 1px solid #dbe4ee;
    border-radius: 8px;
    padding: 0.52rem 0.62rem;
    font: inherit;
}

.actions {
    grid-column: 1 / -1;
    display: flex;
    gap: 0.5rem;
}

.btn-primary,
.btn-secondary {
    border: 1px solid #dbe4ee;
    border-radius: 8px;
    padding: 0.45rem 0.75rem;
    font: inherit;
    cursor: pointer;
    text-decoration: none;
}

.btn-primary {
    background: #0f766e;
    color: #fff;
    border-color: #0f766e;
}

.btn-secondary {
    background: #fff;
}

table {
    width: 100%;
    border-collapse: collapse;
    font-size: 0.93rem;
}

th, td {
    text-align: left;
    border-bottom: 1px solid #e5edf5;
    padding: 0.58rem 0.45rem;
}

.badge {
    display: inline-flex;
    padding: 0.18rem 0.52rem;
    border-radius: 999px;
    font-size: 0.77rem;
    border: 1px solid transparent;
}

.badge-open { color: #166534; background: #dcfce7; border-color: #bbf7d0; }
.badge-pending { color: #854d0e; background: #fef9c3; border-color: #fde68a; }
.badge-resolved { color: #1d4ed8; background: #dbeafe; border-color: #bfdbfe; }
.badge-closed { color: #7f1d1d; background: #fee2e2; border-color: #fecaca; }

.pager {
    margin-top: 0.8rem;
    display: flex;
    justify-content: flex-end;
    align-items: center;
    gap: 0.55rem;
}

.muted { color: #475569; }
.error { color: #991b1b; }

@media (max-width: 900px) {
    .filters {
        grid-template-columns: 1fr;
    }
}
</style>
