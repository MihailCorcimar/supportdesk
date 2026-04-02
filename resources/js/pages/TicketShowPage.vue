<script setup>
import { computed, nextTick, onBeforeUnmount, onMounted, reactive, ref, watch } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import api from '../api/client';
import { useAuthStore } from '../stores/auth';

const route = useRoute();
const router = useRouter();
const auth = useAuthStore();
const availableTopTabs = ['conversation', 'task', 'activity_logs', 'notes'];
const normalizeTopTab = (tab) => (availableTopTabs.includes(tab) ? tab : 'conversation');

const loading = ref(false);
const error = ref('');
const quickActionMessage = ref('');
const activeTopTab = ref(normalizeTopTab(typeof route.query.tab === 'string' ? route.query.tab : 'conversation'));
const ticket = ref(null);
const messageBody = ref('');
const messageFiles = ref([]);
const noteBody = ref('');
const savingStatus = ref(false);
const savingAssignment = ref(false);
const savingMetadata = ref(false);
const sendingMessage = ref(false);
const sendingNote = ref(false);
const statusMenuOpen = ref(false);
const pinPending = ref(false);

const statusForm = reactive({ status: '' });
const assignmentForm = reactive({ assigned_operator_id: '' });
const metadataForm = reactive({
    subject: '',
    priority: '',
    type: '',
    inbox_id: '',
    cc_emails: '',
    follower_user_ids: [],
});
const followerSearch = ref('');

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
const canChat = computed(() => Boolean(ticket.value?.permissions?.can_reply));
const chatMessages = computed(() => (ticket.value?.messages || []).filter((message) => !message.is_internal));
const internalNotes = computed(() => (ticket.value?.messages || []).filter((message) => message.is_internal));
const canAddNotes = computed(() => isOperator.value && ticket.value?.permissions?.can_add_internal_note);
const statusOrder = ['open', 'in_progress', 'pending', 'closed', 'cancelled'];
const headerStatusOptions = computed(() => statusOrder.filter((status) => status !== ticket.value?.status));
const isAlreadyClosed = computed(() => ticket.value?.status === 'closed');
const previousTicket = computed(() => ticket.value?.navigation?.previous ?? null);
const nextTicket = computed(() => ticket.value?.navigation?.next ?? null);
const filteredFollowers = computed(() => {
    const term = followerSearch.value.trim().toLowerCase();
    const followers = ticket.value?.available_followers || [];

    if (!term) return followers;

    return followers.filter((follower) => {
        const name = (follower.name || '').toLowerCase();
        const email = (follower.email || '').toLowerCase();
        return name.includes(term) || email.includes(term);
    });
});
const selectedFollowers = computed(() => {
    const selected = new Set((metadataForm.follower_user_ids || []).map((id) => Number(id)));
    return (ticket.value?.available_followers || []).filter((follower) => selected.has(Number(follower.id)));
});
let conversationRefreshTimer = null;

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
        metadataForm.follower_user_ids = (ticket.value.followers || []).map((follower) => Number(follower.id));
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

const updateStatusTo = async (newStatus) => {
    statusMenuOpen.value = false;
    statusForm.status = newStatus;
    await updateStatus();
};

const toggleStatusMenu = () => {
    statusMenuOpen.value = !statusMenuOpen.value;
};

const closeStatusMenuOnOutsideClick = (event) => {
    if (!statusMenuOpen.value) return;

    const target = event.target;
    if (!(target instanceof HTMLElement)) return;

    if (!target.closest('.status-split')) {
        statusMenuOpen.value = false;
    }
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

const toggleTicketPin = async () => {
    if (!ticket.value?.id || pinPending.value) {
        return;
    }

    pinPending.value = true;

    try {
        if (ticket.value.is_pinned) {
            await api.delete(`/conversations/${ticket.value.id}/pin`);
        } else {
            await api.post(`/conversations/${ticket.value.id}/pin`);
        }

        ticket.value.is_pinned = !ticket.value.is_pinned;
        quickActionMessage.value = ticket.value.is_pinned ? 'Ticket fixado' : 'Ticket desafixado';
        window.dispatchEvent(new CustomEvent('supportdesk:conversations-updated'));
    } catch {
        quickActionMessage.value = 'Não foi possível atualizar pin';
    } finally {
        pinPending.value = false;
        setTimeout(() => {
            quickActionMessage.value = '';
        }, 1400);
    }
};

const goToPreviousTicket = async () => {
    if (!previousTicket.value) return;
    await router.push({ name: 'tickets.show', params: { id: previousTicket.value.id } });
};

const goToNextTicket = async () => {
    if (!nextTicket.value) return;
    await router.push({ name: 'tickets.show', params: { id: nextTicket.value.id } });
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
            follower_user_ids: metadataForm.follower_user_ids,
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

const toggleFollower = (id) => {
    const normalizedId = Number(id);
    const current = (metadataForm.follower_user_ids || []).map((item) => Number(item));

    if (current.includes(normalizedId)) {
        metadataForm.follower_user_ids = current.filter((item) => item !== normalizedId);
        return;
    }

    metadataForm.follower_user_ids = [...current, normalizedId];
};

const removeFollower = (id) => {
    const normalizedId = Number(id);
    metadataForm.follower_user_ids = (metadataForm.follower_user_ids || [])
        .map((item) => Number(item))
        .filter((item) => item !== normalizedId);
};

const followerRoleLabel = (role) => (role === 'operator' ? 'Operador' : 'Cliente');

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

        messageFiles.value.forEach((file) => {
            formData.append('attachments[]', file);
        });

        await api.post(`/tickets/${route.params.id}/messages`, formData, {
            headers: {
                'Content-Type': 'multipart/form-data',
            },
        });

        messageBody.value = '';
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

const sendNote = async () => {
    if (!noteBody.value.trim()) {
        return;
    }

    sendingNote.value = true;
    error.value = '';

    try {
        const formData = new FormData();
        formData.append('body', noteBody.value.trim());
        formData.append('is_internal', '1');

        await api.post(`/tickets/${route.params.id}/messages`, formData, {
            headers: {
                'Content-Type': 'multipart/form-data',
            },
        });

        noteBody.value = '';
        await loadTicket();
    } catch (exception) {
        error.value = exception?.response?.data?.message || 'Falha ao adicionar nota.';
    } finally {
        sendingNote.value = false;
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

const isOwnMessage = (message) => {
    if (message.author_type !== 'user') return false;
    return Number(message.author_user?.id) === Number(auth.state.user?.id);
};

const stopConversationRefresh = () => {
    if (conversationRefreshTimer) {
        clearInterval(conversationRefreshTimer);
        conversationRefreshTimer = null;
    }
};

const startConversationRefresh = () => {
    stopConversationRefresh();
    conversationRefreshTimer = setInterval(() => {
        if (activeTopTab.value !== 'conversation') return;
        if (sendingMessage.value || loading.value) return;
        loadTicket();
    }, 8000);
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
    if (action === 'field_updated') return 'activity-meta';
    return 'activity-default';
};

const activitySymbol = (action) => {
    if (action === 'ticket_created') return '✦';
    if (action === 'status_updated') return '⟳';
    if (action === 'assignment_updated') return '↻';
    if (action === 'field_updated') return '⌁';
    return '•';
};

const formatActivityTime = (value) => {
    if (!value) return '--:--';

    return new Date(value).toLocaleTimeString('pt-PT', {
        hour: '2-digit',
        minute: '2-digit',
    });
};

const activityTitle = (log) => {
    const label = actionLabels[log.action] ?? log.action;

    if (log.field) {
        return `${label} · ${log.field}`;
    }

    return label;
};

const activityChange = (log) => {
    if (!log.field) return '';

    return `${log.old_value ?? '-'} -> ${log.new_value ?? '-'}`;
};

const activityGroupLabel = (value) => {
    const date = new Date(value);
    const now = new Date();

    const sameDay = date.toDateString() === now.toDateString();
    if (sameDay) return 'Today';

    const startOfWeek = new Date(now);
    startOfWeek.setHours(0, 0, 0, 0);
    startOfWeek.setDate(now.getDate() - ((now.getDay() + 6) % 7));

    if (date >= startOfWeek) return 'This week';

    if (date.getFullYear() === now.getFullYear() && date.getMonth() === now.getMonth()) {
        return 'This month';
    }

    return 'Earlier';
};

const activityGroups = computed(() => {
    const groupsMap = new Map();
    const orderedGroupNames = ['Today', 'This week', 'This month', 'Earlier'];
    const logs = ticket.value?.logs || [];

    logs.forEach((log) => {
        const key = activityGroupLabel(log.created_at);
        if (!groupsMap.has(key)) {
            groupsMap.set(key, []);
        }
        groupsMap.get(key).push(log);
    });

    return orderedGroupNames
        .filter((name) => groupsMap.has(name))
        .map((name) => ({
            label: name,
            logs: groupsMap.get(name),
        }));
});

onMounted(loadTicket);
onMounted(() => {
    document.addEventListener('click', closeStatusMenuOnOutsideClick);
    startConversationRefresh();
});
onBeforeUnmount(() => {
    document.removeEventListener('click', closeStatusMenuOnOutsideClick);
    stopConversationRefresh();
});
watch(
    () => route.params.id,
    () => {
        statusMenuOpen.value = false;
        loadTicket();
    },
);
watch(
    () => route.query.tab,
    (tab) => {
        activeTopTab.value = normalizeTopTab(typeof tab === 'string' ? tab : 'conversation');
    },
);
</script>

<template>
    <section v-if="!loading && ticket" class="ticket-workspace">
        <header class="ticket-header">
            <div class="header-left">
                <RouterLink class="back-link" :to="{ name: 'tickets.index' }">
                    <span class="back-icon" aria-hidden="true">
                        <svg viewBox="0 0 24 24" fill="none">
                            <path d="M14.5 6.5L9 12l5.5 5.5" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" />
                        </svg>
                    </span>
                    <span>Lista Tickets</span>
                </RouterLink>
            </div>

            <div class="header-center">
                <button
                    type="button"
                    class="ticket-step"
                    :disabled="!previousTicket"
                    aria-label="Ticket anterior"
                    @click="goToPreviousTicket"
                >
                    <svg class="step-icon" viewBox="0 0 24 24" fill="none" aria-hidden="true">
                        <path d="M14.5 6.5L9 12l5.5 5.5" stroke="currentColor" stroke-width="2.3" stroke-linecap="round" stroke-linejoin="round" />
                    </svg>
                </button>

                <p class="ticket-title" :title="ticket.subject">
                    <strong>{{ ticket.ticket_number }}</strong>
                    <span>{{ ticket.subject }}</span>
                </p>

                <button
                    type="button"
                    class="ticket-step"
                    :disabled="!nextTicket"
                    aria-label="Ticket seguinte"
                    @click="goToNextTicket"
                >
                    <svg class="step-icon" viewBox="0 0 24 24" fill="none" aria-hidden="true">
                        <path d="M9.5 6.5L15 12l-5.5 5.5" stroke="currentColor" stroke-width="2.3" stroke-linecap="round" stroke-linejoin="round" />
                    </svg>
                </button>
            </div>

            <div class="header-actions">
                <button
                    type="button"
                    class="btn-ghost pin-header-btn"
                    :class="{ 'is-pinned': ticket.is_pinned }"
                    :disabled="pinPending"
                    :title="ticket.is_pinned ? 'Desafixar ticket' : 'Fixar ticket'"
                    :aria-label="ticket.is_pinned ? 'Desafixar ticket' : 'Fixar ticket'"
                    @click="toggleTicketPin"
                >
                    <svg class="pin-icon" viewBox="0 0 24 24" fill="none" aria-hidden="true">
                        <path d="M9 4h6l-1.2 5.2 3.2 2.8v1.2H7v-1.2l3.2-2.8L9 4z" stroke="currentColor" stroke-width="1.7" stroke-linejoin="round" />
                        <path d="M12 13v7" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" />
                    </svg>
                    <span>{{ ticket.is_pinned ? 'Desafixar' : 'Fixar' }}</span>
                </button>

                <div v-if="isOperator && canUpdateStatus" class="status-split">
                    <button
                        class="btn-success status-main"
                        type="button"
                        :disabled="savingStatus || isAlreadyClosed"
                        @click="closeTicketQuick"
                    >
                        {{ savingStatus ? 'A atualizar...' : 'Submeter como fechado' }}
                    </button>

                    <button
                        class="btn-success status-toggle"
                        type="button"
                        aria-label="Escolher outro estado"
                        :aria-expanded="statusMenuOpen"
                        @click.stop="toggleStatusMenu"
                    >
                        ▾
                    </button>

                    <div v-if="statusMenuOpen" class="status-menu">
                        <button
                            v-for="status in headerStatusOptions"
                            :key="`header-status-${status}`"
                            type="button"
                            class="status-option"
                            @click="updateStatusTo(status)"
                        >
                            {{ statusLabels[status] ?? status }}
                        </button>
                    </div>
                </div>

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
                        Notas
                    </button>
                </div>

                <div v-if="activeTopTab === 'conversation'" class="conversation-tab-content">
                    <div class="message-stream">
                        <article class="message-row" :class="{ own: isOwnMessage(message) }" v-for="message in chatMessages" :key="message.id">
                            <div class="avatar">{{ messageAuthorInitial(message) }}</div>

                            <div class="bubble" :class="{ internal: message.is_internal }">
                                <div class="bubble-head">
                                    <strong>{{ messageAuthor(message) }}</strong>
                                    <small>{{ formatDate(message.created_at) }}</small>
                                </div>

                                <p v-if="message.body">{{ message.body }}</p>

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

                        <p v-if="!chatMessages.length" class="empty-row">Sem mensagens públicas neste ticket.</p>
                        <p v-if="isOperator && internalNotes.length" class="chat-note-hint">
                            Existem {{ internalNotes.length }} notas internas no separador Notas.
                        </p>
                    </div>

                    <form
                        v-if="canChat"
                        id="composer-section"
                        class="composer"
                        @submit.prevent="sendMessage"
                    >
                        <textarea
                            id="ticket-composer-input"
                            v-model="messageBody"
                            placeholder="Escreva uma resposta para o cliente..."
                        />

                        <div class="composer-tools">
                            <label class="upload-label">
                                Anexos
                                <input id="message-attachments" type="file" multiple @change="handleFilesChange">
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

                    <p v-else class="notes-muted">Este ticket não aceita novas respostas no estado atual.</p>
                </div>

                <div v-else-if="activeTopTab === 'activity_logs'" class="activity-stream">
                    <section class="activity-group" v-for="group in activityGroups" :key="group.label">
                        <h3 class="activity-group-title">{{ group.label }}</h3>

                        <div class="activity-list">
                            <article class="activity-item" v-for="log in group.logs" :key="log.id">
                                <span class="activity-marker" :class="activityIconClass(log.action)">
                                    {{ activitySymbol(log.action) }}
                                </span>
                                <div class="activity-body">
                                    <p class="activity-line">
                                        <strong>{{ activityTitle(log) }}</strong>
                                        <span v-if="log.field"> · {{ activityChange(log) }}</span>
                                        <span> · {{ logActor(log) }}</span>
                                        <span> · {{ formatActivityTime(log.created_at) }}</span>
                                        <span> · {{ formatDate(log.created_at) }}</span>
                                    </p>
                                </div>
                            </article>
                        </div>
                    </section>

                    <p v-if="!ticket.logs.length" class="empty-row">Sem atividade registada.</p>
                </div>

                <div v-else-if="activeTopTab === 'task'" class="task-content">
                    <h3>Tarefa operacional</h3>
                    <p v-if="!isOperator">Sem ações operacionais disponíveis para o seu perfil.</p>

                    <form class="stack" @submit.prevent="updateStatus" v-if="isOperator && canUpdateStatus">
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

                    <form class="stack" @submit.prevent="updateAssignment" v-if="isOperator && canAssign">
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

                    <form class="stack" @submit.prevent="updateMetadata" v-if="isOperator && canUpdateMetadata">
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

                        <label>
                            Seguidores (utilizadores)
                            <div class="followers-picker">
                                <input
                                    v-model="followerSearch"
                                    type="search"
                                    placeholder="Pesquisar utilizador por nome ou email"
                                >

                                <div class="followers-list">
                                    <label v-for="follower in filteredFollowers" :key="follower.id" class="follower-item">
                                        <input
                                            type="checkbox"
                                            :checked="metadataForm.follower_user_ids.map((id) => Number(id)).includes(Number(follower.id))"
                                            @change="toggleFollower(follower.id)"
                                        >
                                        <span>{{ follower.name }}</span>
                                        <small>{{ followerRoleLabel(follower.role) }}</small>
                                    </label>
                                    <p v-if="!filteredFollowers.length" class="followers-empty">Sem resultados.</p>
                                </div>

                                <div v-if="selectedFollowers.length" class="followers-tags">
                                    <span v-for="follower in selectedFollowers" :key="`meta-follow-${follower.id}`" class="follower-tag">
                                        {{ follower.name }}
                                        <button type="button" @click="removeFollower(follower.id)">×</button>
                                    </span>
                                </div>
                            </div>
                        </label>

                        <button class="btn-primary" type="submit" :disabled="savingMetadata">
                            {{ savingMetadata ? 'A guardar...' : 'Atualizar metadados' }}
                        </button>
                    </form>
                </div>

                <div v-else class="notes-content">
                    <form v-if="canAddNotes" class="note-form" @submit.prevent="sendNote">
                        <textarea
                            v-model="noteBody"
                            placeholder="Escreve uma nota interna..."
                            maxlength="3000"
                            rows="3"
                        ></textarea>
                        <button class="btn-send-note" type="submit" :disabled="sendingNote || !noteBody.trim()">
                            {{ sendingNote ? 'A submeter...' : 'Submeter' }}
                        </button>
                    </form>

                    <p v-else class="notes-muted">Só operadores podem adicionar notas internas.</p>

                    <article class="note-row" v-for="message in internalNotes" :key="`note-${message.id}`">
                        <div class="note-avatar">{{ messageAuthorInitial(message) }}</div>
                        <div class="note-item">
                            <div class="note-head">
                                <strong>{{ messageAuthor(message) }}</strong>
                                <small>{{ formatActivityTime(message.created_at) }}</small>
                            </div>
                            <p>{{ message.body || 'Sem texto.' }}</p>
                        </div>
                    </article>
                </div>
            </article>

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
    display: grid;
    grid-template-columns: minmax(0, 1fr) auto minmax(0, 1fr);
    align-items: center;
    gap: 0.8rem;
    background: #fff;
    border: 1px solid #d8e1ed;
    border-radius: 14px;
    padding: 0.75rem 0.85rem;
}

.header-left {
    min-width: 0;
}

.back-link {
    display: inline-flex;
    align-items: center;
    gap: 0.35rem;
    font-size: 0.82rem;
    color: #4b5563;
    text-decoration: none;
}

.back-icon {
    width: 20px;
    height: 20px;
    border-radius: 999px;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    background: #eef2f7;
    color: #334155;
    transition: background-color 120ms ease, color 120ms ease;
}

.back-link:hover .back-icon {
    background: #dff7ed;
    color: #0f766e;
}

.back-icon svg {
    width: 14px;
    height: 14px;
}

.header-center {
    display: inline-flex;
    align-items: center;
    gap: 0.55rem;
    min-width: 0;
    justify-self: center;
}

.ticket-step {
    border: 1px solid #d8e1ed;
    background: #f8fafc;
    color: #64748b;
    width: 28px;
    height: 28px;
    border-radius: 8px;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    cursor: pointer;
    transition: background-color 120ms ease, border-color 120ms ease, color 120ms ease;
}

.ticket-step:hover:not(:disabled) {
    background: #e8fbf2;
    border-color: #a6dfc6;
    color: #0f766e;
}

.ticket-step:disabled {
    opacity: 0.35;
    cursor: not-allowed;
}

.step-icon {
    width: 16px;
    height: 16px;
}

.ticket-title {
    margin: 0;
    display: inline-flex;
    gap: 0.45rem;
    align-items: center;
    min-width: 0;
    max-width: min(52vw, 700px);
}

.ticket-title strong {
    font-size: 1.04rem;
    white-space: nowrap;
}

.ticket-title span {
    color: #0f172a;
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
}

.header-actions {
    display: flex;
    align-items: center;
    gap: 0.45rem;
    flex-wrap: wrap;
    justify-self: end;
}

.pin-header-btn {
    display: inline-flex;
    align-items: center;
    gap: 0.38rem;
    color: #475569;
}

.pin-header-btn .pin-icon {
    width: 16px;
    height: 16px;
    transition: transform 0.18s ease;
}

.pin-header-btn.is-pinned {
    border-color: #a5d8bf;
    background: #eaf9f1;
    color: #0f8f62;
}

.pin-header-btn.is-pinned .pin-icon {
    transform: rotate(-16deg);
}

.status-split {
    position: relative;
    display: inline-flex;
    align-items: stretch;
    border-radius: 9px;
    overflow: hidden;
}

.status-main {
    border-top-right-radius: 0;
    border-bottom-right-radius: 0;
}

.status-toggle {
    border-top-left-radius: 0;
    border-bottom-left-radius: 0;
    border-left-color: rgba(255, 255, 255, 0.45);
    width: 38px;
    padding: 0;
    font-size: 0.95rem;
}

.status-menu {
    position: absolute;
    right: 0;
    top: calc(100% + 0.35rem);
    min-width: 180px;
    background: #fff;
    border: 1px solid #d4dde8;
    border-radius: 10px;
    box-shadow: 0 8px 22px rgba(15, 23, 42, 0.12);
    padding: 0.3rem;
    display: grid;
    gap: 0.2rem;
    z-index: 30;
}

.status-option {
    border: 1px solid transparent;
    background: #fff;
    color: #0f172a;
    text-align: left;
    border-radius: 8px;
    padding: 0.45rem 0.55rem;
    font: inherit;
    cursor: pointer;
}

.status-option:hover {
    background: #f0fdf4;
    border-color: #c5e8d7;
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
    grid-template-columns: 1fr;
    gap: 0.9rem;
}

.conversation-card {
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
    gap: 1rem;
    align-content: start;
    max-height: 58vh;
    overflow: auto;
}

.activity-group {
    display: grid;
    gap: 0.55rem;
}

.activity-group-title {
    margin: 0;
    font-size: 1.15rem;
    font-weight: 600;
    color: #0f172a;
}

.activity-list {
    position: relative;
    display: grid;
    gap: 0.35rem;
}

.activity-list::before {
    content: '';
    position: absolute;
    left: 13px;
    top: 4px;
    bottom: 4px;
    width: 1px;
    background: #dbe4ee;
}

.activity-item {
    display: grid;
    grid-template-columns: 26px minmax(0, 1fr);
    gap: 0.65rem;
    align-items: flex-start;
}

.activity-marker {
    width: 26px;
    height: 26px;
    border-radius: 50%;
    border: 1px solid #cbd5e1;
    background: #f8fafc;
    margin-top: 0.05rem;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    font-size: 0.78rem;
    font-weight: 700;
    color: #334155;
    z-index: 1;
}

.activity-created { border-color: #9ca3af; background: #f3f4f6; color: #4b5563; }
.activity-status { border-color: #ef4444; background: #fee2e2; color: #b91c1c; }
.activity-assignment { border-color: #0ea5e9; background: #e0f2fe; color: #0369a1; }
.activity-meta { border-color: #14b8a6; background: #ccfbf1; color: #0f766e; }
.activity-default { border-color: #94a3b8; background: #f1f5f9; color: #475569; }

.activity-body {
    display: grid;
    padding-top: 0.1rem;
}

.activity-line {
    margin: 0;
    color: #111827;
    font-size: 0.89rem;
    line-height: 1.3;
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
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

.note-form {
    border: 1px solid #cfd8e3;
    border-radius: 16px;
    background: #fff;
    padding: 0.65rem 0.8rem;
    position: relative;
    width: min(920px, 100%);
    margin: 0 auto;
}

.note-form textarea {
    border: none;
    min-height: 64px;
    resize: vertical;
    font: inherit;
    color: #1e293b;
    padding: 0.15rem 7.5rem 0.15rem 0.05rem;
    line-height: 1.4;
    width: 100%;
}

.note-form textarea:focus {
    outline: none;
}

.btn-send-note {
    position: absolute;
    right: 0.75rem;
    bottom: 0.65rem;
    border-radius: 9px;
    border: 1px solid #0f172a;
    background: #0f172a;
    color: #fff;
    padding: 0.4rem 0.9rem;
    cursor: pointer;
    font: inherit;
    font-size: 0.9rem;
}

.btn-send-note:disabled {
    opacity: 0.6;
    cursor: not-allowed;
}

.notes-muted {
    margin: 0;
    color: #64748b;
    font-size: 0.9rem;
}

.note-row {
    display: grid;
    grid-template-columns: 34px minmax(0, 1fr);
    gap: 0.65rem;
    padding: 0.45rem 0.1rem;
    border-bottom: 1px solid #e8edf5;
}

.note-avatar {
    width: 34px;
    height: 34px;
    border-radius: 999px;
    background: #1f2a44;
    color: #fff;
    display: grid;
    place-items: center;
    font-weight: 700;
    font-size: 0.82rem;
}

.note-item {
    display: grid;
    gap: 0.22rem;
    align-content: start;
}

.note-head {
    display: inline-flex;
    align-items: center;
    gap: 0.4rem;
}

.note-head strong {
    font-size: 0.92rem;
    color: #0f172a;
}

.note-head small {
    color: #64748b;
    font-size: 0.8rem;
}

.note-item p {
    margin: 0;
    white-space: pre-wrap;
    color: #111827;
    font-size: 0.9rem;
    line-height: 1.35;
}

@media (max-width: 760px) {
    .note-form {
        padding: 0.6rem 0.65rem;
    }

    .note-form textarea {
        min-height: 56px;
        padding-right: 0.1rem;
        margin-bottom: 0.5rem;
    }

    .btn-send-note {
        position: static;
        justify-self: end;
    }
}

.message-row {
    display: grid;
    grid-template-columns: 34px minmax(0, 1fr);
    gap: 0.55rem;
    align-items: start;
}

.message-row.own {
    grid-template-columns: minmax(0, 1fr) 34px;
}

.message-row.own .avatar {
    order: 2;
    background: #0f766e;
}

.message-row.own .bubble {
    order: 1;
    background: #ecfdf5;
    border-color: #9fd9c2;
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

.chat-note-hint {
    margin: 0;
    color: #64748b;
    font-size: 0.84rem;
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

.followers-picker {
    border: 1px solid #d7e0ea;
    border-radius: 10px;
    padding: 0.55rem;
    display: grid;
    gap: 0.45rem;
    background: #f8fbff;
}

.followers-list {
    border: 1px solid #dbe4ee;
    border-radius: 8px;
    background: #fff;
    max-height: 180px;
    overflow: auto;
}

.follower-item {
    display: grid;
    grid-template-columns: auto 1fr auto;
    align-items: center;
    gap: 0.4rem;
    padding: 0.36rem 0.5rem;
    border-bottom: 1px solid #eef2f7;
}

.follower-item:last-child {
    border-bottom: 0;
}

.follower-item small {
    color: #64748b;
}

.followers-empty {
    margin: 0;
    padding: 0.6rem;
    color: #64748b;
}

.followers-tags {
    display: flex;
    flex-wrap: wrap;
    gap: 0.35rem;
}

.follower-tag {
    display: inline-flex;
    align-items: center;
    gap: 0.3rem;
    border: 1px solid #b9ccdf;
    border-radius: 999px;
    background: #eff6ff;
    color: #0f172a;
    padding: 0.15rem 0.5rem;
    font-size: 0.8rem;
}

.follower-tag button {
    border: 0;
    background: transparent;
    padding: 0;
    line-height: 1;
    cursor: pointer;
    color: #475569;
}

@media (max-width: 1200px) {
    .message-stream {
        max-height: none;
    }
}

@media (max-width: 640px) {
    .ticket-header {
        grid-template-columns: 1fr;
        align-items: stretch;
    }

    .header-actions {
        width: 100%;
        justify-self: stretch;
    }

    .header-center {
        justify-self: stretch;
        justify-content: center;
    }

    .ticket-title {
        max-width: calc(100vw - 170px);
    }

    .btn-success,
    .btn-ghost {
        width: 100%;
        justify-content: center;
        text-align: center;
    }

    .status-split {
        width: 100%;
    }

    .status-main {
        flex: 1;
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
