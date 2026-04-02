<script setup>
import { computed, onMounted, reactive, ref } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import api from '../api/client';
import { useAuthStore } from '../stores/auth';

const route = useRoute();
const router = useRouter();
const auth = useAuthStore();

const loading = ref(true);
const saving = ref(false);
const error = ref('');
const success = ref('');
const entity = ref(null);
const showEntityEditModal = ref(false);
const entityEditForm = reactive({
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

const entityId = computed(() => Number(route.params.id));
const canEditEntity = computed(() => Boolean(auth.state.user?.can_manage_users));

const statusLabel = computed(() => {
    if (!entity.value) return '-';
    return entity.value.is_active ? 'Ativa' : 'Inativa';
});

const typeLabel = computed(() => {
    if (!entity.value) return '-';
    return entity.value.type === 'internal' ? 'Interna' : 'Externa';
});

const formatDate = (value) => {
    if (!value) return '-';
    return new Date(value).toLocaleString('pt-PT');
};

const creatorLabel = (ticket) => ticket.creator_user?.name ?? ticket.creator_contact?.name ?? '-';

const loadEntity = async () => {
    loading.value = true;
    error.value = '';

    try {
        const response = await api.get(`/entities/${entityId.value}`);
        entity.value = response.data.data;
    } catch (exception) {
        error.value = exception?.response?.data?.message || 'Não foi possível carregar detalhes da entidade.';
    } finally {
        loading.value = false;
    }
};

const goBack = async () => {
    await router.push({ name: 'tickets.index' });
};

const fillEntityEditForm = () => {
    if (!entity.value) return;

    entityEditForm.type = entity.value.type ?? 'external';
    entityEditForm.name = entity.value.name ?? '';
    entityEditForm.tax_number = entity.value.tax_number ?? '';
    entityEditForm.email = entity.value.email ?? '';
    entityEditForm.phone = entity.value.phone ?? '';
    entityEditForm.mobile_phone = entity.value.mobile_phone ?? '';
    entityEditForm.website = entity.value.website ?? '';
    entityEditForm.address_line = entity.value.address_line ?? '';
    entityEditForm.postal_code = entity.value.postal_code ?? '';
    entityEditForm.city = entity.value.city ?? '';
    entityEditForm.country = entity.value.country ?? 'PT';
    entityEditForm.notes = entity.value.notes ?? '';
    entityEditForm.is_active = Boolean(entity.value.is_active);
};

const openEntityEditor = () => {
    if (!entity.value) return;
    error.value = '';
    success.value = '';
    fillEntityEditForm();
    showEntityEditModal.value = true;
};

const closeEntityEditor = () => {
    showEntityEditModal.value = false;
};

const saveEntity = async () => {
    if (!entity.value) return;

    saving.value = true;
    error.value = '';
    success.value = '';

    try {
        await api.patch(`/entities/${entity.value.id}`, {
            type: entityEditForm.type,
            name: entityEditForm.name,
            tax_number: entityEditForm.tax_number || null,
            email: entityEditForm.email || null,
            phone: entityEditForm.phone || null,
            mobile_phone: entityEditForm.mobile_phone || null,
            website: entityEditForm.website || null,
            address_line: entityEditForm.address_line || null,
            postal_code: entityEditForm.postal_code || null,
            city: entityEditForm.city || null,
            country: entityEditForm.country || 'PT',
            notes: entityEditForm.notes || null,
            is_active: entityEditForm.is_active,
        });

        success.value = 'Entidade atualizada com sucesso.';
        showEntityEditModal.value = false;
        await loadEntity();
    } catch (exception) {
        error.value = exception?.response?.data?.message
            || Object.values(exception?.response?.data?.errors || {})?.[0]?.[0]
            || 'Não foi possível atualizar a entidade.';
    } finally {
        saving.value = false;
    }
};

onMounted(loadEntity);
</script>

<template>
    <section class="page">
        <div class="header-row">
            <button type="button" class="ghost back-btn" @click="goBack">
                &larr; Lista de tickets
            </button>
            <button v-if="entity && canEditEntity" type="button" class="btn-inline" @click="openEntityEditor">
                Editar entidade
            </button>
        </div>

        <article class="card">
            <p v-if="success" class="success">{{ success }}</p>
            <p v-if="loading" class="muted">A carregar...</p>
            <p v-else-if="error" class="error">{{ error }}</p>

            <template v-else-if="entity">
                <header class="entity-header">
                    <h1>{{ entity.name }}</h1>
                    <p class="muted">{{ entity.email || '-' }}</p>
                </header>

                <div class="stats-grid">
                    <div class="stat-card">
                        <span class="label">Tipo</span>
                        <strong>{{ typeLabel }}</strong>
                    </div>
                    <div class="stat-card">
                        <span class="label">Estado</span>
                        <strong>{{ statusLabel }}</strong>
                    </div>
                    <div class="stat-card">
                        <span class="label">NIF</span>
                        <strong>{{ entity.tax_number || '-' }}</strong>
                    </div>
                    <div class="stat-card">
                        <span class="label">Telefone</span>
                        <strong>{{ entity.phone || '-' }}</strong>
                    </div>
                    <div class="stat-card">
                        <span class="label">Telemóvel</span>
                        <strong>{{ entity.mobile_phone || '-' }}</strong>
                    </div>
                    <div class="stat-card">
                        <span class="label">Website</span>
                        <strong>{{ entity.website || '-' }}</strong>
                    </div>
                    <div class="stat-card">
                        <span class="label">Contactos</span>
                        <strong>{{ entity.contacts_count ?? 0 }}</strong>
                    </div>
                    <div class="stat-card">
                        <span class="label">Tickets</span>
                        <strong>{{ entity.tickets_count ?? 0 }}</strong>
                    </div>
                    <div class="stat-card stat-card-full">
                        <span class="label">Morada</span>
                        <strong>{{ entity.address_line || '-' }}, {{ entity.postal_code || '-' }} {{ entity.city || '-' }} ({{ entity.country || '-' }})</strong>
                    </div>
                    <div class="stat-card stat-card-full">
                        <span class="label">Notas internas</span>
                        <strong>{{ entity.notes || '-' }}</strong>
                    </div>
                </div>

                <section class="list-section">
                    <h2>Contactos</h2>
                    <table class="table" v-if="(entity.contacts || []).length">
                        <thead>
                            <tr>
                                <th>Nome</th>
                                <th>Email</th>
                                <th>Função</th>
                                <th>Telefone</th>
                                <th>Utilizador</th>
                                <th>Estado</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-for="contact in entity.contacts" :key="contact.id">
                                <td>{{ contact.name || '-' }}</td>
                                <td>{{ contact.email || '-' }}</td>
                                <td>{{ contact.function || '-' }}</td>
                                <td>{{ contact.phone || contact.mobile_phone || '-' }}</td>
                                <td>{{ contact.user?.name || '-' }}</td>
                                <td>{{ contact.is_active ? 'Ativo' : 'Inativo' }}</td>
                            </tr>
                        </tbody>
                    </table>
                    <p v-else class="muted">Sem contactos associados.</p>
                </section>

                <section class="list-section">
                    <h2>Tickets Associados</h2>
                    <table class="table" v-if="(entity.associated_tickets || []).length">
                        <thead>
                            <tr>
                                <th>Ticket</th>
                                <th>Assunto</th>
                                <th>Autor</th>
                                <th>Estado</th>
                                <th>Prioridade</th>
                                <th>Inbox</th>
                                <th>Criado</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-for="ticket in entity.associated_tickets" :key="ticket.id">
                                <td>
                                    <RouterLink :to="{ name: 'tickets.show', params: { id: ticket.id } }" class="ticket-link">
                                        {{ ticket.ticket_number }}
                                    </RouterLink>
                                </td>
                                <td>{{ ticket.subject }}</td>
                                <td>{{ creatorLabel(ticket) }}</td>
                                <td>{{ ticket.status }}</td>
                                <td>{{ ticket.priority }}</td>
                                <td>{{ ticket.inbox?.name || '-' }}</td>
                                <td>{{ formatDate(ticket.created_at) }}</td>
                            </tr>
                        </tbody>
                    </table>
                    <p v-else class="muted">Sem tickets para esta entidade.</p>
                </section>
            </template>
        </article>

        <section v-if="showEntityEditModal" class="modal-overlay" @click.self="closeEntityEditor">
            <article class="modal-card">
                <header class="modal-header">
                    <h3>Editar entidade</h3>
                </header>

                <form class="form-grid" @submit.prevent="saveEntity">
                    <label>
                        Tipo
                        <select v-model="entityEditForm.type">
                            <option value="external">Externa</option>
                            <option value="internal">Interna</option>
                        </select>
                    </label>

                    <label>
                        Nome
                        <input v-model="entityEditForm.name" required maxlength="255" />
                    </label>

                    <label>
                        NIF
                        <input v-model="entityEditForm.tax_number" maxlength="50" />
                    </label>

                    <label>
                        Email
                        <input v-model="entityEditForm.email" type="email" maxlength="255" />
                    </label>

                    <label>
                        Telefone
                        <input v-model="entityEditForm.phone" maxlength="50" />
                    </label>

                    <label>
                        Telemóvel
                        <input v-model="entityEditForm.mobile_phone" maxlength="50" />
                    </label>

                    <label>
                        Website
                        <input v-model="entityEditForm.website" type="url" maxlength="255" />
                    </label>

                    <label>
                        Morada
                        <input v-model="entityEditForm.address_line" maxlength="255" />
                    </label>

                    <label>
                        Código postal
                        <input v-model="entityEditForm.postal_code" maxlength="20" />
                    </label>

                    <label>
                        Cidade
                        <input v-model="entityEditForm.city" maxlength="120" />
                    </label>

                    <label>
                        País (2 letras)
                        <input v-model="entityEditForm.country" maxlength="2" />
                    </label>

                    <label class="checkbox-line">
                        <input v-model="entityEditForm.is_active" type="checkbox" />
                        Ativa
                    </label>

                    <label class="full-row">
                        Notas internas
                        <textarea v-model="entityEditForm.notes" rows="3"></textarea>
                    </label>

                    <div class="full-row modal-actions">
                        <button type="submit" :disabled="saving">
                            {{ saving ? 'A guardar...' : 'Guardar alterações' }}
                        </button>
                        <button type="button" class="ghost" @click="closeEntityEditor">Cancelar</button>
                    </div>
                </form>
            </article>
        </section>
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

.entity-header h1 { margin: 0; }
.entity-header p { margin: 0.25rem 0 0; }

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
    color: #0f766e;
    text-decoration: none;
    font-weight: 600;
}

.ticket-link:hover { text-decoration: underline; }

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

input,
select,
textarea {
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
