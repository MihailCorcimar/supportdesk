<script setup>
import { computed, onMounted, reactive, ref } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import api from '../api/client';
import { useAuthStore } from '../stores/auth';
import UserEditModal from '../components/UserEditModal.vue';

const route = useRoute();
const router = useRouter();
const auth = useAuthStore();

const loading = ref(true);
const saving = ref(false);
const error = ref('');
const success = ref('');
const user = ref(null);
const manageableInboxes = ref([]);
const showUserEditModal = ref(false);
const userEditForm = reactive({
    name: '',
    is_active: true,
    contact_name: '',
    is_admin: false,
    inbox_ids: [],
    manager_inbox_ids: [],
});

const userId = computed(() => Number(route.params.id));
const canEditUser = computed(() => Boolean(auth.state.user?.can_manage_users));
const canManageAdminFlag = computed(() => Boolean(auth.state.user?.is_admin));
const isClientUser = computed(() => user.value?.role === 'client');
const isOperatorUser = computed(() => user.value?.role === 'operator');
const canEditAdminFlag = computed(() => canManageAdminFlag.value && isOperatorUser.value);

const roleLabel = computed(() => {
    if (!user.value) return '-';
    if (isOperatorUser.value) {
        return user.value.is_admin ? 'Operador admin' : 'Operador';
    }
    return 'Cliente';
});

const statusLabel = computed(() => {
    if (!user.value) return '-';
    return user.value.is_active ? 'Ativo' : 'Inativo';
});

const accessSummary = computed(() => {
    if (!user.value) return '-';

    if (isOperatorUser.value) {
        if (!user.value.inboxes?.length) return 'Sem inboxes';
        return user.value.inboxes
            .map((inbox) => `${inbox.name}${inbox.can_manage_users ? ' (gestão)' : ''}`)
            .join(' | ');
    }

    return user.value.primary_contact?.entity?.name || 'Sem entidade';
});

const formatDate = (value) => {
    if (!value) return '-';
    return new Date(value).toLocaleString('pt-PT');
};

const fetchMeta = async () => {
    try {
        const response = await api.get('/users/meta');
        manageableInboxes.value = response.data?.data?.manageable_inboxes || [];
    } catch {
        manageableInboxes.value = [];
    }
};

const loadUser = async () => {
    loading.value = true;
    error.value = '';

    try {
        const response = await api.get(`/users/${userId.value}`);
        user.value = response.data.data;
    } catch (exception) {
        error.value = exception?.response?.data?.message || 'Não foi possível carregar detalhes do utilizador.';
    } finally {
        loading.value = false;
    }
};

const goBack = async () => {
    await router.push({ name: 'users.index' });
};

const fillUserEditForm = () => {
    if (!user.value) return;

    userEditForm.name = user.value.name ?? '';
    userEditForm.is_active = Boolean(user.value.is_active);
    userEditForm.contact_name = user.value.primary_contact?.name ?? '';
    userEditForm.is_admin = Boolean(user.value.is_admin);
    userEditForm.inbox_ids = (user.value.inboxes || []).map((inbox) => Number(inbox.id));
    userEditForm.manager_inbox_ids = (user.value.inboxes || [])
        .filter((inbox) => Boolean(inbox.can_manage_users))
        .map((inbox) => Number(inbox.id));
};

const toggleEditInbox = (id) => {
    const value = Number(id);

    if (userEditForm.inbox_ids.includes(value)) {
        userEditForm.inbox_ids = userEditForm.inbox_ids.filter((item) => item !== value);
        userEditForm.manager_inbox_ids = userEditForm.manager_inbox_ids.filter((item) => item !== value);
        return;
    }

    userEditForm.inbox_ids = [...userEditForm.inbox_ids, value];
};

const toggleEditManagerInbox = (id) => {
    const value = Number(id);

    if (!userEditForm.inbox_ids.includes(value)) {
        return;
    }

    if (userEditForm.manager_inbox_ids.includes(value)) {
        userEditForm.manager_inbox_ids = userEditForm.manager_inbox_ids.filter((item) => item !== value);
        return;
    }

    userEditForm.manager_inbox_ids = [...userEditForm.manager_inbox_ids, value];
};

const openUserEditor = () => {
    if (!user.value) return;
    error.value = '';
    success.value = '';
    fillUserEditForm();
    showUserEditModal.value = true;
};

const closeUserEditor = () => {
    showUserEditModal.value = false;
};

const saveUser = async () => {
    if (!user.value) return;

    saving.value = true;
    error.value = '';
    success.value = '';

    try {
        const payload = {
            name: userEditForm.name,
            is_active: userEditForm.is_active,
        };

        if (isClientUser.value) {
            payload.contact_name = userEditForm.contact_name;
        }

        if (canEditAdminFlag.value) {
            payload.is_admin = userEditForm.is_admin;
        }

        await api.patch(`/users/${user.value.id}`, payload);

        if (isOperatorUser.value) {
            await api.patch(`/users/${user.value.id}/inboxes`, {
                inbox_ids: userEditForm.inbox_ids,
                manager_inbox_ids: userEditForm.manager_inbox_ids,
            });
        }

        success.value = 'Utilizador atualizado com sucesso.';
        showUserEditModal.value = false;
        await loadUser();
    } catch (exception) {
        error.value = exception?.response?.data?.message
            || Object.values(exception?.response?.data?.errors || {})?.[0]?.[0]
            || 'Não foi possível atualizar o utilizador.';
    } finally {
        saving.value = false;
    }
};

onMounted(async () => {
    await Promise.all([fetchMeta(), loadUser()]);
});
</script>

<template>
    <section class="page">
        <div class="header-row">
            <button type="button" class="ghost back-btn" @click="goBack">
                &larr; Lista de utilizadores
            </button>
            <button v-if="user && canEditUser" type="button" class="btn-inline" @click="openUserEditor">
                Editar utilizador
            </button>
        </div>

        <article class="card">
            <p v-if="success" class="success">{{ success }}</p>
            <p v-if="loading" class="muted">A carregar...</p>
            <p v-else-if="error" class="error">{{ error }}</p>

            <template v-else-if="user">
                <header class="user-header">
                    <h1>{{ user.name }}</h1>
                    <p class="muted">{{ user.email }}</p>
                </header>

                <div class="stats-grid">
                    <div class="stat-card">
                        <span class="label">Perfil</span>
                        <strong>{{ roleLabel }}</strong>
                    </div>
                    <div class="stat-card">
                        <span class="label">Estado</span>
                        <strong>{{ statusLabel }}</strong>
                    </div>
                    <div class="stat-card">
                        <span class="label">Tickets criados</span>
                        <strong>{{ user.tickets_count ?? 0 }}</strong>
                    </div>
                    <div class="stat-card">
                        <span class="label">Tickets atribuídos</span>
                        <strong>{{ user.assigned_tickets_count ?? 0 }}</strong>
                    </div>
                    <div class="stat-card">
                        <span class="label">Contactos</span>
                        <strong>{{ user.contacts_count ?? 0 }}</strong>
                    </div>
                    <div class="stat-card stat-card-full">
                        <span class="label">Acessos</span>
                        <strong>{{ accessSummary }}</strong>
                    </div>
                </div>

                <section class="list-section">
                    <h2>Tickets Criados</h2>
                    <table class="table" v-if="(user.created_tickets || []).length">
                        <thead>
                            <tr>
                                <th>Ticket</th>
                                <th>Assunto</th>
                                <th>Estado</th>
                                <th>Prioridade</th>
                                <th>Inbox</th>
                                <th>Entidade</th>
                                <th>Criado</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-for="ticket in user.created_tickets" :key="ticket.id">
                                <td>
                                    <RouterLink :to="{ name: 'tickets.show', params: { id: ticket.id } }" class="ticket-link">
                                        {{ ticket.ticket_number }}
                                    </RouterLink>
                                </td>
                                <td>{{ ticket.subject }}</td>
                                <td>{{ ticket.status }}</td>
                                <td>{{ ticket.priority }}</td>
                                <td>{{ ticket.inbox?.name || '-' }}</td>
                                <td>{{ ticket.entity?.name || '-' }}</td>
                                <td>{{ formatDate(ticket.created_at) }}</td>
                            </tr>
                        </tbody>
                    </table>
                    <p v-else class="muted">Sem tickets criados por este utilizador.</p>
                </section>
            </template>
        </article>

        <UserEditModal
            :open="showUserEditModal"
            :saving="saving"
            :form="userEditForm"
            :is-operator="isOperatorUser"
            :is-client="isClientUser"
            :can-set-admin="canEditAdminFlag"
            :manageable-inboxes="manageableInboxes"
            title="Editar utilizador"
            subtitle="Atualizar dados do utilizador e permissões de acesso."
            @close="closeUserEditor"
            @save="saveUser"
            @toggle-inbox="toggleEditInbox"
            @toggle-manager-inbox="toggleEditManagerInbox"
        />
    </section>
</template>

<style scoped>
.page { display: grid; gap: 1rem; }
.header-row { display: flex; align-items: center; justify-content: space-between; gap: 0.8rem; flex-wrap: wrap; }

.card {
    border: 1px solid #dbe4ee;
    background: #fff;
    border-radius: 12px;
    padding: 0.95rem;
    display: grid;
    gap: 0.9rem;
}

.user-header h1 { margin: 0; }
.user-header p { margin: 0.25rem 0 0; }

.stats-grid {
    display: grid;
    grid-template-columns: repeat(3, minmax(0, 1fr));
    gap: 0.65rem;
}

.stat-card {
    border: 1px solid #e2e8f0;
    border-radius: 10px;
    background: #f8fafc;
    padding: 0.65rem;
    display: grid;
    gap: 0.25rem;
}

.stat-card-full { grid-column: 1 / -1; }
.label { font-size: 0.82rem; color: #64748b; }

.list-section { display: grid; gap: 0.5rem; }
.list-section h2 { margin: 0; font-size: 1.05rem; }

.table { width: 100%; border-collapse: collapse; }
th, td {
    border-bottom: 1px solid #e2e8f0;
    text-align: left;
    padding: 0.55rem 0.45rem;
}

.ticket-link {
    color: #1F4E79;
    text-decoration: none;
    font-weight: 600;
}

.ticket-link:hover { text-decoration: underline; }

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

.muted { color: #475569; }

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
    width: min(860px, calc(100vw - 2rem));
    max-height: calc(100vh - 2rem);
    overflow: auto;
    background: #fff;
    border: 1px solid #dbe4ee;
    border-radius: 14px;
    padding: 0.95rem;
    display: grid;
    gap: 0.8rem;
}

.modal-header h3 { margin: 0; }

.form-grid {
    display: grid;
    grid-template-columns: repeat(2, minmax(0, 1fr));
    gap: 0.7rem;
}

label {
    display: grid;
    gap: 0.3rem;
}

input {
    border: 1px solid #dbe4ee;
    border-radius: 8px;
    padding: 0.52rem 0.6rem;
    font: inherit;
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

.full-row { grid-column: 1 / -1; }

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

.modal-actions {
    display: flex;
    gap: 0.45rem;
    justify-content: flex-end;
}

@media (max-width: 960px) {
    .stats-grid { grid-template-columns: 1fr; }
    .form-grid { grid-template-columns: 1fr; }
}
</style>

