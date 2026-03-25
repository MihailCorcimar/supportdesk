<script setup>
import { computed, onMounted, reactive, ref, watch } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import api from '../api/client';

const route = useRoute();
const router = useRouter();

const loading = ref(true);
const error = ref('');
const success = ref('');
const allowedTabs = ['inboxes', 'entities', 'contacts', 'logs'];
const normalizeTab = (tab) => (allowedTabs.includes(tab) ? tab : 'inboxes');
const activeTab = ref(normalizeTab(typeof route.query.tab === 'string' ? route.query.tab : 'inboxes'));

const inboxes = ref([]);
const entities = ref([]);
const contacts = ref([]);
const contactFunctions = ref([]);
const users = ref([]);
const logs = ref([]);

const inboxForm = reactive({ name: '', is_active: true });
const entityForm = reactive({
    type: 'external',
    name: '',
    tax_number: '',
    email: '',
    phone: '',
    mobile_phone: '',
    website: '',
    address_line: '',
    postal_code: '',
    city: '',
    country: 'PT',
    notes: '',
    is_active: true,
});
const contactForm = reactive({
    entity_ids: [],
    function_id: '',
    user_id: '',
    name: '',
    email: '',
    phone: '',
    mobile_phone: '',
    internal_notes: '',
    is_active: true,
});
const logFilters = reactive({ search: '', action: '', actor_type: '' });

const editingInboxId = ref(null);
const editingEntityId = ref(null);
const editingContactId = ref(null);

const userOptions = computed(() => users.value.filter((user) => user.role === 'client'));
const tabLabel = {
    inboxes: 'Inboxes',
    entities: 'Entidades',
    contacts: 'Contactos',
    logs: 'Ticket logs',
};

const loadBaseData = async () => {
    const [inboxesResponse, entitiesResponse, contactsResponse, usersResponse] = await Promise.all([
        api.get('/inboxes', { params: { per_page: 100 } }),
        api.get('/entities', { params: { per_page: 100 } }),
        api.get('/contacts', { params: { per_page: 100 } }),
        api.get('/users', { params: { per_page: 100 } }),
    ]);

    inboxes.value = inboxesResponse.data.data;
    entities.value = entitiesResponse.data.data;
    contacts.value = contactsResponse.data.data;
    contactFunctions.value = contactsResponse.data.options?.functions ?? [];
    users.value = usersResponse.data.data;
};

const loadLogs = async () => {
    const params = { per_page: 50 };

    if (logFilters.search.trim()) params.search = logFilters.search.trim();
    if (logFilters.action.trim()) params.action = logFilters.action.trim();
    if (logFilters.actor_type.trim()) params.actor_type = logFilters.actor_type.trim();

    const response = await api.get('/ticket-logs', { params });
    logs.value = response.data.data;
};

const loadAll = async () => {
    loading.value = true;
    error.value = '';

    try {
        await loadBaseData();
        await loadLogs();
    } catch (exception) {
        error.value = exception?.response?.data?.message || 'Nao foi possivel carregar configuracao.';
    } finally {
        loading.value = false;
    }
};

const resetMessages = () => {
    error.value = '';
    success.value = '';
};

const toggleContactEntity = (target, entityId) => {
    const normalizedId = Number(entityId);
    if (!Array.isArray(target.entity_ids)) {
        target.entity_ids = [];
    }

    if (target.entity_ids.includes(normalizedId)) {
        target.entity_ids = target.entity_ids.filter((id) => id !== normalizedId);
        return;
    }

    target.entity_ids = [...target.entity_ids, normalizedId];
};

const createInbox = async () => {
    resetMessages();

    try {
        await api.post('/inboxes', inboxForm);
        success.value = 'Inbox criada com sucesso.';
        inboxForm.name = '';
        inboxForm.is_active = true;
        await loadBaseData();
    } catch (exception) {
        error.value = exception?.response?.data?.message || 'Falha ao criar inbox.';
    }
};

const saveInbox = async (inbox) => {
    resetMessages();

    try {
        await api.patch(`/inboxes/${inbox.id}`, {
            name: inbox.name,
            is_active: inbox.is_active,
        });
        success.value = 'Inbox atualizada.';
        editingInboxId.value = null;
        await loadBaseData();
    } catch (exception) {
        error.value = exception?.response?.data?.message || 'Falha ao atualizar inbox.';
    }
};

const deleteInbox = async (inbox) => {
    if (!window.confirm(`Eliminar inbox ${inbox.name}?`)) return;

    resetMessages();

    try {
        await api.delete(`/inboxes/${inbox.id}`);
        success.value = 'Inbox eliminada.';
        await loadBaseData();
    } catch (exception) {
        error.value = exception?.response?.data?.message || 'Falha ao eliminar inbox.';
    }
};

const createEntity = async () => {
    resetMessages();

    try {
        await api.post('/entities', entityForm);
        success.value = 'Entidade criada com sucesso.';
        entityForm.type = 'external';
        entityForm.name = '';
        entityForm.tax_number = '';
        entityForm.email = '';
        entityForm.phone = '';
        entityForm.mobile_phone = '';
        entityForm.website = '';
        entityForm.address_line = '';
        entityForm.postal_code = '';
        entityForm.city = '';
        entityForm.country = 'PT';
        entityForm.notes = '';
        entityForm.is_active = true;
        await loadBaseData();
    } catch (exception) {
        error.value = exception?.response?.data?.message || 'Falha ao criar entidade.';
    }
};

const saveEntity = async (entity) => {
    resetMessages();

    try {
        await api.patch(`/entities/${entity.id}`, {
            type: entity.type,
            name: entity.name,
            tax_number: entity.tax_number,
            email: entity.email,
            phone: entity.phone,
            mobile_phone: entity.mobile_phone,
            website: entity.website,
            address_line: entity.address_line,
            postal_code: entity.postal_code,
            city: entity.city,
            country: entity.country,
            notes: entity.notes,
            is_active: entity.is_active,
        });
        success.value = 'Entidade atualizada.';
        editingEntityId.value = null;
        await loadBaseData();
    } catch (exception) {
        error.value = exception?.response?.data?.message || 'Falha ao atualizar entidade.';
    }
};

const deleteEntity = async (entity) => {
    if (!window.confirm(`Eliminar entidade ${entity.name}?`)) return;

    resetMessages();

    try {
        await api.delete(`/entities/${entity.id}`);
        success.value = 'Entidade eliminada.';
        await loadBaseData();
    } catch (exception) {
        error.value = exception?.response?.data?.message || 'Falha ao eliminar entidade.';
    }
};

const createContact = async () => {
    resetMessages();

    try {
        await api.post('/contacts', {
            entity_ids: contactForm.entity_ids.map((id) => Number(id)),
            function_id: contactForm.function_id ? Number(contactForm.function_id) : null,
            user_id: contactForm.user_id ? Number(contactForm.user_id) : null,
            name: contactForm.name,
            email: contactForm.email,
            phone: contactForm.phone || null,
            mobile_phone: contactForm.mobile_phone || null,
            internal_notes: contactForm.internal_notes || null,
            is_active: contactForm.is_active,
        });
        success.value = 'Contacto criado com sucesso.';
        contactForm.entity_ids = [];
        contactForm.function_id = '';
        contactForm.user_id = '';
        contactForm.name = '';
        contactForm.email = '';
        contactForm.phone = '';
        contactForm.mobile_phone = '';
        contactForm.internal_notes = '';
        contactForm.is_active = true;
        await loadBaseData();
    } catch (exception) {
        error.value = exception?.response?.data?.message || 'Falha ao criar contacto.';
    }
};

const saveContact = async (contact) => {
    resetMessages();

    try {
        await api.patch(`/contacts/${contact.id}`, {
            entity_ids: (contact.entity_ids || []).map((id) => Number(id)),
            function_id: contact.function_id ? Number(contact.function_id) : null,
            user_id: contact.user_id ? Number(contact.user_id) : null,
            name: contact.name,
            email: contact.email,
            phone: contact.phone,
            mobile_phone: contact.mobile_phone,
            internal_notes: contact.internal_notes,
            is_active: contact.is_active,
        });
        success.value = 'Contacto atualizado.';
        editingContactId.value = null;
        await loadBaseData();
    } catch (exception) {
        error.value = exception?.response?.data?.message || 'Falha ao atualizar contacto.';
    }
};

const deleteContact = async (contact) => {
    if (!window.confirm(`Eliminar contacto ${contact.name}?`)) return;

    resetMessages();

    try {
        await api.delete(`/contacts/${contact.id}`);
        success.value = 'Contacto eliminado.';
        await loadBaseData();
    } catch (exception) {
        error.value = exception?.response?.data?.message || 'Falha ao eliminar contacto.';
    }
};

const refreshLogs = async () => {
    resetMessages();

    try {
        await loadLogs();
        success.value = 'Logs atualizados.';
    } catch (exception) {
        error.value = exception?.response?.data?.message || 'Falha ao carregar logs.';
    }
};

const formatDate = (value) => {
    if (!value) return '-';
    return new Date(value).toLocaleString('pt-PT');
};

watch(
    () => route.query.tab,
    (tabFromQuery) => {
        const normalized = normalizeTab(typeof tabFromQuery === 'string' ? tabFromQuery : 'inboxes');
        if (normalized !== activeTab.value) {
            activeTab.value = normalized;
        }
    }
);

watch(activeTab, (tab) => {
    const normalized = normalizeTab(tab);
    const current = normalizeTab(typeof route.query.tab === 'string' ? route.query.tab : 'inboxes');

    if (normalized !== current) {
        router.replace({
            query: {
                ...route.query,
                tab: normalized,
            },
        });
    }
});

onMounted(loadAll);
</script>

<template>
    <section class="page">
        <header class="header-row">
            <div>
                <h1>Configuracao operacional</h1>
                <p class="muted">CRUD de inboxes, entidades, contactos e consulta dedicada de ticket logs.</p>
            </div>
            <button class="btn-secondary" @click="loadAll">Atualizar</button>
        </header>

        <p v-if="error" class="error">{{ error }}</p>
        <p v-if="success" class="success">{{ success }}</p>

        <article class="card">
            <div class="tabs">
                <button
                    v-for="tab in Object.keys(tabLabel)"
                    :key="tab"
                    type="button"
                    :class="['tab', { active: activeTab === tab }]"
                    @click="activeTab = tab"
                >
                    {{ tabLabel[tab] }}
                </button>
            </div>

            <p v-if="loading" class="muted">A carregar...</p>

            <template v-if="!loading && activeTab === 'inboxes'">
                <h2>Inboxes</h2>
                <form class="form-grid" @submit.prevent="createInbox">
                    <label>Nome <input v-model="inboxForm.name" required /></label>
                    <label class="checkbox"><input v-model="inboxForm.is_active" type="checkbox" />Ativa</label>
                    <button type="submit" class="btn-inline">Criar inbox</button>
                </form>

                <table class="table">
                    <thead>
                        <tr>
                            <th>Nome</th>
                            <th>Ativa</th>
                            <th>Tickets</th>
                            <th>Operadores</th>
                            <th>Acoes</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="item in inboxes" :key="item.id">
                            <td><input v-model="item.name" :disabled="editingInboxId !== item.id" /></td>
                            <td><input v-model="item.is_active" type="checkbox" :disabled="editingInboxId !== item.id" /></td>
                            <td>{{ item.tickets_count }}</td>
                            <td>{{ item.operators_count }}</td>
                            <td class="row-actions">
                                <button v-if="editingInboxId !== item.id" type="button" @click="editingInboxId = item.id">Editar</button>
                                <button v-else type="button" @click="saveInbox(item)">Guardar</button>
                                <button type="button" class="ghost" @click="editingInboxId = null">Cancelar</button>
                                <button type="button" class="danger" @click="deleteInbox(item)">Eliminar</button>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </template>

            <template v-if="!loading && activeTab === 'entities'">
                <h2>Entidades</h2>
                <form class="form-grid" @submit.prevent="createEntity">
                    <label>Tipo
                        <select v-model="entityForm.type">
                            <option value="external">Externa</option>
                            <option value="internal">Interna</option>
                        </select>
                    </label>
                    <label>Nome <input v-model="entityForm.name" required /></label>
                    <label>NIF <input v-model="entityForm.tax_number" /></label>
                    <label>Email <input v-model="entityForm.email" type="email" /></label>
                    <label>Telefone <input v-model="entityForm.phone" /></label>
                    <label>Telemovel <input v-model="entityForm.mobile_phone" /></label>
                    <label>Website <input v-model="entityForm.website" type="url" placeholder="https://..." /></label>
                    <label>Morada <input v-model="entityForm.address_line" /></label>
                    <label>Codigo postal <input v-model="entityForm.postal_code" maxlength="20" /></label>
                    <label>Cidade <input v-model="entityForm.city" /></label>
                    <label>Pais (2 letras) <input v-model="entityForm.country" maxlength="2" /></label>
                    <label class="checkbox"><input v-model="entityForm.is_active" type="checkbox" />Ativa</label>
                    <label class="full">Notas internas <textarea v-model="entityForm.notes" rows="2"></textarea></label>
                    <button type="submit" class="full">Criar entidade</button>
                </form>

                <table class="table">
                    <thead>
                        <tr>
                            <th>Nome</th>
                            <th>Tipo</th>
                            <th>Email</th>
                            <th>Telefone</th>
                            <th>Telemovel</th>
                            <th>NIF</th>
                            <th>Website</th>
                            <th>Notas internas</th>
                            <th>Ativa</th>
                            <th>Contactos</th>
                            <th>Tickets</th>
                            <th>Acoes</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="item in entities" :key="item.id">
                            <td><input v-model="item.name" :disabled="editingEntityId !== item.id" /></td>
                            <td>
                                <select v-model="item.type" :disabled="editingEntityId !== item.id">
                                    <option value="external">external</option>
                                    <option value="internal">internal</option>
                                </select>
                            </td>
                            <td><input v-model="item.email" :disabled="editingEntityId !== item.id" /></td>
                            <td><input v-model="item.phone" :disabled="editingEntityId !== item.id" /></td>
                            <td><input v-model="item.mobile_phone" :disabled="editingEntityId !== item.id" /></td>
                            <td><input v-model="item.tax_number" :disabled="editingEntityId !== item.id" /></td>
                            <td><input v-model="item.website" :disabled="editingEntityId !== item.id" /></td>
                            <td><input v-model="item.notes" :disabled="editingEntityId !== item.id" /></td>
                            <td><input v-model="item.is_active" type="checkbox" :disabled="editingEntityId !== item.id" /></td>
                            <td>{{ item.contacts_count }}</td>
                            <td>{{ item.tickets_count }}</td>
                            <td class="row-actions">
                                <button v-if="editingEntityId !== item.id" type="button" @click="editingEntityId = item.id">Editar</button>
                                <button v-else type="button" @click="saveEntity(item)">Guardar</button>
                                <button type="button" class="ghost" @click="editingEntityId = null">Cancelar</button>
                                <button type="button" class="danger" @click="deleteEntity(item)">Eliminar</button>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </template>

            <template v-if="!loading && activeTab === 'contacts'">
                <h2>Contactos</h2>
                <form class="form-grid" @submit.prevent="createContact">
                    <label class="full">Entidades relacionadas
                        <div class="checks">
                            <label v-for="entity in entities" :key="`new-contact-entity-${entity.id}`" class="checkbox">
                                <input
                                    type="checkbox"
                                    :checked="contactForm.entity_ids.includes(entity.id)"
                                    @change="toggleContactEntity(contactForm, entity.id)"
                                />
                                {{ entity.name }}
                            </label>
                        </div>
                    </label>
                    <label>Utilizador (opcional)
                        <select v-model="contactForm.user_id">
                            <option value="">Sem associacao</option>
                            <option v-for="user in userOptions" :key="user.id" :value="String(user.id)">
                                {{ user.name }} ({{ user.email }})
                            </option>
                        </select>
                    </label>
                    <label>Funcao
                        <select v-model="contactForm.function_id">
                            <option value="">Sem funcao</option>
                            <option v-for="option in contactFunctions" :key="option.id" :value="String(option.id)">
                                {{ option.name }}
                            </option>
                        </select>
                    </label>
                    <label>Nome <input v-model="contactForm.name" required /></label>
                    <label>Email <input v-model="contactForm.email" type="email" required /></label>
                    <label>Telefone <input v-model="contactForm.phone" /></label>
                    <label>Telemovel <input v-model="contactForm.mobile_phone" /></label>
                    <label class="full">Notas internas <textarea v-model="contactForm.internal_notes" rows="2"></textarea></label>
                    <label class="checkbox"><input v-model="contactForm.is_active" type="checkbox" />Ativo</label>
                    <button type="submit" class="btn-inline">Criar contacto</button>
                </form>

                <table class="table">
                    <thead>
                        <tr>
                            <th>Nome</th>
                            <th>Funcao</th>
                            <th>Email</th>
                            <th>Telefone</th>
                            <th>Telemovel</th>
                            <th>Entidades</th>
                            <th>Notas internas</th>
                            <th>Ativo</th>
                            <th>Acoes</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="item in contacts" :key="item.id">
                            <td><input v-model="item.name" :disabled="editingContactId !== item.id" /></td>
                            <td>
                                <select v-model="item.function_id" :disabled="editingContactId !== item.id">
                                    <option :value="null">Sem funcao</option>
                                    <option v-for="option in contactFunctions" :key="`contact-${item.id}-function-${option.id}`" :value="option.id">
                                        {{ option.name }}
                                    </option>
                                </select>
                            </td>
                            <td><input v-model="item.email" :disabled="editingContactId !== item.id" /></td>
                            <td><input v-model="item.phone" :disabled="editingContactId !== item.id" /></td>
                            <td><input v-model="item.mobile_phone" :disabled="editingContactId !== item.id" /></td>
                            <td>
                                <div v-if="editingContactId === item.id" class="checks compact">
                                    <label v-for="entity in entities" :key="`contact-${item.id}-entity-${entity.id}`" class="checkbox">
                                        <input
                                            type="checkbox"
                                            :checked="(item.entity_ids || []).includes(entity.id)"
                                            @change="toggleContactEntity(item, entity.id)"
                                        />
                                        {{ entity.name }}
                                    </label>
                                </div>
                                <span v-else>
                                    {{ (item.entities || []).map((entity) => entity.name).join(', ') || '-' }}
                                </span>
                            </td>
                            <td><input v-model="item.internal_notes" :disabled="editingContactId !== item.id" /></td>
                            <td><input v-model="item.is_active" type="checkbox" :disabled="editingContactId !== item.id" /></td>
                            <td class="row-actions">
                                <button v-if="editingContactId !== item.id" type="button" @click="editingContactId = item.id">Editar</button>
                                <button v-else type="button" @click="saveContact(item)">Guardar</button>
                                <button type="button" class="ghost" @click="editingContactId = null">Cancelar</button>
                                <button type="button" class="danger" @click="deleteContact(item)">Eliminar</button>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </template>

            <template v-if="!loading && activeTab === 'logs'">
                <h2>Ticket logs</h2>
                <form class="filters" @submit.prevent="refreshLogs">
                    <label>Pesquisa <input v-model="logFilters.search" placeholder="ticket ou acao" /></label>
                    <label>Acao <input v-model="logFilters.action" placeholder="status_updated" /></label>
                    <label>Ator
                        <select v-model="logFilters.actor_type">
                            <option value="">Todos</option>
                            <option value="user">user</option>
                            <option value="contact">contact</option>
                            <option value="system">system</option>
                        </select>
                    </label>
                    <button type="submit">Filtrar logs</button>
                </form>

                <table class="table">
                    <thead>
                        <tr>
                            <th>Data</th>
                            <th>Ticket</th>
                            <th>Acao</th>
                            <th>Campo</th>
                            <th>Ator</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="item in logs" :key="item.id">
                            <td>{{ formatDate(item.created_at) }}</td>
                            <td>{{ item.ticket?.ticket_number ?? '-' }}</td>
                            <td>{{ item.action }}</td>
                            <td>{{ item.field ?? '-' }}</td>
                            <td>{{ item.actor_user?.name || item.actor_contact?.name || item.actor_type }}</td>
                        </tr>
                        <tr v-if="!logs.length">
                            <td colspan="5" class="muted">Sem logs para os filtros atuais.</td>
                        </tr>
                    </tbody>
                </table>
            </template>
        </article>
    </section>
</template>

<style scoped>
.page { display: grid; gap: 1rem; }
.header-row { display: flex; justify-content: space-between; align-items: center; gap: 0.8rem; }
h1, h2 { margin: 0; }

.card {
    background: #fff;
    border: 1px solid #dbe4ee;
    border-radius: 12px;
    padding: 1rem;
    display: grid;
    gap: 0.85rem;
}

.tabs { display: flex; flex-wrap: wrap; gap: 0.5rem; }
.tab {
    border: 1px solid #dbe4ee;
    background: #fff;
    color: #0f172a;
    border-radius: 8px;
    padding: 0.45rem 0.7rem;
    cursor: pointer;
}
.tab.active {
    border-color: #0f766e;
    background: #0f766e;
    color: #fff;
}

.form-grid {
    display: grid;
    grid-template-columns: repeat(3, minmax(0, 1fr));
    gap: 0.6rem;
}

.filters {
    display: grid;
    grid-template-columns: repeat(4, minmax(0, 1fr));
    gap: 0.6rem;
}

label { display: grid; gap: 0.25rem; color: #334155; }
input, select, button, textarea {
    font: inherit;
    border: 1px solid #dbe4ee;
    border-radius: 8px;
    padding: 0.48rem 0.58rem;
}
button {
    background: #0f766e;
    color: #fff;
    border-color: #0f766e;
    cursor: pointer;
}
button.ghost {
    background: #fff;
    color: #0f172a;
    border-color: #cbd5e1;
}
button.danger {
    background: #b91c1c;
    border-color: #b91c1c;
}

.checkbox { display: flex; align-items: center; gap: 0.4rem; }
.checkbox input { width: auto; }
.checks {
    display: flex;
    flex-wrap: wrap;
    gap: 0.45rem 0.8rem;
}
.checks.compact {
    gap: 0.35rem 0.65rem;
}
.full { grid-column: 1 / -1; }

.table { width: 100%; border-collapse: collapse; }
th, td {
    text-align: left;
    border-bottom: 1px solid #e5edf5;
    padding: 0.55rem 0.45rem;
}
.row-actions { display: flex; flex-wrap: wrap; gap: 0.4rem; }

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
.muted { color: #475569; }
.btn-secondary {
    border: 1px solid #dbe4ee;
    background: #fff;
    color: #0f172a;
}
.btn-inline {
    width: auto;
    justify-self: start;
    align-self: end;
    min-width: 140px;
}

@media (max-width: 960px) {
    .form-grid,
    .filters { grid-template-columns: 1fr; }

    .table,
    .table thead,
    .table tbody,
    .table th,
    .table td,
    .table tr { display: block; }

    .table thead { display: none; }

    .table tr {
        border: 1px solid #dbe4ee;
        border-radius: 8px;
        margin-bottom: 0.7rem;
        padding: 0.45rem;
    }

    .table td { border: none; padding: 0.25rem 0.15rem; }
}
</style>
