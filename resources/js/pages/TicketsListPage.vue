<script setup>
import { computed, nextTick, onBeforeUnmount, onMounted, reactive, ref, watchEffect } from 'vue';
import { RouterLink, useRoute, useRouter } from 'vue-router';
import api from '../api/client';
import { useAuthStore } from '../stores/auth';

const route = useRoute();
const router = useRouter();
const auth = useAuthStore();
const loading = ref(false);
const error = ref('');
const success = ref('');
const searchInputRef = ref(null);
const selectAllCheckboxRef = ref(null);
const tickets = ref([]);
const meta = ref({ current_page: 1, last_page: 1, total: 0 });
const openActionsMenuTicketId = ref(null);
const openActionsMenuPlacement = ref('down');
const showFiltersMenu = ref(false);
const pinPendingIds = ref([]);
const selectedTicketIds = ref([]);
const bulkStatus = ref('');
const bulkAssignedOperatorId = ref('');
const bulkLoading = ref(false);
const options = ref({
    inboxes: [],
    entities: [],
    operators: [],
    statuses: [],
    priorities: [],
    types: [],
});
const canManageUsers = computed(() => Boolean(auth.state.user?.can_manage_users));
const selectedCount = computed(() => selectedTicketIds.value.length);
const pageTicketIds = computed(() => tickets.value.map((ticket) => ticket.id));
const areAllPageTicketsSelected = computed(() => (
    pageTicketIds.value.length > 0
    && pageTicketIds.value.every((ticketId) => selectedTicketIds.value.includes(ticketId))
));
const areSomePageTicketsSelected = computed(() => (
    !areAllPageTicketsSelected.value
    && pageTicketIds.value.some((ticketId) => selectedTicketIds.value.includes(ticketId))
));

const defaultFilters = {
    search: '',
    inbox_id: '',
    created_by_user_id: '',
    status: '',
    assigned_operator_id: '',
    type: '',
    priority: '',
    entity_id: '',
    sort_by: 'last_activity_at',
    sort_dir: 'desc',
};

const filters = reactive({ ...defaultFilters });
const actionsMenuRefs = new Map();
const activeFilterCount = computed(() => {
    const keys = ['type', 'inbox_id', 'created_by_user_id', 'status', 'priority', 'entity_id', 'assigned_operator_id'];
    return keys.reduce((count, key) => (filters[key] ?count + 1 : count), 0);
});
const sortByOptions = [
    { value: 'last_activity_at', label: 'Última atividade' },
    { value: 'request_date', label: 'Data pedido' },
    { value: 'ticket_number', label: 'Ticket ID' },
    { value: 'subject', label: 'Assunto' },
    { value: 'priority', label: 'Prioridade' },
    { value: 'status', label: 'Estado' },
    { value: 'type', label: 'Tipo' },
    { value: 'client', label: 'Entidade' },
];

const statusLabels = {
    open: 'Aberto',
    in_progress: 'Em tratamento',
    pending: 'Aguardando cliente',
    closed: 'Fechado',
    cancelled: 'Cancelado',
};

const priorityLabels = {
    low: 'Baixa',
    medium: 'Media',
    high: 'Alta',
    urgent: 'Urgente',
};

const typeLabels = {
    question: 'Questão',
    incident: 'Incidente',
    request: 'Pedido',
    task: 'Tarefa',
    other: 'Outro',
};

const loadMeta = async () => {
    const response = await api.get('/meta');
    options.value = {
        inboxes: response.data.data.inboxes,
        entities: response.data.data.entities,
        operators: response.data.data.operators,
        statuses: response.data.data.statuses,
        priorities: response.data.data.priorities ?? [],
        types: response.data.data.types,
    };
};

const hydrateFiltersFromQuery = () => {
    const inboxId = typeof route.query.inbox_id === 'string' ?route.query.inbox_id.trim() : '';
    const createdByUserId = typeof route.query.created_by_user_id === 'string' ?route.query.created_by_user_id.trim() : '';
    filters.inbox_id = inboxId;
    filters.created_by_user_id = createdByUserId;
};

const loadTickets = async (page = 1) => {
    loading.value = true;
    error.value = '';

    try {
        const params = { page };
        Object.entries(filters).forEach(([key, value]) => {
            if (value !== '' && value !== null) {
                params[key] = value;
            }
        });

        const response = await api.get('/tickets', { params });
        tickets.value = response.data.data;
        meta.value = response.data.meta;
        selectedTicketIds.value = [];
    } catch (exception) {
        error.value = 'Não foi possível carregar tickets.';
    } finally {
        loading.value = false;
    }
};

let searchDebounceTimer = null;

const applyFilters = () => {
    success.value = '';
    loadTickets(1);
};

const applyFiltersDebounced = () => {
    if (searchDebounceTimer) {
        clearTimeout(searchDebounceTimer);
    }

    searchDebounceTimer = setTimeout(() => {
        loadTickets(1);
    }, 220);
};

const clearFilters = () => {
    Object.assign(filters, defaultFilters);
    openActionsMenuTicketId.value = null;
    showFiltersMenu.value = false;
    success.value = '';
    loadTickets(1);
};

const toggleFiltersMenu = () => {
    showFiltersMenu.value = !showFiltersMenu.value;
};

const closeFiltersMenu = () => {
    showFiltersMenu.value = false;
};

const toggleSort = (field) => {
    success.value = '';
    if (filters.sort_by === field) {
        filters.sort_dir = filters.sort_dir === 'asc' ?'desc' : 'asc';
    } else {
        filters.sort_by = field;
        filters.sort_dir = field === 'ticket_number' ?'asc' : 'desc';
    }

    loadTickets(1);
};

const sortState = (field) => {
    if (filters.sort_by !== field) return 'none';
    return filters.sort_dir === 'asc' ?'asc' : 'desc';
};

const focusSearch = () => {
    if (searchInputRef.value) {
        searchInputRef.value.focus();
    }
};

const shouldIgnoreShortcutTarget = (target) => {
    if (!(target instanceof HTMLElement)) {
        return false;
    }

    const tagName = target.tagName.toLowerCase();
    if (['input', 'textarea', 'select', 'button'].includes(tagName)) {
        return true;
    }

    return target.isContentEditable;
};

const handleGlobalKeydown = (event) => {
    if (event.defaultPrevented) return;
    if (event.altKey) return;
    if (!event.ctrlKey && !event.metaKey) return;
    if (event.key.toLowerCase() !== 'k') return;
    if (shouldIgnoreShortcutTarget(event.target)) return;

    event.preventDefault();
    focusSearch();
};

const setActionsMenuRef = (ticketId, element) => {
    if (element instanceof HTMLElement) {
        actionsMenuRefs.set(ticketId, element);
        return;
    }

    actionsMenuRefs.delete(ticketId);
};

const updateActionsMenuPlacement = async (ticketId) => {
    const menuRoot = actionsMenuRefs.get(ticketId);
    if (!(menuRoot instanceof HTMLElement)) {
        openActionsMenuPlacement.value = 'down';
        return;
    }

    const tableWrap = menuRoot.closest('.table-wrap');
    const trigger = menuRoot.querySelector('.menu-trigger');
    const dropdown = menuRoot.querySelector('.actions-dropdown');
    if (!(tableWrap instanceof HTMLElement) || !(trigger instanceof HTMLElement) || !(dropdown instanceof HTMLElement)) {
        openActionsMenuPlacement.value = 'down';
        return;
    }

    const wrapRect = tableWrap.getBoundingClientRect();
    const triggerRect = trigger.getBoundingClientRect();
    const dropdownRect = dropdown.getBoundingClientRect();
    const spaceBelow = wrapRect.bottom - triggerRect.bottom;
    const spaceAbove = triggerRect.top - wrapRect.top;

    openActionsMenuPlacement.value = dropdownRect.height > spaceBelow && spaceAbove > spaceBelow ?'up' : 'down';
};

const toggleActionsMenu = async (ticketId) => {
    if (openActionsMenuTicketId.value === ticketId) {
        closeActionsMenu();
        return;
    }

    openActionsMenuTicketId.value = ticketId;
    openActionsMenuPlacement.value = 'down';

    await nextTick();
    await updateActionsMenuPlacement(ticketId);
};

const closeActionsMenu = () => {
    openActionsMenuTicketId.value = null;
    openActionsMenuPlacement.value = 'down';
};

const isPinPending = (ticketId) => pinPendingIds.value.includes(ticketId);
const isTicketSelected = (ticketId) => selectedTicketIds.value.includes(ticketId);

const toggleTicketSelection = (ticketId) => {
    if (isTicketSelected(ticketId)) {
        selectedTicketIds.value = selectedTicketIds.value.filter((id) => id !== ticketId);
        return;
    }

    selectedTicketIds.value = [...selectedTicketIds.value, ticketId];
};

const toggleSelectAllOnPage = () => {
    if (areAllPageTicketsSelected.value) {
        const idsOnPage = new Set(pageTicketIds.value);
        selectedTicketIds.value = selectedTicketIds.value.filter((id) => !idsOnPage.has(id));
        return;
    }

    const merged = new Set([...selectedTicketIds.value, ...pageTicketIds.value]);
    selectedTicketIds.value = Array.from(merged);
};

const clearSelection = () => {
    selectedTicketIds.value = [];
};

const normalizeBulkMessage = (data) => {
    const updated = Number(data?.updated || 0);
    const unchanged = Number(data?.unchanged || 0);
    const skipped = Number(data?.skipped_count || 0);
    const statusUpdated = Number(data?.status_updated || 0);
    const assignmentUpdated = Number(data?.assignment_updated || 0);

    return `Atualizados: ${updated} (estado: ${statusUpdated}, operador: ${assignmentUpdated}) · Sem alterações: ${unchanged} · Ignorados: ${skipped}`;
};

const applyBulkStatus = async () => {
    if (!selectedTicketIds.value.length || !bulkStatus.value || bulkLoading.value) {
        return;
    }

    bulkLoading.value = true;
    error.value = '';
    success.value = '';

    try {
        const response = await api.patch('/tickets/bulk', {
            ticket_ids: selectedTicketIds.value,
            status: bulkStatus.value,
        });

        success.value = normalizeBulkMessage(response.data.data);
        await loadTickets(meta.value.current_page || 1);
    } catch (exception) {
        error.value = exception?.response?.data?.message || 'Não foi possível aplicar estado em lote.';
    } finally {
        bulkLoading.value = false;
    }
};

const applyBulkAssignment = async () => {
    if (!selectedTicketIds.value.length || bulkAssignedOperatorId.value === '' || bulkLoading.value) {
        return;
    }

    bulkLoading.value = true;
    error.value = '';
    success.value = '';

    try {
        const assignedOperatorId = bulkAssignedOperatorId.value === '__none__'
            ?null
            : Number(bulkAssignedOperatorId.value);

        const response = await api.patch('/tickets/bulk', {
            ticket_ids: selectedTicketIds.value,
            assigned_operator_id: assignedOperatorId,
        });

        success.value = normalizeBulkMessage(response.data.data);
        await loadTickets(meta.value.current_page || 1);
    } catch (exception) {
        error.value = exception?.response?.data?.message || 'Não foi possível aplicar operador em lote.';
    } finally {
        bulkLoading.value = false;
    }
};

const toggleTicketPin = async (ticket) => {
    if (!ticket?.id || isPinPending(ticket.id)) {
        return;
    }

    closeActionsMenu();
    pinPendingIds.value.push(ticket.id);

    try {
        if (ticket.is_pinned) {
            await api.delete(`/conversations/${ticket.id}/pin`);
        } else {
            await api.post(`/conversations/${ticket.id}/pin`);
        }

        ticket.is_pinned = !ticket.is_pinned;
        window.dispatchEvent(new CustomEvent('supportdesk:conversations-updated'));
    } catch {
        error.value = 'Não foi possível atualizar pin.';
    } finally {
        pinPendingIds.value = pinPendingIds.value.filter((id) => id !== ticket.id);
    }
};

const openTicketView = async (ticketId, tab = 'conversation', details = false) => {
    closeActionsMenu();
    const query = { tab };
    if (details) {
        query.details = '1';
    }
    await router.push({
        name: 'tickets.show',
        params: { id: ticketId },
        query,
    });
};

const openTicketEditor = async (ticketId) => {
    closeActionsMenu();
    await router.push({ name: 'tickets.edit', params: { id: ticketId } });
};

const closeActionsMenuOnOutsideClick = (event) => {
    if (openActionsMenuTicketId.value === null) return;

    const target = event.target;
    if (!(target instanceof HTMLElement)) return;

    if (!target.closest('.actions-menu')) {
        closeActionsMenu();
    }
};

const closeFiltersMenuOnOutsideClick = (event) => {
    if (!showFiltersMenu.value) return;

    const target = event.target;
    if (!(target instanceof HTMLElement)) return;

    if (!target.closest('.filters-menu')) {
        closeFiltersMenu();
    }
};

const formatDate = (value) => {
    if (!value) return '-';

    return new Date(value).toLocaleString('pt-PT', {
        day: '2-digit',
        month: '2-digit',
        year: 'numeric',
        hour: '2-digit',
        minute: '2-digit',
    });
};

const entityLabel = (ticket) => ticket.entity?.name || '-';
const creatorLabel = (ticket) => ticket.creator_user?.name || ticket.creator_contact?.name || '-';
const entityLinkTarget = (ticket) => {
    if (!canManageUsers.value || !ticket.entity?.id) return null;
    return { name: 'entities.show', params: { id: ticket.entity.id } };
};
const creatorLinkTarget = (ticket) => {
    if (!canManageUsers.value || !ticket.creator_user?.id) return null;
    return { name: 'users.show', params: { id: ticket.creator_user.id } };
};

onMounted(async () => {
    document.addEventListener('click', closeActionsMenuOnOutsideClick);
    document.addEventListener('click', closeFiltersMenuOnOutsideClick);
    document.addEventListener('keydown', handleGlobalKeydown);
    hydrateFiltersFromQuery();
    await loadMeta();
    await loadTickets(1);
});

watchEffect(() => {
    if (selectAllCheckboxRef.value) {
        selectAllCheckboxRef.value.indeterminate = areSomePageTicketsSelected.value;
    }
});

onBeforeUnmount(() => {
    document.removeEventListener('click', closeActionsMenuOnOutsideClick);
    document.removeEventListener('click', closeFiltersMenuOnOutsideClick);
    document.removeEventListener('keydown', handleGlobalKeydown);
});
</script>

<template>
    <section class="ticket-page">
        <article class="ticket-panel">
            <header class="panel-header">
                <div>
                    <h1>Ticket</h1>
                    <p class="subtitle">{{ meta.total }} registos</p>
                </div>

                <div class="header-actions">
                    <RouterLink class="btn-primary" :to="{ name: 'tickets.create' }">Novo Ticket</RouterLink>
                </div>
            </header>

            <form class="filter-bar" @submit.prevent>
                <label class="search-field">
                    <span class="field-icon">⌕</span>
                    <input ref="searchInputRef" v-model="filters.search" placeholder="Pesquisar" @input="applyFiltersDebounced" />
                    <kbd class="search-kbd">Ctrl+K</kbd>
                </label>

                <div class="inline-filters">
                    <label class="inline-field">
                        <span class="field-icon">⌁</span>
                        <span class="ddl-name">Tipo</span>
                        <select v-model="filters.type" @change="applyFilters">
                            <option value="">Todos</option>
                            <option v-for="type in options.types" :key="type" :value="type">
                                {{ typeLabels[type] || type }}
                            </option>
                        </select>
                    </label>

                    <label class="inline-field">
                        <span class="field-icon">⌂</span>
                        <span class="ddl-name">Origem</span>
                        <select v-model="filters.inbox_id" @change="applyFilters">
                            <option value="">Todas</option>
                            <option v-for="inbox in options.inboxes" :key="inbox.id" :value="String(inbox.id)">
                                {{ inbox.name }}
                            </option>
                        </select>
                    </label>

                    <label class="inline-field">
                        <span class="field-icon">◍</span>
                        <span class="ddl-name">Estado</span>
                        <select v-model="filters.status" @change="applyFilters">
                            <option value="">Todos</option>
                            <option v-for="status in options.statuses" :key="status" :value="status">
                                {{ statusLabels[status] || status }}
                            </option>
                        </select>
                    </label>

                    <label class="inline-field">
                        <span class="field-icon">⚑</span>
                        <span class="ddl-name">Prioridade</span>
                        <select v-model="filters.priority" @change="applyFilters">
                            <option value="">Todas</option>
                            <option v-for="priority in options.priorities" :key="priority" :value="priority">
                                {{ priorityLabels[priority] || priority }}
                            </option>
                        </select>
                    </label>

                    <label class="inline-field is-sort">
                        <span class="field-icon">◷</span>
                        <button type="button" class="link-btn" @click="toggleSort('request_date')">Data pedido</button>
                    </label>
                </div>

                <div class="filter-actions">
                    <div class="filters-menu">
                        <button
                            type="button"
                            :class="['inline-filter-toggle', { 'is-open': showFiltersMenu }]"
                            @click.stop="toggleFiltersMenu"
                        >
                            <svg class="filters-toggle-icon" viewBox="0 0 24 24" fill="none" aria-hidden="true">
                                <path d="M3 5h18l-7 8v5l-4 2v-7L3 5z" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" />
                            </svg>
                            <span>Filtros ticket</span>
                            <span v-if="activeFilterCount" class="filters-count">{{ activeFilterCount }}</span>
                        </button>

                        <div v-if="showFiltersMenu" class="filters-dropdown" @click.stop>
                            <header class="filters-dropdown-header">
                                <h3>Filtros ticket</h3>
                                <p>Refina a lista com filtros avançados.</p>
                            </header>

                            <div class="filters-grid">
                                <label>
                                    Operador atribuído
                                    <select v-model="filters.assigned_operator_id" @change="applyFilters">
                                        <option value="">Todos</option>
                                        <option v-for="operator in options.operators" :key="operator.id" :value="String(operator.id)">
                                            {{ operator.name }}
                                        </option>
                                    </select>
                                </label>

                                <label>
                                    Tipo
                                    <select v-model="filters.type" @change="applyFilters">
                                        <option value="">Todos</option>
                                        <option v-for="type in options.types" :key="`menu-type-${type}`" :value="type">
                                            {{ typeLabels[type] || type }}
                                        </option>
                                    </select>
                                </label>

                                <label>
                                    Origem
                                    <select v-model="filters.inbox_id" @change="applyFilters">
                                        <option value="">Todas</option>
                                        <option v-for="inbox in options.inboxes" :key="`menu-inbox-${inbox.id}`" :value="String(inbox.id)">
                                            {{ inbox.name }}
                                        </option>
                                    </select>
                                </label>

                                <label>
                                    Estado
                                    <select v-model="filters.status" @change="applyFilters">
                                        <option value="">Todos</option>
                                        <option v-for="status in options.statuses" :key="`menu-status-${status}`" :value="status">
                                            {{ statusLabels[status] || status }}
                                        </option>
                                    </select>
                                </label>

                                <label>
                                    Prioridade
                                    <select v-model="filters.priority" @change="applyFilters">
                                        <option value="">Todas</option>
                                        <option v-for="priority in options.priorities" :key="`menu-priority-${priority}`" :value="priority">
                                            {{ priorityLabels[priority] || priority }}
                                        </option>
                                    </select>
                                </label>

                                <label>
                                    Entidade
                                    <select v-model="filters.entity_id" @change="applyFilters">
                                        <option value="">Todas</option>
                                        <option v-for="entity in options.entities" :key="entity.id" :value="String(entity.id)">
                                            {{ entity.name }}
                                        </option>
                                    </select>
                                </label>

                                <label>
                                    Ordenar por
                                    <select v-model="filters.sort_by" @change="applyFilters">
                                        <option v-for="sort in sortByOptions" :key="sort.value" :value="sort.value">
                                            {{ sort.label }}
                                        </option>
                                    </select>
                                </label>

                                <label>
                                    Ordem
                                    <select v-model="filters.sort_dir" @change="applyFilters">
                                        <option value="desc">Descendente</option>
                                        <option value="asc">Ascendente</option>
                                    </select>
                                </label>
                            </div>

                            <footer class="filters-dropdown-footer">
                                <span class="filters-helper">{{ activeFilterCount }} ativos</span>
                                <button type="button" class="btn-clean filters-clear-btn" @click="clearFilters">Limpar filtros</button>
                            </footer>
                        </div>
                    </div>
                </div>
            </form>

            <p v-if="error" class="error">{{ error }}</p>
            <p v-if="success" class="success">{{ success }}</p>
            <p v-if="loading" class="muted">A carregar...</p>

            <div v-if="!loading && tickets.length" class="bulk-toolbar">
                <div class="bulk-toolbar-left">
                    <span class="bulk-count">{{ selectedCount }} selecionado(s)</span>
                    <button
                        type="button"
                        class="btn-clean"
                        :disabled="selectedCount < 1"
                        @click="clearSelection"
                    >
                        Limpar seleção
                    </button>
                </div>

                <div class="bulk-toolbar-actions">
                    <label class="bulk-field">
                        <span>Estado</span>
                        <select v-model="bulkStatus" :disabled="bulkLoading">
                            <option value="">Selecionar</option>
                            <option v-for="status in options.statuses" :key="`bulk-status-${status}`" :value="status">
                                {{ statusLabels[status] || status }}
                            </option>
                        </select>
                    </label>
                    <button
                        type="button"
                        class="btn-primary bulk-btn"
                        :disabled="selectedCount < 1 || !bulkStatus || bulkLoading"
                        @click="applyBulkStatus"
                    >
                        Aplicar estado
                    </button>

                    <label class="bulk-field">
                        <span>Operador</span>
                        <select v-model="bulkAssignedOperatorId" :disabled="bulkLoading">
                            <option value="">Selecionar</option>
                            <option value="__none__">Sem atribuição</option>
                            <option v-for="operator in options.operators" :key="`bulk-operator-${operator.id}`" :value="String(operator.id)">
                                {{ operator.name }}
                            </option>
                        </select>
                    </label>
                    <button
                        type="button"
                        class="btn-primary bulk-btn"
                        :disabled="selectedCount < 1 || bulkAssignedOperatorId === '' || bulkLoading"
                        @click="applyBulkAssignment"
                    >
                        Aplicar operador
                    </button>
                </div>
            </div>

            <div class="table-wrap" v-if="!loading">
                <table class="tickets-table">
                    <thead>
                        <tr>
                            <th class="check-col">
                                <input
                                    ref="selectAllCheckboxRef"
                                    type="checkbox"
                                    :checked="areAllPageTicketsSelected"
                                    :disabled="!tickets.length || bulkLoading"
                                    @change="toggleSelectAllOnPage"
                                />
                            </th>
                            <th>
                                <button type="button" class="sort-btn" @click="toggleSort('ticket_number')">
                                    Ticket ID <span class="sort-indicator" :class="`is-${sortState('ticket_number')}`"></span>
                                </button>
                            </th>
                            <th>
                                <button type="button" class="sort-btn" @click="toggleSort('subject')">
                                    Assunto <span class="sort-indicator" :class="`is-${sortState('subject')}`"></span>
                                </button>
                            </th>
                            <th>
                                <button type="button" class="sort-btn" @click="toggleSort('priority')">
                                    Prioridade <span class="sort-indicator" :class="`is-${sortState('priority')}`"></span>
                                </button>
                            </th>
                            <th>
                                <button type="button" class="sort-btn" @click="toggleSort('status')">
                                    Estado <span class="sort-indicator" :class="`is-${sortState('status')}`"></span>
                                </button>
                            </th>
                            <th>
                                <button type="button" class="sort-btn" @click="toggleSort('type')">
                                    Tipo <span class="sort-indicator" :class="`is-${sortState('type')}`"></span>
                                </button>
                            </th>
                            <th>
                                <button type="button" class="sort-btn" @click="toggleSort('client')">
                                    Entidade <span class="sort-indicator" :class="`is-${sortState('client')}`"></span>
                                </button>
                            </th>
                            <th>Autor</th>
                            <th>
                                <button type="button" class="sort-btn" @click="toggleSort('request_date')">
                                    Data pedido <span class="sort-indicator" :class="`is-${sortState('request_date')}`"></span>
                                </button>
                            </th>
                            <th class="actions-col">Ações</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr
                            v-for="ticket in tickets"
                            :key="ticket.id"
                            :class="{ 'is-selected': isTicketSelected(ticket.id) }"
                        >
                            <td class="check-col">
                                <input
                                    type="checkbox"
                                    :checked="isTicketSelected(ticket.id)"
                                    :disabled="bulkLoading"
                                    @change="toggleTicketSelection(ticket.id)"
                                />
                            </td>
                            <td>
                                <RouterLink class="ticket-id" :to="{ name: 'tickets.show', params: { id: ticket.id } }">
                                    {{ ticket.ticket_number }}
                                </RouterLink>
                            </td>
                            <td class="subject-cell">
                                <RouterLink class="subject-link" :to="{ name: 'tickets.show', params: { id: ticket.id } }">
                                    {{ ticket.subject }}
                                </RouterLink>
                            </td>
                            <td>
                                <span class="priority-pill" :class="`priority-${ticket.priority}`">
                                    {{ priorityLabels[ticket.priority] || ticket.priority }}
                                </span>
                            </td>
                            <td>
                                <span class="status-pill" :class="`status-${ticket.status}`">
                                    {{ statusLabels[ticket.status] || ticket.status }}
                                </span>
                            </td>
                            <td>
                                <span class="type-pill">{{ typeLabels[ticket.type] || ticket.type }}</span>
                            </td>
                            <td>
                                <RouterLink
                                    v-if="entityLinkTarget(ticket)"
                                    class="table-ref-link"
                                    :to="entityLinkTarget(ticket)"
                                >
                                    {{ entityLabel(ticket) }}
                                </RouterLink>
                                <span v-else>{{ entityLabel(ticket) }}</span>
                            </td>
                            <td>
                                <RouterLink
                                    v-if="creatorLinkTarget(ticket)"
                                    class="table-ref-link"
                                    :to="creatorLinkTarget(ticket)"
                                >
                                    {{ creatorLabel(ticket) }}
                                </RouterLink>
                                <span v-else>{{ creatorLabel(ticket) }}</span>
                            </td>
                            <td>{{ formatDate(ticket.created_at || ticket.last_activity_at) }}</td>
                            <td class="actions-col">
                                <div class="row-actions">
                                    <button
                                        type="button"
                                        class="btn-clean row-action row-pin-btn"
                                        :class="{ 'is-pinned': ticket.is_pinned }"
                                        :disabled="isPinPending(ticket.id)"
                                        :title="ticket.is_pinned ?'Desafixar ticket' : 'Fixar ticket'"
                                        :aria-label="ticket.is_pinned ?'Desafixar ticket' : 'Fixar ticket'"
                                        @click.stop="toggleTicketPin(ticket)"
                                    >
                                        <svg class="row-pin-icon" viewBox="0 0 24 24" fill="none" aria-hidden="true">
                                            <path d="M9 4h6l-1.2 5.2 3.2 2.8v1.2H7v-1.2l3.2-2.8L9 4z" stroke="currentColor" stroke-width="1.7" stroke-linejoin="round" />
                                            <path d="M12 13v7" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" />
                                        </svg>
                                    </button>

                                    <div class="actions-menu" :ref="(el) => setActionsMenuRef(ticket.id, el)">
                                    <button
                                        type="button"
                                        class="btn-clean row-action menu-trigger"
                                        aria-label="Abrir ações"
                                        @click.stop="toggleActionsMenu(ticket.id)"
                                    >
                                        ⋯
                                    </button>
                                    <div
                                        v-if="openActionsMenuTicketId === ticket.id"
                                        :class="['actions-dropdown', { 'is-open-up': openActionsMenuPlacement === 'up' }]"
                                        @click.stop
                                    >
                                        <button type="button" class="menu-item" @click="openTicketView(ticket.id, 'conversation')">
                                            Abrir ticket
                                        </button>
                                        <button type="button" class="menu-item" @click="openTicketView(ticket.id, 'conversation', true)">
                                            Detalhes
                                        </button>
                                        <button type="button" class="menu-item" @click="openTicketView(ticket.id, 'conversation')">
                                            Conversação
                                        </button>
                                        <button type="button" class="menu-item" @click="openTicketView(ticket.id, 'task')">
                                            Task
                                        </button>
                                        <button type="button" class="menu-item" @click="openTicketView(ticket.id, 'activity_logs')">
                                            Activity Logs
                                        </button>
                                        <button type="button" class="menu-item" @click="openTicketView(ticket.id, 'notes')">
                                            Notas
                                        </button>
                                        <button type="button" class="menu-item" @click="openTicketEditor(ticket.id)">
                                            Editar
                                        </button>
                                        <button type="button" class="menu-item" @click="toggleTicketPin(ticket)">
                                            {{ ticket.is_pinned ?'Desafixar' : 'Fixar' }}
                                        </button>
                                    </div>
                                </div>
                                </div>
                            </td>
                        </tr>
                        <tr v-if="!tickets.length">
                            <td colspan="10" class="empty-row">Sem tickets para os filtros escolhidos.</td>
                        </tr>
                    </tbody>
                </table>
            </div>

            <footer class="pager">
                <button
                    class="btn-clean"
                    :disabled="meta.current_page <= 1"
                    @click="loadTickets(meta.current_page - 1)"
                >
                    Anterior
                </button>
                <span>Página {{ meta.current_page }} de {{ meta.last_page }}</span>
                <button
                    class="btn-clean"
                    :disabled="meta.current_page >= meta.last_page"
                    @click="loadTickets(meta.current_page + 1)"
                >
                    Seguinte
                </button>
            </footer>
        </article>

    </section>
</template>

<style scoped>
.ticket-page {
    display: grid;
}

.ticket-panel {
    background: #fff;
    border: 1px solid #d8e1ed;
    border-radius: 14px;
    overflow: visible;
}

.panel-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    gap: 0.8rem;
    padding: 0.8rem 1rem;
    border-bottom: 1px solid #e3ebf5;
}

h1 {
    margin: 0;
    font-size: 2rem;
    font-weight: 700;
}

.subtitle {
    margin: 0.2rem 0 0;
    color: #64748b;
    font-size: 0.84rem;
}

.header-actions {
    display: flex;
    align-items: center;
    gap: 0.55rem;
}

.btn-primary,
.btn-ghost,
.btn-clean {
    border-radius: 9px;
    border: 1px solid #d4dde8;
    font: inherit;
    text-decoration: none;
    cursor: pointer;
    padding: 0.45rem 0.78rem;
}

.btn-primary {
    background: #1fb873;
    border-color: #1fb873;
    color: #fff;
}

.btn-ghost {
    background: #fff;
    color: #334155;
}

.btn-clean {
    background: #fff;
    color: #334155;
}

.filter-bar {
    display: grid;
    grid-template-columns: minmax(260px, 340px) 1fr auto;
    gap: 0.75rem;
    align-items: center;
    padding: 0.85rem 1rem;
    border-bottom: 1px solid #e3ebf5;
    background: linear-gradient(180deg, #fcfdff 0%, #f6fbff 100%);
}

.search-field {
    border: 1px solid #d3deec;
    border-radius: 12px;
    background: #fff;
    min-height: 42px;
    display: flex;
    align-items: center;
    gap: 0.5rem;
    padding: 0 0.7rem;
    box-shadow: inset 0 1px 0 rgba(255, 255, 255, 0.7);
    transition: border-color 120ms ease, box-shadow 120ms ease;
}

.search-field:focus-within {
    border-color: #9dc0e4;
    box-shadow: 0 0 0 4px rgba(23, 128, 255, 0.12);
}

.search-kbd {
    border: 1px solid #d5dfec;
    border-bottom-width: 2px;
    border-radius: 6px;
    background: #f5f8fc;
    color: #64748b;
    font-size: 0.72rem;
    line-height: 1;
    padding: 0.12rem 0.28rem;
}

.inline-filters {
    display: flex;
    align-items: center;
    gap: 0.52rem;
    min-width: 0;
    overflow-x: auto;
    padding: 2px 2px 4px;
}

.inline-field {
    position: relative;
    min-height: 40px;
    display: inline-flex;
    align-items: center;
    gap: 0.36rem;
    color: #475569;
    white-space: nowrap;
    flex: 0 0 auto;
    border: 1px solid #d7e3f0;
    border-radius: 999px;
    background: #fff;
    padding: 0 0.52rem;
    transition: border-color 120ms ease, background-color 120ms ease, box-shadow 120ms ease;
}

.inline-field:hover {
    border-color: #b8cde3;
    background: #fafdff;
}

.inline-field:focus-within {
    border-color: #95b5d8;
    box-shadow: 0 0 0 3px rgba(35, 126, 214, 0.14);
}

.field-icon {
    color: #60758f;
    font-size: 1rem;
    width: 15px;
    display: inline-flex;
    justify-content: center;
    align-items: center;
    font-weight: 500;
}

.ddl-name {
    color: #3f526a;
    font-size: 0.83rem;
    font-weight: 600;
    letter-spacing: 0.01em;
}

.link-btn {
    border: none;
    background: transparent;
    color: #334155;
    font: inherit;
    padding: 0;
    cursor: pointer;
    font-size: 0.86rem;
    font-weight: 600;
}

.inline-field select {
    appearance: none;
    -webkit-appearance: none;
    -moz-appearance: none;
    border: 0;
    background: transparent;
    width: auto;
    min-width: 66px;
    color: #0f172a;
    font-size: 0.86rem;
    font-weight: 500;
    padding: 0.1rem 1rem 0.1rem 0.06rem;
    cursor: pointer;
}

.inline-field::after {
    content: '▾';
    font-size: 0.66rem;
    color: #7c8da2;
    margin-left: -0.72rem;
    pointer-events: none;
}

.inline-field.is-sort::after {
    display: none;
}

input,
select {
    border: none;
    border-radius: 0;
    padding: 0.46rem 0.1rem;
    font: inherit;
    color: #0f172a;
    background: #fff;
    width: 100%;
}

input:focus,
select:focus {
    outline: none;
}

.filter-actions {
    display: flex;
    align-items: center;
    gap: 0.5rem;
    justify-content: flex-start;
    flex: 0 0 auto;
}

.filters-menu {
    position: relative;
}

.inline-filter-toggle {
    display: inline-flex;
    align-items: center;
    gap: 0.48rem;
    border: 1px solid #cddced;
    background: #fff;
    color: #334155;
    padding: 0.46rem 0.72rem;
    border-radius: 999px;
    font-size: 0.88rem;
    font-weight: 600;
    cursor: pointer;
    transition: border-color 120ms ease, background-color 120ms ease, color 120ms ease, box-shadow 120ms ease;
}

.inline-filter-toggle:hover {
    border-color: #9ab9d8;
    background: #f3f9ff;
}

.inline-filter-toggle.is-open {
    border-color: #2b5d8d;
    background: #e8f0fa;
    color: #173e64;
    box-shadow: 0 0 0 3px rgba(31, 78, 121, 0.12);
}

.filters-toggle-icon {
    width: 15px;
    height: 15px;
}

.filters-count {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    min-width: 1.25rem;
    height: 1.25rem;
    border-radius: 999px;
    background: #ffffff;
    border: 1px solid rgba(31, 78, 121, 0.28);
    color: #1F4E79;
    font-size: 0.78rem;
    line-height: 1;
    font-weight: 700;
}

.filters-dropdown {
    position: absolute;
    top: calc(100% + 0.55rem);
    right: 0;
    z-index: 40;
    width: min(680px, calc(100vw - 3rem));
    border: 1px solid #cfe0f1;
    border-radius: 18px;
    background: linear-gradient(180deg, #ffffff 0%, #f8fbff 100%);
    box-shadow: 0 24px 42px rgba(15, 23, 42, 0.22);
    backdrop-filter: blur(8px);
    padding: 0.95rem;
}

.filters-dropdown-header {
    display: grid;
    gap: 0.15rem;
    margin-bottom: 0.75rem;
}

.filters-dropdown-header h3 {
    margin: 0;
    font-size: 1.02rem;
    color: #0f172a;
}

.filters-dropdown-header p {
    margin: 0;
    color: #64748b;
    font-size: 0.84rem;
}

.filters-grid {
    display: grid;
    grid-template-columns: repeat(2, minmax(0, 1fr));
    gap: 0.72rem;
    padding-bottom: 0.6rem;
}

.filters-grid label {
    display: grid;
    gap: 0.3rem;
    color: #334155;
    font-size: 0.83rem;
    font-weight: 600;
    border: 1px solid #dbe5f1;
    border-radius: 12px;
    background: #ffffff;
    padding: 0.52rem 0.56rem;
}

.filters-grid select {
    border: 1px solid #cfdbe9;
    border-radius: 9px;
    padding: 0.44rem 0.55rem;
    font: inherit;
    color: #0f172a;
    background: #fdfefe;
    width: 100%;
    appearance: auto;
    -webkit-appearance: auto;
    -moz-appearance: auto;
    font-weight: 500;
}

.filters-dropdown-footer {
    border-top: 1px solid #dce7f3;
    padding-top: 0.7rem;
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 0.5rem;
}

.filters-helper {
    color: #5b6f87;
    font-size: 0.82rem;
    font-weight: 600;
}

.filters-clear-btn {
    border-color: #c8d6e6;
    color: #344759;
    border-radius: 999px;
    padding: 0.4rem 0.7rem;
}

.table-wrap {
    overflow: auto;
}

.tickets-table {
    width: 100%;
    min-width: 980px;
    border-collapse: collapse;
}

.tickets-table th,
.tickets-table td {
    border-bottom: 1px solid #e8edf5;
    text-align: left;
    padding: 0.5rem 0.55rem;
    vertical-align: middle;
    font-size: 0.9rem;
}

.tickets-table th {
    font-size: 0.8rem;
    color: #475569;
    font-weight: 600;
    background: #fff;
}

.sort-btn {
    border: none;
    background: transparent;
    color: inherit;
    font: inherit;
    font-weight: 600;
    cursor: pointer;
    padding: 0;
    display: inline-flex;
    align-items: center;
    gap: 0.25rem;
    overflow: visible;
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
    border-left: 4px solid transparent;
    border-right: 4px solid transparent;
}

.sort-indicator::before {
    border-bottom: 5px solid #94a3b8;
}

.sort-indicator::after {
    border-top: 5px solid #94a3b8;
}

.sort-indicator.is-asc::before {
    border-bottom-color: #334155;
}

.sort-indicator.is-asc::after {
    border-top-color: #cbd5e1;
}

.sort-indicator.is-desc::before {
    border-bottom-color: #cbd5e1;
}

.sort-indicator.is-desc::after {
    border-top-color: #334155;
}

.check-col {
    width: 38px;
    text-align: center;
}

.check-col input[type='checkbox'] {
    width: 14px;
    height: 14px;
    cursor: pointer;
}

.actions-col {
    width: 120px;
    text-align: center;
}

.ticket-id {
    color: #0f172a;
    text-decoration: none;
    font-weight: 600;
}

.subject-cell {
    max-width: 290px;
}

.subject-link {
    color: #0f172a;
    text-decoration: none;
    display: inline-block;
    max-width: 280px;
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
}

.table-ref-link {
    color: #0f172a;
    text-decoration: none;
    border-bottom: 1px dashed #94a3b8;
}

.table-ref-link:hover {
    color: #1F4E79;
    border-bottom-color: #1F4E79;
}

.priority-pill,
.status-pill,
.type-pill {
    display: inline-flex;
    align-items: center;
    border-radius: 999px;
    padding: 0.15rem 0.55rem;
    font-size: 0.77rem;
    border: 1px solid transparent;
}

.priority-low { color: #1f4e79; background: #edf4fb; border-color: #bbf7d0; }
.priority-medium { color: #854d0e; background: #fef3c7; border-color: #fde68a; }
.priority-high { color: #991b1b; background: #fee2e2; border-color: #fecaca; }
.priority-urgent { color: #7f1d1d; background: #fee2e2; border-color: #fca5a5; }

.status-open { color: #1f4e79; background: #edf4fb; border-color: #b9cde4; }
.status-in_progress { color: #1d4ed8; background: #dbeafe; border-color: #93c5fd; }
.status-pending { color: #854d0e; background: #fef3c7; border-color: #fcd34d; }
.status-closed { color: #1F4E79; background: #e7f0fa; border-color: #bfd5eb; }
.status-cancelled { color: #991b1b; background: #fee2e2; border-color: #fca5a5; }

.type-pill {
    color: #334155;
    background: #f1f5f9;
    border-color: #dbe4ee;
}

.row-action {
    color: #64748b;
    text-decoration: none;
    font-weight: 700;
    padding: 0.2rem 0.42rem;
}

.actions-menu {
    position: relative;
    display: inline-flex;
}

.row-actions {
    display: inline-flex;
    align-items: center;
    gap: 0.25rem;
}

.row-pin-btn {
    width: 2rem;
    height: 2rem;
    border-radius: 999px;
    padding: 0;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    color: #60758f;
}

.row-pin-icon {
    width: 16px;
    height: 16px;
    transition: transform 0.18s ease;
}

.row-pin-btn.is-pinned {
    border-color: #a5d8bf;
    background: #eaf9f1;
    color: #0f8f62;
}

.row-pin-btn.is-pinned .row-pin-icon {
    transform: rotate(-16deg);
}

.menu-trigger {
    width: 2rem;
    height: 2rem;
    border-radius: 999px;
    padding: 0;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    font-size: 1.2rem;
    line-height: 1;
}

.actions-dropdown {
    position: absolute;
    top: calc(100% + 0.3rem);
    right: 0;
    z-index: 30;
    min-width: 168px;
    border: 1px solid #dbe4ee;
    border-radius: 10px;
    background: #fff;
    box-shadow: 0 12px 24px rgba(15, 23, 42, 0.18);
    padding: 0.32rem;
    display: grid;
    gap: 0.32rem;
}

.actions-dropdown.is-open-up {
    top: auto;
    bottom: calc(100% + 0.3rem);
}

.menu-item {
    width: 100%;
    border: 1px solid #cbd5e1;
    background: #fff;
    color: #0f172a;
    border-radius: 8px;
    text-align: left;
    padding: 0.42rem 0.56rem;
    cursor: pointer;
}

.menu-item:hover {
    background: #f1f5f9;
}

.empty-row {
    color: #64748b;
    text-align: center;
    padding: 1rem;
}

.tickets-table tbody tr.is-selected {
    background: #f0fdf4;
}

.pager {
    padding: 0.65rem 1rem;
    display: flex;
    justify-content: flex-end;
    align-items: center;
    gap: 0.55rem;
}

.muted {
    padding: 0 1rem;
    color: #64748b;
}

.error {
    margin: 0;
    padding: 0.65rem 1rem;
    color: #991b1b;
    border-bottom: 1px solid #fecaca;
    background: #fef2f2;
}

.success {
    margin: 0;
    padding: 0.65rem 1rem;
    color: #1F4E79;
    border-bottom: 1px solid #c8d8ea;
    background: #EDF3FA;
}

.bulk-toolbar {
    display: flex;
    justify-content: space-between;
    align-items: flex-end;
    gap: 0.8rem;
    padding: 0.7rem 1rem;
    border-bottom: 1px solid #e3ebf5;
    background: #f8fbff;
}

.bulk-toolbar-left {
    display: inline-flex;
    align-items: center;
    gap: 0.55rem;
}

.bulk-count {
    font-size: 0.85rem;
    font-weight: 700;
    color: #334155;
}

.bulk-toolbar-actions {
    display: flex;
    align-items: flex-end;
    gap: 0.55rem;
    flex-wrap: wrap;
    justify-content: flex-end;
}

.bulk-field {
    display: grid;
    gap: 0.25rem;
    min-width: 160px;
}

.bulk-field > span {
    font-size: 0.75rem;
    color: #64748b;
    font-weight: 600;
}

.bulk-btn {
    padding: 0.45rem 0.8rem;
    min-height: 36px;
}

@media (max-width: 1280px) {
    .filter-bar {
        grid-template-columns: 1fr auto;
        grid-template-areas:
            'search actions'
            'filters filters';
    }

    .search-field {
        grid-area: search;
    }

    .inline-filters {
        grid-area: filters;
    }

    .filter-actions {
        grid-area: actions;
        justify-content: flex-end;
    }

    .bulk-toolbar {
        flex-direction: column;
        align-items: stretch;
    }

    .bulk-toolbar-actions {
        justify-content: flex-start;
    }

}

@media (max-width: 720px) {
    .panel-header {
        flex-direction: column;
        align-items: flex-start;
    }

    h1 {
        font-size: 1.65rem;
    }

    .header-actions {
        width: 100%;
    }

    .header-actions .btn-primary,
    .header-actions .btn-ghost {
        flex: 1;
        text-align: center;
    }

    .filter-bar {
        grid-template-columns: 1fr;
        grid-template-areas:
            'search'
            'filters'
            'actions';
    }

    .filters-dropdown {
        width: min(420px, calc(100vw - 2.25rem));
    }

    .filters-grid {
        grid-template-columns: 1fr;
    }

    .filter-actions {
        justify-content: flex-start;
    }

    .bulk-toolbar-left {
        justify-content: space-between;
    }

    .bulk-field {
        min-width: 100%;
    }

}
</style>
