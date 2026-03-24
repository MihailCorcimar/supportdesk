<script setup>
import { computed } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import { useAuthStore } from './stores/auth';

const route = useRoute();
const router = useRouter();
const auth = useAuthStore();

const user = computed(() => auth.state.user);
const hideShell = computed(() => Boolean(route.meta?.hideShell));
const mainLinks = computed(() => {
    const links = [
        { id: 'dashboard', label: 'Dashboard', to: { name: 'dashboard' } },
        { id: 'tickets', label: 'Tickets', to: { name: 'tickets.index' } },
        { id: 'tickets-create', label: 'Novo Ticket', to: { name: 'tickets.create' } },
    ];

    if (user.value?.can_manage_users) {
        links.push({ id: 'users', label: 'Utilizadores', to: { name: 'users.index' } });
        links.push({ id: 'entities', label: 'Entidades', to: { name: 'management', query: { tab: 'entities' } } });
        links.push({ id: 'management', label: 'Configuracao', to: { name: 'management' } });
    }

    return links;
});

const logout = async () => {
    try {
        await auth.logout();
    } finally {
        await router.push({ name: 'login' });
    }
};
</script>

<template>
    <div class="app-shell" :class="{ 'app-shell-auth': hideShell }">
        <main v-if="hideShell" class="auth-main">
            <RouterView />
        </main>

        <template v-else>
            <aside v-if="user" class="sidebar">
                <div class="sidebar-brand">Supportdesk</div>

                <div class="sidebar-user">
                    <p><span class="muted">Utilizador</span><strong>{{ user.name }}</strong></p>
                    <p><span class="muted">Perfil</span><strong>{{ user.role === 'operator' ? 'Operador' : 'Cliente' }}</strong></p>
                </div>

                <nav class="sidebar-menu">
                    <RouterLink
                        v-for="link in mainLinks"
                        :key="link.id"
                        :to="link.to"
                        class="sidebar-link"
                    >
                        {{ link.label }}
                    </RouterLink>
                </nav>

                <button type="button" class="sidebar-logout" @click="logout">Terminar sessao</button>
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
                        >
                            {{ link.label }}
                        </RouterLink>
                        <button type="button" @click="logout">Terminar sessao</button>
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
    --bg: #f5f7fb;
    --surface: #ffffff;
    --text: #0f172a;
    --muted: #475569;
    --brand: #0f766e;
    --danger: #b91c1c;
    --border: #dbe4ee;
}

* {
    box-sizing: border-box;
}

body {
    margin: 0;
    font-family: "Segoe UI", Tahoma, Geneva, Verdana, sans-serif;
    color: var(--text);
    background: linear-gradient(140deg, #f5f7fb 0%, #ecfdf5 100%);
}

.app-shell {
    min-height: 100vh;
    display: flex;
}

.app-shell-auth {
    display: block;
}

.auth-main {
    width: min(1120px, calc(100% - 2rem));
    margin: 0 auto;
    padding: 1rem 0 2rem;
}

.sidebar {
    width: 252px;
    min-height: 100vh;
    background: #f8fafc;
    border-right: 1px solid var(--border);
    padding: 1rem 0.8rem;
    position: sticky;
    top: 0;
    display: flex;
    flex-direction: column;
    gap: 1rem;
}

.sidebar-brand {
    font-size: 1.25rem;
    font-weight: 700;
    letter-spacing: 0.01em;
    padding: 0.2rem 0.4rem;
}

.sidebar-user {
    border: 1px solid var(--border);
    border-radius: 10px;
    background: #fff;
    padding: 0.55rem;
    display: grid;
    gap: 0.45rem;
}

.sidebar-user p {
    margin: 0;
    display: grid;
    gap: 0.12rem;
}

.muted {
    color: var(--muted);
    font-size: 0.82rem;
}

.sidebar-menu {
    display: grid;
    gap: 0.45rem;
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
    padding: 0.52rem 0.72rem;
    font: inherit;
    cursor: pointer;
    text-align: left;
}

.sidebar-link.router-link-active,
.mobile-menu a.router-link-active {
    background: var(--brand);
    color: #fff;
    border-color: var(--brand);
}

.sidebar-logout {
    margin-top: auto;
    background: #fff;
    color: #991b1b;
    border-color: #fecaca;
}

.content-shell {
    flex: 1;
    min-width: 0;
}

.page-content {
    width: min(1240px, calc(100% - 2rem));
    margin: 0 auto;
    padding: 1rem 0 2rem;
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
    .sidebar {
        display: none;
    }

    .mobile-topbar {
        display: block;
    }

    .page-content {
        width: min(1120px, calc(100% - 1.3rem));
        padding: 0.9rem 0 1.4rem;
    }
}
</style>
