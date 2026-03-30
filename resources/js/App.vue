<script setup>
import { computed, ref } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import { useAuthStore } from './stores/auth';

const route = useRoute();
const router = useRouter();
const auth = useAuthStore();

const user = computed(() => auth.state.user);
const hideShell = computed(() => Boolean(route.meta?.hideShell));
const isSidebarOpen = ref(true);
const mainLinks = computed(() => {
    const links = [
        { id: 'dashboard', label: 'Dashboard', to: { name: 'dashboard' } },
        { id: 'tickets', label: 'Tickets', to: { name: 'tickets.index' } },
    ];

    if (user.value?.can_manage_users) {
        links.push({ id: 'users', label: 'Utilizadores', to: { name: 'users.index' } });
        links.push({ id: 'entities', label: 'Entidades', to: { name: 'management', query: { tab: 'entities' } } });
        links.push({ id: 'management', label: 'Configuração', to: { name: 'management' } });
    }

    return links;
});

const quickConversations = [
    '#TC-192 produção',
    '#TC-191 pagamento',
    '#TC-188 contrato',
];

const isLinkActive = (link) => {
    if (link.id === 'entities') {
        return route.name === 'management' && route.query.tab === 'entities';
    }

    if (link.id === 'management') {
        return route.name === 'management' && route.query.tab !== 'entities';
    }

    return route.name === link.to.name;
};

const logout = async () => {
    try {
        await auth.logout();
    } finally {
        await router.push({ name: 'login' });
    }
};

const toggleSidebar = () => {
    isSidebarOpen.value = !isSidebarOpen.value;
};
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
                    </RouterLink>
                </nav>

                <section v-if="isSidebarOpen" class="sidebar-section">
                    <p class="section-title">Conversas</p>
                    <ul class="conversation-list">
                        <li v-for="conversation in quickConversations" :key="conversation">{{ conversation }}</li>
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

.conversation-list {
    list-style: none;
    margin: 0;
    padding: 0;
    display: grid;
    gap: 0.35rem;
}

.conversation-list li {
    border: 1px solid #e8edf5;
    background: #fafcff;
    border-radius: 8px;
    padding: 0.34rem 0.48rem;
    font-size: 0.82rem;
    color: #334155;
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
