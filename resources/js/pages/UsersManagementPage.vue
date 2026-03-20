<script setup>
import { computed, onMounted, reactive, ref } from 'vue';
import api from '../api/client';

const loading = ref(true);
const saving = ref(false);
const error = ref('');
const success = ref('');

const users = ref([]);
const manageableInboxes = ref([]);
const entities = ref([]);
const search = ref('');
const roleFilter = ref('');

const createForm = reactive({
    name: '',
    email: '',
    role: 'operator',
    entity_id: '',
    contact_name: '',
    inbox_ids: [],
    manager_inbox_ids: [],
    is_active: true,
});

const editingInboxesUserId = ref(null);
const editingInboxIds = ref([]);
const editingManagerInboxIds = ref([]);
const latestInviteUrl = ref('');

const isOperatorForm = computed(() => createForm.role === 'operator');

const fetchMeta = async () => {
    const response = await api.get('/users/meta');
    manageableInboxes.value = response.data.data.manageable_inboxes;
    entities.value = response.data.data.entities;
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
        error.value = exception?.response?.data?.message || 'Nao foi possivel carregar utilizadores.';
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
        } else {
            payload.entity_id = Number(createForm.entity_id);
            payload.contact_name = createForm.contact_name || createForm.name;
        }

        const response = await api.post('/users', payload);

        latestInviteUrl.value = response.data.data.invite.url;
        success.value = 'Utilizador criado. Convite gerado.';

        resetCreateForm();
        await fetchUsers();
    } catch (exception) {
        error.value = exception?.response?.data?.message
            || Object.values(exception?.response?.data?.errors || {})?.[0]?.[0]
            || 'Nao foi possivel criar o utilizador.';
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
        error.value = exception?.response?.data?.message || 'Nao foi possivel atualizar o utilizador.';
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
        error.value = exception?.response?.data?.message || 'Nao foi possivel atualizar acessos.';
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
        error.value = exception?.response?.data?.message || 'Nao foi possivel gerar convite.';
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
        error.value = exception?.response?.data?.message || 'Nao foi possivel eliminar utilizador.';
    }
};

onMounted(load);
</script>

<template>
    <section class="page">
        <header>
            <h1>Gestao de Utilizadores</h1>
            <p class="muted">Criar operadores/clientes, gerir acessos a inboxes e gerar convites.</p>
        </header>

        <p v-if="error" class="error">{{ error }}</p>
        <p v-if="success" class="success">{{ success }}</p>

        <div v-if="latestInviteUrl" class="invite-box">
            <strong>Link de convite:</strong>
            <input :value="latestInviteUrl" readonly @focus="$event.target.select()" />
        </div>

        <article class="card" v-if="!loading">
            <h2>Criar utilizador</h2>
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
                        <p class="field-label">Permissao para gerir utilizadores (por inbox)</p>
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

                <div class="full-row actions">
                    <button :disabled="saving" type="submit">
                        {{ saving ? 'A criar...' : 'Criar utilizador' }}
                    </button>
                </div>
            </form>
        </article>

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
                        <th>Acessos</th>
                        <th>Acoes</th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-for="user in users" :key="user.id">
                        <td>{{ user.name }}</td>
                        <td>{{ user.email }}</td>
                        <td>{{ user.role === 'operator' ? 'Operador' : 'Cliente' }}</td>
                        <td>{{ user.is_active ? 'Ativo' : 'Inativo' }}</td>
                        <td>
                            <template v-if="user.role === 'operator'">
                                <span v-if="user.inboxes.length === 0" class="muted">Sem inboxes</span>
                                <ul v-else class="flat-list">
                                    <li v-for="inbox in user.inboxes" :key="`user-${user.id}-inbox-${inbox.id}`">
                                        {{ inbox.name }}
                                        <small v-if="inbox.can_manage_users">(gestao)</small>
                                    </li>
                                </ul>
                            </template>
                            <template v-else>
                                <span class="muted">{{ user.primary_contact?.entity?.name || 'Sem entidade' }}</span>
                            </template>
                        </td>
                        <td>
                            <div class="row-actions">
                                <button type="button" @click="toggleActive(user)">
                                    {{ user.is_active ? 'Desativar' : 'Ativar' }}
                                </button>

                                <button type="button" @click="regenerateInvite(user)">Novo convite</button>

                                <button
                                    v-if="user.role === 'operator'"
                                    type="button"
                                    @click="openInboxEditor(user)"
                                >
                                    Gerir acessos
                                </button>

                                <button type="button" class="danger" @click="deleteUser(user)">Eliminar</button>
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

                                <p class="field-label">Permissao de gestao de utilizadores</p>
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
