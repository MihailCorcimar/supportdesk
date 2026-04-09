<script setup>
import { computed, onMounted, onUnmounted, ref, watch } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import api from './api/client';
import { useAuthStore } from './stores/auth';
import NotificationsPage from './pages/NotificationsPage.vue';

const route = useRoute();
const router = useRouter();
const auth = useAuthStore();

const user = computed(() => auth.state.user);
const hideShell = computed(() => Boolean(route.meta?.hideShell));
const isSidebarOpen = ref(true);
const isNotificationsDrawerOpen = ref(false);
const notificationUnreadCount = ref(0);
const pinnedConversations = ref([]);
const conversationsLoading = ref(false);
const pinPendingIds = ref([]);

const mainLinks = computed(() => {
    const links = [
        { id: 'dashboard', label: 'Dashboard', to: { name: 'dashboard' } },
        { id: 'tickets', label: 'Tickets', to: { name: 'tickets.index' } },
        { id: 'notifications', label: 'Notificações' },
    ];

    if (user.value?.can_manage_users) {
        links.push({ id: 'users', label: 'Utilizadores', to: { name: 'users.index' } });
        links.push({ id: 'management', label: 'Configuração', to: { name: 'management' } });
    }

    return links;
});

const isLinkActive = (link) => {
    if (link.id === 'notifications') {
        return isNotificationsDrawerOpen.value;
    }

    if (link.id === 'management') {
        return route.name === 'management';
    }

    if (link.id === 'users') {
        return String(route.name || '').startsWith('users.');
    }

    return route.name === link.to?.name;
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
        isNotificationsDrawerOpen.value = false;
        notificationUnreadCount.value = 0;
        await router.push({ name: 'login' });
    }
};

const toggleSidebar = () => {
    isSidebarOpen.value = !isSidebarOpen.value;
};

const toggleNotificationsDrawer = () => {
    if (!user.value) {
        return;
    }

    isNotificationsDrawerOpen.value = !isNotificationsDrawerOpen.value;
};

const closeNotificationsDrawer = () => {
    isNotificationsDrawerOpen.value = false;
};

const onNotificationsUpdated = () => {
    loadNotificationUnreadCount();
};

const onConversationsUpdated = () => {
    loadRecentConversations();
};

const handleGlobalKeydown = (event) => {
    if (event.key === 'Escape' && isNotificationsDrawerOpen.value) {
        event.preventDefault();
        closeNotificationsDrawer();
    }
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
        if (isNotificationsDrawerOpen.value) {
            closeNotificationsDrawer();
        }

        if (user.value) {
            loadNotificationUnreadCount();
            loadRecentConversations();
        }
    }
);

onMounted(() => {
    window.addEventListener('supportdesk:notifications-updated', onNotificationsUpdated);
    window.addEventListener('supportdesk:conversations-updated', onConversationsUpdated);
    window.addEventListener('keydown', handleGlobalKeydown);
});

onUnmounted(() => {
    window.removeEventListener('supportdesk:notifications-updated', onNotificationsUpdated);
    window.removeEventListener('supportdesk:conversations-updated', onConversationsUpdated);
    window.removeEventListener('keydown', handleGlobalKeydown);
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
                    <svg class="brand-avatar" viewBox="0 0 40 40" xmlns="http://www.w3.org/2000/svg" aria-label="Supportdesk">
                        <rect width="40" height="40" rx="12" fill="#1f2a44"/>
                        <path d="M12.5 20.5c0-4.1 3.4-7.5 7.5-7.5s7.5 3.4 7.5 7.5" stroke="#12a26f" stroke-width="2.3" stroke-linecap="round" fill="none"/>
                        <rect x="10" y="19.5" width="4.5" height="6.5" rx="2.25" fill="#12a26f"/>
                        <rect x="25.5" y="19.5" width="4.5" height="6.5" rx="2.25" fill="#12a26f"/>
                        <path d="M28 25.5c0 3.8-3.6 6.5-8 6.5" stroke="#12a26f" stroke-width="2" stroke-linecap="round" fill="none"/>
                        <circle cx="20" cy="32" r="1.5" fill="#12a26f"/>
                    </svg>
                    <div v-if="isSidebarOpen">
                        <p class="brand-title">Supportdesk</p>
                        <p class="brand-sub">{{ user.role === 'operator' ? 'Operador' : 'Cliente' }}</p>
                    </div>
                </div>

                <nav class="sidebar-menu">
                    <template v-for="link in mainLinks" :key="link.id">
                        <button
                            v-if="link.id === 'notifications'"
                            type="button"
                            :class="['sidebar-link', { 'is-active': isLinkActive(link) }]"
                            :title="link.label"
                            :aria-expanded="isNotificationsDrawerOpen"
                            @click="toggleNotificationsDrawer"
                        >
                            <span class="link-icon">
                                <svg viewBox="0 0 24 24" fill="none" aria-hidden="true">
                                    <path d="M7 10a5 5 0 1 1 10 0v4l1.6 1.7a1 1 0 0 1-.73 1.7H6.13a1 1 0 0 1-.73-1.7L7 14v-4Z" stroke="currentColor" stroke-width="1.8" />
                                    <path d="M10 18a2 2 0 0 0 4 0" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" />
                                </svg>
                            </span>
                            <span v-if="isSidebarOpen" class="link-label">{{ link.label }}</span>
                            <span
                                v-if="isSidebarOpen && notificationUnreadCount > 0"
                                class="link-badge"
                            >
                                {{ notificationUnreadCount > 99 ? '99+' : notificationUnreadCount }}
                            </span>
                        </button>

                        <RouterLink
                            v-else
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
                            <svg v-else-if="link.id === 'users'" viewBox="0 0 24 24" fill="none" aria-hidden="true">
                                <circle cx="9" cy="8" r="3" stroke="currentColor" stroke-width="1.8" />
                                <path d="M3.5 18a5.5 5.5 0 0 1 11 0" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" />
                                <circle cx="17" cy="9" r="2.5" stroke="currentColor" stroke-width="1.8" />
                                <path d="M14.5 18a4.5 4.5 0 0 1 6 0" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" />
                            </svg>
                            <svg v-else viewBox="0 0 24 24" fill="none" aria-hidden="true">
                                <circle cx="12" cy="12" r="3.2" stroke="currentColor" stroke-width="1.8" />
                                <path d="M12 4.5v2.2M12 17.3v2.2M4.5 12h2.2M17.3 12h2.2M6.8 6.8l1.6 1.6M15.6 15.6l1.6 1.6M17.2 6.8l-1.6 1.6M8.4 15.6l-1.6 1.6" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" />
                            </svg>
                        </span>
                        <span v-if="isSidebarOpen" class="link-label">{{ link.label }}</span>
                        </RouterLink>
                    </template>
                </nav>

                <div class="sidebar-divider"></div>

                <section v-if="isSidebarOpen" class="sidebar-section">
                    <div class="sidebar-section-label">Fixadas</div>
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

                <div class="sidebar-bottom">
                <div v-if="isSidebarOpen" class="sidebar-user-card">
                    <div class="sidebar-user-avatar">{{ user.name?.slice(0, 2) }}</div>
                    <p>
                        <span>{{ user.role === 'operator' ? 'Operador' : 'Cliente' }}</span>
                        <strong>{{ user.name }}</strong>
                    </p>
                </div>

                <button type="button" class="sidebar-logout" @click="logout">
                    <span class="sidebar-logout-icon" aria-hidden="true">
                        <svg viewBox="0 0 24 24" fill="none">
                            <path d="M10 5.2a7 7 0 1 0 7.9 11" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" />
                            <path d="M14 12h7m0 0-2.5-2.5M21 12l-2.5 2.5" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" />
                        </svg>
                    </span>
                    <span v-if="isSidebarOpen" class="sidebar-logout-copy">
                        <span class="sidebar-logout-label">Terminar sessão</span>
                        <span class="sidebar-logout-hint">Sair da conta atual</span>
                    </span>
                </button>
                </div>
            </aside>

            <div class="content-shell">
                <Transition name="notifications-drawer">
                    <div
                        v-if="user && isNotificationsDrawerOpen"
                        class="notifications-drawer-layer"
                        @click.self="closeNotificationsDrawer"
                    >
                        <aside class="notifications-drawer" role="dialog" aria-modal="true" aria-label="Notificações">
                            <button
                                type="button"
                                class="notifications-drawer-close"
                                aria-label="Fechar notificações"
                                @click="closeNotificationsDrawer"
                            >
                                ×
                            </button>
                            <NotificationsPage />
                        </aside>
                    </div>
                </Transition>

                <header v-if="user" class="mobile-topbar">
                    <div class="mobile-topbar-title">
                        <span class="mobile-brand">
                            <svg class="mobile-brand-logo" viewBox="0 0 40 40" xmlns="http://www.w3.org/2000/svg" aria-label="Supportdesk">
                                <rect width="40" height="40" rx="12" fill="#1f2a44"/>
                                <path d="M12.5 20.5c0-4.1 3.4-7.5 7.5-7.5s7.5 3.4 7.5 7.5" stroke="#12a26f" stroke-width="2.3" stroke-linecap="round" fill="none"/>
                                <rect x="10" y="19.5" width="4.5" height="6.5" rx="2.25" fill="#12a26f"/>
                                <rect x="25.5" y="19.5" width="4.5" height="6.5" rx="2.25" fill="#12a26f"/>
                                <path d="M28 25.5c0 3.8-3.6 6.5-8 6.5" stroke="#12a26f" stroke-width="2" stroke-linecap="round" fill="none"/>
                                <circle cx="20" cy="32" r="1.5" fill="#12a26f"/>
                            </svg>
                            <strong>Supportdesk</strong>
                        </span>
                        <span>{{ user.name }}</span>
                    </div>

                    <nav class="mobile-menu">
                        <template v-for="link in mainLinks" :key="`mobile-${link.id}`">
                            <button
                                v-if="link.id === 'notifications'"
                                type="button"
                                :class="{ 'is-active': isLinkActive(link) }"
                                @click="toggleNotificationsDrawer"
                            >
                                {{ link.label }}
                                <span v-if="notificationUnreadCount > 0">({{ notificationUnreadCount > 99 ? '99+' : notificationUnreadCount }})</span>
                            </button>
                            <RouterLink
                                v-else
                                :to="link.to"
                                :class="{ 'is-active': isLinkActive(link) }"
                            >
                                {{ link.label }}
                            </RouterLink>
                        </template>
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

/* ── Sidebar ──────────────────────────────────────────────── */

.sidebar {
    width: 240px;
    min-height: calc(100vh - 2rem);
    background: #0f1526;
    border: 1px solid rgba(255, 255, 255, 0.07);
    border-radius: 16px;
    padding: 1rem 0.75rem;
    position: sticky;
    top: 1rem;
    display: flex;
    flex-direction: column;
    gap: 0.5rem;
    transition: width 160ms ease, padding 160ms ease;
    flex-shrink: 0;
}

.sidebar-collapsed {
    width: 70px;
    padding: 1rem 0.6rem;
    align-items: center;
}

.sidebar-collapse-toggle {
    position: absolute;
    top: 52px;
    right: -14px;
    width: 28px;
    height: 28px;
    border: 1px solid rgba(255, 255, 255, 0.15);
    border-radius: 999px;
    background: #1e2d48;
    color: #94adc8;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    cursor: pointer;
    z-index: 5;
    box-shadow: 0 4px 14px rgba(0, 0, 0, 0.35);
    transition: background 120ms, border-color 120ms, color 120ms;
}

.sidebar-collapse-toggle:hover {
    background: #263852;
    border-color: rgba(255, 255, 255, 0.28);
    color: #c8ddf0;
}

.sidebar-collapse-toggle svg {
    width: 16px;
    height: 16px;
}

/* ── Brand ────────────────────────────────────────────────── */

.brand-row {
    display: flex;
    align-items: center;
    gap: 0.65rem;
    padding: 0.1rem 0.3rem 0.6rem;
    border-bottom: 1px solid rgba(255, 255, 255, 0.06);
    margin-bottom: 0.3rem;
}

.brand-avatar {
    width: 36px;
    height: 36px;
    border-radius: 10px;
    flex-shrink: 0;
    display: block;
}

.mobile-brand {
    display: flex;
    align-items: center;
    gap: 0.5rem;
}

.mobile-brand-logo {
    width: 28px;
    height: 28px;
    border-radius: 8px;
    flex-shrink: 0;
}

.brand-title {
    margin: 0;
    font-size: 0.95rem;
    font-weight: 700;
    color: #e8f0fa;
    letter-spacing: -0.01em;
}

.brand-sub {
    margin: 0.1rem 0 0;
    color: #4e6582;
    font-size: 0.73rem;
    font-weight: 500;
}

/* ── Nav menu ─────────────────────────────────────────────── */

.sidebar-menu {
    display: flex;
    flex-direction: column;
    gap: 0.18rem;
    width: 100%;
}

.sidebar-link,
.mobile-menu a,
.mobile-menu button {
    border: 1px solid transparent;
    border-radius: 9px;
    background: transparent;
    color: #7a97b8;
    text-decoration: none;
    padding: 0.52rem 0.6rem;
    font: inherit;
    font-size: 0.875rem;
    font-weight: 500;
    cursor: pointer;
    text-align: left;
    display: flex;
    align-items: center;
    gap: 0.6rem;
    transition: background 120ms, color 120ms, border-color 120ms;
    line-height: 1;
    width: 100%;
}

.sidebar-link:hover,
.mobile-menu a:hover,
.mobile-menu button:hover {
    background: rgba(255, 255, 255, 0.05);
    color: #c8ddf0;
    border-color: transparent;
}

.link-icon {
    width: 18px;
    height: 18px;
    flex: 0 0 auto;
    display: flex;
    align-items: center;
    justify-content: center;
    opacity: 0.7;
}

.link-icon svg {
    width: 18px;
    height: 18px;
}

.link-label {
    flex: 1;
    min-width: 0;
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
}

.link-badge {
    margin-left: auto;
    min-width: 19px;
    height: 19px;
    padding: 0 0.32rem;
    border-radius: 999px;
    background: #e03050;
    color: #fff;
    font-size: 0.7rem;
    font-weight: 700;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    flex-shrink: 0;
}

.sidebar-link.is-active,
.mobile-menu a.is-active,
.mobile-menu button.is-active {
    background: rgba(18, 162, 111, 0.14);
    color: #4dd4a0;
    border-color: rgba(18, 162, 111, 0.22);
    font-weight: 600;
}

.sidebar-link.is-active .link-icon {
    opacity: 1;
}

.sidebar-collapsed .sidebar-link {
    justify-content: center;
    padding: 0.55rem;
}

/* ── Divider ──────────────────────────────────────────────── */

.sidebar-divider {
    height: 1px;
    background: rgba(255, 255, 255, 0.06);
    margin: 0.2rem 0;
}

/* ── Section divider label ────────────────────────────────── */

.sidebar-section-label {
    display: flex;
    align-items: center;
    gap: 0.4rem;
    padding: 0.6rem 0.3rem 0.25rem;
    font-size: 0.68rem;
    font-weight: 700;
    color: #3a516e;
    text-transform: uppercase;
    letter-spacing: 0.07em;
}

.sidebar-section-label::after {
    content: '';
    flex: 1;
    height: 1px;
    background: rgba(255, 255, 255, 0.05);
}

/* ── Conversations ────────────────────────────────────────── */

.sidebar-section {
    border: none;
    border-radius: 0;
    background: transparent;
    padding: 0;
}

.section-title {
    display: none;
}

.section-subtitle {
    display: none;
}

.conversation-list {
    list-style: none;
    margin: 0;
    padding: 0;
    display: flex;
    flex-direction: column;
    gap: 0.15rem;
}

.conversation-item {
    margin: 0;
    display: grid;
    grid-template-columns: 1fr auto;
    gap: 0.3rem;
    align-items: center;
}

.conversation-link {
    border: 1px solid transparent;
    background: transparent;
    border-radius: 8px;
    padding: 0.3rem 0.5rem;
    text-decoration: none;
    color: #6688aa;
    display: grid;
    gap: 0.05rem;
    transition: background 110ms, color 110ms;
    min-width: 0;
}

.conversation-link:hover {
    background: rgba(255, 255, 255, 0.05);
    color: #a8c4dc;
}

.pin-toggle {
    border: 1px solid transparent;
    background: transparent;
    border-radius: 6px;
    width: 26px;
    height: 26px;
    padding: 0;
    cursor: pointer;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    color: #3a5170;
    flex-shrink: 0;
    transition: background 110ms, color 110ms;
}

.pin-toggle:hover {
    background: rgba(255, 255, 255, 0.07);
    color: #6a9ac0;
}

.pin-toggle.is-pinned {
    color: #12a26f;
}

.pin-toggle.is-pinned:hover {
    background: rgba(18, 162, 111, 0.12);
    color: #2ac88e;
}

.pin-icon {
    width: 14px;
    height: 14px;
    transition: transform 0.18s ease;
}

.pin-toggle.is-pinned .pin-icon {
    transform: rotate(-16deg);
}

.pin-toggle:disabled {
    opacity: 0.4;
    cursor: not-allowed;
}

.conversation-code {
    font-size: 0.75rem;
    font-weight: 700;
    line-height: 1.2;
    color: #8aaec8;
}

.conversation-subject {
    font-size: 0.72rem;
    color: #4a6882;
    line-height: 1.2;
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
}

.conversation-empty {
    padding: 0.35rem 0.5rem;
    font-size: 0.75rem;
    color: #35506a;
    font-style: italic;
}

/* ── Bottom block ─────────────────────────────────────────── */

.sidebar-bottom {
    margin-top: auto;
    display: flex;
    flex-direction: column;
    gap: 0.2rem;
}

/* ── User card ────────────────────────────────────────────── */

.sidebar-user-card {
    margin-top: 0;
    border: 1px solid rgba(255, 255, 255, 0.06);
    border-radius: 10px;
    background: rgba(255, 255, 255, 0.03);
    padding: 0.55rem 0.65rem;
    display: flex;
    align-items: center;
    gap: 0.55rem;
}

.sidebar-user-avatar {
    width: 30px;
    height: 30px;
    border-radius: 8px;
    background: #1e3a5f;
    border: 1px solid rgba(255, 255, 255, 0.1);
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 0.72rem;
    font-weight: 800;
    color: #7ab8d8;
    flex-shrink: 0;
    text-transform: uppercase;
    letter-spacing: 0.02em;
}

.sidebar-user-card p {
    margin: 0;
    display: grid;
    gap: 0.05rem;
    min-width: 0;
}

.sidebar-user-card span {
    color: #3d5878;
    font-size: 0.7rem;
    font-weight: 600;
    text-transform: uppercase;
    letter-spacing: 0.05em;
}

.sidebar-user-card strong {
    color: #a8c4dc;
    font-size: 0.82rem;
    font-weight: 600;
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
}

/* ── Logout ───────────────────────────────────────────────── */

.sidebar-logout {
    width: 100%;
    justify-content: flex-start;
    gap: 0.55rem;
    padding: 0.5rem 0.6rem;
    color: #7a97b8;
    border: 1px solid transparent;
    background: transparent;
    border-radius: 9px;
    font: inherit;
    font-size: 0.875rem;
    font-weight: 500;
    cursor: pointer;
    transition: background 120ms, color 120ms;
    text-align: left;
    display: flex;
    align-items: center;
}

.sidebar-logout:hover {
    background: rgba(220, 50, 60, 0.1);
    color: #f08080;
    border-color: rgba(220, 50, 60, 0.15);
}

.sidebar-logout:focus-visible {
    outline: none;
    box-shadow: 0 0 0 2px rgba(220, 50, 60, 0.3);
}

.sidebar-logout-icon {
    width: 26px;
    height: 26px;
    border-radius: 7px;
    background: rgba(255, 255, 255, 0.04);
    display: inline-flex;
    align-items: center;
    justify-content: center;
    flex: 0 0 auto;
}

.sidebar-logout-icon svg {
    width: 15px;
    height: 15px;
}

.sidebar-logout-copy {
    display: grid;
    gap: 0.02rem;
    min-width: 0;
}

.sidebar-logout-label {
    font-weight: 600;
    line-height: 1.15;
}

.sidebar-logout-hint {
    font-size: 0.7rem;
    color: #3d5878;
    line-height: 1.1;
}

.sidebar-collapsed .sidebar-logout {
    justify-content: center;
    padding: 0.5rem;
}

.sidebar-collapsed .sidebar-bottom {
    width: 100%;
    align-items: center;
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
    position: relative;
}

.notifications-drawer-layer {
    position: absolute;
    inset: 0;
    z-index: 40;
    background: rgba(15, 23, 42, 0.2);
    display: flex;
    align-items: stretch;
    justify-content: flex-start;
}

.notifications-drawer {
    width: min(430px, 100%);
    height: 100%;
    background: #f5f7fb;
    border-right: 1px solid #d2ddec;
    box-shadow: 18px 0 34px rgba(15, 23, 42, 0.22);
    overflow: auto;
    position: relative;
}

.notifications-drawer-close {
    position: sticky;
    top: 0.6rem;
    right: 0.6rem;
    margin-left: auto;
    margin-right: 0.6rem;
    margin-top: 0.6rem;
    width: 32px;
    height: 32px;
    border: 1px solid #c4d3e6;
    border-radius: 999px;
    background: #fff;
    color: #334155;
    font-size: 1.1rem;
    line-height: 1;
    cursor: pointer;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    z-index: 2;
}

.notifications-drawer-close:hover {
    background: #f3f7fd;
    border-color: #9eb5d0;
}

.notifications-drawer .notifications-page {
    padding-top: 0.2rem;
}

.notifications-drawer-enter-active,
.notifications-drawer-leave-active {
    transition: opacity 180ms ease;
}

.notifications-drawer-enter-active .notifications-drawer,
.notifications-drawer-leave-active .notifications-drawer {
    transition: transform 220ms ease;
}

.notifications-drawer-enter-from,
.notifications-drawer-leave-to {
    opacity: 0;
}

.notifications-drawer-enter-from .notifications-drawer,
.notifications-drawer-leave-to .notifications-drawer {
    transform: translateX(-24px);
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




