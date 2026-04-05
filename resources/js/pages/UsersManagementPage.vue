<script setup>
import { computed, onBeforeUnmount, onMounted, reactive, ref } from 'vue';
import { useRouter } from 'vue-router';
import api from '../api/client';
import UserEditModal from '../components/UserEditModal.vue';

const router = useRouter();
const loading = ref(true);
const saving = ref(false);
const error = ref('');
const success = ref('');

const users = ref([]);
const manageableInboxes = ref([]);
const entities = ref([]);
const canSetAdmin = ref(false);
const search = ref('');
const roleFilter = ref('');
const sortBy = ref('name');
const sortDir = ref('asc');
const showFiltersMenu = ref(false);
const showCreateUserModal = ref(false);
const showEditUserModal = ref(false);

const createForm = reactive({
    name: '',
    email: '',
    role: 'operator',
    entity_id: '',
    contact_name: '',
    inbox_ids: [],
    manager_inbox_ids: [],
    is_active: true,
    is_admin: false,
});

const editUserId = ref(null);
const editUserRole = ref('operator');
const editForm = reactive({
    name: '',
    email: '',
    is_active: true,
    is_admin: false,
    contact_name: '',
    inbox_ids: [],
    manager_inbox_ids: [],
});
const latestInviteUrl = ref('');
const openActionsMenuUserId = ref(null);
const activeFilterCount = computed(() => {
    let count = 0;

    if (search.value.trim() !== '') {
        count += 1;
    }

    if (roleFilter.value) {
        count += 1;
    }

    return count;
});

const isOperatorForm = computed(() => createForm.role === 'operator');
const isOperatorEditForm = computed(() => editUserRole.value === 'operator');
const isClientEditForm = computed(() => editUserRole.value === 'client');

const fetchMeta = async () => {
    const response = await api.get('/users/meta');
    manageableInboxes.value = response.data.data.manageable_inboxes;
    entities.value = response.data.data.entities;
    canSetAdmin.value = Boolean(response.data.data.can_set_admin);
};

const fetchUsers = async () => {
    const params = {};

    if (search.value.trim() !== '') {
        params.search = search.value.trim();
    }

    if (roleFilter.value) {
        params.role = roleFilter.value;
    }

    params.sort_by = sortBy.value;
    params.sort_dir = sortDir.value;

    const response = await api.get('/users', { params });
    users.value = response.data.data;
};

const toggleSort = async (field) => {
    if (sortBy.value === field) {
        sortDir.value = sortDir.value === 'asc' ? 'desc' : 'asc';
    } else {
        sortBy.value = field;
        sortDir.value = field === 'name' || field === 'email' ? 'asc' : 'desc';
    }

    await fetchUsers();
};

const sortState = (field) => {
    if (sortBy.value !== field) return 'none';
    return sortDir.value === 'asc' ? 'asc' : 'desc';
};

const userInitials = (fullName) => {
    const value = String(fullName || '').trim();
    if (!value) return 'U';

    const parts = value.split(/\s+/).filter(Boolean);
    return parts.slice(0, 2).map((part) => (part[0] || '').toUpperCase()).join('') || 'U';
};

const accessSummary = (user) => {
    if (user.role === 'operator') {
        if (!user.inboxes?.length) return 'Sem inboxes';

        return user.inboxes
            .map((inbox) => `${inbox.name}${inbox.can_manage_users ? ' (gest\u00E3o)' : ''}`)
            .join(' | ');
    }

    return user.primary_contact?.entity?.name || 'Sem entidade';
};

const load = async () => {
    loading.value = true;
    error.value = '';

    try {
        await fetchMeta();
        await fetchUsers();
    } catch (exception) {
        error.value = exception?.response?.data?.message || 'N\u00E3o foi poss\u00EDvel carregar utilizadores.';
    } finally {
        loading.value = false;
    }
};

const toggleInbox = (id) => {
    const value = Number(id);

    if (createForm.inbox_ids.includes(value)) {
        createForm.inbox_ids = createForm.inbox_ids.filter((item) => item !== value);
        createForm.manager_inbox_ids = createForm.manager_inbox_ids.filter((item) => item !== value);
        return;
    }

    createForm.inbox_ids = [...createForm.inbox_ids, value];
};

const toggleManagerInbox = (id) => {
    const value = Number(id);

    if (!createForm.inbox_ids.includes(value)) {
        return;
    }

    if (createForm.manager_inbox_ids.includes(value)) {
        createForm.manager_inbox_ids = createForm.manager_inbox_ids.filter((item) => item !== value);
        return;
    }

    createForm.manager_inbox_ids = [...createForm.manager_inbox_ids, value];
};

const resetCreateForm = () => {
    createForm.name = '';
    createForm.email = '';
    createForm.role = 'operator';
    createForm.entity_id = '';
    createForm.contact_name = '';
    createForm.inbox_ids = [];
    createForm.manager_inbox_ids = [];
    createForm.is_active = true;
    createForm.is_admin = false;
};

const openCreateUserModal = () => {
    resetCreateForm();
    error.value = '';
    success.value = '';
    showCreateUserModal.value = true;
};

const closeCreateUserModal = () => {
    showCreateUserModal.value = false;
};

const toggleActionsMenu = (userId) => {
    openActionsMenuUserId.value = openActionsMenuUserId.value === userId ? null : userId;
};

const closeActionsMenu = () => {
    openActionsMenuUserId.value = null;
};

const closeActionsMenuOnOutsideClick = (event) => {
    if (openActionsMenuUserId.value === null) return;

    const target = event.target;
    if (!(target instanceof HTMLElement)) return;

    if (!target.closest('.actions-menu')) {
        closeActionsMenu();
    }
};

const toggleFiltersMenu = () => {
    showFiltersMenu.value = !showFiltersMenu.value;
};

const closeFiltersMenu = () => {
    showFiltersMenu.value = false;
};

const closeFiltersMenuOnOutsideClick = (event) => {
    if (!showFiltersMenu.value) return;

    const target = event.target;
    if (!(target instanceof HTMLElement)) return;

    if (!target.closest('.user-filters-menu')) {
        closeFiltersMenu();
    }
};

const applyUsersFilters = async () => {
    await fetchUsers();
    closeFiltersMenu();
};

const clearUsersFilters = async () => {
    search.value = '';
    roleFilter.value = '';
    await fetchUsers();
    closeFiltersMenu();
};

const createUser = async () => {
    saving.value = true;
    error.value = '';
    success.value = '';
    latestInviteUrl.value = '';

    try {
        const payload = {
            name: createForm.name,
            email: createForm.email,
            role: createForm.role,
            is_active: createForm.is_active,
        };

        if (createForm.role === 'operator') {
            payload.inbox_ids = createForm.inbox_ids;
            payload.manager_inbox_ids = createForm.manager_inbox_ids;
            payload.is_admin = createForm.is_admin;
        } else {
            payload.entity_id = Number(createForm.entity_id);
            payload.contact_name = createForm.contact_name || createForm.name;
        }

        const response = await api.post('/users', payload);

        latestInviteUrl.value = response.data.data.invite.url;
        success.value = 'Utilizador criado. Convite gerado.';

        resetCreateForm();
        showCreateUserModal.value = false;
        await fetchUsers();
    } catch (exception) {
        error.value = exception?.response?.data?.message
            || Object.values(exception?.response?.data?.errors || {})?.[0]?.[0]
            || 'N\u00E3o foi poss\u00EDvel criar o utilizador.';
    } finally {
        saving.value = false;
    }
};

const toggleActive = async (user) => {
    error.value = '';
    success.value = '';

    try {
        await api.patch(`/users/${user.id}`, {
            is_active: !user.is_active,
        });

        success.value = 'Estado do utilizador atualizado.';
        await fetchUsers();
    } catch (exception) {
        error.value = exception?.response?.data?.message || 'N\u00E3o foi poss\u00EDvel atualizar o utilizador.';
    }
};
const toggleAdmin = async (user) => {
    error.value = '';
    success.value = '';

    try {
        await api.patch(`/users/${user.id}`, {
            is_admin: !user.is_admin,
        });

        success.value = 'Permiss\u00E3o de admin atualizada.';
        await fetchUsers();
    } catch (exception) {
        error.value = exception?.response?.data?.message || 'N\u00E3o foi poss\u00EDvel atualizar permiss\u00E3o de admin.';
    }
};

const resetEditForm = () => {
    editUserId.value = null;
    editUserRole.value = 'operator';
    editForm.name = '';
    editForm.email = '';
    editForm.is_active = true;
    editForm.is_admin = false;
    editForm.contact_name = '';
    editForm.inbox_ids = [];
    editForm.manager_inbox_ids = [];
};

const openEditUserModal = (user) => {
    resetEditForm();
    editUserId.value = user.id;
    editUserRole.value = user.role;
    editForm.name = user.name || '';
    editForm.email = user.email || '';
    editForm.is_active = Boolean(user.is_active);
    editForm.is_admin = Boolean(user.is_admin);
    editForm.contact_name = user.primary_contact?.name || user.name || '';
    editForm.inbox_ids = (user.inboxes || []).map((inbox) => Number(inbox.id));
    editForm.manager_inbox_ids = (user.inboxes || [])
        .filter((inbox) => inbox.can_manage_users)
        .map((inbox) => Number(inbox.id));
    showEditUserModal.value = true;
};

const closeEditUserModal = () => {
    showEditUserModal.value = false;
    resetEditForm();
};

const toggleEditInbox = (id) => {
    const value = Number(id);

    if (editForm.inbox_ids.includes(value)) {
        editForm.inbox_ids = editForm.inbox_ids.filter((item) => item !== value);
        editForm.manager_inbox_ids = editForm.manager_inbox_ids.filter((item) => item !== value);
        return;
    }

    editForm.inbox_ids = [...editForm.inbox_ids, value];
};

const toggleEditManagerInbox = (id) => {
    const value = Number(id);

    if (!editForm.inbox_ids.includes(value)) {
        return;
    }

    if (editForm.manager_inbox_ids.includes(value)) {
        editForm.manager_inbox_ids = editForm.manager_inbox_ids.filter((item) => item !== value);
        return;
    }

    editForm.manager_inbox_ids = [...editForm.manager_inbox_ids, value];
};

const saveEditedUser = async () => {
    if (!editUserId.value) {
        return;
    }

    error.value = '';
    success.value = '';
    saving.value = true;

    try {
        const payload = {
            name: editForm.name,
            is_active: editForm.is_active,
        };

        if (isClientEditForm.value) {
            payload.contact_name = editForm.contact_name;
        }

        if (isOperatorEditForm.value && canSetAdmin.value) {
            payload.is_admin = editForm.is_admin;
        }

        await api.patch(`/users/${editUserId.value}`, payload);

        if (isOperatorEditForm.value) {
            await api.patch(`/users/${editUserId.value}/inboxes`, {
                inbox_ids: editForm.inbox_ids,
                manager_inbox_ids: editForm.manager_inbox_ids,
            });
        }

        success.value = 'Utilizador atualizado com sucesso.';
        closeEditUserModal();
        await fetchUsers();
    } catch (exception) {
        error.value = exception?.response?.data?.message
            || Object.values(exception?.response?.data?.errors || {})?.[0]?.[0]
            || 'N\u00E3o foi poss\u00EDvel atualizar utilizador.';
    } finally {
        saving.value = false;
    }
};

const regenerateInvite = async (user) => {
    error.value = '';
    success.value = '';

    try {
        const response = await api.post(`/users/${user.id}/invite`);
        latestInviteUrl.value = response.data.data.url;
        success.value = 'Novo convite gerado com sucesso.';
    } catch (exception) {
        error.value = exception?.response?.data?.message || 'N\u00E3o foi poss\u00EDvel gerar convite.';
    }
};

const deleteUser = async (user) => {
    if (!window.confirm(`Eliminar utilizador ${user.name}?`)) {
        return;
    }

    error.value = '';
    success.value = '';

    try {
        await api.delete(`/users/${user.id}`);
        success.value = 'Utilizador eliminado com sucesso.';
        await fetchUsers();
    } catch (exception) {
        error.value = exception?.response?.data?.message || 'N\u00E3o foi poss\u00EDvel eliminar utilizador.';
    }
};

const goToUserTickets = async (user) => {
    await router.push({
        name: 'tickets.index',
        query: {
            created_by_user_id: String(user.id),
        },
    });
};

onMounted(() => {
    document.addEventListener('click', closeActionsMenuOnOutsideClick);
    document.addEventListener('click', closeFiltersMenuOnOutsideClick);
    load();
});

onBeforeUnmount(() => {
    document.removeEventListener('click', closeActionsMenuOnOutsideClick);
    document.removeEventListener('click', closeFiltersMenuOnOutsideClick);
});
</script>

<template>
    <section class="page">
        <header>
            <h1>Gest&atilde;o de Utilizadores</h1>
            <p class="muted">Criar operadores/clientes, gerir acessos a inboxes e gerar convites.</p>
        </header>

        <p v-if="error" class="error">{{ error }}</p>
        <p v-if="success" class="success">{{ success }}</p>

        <div v-if="latestInviteUrl" class="invite-box">
            <strong>Link de convite:</strong>
            <input :value="latestInviteUrl" readonly @focus="$event.target.select()" />
        </div>

        <div v-if="!loading" class="section-actions">
            <button type="button" class="btn-inline" @click="openCreateUserModal">
                Criar utilizador
            </button>
        </div>

        <section v-if="showCreateUserModal" class="modal-overlay" @click.self="closeCreateUserModal">
            <article class="modal-card">
                <header class="modal-header">
                    <div>
                        <h2>Criar utilizador</h2>
                        <p class="muted">Criar operadores/clientes, gerir acessos a inboxes e gerar convites.</p>
                    </div>
                </header>

                <form class="grid" @submit.prevent="createUser">
                    <label>
                        Nome
                        <input v-model="createForm.name" required maxlength="255" />
                    </label>

                    <label>
                        Email
                        <input v-model="createForm.email" type="email" required />
                    </label>

                    <label>
                        Perfil
                        <select v-model="createForm.role">
                            <option value="operator">Operador</option>
                            <option value="client">Cliente</option>
                        </select>
                    </label>

                    <label class="checkbox-line">
                        <input v-model="createForm.is_active" type="checkbox" />
                        Ativo
                    </label>

                    <template v-if="isOperatorForm">
                        <div class="full-row">
                            <p class="field-label">Acessos a inboxes</p>
                            <div class="checks">
                                <label v-for="inbox in manageableInboxes" :key="`inbox-${inbox.id}`">
                                    <input
                                        type="checkbox"
                                        :checked="createForm.inbox_ids.includes(inbox.id)"
                                        @change="toggleInbox(inbox.id)"
                                    />
                                    {{ inbox.name }}
                                </label>
                            </div>
                        </div>

                        <div class="full-row">
                            <p class="field-label">Permiss\u00E3o para gerir utilizadores (por inbox)</p>
                            <div class="checks">
                                <label v-for="inbox in manageableInboxes" :key="`manager-${inbox.id}`">
                                    <input
                                        type="checkbox"
                                        :checked="createForm.manager_inbox_ids.includes(inbox.id)"
                                        :disabled="!createForm.inbox_ids.includes(inbox.id)"
                                        @change="toggleManagerInbox(inbox.id)"
                                    />
                                    {{ inbox.name }}
                                </label>
                            </div>
                        </div>

                        <label v-if="canSetAdmin" class="checkbox-line full-row">
                            <input v-model="createForm.is_admin" type="checkbox" />
                            Operador admin (acesso global)
                        </label>
                    </template>

                    <template v-else>
                        <label>
                            Entidade
                            <select v-model="createForm.entity_id" required>
                                <option value="">Selecionar entidade</option>
                                <option v-for="entity in entities" :key="entity.id" :value="entity.id">
                                    {{ entity.name }}
                                </option>
                            </select>
                        </label>

                        <label>
                            Nome de contacto
                            <input v-model="createForm.contact_name" maxlength="255" />
                        </label>
                    </template>

                    <div class="full-row actions modal-actions">
                        <button :disabled="saving" type="submit">
                            {{ saving ? 'A criar...' : 'Criar utilizador' }}
                        </button>
                        <button type="button" class="ghost" @click="closeCreateUserModal">Cancelar</button>
                    </div>
                </form>
            </article>
        </section>
        <UserEditModal
            :open="showEditUserModal"
            :saving="saving"
            :form="editForm"
            :is-operator="isOperatorEditForm"
            :is-client="isClientEditForm"
            :can-set-admin="canSetAdmin"
            :manageable-inboxes="manageableInboxes"
            title="Editar utilizador"
            subtitle="Atualizar dados do utilizador e permissões de acesso."
            @close="closeEditUserModal"
            @save="saveEditedUser"
            @toggle-inbox="toggleEditInbox"
            @toggle-manager-inbox="toggleEditManagerInbox"
        />

        <article class="card" v-if="!loading">
            <div class="toolbar">
                <h2>Lista de utilizadores</h2>
                <div class="user-filters-menu">
                    <button type="button" class="user-filter-toggle" @click.stop="toggleFiltersMenu">
                        <svg class="user-filters-toggle-icon" viewBox="0 0 24 24" fill="none" aria-hidden="true">
                            <path d="M3 5h18l-7 8v5l-4 2v-7L3 5z" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" />
                        </svg>
                        <span>Filtros utilizadores</span>
                        <span v-if="activeFilterCount" class="user-filters-count">{{ activeFilterCount }}</span>
                    </button>

                    <div v-if="showFiltersMenu" class="user-filters-dropdown" @click.stop>
                        <header class="user-filters-dropdown-header">
                            <h3>Filtros utilizadores</h3>
                            <p>Refina a lista por pesquisa e perfil.</p>
                        </header>

                        <div class="user-filters-grid">
                            <label>
                                Pesquisa
                                <input
                                    v-model="search"
                                    placeholder="Procurar por nome ou email"
                                    @keyup.enter="applyUsersFilters"
                                />
                            </label>

                            <label>
                                Perfil
                                <select v-model="roleFilter">
                                    <option value="">Todos os perfis</option>
                                    <option value="operator">Operadores</option>
                                    <option value="client">Clientes</option>
                                </select>
                            </label>
                        </div>

                        <footer class="user-filters-footer">
                            <span class="user-filters-helper">{{ activeFilterCount }} ativos</span>
                            <div class="user-filters-actions">
                                <button type="button" class="ghost user-filters-clear-btn" @click="clearUsersFilters">
                                    Limpar
                                </button>
                                <button type="button" @click="applyUsersFilters">Filtrar</button>
                            </div>
                        </footer>
                    </div>
                </div>
            </div>

            <table class="table">
                <thead>
                    <tr>
                        <th>
                            <button type="button" class="sort-btn" @click="toggleSort('name')">
                                Nome <span class="sort-indicator" :class="`is-${sortState('name')}`"></span>
                            </button>
                        </th>
                        <th>
                            <button type="button" class="sort-btn" @click="toggleSort('email')">
                                Email <span class="sort-indicator" :class="`is-${sortState('email')}`"></span>
                            </button>
                        </th>
                        <th>
                            <button type="button" class="sort-btn" @click="toggleSort('role')">
                                Perfil <span class="sort-indicator" :class="`is-${sortState('role')}`"></span>
                            </button>
                        </th>
                        <th>
                            <button type="button" class="sort-btn" @click="toggleSort('is_active')">
                                Estado <span class="sort-indicator" :class="`is-${sortState('is_active')}`"></span>
                            </button>
                        </th>
                        <th>
                            <button type="button" class="sort-btn" @click="toggleSort('tickets_count')">
                                Tickets <span class="sort-indicator" :class="`is-${sortState('tickets_count')}`"></span>
                            </button>
                        </th>
                        <th>Acessos</th>
                        <th>A&ccedil;&otilde;es</th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-for="user in users" :key="user.id">
                        <td>
                            <div class="user-name-cell">
                                <RouterLink class="user-avatar-link" :to="{ name: 'users.show', params: { id: user.id } }" :aria-label="`Abrir detalhe de ${user.name}`">
                                    <img v-if="user.avatar_url" :src="user.avatar_url" :alt="`Avatar de ${user.name}`">
                                    <span v-else>{{ userInitials(user.name) }}</span>
                                </RouterLink>
                                <RouterLink class="user-name-link" :to="{ name: 'users.show', params: { id: user.id } }">
                                    {{ user.name }}
                                </RouterLink>
                            </div>
                        </td>
                        <td>{{ user.email }}</td>
                        <td>{{ user.role === 'operator' ? (user.is_admin ? 'Operador admin' : 'Operador') : 'Cliente' }}</td>
                        <td>{{ user.is_active ? 'Ativo' : 'Inativo' }}</td>
                        <td>
                            <button type="button" class="ticket-count-link" @click="goToUserTickets(user)">
                                {{ user.tickets_count ?? 0 }}
                            </button>
                        </td>
                        <td class="access-cell">
                            <span class="access-summary" :title="accessSummary(user)">
                                {{ accessSummary(user) }}
                            </span>
                        </td>
                        <td>
                            <div class="actions-menu">
                                <button
                                    type="button"
                                    class="ghost menu-trigger"
                                    aria-label="Abrir A&ccedil;&otilde;es"
                                    @click.stop="toggleActionsMenu(user.id)"
                                >
                                    &#8943;
                                </button>
                                <div
                                    v-if="openActionsMenuUserId === user.id"
                                    class="actions-dropdown"
                                    @click.stop
                                >
                                    <button type="button" class="menu-item" @click="toggleActive(user); closeActionsMenu()">
                                        {{ user.is_active ? 'Desativar' : 'Ativar' }}
                                    </button>

                                    <button type="button" class="menu-item" @click="regenerateInvite(user); closeActionsMenu()">
                                        Novo convite
                                    </button>

                                    <button
                                        type="button"
                                        class="menu-item"
                                        @click="openEditUserModal(user); closeActionsMenu()"
                                    >
                                        Editar
                                    </button>

                                    <button
                                        v-if="user.role === 'operator' && canSetAdmin"
                                        type="button"
                                        class="menu-item"
                                        @click="toggleAdmin(user); closeActionsMenu()"
                                    >
                                        {{ user.is_admin ? 'Remover admin' : 'Tornar admin' }}
                                    </button>

                                    <button type="button" class="menu-item danger" @click="deleteUser(user); closeActionsMenu()">
                                        Eliminar
                                    </button>
                                </div>
                            </div>

                        </td>
                    </tr>
                </tbody>
            </table>
        </article>
    </section>
</template>

<style scoped>
.page {
    display: grid;
    gap: 1rem;
}

h1,
h2 {
    margin: 0;
}

.muted {
    color: #475569;
}

.error {
    border: 1px solid #fecaca;
    background: #fef2f2;
    color: #991b1b;
    border-radius: 8px;
    padding: 0.65rem;
}

.success {
    border: 1px solid #c8d8ea;
    background: #EDF3FA;
    color: #1F4E79;
    border-radius: 8px;
    padding: 0.65rem;
}

.invite-box {
    border: 1px solid #bfdbfe;
    background: #eff6ff;
    border-radius: 10px;
    padding: 0.75rem;
    display: grid;
    gap: 0.45rem;
}

.invite-box input {
    width: 100%;
    border: 1px solid #cbd5e1;
    border-radius: 8px;
    padding: 0.45rem 0.55rem;
    font-family: Consolas, Monaco, monospace;
}

.card {
    border: 1px solid #dbe4ee;
    background: #fff;
    border-radius: 12px;
    padding: 0.95rem;
    display: grid;
    gap: 0.8rem;
}

.section-actions {
    display: flex;
    justify-content: flex-start;
}

.btn-inline {
    width: auto;
    min-width: 150px;
}

.grid {
    display: grid;
    grid-template-columns: repeat(2, minmax(0, 1fr));
    gap: 0.7rem;
}

label {
    display: grid;
    gap: 0.3rem;
}

input,
select,
button {
    font: inherit;
}

input,
select {
    border: 1px solid #dbe4ee;
    border-radius: 8px;
    padding: 0.52rem 0.6rem;
}

button {
    border: 1px solid #1F4E79;
    background: #1F4E79;
    color: #fff;
    border-radius: 8px;
    padding: 0.5rem 0.65rem;
    cursor: pointer;
}

button.ghost {
    border-color: #cbd5e1;
    background: #fff;
    color: #0f172a;
}

button.danger {
    border-color: #b91c1c;
    background: #b91c1c;
    color: #fff;
}

.ticket-count-link {
    min-width: 2.1rem;
    padding: 0.2rem 0.5rem;
    border-radius: 999px;
    border: 1px solid #cbd5e1;
    background: #f8fafc;
    color: #334155;
}

.ticket-count-link:hover {
    border-color: #9ab9d8;
    background: #EDF3FA;
    color: #1F4E79;
}

.user-name-cell {
    display: inline-flex;
    align-items: center;
    gap: 0.5rem;
    min-width: 0;
}

.user-avatar-link {
    width: 32px;
    height: 32px;
    border-radius: 999px;
    border: 2px solid #b6c9e3;
    overflow: hidden;
    background: linear-gradient(145deg, #1f2a44 0%, #2e3e66 100%);
    color: #fff;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    text-decoration: none;
    font-size: 0.72rem;
    font-weight: 700;
    flex-shrink: 0;
    box-shadow: 0 0 0 2px rgba(255, 255, 255, 0.82), 0 2px 7px rgba(15, 23, 42, 0.1);
}

.user-avatar-link img {
    width: 100%;
    height: 100%;
    object-fit: cover;
    display: block;
}

.user-name-link {
    color: #0f172a;
    text-decoration: none;
    font-weight: 600;
    min-width: 0;
}

.user-name-link:hover {
    color: #1F4E79;
    text-decoration: underline;
}

.checkbox-line {
    display: flex;
    align-items: center;
    gap: 0.45rem;
    align-self: end;
}

.checkbox-line input {
    width: auto;
}

.full-row {
    grid-column: 1 / -1;
}

.field-label {
    margin: 0 0 0.3rem;
    font-size: 0.92rem;
    color: #334155;
}

.checks {
    display: flex;
    flex-wrap: wrap;
    gap: 0.5rem 0.9rem;
}

.checks label {
    display: inline-flex;
    align-items: center;
    gap: 0.35rem;
}

.checks input {
    width: auto;
}

.actions {
    display: flex;
    justify-content: flex-end;
}

.modal-overlay {
    position: fixed;
    inset: 0;
    z-index: 80;
    background: rgba(15, 23, 42, 0.45);
    backdrop-filter: blur(1px);
    display: grid;
    place-items: center;
    padding: 1rem;
}

.modal-card {
    width: min(980px, calc(100vw - 2rem));
    max-height: calc(100vh - 2rem);
    overflow: auto;
    background: #fff;
    border: 1px solid #dbe4ee;
    border-radius: 14px;
    padding: 0.95rem;
    display: grid;
    gap: 0.8rem;
}

.modal-header {
    display: flex;
    align-items: flex-start;
    justify-content: space-between;
    gap: 0.8rem;
}

.modal-header h2 {
    margin: 0;
}

.modal-header p {
    margin: 0.25rem 0 0;
}

.modal-actions {
    align-items: center;
    gap: 0.45rem;
}

.toolbar {
    display: flex;
    justify-content: space-between;
    align-items: center;
    gap: 1rem;
    flex-wrap: wrap;
}

.user-filters-menu {
    position: relative;
}

.user-filter-toggle {
    display: inline-flex;
    align-items: center;
    gap: 0.42rem;
    border: none;
    background: transparent;
    color: #475569;
    padding: 0.25rem 0.1rem;
    cursor: pointer;
}

.user-filters-toggle-icon {
    width: 16px;
    height: 16px;
}

.user-filters-count {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    min-width: 1.25rem;
    height: 1.25rem;
    border-radius: 999px;
    background: #e8f0fa;
    border: 1px solid #9ab9d8;
    color: #1F4E79;
    font-size: 0.78rem;
    line-height: 1;
}

.user-filters-dropdown {
    position: absolute;
    top: calc(100% + 0.38rem);
    right: -0.35rem;
    z-index: 40;
    width: min(560px, calc(100vw - 3rem));
    border: 1px solid #dbe4ee;
    border-radius: 14px;
    background: #fff;
    box-shadow: 0 14px 34px rgba(15, 23, 42, 0.18);
    padding: 0.85rem;
}

.user-filters-dropdown-header {
    display: grid;
    gap: 0.12rem;
    margin-bottom: 0.6rem;
}

.user-filters-dropdown-header h3 {
    margin: 0;
    font-size: 1rem;
    color: #0f172a;
}

.user-filters-dropdown-header p {
    margin: 0;
    color: #64748b;
    font-size: 0.83rem;
}

.user-filters-grid {
    display: grid;
    grid-template-columns: repeat(2, minmax(0, 1fr));
    gap: 0.7rem;
    padding-bottom: 0.5rem;
}

.user-filters-grid label {
    display: grid;
    gap: 0.24rem;
    color: #334155;
    font-size: 0.88rem;
}

.user-filters-grid input,
.user-filters-grid select {
    border: 1px solid #dbe4ee;
    border-radius: 8px;
    padding: 0.42rem 0.55rem;
    font: inherit;
    color: #0f172a;
    background: #fff;
    width: 100%;
}

.user-filters-footer {
    border-top: 1px solid #e7edf6;
    padding-top: 0.6rem;
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 0.5rem;
}

.user-filters-helper {
    color: #64748b;
    font-size: 0.82rem;
}

.user-filters-actions {
    display: inline-flex;
    align-items: center;
    gap: 0.45rem;
}

.user-filters-clear-btn {
    border-color: #cdd8e6;
    color: #334155;
}

.table {
    width: 100%;
    border-collapse: collapse;
}

th,
td {
    border-bottom: 1px solid #e2e8f0;
    text-align: left;
    padding: 0.6rem;
    vertical-align: middle;
}

.sort-btn {
    border: none;
    background: transparent;
    color: inherit;
    font: inherit;
    font-weight: 600;
    cursor: pointer;
    padding: 0;
    display: inline-flex;
    align-items: center;
    gap: 0.25rem;
    overflow: visible;
}

.sort-indicator {
    display: inline-flex;
    flex-direction: column;
    justify-content: center;
    gap: 1px;
    width: 9px;
    margin-left: 0.08rem;
}

.sort-indicator::before,
.sort-indicator::after {
    content: '';
    width: 0;
    height: 0;
    border-left: 4px solid transparent;
    border-right: 4px solid transparent;
}

.sort-indicator::before {
    border-bottom: 5px solid #94a3b8;
}

.sort-indicator::after {
    border-top: 5px solid #94a3b8;
}

.sort-indicator.is-asc::before {
    border-bottom-color: #334155;
}

.sort-indicator.is-asc::after {
    border-top-color: #cbd5e1;
}

.sort-indicator.is-desc::before {
    border-bottom-color: #cbd5e1;
}

.sort-indicator.is-desc::after {
    border-top-color: #334155;
}

.access-cell {
    max-width: 320px;
}

.access-summary {
    display: block;
    max-width: 320px;
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
    color: #334155;
}

.flat-list {
    list-style: none;
    margin: 0;
    padding: 0;
    display: grid;
    gap: 0.2rem;
}

.actions-menu {
    position: relative;
    display: inline-flex;
}

.menu-trigger {
    width: 2.2rem;
    height: 2.2rem;
    border-radius: 999px;
    padding: 0;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    font-size: 1.35rem;
    line-height: 1;
}

.actions-dropdown {
    position: absolute;
    top: calc(100% + 0.35rem);
    right: 0;
    z-index: 25;
    min-width: 180px;
    border: 1px solid #dbe4ee;
    border-radius: 10px;
    background: #fff;
    box-shadow: 0 12px 28px rgba(15, 23, 42, 0.18);
    padding: 0.35rem;
    display: grid;
    gap: 0.35rem;
}

.menu-item {
    width: 100%;
    border-color: #cbd5e1;
    background: #fff;
    color: #0f172a;
    text-align: left;
}

.menu-item:hover {
    background: #f1f5f9;
}

.menu-item.danger {
    border-color: #b91c1c;
    background: #b91c1c;
    color: #fff;
}

@media (max-width: 960px) {
    .grid {
        grid-template-columns: 1fr;
    }

    .user-filters-dropdown {
        right: 0;
        width: min(420px, calc(100vw - 2.5rem));
    }

    .user-filters-grid {
        grid-template-columns: 1fr;
    }

    .table,
    .table thead,
    .table tbody,
    .table th,
    .table td,
    .table tr {
        display: block;
    }

    .table thead {
        display: none;
    }

    .table tr {
        border: 1px solid #dbe4ee;
        border-radius: 8px;
        margin-bottom: 0.7rem;
        padding: 0.45rem;
        background: #fff;
    }

    .table td {
        border: none;
        padding: 0.3rem 0.2rem;
    }
}
</style>


