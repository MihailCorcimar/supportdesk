<script setup>
import { computed, onBeforeUnmount, onMounted, reactive, ref } from 'vue';
import { RouterLink, useRoute, useRouter } from 'vue-router';
import api from '../api/client';

const route = useRoute();
const router = useRouter();
const loading = ref(false);
const error = ref('');
const quickActionMessage = ref('');
const searchInputRef = ref(null);
const tickets = ref([]);
const meta = ref({ current_page: 1, last_page: 1, total: 0 });
const openActionsMenuTicketId = ref(null);
const showFiltersMenu = ref(false);
const options = ref({
    inboxes: [],
    entities: [],
    operators: [],
    statuses: [],
    priorities: [],
    types: [],
});

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
const activeFilterCount = computed(() => {
    const keys = ['type', 'inbox_id', 'created_by_user_id', 'status', 'priority', 'entity_id', 'assigned_operator_id'];
    return keys.reduce((count, key) => (filters[key] ? count + 1 : count), 0);
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
    const inboxId = typeof route.query.inbox_id === 'string' ? route.query.inbox_id.trim() : '';
    const createdByUserId = typeof route.query.created_by_user_id === 'string' ? route.query.created_by_user_id.trim() : '';
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
    } catch (exception) {
        error.value = 'Não foi possível carregar tickets.';
    } finally {
        loading.value = false;
    }
};

let searchDebounceTimer = null;

const applyFilters = () => {
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
    loadTickets(1);
};

const toggleFiltersMenu = () => {
    showFiltersMenu.value = !showFiltersMenu.value;
};

const closeFiltersMenu = () => {
    showFiltersMenu.value = false;
};

const toggleSort = (field) => {
    if (filters.sort_by === field) {
        filters.sort_dir = filters.sort_dir === 'asc' ? 'desc' : 'asc';
    } else {
        filters.sort_by = field;
        filters.sort_dir = field === 'ticket_number' ? 'asc' : 'desc';
    }

    loadTickets(1);
};

const sortState = (field) => {
    if (filters.sort_by !== field) return 'none';
    return filters.sort_dir === 'asc' ? 'asc' : 'desc';
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

const refreshCurrentPage = () => {
    openActionsMenuTicketId.value = null;
    loadTickets(meta.value.current_page || 1);
};

const toggleActionsMenu = (ticketId) => {
    openActionsMenuTicketId.value = openActionsMenuTicketId.value === ticketId ? null : ticketId;
};

const closeActionsMenu = () => {
    openActionsMenuTicketId.value = null;
};

const openTicketView = async (ticketId, tab = 'conversation') => {
    closeActionsMenu();
    await router.push({
        name: 'tickets.show',
        params: { id: ticketId },
        query: { tab },
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

const goToFirstTicket = async () => {
    if (!tickets.value.length) {
        quickActionMessage.value = 'Sem tickets para abrir';
        setTimeout(() => {
            quickActionMessage.value = '';
        }, 1400);
        return;
    }

    await router.push({ name: 'tickets.show', params: { id: tickets.value[0].id } });
};

const openNewTicketModal = async () => {
    await router.push({ name: 'tickets.create' });
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

const clientLabel = (ticket) => ticket.contact?.name ?? ticket.entity?.name ?? '-';

onMounted(async () => {
    document.addEventListener('click', closeActionsMenuOnOutsideClick);
    document.addEventListener('click', closeFiltersMenuOnOutsideClick);
    document.addEventListener('keydown', handleGlobalKeydown);
    hydrateFiltersFromQuery();
    await loadMeta();
    await loadTickets(1);
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
                                {{ typeLabels[type] ?? type }}
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
                                {{ statusLabels[status] ?? status }}
                            </option>
                        </select>
                    </label>

                    <label class="inline-field">
                        <span class="field-icon">⚑</span>
                        <span class="ddl-name">Prioridade</span>
                        <select v-model="filters.priority" @change="applyFilters">
                            <option value="">Todas</option>
                            <option v-for="priority in options.priorities" :key="priority" :value="priority">
                                {{ priorityLabels[priority] ?? priority }}
                            </option>
                        </select>
                    </label>

                    <label class="inline-field">
                        <span class="field-icon">◷</span>
                        <button type="button" class="link-btn" @click="toggleSort('request_date')">Data pedido</button>
                    </label>
                </div>

                <div class="filter-actions">
                    <div class="filters-menu">
                        <button type="button" class="inline-filter-toggle" @click.stop="toggleFiltersMenu">
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
                                            {{ typeLabels[type] ?? type }}
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
                                            {{ statusLabels[status] ?? status }}
                                        </option>
                                    </select>
                                </label>

                                <label>
                                    Prioridade
                                    <select v-model="filters.priority" @change="applyFilters">
                                        <option value="">Todas</option>
                                        <option v-for="priority in options.priorities" :key="`menu-priority-${priority}`" :value="priority">
                                            {{ priorityLabels[priority] ?? priority }}
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
            <p v-if="loading" class="muted">A carregar...</p>

            <div class="table-wrap" v-if="!loading">
                <table class="tickets-table">
                    <thead>
                        <tr>
                            <th class="check-col"><input type="checkbox" disabled /></th>
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
                            <th>
                                <button type="button" class="sort-btn" @click="toggleSort('request_date')">
                                    Data pedido <span class="sort-indicator" :class="`is-${sortState('request_date')}`"></span>
                                </button>
                            </th>
                            <th class="actions-col">Ações</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="ticket in tickets" :key="ticket.id">
                            <td class="check-col"><input type="checkbox" disabled /></td>
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
                                    {{ priorityLabels[ticket.priority] ?? ticket.priority }}
                                </span>
                            </td>
                            <td>
                                <span class="status-pill" :class="`status-${ticket.status}`">
                                    {{ statusLabels[ticket.status] ?? ticket.status }}
                                </span>
                            </td>
                            <td>
                                <span class="type-pill">{{ typeLabels[ticket.type] ?? ticket.type }}</span>
                            </td>
                            <td>{{ clientLabel(ticket) }}</td>
                            <td>{{ formatDate(ticket.created_at ?? ticket.last_activity_at) }}</td>
                            <td class="actions-col">
                                <div class="actions-menu">
                                    <button
                                        type="button"
                                        class="btn-clean row-action menu-trigger"
                                        aria-label="Abrir ações"
                                        @click.stop="toggleActionsMenu(ticket.id)"
                                    >
                                        ⋯
                                    </button>
                                    <div v-if="openActionsMenuTicketId === ticket.id" class="actions-dropdown" @click.stop>
                                        <button type="button" class="menu-item" @click="openTicketView(ticket.id, 'conversation')">
                                            Abrir ticket
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
                                    </div>
                                </div>
                            </td>
                        </tr>
                        <tr v-if="!tickets.length">
                            <td colspan="9" class="empty-row">Sem tickets para os filtros escolhidos.</td>
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

        <nav class="quick-actions" aria-label="Ações rápidas">
            <button type="button" title="Focar pesquisa" @click="focusSearch">
                <svg viewBox="0 0 24 24" fill="none" aria-hidden="true">
                    <circle cx="11" cy="11" r="6.2" stroke="currentColor" stroke-width="1.8" />
                    <path d="M16 16l4 4" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" />
                </svg>
            </button>
            <button type="button" title="Limpar filtros" @click="clearFilters">
                <svg viewBox="0 0 24 24" fill="none" aria-hidden="true">
                    <path d="M6 6l12 12M18 6 6 18" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" />
                </svg>
            </button>
            <button type="button" title="Atualizar lista" @click="refreshCurrentPage">
                <svg viewBox="0 0 24 24" fill="none" aria-hidden="true">
                    <path d="M20 11a8 8 0 1 0-2.3 5.6M20 6.5v4h-4" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" />
                </svg>
            </button>
            <button type="button" title="Abrir primeiro ticket" @click="goToFirstTicket">
                <svg viewBox="0 0 24 24" fill="none" aria-hidden="true">
                    <rect x="4" y="4" width="16" height="16" rx="3" stroke="currentColor" stroke-width="1.8" />
                    <path d="M8 8h8M8 12h8M8 16h5" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" />
                </svg>
            </button>
            <button type="button" title="Novo ticket" @click="openNewTicketModal">
                <svg viewBox="0 0 24 24" fill="none" aria-hidden="true">
                    <path d="M12 5v14M5 12h14" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" />
                </svg>
            </button>
        </nav>

        <p v-if="quickActionMessage" class="quick-message">{{ quickActionMessage }}</p>
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
    gap: 0.55rem;
    align-items: center;
    padding: 0.75rem 1rem;
    border-bottom: 1px solid #e3ebf5;
    background: #fbfdff;
}

.search-field {
    border: 1px solid #dbe4ee;
    border-radius: 9px;
    background: #fff;
    min-height: 38px;
    display: flex;
    align-items: center;
    gap: 0.5rem;
    padding: 0 0.5rem;
}

.search-kbd {
    border: 1px solid #dbe4ee;
    border-bottom-width: 2px;
    border-radius: 6px;
    background: #f8fafc;
    color: #64748b;
    font-size: 0.72rem;
    line-height: 1;
    padding: 0.12rem 0.28rem;
}

.inline-filters {
    display: flex;
    align-items: center;
    gap: 0.88rem;
    min-width: 0;
    overflow-x: auto;
    padding-bottom: 2px;
}

.inline-field {
    position: relative;
    min-height: 34px;
    display: inline-flex;
    align-items: center;
    gap: 0.32rem;
    color: #475569;
    white-space: nowrap;
    flex: 0 0 auto;
}

.field-icon {
    color: #64748b;
    font-size: 1rem;
    width: 16px;
    display: inline-flex;
    justify-content: center;
    align-items: center;
    font-weight: 500;
}

.ddl-name {
    color: #475569;
    font-size: 0.86rem;
}

.link-btn {
    border: none;
    background: transparent;
    color: #475569;
    font: inherit;
    padding: 0;
    cursor: pointer;
}

.inline-field select {
    position: absolute;
    inset: 0;
    opacity: 0;
    cursor: pointer;
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
    gap: 0.4rem;
    justify-content: flex-start;
    flex: 0 0 auto;
}

.filters-menu {
    position: relative;
}

.inline-filter-toggle {
    display: inline-flex;
    align-items: center;
    gap: 0.4rem;
    border: none;
    background: transparent;
    color: #475569;
    padding: 0.22rem 0.1rem;
    cursor: pointer;
}

.filters-toggle-icon {
    width: 16px;
    height: 16px;
}

.filters-count {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    min-width: 1.25rem;
    height: 1.25rem;
    border-radius: 999px;
    background: #e8fbf2;
    border: 1px solid #9fd9c2;
    color: #0f766e;
    font-size: 0.78rem;
    line-height: 1;
}

.filters-dropdown {
    position: absolute;
    top: calc(100% + 0.38rem);
    right: -0.4rem;
    z-index: 40;
    width: min(620px, calc(100vw - 3rem));
    border: 1px solid #dbe4ee;
    border-radius: 14px;
    background: #fff;
    box-shadow: 0 14px 34px rgba(15, 23, 42, 0.18);
    padding: 0.85rem;
}

.filters-dropdown-header {
    display: grid;
    gap: 0.12rem;
    margin-bottom: 0.6rem;
}

.filters-dropdown-header h3 {
    margin: 0;
    font-size: 1rem;
    color: #0f172a;
}

.filters-dropdown-header p {
    margin: 0;
    color: #64748b;
    font-size: 0.83rem;
}

.filters-grid {
    display: grid;
    grid-template-columns: repeat(2, minmax(0, 1fr));
    gap: 0.7rem;
    padding-bottom: 0.5rem;
}

.filters-grid label {
    display: grid;
    gap: 0.24rem;
    color: #334155;
    font-size: 0.88rem;
}

.filters-grid select {
    border: 1px solid #dbe4ee;
    border-radius: 8px;
    padding: 0.42rem 0.55rem;
    font: inherit;
    color: #0f172a;
    background: #fff;
    width: 100%;
    appearance: auto;
    -webkit-appearance: auto;
    -moz-appearance: auto;
}

.filters-dropdown-footer {
    border-top: 1px solid #e7edf6;
    padding-top: 0.6rem;
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 0.5rem;
}

.filters-helper {
    color: #64748b;
    font-size: 0.82rem;
}

.filters-clear-btn {
    border-color: #cdd8e6;
    color: #334155;
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

.actions-col {
    width: 82px;
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

.priority-low { color: #166534; background: #dcfce7; border-color: #bbf7d0; }
.priority-medium { color: #854d0e; background: #fef3c7; border-color: #fde68a; }
.priority-high { color: #991b1b; background: #fee2e2; border-color: #fecaca; }
.priority-urgent { color: #7f1d1d; background: #fee2e2; border-color: #fca5a5; }

.status-open { color: #166534; background: #dcfce7; border-color: #86efac; }
.status-in_progress { color: #1d4ed8; background: #dbeafe; border-color: #93c5fd; }
.status-pending { color: #854d0e; background: #fef3c7; border-color: #fcd34d; }
.status-closed { color: #065f46; background: #d1fae5; border-color: #6ee7b7; }
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

.quick-actions {
    position: fixed;
    right: 1.7rem;
    top: 50%;
    transform: translateY(-50%);
    display: grid;
    gap: 0.45rem;
    z-index: 65;
}

.quick-actions button {
    width: 46px;
    height: 46px;
    border-radius: 999px;
    border: 1px solid #d9e2ee;
    background: #f8fafc;
    color: #64748b;
    display: grid;
    place-items: center;
    cursor: pointer;
    transition: background-color 120ms ease, color 120ms ease, border-color 120ms ease;
}

.quick-actions button:hover {
    background: #e8fbf2;
    color: #0f766e;
    border-color: #9fd9c2;
}

.quick-actions svg {
    width: 22px;
    height: 22px;
}

.quick-message {
    position: fixed;
    right: 1.6rem;
    top: calc(50% + 190px);
    margin: 0;
    z-index: 66;
    border: 1px solid #9fd9c2;
    background: #ecfdf5;
    color: #0d704e;
    border-radius: 8px;
    padding: 0.34rem 0.5rem;
    font-size: 0.82rem;
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

    .quick-actions {
        right: 1.05rem;
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

    .quick-actions {
        position: sticky;
        top: auto;
        right: auto;
        transform: none;
        margin-top: 0.55rem;
        grid-template-columns: repeat(3, minmax(0, 1fr));
        background: #fff;
        border: 1px solid #d9e2ee;
        border-radius: 12px;
        padding: 0.4rem;
    }

    .quick-actions button {
        width: 100%;
        border-radius: 10px;
    }

    .quick-message {
        position: static;
        width: fit-content;
    }
}
</style>
