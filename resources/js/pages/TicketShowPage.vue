<script setup>
import { computed, onMounted, reactive, ref } from 'vue';
import { useRoute } from 'vue-router';
import api from '../api/client';
import { useAuthStore } from '../stores/auth';

const route = useRoute();
const auth = useAuthStore();

const loading = ref(false);
const error = ref('');
const ticket = ref(null);
const messageBody = ref('');
const isInternal = ref(false);
const savingStatus = ref(false);
const savingAssignment = ref(false);
const sendingMessage = ref(false);

const statusForm = reactive({ status: '' });
const assignmentForm = reactive({ assigned_operator_id: '' });

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

const actionLabels = {
    ticket_created: 'Ticket criado',
    message_added: 'Mensagem adicionada',
    status_updated: 'Estado alterado',
    assignment_updated: 'Atribuicao alterada',
};

const isOperator = computed(() => auth.state.user?.role === 'operator');

const loadTicket = async () => {
    loading.value = true;
    error.value = '';

    try {
        const response = await api.get(`/tickets/${route.params.id}`);
        ticket.value = response.data.data;
        statusForm.status = ticket.value.status;
        assignmentForm.assigned_operator_id = ticket.value.assigned_operator?.id
            ? String(ticket.value.assigned_operator.id)
            : '';
    } catch (exception) {
        error.value = 'Nao foi possivel carregar o ticket.';
    } finally {
        loading.value = false;
    }
};

const updateStatus = async () => {
    savingStatus.value = true;
    try {
        await api.patch(`/tickets/${route.params.id}/status`, { status: statusForm.status });
        await loadTicket();
    } catch (exception) {
        error.value = exception?.response?.data?.message || 'Falha ao atualizar estado.';
    } finally {
        savingStatus.value = false;
    }
};

const updateAssignment = async () => {
    savingAssignment.value = true;
    try {
        await api.patch(`/tickets/${route.params.id}/assignment`, {
            assigned_operator_id: assignmentForm.assigned_operator_id
                ? Number(assignmentForm.assigned_operator_id)
                : null,
        });
        await loadTicket();
    } catch (exception) {
        error.value = exception?.response?.data?.message || 'Falha ao atualizar atribuicao.';
    } finally {
        savingAssignment.value = false;
    }
};

const sendMessage = async () => {
    if (!messageBody.value.trim()) return;

    sendingMessage.value = true;
    try {
        await api.post(`/tickets/${route.params.id}/messages`, {
            body: messageBody.value,
            is_internal: isInternal.value,
        });
        messageBody.value = '';
        isInternal.value = false;
        await loadTicket();
    } catch (exception) {
        error.value = exception?.response?.data?.message || 'Falha ao enviar mensagem.';
    } finally {
        sendingMessage.value = false;
    }
};

const formatDate = (value) => {
    if (!value) return '-';
    return new Date(value).toLocaleString('pt-PT');
};

const messageAuthor = (message) => {
    if (message.author_type === 'user') return message.author_user?.name || 'Operador';
    if (message.author_type === 'contact') return message.author_contact?.name || 'Cliente';
    return 'Sistema';
};

const logActor = (log) => {
    if (log.actor_type === 'user') return log.actor_user?.name || 'Operador';
    if (log.actor_type === 'contact') return log.actor_contact?.name || 'Cliente';
    return 'Sistema';
};

onMounted(loadTicket);
</script>

<template>
    <section class="page" v-if="!loading && ticket">
        <div class="header-row">
            <div>
                <h1>{{ ticket.ticket_number }}</h1>
                <p class="muted">{{ ticket.subject }}</p>
            </div>
            <RouterLink class="btn-secondary" :to="{ name: 'tickets.index' }">Voltar</RouterLink>
        </div>

        <p v-if="error" class="error">{{ error }}</p>

        <article class="card details-grid">
            <div><strong>Inbox:</strong><br>{{ ticket.inbox?.name ?? '-' }}</div>
            <div><strong>Entidade:</strong><br>{{ ticket.entity?.name ?? '-' }}</div>
            <div>
                <strong>Estado:</strong><br>
                <span class="badge" :class="'badge-' + ticket.status">{{ statusLabels[ticket.status] ?? ticket.status }}</span>
            </div>
            <div><strong>Prioridade:</strong><br>{{ priorityLabels[ticket.priority] ?? ticket.priority }}</div>
            <div><strong>Tipo:</strong><br>{{ ticket.type }}</div>
            <div><strong>Operador:</strong><br>{{ ticket.assigned_operator?.name ?? '-' }}</div>
            <div><strong>Criado:</strong><br>{{ formatDate(ticket.created_at) }}</div>
            <div><strong>Ultima atividade:</strong><br>{{ formatDate(ticket.last_activity_at) }}</div>
            <div class="full"><strong>Conhecimento:</strong> {{ ticket.cc_emails?.length ? ticket.cc_emails.join(', ') : '-' }}</div>
        </article>

        <article class="card" v-if="isOperator">
            <h2>Gestao rapida</h2>
            <div class="actions-grid">
                <form @submit.prevent="updateStatus" class="inline-form">
                    <label>
                        Estado
                        <select v-model="statusForm.status">
                            <option v-for="(label, key) in statusLabels" :key="key" :value="key">{{ label }}</option>
                        </select>
                    </label>
                    <button class="btn-primary" type="submit" :disabled="savingStatus">
                        {{ savingStatus ? 'A guardar...' : 'Atualizar estado' }}
                    </button>
                </form>

                <form @submit.prevent="updateAssignment" class="inline-form">
                    <label>
                        Operador
                        <select v-model="assignmentForm.assigned_operator_id">
                            <option value="">Sem atribuicao</option>
                            <option v-for="operator in ticket.operators" :key="operator.id" :value="String(operator.id)">
                                {{ operator.name }}
                            </option>
                        </select>
                    </label>
                    <button class="btn-primary" type="submit" :disabled="savingAssignment">
                        {{ savingAssignment ? 'A guardar...' : 'Atualizar atribuicao' }}
                    </button>
                </form>
            </div>
        </article>

        <div class="columns">
            <div class="left-column">
                <article class="card">
                    <h2>Mensagens</h2>
                    <div class="stack">
                        <article class="timeline-item" v-for="message in ticket.messages" :key="message.id">
                            <div class="timeline-head">
                                <strong>{{ messageAuthor(message) }}</strong>
                                <small>{{ formatDate(message.created_at) }}</small>
                            </div>
                            <span v-if="message.is_internal" class="internal-badge">Nota interna</span>
                            <p>{{ message.body }}</p>
                        </article>

                        <p v-if="!ticket.messages.length" class="muted">Sem mensagens.</p>
                    </div>
                </article>

                <article class="card">
                    <h2>Responder</h2>
                    <form class="stack" @submit.prevent="sendMessage">
                        <label>
                            Mensagem
                            <textarea v-model="messageBody" required />
                        </label>

                        <label v-if="isOperator" class="remember-row">
                            <input type="checkbox" v-model="isInternal" />
                            Guardar como nota interna
                        </label>

                        <button class="btn-primary" type="submit" :disabled="sendingMessage">
                            {{ sendingMessage ? 'A enviar...' : 'Enviar resposta' }}
                        </button>
                    </form>
                </article>
            </div>

            <div class="right-column">
                <article class="card">
                    <h2>Historico</h2>
                    <div class="stack">
                        <article class="timeline-item" v-for="log in ticket.logs" :key="log.id">
                            <div class="timeline-head">
                                <strong>{{ actionLabels[log.action] ?? log.action }}</strong>
                                <small>{{ formatDate(log.created_at) }}</small>
                            </div>
                            <p class="muted">Por: {{ logActor(log) }}</p>
                            <p v-if="log.field">
                                Campo: <strong>{{ log.field }}</strong><br>
                                De: <strong>{{ log.old_value ?? '-' }}</strong><br>
                                Para: <strong>{{ log.new_value ?? '-' }}</strong>
                            </p>
                        </article>
                        <p v-if="!ticket.logs.length" class="muted">Sem registos.</p>
                    </div>
                </article>
            </div>
        </div>
    </section>

    <p v-else-if="loading" class="muted">A carregar ticket...</p>
    <p v-else class="error">{{ error || 'Ticket nao encontrado.' }}</p>
</template>

<style scoped>
.page { display: grid; gap: 1rem; }
.header-row { display: flex; justify-content: space-between; align-items: center; gap: 0.8rem; }
h1 { margin: 0; }
h2 { margin: 0 0 0.5rem; font-size: 1.05rem; }
.muted { color: #475569; margin: 0.25rem 0 0; }
.error { color: #991b1b; }

.card {
    background: #fff;
    border: 1px solid #dbe4ee;
    border-radius: 12px;
    padding: 0.9rem;
}

.details-grid {
    display: grid;
    grid-template-columns: repeat(4, minmax(0, 1fr));
    gap: 0.7rem;
}

.full { grid-column: 1 / -1; }

.columns {
    display: grid;
    grid-template-columns: 2fr 1fr;
    gap: 1rem;
}

.left-column, .right-column { display: grid; gap: 1rem; }

.actions-grid {
    display: grid;
    grid-template-columns: repeat(2, minmax(0, 1fr));
    gap: 0.7rem;
}

.inline-form, .stack { display: grid; gap: 0.55rem; }

label {
    display: grid;
    gap: 0.3rem;
    color: #334155;
}

input, select, textarea {
    border: 1px solid #dbe4ee;
    border-radius: 8px;
    padding: 0.52rem 0.62rem;
    font: inherit;
}

textarea { min-height: 120px; resize: vertical; }

.remember-row {
    display: flex;
    align-items: center;
    gap: 0.45rem;
}

.remember-row input { width: auto; }

.btn-primary, .btn-secondary {
    border: 1px solid #dbe4ee;
    border-radius: 8px;
    padding: 0.45rem 0.75rem;
    text-decoration: none;
    font: inherit;
    cursor: pointer;
}

.btn-primary {
    background: #0f766e;
    color: #fff;
    border-color: #0f766e;
}

.btn-secondary {
    background: #fff;
    color: #0f172a;
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

.timeline-item {
    border: 1px solid #e5edf5;
    border-radius: 10px;
    padding: 0.7rem;
    background: #fff;
}

.timeline-head {
    display: flex;
    justify-content: space-between;
    gap: 0.6rem;
}

.timeline-item p {
    margin: 0.45rem 0 0;
    white-space: pre-wrap;
}

.internal-badge {
    display: inline-flex;
    margin-top: 0.4rem;
    border: 1px solid #e2e8f0;
    border-radius: 999px;
    padding: 0.15rem 0.5rem;
    font-size: 0.74rem;
    background: #f8fafc;
}

@media (max-width: 980px) {
    .details-grid { grid-template-columns: repeat(2, minmax(0, 1fr)); }
    .columns { grid-template-columns: 1fr; }
    .actions-grid { grid-template-columns: 1fr; }
}

@media (max-width: 640px) {
    .details-grid { grid-template-columns: 1fr; }
}
</style>
