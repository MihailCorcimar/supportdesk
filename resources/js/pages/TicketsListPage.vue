<script setup>
import { computed, nextTick, onBeforeUnmount, onMounted, reactive, ref, watch, watchEffect } from 'vue';
import { RouterLink, useRoute, useRouter } from 'vue-router';
import api from '../api/client';
import { useAuthStore } from '../stores/auth';
import UserAvatar from '../components/UserAvatar.vue';
import QuickActionsRail from '../components/QuickActionsRail.vue';
import QuickMenuPanel from '../components/QuickMenuPanel.vue';

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
const showRequestDateMenu = ref(false);
const pinPendingIds = ref([]);
const selectedTicketIds = ref([]);
const bulkStatus = ref('');
const bulkAssignedOperatorId = ref('');
const bulkOperatorSearch = ref('');
const bulkOperatorPickerOpen = ref(false);
const bulkPanelOpen = ref(false);
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
    entity_ids: [],
    request_date_from: '',
    request_date_to: '',
    sort_by: 'last_activity_at',
    sort_dir: 'desc',
};

const filters = reactive({ ...defaultFilters });
const actionsMenuRefs = new Map();
const activeFilterCount = computed(() => {
    const keys = ['type', 'inbox_id', 'created_by_user_id', 'status', 'priority', 'assigned_operator_id'];
    const base = keys.reduce((count, key) => (filters[key] ?count + 1 : count), 0);
    const entityCount = Array.isArray(filters.entity_ids) ? filters.entity_ids.length : 0;
    const hasRequestDate = Boolean(filters.request_date_from || filters.request_date_to);

    return base + entityCount + (hasRequestDate ?1 : 0);
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

const statusIcons = {
    open: '◯',
    in_progress: '⟳',
    pending: '⌛',
    closed: '☑',
    cancelled: '⊗',
};

const formatStatusOption = (status) => `${statusIcons[status] || '⚪'} ${statusLabels[status] || status}`;
const statusOptionColor = (status) => {
    const map = {
        open: '#16a34a',
        in_progress: '#2563eb',
        pending: '#7c3aed',
        closed: '#0f766e',
        cancelled: '#dc2626',
    };
    return map[status] || '#0f172a';
};
const statusOptionStyle = (status) => ({ color: statusOptionColor(status) });

const priorityLabels = {
    low: 'Baixa',
    medium: 'Média',
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

const quickActions = computed(() => ([
    {
        id: 'filters',
        title: 'Filtros ticket',
        active: showFiltersMenu.value || activeFilterCount.value > 0,
        disabled: false,
    },
    {
        id: 'bulk',
        title: 'Alteração múltipla',
        active: bulkPanelOpen.value,
        disabled: bulkLoading.value || tickets.value.length === 0,
    },
]));
const isQuickPanelDocked = computed(() => (
    showFiltersMenu.value || bulkPanelOpen.value
));

const bulkFilteredOperators = computed(() => {
    const term = bulkOperatorSearch.value.trim().toLowerCase();
    const operators = options.value.operators || [];

    if (!term) return operators;

    return operators
        .map((operator) => {
            const name = (operator.name || '').toLowerCase();
            const words = name.split(/\s+/).filter(Boolean);

            if (name.startsWith(term)) {
                return { operator, score: 0, name };
            }

            if (words.some((word) => word.startsWith(term))) {
                return { operator, score: 1, name };
            }

            if (name.includes(term)) {
                return { operator, score: 2, name };
            }

            return null;
        })
        .filter((entry) => entry !== null)
        .sort((a, b) => {
            if (a.score !== b.score) {
                return a.score - b.score;
            }

            return a.name.localeCompare(b.name, 'pt-PT', { sensitivity: 'base' });
        })
        .map((entry) => entry.operator);
});

const bulkSelectedOperator = computed(() => {
    const selectedId = Number(bulkAssignedOperatorId.value || 0);
    if (!selectedId || bulkAssignedOperatorId.value === '__none__') return null;

    return (options.value.operators || []).find((operator) => Number(operator.id) === selectedId) || null;
});

const bulkOperatorSuggestions = computed(() => {
    const selectedId = Number(bulkAssignedOperatorId.value || 0);

    return bulkFilteredOperators.value
        .filter((operator) => Number(operator.id) !== selectedId)
        .slice(0, 10);
});

const entitySearch = ref('');
const entityDropdownOpen = ref(false);
const filteredEntityOptions = computed(() => {
    const term = entitySearch.value.trim().toLowerCase();
    const entities = options.value.entities || [];
    if (!term) return entities;
    return entities.filter((entity) => String(entity.name || '').toLowerCase().includes(term));
});

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
    const status = typeof route.query.status === 'string' ?route.query.status.trim() : '';
    filters.inbox_id = inboxId;
    filters.created_by_user_id = createdByUserId;
    filters.status = status;
};

const loadTickets = async (page = 1) => {
    loading.value = true;
    error.value = '';

    try {
        const params = { page };
        Object.entries(filters).forEach(([key, value]) => {
            if (Array.isArray(value)) {
                if (value.length) {
                    params[key] = value;
                }
                return;
            }
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

const toggleFilter = (key, value) => {
    filters[key] = filters[key] === value ? '' : value;
    applyFilters();
};

const activeFilterLabels = computed(() => {
    const chips = [];
    if (filters.status) chips.push({ key: 'status', label: statusLabels[filters.status] || filters.status });
    if (filters.priority) chips.push({ key: 'priority', label: priorityLabels[filters.priority] || filters.priority });
    if (filters.type) chips.push({ key: 'type', label: typeLabels[filters.type] || filters.type });
    if (filters.inbox_id) {
        const inbox = options.value.inboxes.find((i) => String(i.id) === filters.inbox_id);
        if (inbox) chips.push({ key: 'inbox_id', label: inbox.name });
    }
    if (Array.isArray(filters.entity_ids) && filters.entity_ids.length) {
        filters.entity_ids.forEach((entityId) => {
            const entity = options.value.entities.find((e) => String(e.id) === String(entityId));
            if (entity) chips.push({ key: `entity_id:${entity.id}`, label: entity.name });
        });
    }
    if (filters.assigned_operator_id) {
        const op = options.value.operators.find((o) => String(o.id) === filters.assigned_operator_id);
        if (op) chips.push({ key: 'assigned_operator_id', label: op.name });
    }
    if (filters.request_date_from || filters.request_date_to) {
        const from = filters.request_date_from;
        const to = filters.request_date_to;
        const label = from && to ? `${from} → ${to}` : from ? `desde ${from}` : `até ${to}`;
        chips.push({ key: 'date_range', label });
    }
    return chips;
});

const removeFilterChip = (key) => {
    if (key === 'date_range') {
        filters.request_date_from = '';
        filters.request_date_to = '';
    } else if (key.startsWith('entity_id:')) {
        const id = key.split(':')[1];
        filters.entity_ids = (filters.entity_ids || []).filter((item) => String(item) !== String(id));
    } else {
        filters[key] = '';
    }
    applyFilters();
};

const clearFilters = () => {
    Object.assign(filters, defaultFilters);
    entitySearch.value = '';
    openActionsMenuTicketId.value = null;
    showFiltersMenu.value = false;
    showRequestDateMenu.value = false;
    success.value = '';
    loadTickets(1);
};

const toggleFiltersMenu = () => {
    showFiltersMenu.value = !showFiltersMenu.value;
};

const closeFiltersMenu = () => {
    showFiltersMenu.value = false;
};

const requestDateLabel = computed(() => {
    const from = filters.request_date_from;
    const to = filters.request_date_to;

    if (from && to) return `${from} - ${to}`;
    if (from) return `Desde ${from}`;
    if (to) return `Até ${to}`;
    return 'Data pedido';
});

const normalizeRequestDateRange = () => {
    if (filters.request_date_from && filters.request_date_to && filters.request_date_from > filters.request_date_to) {
        const from = filters.request_date_from;
        filters.request_date_from = filters.request_date_to;
        filters.request_date_to = from;
    }
};

const toggleRequestDateMenu = () => {
    showRequestDateMenu.value = !showRequestDateMenu.value;
};

const applyRequestDateFilter = () => {
    normalizeRequestDateRange();
    applyFilters();
};

const clearRequestDateFilter = () => {
    if (!filters.request_date_from && !filters.request_date_to) return;

    filters.request_date_from = '';
    filters.request_date_to = '';
    applyFilters();
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

const handleConversationsUpdated = () => {
    if (loading.value) return;
    loadTickets(meta.value.current_page || 1);
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
        bulkPanelOpen.value = false;
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
        bulkPanelOpen.value = false;
    } catch (exception) {
        error.value = exception?.response?.data?.message || 'Não foi possível aplicar operador em lote.';
    } finally {
        bulkLoading.value = false;
    }
};

const toggleBulkPanel = () => {
    if (!bulkPanelOpen.value) {
        closeFiltersMenu();
    }
    bulkPanelOpen.value = !bulkPanelOpen.value;
};

const closeBulkPanel = () => {
    bulkPanelOpen.value = false;
};

const handleQuickAction = (actionId) => {
    if (actionId === 'filters') {
        if (!showFiltersMenu.value) {
            closeBulkPanel();
        }
        toggleFiltersMenu();
        return;
    }

    if (actionId === 'bulk') {
        toggleBulkPanel();
    }
};

watch(selectedCount, (nextCount, previousCount) => {
    if (nextCount > 0 && previousCount === 0) {
        closeFiltersMenu();
        bulkPanelOpen.value = true;
        return;
    }

    if (nextCount === 0) {
        bulkPanelOpen.value = false;
    }
});

const chooseBulkOperator = (id) => {
    bulkAssignedOperatorId.value = String(Number(id));
    bulkOperatorSearch.value = '';
    bulkOperatorPickerOpen.value = false;
};

const chooseBulkNoOperator = () => {
    bulkAssignedOperatorId.value = '__none__';
    bulkOperatorSearch.value = '';
    bulkOperatorPickerOpen.value = false;
};

const clearBulkOperatorSelection = () => {
    bulkAssignedOperatorId.value = '';
    bulkOperatorSearch.value = '';
    bulkOperatorPickerOpen.value = false;
};

const handleBulkOperatorSearchEnter = (event) => {
    if (event.key !== 'Enter') return;
    event.preventDefault();
    const first = bulkOperatorSuggestions.value[0];
    if (first) {
        chooseBulkOperator(first.id);
    }
};

const closeBulkOperatorPicker = () => {
    setTimeout(() => {
        bulkOperatorPickerOpen.value = false;
    }, 120);
};

const onBulkOperatorSearchInput = () => {
    bulkOperatorPickerOpen.value = bulkOperatorSearch.value.trim().length > 0;
};

const onBulkOperatorSearchFocus = () => {
    bulkOperatorPickerOpen.value = bulkOperatorSearch.value.trim().length > 0;
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

const openEntityDropdown = () => {
    entityDropdownOpen.value = true;
};

const closeEntityDropdown = () => {
    setTimeout(() => {
        entityDropdownOpen.value = false;
    }, 120);
};

const selectEntityFilter = (entityId) => {
    if (!entityId) {
        filters.entity_ids = [];
        entitySearch.value = '';
        entityDropdownOpen.value = false;
        applyFilters();
        return;
    }

    const normalized = String(entityId);
    const current = Array.isArray(filters.entity_ids) ? filters.entity_ids.map((id) => String(id)) : [];
    filters.entity_ids = current.includes(normalized)
        ? current.filter((id) => id !== normalized)
        : [...current, normalized];
    entitySearch.value = '';
    entityDropdownOpen.value = true;
    applyFilters();
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
    const target = event.target;
    if (!(target instanceof HTMLElement)) return;

    if (showRequestDateMenu.value && !target.closest('.request-date-menu')) {
        showRequestDateMenu.value = false;
    }

    if (bulkOperatorPickerOpen.value && !target.closest('.bulk-operator-picker')) {
        bulkOperatorPickerOpen.value = false;
    }

    if (entityDropdownOpen.value && !target.closest('.fp-entity-picker')) {
        entityDropdownOpen.value = false;
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
    window.addEventListener('supportdesk:conversations-updated', handleConversationsUpdated);
    hydrateFiltersFromQuery();
    await loadMeta();
    await loadTickets(1);
});

watchEffect(() => {
    if (selectAllCheckboxRef.value) {
        selectAllCheckboxRef.value.indeterminate = areSomePageTicketsSelected.value;
    }
});

const ticketPagesRange = computed(() => {
    const total = meta.value.last_page || 1;
    const current = meta.value.current_page || 1;
    const delta = 2;
    const range = [];
    for (let i = Math.max(1, current - delta); i <= Math.min(total, current + delta); i++) {
        range.push(i);
    }
    return range;
});

onBeforeUnmount(() => {
    document.removeEventListener('click', closeActionsMenuOnOutsideClick);
    document.removeEventListener('click', closeFiltersMenuOnOutsideClick);
    document.removeEventListener('keydown', handleGlobalKeydown);
    window.removeEventListener('supportdesk:conversations-updated', handleConversationsUpdated);
});
</script>

<template>
    <section class="ticket-page">
        <article class="ticket-panel">
            <header class="panel-header">
                <div>
                    <h1>Tickets</h1>
                    <p class="subtitle">{{ meta.total }} registos</p>
                </div>

                <div class="header-actions">
                    <RouterLink class="btn-primary" :to="{ name: 'tickets.create' }">Novo Ticket</RouterLink>
                </div>
            </header>

            <div class="filter-bar">
                <label class="search-field">
                    <span class="field-icon">⌕</span>
                    <input ref="searchInputRef" v-model="filters.search" placeholder="Pesquisar" @input="applyFiltersDebounced" />
                    <kbd class="search-kbd">Ctrl+K</kbd>
                </label>

                <div class="inline-filters">
                    <label class="inline-field">
                        <span class="field-icon" aria-hidden="true">
                            <svg class="field-icon-svg" viewBox="0 0 24 24">
                                <path d="M20 10.2V5a1 1 0 0 0-1-1h-5.2a2 2 0 0 0-1.4.6L4.6 12.4a2 2 0 0 0 0 2.8l4.2 4.2a2 2 0 0 0 2.8 0l7.8-7.8a2 2 0 0 0 .6-1.4Z" />
                                <circle cx="16.5" cy="7.5" r="1.2" />
                            </svg>
                        </span>
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
                        <span class="field-icon" aria-hidden="true">
                            <svg class="field-icon-svg field-icon-svg-state" viewBox="0 0 24 24">
                                <rect x="4" y="4" width="16" height="16" rx="3" class="state-frame"></rect>
                                <rect x="8" y="11" width="2.2" height="5" rx="0.7" class="state-bar"></rect>
                                <rect x="11.6" y="8.5" width="2.2" height="7.5" rx="0.7" class="state-bar"></rect>
                                <rect x="15.2" y="10" width="2.2" height="6" rx="0.7" class="state-bar"></rect>
                            </svg>
                        </span>
                        <span class="ddl-name">Estado</span>
                        <select v-model="filters.status" @change="applyFilters">
                            <option value="">Todos</option>
                            <option v-for="status in options.statuses" :key="status" :value="status" :style="statusOptionStyle(status)">
                                {{ formatStatusOption(status) }}
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

                    <div class="inline-field is-sort request-date-menu">
                        <span class="field-icon">◷</span>
                        <button type="button" class="link-btn" @click.stop="toggleRequestDateMenu">{{ requestDateLabel }}</button>
                        <button
                            v-if="filters.request_date_from || filters.request_date_to"
                            type="button"
                            class="request-date-clear"
                            title="Limpar período"
                            @click.stop="clearRequestDateFilter"
                        >
                            ×
                        </button>

                        <div v-if="showRequestDateMenu" class="request-date-popover" @click.stop>
                            <label>
                                De
                                <input v-model="filters.request_date_from" type="date" @change="applyRequestDateFilter" />
                            </label>
                            <label>
                                Até
                                <input v-model="filters.request_date_to" type="date" @change="applyRequestDateFilter" />
                            </label>
                            <div class="request-date-actions">
                                <button type="button" class="btn-clean request-date-btn" @click="clearRequestDateFilter">Limpar</button>
                                <button type="button" class="btn-primary request-date-btn" @click="applyRequestDateFilter">Aplicar</button>
                            </div>
                        </div>
                    </div>
                </div>

            </div>

            <p v-if="error" class="error">{{ error }}</p>
            <p v-if="success" class="success">{{ success }}</p>

            <div v-if="!loading && tickets.length" class="bulk-toolbar">
                <div class="bulk-toolbar-left">
                    <span class="bulk-count">{{ selectedCount }} selecionado(s)</span>
                </div>

            </div>

            <div class="table-wrap" :class="{ 'is-loading': loading }">
                <div v-if="loading" class="tickets-loading-overlay">
                    <div class="tickets-loading-spinner"></div>
                </div>
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
                            <td class="ticket-id-cell">
                                <RouterLink class="ticket-id-badge" :to="{ name: 'tickets.show', params: { id: ticket.id } }">
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
                                            <svg class="menu-item-icon" viewBox="0 0 20 20" fill="none"><path d="M10 2a8 8 0 1 1 0 16A8 8 0 0 1 10 2Z" stroke="currentColor" stroke-width="1.6"/><path d="M10 6v4l2.5 2.5" stroke="currentColor" stroke-width="1.6" stroke-linecap="round"/></svg>
                                            Abrir ticket
                                        </button>
                                        <button type="button" class="menu-item" @click="openTicketView(ticket.id, 'conversation', true)">
                                            <svg class="menu-item-icon" viewBox="0 0 20 20" fill="none"><rect x="3" y="3" width="14" height="14" rx="3" stroke="currentColor" stroke-width="1.6"/><path d="M6.5 7h7M6.5 10h7M6.5 13h4" stroke="currentColor" stroke-width="1.5" stroke-linecap="round"/></svg>
                                            Detalhes
                                        </button>
                                        <button type="button" class="menu-item" @click="openTicketView(ticket.id, 'conversation')">
                                            <svg class="menu-item-icon" viewBox="0 0 20 20" fill="none"><path d="M3 5.5A1.5 1.5 0 0 1 4.5 4h11A1.5 1.5 0 0 1 17 5.5v7A1.5 1.5 0 0 1 15.5 14H11l-3 3v-3H4.5A1.5 1.5 0 0 1 3 12.5v-7Z" stroke="currentColor" stroke-width="1.6" stroke-linejoin="round"/></svg>
                                            Chat
                                        </button>
                                        <button type="button" class="menu-item" @click="openTicketView(ticket.id, 'task')">
                                            <svg class="menu-item-icon" viewBox="0 0 20 20" fill="none"><rect x="3" y="3" width="14" height="14" rx="3" stroke="currentColor" stroke-width="1.6"/><path d="M7 10l2 2 4-4" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"/></svg>
                                            Task
                                        </button>
                                        <button type="button" class="menu-item" @click="openTicketView(ticket.id, 'activity_logs')">
                                            <svg class="menu-item-icon" viewBox="0 0 20 20" fill="none"><path d="M4 5h12M4 10h8M4 15h10" stroke="currentColor" stroke-width="1.6" stroke-linecap="round"/><circle cx="15.5" cy="14.5" r="2.5" stroke="currentColor" stroke-width="1.4"/><path d="M15.5 13.5v1l.75.75" stroke="currentColor" stroke-width="1.3" stroke-linecap="round"/></svg>
                                            Activity Logs
                                        </button>
                                        <button type="button" class="menu-item" @click="openTicketView(ticket.id, 'notes')">
                                            <svg class="menu-item-icon" viewBox="0 0 20 20" fill="none"><path d="M5 3h8l4 4v10a1 1 0 0 1-1 1H5a1 1 0 0 1-1-1V4a1 1 0 0 1 1-1Z" stroke="currentColor" stroke-width="1.6" stroke-linejoin="round"/><path d="M13 3v4h4" stroke="currentColor" stroke-width="1.5" stroke-linejoin="round"/><path d="M7 10h6M7 13h4" stroke="currentColor" stroke-width="1.4" stroke-linecap="round"/></svg>
                                            Notas
                                        </button>
                                        <button type="button" class="menu-item" @click="openTicketEditor(ticket.id)">
                                            <svg class="menu-item-icon" viewBox="0 0 20 20" fill="none"><path d="M13.5 3.5a2.121 2.121 0 0 1 3 3L6 17H3v-3L13.5 3.5Z" stroke="currentColor" stroke-width="1.6" stroke-linejoin="round"/></svg>
                                            Editar
                                        </button>
                                        <button type="button" class="menu-item" :class="{ 'is-unpin': ticket.is_pinned }" @click="toggleTicketPin(ticket)">
                                            <svg class="menu-item-icon" viewBox="0 0 20 20" fill="none"><path d="M7.5 3.5h5l-1 4.5 2.5 2V11H6v-.5l2.5-2-1-4.5Z" stroke="currentColor" stroke-width="1.6" stroke-linejoin="round"/><path d="M10 11v6" stroke="currentColor" stroke-width="1.6" stroke-linecap="round"/></svg>
                                            {{ ticket.is_pinned ? 'Desafixar' : 'Fixar' }}
                                        </button>
                                    </div>
                                </div>
                                </div>
                            </td>
                        </tr>
                        <tr v-if="!tickets.length && !loading">
                            <td colspan="10" class="tickets-empty">
                                <svg class="tickets-empty-icon" viewBox="0 0 48 48" fill="none">
                                    <rect x="6" y="10" width="36" height="30" rx="5" stroke="#c4d0de" stroke-width="2.2"/>
                                    <path d="M6 17h36" stroke="#c4d0de" stroke-width="2.2"/>
                                    <path d="M17 27h14M20 33h8" stroke="#c4d0de" stroke-width="2" stroke-linecap="round"/>
                                    <circle cx="12" cy="13.5" r="1.5" fill="#c4d0de"/>
                                    <circle cx="17" cy="13.5" r="1.5" fill="#c4d0de"/>
                                    <circle cx="22" cy="13.5" r="1.5" fill="#c4d0de"/>
                                </svg>
                                <p class="tickets-empty-text">Sem tickets para os filtros escolhidos.</p>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>

            <footer class="pager">
                <button
                    class="page-btn"
                    :disabled="meta.current_page <= 1"
                    @click="loadTickets(meta.current_page - 1)"
                    title="Anterior"
                >‹</button>
                <button
                    v-for="p in ticketPagesRange"
                    :key="p"
                    class="page-btn"
                    :class="{ 'is-active': p === meta.current_page }"
                    @click="loadTickets(p)"
                >{{ p }}</button>
                <button
                    class="page-btn"
                    :disabled="meta.current_page >= meta.last_page"
                    @click="loadTickets(meta.current_page + 1)"
                    title="Seguinte"
                >›</button>
                <span class="page-info">Página {{ meta.current_page }} de {{ meta.last_page }} · {{ meta.total }} tickets</span>
            </footer>
        </article>

        <QuickActionsRail
            v-if="!loading"
            :actions="quickActions"
            aria-label="Ações tickets"
            desktop-style="floating"
            mobile-style="bottom"
            :dock="isQuickPanelDocked"
            dock-offset="min(420px, calc(100vw - 1.2rem))"
            @action="handleQuickAction"
        >
            <template #icon-filters>
                <svg viewBox="0 0 24 24" fill="none" aria-hidden="true">
                    <path d="M3 5h18l-7 8v5l-4 2v-7L3 5z" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" />
                </svg>
            </template>
            <template #icon-bulk>
                <svg viewBox="0 0 24 24" fill="none" aria-hidden="true">
                    <path d="M4 6h10M4 12h16M4 18h12" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" />
                    <circle cx="17" cy="6" r="2" fill="currentColor" />
                    <circle cx="9" cy="18" r="2" fill="currentColor" />
                </svg>
            </template>
        </QuickActionsRail>

        <QuickMenuPanel
            :open="showFiltersMenu"
            title="Filtros"
            aria-label="Filtros ticket"
            @close="closeFiltersMenu"
        >
            <div class="fp-body">

                <!-- Chips de filtros ativos -->
                <div v-if="activeFilterLabels.length" class="fp-active-bar">
                    <span class="fp-active-title">Ativos:</span>
                    <div class="fp-active-chips">
                        <span
                            v-for="chip in activeFilterLabels"
                            :key="chip.key"
                            class="fp-chip"
                        >
                            {{ chip.label }}
                            <button type="button" class="fp-chip-remove" :aria-label="`Remover filtro ${chip.label}`" @click="removeFilterChip(chip.key)">
                                <svg viewBox="0 0 10 10" fill="none"><path d="M2 2l6 6M8 2l-6 6" stroke="currentColor" stroke-width="1.6" stroke-linecap="round"/></svg>
                            </button>
                        </span>
                    </div>
                </div>

                <!-- Estado -->
                <div class="fp-section">
                    <p class="fp-section-label">
                        <svg viewBox="0 0 14 14" fill="none" class="fp-section-icon"><rect x="1" y="1" width="12" height="12" rx="2.5" stroke="currentColor" stroke-width="1.4"/><path d="M4 7.5l2 2 4-4" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"/></svg>
                        Estado
                    </p>
                    <div class="fp-pills">
                        <button
                            v-for="status in options.statuses"
                            :key="`fp-status-${status}`"
                            type="button"
                            class="fp-pill"
                            :class="[`fp-pill--status-${status}`, { 'is-active': filters.status === status }]"
                            @click="toggleFilter('status', status)"
                        >{{ statusLabels[status] || status }}</button>
                    </div>
                </div>

                <!-- Prioridade -->
                <div class="fp-section">
                    <p class="fp-section-label">
                        <svg viewBox="0 0 14 14" fill="none" class="fp-section-icon"><path d="M7 1v8M4 6l3 5 3-5" stroke="currentColor" stroke-width="1.4" stroke-linecap="round" stroke-linejoin="round"/></svg>
                        Prioridade
                    </p>
                    <div class="fp-pills">
                        <button
                            v-for="(label, key) in priorityLabels"
                            :key="`fp-priority-${key}`"
                            type="button"
                            class="fp-pill"
                            :class="[`fp-pill--priority-${key}`, { 'is-active': filters.priority === key }]"
                            @click="toggleFilter('priority', key)"
                        >{{ label }}</button>
                    </div>
                </div>

                <!-- Tipo -->
                <div class="fp-section">
                    <p class="fp-section-label">
                        <svg viewBox="0 0 14 14" fill="none" class="fp-section-icon"><path d="M2 2.5A1.5 1.5 0 0 1 3.5 1h5L11 4v7.5A1.5 1.5 0 0 1 9.5 13h-6A1.5 1.5 0 0 1 2 11.5V2.5Z" stroke="currentColor" stroke-width="1.4" stroke-linejoin="round"/><path d="M8.5 1v3H11" stroke="currentColor" stroke-width="1.3" stroke-linejoin="round"/></svg>
                        Tipo
                    </p>
                    <div class="fp-pills">
                        <button
                            v-for="(label, key) in typeLabels"
                            :key="`fp-type-${key}`"
                            type="button"
                            class="fp-pill"
                            :class="[`fp-pill--type`, { 'is-active': filters.type === key }]"
                            @click="toggleFilter('type', key)"
                        >{{ label }}</button>
                    </div>
                </div>

                <div class="fp-divider"></div>

                <!-- Operador -->
                <div class="fp-section">
                    <p class="fp-section-label">
                        <svg viewBox="0 0 14 14" fill="none" class="fp-section-icon"><circle cx="7" cy="4.5" r="2.5" stroke="currentColor" stroke-width="1.4"/><path d="M1.5 12c0-2.8 2.5-4.5 5.5-4.5s5.5 1.7 5.5 4.5" stroke="currentColor" stroke-width="1.4" stroke-linecap="round"/></svg>
                        Operador atribuído
                    </p>
                    <select class="fp-select" v-model="filters.assigned_operator_id" @change="applyFilters">
                        <option value="">Qualquer operador</option>
                        <option v-for="operator in options.operators" :key="operator.id" :value="String(operator.id)">{{ operator.name }}</option>
                    </select>
                </div>

                <!-- Origem + Entidade -->
                <div class="fp-row-2">
                    <div class="fp-section">
                        <p class="fp-section-label">
                            <svg viewBox="0 0 14 14" fill="none" class="fp-section-icon"><path d="M1.5 3A1.5 1.5 0 0 1 3 1.5h8A1.5 1.5 0 0 1 12.5 3v5A1.5 1.5 0 0 1 11 9.5H8.5l-1.5 2-1.5-2H3A1.5 1.5 0 0 1 1.5 8V3Z" stroke="currentColor" stroke-width="1.3" stroke-linejoin="round"/></svg>
                            Inbox
                        </p>
                        <select class="fp-select" v-model="filters.inbox_id" @change="applyFilters">
                            <option value="">Todas</option>
                            <option v-for="inbox in options.inboxes" :key="`fp-inbox-${inbox.id}`" :value="String(inbox.id)">{{ inbox.name }}</option>
                        </select>
                    </div>
                    <div class="fp-section">
                        <p class="fp-section-label">
                            <svg viewBox="0 0 14 14" fill="none" class="fp-section-icon"><rect x="1.5" y="4" width="11" height="8.5" rx="1.5" stroke="currentColor" stroke-width="1.3"/><path d="M4.5 4V3a1 1 0 0 1 1-1h3a1 1 0 0 1 1 1v1" stroke="currentColor" stroke-width="1.3"/></svg>
                            Entidade
                        </p>
                        <div class="fp-entity-picker">
                            <input
                                v-model="entitySearch"
                                type="search"
                                class="fp-select fp-entity-input"
                                placeholder="Pesquisar entidade"
                                @focus="openEntityDropdown"
                                @input="openEntityDropdown"
                                @blur="closeEntityDropdown"
                            />
                            <div v-if="entityDropdownOpen" class="fp-entity-options">
                                <button
                                    type="button"
                                    class="fp-entity-option"
                                    :class="{ 'is-active': !filters.entity_ids.length }"
                                    @mousedown.prevent="selectEntityFilter('')"
                                >
                                    Todas
                                </button>
                                <button
                                    v-for="entity in filteredEntityOptions"
                                    :key="`fp-entity-${entity.id}`"
                                    type="button"
                                    class="fp-entity-option"
                                    :class="{ 'is-active': filters.entity_ids.map(String).includes(String(entity.id)) }"
                                    @mousedown.prevent="selectEntityFilter(entity.id)"
                                >
                                    {{ entity.name }}
                                </button>
                                <p v-if="!filteredEntityOptions.length" class="fp-entity-empty">Sem resultados.</p>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="fp-divider"></div>

                <!-- Data pedido -->
                <div class="fp-section">
                    <p class="fp-section-label">
                        <svg viewBox="0 0 14 14" fill="none" class="fp-section-icon"><rect x="1" y="2.5" width="12" height="10" rx="1.5" stroke="currentColor" stroke-width="1.3"/><path d="M4.5 1v3M9.5 1v3M1 6.5h12" stroke="currentColor" stroke-width="1.3" stroke-linecap="round"/></svg>
                        Data do pedido
                    </p>
                    <div class="fp-date-row">
                        <div class="fp-date-field">
                            <span class="fp-date-lbl">De</span>
                            <input type="date" class="fp-date-input" v-model="filters.request_date_from" @change="applyRequestDateFilter" />
                        </div>
                        <svg class="fp-date-sep" viewBox="0 0 16 8" fill="none"><path d="M0 4h13M10 1l3 3-3 3" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"/></svg>
                        <div class="fp-date-field">
                            <span class="fp-date-lbl">Até</span>
                            <input type="date" class="fp-date-input" v-model="filters.request_date_to" @change="applyRequestDateFilter" />
                        </div>
                    </div>
                </div>

                <!-- Footer -->
                <footer class="fp-footer">
                    <span class="fp-footer-count">
                        <template v-if="activeFilterCount > 0">{{ activeFilterCount }} filtro{{ activeFilterCount !== 1 ? 's' : '' }} ativo{{ activeFilterCount !== 1 ? 's' : '' }}</template>
                        <template v-else>Sem filtros ativos</template>
                    </span>
                    <button
                        v-if="activeFilterCount > 0"
                        type="button"
                        class="fp-clear-btn"
                        @click="clearFilters"
                    >Limpar todos</button>
                </footer>
            </div>
        </QuickMenuPanel>

        <QuickMenuPanel
            :open="!loading && tickets.length && bulkPanelOpen"
            title="Alteração múltipla"
            aria-label="Alteração múltipla"
            @close="closeBulkPanel"
        >
            <div class="bulk-panel-body">
                <p class="bulk-panel-note">
                    {{ selectedCount }} ticket(s) selecionado(s)
                </p>

                <button
                    type="button"
                    class="btn-clean bulk-panel-clear"
                    :disabled="selectedCount < 1"
                    @click="clearSelection"
                >
                    Limpar seleção
                </button>

                <label class="bulk-field bulk-panel-field">
                    <span>Estado</span>
                    <select v-model="bulkStatus" class="status-select-gloss" :disabled="bulkLoading">
                        <option value="">Selecionar</option>
                        <option v-for="status in options.statuses" :key="`bulk-status-${status}`" :value="status" :style="statusOptionStyle(status)">
                            {{ formatStatusOption(status) }}
                        </option>
                    </select>
                </label>

                <button
                    type="button"
                    class="btn-primary bulk-panel-btn"
                    :disabled="selectedCount < 1 || !bulkStatus || bulkLoading"
                    @click="applyBulkStatus"
                >
                    Aplicar estado
                </button>

                <label class="bulk-field bulk-panel-field">
                    <span>Operador</span>
                    <div class="bulk-operator-picker">
                        <div v-if="bulkSelectedOperator" class="bulk-selected-chip">
                            <UserAvatar
                                class="bulk-selected-avatar"
                                :name="bulkSelectedOperator.name"
                                :src="bulkSelectedOperator.avatar_url"
                                :size="20"
                            />
                            <span class="bulk-selected-name">{{ bulkSelectedOperator.name }}</span>
                            <button
                                type="button"
                                title="Limpar operador"
                                :disabled="bulkLoading"
                                @click="clearBulkOperatorSelection"
                            >
                                &times;
                            </button>
                        </div>

                        <div v-else-if="bulkAssignedOperatorId === '__none__'" class="bulk-selected-chip is-clear">
                            <span class="bulk-selected-clear">-</span>
                            <span class="bulk-selected-name">Sem atribuição</span>
                            <button
                                type="button"
                                title="Limpar operador"
                                :disabled="bulkLoading"
                                @click="clearBulkOperatorSelection"
                            >
                                &times;
                            </button>
                        </div>

                        <input
                            v-if="bulkAssignedOperatorId === ''"
                            v-model="bulkOperatorSearch"
                            type="search"
                            placeholder="Pesquisar operador"
                            :disabled="bulkLoading"
                            @focus="onBulkOperatorSearchFocus"
                            @input="onBulkOperatorSearchInput"
                            @blur="closeBulkOperatorPicker"
                            @keydown="handleBulkOperatorSearchEnter"
                        >

                        <div v-if="bulkOperatorPickerOpen" class="bulk-operator-suggestions">
                            <button
                                type="button"
                                class="bulk-operator-option is-clear-option"
                                :disabled="bulkLoading"
                                @mousedown.prevent="chooseBulkNoOperator"
                            >
                                <span class="bulk-selected-clear">-</span>
                                <span class="bulk-option-meta">
                                    <strong>Sem atribuição</strong>
                                </span>
                            </button>

                            <button
                                v-for="operator in bulkOperatorSuggestions"
                                :key="`bulk-operator-${operator.id}`"
                                type="button"
                                class="bulk-operator-option"
                                :disabled="bulkLoading"
                                @mousedown.prevent="chooseBulkOperator(operator.id)"
                            >
                                <UserAvatar
                                    class="bulk-selected-avatar"
                                    :name="operator.name"
                                    :src="operator.avatar_url"
                                    :size="20"
                                />
                                <span class="bulk-option-meta">
                                    <strong>{{ operator.name }}</strong>
                                    <small>{{ operator.email }}</small>
                                </span>
                            </button>

                            <p v-if="!bulkOperatorSuggestions.length" class="bulk-operator-empty">Sem resultados.</p>
                        </div>
                    </div>
                </label>

                <button
                    type="button"
                    class="btn-primary bulk-panel-btn"
                    :disabled="selectedCount < 1 || bulkAssignedOperatorId === '' || bulkLoading"
                    @click="applyBulkAssignment"
                >
                    Aplicar operador
                </button>
            </div>
        </QuickMenuPanel>

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
    grid-template-columns: minmax(260px, 340px) 1fr;
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
    flex-wrap: wrap;
    overflow: visible;
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
    font-size: 1.12rem;
    width: 18px;
    display: inline-flex;
    justify-content: center;
    align-items: center;
    font-weight: 500;
}

.field-icon-svg {
    width: 16px;
    height: 16px;
    display: block;
    stroke: currentColor;
    stroke-width: 1.8;
    fill: none;
    stroke-linecap: round;
    stroke-linejoin: round;
}

.field-icon-svg-state .state-frame {
    fill: none;
}

.field-icon-svg-state .state-bar {
    fill: currentColor;
    stroke: none;
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

.request-date-menu {
    position: relative;
}

.request-date-clear {
    border: none;
    background: transparent;
    color: #8aa0b8;
    font-size: 1rem;
    line-height: 1;
    padding: 0 0.1rem;
    cursor: pointer;
}

.request-date-clear:hover {
    color: #45698f;
}

.request-date-popover {
    position: absolute;
    top: calc(100% + 0.45rem);
    right: 0;
    z-index: 90;
    min-width: 250px;
    border: 1px solid #cfe0f1;
    border-radius: 12px;
    background: #fff;
    box-shadow: 0 18px 30px rgba(15, 23, 42, 0.2);
    padding: 0.62rem;
    display: grid;
    gap: 0.55rem;
}

.request-date-popover label {
    display: grid;
    gap: 0.22rem;
    color: #334155;
    font-size: 0.8rem;
    font-weight: 600;
}

.request-date-popover input[type="date"] {
    border: 1px solid #cfdbe9;
    border-radius: 9px;
    padding: 0.42rem 0.5rem;
    font-size: 0.83rem;
    width: 100%;
}

.request-date-actions {
    display: flex;
    justify-content: flex-end;
    gap: 0.45rem;
}

.request-date-btn {
    min-height: 30px;
    border-radius: 999px;
    padding: 0.3rem 0.66rem;
    font-size: 0.78rem;
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

.status-select-gloss {
    border: 1px solid #b8d2ea;
    border-radius: 10px;
    background-image: linear-gradient(
        145deg,
        rgba(255, 255, 255, 0.98) 0%,
        rgba(226, 241, 255, 0.95) 32%,
        rgba(255, 255, 255, 0.96) 58%,
        rgba(209, 232, 252, 0.94) 100%
    );
    background-size: 200% 100%;
    box-shadow:
        inset 0 1px 0 rgba(255, 255, 255, 0.95),
        inset 0 10px 16px rgba(168, 205, 238, 0.24),
        0 0 0 1px rgba(168, 205, 238, 0.2);
    text-shadow:
        0 0 4px rgba(255, 255, 255, 0.95),
        0 0 7px rgba(147, 197, 253, 0.45);
    animation: statusGlossWave 3.2s ease-in-out infinite;
}

.status-select-gloss:focus {
    border-color: #8bb8df;
    box-shadow:
        inset 0 1px 0 rgba(255, 255, 255, 0.95),
        inset 0 10px 16px rgba(168, 205, 238, 0.28),
        0 0 0 3px rgba(59, 130, 246, 0.18);
}

@keyframes statusGlossWave {
    0% { background-position: 0% 0%; }
    50% { background-position: 100% 0%; }
    100% { background-position: 0% 0%; }
}

input:focus,
select:focus {
    outline: none;
}

/* ── Filters panel (fp-*) ─────────────────────────────────── */

.fp-body {
    display: flex;
    flex-direction: column;
    gap: 0;
    padding: 0.6rem 0.75rem 0.75rem;
    overflow-y: auto;
    height: 100%;
}

/* Active filter chips bar */
.fp-active-bar {
    display: flex;
    align-items: flex-start;
    gap: 0.4rem 0.5rem;
    flex-wrap: wrap;
    background: #eef4fb;
    border: 1px solid #d3e3f5;
    border-radius: 10px;
    padding: 0.45rem 0.6rem;
    margin-bottom: 0.55rem;
}

.fp-active-title {
    font-size: 0.76rem;
    font-weight: 700;
    color: #4a6a8a;
    white-space: nowrap;
    line-height: 1.8;
}

.fp-active-chips {
    display: flex;
    flex-wrap: wrap;
    gap: 0.3rem;
}

.fp-chip {
    display: inline-flex;
    align-items: center;
    gap: 0.25rem;
    background: #fff;
    border: 1px solid #b8d0ea;
    border-radius: 999px;
    padding: 0.18rem 0.4rem 0.18rem 0.55rem;
    font-size: 0.77rem;
    font-weight: 600;
    color: #1e3a5f;
    line-height: 1;
}

.fp-chip-remove {
    display: flex;
    align-items: center;
    justify-content: center;
    width: 16px;
    height: 16px;
    border-radius: 50%;
    border: none;
    background: #d4e4f5;
    color: #3a6a9a;
    cursor: pointer;
    padding: 0;
    flex-shrink: 0;
}

.fp-chip-remove:hover {
    background: #bad0e9;
    color: #1e3a5f;
}

.fp-chip-remove svg {
    width: 8px;
    height: 8px;
}

/* Section */
.fp-section {
    display: flex;
    flex-direction: column;
    gap: 0.35rem;
    padding: 0.55rem 0;
}

.fp-section-label {
    display: flex;
    align-items: center;
    gap: 0.3rem;
    margin: 0;
    font-size: 0.78rem;
    font-weight: 700;
    color: #4a6785;
    text-transform: uppercase;
    letter-spacing: 0.04em;
}

.fp-section-icon {
    width: 13px;
    height: 13px;
    flex-shrink: 0;
    color: #6a90b0;
}

/* Pill buttons */
.fp-pills {
    display: flex;
    flex-wrap: wrap;
    gap: 0.3rem;
}

.fp-pill {
    display: inline-flex;
    align-items: center;
    border-radius: 999px;
    border: 1.5px solid #c8d8ea;
    background: #fff;
    color: #334155;
    font: inherit;
    font-size: 0.8rem;
    font-weight: 600;
    padding: 0.22rem 0.72rem;
    cursor: pointer;
    transition: background 120ms, border-color 120ms, color 120ms;
    line-height: 1.5;
}

.fp-pill:hover {
    border-color: #94b8d4;
    background: #f0f6fb;
}

/* Status pills */
.fp-pill--status-open.is-active        { background: #dcfce7; border-color: #16a34a; color: #15803d; }
.fp-pill--status-open:not(.is-active):hover { border-color: #16a34a; color: #16a34a; }

.fp-pill--status-in_progress.is-active        { background: #dbeafe; border-color: #2563eb; color: #1d4ed8; }
.fp-pill--status-in_progress:not(.is-active):hover { border-color: #2563eb; color: #2563eb; }

.fp-pill--status-pending.is-active        { background: #f3e8ff; border-color: #7c3aed; color: #6d28d9; }
.fp-pill--status-pending:not(.is-active):hover { border-color: #7c3aed; color: #7c3aed; }

.fp-pill--status-closed.is-active        { background: #ccfbf1; border-color: #0f766e; color: #0f766e; }
.fp-pill--status-closed:not(.is-active):hover { border-color: #0f766e; color: #0f766e; }

.fp-pill--status-cancelled.is-active        { background: #fee2e2; border-color: #dc2626; color: #b91c1c; }
.fp-pill--status-cancelled:not(.is-active):hover { border-color: #dc2626; color: #dc2626; }

/* Priority pills */
.fp-pill--priority-low.is-active        { background: #f0fdf4; border-color: #4ade80; color: #15803d; }
.fp-pill--priority-low:not(.is-active):hover { border-color: #4ade80; color: #15803d; }

.fp-pill--priority-medium.is-active        { background: #fefce8; border-color: #ca8a04; color: #854d0e; }
.fp-pill--priority-medium:not(.is-active):hover { border-color: #ca8a04; color: #854d0e; }

.fp-pill--priority-high.is-active        { background: #fff7ed; border-color: #ea580c; color: #9a3412; }
.fp-pill--priority-high:not(.is-active):hover { border-color: #ea580c; color: #9a3412; }

.fp-pill--priority-urgent.is-active        { background: #fef2f2; border-color: #dc2626; color: #991b1b; }
.fp-pill--priority-urgent:not(.is-active):hover { border-color: #dc2626; color: #991b1b; }

/* Type pills */
.fp-pill--type.is-active        { background: #eff6ff; border-color: #3b82f6; color: #1d4ed8; }
.fp-pill--type:not(.is-active):hover { border-color: #3b82f6; color: #1d4ed8; }

/* Select */
.fp-select {
    border: 1px solid #cfdbe9;
    border-radius: 9px;
    padding: 0.42rem 0.6rem;
    font: inherit;
    font-size: 0.85rem;
    color: #0f172a;
    background: #fafcff;
    width: 100%;
    cursor: pointer;
}

.fp-select:focus {
    outline: none;
    border-color: #7ab0d8;
    box-shadow: 0 0 0 3px rgba(59, 130, 246, 0.12);
}

/* Two-column row */
.fp-row-2 {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 0.6rem;
}

.fp-entity-picker {
    position: relative;
}

.fp-entity-input {
    padding-right: 0.7rem;
}

.fp-entity-options {
    position: absolute;
    top: calc(100% + 0.35rem);
    right: 0;
    width: min(320px, calc(100vw - 3rem));
    z-index: 20;
    border: 1px solid #d7e3f0;
    border-radius: 12px;
    background: #fff;
    box-shadow: 0 14px 26px rgba(15, 23, 42, 0.16);
    max-height: 240px;
    overflow: auto;
    overflow-x: hidden;
    padding: 0.35rem;
    display: grid;
    gap: 0.25rem;
}

.fp-entity-option {
    border: 1px solid transparent;
    background: #fff;
    border-radius: 8px;
    padding: 0.35rem 0.55rem;
    text-align: left;
    cursor: pointer;
    font: inherit;
    color: #1e293b;
    white-space: normal;
    line-height: 1.15;
    font-size: 0.85rem;
}

.fp-entity-option:hover {
    background: #f1f5f9;
}

.fp-entity-option.is-active {
    background: #EDF3FA;
    border-color: #9ab9d8;
    color: #1F4E79;
    font-weight: 600;
}

.fp-entity-empty {
    margin: 0.2rem 0.3rem 0.1rem;
    color: #64748b;
    font-size: 0.82rem;
}

/* Date range */
.fp-date-row {
    display: flex;
    align-items: center;
    gap: 0.4rem;
}

.fp-date-field {
    display: flex;
    flex-direction: column;
    gap: 0.18rem;
    flex: 1;
}

.fp-date-lbl {
    font-size: 0.73rem;
    font-weight: 700;
    color: #6b8aaa;
    text-transform: uppercase;
    letter-spacing: 0.05em;
}

.fp-date-input {
    border: 1px solid #cfdbe9;
    border-radius: 9px;
    padding: 0.38rem 0.5rem;
    font: inherit;
    font-size: 0.83rem;
    color: #0f172a;
    background: #fafcff;
    width: 100%;
}

.fp-date-input:focus {
    outline: none;
    border-color: #7ab0d8;
    box-shadow: 0 0 0 3px rgba(59, 130, 246, 0.12);
}

.fp-date-sep {
    width: 18px;
    height: 10px;
    color: #94a3b8;
    flex-shrink: 0;
    margin-top: 1.1rem;
}

.fp-date-clear {
    align-self: flex-start;
    margin-top: 0.3rem;
    font: inherit;
    font-size: 0.78rem;
    font-weight: 600;
    color: #4a6a8a;
    background: none;
    border: 1px solid #c5d7ea;
    border-radius: 999px;
    padding: 0.18rem 0.65rem;
    cursor: pointer;
}

.fp-date-clear:hover {
    background: #eef4fb;
    border-color: #94b8d4;
}

/* Sort direction */
.fp-sort-dir {
    display: flex;
    gap: 0.35rem;
    margin-top: 0.1rem;
}

.fp-dir-btn {
    display: inline-flex;
    align-items: center;
    gap: 0.3rem;
    flex: 1;
    justify-content: center;
    border: 1.5px solid #c8d8ea;
    border-radius: 8px;
    background: #fff;
    color: #475569;
    font: inherit;
    font-size: 0.8rem;
    font-weight: 600;
    padding: 0.32rem 0.6rem;
    cursor: pointer;
    transition: background 100ms, border-color 100ms, color 100ms;
}

.fp-dir-btn svg {
    width: 13px;
    height: 13px;
    flex-shrink: 0;
}

.fp-dir-btn:hover {
    border-color: #94b8d4;
    background: #f0f6fb;
}

.fp-dir-btn.is-active {
    background: #1F4E79;
    border-color: #1F4E79;
    color: #fff;
}

/* Divider */
.fp-divider {
    height: 1px;
    background: #e4edf6;
    margin: 0.1rem 0;
}

/* Footer */
.fp-footer {
    margin-top: auto;
    padding-top: 0.6rem;
    border-top: 1px solid #e4edf6;
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 0.5rem;
}

.fp-footer-count {
    font-size: 0.8rem;
    font-weight: 600;
    color: #5b7a9a;
}

.fp-clear-btn {
    font: inherit;
    font-size: 0.8rem;
    font-weight: 600;
    color: #dc2626;
    background: none;
    border: 1px solid #fca5a5;
    border-radius: 999px;
    padding: 0.22rem 0.7rem;
    cursor: pointer;
}

.fp-clear-btn:hover {
    background: #fef2f2;
    border-color: #f87171;
}

.table-wrap {
    overflow: auto;
    position: relative;
}

.table-wrap.is-loading {
    min-height: 160px;
}

.tickets-loading-overlay {
    position: absolute;
    inset: 0;
    z-index: 10;
    background: rgba(255, 255, 255, 0.75);
    display: flex;
    align-items: center;
    justify-content: center;
}

.tickets-loading-spinner {
    width: 28px;
    height: 28px;
    border: 3px solid #d8e9f5;
    border-top-color: #1F4E79;
    border-radius: 50%;
    animation: ticketSpinnerRotate 0.7s linear infinite;
}

@keyframes ticketSpinnerRotate {
    to { transform: rotate(360deg); }
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
    font-size: 0.78rem;
    color: #475569;
    font-weight: 600;
    background: #f8fafd;
    position: sticky;
    top: 0;
    z-index: 2;
    text-transform: uppercase;
    letter-spacing: 0.05em;
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

.ticket-id-cell {
    white-space: nowrap;
}

.ticket-id-badge {
    display: inline-flex;
    align-items: center;
    border-radius: 7px;
    background: #edf4fb;
    border: 1px solid #b9cde4;
    color: #1F4E79;
    font-weight: 700;
    font-size: 0.8rem;
    padding: 0.14rem 0.48rem;
    text-decoration: none;
    letter-spacing: 0.01em;
    transition: background 120ms, border-color 120ms;
}

.ticket-id-badge:hover {
    background: #d8eaf6;
    border-color: #8fb3d1;
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
    transition: color 120ms, background 120ms;
}

.row-action:hover:not(:disabled) {
    color: #1F4E79;
    background: #edf4fb;
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
    display: flex;
    align-items: center;
    gap: 0.52rem;
    font: inherit;
    font-size: 0.84rem;
}

.menu-item:hover {
    background: #f1f5f9;
}

.menu-item.is-unpin {
    color: #7c3aed;
}

.menu-item.is-unpin:hover {
    background: #f5f0ff;
    border-color: #c4b5fd;
}

.menu-item-icon {
    width: 15px;
    height: 15px;
    flex-shrink: 0;
    opacity: 0.7;
}

.tickets-empty {
    text-align: center;
    padding: 2.8rem 1rem;
}

.tickets-empty-icon {
    width: 52px;
    height: 52px;
    margin: 0 auto 0.75rem;
    display: block;
}

.tickets-empty-text {
    margin: 0;
    color: #64748b;
    font-size: 0.9rem;
}

.tickets-table tbody tr.is-selected {
    background: #f0fdf4;
}

.pager {
    padding: 0.65rem 1rem;
    display: flex;
    justify-content: flex-end;
    align-items: center;
    gap: 0.4rem;
    flex-wrap: wrap;
}

.page-btn {
    border: 1px solid #d4dde8;
    background: #fff;
    color: #334155;
    border-radius: 8px;
    font: inherit;
    font-size: 0.84rem;
    font-weight: 500;
    padding: 0.3rem 0.6rem;
    min-width: 32px;
    cursor: pointer;
    transition: background 120ms, border-color 120ms;
}

.page-btn:hover:not(:disabled) {
    background: #f1f5f9;
    border-color: #b8ccd8;
}

.page-btn:disabled {
    opacity: 0.4;
    cursor: default;
}

.page-btn.is-active {
    background: #1F4E79;
    border-color: #1F4E79;
    color: #fff;
    font-weight: 600;
}

.page-info {
    font-size: 0.8rem;
    color: #64748b;
    margin-left: 0.35rem;
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

.bulk-operator-picker {
    position: relative;
    border: 1px solid #cfdbe9;
    border-radius: 10px;
    background: #fff;
    height: 40px;
    padding: 0 0.48rem;
    display: flex;
    align-items: center;
    gap: 0.34rem;
    overflow: visible;
}

.bulk-operator-picker:focus-within {
    border-color: #9dc0e4;
    box-shadow: 0 0 0 3px rgba(31, 78, 121, 0.12);
}

.bulk-operator-picker input {
    border: 0;
    border-radius: 0;
    background: transparent;
    min-width: 0;
    flex: 1 1 auto;
    font-size: 0.84rem;
    padding: 0;
    line-height: 1.2;
}

.bulk-selected-chip {
    display: inline-flex;
    align-items: center;
    gap: 0.34rem;
    border: 1px solid #c7d7e8;
    border-radius: 999px;
    background: #eff6ff;
    color: #0f172a;
    max-width: 100%;
    min-width: 0;
    padding: 0.12rem 0.44rem 0.12rem 0.2rem;
    flex: 1 1 auto;
    width: 100%;
}

.bulk-selected-chip.is-clear {
    background: #f8fafc;
}

.bulk-selected-avatar {
    flex: 0 0 auto;
}

.bulk-selected-name {
    font-size: 0.8rem;
    flex: 1 1 auto;
    min-width: 0;
    overflow: hidden;
    text-overflow: clip;
    white-space: nowrap;
}

.bulk-selected-chip button {
    border: 0;
    background: transparent;
    color: #5b6c80;
    padding: 0;
    line-height: 1;
    cursor: pointer;
}

.bulk-selected-chip button:disabled {
    cursor: default;
    opacity: 0.6;
}

.bulk-selected-clear {
    width: 18px;
    height: 18px;
    border-radius: 999px;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    background: #64748b;
    color: #fff;
    font-size: 0.72rem;
    font-weight: 700;
}

.bulk-operator-suggestions {
    position: absolute;
    left: 0;
    right: 0;
    top: calc(100% + 0.28rem);
    z-index: 70;
    border: 1px solid #cfdbe9;
    border-radius: 10px;
    background: #fff;
    box-shadow: 0 14px 30px rgba(15, 23, 42, 0.2);
    max-height: 240px;
    overflow: auto;
}

.bulk-operator-option {
    width: 100%;
    border: 0;
    background: transparent;
    display: flex;
    align-items: center;
    gap: 0.5rem;
    text-align: left;
    padding: 0.42rem 0.52rem;
    cursor: pointer;
    border-bottom: 1px solid #edf2f8;
}

.bulk-operator-option:last-of-type {
    border-bottom: 0;
}

.bulk-operator-option:hover {
    background: #f0fdf4;
}

.bulk-operator-option:disabled {
    cursor: default;
    opacity: 0.7;
}

.bulk-operator-option.is-clear-option {
    background: #f8fafc;
}

.bulk-option-meta {
    display: grid;
    gap: 0.08rem;
}

.bulk-option-meta strong {
    font-size: 0.86rem;
}

.bulk-option-meta small {
    color: #64748b;
    font-size: 0.76rem;
}

.bulk-operator-empty {
    margin: 0;
    padding: 0.62rem;
    color: #64748b;
    font-size: 0.82rem;
}

.bulk-btn {
    padding: 0.45rem 0.8rem;
    min-height: 36px;
}

.bulk-panel-body {
    padding: 0.85rem;
    display: grid;
    align-content: start;
    gap: 0.65rem;
    overflow: auto;
}

.bulk-panel-note {
    margin: 0;
    color: #475569;
    font-size: 0.84rem;
    font-weight: 600;
}

.bulk-panel-clear {
    justify-self: end;
    padding: 0.34rem 0.64rem;
    font-size: 0.86rem;
}

.bulk-panel-field {
    min-width: 0;
}

.bulk-panel-btn {
    justify-self: end;
    width: auto;
    min-height: 34px;
    padding: 0.34rem 0.72rem;
    font-size: 0.88rem;
}

@media (max-width: 1280px) {
    .filter-bar {
        grid-template-columns: 1fr;
    }

    .bulk-toolbar {
        flex-direction: column;
        align-items: stretch;
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
    }

    .fp-row-2 {
        grid-template-columns: 1fr;
    }

    .bulk-toolbar-left {
        justify-content: space-between;
    }

    .bulk-field {
        min-width: 100%;
    }

}
</style>
