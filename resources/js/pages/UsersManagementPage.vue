<script setup>
import { computed, onBeforeUnmount, onMounted, reactive, ref } from 'vue';
import { useRouter } from 'vue-router';
import api from '../api/client';

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
const showCreateUserModal = ref(false);

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

const editingInboxesUserId = ref(null);
const editingInboxIds = ref([]);
const editingManagerInboxIds = ref([]);
const latestInviteUrl = ref('');
const openActionsMenuUserId = ref(null);

const isOperatorForm = computed(() => createForm.role === 'operator');

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

    const response = await api.get('/users', { params });
    users.value = response.data.data;
};

const load = async () => {
    loading.value = true;
    error.value = '';

    try {
        await fetchMeta();
        await fetchUsers();
    } catch (exception) {
        error.value = exception?.response?.data?.message || 'Não foi possível carregar utilizadores.';
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
            || 'Não foi possível criar o utilizador.';
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
        error.value = exception?.response?.data?.message || 'Não foi possível atualizar o utilizador.';
    }
};
const toggleAdmin = async (user) => {
    error.value = '';
    success.value = '';

    try {
        await api.patch(`/users/${user.id}`, {
            is_admin: !user.is_admin,
        });

        success.value = 'Permissão de admin atualizada.';
        await fetchUsers();
    } catch (exception) {
        error.value = exception?.response?.data?.message || 'Não foi possível atualizar permissão de admin.';
    }
};

const openInboxEditor = (user) => {
    editingInboxesUserId.value = user.id;
    editingInboxIds.value = user.inboxes.map((inbox) => inbox.id);
    editingManagerInboxIds.value = user.inboxes
        .filter((inbox) => inbox.can_manage_users)
        .map((inbox) => inbox.id);
};

const closeInboxEditor = () => {
    editingInboxesUserId.value = null;
    editingInboxIds.value = [];
    editingManagerInboxIds.value = [];
};

const toggleEditingInbox = (id) => {
    const value = Number(id);

    if (editingInboxIds.value.includes(value)) {
        editingInboxIds.value = editingInboxIds.value.filter((item) => item !== value);
        editingManagerInboxIds.value = editingManagerInboxIds.value.filter((item) => item !== value);
        return;
    }

    editingInboxIds.value = [...editingInboxIds.value, value];
};

const toggleEditingManagerInbox = (id) => {
    const value = Number(id);

    if (!editingInboxIds.value.includes(value)) {
        return;
    }

    if (editingManagerInboxIds.value.includes(value)) {
        editingManagerInboxIds.value = editingManagerInboxIds.value.filter((item) => item !== value);
        return;
    }

    editingManagerInboxIds.value = [...editingManagerInboxIds.value, value];
};

const saveInboxes = async (user) => {
    error.value = '';
    success.value = '';

    try {
        await api.patch(`/users/${user.id}/inboxes`, {
            inbox_ids: editingInboxIds.value,
            manager_inbox_ids: editingManagerInboxIds.value,
        });

        success.value = 'Acessos do operador atualizados.';
        closeInboxEditor();
        await fetchUsers();
    } catch (exception) {
        error.value = exception?.response?.data?.message || 'Não foi possível atualizar acessos.';
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
        error.value = exception?.response?.data?.message || 'Não foi possível gerar convite.';
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
        error.value = exception?.response?.data?.message || 'Não foi possível eliminar utilizador.';
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
    <section class="page">
        <header>
            <h1>Gestão de Utilizadores</h1>
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
                            <p class="field-label">Permissão para gerir utilizadores (por inbox)</p>
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

        <article class="card" v-if="!loading">
            <div class="toolbar">
                <h2>Lista de utilizadores</h2>
                <div class="toolbar-fields">
                    <input v-model="search" placeholder="Procurar por nome ou email" @keyup.enter="fetchUsers" />
                    <select v-model="roleFilter" @change="fetchUsers">
                        <option value="">Todos os perfis</option>
                        <option value="operator">Operadores</option>
                        <option value="client">Clientes</option>
                    </select>
                    <button type="button" @click="fetchUsers">Filtrar</button>
                </div>
            </div>

            <table class="table">
                <thead>
                    <tr>
                        <th>Nome</th>
                        <th>Email</th>
                        <th>Perfil</th>
                        <th>Estado</th>
                        <th>Tickets</th>
                        <th>Acessos</th>
                        <th>Ações</th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-for="user in users" :key="user.id">
                        <td>{{ user.name }}</td>
                        <td>{{ user.email }}</td>
                        <td>{{ user.role === 'operator' ? (user.is_admin ? 'Operador admin' : 'Operador') : 'Cliente' }}</td>
                        <td>{{ user.is_active ? 'Ativo' : 'Inativo' }}</td>
                        <td>
                            <button type="button" class="ticket-count-link" @click="goToUserTickets(user)">
                                {{ user.tickets_count ?? 0 }}
                            </button>
                        </td>
                        <td>
                            <template v-if="user.role === 'operator'">
                                <span v-if="user.inboxes.length === 0" class="muted">Sem inboxes</span>
                                <ul v-else class="flat-list">
                                    <li v-for="inbox in user.inboxes" :key="`user-${user.id}-inbox-${inbox.id}`">
                                        {{ inbox.name }}
                                        <small v-if="inbox.can_manage_users">(gestão)</small>
                                    </li>
                                </ul>
                            </template>
                            <template v-else>
                                <span class="muted">{{ user.primary_contact?.entity?.name || 'Sem entidade' }}</span>
                            </template>
                        </td>
                        <td>
                            <div class="actions-menu">
                                <button
                                    type="button"
                                    class="ghost menu-trigger"
                                    aria-label="Abrir ações"
                                    @click.stop="toggleActionsMenu(user.id)"
                                >
                                    ⋯
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
                                        v-if="user.role === 'operator'"
                                        type="button"
                                        class="menu-item"
                                        @click="openInboxEditor(user); closeActionsMenu()"
                                    >
                                        Gerir acessos
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

                            <div v-if="editingInboxesUserId === user.id" class="editor-box">
                                <p class="field-label">Inboxes do operador</p>
                                <div class="checks">
                                    <label v-for="inbox in manageableInboxes" :key="`edit-inbox-${user.id}-${inbox.id}`">
                                        <input
                                            type="checkbox"
                                            :checked="editingInboxIds.includes(inbox.id)"
                                            @change="toggleEditingInbox(inbox.id)"
                                        />
                                        {{ inbox.name }}
                                    </label>
                                </div>

                                <p class="field-label">Permissão de gestão de utilizadores</p>
                                <div class="checks">
                                    <label v-for="inbox in manageableInboxes" :key="`edit-manager-${user.id}-${inbox.id}`">
                                        <input
                                            type="checkbox"
                                            :checked="editingManagerInboxIds.includes(inbox.id)"
                                            :disabled="!editingInboxIds.includes(inbox.id)"
                                            @change="toggleEditingManagerInbox(inbox.id)"
                                        />
                                        {{ inbox.name }}
                                    </label>
                                </div>

                                <div class="row-actions">
                                    <button type="button" @click="saveInboxes(user)">Guardar acessos</button>
                                    <button type="button" class="ghost" @click="closeInboxEditor">Cancelar</button>
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
    border: 1px solid #a7f3d0;
    background: #ecfdf5;
    color: #065f46;
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
    border: 1px solid #0f766e;
    background: #0f766e;
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
    border-color: #9fd9c2;
    background: #ecfdf5;
    color: #0f766e;
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

.toolbar-fields {
    display: flex;
    gap: 0.55rem;
    flex-wrap: wrap;
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
    vertical-align: top;
}

.flat-list {
    list-style: none;
    margin: 0;
    padding: 0;
    display: grid;
    gap: 0.2rem;
}

.row-actions {
    display: flex;
    flex-wrap: wrap;
    gap: 0.45rem;
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

.editor-box {
    margin-top: 0.6rem;
    border: 1px solid #dbe4ee;
    border-radius: 8px;
    padding: 0.6rem;
    display: grid;
    gap: 0.55rem;
    background: #f8fafc;
}

@media (max-width: 960px) {
    .grid {
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
