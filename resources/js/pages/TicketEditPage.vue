<script setup>
import { computed, onMounted, reactive, ref } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import api from '../api/client';

const route = useRoute();
const router = useRouter();

const loading = ref(true);
const saving = ref(false);
const error = ref('');
const ticket = ref(null);

const form = reactive({
    subject: '',
    description: '',
    priority: 'medium',
    type: 'request',
    status: 'open',
    inbox_id: '',
    entity_id: '',
    contact_id: '',
    assigned_operator_id: '',
    cc_emails: '',
});

const statusLabels = {
    open: 'Aberto',
    in_progress: 'Em tratamento',
    pending: 'Aguardando cliente',
    closed: 'Fechado',
    cancelled: 'Cancelado',
};
const priorityLabels = { low: 'Baixa', medium: 'Media', high: 'Alta', urgent: 'Urgente' };
const typeLabels = { question: 'Questao', incident: 'Incidente', request: 'Pedido', task: 'Tarefa', other: 'Outro' };

const filteredContacts = computed(() => {
    if (!ticket.value?.available_contacts) return [];
    if (!form.entity_id) return ticket.value.available_contacts;

    return ticket.value.available_contacts.filter((contact) => String(contact.entity_id) === String(form.entity_id));
});

const loadTicket = async () => {
    loading.value = true;
    error.value = '';

    try {
        const response = await api.get(`/tickets/${route.params.id}`);
        ticket.value = response.data.data;

        form.subject = ticket.value.subject || '';
        form.description = ticket.value.description || '';
        form.priority = ticket.value.priority || 'medium';
        form.type = ticket.value.type || 'request';
        form.status = ticket.value.status || 'open';
        form.inbox_id = ticket.value.inbox?.id ? String(ticket.value.inbox.id) : '';
        form.entity_id = ticket.value.entity?.id ? String(ticket.value.entity.id) : '';
        form.contact_id = ticket.value.contact?.id ? String(ticket.value.contact.id) : '';
        form.assigned_operator_id = ticket.value.assigned_operator?.id ? String(ticket.value.assigned_operator.id) : '';
        form.cc_emails = (ticket.value.cc_emails || []).join(', ');
    } catch (exception) {
        error.value = exception?.response?.data?.message || 'Nao foi possivel carregar ticket para edicao.';
    } finally {
        loading.value = false;
    }
};

const save = async () => {
    saving.value = true;
    error.value = '';

    try {
        await api.patch(`/tickets/${route.params.id}`, {
            subject: form.subject,
            description: form.description,
            priority: form.priority,
            type: form.type,
            status: form.status,
            inbox_id: form.inbox_id ? Number(form.inbox_id) : null,
            entity_id: form.entity_id ? Number(form.entity_id) : null,
            contact_id: form.contact_id ? Number(form.contact_id) : null,
            assigned_operator_id: form.assigned_operator_id ? Number(form.assigned_operator_id) : null,
            cc_emails: form.cc_emails,
        });

        await router.push({ name: 'tickets.show', params: { id: route.params.id } });
    } catch (exception) {
        error.value = exception?.response?.data?.message
            || Object.values(exception?.response?.data?.errors || {})?.[0]?.[0]
            || 'Falha ao guardar ticket.';
    } finally {
        saving.value = false;
    }
};

onMounted(loadTicket);
</script>

<template>
    <section class="page">
        <div class="header-row">
            <h1>Editar ticket</h1>
            <RouterLink class="btn-secondary" :to="{ name: 'tickets.show', params: { id: route.params.id } }">Voltar</RouterLink>
        </div>

        <p v-if="loading" class="muted">A carregar...</p>
        <p v-if="error" class="error">{{ error }}</p>

        <form v-if="!loading && ticket" class="card grid" @submit.prevent="save">
            <label class="full">
                Assunto
                <input v-model="form.subject" required maxlength="255" />
            </label>

            <label class="full">
                Descricao
                <textarea v-model="form.description"></textarea>
            </label>

            <label>
                Estado
                <select v-model="form.status">
                    <option v-for="(label, key) in statusLabels" :key="key" :value="key">{{ label }}</option>
                </select>
            </label>

            <label>
                Prioridade
                <select v-model="form.priority">
                    <option v-for="(label, key) in priorityLabels" :key="key" :value="key">{{ label }}</option>
                </select>
            </label>

            <label>
                Tipo
                <select v-model="form.type">
                    <option v-for="(label, key) in typeLabels" :key="key" :value="key">{{ label }}</option>
                </select>
            </label>

            <label>
                Inbox
                <select v-model="form.inbox_id">
                    <option v-for="item in ticket.available_inboxes" :key="item.id" :value="String(item.id)">{{ item.name }}</option>
                </select>
            </label>

            <label>
                Entidade
                <select v-model="form.entity_id">
                    <option v-for="item in ticket.available_entities" :key="item.id" :value="String(item.id)">{{ item.name }}</option>
                </select>
            </label>

            <label>
                Contacto
                <select v-model="form.contact_id">
                    <option value="">Sem contacto</option>
                    <option v-for="item in filteredContacts" :key="item.id" :value="String(item.id)">
                        {{ item.name }} ({{ item.email }})
                    </option>
                </select>
            </label>

            <label>
                Operador atribuido
                <select v-model="form.assigned_operator_id">
                    <option value="">Sem atribuicao</option>
                    <option v-for="item in ticket.operators" :key="item.id" :value="String(item.id)">{{ item.name }}</option>
                </select>
            </label>

            <label class="full">
                Conhecimento (emails separados por virgula)
                <input v-model="form.cc_emails" />
            </label>

            <div class="actions full">
                <button type="submit" :disabled="saving">{{ saving ? 'A guardar...' : 'Guardar alteracoes' }}</button>
            </div>
        </form>
    </section>
</template>

<style scoped>
.page { display: grid; gap: 1rem; }
.header-row { display: flex; justify-content: space-between; align-items: center; gap: 0.8rem; }
h1 { margin: 0; }

.card {
    background: #fff;
    border: 1px solid #dbe4ee;
    border-radius: 12px;
    padding: 1rem;
}

.grid {
    display: grid;
    grid-template-columns: repeat(2, minmax(0, 1fr));
    gap: 0.7rem;
}

label { display: grid; gap: 0.25rem; }
input, select, textarea, button {
    font: inherit;
    border: 1px solid #dbe4ee;
    border-radius: 8px;
    padding: 0.52rem 0.6rem;
}
textarea { min-height: 130px; resize: vertical; }
button {
    border-color: #0f766e;
    background: #0f766e;
    color: #fff;
    cursor: pointer;
}

.full { grid-column: 1 / -1; }
.actions { display: flex; gap: 0.5rem; }

.btn-secondary {
    border: 1px solid #dbe4ee;
    border-radius: 8px;
    padding: 0.45rem 0.75rem;
    background: #fff;
    text-decoration: none;
    color: #0f172a;
}

.error {
    border: 1px solid #fecaca;
    background: #fef2f2;
    color: #991b1b;
    border-radius: 8px;
    padding: 0.65rem;
}

.muted { color: #475569; }

@media (max-width: 900px) {
    .grid { grid-template-columns: 1fr; }
}
</style>
