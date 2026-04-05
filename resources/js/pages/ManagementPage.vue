<script setup>
import { computed, nextTick, onBeforeUnmount, onMounted, reactive, ref, watch } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import api from '../api/client';

const route = useRoute();
const router = useRouter();

const loading = ref(true);
const error = ref('');
const success = ref('');
const allowedTabs = ['inboxes', 'entities', 'contacts', 'notifications', 'logs'];
const normalizeTab = (tab) => (allowedTabs.includes(tab) ? tab : 'inboxes');
const activeTab = ref(normalizeTab(typeof route.query.tab === 'string' ? route.query.tab : 'inboxes'));

const inboxes = ref([]);
const entities = ref([]);
const contacts = ref([]);
const contactFunctions = ref([]);
const users = ref([]);
const logs = ref([]);
const notificationTemplates = ref([]);
const notificationPlaceholders = ref([]);
const notificationCarouselIndex = ref(0);
const showEmailStylePanel = ref(false);
const savingNotificationEventKey = ref('');
const savingEmailStyle = ref(false);
const emailStyle = reactive({
    brand_name: 'Supportdesk',
    header_background: '#1F4E79',
    accent_color: '#1F4E79',
    button_text: 'Aceder ao ticket',
    footer_text: 'Mensagem automática enviada pelo Supportdesk.',
    show_ticket_link: true,
});
const emailStyleDefaults = reactive({
    brand_name: 'Supportdesk',
    header_background: '#1F4E79',
    accent_color: '#1F4E79',
    button_text: 'Aceder ao ticket',
    footer_text: 'Mensagem automática enviada pelo Supportdesk.',
    show_ticket_link: true,
});

const inboxForm = reactive({ name: '', is_active: true });
const inboxEditForm = reactive({
    name: '',
    is_active: true,
    operator_ids: [],
});
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
const contactEditForm = reactive({
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
const showInboxEditModal = ref(false);
const showEntityCreateModal = ref(false);
const showEntityEditModal = ref(false);
const showContactCreateModal = ref(false);
const showContactEditModal = ref(false);
const inboxOperatorSearch = ref('');
const inboxOperatorDropdownOpen = ref(false);
const contactEntitySearch = ref('');
const contactEntityDropdownOpen = ref(false);
const contactEditEntitySearch = ref('');
const contactEditEntityDropdownOpen = ref(false);
const inboxActionsMenuOpenId = ref(null);
const entityActionsMenuOpenId = ref(null);
const contactActionsMenuOpenId = ref(null);

const operatorOptions = computed(() => users.value.filter((user) => user.role === 'operator'));
const activeNotificationTemplate = computed(() => {
    const list = notificationTemplates.value;
    if (!list.length) return null;

    const safeIndex = Math.min(Math.max(notificationCarouselIndex.value, 0), list.length - 1);
    return list[safeIndex] || null;
});
const activeNotificationTemplateLabel = computed(() => {
    const template = activeNotificationTemplate.value;
    if (!template) return '';
    return notificationEventLabel[template.event_key] || template.event_key;
});
const userOptions = computed(() => users.value.filter((user) => user.role === 'client'));
const usedClientUserIds = computed(() => {
    const ids = contacts.value
        .map((contact) => Number(contact.user_id))
        .filter((id) => Number.isInteger(id) && id > 0);

    return new Set(ids);
});
const availableClientUsers = computed(() => {
    return userOptions.value.filter((user) => !usedClientUserIds.value.has(Number(user.id)));
});
const filteredEntitiesForContact = computed(() => {
    const term = contactEntitySearch.value.trim().toLowerCase();

    if (!term) {
        return entities.value;
    }

    return entities.value.filter((entity) => {
        const name = String(entity.name || '').toLowerCase();
        const tax = String(entity.tax_number || '').toLowerCase();
        return name.includes(term) || tax.includes(term);
    });
});
const selectedContactEntities = computed(() => {
    const selected = new Set((contactForm.entity_ids || []).map((id) => Number(id)));
    return entities.value.filter((entity) => selected.has(Number(entity.id)));
});
const selectedContactEntityIds = computed(() => new Set((contactForm.entity_ids || []).map((id) => Number(id))));
const contactEntityDropdownLabel = computed(() => {
    if (!selectedContactEntities.value.length) {
        return 'Selecionar entidades relacionadas';
    }

    if (selectedContactEntities.value.length === 1) {
        return selectedContactEntities.value[0].name;
    }

    return `${selectedContactEntities.value.length} entidades selecionadas`;
});
const isContactEntitySelected = (entityId) => selectedContactEntityIds.value.has(Number(entityId));
const filteredEntitiesForContactEdit = computed(() => {
    const term = contactEditEntitySearch.value.trim().toLowerCase();

    if (!term) {
        return entities.value;
    }

    return entities.value.filter((entity) => {
        const name = String(entity.name || '').toLowerCase();
        const tax = String(entity.tax_number || '').toLowerCase();
        return name.includes(term) || tax.includes(term);
    });
});
const selectedContactEditEntities = computed(() => {
    const selected = new Set((contactEditForm.entity_ids || []).map((id) => Number(id)));
    return entities.value.filter((entity) => selected.has(Number(entity.id)));
});
const selectedContactEditEntityIds = computed(() => new Set((contactEditForm.entity_ids || []).map((id) => Number(id))));
const contactEditEntityDropdownLabel = computed(() => {
    if (!selectedContactEditEntities.value.length) {
        return 'Selecionar entidades relacionadas';
    }

    if (selectedContactEditEntities.value.length === 1) {
        return selectedContactEditEntities.value[0].name;
    }

    return `${selectedContactEditEntities.value.length} entidades selecionadas`;
});
const isContactEditEntitySelected = (entityId) => selectedContactEditEntityIds.value.has(Number(entityId));
const filteredInboxOperators = computed(() => {
    const term = inboxOperatorSearch.value.trim().toLowerCase();

    if (!term) {
        return operatorOptions.value;
    }

    return operatorOptions.value.filter((operator) => {
        const name = String(operator.name || '').toLowerCase();
        const email = String(operator.email || '').toLowerCase();
        return name.includes(term) || email.includes(term);
    });
});
const selectedInboxOperators = computed(() => {
    const selected = new Set((inboxEditForm.operator_ids || []).map((id) => Number(id)));
    return operatorOptions.value.filter((operator) => selected.has(Number(operator.id)));
});
const selectedInboxOperatorIds = computed(() => new Set((inboxEditForm.operator_ids || []).map((id) => Number(id))));
const inboxOperatorDropdownLabel = computed(() => {
    if (!selectedInboxOperators.value.length) {
        return 'Selecionar operadores';
    }

    if (selectedInboxOperators.value.length === 1) {
        return selectedInboxOperators.value[0].name;
    }

    return `${selectedInboxOperators.value.length} operadores selecionados`;
});
const isInboxOperatorSelected = (operatorId) => selectedInboxOperatorIds.value.has(Number(operatorId));
const tabLabel = {
    inboxes: 'Inboxes',
    entities: 'Entidades',
    contacts: 'Contactos',
    notifications: 'Notificações',
    logs: 'Ticket logs',
};
const notificationEventLabel = {
    ticket_created: 'Ticket criado',
    ticket_replied: 'Nova resposta',
    ticket_assignment_updated: 'Atribuição atualizada',
    ticket_status_updated: 'Estado atualizado',
    ticket_knowledge_updated: 'Conhecimento atualizado',
};
const notificationPlaceholderLabelMap = Object.freeze({
    '{ticket_number}': 'Número do ticket',
    '{subject}': 'Assunto',
    '{status}': 'Estado',
    '{priority}': 'Prioridade',
    '{type}': 'Tipo',
    '{inbox}': 'Inbox',
    '{entity}': 'Entidade',
    '{contact}': 'Contacto',
    '{creator_name}': 'Criador do ticket',
    '{assigned_operator}': 'Operador atribuído',
    '{author_name}': 'Autor da mensagem',
    '{message_preview}': 'Resumo da mensagem',
    '{cc_emails}': 'Conhecimento',
    '{ticket_url}': 'Link do ticket',
});
const draggedNotificationPlaceholder = ref('');
const activeNotificationDropZone = reactive({ templateKey: '', field: '' });
const notificationBodyEditorRefs = new Map();
const notificationSubjectEditorRefs = new Map();
const draggedBodyTokenElement = ref(null);
let logSearchDebounceTimer = null;

const formatNotificationPlaceholderLabel = (placeholder) => {
    const mapped = notificationPlaceholderLabelMap[placeholder];
    if (mapped) return mapped;
    const raw = String(placeholder || '').replace(/[{}]/g, '').replaceAll('_', ' ').trim();
    if (!raw) return 'Dado automático';
    return raw.charAt(0).toUpperCase() + raw.slice(1);
};

const normalizeNotificationTemplateValue = (value) =>
    String(value ?? '')
        .replace(/\s*[\u00C3][\u00D7\u2014]/g, '')
        .replace(/\u00D7/g, '')
        .replace(/\uFFFD/g, '');

const notificationPlaceholderOptions = computed(() =>
    notificationPlaceholders.value.map((placeholder) => ({
        value: placeholder,
        label: formatNotificationPlaceholderLabel(placeholder),
    }))
);

const isNotificationDropZoneActive = (templateKey, field) =>
    draggedNotificationPlaceholder.value &&
    activeNotificationDropZone.templateKey === templateKey &&
    activeNotificationDropZone.field === field;

const escapeHtml = (value) =>
    String(value ?? '')
        .replaceAll('&', '&amp;')
        .replaceAll('<', '&lt;')
        .replaceAll('>', '&gt;')
        .replaceAll('"', '&quot;')
        .replaceAll("'", '&#39;');

const tokenizeNotificationTemplateText = (value) => {
    const text = String(value ?? '');
    const regex = /\{[a-z_]+\}/gi;
    const segments = [];
    let lastIndex = 0;
    let match;

    while ((match = regex.exec(text)) !== null) {
        const index = match.index;
        const token = match[0];

        if (index > lastIndex) {
            segments.push({ type: 'text', value: text.slice(lastIndex, index) });
        }

        segments.push({ type: 'token', value: token });
        lastIndex = index + token.length;
    }

    if (lastIndex < text.length) {
        segments.push({ type: 'text', value: text.slice(lastIndex) });
    }

    return segments;
};

const buildNotificationTokenChipHtml = (placeholder) => {
    const token = String(placeholder || '');
    const label = formatNotificationPlaceholderLabel(token);

    return `<span class="template-token-chip" contenteditable="false" draggable="true" data-token="${escapeHtml(token)}"><span class="template-token-label">${escapeHtml(label)}</span><span class="template-token-remove" data-action="remove-token" title="Remover">&times;</span></span>`;
};

const buildNotificationBodyEditorHtml = (value) => {
    const segments = tokenizeNotificationTemplateText(value);
    if (!segments.length) return '';

    return segments
        .map((segment) => {
            if (segment.type === 'token') {
                return buildNotificationTokenChipHtml(segment.value);
            }

            return escapeHtml(segment.value).replaceAll('\n', '<br>');
        })
        .join('');
};

const buildNotificationInlineEditorHtml = (value) => {
    const segments = tokenizeNotificationTemplateText(value);
    if (!segments.length) return '';

    return segments
        .map((segment) => {
            if (segment.type === 'token') {
                return buildNotificationTokenChipHtml(segment.value);
            }

            return escapeHtml(segment.value).replaceAll('\n', ' ');
        })
        .join('');
};

const getNotificationSubjectEditorRef = (templateKey) => notificationSubjectEditorRefs.get(String(templateKey || ''));
const setNotificationSubjectEditorRef = (template, element) => {
    const key = String(template?.event_key || '');
    if (!key) return;

    if (!element) {
        notificationSubjectEditorRefs.delete(key);
        return;
    }

    notificationSubjectEditorRefs.set(key, element);
    const rawValue = String(template.subject_template ?? '');
    element.innerHTML = buildNotificationInlineEditorHtml(rawValue);
    element.dataset.rawValue = rawValue;
};

const getNotificationBodyEditorRef = (templateKey) => notificationBodyEditorRefs.get(String(templateKey || ''));

const setNotificationBodyEditorRef = (template, element) => {
    const key = String(template?.event_key || '');
    if (!key) return;

    if (!element) {
        notificationBodyEditorRefs.delete(key);
        return;
    }

    notificationBodyEditorRefs.set(key, element);
    const rawValue = String(template.body_template ?? '');
    element.innerHTML = buildNotificationBodyEditorHtml(rawValue);
    element.dataset.rawValue = rawValue;
};

const serializeNotificationBodyNode = (node) => {
    if (!node) return '';

    if (node.nodeType === Node.TEXT_NODE) {
        return node.textContent || '';
    }

    if (node.nodeType !== Node.ELEMENT_NODE) {
        return '';
    }

    const element = node;
    if (element.classList.contains('template-token-chip')) {
        return String(element.dataset.token || '');
    }

    if (element.tagName === 'BR') {
        return '\n';
    }

    let text = '';
    element.childNodes.forEach((childNode) => {
        text += serializeNotificationBodyNode(childNode);
    });

    if ((element.tagName === 'DIV' || element.tagName === 'P') && !text.endsWith('\n')) {
        text += '\n';
    }

    return text;
};

const serializeNotificationBodyEditor = (editorElement) => {
    if (!editorElement) return '';

    let text = '';
    editorElement.childNodes.forEach((node) => {
        text += serializeNotificationBodyNode(node);
    });

    return text.replace(/\u00A0/g, ' ').replace(/\n{3,}/g, '\n\n').trimEnd();
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

const applyEmailStyle = (target, source = {}) => {
    target.brand_name = String(source.brand_name ?? target.brand_name ?? 'Supportdesk');
    target.header_background = String(source.header_background ?? target.header_background ?? '#1F4E79').toUpperCase();
    target.accent_color = String(source.accent_color ?? target.accent_color ?? '#1F4E79').toUpperCase();
    target.button_text = String(source.button_text ?? target.button_text ?? 'Aceder ao ticket');
    target.footer_text = String(source.footer_text ?? target.footer_text ?? 'Mensagem automática enviada pelo Supportdesk.');
    target.show_ticket_link = Boolean(source.show_ticket_link ?? target.show_ticket_link ?? true);
};

const loadNotificationTemplates = async () => {
    const selectedEventKey = activeNotificationTemplate.value?.event_key || '';
    const response = await api.get('/notification-templates');
    notificationTemplates.value = (response.data.data || []).map((template) => ({
        ...template,
        subject_template: normalizeNotificationTemplateValue(template.subject_template),
        title_template: normalizeNotificationTemplateValue(template.title_template),
        body_template: normalizeNotificationTemplateValue(template.body_template),
    }));
    if (!notificationTemplates.value.length) {
        notificationCarouselIndex.value = 0;
    } else {
        const selectedIndex = notificationTemplates.value.findIndex((template) => template.event_key === selectedEventKey);
        if (selectedIndex >= 0) {
            notificationCarouselIndex.value = selectedIndex;
        } else if (notificationCarouselIndex.value > notificationTemplates.value.length - 1) {
            notificationCarouselIndex.value = notificationTemplates.value.length - 1;
        }
    }
    notificationPlaceholders.value = response.data.meta?.placeholders || [];
    applyEmailStyle(emailStyleDefaults, response.data.meta?.email_style_defaults || {});
    applyEmailStyle(emailStyle, response.data.meta?.email_style || response.data.meta?.email_style_defaults || {});

    await nextTick();
    notificationTemplates.value.forEach((template) => {
        const subjectEditor = getNotificationSubjectEditorRef(template.event_key);
        if (subjectEditor) {
            const subjectRaw = String(template.subject_template ?? '');
            subjectEditor.innerHTML = buildNotificationInlineEditorHtml(subjectRaw);
            subjectEditor.dataset.rawValue = subjectRaw;
        }

        const editor = getNotificationBodyEditorRef(template.event_key);
        if (!editor) return;
        const rawValue = String(template.body_template ?? '');
        editor.innerHTML = buildNotificationBodyEditorHtml(rawValue);
        editor.dataset.rawValue = rawValue;
    });
};

const goToNotificationTemplateIndex = (index) => {
    const max = notificationTemplates.value.length - 1;
    if (max < 0) {
        notificationCarouselIndex.value = 0;
        return;
    }

    notificationCarouselIndex.value = Math.min(Math.max(Number(index) || 0, 0), max);
};

const goToPreviousNotificationTemplate = () => {
    if (notificationCarouselIndex.value <= 0) return;
    notificationCarouselIndex.value -= 1;
};

const goToNextNotificationTemplate = () => {
    const max = notificationTemplates.value.length - 1;
    if (notificationCarouselIndex.value >= max) return;
    notificationCarouselIndex.value += 1;
};

const loadAll = async () => {
    loading.value = true;
    error.value = '';

    try {
        await loadBaseData();
        await loadNotificationTemplates();
        await loadLogs();
        await maybeOpenEntityEditFromQuery();
    } catch (exception) {
        error.value = exception?.response?.data?.message || 'Não foi possível carregar configuração.';
    } finally {
        loading.value = false;
    }
};

const resetMessages = () => {
    error.value = '';
    success.value = '';
};

const resetInboxEditForm = () => {
    inboxEditForm.name = '';
    inboxEditForm.is_active = true;
    inboxEditForm.operator_ids = [];
    inboxOperatorSearch.value = '';
    inboxOperatorDropdownOpen.value = false;
};

const openInboxEditModal = (inbox) => {
    resetMessages();
    closeInboxActionsMenu();
    editingInboxId.value = inbox.id;
    inboxEditForm.name = inbox.name ?? '';
    inboxEditForm.is_active = Boolean(inbox.is_active);
    inboxEditForm.operator_ids = (inbox.operators || []).map((operator) => Number(operator.id));
    inboxOperatorSearch.value = '';
    inboxOperatorDropdownOpen.value = false;
    showInboxEditModal.value = true;
};

const closeInboxEditModal = () => {
    showInboxEditModal.value = false;
    editingInboxId.value = null;
    resetInboxEditForm();
};

const toggleInboxOperatorDropdown = () => {
    inboxOperatorDropdownOpen.value = !inboxOperatorDropdownOpen.value;
};

const toggleInboxOperator = (operatorId) => {
    const normalizedId = Number(operatorId);
    if (!Array.isArray(inboxEditForm.operator_ids)) {
        inboxEditForm.operator_ids = [];
    }

    if (inboxEditForm.operator_ids.includes(normalizedId)) {
        inboxEditForm.operator_ids = inboxEditForm.operator_ids.filter((id) => id !== normalizedId);
        return;
    }

    inboxEditForm.operator_ids = [...inboxEditForm.operator_ids, normalizedId];
};

const clearInboxOperatorsSelection = () => {
    inboxEditForm.operator_ids = [];
};

const closeInboxOperatorDropdownOnOutsideClick = (event) => {
    if (!inboxOperatorDropdownOpen.value) return;

    const target = event.target;
    if (!(target instanceof HTMLElement)) return;

    if (!target.closest('.inbox-operators-ddl')) {
        inboxOperatorDropdownOpen.value = false;
    }
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

const syncContactFormFromSelectedUser = () => {
    if (!contactForm.user_id) {
        return;
    }

    const selectedUser = userOptions.value.find((user) => Number(user.id) === Number(contactForm.user_id));
    if (!selectedUser) {
        return;
    }

    contactForm.name = selectedUser.name ?? '';
    contactForm.email = selectedUser.email ?? '';
};

const syncContactEditFormFromSelectedUser = () => {
    if (!contactEditForm.user_id) {
        return;
    }

    const selectedUser = userOptions.value.find((user) => Number(user.id) === Number(contactEditForm.user_id));
    if (!selectedUser) {
        return;
    }

    contactEditForm.name = selectedUser.name ?? '';
    contactEditForm.email = selectedUser.email ?? '';
};

const resetContactForm = () => {
    contactForm.entity_ids = [];
    contactForm.function_id = '';
    contactForm.user_id = '';
    contactForm.name = '';
    contactForm.email = '';
    contactForm.phone = '';
    contactForm.mobile_phone = '';
    contactForm.internal_notes = '';
    contactForm.is_active = true;
    contactEntitySearch.value = '';
    contactEntityDropdownOpen.value = false;
};

const resetContactEditForm = () => {
    contactEditForm.entity_ids = [];
    contactEditForm.function_id = '';
    contactEditForm.user_id = '';
    contactEditForm.name = '';
    contactEditForm.email = '';
    contactEditForm.phone = '';
    contactEditForm.mobile_phone = '';
    contactEditForm.internal_notes = '';
    contactEditForm.is_active = true;
    contactEditEntitySearch.value = '';
    contactEditEntityDropdownOpen.value = false;
};

const clearContactEntitySelection = () => {
    contactForm.entity_ids = [];
};

const clearContactEditEntitySelection = () => {
    contactEditForm.entity_ids = [];
};

const toggleContactEntityDropdown = () => {
    contactEntityDropdownOpen.value = !contactEntityDropdownOpen.value;
};

const toggleContactEditEntityDropdown = () => {
    contactEditEntityDropdownOpen.value = !contactEditEntityDropdownOpen.value;
};

const closeContactEntityDropdownOnOutsideClick = (event) => {
    if (!contactEntityDropdownOpen.value) return;

    const target = event.target;
    if (!(target instanceof HTMLElement)) return;

    if (!target.closest('.entity-ddl')) {
        contactEntityDropdownOpen.value = false;
    }
};

const closeContactEditEntityDropdownOnOutsideClick = (event) => {
    if (!contactEditEntityDropdownOpen.value) return;

    const target = event.target;
    if (!(target instanceof HTMLElement)) return;

    if (!target.closest('.entity-ddl')) {
        contactEditEntityDropdownOpen.value = false;
    }
};

const toggleEntityActionsMenu = (entityId) => {
    entityActionsMenuOpenId.value = entityActionsMenuOpenId.value === entityId ? null : entityId;
};

const toggleInboxActionsMenu = (inboxId) => {
    inboxActionsMenuOpenId.value = inboxActionsMenuOpenId.value === inboxId ? null : inboxId;
};

const toggleContactActionsMenu = (contactId) => {
    contactActionsMenuOpenId.value = contactActionsMenuOpenId.value === contactId ? null : contactId;
};

const closeInboxActionsMenu = () => {
    inboxActionsMenuOpenId.value = null;
};

const closeContactActionsMenu = () => {
    contactActionsMenuOpenId.value = null;
};

const closeEntityActionsMenu = () => {
    entityActionsMenuOpenId.value = null;
};

const closeEntityActionsMenuOnOutsideClick = (event) => {
    if (entityActionsMenuOpenId.value === null) return;

    const target = event.target;
    if (!(target instanceof HTMLElement)) return;

    if (!target.closest('.entity-actions-menu')) {
        closeEntityActionsMenu();
    }
};

const closeInboxActionsMenuOnOutsideClick = (event) => {
    if (inboxActionsMenuOpenId.value === null) return;

    const target = event.target;
    if (!(target instanceof HTMLElement)) return;

    if (!target.closest('.inbox-actions-menu')) {
        closeInboxActionsMenu();
    }
};

const closeContactActionsMenuOnOutsideClick = (event) => {
    if (contactActionsMenuOpenId.value === null) return;

    const target = event.target;
    if (!(target instanceof HTMLElement)) return;

    if (!target.closest('.contact-actions-menu')) {
        closeContactActionsMenu();
    }
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

const goToInboxTickets = async (inboxId) => {
    await router.push({
        name: 'tickets.index',
        query: {
            inbox_id: String(inboxId),
        },
    });
};

const saveInbox = async () => {
    if (!editingInboxId.value) return;

    resetMessages();

    try {
        await api.patch(`/inboxes/${editingInboxId.value}`, {
            name: inboxEditForm.name,
            is_active: inboxEditForm.is_active,
            operator_ids: (inboxEditForm.operator_ids || []).map((id) => Number(id)),
        });
        success.value = 'Inbox atualizada.';
        closeInboxEditModal();
        await loadBaseData();
    } catch (exception) {
        error.value = exception?.response?.data?.message || 'Falha ao atualizar inbox.';
    }
};

const deleteInbox = async (inbox) => {
    closeInboxActionsMenu();
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

const resetEntityForm = () => {
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
};

const resetEntityEditForm = () => {
    entityEditForm.type = 'external';
    entityEditForm.name = '';
    entityEditForm.tax_number = '';
    entityEditForm.email = '';
    entityEditForm.phone = '';
    entityEditForm.mobile_phone = '';
    entityEditForm.website = '';
    entityEditForm.address_line = '';
    entityEditForm.postal_code = '';
    entityEditForm.city = '';
    entityEditForm.country = 'PT';
    entityEditForm.notes = '';
    entityEditForm.is_active = true;
};

const openEntityCreateModal = () => {
    resetMessages();
    resetEntityForm();
    showEntityCreateModal.value = true;
};

const closeEntityCreateModal = () => {
    showEntityCreateModal.value = false;
};

const openEntityEditModal = (entity) => {
    resetMessages();
    closeEntityActionsMenu();
    editingEntityId.value = entity.id;
    entityEditForm.type = entity.type ?? 'external';
    entityEditForm.name = entity.name ?? '';
    entityEditForm.tax_number = entity.tax_number ?? '';
    entityEditForm.email = entity.email ?? '';
    entityEditForm.phone = entity.phone ?? '';
    entityEditForm.mobile_phone = entity.mobile_phone ?? '';
    entityEditForm.website = entity.website ?? '';
    entityEditForm.address_line = entity.address_line ?? '';
    entityEditForm.postal_code = entity.postal_code ?? '';
    entityEditForm.city = entity.city ?? '';
    entityEditForm.country = entity.country ?? 'PT';
    entityEditForm.notes = entity.notes ?? '';
    entityEditForm.is_active = Boolean(entity.is_active);
    showEntityEditModal.value = true;
};

const maybeOpenEntityEditFromQuery = async () => {
    if (activeTab.value !== 'entities') {
        return;
    }

    const rawEntityId = route.query.edit_entity_id;
    if (typeof rawEntityId !== 'string') {
        return;
    }

    const entityId = Number(rawEntityId);
    if (!Number.isInteger(entityId) || entityId <= 0) {
        return;
    }

    const entity = entities.value.find((item) => Number(item.id) === entityId);
    if (!entity) {
        return;
    }

    openEntityEditModal(entity);

    const { edit_entity_id, ...restQuery } = route.query;
    await router.replace({ query: restQuery });
};

const closeEntityEditModal = () => {
    showEntityEditModal.value = false;
    editingEntityId.value = null;
    resetEntityEditForm();
};

const openContactCreateModal = () => {
    resetMessages();
    resetContactForm();
    contactEntityDropdownOpen.value = false;
    showContactCreateModal.value = true;
};

const closeContactCreateModal = () => {
    contactEntityDropdownOpen.value = false;
    showContactCreateModal.value = false;
};

const openContactEditModal = (contact) => {
    resetMessages();
    closeContactActionsMenu();
    editingContactId.value = contact.id;
    contactEditForm.entity_ids = (contact.entity_ids || []).map((id) => Number(id));
    contactEditForm.function_id = contact.function_id ? String(contact.function_id) : '';
    contactEditForm.user_id = contact.user_id ? String(contact.user_id) : '';
    contactEditForm.name = contact.name ?? '';
    contactEditForm.email = contact.email ?? '';
    contactEditForm.phone = contact.phone ?? '';
    contactEditForm.mobile_phone = contact.mobile_phone ?? '';
    contactEditForm.internal_notes = contact.internal_notes ?? '';
    contactEditForm.is_active = Boolean(contact.is_active);
    contactEditEntitySearch.value = '';
    contactEditEntityDropdownOpen.value = false;
    showContactEditModal.value = true;
};

const closeContactEditModal = () => {
    showContactEditModal.value = false;
    editingContactId.value = null;
    resetContactEditForm();
};

const createEntity = async () => {
    resetMessages();

    try {
        await api.post('/entities', entityForm);
        success.value = 'Entidade criada com sucesso.';
        resetEntityForm();
        showEntityCreateModal.value = false;
        await loadBaseData();
    } catch (exception) {
        error.value = exception?.response?.data?.message || 'Falha ao criar entidade.';
    }
};

const saveEntity = async () => {
    if (!editingEntityId.value) return;

    resetMessages();
    closeEntityActionsMenu();

    try {
        await api.patch(`/entities/${editingEntityId.value}`, {
            type: entityEditForm.type,
            name: entityEditForm.name,
            tax_number: entityEditForm.tax_number,
            email: entityEditForm.email,
            phone: entityEditForm.phone,
            mobile_phone: entityEditForm.mobile_phone,
            website: entityEditForm.website,
            address_line: entityEditForm.address_line,
            postal_code: entityEditForm.postal_code,
            city: entityEditForm.city,
            country: entityEditForm.country,
            notes: entityEditForm.notes,
            is_active: entityEditForm.is_active,
        });
        success.value = 'Entidade atualizada.';
        closeEntityEditModal();
        await loadBaseData();
    } catch (exception) {
        error.value = exception?.response?.data?.message || 'Falha ao atualizar entidade.';
    }
};

const deleteEntity = async (entity) => {
    if (!window.confirm(`Eliminar entidade ${entity.name}?`)) return;

    resetMessages();
    closeEntityActionsMenu();

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
        resetContactForm();
        showContactCreateModal.value = false;
        await loadBaseData();
    } catch (exception) {
        error.value = exception?.response?.data?.message || 'Falha ao criar contacto.';
    }
};

const saveContact = async () => {
    if (!editingContactId.value) return;

    resetMessages();
    closeContactActionsMenu();

    try {
        await api.patch(`/contacts/${editingContactId.value}`, {
            entity_ids: (contactEditForm.entity_ids || []).map((id) => Number(id)),
            function_id: contactEditForm.function_id ? Number(contactEditForm.function_id) : null,
            user_id: contactEditForm.user_id ? Number(contactEditForm.user_id) : null,
            name: contactEditForm.name,
            email: contactEditForm.email,
            phone: contactEditForm.phone || null,
            mobile_phone: contactEditForm.mobile_phone || null,
            internal_notes: contactEditForm.internal_notes || null,
            is_active: contactEditForm.is_active,
        });
        success.value = 'Contacto atualizado.';
        closeContactEditModal();
        await loadBaseData();
    } catch (exception) {
        error.value = exception?.response?.data?.message || 'Falha ao atualizar contacto.';
    }
};

const deleteContact = async (contact) => {
    if (!window.confirm(`Eliminar contacto ${contact.name}?`)) return;

    resetMessages();
    closeContactActionsMenu();

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

const saveNotificationTemplate = async (template) => {
    if (!template?.event_key) return;

    resetMessages();
    savingNotificationEventKey.value = template.event_key;

    try {
        const response = await api.patch(`/notification-templates/${template.event_key}`, {
            subject_template: normalizeNotificationTemplateValue(template.subject_template),
            title_template: normalizeNotificationTemplateValue(template.title_template),
            body_template: normalizeNotificationTemplateValue(template.body_template),
            is_enabled: Boolean(template.is_enabled),
        });

        const updatedTemplate = response?.data?.data;
        if (updatedTemplate) {
            const index = notificationTemplates.value.findIndex((item) => item.event_key === updatedTemplate.event_key);
            if (index !== -1) {
                notificationTemplates.value[index] = updatedTemplate;
            }
        }

        success.value = 'Template de notificação atualizado.';
    } catch (exception) {
        error.value = exception?.response?.data?.message || 'Falha ao atualizar template de notificação.';
    } finally {
        savingNotificationEventKey.value = '';
    }
};

const saveEmailStyle = async () => {
    resetMessages();
    savingEmailStyle.value = true;

    try {
        const response = await api.patch('/notification-email-style', {
            brand_name: emailStyle.brand_name,
            header_background: emailStyle.header_background,
            accent_color: emailStyle.accent_color,
            button_text: emailStyle.button_text,
            footer_text: emailStyle.footer_text,
            show_ticket_link: Boolean(emailStyle.show_ticket_link),
        });

        applyEmailStyle(emailStyle, response?.data?.data || {});
        success.value = response?.data?.message || 'Aspeto do email atualizado.';
    } catch (exception) {
        error.value = exception?.response?.data?.message || 'Falha ao atualizar aspeto do email.';
    } finally {
        savingEmailStyle.value = false;
    }
};

const resetEmailStyle = () => {
    applyEmailStyle(emailStyle, emailStyleDefaults);
    success.value = 'Aspeto reposto para o padrão.';
};

const appendPlaceholder = (template, field, placeholder) => {
    if (!template || !field || !placeholder) return;

    const current = String(template[field] ?? '');
    template[field] = current.trim() === '' ? placeholder : `${current} ${placeholder}`;
};

const onNotificationPlaceholderDragStart = (placeholder, event) => {
    const value = String(placeholder || '');
    if (!value) return;

    draggedNotificationPlaceholder.value = value;
    draggedBodyTokenElement.value = null;
    if (event?.dataTransfer) {
        event.dataTransfer.effectAllowed = 'copy';
        event.dataTransfer.setData('text/plain', value);
    }
};

const clearNotificationDropZone = () => {
    activeNotificationDropZone.templateKey = '';
    activeNotificationDropZone.field = '';
};

const onNotificationPlaceholderDragEnd = () => {
    draggedNotificationPlaceholder.value = '';
    draggedBodyTokenElement.value = null;
    clearNotificationDropZone();
};

const onNotificationFieldDragOver = (event, templateKey, field) => {
    const hasPlaceholder =
        Boolean(draggedNotificationPlaceholder.value) ||
        Boolean(event?.dataTransfer?.types?.includes?.('text/plain'));
    if (!hasPlaceholder) return;

    event.preventDefault();
    if (event?.dataTransfer) {
        event.dataTransfer.dropEffect = draggedBodyTokenElement.value ? 'move' : 'copy';
    }
    activeNotificationDropZone.templateKey = String(templateKey || '');
    activeNotificationDropZone.field = String(field || '');
};

const onNotificationFieldDragLeave = (templateKey, field) => {
    if (
        activeNotificationDropZone.templateKey === String(templateKey || '') &&
        activeNotificationDropZone.field === String(field || '')
    ) {
        clearNotificationDropZone();
    }
};

const insertPlaceholderAtCursor = (template, field, placeholder, inputElement = null) => {
    if (!template || !field || !placeholder) return;

    const current = String(template[field] ?? '');
    const start = Number(inputElement?.selectionStart);
    const end = Number(inputElement?.selectionEnd);

    if (Number.isInteger(start) && Number.isInteger(end) && start >= 0 && end >= start) {
        const before = current.slice(0, start);
        const after = current.slice(end);
        const leftSpacer = before && !before.endsWith(' ') ? ' ' : '';
        const rightSpacer = after && !after.startsWith(' ') ? ' ' : '';
        template[field] = `${before}${leftSpacer}${placeholder}${rightSpacer}${after}`;
        return;
    }

    appendPlaceholder(template, field, placeholder);
};

const onNotificationFieldDrop = (event, template, field) => {
    event.preventDefault();
    const droppedPlaceholder =
        String(event?.dataTransfer?.getData('text/plain') || '') || draggedNotificationPlaceholder.value;
    if (!droppedPlaceholder) {
        onNotificationPlaceholderDragEnd();
        return;
    }

    insertPlaceholderAtCursor(template, field, droppedPlaceholder, event?.target);
    onNotificationPlaceholderDragEnd();
};

const syncNotificationBodyTemplateFromEditor = (template) => {
    if (!template?.event_key) return;
    const editor = getNotificationBodyEditorRef(template.event_key);
    if (!editor) return;

    const value = normalizeNotificationTemplateValue(serializeNotificationBodyEditor(editor));
    template.body_template = value;
    editor.dataset.rawValue = value;
};

const syncNotificationSubjectTemplateFromEditor = (template) => {
    if (!template?.event_key) return;
    const editor = getNotificationSubjectEditorRef(template.event_key);
    if (!editor) return;

    const value = normalizeNotificationTemplateValue(
        serializeNotificationBodyEditor(editor).replace(/\n+/g, ' ').replace(/\s{2,}/g, ' ').trim()
    );
    template.subject_template = value;
    editor.dataset.rawValue = value;
};

const onNotificationSubjectEditorInput = (template) => {
    syncNotificationSubjectTemplateFromEditor(template);
};

const onNotificationSubjectEditorClick = (event, template) => {
    const target = event?.target;
    if (!(target instanceof HTMLElement)) return;

    const removeAction = target.closest('.template-token-remove');
    if (!removeAction) return;

    event.preventDefault();
    const tokenChip = removeAction.closest('.template-token-chip');
    tokenChip?.remove();
    syncNotificationSubjectTemplateFromEditor(template);
};

const onNotificationSubjectEditorKeydown = (event) => {
    if (event.key !== 'Enter') return;
    event.preventDefault();
};

const onNotificationBodyEditorInput = (template) => {
    syncNotificationBodyTemplateFromEditor(template);
};

const onNotificationBodyEditorClick = (event, template) => {
    const target = event?.target;
    if (!(target instanceof HTMLElement)) return;

    const removeAction = target.closest('.template-token-remove');
    if (!removeAction) return;

    event.preventDefault();
    const tokenChip = removeAction.closest('.template-token-chip');
    tokenChip?.remove();
    syncNotificationBodyTemplateFromEditor(template);
};

const onNotificationBodyEditorKeydown = (event) => {
    if (event.key !== 'Enter') return;
    event.preventDefault();
    document.execCommand('insertLineBreak');
};

const onNotificationBodyEditorDragStart = (event) => {
    const target = event?.target;
    if (!(target instanceof HTMLElement)) return;

    const tokenChip = target.closest('.template-token-chip');
    if (!tokenChip) return;

    const token = String(tokenChip.dataset.token || '');
    if (!token) return;

    draggedBodyTokenElement.value = tokenChip;
    draggedNotificationPlaceholder.value = token;

    if (event?.dataTransfer) {
        event.dataTransfer.effectAllowed = 'move';
        event.dataTransfer.setData('text/plain', token);
    }
};

const getRangeFromDropPoint = (event) => {
    if (!event) return null;

    if (typeof document.caretRangeFromPoint === 'function') {
        return document.caretRangeFromPoint(event.clientX, event.clientY);
    }

    if (typeof document.caretPositionFromPoint === 'function') {
        const position = document.caretPositionFromPoint(event.clientX, event.clientY);
        if (!position) return null;
        const range = document.createRange();
        range.setStart(position.offsetNode, position.offset);
        range.collapse(true);
        return range;
    }

    return null;
};

const moveCaretToRange = (range) => {
    if (!range) return;
    const selection = window.getSelection();
    if (!selection) return;
    selection.removeAllRanges();
    selection.addRange(range);
};

const placeNodeIntoEditor = (editor, node, event) => {
    const range = getRangeFromDropPoint(event) || (() => {
        const fallback = document.createRange();
        fallback.selectNodeContents(editor);
        fallback.collapse(false);
        return fallback;
    })();

    const container = range.startContainer.nodeType === Node.ELEMENT_NODE
        ? range.startContainer
        : range.startContainer.parentElement;
    const insideToken = container instanceof HTMLElement ? container.closest('.template-token-chip') : null;
    if (insideToken) {
        range.setStartAfter(insideToken);
        range.collapse(true);
    }

    if (!editor.contains(range.startContainer)) {
        range.selectNodeContents(editor);
        range.collapse(false);
    }

    range.deleteContents();
    range.insertNode(node);

    const spacer = document.createTextNode(' ');
    node.after(spacer);

    const afterRange = document.createRange();
    afterRange.setStartAfter(spacer);
    afterRange.collapse(true);
    moveCaretToRange(afterRange);
};

const onNotificationBodyEditorDrop = (event, template) => {
    event.preventDefault();

    const editor = getNotificationBodyEditorRef(template?.event_key);
    if (!editor) {
        onNotificationPlaceholderDragEnd();
        return;
    }

    const droppedPlaceholder =
        String(event?.dataTransfer?.getData('text/plain') || '') || draggedNotificationPlaceholder.value;
    if (!droppedPlaceholder) {
        onNotificationPlaceholderDragEnd();
        return;
    }

    let tokenNode;
    if (draggedBodyTokenElement.value) {
        tokenNode = draggedBodyTokenElement.value;
    } else {
        const wrapper = document.createElement('span');
        wrapper.innerHTML = buildNotificationTokenChipHtml(droppedPlaceholder);
        tokenNode = wrapper.firstChild;
    }

    if (tokenNode) {
        placeNodeIntoEditor(editor, tokenNode, event);
    }

    syncNotificationBodyTemplateFromEditor(template);
    onNotificationPlaceholderDragEnd();
};

const onNotificationSubjectEditorDrop = (event, template) => {
    event.preventDefault();

    const editor = getNotificationSubjectEditorRef(template?.event_key);
    if (!editor) {
        onNotificationPlaceholderDragEnd();
        return;
    }

    const droppedPlaceholder =
        String(event?.dataTransfer?.getData('text/plain') || '') || draggedNotificationPlaceholder.value;
    if (!droppedPlaceholder) {
        onNotificationPlaceholderDragEnd();
        return;
    }

    let tokenNode;
    if (draggedBodyTokenElement.value) {
        tokenNode = draggedBodyTokenElement.value;
    } else {
        const wrapper = document.createElement('span');
        wrapper.innerHTML = buildNotificationTokenChipHtml(droppedPlaceholder);
        tokenNode = wrapper.firstChild;
    }

    if (tokenNode) {
        placeNodeIntoEditor(editor, tokenNode, event);
    }

    syncNotificationSubjectTemplateFromEditor(template);
    onNotificationPlaceholderDragEnd();
};

const applyLogFiltersInstantly = () => {
    if (logSearchDebounceTimer) {
        clearTimeout(logSearchDebounceTimer);
    }

    logSearchDebounceTimer = setTimeout(() => {
        loadLogs();
    }, 240);
};

const applyLogFiltersImmediately = () => {
    if (logSearchDebounceTimer) {
        clearTimeout(logSearchDebounceTimer);
    }
    loadLogs();
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

watch(
    () => route.query.edit_entity_id,
    () => {
        if (loading.value) return;
        maybeOpenEntityEditFromQuery();
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

    if (normalized === 'entities' && typeof route.query.edit_entity_id === 'string') {
        maybeOpenEntityEditFromQuery();
    }
});

onMounted(loadAll);
onMounted(() => {
    document.addEventListener('click', closeInboxOperatorDropdownOnOutsideClick);
    document.addEventListener('click', closeContactEntityDropdownOnOutsideClick);
    document.addEventListener('click', closeContactEditEntityDropdownOnOutsideClick);
    document.addEventListener('click', closeInboxActionsMenuOnOutsideClick);
    document.addEventListener('click', closeEntityActionsMenuOnOutsideClick);
    document.addEventListener('click', closeContactActionsMenuOnOutsideClick);
});
onBeforeUnmount(() => {
    document.removeEventListener('click', closeInboxOperatorDropdownOnOutsideClick);
    document.removeEventListener('click', closeContactEntityDropdownOnOutsideClick);
    document.removeEventListener('click', closeContactEditEntityDropdownOnOutsideClick);
    document.removeEventListener('click', closeInboxActionsMenuOnOutsideClick);
    document.removeEventListener('click', closeEntityActionsMenuOnOutsideClick);
    document.removeEventListener('click', closeContactActionsMenuOnOutsideClick);
    if (logSearchDebounceTimer) {
        clearTimeout(logSearchDebounceTimer);
    }
});
</script>

<template>
    <section class="page">
        <header class="header-row">
            <div>
                <h1>Configuração operacional</h1>
                <p class="muted">CRUD de inboxes, entidades, contactos, templates de notificação e consulta dedicada de ticket logs.</p>
            </div>
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

                <section v-if="showInboxEditModal" class="modal-overlay" @click.self="closeInboxEditModal">
                    <article class="modal-card">
                        <header class="modal-header">
                            <div>
                                <h3>Editar inbox</h3>
                                <p class="muted">Atualiza os dados da inbox e os operadores associados.</p>
                            </div>
                        </header>

                        <form class="form-grid" @submit.prevent="saveInbox">
                            <label>Nome <input v-model="inboxEditForm.name" required /></label>
                            <label class="checkbox"><input v-model="inboxEditForm.is_active" type="checkbox" />Ativa</label>

                            <label class="full">Operadores
                                <div class="entity-ddl inbox-operators-ddl">
                                    <button type="button" class="entity-ddl-toggle" @click="toggleInboxOperatorDropdown">
                                        <span class="entity-ddl-value">{{ inboxOperatorDropdownLabel }}</span>
                                        <span class="entity-ddl-arrow" :class="{ open: inboxOperatorDropdownOpen }" aria-hidden="true">
                                            <svg viewBox="0 0 20 20" fill="none">
                                                <path d="M5 7.5L10 12.5L15 7.5" stroke="currentColor" stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8" />
                                            </svg>
                                        </span>
                                    </button>

                                    <div v-if="inboxOperatorDropdownOpen" class="entity-ddl-panel">
                                        <div class="entity-ddl-search-wrap">
                                            <input
                                                v-model="inboxOperatorSearch"
                                                class="entity-ddl-search"
                                                type="search"
                                                placeholder="Pesquisar operadores..."
                                            />
                                        </div>

                                        <div class="entity-ddl-list">
                                            <button
                                                v-for="operator in filteredInboxOperators"
                                                :key="`inbox-operator-${operator.id}`"
                                                type="button"
                                                class="entity-ddl-option"
                                                :class="{ selected: isInboxOperatorSelected(operator.id) }"
                                                @click="toggleInboxOperator(operator.id)"
                                            >
                                                <span class="entity-ddl-option-name">
                                                    {{ operator.name }}
                                                    <small v-if="!operator.is_active">(inativo)</small>
                                                </span>
                                                <span class="entity-ddl-option-check">
                                                    {{ isInboxOperatorSelected(operator.id) ? '✓' : '' }}
                                                </span>
                                            </button>

                                            <p v-if="!filteredInboxOperators.length" class="muted">
                                                Sem operadores para este filtro.
                                            </p>
                                        </div>

                                        <div class="entity-ddl-footer">
                                            <small>{{ selectedInboxOperators.length }} selecionados</small>
                                            <button type="button" class="ghost mini-btn" @click="clearInboxOperatorsSelection">
                                                Limpar
                                            </button>
                                        </div>
                                    </div>

                                    <div v-if="selectedInboxOperators.length" class="entity-ddl-selected">
                                        <button
                                            v-for="operator in selectedInboxOperators"
                                            :key="`selected-inbox-operator-${operator.id}`"
                                            type="button"
                                            class="entity-ddl-tag"
                                            @click="toggleInboxOperator(operator.id)"
                                        >
                                            {{ operator.name }}
                                        </button>
                                    </div>
                                </div>
                            </label>

                            <div class="full modal-actions">
                                <button type="submit">Guardar alterações</button>
                                <button type="button" class="ghost" @click="closeInboxEditModal">Cancelar</button>
                            </div>
                        </form>
                    </article>
                </section>

                <table class="table">
                    <thead>
                        <tr>
                            <th>Nome</th>
                            <th>Ativa</th>
                            <th>Tickets</th>
                            <th>Operadores</th>
                            <th>Ações</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="item in inboxes" :key="item.id">
                            <td><span class="cell-text cell-strong" :title="item.name">{{ item.name }}</span></td>
                            <td>
                                <span class="status-chip" :class="item.is_active ? 'active' : 'inactive'">
                                    {{ item.is_active ? 'Ativa' : 'Inativa' }}
                                </span>
                            </td>
                            <td>
                                <button type="button" class="count-pill count-link" @click="goToInboxTickets(item.id)">
                                    {{ item.tickets_count }}
                                </button>
                            </td>
                            <td>
                                <span class="count-pill">{{ item.operators_count }}</span>
                                <span class="cell-text notes-text" :title="(item.operators || []).map((operator) => operator.name).join(', ') || '-'">
                                    {{ (item.operators || []).map((operator) => operator.name).join(', ') || '-' }}
                                </span>
                            </td>
                            <td class="inbox-row-actions">
                                <div class="inbox-actions-menu">
                                    <button
                                        type="button"
                                        class="ghost entity-actions-trigger"
                                        aria-label="Abrir ações"
                                        @click.stop="toggleInboxActionsMenu(item.id)"
                                    >
                                        ...
                                    </button>
                                    <div
                                        v-if="inboxActionsMenuOpenId === item.id"
                                        class="entity-actions-dropdown"
                                        @click.stop
                                    >
                                        <button
                                            type="button"
                                            class="entity-menu-item"
                                            @click="openInboxEditModal(item)"
                                        >
                                            Editar
                                        </button>
                                        <button
                                            type="button"
                                            class="entity-menu-item danger"
                                            @click="deleteInbox(item)"
                                        >
                                            Eliminar
                                        </button>
                                    </div>
                                </div>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </template>

            <template v-if="!loading && activeTab === 'entities'">
                <h2>Entidades</h2>
                <div class="section-actions">
                    <button type="button" class="btn-inline" @click="openEntityCreateModal">
                        Criar entidade
                    </button>
                </div>

                <section v-if="showEntityCreateModal" class="modal-overlay" @click.self="closeEntityCreateModal">
                    <article class="modal-card">
                        <header class="modal-header">
                            <div>
                                <h3>Nova entidade</h3>
                                <p class="muted">Preenche os dados da entidade para criar.</p>
                            </div>
                            <button type="button" class="ghost" @click="closeEntityCreateModal">Fechar</button>
                        </header>

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
                            <label>Telemóvel <input v-model="entityForm.mobile_phone" /></label>
                            <label>Website <input v-model="entityForm.website" type="url" placeholder="https://..." /></label>
                            <label>Morada <input v-model="entityForm.address_line" /></label>
                            <label>Código postal <input v-model="entityForm.postal_code" maxlength="20" /></label>
                            <label>Cidade <input v-model="entityForm.city" /></label>
                            <label>País (2 letras) <input v-model="entityForm.country" maxlength="2" /></label>
                            <label class="checkbox"><input v-model="entityForm.is_active" type="checkbox" />Ativa</label>
                            <label class="full">Notas internas <textarea v-model="entityForm.notes" rows="2"></textarea></label>

                            <div class="full modal-actions">
                                <button type="submit">Criar entidade</button>
                                <button type="button" class="ghost" @click="closeEntityCreateModal">Cancelar</button>
                            </div>
                        </form>
                    </article>
                </section>

                <section v-if="showEntityEditModal" class="modal-overlay" @click.self="closeEntityEditModal">
                    <article class="modal-card">
                        <header class="modal-header">
                            <div>
                                <h3>Editar entidade</h3>
                                <p class="muted">Atualiza os campos da entidade selecionada.</p>
                            </div>
                        </header>

                        <form class="form-grid" @submit.prevent="saveEntity">
                            <label>Tipo
                                <select v-model="entityEditForm.type">
                                    <option value="external">Externa</option>
                                    <option value="internal">Interna</option>
                                </select>
                            </label>
                            <label>Nome <input v-model="entityEditForm.name" required /></label>
                            <label>NIF <input v-model="entityEditForm.tax_number" /></label>
                            <label>Email <input v-model="entityEditForm.email" type="email" /></label>
                            <label>Telefone <input v-model="entityEditForm.phone" /></label>
                            <label>Telemóvel <input v-model="entityEditForm.mobile_phone" /></label>
                            <label>Website <input v-model="entityEditForm.website" type="url" placeholder="https://..." /></label>
                            <label>Morada <input v-model="entityEditForm.address_line" /></label>
                            <label>Código postal <input v-model="entityEditForm.postal_code" maxlength="20" /></label>
                            <label>Cidade <input v-model="entityEditForm.city" /></label>
                            <label>País (2 letras) <input v-model="entityEditForm.country" maxlength="2" /></label>
                            <label class="checkbox"><input v-model="entityEditForm.is_active" type="checkbox" />Ativa</label>
                            <label class="full">Notas internas <textarea v-model="entityEditForm.notes" rows="2"></textarea></label>

                            <div class="full modal-actions">
                                <button type="submit">Guardar alterações</button>
                                <button type="button" class="ghost" @click="closeEntityEditModal">Cancelar</button>
                            </div>
                        </form>
                    </article>
                </section>

                <div class="table-scroll">
                    <table class="table entity-table">
                        <thead>
                            <tr>
                                <th>Nome</th>
                                <th>Tipo</th>
                                <th>Email</th>
                                <th>Telefone</th>
                                <th>Telemóvel</th>
                                <th>NIF</th>
                                <th>Website</th>
                                <th>Notas internas</th>
                                <th>Estado</th>
                                <th>Contactos</th>
                                <th>Tickets</th>
                                <th>Ações</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-for="item in entities" :key="item.id">
                                <td>
                                    <span class="cell-text cell-strong" :title="item.name">{{ item.name || '-' }}</span>
                                </td>
                                <td>
                                    <span class="entity-type-chip" :class="`type-${item.type}`">
                                        {{ item.type === 'internal' ? 'interna' : 'externa' }}
                                    </span>
                                </td>
                                <td>
                                    <span class="cell-text" :title="item.email">{{ item.email || '-' }}</span>
                                </td>
                                <td>
                                    <span class="cell-text">{{ item.phone || '-' }}</span>
                                </td>
                                <td>
                                    <span class="cell-text">{{ item.mobile_phone || '-' }}</span>
                                </td>
                                <td>
                                    <span class="cell-text">{{ item.tax_number || '-' }}</span>
                                </td>
                                <td>
                                    <span class="cell-text" :title="item.website">{{ item.website || '-' }}</span>
                                </td>
                                <td>
                                    <span class="cell-text notes-text" :title="item.notes">{{ item.notes || '-' }}</span>
                                </td>
                                <td>
                                    <span class="status-chip" :class="item.is_active ? 'active' : 'inactive'">
                                        {{ item.is_active ? 'Ativa' : 'Inativa' }}
                                    </span>
                                </td>
                                <td><span class="count-pill">{{ item.contacts_count }}</span></td>
                                <td><span class="count-pill">{{ item.tickets_count }}</span></td>
                                <td class="row-actions">
                                    <div class="entity-actions-menu">
                                        <button
                                            type="button"
                                            class="ghost entity-actions-trigger"
                                            aria-label="Abrir ações"
                                            @click.stop="toggleEntityActionsMenu(item.id)"
                                        >
                                            ...
                                        </button>
                                        <div
                                            v-if="entityActionsMenuOpenId === item.id"
                                            class="entity-actions-dropdown"
                                            @click.stop
                                        >
                                            <button
                                                type="button"
                                                class="entity-menu-item"
                                                @click="openEntityEditModal(item)"
                                            >
                                                Editar
                                            </button>
                                            <button
                                                type="button"
                                                class="entity-menu-item danger"
                                                @click="deleteEntity(item)"
                                            >
                                                Eliminar
                                            </button>
                                        </div>
                                    </div>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </template>

            <template v-if="!loading && activeTab === 'contacts'">
                <h2>Contactos</h2>
                <div class="section-actions">
                    <button type="button" class="btn-inline" @click="openContactCreateModal">
                        Criar contacto
                    </button>
                </div>

                <section v-if="showContactCreateModal" class="modal-overlay" @click.self="closeContactCreateModal">
                    <article class="modal-card">
                        <header class="modal-header">
                            <div>
                                <h3>Novo contacto</h3>
                                <p class="muted">Preenche os dados do contacto para criar.</p>
                            </div>
                            <button type="button" class="ghost" @click="closeContactCreateModal">Fechar</button>
                        </header>

                        <form class="form-grid" @submit.prevent="createContact">
                            <label class="full">Entidades relacionadas
                                <div class="entity-ddl">
                                    <button type="button" class="entity-ddl-toggle" @click.stop="toggleContactEntityDropdown">
                                        <span class="entity-ddl-value">{{ contactEntityDropdownLabel }}</span>
                                        <span class="entity-ddl-arrow" :class="{ open: contactEntityDropdownOpen }">
                                            <svg viewBox="0 0 24 24" fill="none" aria-hidden="true">
                                                <path d="M7 10l5 5 5-5" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" />
                                            </svg>
                                        </span>
                                    </button>

                                    <div v-if="contactEntityDropdownOpen" class="entity-ddl-panel">
                                        <div class="entity-ddl-search-wrap">
                                            <input
                                                v-model="contactEntitySearch"
                                                class="entity-ddl-search"
                                                type="search"
                                                placeholder="Pesquisar entidade por nome ou NIF..."
                                            />
                                        </div>

                                        <div class="entity-ddl-list" role="listbox" aria-multiselectable="true">
                                            <button
                                                v-for="entity in filteredEntitiesForContact"
                                                :key="`new-contact-entity-${entity.id}`"
                                                type="button"
                                                class="entity-ddl-option"
                                                :class="{ selected: isContactEntitySelected(entity.id) }"
                                                @click="toggleContactEntity(contactForm, entity.id)"
                                            >
                                                <span class="entity-ddl-option-name">{{ entity.name }}</span>
                                                <span class="entity-ddl-option-check">
                                                    {{ isContactEntitySelected(entity.id) ? '✓' : '' }}
                                                </span>
                                            </button>

                                            <p v-if="!filteredEntitiesForContact.length" class="muted">
                                                Sem entidades para este filtro.
                                            </p>
                                        </div>

                                        <div class="entity-ddl-footer">
                                            <small>{{ selectedContactEntities.length }} selecionadas</small>
                                            <button type="button" class="ghost mini-btn" @click="clearContactEntitySelection">
                                                Limpar
                                            </button>
                                        </div>
                                    </div>
                                    <div v-if="selectedContactEntities.length" class="entity-ddl-selected">
                                        <button
                                            v-for="entity in selectedContactEntities"
                                            :key="`selected-entity-${entity.id}`"
                                            type="button"
                                            class="entity-ddl-tag"
                                            @click="toggleContactEntity(contactForm, entity.id)"
                                        >
                                            {{ entity.name }}
                                        </button>
                                    </div>
                                </div>
                            </label>
                            <label>Utilizador (opcional)
                                <select v-model="contactForm.user_id" @change="syncContactFormFromSelectedUser">
                                    <option value="">Sem associação</option>
                                    <option v-for="user in availableClientUsers" :key="user.id" :value="String(user.id)">
                                        {{ user.name }} ({{ user.email }})
                                    </option>
                                </select>
                            </label>
                            <label>Função
                                <select v-model="contactForm.function_id">
                                    <option value="">Sem função</option>
                                    <option v-for="option in contactFunctions" :key="option.id" :value="String(option.id)">
                                        {{ option.name }}
                                    </option>
                                </select>
                            </label>
                            <label>Nome <input v-model="contactForm.name" required /></label>
                            <label>Email <input v-model="contactForm.email" type="email" required /></label>
                            <label>Telefone <input v-model="contactForm.phone" /></label>
                            <label>Telemóvel <input v-model="contactForm.mobile_phone" /></label>
                            <label class="full">Notas internas <textarea v-model="contactForm.internal_notes" rows="2"></textarea></label>
                            <label class="checkbox"><input v-model="contactForm.is_active" type="checkbox" />Ativo</label>

                            <div class="full modal-actions">
                                <button type="submit">Criar contacto</button>
                                <button type="button" class="ghost" @click="closeContactCreateModal">Cancelar</button>
                            </div>
                        </form>
                    </article>
                </section>

                <section v-if="showContactEditModal" class="modal-overlay" @click.self="closeContactEditModal">
                    <article class="modal-card">
                        <header class="modal-header">
                            <div>
                                <h3>Editar contacto</h3>
                                <p class="muted">Atualiza os dados do contacto selecionado.</p>
                            </div>
                        </header>

                        <form class="form-grid" @submit.prevent="saveContact">
                            <label class="full">Entidades relacionadas
                                <div class="entity-ddl">
                                    <button type="button" class="entity-ddl-toggle" @click.stop="toggleContactEditEntityDropdown">
                                        <span class="entity-ddl-value">{{ contactEditEntityDropdownLabel }}</span>
                                        <span class="entity-ddl-arrow" :class="{ open: contactEditEntityDropdownOpen }">
                                            <svg viewBox="0 0 24 24" fill="none" aria-hidden="true">
                                                <path d="M7 10l5 5 5-5" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" />
                                            </svg>
                                        </span>
                                    </button>

                                    <div v-if="contactEditEntityDropdownOpen" class="entity-ddl-panel">
                                        <div class="entity-ddl-search-wrap">
                                            <input
                                                v-model="contactEditEntitySearch"
                                                class="entity-ddl-search"
                                                type="search"
                                                placeholder="Pesquisar entidade por nome ou NIF..."
                                            />
                                        </div>

                                        <div class="entity-ddl-list" role="listbox" aria-multiselectable="true">
                                            <button
                                                v-for="entity in filteredEntitiesForContactEdit"
                                                :key="`edit-contact-entity-${entity.id}`"
                                                type="button"
                                                class="entity-ddl-option"
                                                :class="{ selected: isContactEditEntitySelected(entity.id) }"
                                                @click="toggleContactEntity(contactEditForm, entity.id)"
                                            >
                                                <span class="entity-ddl-option-name">{{ entity.name }}</span>
                                                <span class="entity-ddl-option-check">
                                                    {{ isContactEditEntitySelected(entity.id) ? '✓' : '' }}
                                                </span>
                                            </button>

                                            <p v-if="!filteredEntitiesForContactEdit.length" class="muted">
                                                Sem entidades para este filtro.
                                            </p>
                                        </div>

                                        <div class="entity-ddl-footer">
                                            <small>{{ selectedContactEditEntities.length }} selecionadas</small>
                                            <button type="button" class="ghost mini-btn" @click="clearContactEditEntitySelection">
                                                Limpar
                                            </button>
                                        </div>
                                    </div>
                                    <div v-if="selectedContactEditEntities.length" class="entity-ddl-selected">
                                        <button
                                            v-for="entity in selectedContactEditEntities"
                                            :key="`selected-edit-entity-${entity.id}`"
                                            type="button"
                                            class="entity-ddl-tag"
                                            @click="toggleContactEntity(contactEditForm, entity.id)"
                                        >
                                            {{ entity.name }}
                                        </button>
                                    </div>
                                </div>
                            </label>
                            <label>Utilizador (opcional)
                                <select v-model="contactEditForm.user_id" @change="syncContactEditFormFromSelectedUser">
                                    <option value="">Sem associação</option>
                                    <option v-for="user in userOptions" :key="`edit-contact-user-${user.id}`" :value="String(user.id)">
                                        {{ user.name }} ({{ user.email }})
                                    </option>
                                </select>
                            </label>
                            <label>Função
                                <select v-model="contactEditForm.function_id">
                                    <option value="">Sem função</option>
                                    <option v-for="option in contactFunctions" :key="`edit-contact-function-${option.id}`" :value="String(option.id)">
                                        {{ option.name }}
                                    </option>
                                </select>
                            </label>
                            <label>Nome <input v-model="contactEditForm.name" required /></label>
                            <label>Email <input v-model="contactEditForm.email" type="email" required /></label>
                            <label>Telefone <input v-model="contactEditForm.phone" /></label>
                            <label>Telemóvel <input v-model="contactEditForm.mobile_phone" /></label>
                            <label class="full">Notas internas <textarea v-model="contactEditForm.internal_notes" rows="2"></textarea></label>
                            <label class="checkbox"><input v-model="contactEditForm.is_active" type="checkbox" />Ativo</label>

                            <div class="full modal-actions">
                                <button type="submit">Guardar alterações</button>
                                <button type="button" class="ghost" @click="closeContactEditModal">Cancelar</button>
                            </div>
                        </form>
                    </article>
                </section>

                <table class="table">
                    <thead>
                        <tr>
                            <th>Nome</th>
                            <th>Função</th>
                            <th>Email</th>
                            <th>Telefone</th>
                            <th>Telemóvel</th>
                            <th>Entidades</th>
                            <th>Notas internas</th>
                            <th>Ativo</th>
                            <th>Ações</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="item in contacts" :key="item.id">
                            <td><span class="cell-text cell-strong" :title="item.name">{{ item.name || '-' }}</span></td>
                            <td><span class="cell-text">{{ item.function?.name || 'Sem função' }}</span></td>
                            <td><span class="cell-text" :title="item.email">{{ item.email || '-' }}</span></td>
                            <td><span class="cell-text">{{ item.phone || '-' }}</span></td>
                            <td><span class="cell-text">{{ item.mobile_phone || '-' }}</span></td>
                            <td>
                                <span class="cell-text notes-text" :title="(item.entities || []).map((entity) => entity.name).join(', ') || '-'">
                                    {{ (item.entities || []).map((entity) => entity.name).join(', ') || '-' }}
                                </span>
                            </td>
                            <td><span class="cell-text notes-text" :title="item.internal_notes">{{ item.internal_notes || '-' }}</span></td>
                            <td>
                                <span class="status-chip" :class="item.is_active ? 'active' : 'inactive'">
                                    {{ item.is_active ? 'Ativo' : 'Inativo' }}
                                </span>
                            </td>
                            <td class="row-actions">
                                <div class="contact-actions-menu">
                                    <button
                                        type="button"
                                        class="ghost entity-actions-trigger"
                                        aria-label="Abrir ações"
                                        @click.stop="toggleContactActionsMenu(item.id)"
                                    >
                                        ...
                                    </button>
                                    <div
                                        v-if="contactActionsMenuOpenId === item.id"
                                        class="entity-actions-dropdown"
                                        @click.stop
                                    >
                                        <button
                                            type="button"
                                            class="entity-menu-item"
                                            @click="openContactEditModal(item)"
                                        >
                                            Editar
                                        </button>
                                        <button
                                            type="button"
                                            class="entity-menu-item danger"
                                            @click="deleteContact(item)"
                                        >
                                            Eliminar
                                        </button>
                                    </div>
                                </div>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </template>

            <template v-if="!loading && activeTab === 'notifications'">
                <h2>Notificações por email</h2>
                <p class="muted">
                    Configura texto e aspeto visual dos emails enviados pelo sistema.
                </p>

                <p class="muted placeholders-help">
                    Dica: arrasta os blocos da sidebar para Assunto, Título ou Corpo e monta o template de forma visual.
                </p>

                <div class="notification-builder-shell">
                    <aside class="notification-dnd-sidebar" v-if="notificationPlaceholderOptions.length">
                        <h3>Blocos dinâmicos</h3>
                        <p class="muted">Arrasta para os campos do template.</p>
                        <div class="notification-dnd-list">
                            <button
                                v-for="option in notificationPlaceholderOptions"
                                :key="`dnd-${option.value}`"
                                type="button"
                                class="notification-dnd-chip"
                                draggable="true"
                                @dragstart="onNotificationPlaceholderDragStart(option.value, $event)"
                                @dragend="onNotificationPlaceholderDragEnd"
                            >
                                {{ option.label }}
                            </button>
                        </div>
                    </aside>

                    <section class="notification-carousel-panel">
                        <header class="notification-carousel-header" v-if="notificationTemplates.length">
                            <button
                                type="button"
                                class="ghost carousel-nav-btn"
                                :disabled="notificationCarouselIndex === 0"
                                @click="goToPreviousNotificationTemplate"
                            >
                                ‹
                            </button>
                            <div class="notification-carousel-title">
                                <strong>{{ activeNotificationTemplateLabel }}</strong>
                                <small class="muted">
                                    {{ notificationCarouselIndex + 1 }} de {{ notificationTemplates.length }}
                                </small>
                            </div>
                            <button
                                type="button"
                                class="ghost carousel-nav-btn"
                                :disabled="notificationCarouselIndex >= notificationTemplates.length - 1"
                                @click="goToNextNotificationTemplate"
                            >
                                ›
                            </button>
                        </header>

                        <div class="notification-carousel-dots" v-if="notificationTemplates.length > 1">
                            <button
                                v-for="(template, index) in notificationTemplates"
                                :key="`template-switch-${template.event_key}`"
                                type="button"
                                class="notification-carousel-dot"
                                :class="{ active: index === notificationCarouselIndex }"
                                @click="goToNotificationTemplateIndex(index)"
                            >
                                {{ notificationEventLabel[template.event_key] || template.event_key }}
                            </button>
                        </div>

                        <article
                            v-if="activeNotificationTemplate"
                            :key="activeNotificationTemplate.event_key"
                            class="notification-template-card"
                        >
                            <header class="notification-template-header">
                                <h3>{{ activeNotificationTemplateLabel }}</h3>
                                <label class="toggle-switch">
                                    <input v-model="activeNotificationTemplate.is_enabled" type="checkbox" />
                                    <span class="toggle-track" aria-hidden="true">
                                        <span class="toggle-thumb"></span>
                                    </span>
                                    <span class="toggle-text">Ativa</span>
                                </label>
                            </header>

                            <form
                                class="form-grid notification-template-form"
                                @submit.prevent="saveNotificationTemplate(activeNotificationTemplate)"
                            >
                                <label class="full">
                                    Assunto
                                    <div
                                        class="template-inline-editor"
                                        contenteditable="true"
                                        data-placeholder="Escreve o assunto e arrasta blocos dinâmicos"
                                        :class="{ 'notification-drop-active': isNotificationDropZoneActive(activeNotificationTemplate.event_key, 'subject_template') }"
                                        :ref="(el) => setNotificationSubjectEditorRef(activeNotificationTemplate, el)"
                                        @input="onNotificationSubjectEditorInput(activeNotificationTemplate)"
                                        @click="onNotificationSubjectEditorClick($event, activeNotificationTemplate)"
                                        @keydown="onNotificationSubjectEditorKeydown($event)"
                                        @dragstart="onNotificationBodyEditorDragStart($event)"
                                        @dragover="onNotificationFieldDragOver($event, activeNotificationTemplate.event_key, 'subject_template')"
                                        @dragleave="onNotificationFieldDragLeave(activeNotificationTemplate.event_key, 'subject_template')"
                                        @drop="onNotificationSubjectEditorDrop($event, activeNotificationTemplate)"
                                        @dragend="onNotificationPlaceholderDragEnd"
                                    ></div>
                                </label>
                                <label class="full">
                                    Título
                                    <input
                                        v-model="activeNotificationTemplate.title_template"
                                        maxlength="255"
                                        required
                                        :class="{ 'notification-drop-active': isNotificationDropZoneActive(activeNotificationTemplate.event_key, 'title_template') }"
                                        @dragover="onNotificationFieldDragOver($event, activeNotificationTemplate.event_key, 'title_template')"
                                        @dragleave="onNotificationFieldDragLeave(activeNotificationTemplate.event_key, 'title_template')"
                                        @drop="onNotificationFieldDrop($event, activeNotificationTemplate, 'title_template')"
                                    />
                                </label>
                                <label class="full">
                                    Corpo
                                    <div
                                        class="template-body-editor"
                                        contenteditable="true"
                                        data-placeholder="Escreve o corpo do email e arrasta blocos dinâmicos para aqui"
                                        :class="{ 'notification-drop-active': isNotificationDropZoneActive(activeNotificationTemplate.event_key, 'body_template') }"
                                        :ref="(el) => setNotificationBodyEditorRef(activeNotificationTemplate, el)"
                                        @input="onNotificationBodyEditorInput(activeNotificationTemplate)"
                                        @click="onNotificationBodyEditorClick($event, activeNotificationTemplate)"
                                        @keydown="onNotificationBodyEditorKeydown($event)"
                                        @dragstart="onNotificationBodyEditorDragStart($event)"
                                        @dragover="onNotificationFieldDragOver($event, activeNotificationTemplate.event_key, 'body_template')"
                                        @dragleave="onNotificationFieldDragLeave(activeNotificationTemplate.event_key, 'body_template')"
                                        @drop="onNotificationBodyEditorDrop($event, activeNotificationTemplate)"
                                        @dragend="onNotificationPlaceholderDragEnd"
                                    ></div>
                                </label>
                                <div class="full notification-template-actions">
                                    <button
                                        type="submit"
                                        :disabled="savingNotificationEventKey === activeNotificationTemplate.event_key"
                                    >
                                        {{ savingNotificationEventKey === activeNotificationTemplate.event_key ? 'A guardar...' : 'Guardar template' }}
                                    </button>
                                </div>
                            </form>
                        </article>

                        <p v-else class="muted">
                            Sem templates de notificação para configurar.
                        </p>
                    </section>

                    <section class="notification-right-column">
                        <div class="notification-style-ddl">
                            <button
                                type="button"
                                class="ghost notification-style-toggle-btn"
                                @click="showEmailStylePanel = !showEmailStylePanel"
                            >
                                <span>Aspeto visual do email</span>
                                <span class="notification-style-toggle-icon">{{ showEmailStylePanel ? '▲' : '▼' }}</span>
                            </button>

                            <article v-if="showEmailStylePanel" class="notification-template-card notification-style-card compact notification-style-popover">
                                <form class="form-grid notification-template-form notification-style-form" @submit.prevent="saveEmailStyle">
                                    <label>
                                        Nome da marca
                                        <input v-model="emailStyle.brand_name" maxlength="120" required />
                                    </label>
                                    <label>
                                        Texto do botão
                                        <input v-model="emailStyle.button_text" maxlength="120" required />
                                    </label>
                                    <label>
                                        Cor de cabeçalho
                                        <input v-model="emailStyle.header_background" type="color" />
                                    </label>
                                    <label>
                                        Cor de destaque
                                        <input v-model="emailStyle.accent_color" type="color" />
                                    </label>
                                    <label class="full">
                                        Rodapé
                                        <input v-model="emailStyle.footer_text" maxlength="300" required />
                                    </label>
                                    <div class="full notification-style-actions-row">
                                        <label class="toggle-switch">
                                            <input v-model="emailStyle.show_ticket_link" type="checkbox" />
                                            <span class="toggle-track" aria-hidden="true">
                                                <span class="toggle-thumb"></span>
                                            </span>
                                            <span class="toggle-text">Mostrar botão de acesso ao ticket</span>
                                        </label>

                                        <div class="notification-template-actions">
                                            <button type="submit" :disabled="savingEmailStyle">
                                                {{ savingEmailStyle ? 'A guardar...' : 'Guardar aspeto' }}
                                            </button>
                                            <button type="button" class="ghost" @click="resetEmailStyle">Repor padrão</button>
                                        </div>
                                    </div>
                                </form>
                            </article>
                        </div>

                        <article class="notification-template-card notification-preview-card notification-preview-side">
                            <header class="notification-template-header">
                                <h3>Pré-visualização</h3>
                                <small class="muted" v-if="activeNotificationTemplateLabel">{{ activeNotificationTemplateLabel }}</small>
                            </header>
                            <div class="email-preview-shell">
                                <div class="email-preview-header" :style="{ background: emailStyle.header_background }">
                                    <span class="email-preview-badge">SD</span>
                                    <strong>{{ emailStyle.brand_name }}</strong>
                                </div>
                                <div class="email-preview-content">
                                    <h4>{{ activeNotificationTemplate?.title_template || 'Título do email' }}</h4>
                                    <p><strong>Ticket:</strong> TC-000001</p>
                                    <p><strong>Assunto:</strong> Exemplo de pedido de suporte</p>
                                    <p>{{ activeNotificationTemplate?.body_template || 'Corpo configurável do email.' }}</p>
                                </div>
                                <div class="email-preview-footer">
                                    <button
                                        v-if="emailStyle.show_ticket_link"
                                        type="button"
                                        class="email-preview-button"
                                        :style="{ background: emailStyle.accent_color, borderColor: emailStyle.accent_color }"
                                    >
                                        {{ emailStyle.button_text }}
                                    </button>
                                    <small>{{ emailStyle.footer_text }}</small>
                                </div>
                            </div>
                        </article>
                    </section>
                </div>
            </template>

            <template v-if="!loading && activeTab === 'logs'">
                <h2>Ticket logs</h2>
                <form class="filters" @submit.prevent>
                    <label>Pesquisa <input v-model="logFilters.search" placeholder="ticket ou ação" @input="applyLogFiltersInstantly" /></label>
                    <label>Ação <input v-model="logFilters.action" placeholder="status_updated" @input="applyLogFiltersInstantly" /></label>
                    <label>Ator
                        <select v-model="logFilters.actor_type" @change="applyLogFiltersImmediately">
                            <option value="">Todos</option>
                            <option value="user">user</option>
                            <option value="contact">contact</option>
                            <option value="system">system</option>
                        </select>
                    </label>
                </form>

                <table class="table">
                    <thead>
                        <tr>
                            <th>Data</th>
                            <th>Ticket</th>
                            <th>Ação</th>
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
    border-color: #1F4E79;
    background: #1F4E79;
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

.notification-template-grid {
    display: grid;
    grid-template-columns: repeat(2, minmax(0, 1fr));
    gap: 0.85rem;
}

.notification-layout-grid {
    display: grid;
    grid-template-columns: 1fr;
    gap: 0.5rem;
    margin-bottom: 0.55rem;
}

.notification-builder-shell {
    display: grid;
    grid-template-columns: 220px minmax(0, 1fr) minmax(300px, 360px);
    gap: 0.6rem;
    align-items: start;
}

.notification-right-column {
    min-width: 0;
    display: grid;
    gap: 0.5rem;
    position: sticky;
    top: 0.6rem;
    align-self: start;
}

.notification-style-ddl {
    position: relative;
    display: flex;
    justify-content: flex-end;
    width: 100%;
    align-items: flex-start;
    margin-bottom: 0.35rem;
}

.notification-style-toggle-btn {
    width: fit-content;
    display: inline-flex;
    align-items: center;
    gap: 0.45rem;
    padding: 0.35rem 0.58rem;
    font-size: 0.86rem;
    font-weight: 700;
}

.notification-style-toggle-icon {
    font-size: 0.75rem;
    opacity: 0.8;
}

.notification-style-card.compact {
    padding: 0.52rem;
}

.notification-style-popover {
    position: absolute;
    top: calc(100% + 0.35rem);
    right: 0;
    left: auto;
    width: 100%;
    max-width: 100%;
    z-index: 30;
    box-shadow: 0 14px 34px rgba(15, 23, 42, 0.14);
}

.notification-style-form {
    grid-template-columns: 1fr;
    gap: 0.45rem;
}

.notification-style-actions-row {
    display: flex;
    flex-wrap: wrap;
    align-items: center;
    justify-content: space-between;
    gap: 0.45rem;
}

.notification-carousel-panel {
    min-width: 0;
    display: grid;
    gap: 0.6rem;
}

.notification-preview-side {
    position: static;
    align-self: start;
}

.notification-carousel-header {
    display: grid;
    grid-template-columns: auto minmax(0, 1fr) auto;
    gap: 0.4rem;
    align-items: center;
    border: 1px solid #dbe4ee;
    border-radius: 12px;
    background: #f8fafc;
    padding: 0.35rem 0.45rem;
}

.notification-carousel-title {
    min-width: 0;
    display: grid;
    gap: 0.1rem;
    justify-items: center;
    text-align: center;
}

.notification-carousel-title strong {
    font-size: 0.9rem;
    color: #0f172a;
}

.carousel-nav-btn {
    width: 1.8rem;
    height: 1.8rem;
    border-radius: 999px;
    padding: 0;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    font-size: 1rem;
    line-height: 1;
}

.notification-carousel-dots {
    display: flex;
    gap: 0.45rem;
    overflow-x: auto;
    padding: 0.05rem 0.05rem 0.2rem;
}

.notification-carousel-dot {
    border: 1px solid #cbd5e1;
    border-radius: 999px;
    background: #ffffff;
    color: #334155;
    padding: 0.22rem 0.55rem;
    white-space: nowrap;
    font-size: 0.76rem;
    font-weight: 600;
}

.notification-carousel-dot.active {
    border-color: #1F4E79;
    background: #EDF3FA;
    color: #1F4E79;
}

.notification-dnd-sidebar {
    border: 1px solid #dbe4ee;
    border-radius: 12px;
    background: #f8fafc;
    padding: 0.6rem;
    display: grid;
    gap: 0.4rem;
    position: sticky;
    top: 0.6rem;
}

.notification-dnd-sidebar h3 {
    margin: 0;
    font-size: 0.9rem;
}

.notification-dnd-list {
    display: flex;
    flex-wrap: wrap;
    align-content: flex-start;
    gap: 0.35rem;
    max-height: 340px;
    overflow: auto;
    padding-right: 0.15rem;
}

.notification-dnd-chip {
    border: 1px solid #b9ccdf;
    border-radius: 999px;
    background: #eff6ff;
    color: #0f172a;
    padding: 0.14rem 0.48rem;
    font-size: 0.74rem;
    font-weight: 500;
    line-height: 1.25;
    width: auto;
    max-width: 100%;
    text-align: left;
    cursor: grab;
    transition: border-color 120ms ease, background-color 120ms ease;
}

.notification-dnd-chip:hover {
    border-color: #94a3b8;
    background: #e2edff;
}

.notification-dnd-chip:active {
    cursor: grabbing;
}

.notification-template-card {
    border: 1px solid #dbe4ee;
    border-radius: 12px;
    background: #f8fafc;
    padding: 0.62rem;
    display: grid;
    gap: 0.45rem;
}

.notification-template-header {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 0.6rem;
}

.notification-template-header h3 {
    margin: 0;
    font-size: 0.92rem;
}

.notification-template-form {
    grid-template-columns: 1fr;
    gap: 0.45rem;
}

.notification-template-form label {
    gap: 0.18rem;
    font-size: 0.93rem;
}

.notification-template-form input,
.notification-template-form textarea,
.notification-template-form select {
    padding: 0.36rem 0.5rem;
    min-height: 2.05rem;
    font-size: 0.92rem;
}

.notification-style-card input[type="color"] {
    width: 100%;
    height: 36px;
    padding: 0.15rem 0.2rem;
}

.email-preview-shell {
    border: 1px solid #dbe4ee;
    border-radius: 12px;
    background: #ffffff;
    overflow: hidden;
}

.email-preview-header {
    display: flex;
    align-items: center;
    gap: 0.55rem;
    color: #ffffff;
    padding: 0.6rem 0.75rem;
}

.email-preview-badge {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    width: 24px;
    height: 24px;
    border-radius: 8px;
    font-weight: 700;
    background: rgba(255, 255, 255, 0.22);
}

.email-preview-content {
    padding: 0.6rem 0.75rem 0.45rem;
    display: grid;
    gap: 0.28rem;
    max-height: 190px;
    overflow: auto;
}

.email-preview-content h4 {
    margin: 0;
    font-size: 0.92rem;
}

.email-preview-content p {
    margin: 0;
    color: #334155;
    font-size: 0.9rem;
}

.email-preview-footer {
    border-top: 1px solid #e2e8f0;
    padding: 0.55rem 0.75rem;
    display: grid;
    gap: 0.32rem;
}

.email-preview-footer small {
    color: #64748b;
}

.email-preview-button {
    justify-self: start;
    color: #ffffff;
    border-radius: 8px;
    padding: 0.35rem 0.62rem;
    border: 1px solid;
    font-weight: 600;
    font-size: 0.86rem;
}

.notification-drop-active {
    border-color: #1F4E79 !important;
    background: #EDF3FA;
    box-shadow: 0 0 0 2px rgba(31, 78, 121, 0.15);
}

.template-body-editor {
    min-height: 102px;
    border: 1px solid #cdd8e6;
    border-radius: 8px;
    background: linear-gradient(180deg, #ffffff 0%, #f8fbff 100%);
    padding: 0.38rem 0.5rem;
    line-height: 1.35;
    white-space: pre-wrap;
    word-break: break-word;
    overflow-wrap: anywhere;
}

.template-body-editor:focus {
    outline: none;
    border-color: #1F4E79;
    box-shadow: 0 0 0 2px rgba(31, 78, 121, 0.14);
}

.template-body-editor:empty::before {
    content: attr(data-placeholder);
    color: #94a3b8;
    pointer-events: none;
}

.template-inline-editor {
    min-height: 2.3rem;
    border: 1px solid #dbe4ee;
    border-radius: 8px;
    background: #ffffff;
    padding: 0.34rem 0.46rem;
    line-height: 1.3;
    white-space: nowrap;
    overflow-x: auto;
    overflow-y: hidden;
}

.template-inline-editor:focus {
    outline: none;
    border-color: #1F4E79;
    box-shadow: 0 0 0 2px rgba(31, 78, 121, 0.14);
}

.template-inline-editor:empty::before {
    content: attr(data-placeholder);
    color: #94a3b8;
    pointer-events: none;
}

.template-body-editor :deep(.template-token-chip),
.template-inline-editor :deep(.template-token-chip) {
    display: inline-flex;
    align-items: center;
    gap: 0.3rem;
    padding: 0.1rem 0.42rem;
    margin: 0 0.15rem 0.12rem 0;
    border: 1px solid #b9ccdf;
    border-radius: 999px;
    background: #eff6ff;
    color: #0f172a;
    user-select: none;
    cursor: grab;
    transition: border-color 120ms ease, background-color 120ms ease;
}

.template-body-editor :deep(.template-token-chip:hover),
.template-inline-editor :deep(.template-token-chip:hover) {
    border-color: #94a3b8;
    background: #e2edff;
}

.template-body-editor :deep(.template-token-chip:active),
.template-inline-editor :deep(.template-token-chip:active) {
    cursor: grabbing;
}

.template-body-editor :deep(.template-token-label),
.template-inline-editor :deep(.template-token-label) {
    max-width: 160px;
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
    font-size: 0.74rem;
    font-weight: 500;
}

.template-body-editor :deep(.template-token-remove),
.template-inline-editor :deep(.template-token-remove) {
    border: 0;
    background: transparent;
    padding: 0;
    line-height: 1;
    cursor: pointer;
    color: #475569;
    font-size: 1rem;
    display: inline-flex;
    align-items: center;
    justify-content: center;
}

.template-body-editor :deep(.template-token-remove:hover),
.template-inline-editor :deep(.template-token-remove:hover) {
    color: #334155;
}

.notification-template-actions {
    display: flex;
    justify-content: flex-end;
    flex-wrap: wrap;
    gap: 0.3rem;
}

.notification-template-actions button[disabled] {
    opacity: 0.75;
    cursor: wait;
}

.placeholders-help {
    font-size: 0.84rem;
    margin: 0.05rem 0 0.38rem;
}

label { display: grid; gap: 0.25rem; color: #334155; }
input, select, button, textarea {
    font: inherit;
    border: 1px solid #dbe4ee;
    border-radius: 8px;
    padding: 0.48rem 0.58rem;
}
button {
    background: #1F4E79;
    color: #fff;
    border-color: #1F4E79;
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

.toggle-switch {
    display: inline-flex;
    align-items: center;
    gap: 0.5rem;
    cursor: pointer;
    user-select: none;
    color: #0f172a;
    font-weight: 600;
}

.toggle-switch input {
    position: absolute;
    width: 1px;
    height: 1px;
    opacity: 0;
    pointer-events: none;
}

.toggle-track {
    width: 2.25rem;
    height: 1.3rem;
    border-radius: 999px;
    background: #cbd5e1;
    border: 1px solid #b6c4d6;
    position: relative;
    transition: background-color 140ms ease, border-color 140ms ease;
}

.toggle-thumb {
    position: absolute;
    top: 1px;
    left: 1px;
    width: 1rem;
    height: 1rem;
    border-radius: 999px;
    background: #ffffff;
    box-shadow: 0 1px 3px rgba(15, 23, 42, 0.2);
    transition: transform 140ms ease;
}

.toggle-switch input:checked + .toggle-track {
    background: #1F4E79;
    border-color: #1F4E79;
}

.toggle-switch input:checked + .toggle-track .toggle-thumb {
    transform: translateX(0.95rem);
}

.toggle-switch input:focus-visible + .toggle-track {
    box-shadow: 0 0 0 3px rgba(31, 78, 121, 0.22);
}

.toggle-text {
    font-size: 0.95rem;
}

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

.inbox-row-actions {
    vertical-align: bottom;
    padding-top: 0.78rem;
    padding-bottom: 0.35rem;
}

.inbox-actions-menu,
.entity-actions-menu,
.contact-actions-menu {
    position: relative;
    display: inline-flex;
}

.entity-actions-trigger {
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

.entity-actions-dropdown {
    position: absolute;
    top: calc(100% + 0.28rem);
    right: 0;
    z-index: 35;
    min-width: 146px;
    border: 1px solid #dbe4ee;
    border-radius: 10px;
    background: #fff;
    box-shadow: 0 12px 24px rgba(15, 23, 42, 0.18);
    padding: 0.32rem;
    display: grid;
    gap: 0.3rem;
}

.entity-menu-item {
    width: 100%;
    border: 1px solid #cbd5e1;
    background: #fff;
    color: #0f172a;
    text-align: left;
    border-radius: 8px;
    padding: 0.38rem 0.54rem;
}

.entity-menu-item:hover {
    background: #f1f5f9;
}

.entity-menu-item.danger {
    border-color: #b91c1c;
    background: #b91c1c;
    color: #fff;
}

.table-scroll {
    overflow-x: auto;
}

.entity-table {
    min-width: 1160px;
    table-layout: fixed;
}

.entity-table th,
.entity-table td {
    padding: 0.42rem 0.4rem;
}

.entity-table input,
.entity-table select {
    padding: 0.34rem 0.46rem;
}

.cell-text {
    display: block;
    max-width: 170px;
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
    color: #0f172a;
    line-height: 1.35;
}

.cell-strong {
    font-weight: 600;
}

.notes-text {
    max-width: 210px;
}

.entity-type-chip,
.status-chip,
.count-pill {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    border-radius: 999px;
    border: 1px solid transparent;
    padding: 0.14rem 0.46rem;
    font-size: 0.78rem;
    line-height: 1.2;
}

.entity-type-chip {
    background: #f8fafc;
    border-color: #dbe4ee;
    color: #334155;
}

.entity-type-chip.type-internal {
    background: #ecfeff;
    border-color: #a5f3fc;
    color: #155e75;
}

.entity-type-chip.type-external {
    background: #f1f5f9;
    border-color: #cbd5e1;
    color: #334155;
}

.status-chip.active {
    background: #edf4fb;
    border-color: #b9cde4;
    color: #1f4e79;
}

.status-chip.inactive {
    background: #fee2e2;
    border-color: #fecaca;
    color: #991b1b;
}

.count-pill {
    min-width: 1.9rem;
    background: #f8fafc;
    border-color: #dbe4ee;
    color: #334155;
}

.count-link {
    cursor: pointer;
}

.count-link:hover {
    background: #EDF3FA;
    border-color: #9ab9d8;
    color: #1F4E79;
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

.section-actions {
    display: flex;
    justify-content: flex-start;
}

.entity-ddl {
    position: relative;
    display: grid;
    gap: 0.45rem;
    z-index: 12;
}

.entity-ddl-toggle {
    width: 100%;
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 0.6rem;
    border: 1px solid #dbe4ee;
    border-radius: 8px;
    background: #fff;
    color: #0f172a;
    padding: 0.48rem 0.58rem;
    cursor: pointer;
}

.entity-ddl-value {
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
}

.entity-ddl-arrow {
    width: 16px;
    height: 16px;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    color: #64748b;
    transition: transform 120ms ease;
}

.entity-ddl-arrow svg {
    width: 14px;
    height: 14px;
}

.entity-ddl-arrow.open {
    transform: rotate(180deg);
}

.entity-ddl-panel {
    position: absolute;
    top: calc(100% + 0.38rem);
    left: 0;
    right: 0;
    z-index: 18;
    border: 1px solid #dbe4ee;
    border-radius: 10px;
    background: #fff;
    box-shadow: 0 14px 34px rgba(15, 23, 42, 0.18);
    padding: 0.45rem;
    display: grid;
    gap: 0.45rem;
}

.entity-ddl-search-wrap {
    padding: 0.15rem;
}

.entity-ddl-search {
    width: 100%;
}

.mini-btn {
    padding: 0.34rem 0.52rem;
    font-size: 0.84rem;
}

.entity-ddl-list {
    max-height: 208px;
    overflow: auto;
    border: 1px solid #dbe4ee;
    border-radius: 8px;
    display: grid;
    align-content: start;
}

.entity-ddl-option {
    width: 100%;
    border: 0;
    border-bottom: 1px solid #e2e8f0;
    border-radius: 0;
    background: #fff;
    color: #0f172a;
    padding: 0.5rem 0.65rem;
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 0.6rem;
    text-align: left;
}

.entity-ddl-option:last-of-type {
    border-bottom: 0;
}

.entity-ddl-option:hover {
    background: #f1f5f9;
}

.entity-ddl-option.selected {
    background: #334155;
    color: #fff;
}

.entity-ddl-option-name {
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
}

.entity-ddl-option-check {
    min-width: 1rem;
    text-align: right;
    font-weight: 700;
}

.entity-ddl-footer {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 0.5rem;
    padding: 0.2rem 0.25rem 0.05rem;
}

.entity-ddl-selected {
    display: flex;
    flex-wrap: wrap;
    gap: 0.4rem;
}

.entity-ddl-tag {
    border: 1px solid #9ab9d8;
    background: #EDF3FA;
    color: #1F4E79;
    border-radius: 999px;
    padding: 0.2rem 0.56rem;
    font-size: 0.84rem;
}

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
    width: min(1100px, calc(100vw - 2rem));
    max-height: calc(100vh - 2rem);
    overflow: auto;
    background: #fff;
    border: 1px solid #dbe4ee;
    border-radius: 14px;
    padding: 0.95rem;
    display: grid;
    gap: 0.8rem;
}

.modal-header {
    display: flex;
    align-items: flex-start;
    justify-content: space-between;
    gap: 0.8rem;
}

.modal-header h3 {
    margin: 0;
}

.modal-header p {
    margin: 0.25rem 0 0;
}

.modal-actions {
    display: flex;
    align-items: center;
    gap: 0.5rem;
}

@media (max-width: 960px) {
    .form-grid,
    .filters { grid-template-columns: 1fr; }

    .notification-layout-grid { grid-template-columns: 1fr; }
    .notification-builder-shell { grid-template-columns: 1fr; }
    .notification-template-grid { grid-template-columns: 1fr; }
    .notification-style-form { grid-template-columns: 1fr; }
    .notification-style-actions-row { justify-content: flex-start; }
    .notification-right-column {
        position: static;
    }
    .notification-preview-side {
        position: static;
    }
    .notification-style-popover {
        position: static;
        width: 100%;
        margin-top: 0.4rem;
        box-shadow: none;
    }
    .notification-carousel-header {
        grid-template-columns: 1fr;
        justify-items: center;
    }
    .notification-carousel-title {
        order: -1;
    }
    .notification-dnd-sidebar {
        position: static;
    }

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
