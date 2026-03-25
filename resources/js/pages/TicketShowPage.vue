<script setup>
import { computed, nextTick, onMounted, reactive, ref } from 'vue';
import { useRoute } from 'vue-router';
import api from '../api/client';
import { useAuthStore } from '../stores/auth';

const route = useRoute();
const auth = useAuthStore();

const loading = ref(false);
const error = ref('');
const quickActionMessage = ref('');
const activeTopTab = ref('activity_logs');
const ticket = ref(null);
const messageBody = ref('');
const isInternal = ref(false);
const messageFiles = ref([]);
const savingStatus = ref(false);
const savingAssignment = ref(false);
const savingMetadata = ref(false);
const sendingMessage = ref(false);

const statusForm = reactive({ status: '' });
const assignmentForm = reactive({ assigned_operator_id: '' });
const metadataForm = reactive({
    subject: '',
    priority: '',
    type: '',
    inbox_id: '',
    cc_emails: '',
});

const statusLabels = {
    open: 'Aberto',
    in_progress: 'Em tratamento',
    pending: 'Aguardando cliente',
    closed: 'Fechado',
    cancelled: 'Cancelado',
};

const priorityLabels = {
    low: 'Baixa',
    medium: 'Media',
    high: 'Alta',
    urgent: 'Urgente',
};

const typeLabels = {
    question: 'Questao',
    incident: 'Incidente',
    request: 'Pedido',
    task: 'Tarefa',
    other: 'Outro',
};

const actionLabels = {
    ticket_created: 'Ticket criado',
    message_added: 'Mensagem adicionada',
    status_updated: 'Estado atualizado',
    assignment_updated: 'Atribuicao alterada',
    field_updated: 'Campo alterado',
    attachments_added: 'Anexos adicionados',
};

const isOperator = computed(() => auth.state.user?.role === 'operator');
const canUpdateStatus = computed(() => ticket.value?.permissions?.can_update_status);
const canAssign = computed(() => ticket.value?.permissions?.can_assign);
const canUpdateMetadata = computed(() => ticket.value?.permissions?.can_update_metadata);
const canQuickClose = computed(() => canUpdateStatus.value && !['closed', 'cancelled'].includes(ticket.value?.status));
const internalNotes = computed(() => (ticket.value?.messages || []).filter((message) => message.is_internal));

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

        metadataForm.subject = ticket.value.subject || '';
        metadataForm.priority = ticket.value.priority || 'medium';
        metadataForm.type = ticket.value.type || 'request';
        metadataForm.inbox_id = ticket.value.inbox?.id ? String(ticket.value.inbox.id) : '';
        metadataForm.cc_emails = (ticket.value.cc_emails || []).join(', ');
    } catch (exception) {
        error.value = 'Nao foi possivel carregar o ticket.';
    } finally {
        loading.value = false;
    }
};

const updateStatus = async () => {
    savingStatus.value = true;
    error.value = '';

    try {
        await api.patch(`/tickets/${route.params.id}/status`, { status: statusForm.status });
        await loadTicket();
    } catch (exception) {
        error.value = exception?.response?.data?.message || 'Falha ao atualizar estado.';
    } finally {
        savingStatus.value = false;
    }
};

const closeTicketQuick = async () => {
    statusForm.status = 'closed';
    await updateStatus();
};

const scrollToSection = (sectionId) => {
    const element = document.getElementById(sectionId);

    if (element) {
        element.scrollIntoView({ behavior: 'smooth', block: 'start' });
    }
};

const focusComposer = async () => {
    activeTopTab.value = 'conversation';
    await nextTick();
    scrollToSection('composer-section');

    const textarea = document.getElementById('ticket-composer-input');
    if (textarea) {
        textarea.focus();
    }
};

const copyTicketId = async () => {
    if (!ticket.value?.ticket_number) return;

    try {
        await navigator.clipboard.writeText(ticket.value.ticket_number);
        quickActionMessage.value = 'Ticket copiado';
    } catch {
        quickActionMessage.value = 'Não foi possível copiar';
    } finally {
        setTimeout(() => {
            quickActionMessage.value = '';
        }, 1400);
    }
};

const updateAssignment = async () => {
    savingAssignment.value = true;
    error.value = '';

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

const updateMetadata = async () => {
    savingMetadata.value = true;
    error.value = '';

    try {
        await api.patch(`/tickets/${route.params.id}/metadata`, {
            subject: metadataForm.subject,
            priority: metadataForm.priority,
            type: metadataForm.type,
            inbox_id: metadataForm.inbox_id ? Number(metadataForm.inbox_id) : null,
            cc_emails: metadataForm.cc_emails,
        });
        await loadTicket();
    } catch (exception) {
        error.value = exception?.response?.data?.message || 'Falha ao atualizar metadados.';
    } finally {
        savingMetadata.value = false;
    }
};

const handleFilesChange = (event) => {
    const files = event.target.files ? [...event.target.files] : [];
    messageFiles.value = files;
};

const sendMessage = async () => {
    if (!messageBody.value.trim() && messageFiles.value.length === 0) {
        return;
    }

    sendingMessage.value = true;
    error.value = '';

    try {
        const formData = new FormData();

        if (messageBody.value.trim()) {
            formData.append('body', messageBody.value);
        }

        if (isInternal.value) {
            formData.append('is_internal', '1');
        }

        messageFiles.value.forEach((file) => {
            formData.append('attachments[]', file);
        });

        await api.post(`/tickets/${route.params.id}/messages`, formData, {
            headers: {
                'Content-Type': 'multipart/form-data',
            },
        });

        messageBody.value = '';
        isInternal.value = false;
        messageFiles.value = [];

        const input = document.getElementById('message-attachments');
        if (input) input.value = '';

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

const formatSize = (value) => {
    if (!value) return '0 KB';

    const units = ['B', 'KB', 'MB', 'GB'];
    let size = Number(value);
    let unit = 0;

    while (size >= 1024 && unit < units.length - 1) {
        size /= 1024;
        unit += 1;
    }

    return `${size.toFixed(size >= 10 || unit === 0 ? 0 : 1)} ${units[unit]}`;
};

const messageAuthor = (message) => {
    if (message.author_type === 'user') return message.author_user?.name || 'Operador';
    if (message.author_type === 'contact') return message.author_contact?.name || 'Cliente';
    return 'Sistema';
};

const messageAuthorInitial = (message) => {
    const name = messageAuthor(message);
    return name ? name.charAt(0).toUpperCase() : 'S';
};

const logActor = (log) => {
    if (log.actor_type === 'user') return log.actor_user?.name || 'Operador';
    if (log.actor_type === 'contact') return log.actor_contact?.name || 'Cliente';
    return 'Sistema';
};

const activityIconClass = (action) => {
    if (action === 'ticket_created') return 'activity-created';
    if (action === 'status_updated') return 'activity-status';
    if (action === 'assignment_updated') return 'activity-assignment';
    if (action === 'field_updated') return 'activity-field';
    return 'activity-default';
};

onMounted(loadTicket);
</script>

<template>
    <section v-if="!loading && ticket" class="ticket-workspace">
        <header class="ticket-header">
            <div class="header-left">
                <RouterLink class="back-link" :to="{ name: 'tickets.index' }">Ticket list</RouterLink>
                <p class="ticket-title">
                    <strong>{{ ticket.ticket_number }}</strong>
                    <span>{{ ticket.subject }}</span>
                </p>
            </div>

            <div class="header-actions">
                <button
                    v-if="isOperator && canUpdateStatus"
                    class="btn-success"
                    type="button"
                    :disabled="savingStatus"
                    @click="closeTicketQuick"
                >
                    {{ savingStatus ? 'A fechar...' : 'Submeter como fechado' }}
                </button>

                <RouterLink
                    v-if="ticket.permissions?.can_update"
                    class="btn-ghost"
                    :to="{ name: 'tickets.edit', params: { id: ticket.id } }"
                >
                    Editar completo
                </RouterLink>
            </div>
        </header>

        <p v-if="error" class="error-banner">{{ error }}</p>

        <div class="workspace-grid">
            <article id="conversation-section" class="conversation-card">
                <div class="conversation-tabs">
                    <button
                        type="button"
                        :class="['tab', { active: activeTopTab === 'conversation' }]"
                        @click="activeTopTab = 'conversation'"
                    >
                        Conversation
                    </button>
                    <button
                        type="button"
                        :class="['tab', { active: activeTopTab === 'task' }]"
                        @click="activeTopTab = 'task'"
                    >
                        Task
                    </button>
                    <button
                        type="button"
                        :class="['tab', { active: activeTopTab === 'activity_logs' }]"
                        @click="activeTopTab = 'activity_logs'"
                    >
                        Activity Logs
                    </button>
                    <button
                        type="button"
                        :class="['tab', { active: activeTopTab === 'notes' }]"
                        @click="activeTopTab = 'notes'"
                    >
                        Notes
                    </button>
                </div>

                <div v-if="activeTopTab === 'conversation'" class="conversation-tab-content">
                    <div class="message-stream">
                        <article class="message-row" v-for="message in ticket.messages" :key="message.id">
                            <div class="avatar">{{ messageAuthorInitial(message) }}</div>

                            <div class="bubble" :class="{ internal: message.is_internal }">
                                <div class="bubble-head">
                                    <strong>{{ messageAuthor(message) }}</strong>
                                    <small>{{ formatDate(message.created_at) }}</small>
                                </div>

                                <p v-if="message.body">{{ message.body }}</p>

                                <span v-if="message.is_internal" class="internal-tag">Nota interna</span>

                                <ul v-if="message.attachments?.length" class="attachment-list">
                                    <li v-for="attachment in message.attachments" :key="attachment.uuid">
                                        <a :href="attachment.download_url" target="_blank" rel="noopener noreferrer">
                                            {{ attachment.original_name }}
                                        </a>
                                        <small>({{ formatSize(attachment.size) }})</small>
                                    </li>
                                </ul>
                            </div>
                        </article>

                        <p v-if="!ticket.messages.length" class="empty-row">Sem mensagens neste ticket.</p>
                    </div>

                    <form
                        v-if="ticket.permissions?.can_reply"
                        id="composer-section"
                        class="composer"
                        @submit.prevent="sendMessage"
                    >
                        <textarea
                            id="ticket-composer-input"
                            v-model="messageBody"
                            placeholder="Comentar ou escrever / para comandos"
                        />

                        <div class="composer-tools">
                            <label class="upload-label">
                                Anexos
                                <input id="message-attachments" type="file" multiple @change="handleFilesChange">
                            </label>

                            <label v-if="isOperator" class="internal-check">
                                <input v-model="isInternal" type="checkbox">
                                Mensagem interna
                            </label>

                            <button class="btn-send" type="submit" :disabled="sendingMessage">
                                {{ sendingMessage ? 'A enviar...' : 'Enviar' }}
                            </button>
                        </div>

                        <ul v-if="messageFiles.length" class="attachment-list staged-list">
                            <li v-for="file in messageFiles" :key="file.name + file.size">
                                {{ file.name }} <small>({{ formatSize(file.size) }})</small>
                            </li>
                        </ul>
                    </form>
                </div>

                <div v-else-if="activeTopTab === 'activity_logs'" class="activity-stream">
                    <article class="activity-item" v-for="log in ticket.logs" :key="log.id">
                        <span class="activity-dot" :class="activityIconClass(log.action)"></span>
                        <div class="activity-body">
                            <p>
                                <strong>{{ actionLabels[log.action] ?? log.action }}</strong>
                                <span> · {{ logActor(log) }} · {{ formatDate(log.created_at) }}</span>
                            </p>
                            <p v-if="log.field" class="activity-field">
                                {{ log.field }}: {{ log.old_value ?? '-' }} -> {{ log.new_value ?? '-' }}
                            </p>
                        </div>
                    </article>
                    <p v-if="!ticket.logs.length" class="empty-row">Sem atividade registada.</p>
                </div>

                <div v-else-if="activeTopTab === 'task'" class="task-content">
                    <h3>Tarefa operacional</h3>
                    <p>Gerir estado, atribuição e metadados no painel lateral.</p>
                    <button v-if="isOperator" type="button" class="btn-ghost" @click="scrollToSection('management-section')">
                        Ir para gestão operacional
                    </button>
                </div>

                <div v-else class="notes-content">
                    <h3>Notas internas</h3>
                    <article class="note-item" v-for="message in internalNotes" :key="`note-${message.id}`">
                        <div class="note-head">
                            <strong>{{ messageAuthor(message) }}</strong>
                            <small>{{ formatDate(message.created_at) }}</small>
                        </div>
                        <p>{{ message.body || 'Sem texto.' }}</p>
                    </article>
                    <p v-if="!internalNotes.length" class="empty-row">Sem notas internas.</p>
                </div>
            </article>

            <aside class="context-panel">
                <article id="summary-section" class="panel-card">
                    <h2>Resumo</h2>
                    <div class="kv-grid">
                        <div><span>Inbox</span><strong>{{ ticket.inbox?.name ?? '-' }}</strong></div>
                        <div><span>Entidade</span><strong>{{ ticket.entity?.name ?? '-' }}</strong></div>
                        <div><span>Estado</span><strong><span class="badge" :class="`badge-${ticket.status}`">{{ statusLabels[ticket.status] ?? ticket.status }}</span></strong></div>
                        <div><span>Prioridade</span><strong>{{ priorityLabels[ticket.priority] ?? ticket.priority }}</strong></div>
                        <div><span>Tipo</span><strong>{{ typeLabels[ticket.type] ?? ticket.type }}</strong></div>
                        <div><span>Operador</span><strong>{{ ticket.assigned_operator?.name ?? 'Sem atribuicao' }}</strong></div>
                        <div><span>Criado</span><strong>{{ formatDate(ticket.created_at) }}</strong></div>
                        <div><span>Ultima atividade</span><strong>{{ formatDate(ticket.last_activity_at) }}</strong></div>
                        <div class="full-row"><span>Conhecimento</span><strong>{{ ticket.cc_emails?.length ? ticket.cc_emails.join(', ') : '-' }}</strong></div>
                    </div>
                </article>

                <article id="management-section" class="panel-card" v-if="isOperator">
                    <h2>Gestao operacional</h2>

                    <form class="stack" @submit.prevent="updateStatus" v-if="canUpdateStatus">
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

                    <form class="stack" @submit.prevent="updateAssignment" v-if="canAssign">
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

                    <form class="stack" @submit.prevent="updateMetadata" v-if="canUpdateMetadata">
                        <label>
                            Assunto
                            <input v-model="metadataForm.subject" maxlength="255" required>
                        </label>

                        <label>
                            Prioridade
                            <select v-model="metadataForm.priority">
                                <option v-for="(label, key) in priorityLabels" :key="key" :value="key">{{ label }}</option>
                            </select>
                        </label>

                        <label>
                            Tipo
                            <select v-model="metadataForm.type">
                                <option v-for="(label, key) in typeLabels" :key="key" :value="key">{{ label }}</option>
                            </select>
                        </label>

                        <label>
                            Inbox
                            <select v-model="metadataForm.inbox_id">
                                <option v-for="inbox in ticket.available_inboxes" :key="inbox.id" :value="String(inbox.id)">
                                    {{ inbox.name }}
                                </option>
                            </select>
                        </label>

                        <label>
                            Conhecimento (emails separados por virgula)
                            <input v-model="metadataForm.cc_emails" placeholder="exemplo@dominio.pt, outro@dominio.pt">
                        </label>

                        <button class="btn-primary" type="submit" :disabled="savingMetadata">
                            {{ savingMetadata ? 'A guardar...' : 'Atualizar metadados' }}
                        </button>
                    </form>
                </article>

                <article id="history-section" class="panel-card">
                    <h2>Historico</h2>

                    <div class="log-list">
                        <article class="log-item" v-for="log in ticket.logs" :key="log.id">
                            <div class="log-head">
                                <strong>{{ actionLabels[log.action] ?? log.action }}</strong>
                                <small>{{ formatDate(log.created_at) }}</small>
                            </div>
                            <p>Por: {{ logActor(log) }}</p>
                            <p v-if="log.field">
                                Campo {{ log.field }}: {{ log.old_value ?? '-' }} -> {{ log.new_value ?? '-' }}
                            </p>
                        </article>

                        <p v-if="!ticket.logs.length" class="empty-row">Sem registos.</p>
                    </div>
                </article>
            </aside>
        </div>

        <nav class="quick-actions" aria-label="Ações rápidas">
            <button
                type="button"
                title="Ir para conversa"
                @click="activeTopTab = 'conversation'; scrollToSection('conversation-section')"
            >
                <svg viewBox="0 0 24 24" fill="none" aria-hidden="true">
                    <path d="M4 6a2 2 0 0 1 2-2h12a2 2 0 0 1 2 2v8a2 2 0 0 1-2 2H9l-5 4V6Z" stroke="currentColor" stroke-width="1.8" />
                </svg>
            </button>
            <button type="button" title="Responder" @click="focusComposer">
                <svg viewBox="0 0 24 24" fill="none" aria-hidden="true">
                    <path d="M8 12h8M8 8h8M8 16h5M5 4h14a2 2 0 0 1 2 2v12l-4-3H5a2 2 0 0 1-2-2V6a2 2 0 0 1 2-2Z" stroke="currentColor" stroke-width="1.8" />
                </svg>
            </button>
            <button type="button" title="Resumo" @click="scrollToSection('summary-section')">
                <svg viewBox="0 0 24 24" fill="none" aria-hidden="true">
                    <rect x="4" y="4" width="16" height="16" rx="3" stroke="currentColor" stroke-width="1.8" />
                    <path d="M8 9h8M8 13h8M8 17h5" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" />
                </svg>
            </button>
            <button
                v-if="isOperator"
                type="button"
                title="Gestão operacional"
                @click="scrollToSection('management-section')"
            >
                <svg viewBox="0 0 24 24" fill="none" aria-hidden="true">
                    <circle cx="12" cy="12" r="3" stroke="currentColor" stroke-width="1.8" />
                    <path d="M12 4.5v2.2M12 17.3v2.2M4.5 12h2.2M17.3 12h2.2M6.8 6.8l1.6 1.6M15.6 15.6l1.6 1.6M17.2 6.8l-1.6 1.6M8.4 15.6l-1.6 1.6" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" />
                </svg>
            </button>
            <button type="button" title="Activity logs" @click="activeTopTab = 'activity_logs'">
                <svg viewBox="0 0 24 24" fill="none" aria-hidden="true">
                    <path d="M4 12a8 8 0 1 0 2.3-5.6M4 5.5v3.8h3.8M12 8v4l2.8 2.4" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" />
                </svg>
            </button>
            <button type="button" title="Copiar número do ticket" @click="copyTicketId">
                <svg viewBox="0 0 24 24" fill="none" aria-hidden="true">
                    <rect x="9" y="9" width="10" height="10" rx="2" stroke="currentColor" stroke-width="1.8" />
                    <rect x="5" y="5" width="10" height="10" rx="2" stroke="currentColor" stroke-width="1.8" />
                </svg>
            </button>
            <button
                v-if="isOperator && canQuickClose"
                type="button"
                title="Fechar ticket"
                @click="closeTicketQuick"
            >
                <svg viewBox="0 0 24 24" fill="none" aria-hidden="true">
                    <path d="M7 12.5l3.2 3.2L17.5 8.5" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" />
                    <circle cx="12" cy="12" r="9" stroke="currentColor" stroke-width="1.8" />
                </svg>
            </button>
        </nav>

        <p v-if="quickActionMessage" class="quick-message">{{ quickActionMessage }}</p>
    </section>

    <p v-else-if="loading" class="loading-text">A carregar ticket...</p>
    <p v-else class="error-banner">{{ error || 'Ticket nao encontrado.' }}</p>
</template>

<style scoped>
.ticket-workspace {
    display: grid;
    gap: 0.9rem;
}

.ticket-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    gap: 0.8rem;
    background: #fff;
    border: 1px solid #d8e1ed;
    border-radius: 14px;
    padding: 0.75rem 0.85rem;
}

.header-left {
    display: grid;
    gap: 0.35rem;
}

.back-link {
    font-size: 0.82rem;
    color: #4b5563;
    text-decoration: none;
}

.ticket-title {
    margin: 0;
    display: flex;
    gap: 0.65rem;
    align-items: center;
    flex-wrap: wrap;
}

.ticket-title strong {
    font-size: 1.05rem;
}

.header-actions {
    display: flex;
    align-items: center;
    gap: 0.45rem;
    flex-wrap: wrap;
}

.btn-success,
.btn-ghost,
.btn-primary,
.btn-send {
    border-radius: 9px;
    border: 1px solid #d4dde8;
    font: inherit;
    cursor: pointer;
    text-decoration: none;
}

.btn-success {
    background: #1fb873;
    border-color: #1fb873;
    color: #fff;
    padding: 0.48rem 0.78rem;
}

.btn-ghost {
    background: #fff;
    color: #0f172a;
    padding: 0.47rem 0.73rem;
}

.btn-primary {
    background: #0f766e;
    color: #fff;
    border-color: #0f766e;
    padding: 0.5rem 0.7rem;
}

.error-banner {
    margin: 0;
    border: 1px solid #fecaca;
    border-radius: 10px;
    background: #fef2f2;
    color: #991b1b;
    padding: 0.6rem 0.7rem;
}

.loading-text {
    color: #334155;
}

.quick-actions {
    position: fixed;
    right: 1.7rem;
    top: 50%;
    transform: translateY(-50%);
    display: grid;
    gap: 0.45rem;
    z-index: 65;
}

.quick-actions button {
    width: 40px;
    height: 40px;
    border-radius: 999px;
    border: 1px solid #d9e2ee;
    background: #f8fafc;
    color: #64748b;
    display: grid;
    place-items: center;
    cursor: pointer;
    transition: background-color 120ms ease, color 120ms ease, border-color 120ms ease;
}

.quick-actions button:hover {
    background: #e8fbf2;
    color: #0f766e;
    border-color: #9fd9c2;
}

.quick-actions svg {
    width: 18px;
    height: 18px;
}

.quick-message {
    position: fixed;
    right: 1.6rem;
    top: calc(50% + 190px);
    margin: 0;
    z-index: 66;
    border: 1px solid #9fd9c2;
    background: #ecfdf5;
    color: #0d704e;
    border-radius: 8px;
    padding: 0.34rem 0.5rem;
    font-size: 0.82rem;
}

.workspace-grid {
    display: grid;
    grid-template-columns: minmax(0, 1.8fr) minmax(340px, 1fr);
    gap: 0.9rem;
}

.conversation-card,
.panel-card {
    border: 1px solid #d8e1ed;
    border-radius: 14px;
    background: #fff;
}

.conversation-card {
    display: grid;
    grid-template-rows: auto minmax(380px, 1fr);
    overflow: hidden;
}

.conversation-tabs {
    display: flex;
    gap: 1rem;
    border-bottom: 1px solid #e7edf6;
    padding: 0.4rem 0.9rem 0;
    background: #fcfdff;
}

.tab {
    border: none;
    background: transparent;
    color: #64748b;
    font: inherit;
    font-size: 0.9rem;
    padding: 0.55rem 0.15rem 0.5rem;
    border-bottom: 3px solid transparent;
    cursor: pointer;
}

.tab.active {
    color: #0f766e;
    border-bottom-color: #0f9f73;
    font-weight: 600;
}

.tab:hover {
    color: #0f172a;
}

.conversation-tab-content {
    display: grid;
    grid-template-rows: minmax(320px, 1fr) auto;
    min-height: 100%;
}

.message-stream {
    padding: 0.9rem;
    display: grid;
    gap: 0.7rem;
    align-content: start;
    max-height: 58vh;
    overflow: auto;
    background: #fff;
}

.activity-stream {
    padding: 0.95rem 1rem;
    display: grid;
    gap: 0.8rem;
    align-content: start;
    max-height: 58vh;
    overflow: auto;
}

.activity-item {
    display: grid;
    grid-template-columns: 18px minmax(0, 1fr);
    gap: 0.65rem;
}

.activity-dot {
    width: 18px;
    height: 18px;
    border-radius: 50%;
    border: 2px solid #cbd5e1;
    background: #fff;
    margin-top: 0.15rem;
}

.activity-created { border-color: #0ea5e9; background: #e0f2fe; }
.activity-status { border-color: #22c55e; background: #dcfce7; }
.activity-assignment { border-color: #f59e0b; background: #fef3c7; }
.activity-field { border-color: #a78bfa; background: #ede9fe; }
.activity-default { border-color: #94a3b8; background: #f1f5f9; }

.activity-body p {
    margin: 0;
    color: #334155;
}

.activity-body span {
    color: #64748b;
}

.activity-field {
    margin-top: 0.28rem !important;
    font-size: 0.86rem;
}

.task-content,
.notes-content {
    padding: 1rem;
    display: grid;
    gap: 0.6rem;
    align-content: start;
}

.task-content h3,
.notes-content h3 {
    margin: 0;
    font-size: 1rem;
}

.task-content p {
    margin: 0;
    color: #475569;
}

.note-item {
    border: 1px solid #e5edf7;
    border-radius: 10px;
    padding: 0.65rem;
    background: #fffbeb;
}

.note-head {
    display: flex;
    justify-content: space-between;
    gap: 0.6rem;
}

.note-item p {
    margin: 0.35rem 0 0;
    white-space: pre-wrap;
}

.message-row {
    display: grid;
    grid-template-columns: 34px minmax(0, 1fr);
    gap: 0.55rem;
    align-items: start;
}

.avatar {
    width: 34px;
    height: 34px;
    border-radius: 50%;
    display: grid;
    place-items: center;
    background: #1f2a44;
    color: #fff;
    font-weight: 700;
    font-size: 0.82rem;
}

.bubble {
    border: 1px solid #e5edf7;
    border-radius: 12px;
    background: #fafcff;
    padding: 0.6rem 0.7rem;
    display: grid;
    gap: 0.35rem;
}

.bubble.internal {
    background: #fff7ed;
    border-color: #fed7aa;
}

.bubble-head {
    display: flex;
    justify-content: space-between;
    align-items: center;
    gap: 0.5rem;
}

.bubble-head small {
    color: #64748b;
}

.bubble p {
    margin: 0;
    white-space: pre-wrap;
    color: #1e293b;
}

.internal-tag {
    display: inline-flex;
    width: fit-content;
    padding: 0.15rem 0.5rem;
    border-radius: 999px;
    border: 1px solid #fdba74;
    color: #9a3412;
    background: #ffedd5;
    font-size: 0.74rem;
}

.attachment-list {
    margin: 0.2rem 0 0;
    padding-left: 1rem;
    display: grid;
    gap: 0.2rem;
}

.attachment-list a {
    color: #0f766e;
    text-decoration: none;
}

.empty-row {
    margin: 0;
    color: #64748b;
}

.composer {
    border-top: 1px solid #e7edf6;
    padding: 0.75rem;
    display: grid;
    gap: 0.55rem;
    background: #fff;
}

.composer textarea {
    border: 1px solid #d7e0ea;
    border-radius: 10px;
    min-height: 84px;
    resize: vertical;
    padding: 0.58rem 0.65rem;
    font: inherit;
}

.composer-tools {
    display: flex;
    align-items: center;
    gap: 0.6rem;
    flex-wrap: wrap;
}

.upload-label,
.internal-check {
    display: inline-flex;
    align-items: center;
    gap: 0.4rem;
    color: #334155;
    font-size: 0.86rem;
}

.upload-label input {
    font-size: 0.82rem;
}

.internal-check input {
    width: auto;
}

.btn-send {
    margin-left: auto;
    background: #0f172a;
    border-color: #0f172a;
    color: #fff;
    padding: 0.46rem 0.85rem;
}

.staged-list {
    margin-top: 0;
}

.context-panel {
    display: grid;
    gap: 0.85rem;
    align-content: start;
}

.panel-card {
    padding: 0.75rem;
    display: grid;
    gap: 0.65rem;
}

.panel-card h2 {
    margin: 0;
    font-size: 0.96rem;
}

.kv-grid {
    display: grid;
    grid-template-columns: repeat(2, minmax(0, 1fr));
    gap: 0.55rem;
}

.kv-grid div {
    border: 1px solid #e4ebf5;
    border-radius: 10px;
    background: #fbfdff;
    padding: 0.45rem 0.52rem;
    display: grid;
    gap: 0.18rem;
}

.kv-grid .full-row {
    grid-column: 1 / -1;
}

.kv-grid span {
    color: #64748b;
    font-size: 0.78rem;
}

.kv-grid strong {
    font-size: 0.9rem;
}

.stack {
    display: grid;
    gap: 0.46rem;
}

.stack label {
    display: grid;
    gap: 0.28rem;
    color: #334155;
    font-size: 0.88rem;
}

.stack input,
.stack select {
    border: 1px solid #d7e0ea;
    border-radius: 9px;
    padding: 0.47rem 0.55rem;
    font: inherit;
}

.badge {
    display: inline-flex;
    padding: 0.15rem 0.48rem;
    border-radius: 999px;
    font-size: 0.76rem;
    border: 1px solid transparent;
}

.badge-open { color: #166534; background: #dcfce7; border-color: #bbf7d0; }
.badge-in_progress { color: #1d4ed8; background: #dbeafe; border-color: #bfdbfe; }
.badge-pending { color: #854d0e; background: #fef9c3; border-color: #fde68a; }
.badge-closed { color: #166534; background: #dcfce7; border-color: #86efac; }
.badge-cancelled { color: #7f1d1d; background: #fee2e2; border-color: #fecaca; }

.log-list {
    display: grid;
    gap: 0.45rem;
}

.log-item {
    border: 1px solid #e4ebf5;
    border-radius: 10px;
    background: #fbfdff;
    padding: 0.5rem;
    display: grid;
    gap: 0.25rem;
}

.log-head {
    display: flex;
    justify-content: space-between;
    align-items: center;
    gap: 0.45rem;
}

.log-item p {
    margin: 0;
    color: #475569;
    font-size: 0.84rem;
}

@media (max-width: 1200px) {
    .workspace-grid {
        grid-template-columns: 1fr;
    }

    .message-stream {
        max-height: none;
    }
}

@media (max-width: 640px) {
    .ticket-header {
        flex-direction: column;
        align-items: flex-start;
    }

    .header-actions {
        width: 100%;
    }

    .btn-success,
    .btn-ghost {
        width: 100%;
        justify-content: center;
        text-align: center;
    }

    .kv-grid {
        grid-template-columns: 1fr;
    }

    .btn-send {
        margin-left: 0;
        width: 100%;
    }

    .quick-actions {
        position: sticky;
        top: auto;
        right: auto;
        transform: none;
        margin-top: 0.4rem;
        grid-template-columns: repeat(4, minmax(0, 1fr));
        background: #fff;
        border: 1px solid #d9e2ee;
        border-radius: 12px;
        padding: 0.4rem;
    }

    .quick-actions button {
        width: 100%;
        border-radius: 10px;
    }

    .quick-message {
        position: static;
        width: fit-content;
    }
}
</style>
