<script setup>
import { computed } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import { useAuthStore } from './stores/auth';

const route = useRoute();
const router = useRouter();
const auth = useAuthStore();

const user = computed(() => auth.state.user);
const hideShell = computed(() => Boolean(route.meta?.hideShell));

const logout = async () => {
    try {
        await auth.logout();
    } finally {
        await router.push({ name: 'login' });
    }
};
</script>

<template>
    <div class="app-shell">
        <header v-if="!hideShell" class="topbar">
            <div class="container topbar-inner">
                <strong class="brand">Supportdesk</strong>

                <nav class="menu" v-if="user">
                    <RouterLink :to="{ name: 'dashboard' }">Dashboard</RouterLink>
                    <RouterLink :to="{ name: 'tickets.index' }">Tickets</RouterLink>
                    <RouterLink :to="{ name: 'tickets.create' }">Novo Ticket</RouterLink>
                    <RouterLink v-if="user.can_manage_users" :to="{ name: 'users.index' }">Utilizadores</RouterLink>
                    <RouterLink v-if="user.can_manage_users" :to="{ name: 'management' }">Configuracao</RouterLink>
                    <button type="button" @click="logout">Terminar sessao</button>
                </nav>
            </div>
        </header>

        <main class="container page-content">
            <p v-if="user && !hideShell" class="user-line">
                Utilizador: <strong>{{ user.name }}</strong>
                | Perfil: <strong>{{ user.role === 'operator' ? 'Operador' : 'Cliente' }}</strong>
            </p>

            <RouterView />
        </main>
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

.container {
    width: min(1120px, calc(100% - 2rem));
    margin: 0 auto;
}

.topbar {
    background: var(--surface);
    border-bottom: 1px solid var(--border);
    position: sticky;
    top: 0;
    z-index: 20;
}

.topbar-inner {
    min-height: 68px;
    display: flex;
    justify-content: space-between;
    align-items: center;
    gap: 1rem;
}

.brand {
    font-size: 1.1rem;
    letter-spacing: 0.02em;
}

.menu {
    display: flex;
    flex-wrap: wrap;
    align-items: center;
    gap: 0.5rem;
}

.menu a,
.menu button {
    border: 1px solid var(--border);
    border-radius: 8px;
    background: #fff;
    color: var(--text);
    text-decoration: none;
    padding: 0.42rem 0.72rem;
    font: inherit;
    cursor: pointer;
}

.menu a.router-link-active {
    background: var(--brand);
    color: #fff;
    border-color: var(--brand);
}

.page-content {
    padding: 1rem 0 2rem;
}

.user-line {
    margin-top: 0;
    color: var(--muted);
}
</style>
