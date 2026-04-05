<script setup>
import { computed, onMounted, ref } from 'vue';
import { useRouter } from 'vue-router';
import api from '../api/client';

const router = useRouter();

const loading = ref(false);
const loadingMore = ref(false);
const items = ref([]);
const unreadOnly = ref(false);
const page = ref(1);
const lastPage = ref(1);
const total = ref(0);
const unreadCount = ref(0);
const error = ref('');

const eventLabels = {
    ticket_created: 'Ticket criado',
    ticket_replied: 'Nova resposta',
    ticket_assignment_updated: 'Atribuição alterada',
    ticket_status_updated: 'Estado atualizado',
    ticket_knowledge_updated: 'Conhecimento atualizado',
};

const statusLabels = {
    open: 'Aberto',
    in_progress: 'Em tratamento',
    pending: 'Aguardando cliente',
    closed: 'Fechado',
    cancelled: 'Cancelado',
};

const hasMore = computed(() => page.value < lastPage.value);

const formatDateTime = (value) => {
    if (!value) {
        return '-';
    }

    const date = new Date(value);

    if (Number.isNaN(date.getTime())) {
        return '-';
    }

    return new Intl.DateTimeFormat('pt-PT', {
        day: '2-digit',
        month: '2-digit',
        year: 'numeric',
        hour: '2-digit',
        minute: '2-digit',
    }).format(date);
};

const mapEventLabel = (eventKey) => eventLabels[eventKey] || 'Notificação';
const mapStatusLabel = (status) => statusLabels[String(status || '')] || String(status || '-');

const mapNotificationMessage = (notification) => {
    const payload = notification?.payload || {};
    const message = String(notification?.message || notification?.title || '').trim();

    if (notification?.event_key === 'ticket_status_updated') {
        const status = payload.status_label || payload.status;
        if (status) {
            return `Estado atual: ${mapStatusLabel(status)}`;
        }

        const legacyStatus = message.replace(/^Current status:\s*/i, '').trim();
        if (legacyStatus !== '' && legacyStatus !== message) {
            return `Estado atual: ${mapStatusLabel(legacyStatus)}`;
        }
    }

    if (notification?.event_key === 'ticket_assignment_updated') {
        const assigned = payload.assigned_operator || '';
        if (assigned) {
            return `Operador atribuído: ${assigned}`;
        }

        const legacyAssigned = message.replace(/^Assigned operator:\s*/i, '').trim();
        if (legacyAssigned !== '' && legacyAssigned !== message) {
            return `Operador atribuído: ${legacyAssigned}`;
        }
    }

    if (notification?.event_key === 'ticket_knowledge_updated') {
        return 'Utilizadores em conhecimento foram atualizados.';
    }

    if (message === 'Knowledge recipients were updated.') {
        return 'Utilizadores em conhecimento foram atualizados.';
    }

    return message;
};

const fetchNotifications = async ({ reset = false } = {}) => {
    if (reset) {
        loading.value = true;
        page.value = 1;
    } else {
        loadingMore.value = true;
    }

    error.value = '';

    try {
        const response = await api.get('/notifications', {
            params: {
                page: page.value,
                per_page: 20,
                unread_only: unreadOnly.value ? 1 : 0,
            },
        });

        const rows = response.data.data || [];
        const meta = response.data.meta || {};

        if (reset) {
            items.value = rows;
        } else {
            items.value.push(...rows);
        }

        total.value = Number(meta.total || 0);
        lastPage.value = Number(meta.last_page || 1);
        unreadCount.value = Number(meta.unread_count || 0);

        window.dispatchEvent(new Event('supportdesk:notifications-updated'));
    } catch (exception) {
        error.value = exception?.response?.data?.message || 'Falha ao carregar notificações.';
    } finally {
        loading.value = false;
        loadingMore.value = false;
    }
};

const toggleUnreadOnly = async () => {
    await fetchNotifications({ reset: true });
};

const loadMore = async () => {
    if (!hasMore.value || loadingMore.value) {
        return;
    }

    page.value += 1;
    await fetchNotifications();
};

const markRead = async (notification) => {
    if (notification.is_read) {
        return;
    }

    try {
        await api.patch(`/notifications/${notification.id}/read`);
        notification.is_read = true;
        notification.read_at = new Date().toISOString();
        unreadCount.value = Math.max(0, unreadCount.value - 1);
        window.dispatchEvent(new Event('supportdesk:notifications-updated'));
    } catch (exception) {
        error.value = exception?.response?.data?.message || 'Falha ao marcar notificação como lida.';
    }
};

const markAllRead = async () => {
    if (unreadCount.value < 1) {
        return;
    }

    try {
        await api.patch('/notifications/read-all');
        items.value = items.value.map((notification) => ({
            ...notification,
            is_read: true,
            read_at: notification.read_at || new Date().toISOString(),
        }));
        unreadCount.value = 0;
        window.dispatchEvent(new Event('supportdesk:notifications-updated'));
    } catch (exception) {
        error.value = exception?.response?.data?.message || 'Falha ao marcar todas como lidas.';
    }
};

const openTicket = async (notification) => {
    await markRead(notification);

    if (notification.ticket?.id) {
        await router.push({ name: 'tickets.show', params: { id: notification.ticket.id } });
    }
};

onMounted(async () => {
    await fetchNotifications({ reset: true });
});
</script>

<template>
    <section class="notifications-page card">
        <header class="notifications-header">
            <div>
                <h1>Notificações</h1>
                <p class="muted">Recebe aqui alertas de tickets e atividade operacional.</p>
            </div>

            <div class="notifications-header-actions">
                <button type="button" class="ghost-button" :disabled="unreadCount < 1" @click="markAllRead">
                    Marcar todas como lidas
                </button>
                <span class="badge" :class="{ 'is-empty': unreadCount < 1 }">{{ unreadCount }}</span>
            </div>
        </header>

        <div class="notifications-toolbar">
            <label class="switch-row">
                <input v-model="unreadOnly" type="checkbox" @change="toggleUnreadOnly">
                <span>Apenas não lidas</span>
            </label>
            <span class="muted">{{ total }} registos</span>
        </div>

        <p v-if="error" class="error-banner">{{ error }}</p>

        <div v-if="loading" class="loading-state">A carregar notificações...</div>

        <ul v-else-if="items.length" class="notification-list">
            <li
                v-for="notification in items"
                :key="notification.id"
                :class="['notification-row', { 'is-unread': !notification.is_read }]"
            >
                <button type="button" class="notification-main" @click="openTicket(notification)">
                    <div class="notification-dot" :class="{ 'is-unread': !notification.is_read }" />

                    <div class="notification-content">
                        <p class="notification-title">
                            {{ mapEventLabel(notification.event_key) }}
                            <span v-if="notification.ticket?.ticket_number" class="ticket-tag">
                                {{ notification.ticket.ticket_number }}
                            </span>
                        </p>
                        <p class="notification-message">{{ mapNotificationMessage(notification) }}</p>
                        <p class="notification-meta">{{ formatDateTime(notification.created_at) }}</p>
                    </div>
                </button>

                <button
                    type="button"
                    class="link-button"
                    :disabled="notification.is_read"
                    @click="markRead(notification)"
                >
                    {{ notification.is_read ? 'Lida' : 'Marcar lida' }}
                </button>
            </li>
        </ul>

        <p v-else class="empty-state">Sem notificações para apresentar.</p>

        <div v-if="!loading && hasMore" class="list-footer">
            <button type="button" class="ghost-button" :disabled="loadingMore" @click="loadMore">
                {{ loadingMore ? 'A carregar...' : 'Carregar mais' }}
            </button>
        </div>
    </section>
</template>

<style scoped>
.notifications-page {
    padding: 1rem;
    display: grid;
    gap: 0.9rem;
}

.notifications-header {
    display: flex;
    justify-content: space-between;
    gap: 0.9rem;
    align-items: center;
}

.notifications-header h1 {
    margin: 0;
    font-size: 2rem;
}

.notifications-header-actions {
    display: inline-flex;
    align-items: center;
    gap: 0.6rem;
}

.notifications-toolbar {
    display: flex;
    justify-content: space-between;
    gap: 0.8rem;
    align-items: center;
    border: 1px solid #dce3ed;
    border-radius: 12px;
    padding: 0.58rem 0.72rem;
    background: #f9fbff;
}

.switch-row {
    display: inline-flex;
    gap: 0.45rem;
    align-items: center;
    font-weight: 600;
}

.error-banner {
    margin: 0;
    border: 1px solid #f3b7b7;
    background: #fff3f3;
    color: #8b1d1d;
    border-radius: 10px;
    padding: 0.62rem 0.72rem;
}

.loading-state,
.empty-state {
    margin: 0;
    border: 1px dashed #c7d3e2;
    border-radius: 12px;
    color: #5f6d80;
    padding: 1.2rem;
    text-align: center;
}

.notification-list {
    margin: 0;
    padding: 0;
    list-style: none;
    display: grid;
    gap: 0.52rem;
}

.notification-row {
    border: 1px solid #dce3ed;
    border-radius: 12px;
    background: #fff;
    padding: 0.5rem;
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 0.65rem;
}

.notification-row.is-unread {
    border-color: #8dcfbb;
    background: #f1fbf7;
}

.notification-main {
    border: none;
    background: transparent;
    padding: 0;
    margin: 0;
    text-align: left;
    display: flex;
    align-items: flex-start;
    gap: 0.6rem;
    color: inherit;
    cursor: pointer;
    width: 100%;
}

.notification-dot {
    width: 11px;
    height: 11px;
    border-radius: 999px;
    border: 1px solid #a9b7ca;
    margin-top: 0.4rem;
    flex: 0 0 auto;
}

.notification-dot.is-unread {
    background: #0f9f6a;
    border-color: #0f9f6a;
}

.notification-content {
    min-width: 0;
    display: grid;
    gap: 0.22rem;
}

.notification-title {
    margin: 0;
    font-weight: 700;
    display: inline-flex;
    align-items: center;
    gap: 0.35rem;
    flex-wrap: wrap;
}

.notification-message,
.notification-meta {
    margin: 0;
    color: #40526a;
}

.notification-meta {
    font-size: 0.82rem;
}

.ticket-tag {
    border: 1px solid #bfd5ef;
    border-radius: 999px;
    padding: 0.12rem 0.5rem;
    font-size: 0.78rem;
    color: #294e78;
    background: #f5f9ff;
}

.list-footer {
    display: flex;
    justify-content: center;
}

.badge {
    min-width: 30px;
    height: 30px;
    border-radius: 999px;
    background: #12a26f;
    color: #fff;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    font-weight: 700;
    padding: 0 0.45rem;
}

.badge.is-empty {
    background: #9fb0c6;
}

@media (max-width: 880px) {
    .notifications-header {
        flex-direction: column;
        align-items: flex-start;
    }

    .notifications-toolbar {
        flex-direction: column;
        align-items: flex-start;
    }

    .notification-row {
        flex-direction: column;
        align-items: stretch;
    }
}
</style>
