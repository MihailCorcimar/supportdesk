<script setup>
import { computed, onMounted, reactive, ref, watch } from 'vue';
import { useRouter } from 'vue-router';
import api from '../api/client';
import { useAuthStore } from '../stores/auth';
import UserAvatar from '../components/UserAvatar.vue';

const router = useRouter();
const auth = useAuthStore();

const loading = ref(false);
const submitting = ref(false);
const error = ref('');
const fieldErrors = ref({});
const messageAttachments = ref([]);
const options = ref({
    inboxes: [],
    triage_inbox: null,
    entities: [],
    contacts: [],
    operators: [],
    followers: [],
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
    follower_user_ids: [],
});

const followerSearch = ref('');
const followerPickerOpen = ref(false);
const operatorSearch = ref('');
const operatorPickerOpen = ref(false);

const priorityLabels = {
    low: 'Baixa',
    medium: 'Media',
    high: 'Alta',
    urgent: 'Urgente',
};
const priorityDotClass = {
    low: 'is-low',
    medium: 'is-medium',
    high: 'is-high',
    urgent: 'is-urgent',
};

const typeLabels = {
    question: 'Questão',
    incident: 'Incidente',
    request: 'Pedido',
    task: 'Tarefa',
    other: 'Outro',
};
const availableTypes = computed(() => {
    const types = options.value.types || [];
    if (isOperator.value) return types;
    return types.filter((type) => type !== 'task');
});

const statusLabels = {
    open: 'Aberto',
    in_progress: 'Em tratamento',
    pending: 'Aguardando cliente',
};

const isOperator = computed(() => auth.state.user?.role === 'operator');
const isClient = computed(() => auth.state.user?.role === 'client');
const isAdminOperator = computed(() => isOperator.value && Boolean(auth.state.user?.is_admin));
const mustUseTriageInbox = computed(() => false);
const canSetOperationalDataOnCreate = computed(() => isOperator.value && isAdminOperator.value);
const triageInboxId = computed(() => {
    const id = options.value?.triage_inbox?.id;
    return id ? String(id) : '';
});
const availableContacts = computed(() => {
    if (!form.entity_id) return options.value.contacts;
    return options.value.contacts.filter(
        (contact) => Array.isArray(contact.entity_ids) && contact.entity_ids.includes(Number(form.entity_id)),
    );
});

const followersInScope = computed(() => {
    const followers = options.value.followers || [];

    if (!isOperator.value || isAdminOperator.value) {
        return followers;
    }

    const selectedInboxId = Number(form.inbox_id || 0);
    if (!selectedInboxId) {
        return [];
    }

    return followers.filter(
        (follower) => Array.isArray(follower.inbox_ids) && follower.inbox_ids.includes(selectedInboxId),
    );
});

const filteredFollowers = computed(() => {
    const term = followerSearch.value.trim().toLowerCase();
    const followers = followersInScope.value;

    if (!term) return followers;

    return followers.filter((follower) => {
        const name = (follower.name || '').toLowerCase();
        const email = (follower.email || '').toLowerCase();
        return name.includes(term) || email.includes(term);
    });
});

const selectedFollowers = computed(() => {
    const selected = new Set((form.follower_user_ids || []).map((id) => Number(id)));
    return (options.value.followers || []).filter((follower) => selected.has(Number(follower.id)));
});
const followerSuggestions = computed(() => {
    const selected = new Set((form.follower_user_ids || []).map((id) => Number(id)));
    return filteredFollowers.value
        .filter((follower) => !selected.has(Number(follower.id)))
        .slice(0, 8);
});
const operatorCandidates = computed(() => {
    const allOperators = (options.value.followers || []).filter((follower) => follower.role === 'operator');
    const selectedInboxId = Number(form.inbox_id || 0);

    if (!selectedInboxId) {
        return allOperators;
    }

    return allOperators.filter(
        (operator) => Array.isArray(operator.inbox_ids) && operator.inbox_ids.includes(selectedInboxId),
    );
});
const filteredOperators = computed(() => {
    const term = operatorSearch.value.trim().toLowerCase();
    if (!term) return operatorCandidates.value;

    return operatorCandidates.value.filter((operator) => {
        const name = (operator.name || '').toLowerCase();
        const email = (operator.email || '').toLowerCase();
        return name.includes(term) || email.includes(term);
    });
});
const operatorSuggestions = computed(() => {
    const selectedId = Number(form.assigned_operator_id || 0);
    return filteredOperators.value
        .filter((operator) => Number(operator.id) !== selectedId)
        .slice(0, 8);
});
const selectedOperator = computed(() => {
    const selectedId = Number(form.assigned_operator_id || 0);
    if (!selectedId) return null;

    return (options.value.followers || []).find(
        (follower) => Number(follower.id) === selectedId && follower.role === 'operator',
    ) || null;
});

const loadMeta = async () => {
    loading.value = true;
    error.value = '';

    try {
        const response = await api.get('/meta');
        options.value = response.data.data;

        if (options.value.entities.length === 1) {
            form.entity_id = String(options.value.entities[0].id);
        } else if (isClient.value && options.value.entities.length > 0) {
            form.entity_id = String(options.value.entities[0].id);
        }

        if (mustUseTriageInbox.value) {
            if (triageInboxId.value) {
                form.inbox_id = triageInboxId.value;
            } else if (options.value.inboxes.length > 0) {
                form.inbox_id = String(options.value.inboxes[0].id);
            }
        } else if (options.value.inboxes.length === 1) {
            form.inbox_id = String(options.value.inboxes[0].id);
        }

    } catch (exception) {
        error.value = '';
    } finally {
        loading.value = false;
    }
};

const submit = async () => {
    submitting.value = true;
    error.value = '';
    fieldErrors.value = {};

    const selectedInboxId = mustUseTriageInbox.value ? triageInboxId.value : form.inbox_id;
    if (!selectedInboxId && !mustUseTriageInbox.value) {
        fieldErrors.value = { inbox_id: ['Inbox obrigatoria.'] };
        error.value = 'Seleciona uma inbox para continuar.';
        submitting.value = false;
        return;
    }

    const payload = new FormData();
    if (selectedInboxId) {
        payload.append('inbox_id', selectedInboxId);
    }
    if (form.entity_id) {
        payload.append('entity_id', String(Number(form.entity_id)));
    }
    payload.append('subject', form.subject);
    payload.append('description', form.description);
    payload.append('description_format', 'plain');
    payload.append('type', form.type);
    payload.append('priority', form.priority);

    if (form.contact_id) {
        payload.append('contact_id', String(Number(form.contact_id)));
    }

    (form.follower_user_ids || []).forEach((id) => {
        payload.append('follower_user_ids[]', String(Number(id)));
    });

    if (canSetOperationalDataOnCreate.value) {
        payload.append('status', form.status);
        if (form.assigned_operator_id) {
            payload.append('assigned_operator_id', String(Number(form.assigned_operator_id)));
        }
    }

    messageAttachments.value.forEach((file) => {
        payload.append('attachments[]', file);
    });

    try {
        const response = await api.post('/tickets', payload, {
            headers: {
                'Content-Type': 'multipart/form-data',
            },
        });
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

const onAttachmentChange = (event) => {
    messageAttachments.value = Array.from(event?.target?.files ?? []);
};

const toggleFollower = (id) => {
    const normalizedId = Number(id);
    const current = (form.follower_user_ids || []).map((item) => Number(item));

    if (current.includes(normalizedId)) {
        form.follower_user_ids = current.filter((item) => item !== normalizedId);
        return;
    }

    form.follower_user_ids = [...current, normalizedId];
};

const removeFollower = (id) => {
    const normalizedId = Number(id);
    form.follower_user_ids = (form.follower_user_ids || [])
        .map((item) => Number(item))
        .filter((item) => item !== normalizedId);

    if (Number(form.assigned_operator_id || 0) === normalizedId) {
        form.assigned_operator_id = '';
    }
};

const followerRoleLabel = (role) => (role === 'operator' ? 'Operador' : 'Cliente');
const findFollowerById = (id) => {
    const normalizedId = Number(id);
    return (options.value.followers || []).find((follower) => Number(follower.id) === normalizedId) || null;
};
const canOperatorBeAssignedForSelectedInbox = (operatorId) => {
    const selectedInboxId = Number(form.inbox_id || 0);
    if (!selectedInboxId) return false;

    const follower = findFollowerById(operatorId);
    if (!follower || follower.role !== 'operator') return false;

    return Array.isArray(follower.inbox_ids) && follower.inbox_ids.includes(selectedInboxId);
};
const chooseFollower = (id) => {
    toggleFollower(id);

    if (canSetOperationalDataOnCreate.value && !form.assigned_operator_id) {
        const follower = findFollowerById(id);
        if (follower?.role === 'operator' && canOperatorBeAssignedForSelectedInbox(follower.id)) {
            form.assigned_operator_id = String(Number(follower.id));
        }
    }

    followerSearch.value = '';
    followerPickerOpen.value = false;
};
const chooseOperator = (id) => {
    form.assigned_operator_id = String(Number(id));
    operatorSearch.value = '';
    operatorPickerOpen.value = false;
};
const clearAssignedOperator = () => {
    form.assigned_operator_id = '';
    operatorSearch.value = '';
    operatorPickerOpen.value = false;
};
const handleOperatorSearchEnter = (event) => {
    if (event.key !== 'Enter') return;
    event.preventDefault();
    const first = operatorSuggestions.value[0];
    if (first) chooseOperator(first.id);
};
const closeOperatorPicker = () => {
    setTimeout(() => {
        operatorPickerOpen.value = false;
    }, 120);
};
const onOperatorSearchInput = () => {
    operatorPickerOpen.value = operatorSearch.value.trim().length > 0;
};
const onOperatorSearchFocus = () => {
    operatorPickerOpen.value = operatorSearch.value.trim().length > 0;
};
const handleFollowerSearchEnter = (event) => {
    if (event.key !== 'Enter') return;
    event.preventDefault();
    const first = followerSuggestions.value[0];
    if (first) chooseFollower(first.id);
};
const closeFollowerPicker = () => {
    setTimeout(() => {
        followerPickerOpen.value = false;
    }, 120);
};
const onFollowerSearchInput = () => {
    followerPickerOpen.value = followerSearch.value.trim().length > 0;
};
const onFollowerSearchFocus = () => {
    followerPickerOpen.value = followerSearch.value.trim().length > 0;
};

watch(
    () => form.inbox_id,
    () => {
        if (!isOperator.value) {
            return;
        }

        if (!isAdminOperator.value) {
            const allowedIds = new Set(followersInScope.value.map((follower) => Number(follower.id)));
            form.follower_user_ids = (form.follower_user_ids || [])
                .map((id) => Number(id))
                .filter((id) => allowedIds.has(id));
        }

        if (
            form.assigned_operator_id
            && !canOperatorBeAssignedForSelectedInbox(form.assigned_operator_id)
        ) {
            form.assigned_operator_id = '';
        }

    },
);

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
                <label v-if="!mustUseTriageInbox">
                    Inbox
                    <select v-model="form.inbox_id" required>
                        <option value="">Selecionar</option>
                        <option v-for="inbox in options.inboxes" :key="inbox.id" :value="String(inbox.id)">
                            {{ inbox.name }}
                        </option>
                    </select>
                    <small class="field-error">{{ fieldErrors.inbox_id?.[0] }}</small>
                </label>

                <label v-if="isOperator">
                    Entidade
                    <select v-model="form.entity_id" required>
                        <option value="">Selecionar</option>
                        <option v-for="entity in options.entities" :key="entity.id" :value="String(entity.id)">
                            {{ entity.name }}
                        </option>
                    </select>
                    <small class="field-error">{{ fieldErrors.entity_id?.[0] }}</small>
                </label>

                <label v-if="isOperator">
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

                <label class="col-span-3">
                    Descricao
                    <textarea v-model="form.description" required></textarea>
                    <small class="field-error">{{ fieldErrors.description?.[0] }}</small>
                </label>

                <label>
                    Tipo
                    <select v-model="form.type" required>
                        <option v-for="type in availableTypes" :key="type" :value="type">{{ typeLabels[type] ?? type }}</option>
                    </select>
                    <small class="field-error">{{ fieldErrors.type?.[0] }}</small>
                </label>

                <label v-if="isOperator">
                    Prioridade
                    <div class="priority-picker">
                        <button
                            type="button"
                            v-for="priority in options.priorities"
                            :key="priority"
                            class="priority-option"
                            :class="{ 'is-selected': form.priority === priority }"
                            :aria-pressed="form.priority === priority ? 'true' : 'false'"
                            @click="form.priority = priority"
                        >
                            <span class="priority-dot" :class="priorityDotClass[priority] || ''"></span>
                            <span>{{ priorityLabels[priority] ?? priority }}</span>
                        </button>
                    </div>
                    <small class="field-error">{{ fieldErrors.priority?.[0] }}</small>
                </label>

                <label v-if="canSetOperationalDataOnCreate">
                    Estado inicial
                    <select v-model="form.status">
                        <option v-for="status in options.create_statuses" :key="status" :value="status">
                            {{ statusLabels[status] }}
                        </option>
                    </select>
                    <small class="field-error">{{ fieldErrors.status?.[0] }}</small>
                </label>

                <label v-if="canSetOperationalDataOnCreate">
                    Operador responsavel
                    <div class="followers-picker-modern operator-picker-modern">
                        <div v-if="selectedOperator" class="followers-tags-modern">
                            <span class="follower-tag-modern">
                                <UserAvatar
                                    class="follower-avatar"
                                    :name="selectedOperator.name"
                                    :src="selectedOperator.avatar_url"
                                    :size="22"
                                />
                                <span class="follower-name">{{ selectedOperator.name }}</span>
                                <button type="button" @click="clearAssignedOperator">&times;</button>
                            </span>
                        </div>

                        <input
                            v-model="operatorSearch"
                            type="search"
                            placeholder="Escreve nome ou email do operador"
                            @focus="onOperatorSearchFocus"
                            @input="onOperatorSearchInput"
                            @blur="closeOperatorPicker"
                            @keydown="handleOperatorSearchEnter"
                        />

                        <div v-if="operatorPickerOpen" class="followers-suggestions">
                            <button
                                type="button"
                                class="follower-option follower-option-clear"
                                @click.prevent="clearAssignedOperator"
                            >
                                <span class="follower-avatar follower-avatar-clear">-</span>
                                <span class="follower-meta">
                                    <strong>Sem atribuicao</strong>
                                </span>
                            </button>

                            <button
                                type="button"
                                v-for="operator in operatorSuggestions"
                                :key="`operator-opt-${operator.id}`"
                                class="follower-option"
                                @click.prevent="chooseOperator(operator.id)"
                            >
                                <UserAvatar
                                    class="follower-avatar"
                                    :name="operator.name"
                                    :src="operator.avatar_url"
                                    :size="22"
                                />
                                <span class="follower-meta">
                                    <strong>{{ operator.name }}</strong>
                                    <small>{{ operator.email }} &middot; Operador</small>
                                </span>
                            </button>

                            <p v-if="!operatorSuggestions.length" class="followers-empty">Sem resultados.</p>
                        </div>
                    </div>
                    <small class="field-hint">Pessoa que vai tratar o ticket.</small>
                    <small class="field-error">{{ fieldErrors.assigned_operator_id?.[0] }}</small>
                </label>

                <label v-if="isOperator" :class="canSetOperationalDataOnCreate ? '' : 'col-span-2'">
                    Conhecimento (utilizadores)
                    <div class="followers-picker-modern">
                        <div v-if="selectedFollowers.length" class="followers-tags-modern">
                            <span v-for="follower in selectedFollowers" :key="`sel-${follower.id}`" class="follower-tag-modern">
                                <UserAvatar
                                    class="follower-avatar"
                                    :name="follower.name"
                                    :src="follower.avatar_url"
                                    :size="22"
                                />
                                <span class="follower-name">{{ follower.name }}</span>
                                <button type="button" @click="removeFollower(follower.id)">&times;</button>
                            </span>
                        </div>

                        <input
                            v-model="followerSearch"
                            type="search"
                            placeholder="Escreve nome ou email para adicionar"
                            @focus="onFollowerSearchFocus"
                            @input="onFollowerSearchInput"
                            @blur="closeFollowerPicker"
                            @keydown="handleFollowerSearchEnter"
                        />

                        <div v-if="followerPickerOpen" class="followers-suggestions">
                            <button
                                type="button"
                                v-for="follower in followerSuggestions"
                                :key="`opt-${follower.id}`"
                                class="follower-option"
                                @click.prevent="chooseFollower(follower.id)"
                            >
                                <UserAvatar
                                    class="follower-avatar"
                                    :name="follower.name"
                                    :src="follower.avatar_url"
                                    :size="22"
                                />
                                <span class="follower-meta">
                                    <strong>{{ follower.name }}</strong>
                                    <small>{{ follower.email }} &middot; {{ followerRoleLabel(follower.role) }}</small>
                                </span>
                            </button>

                            <p v-if="!followerSuggestions.length" class="followers-empty">Sem resultados.</p>
                        </div>
                    </div>
                    <small
                        v-if="isOperator && !isAdminOperator && !form.inbox_id"
                        class="field-hint"
                    >
                        Seleciona primeiro a inbox para listar utilizadores em conhecimento.
                    </small>
                    <small class="field-error">{{ fieldErrors.follower_user_ids?.[0] || fieldErrors['follower_user_ids.0']?.[0] }}</small>
                </label>

                <label class="col-span-3">
                    Anexos (opcional)
                    <input
                        type="file"
                        multiple
                        accept=".jpg,.jpeg,.png,.pdf,.doc,.docx,.xls,.xlsx,.txt,.csv,.zip"
                        @change="onAttachmentChange"
                    />
                    <small class="field-error">{{ fieldErrors.attachments?.[0] || fieldErrors['attachments.0']?.[0] }}</small>
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

.priority-picker {
    display: grid;
    grid-template-columns: repeat(4, minmax(0, 1fr));
    gap: 0.45rem;
}

.priority-option {
    border: 1px solid #dbe4ee;
    background: #fff;
    border-radius: 8px;
    padding: 0.55rem 0.5rem;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    gap: 0.35rem;
    cursor: pointer;
    color: #334155;
    font-weight: 500;
}

.priority-option:hover {
    border-color: #94a3b8;
}

.priority-option.is-selected {
    border-color: #1F4E79;
    background: #EDF3FA;
    color: #0f172a;
}

.priority-dot {
    width: 0.45rem;
    height: 0.45rem;
    border-radius: 999px;
    display: inline-block;
}

.priority-dot.is-low { background: #3b82f6; }
.priority-dot.is-medium { background: #f59e0b; }
.priority-dot.is-high { background: #ef4444; }
.priority-dot.is-urgent { background: #b91c1c; }

.followers-picker-modern {
    position: relative;
    border: 1px solid #dbe4ee;
    border-radius: 10px;
    padding: 0.55rem;
    display: grid;
    gap: 0.45rem;
    background: #f8fbff;
}

.followers-suggestions {
    position: absolute;
    left: 0.55rem;
    right: 0.55rem;
    top: calc(100% + 0.3rem);
    z-index: 20;
    border: 1px solid #dbe4ee;
    border-radius: 8px;
    background: #fff;
    box-shadow: 0 10px 28px rgba(15, 23, 42, 0.16);
    max-height: 220px;
    overflow: auto;
}

.follower-option {
    width: 100%;
    border: 0;
    background: transparent;
    display: flex;
    align-items: center;
    gap: 0.55rem;
    padding: 0.38rem 0.5rem;
    border-bottom: 1px solid #eef2f7;
    text-align: left;
    cursor: pointer;
}

.follower-option:last-child {
    border-bottom: 0;
}

.follower-option:hover {
    background: #f0fdf4;
}

.follower-option-clear {
    background: #f8fafc;
}

.follower-avatar-clear {
    width: 1.35rem;
    height: 1.35rem;
    border-radius: 999px;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    background: #64748b;
    color: #fff;
    font-size: 0.7rem;
    font-weight: 700;
}

.follower-meta {
    display: grid;
    gap: 0.12rem;
}

.follower-meta strong {
    font-size: 0.9rem;
}

.follower-meta small {
    color: #64748b;
    font-size: 0.77rem;
}

.followers-empty {
    margin: 0;
    padding: 0.6rem;
    color: #64748b;
}

.followers-tags-modern {
    display: flex;
    flex-wrap: wrap;
    gap: 0.35rem;
}

.follower-tag-modern {
    display: inline-flex;
    align-items: center;
    gap: 0.3rem;
    border: 1px solid #b9ccdf;
    border-radius: 999px;
    background: #eff6ff;
    color: #0f172a;
    padding: 0.15rem 0.5rem;
    font-size: 0.8rem;
}

.follower-tag-modern button {
    border: 0;
    background: transparent;
    padding: 0;
    line-height: 1;
    cursor: pointer;
    color: #475569;
}

.follower-avatar {
    flex: 0 0 auto;
}

.follower-name {
    max-width: 170px;
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
}

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
    background: #1F4E79;
    color: #fff;
    border-color: #1F4E79;
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

.field-hint {
    color: #64748b;
    min-height: 1rem;
}

.muted { color: #475569; }
.error { color: #991b1b; }

@media (max-width: 900px) {
    .grid { grid-template-columns: 1fr; }
    .col-span-2, .col-span-3 { grid-column: span 1; }
    .priority-picker { grid-template-columns: repeat(2, minmax(0, 1fr)); }
}
</style>



