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

const formatRelativeTime = (value) => {
    if (!value) return '-';
    const date = new Date(value);
    if (Number.isNaN(date.getTime())) return '-';

    const diffMs = Date.now() - date.getTime();
    const diffMin = Math.floor(diffMs / 60000);
    const diffHour = Math.floor(diffMin / 60);
    const diffDay = Math.floor(diffHour / 24);

    if (diffMin < 1) return 'Agora mesmo';
    if (diffMin < 60) return `Há ${diffMin} min`;
    if (diffHour < 24) return `Há ${diffHour}h`;
    if (diffDay === 1) return 'Ontem';
    if (diffDay < 7) return `Há ${diffDay} dias`;

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

const eventIconPath = (eventKey) => {
    const icons = {
        ticket_created: 'M3 3h8l3 3v9a1 1 0 0 1-1 1H3a1 1 0 0 1-1-1V4a1 1 0 0 1 1-1zm8 0v3h3',
        ticket_replied: 'M2 3.5A1.5 1.5 0 0 1 3.5 2h9A1.5 1.5 0 0 1 14 3.5v6A1.5 1.5 0 0 1 12.5 11H9l-2 2.5L5 11H3.5A1.5 1.5 0 0 1 2 9.5v-6z',
        ticket_assignment_updated: 'M8 2a3 3 0 1 0 0 6 3 3 0 0 0 0-6zm-6 11c0-3 2.7-5 6-5s6 2 6 5',
        ticket_status_updated: 'M8 2a6 6 0 1 0 0 12A6 6 0 0 0 8 2zm0 3v3l2 2',
        ticket_knowledge_updated: 'M2 3h5a2 2 0 0 1 1 .5V13a2 2 0 0 0-2-2H2V3zm12 0h-5a2 2 0 0 0-1 .5V13a2 2 0 0 1 2-2h4V3z',
    };
    return icons[eventKey] || 'M8 2a6 6 0 1 0 0 12A6 6 0 0 0 8 2zm0 4v2m0 2.5v.5';
};

const eventColor = (eventKey) => {
    const colors = {
        ticket_created: '#2563eb',
        ticket_replied: '#0891b2',
        ticket_assignment_updated: '#7c3aed',
        ticket_status_updated: '#d97706',
        ticket_knowledge_updated: '#0f766e',
    };
    return colors[eventKey] || '#6b7280';
};

const eventBg = (eventKey) => {
    const bgs = {
        ticket_created: '#dbeafe',
        ticket_replied: '#cffafe',
        ticket_assignment_updated: '#f3e8ff',
        ticket_status_updated: '#fef3c7',
        ticket_knowledge_updated: '#ccfbf1',
    };
    return bgs[eventKey] || '#f1f5f9';
};

const mapNotificationMessage = (notification) => {
    const payload = notification?.payload || {};
    const message = String(notification?.message || notification?.title || '').trim();

    if (notification?.event_key === 'ticket_status_updated') {
        const status = payload.status_label || payload.status;
        if (status) return `Estado atual: ${mapStatusLabel(status)}`;
        const legacyStatus = message.replace(/^Current status:\s*/i, '').trim();
        if (legacyStatus !== '' && legacyStatus !== message) return `Estado atual: ${mapStatusLabel(legacyStatus)}`;
    }

    if (notification?.event_key === 'ticket_assignment_updated') {
        const assigned = payload.assigned_operator || '';
        if (assigned) return `Operador atribuído: ${assigned}`;
        const legacyAssigned = message.replace(/^Assigned operator:\s*/i, '').trim();
        if (legacyAssigned !== '' && legacyAssigned !== message) return `Operador atribuído: ${legacyAssigned}`;
    }

    if (notification?.event_key === 'ticket_knowledge_updated') return 'Utilizadores em conhecimento foram atualizados.';
    if (message === 'Knowledge recipients were updated.') return 'Utilizadores em conhecimento foram atualizados.';

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
    if (!hasMore.value || loadingMore.value) return;
    page.value += 1;
    await fetchNotifications();
};

const markRead = async (notification) => {
    if (notification.is_read) return;

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
    if (unreadCount.value < 1) return;

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
    <section class="npage">

        <!-- Header -->
        <header class="npage-header">
            <div class="npage-title-block">
                <div class="npage-title-icon">
                    <svg viewBox="0 0 20 20" fill="none">
                        <path d="M10 2a6 6 0 0 0-6 6v3l-1.5 2.5h15L16 11V8a6 6 0 0 0-6-6z" stroke="currentColor" stroke-width="1.5" stroke-linejoin="round"/>
                        <path d="M8 16.5a2 2 0 0 0 4 0" stroke="currentColor" stroke-width="1.5" stroke-linecap="round"/>
                    </svg>
                </div>
                <div>
                    <h1 class="npage-h1">Notificações</h1>
                    <p class="npage-subtitle">Alertas de tickets e atividade operacional</p>
                </div>
            </div>

            <div class="npage-header-right">
                <button
                    type="button"
                    class="npage-mark-all-btn"
                    :disabled="unreadCount < 1"
                    @click="markAllRead"
                >
                    <svg viewBox="0 0 16 16" fill="none">
                        <path d="M2 8l4 4 8-8" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/>
                    </svg>
                    Marcar todas como lidas
                </button>
                <div class="npage-badge" :class="{ 'is-empty': unreadCount < 1 }">
                    {{ unreadCount }}
                </div>
            </div>
        </header>

        <!-- Toolbar -->
        <div class="npage-toolbar">
            <label class="npage-toggle-label">
                <span class="npage-toggle-track" :class="{ 'is-on': unreadOnly }">
                    <span class="npage-toggle-thumb" />
                </span>
                <input v-model="unreadOnly" type="checkbox" class="npage-toggle-input" @change="toggleUnreadOnly">
                <span class="npage-toggle-text">Apenas não lidas</span>
            </label>
            <span class="npage-count">{{ total }} notificação{{ total !== 1 ? 'ões' : '' }}</span>
        </div>

        <!-- Error -->
        <p v-if="error" class="npage-error">
            <svg viewBox="0 0 16 16" fill="none"><circle cx="8" cy="8" r="6" stroke="currentColor" stroke-width="1.4"/><path d="M8 5v3m0 2.5v.5" stroke="currentColor" stroke-width="1.5" stroke-linecap="round"/></svg>
            {{ error }}
        </p>

        <!-- Loading -->
        <div v-if="loading" class="npage-loading">
            <span class="npage-spinner"></span>
            A carregar notificações…
        </div>

        <!-- List -->
        <ul v-else-if="items.length" class="npage-list">
            <li
                v-for="notification in items"
                :key="notification.id"
                class="npage-item"
                :class="{ 'is-unread': !notification.is_read }"
            >
                <!-- Event icon -->
                <div
                    class="npage-event-icon"
                    :style="{ background: eventBg(notification.event_key), color: eventColor(notification.event_key) }"
                >
                    <svg viewBox="0 0 16 16" fill="none">
                        <path :d="eventIconPath(notification.event_key)" stroke="currentColor" stroke-width="1.4" stroke-linecap="round" stroke-linejoin="round"/>
                    </svg>
                </div>

                <!-- Content (clickable area) -->
                <button type="button" class="npage-item-body" @click="openTicket(notification)">
                    <div class="npage-item-top">
                        <span class="npage-event-label">{{ mapEventLabel(notification.event_key) }}</span>
                        <span
                            v-if="notification.ticket?.ticket_number"
                            class="npage-ticket-tag"
                        >#{{ notification.ticket.ticket_number }}</span>
                        <span v-if="!notification.is_read" class="npage-unread-dot" aria-label="Não lida"></span>
                    </div>
                    <p class="npage-message">{{ mapNotificationMessage(notification) }}</p>
                    <p class="npage-time">{{ formatRelativeTime(notification.created_at) }}</p>
                </button>

                <!-- Mark read action -->
                <button
                    type="button"
                    class="npage-read-btn"
                    :class="{ 'is-done': notification.is_read }"
                    :disabled="notification.is_read"
                    :title="notification.is_read ? 'Já lida' : 'Marcar como lida'"
                    @click.stop="markRead(notification)"
                >
                    <svg viewBox="0 0 16 16" fill="none">
                        <path d="M3 8l3.5 3.5 6.5-7" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/>
                    </svg>
                </button>
            </li>
        </ul>

        <!-- Empty state -->
        <div v-else class="npage-empty">
            <svg class="npage-empty-icon" viewBox="0 0 64 64" fill="none">
                <circle cx="32" cy="32" r="28" stroke="#dde8f4" stroke-width="2"/>
                <path d="M32 14a12 12 0 0 0-12 12v7l-3 5h30l-3-5v-7a12 12 0 0 0-12-12z" stroke="#b0c8e4" stroke-width="2" stroke-linejoin="round"/>
                <path d="M28 41a4 4 0 0 0 8 0" stroke="#b0c8e4" stroke-width="2" stroke-linecap="round"/>
                <path d="M26 26l12 12M38 26L26 38" stroke="#d4e4f5" stroke-width="1.5" stroke-linecap="round"/>
            </svg>
            <p class="npage-empty-title">Sem notificações</p>
            <p class="npage-empty-sub">{{ unreadOnly ? 'Não há notificações não lidas.' : 'Ainda não recebeste nenhuma notificação.' }}</p>
        </div>

        <!-- Load more -->
        <div v-if="!loading && hasMore" class="npage-more">
            <button type="button" class="npage-more-btn" :disabled="loadingMore" @click="loadMore">
                <span v-if="loadingMore" class="npage-spinner npage-spinner--sm"></span>
                {{ loadingMore ? 'A carregar…' : 'Carregar mais' }}
            </button>
        </div>

    </section>
</template>

<style scoped>
.npage {
    display: flex;
    flex-direction: column;
    gap: 0.85rem;
    padding: 1.1rem;
    max-width: 780px;
    margin: 0 auto;
}

/* ── Header ───────────────────────────────────────────────── */

.npage-header {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 1rem;
    flex-wrap: wrap;
}

.npage-title-block {
    display: flex;
    align-items: center;
    gap: 0.75rem;
}

.npage-title-icon {
    width: 44px;
    height: 44px;
    border-radius: 12px;
    background: #e8f0fb;
    color: #1F4E79;
    display: flex;
    align-items: center;
    justify-content: center;
    flex-shrink: 0;
}

.npage-title-icon svg {
    width: 22px;
    height: 22px;
}

.npage-h1 {
    margin: 0;
    font-size: 1.45rem;
    font-weight: 800;
    color: #0f172a;
    line-height: 1.2;
}

.npage-subtitle {
    margin: 0.1rem 0 0;
    font-size: 0.84rem;
    color: #64748b;
}

.npage-header-right {
    display: flex;
    align-items: center;
    gap: 0.55rem;
}

.npage-mark-all-btn {
    display: inline-flex;
    align-items: center;
    gap: 0.35rem;
    font: inherit;
    font-size: 0.84rem;
    font-weight: 600;
    color: #1e5a9c;
    background: #e8f2fc;
    border: 1px solid #b8d5f0;
    border-radius: 999px;
    padding: 0.38rem 0.8rem;
    cursor: pointer;
    transition: background 120ms, border-color 120ms;
}

.npage-mark-all-btn svg {
    width: 14px;
    height: 14px;
}

.npage-mark-all-btn:hover:not(:disabled) {
    background: #d4e8f8;
    border-color: #7ab8e8;
}

.npage-mark-all-btn:disabled {
    opacity: 0.45;
    cursor: default;
}

.npage-badge {
    min-width: 32px;
    height: 32px;
    border-radius: 999px;
    background: #12a26f;
    color: #fff;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    font-weight: 800;
    font-size: 0.88rem;
    padding: 0 0.5rem;
}

.npage-badge.is-empty {
    background: #cbd5e1;
    color: #64748b;
}

/* ── Toolbar ──────────────────────────────────────────────── */

.npage-toolbar {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 0.8rem;
    background: #f8fbff;
    border: 1px solid #dde8f4;
    border-radius: 12px;
    padding: 0.55rem 0.85rem;
}

.npage-toggle-label {
    display: inline-flex;
    align-items: center;
    gap: 0.5rem;
    cursor: pointer;
    user-select: none;
}

.npage-toggle-input {
    position: absolute;
    opacity: 0;
    width: 0;
    height: 0;
}

.npage-toggle-track {
    width: 36px;
    height: 20px;
    border-radius: 999px;
    background: #cbd5e1;
    border: 1px solid #b6c3d4;
    position: relative;
    transition: background 150ms, border-color 150ms;
    flex-shrink: 0;
}

.npage-toggle-track.is-on {
    background: #12a26f;
    border-color: #0d8a5c;
}

.npage-toggle-thumb {
    position: absolute;
    top: 2px;
    left: 2px;
    width: 14px;
    height: 14px;
    border-radius: 50%;
    background: #fff;
    box-shadow: 0 1px 3px rgba(0, 0, 0, 0.18);
    transition: left 150ms;
}

.npage-toggle-track.is-on .npage-toggle-thumb {
    left: 18px;
}

.npage-toggle-text {
    font-size: 0.87rem;
    font-weight: 600;
    color: #334155;
}

.npage-count {
    font-size: 0.82rem;
    color: #64748b;
    font-weight: 500;
}

/* ── Error ────────────────────────────────────────────────── */

.npage-error {
    margin: 0;
    display: flex;
    align-items: center;
    gap: 0.45rem;
    border: 1px solid #fca5a5;
    background: #fef2f2;
    color: #991b1b;
    border-radius: 10px;
    padding: 0.62rem 0.78rem;
    font-size: 0.88rem;
}

.npage-error svg {
    width: 16px;
    height: 16px;
    flex-shrink: 0;
}

/* ── Loading ──────────────────────────────────────────────── */

.npage-loading {
    display: flex;
    align-items: center;
    justify-content: center;
    gap: 0.6rem;
    padding: 2.5rem 1rem;
    color: #64748b;
    font-size: 0.9rem;
}

.npage-spinner {
    display: inline-block;
    width: 18px;
    height: 18px;
    border: 2px solid #d4e4f5;
    border-top-color: #1F4E79;
    border-radius: 50%;
    animation: npage-spin 0.7s linear infinite;
    flex-shrink: 0;
}

.npage-spinner--sm {
    width: 13px;
    height: 13px;
}

@keyframes npage-spin {
    to { transform: rotate(360deg); }
}

/* ── Notification list ────────────────────────────────────── */

.npage-list {
    margin: 0;
    padding: 0;
    list-style: none;
    display: flex;
    flex-direction: column;
    gap: 0.42rem;
}

.npage-item {
    display: flex;
    align-items: flex-start;
    gap: 0.7rem;
    background: #fff;
    border: 1px solid #e2ecf5;
    border-radius: 14px;
    padding: 0.72rem 0.75rem;
    transition: border-color 120ms, box-shadow 120ms;
    position: relative;
}

.npage-item::before {
    content: '';
    position: absolute;
    left: 0;
    top: 6px;
    bottom: 6px;
    width: 3px;
    border-radius: 0 3px 3px 0;
    background: transparent;
    transition: background 150ms;
}

.npage-item.is-unread {
    border-color: #a7d4b8;
    background: #f2fbf7;
}

.npage-item.is-unread::before {
    background: #12a26f;
}

.npage-item:hover {
    border-color: #b8d0ea;
    box-shadow: 0 2px 10px rgba(15, 23, 42, 0.06);
}

/* Event icon */
.npage-event-icon {
    width: 38px;
    height: 38px;
    border-radius: 10px;
    display: flex;
    align-items: center;
    justify-content: center;
    flex-shrink: 0;
}

.npage-event-icon svg {
    width: 18px;
    height: 18px;
}

/* Item body */
.npage-item-body {
    flex: 1;
    min-width: 0;
    border: none;
    background: transparent;
    padding: 0;
    margin: 0;
    text-align: left;
    cursor: pointer;
    display: flex;
    flex-direction: column;
    gap: 0.18rem;
    color: inherit;
}

.npage-item-top {
    display: flex;
    align-items: center;
    gap: 0.4rem;
    flex-wrap: wrap;
}

.npage-event-label {
    font-size: 0.88rem;
    font-weight: 700;
    color: #0f172a;
}

.npage-ticket-tag {
    font-size: 0.75rem;
    font-weight: 700;
    color: #1e4e8c;
    background: #e8f2fc;
    border: 1px solid #b8d5f0;
    border-radius: 999px;
    padding: 0.1rem 0.48rem;
    line-height: 1.5;
}

.npage-unread-dot {
    display: inline-block;
    width: 8px;
    height: 8px;
    border-radius: 50%;
    background: #12a26f;
    flex-shrink: 0;
    margin-left: 0.1rem;
}

.npage-message {
    margin: 0;
    font-size: 0.84rem;
    color: #334155;
    line-height: 1.45;
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
}

.npage-time {
    margin: 0;
    font-size: 0.76rem;
    color: #94a3b8;
    font-weight: 500;
}

/* Mark read button */
.npage-read-btn {
    width: 30px;
    height: 30px;
    border-radius: 50%;
    border: 1px solid #d4e2f0;
    background: #f4f8fd;
    color: #7a9fbe;
    display: flex;
    align-items: center;
    justify-content: center;
    cursor: pointer;
    flex-shrink: 0;
    transition: background 120ms, border-color 120ms, color 120ms;
    margin-top: 0.1rem;
}

.npage-read-btn svg {
    width: 14px;
    height: 14px;
}

.npage-read-btn:hover:not(:disabled) {
    background: #dcf5ec;
    border-color: #5ec4a0;
    color: #0d8a5c;
}

.npage-read-btn.is-done {
    background: #f0fdf7;
    border-color: #a7e6cc;
    color: #12a26f;
    cursor: default;
    opacity: 0.6;
}

/* ── Empty state ──────────────────────────────────────────── */

.npage-empty {
    display: flex;
    flex-direction: column;
    align-items: center;
    padding: 3rem 1rem;
    gap: 0.5rem;
    text-align: center;
}

.npage-empty-icon {
    width: 72px;
    height: 72px;
    margin-bottom: 0.5rem;
    opacity: 0.9;
}

.npage-empty-title {
    margin: 0;
    font-size: 1rem;
    font-weight: 700;
    color: #334155;
}

.npage-empty-sub {
    margin: 0;
    font-size: 0.85rem;
    color: #64748b;
}

/* ── Load more ────────────────────────────────────────────── */

.npage-more {
    display: flex;
    justify-content: center;
    padding-top: 0.2rem;
}

.npage-more-btn {
    display: inline-flex;
    align-items: center;
    gap: 0.4rem;
    font: inherit;
    font-size: 0.85rem;
    font-weight: 600;
    color: #1e4e8c;
    background: #eff6ff;
    border: 1px solid #bfdbfe;
    border-radius: 999px;
    padding: 0.45rem 1.2rem;
    cursor: pointer;
    transition: background 120ms, border-color 120ms;
}

.npage-more-btn:hover:not(:disabled) {
    background: #dbeafe;
    border-color: #93c5fd;
}

.npage-more-btn:disabled {
    opacity: 0.6;
    cursor: default;
}

/* ── Responsive ───────────────────────────────────────────── */

@media (max-width: 600px) {
    .npage-header {
        flex-direction: column;
        align-items: flex-start;
    }

    .npage-toolbar {
        flex-direction: column;
        align-items: flex-start;
        gap: 0.5rem;
    }

    .npage-message {
        white-space: normal;
    }
}
</style>
