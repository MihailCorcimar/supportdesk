<script setup>
import { computed, onMounted, reactive, ref } from 'vue';
import { useRouter } from 'vue-router';
import api from '../api/client';
import { useAuthStore } from '../stores/auth';

const router = useRouter();
const auth = useAuthStore();

const loading = ref(false);
const submitting = ref(false);
const error = ref('');
const fieldErrors = ref({});
const options = ref({
    inboxes: [],
    entities: [],
    contacts: [],
    operators: [],
    create_statuses: ['open', 'in_progress', 'pending'],
    priorities: ['low', 'medium', 'high', 'urgent'],
    types: ['question', 'incident', 'request', 'task', 'other'],
});

const form = reactive({
    inbox_id: '',
    entity_id: '',
    contact_id: '',
    subject: '',
    description: '',
    type: 'request',
    priority: 'medium',
    status: 'open',
    assigned_operator_id: '',
    cc_emails: '',
});

const priorityLabels = {
    low: 'Baixa',
    medium: 'Media',
    high: 'Alta',
    urgent: 'Urgente',
};

const statusLabels = {
    open: 'Aberto',
    in_progress: 'Em tratamento',
    pending: 'Aguardando cliente',
};

const isOperator = computed(() => auth.state.user?.role === 'operator');

const availableContacts = computed(() => {
    if (!form.entity_id) return options.value.contacts;
    return options.value.contacts.filter(
        (contact) => Array.isArray(contact.entity_ids) && contact.entity_ids.includes(Number(form.entity_id)),
    );
});

const loadMeta = async () => {
    loading.value = true;
    error.value = '';

    try {
        const response = await api.get('/meta');
        options.value = response.data.data;

        if (options.value.entities.length === 1) {
            form.entity_id = String(options.value.entities[0].id);
        }

        if (options.value.inboxes.length === 1) {
            form.inbox_id = String(options.value.inboxes[0].id);
        }
    } catch (exception) {
        error.value = 'Nao foi possivel carregar os dados de apoio.';
    } finally {
        loading.value = false;
    }
};

const submit = async () => {
    submitting.value = true;
    error.value = '';
    fieldErrors.value = {};

    const payload = {
        inbox_id: Number(form.inbox_id),
        entity_id: Number(form.entity_id),
        contact_id: form.contact_id ? Number(form.contact_id) : null,
        subject: form.subject,
        description: form.description,
        type: form.type,
        priority: form.priority,
        cc_emails: form.cc_emails || null,
    };

    if (isOperator.value) {
        payload.status = form.status;
        payload.assigned_operator_id = form.assigned_operator_id
            ? Number(form.assigned_operator_id)
            : null;
    }

    try {
        const response = await api.post('/tickets', payload);
        await router.push({ name: 'tickets.show', params: { id: response.data.data.id } });
    } catch (exception) {
        fieldErrors.value = exception?.response?.data?.errors ?? {};
        error.value = exception?.response?.data?.message ?? 'Nao foi possivel criar o ticket.';
    } finally {
        submitting.value = false;
    }
};

const closeModal = async () => {
    await router.push({ name: 'tickets.index' });
};

onMounted(loadMeta);
</script>

<template>
    <section class="modal-overlay">
        <article class="modal-card">
            <header class="modal-header">
                <div>
                    <h1>Novo ticket</h1>
                    <p>Preenche os dados para criar e ligar o ticket.</p>
                </div>
                <button type="button" class="btn-icon" @click="closeModal">Fechar</button>
            </header>

            <p v-if="loading" class="muted">A carregar...</p>
            <p v-if="error" class="error">{{ error }}</p>

            <form v-if="!loading" @submit.prevent="submit" class="grid">
                <label>
                    Inbox
                    <select v-model="form.inbox_id" required>
                        <option value="">Selecionar</option>
                        <option v-for="inbox in options.inboxes" :key="inbox.id" :value="String(inbox.id)">
                            {{ inbox.name }}
                        </option>
                    </select>
                    <small class="field-error">{{ fieldErrors.inbox_id?.[0] }}</small>
                </label>

                <label>
                    Entidade
                    <select v-model="form.entity_id" required>
                        <option value="">Selecionar</option>
                        <option v-for="entity in options.entities" :key="entity.id" :value="String(entity.id)">
                            {{ entity.name }}
                        </option>
                    </select>
                    <small class="field-error">{{ fieldErrors.entity_id?.[0] }}</small>
                </label>

                <label>
                    Contacto (opcional)
                    <select v-model="form.contact_id">
                        <option value="">Selecionar</option>
                        <option v-for="contact in availableContacts" :key="contact.id" :value="String(contact.id)">
                            {{ contact.name }} ({{ contact.email }})
                        </option>
                    </select>
                    <small class="field-error">{{ fieldErrors.contact_id?.[0] }}</small>
                </label>

                <label class="col-span-2">
                    Assunto
                    <input v-model="form.subject" required maxlength="255" />
                    <small class="field-error">{{ fieldErrors.subject?.[0] }}</small>
                </label>

                <label>
                    Tipo
                    <select v-model="form.type" required>
                        <option v-for="type in options.types" :key="type" :value="type">{{ type }}</option>
                    </select>
                    <small class="field-error">{{ fieldErrors.type?.[0] }}</small>
                </label>

                <label>
                    Prioridade
                    <select v-model="form.priority" required>
                        <option v-for="priority in options.priorities" :key="priority" :value="priority">
                            {{ priorityLabels[priority] ?? priority }}
                        </option>
                    </select>
                    <small class="field-error">{{ fieldErrors.priority?.[0] }}</small>
                </label>

                <label v-if="isOperator">
                    Estado inicial
                    <select v-model="form.status">
                        <option v-for="status in options.create_statuses" :key="status" :value="status">
                            {{ statusLabels[status] }}
                        </option>
                    </select>
                    <small class="field-error">{{ fieldErrors.status?.[0] }}</small>
                </label>

                <label v-if="isOperator">
                    Operador atribuido
                    <select v-model="form.assigned_operator_id">
                        <option value="">Sem atribuicao</option>
                        <option v-for="operator in options.operators" :key="operator.id" :value="String(operator.id)">
                            {{ operator.name }}
                        </option>
                    </select>
                    <small class="field-error">{{ fieldErrors.assigned_operator_id?.[0] }}</small>
                </label>

                <label class="col-span-2">
                    Conhecimento (emails separados por virgula)
                    <input v-model="form.cc_emails" placeholder="exemplo@dominio.pt, segundo@dominio.pt" />
                    <small class="field-error">{{ fieldErrors.cc_emails?.[0] }}</small>
                </label>

                <label class="col-span-3">
                    Descricao
                    <textarea v-model="form.description" required></textarea>
                    <small class="field-error">{{ fieldErrors.description?.[0] }}</small>
                </label>

                <div class="actions col-span-3">
                    <button type="submit" class="btn-primary" :disabled="submitting">
                        {{ submitting ? 'A criar...' : 'Criar ticket' }}
                    </button>
                    <button type="button" class="btn-secondary" @click="closeModal">Cancelar</button>
                </div>
            </form>
        </article>
    </section>
</template>

<style scoped>
.modal-overlay {
    position: fixed;
    inset: 0;
    z-index: 70;
    background: rgba(17, 24, 39, 0.44);
    backdrop-filter: blur(1px);
    display: grid;
    place-items: center;
    padding: 1rem;
}

.modal-card {
    width: min(1120px, calc(100vw - 2rem));
    max-height: calc(100vh - 2rem);
    overflow: auto;
    background: #fff;
    border: 1px solid #dbe4ee;
    border-radius: 14px;
    padding: 1rem;
    display: grid;
    gap: 0.85rem;
}

.modal-header {
    display: flex;
    justify-content: space-between;
    align-items: flex-start;
    gap: 0.8rem;
}

h1 {
    margin: 0;
}

.modal-header p {
    margin: 0.25rem 0 0;
    color: #475569;
}

.grid {
    display: grid;
    grid-template-columns: repeat(3, minmax(0, 1fr));
    gap: 0.75rem;
}

.col-span-2 { grid-column: span 2; }
.col-span-3 { grid-column: span 3; }

label {
    display: grid;
    gap: 0.3rem;
    color: #334155;
}

input, select, textarea {
    border: 1px solid #dbe4ee;
    border-radius: 8px;
    padding: 0.52rem 0.62rem;
    font: inherit;
}

textarea { min-height: 130px; resize: vertical; }

.actions {
    display: flex;
    align-items: center;
    gap: 0.5rem;
}

.btn-icon,
.btn-primary,
.btn-secondary {
    border: 1px solid #dbe4ee;
    border-radius: 8px;
    padding: 0.45rem 0.75rem;
    text-decoration: none;
    font: inherit;
    cursor: pointer;
}

.btn-primary {
    background: #0f766e;
    color: #fff;
    border-color: #0f766e;
}

.btn-secondary {
    background: #fff;
    color: #0f172a;
}

.btn-icon {
    background: #fff;
    color: #334155;
}

.field-error {
    color: #b91c1c;
    min-height: 1rem;
}

.muted { color: #475569; }
.error { color: #991b1b; }

@media (max-width: 900px) {
    .grid { grid-template-columns: 1fr; }
    .col-span-2, .col-span-3 { grid-column: span 1; }
}
</style>

