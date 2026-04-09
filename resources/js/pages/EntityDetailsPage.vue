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
const statusLabels = {
    open: 'Aberto',
    in_progress: 'Em tratamento',
    pending: 'Aguardando cliente',
    closed: 'Fechado',
    cancelled: 'Cancelado',
};
const priorityLabels = {
    low: 'Baixa',
    medium: 'Média',
    high: 'Alta',
    urgent: 'Urgente',
};
const statusPillClass = (status) => {
    switch (status) {
        case 'open':
        case 'in_progress':
            return 'sd-pill--info';
        case 'pending':
            return 'sd-pill--warning';
        case 'closed':
            return 'sd-pill--neutral';
        case 'cancelled':
            return 'sd-pill--danger';
        default:
            return 'sd-pill--neutral';
    }
};
const priorityPillClass = (priority) => {
    switch (priority) {
        case 'low':
            return 'sd-pill--success';
        case 'medium':
            return 'sd-pill--warning';
        case 'high':
            return 'sd-pill--orange';
        case 'urgent':
            return 'sd-pill--danger';
        default:
            return 'sd-pill--neutral';
    }
};
const statusLabelFor = (status) => statusLabels[status] || status || '-';
const priorityLabelFor = (priority) => priorityLabels[priority] || priority || '-';

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
                                <td>
                                    <span class="sd-pill" :class="contact.is_active ? 'sd-pill--success' : 'sd-pill--neutral'">
                                        {{ contact.is_active ? 'Ativo' : 'Inativo' }}
                                    </span>
                                </td>
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
                                <td>
                                    <span class="sd-pill" :class="statusPillClass(ticket.status)">
                                        {{ statusLabelFor(ticket.status) }}
                                    </span>
                                </td>
                                <td>
                                    <span class="sd-pill" :class="priorityPillClass(ticket.priority)">
                                        {{ priorityLabelFor(ticket.priority) }}
                                    </span>
                                </td>
                                <td>{{ ticket.inbox?.name || '-' }}</td>
                                <td>{{ formatDate(ticket.created_at) }}</td>
                            </tr>
                        </tbody>
                    </table>
                    <p v-else class="muted">Sem tickets para esta entidade.</p>
                </section>
            </template>
        </article>

        <Teleport to="body">
            <section v-if="showEntityEditModal" class="entity-modal-overlay" @click.self="closeEntityEditor">
                <article class="entity-modal-card">
                    <header class="entity-modal-header">
                        <div class="entity-modal-header-icon">
                            <svg viewBox="0 0 24 24" fill="none" width="20" height="20" aria-hidden="true">
                                <path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/>
                                <path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/>
                            </svg>
                        </div>
                        <div class="entity-modal-header-text">
                            <h3>Editar entidade</h3>
                            <p>Atualiza os campos da entidade selecionada.</p>
                        </div>
                        <button type="button" class="entity-modal-close" aria-label="Fechar" @click="closeEntityEditor">
                            <svg viewBox="0 0 20 20" fill="none" width="16" height="16"><path d="M5 5l10 10M15 5L5 15" stroke="currentColor" stroke-width="1.9" stroke-linecap="round"/></svg>
                        </button>
                    </header>

                    <form @submit.prevent="saveEntity">
                        <div class="entity-modal-body">
                            <div class="emf-section">
                                <p class="emf-section-title">Identificação</p>
                                <div class="emf-grid emf-grid-id">
                                    <label class="emf-field">
                                        <span>Tipo</span>
                                        <select v-model="entityEditForm.type">
                                            <option value="external">Externa</option>
                                            <option value="internal">Interna</option>
                                        </select>
                                    </label>
                                    <label class="emf-field emf-col-grow">
                                        <span>Nome <em class="emf-required">*</em></span>
                                        <input v-model="entityEditForm.name" required placeholder="Nome da entidade" />
                                    </label>
                                    <label class="emf-field">
                                        <span>NIF</span>
                                        <input v-model="entityEditForm.tax_number" placeholder="000 000 000" />
                                    </label>
                                </div>
                            </div>

                            <div class="emf-section">
                                <p class="emf-section-title">Contacto</p>
                                <div class="emf-grid emf-grid-2">
                                    <label class="emf-field emf-col-2">
                                        <span>Email</span>
                                        <input v-model="entityEditForm.email" type="email" placeholder="email@empresa.pt" />
                                    </label>
                                    <label class="emf-field">
                                        <span>Telefone</span>
                                        <input v-model="entityEditForm.phone" placeholder="Telefone" />
                                    </label>
                                    <label class="emf-field">
                                        <span>Telemóvel</span>
                                        <input v-model="entityEditForm.mobile_phone" placeholder="Telemóvel" />
                                    </label>
                                    <label class="emf-field emf-col-2">
                                        <span>Website</span>
                                        <input v-model="entityEditForm.website" type="url" placeholder="https://empresa.pt" />
                                    </label>
                                </div>
                            </div>

                            <div class="emf-section">
                                <p class="emf-section-title">Localização</p>
                                <div class="emf-grid emf-grid-loc">
                                    <label class="emf-field emf-col-loc-full">
                                        <span>Morada</span>
                                        <input v-model="entityEditForm.address_line" placeholder="Rua, nº, piso" />
                                    </label>
                                    <label class="emf-field">
                                        <span>Código postal</span>
                                        <input v-model="entityEditForm.postal_code" placeholder="0000-000" />
                                    </label>
                                    <label class="emf-field">
                                        <span>Cidade</span>
                                        <input v-model="entityEditForm.city" placeholder="Cidade" />
                                    </label>
                                    <label class="emf-field">
                                        <span>País</span>
                                        <input v-model="entityEditForm.country" placeholder="PT" maxlength="2" />
                                    </label>
                                </div>
                            </div>

                            <div class="emf-section emf-section-last">
                                <button
                                    type="button"
                                    class="emf-toggle-row"
                                    @click="entityEditForm.is_active = !entityEditForm.is_active"
                                >
                                    <span class="emf-toggle-label">Entidade ativa</span>
                                    <span class="emf-toggle-btn" :class="entityEditForm.is_active ? 'emf-toggle-on' : ''" aria-hidden="true">
                                        <span class="emf-toggle-thumb"></span>
                                    </span>
                                </button>

                                <label class="emf-field">
                                    <span>Notas internas</span>
                                    <textarea v-model="entityEditForm.notes" rows="3" placeholder="Observações visíveis apenas internamente..."></textarea>
                                </label>
                            </div>
                        </div>

                        <footer class="entity-modal-footer">
                            <button type="button" class="ghost" @click="closeEntityEditor">Cancelar</button>
                            <button type="submit" :disabled="saving">
                                {{ saving ? 'A guardar...' : 'Guardar alterações' }}
                            </button>
                        </footer>
                    </form>
                </article>
            </section>
        </Teleport>
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
.table thead th {
    text-transform: uppercase;
    letter-spacing: 0.05em;
    font-size: 0.74rem;
    font-weight: 700;
    color: #64748b;
    background: #f8fbff;
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

/* Entity modal (aligned with management/user/ticket UI) */
.entity-modal-overlay {
    position: fixed;
    inset: 0;
    z-index: 80;
    background: rgba(10, 18, 36, 0.5);
    backdrop-filter: blur(3px);
    display: flex;
    align-items: center;
    justify-content: center;
    padding: 1rem;
}

.entity-modal-card {
    width: min(700px, calc(100vw - 2rem));
    max-height: calc(100vh - 2rem);
    display: flex;
    flex-direction: column;
    background: #fff;
    border: 1px solid #e2e8f0;
    border-radius: 18px;
    box-shadow: 0 24px 60px rgba(10, 18, 36, 0.22), 0 6px 18px rgba(10, 18, 36, 0.1);
    overflow: auto;
}

.entity-modal-header {
    display: flex;
    align-items: center;
    gap: 0.85rem;
    padding: 1.1rem 1.3rem;
    border-bottom: 1px solid #f1f5f9;
    background: #fafbfd;
    flex-shrink: 0;
}

.entity-modal-header-icon {
    width: 42px;
    height: 42px;
    border-radius: 12px;
    background: linear-gradient(135deg, #1F4E79 0%, #2563a8 100%);
    color: #fff;
    display: flex;
    align-items: center;
    justify-content: center;
    flex-shrink: 0;
}

.entity-modal-header-text {
    flex: 1;
    min-width: 0;
}

.entity-modal-header-text h3 {
    margin: 0;
    font-size: 1.05rem;
    font-weight: 700;
    color: #0f172a;
}

.entity-modal-header-text p {
    margin: 0.15rem 0 0;
    font-size: 0.82rem;
    color: #64748b;
}

.entity-modal-close {
    width: 34px;
    height: 34px;
    border-radius: 10px;
    border: 1px solid #e2e8f0;
    background: #fff;
    color: #64748b;
    display: flex;
    align-items: center;
    justify-content: center;
    cursor: pointer;
    flex-shrink: 0;
    padding: 0;
    transition: background 100ms, border-color 100ms, color 100ms;
}

.entity-modal-close:hover {
    background: #f8fafc;
    border-color: #cbd5e1;
    color: #1e293b;
}

.entity-modal-body {
    flex: 1;
    overflow-y: visible;
    padding: 1.1rem 1.3rem;
    display: flex;
    flex-direction: column;
    gap: 0;
}

.emf-section {
    padding-bottom: 1.1rem;
    margin-bottom: 1.1rem;
    border-bottom: 1px solid #f1f5f9;
}

.emf-section-last {
    padding-bottom: 0;
    margin-bottom: 0;
    border-bottom: none;
}

.emf-section-title {
    margin: 0 0 0.7rem;
    font-size: 0.72rem;
    font-weight: 700;
    color: #94a3b8;
    text-transform: uppercase;
    letter-spacing: 0.06em;
}

.emf-grid {
    display: grid;
    grid-template-columns: repeat(3, minmax(0, 1fr));
    gap: 0.65rem;
}

.emf-field {
    display: flex;
    flex-direction: column;
    gap: 0.28rem;
}

.emf-field span {
    font-size: 0.8rem;
    font-weight: 600;
    color: #475569;
}

.emf-field input,
.emf-field select,
.emf-field textarea {
    border: 1px solid #e2e8f0;
    border-radius: 9px;
    padding: 0.46rem 0.62rem;
    font: inherit;
    font-size: 0.9rem;
    color: #0f172a;
    background: #fff;
    transition: border-color 140ms, box-shadow 140ms;
}

.emf-field input:focus,
.emf-field select:focus,
.emf-field textarea:focus {
    outline: none;
    border-color: #1F4E79;
    box-shadow: 0 0 0 3px rgba(31, 78, 121, 0.1);
}

.emf-field textarea {
    resize: vertical;
    min-height: 72px;
}

.emf-grid-id { grid-template-columns: minmax(110px, 1fr) 2fr minmax(110px, 1fr); }
.emf-grid-2 { grid-template-columns: 1fr 1fr; }
.emf-grid-loc { grid-template-columns: 1fr 1fr 1fr; }

.emf-col-2 { grid-column: span 2; }
.emf-col-3 { grid-column: span 3; }
.emf-col-grow { grid-column: auto; }
.emf-col-loc-full { grid-column: span 3; }

.emf-required {
    font-style: normal;
    color: #e11d48;
    margin-left: 1px;
}

.emf-toggle-row {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 1rem;
    padding: 0.6rem 0.8rem;
    border: 1px solid #e2e8f0;
    border-radius: 10px;
    background: #f8fafc;
    cursor: pointer;
    margin-bottom: 0.65rem;
}

.emf-toggle-label {
    font-size: 0.88rem;
    font-weight: 600;
    color: #334155;
}

.emf-toggle-btn {
    width: 44px;
    height: 24px;
    border-radius: 999px;
    border: none;
    background: #cbd5e1;
    padding: 2px;
    cursor: pointer;
    transition: background 200ms;
    position: relative;
    flex-shrink: 0;
}

.emf-toggle-btn.emf-toggle-on { background: #1F4E79; }

.emf-toggle-thumb {
    display: block;
    width: 20px;
    height: 20px;
    border-radius: 50%;
    background: #fff;
    box-shadow: 0 1px 4px rgba(0,0,0,0.2);
    transition: transform 200ms;
}

.emf-toggle-on .emf-toggle-thumb { transform: translateX(20px); }

.entity-modal-footer {
    display: flex;
    align-items: center;
    justify-content: flex-end;
    gap: 0.6rem;
    padding: 0.9rem 1.3rem;
    border-top: 1px solid #f1f5f9;
    background: #fafbfd;
    flex-shrink: 0;
}

.entity-modal-footer button {
    padding: 0.5rem 1.1rem;
    font-size: 0.9rem;
    border-radius: 10px;
}

@media (max-width: 960px) {
    .stats-grid { grid-template-columns: 1fr; }
    .form-grid { grid-template-columns: 1fr; }
}
</style>
