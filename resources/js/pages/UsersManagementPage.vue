<script setup>
import { computed, onBeforeUnmount, onMounted, reactive, ref } from 'vue';
import { useRouter } from 'vue-router';
import api from '../api/client';
import UserEditModal from '../components/UserEditModal.vue';
import QuickActionsRail from '../components/QuickActionsRail.vue';
import QuickMenuPanel from '../components/QuickMenuPanel.vue';

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
const inboxFilter = ref('');
const activeStatusFilter = ref('');
const sortBy = ref('name');
const sortDir = ref('asc');
const showCreateUserModal = ref(false);
const showEditUserModal = ref(false);
const showFiltersMenu = ref(false);

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
    if (search.value.trim() !== '') count += 1;
    if (roleFilter.value) count += 1;
    if (inboxFilter.value) count += 1;
    if (activeStatusFilter.value) count += 1;
    return count;
});

const quickActions = computed(() => ([
    { id: 'filters', title: 'Filtros utilizadores', active: showFiltersMenu.value },
]));

const handleQuickAction = (actionId) => {
    if (actionId === 'filters') {
        showFiltersMenu.value = !showFiltersMenu.value;
    }
};

const closeFiltersMenu = () => {
    showFiltersMenu.value = false;
};


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
    if (search.value.trim() !== '') params.search = search.value.trim();
    if (roleFilter.value) params.role = roleFilter.value;
    if (inboxFilter.value) params.inbox_id = inboxFilter.value;
    if (activeStatusFilter.value) params.is_active = activeStatusFilter.value === 'active' ? 1 : 0;
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


const applyUsersFilters = async () => {
    await fetchUsers();
};

const clearUsersFilters = async () => {
    search.value = '';
    roleFilter.value = '';
    inboxFilter.value = '';
    activeStatusFilter.value = '';
    await fetchUsers();
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
    load();
});

onBeforeUnmount(() => {
    document.removeEventListener('click', closeActionsMenuOnOutsideClick);
});
</script>

<template>
    <section class="upage">

        <!-- Header -->
        <header class="upage-header">
            <div>
                <h1 class="upage-h1">Utilizadores</h1>
                <p class="upage-sub">{{ users.length }} registo{{ users.length !== 1 ? 's' : '' }}</p>
            </div>
            <button v-if="!loading" type="button" class="upage-create-btn" @click="openCreateUserModal">
                <svg viewBox="0 0 16 16" fill="none"><path d="M8 3v10M3 8h10" stroke="currentColor" stroke-width="2.2" stroke-linecap="round"/></svg>
                Novo Utilizador
            </button>
        </header>

        <!-- Banners -->
        <div v-if="error" class="upage-error">
            <svg viewBox="0 0 16 16" fill="none"><circle cx="8" cy="8" r="6" stroke="currentColor" stroke-width="1.4"/><path d="M8 5v3m0 2.5v.5" stroke="currentColor" stroke-width="1.5" stroke-linecap="round"/></svg>
            {{ error }}
        </div>
        <div v-if="success" class="upage-success">
            <svg viewBox="0 0 16 16" fill="none"><circle cx="8" cy="8" r="6" stroke="currentColor" stroke-width="1.4"/><path d="M5.5 8.5l2 2 3.5-4" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"/></svg>
            {{ success }}
        </div>

        <!-- Invite box -->
        <div v-if="latestInviteUrl" class="upage-invite-box">
            <div class="upage-invite-icon">
                <svg viewBox="0 0 16 16" fill="none"><path d="M2 4l6 5 6-5" stroke="currentColor" stroke-width="1.4" stroke-linecap="round" stroke-linejoin="round"/><rect x="1" y="3.5" width="14" height="10" rx="2" stroke="currentColor" stroke-width="1.4"/></svg>
            </div>
            <div class="upage-invite-body">
                <p class="upage-invite-label">Link de convite gerado</p>
                <input class="upage-invite-input" :value="latestInviteUrl" readonly @focus="$event.target.select()" />
            </div>
        </div>

        <!-- Create user modal -->
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

        <!-- Users table card -->
        <article class="upage-card" v-if="!loading">

            <!-- Selection bar -->
            <div v-if="activeFilterCount > 0" class="u-active-bar">
                <span class="u-active-label">{{ activeFilterCount }} filtro{{ activeFilterCount !== 1 ? 's' : '' }} ativo{{ activeFilterCount !== 1 ? 's' : '' }}</span>
                <button type="button" class="u-clear-btn" @click="clearUsersFilters">Limpar filtros</button>
            </div>

            <div class="upage-table-wrap">
                <table class="upage-table">
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
                            <th>Ações</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-if="!users.length">
                            <td colspan="7" class="upage-empty-row">
                                <svg viewBox="0 0 40 40" fill="none"><circle cx="20" cy="20" r="16" stroke="#d4e0ee" stroke-width="2"/><path d="M14 20h12M20 14v12" stroke="#b0c4d8" stroke-width="2" stroke-linecap="round" opacity="0.4"/></svg>
                                <span>Sem utilizadores para apresentar.</span>
                            </td>
                        </tr>
                        <tr v-for="user in users" :key="user.id" class="upage-row">
                            <td>
                                <div class="user-name-cell">
                                    <RouterLink class="user-avatar-link" :to="{ name: 'users.show', params: { id: user.id } }">
                                        <img v-if="user.avatar_url" :src="user.avatar_url" :alt="`Avatar de ${user.name}`">
                                        <span v-else>{{ userInitials(user.name) }}</span>
                                    </RouterLink>
                                    <div class="user-name-info">
                                        <RouterLink class="user-name-link" :to="{ name: 'users.show', params: { id: user.id } }">{{ user.name }}</RouterLink>
                                    </div>
                                </div>
                            </td>
                            <td class="upage-td-email">{{ user.email }}</td>
                            <td>
                                <span class="upage-role-badge" :class="user.role === 'operator' ? (user.is_admin ? 'is-admin' : 'is-operator') : 'is-client'">
                                    <svg v-if="user.role === 'operator'" viewBox="0 0 12 12" fill="none"><circle cx="6" cy="3.5" r="2" stroke="currentColor" stroke-width="1.2"/><path d="M1.5 10c0-2.5 2-4 4.5-4s4.5 1.5 4.5 4" stroke="currentColor" stroke-width="1.2" stroke-linecap="round"/></svg>
                                    <svg v-else viewBox="0 0 12 12" fill="none"><circle cx="6" cy="3.5" r="2" stroke="currentColor" stroke-width="1.2"/><path d="M1.5 10c0-2.5 2-4 4.5-4s4.5 1.5 4.5 4" stroke="currentColor" stroke-width="1.2" stroke-linecap="round"/></svg>
                                    {{ user.role === 'operator' ? (user.is_admin ? 'Admin' : 'Operador') : 'Cliente' }}
                                </span>
                            </td>
                            <td>
                                <span class="upage-status-badge" :class="user.is_active ? 'is-active' : 'is-inactive'">
                                    <span class="upage-status-dot"></span>
                                    {{ user.is_active ? 'Ativo' : 'Inativo' }}
                                </span>
                            </td>
                            <td>
                                <button type="button" class="upage-ticket-count" @click="goToUserTickets(user)">
                                    <svg viewBox="0 0 12 12" fill="none"><path d="M1.5 3A1 1 0 0 1 2.5 2h7A1 1 0 0 1 10.5 3v1.5a1 1 0 0 0 0 2V8a1 1 0 0 1-1 1h-7a1 1 0 0 1-1-1V6.5a1 1 0 0 0 0-2V3Z" stroke="currentColor" stroke-width="1.1"/></svg>
                                    {{ user.tickets_count ?? 0 }}
                                </button>
                            </td>
                            <td class="access-cell">
                                <span class="access-summary" :title="accessSummary(user)">{{ accessSummary(user) }}</span>
                            </td>
                            <td>
                                <div class="actions-menu">
                                    <button
                                        type="button"
                                        class="menu-trigger"
                                        aria-label="Abrir ações"
                                        @click.stop="toggleActionsMenu(user.id)"
                                    >
                                        <svg viewBox="0 0 18 18" fill="currentColor"><circle cx="9" cy="4" r="1.4"/><circle cx="9" cy="9" r="1.4"/><circle cx="9" cy="14" r="1.4"/></svg>
                                    </button>
                                    <div v-if="openActionsMenuUserId === user.id" class="actions-dropdown" @click.stop>
                                        <button type="button" class="menu-item" @click="toggleActive(user); closeActionsMenu()">
                                            <svg viewBox="0 0 14 14" fill="none">
                                                <template v-if="user.is_active">
                                                    <circle cx="7" cy="7" r="5" stroke="currentColor" stroke-width="1.3"/>
                                                    <path d="M5 7.5l2 2 2-4" stroke="currentColor" stroke-width="1.3" stroke-linecap="round" stroke-linejoin="round"/>
                                                </template>
                                                <template v-else>
                                                    <circle cx="7" cy="7" r="5" stroke="currentColor" stroke-width="1.3"/>
                                                    <path d="M5 5l4 4M9 5l-4 4" stroke="currentColor" stroke-width="1.3" stroke-linecap="round"/>
                                                </template>
                                            </svg>
                                            {{ user.is_active ? 'Desativar' : 'Ativar' }}
                                        </button>

                                        <button type="button" class="menu-item" @click="openEditUserModal(user); closeActionsMenu()">
                                            <svg viewBox="0 0 14 14" fill="none"><path d="M2 10.5V12h1.5l5-5-1.5-1.5-5 5zm8-6.5L8.5 2.5 7 4l1.5 1.5L10 4z" stroke="currentColor" stroke-width="1.2" stroke-linejoin="round"/></svg>
                                            Editar
                                        </button>

                                        <button type="button" class="menu-item" @click="regenerateInvite(user); closeActionsMenu()">
                                            <svg viewBox="0 0 14 14" fill="none"><path d="M2 7a5 5 0 1 1 1.2 3.2" stroke="currentColor" stroke-width="1.3" stroke-linecap="round"/><path d="M2 10.5V7H5.5" stroke="currentColor" stroke-width="1.3" stroke-linecap="round" stroke-linejoin="round"/></svg>
                                            Novo convite
                                        </button>

                                        <button
                                            v-if="user.role === 'operator' && canSetAdmin"
                                            type="button"
                                            class="menu-item"
                                            :class="user.is_admin ? 'is-warning' : ''"
                                            @click="toggleAdmin(user); closeActionsMenu()"
                                        >
                                            <svg viewBox="0 0 14 14" fill="none"><path d="M7 1l1.5 3.2L12 4.8l-2.5 2.4.6 3.4L7 9l-3.1 1.6.6-3.4L2 4.8l3.5-.6L7 1z" stroke="currentColor" stroke-width="1.2" stroke-linejoin="round"/></svg>
                                            {{ user.is_admin ? 'Remover admin' : 'Tornar admin' }}
                                        </button>

                                        <div class="menu-divider"></div>

                                        <button type="button" class="menu-item is-danger" @click="deleteUser(user); closeActionsMenu()">
                                            <svg viewBox="0 0 14 14" fill="none"><path d="M2.5 4h9M5.5 4V2.5h3V4M6 6.5v4M8 6.5v4" stroke="currentColor" stroke-width="1.3" stroke-linecap="round" stroke-linejoin="round"/><rect x="3" y="4" width="8" height="8.5" rx="1.2" stroke="currentColor" stroke-width="1.3"/></svg>
                                            Eliminar
                                        </button>
                                    </div>
                                </div>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </article>

        <QuickActionsRail
            v-if="!loading"
            :actions="quickActions"
            aria-label="Ações utilizadores"
            desktop-style="floating"
            mobile-style="bottom"
            :dock="showFiltersMenu"
            dock-offset="min(420px, calc(100vw - 1.2rem))"
            @action="handleQuickAction"
        >
            <template #icon-filters>
                <svg viewBox="0 0 24 24" fill="none" aria-hidden="true">
                    <path d="M3 5h18l-7 8v5l-4 2v-7L3 5z" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" />
                </svg>
            </template>
        </QuickActionsRail>

        <QuickMenuPanel
            :open="showFiltersMenu"
            title="Filtros utilizadores"
            aria-label="Filtros utilizadores"
            @close="closeFiltersMenu"
        >
            <div class="u-filter-panel">
                <label class="u-filter-field">
                    <span class="u-field-label">Pesquisa</span>
                    <input
                        v-model="search"
                        type="search"
                        placeholder="Procurar por nome ou email"
                        @input="applyUsersFilters"
                    />
                </label>

                <div class="u-filter-grid">
                    <label class="u-filter-field">
                        <span class="u-field-label">Perfil</span>
                        <select v-model="roleFilter" @change="applyUsersFilters">
                            <option value="">Todos os perfis</option>
                            <option value="operator">Operadores</option>
                            <option value="client">Clientes</option>
                        </select>
                    </label>

                    <label class="u-filter-field">
                        <span class="u-field-label">Inbox</span>
                        <select v-model="inboxFilter" @change="applyUsersFilters">
                            <option value="">Todas as inboxes</option>
                            <option v-for="inbox in manageableInboxes" :key="inbox.id" :value="String(inbox.id)">{{ inbox.name }}</option>
                        </select>
                    </label>

                    <label class="u-filter-field">
                        <span class="u-field-label">Estado</span>
                        <select v-model="activeStatusFilter" @change="applyUsersFilters">
                            <option value="">Todos</option>
                            <option value="active">Ativos</option>
                            <option value="inactive">Inativos</option>
                        </select>
                    </label>
                </div>

                <div class="u-filter-footer">
                    <span class="u-filter-count">
                        <template v-if="activeFilterCount > 0">{{ activeFilterCount }} filtro{{ activeFilterCount !== 1 ? 's' : '' }} ativo{{ activeFilterCount !== 1 ? 's' : '' }}</template>
                        <template v-else>Sem filtros ativos</template>
                    </span>
                    <div class="u-filter-actions">
                        <button type="button" class="u-filter-clear" @click="clearUsersFilters">Limpar</button>
                        <button type="button" class="u-filter-apply" @click="applyUsersFilters">Filtrar</button>
                    </div>
                </div>
            </div>
        </QuickMenuPanel>

        <!-- Loading state -->
        <div v-if="loading" class="upage-loading">
            <span class="upage-spinner"></span>
            A carregar utilizadores…
        </div>

    </section>
</template>

<style scoped>
/* ── Page ─────────────────────────────────────────────────── */

.upage {
    display: flex;
    flex-direction: column;
    gap: 0.9rem;
}

/* ── Header ───────────────────────────────────────────────── */

.upage-header {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 1rem;
    flex-wrap: wrap;
}

.upage-title-block {
    display: flex;
    align-items: center;
    gap: 0.75rem;
}

.upage-title-icon {
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

.upage-title-icon svg { width: 22px; height: 22px; }

.upage-h1 {
    margin: 0;
    font-size: 1.45rem;
    font-weight: 800;
    color: #0f172a;
    line-height: 1.2;
}

.upage-sub {
    margin: 0.1rem 0 0;
    font-size: 0.84rem;
    color: #64748b;
}

.upage-create-btn {
    display: inline-flex;
    align-items: center;
    gap: 0.38rem;
    border: 1px solid #1F4E79;
    background: #1F4E79;
    color: #fff;
    border-radius: 10px;
    padding: 0.48rem 0.9rem;
    font: inherit;
    font-size: 0.86rem;
    font-weight: 600;
    cursor: pointer;
    transition: background 110ms;
}

.upage-create-btn svg { width: 14px; height: 14px; }
.upage-create-btn:hover { background: #163d60; border-color: #163d60; }

/* ── Banners ──────────────────────────────────────────────── */

.upage-error, .upage-success {
    display: flex;
    align-items: center;
    gap: 0.45rem;
    padding: 0.62rem 0.78rem;
    border-radius: 10px;
    font-size: 0.88rem;
}

.upage-error svg, .upage-success svg { width: 16px; height: 16px; flex-shrink: 0; }

.upage-error   { border: 1px solid #fca5a5; background: #fef2f2; color: #991b1b; }
.upage-success { border: 1px solid #86efac; background: #f0fdf4; color: #15803d; }

/* ── Invite box ───────────────────────────────────────────── */

.upage-invite-box {
    border: 1px solid #bfdbfe;
    background: #eff6ff;
    border-radius: 12px;
    padding: 0.75rem 0.9rem;
    display: flex;
    align-items: flex-start;
    gap: 0.65rem;
}

.upage-invite-icon {
    width: 34px;
    height: 34px;
    border-radius: 8px;
    background: #dbeafe;
    color: #1d4ed8;
    display: flex;
    align-items: center;
    justify-content: center;
    flex-shrink: 0;
}

.upage-invite-icon svg { width: 16px; height: 16px; }

.upage-invite-body { display: flex; flex-direction: column; gap: 0.3rem; flex: 1; min-width: 0; }

.upage-invite-label {
    font-size: 0.78rem;
    font-weight: 700;
    color: #1d4ed8;
    text-transform: uppercase;
    letter-spacing: 0.04em;
}

.upage-invite-input {
    width: 100%;
    border: 1px solid #bfdbfe;
    border-radius: 8px;
    padding: 0.38rem 0.55rem;
    font-family: Consolas, Monaco, monospace;
    font-size: 0.82rem;
    background: #fff;
    color: #1e3a8a;
}

/* ── Card ─────────────────────────────────────────────────── */

.upage-card {
    border: 1px solid #dde8f4;
    background: #fff;
    border-radius: 14px;
    padding: 0.95rem;
    display: flex;
    flex-direction: column;
    gap: 0.75rem;
}

/* —— Quick menu filters —— */
.u-filter-panel {
    display: flex;
    flex-direction: column;
    gap: 0.85rem;
    padding-right: 0.35rem;
}

.u-filter-field {
    display: flex;
    flex-direction: column;
    gap: 0.35rem;
    font-size: 0.86rem;
    color: #0f172a;
}

.u-field-label {
    font-size: 0.74rem;
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: 0.04em;
    color: #64748b;
}

.u-filter-field input,
.u-filter-field select {
    border: 1px solid #d4e0ee;
    border-radius: 12px;
    padding: 0.52rem 0.7rem;
    font: inherit;
    font-size: 0.86rem;
    background: #fff;
    color: #0f172a;
    transition: border-color 120ms, box-shadow 120ms;
}

.u-filter-field input:focus,
.u-filter-field select:focus {
    outline: none;
    border-color: #9dc0e4;
    box-shadow: 0 0 0 3px rgba(23, 128, 255, 0.12);
}

.u-filter-grid {
    display: grid;
    gap: 0.65rem;
}

.u-filter-footer {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 0.75rem;
    padding-top: 0.35rem;
    border-top: 1px solid #e2e8f0;
    font-size: 0.82rem;
    color: #64748b;
}

.u-filter-actions {
    display: flex;
    align-items: center;
    gap: 0.5rem;
}

.u-filter-clear,
.u-filter-apply {
    border: 1px solid #cdd9e8;
    background: #fff;
    color: #0f172a;
    border-radius: 10px;
    padding: 0.35rem 0.7rem;
    font: inherit;
    font-size: 0.82rem;
    font-weight: 600;
    cursor: pointer;
}

.u-filter-apply {
    border-color: #1f4e79;
    background: #1f4e79;
    color: #fff;
}

.u-filter-apply:hover { background: #163d60; border-color: #163d60; }
.u-filter-clear:hover { border-color: #b6c6da; background: #f8fbff; }

/* ── Filter bar (inline — same pattern as TicketsListPage) ── */

.u-filter-bar {
    display: grid;
    grid-template-columns: minmax(220px, 300px) 1fr;
    gap: 0.55rem;
    align-items: center;
}

.u-search-field {
    border: 1px solid #d3deec;
    border-radius: 12px;
    padding: 0 0.75rem;
    display: flex;
    align-items: center;
    gap: 0.4rem;
    height: 40px;
    background: #fafcff;
    cursor: text;
    transition: border-color 100ms, box-shadow 100ms;
}

.u-search-field:focus-within {
    border-color: #9dc0e4;
    box-shadow: 0 0 0 4px rgba(23, 128, 255, 0.12);
}

.u-search-field input {
    border: none;
    background: transparent;
    outline: none;
    font: inherit;
    font-size: 0.86rem;
    color: #0f172a;
    flex: 1;
    min-width: 0;
    padding: 0;
}

.u-search-field input::placeholder { color: #94a3b8; }

.u-field-icon {
    width: 16px;
    height: 16px;
    display: flex;
    align-items: center;
    justify-content: center;
    color: #94a3b8;
    flex-shrink: 0;
}

.u-field-icon svg { width: 100%; height: 100%; }

.u-inline-filters {
    display: flex;
    align-items: center;
    gap: 0.4rem;
    flex-wrap: wrap;
}

.u-inline-field {
    position: relative;
    min-height: 40px;
    display: inline-flex;
    align-items: center;
    gap: 0.35rem;
    padding: 0 2rem 0 0.65rem;
    border: 1px solid #d3deec;
    border-radius: 12px;
    background: #fafcff;
    font-size: 0.83rem;
    color: #334155;
    cursor: pointer;
    transition: border-color 100ms, background 100ms, box-shadow 100ms;
    white-space: nowrap;
}

.u-inline-field:hover {
    border-color: #b8cde3;
    background: #fafdff;
}

.u-inline-field:focus-within {
    border-color: #95b5d8;
    box-shadow: 0 0 0 3px rgba(35, 126, 214, 0.14);
}

.u-inline-field .u-field-icon {
    width: 14px;
    height: 14px;
    color: #7a97b8;
}

.u-ddl-name {
    color: #3f526a;
    font-size: 0.83rem;
    font-weight: 600;
    pointer-events: none;
    user-select: none;
}

.u-inline-field select {
    appearance: none;
    -webkit-appearance: none;
    border: none;
    background: transparent;
    outline: none;
    font: inherit;
    font-size: 0.83rem;
    color: #0f172a;
    cursor: pointer;
    position: absolute;
    inset: 0;
    padding: 0 2rem 0 0.65rem;
    opacity: 0;
}

.u-inline-field::after {
    content: '▾';
    font-size: 0.66rem;
    color: #94a3b8;
    position: absolute;
    right: 0.65rem;
    pointer-events: none;
}

/* Active filters bar */

.u-active-bar {
    display: flex;
    align-items: center;
    gap: 0.5rem;
    padding: 0.38rem 0.7rem;
    background: #eef6ff;
    border: 1px solid #c8dff4;
    border-radius: 9px;
}

.u-active-label {
    font-size: 0.8rem;
    font-weight: 600;
    color: #1e5799;
    flex: 1;
}

.u-clear-btn {
    font: inherit;
    font-size: 0.78rem;
    font-weight: 600;
    color: #dc2626;
    background: none;
    border: 1px solid #fca5a5;
    border-radius: 999px;
    padding: 0.16rem 0.6rem;
    cursor: pointer;
}

.u-clear-btn:hover { background: #fef2f2; }

/* ── Loading ──────────────────────────────────────────────── */

.upage-loading {
    display: flex;
    align-items: center;
    gap: 0.55rem;
    color: #64748b;
    font-size: 0.9rem;
    padding: 1.5rem 0;
}

.upage-spinner {
    display: inline-block;
    width: 16px;
    height: 16px;
    border: 2px solid #d4e4f5;
    border-top-color: #1F4E79;
    border-radius: 50%;
    animation: upage-spin 0.7s linear infinite;
    flex-shrink: 0;
}

@keyframes upage-spin { to { transform: rotate(360deg); } }

/* ── Table ────────────────────────────────────────────────── */

.upage-table-wrap { overflow-x: auto; }

.upage-table {
    width: 100%;
    border-collapse: collapse;
    min-width: 680px;
}

.upage-table th,
.upage-table td {
    border-bottom: 1px solid #eef3fa;
    text-align: left;
    padding: 0.6rem 0.65rem;
    vertical-align: middle;
}

.upage-table thead th {
    font-size: 0.74rem;
    font-weight: 700;
    color: #64748b;
    text-transform: uppercase;
    letter-spacing: 0.04em;
    background: #f8fbff;
    white-space: nowrap;
}

.upage-table thead th:first-child { border-radius: 8px 0 0 0; }
.upage-table thead th:last-child  { border-radius: 0 8px 0 0; }
.upage-table tbody tr:last-child td { border-bottom: none; }

.upage-row:hover td { background: #fafcff; }

.upage-empty-row {
    text-align: center;
    padding: 2.5rem !important;
    color: #64748b;
    border-bottom: none !important;
}

.upage-empty-row {
    display: flex;
    flex-direction: column;
    align-items: center;
    gap: 0.4rem;
}

/* Name cell */

.user-name-cell {
    display: inline-flex;
    align-items: center;
    gap: 0.55rem;
    min-width: 0;
}

.user-avatar-link {
    width: 34px;
    height: 34px;
    border-radius: 50%;
    border: 2px solid #b6c9e3;
    overflow: hidden;
    background: linear-gradient(145deg, #1f2a44 0%, #2e3e66 100%);
    color: #fff;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    text-decoration: none;
    font-size: 0.7rem;
    font-weight: 700;
    flex-shrink: 0;
    box-shadow: 0 0 0 2px rgba(255,255,255,0.85);
}

.user-avatar-link img { width: 100%; height: 100%; object-fit: cover; display: block; }

.user-name-info { display: flex; flex-direction: column; gap: 0.04rem; min-width: 0; }

.user-name-link {
    color: #0f172a;
    text-decoration: none;
    font-weight: 600;
    font-size: 0.87rem;
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
}

.user-name-link:hover { color: #1F4E79; text-decoration: underline; }

.user-email-sub {
    font-size: 0.74rem;
    color: #94a3b8;
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
}

.upage-td-email { color: #475569; font-size: 0.86rem; }

/* Badges */

.upage-role-badge {
    display: inline-flex;
    align-items: center;
    gap: 0.3rem;
    border-radius: 999px;
    padding: 0.18rem 0.6rem;
    font-size: 0.76rem;
    font-weight: 700;
    white-space: nowrap;
}

.upage-role-badge svg { width: 11px; height: 11px; flex-shrink: 0; }

.upage-role-badge.is-operator { background: #eff6ff; color: #1d4ed8; border: 1px solid #bfdbfe; }
.upage-role-badge.is-admin    { background: #faf5ff; color: #6d28d9; border: 1px solid #ddd6fe; }
.upage-role-badge.is-client   { background: #f0fdf4; color: #15803d; border: 1px solid #bbf7d0; }

.upage-status-badge {
    display: inline-flex;
    align-items: center;
    gap: 0.32rem;
    border-radius: 999px;
    padding: 0.18rem 0.6rem;
    font-size: 0.76rem;
    font-weight: 600;
    white-space: nowrap;
}

.upage-status-dot {
    width: 7px;
    height: 7px;
    border-radius: 50%;
    flex-shrink: 0;
    background: currentColor;
}

.upage-status-badge.is-active   { background: #f0fdf4; color: #15803d; border: 1px solid #bbf7d0; }
.upage-status-badge.is-inactive { background: #f8fafc; color: #94a3b8; border: 1px solid #e2e8f0; }

/* Ticket count button */

.upage-ticket-count {
    display: inline-flex;
    align-items: center;
    gap: 0.28rem;
    border: 1px solid #e2eaf5;
    background: #f8fbff;
    color: #334155;
    border-radius: 999px;
    padding: 0.18rem 0.55rem;
    font: inherit;
    font-size: 0.8rem;
    font-weight: 600;
    cursor: pointer;
    transition: background 100ms, border-color 100ms, color 100ms;
}

.upage-ticket-count svg { width: 11px; height: 11px; flex-shrink: 0; }
.upage-ticket-count:hover { background: #e8f2fc; border-color: #94b8d4; color: #1F4E79; }

/* Access cell */

.access-cell { max-width: 260px; }
.access-summary {
    display: block;
    max-width: 260px;
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
    font-size: 0.82rem;
    color: #475569;
}

/* ── Actions menu ─────────────────────────────────────────── */

.actions-menu {
    position: relative;
    display: inline-flex;
}

.menu-trigger {
    width: 30px;
    height: 30px;
    border-radius: 8px;
    border: 1px solid #e2eaf5;
    background: #f8fbff;
    color: #475569;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    cursor: pointer;
    transition: background 100ms, border-color 100ms;
    padding: 0;
}

.menu-trigger svg { width: 16px; height: 16px; }
.menu-trigger:hover { background: #e8f2fc; border-color: #94b8d4; color: #1F4E79; }

.actions-dropdown {
    position: absolute;
    top: calc(100% + 0.35rem);
    right: 0;
    z-index: 25;
    min-width: 180px;
    border: 1px solid #dde8f4;
    border-radius: 10px;
    background: #fff;
    box-shadow: 0 12px 28px rgba(15, 23, 42, 0.15);
    padding: 0.3rem;
    display: flex;
    flex-direction: column;
    gap: 0.08rem;
}

.menu-item {
    width: 100%;
    border: none;
    background: transparent;
    color: #334155;
    text-align: left;
    padding: 0.42rem 0.55rem;
    border-radius: 7px;
    font: inherit;
    font-size: 0.84rem;
    font-weight: 500;
    cursor: pointer;
    display: flex;
    align-items: center;
    gap: 0.52rem;
    transition: background 80ms, color 80ms;
}

.menu-item svg { width: 14px; height: 14px; flex-shrink: 0; color: #64748b; }
.menu-item:hover { background: #f1f5f9; color: #0f172a; }
.menu-item.is-warning { color: #92400e; }
.menu-item.is-warning:hover { background: #fffbeb; }
.menu-item.is-danger { color: #b91c1c; }
.menu-item.is-danger svg { color: #b91c1c; }
.menu-item.is-danger:hover { background: #fef2f2; }

.menu-divider { height: 1px; background: #f1f5f9; margin: 0.2rem 0.3rem; }

/* ── Sort ─────────────────────────────────────────────────── */

.sort-btn {
    border: none;
    background: transparent;
    color: inherit;
    font: inherit;
    font-weight: 700;
    font-size: 0.74rem;
    text-transform: uppercase;
    letter-spacing: 0.04em;
    cursor: pointer;
    padding: 0;
    display: inline-flex;
    align-items: center;
    gap: 0.25rem;
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
    border-left: 3.5px solid transparent;
    border-right: 3.5px solid transparent;
}

.sort-indicator::before { border-bottom: 4.5px solid #94a3b8; }
.sort-indicator::after  { border-top: 4.5px solid #94a3b8; }
.sort-indicator.is-asc::before  { border-bottom-color: #334155; }
.sort-indicator.is-asc::after   { border-top-color: #cbd5e1; }
.sort-indicator.is-desc::before { border-bottom-color: #cbd5e1; }
.sort-indicator.is-desc::after  { border-top-color: #334155; }

/* ── Modal (kept from before) ────────────────────────────── */

.muted { color: #475569; }

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


@media (max-width: 960px) {
    .grid { grid-template-columns: 1fr; }
    .upage-header { flex-direction: column; align-items: flex-start; }
    .u-filter-bar { grid-template-columns: 1fr; }
}

@media (max-width: 640px) {
    .u-inline-filters { flex-wrap: wrap; }
}
</style>
