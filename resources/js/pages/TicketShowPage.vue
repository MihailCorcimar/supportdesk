<script setup>
import { computed, nextTick, onBeforeUnmount, onMounted, reactive, ref, watch } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import api from '../api/client';
import { useAuthStore } from '../stores/auth';
import UserAvatar from '../components/UserAvatar.vue';
import QuickActionsRail from '../components/QuickActionsRail.vue';
import QuickMenuPanel from '../components/QuickMenuPanel.vue';

const route = useRoute();
const router = useRouter();
const auth = useAuthStore();
const isOperator = computed(() => auth.state.user?.role === 'operator');
const availableTopTabs = computed(() => (
    isOperator.value ? ['conversation', 'activity_logs', 'notes'] : ['conversation', 'activity_logs']
));
const normalizeTopTab = (tab) => (availableTopTabs.value.includes(tab) ?tab : 'conversation');

const loading = ref(false);
const error = ref('');
const quickActionMessage = ref('');
const activeTopTab = ref(normalizeTopTab(typeof route.query.tab === 'string' ?route.query.tab : 'conversation'));
const ticket = ref(null);
const messageBody = ref('');
const composerRef = ref(null);
const messageFiles = ref([]);
const noteBody = ref('');
const savingStatus = ref(false);
const savingAssignment = ref(false);
const savingMetadata = ref(false);
const sendingMessage = ref(false);
const sendingNote = ref(false);
const statusMenuOpen = ref(false);
const pinPending = ref(false);
const detailsPanelOpen = ref(false);
const transferOperatorOpen = ref(false);
const detailsFromQuery = (value) => ['1', 'true', 'yes', 'on'].includes(String(value || '').toLowerCase());

const statusForm = reactive({ status: '' });
const assignmentForm = reactive({ assigned_operator_id: '' });
const metadataForm = reactive({
    subject: '',
    priority: '',
    type: '',
    inbox_id: '',
    cc_emails: '',
    follower_user_ids: [],
});
const followerSearch = ref('');
const panelOperatorSearch = ref('');
const panelOperatorPickerOpen = ref(false);
const panelFollowerPickerOpen = ref(false);

const statusLabels = {
    open: 'Aberto',
    in_progress: 'Em tratamento',
    pending: 'Aguardando cliente',
    closed: 'Fechado',
    cancelled: 'Cancelado',
};
const formatStatusOption = (status) => statusLabels[status] || status;
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

let metadataAutoSaveTimer = null;
let metadataAutoSavePending = false;
let ticketChannel = null;
let subscribedTicketId = null;

const typeLabels = {
    question: 'QuestÃ£o',
    incident: 'Incidente',
    request: 'Pedido',
    task: 'Tarefa',
    other: 'Outro',
};

const actionLabels = {
    ticket_created: 'Ticket criado',
    message_added: 'Mensagem adicionada',
    status_updated: 'Estado atualizado',
    assignment_updated: 'Atribuicao alterada',
    field_updated: 'Campo alterado',
    attachments_added: 'Anexos adicionados',
};

const canUpdateStatus = computed(() => ticket.value?.permissions?.can_update_status);
const canAssign = computed(() => ticket.value?.permissions?.can_assign);
const canUpdateMetadata = computed(() => ticket.value?.permissions?.can_update_metadata);
const canSetTerminalStatus = computed(() => {
    if (!canUpdateStatus.value) return false;

    const permissionFlag = ticket.value?.permissions?.can_update_terminal_status;
    if (typeof permissionFlag === 'boolean') {
        return permissionFlag;
    }

    const assignedOperatorId = Number(ticket.value?.assigned_operator?.id || 0);
    if (!assignedOperatorId) return true;

    return assignedOperatorId === Number(auth.state.user?.id || 0);
});
const visibleStatusOptions = computed(() => {
    const currentStatus = String(ticket.value?.status || '');

    return statusOrder.filter((status) => {
        if (!['closed', 'cancelled'].includes(status)) return true;
        if (canSetTerminalStatus.value) return true;
        return status === currentStatus;
    });
});
const canQuickClose = computed(() => canSetTerminalStatus.value && !['closed', 'cancelled'].includes(ticket.value?.status));
const canChat = computed(() => Boolean(ticket.value?.permissions?.can_reply));
const chatMessages = computed(() => (ticket.value?.messages || []).filter((message) => !message.is_internal));
const internalNotes = computed(() => (ticket.value?.messages || []).filter((message) => message.is_internal));
const canAddNotes = computed(() => isOperator.value && ticket.value?.permissions?.can_add_internal_note);
const statusOrder = ['open', 'in_progress', 'pending', 'closed', 'cancelled'];
const headerStatusOptions = computed(() => visibleStatusOptions.value.filter((status) => status !== ticket.value?.status));
const isAlreadyClosed = computed(() => ticket.value?.status === 'closed');
const previousTicket = computed(() => ticket.value?.navigation?.previous || null);
const nextTicket = computed(() => ticket.value?.navigation?.next || null);
const filteredFollowers = computed(() => {
    const term = followerSearch.value.trim().toLowerCase();
    const followers = ticket.value?.available_followers || [];

    if (!term) return followers;

    return followers.filter((follower) => {
        const name = (follower.name || '').toLowerCase();
        const email = (follower.email || '').toLowerCase();
        return name.includes(term) || email.includes(term);
    });
});
const selectedFollowers = computed(() => {
    const selected = new Set((metadataForm.follower_user_ids || []).map((id) => Number(id)));
    return (ticket.value?.available_followers || []).filter((follower) => selected.has(Number(follower.id)));
});
const panelFollowerSuggestions = computed(() => {
    const term = followerSearch.value.trim();
    if (!term) return [];

    const selectedIds = new Set((metadataForm.follower_user_ids || []).map((id) => Number(id)));
    return filteredFollowers.value
        .filter((follower) => !selectedIds.has(Number(follower.id)))
        .slice(0, 8);
});
const panelOperatorCandidates = computed(() => (ticket.value?.operators || []));
const filteredPanelOperators = computed(() => {
    const term = panelOperatorSearch.value.trim().toLowerCase();
    if (!term) return panelOperatorCandidates.value;

    return panelOperatorCandidates.value.filter((operator) => {
        const name = (operator.name || '').toLowerCase();
        const email = (operator.email || '').toLowerCase();
        return name.includes(term) || email.includes(term);
    });
});
const panelOperatorSuggestions = computed(() => {
    const selectedId = Number(assignmentForm.assigned_operator_id || 0);
    return filteredPanelOperators.value
        .filter((operator) => Number(operator.id) !== selectedId)
        .slice(0, 8);
});
const selectedPanelOperator = computed(() => {
    const selectedId = Number(assignmentForm.assigned_operator_id || 0);
    if (!selectedId) return null;
    return (ticket.value?.operators || []).find((operator) => Number(operator.id) === selectedId) || null;
});
const assignedOperatorWithEmail = computed(() => {
    if (!ticket.value) return null;
    const selectedId = Number(assignmentForm.assigned_operator_id || 0);
    if (!selectedId) return null;

    const fromOperators = (ticket.value.operators || []).find((operator) => Number(operator.id) === selectedId);
    if (fromOperators) return fromOperators;

    if (ticket.value.assigned_operator && Number(ticket.value.assigned_operator.id) === selectedId) {
        return ticket.value.assigned_operator;
    }

    return null;
});

const loadTicket = async () => {
    loading.value = true;
    error.value = '';

    try {
        const response = await api.get(`/tickets/${route.params.id}`);
        ticket.value = response.data.data;

        statusForm.status = ticket.value.status;
        assignmentForm.assigned_operator_id = ticket.value.assigned_operator?.id
            ?String(ticket.value.assigned_operator.id)
            : '';
        panelOperatorSearch.value = '';
        panelOperatorPickerOpen.value = false;
        transferOperatorOpen.value = false;
        panelFollowerPickerOpen.value = false;
        followerSearch.value = '';

        metadataForm.subject = ticket.value.subject || '';
        metadataForm.priority = ticket.value.priority || 'medium';
        metadataForm.type = ticket.value.type || 'request';
        metadataForm.inbox_id = ticket.value.inbox?.id ?String(ticket.value.inbox.id) : '';
        metadataForm.cc_emails = (ticket.value.cc_emails || []).join(', ');
        metadataForm.follower_user_ids = (ticket.value.followers || []).map((follower) => Number(follower.id));
    } catch (exception) {
        error.value = 'Nao foi possivel carregar o ticket.';
    } finally {
        loading.value = false;
    }

    subscribeToTicketChannel(ticket.value?.id || null);
    await markTicketNotificationsRead();
};

const markTicketNotificationsRead = async () => {
    if (!ticket.value?.id) return;

    try {
        await api.patch('/notifications/read-ticket', {
            ticket_id: Number(ticket.value.id),
        });
        window.dispatchEvent(new Event('supportdesk:notifications-updated'));
    } catch {
        // ignore
    }
};

const handleTicketMessageCreated = (payload) => {
    if (!ticket.value || !payload) return;
    if (Number(payload.ticket_id || 0) !== Number(ticket.value.id || 0)) return;

    const message = payload.message;
    if (!message || message.is_internal) return;

    const existingMessages = ticket.value.messages || [];
    const alreadyExists = existingMessages.some((item) => Number(item.id) === Number(message.id));
    if (!alreadyExists) {
        ticket.value.messages = [message, ...existingMessages];
    }

    if (Array.isArray(payload.logs) && ticket.value.logs) {
        const existingLogIds = new Set(ticket.value.logs.map((log) => Number(log.id)));
        const newLogs = payload.logs.filter((log) => log && !existingLogIds.has(Number(log.id)));
        if (newLogs.length) {
            ticket.value.logs = [...newLogs, ...ticket.value.logs];
        }
    }

    touchTicketLastActivity();
    markTicketNotificationsRead();
};

const subscribeToTicketChannel = (ticketId) => {
    if (!ticketId || typeof window === 'undefined' || !window.Echo) return;
    if (subscribedTicketId === Number(ticketId)) return;

    if (subscribedTicketId) {
        window.Echo.leave(`tickets.${subscribedTicketId}`);
    }

    subscribedTicketId = Number(ticketId);
    ticketChannel = window.Echo.private(`tickets.${subscribedTicketId}`);
    ticketChannel.listen('.ticket.message.created', handleTicketMessageCreated);
};

const leaveTicketChannel = () => {
    if (typeof window === 'undefined' || !window.Echo) return;
    if (subscribedTicketId) {
        window.Echo.leave(`tickets.${subscribedTicketId}`);
    }
    subscribedTicketId = null;
    ticketChannel = null;
};

const touchTicketLastActivity = () => {
    if (!ticket.value) return;
    ticket.value.last_activity_at = new Date().toISOString();
};

const updateStatus = async () => {
    savingStatus.value = true;
    error.value = '';

    try {
        await api.patch(`/tickets/${route.params.id}/status`, { status: statusForm.status });
        if (ticket.value) {
            ticket.value.status = statusForm.status;
            touchTicketLastActivity();
        }
    } catch (exception) {
        error.value = exception?.response?.data?.message || 'Falha ao atualizar estado.';
    } finally {
        savingStatus.value = false;
    }
};

const closeTicketQuick = async () => {
    statusForm.status = 'closed';
    await updateStatus();
};

const updateStatusTo = async (newStatus) => {
    statusMenuOpen.value = false;
    statusForm.status = newStatus;
    await updateStatus();
};

const toggleStatusMenu = () => {
    statusMenuOpen.value = !statusMenuOpen.value;
};

const closeStatusMenuOnOutsideClick = (event) => {
    if (!statusMenuOpen.value) return;

    const target = event.target;
    if (!(target instanceof HTMLElement)) return;

    if (!target.closest('.status-split')) {
        statusMenuOpen.value = false;
    }
};

const scrollToSection = (sectionId) => {
    const element = document.getElementById(sectionId);

    if (element) {
        element.scrollIntoView({ behavior: 'smooth', block: 'start' });
    }
};

const focusComposer = async () => {
    activeTopTab.value = 'conversation';
    await nextTick();
    scrollToSection('composer-section');

    const editor = document.getElementById('ticket-composer-input');
    if (editor) {
        editor.focus();
    }
};

const toggleDetailsPanel = () => {
    detailsPanelOpen.value = !detailsPanelOpen.value;
};

const quickRailActions = computed(() => ([
    { id: 'details', title: 'Detalhes do ticket', active: detailsPanelOpen.value },
    { id: 'reply', title: 'Responder', active: false },
    { id: 'activity_logs', title: 'Activity logs', active: false },
]));

const handleQuickRailAction = (actionId) => {
    if (actionId === 'details') {
        toggleDetailsPanel();
        return;
    }

    if (actionId === 'reply') {
        focusComposer();
        return;
    }

    if (actionId === 'activity_logs') {
        activeTopTab.value = 'activity_logs';
        return;
    }
};

const toggleTicketPin = async () => {
    if (!ticket.value?.id || pinPending.value) {
        return;
    }

    pinPending.value = true;

    try {
        if (ticket.value.is_pinned) {
            await api.delete(`/conversations/${ticket.value.id}/pin`);
        } else {
            await api.post(`/conversations/${ticket.value.id}/pin`);
        }

        ticket.value.is_pinned = !ticket.value.is_pinned;
        quickActionMessage.value = ticket.value.is_pinned ?'Ticket fixado' : 'Ticket desafixado';
        window.dispatchEvent(new CustomEvent('supportdesk:conversations-updated'));
    } catch {
        quickActionMessage.value = 'NÃ£o foi possÃ­vel atualizar pin';
    } finally {
        pinPending.value = false;
        setTimeout(() => {
            quickActionMessage.value = '';
        }, 1400);
    }
};

const goToPreviousTicket = async () => {
    if (!previousTicket.value) return;
    await router.push({ name: 'tickets.show', params: { id: previousTicket.value.id } });
};

const goToNextTicket = async () => {
    if (!nextTicket.value) return;
    await router.push({ name: 'tickets.show', params: { id: nextTicket.value.id } });
};

const updateAssignment = async () => {
    savingAssignment.value = true;
    error.value = '';

    try {
        await api.patch(`/tickets/${route.params.id}/assignment`, {
            assigned_operator_id: assignmentForm.assigned_operator_id
                ?Number(assignmentForm.assigned_operator_id)
                : null,
        });
        if (ticket.value) {
            const selectedId = Number(assignmentForm.assigned_operator_id || 0);
            const selectedOperator = selectedId
                ?(ticket.value.operators || []).find((operator) => Number(operator.id) === selectedId) || null
                : null;
            ticket.value.assigned_operator = selectedOperator
                ?{
                    id: selectedOperator.id,
                    name: selectedOperator.name,
                }
                : null;
            touchTicketLastActivity();
        }
        quickActionMessage.value = 'Operador atualizado';
    } catch (exception) {
        error.value = exception?.response?.data?.message || 'Falha ao atualizar atribuicao.';
        quickActionMessage.value = 'NÃ£o foi possÃ­vel transferir operador';
    } finally {
        savingAssignment.value = false;
        setTimeout(() => {
            quickActionMessage.value = '';
        }, 1600);
    }
};

const updateMetadata = async () => {
    if (savingMetadata.value) {
        metadataAutoSavePending = true;
        return;
    }

    savingMetadata.value = true;
    error.value = '';

    try {
        await api.patch(`/tickets/${route.params.id}/metadata`, {
            subject: metadataForm.subject,
            priority: metadataForm.priority,
            type: metadataForm.type,
            inbox_id: metadataForm.inbox_id ?Number(metadataForm.inbox_id) : null,
            cc_emails: metadataForm.cc_emails,
            follower_user_ids: metadataForm.follower_user_ids,
        });
        if (ticket.value) {
            ticket.value.subject = metadataForm.subject;
            ticket.value.priority = metadataForm.priority;
            ticket.value.type = metadataForm.type;
            ticket.value.cc_emails = String(metadataForm.cc_emails || '')
                .split(',')
                .map((email) => email.trim())
                .filter(Boolean);

            const targetInboxId = Number(metadataForm.inbox_id || 0);
            if (targetInboxId) {
                const selectedInbox = (ticket.value.available_inboxes || []).find(
                    (inbox) => Number(inbox.id) === targetInboxId,
                );
                if (selectedInbox) {
                    ticket.value.inbox = {
                        id: selectedInbox.id,
                        name: selectedInbox.name,
                    };
                }
            }

            const selectedFollowerIds = new Set(
                (metadataForm.follower_user_ids || []).map((id) => Number(id)),
            );
            ticket.value.followers = (ticket.value.available_followers || []).filter(
                (follower) => selectedFollowerIds.has(Number(follower.id)),
            );
            touchTicketLastActivity();
        }
    } catch (exception) {
        error.value = exception?.response?.data?.message || 'Falha ao atualizar metadados.';
    } finally {
        savingMetadata.value = false;
        if (metadataAutoSavePending) {
            metadataAutoSavePending = false;
            await updateMetadata();
        }
    }
};

const autoSaveStatusFromPanel = async () => {
    if (!isOperator.value || !canUpdateStatus.value) return;
    await updateStatus();
};

const autoSaveAssignmentFromPanel = async () => {
    if (!isOperator.value || !canAssign.value) return;
    await updateAssignment();
};

const triggerMetadataAutoSave = () => {
    if (!isOperator.value || !canUpdateMetadata.value) return;

    if (metadataAutoSaveTimer) {
        clearTimeout(metadataAutoSaveTimer);
    }

    metadataAutoSaveTimer = setTimeout(() => {
        metadataAutoSaveTimer = null;
        updateMetadata();
    }, 350);
};

const flushMetadataAutoSave = () => {
    if (!isOperator.value || !canUpdateMetadata.value) return;

    if (metadataAutoSaveTimer) {
        clearTimeout(metadataAutoSaveTimer);
        metadataAutoSaveTimer = null;
    }

    updateMetadata();
};

const handleDetailsSubjectInput = () => {
    triggerMetadataAutoSave();
};

const handleDetailsMetadataChange = () => {
    flushMetadataAutoSave();
};

const handleDetailsPrioritySelect = (priority) => {
    if (!priority || metadataForm.priority === priority) return;
    metadataForm.priority = priority;
    flushMetadataAutoSave();
};

const handleFilesChange = (event) => {
    const files = event.target.files ?[...event.target.files] : [];
    messageFiles.value = files;
};

const toggleFollower = (id) => {
    const normalizedId = Number(id);
    const current = (metadataForm.follower_user_ids || []).map((item) => Number(item));

    if (current.includes(normalizedId)) {
        metadataForm.follower_user_ids = current.filter((item) => item !== normalizedId);
        return;
    }

    metadataForm.follower_user_ids = [...current, normalizedId];
};

const removeFollower = (id) => {
    const normalizedId = Number(id);
    metadataForm.follower_user_ids = (metadataForm.follower_user_ids || [])
        .map((item) => Number(item))
        .filter((item) => item !== normalizedId);
};

const followerRoleLabel = (role) => (role === 'operator' ?'Operador' : 'Cliente');
const userAvatarUrl = (user) => String(user?.avatar_url || '').trim();
const choosePanelOperator = async (id) => {
    assignmentForm.assigned_operator_id = String(Number(id));
    panelOperatorSearch.value = '';
    panelOperatorPickerOpen.value = false;
    transferOperatorOpen.value = false;
    await autoSaveAssignmentFromPanel();
};
const clearPanelAssignedOperator = async () => {
    assignmentForm.assigned_operator_id = '';
    panelOperatorSearch.value = '';
    panelOperatorPickerOpen.value = false;
    transferOperatorOpen.value = false;
    await autoSaveAssignmentFromPanel();
};
const handlePanelOperatorSearchEnter = async (event) => {
    if (event.key !== 'Enter') return;
    event.preventDefault();
    const first = panelOperatorSuggestions.value[0];
    if (first) {
        await choosePanelOperator(first.id);
    } else {
        await clearPanelAssignedOperator();
    }
};
const closePanelOperatorPicker = () => {
    setTimeout(() => {
        panelOperatorPickerOpen.value = false;
    }, 120);
};
const onPanelOperatorSearchInput = () => {
    panelOperatorPickerOpen.value = panelOperatorSearch.value.trim().length > 0;
};
const onPanelOperatorSearchFocus = () => {
    panelOperatorPickerOpen.value = panelOperatorSearch.value.trim().length > 0;
};
const toggleTransferOperator = () => {
    transferOperatorOpen.value = !transferOperatorOpen.value;
    if (!transferOperatorOpen.value) {
        panelOperatorPickerOpen.value = false;
        panelOperatorSearch.value = '';
    }
};
const addFollowerFromPanel = () => {
    const first = panelFollowerSuggestions.value[0];
    if (first) {
        const normalizedId = Number(first.id);
        const current = (metadataForm.follower_user_ids || []).map((item) => Number(item));
        if (!current.includes(normalizedId)) {
            metadataForm.follower_user_ids = [...current, normalizedId];
            flushMetadataAutoSave();
        }
    }

    followerSearch.value = '';
    panelFollowerPickerOpen.value = false;
};
const chooseFollowerFromPanel = (id) => {
    const normalizedId = Number(id);
    const current = (metadataForm.follower_user_ids || []).map((item) => Number(item));
    if (!current.includes(normalizedId)) {
        metadataForm.follower_user_ids = [...current, normalizedId];
        flushMetadataAutoSave();
    }

    followerSearch.value = '';
    panelFollowerPickerOpen.value = false;
};
const removeFollowerFromPanel = (id) => {
    const normalizedId = Number(id);
    metadataForm.follower_user_ids = (metadataForm.follower_user_ids || [])
        .map((item) => Number(item))
        .filter((item) => item !== normalizedId);
    flushMetadataAutoSave();
};
const handlePanelFollowerSearchEnter = (event) => {
    if (event.key !== 'Enter') return;
    event.preventDefault();
    addFollowerFromPanel();
};
const closePanelFollowerPicker = () => {
    setTimeout(() => {
        panelFollowerPickerOpen.value = false;
    }, 120);
};
const onPanelFollowerSearchInput = () => {
    panelFollowerPickerOpen.value = followerSearch.value.trim().length > 0;
};
const onPanelFollowerSearchFocus = () => {
    panelFollowerPickerOpen.value = followerSearch.value.trim().length > 0;
};

const formatMessageHtml = (value) => {
    const raw = String(value || '');
    if (!raw) return '';
    const looksLikeHtml = /<\/?[a-z][\s\S]*>/i.test(raw);
    if (!looksLikeHtml) {
        const escaped = raw
            .replaceAll('&', '&amp;')
            .replaceAll('<', '&lt;')
            .replaceAll('>', '&gt;')
            .replaceAll('"', '&quot;')
            .replaceAll("'", '&#39;');
        return escaped.replace(/\n/g, '<br>');
    }
    return sanitizeHtml(raw);
};

const sanitizeHtml = (html) => {
    const allowedTags = new Set([
        'b', 'strong', 'i', 'em', 'u', 's', 'br', 'p', 'div', 'span',
        'ul', 'ol', 'li', 'blockquote', 'code', 'pre', 'a',
    ]);
    const parser = new DOMParser();
    const doc = parser.parseFromString(html, 'text/html');

    const sanitizeNode = (node) => {
        if (node.nodeType === Node.TEXT_NODE) {
            return document.createTextNode(node.textContent || '');
        }
        if (node.nodeType !== Node.ELEMENT_NODE) {
            return document.createTextNode('');
        }

        const tag = node.tagName.toLowerCase();
        if (!allowedTags.has(tag)) {
            const fragment = document.createDocumentFragment();
            node.childNodes.forEach((child) => fragment.appendChild(sanitizeNode(child)));
            return fragment;
        }

        const element = document.createElement(tag);
        if (tag === 'a') {
            const href = node.getAttribute('href') || '';
            if (/^https?:\/\//i.test(href)) {
                element.setAttribute('href', href);
                element.setAttribute('target', '_blank');
                element.setAttribute('rel', 'noopener noreferrer');
            }
        }

        node.childNodes.forEach((child) => element.appendChild(sanitizeNode(child)));
        return element;
    };

    const fragment = document.createDocumentFragment();
    doc.body.childNodes.forEach((child) => fragment.appendChild(sanitizeNode(child)));
    const container = document.createElement('div');
    container.appendChild(fragment);
    return container.innerHTML;
};

const normalizeComposerHtml = (html) => {
    return String(html || '')
        .replace(/<div><br><\/div>/gi, '<br>')
        .replace(/<\/div><div>/gi, '<br>')
        .replace(/<\/?div>/gi, '')
        .trim();
};

const getComposerPlainText = () => {
    const editor = composerRef.value;
    if (!editor) return '';
    return String(editor.innerText || '').replace(/\u00A0/g, ' ').trim();
};

const updateMessageBodyFromComposer = () => {
    const editor = composerRef.value;
    if (!editor) return;

    const plain = getComposerPlainText();
    if (!plain) {
        messageBody.value = '';
        editor.innerHTML = '';
        return;
    }

    messageBody.value = normalizeComposerHtml(editor.innerHTML);
};

const applyComposerCommand = (command, value = null) => {
    document.execCommand(command, false, value);
    updateMessageBodyFromComposer();
    composerRef.value?.focus();
};

const insertComposerLink = () => {
    const url = window.prompt('URL do link');
    if (!url) return;
    const normalized = url.startsWith('http') ? url : `https://${url}`;
    applyComposerCommand('createLink', normalized);
};

const onComposerInput = () => {
    updateMessageBodyFromComposer();
};

const onComposerPaste = (event) => {
    event.preventDefault();
    const text = event.clipboardData?.getData('text/plain') || '';
    document.execCommand('insertText', false, text);
};

const sendMessage = async () => {
    const plain = getComposerPlainText();
    if (!plain && messageFiles.value.length === 0) {
        return;
    }

    sendingMessage.value = true;
    error.value = '';

    try {
        const formData = new FormData();

        if (plain) {
            const safeHtml = sanitizeHtml(messageBody.value || plain);
            formData.append('body', safeHtml);
        }

        messageFiles.value.forEach((file) => {
            formData.append('attachments[]', file);
        });

        await api.post(`/tickets/${route.params.id}/messages`, formData, {
            headers: {
                'Content-Type': 'multipart/form-data',
            },
        });

        messageBody.value = '';
        messageFiles.value = [];
        if (composerRef.value) {
            composerRef.value.innerHTML = '';
        }

        const input = document.getElementById('message-attachments');
        if (input) input.value = '';

        await loadTicket();
    } catch (exception) {
        error.value = exception?.response?.data?.message || 'Falha ao enviar mensagem.';
    } finally {
        sendingMessage.value = false;
    }
};

const sendNote = async () => {
    if (!noteBody.value.trim()) {
        return;
    }

    sendingNote.value = true;
    error.value = '';

    try {
        const formData = new FormData();
        formData.append('body', noteBody.value.trim());
        formData.append('is_internal', '1');

        await api.post(`/tickets/${route.params.id}/messages`, formData, {
            headers: {
                'Content-Type': 'multipart/form-data',
            },
        });

        noteBody.value = '';
        await loadTicket();
    } catch (exception) {
        error.value = exception?.response?.data?.message || 'Falha ao adicionar nota.';
    } finally {
        sendingNote.value = false;
    }
};

const formatDate = (value) => {
    if (!value) return '-';
    return new Date(value).toLocaleString('pt-PT');
};

const formatSize = (value) => {
    if (!value) return '0 KB';

    const units = ['B', 'KB', 'MB', 'GB'];
    let size = Number(value);
    let unit = 0;

    while (size >= 1024 && unit < units.length - 1) {
        size /= 1024;
        unit += 1;
    }

    return `${size.toFixed(size >= 10 || unit === 0 ?0 : 1)} ${units[unit]}`;
};

const messageAuthor = (message) => {
    if (message.author_type === 'user') return message.author_user?.name || 'Operador';
    if (message.author_type === 'contact') return message.author_contact?.name || 'Cliente';
    return 'Sistema';
};
const messageAuthorAvatarUrl = (message) => {
    if (message.author_type === 'user') {
        return String(message.author_user?.avatar_url || '').trim();
    }

    return '';
};

const isOwnMessage = (message) => {
    if (message.author_type !== 'user') return false;
    return Number(message.author_user?.id) === Number(auth.state.user?.id);
};

const logActor = (log) => {
    if (log.actor_type === 'user') return log.actor_user?.name || 'Operador';
    if (log.actor_type === 'contact') return log.actor_contact?.name || 'Cliente';
    return 'Sistema';
};

const activityIconClass = (action) => {
    if (action === 'ticket_created') return 'act-ic--created';
    if (action === 'status_updated') return 'act-ic--status';
    if (action === 'assignment_updated') return 'act-ic--assign';
    if (action === 'field_updated') return 'act-ic--field';
    if (action === 'message_added') return 'act-ic--message';
    if (action === 'attachments_added') return 'act-ic--attach';
    return 'act-ic--default';
};

// SVG path data por tipo de aÃ§Ã£o (viewBox 0 0 16 16)
const activityIconPath = (action) => {
    if (action === 'ticket_created') return 'M8 2v12M2 8h12';
    if (action === 'status_updated') return 'M13 8A5 5 0 1 1 5.3 4M13 2v4H9';
    if (action === 'assignment_updated') return 'M8 7a3 3 0 1 0 0-6 3 3 0 0 0 0 6ZM2 14c0-3.3 2.7-5 6-5s6 1.7 6 5';
    if (action === 'field_updated') return 'M11 2.5 13.5 5 5.5 13H3v-2.5L11 2.5Z';
    if (action === 'message_added') return 'M2 4a1.5 1.5 0 0 1 1.5-1.5h9A1.5 1.5 0 0 1 14 4v5a1.5 1.5 0 0 1-1.5 1.5H9l-2 2-2-2H3.5A1.5 1.5 0 0 1 2 9V4Z';
    if (action === 'attachments_added') return 'M13 8.5 7 14.5a4 4 0 0 1-5.7-5.6L7.7 2.5a2.5 2.5 0 0 1 3.5 3.5L5 12.4a1 1 0 0 1-1.4-1.4L9.5 5';
    return 'M8 8m-1 0a1 1 0 1 0 2 0 1 1 0 1 0-2 0';
};

const ACTIVITY_FIELD_LABELS = {
    status: 'Estado',
    priority: 'Prioridade',
    type: 'Tipo',
    subject: 'Assunto',
    inbox_id: 'Inbox',
    assigned_operator_id: 'Operador',
    cc_emails: 'CC',
    follower_user_ids: 'Seguidores',
};

const ACTIVITY_STATUS_LABELS = {
    open: 'Aberto', in_progress: 'Em tratamento', pending: 'Aguardando cliente',
    closed: 'Fechado', cancelled: 'Cancelado',
};
const ACTIVITY_PRIORITY_LABELS = { low: 'Baixa', medium: 'MÃ©dia', high: 'Alta', urgent: 'Urgente' };
const ACTIVITY_TYPE_LABELS = { question: 'QuestÃ£o', incident: 'Incidente', request: 'Pedido', task: 'Tarefa', other: 'Outro' };

const activityFieldLabel = (field) => ACTIVITY_FIELD_LABELS[field] || field;

const activityValueLabel = (field, value) => {
    if (!value || value === 'null') return 'â€”';
    if (field === 'status') return ACTIVITY_STATUS_LABELS[value] || value;
    if (field === 'priority') return ACTIVITY_PRIORITY_LABELS[value] || value;
    if (field === 'type') return ACTIVITY_TYPE_LABELS[value] || value;
    return value;
};

const formatActivityTime = (value) => {
    if (!value) return '--:--';

    return new Date(value).toLocaleTimeString('pt-PT', {
        hour: '2-digit',
        minute: '2-digit',
    });
};

const activityTitle = (log) => actionLabels[log.action] || log.action;

const activityFormatDate = (value) => {
    if (!value) return '';
    return new Date(value).toLocaleDateString('pt-PT', { day: '2-digit', month: 'short', year: 'numeric' });
};

const activityGroupLabel = (value) => {
    const date = new Date(value);
    const now = new Date();

    const sameDay = date.toDateString() === now.toDateString();
    if (sameDay) return 'Hoje';

    const startOfWeek = new Date(now);
    startOfWeek.setHours(0, 0, 0, 0);
    startOfWeek.setDate(now.getDate() - ((now.getDay() + 6) % 7));

    if (date >= startOfWeek) return 'Esta semana';

    if (date.getFullYear() === now.getFullYear() && date.getMonth() === now.getMonth()) {
        return 'Este mÃªs';
    }

    return 'Anteriores';
};

const activityGroups = computed(() => {
    const groupsMap = new Map();
    const orderedGroupNames = ['Hoje', 'Esta semana', 'Este mÃªs', 'Anteriores'];
    let logs = ticket.value?.logs || [];
    if (!isOperator.value) {
        const allowedActions = new Set(['ticket_created', 'status_updated']);
        logs = logs.filter((log) => allowedActions.has(String(log.action)));
    }

    logs.forEach((log) => {
        const key = activityGroupLabel(log.created_at);
        if (!groupsMap.has(key)) {
            groupsMap.set(key, []);
        }
        groupsMap.get(key).push(log);
    });

    return orderedGroupNames
        .filter((name) => groupsMap.has(name))
        .map((name) => ({
            label: name,
            logs: groupsMap.get(name),
        }));
});

onMounted(loadTicket);
onMounted(() => {
    document.addEventListener('click', closeStatusMenuOnOutsideClick);
    detailsPanelOpen.value = detailsFromQuery(route.query.details);
});
onBeforeUnmount(() => {
    document.removeEventListener('click', closeStatusMenuOnOutsideClick);
    leaveTicketChannel();
    if (metadataAutoSaveTimer) {
        clearTimeout(metadataAutoSaveTimer);
        metadataAutoSaveTimer = null;
    }
});
watch(
    () => route.params.id,
    () => {
        statusMenuOpen.value = false;
        leaveTicketChannel();
        loadTicket();
    },
);
watch(
    () => route.query.tab,
    (tab) => {
        activeTopTab.value = normalizeTopTab(typeof tab === 'string' ?tab : 'conversation');
    },
);
watch(
    () => route.query.details,
    (value) => {
        detailsPanelOpen.value = detailsFromQuery(value);
    },
);
</script>

<template>
    <section v-if="!loading && ticket" class="ticket-workspace">
        <header class="ticket-header">
            <div class="header-left">
                <RouterLink class="back-link" :to="{ name: 'tickets.index' }">
                    <span class="back-icon" aria-hidden="true">
                        <svg viewBox="0 0 24 24" fill="none">
                            <path d="M14.5 6.5L9 12l5.5 5.5" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" />
                        </svg>
                    </span>
                    <span>Lista Tickets</span>
                </RouterLink>
            </div>

            <div class="header-center">
                <button
                    type="button"
                    class="ticket-step"
                    :disabled="!previousTicket"
                    aria-label="Ticket anterior"
                    @click="goToPreviousTicket"
                >
                    <svg class="step-icon" viewBox="0 0 24 24" fill="none" aria-hidden="true">
                        <path d="M14.5 6.5L9 12l5.5 5.5" stroke="currentColor" stroke-width="2.3" stroke-linecap="round" stroke-linejoin="round" />
                    </svg>
                </button>

                <p class="ticket-title" :title="ticket.subject">
                    <strong>{{ ticket.ticket_number }}</strong>
                    <span>{{ ticket.subject }}</span>
                </p>

                <button
                    type="button"
                    class="ticket-step"
                    :disabled="!nextTicket"
                    aria-label="Ticket seguinte"
                    @click="goToNextTicket"
                >
                    <svg class="step-icon" viewBox="0 0 24 24" fill="none" aria-hidden="true">
                        <path d="M9.5 6.5L15 12l-5.5 5.5" stroke="currentColor" stroke-width="2.3" stroke-linecap="round" stroke-linejoin="round" />
                    </svg>
                </button>
            </div>

            <div class="header-actions">
                <button
                    type="button"
                    class="btn-ghost pin-header-btn"
                    :class="{ 'is-pinned': ticket.is_pinned }"
                    :disabled="pinPending"
                    :title="ticket.is_pinned ?'Desafixar ticket' : 'Fixar ticket'"
                    :aria-label="ticket.is_pinned ?'Desafixar ticket' : 'Fixar ticket'"
                    @click="toggleTicketPin"
                >
                    <svg class="pin-icon" viewBox="0 0 24 24" fill="none" aria-hidden="true">
                        <path d="M9 4h6l-1.2 5.2 3.2 2.8v1.2H7v-1.2l3.2-2.8L9 4z" stroke="currentColor" stroke-width="1.7" stroke-linejoin="round" />
                        <path d="M12 13v7" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" />
                    </svg>
                    <span>{{ ticket.is_pinned ?'Desafixar' : 'Fixar' }}</span>
                </button>

                <div v-if="isOperator && canUpdateStatus && (canQuickClose || headerStatusOptions.length)" class="status-split">
                    <button
                        v-if="canQuickClose"
                        class="btn-success status-main"
                        type="button"
                        :disabled="savingStatus || isAlreadyClosed"
                        @click="closeTicketQuick"
                    >
                        <svg viewBox="0 0 14 14" fill="none" aria-hidden="true"><path d="M2.5 7.5l3 3 6-6" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/></svg>
                        {{ savingStatus ? 'A atualizar...' : 'Submeter como fechado' }}
                    </button>

                    <button
                        v-if="headerStatusOptions.length"
                        class="btn-success status-toggle"
                        :class="{ 'is-standalone': !canQuickClose }"
                        type="button"
                        aria-label="Escolher outro estado"
                        :aria-expanded="statusMenuOpen"
                        @click.stop="toggleStatusMenu"
                    >
                        <svg viewBox="0 0 12 12" fill="none" aria-hidden="true"><path d="M2.5 4.5l3.5 3.5 3.5-3.5" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/></svg>
                    </button>

                    <div v-if="statusMenuOpen" class="status-menu">
                        <button
                            v-for="status in headerStatusOptions"
                            :key="`header-status-${status}`"
                            type="button"
                            class="status-option"
                            :class="`status-option--${status}`"
                            @click="updateStatusTo(status)"
                        >
                            <span class="status-option-dot"></span>
                            {{ formatStatusOption(status) }}
                        </button>
                    </div>
                </div>

                <RouterLink
                    v-if="ticket.permissions?.can_update"
                    class="btn-ghost"
                    :to="{ name: 'tickets.edit', params: { id: ticket.id } }"
                >
                    Editar completo
                </RouterLink>
            </div>
        </header>

        <p v-if="error" class="error-banner">{{ error }}</p>

        <div class="ticket-info-bar">
            <span class="tib-pill status-pill" :class="`status-${ticket.status}`">{{ statusLabels[ticket.status] || ticket.status }}</span>
            <span class="tib-pill priority-pill" :class="`priority-${ticket.priority}`">{{ priorityLabels[ticket.priority] || ticket.priority }}</span>
            <span class="tib-pill type-pill">{{ typeLabels[ticket.type] || ticket.type }}</span>
            <span v-if="ticket.inbox" class="tib-item">
                <svg class="tib-icon" viewBox="0 0 16 16" fill="none"><path d="M2 3.5A1.5 1.5 0 0 1 3.5 2h9A1.5 1.5 0 0 1 14 3.5v6A1.5 1.5 0 0 1 12.5 11H9l-2 2.5L5 11H3.5A1.5 1.5 0 0 1 2 9.5v-6Z" stroke="currentColor" stroke-width="1.3" stroke-linejoin="round"/></svg>
                {{ ticket.inbox.name }}
            </span>
            <span v-if="ticket.entity" class="tib-item">
                <svg class="tib-icon" viewBox="0 0 16 16" fill="none"><rect x="2" y="4" width="12" height="9" rx="1.5" stroke="currentColor" stroke-width="1.3"/><path d="M5 4V3a1 1 0 0 1 1-1h4a1 1 0 0 1 1 1v1" stroke="currentColor" stroke-width="1.3"/></svg>
                {{ ticket.entity.name }}
            </span>
            <span class="tib-item tib-operator">
                <UserAvatar
                    :name="ticket.assigned_operator?.name || '?'"
                    :src="userAvatarUrl(ticket.assigned_operator)"
                    :size="18"
                    class="tib-avatar"
                />
                {{ ticket.assigned_operator?.name || 'Sem operador' }}
            </span>
            <span v-if="ticket.created_at" class="tib-item tib-date">
                <svg class="tib-icon" viewBox="0 0 16 16" fill="none"><rect x="2" y="3" width="12" height="11" rx="1.5" stroke="currentColor" stroke-width="1.3"/><path d="M5 1.5v3M11 1.5v3M2 7h12" stroke="currentColor" stroke-width="1.3" stroke-linecap="round"/></svg>
                {{ formatDate(ticket.created_at) }}
            </span>
        </div>

        <div class="workspace-grid">
            <article id="conversation-section" class="conversation-card">
                <div class="conversation-tabs">
                    <button
                        type="button"
                        :class="['tab', { active: activeTopTab === 'conversation' }]"
                        @click="activeTopTab = 'conversation'"
                    >
                        <svg class="tab-icon" viewBox="0 0 18 18" fill="none"><path d="M2 4a1.5 1.5 0 0 1 1.5-1.5h11A1.5 1.5 0 0 1 16 4v7a1.5 1.5 0 0 1-1.5 1.5H10l-2 2.5L6 12.5H3.5A1.5 1.5 0 0 1 2 11V4Z" stroke="currentColor" stroke-width="1.5" stroke-linejoin="round"/></svg>
                        Conversa
                    </button>
                    <button
                        type="button"
                        :class="['tab', { active: activeTopTab === 'activity_logs' }]"
                        @click="activeTopTab = 'activity_logs'"
                    >
                        <svg class="tab-icon" viewBox="0 0 18 18" fill="none"><path d="M3 9a6 6 0 1 0 1.7-4.2M3 4v3.5h3.5" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"/><path d="M9 6v3l2 1.5" stroke="currentColor" stroke-width="1.5" stroke-linecap="round"/></svg>
                        Atividade
                    </button>
                    <button
                        v-if="isOperator"
                        type="button"
                        :class="['tab', { active: activeTopTab === 'notes' }]"
                        @click="activeTopTab = 'notes'"
                    >
                        <svg class="tab-icon" viewBox="0 0 18 18" fill="none"><path d="M4 2.5h7.5L14.5 6V15a1 1 0 0 1-1 1H4a1 1 0 0 1-1-1V3.5a1 1 0 0 1 1-1Z" stroke="currentColor" stroke-width="1.5" stroke-linejoin="round"/><path d="M11.5 2.5V6H15" stroke="currentColor" stroke-width="1.4" stroke-linejoin="round"/><path d="M6 9h6M6 12h4" stroke="currentColor" stroke-width="1.4" stroke-linecap="round"/></svg>
                        Notas
                        <span v-if="internalNotes.length" class="tab-badge">{{ internalNotes.length }}</span>
                    </button>
                </div>

                <div v-if="activeTopTab === 'conversation'" class="conversation-tab-content">
                    <div class="message-stream">
                        <article
                            v-for="message in chatMessages"
                            :key="message.id"
                            class="message-row"
                            :class="{ 'is-operator': message.author_type === 'user', 'is-mine': isOwnMessage(message) }"
                        >
                            <UserAvatar
                                class="msg-avatar"
                                :name="messageAuthor(message)"
                                :src="messageAuthorAvatarUrl(message)"
                                :size="30"
                            />
                            <div class="bubble">
                                <span v-if="message.author_type !== 'user'" class="bubble-author">{{ messageAuthor(message) }}</span>
                                <div v-if="message.body" class="message-body" v-html="formatMessageHtml(message.body)"></div>
                                <div v-if="message.attachments?.length" class="attachment-cards">
                                    <a
                                        v-for="attachment in message.attachments"
                                        :key="attachment.uuid"
                                        class="attachment-card"
                                        :href="attachment.download_url"
                                        target="_blank"
                                        rel="noopener noreferrer"
                                    >
                                        <svg class="att-file-icon" viewBox="0 0 20 20" fill="none"><path d="M5 3h8l4 4v10a1 1 0 0 1-1 1H5a1 1 0 0 1-1-1V4a1 1 0 0 1 1-1Z" stroke="currentColor" stroke-width="1.5" stroke-linejoin="round"/><path d="M13 3v4h4" stroke="currentColor" stroke-width="1.4" stroke-linejoin="round"/></svg>
                                        <div class="att-info">
                                            <span class="att-name">{{ attachment.original_name }}</span>
                                            <small class="att-size">{{ formatSize(attachment.size) }}</small>
                                        </div>
                                        <svg class="att-dl-icon" viewBox="0 0 16 16" fill="none"><path d="M8 3v7M5 7.5l3 3 3-3M3 13h10" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"/></svg>
                                    </a>
                                </div>
                                <span class="bubble-time">{{ formatActivityTime(message.created_at) }}</span>
                            </div>
                        </article>

                        <div v-if="!chatMessages.length" class="stream-empty">
                            <svg class="stream-empty-icon" viewBox="0 0 48 48" fill="none">
                                <path d="M6 12a4 4 0 0 1 4-4h28a4 4 0 0 1 4 4v18a4 4 0 0 1-4 4H28l-5 6-5-6H10a4 4 0 0 1-4-4V12Z" stroke="#c4d0de" stroke-width="2.2" stroke-linejoin="round"/>
                                <path d="M16 21h16M16 27h10" stroke="#c4d0de" stroke-width="2" stroke-linecap="round"/>
                            </svg>
                            <p>Sem mensagens pÃºblicas neste ticket.</p>
                        </div>
                        <div v-if="isOperator && internalNotes.length" class="chat-note-hint">
                            <svg viewBox="0 0 16 16" fill="none" class="hint-icon"><path d="M8 2a6 6 0 1 1 0 12A6 6 0 0 1 8 2Z" stroke="currentColor" stroke-width="1.4"/><path d="M8 7v4M8 5.5v.5" stroke="currentColor" stroke-width="1.5" stroke-linecap="round"/></svg>
                            {{ internalNotes.length }} nota(s) interna(s) no separador <strong>Notas</strong>.
                        </div>
                    </div>

                    <form
                        v-if="canChat"
                        id="composer-section"
                        class="composer"
                        @submit.prevent="sendMessage"
                    >
                        <div class="composer-body">
                            <div
                                id="ticket-composer-input"
                                ref="composerRef"
                                class="composer-editor"
                                contenteditable="true"
                                role="textbox"
                                aria-multiline="true"
                                data-placeholder="Escreva uma resposta para o cliente..."
                                @input="onComposerInput"
                                @paste="onComposerPaste"
                            ></div>
                            <ul v-if="messageFiles.length" class="staged-files">
                                <li v-for="file in messageFiles" :key="file.name + file.size" class="staged-file">
                                    <svg viewBox="0 0 14 14" fill="none" class="staged-file-icon"><path d="M3 1.5h6l2 2V12a1 1 0 0 1-1 1H3a1 1 0 0 1-1-1V2.5a1 1 0 0 1 1-1Z" stroke="currentColor" stroke-width="1.2"/><path d="M9 1.5V4H11" stroke="currentColor" stroke-width="1.1"/></svg>
                                    {{ file.name }} <span class="staged-file-size">{{ formatSize(file.size) }}</span>
                                </li>
                            </ul>
                        </div>
                        <div class="composer-actions">
                            <div class="composer-toolbar" role="toolbar" aria-label="FormataÃ§Ã£o de texto">
                                <button type="button" class="composer-tool" title="Negrito" @click="applyComposerCommand('bold')">
                                    <svg viewBox="0 0 16 16" fill="none" aria-hidden="true"><path d="M4 3h4.6a3 3 0 0 1 0 6H4V3Z" stroke="currentColor" stroke-width="1.6"/><path d="M4 9h5a3 3 0 0 1 0 6H4V9Z" stroke="currentColor" stroke-width="1.6"/></svg>
                                </button>
                                <button type="button" class="composer-tool" title="ItÃ¡lico" @click="applyComposerCommand('italic')">
                                    <svg viewBox="0 0 16 16" fill="none" aria-hidden="true"><path d="M6 3h6M4 13h6M7.5 3l-2 10" stroke="currentColor" stroke-width="1.6" stroke-linecap="round"/></svg>
                                </button>
                                <button type="button" class="composer-tool" title="Sublinhado" @click="applyComposerCommand('underline')">
                                    <svg viewBox="0 0 16 16" fill="none" aria-hidden="true"><path d="M5 3v4a3 3 0 0 0 6 0V3" stroke="currentColor" stroke-width="1.6" stroke-linecap="round"/><path d="M4 13h8" stroke="currentColor" stroke-width="1.6" stroke-linecap="round"/></svg>
                                </button>
                                <button type="button" class="composer-tool" title="Rasurado" @click="applyComposerCommand('strikeThrough')">
                                    <svg viewBox="0 0 16 16" fill="none" aria-hidden="true"><path d="M4 5c0-1.4 1.5-2.5 3.5-2.5S11 3.6 11 5" stroke="currentColor" stroke-width="1.6" stroke-linecap="round"/><path d="M3 8h10" stroke="currentColor" stroke-width="1.6" stroke-linecap="round"/><path d="M5 11c0 1.4 1.5 2.5 3.5 2.5S12 12.4 12 11" stroke="currentColor" stroke-width="1.6" stroke-linecap="round"/></svg>
                                </button>
                                <span class="composer-tool-sep"></span>
                                <button type="button" class="composer-tool" title="Lista com pontos" @click="applyComposerCommand('insertUnorderedList')">
                                    <svg viewBox="0 0 16 16" fill="none" aria-hidden="true"><path d="M6 4h7M6 8h7M6 12h7" stroke="currentColor" stroke-width="1.6" stroke-linecap="round"/><circle cx="3.2" cy="4" r="1" fill="currentColor"/><circle cx="3.2" cy="8" r="1" fill="currentColor"/><circle cx="3.2" cy="12" r="1" fill="currentColor"/></svg>
                                </button>
                                <button type="button" class="composer-tool" title="Lista numerada" @click="applyComposerCommand('insertOrderedList')">
                                    <svg viewBox="0 0 16 16" fill="none" aria-hidden="true"><path d="M7 4h6M7 8h6M7 12h6" stroke="currentColor" stroke-width="1.6" stroke-linecap="round"/><path d="M2.5 5h2M2.5 9h2M2.5 13h2" stroke="currentColor" stroke-width="1.6" stroke-linecap="round"/></svg>
                                </button>
                                <button type="button" class="composer-tool" title="CitaÃ§Ã£o" @click="applyComposerCommand('formatBlock', 'blockquote')">
                                    <svg viewBox="0 0 16 16" fill="none" aria-hidden="true"><path d="M4 4h3v4H4zM9 4h3v4H9z" stroke="currentColor" stroke-width="1.6"/><path d="M4 8.5c0 2.2 1.5 3.5 3.5 3.5M9 8.5c0 2.2 1.5 3.5 3.5 3.5" stroke="currentColor" stroke-width="1.4" stroke-linecap="round"/></svg>
                                </button>
                                <button type="button" class="composer-tool" title="CÃ³digo" @click="applyComposerCommand('formatBlock', 'pre')">
                                    <svg viewBox="0 0 16 16" fill="none" aria-hidden="true"><path d="M6 4l-3 4 3 4M10 4l3 4-3 4" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"/></svg>
                                </button>
                                <span class="composer-tool-sep"></span>
                                <button type="button" class="composer-tool" title="Link" @click="insertComposerLink">
                                    <svg viewBox="0 0 16 16" fill="none" aria-hidden="true"><path d="M6.5 9.5l3-3" stroke="currentColor" stroke-width="1.6" stroke-linecap="round"/><path d="M4 7a3 3 0 0 1 4.2-2.8" stroke="currentColor" stroke-width="1.6" stroke-linecap="round"/><path d="M12 9a3 3 0 0 1-4.2 2.8" stroke="currentColor" stroke-width="1.6" stroke-linecap="round"/></svg>
                                </button>
                                <button type="button" class="composer-tool" title="Limpar formataÃ§Ã£o" @click="applyComposerCommand('removeFormat')">
                                    <svg viewBox="0 0 16 16" fill="none" aria-hidden="true"><path d="M3 13h6M5 4h6M7 4l2 6" stroke="currentColor" stroke-width="1.6" stroke-linecap="round"/><path d="M11.5 9.5l2 2M13.5 9.5l-2 2" stroke="currentColor" stroke-width="1.6" stroke-linecap="round"/></svg>
                                </button>
                            </div>
                            <div class="composer-actions-right">
                                <label class="upload-label" title="Anexar ficheiro">
                                    <svg viewBox="0 0 16 16" fill="none" class="upload-icon"><path d="M3 13h10M8 10V3M5 6l3-3 3 3" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"/></svg>
                                    <input id="message-attachments" type="file" multiple @change="handleFilesChange">
                                </label>
                                <button class="btn-send" type="submit" :disabled="sendingMessage">
                                    <svg v-if="!sendingMessage" viewBox="0 0 16 16" fill="none" class="send-icon"><path d="M13.5 2.5L7 9M13.5 2.5L9 14l-2-5-5-2 11.5-4.5Z" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"/></svg>
                                    {{ sendingMessage ? 'A enviar...' : 'Enviar' }}
                                </button>
                            </div>
                        </div>
                    </form>

                    <p v-else class="notes-muted">Este ticket nÃ£o aceita novas respostas no estado atual.</p>
                </div>

                <div v-else-if="activeTopTab === 'activity_logs'" class="activity-stream">
                    <template v-for="group in activityGroups" :key="group.label">
                        <div class="act-group-divider">
                            <span class="act-group-label">{{ group.label }}</span>
                        </div>

                        <article
                            v-for="log in group.logs"
                            :key="log.id"
                            class="act-item"
                        >
                            <div class="act-marker" :class="activityIconClass(log.action)">
                                <svg viewBox="0 0 16 16" fill="none" aria-hidden="true">
                                    <path :d="activityIconPath(log.action)" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"/>
                                </svg>
                            </div>
                            <div class="act-content">
                                <div class="act-header">
                                    <span class="act-label">{{ activityTitle(log) }}</span>
                                    <span class="act-meta">
                                        <span class="act-actor">{{ logActor(log) }}</span>
                                        <span class="act-sep">&middot;</span>
                                        <time class="act-time" :datetime="log.created_at" :title="activityFormatDate(log.created_at)">
                                            {{ formatActivityTime(log.created_at) }}
                                        </time>
                                        <span class="act-sep">&middot;</span>
                                        <span class="act-date">{{ activityFormatDate(log.created_at) }}</span>
                                    </span>
                                </div>
                                <div v-if="log.field" class="act-change">
                                    <span class="act-field-label">{{ activityFieldLabel(log.field) }}</span>
                                    <span class="act-val act-val--old">{{ activityValueLabel(log.field, log.old_value) }}</span>
                                    <svg class="act-arrow" viewBox="0 0 16 8" fill="none"><path d="M0 4h13M10 1l3 3-3 3" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"/></svg>
                                    <span class="act-val act-val--new">{{ activityValueLabel(log.field, log.new_value) }}</span>
                                </div>
                            </div>
                        </article>
                    </template>

                    <div v-if="!ticket.logs.length" class="stream-empty">
                        <svg class="stream-empty-icon" viewBox="0 0 48 48" fill="none">
                            <path d="M24 6a18 18 0 1 0 5.1 35.3M24 6v10M24 16l5-5" stroke="#c4d0de" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"/>
                            <path d="M24 24l5 4" stroke="#c4d0de" stroke-width="2" stroke-linecap="round"/>
                        </svg>
                        <p>Sem atividade registada.</p>
                    </div>
                </div>


                <div v-else-if="activeTopTab === 'notes' && isOperator" class="notes-content">
                    <form v-if="canAddNotes" class="note-form" @submit.prevent="sendNote">
                        <textarea
                            v-model="noteBody"
                            placeholder="Escreve uma nota interna..."
                            maxlength="3000"
                            rows="3"
                        ></textarea>
                        <button class="btn-send-note" type="submit" :disabled="sendingNote || !noteBody.trim()">
                            {{ sendingNote ?'A submeter...' : 'Submeter' }}
                        </button>
                    </form>

                    <p v-else class="notes-muted">SÃ³ operadores podem adicionar notas internas.</p>

                    <article class="note-row" v-for="message in internalNotes" :key="`note-${message.id}`">
                        <UserAvatar
                            class="note-avatar"
                            :name="messageAuthor(message)"
                            :src="messageAuthorAvatarUrl(message)"
                            :size="34"
                        />
                        <div class="note-item">
                            <div class="note-head">
                                <strong>{{ messageAuthor(message) }}</strong>
                                <small>{{ formatActivityTime(message.created_at) }}</small>
                            </div>
                            <p>{{ message.body || 'Sem texto.' }}</p>
                        </div>
                    </article>
                </div>
            </article>

            <QuickMenuPanel
                :open="detailsPanelOpen"
                title="Detalhes do ticket"
                aria-label="Detalhes do ticket"
                @close="detailsPanelOpen = false"
            >
                <div class="details-panel-body">
                    <form
                        class="stack details-form"
                        @submit.prevent
                        v-if="isOperator && (canUpdateStatus || canAssign || canUpdateMetadata)"
                    >
                            <label v-if="canUpdateStatus">
                                Estado
                                <select v-model="statusForm.status" class="status-select-gloss" @change="autoSaveStatusFromPanel">
                                    <option v-for="status in visibleStatusOptions" :key="`panel-status-${status}`" :value="status" :style="statusOptionStyle(status)">{{ formatStatusOption(status) }}</option>
                                </select>
                            </label>

                            <div v-if="canAssign" class="details-assignee-block">
                                <p class="details-block-title">Operador atribuÃ­do</p>
                                <div class="details-assigned-card">
                                    <UserAvatar
                                        class="details-operator-avatar"
                                        :name="assignedOperatorWithEmail?.name || 'Sem atribuicao'"
                                        :src="userAvatarUrl(assignedOperatorWithEmail)"
                                        :size="26"
                                    />
                                    <div class="details-assigned-meta">
                                        <strong>{{ assignedOperatorWithEmail?.name || 'Sem atribuiÃ§Ã£o' }}</strong>
                                        <small>{{ assignedOperatorWithEmail?.email || 'Sem email' }}</small>
                                    </div>
                                </div>

                                <button type="button" class="btn-primary details-transfer-trigger" @click="toggleTransferOperator">
                                    {{ transferOperatorOpen ?'Cancelar transferÃªncia' : 'Transferir para outro operador' }}
                                </button>

                                <div v-if="transferOperatorOpen" class="details-operator-picker">
                                    <input
                                        v-model="panelOperatorSearch"
                                        type="search"
                                        placeholder="Escreve nome ou email do operador"
                                        @focus="onPanelOperatorSearchFocus"
                                        @input="onPanelOperatorSearchInput"
                                        @blur="closePanelOperatorPicker"
                                        @keydown="handlePanelOperatorSearchEnter"
                                    >

                                    <div v-if="panelOperatorPickerOpen" class="details-operator-suggestions">
                                        <button
                                            type="button"
                                            class="details-operator-option details-operator-option-clear"
                                            @mousedown.prevent="clearPanelAssignedOperator"
                                        >
                                            <span class="details-operator-avatar details-operator-avatar-clear">-</span>
                                            <span class="details-operator-meta">
                                                <strong>Sem atribuiÃ§Ã£o</strong>
                                            </span>
                                        </button>

                                        <button
                                            type="button"
                                            v-for="operator in panelOperatorSuggestions"
                                            :key="`panel-op-${operator.id}`"
                                            class="details-operator-option"
                                            @mousedown.prevent="choosePanelOperator(operator.id)"
                                        >
                                            <UserAvatar
                                                class="details-operator-avatar"
                                                :name="operator.name"
                                                :src="userAvatarUrl(operator)"
                                                :size="26"
                                            />
                                            <span class="details-operator-meta">
                                                <strong>{{ operator.name }}</strong>
                                                <small>{{ operator.email || 'Sem email' }} &middot; Operador</small>
                                            </span>
                                        </button>

                                        <p v-if="!panelOperatorSuggestions.length" class="details-operator-empty">Sem resultados.</p>
                                    </div>
                                </div>
                            </div>

                            <template v-if="canUpdateMetadata">
                                <label>
                                    Assunto
                                    <input
                                        v-model="metadataForm.subject"
                                        maxlength="255"
                                        required
                                        @input="handleDetailsSubjectInput"
                                        @blur="flushMetadataAutoSave"
                                    >
                                </label>

                                <label>
                                    Tipo
                                    <select v-model="metadataForm.type" @change="handleDetailsMetadataChange">
                                        <option v-for="(label, key) in typeLabels" :key="`panel-type-${key}`" :value="key">{{ label }}</option>
                                    </select>
                                </label>

                                <label>
                                    Prioridade
                                    <div class="details-priority-picker">
                                        <button
                                            type="button"
                                            v-for="(label, key) in priorityLabels"
                                            :key="`panel-priority-${key}`"
                                            class="details-priority-btn"
                                            :class="{ 'is-selected': metadataForm.priority === key }"
                                            :aria-pressed="metadataForm.priority === key ?'true' : 'false'"
                                            @click="handleDetailsPrioritySelect(key)"
                                        >
                                            <span class="details-priority-dot" :class="priorityDotClass[key]"></span>
                                            {{ label }}
                                        </button>
                                    </div>
                                </label>

                                <label>
                                    Inbox
                                    <select v-model="metadataForm.inbox_id" @change="handleDetailsMetadataChange">
                                        <option v-for="inbox in ticket.available_inboxes" :key="`panel-inbox-${inbox.id}`" :value="String(inbox.id)">
                                            {{ inbox.name }}
                                        </option>
                                    </select>
                                </label>

                                <div class="details-followers-block">
                                    <p class="details-block-title">Utilizadores em conhecimento</p>

                                    <div v-if="selectedFollowers.length" class="details-followers-tags">
                                        <span v-for="follower in selectedFollowers" :key="`panel-follow-${follower.id}`" class="follower-tag">
                                            <UserAvatar
                                                class="details-operator-avatar"
                                                :name="follower.name"
                                                :src="userAvatarUrl(follower)"
                                                :size="22"
                                            />
                                            {{ follower.name }}
                                            <small>{{ followerRoleLabel(follower.role) }}</small>
                                            <button type="button" @click="removeFollowerFromPanel(follower.id)">&times;</button>
                                        </span>
                                    </div>
                                    <p v-else class="followers-empty">Sem utilizadores em conhecimento.</p>

                                    <div class="details-followers-picker">
                                        <input
                                            v-model="followerSearch"
                                            type="search"
                                            placeholder="Adicionar utilizador por nome ou email"
                                            @focus="onPanelFollowerSearchFocus"
                                            @input="onPanelFollowerSearchInput"
                                            @blur="closePanelFollowerPicker"
                                            @keydown="handlePanelFollowerSearchEnter"
                                        >

                                        <div v-if="panelFollowerPickerOpen" class="details-follower-suggestions">
                                            <button
                                                type="button"
                                                v-for="follower in panelFollowerSuggestions"
                                                :key="`panel-follow-suggest-${follower.id}`"
                                                class="details-operator-option"
                                                @mousedown.prevent="chooseFollowerFromPanel(follower.id)"
                                            >
                                                <UserAvatar
                                                    class="details-operator-avatar"
                                                    :name="follower.name"
                                                    :src="userAvatarUrl(follower)"
                                                    :size="26"
                                                />
                                                <span class="details-operator-meta">
                                                    <strong>{{ follower.name }}</strong>
                                                    <small>{{ follower.email || 'Sem email' }} &middot; {{ followerRoleLabel(follower.role) }}</small>
                                                </span>
                                            </button>

                                            <p v-if="!panelFollowerSuggestions.length" class="details-operator-empty">Sem resultados.</p>
                                        </div>
                                    </div>
                                </div>
                            </template>
                        </form>

                    <div v-if="!isOperator" class="details-readonly">
                        <p><strong>Estado:</strong> {{ statusLabels[ticket.status] || ticket.status }}</p>
                        <p><strong>Prioridade:</strong> {{ priorityLabels[ticket.priority] || ticket.priority }}</p>
                        <p><strong>Tipo:</strong> {{ typeLabels[ticket.type] || ticket.type }}</p>
                        <p><strong>Inbox:</strong> {{ ticket.inbox?.name || '-' }}</p>
                        <p><strong>Entidade:</strong> {{ ticket.entity?.name || '-' }}</p>
                        <p><strong>Operador:</strong> {{ ticket.assigned_operator?.name || 'Sem atribuiÃ§Ã£o' }}</p>
                    </div>

                    <p
                            v-if="isOperator && !canUpdateStatus && !canAssign && !canUpdateMetadata"
                            class="notes-muted"
                        >
                            Sem permissÃµes para editar detalhes deste ticket.
                    </p>
                </div>
            </QuickMenuPanel>

        </div>

        <QuickActionsRail
            :actions="quickRailActions"
            aria-label="AÃ§Ãµes rÃ¡pidas"
            desktop-style="floating"
            mobile-style="inline"
            :dock="detailsPanelOpen"
            dock-offset="min(420px, calc(100vw - 1.2rem))"
            @action="handleQuickRailAction"
        >
            <template #icon-details>
                <svg class="quick-icon quick-icon-details" viewBox="0 0 24 24" fill="none" aria-hidden="true">
                    <path d="M5 7a2 2 0 0 1 2-2h10a2 2 0 0 1 2 2v2a1.6 1.6 0 0 0 0 3.2V15a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2v-2.8a1.6 1.6 0 0 0 0-3.2V7Z" stroke="#1d4ed8" stroke-width="1.6" stroke-linejoin="round" />
                    <path d="M10 9.5h4M10 12h4M10 14.5h2.8" stroke="#2563eb" stroke-width="1.6" stroke-linecap="round" />
                </svg>
            </template>
            <template #icon-reply>
                <svg class="quick-icon quick-icon-reply" viewBox="0 0 24 24" fill="none" aria-hidden="true">
                    <path d="M8 12h8M8 8h8M8 16h5M5 4h14a2 2 0 0 1 2 2v12l-4-3H5a2 2 0 0 1-2-2V6a2 2 0 0 1 2-2Z" stroke="#0891b2" stroke-width="1.8" />
                </svg>
            </template>
            <template #icon-activity_logs>
                <svg class="quick-icon quick-icon-activity" viewBox="0 0 24 24" fill="none" aria-hidden="true">
                    <path d="M4 12a8 8 0 1 0 2.3-5.6M4 5.5v3.8h3.8M12 8v4l2.8 2.4" stroke="#7c3aed" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" />
                </svg>
            </template>
        </QuickActionsRail>

        <p v-if="quickActionMessage" class="quick-message">{{ quickActionMessage }}</p>

    </section>

    <div v-else-if="loading" class="loading-card">
        <div class="loading-spinner"></div>
        <p>A carregar ticket...</p>
    </div>
    <p v-else class="error-banner">{{ error || 'Ticket nÃ£o encontrado.' }}</p>
</template>

<style scoped>
.ticket-workspace {
    display: grid;
    gap: 0.9rem;
}

.ticket-header {
    display: grid;
    grid-template-columns: minmax(0, 1fr) auto minmax(0, 1fr);
    align-items: center;
    gap: 0.8rem;
    background: #fff;
    border: 1px solid #d8e1ed;
    border-radius: 14px;
    padding: 0.75rem 0.85rem;
}

.header-left {
    min-width: 0;
}

.back-link {
    display: inline-flex;
    align-items: center;
    gap: 0.35rem;
    font-size: 0.82rem;
    color: #4b5563;
    text-decoration: none;
}

.back-icon {
    width: 20px;
    height: 20px;
    border-radius: 999px;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    background: #eef2f7;
    color: #334155;
    transition: background-color 120ms ease, color 120ms ease;
}

.back-link:hover .back-icon {
    background: #dff7ed;
    color: #1F4E79;
}

.back-icon svg {
    width: 14px;
    height: 14px;
}

.header-center {
    display: inline-flex;
    align-items: center;
    gap: 0.55rem;
    min-width: 0;
    justify-self: center;
}

.ticket-step {
    border: 1px solid #d8e1ed;
    background: #f8fafc;
    color: #64748b;
    width: 28px;
    height: 28px;
    border-radius: 8px;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    cursor: pointer;
    transition: background-color 120ms ease, border-color 120ms ease, color 120ms ease;
}

.ticket-step:hover:not(:disabled) {
    background: #e8f0fa;
    border-color: #a6dfc6;
    color: #1F4E79;
}

.ticket-step:disabled {
    opacity: 0.35;
    cursor: not-allowed;
}

.step-icon {
    width: 16px;
    height: 16px;
}

.ticket-title {
    margin: 0;
    display: inline-flex;
    gap: 0.45rem;
    align-items: center;
    min-width: 0;
    max-width: min(52vw, 700px);
}

.ticket-title strong {
    font-size: 1.04rem;
    white-space: nowrap;
}

.ticket-title span {
    color: #0f172a;
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
}

.header-actions {
    display: flex;
    align-items: center;
    gap: 0.45rem;
    flex-wrap: wrap;
    justify-self: end;
}

.pin-header-btn {
    display: inline-flex;
    align-items: center;
    gap: 0.38rem;
    color: #475569;
}

.pin-header-btn .pin-icon {
    width: 16px;
    height: 16px;
    transition: transform 0.18s ease;
}

.pin-header-btn.is-pinned {
    border-color: #a5d8bf;
    background: #eaf9f1;
    color: #0f8f62;
}

.pin-header-btn.is-pinned .pin-icon {
    transform: rotate(-16deg);
}

.status-split {
    position: relative;
    display: inline-flex;
    align-items: stretch;
    border-radius: 9px;
    overflow: visible;
    box-shadow: 0 1px 3px rgba(0, 100, 60, 0.18);
}

.status-main {
    border-top-right-radius: 0;
    border-bottom-right-radius: 0;
    border-right: none;
    display: inline-flex;
    align-items: center;
    gap: 0.38rem;
}

.status-main svg {
    width: 13px;
    height: 13px;
    flex-shrink: 0;
    opacity: 0.9;
}

.status-main:hover:not(:disabled) {
    background: #19a065;
    border-color: #19a065;
}

.status-main:disabled {
    opacity: 0.6;
    cursor: not-allowed;
}

.status-toggle {
    border-top-left-radius: 0;
    border-bottom-left-radius: 0;
    border-left: 1px solid rgba(255, 255, 255, 0.3);
    width: 34px;
    padding: 0;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    flex-shrink: 0;
}

.status-toggle svg {
    width: 12px;
    height: 12px;
}

.status-toggle:hover {
    background: #19a065;
    border-color: #19a065;
    border-left-color: rgba(255, 255, 255, 0.3);
}

.status-toggle.is-standalone {
    border-radius: 9px;
    border-left-color: #1fb873;
    width: auto;
    padding: 0.48rem 0.7rem;
    gap: 0.35rem;
    display: inline-flex;
    align-items: center;
}

.status-menu {
    position: absolute;
    right: 0;
    top: calc(100% + 0.35rem);
    min-width: 180px;
    background: #fff;
    border: 1px solid #d4dde8;
    border-radius: 10px;
    box-shadow: 0 8px 22px rgba(15, 23, 42, 0.12);
    padding: 0.3rem;
    display: grid;
    gap: 0.2rem;
    z-index: 30;
}

.status-option {
    border: 1px solid transparent;
    background: #fff;
    color: #0f172a;
    text-align: left;
    border-radius: 8px;
    padding: 0.42rem 0.6rem;
    font: inherit;
    font-size: 0.86rem;
    font-weight: 500;
    cursor: pointer;
    display: flex;
    align-items: center;
    gap: 0.5rem;
    transition: background 80ms;
}

.status-option:hover { background: #f1f5f9; border-color: #e2e8f0; }

.status-option-dot {
    width: 8px;
    height: 8px;
    border-radius: 50%;
    flex-shrink: 0;
    background: #94a3b8;
}

.status-option--open          .status-option-dot { background: #16a34a; }
.status-option--in_progress   .status-option-dot { background: #2563eb; }
.status-option--pending       .status-option-dot { background: #d97706; }
.status-option--closed        .status-option-dot { background: #64748b; }
.status-option--cancelled     .status-option-dot { background: #dc2626; }

.btn-success,
.btn-ghost,
.btn-primary,
.btn-send {
    border-radius: 9px;
    border: 1px solid #d4dde8;
    font: inherit;
    cursor: pointer;
    text-decoration: none;
}

.btn-success {
    background: #1fb873;
    border-color: #1fb873;
    color: #fff;
    padding: 0.48rem 0.78rem;
    font-weight: 600;
    font-size: 0.88rem;
    transition: background 110ms, border-color 110ms;
}

.btn-success:hover:not(:disabled) {
    background: #19a065;
    border-color: #19a065;
}

.btn-ghost {
    background: #fff;
    color: #0f172a;
    padding: 0.47rem 0.73rem;
}

.btn-primary {
    background: #1F4E79;
    color: #fff;
    border-color: #1F4E79;
    padding: 0.5rem 0.7rem;
}

.error-banner {
    margin: 0;
    border: 1px solid #fecaca;
    border-radius: 10px;
    background: #fef2f2;
    color: #991b1b;
    padding: 0.6rem 0.7rem;
}

.loading-card {
    display: flex;
    flex-direction: column;
    align-items: center;
    justify-content: center;
    gap: 0.9rem;
    min-height: 220px;
    background: #fff;
    border: 1px solid #d8e1ed;
    border-radius: 14px;
    color: #475569;
    font-size: 0.9rem;
}

.loading-card p {
    margin: 0;
}

.loading-spinner {
    width: 30px;
    height: 30px;
    border: 3px solid #d8e9f5;
    border-top-color: #1F4E79;
    border-radius: 50%;
    animation: showSpinnerRotate 0.7s linear infinite;
}

@keyframes showSpinnerRotate {
    to { transform: rotate(360deg); }
}

.quick-message {
    position: fixed;
    right: 1.6rem;
    top: calc(50% + 160px);
    margin: 0;
    z-index: 66;
    border: 1px solid #9ab9d8;
    background: #EDF3FA;
    color: #0d704e;
    border-radius: 8px;
    padding: 0.34rem 0.5rem;
    font-size: 0.82rem;
}

.quick-icon {
    filter: drop-shadow(0 0 2px rgba(255, 255, 255, 0.85));
}

.quick-icon-details {
    filter: drop-shadow(0 0 2px rgba(255, 255, 255, 0.9)) drop-shadow(0 0 4px rgba(37, 99, 235, 0.55));
}

.quick-icon-reply {
    filter: drop-shadow(0 0 2px rgba(255, 255, 255, 0.9)) drop-shadow(0 0 4px rgba(8, 145, 178, 0.55));
}

.quick-icon-activity {
    filter: drop-shadow(0 0 2px rgba(255, 255, 255, 0.9)) drop-shadow(0 0 4px rgba(124, 58, 237, 0.55));
}

.workspace-grid {
    display: grid;
    grid-template-columns: 1fr;
    gap: 0.9rem;
}

.details-panel-body {
    padding: 0.85rem;
    display: grid;
    gap: 0.65rem;
    align-content: start;
    overflow: auto;
}

.details-form {
    border: 1px solid #d8e4f2;
    border-radius: 12px;
    background: #ffffff;
    padding: 0.74rem;
    box-shadow: 0 1px 0 rgba(255, 255, 255, 0.8) inset;
}

.details-form .btn-primary {
    margin-top: 0.2rem;
}

.details-form label {
    display: grid;
    gap: 0.28rem;
    color: #334155;
    font-size: 0.83rem;
    font-weight: 600;
}

.details-form input,
.details-form select {
    border: 1px solid #cfdae8;
    border-radius: 9px;
    padding: 0.47rem 0.58rem;
    font: inherit;
    background: #fff;
    color: #0f172a;
}

.details-form select.status-select-gloss {
    border-color: #b8d2ea;
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

.details-form select.status-select-gloss:focus {
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

.details-operator-picker {
    position: relative;
    border: 1px solid #dbe4ee;
    border-radius: 10px;
    padding: 0.5rem;
    display: grid;
    gap: 0.4rem;
    background: #f8fbff;
}

.details-operator-suggestions {
    position: absolute;
    left: 0.5rem;
    right: 0.5rem;
    top: calc(100% + 0.3rem);
    z-index: 40;
    border: 1px solid #dbe4ee;
    border-radius: 8px;
    background: #fff;
    box-shadow: 0 10px 28px rgba(15, 23, 42, 0.16);
    max-height: 220px;
    overflow: auto;
}

.details-operator-option {
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

.details-operator-option:last-child {
    border-bottom: 0;
}

.details-operator-option:hover {
    background: #f0fdf4;
}

.details-operator-option-clear {
    background: #f8fafc;
}

.details-operator-tags {
    display: flex;
    flex-wrap: wrap;
    gap: 0.35rem;
}

.details-operator-tag {
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

.details-operator-tag button {
    border: 0;
    background: transparent;
    padding: 0;
    line-height: 1;
    cursor: pointer;
    color: #475569;
}

.details-operator-avatar {
    width: 1.35rem;
    height: 1.35rem;
    border-radius: 999px;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    background: #0f172a;
    color: #fff;
    font-size: 0.66rem;
    font-weight: 700;
}

.details-operator-avatar-clear {
    background: #64748b;
}

.details-operator-name {
    max-width: 160px;
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
}

.details-operator-meta {
    display: grid;
    gap: 0.12rem;
}

.details-operator-meta strong {
    font-size: 0.9rem;
}

.details-operator-meta small {
    color: #64748b;
    font-size: 0.77rem;
}

.details-operator-empty {
    margin: 0;
    padding: 0.6rem;
    color: #64748b;
}

.details-priority-picker {
    display: grid;
    grid-template-columns: repeat(2, minmax(0, 1fr));
    gap: 0.4rem;
}

.details-priority-btn {
    border: 1px solid #d4deea;
    border-radius: 8px;
    background: #fff;
    color: #334155;
    padding: 0.38rem 0.45rem;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    gap: 0.33rem;
    font: inherit;
    font-size: 0.79rem;
    cursor: pointer;
}

.details-priority-btn.is-selected {
    border-color: #2b5d8d;
    background: #e9fbf3;
    color: #1F4E79;
}

.details-priority-dot {
    width: 0.42rem;
    height: 0.42rem;
    border-radius: 999px;
}

.details-priority-dot.is-low { background: #3b82f6; }
.details-priority-dot.is-medium { background: #f59e0b; }
.details-priority-dot.is-high { background: #ef4444; }
.details-priority-dot.is-urgent { background: #b91c1c; }

.details-form .stack {
    gap: 0.5rem;
}

.details-block-title {
    margin: 0;
    color: #334155;
    font-size: 0.8rem;
    font-weight: 700;
}

.details-assignee-block {
    display: grid;
    gap: 0.38rem;
}

.details-assigned-card {
    display: flex;
    align-items: center;
    gap: 0.45rem;
    border: 1px solid #d8e4f2;
    border-radius: 10px;
    background: #f8fbff;
    padding: 0.45rem 0.55rem;
}

.details-assigned-meta {
    display: grid;
    min-width: 0;
}

.details-assigned-meta strong {
    font-size: 0.88rem;
    color: #0f172a;
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
}

.details-assigned-meta small {
    color: #64748b;
    font-size: 0.76rem;
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
}

.details-transfer-trigger {
    width: 100%;
}

.details-followers-block {
    display: grid;
    gap: 0.42rem;
}

.details-followers-tags {
    display: flex;
    flex-wrap: wrap;
    gap: 0.35rem;
}

.details-followers-picker {
    position: relative;
    border: 1px solid #dbe4ee;
    border-radius: 10px;
    padding: 0.5rem;
    display: grid;
    gap: 0.4rem;
    background: #f8fbff;
}

.details-follower-suggestions {
    position: absolute;
    left: 0.5rem;
    right: 0.5rem;
    top: calc(100% + 0.3rem);
    z-index: 40;
    border: 1px solid #dbe4ee;
    border-radius: 8px;
    background: #fff;
    box-shadow: 0 10px 28px rgba(15, 23, 42, 0.16);
    max-height: 220px;
    overflow: auto;
}

.follower-tag small {
    color: #64748b;
    font-size: 0.74rem;
}

.details-readonly {
    border: 1px solid #e6edf6;
    border-radius: 12px;
    padding: 0.7rem;
    display: grid;
    gap: 0.35rem;
}

.details-readonly p {
    margin: 0;
    color: #334155;
    font-size: 0.88rem;
}

.conversation-card {
    border: 1px solid #d8e1ed;
    border-radius: 14px;
    background: #fff;
}

.conversation-card {
    display: grid;
    grid-template-rows: auto minmax(380px, 1fr);
    overflow: hidden;
}

.conversation-tabs {
    display: flex;
    gap: 1rem;
    border-bottom: 1px solid #e7edf6;
    padding: 0.4rem 0.9rem 0;
    background: #fcfdff;
}

.tab {
    border: none;
    background: transparent;
    color: #64748b;
    font: inherit;
    font-size: 0.9rem;
    padding: 0.55rem 0.15rem 0.5rem;
    border-bottom: 3px solid transparent;
    cursor: pointer;
}

.tab.active {
    color: #1F4E79;
    border-bottom-color: #2b5d8d;
    font-weight: 600;
}

.tab:hover {
    color: #0f172a;
}

.conversation-tab-content {
    display: grid;
    grid-template-rows: minmax(320px, 1fr) auto;
    min-height: 100%;
}

.message-stream {
    padding: 0.9rem;
    display: grid;
    gap: 0.7rem;
    align-content: start;
    max-height: 58vh;
    overflow: auto;
    background: #fff;
}

.activity-stream {
    padding: 0.85rem 1rem;
    display: grid;
    gap: 0;
    align-content: start;
    max-height: 58vh;
    overflow: auto;
}

/* â”€â”€ Group divider â”€â”€ */
.act-group-divider {
    display: flex;
    align-items: center;
    gap: 0.6rem;
    margin: 0.7rem 0 0.6rem;
}

.act-group-divider::before,
.act-group-divider::after {
    content: '';
    flex: 1;
    height: 1px;
    background: #e2ebf5;
}

.act-group-divider:first-child {
    margin-top: 0;
}

.act-group-label {
    font-size: 0.72rem;
    font-weight: 700;
    color: #94a3b8;
    text-transform: uppercase;
    letter-spacing: 0.06em;
    white-space: nowrap;
}

/* â”€â”€ Activity item â”€â”€ */
.act-item {
    display: grid;
    grid-template-columns: 28px minmax(0, 1fr);
    gap: 0.7rem;
    align-items: flex-start;
    padding: 0.5rem 0;
    position: relative;
}

.act-item + .act-item::before {
    content: '';
    position: absolute;
    left: 13px;
    top: -0.25rem;
    height: 0.5rem;
    width: 1px;
    background: #dce6f0;
}

/* â”€â”€ Marker icon â”€â”€ */
.act-marker {
    width: 28px;
    height: 28px;
    border-radius: 50%;
    display: flex;
    align-items: center;
    justify-content: center;
    flex-shrink: 0;
    border: 1.5px solid;
    position: relative;
    z-index: 1;
}

.act-marker svg {
    width: 14px;
    height: 14px;
    display: block;
}

.act-ic--created  { background: #ecfdf5; border-color: #6ee7b7; color: #047857; }
.act-ic--status   { background: #eff6ff; border-color: #93c5fd; color: #1d4ed8; }
.act-ic--assign   { background: #faf5ff; border-color: #c4b5fd; color: #7c3aed; }
.act-ic--field    { background: #fefce8; border-color: #fde047; color: #a16207; }
.act-ic--message  { background: #f0f9ff; border-color: #7dd3fc; color: #0369a1; }
.act-ic--attach   { background: #fff7ed; border-color: #fdba74; color: #c2410c; }
.act-ic--default  { background: #f8fafc; border-color: #cbd5e1; color: #475569; }

/* â”€â”€ Content block â”€â”€ */
.act-content {
    display: grid;
    gap: 0.28rem;
    padding-top: 0.04rem;
}

.act-header {
    display: flex;
    align-items: baseline;
    justify-content: space-between;
    gap: 0.5rem;
    flex-wrap: wrap;
}

.act-label {
    font-size: 0.88rem;
    font-weight: 600;
    color: #0f172a;
}

.act-meta {
    display: inline-flex;
    align-items: center;
    gap: 0.3rem;
    flex-shrink: 0;
}

.act-actor {
    font-size: 0.78rem;
    color: #475569;
    font-weight: 500;
}

.act-sep {
    color: #cbd5e1;
    font-size: 0.7rem;
}

.act-time {
    font-size: 0.76rem;
    color: #94a3b8;
    font-variant-numeric: tabular-nums;
}

.act-date {
    font-size: 0.74rem;
    color: #94a3b8;
    font-variant-numeric: tabular-nums;
}

/* â”€â”€ Change row â”€â”€ */
.act-change {
    display: inline-flex;
    align-items: center;
    flex-wrap: wrap;
    gap: 0.3rem;
}

.act-field-label {
    font-size: 0.75rem;
    font-weight: 600;
    color: #64748b;
    background: #f1f5f9;
    border: 1px solid #e2e8f0;
    border-radius: 5px;
    padding: 0.06rem 0.38rem;
}

.act-val {
    font-size: 0.78rem;
    border-radius: 5px;
    padding: 0.06rem 0.42rem;
    max-width: 160px;
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
}

.act-val--old {
    background: #fef2f2;
    border: 1px solid #fecaca;
    color: #991b1b;
    text-decoration: line-through;
    opacity: 0.8;
}

.act-val--new {
    background: #f0fdf4;
    border: 1px solid #bbf7d0;
    color: #166534;
    font-weight: 600;
}

.act-arrow {
    width: 14px;
    height: 7px;
    color: #94a3b8;
    flex-shrink: 0;
}

.task-content,
.notes-content {
    padding: 1rem;
    display: flex;
    flex-direction: column;
    gap: 0.75rem;
    align-content: start;
}

/* â”€â”€ Task: no access â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€ */

.task-no-access {
    display: flex;
    flex-direction: column;
    align-items: center;
    gap: 0.5rem;
    padding: 2.5rem 1rem;
    text-align: center;
}

.task-no-access-icon {
    width: 48px;
    height: 48px;
    opacity: 0.6;
}

.task-no-access p {
    margin: 0;
    color: #64748b;
    font-size: 0.9rem;
}

/* â”€â”€ Task card â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€ */

.task-card {
    background: #fff;
    border: 1px solid #e2ecf5;
    border-radius: 14px;
    padding: 0.85rem 0.9rem;
    display: flex;
    flex-direction: column;
    gap: 0.7rem;
}

.task-card-header {
    display: flex;
    align-items: center;
    gap: 0.5rem;
}

.task-card-icon {
    width: 28px;
    height: 28px;
    border-radius: 8px;
    display: flex;
    align-items: center;
    justify-content: center;
    flex-shrink: 0;
}

.task-card-icon svg {
    width: 15px;
    height: 15px;
}

.task-card-icon--status { background: #eff6ff; color: #2563eb; }
.task-card-icon--assign { background: #f5f3ff; color: #7c3aed; }
.task-card-icon--meta   { background: #f0fdf4; color: #16a34a; }

.task-card-title {
    margin: 0;
    font-size: 0.88rem;
    font-weight: 700;
    color: #1e293b;
}

/* â”€â”€ Status pills â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€ */

.task-status-pills {
    display: flex;
    flex-wrap: wrap;
    gap: 0.4rem;
}

.task-status-pill {
    display: inline-flex;
    align-items: center;
    gap: 0.35rem;
    border-radius: 999px;
    border: 1.5px solid #d1dce8;
    background: #f8fafc;
    color: #475569;
    font: inherit;
    font-size: 0.82rem;
    font-weight: 600;
    padding: 0.28rem 0.75rem 0.28rem 0.55rem;
    cursor: pointer;
    transition: border-color 110ms, background 110ms, color 110ms;
}

.task-status-pill:disabled {
    opacity: 0.55;
    cursor: not-allowed;
}

.task-status-dot {
    width: 8px;
    height: 8px;
    border-radius: 50%;
    background: currentColor;
    flex-shrink: 0;
    opacity: 0.5;
}

.task-status-check {
    width: 12px;
    height: 12px;
    flex-shrink: 0;
}

/* Status colours */
.task-status-pill.is-open:not(.is-current)           { color: #15803d; border-color: #bbf7d0; background: #f0fdf4; }
.task-status-pill.is-open.is-current                 { background: #dcfce7; border-color: #16a34a; color: #15803d; }
.task-status-pill.is-in-progress:not(.is-current)    { color: #1d4ed8; border-color: #bfdbfe; background: #eff6ff; }
.task-status-pill.is-in-progress.is-current          { background: #dbeafe; border-color: #2563eb; color: #1d4ed8; }
.task-status-pill.is-pending:not(.is-current)        { color: #6d28d9; border-color: #ddd6fe; background: #faf5ff; }
.task-status-pill.is-pending.is-current              { background: #ede9fe; border-color: #7c3aed; color: #6d28d9; }
.task-status-pill.is-closed:not(.is-current)         { color: #0f766e; border-color: #99f6e4; background: #f0fdfa; }
.task-status-pill.is-closed.is-current               { background: #ccfbf1; border-color: #0f766e; color: #0f766e; }
.task-status-pill.is-cancelled:not(.is-current)      { color: #b91c1c; border-color: #fecaca; background: #fff5f5; }
.task-status-pill.is-cancelled.is-current            { background: #fee2e2; border-color: #dc2626; color: #b91c1c; }

.task-saving-hint {
    display: flex;
    align-items: center;
    gap: 0.4rem;
    margin: 0;
    font-size: 0.78rem;
    color: #64748b;
}

/* â”€â”€ Assign form â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€ */

.task-assign-row {
    display: flex;
    gap: 0.5rem;
    align-items: center;
}

/* â”€â”€ Meta form â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€ */

.task-meta-form {
    display: flex;
    flex-direction: column;
    gap: 0.7rem;
}

.task-field {
    display: flex;
    flex-direction: column;
    gap: 0.3rem;
}

.task-field-row {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 0.65rem;
}

.task-field-label {
    font-size: 0.78rem;
    font-weight: 700;
    color: #4a6785;
    display: flex;
    align-items: baseline;
    gap: 0.35rem;
}

.task-field-hint {
    font-size: 0.72rem;
    font-weight: 400;
    color: #94a3b8;
}

.task-input {
    border: 1px solid #d4e0ee;
    border-radius: 9px;
    padding: 0.42rem 0.6rem;
    font: inherit;
    font-size: 0.85rem;
    color: #0f172a;
    background: #fafcff;
    width: 100%;
}

.task-input:focus {
    outline: none;
    border-color: #7ab0d8;
    box-shadow: 0 0 0 3px rgba(59, 130, 246, 0.1);
}

.task-select {
    flex: 1;
    border: 1px solid #d4e0ee;
    border-radius: 9px;
    padding: 0.42rem 0.6rem;
    font: inherit;
    font-size: 0.85rem;
    color: #0f172a;
    background: #fafcff;
    cursor: pointer;
}

.task-select:focus {
    outline: none;
    border-color: #7ab0d8;
    box-shadow: 0 0 0 3px rgba(59, 130, 246, 0.1);
}

/* â”€â”€ Priority pills â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€ */

.task-priority-pills,
.task-type-pills {
    display: flex;
    flex-wrap: wrap;
    gap: 0.28rem;
}

.task-priority-pill,
.task-type-pill {
    border: 1.5px solid #d4dce8;
    border-radius: 999px;
    background: #f8fafc;
    color: #475569;
    font: inherit;
    font-size: 0.78rem;
    font-weight: 600;
    padding: 0.2rem 0.6rem;
    cursor: pointer;
    transition: background 100ms, border-color 100ms, color 100ms;
}

.task-priority-pill.is-low.is-active    { background: #f0fdf4; border-color: #4ade80; color: #15803d; }
.task-priority-pill.is-low:hover        { border-color: #86efac; color: #15803d; }
.task-priority-pill.is-medium.is-active { background: #fefce8; border-color: #fbbf24; color: #854d0e; }
.task-priority-pill.is-medium:hover     { border-color: #fbbf24; color: #854d0e; }
.task-priority-pill.is-high.is-active   { background: #fff7ed; border-color: #f97316; color: #9a3412; }
.task-priority-pill.is-high:hover       { border-color: #f97316; color: #9a3412; }
.task-priority-pill.is-urgent.is-active { background: #fef2f2; border-color: #f87171; color: #991b1b; }
.task-priority-pill.is-urgent:hover     { border-color: #f87171; color: #991b1b; }

.task-type-pill.is-active { background: #eff6ff; border-color: #3b82f6; color: #1d4ed8; }
.task-type-pill:hover     { border-color: #93c5fd; color: #1d4ed8; }

/* â”€â”€ Followers â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€ */

.task-followers {
    display: flex;
    flex-direction: column;
    gap: 0.4rem;
}

.task-followers-selected {
    display: flex;
    flex-wrap: wrap;
    gap: 0.3rem;
}

.task-follower-chip {
    display: inline-flex;
    align-items: center;
    gap: 0.25rem;
    background: #e8f2fc;
    border: 1px solid #b8d5f0;
    border-radius: 999px;
    padding: 0.16rem 0.4rem 0.16rem 0.55rem;
    font-size: 0.78rem;
    font-weight: 600;
    color: #1e3a5f;
}

.task-follower-remove {
    display: flex;
    align-items: center;
    justify-content: center;
    width: 15px;
    height: 15px;
    border-radius: 50%;
    border: none;
    background: #c5dff5;
    color: #2a5a8a;
    cursor: pointer;
    padding: 0;
}

.task-follower-remove svg {
    width: 7px;
    height: 7px;
}

.task-followers-search {
    width: 100%;
}

.task-followers-list {
    border: 1px solid #d4e0ee;
    border-radius: 9px;
    background: #fff;
    overflow: hidden;
    max-height: 180px;
    overflow-y: auto;
}

.task-follower-row {
    display: flex;
    align-items: center;
    gap: 0.5rem;
    padding: 0.38rem 0.6rem;
    cursor: pointer;
    border-bottom: 1px solid #f0f6fb;
    transition: background 80ms;
}

.task-follower-row:last-child {
    border-bottom: none;
}

.task-follower-row:hover {
    background: #f5f9ff;
}

.task-follower-row input[type="checkbox"] {
    width: auto;
    flex-shrink: 0;
}

.task-follower-name {
    font-size: 0.84rem;
    font-weight: 600;
    color: #1e293b;
    flex: 1;
}

.task-follower-role {
    font-size: 0.73rem;
    color: #64748b;
    background: #f1f5f9;
    border-radius: 999px;
    padding: 0.06rem 0.42rem;
}

.task-followers-empty {
    margin: 0;
    padding: 0.5rem 0.6rem;
    font-size: 0.82rem;
    color: #94a3b8;
    font-style: italic;
}

/* â”€â”€ Submit button â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€ */

.task-submit-btn {
    display: inline-flex;
    align-items: center;
    gap: 0.35rem;
    border: 1px solid #1F4E79;
    background: #1F4E79;
    color: #fff;
    border-radius: 9px;
    padding: 0.42rem 0.8rem;
    font: inherit;
    font-size: 0.84rem;
    font-weight: 600;
    cursor: pointer;
    white-space: nowrap;
    transition: background 110ms, border-color 110ms;
    flex-shrink: 0;
}

.task-submit-btn svg {
    width: 14px;
    height: 14px;
    flex-shrink: 0;
}

.task-submit-btn:hover:not(:disabled) {
    background: #163d60;
    border-color: #163d60;
}

.task-submit-btn:disabled {
    opacity: 0.55;
    cursor: not-allowed;
}

.task-submit-btn--full {
    width: 100%;
    justify-content: center;
}

.task-meta-footer {
    padding-top: 0.3rem;
    border-top: 1px solid #e8f0f8;
}

/* â”€â”€ Spinner â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€ */

.task-spinner {
    display: inline-block;
    width: 16px;
    height: 16px;
    border: 2px solid rgba(255,255,255,0.35);
    border-top-color: #fff;
    border-radius: 50%;
    animation: task-spin 0.65s linear infinite;
    flex-shrink: 0;
}

.task-spinner--sm {
    width: 12px;
    height: 12px;
    border-width: 1.5px;
}

@keyframes task-spin {
    to { transform: rotate(360deg); }
}

/* saving hint spinner (dark bg) */
.task-saving-hint .task-spinner {
    border-color: #c5d8ea;
    border-top-color: #1F4E79;
}

.note-form {
    border: 1px solid #cfd8e3;
    border-radius: 16px;
    background: #fff;
    padding: 0.65rem 0.8rem;
    position: relative;
    width: min(920px, 100%);
    margin: 0 auto;
}

.note-form textarea {
    border: none;
    min-height: 64px;
    resize: vertical;
    font: inherit;
    color: #1e293b;
    padding: 0.15rem 7.5rem 0.15rem 0.05rem;
    line-height: 1.4;
    width: 100%;
}

.note-form textarea:focus {
    outline: none;
}

.btn-send-note {
    position: absolute;
    right: 0.75rem;
    bottom: 0.65rem;
    border-radius: 9px;
    border: 1px solid #0f172a;
    background: #0f172a;
    color: #fff;
    padding: 0.4rem 0.9rem;
    cursor: pointer;
    font: inherit;
    font-size: 0.9rem;
}

.btn-send-note:disabled {
    opacity: 0.6;
    cursor: not-allowed;
}

.notes-muted {
    margin: 0;
    color: #64748b;
    font-size: 0.9rem;
}

.note-row {
    display: grid;
    grid-template-columns: 34px minmax(0, 1fr);
    gap: 0.65rem;
    padding: 0.45rem 0.1rem;
    border-bottom: 1px solid #e8edf5;
}

.note-avatar {
    width: 34px;
    height: 34px;
    border-radius: 999px;
    background: #1f2a44;
    color: #fff;
    display: grid;
    place-items: center;
    font-weight: 700;
    font-size: 0.82rem;
}

.note-item {
    display: grid;
    gap: 0.22rem;
    align-content: start;
}

.note-head {
    display: inline-flex;
    align-items: center;
    gap: 0.4rem;
}

.note-head strong {
    font-size: 0.92rem;
    color: #0f172a;
}

.note-head small {
    color: #64748b;
    font-size: 0.8rem;
}

.note-item p {
    margin: 0;
    white-space: pre-wrap;
    color: #111827;
    font-size: 0.9rem;
    line-height: 1.35;
}

@media (max-width: 760px) {
    .note-form {
        padding: 0.6rem 0.65rem;
    }

    .note-form textarea {
        min-height: 56px;
        padding-right: 0.1rem;
        margin-bottom: 0.5rem;
    }

    .btn-send-note {
        position: static;
        justify-self: end;
    }
}

/* ── Chat messages ───────────────────────────────────────── */

.message-row {
    display: flex;
    align-items: flex-end;
    gap: 0.5rem;
}

/* Operator messages → avatar on the right */
.message-row.is-operator {
    flex-direction: row-reverse;
}

.msg-avatar {
    width: 30px;
    height: 30px;
    border-radius: 50%;
    display: grid;
    place-items: center;
    background: #1f2a44;
    color: #fff;
    font-weight: 700;
    font-size: 0.75rem;
    flex-shrink: 0;
    align-self: flex-end;
    margin-bottom: 2px;
}

.bubble {
    border: 1px solid #e5edf7;
    border-radius: 4px 14px 14px 14px;
    background: #fafcff;
    padding: 0.55rem 0.75rem 0.4rem;
    display: grid;
    gap: 0.28rem;
    max-width: 72%;
    min-width: 60px;
}

/* Operator bubble — right side, blue tint */
.message-row.is-operator .bubble {
    border-radius: 14px 4px 14px 14px;
    background: #edf3fa;
    border-color: #9ab9d8;
}

/* Own message (current user) — slightly stronger tint */
.message-row.is-mine .bubble {
    background: #e0edf8;
    border-color: #7aadd4;
}

.bubble.internal {
    background: #fff7ed;
    border-color: #fed7aa;
}

.bubble-author {
    font-size: 0.74rem;
    font-weight: 700;
    color: #1F4E79;
    letter-spacing: 0.01em;
}

.bubble-time {
    font-size: 0.7rem;
    color: #94a3b8;
    text-align: right;
    margin-top: 0.05rem;
}

.message-body {
    margin: 0;
    color: #1e293b;
    font-size: 0.9rem;
    line-height: 1.5;
}

.message-body :deep(p) {
    margin: 0 0 0.35rem;
}

.message-body :deep(p:last-child) {
    margin-bottom: 0;
}

.message-body :deep(ul),
.message-body :deep(ol) {
    margin: 0.35rem 0 0.35rem 1.2rem;
    padding: 0;
}

.message-body :deep(blockquote) {
    margin: 0.35rem 0;
    padding-left: 0.7rem;
    border-left: 3px solid #cbd5e1;
    color: #475569;
}

.message-body :deep(pre) {
    background: #f1f5f9;
    border-radius: 10px;
    padding: 0.6rem;
    overflow: auto;
    font-family: ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, "Liberation Mono", "Courier New", monospace;
    font-size: 0.85rem;
}

.message-body :deep(code) {
    background: #eef2f7;
    border-radius: 6px;
    padding: 0.05rem 0.35rem;
    font-family: ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, "Liberation Mono", "Courier New", monospace;
    font-size: 0.85rem;
}

.internal-tag {
    display: inline-flex;
    width: fit-content;
    padding: 0.15rem 0.5rem;
    border-radius: 999px;
    border: 1px solid #fdba74;
    color: #9a3412;
    background: #ffedd5;
    font-size: 0.74rem;
}

.attachment-cards {
    display: grid;
    gap: 0.3rem;
    margin-top: 0.15rem;
}

.attachment-card {
    display: flex;
    align-items: center;
    gap: 0.5rem;
    border: 1px solid #d4e2f0;
    border-radius: 9px;
    background: #f4f8fd;
    padding: 0.38rem 0.55rem;
    text-decoration: none;
    color: #1e293b;
    transition: background 120ms, border-color 120ms;
}

.attachment-card:hover {
    background: #e8f1fa;
    border-color: #a8c5e0;
}

.att-file-icon {
    width: 20px;
    height: 20px;
    flex-shrink: 0;
    color: #1F4E79;
    opacity: 0.8;
}

.att-info {
    flex: 1 1 0;
    min-width: 0;
    display: grid;
    gap: 0.05rem;
}

.att-name {
    font-size: 0.8rem;
    font-weight: 600;
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
    color: #1F4E79;
}

.att-size {
    font-size: 0.72rem;
    color: #64748b;
}

.att-dl-icon {
    width: 16px;
    height: 16px;
    flex-shrink: 0;
    color: #64748b;
    opacity: 0.7;
    transition: opacity 120ms;
}

.attachment-card:hover .att-dl-icon {
    opacity: 1;
    color: #1F4E79;
}

.empty-row {
    margin: 0;
    color: #64748b;
}

.chat-note-hint {
    margin: 0;
    color: #64748b;
    font-size: 0.84rem;
}

.internal-check {
    display: inline-flex;
    align-items: center;
    gap: 0.4rem;
    color: #334155;
    font-size: 0.86rem;
}

.internal-check input {
    width: auto;
}

.staged-list {
    margin-top: 0;
}

.stack {
    display: grid;
    gap: 0.46rem;
}

.stack label {
    display: grid;
    gap: 0.28rem;
    color: #334155;
    font-size: 0.88rem;
}

.stack input,
.stack select {
    border: 1px solid #d7e0ea;
    border-radius: 9px;
    padding: 0.47rem 0.55rem;
    font: inherit;
}

.followers-picker {
    border: 1px solid #d7e0ea;
    border-radius: 10px;
    padding: 0.55rem;
    display: grid;
    gap: 0.45rem;
    background: #f8fbff;
}

.followers-list {
    border: 1px solid #dbe4ee;
    border-radius: 8px;
    background: #fff;
    max-height: 180px;
    overflow: auto;
}

.follower-item {
    display: grid;
    grid-template-columns: auto 1fr auto;
    align-items: center;
    gap: 0.4rem;
    padding: 0.36rem 0.5rem;
    border-bottom: 1px solid #eef2f7;
}

.follower-item:last-child {
    border-bottom: 0;
}

.follower-item small {
    color: #64748b;
}

.followers-empty {
    margin: 0;
    padding: 0.6rem;
    color: #64748b;
}

.followers-tags {
    display: flex;
    flex-wrap: wrap;
    gap: 0.35rem;
}

.follower-tag {
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

.follower-tag button {
    border: 0;
    background: transparent;
    padding: 0;
    line-height: 1;
    cursor: pointer;
    color: #475569;
}

@media (max-width: 1200px) {
    .message-stream {
        max-height: none;
    }
}

/* â”€â”€ Ticket info bar â”€â”€ */
.ticket-info-bar {
    display: flex;
    align-items: center;
    flex-wrap: wrap;
    gap: 0.45rem;
    background: #fff;
    border: 1px solid #d8e1ed;
    border-radius: 12px;
    padding: 0.6rem 0.85rem;
}

.tib-pill {
    display: inline-flex;
    align-items: center;
    border-radius: 999px;
    padding: 0.18rem 0.6rem;
    font-size: 0.78rem;
    font-weight: 600;
    border: 1px solid transparent;
}

.tib-item {
    display: inline-flex;
    align-items: center;
    gap: 0.3rem;
    font-size: 0.82rem;
    color: #334155;
    background: #f1f5f9;
    border: 1px solid #e2e8f0;
    border-radius: 999px;
    padding: 0.18rem 0.58rem;
    white-space: nowrap;
}

.tib-icon {
    width: 13px;
    height: 13px;
    flex-shrink: 0;
    opacity: 0.65;
}

.tib-avatar {
    flex-shrink: 0;
}

.tib-operator {
    background: #eef6ff;
    border-color: #b9d5f0;
    color: #1F4E79;
}

.tib-date {
    color: #64748b;
    background: #f8fafc;
}

/* Status/priority pills (shared with list page â€” redefine here for info bar) */
.tib-pill.status-open       { color: #1f4e79; background: #edf4fb; border-color: #b9cde4; }
.tib-pill.status-in_progress{ color: #1d4ed8; background: #dbeafe; border-color: #93c5fd; }
.tib-pill.status-pending    { color: #854d0e; background: #fef3c7; border-color: #fcd34d; }
.tib-pill.status-closed     { color: #1F4E79; background: #e7f0fa; border-color: #bfd5eb; }
.tib-pill.status-cancelled  { color: #991b1b; background: #fee2e2; border-color: #fca5a5; }
.tib-pill.priority-low      { color: #1f4e79; background: #edf4fb; border-color: #bbf7d0; }
.tib-pill.priority-medium   { color: #854d0e; background: #fef3c7; border-color: #fde68a; }
.tib-pill.priority-high     { color: #991b1b; background: #fee2e2; border-color: #fecaca; }
.tib-pill.priority-urgent   { color: #7f1d1d; background: #fee2e2; border-color: #fca5a5; }
.tib-pill.type-pill         { color: #334155; background: #f1f5f9; border-color: #dbe4ee; }

/* â”€â”€ Tab icons â”€â”€ */
.tab {
    display: inline-flex;
    align-items: center;
    gap: 0.36rem;
}

.tab-icon {
    width: 15px;
    height: 15px;
    flex-shrink: 0;
    opacity: 0.7;
}

.tab.active .tab-icon {
    opacity: 1;
}

.tab-badge {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    min-width: 18px;
    height: 18px;
    border-radius: 999px;
    background: #fef3c7;
    border: 1px solid #fde68a;
    color: #854d0e;
    font-size: 0.7rem;
    font-weight: 700;
    padding: 0 0.26rem;
    line-height: 1;
}

/* â”€â”€ Stream empty states â”€â”€ */
.stream-empty {
    display: flex;
    flex-direction: column;
    align-items: center;
    justify-content: center;
    padding: 2.4rem 1rem;
    gap: 0.65rem;
}

.stream-empty-icon {
    width: 52px;
    height: 52px;
    display: block;
}

.stream-empty p {
    margin: 0;
    color: #64748b;
    font-size: 0.88rem;
    text-align: center;
}

/* â”€â”€ Chat note hint â”€â”€ */
.chat-note-hint {
    margin: 0;
    padding: 0.5rem 0.7rem;
    color: #854d0e;
    background: #fef9ec;
    border: 1px solid #fde68a;
    border-radius: 8px;
    font-size: 0.82rem;
    display: flex;
    align-items: center;
    gap: 0.38rem;
}

.hint-icon {
    width: 15px;
    height: 15px;
    flex-shrink: 0;
    color: #b45309;
}

/* â”€â”€ Composer rico â”€â”€ */
.composer {
    border-top: 1px solid #e7edf6;
    padding: 0.8rem;
    background: #fff;
}

.composer-body {
    border: 1px solid #c8d8eb;
    border-radius: 14px 14px 0 0;
    border-bottom: none;
    background: #fff;
    transition: border-color 140ms, box-shadow 140ms;
}

.composer:focus-within .composer-body {
    border-color: #8bb8df;
}

.composer-editor {
    min-height: 100px;
    padding: 0.7rem 0.85rem 0.5rem;
    font: inherit;
    font-size: 0.92rem;
    color: #1e293b;
    width: 100%;
    display: block;
    line-height: 1.5;
    word-break: break-word;
    overflow-wrap: anywhere;
    outline: none;
}

.composer-editor:empty::before {
    content: attr(data-placeholder);
    color: #94a3b8;
    pointer-events: none;
}

.staged-files {
    margin: 0;
    padding: 0.35rem 0.75rem 0.5rem;
    border-top: 1px dashed #dce8f3;
    display: flex;
    flex-wrap: wrap;
    gap: 0.3rem;
    list-style: none;
    background: transparent;
}

.staged-file {
    display: inline-flex;
    align-items: center;
    gap: 0.28rem;
    font-size: 0.77rem;
    color: #334155;
    background: #f1f8ff;
    border: 1px solid #bdd4eb;
    border-radius: 6px;
    padding: 0.15rem 0.4rem;
}

.staged-file-icon {
    width: 11px;
    height: 11px;
    opacity: 0.65;
}

.staged-file-size {
    color: #64748b;
}

/* unified bottom bar: toolbar + upload + send */
.composer-actions {
    display: flex;
    align-items: center;
    gap: 0.4rem;
    border: 1px solid #c8d8eb;
    border-top: 1px solid #dce8f3;
    border-radius: 0 0 14px 14px;
    padding: 0.4rem 0.6rem;
    background: #f6faff;
    flex-wrap: wrap;
}

.composer:focus-within .composer-actions {
    border-color: #8bb8df;
    border-top-color: #c8d8eb;
}

.composer-toolbar {
    display: flex;
    align-items: center;
    gap: 0.15rem;
    flex-wrap: wrap;
}

.composer-tool {
    border: none;
    border-radius: 7px;
    background: transparent;
    color: #475569;
    padding: 0.22rem 0.3rem;
    cursor: pointer;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    width: 28px;
    height: 28px;
    transition: background 100ms, color 100ms;
}

.composer-tool:hover {
    background: #e2edf8;
    color: #1F4E79;
}

.composer-tool svg {
    width: 15px;
    height: 15px;
    stroke: currentColor;
    display: block;
}

.composer-tool-sep {
    width: 1px;
    height: 18px;
    background: #d0dde9;
    margin: 0 0.2rem;
    flex-shrink: 0;
}

.composer-actions-right {
    display: flex;
    align-items: center;
    gap: 0.45rem;
    margin-left: auto;
}

.upload-label {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    width: 30px;
    height: 30px;
    border-radius: 8px;
    border: 1px solid #d0dce9;
    background: #fff;
    color: #475569;
    cursor: pointer;
    transition: background 120ms, border-color 120ms, color 120ms;
}

.upload-label:hover {
    background: #e8f1fa;
    border-color: #9dc0e4;
    color: #1F4E79;
}

.upload-icon {
    width: 15px;
    height: 15px;
    flex-shrink: 0;
    display: block;
}

.upload-label input {
    display: none;
}

.btn-send {
    display: inline-flex;
    align-items: center;
    gap: 0.38rem;
    background: #1F4E79;
    border: 1px solid #1F4E79;
    border-radius: 9px;
    color: #fff;
    padding: 0.4rem 0.9rem;
    font: inherit;
    font-size: 0.86rem;
    font-weight: 600;
    cursor: pointer;
    transition: background 120ms;
}

.btn-send:hover:not(:disabled) {
    background: #17406a;
}

.btn-send:disabled {
    opacity: 0.55;
    cursor: not-allowed;
}

.send-icon {
    width: 13px;
    height: 13px;
    flex-shrink: 0;
}

@media (max-width: 640px) {
    .ticket-info-bar {
        display: none;
    }
}

@media (max-width: 640px) {
    .ticket-header {
        grid-template-columns: 1fr;
        align-items: stretch;
    }

    .header-actions {
        width: 100%;
        justify-self: stretch;
    }

    .header-center {
        justify-self: stretch;
        justify-content: center;
    }

    .ticket-title {
        max-width: calc(100vw - 170px);
    }

    .btn-success,
    .btn-ghost {
        width: 100%;
        justify-content: center;
        text-align: center;
    }

    .status-split {
        width: 100%;
    }

    .status-main {
        flex: 1;
    }

    .btn-send {
        margin-left: 0;
        width: 100%;
    }

    .quick-message {
        position: static;
        width: fit-content;
    }

}
</style>


