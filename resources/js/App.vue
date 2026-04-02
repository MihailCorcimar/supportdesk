<script setup>
import { computed, onMounted, onUnmounted, ref, watch } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import api from './api/client';
import { useAuthStore } from './stores/auth';

const route = useRoute();
const router = useRouter();
const auth = useAuthStore();

const user = computed(() => auth.state.user);
const hideShell = computed(() => Boolean(route.meta?.hideShell));
const isSidebarOpen = ref(true);
const notificationUnreadCount = ref(0);
const pinnedConversations = ref([]);
const conversationsLoading = ref(false);
const pinPendingIds = ref([]);

const mainLinks = computed(() => {
    const links = [
        { id: 'dashboard', label: 'Dashboard', to: { name: 'dashboard' } },
        { id: 'tickets', label: 'Tickets', to: { name: 'tickets.index' } },
        { id: 'notifications', label: 'Notificações', to: { name: 'notifications.index' } },
    ];

    if (user.value?.can_manage_users) {
        links.push({ id: 'users', label: 'Utilizadores', to: { name: 'users.index' } });
        links.push({ id: 'entities', label: 'Entidades', to: { name: 'management', query: { tab: 'entities' } } });
        links.push({ id: 'management', label: 'Configuração', to: { name: 'management' } });
    }

    return links;
});

const isLinkActive = (link) => {
    if (link.id === 'entities') {
        return route.name === 'management' && route.query.tab === 'entities';
    }

    if (link.id === 'management') {
        return route.name === 'management' && route.query.tab !== 'entities';
    }

    if (link.id === 'users') {
        return String(route.name || '').startsWith('users.');
    }

    return route.name === link.to.name;
};

const loadRecentConversations = async () => {
    if (!user.value) {
        pinnedConversations.value = [];
        return;
    }

    conversationsLoading.value = true;

    try {
        const response = await api.get('/conversations', {
            params: {
                limit: 6,
            },
        });

        const payload = response.data?.data || {};
        pinnedConversations.value = payload.pinned || [];
    } catch {
        pinnedConversations.value = [];
    } finally {
        conversationsLoading.value = false;
    }
};

const isPinPending = (ticketId) => pinPendingIds.value.includes(ticketId);

const toggleConversationPin = async (conversation) => {
    if (!conversation?.id || isPinPending(conversation.id)) {
        return;
    }

    pinPendingIds.value.push(conversation.id);

    try {
        if (conversation.is_pinned) {
            await api.delete(`/conversations/${conversation.id}/pin`);
        } else {
            await api.post(`/conversations/${conversation.id}/pin`);
        }

        await loadRecentConversations();
    } finally {
        pinPendingIds.value = pinPendingIds.value.filter((id) => id !== conversation.id);
    }
};

const loadNotificationUnreadCount = async () => {
    if (!user.value) {
        notificationUnreadCount.value = 0;
        return;
    }

    try {
        const response = await api.get('/notifications/unread-count');
        notificationUnreadCount.value = Number(response.data?.data?.unread_count || 0);
    } catch {
        notificationUnreadCount.value = 0;
    }
};

const logout = async () => {
    try {
        await auth.logout();
    } finally {
        notificationUnreadCount.value = 0;
        await router.push({ name: 'login' });
    }
};

const toggleSidebar = () => {
    isSidebarOpen.value = !isSidebarOpen.value;
};

const onNotificationsUpdated = () => {
    loadNotificationUnreadCount();
};

const onConversationsUpdated = () => {
    loadRecentConversations();
};

watch(
    () => user.value?.id,
    () => {
        loadNotificationUnreadCount();
        loadRecentConversations();
    },
    { immediate: true }
);

watch(
    () => route.fullPath,
    () => {
        if (user.value) {
            loadNotificationUnreadCount();
            loadRecentConversations();
        }
    }
);

onMounted(() => {
    window.addEventListener('supportdesk:notifications-updated', onNotificationsUpdated);
    window.addEventListener('supportdesk:conversations-updated', onConversationsUpdated);
});

onUnmounted(() => {
    window.removeEventListener('supportdesk:notifications-updated', onNotificationsUpdated);
    window.removeEventListener('supportdesk:conversations-updated', onConversationsUpdated);
});
</script>

<template>
    <div class="app-shell" :class="{ 'app-shell-auth': hideShell }">
        <main v-if="hideShell" class="auth-main">
            <RouterView />
        </main>

        <template v-else>
            <aside v-if="user" :class="['sidebar', { 'sidebar-collapsed': !isSidebarOpen }]">
                <button
                    type="button"
                    class="sidebar-collapse-toggle"
                    :aria-label="isSidebarOpen ? 'Fechar menu lateral' : 'Abrir menu lateral'"
                    :aria-expanded="isSidebarOpen"
                    @click="toggleSidebar"
                >
                    <svg viewBox="0 0 24 24" fill="none" aria-hidden="true">
                        <path
                            :d="isSidebarOpen ? 'M14.5 6.5L9 12l5.5 5.5' : 'M9.5 6.5 15 12l-5.5 5.5'"
                            stroke="currentColor"
                            stroke-width="2.2"
                            stroke-linecap="round"
                            stroke-linejoin="round"
                        />
                    </svg>
                </button>

                <div class="brand-row">
                    <div class="brand-avatar">SD</div>
                    <div v-if="isSidebarOpen">
                        <p class="brand-title">Supportdesk</p>
                        <p class="brand-sub">{{ user.role === 'operator' ? 'Operador' : 'Cliente' }}</p>
                    </div>
                </div>

                <label v-if="isSidebarOpen" class="search-box">
                    <input type="text" placeholder="Pesquisar" disabled>
                </label>

                <nav class="sidebar-menu">
                    <RouterLink
                        v-for="link in mainLinks"
                        :key="link.id"
                        :to="link.to"
                        :class="['sidebar-link', { 'is-active': isLinkActive(link) }]"
                        :title="link.label"
                    >
                        <span class="link-icon">
                            <svg v-if="link.id === 'dashboard'" viewBox="0 0 24 24" fill="none" aria-hidden="true">
                                <rect x="3" y="3" width="8" height="8" rx="2" stroke="currentColor" stroke-width="1.8" />
                                <rect x="13" y="3" width="8" height="5" rx="2" stroke="currentColor" stroke-width="1.8" />
                                <rect x="13" y="10" width="8" height="11" rx="2" stroke="currentColor" stroke-width="1.8" />
                                <rect x="3" y="13" width="8" height="8" rx="2" stroke="currentColor" stroke-width="1.8" />
                            </svg>
                            <svg v-else-if="link.id === 'tickets'" viewBox="0 0 24 24" fill="none" aria-hidden="true">
                                <path d="M5 7.5A2.5 2.5 0 0 1 7.5 5h9A2.5 2.5 0 0 1 19 7.5v2a1.5 1.5 0 1 0 0 3v2A2.5 2.5 0 0 1 16.5 17h-9A2.5 2.5 0 0 1 5 14.5v-2a1.5 1.5 0 1 0 0-3v-2Z" stroke="currentColor" stroke-width="1.8" />
                                <path d="M12 8v8" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-dasharray="1.6 2.2" />
                            </svg>
                            <svg v-else-if="link.id === 'notifications'" viewBox="0 0 24 24" fill="none" aria-hidden="true">
                                <path d="M7 10a5 5 0 1 1 10 0v4l1.6 1.7a1 1 0 0 1-.73 1.7H6.13a1 1 0 0 1-.73-1.7L7 14v-4Z" stroke="currentColor" stroke-width="1.8" />
                                <path d="M10 18a2 2 0 0 0 4 0" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" />
                            </svg>
                            <svg v-else-if="link.id === 'users'" viewBox="0 0 24 24" fill="none" aria-hidden="true">
                                <circle cx="9" cy="8" r="3" stroke="currentColor" stroke-width="1.8" />
                                <path d="M3.5 18a5.5 5.5 0 0 1 11 0" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" />
                                <circle cx="17" cy="9" r="2.5" stroke="currentColor" stroke-width="1.8" />
                                <path d="M14.5 18a4.5 4.5 0 0 1 6 0" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" />
                            </svg>
                            <svg v-else-if="link.id === 'entities'" viewBox="0 0 24 24" fill="none" aria-hidden="true">
                                <rect x="4" y="3" width="16" height="18" rx="2.5" stroke="currentColor" stroke-width="1.8" />
                                <path d="M8 7h2M14 7h2M8 11h2M14 11h2M8 15h8" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" />
                            </svg>
                            <svg v-else viewBox="0 0 24 24" fill="none" aria-hidden="true">
                                <circle cx="12" cy="12" r="3.2" stroke="currentColor" stroke-width="1.8" />
                                <path d="M12 4.5v2.2M12 17.3v2.2M4.5 12h2.2M17.3 12h2.2M6.8 6.8l1.6 1.6M15.6 15.6l1.6 1.6M17.2 6.8l-1.6 1.6M8.4 15.6l-1.6 1.6" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" />
                            </svg>
                        </span>
                        <span v-if="isSidebarOpen" class="link-label">{{ link.label }}</span>
                        <span
                            v-if="isSidebarOpen && link.id === 'notifications' && notificationUnreadCount > 0"
                            class="link-badge"
                        >
                            {{ notificationUnreadCount > 99 ? '99+' : notificationUnreadCount }}
                        </span>
                    </RouterLink>
                </nav>

                <section v-if="isSidebarOpen" class="sidebar-section">
                    <p class="section-title">Conversas</p>
                    <p v-if="pinnedConversations.length" class="section-subtitle">Fixadas</p>
                    <ul class="conversation-list">
                        <li v-if="conversationsLoading" class="conversation-empty">A carregar...</li>
                        <li
                            v-for="conversation in pinnedConversations"
                            :key="`pinned-${conversation.id}`"
                            class="conversation-item"
                        >
                            <RouterLink
                                :to="{ name: 'tickets.show', params: { id: conversation.id } }"
                                class="conversation-link"
                            >
                                <span class="conversation-code">#{{ conversation.ticket_number }}</span>
                                <span class="conversation-subject">{{ conversation.subject }}</span>
                            </RouterLink>
                            <button
                                type="button"
                                class="pin-toggle is-pinned"
                                :disabled="isPinPending(conversation.id)"
                                title="Desafixar conversa"
                                aria-label="Desafixar conversa"
                                @click.stop.prevent="toggleConversationPin(conversation)"
                            >
                                <svg class="pin-icon" viewBox="0 0 24 24" fill="none" aria-hidden="true">
                                    <path d="M9 4h6l-1.2 5.2 3.2 2.8v1.2H7v-1.2l3.2-2.8L9 4z" stroke="currentColor" stroke-width="1.7" stroke-linejoin="round" />
                                    <path d="M12 13v7" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" />
                                </svg>
                            </button>
                        </li>
                        <li
                            v-if="!conversationsLoading && !pinnedConversations.length"
                            class="conversation-empty"
                        >
                            Sem conversas fixadas
                        </li>
                    </ul>
                </section>

                <div v-if="isSidebarOpen" class="sidebar-user-card">
                    <p><span>Utilizador</span><strong>{{ user.name }}</strong></p>
                </div>

                <button v-if="isSidebarOpen" type="button" class="sidebar-logout" @click="logout">Terminar sessão</button>
            </aside>

            <div class="content-shell">
                <header v-if="user" class="mobile-topbar">
                    <div class="mobile-topbar-title">
                        <strong>Supportdesk</strong>
                        <span>{{ user.name }}</span>
                    </div>

                    <nav class="mobile-menu">
                        <RouterLink
                            v-for="link in mainLinks"
                            :key="`mobile-${link.id}`"
                            :to="link.to"
                            :class="{ 'is-active': isLinkActive(link) }"
                        >
                            {{ link.label }}
                        </RouterLink>
                        <button type="button" @click="logout">Terminar sessão</button>
                    </nav>
                </header>

                <main class="page-content">
                    <RouterView />
                </main>
            </div>
        </template>
    </div>
</template>

<style>
:root {
    --bg: #f1f4f8;
    --surface: #ffffff;
    --surface-soft: #f7f8fb;
    --text: #161f2f;
    --muted: #5f6d80;
    --brand: #12a26f;
    --danger: #b91c1c;
    --border: #dce3ed;
}

* {
    box-sizing: border-box;
}

body {
    margin: 0;
    font-family: "Segoe UI", Tahoma, Geneva, Verdana, sans-serif;
    color: var(--text);
    background:
        radial-gradient(circle at 12% 8%, #2e3456 0%, #191e35 36%, #111427 100%);
}

.app-shell {
    min-height: 100vh;
    display: flex;
    gap: 0.9rem;
    padding: 1rem;
}

.app-shell-auth {
    display: block;
    padding: 0;
    background: linear-gradient(140deg, #f5f7fb 0%, #ecfdf5 100%);
}

.auth-main {
    width: min(1120px, calc(100% - 2rem));
    margin: 0 auto;
    padding: 1rem 0 2rem;
}

.sidebar {
    width: 262px;
    min-height: calc(100vh - 2rem);
    background: var(--surface-soft);
    border: 1px solid #2b3247;
    border-radius: 16px;
    padding: 0.95rem 0.8rem;
    position: sticky;
    top: 1rem;
    display: flex;
    flex-direction: column;
    gap: 0.85rem;
    transition: width 160ms ease, padding 160ms ease;
}

.sidebar-collapsed {
    width: 78px;
    padding: 0.95rem 0.55rem;
    align-items: center;
}

.sidebar-collapse-toggle {
    position: absolute;
    top: 48px;
    right: -16px;
    width: 32px;
    height: 32px;
    border: 1px solid #9fb0ca;
    border-radius: 999px;
    background: #f1f5ff;
    color: #42526d;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    cursor: pointer;
    z-index: 5;
    box-shadow: 0 8px 16px rgba(15, 23, 42, 0.18);
}

.sidebar-collapse-toggle:hover {
    background: #e3ebff;
    border-color: #7f91b0;
}

.sidebar-collapse-toggle svg {
    width: 18px;
    height: 18px;
}

.brand-row {
    display: flex;
    align-items: center;
    gap: 0.65rem;
}

.brand-avatar {
    width: 38px;
    height: 38px;
    border-radius: 11px;
    display: grid;
    place-items: center;
    background: #1f2a44;
    color: #fff;
    font-weight: 700;
    font-size: 0.9rem;
}

.brand-title {
    margin: 0;
    font-size: 1rem;
    font-weight: 700;
}

.brand-sub {
    margin: 0.15rem 0 0;
    color: var(--muted);
    font-size: 0.78rem;
}

.search-box input {
    width: 100%;
    border: 1px solid var(--border);
    border-radius: 10px;
    padding: 0.5rem 0.62rem;
    background: #fff;
    color: #0f172a;
}

.sidebar-menu {
    display: grid;
    gap: 0.35rem;
    width: 100%;
}

.sidebar-link,
.sidebar-logout,
.mobile-menu a,
.mobile-menu button {
    border: 1px solid var(--border);
    border-radius: 10px;
    background: #fff;
    color: var(--text);
    text-decoration: none;
    padding: 0.5rem 0.66rem;
    font: inherit;
    cursor: pointer;
    text-align: left;
    display: flex;
    align-items: center;
    gap: 0.5rem;
}

.link-icon {
    width: 16px;
    height: 16px;
    color: #64748b;
    flex: 0 0 auto;
}

.link-icon svg {
    width: 100%;
    height: 100%;
}

.link-badge {
    margin-left: auto;
    min-width: 21px;
    height: 21px;
    padding: 0 0.35rem;
    border-radius: 999px;
    background: #d21f3c;
    color: #fff;
    font-size: 0.72rem;
    font-weight: 700;
    display: inline-flex;
    align-items: center;
    justify-content: center;
}

.sidebar-link.is-active,
.mobile-menu a.is-active {
    background: #e9f9f1;
    color: #0d704e;
    border-color: #9fd9c2;
}

.sidebar-link.is-active .link-icon {
    color: var(--brand);
}

.sidebar-collapsed .sidebar-link {
    justify-content: center;
    padding: 0.5rem;
}

.sidebar-section {
    border: 1px solid var(--border);
    border-radius: 11px;
    background: #fff;
    padding: 0.6rem;
}

.section-title {
    margin: 0 0 0.4rem;
    font-size: 0.75rem;
    color: var(--muted);
    text-transform: uppercase;
    letter-spacing: 0.04em;
}

.section-subtitle {
    margin: 0.35rem 0;
    font-size: 0.7rem;
    color: #7a8ba3;
    text-transform: uppercase;
    letter-spacing: 0.03em;
}

.conversation-list {
    list-style: none;
    margin: 0;
    padding: 0;
    display: grid;
    gap: 0.35rem;
}

.conversation-item {
    margin: 0;
    display: grid;
    grid-template-columns: 1fr auto;
    gap: 0.35rem;
    align-items: center;
}

.conversation-link {
    border: 1px solid #e8edf5;
    background: #fafcff;
    border-radius: 8px;
    padding: 0.34rem 0.48rem;
    text-decoration: none;
    color: #334155;
    display: grid;
    gap: 0.08rem;
}

.conversation-link:hover {
    border-color: #c9d8ea;
    background: #f3f8ff;
}

.pin-toggle {
    border: 1px solid #d7e0ec;
    background: #f8fbff;
    border-radius: 999px;
    width: 32px;
    height: 32px;
    padding: 0;
    cursor: pointer;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    color: #60758f;
    flex-shrink: 0;
}

.pin-toggle:hover {
    border-color: #b9c8db;
    background: #eef5ff;
}

.pin-toggle.is-pinned {
    border-color: #a5d8bf;
    background: #eaf9f1;
    color: #0f8f62;
}

.pin-icon {
    width: 16px;
    height: 16px;
    transition: transform 0.18s ease;
}

.pin-toggle.is-pinned .pin-icon {
    transform: rotate(-16deg);
}

.pin-toggle:disabled {
    opacity: 0.55;
    cursor: not-allowed;
}

.conversation-code {
    font-size: 0.82rem;
    font-weight: 700;
    line-height: 1.2;
}

.conversation-subject {
    font-size: 0.76rem;
    color: #5f6d80;
    line-height: 1.2;
}

.conversation-empty {
    border: 1px dashed #d6deea;
    background: #fbfdff;
    border-radius: 8px;
    padding: 0.4rem 0.48rem;
    font-size: 0.78rem;
    color: #6b7a90;
}

.sidebar-user-card {
    border: 1px solid var(--border);
    border-radius: 10px;
    background: #fff;
    padding: 0.5rem;
}

.sidebar-user-card p {
    margin: 0;
    display: grid;
    gap: 0.1rem;
}

.sidebar-user-card span {
    color: var(--muted);
    font-size: 0.78rem;
}

.sidebar-logout {
    margin-top: auto;
    color: #8b1f1f;
    border-color: #f3c4c4;
    background: #fff6f6;
}

.content-shell {
    flex: 1;
    min-width: 0;
    border: 1px solid #2b3247;
    border-radius: 16px;
    background: #f5f7fb;
    overflow: hidden;
    display: flex;
    flex-direction: column;
}

.page-content {
    width: 100%;
    margin: 0;
    padding: 1rem;
}

.mobile-topbar {
    display: none;
    border-bottom: 1px solid var(--border);
    background: #fff;
    padding: 0.7rem 1rem;
}

.mobile-topbar-title {
    display: flex;
    justify-content: space-between;
    align-items: center;
    gap: 0.7rem;
}

.mobile-topbar-title span {
    color: var(--muted);
    font-size: 0.9rem;
}

.mobile-menu {
    margin-top: 0.65rem;
    display: flex;
    flex-wrap: wrap;
    gap: 0.45rem;
}

@media (max-width: 980px) {
    .app-shell {
        display: block;
        padding: 0;
        background: linear-gradient(140deg, #f5f7fb 0%, #ecfdf5 100%);
    }

    .sidebar {
        display: none;
    }

    .content-shell {
        border: none;
        border-radius: 0;
    }

    .sidebar-toggle {
        display: none;
    }

    .mobile-topbar {
        display: block;
    }

    .page-content {
        padding: 0.9rem 0.65rem 1.4rem;
    }
}
</style>



