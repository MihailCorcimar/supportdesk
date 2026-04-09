<script setup>
import { computed, nextTick, onBeforeUnmount, onMounted, reactive, ref, watch } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import api from '../api/client';
import QuickActionsRail from '../components/QuickActionsRail.vue';
import QuickMenuPanel from '../components/QuickMenuPanel.vue';

const route = useRoute();
const router = useRouter();

const COUNTRIES = [
    { code: 'PT', name: 'Portugal' },
    { code: 'BR', name: 'Brasil' },
    { code: 'AO', name: 'Angola' },
    { code: 'MZ', name: 'Moçambique' },
    { code: 'CV', name: 'Cabo Verde' },
    { code: 'ST', name: 'São Tomé e Príncipe' },
    { code: 'GW', name: 'Guiné-Bissau' },
    { code: 'TL', name: 'Timor-Leste' },
    { code: '—', name: '──────────────' },
    { code: 'ES', name: 'Espanha' },
    { code: 'FR', name: 'França' },
    { code: 'DE', name: 'Alemanha' },
    { code: 'IT', name: 'Itália' },
    { code: 'GB', name: 'Reino Unido' },
    { code: 'NL', name: 'Países Baixos' },
    { code: 'BE', name: 'Bélgica' },
    { code: 'CH', name: 'Suíça' },
    { code: 'AT', name: 'Áustria' },
    { code: 'SE', name: 'Suécia' },
    { code: 'NO', name: 'Noruega' },
    { code: 'DK', name: 'Dinamarca' },
    { code: 'FI', name: 'Finlândia' },
    { code: 'IE', name: 'Irlanda' },
    { code: 'GR', name: 'Grécia' },
    { code: 'PL', name: 'Polónia' },
    { code: 'CZ', name: 'República Checa' },
    { code: 'HU', name: 'Hungria' },
    { code: 'RO', name: 'Roménia' },
    { code: 'BG', name: 'Bulgária' },
    { code: 'HR', name: 'Croácia' },
    { code: 'SK', name: 'Eslováquia' },
    { code: 'SI', name: 'Eslovénia' },
    { code: 'EE', name: 'Estónia' },
    { code: 'LV', name: 'Letónia' },
    { code: 'LT', name: 'Lituânia' },
    { code: 'LU', name: 'Luxemburgo' },
    { code: 'MT', name: 'Malta' },
    { code: 'CY', name: 'Chipre' },
    { code: 'US', name: 'Estados Unidos' },
    { code: 'MX', name: 'México' },
    { code: 'AR', name: 'Argentina' },
    { code: 'ZA', name: 'África do Sul' },
];

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
const showEmailPreviewPanel = ref(false);
const savingNotificationEventKey = ref('');
const savingEmailStyle = ref(false);
const notificationQuickActions = computed(() => ([
    {
        id: 'email_style',
        title: 'Aspeto visual do email',
        active: showEmailStylePanel.value,
    },
    {
        id: 'email_preview',
        title: 'Pré-visualização',
        active: showEmailPreviewPanel.value,
    },
]));
const notificationQuickDocked = computed(() => showEmailStylePanel.value || showEmailPreviewPanel.value);
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
const inboxImageFile = ref(null);
const inboxImagePreviewUrl = ref('');
const inboxEditForm = reactive({
    name: '',
    is_active: true,
    operator_ids: [],
    image_url: '',
});
const inboxEditImageFile = ref(null);
const inboxEditImagePreviewUrl = ref('');
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
const logFilters = reactive({ search: '', action: '', actor_type: '', date_from: '', date_to: '' });
const logsLoading = ref(false);
const logsMeta = ref(null);
const logsPage = ref(1);

const editingInboxId = ref(null);
const editingEntityId = ref(null);
const editingContactId = ref(null);
const showInboxCreateModal = ref(false);
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
const handleNotificationQuickAction = (actionId) => {
    if (actionId === 'email_style') {
        showEmailStylePanel.value = !showEmailStylePanel.value;
    }
    if (actionId === 'email_preview') {
        showEmailPreviewPanel.value = !showEmailPreviewPanel.value;
    }
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

const previewSampleValues = Object.freeze({
    '{ticket_number}': 'TC-000001',
    '{subject}': 'Falha na máquina de embalar',
    '{status}': 'Em tratamento',
    '{priority}': 'Alta',
    '{type}': 'Incidente',
    '{inbox}': 'Suporte Geral',
    '{entity}': 'ACME Lda.',
    '{contact}': 'João Silva',
    '{creator_name}': 'Maria Santos',
    '{assigned_operator}': 'Pedro Costa',
    '{author_name}': 'João Silva',
    '{message_preview}': 'Bom dia, a máquina de embalar da linha 3 parou subitamente sem aviso prévio.',
    '{cc_emails}': 'gestor@empresa.pt, diretor@empresa.pt',
    '{ticket_url}': '#',
});

const resolvePreviewTemplate = (text) =>
    String(text ?? '')
        .replace(/\{[a-z_]+\}/gi, (match) => previewSampleValues[match] ?? match)
        .replace(/\n/g, '<br>');

const previewSubjectHtml = computed(() =>
    resolvePreviewTemplate(activeNotificationTemplate.value?.subject_template || '')
);
const previewTitleText = computed(() =>
    String(activeNotificationTemplate.value?.title_template || 'Título do email')
        .replace(/\{[a-z_]+\}/gi, (match) => previewSampleValues[match] ?? match)
);
const previewBodyHtml = computed(() =>
    resolvePreviewTemplate(activeNotificationTemplate.value?.body_template || 'Corpo configurável do email.')
);
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

const loadLogs = async (page = logsPage.value) => {
    logsLoading.value = true;

    try {
        const params = { per_page: 30, page };

        if (logFilters.search.trim()) params.search = logFilters.search.trim();
        if (logFilters.action) params.action = logFilters.action;
        if (logFilters.actor_type) params.actor_type = logFilters.actor_type;
        if (logFilters.date_from) params.date_from = logFilters.date_from;
        if (logFilters.date_to) params.date_to = logFilters.date_to;

        const response = await api.get('/ticket-logs', { params });
        logs.value = response.data.data;
        logsMeta.value = response.data.meta || null;
        logsPage.value = page;
    } finally {
        logsLoading.value = false;
    }
};

const goToLogsPage = (page) => {
    if (!logsMeta.value) return;
    if (page < 1 || page > logsMeta.value.last_page) return;
    loadLogs(page);
};

const clearLogFilters = () => {
    logFilters.search = '';
    logFilters.action = '';
    logFilters.actor_type = '';
    logFilters.date_from = '';
    logFilters.date_to = '';
    loadLogs(1);
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
    inboxEditForm.image_url = '';
    inboxOperatorSearch.value = '';
    inboxOperatorDropdownOpen.value = false;
};

const setInboxImageFile = (event) => {
    const file = event.target?.files?.[0] || null;
    inboxImageFile.value = file;
    if (inboxImagePreviewUrl.value) {
        URL.revokeObjectURL(inboxImagePreviewUrl.value);
        inboxImagePreviewUrl.value = '';
    }
    if (file) {
        inboxImagePreviewUrl.value = URL.createObjectURL(file);
    }
};

const setInboxEditImageFile = (event) => {
    const file = event.target?.files?.[0] || null;
    inboxEditImageFile.value = file;
    if (inboxEditImagePreviewUrl.value && inboxEditImagePreviewUrl.value.startsWith('blob:')) {
        URL.revokeObjectURL(inboxEditImagePreviewUrl.value);
    }
    inboxEditImagePreviewUrl.value = file
        ? URL.createObjectURL(file)
        : (inboxEditForm.image_url || '');
};

const openInboxCreateModal = () => {
    resetMessages();
    inboxForm.name = '';
    inboxForm.is_active = true;
    inboxImageFile.value = null;
    if (inboxImagePreviewUrl.value) {
        URL.revokeObjectURL(inboxImagePreviewUrl.value);
    }
    inboxImagePreviewUrl.value = '';
    showInboxCreateModal.value = true;
};

const closeInboxCreateModal = () => {
    showInboxCreateModal.value = false;
    inboxForm.name = '';
    inboxForm.is_active = true;
    inboxImageFile.value = null;
    if (inboxImagePreviewUrl.value) {
        URL.revokeObjectURL(inboxImagePreviewUrl.value);
    }
    inboxImagePreviewUrl.value = '';
};

const openInboxEditModal = (inbox) => {
    resetMessages();
    closeInboxActionsMenu();
    editingInboxId.value = inbox.id;
    inboxEditForm.name = inbox.name ?? '';
    inboxEditForm.is_active = Boolean(inbox.is_active);
    inboxEditForm.operator_ids = (inbox.operators || []).map((operator) => Number(operator.id));
    inboxEditForm.image_url = inbox.image_url || '';
    inboxOperatorSearch.value = '';
    inboxOperatorDropdownOpen.value = false;
    inboxEditImageFile.value = null;
    inboxEditImagePreviewUrl.value = inbox.image_url || '';
    showInboxEditModal.value = true;
};

const closeInboxEditModal = () => {
    showInboxEditModal.value = false;
    editingInboxId.value = null;
    resetInboxEditForm();
    if (inboxEditImagePreviewUrl.value && inboxEditImagePreviewUrl.value.startsWith('blob:')) {
        URL.revokeObjectURL(inboxEditImagePreviewUrl.value);
    }
    inboxEditImagePreviewUrl.value = '';
    inboxEditImageFile.value = null;
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
        const formData = new FormData();
        formData.append('name', inboxForm.name);
        formData.append('is_active', inboxForm.is_active ? '1' : '0');
        if (inboxImageFile.value) {
            formData.append('image', inboxImageFile.value);
        }

        await api.post('/inboxes', formData, {
            headers: { 'Content-Type': 'multipart/form-data' },
        });
        success.value = 'Inbox criada com sucesso.';
        closeInboxCreateModal();
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

const goToEntityTickets = async (entityId) => {
    await router.push({
        name: 'tickets.index',
        query: {
            entity_id: String(entityId),
        },
    });
};

const saveInbox = async () => {
    if (!editingInboxId.value) return;

    resetMessages();

    try {
        const hasImage = Boolean(inboxEditImageFile.value);
        if (hasImage) {
            const formData = new FormData();
            formData.append('name', inboxEditForm.name);
            formData.append('is_active', inboxEditForm.is_active ? '1' : '0');
            (inboxEditForm.operator_ids || []).forEach((id) => {
                formData.append('operator_ids[]', String(Number(id)));
            });
            formData.append('image', inboxEditImageFile.value);

            await api.patch(`/inboxes/${editingInboxId.value}`, formData, {
                headers: { 'Content-Type': 'multipart/form-data' },
            });
        } else {
            await api.patch(`/inboxes/${editingInboxId.value}`, {
                name: inboxEditForm.name,
                is_active: inboxEditForm.is_active,
                operator_ids: (inboxEditForm.operator_ids || []).map((id) => Number(id)),
            });
        }
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
        await loadLogs(logsPage.value);
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
    if (logSearchDebounceTimer) clearTimeout(logSearchDebounceTimer);
    logSearchDebounceTimer = setTimeout(() => loadLogs(1), 260);
};

const applyLogFiltersImmediately = () => {
    if (logSearchDebounceTimer) clearTimeout(logSearchDebounceTimer);
    loadLogs(1);
};

const formatDate = (value) => {
    if (!value) return '-';
    return new Date(value).toLocaleString('pt-PT');
};

const LOG_STATUS_LABELS = {
    open: 'Aberto',
    in_progress: 'Em tratamento',
    pending: 'Aguardando cliente',
    closed: 'Fechado',
    cancelled: 'Cancelado',
};

const LOG_PRIORITY_LABELS = {
    low: 'Baixa',
    medium: 'Média',
    high: 'Alta',
    urgent: 'Urgente',
};

const LOG_TYPE_LABELS = {
    question: 'Questão',
    incident: 'Incidente',
    request: 'Pedido',
    task: 'Tarefa',
};

const LOG_FIELD_LABELS = {
    status: 'Estado',
    priority: 'Prioridade',
    type: 'Tipo',
    assigned_operator_id: 'Atribuído a',
    follower_user_ids: 'Seguidores',
    subject: 'Assunto',
    inbox_id: 'Caixa de entrada',
};

const formatLogField = (field) => LOG_FIELD_LABELS[field] ?? field;

const formatLogValue = (field, value) => {
    if (value === null || value === undefined || value === '') return '—';
    if (field === 'status') return LOG_STATUS_LABELS[value] ?? value;
    if (field === 'priority') return LOG_PRIORITY_LABELS[value] ?? value;
    if (field === 'type') return LOG_TYPE_LABELS[value] ?? value;
    return value;
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

    if (normalized !== 'notifications') {
        showEmailStylePanel.value = false;
        showEmailPreviewPanel.value = false;
    }

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
                <div class="entities-header">
                    <div>
                        <h2>Inboxes</h2>
                        <p class="entities-subtitle">
                            {{ inboxes.length }} {{ inboxes.length === 1 ? 'inbox registada' : 'inboxes registadas' }}
                        </p>
                    </div>
                    <button type="button" class="btn-entity-new" @click="openInboxCreateModal">
                        <svg viewBox="0 0 20 20" fill="none" width="15" height="15" aria-hidden="true">
                            <path d="M10 4v12M4 10h12" stroke="currentColor" stroke-width="2.2" stroke-linecap="round"/>
                        </svg>
                        Nova inbox
                    </button>
                </div>

                <!-- ── Create modal ── -->
                <Teleport to="body">
                    <section v-if="showInboxCreateModal" class="entity-modal-overlay" @click.self="closeInboxCreateModal">
                        <article class="entity-modal-card" style="max-width:460px">
                            <header class="entity-modal-header">
                                <div class="entity-modal-header-icon">
                                    <svg viewBox="0 0 24 24" fill="none" width="20" height="20" aria-hidden="true">
                                        <path d="M4 4h16v16H4z" rx="2" stroke="currentColor" stroke-width="1.8" stroke-linejoin="round"/>
                                        <path d="M4 9l8 5 8-5" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"/>
                                    </svg>
                                </div>
                                <div class="entity-modal-header-text">
                                    <h3>Nova inbox</h3>
                                    <p>Caixa de entrada para organizar tickets.</p>
                                </div>
                                <button type="button" class="entity-modal-close" aria-label="Fechar" @click="closeInboxCreateModal">
                                    <svg viewBox="0 0 20 20" fill="none" width="16" height="16"><path d="M5 5l10 10M15 5L5 15" stroke="currentColor" stroke-width="1.9" stroke-linecap="round"/></svg>
                                </button>
                            </header>

                            <form @submit.prevent="createInbox">
                                <div class="entity-modal-body">
                                    <div class="emf-section emf-section-last">
                                        <div class="emf-grid emf-grid-2">
                                            <label class="emf-field emf-col-2">
                                                <span>Nome <em class="emf-required">*</em></span>
                                                <input v-model="inboxForm.name" required placeholder="Ex: Suporte Geral" />
                                            </label>
                                            <label class="emf-field emf-col-2">
                                                <span>Imagem da inbox</span>
                                                <input type="file" accept="image/*" @change="setInboxImageFile" />
                                                <small class="muted">PNG ou JPG até 4MB.</small>
                                            </label>
                                            <div v-if="inboxImagePreviewUrl" class="inbox-image-preview">
                                                <img :src="inboxImagePreviewUrl" alt="Pré-visualização da inbox" />
                                            </div>
                                        </div>
                                        <label class="emf-toggle-row" style="margin-top:0.65rem">
                                            <span class="emf-toggle-label">Inbox ativa</span>
                                            <button
                                                type="button"
                                                role="switch"
                                                :aria-checked="inboxForm.is_active"
                                                :class="['emf-toggle-btn', { 'emf-toggle-on': inboxForm.is_active }]"
                                                @click="inboxForm.is_active = !inboxForm.is_active"
                                            >
                                                <span class="emf-toggle-thumb" />
                                            </button>
                                        </label>
                                    </div>
                                </div>
                                <footer class="entity-modal-footer">
                                    <button type="button" class="ghost" @click="closeInboxCreateModal">Cancelar</button>
                                    <button type="submit">Criar inbox</button>
                                </footer>
                            </form>
                        </article>
                    </section>
                </Teleport>

                <!-- ── Edit modal ── -->
                <Teleport to="body">
                    <section v-if="showInboxEditModal" class="entity-modal-overlay" @click.self="closeInboxEditModal">
                        <article class="entity-modal-card" style="max-width:540px">
                            <header class="entity-modal-header">
                                <div class="entity-modal-header-icon">
                                    <svg viewBox="0 0 24 24" fill="none" width="20" height="20" aria-hidden="true">
                                        <path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/>
                                        <path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/>
                                    </svg>
                                </div>
                                <div class="entity-modal-header-text">
                                    <h3>Editar inbox</h3>
                                    <p>Atualiza os dados e operadores associados.</p>
                                </div>
                                <button type="button" class="entity-modal-close" aria-label="Fechar" @click="closeInboxEditModal">
                                    <svg viewBox="0 0 20 20" fill="none" width="16" height="16"><path d="M5 5l10 10M15 5L5 15" stroke="currentColor" stroke-width="1.9" stroke-linecap="round"/></svg>
                                </button>
                            </header>

                            <form @submit.prevent="saveInbox">
                                <div class="entity-modal-body">
                                    <div class="emf-section">
                                        <p class="emf-section-title">Identificação</p>
                                        <div class="emf-grid emf-grid-2">
                                            <label class="emf-field emf-col-2">
                                                <span>Nome <em class="emf-required">*</em></span>
                                                <input v-model="inboxEditForm.name" required />
                                            </label>
                                            <label class="emf-field emf-col-2">
                                                <span>Imagem da inbox</span>
                                                <input type="file" accept="image/*" @change="setInboxEditImageFile" />
                                                <small class="muted">PNG ou JPG até 4MB.</small>
                                            </label>
                                            <div v-if="inboxEditImagePreviewUrl" class="inbox-image-preview">
                                                <img :src="inboxEditImagePreviewUrl" alt="Imagem atual da inbox" />
                                            </div>
                                        </div>
                                        <label class="emf-toggle-row" style="margin-top:0.65rem">
                                            <span class="emf-toggle-label">Inbox ativa</span>
                                            <button
                                                type="button"
                                                role="switch"
                                                :aria-checked="inboxEditForm.is_active"
                                                :class="['emf-toggle-btn', { 'emf-toggle-on': inboxEditForm.is_active }]"
                                                @click="inboxEditForm.is_active = !inboxEditForm.is_active"
                                            >
                                                <span class="emf-toggle-thumb" />
                                            </button>
                                        </label>
                                    </div>

                                    <div class="emf-section emf-section-last">
                                        <p class="emf-section-title">Operadores</p>
                                        <div class="entity-ddl inbox-operators-ddl">
                                            <button type="button" class="entity-ddl-toggle" @click="toggleInboxOperatorDropdown">
                                                <span class="entity-ddl-value">{{ inboxOperatorDropdownLabel }}</span>
                                                <span class="entity-ddl-arrow" :class="{ open: inboxOperatorDropdownOpen }" aria-hidden="true">
                                                    <svg viewBox="0 0 20 20" fill="none" width="16" height="16">
                                                        <path d="M5 7.5L10 12.5L15 7.5" stroke="currentColor" stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8" />
                                                    </svg>
                                                </span>
                                            </button>
                                            <div v-if="inboxOperatorDropdownOpen" class="entity-ddl-panel">
                                                <div class="entity-ddl-search-wrap">
                                                    <input v-model="inboxOperatorSearch" class="entity-ddl-search" type="search" placeholder="Pesquisar operadores..." />
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
                                                        <span class="entity-ddl-option-name">{{ operator.name }}<small v-if="!operator.is_active"> (inativo)</small></span>
                                                        <span class="entity-ddl-option-check">{{ isInboxOperatorSelected(operator.id) ? '✓' : '' }}</span>
                                                    </button>
                                                    <p v-if="!filteredInboxOperators.length" class="muted">Sem operadores para este filtro.</p>
                                                </div>
                                                <div class="entity-ddl-footer">
                                                    <small>{{ selectedInboxOperators.length }} selecionados</small>
                                                    <button type="button" class="ghost mini-btn" @click="clearInboxOperatorsSelection">Limpar</button>
                                                </div>
                                            </div>
                                            <div v-if="selectedInboxOperators.length" class="entity-ddl-selected">
                                                <button
                                                    v-for="operator in selectedInboxOperators"
                                                    :key="`selected-inbox-operator-${operator.id}`"
                                                    type="button"
                                                    class="entity-ddl-tag"
                                                    @click="toggleInboxOperator(operator.id)"
                                                >{{ operator.name }}</button>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                                <footer class="entity-modal-footer">
                                    <button type="button" class="ghost" @click="closeInboxEditModal">Cancelar</button>
                                    <button type="submit">Guardar alterações</button>
                                </footer>
                            </form>
                        </article>
                    </section>
                </Teleport>

                <table class="table">
                    <thead>
                        <tr>
                            <th>Nome</th>
                            <th>Estado</th>
                            <th>Tickets</th>
                            <th>Operadores</th>
                            <th style="text-align:right">Ações</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="item in inboxes" :key="item.id" class="entity-row">
                            <td>
                                <div class="entity-name-cell">
                                    <span v-if="item.image_url" class="entity-initials entity-initials-image">
                                        <img :src="item.image_url" :alt="`Imagem da inbox ${item.name}`" />
                                    </span>
                                    <span v-else class="entity-initials type-external">
                                        {{ (item.name || '?').slice(0, 2).toUpperCase() }}
                                    </span>
                                    <span class="entity-name-text">{{ item.name }}</span>
                                </div>
                            </td>
                            <td>
                                <span class="status-chip" :class="item.is_active ? 'active' : 'inactive'">
                                    {{ item.is_active ? 'Ativa' : 'Inativa' }}
                                </span>
                            </td>
                            <td>
                                <button type="button" class="count-pill count-link" @click="goToInboxTickets(item.id)">
                                    <svg viewBox="0 0 12 12" fill="none" aria-hidden="true">
                                        <path d="M1.5 3A1 1 0 0 1 2.5 2h7A1 1 0 0 1 10.5 3v1.5a1 1 0 0 0 0 2V8a1 1 0 0 1-1 1h-7a1 1 0 0 1-1-1V6.5a1 1 0 0 0 0-2V3Z" stroke="currentColor" stroke-width="1.1"/>
                                    </svg>
                                    {{ item.tickets_count }}
                                </button>
                            </td>
                            <td>
                                <div class="inbox-operators-cell">
                                    <span class="count-pill">{{ item.operators_count }}</span>
                                    <span class="cell-text notes-text" :title="(item.operators || []).map(o => o.name).join(', ') || '—'">
                                        {{ (item.operators || []).map(o => o.name).join(', ') || '—' }}
                                    </span>
                                </div>
                            </td>
                            <td>
                                <div class="entity-row-actions">
                                    <button type="button" class="entity-row-btn" title="Editar" @click="openInboxEditModal(item)">
                                        <svg viewBox="0 0 18 18" fill="none" width="14" height="14"><path d="M10.5 3.5l4 4L5 17H1v-4L10.5 3.5z" stroke="currentColor" stroke-width="1.6" stroke-linejoin="round"/><path d="M8 6l4 4" stroke="currentColor" stroke-width="1.6"/></svg>
                                    </button>
                                    <button type="button" class="entity-row-btn entity-row-btn-danger" title="Eliminar" @click="deleteInbox(item)">
                                        <svg viewBox="0 0 18 18" fill="none" width="14" height="14"><path d="M3 5h12M8 8v5M10 8v5M4 5l1 10h8l1-10" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"/><path d="M7 5V3h4v2" stroke="currentColor" stroke-width="1.6" stroke-linejoin="round"/></svg>
                                    </button>
                                </div>
                            </td>
                        </tr>
                        <tr v-if="!inboxes.length">
                            <td colspan="5" class="entity-empty">
                                <svg viewBox="0 0 24 24" fill="none" width="28" height="28"><rect x="2" y="4" width="20" height="16" rx="2" stroke="currentColor" stroke-width="1.6"/><path d="M2 9l10 6 10-6" stroke="currentColor" stroke-width="1.6"/></svg>
                                <span>Nenhuma inbox registada.</span>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </template>

            <template v-if="!loading && activeTab === 'entities'">
                <!-- ── Entities header ── -->
                <div class="entities-header">
                    <div>
                        <h2>Entidades</h2>
                        <p class="entities-subtitle">
                            {{ entities.length }} {{ entities.length === 1 ? 'entidade registada' : 'entidades registadas' }}
                        </p>
                    </div>
                    <button type="button" class="btn-entity-new" @click="openEntityCreateModal">
                        <svg viewBox="0 0 20 20" fill="none" width="15" height="15" aria-hidden="true">
                            <path d="M10 4v12M4 10h12" stroke="currentColor" stroke-width="2.2" stroke-linecap="round"/>
                        </svg>
                        Nova entidade
                    </button>
                </div>

                <!-- ── Create modal ── -->
                <Teleport to="body">
                    <section v-if="showEntityCreateModal" class="entity-modal-overlay" @click.self="closeEntityCreateModal">
                        <article class="entity-modal-card">
                            <header class="entity-modal-header">
                                <div class="entity-modal-header-icon">
                                    <svg viewBox="0 0 24 24" fill="none" width="20" height="20" aria-hidden="true">
                                        <path d="M3 21h18M5 21V7l7-4 7 4v14" stroke="currentColor" stroke-width="1.8" stroke-linejoin="round"/>
                                        <path d="M9 21v-6h6v6" stroke="currentColor" stroke-width="1.8" stroke-linejoin="round"/>
                                    </svg>
                                </div>
                                <div class="entity-modal-header-text">
                                    <h3>Nova entidade</h3>
                                    <p>Preenche os dados da entidade para criar.</p>
                                </div>
                                <button type="button" class="entity-modal-close" aria-label="Fechar" @click="closeEntityCreateModal">
                                    <svg viewBox="0 0 20 20" fill="none" width="16" height="16"><path d="M5 5l10 10M15 5L5 15" stroke="currentColor" stroke-width="1.9" stroke-linecap="round"/></svg>
                                </button>
                            </header>

                            <form @submit.prevent="createEntity">
                                <div class="entity-modal-body">
                                    <div class="emf-section">
                                        <p class="emf-section-title">Identificação</p>
                                        <div class="emf-grid emf-grid-id">
                                            <label class="emf-field">
                                                <span>Tipo</span>
                                                <select v-model="entityForm.type">
                                                    <option value="external">Externa</option>
                                                    <option value="internal">Interna</option>
                                                </select>
                                            </label>
                                            <label class="emf-field emf-col-grow">
                                                <span>Nome <em class="emf-required">*</em></span>
                                                <input v-model="entityForm.name" required placeholder="Nome da entidade" />
                                            </label>
                                            <label class="emf-field">
                                                <span>NIF</span>
                                                <input v-model="entityForm.tax_number" placeholder="000 000 000" />
                                            </label>
                                        </div>
                                    </div>

                                    <div class="emf-section">
                                        <p class="emf-section-title">Contacto</p>
                                        <div class="emf-grid emf-grid-2">
                                            <label class="emf-field emf-col-2">
                                                <span>Email</span>
                                                <input v-model="entityForm.email" type="email" placeholder="email@empresa.pt" />
                                            </label>
                                            <label class="emf-field">
                                                <span>Telefone</span>
                                                <input v-model="entityForm.phone" placeholder="+351 200 000 000" />
                                            </label>
                                            <label class="emf-field">
                                                <span>Telemóvel</span>
                                                <input v-model="entityForm.mobile_phone" placeholder="+351 900 000 000" />
                                            </label>
                                            <label class="emf-field emf-col-2">
                                                <span>Website</span>
                                                <input v-model="entityForm.website" type="url" placeholder="https://..." />
                                            </label>
                                        </div>
                                    </div>

                                    <div class="emf-section">
                                        <p class="emf-section-title">Localização</p>
                                        <div class="emf-grid emf-grid-loc">
                                            <label class="emf-field emf-col-loc-full">
                                                <span>Morada</span>
                                                <input v-model="entityForm.address_line" placeholder="Rua, número, andar..." />
                                            </label>
                                            <label class="emf-field">
                                                <span>Código postal</span>
                                                <input v-model="entityForm.postal_code" maxlength="20" placeholder="0000-000" />
                                            </label>
                                            <label class="emf-field">
                                                <span>Cidade</span>
                                                <input v-model="entityForm.city" placeholder="Lisboa" />
                                            </label>
                                            <label class="emf-field">
                                                <span>País</span>
                                                <select v-model="entityForm.country">
                                                    <option value="">Selecionar país</option>
                                                    <option
                                                        v-for="c in COUNTRIES"
                                                        :key="c.code"
                                                        :value="c.code"
                                                        :disabled="c.code === '—'"
                                                    >{{ c.name }}</option>
                                                </select>
                                            </label>
                                        </div>
                                    </div>

                                    <div class="emf-section emf-section-last">
                                        <label class="emf-toggle-row">
                                            <span class="emf-toggle-label">Entidade ativa</span>
                                            <button
                                                type="button"
                                                role="switch"
                                                :aria-checked="entityForm.is_active"
                                                :class="['emf-toggle-btn', { 'emf-toggle-on': entityForm.is_active }]"
                                                @click="entityForm.is_active = !entityForm.is_active"
                                            >
                                                <span class="emf-toggle-thumb" />
                                            </button>
                                        </label>
                                        <label class="emf-field">
                                            <span>Notas internas</span>
                                            <textarea v-model="entityForm.notes" rows="3" placeholder="Observações visíveis apenas internamente..."></textarea>
                                        </label>
                                    </div>
                                </div>

                                <footer class="entity-modal-footer">
                                    <button type="button" class="ghost" @click="closeEntityCreateModal">Cancelar</button>
                                    <button type="submit">Criar entidade</button>
                                </footer>
                            </form>
                        </article>
                    </section>
                </Teleport>

                <!-- ── Edit modal ── -->
                <Teleport to="body">
                    <section v-if="showEntityEditModal" class="entity-modal-overlay" @click.self="closeEntityEditModal">
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
                                <button type="button" class="entity-modal-close" aria-label="Fechar" @click="closeEntityEditModal">
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
                                                <input v-model="entityEditForm.phone" placeholder="+351 200 000 000" />
                                            </label>
                                            <label class="emf-field">
                                                <span>Telemóvel</span>
                                                <input v-model="entityEditForm.mobile_phone" placeholder="+351 900 000 000" />
                                            </label>
                                            <label class="emf-field emf-col-2">
                                                <span>Website</span>
                                                <input v-model="entityEditForm.website" type="url" placeholder="https://..." />
                                            </label>
                                        </div>
                                    </div>

                                    <div class="emf-section">
                                        <p class="emf-section-title">Localização</p>
                                        <div class="emf-grid emf-grid-loc">
                                            <label class="emf-field emf-col-loc-full">
                                                <span>Morada</span>
                                                <input v-model="entityEditForm.address_line" placeholder="Rua, número, andar..." />
                                            </label>
                                            <label class="emf-field">
                                                <span>Código postal</span>
                                                <input v-model="entityEditForm.postal_code" maxlength="20" placeholder="0000-000" />
                                            </label>
                                            <label class="emf-field">
                                                <span>Cidade</span>
                                                <input v-model="entityEditForm.city" placeholder="Lisboa" />
                                            </label>
                                            <label class="emf-field">
                                                <span>País</span>
                                                <select v-model="entityEditForm.country">
                                                    <option value="">Selecionar país</option>
                                                    <option
                                                        v-for="c in COUNTRIES"
                                                        :key="c.code"
                                                        :value="c.code"
                                                        :disabled="c.code === '—'"
                                                    >{{ c.name }}</option>
                                                </select>
                                            </label>
                                        </div>
                                    </div>

                                    <div class="emf-section emf-section-last">
                                        <label class="emf-toggle-row">
                                            <span class="emf-toggle-label">Entidade ativa</span>
                                            <button
                                                type="button"
                                                role="switch"
                                                :aria-checked="entityEditForm.is_active"
                                                :class="['emf-toggle-btn', { 'emf-toggle-on': entityEditForm.is_active }]"
                                                @click="entityEditForm.is_active = !entityEditForm.is_active"
                                            >
                                                <span class="emf-toggle-thumb" />
                                            </button>
                                        </label>
                                        <label class="emf-field">
                                            <span>Notas internas</span>
                                            <textarea v-model="entityEditForm.notes" rows="3" placeholder="Observações visíveis apenas internamente..."></textarea>
                                        </label>
                                    </div>
                                </div>

                                <footer class="entity-modal-footer">
                                    <button type="button" class="ghost" @click="closeEntityEditModal">Cancelar</button>
                                    <button type="submit">Guardar alterações</button>
                                </footer>
                            </form>
                        </article>
                    </section>
                </Teleport>

                <!-- ── Entity table ── -->
                <div class="table-scroll">
                    <table class="table entity-table">
                        <thead>
                            <tr>
                                <th>Entidade</th>
                                <th>Email</th>
                                <th>Contacto</th>
                                <th>NIF</th>
                                <th>Estado</th>
                                <th style="text-align:center">Contactos</th>
                                <th style="text-align:center">Tickets</th>
                                <th style="text-align:right">Ações</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-for="item in entities" :key="item.id" class="entity-row">
                                <td>
                                    <div class="entity-name-cell">
                                        <span class="entity-initials" :class="`type-${item.type}`">
                                            {{ (item.name || '?').slice(0, 2).toUpperCase() }}
                                        </span>
                                        <div>
                                            <span class="entity-name-text">{{ item.name || '—' }}</span>
                                            <span class="entity-type-chip" :class="`type-${item.type}`">
                                                {{ item.type === 'internal' ? 'Interna' : 'Externa' }}
                                            </span>
                                        </div>
                                    </div>
                                </td>
                                <td>
                                    <span class="cell-text" :title="item.email">{{ item.email || '—' }}</span>
                                </td>
                                <td>
                                    <div class="entity-contact-cell">
                                        <span v-if="item.phone" class="entity-contact-line">{{ item.phone }}</span>
                                        <span v-if="item.mobile_phone" class="entity-contact-line muted">{{ item.mobile_phone }}</span>
                                        <span v-if="!item.phone && !item.mobile_phone" class="muted">—</span>
                                    </div>
                                </td>
                                <td>
                                    <span class="cell-text">{{ item.tax_number || '—' }}</span>
                                </td>
                                <td>
                                    <span class="status-chip status-chip-dot" :class="item.is_active ? 'active' : 'inactive'">
                                        <span class="status-dot" aria-hidden="true"></span>
                                        {{ item.is_active ? 'Ativa' : 'Inativa' }}
                                    </span>
                                </td>
                                <td style="text-align:center">
                                    <span class="count-pill">{{ item.contacts_count }}</span>
                                </td>
                                <td style="text-align:center">
                                    <button type="button" class="count-pill count-link" @click="goToEntityTickets(item.id)">
                                        <svg viewBox="0 0 12 12" fill="none" aria-hidden="true">
                                            <path d="M1.5 3A1 1 0 0 1 2.5 2h7A1 1 0 0 1 10.5 3v1.5a1 1 0 0 0 0 2V8a1 1 0 0 1-1 1h-7a1 1 0 0 1-1-1V6.5a1 1 0 0 0 0-2V3Z" stroke="currentColor" stroke-width="1.1"/>
                                        </svg>
                                        {{ item.tickets_count }}
                                    </button>
                                </td>
                                <td>
                                    <div class="entity-row-actions">
                                        <button
                                            type="button"
                                            class="entity-row-btn"
                                            title="Editar"
                                            aria-label="Editar entidade"
                                            @click="openEntityEditModal(item)"
                                        >
                                            <svg viewBox="0 0 18 18" fill="none" width="14" height="14" aria-hidden="true">
                                                <path d="M10.5 3.5l4 4L5 17H1v-4L10.5 3.5z" stroke="currentColor" stroke-width="1.6" stroke-linejoin="round"/>
                                                <path d="M8 6l4 4" stroke="currentColor" stroke-width="1.6"/>
                                            </svg>
                                        </button>
                                        <button
                                            type="button"
                                            class="entity-row-btn entity-row-btn-danger"
                                            title="Eliminar"
                                            aria-label="Eliminar entidade"
                                            @click="deleteEntity(item)"
                                        >
                                            <svg viewBox="0 0 18 18" fill="none" width="14" height="14" aria-hidden="true">
                                                <path d="M3 5h12M8 8v5M10 8v5M4 5l1 10h8l1-10" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"/>
                                                <path d="M7 5V3h4v2" stroke="currentColor" stroke-width="1.6" stroke-linejoin="round"/>
                                            </svg>
                                        </button>
                                    </div>
                                </td>
                            </tr>
                            <tr v-if="!entities.length">
                                <td colspan="8" class="entity-empty">
                                    <svg viewBox="0 0 24 24" fill="none" width="28" height="28" aria-hidden="true"><path d="M3 21h18M5 21V7l7-4 7 4v14" stroke="currentColor" stroke-width="1.6" stroke-linejoin="round"/><path d="M9 21v-6h6v6" stroke="currentColor" stroke-width="1.6" stroke-linejoin="round"/></svg>
                                    <span>Nenhuma entidade registada.</span>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </template>

            <template v-if="!loading && activeTab === 'contacts'">
                <div class="entities-header">
                    <div>
                        <h2>Contactos</h2>
                        <p class="entities-subtitle">
                            {{ contacts.length }} {{ contacts.length === 1 ? 'contacto registado' : 'contactos registados' }}
                        </p>
                    </div>
                    <button type="button" class="btn-entity-new" @click="openContactCreateModal">
                        <svg viewBox="0 0 20 20" fill="none" width="15" height="15" aria-hidden="true">
                            <path d="M10 4v12M4 10h12" stroke="currentColor" stroke-width="2.2" stroke-linecap="round"/>
                        </svg>
                        Novo contacto
                    </button>
                </div>

                <!-- ── Create modal ── -->
                <Teleport to="body">
                    <section v-if="showContactCreateModal" class="entity-modal-overlay" @click.self="closeContactCreateModal">
                        <article class="entity-modal-card">
                            <header class="entity-modal-header">
                                <div class="entity-modal-header-icon">
                                    <svg viewBox="0 0 24 24" fill="none" width="20" height="20" aria-hidden="true">
                                        <circle cx="12" cy="8" r="4" stroke="currentColor" stroke-width="1.8"/>
                                        <path d="M4 20c0-4 3.6-7 8-7s8 3 8 7" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"/>
                                    </svg>
                                </div>
                                <div class="entity-modal-header-text">
                                    <h3>Novo contacto</h3>
                                    <p>Preenche os dados do contacto para criar.</p>
                                </div>
                                <button type="button" class="entity-modal-close" aria-label="Fechar" @click="closeContactCreateModal">
                                    <svg viewBox="0 0 20 20" fill="none" width="16" height="16"><path d="M5 5l10 10M15 5L5 15" stroke="currentColor" stroke-width="1.9" stroke-linecap="round"/></svg>
                                </button>
                            </header>

                            <form @submit.prevent="createContact">
                                <div class="entity-modal-body">
                                    <div class="emf-section">
                                        <p class="emf-section-title">Identificação</p>
                                        <div class="emf-grid emf-grid-2">
                                            <label class="emf-field emf-col-2">
                                                <span>Nome <em class="emf-required">*</em></span>
                                                <input v-model="contactForm.name" required placeholder="Nome completo" />
                                            </label>
                                            <label class="emf-field emf-col-2">
                                                <span>Email <em class="emf-required">*</em></span>
                                                <input v-model="contactForm.email" type="email" required placeholder="email@empresa.pt" />
                                            </label>
                                            <label class="emf-field">
                                                <span>Telefone</span>
                                                <input v-model="contactForm.phone" placeholder="+351 200 000 000" />
                                            </label>
                                            <label class="emf-field">
                                                <span>Telemóvel</span>
                                                <input v-model="contactForm.mobile_phone" placeholder="+351 900 000 000" />
                                            </label>
                                        </div>
                                    </div>

                                    <div class="emf-section">
                                        <p class="emf-section-title">Função & Associação</p>
                                        <div class="emf-grid emf-grid-2">
                                            <label class="emf-field">
                                                <span>Função</span>
                                                <select v-model="contactForm.function_id">
                                                    <option value="">Sem função</option>
                                                    <option v-for="option in contactFunctions" :key="option.id" :value="String(option.id)">{{ option.name }}</option>
                                                </select>
                                            </label>
                                            <label class="emf-field">
                                                <span>Utilizador (opcional)</span>
                                                <select v-model="contactForm.user_id" @change="syncContactFormFromSelectedUser">
                                                    <option value="">Sem associação</option>
                                                    <option v-for="user in availableClientUsers" :key="user.id" :value="String(user.id)">{{ user.name }} ({{ user.email }})</option>
                                                </select>
                                            </label>
                                            <label class="emf-field emf-col-2">
                                                <span>Entidades relacionadas</span>
                                                <div class="entity-ddl">
                                                    <button type="button" class="entity-ddl-toggle" @click.stop="toggleContactEntityDropdown">
                                                        <span class="entity-ddl-value">{{ contactEntityDropdownLabel }}</span>
                                                        <span class="entity-ddl-arrow" :class="{ open: contactEntityDropdownOpen }">
                                                            <svg viewBox="0 0 20 20" fill="none" width="16" height="16"><path d="M5 7.5L10 12.5L15 7.5" stroke="currentColor" stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8"/></svg>
                                                        </span>
                                                    </button>
                                                    <div v-if="contactEntityDropdownOpen" class="entity-ddl-panel">
                                                        <div class="entity-ddl-search-wrap">
                                                            <input v-model="contactEntitySearch" class="entity-ddl-search" type="search" placeholder="Pesquisar entidade..." />
                                                        </div>
                                                        <div class="entity-ddl-list" role="listbox" aria-multiselectable="true">
                                                            <button v-for="entity in filteredEntitiesForContact" :key="`new-contact-entity-${entity.id}`" type="button" class="entity-ddl-option" :class="{ selected: isContactEntitySelected(entity.id) }" @click="toggleContactEntity(contactForm, entity.id)">
                                                                <span class="entity-ddl-option-name">{{ entity.name }}</span>
                                                                <span class="entity-ddl-option-check">{{ isContactEntitySelected(entity.id) ? '✓' : '' }}</span>
                                                            </button>
                                                            <p v-if="!filteredEntitiesForContact.length" class="muted">Sem entidades para este filtro.</p>
                                                        </div>
                                                        <div class="entity-ddl-footer">
                                                            <small>{{ selectedContactEntities.length }} selecionadas</small>
                                                            <button type="button" class="ghost mini-btn" @click="clearContactEntitySelection">Limpar</button>
                                                        </div>
                                                    </div>
                                                    <div v-if="selectedContactEntities.length" class="entity-ddl-selected">
                                                        <button v-for="entity in selectedContactEntities" :key="`selected-entity-${entity.id}`" type="button" class="entity-ddl-tag" @click="toggleContactEntity(contactForm, entity.id)">{{ entity.name }}</button>
                                                    </div>
                                                </div>
                                            </label>
                                        </div>
                                    </div>

                                    <div class="emf-section emf-section-last">
                                        <label class="emf-toggle-row">
                                            <span class="emf-toggle-label">Contacto ativo</span>
                                            <button type="button" role="switch" :aria-checked="contactForm.is_active" :class="['emf-toggle-btn', { 'emf-toggle-on': contactForm.is_active }]" @click="contactForm.is_active = !contactForm.is_active">
                                                <span class="emf-toggle-thumb" />
                                            </button>
                                        </label>
                                        <label class="emf-field">
                                            <span>Notas internas</span>
                                            <textarea v-model="contactForm.internal_notes" rows="3" placeholder="Observações visíveis apenas internamente..."></textarea>
                                        </label>
                                    </div>
                                </div>
                                <footer class="entity-modal-footer">
                                    <button type="button" class="ghost" @click="closeContactCreateModal">Cancelar</button>
                                    <button type="submit">Criar contacto</button>
                                </footer>
                            </form>
                        </article>
                    </section>
                </Teleport>

                <!-- ── Edit modal ── -->
                <Teleport to="body">
                    <section v-if="showContactEditModal" class="entity-modal-overlay" @click.self="closeContactEditModal">
                        <article class="entity-modal-card">
                            <header class="entity-modal-header">
                                <div class="entity-modal-header-icon">
                                    <svg viewBox="0 0 24 24" fill="none" width="20" height="20" aria-hidden="true">
                                        <path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/>
                                        <path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/>
                                    </svg>
                                </div>
                                <div class="entity-modal-header-text">
                                    <h3>Editar contacto</h3>
                                    <p>Atualiza os dados do contacto selecionado.</p>
                                </div>
                                <button type="button" class="entity-modal-close" aria-label="Fechar" @click="closeContactEditModal">
                                    <svg viewBox="0 0 20 20" fill="none" width="16" height="16"><path d="M5 5l10 10M15 5L5 15" stroke="currentColor" stroke-width="1.9" stroke-linecap="round"/></svg>
                                </button>
                            </header>

                            <form @submit.prevent="saveContact">
                                <div class="entity-modal-body">
                                    <div class="emf-section">
                                        <p class="emf-section-title">Identificação</p>
                                        <div class="emf-grid emf-grid-2">
                                            <label class="emf-field emf-col-2">
                                                <span>Nome <em class="emf-required">*</em></span>
                                                <input v-model="contactEditForm.name" required placeholder="Nome completo" />
                                            </label>
                                            <label class="emf-field emf-col-2">
                                                <span>Email <em class="emf-required">*</em></span>
                                                <input v-model="contactEditForm.email" type="email" required placeholder="email@empresa.pt" />
                                            </label>
                                            <label class="emf-field">
                                                <span>Telefone</span>
                                                <input v-model="contactEditForm.phone" placeholder="+351 200 000 000" />
                                            </label>
                                            <label class="emf-field">
                                                <span>Telemóvel</span>
                                                <input v-model="contactEditForm.mobile_phone" placeholder="+351 900 000 000" />
                                            </label>
                                        </div>
                                    </div>

                                    <div class="emf-section">
                                        <p class="emf-section-title">Função & Associação</p>
                                        <div class="emf-grid emf-grid-2">
                                            <label class="emf-field">
                                                <span>Função</span>
                                                <select v-model="contactEditForm.function_id">
                                                    <option value="">Sem função</option>
                                                    <option v-for="option in contactFunctions" :key="`edit-contact-function-${option.id}`" :value="String(option.id)">{{ option.name }}</option>
                                                </select>
                                            </label>
                                            <label class="emf-field">
                                                <span>Utilizador (opcional)</span>
                                                <select v-model="contactEditForm.user_id" @change="syncContactEditFormFromSelectedUser">
                                                    <option value="">Sem associação</option>
                                                    <option v-for="user in userOptions" :key="`edit-contact-user-${user.id}`" :value="String(user.id)">{{ user.name }} ({{ user.email }})</option>
                                                </select>
                                            </label>
                                            <label class="emf-field emf-col-2">
                                                <span>Entidades relacionadas</span>
                                                <div class="entity-ddl">
                                                    <button type="button" class="entity-ddl-toggle" @click.stop="toggleContactEditEntityDropdown">
                                                        <span class="entity-ddl-value">{{ contactEditEntityDropdownLabel }}</span>
                                                        <span class="entity-ddl-arrow" :class="{ open: contactEditEntityDropdownOpen }">
                                                            <svg viewBox="0 0 20 20" fill="none" width="16" height="16"><path d="M5 7.5L10 12.5L15 7.5" stroke="currentColor" stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8"/></svg>
                                                        </span>
                                                    </button>
                                                    <div v-if="contactEditEntityDropdownOpen" class="entity-ddl-panel">
                                                        <div class="entity-ddl-search-wrap">
                                                            <input v-model="contactEditEntitySearch" class="entity-ddl-search" type="search" placeholder="Pesquisar entidade..." />
                                                        </div>
                                                        <div class="entity-ddl-list" role="listbox" aria-multiselectable="true">
                                                            <button v-for="entity in filteredEntitiesForContactEdit" :key="`edit-contact-entity-${entity.id}`" type="button" class="entity-ddl-option" :class="{ selected: isContactEditEntitySelected(entity.id) }" @click="toggleContactEntity(contactEditForm, entity.id)">
                                                                <span class="entity-ddl-option-name">{{ entity.name }}</span>
                                                                <span class="entity-ddl-option-check">{{ isContactEditEntitySelected(entity.id) ? '✓' : '' }}</span>
                                                            </button>
                                                            <p v-if="!filteredEntitiesForContactEdit.length" class="muted">Sem entidades para este filtro.</p>
                                                        </div>
                                                        <div class="entity-ddl-footer">
                                                            <small>{{ selectedContactEditEntities.length }} selecionadas</small>
                                                            <button type="button" class="ghost mini-btn" @click="clearContactEditEntitySelection">Limpar</button>
                                                        </div>
                                                    </div>
                                                    <div v-if="selectedContactEditEntities.length" class="entity-ddl-selected">
                                                        <button v-for="entity in selectedContactEditEntities" :key="`selected-edit-entity-${entity.id}`" type="button" class="entity-ddl-tag" @click="toggleContactEntity(contactEditForm, entity.id)">{{ entity.name }}</button>
                                                    </div>
                                                </div>
                                            </label>
                                        </div>
                                    </div>

                                    <div class="emf-section emf-section-last">
                                        <label class="emf-toggle-row">
                                            <span class="emf-toggle-label">Contacto ativo</span>
                                            <button type="button" role="switch" :aria-checked="contactEditForm.is_active" :class="['emf-toggle-btn', { 'emf-toggle-on': contactEditForm.is_active }]" @click="contactEditForm.is_active = !contactEditForm.is_active">
                                                <span class="emf-toggle-thumb" />
                                            </button>
                                        </label>
                                        <label class="emf-field">
                                            <span>Notas internas</span>
                                            <textarea v-model="contactEditForm.internal_notes" rows="3" placeholder="Observações visíveis apenas internamente..."></textarea>
                                        </label>
                                    </div>
                                </div>
                                <footer class="entity-modal-footer">
                                    <button type="button" class="ghost" @click="closeContactEditModal">Cancelar</button>
                                    <button type="submit">Guardar alterações</button>
                                </footer>
                            </form>
                        </article>
                    </section>
                </Teleport>

                <table class="table">
                    <thead>
                        <tr>
                            <th>Contacto</th>
                            <th>Função</th>
                            <th>Contacto</th>
                            <th>Entidades</th>
                            <th>Estado</th>
                            <th style="text-align:right">Ações</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="item in contacts" :key="item.id" class="entity-row">
                            <td>
                                <div class="entity-name-cell">
                                    <span class="entity-initials type-internal">
                                        {{ (item.name || '?').slice(0, 2).toUpperCase() }}
                                    </span>
                                    <div>
                                        <span class="entity-name-text">{{ item.name || '—' }}</span>
                                        <span class="cell-text" style="font-size:0.76rem">{{ item.email || '—' }}</span>
                                    </div>
                                </div>
                            </td>
                            <td><span class="cell-text">{{ item.function?.name || '—' }}</span></td>
                            <td>
                                <div class="entity-contact-cell">
                                    <span v-if="item.phone" class="entity-contact-line">{{ item.phone }}</span>
                                    <span v-if="item.mobile_phone" class="entity-contact-line muted">{{ item.mobile_phone }}</span>
                                    <span v-if="!item.phone && !item.mobile_phone" class="muted">—</span>
                                </div>
                            </td>
                            <td>
                                <span class="cell-text notes-text" :title="(item.entities || []).map(e => e.name).join(', ') || '—'">
                                    {{ (item.entities || []).map(e => e.name).join(', ') || '—' }}
                                </span>
                            </td>
                            <td>
                                <span class="status-chip" :class="item.is_active ? 'active' : 'inactive'">
                                    {{ item.is_active ? 'Ativo' : 'Inativo' }}
                                </span>
                            </td>
                            <td>
                                <div class="entity-row-actions">
                                    <button type="button" class="entity-row-btn" title="Editar" @click="openContactEditModal(item)">
                                        <svg viewBox="0 0 18 18" fill="none" width="14" height="14"><path d="M10.5 3.5l4 4L5 17H1v-4L10.5 3.5z" stroke="currentColor" stroke-width="1.6" stroke-linejoin="round"/><path d="M8 6l4 4" stroke="currentColor" stroke-width="1.6"/></svg>
                                    </button>
                                    <button type="button" class="entity-row-btn entity-row-btn-danger" title="Eliminar" @click="deleteContact(item)">
                                        <svg viewBox="0 0 18 18" fill="none" width="14" height="14"><path d="M3 5h12M8 8v5M10 8v5M4 5l1 10h8l1-10" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"/><path d="M7 5V3h4v2" stroke="currentColor" stroke-width="1.6" stroke-linejoin="round"/></svg>
                                    </button>
                                </div>
                            </td>
                        </tr>
                        <tr v-if="!contacts.length">
                            <td colspan="6" class="entity-empty">
                                <svg viewBox="0 0 24 24" fill="none" width="28" height="28"><circle cx="12" cy="8" r="4" stroke="currentColor" stroke-width="1.6"/><path d="M4 20c0-4 3.6-7 8-7s8 3 8 7" stroke="currentColor" stroke-width="1.6" stroke-linecap="round"/></svg>
                                <span>Nenhum contacto registado.</span>
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

                </div>

                <QuickActionsRail
                    :actions="notificationQuickActions"
                    aria-label="Ações rápidas notificações"
                    desktop-style="floating"
                    mobile-style="bottom"
                    :dock="notificationQuickDocked"
                    dock-offset="min(420px, calc(100vw - 1.2rem))"
                    @action="handleNotificationQuickAction"
                >
                    <template #icon-email_style>
                        <svg viewBox="0 0 24 24" fill="none" aria-hidden="true">
                            <circle cx="12" cy="12" r="7.5" stroke="currentColor" stroke-width="1.8" />
                            <circle cx="8.3" cy="9" r="1.2" fill="currentColor" />
                            <circle cx="12" cy="7.8" r="1.1" fill="currentColor" />
                            <circle cx="15.8" cy="9.2" r="1.1" fill="currentColor" />
                            <circle cx="15.8" cy="14.4" r="1.1" fill="currentColor" />
                            <path d="M10.8 13.2c.7-.35 1.6.2 1.6.98 0 .52-.42.94-.94.94H10.2a1.8 1.8 0 0 1 0-3.6h1" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" />
                        </svg>
                    </template>
                    <template #icon-email_preview>
                        <svg viewBox="0 0 24 24" fill="none" aria-hidden="true">
                            <path d="M2.5 12s3.8-6 9.5-6 9.5 6 9.5 6-3.8 6-9.5 6-9.5-6-9.5-6Z" stroke="currentColor" stroke-width="1.6" />
                            <circle cx="12" cy="12" r="3.2" stroke="currentColor" stroke-width="1.6" />
                        </svg>
                    </template>
                </QuickActionsRail>

                <QuickMenuPanel
                    :open="showEmailStylePanel"
                    title="Aspeto visual do email"
                    aria-label="Aspeto visual do email"
                    @close="showEmailStylePanel = false"
                >
                    <div class="notification-style-panel-body">
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
                    </div>
                </QuickMenuPanel>

                <section v-if="showEmailPreviewPanel" class="modal-overlay" @click.self="showEmailPreviewPanel = false">
                    <article class="modal-card email-preview-modal-card">
                        <header class="modal-header">
                            <div>
                                <h3>Pré-visualização do email</h3>
                                <p class="muted">Simulação com dados de exemplo. O aspeto real depende do cliente de email.</p>
                            </div>
                            <button type="button" class="ghost" @click="showEmailPreviewPanel = false">Fechar</button>
                        </header>

                        <div class="email-preview-template-tabs" v-if="notificationTemplates.length > 1">
                            <button
                                v-for="(tpl, idx) in notificationTemplates"
                                :key="`prev-tab-${tpl.event_key}`"
                                type="button"
                                class="email-preview-tab-btn"
                                :class="{ active: idx === notificationCarouselIndex }"
                                @click="goToNotificationTemplateIndex(idx)"
                            >
                                {{ notificationEventLabel[tpl.event_key] || tpl.event_key }}
                            </button>
                        </div>

                        <div class="email-preview-meta-row" v-if="activeNotificationTemplate">
                            <span class="email-preview-meta-label">Para:</span>
                            <span class="email-preview-meta-value">contacto@empresa.pt</span>
                            <span class="email-preview-meta-label">Assunto:</span>
                            <span class="email-preview-meta-value" v-html="previewSubjectHtml || '(sem assunto)'"></span>
                        </div>

                        <div class="email-preview-shell email-preview-shell--modal">
                            <div class="email-preview-header" :style="{ background: emailStyle.header_background }">
                                <span class="email-preview-badge">{{ emailStyle.brand_name.charAt(0).toUpperCase() }}</span>
                                <strong>{{ emailStyle.brand_name }}</strong>
                            </div>
                            <div class="email-preview-content">
                                <h4>{{ previewTitleText }}</h4>
                                <div class="email-preview-body" v-html="previewBodyHtml"></div>
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
            </template>

            <template v-if="!loading && activeTab === 'logs'">
                <div class="logs-header">
                    <div>
                        <h2>Registos de atividade</h2>
                        <p v-if="logsMeta" class="logs-total">
                            {{ logsMeta.total }} {{ logsMeta.total === 1 ? 'registo' : 'registos' }}
                        </p>
                    </div>
                    <button type="button" class="btn btn-secondary btn-sm" :disabled="logsLoading" @click="refreshLogs">
                        <svg viewBox="0 0 24 24" fill="none" width="14" height="14" aria-hidden="true">
                            <path d="M4 4v5h5M20 20v-5h-5" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
                            <path d="M4 9a8 8 0 0 1 14.9-2.1M20 15a8 8 0 0 1-14.9 2.1" stroke="currentColor" stroke-width="2" stroke-linecap="round"/>
                        </svg>
                        Atualizar
                    </button>
                </div>

                <div class="logs-filters">
                    <label class="logs-filter-field logs-filter-search">
                        <span class="filter-label">Pesquisa</span>
                        <input v-model="logFilters.search" type="search" placeholder="ticket, assunto..." @input="applyLogFiltersInstantly" />
                    </label>
                    <label class="logs-filter-field">
                        <span class="filter-label">Ação</span>
                        <select v-model="logFilters.action" @change="applyLogFiltersImmediately">
                            <option value="">Todas</option>
                            <option value="ticket_created">Ticket criado</option>
                            <option value="message_added">Mensagem adicionada</option>
                            <option value="status_updated">Estado atualizado</option>
                            <option value="assignment_updated">Atribuição atualizada</option>
                            <option value="field_updated">Campo atualizado</option>
                            <option value="attachments_added">Anexos adicionados</option>
                        </select>
                    </label>
                    <label class="logs-filter-field">
                        <span class="filter-label">Ator</span>
                        <select v-model="logFilters.actor_type" @change="applyLogFiltersImmediately">
                            <option value="">Todos</option>
                            <option value="user">Operador</option>
                            <option value="contact">Contacto</option>
                            <option value="system">Sistema</option>
                        </select>
                    </label>
                    <label class="logs-filter-field">
                        <span class="filter-label">De</span>
                        <input v-model="logFilters.date_from" type="date" @change="applyLogFiltersImmediately" />
                    </label>
                    <label class="logs-filter-field">
                        <span class="filter-label">Até</span>
                        <input v-model="logFilters.date_to" type="date" @change="applyLogFiltersImmediately" />
                    </label>
                    <button
                        v-if="logFilters.search || logFilters.action || logFilters.actor_type || logFilters.date_from || logFilters.date_to"
                        type="button"
                        class="logs-clear-btn"
                        @click="clearLogFilters"
                    >
                        Limpar
                    </button>
                </div>

                <div class="logs-table-wrap" :class="{ 'is-loading': logsLoading }">
                    <table class="table logs-table">
                        <thead>
                            <tr>
                                <th>Data</th>
                                <th>Ticket</th>
                                <th>Ação</th>
                                <th>Alteração</th>
                                <th>Ator</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-for="item in logs" :key="item.id" class="log-row">
                                <td class="log-date">
                                    <span :title="formatDate(item.created_at)">{{ formatDate(item.created_at) }}</span>
                                </td>
                                <td class="log-ticket">
                                    <RouterLink
                                        v-if="item.ticket"
                                        :to="{ name: 'tickets.show', params: { id: item.ticket.id } }"
                                        class="log-ticket-link"
                                    >
                                        <span class="log-ticket-num">#{{ item.ticket.ticket_number }}</span>
                                        <span v-if="item.ticket.subject" class="log-ticket-sub">{{ item.ticket.subject }}</span>
                                    </RouterLink>
                                    <span v-else class="muted">—</span>
                                </td>
                                <td class="log-action-cell">
                                    <span :class="['log-action-badge', `log-action--${item.action}`]">
                                        {{ {
                                            ticket_created: 'Criado',
                                            message_added: 'Mensagem',
                                            status_updated: 'Estado',
                                            assignment_updated: 'Atribuição',
                                            field_updated: 'Campo',
                                            attachments_added: 'Anexos',
                                        }[item.action] ?? item.action }}
                                    </span>
                                </td>
                                <td class="log-change">
                                    <template v-if="item.field">
                                        <span class="log-field">{{ formatLogField(item.field) }}</span>
                                        <template v-if="item.old_value !== null && item.new_value !== null">
                                            <span class="log-old">{{ formatLogValue(item.field, item.old_value) }}</span>
                                            <svg viewBox="0 0 16 16" fill="none" width="10" height="10" class="log-arrow" aria-hidden="true"><path d="M3 8h10M9 4l4 4-4 4" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"/></svg>
                                            <span class="log-new">{{ formatLogValue(item.field, item.new_value) }}</span>
                                        </template>
                                        <span v-else-if="item.new_value !== null" class="log-new">{{ formatLogValue(item.field, item.new_value) }}</span>
                                    </template>
                                    <span v-else class="muted">—</span>
                                </td>
                                <td class="log-actor">
                                    <span :class="['log-actor-badge', `log-actor--${item.actor_type}`]">
                                        {{ item.actor_type === 'system' ? 'Sistema' : (item.actor_user?.name || item.actor_contact?.name || item.actor_type) }}
                                    </span>
                                </td>
                            </tr>
                            <tr v-if="!logsLoading && !logs.length">
                                <td colspan="5" class="logs-empty">
                                    <svg viewBox="0 0 24 24" fill="none" width="28" height="28" aria-hidden="true"><path d="M9 5H7a2 2 0 0 0-2 2v12a2 2 0 0 0 2 2h10a2 2 0 0 0 2-2V7a2 2 0 0 0-2-2h-2" stroke="currentColor" stroke-width="1.6"/><rect x="9" y="3" width="6" height="4" rx="1" stroke="currentColor" stroke-width="1.6"/><path d="M9 12h6M9 16h4" stroke="currentColor" stroke-width="1.6" stroke-linecap="round"/></svg>
                                    <span>Sem registos para os filtros atuais.</span>
                                </td>
                            </tr>
                        </tbody>
                    </table>

                    <div v-if="logsLoading" class="logs-loading-overlay" aria-label="A carregar...">
                        <span class="logs-spinner" />
                    </div>
                </div>

                <div v-if="logsMeta && logsMeta.last_page > 1" class="logs-pagination">
                    <button type="button" class="page-btn" :disabled="logsPage <= 1" @click="goToLogsPage(logsPage - 1)">
                        <svg viewBox="0 0 16 16" fill="none" width="14" height="14"><path d="M10 4L6 8l4 4" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/></svg>
                    </button>
                    <span class="page-info">Página {{ logsPage }} de {{ logsMeta.last_page }}</span>
                    <button type="button" class="page-btn" :disabled="logsPage >= logsMeta.last_page" @click="goToLogsPage(logsPage + 1)">
                        <svg viewBox="0 0 16 16" fill="none" width="14" height="14"><path d="M6 4l4 4-4 4" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/></svg>
                    </button>
                </div>
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
    grid-template-columns: 220px minmax(0, 1fr);
    gap: 0.6rem;
    align-items: start;
}

.notification-style-panel-body {
    padding: 0.85rem;
    display: grid;
    align-content: start;
    gap: 0.55rem;
    overflow: auto;
}

.notification-preview-panel-body {
    padding: 0.85rem;
    display: grid;
    align-content: start;
    gap: 0.6rem;
    overflow: auto;
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
thead th {
    text-transform: uppercase;
    letter-spacing: 0.05em;
    font-size: 0.74rem;
    font-weight: 700;
    color: #64748b;
    background: #f8fbff;
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
    gap: 0.28rem;
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

.status-chip-dot {
    gap: 0.35rem;
}

.status-dot {
    width: 7px;
    height: 7px;
    border-radius: 50%;
    background: currentColor;
    flex-shrink: 0;
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

.inbox-image-preview {
    grid-column: 1 / -1;
    border: 1px dashed #cbd5e1;
    border-radius: 12px;
    padding: 0.5rem;
    background: #f8fafc;
    display: flex;
    justify-content: center;
}

.inbox-image-preview img {
    max-width: 100%;
    max-height: 160px;
    border-radius: 10px;
    object-fit: contain;
    background: #fff;
    pointer-events: none;
}

/* keep .modal-overlay for other modals that still use it */
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

.modal-header h3 { margin: 0; }
.modal-header p { margin: 0.25rem 0 0; }

.modal-actions {
    display: flex;
    align-items: center;
    gap: 0.5rem;
}

/* ── Entity modal (new design) ──────────────────────────────────────────── */

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

/* grid: Identificação — Tipo | Nome(flex) | NIF */
.emf-grid-id {
    grid-template-columns: minmax(110px, 1fr) 2fr minmax(110px, 1fr);
}

/* grid: Contacto — 2 colunas iguais */
.emf-grid-2 {
    grid-template-columns: 1fr 1fr;
}

/* grid: Localização — CP | Cidade | País */
.emf-grid-loc {
    grid-template-columns: 1fr 1fr 1fr;
}

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

.email-preview-modal-card {
    width: min(760px, calc(100vw - 2rem));
}

.email-preview-modal-card .email-preview-content {
    max-height: none;
}

.email-preview-template-tabs {
    display: flex;
    flex-wrap: wrap;
    gap: 0.3rem;
    padding-bottom: 0.1rem;
    border-bottom: 1px solid #e2e8f0;
}

.email-preview-tab-btn {
    background: none;
    border: 1px solid #dbe4ee;
    border-radius: 8px;
    padding: 0.28rem 0.65rem;
    font-size: 0.82rem;
    color: #475569;
    cursor: pointer;
    transition: background 0.15s, color 0.15s, border-color 0.15s;
}

.email-preview-tab-btn:hover {
    background: #f1f5f9;
}

.email-preview-tab-btn.active {
    background: #1F4E79;
    color: #fff;
    border-color: #1F4E79;
}

.email-preview-meta-row {
    display: grid;
    grid-template-columns: auto 1fr;
    gap: 0.2rem 0.5rem;
    align-items: baseline;
    background: #f8fafc;
    border: 1px solid #e2e8f0;
    border-radius: 8px;
    padding: 0.5rem 0.75rem;
    font-size: 0.86rem;
}

.email-preview-meta-label {
    color: #64748b;
    font-weight: 600;
    white-space: nowrap;
}

.email-preview-meta-value {
    color: #1e293b;
    word-break: break-word;
}

.email-preview-shell--modal {
    max-width: 560px;
    margin: 0 auto;
}

.email-preview-body {
    color: #334155;
    font-size: 0.9rem;
    line-height: 1.55;
    white-space: pre-wrap;
}

@media (max-width: 960px) {
    .form-grid,
    .filters { grid-template-columns: 1fr; }

    .notification-layout-grid { grid-template-columns: 1fr; }
    .notification-builder-shell { grid-template-columns: 1fr; }
    .notification-template-grid { grid-template-columns: 1fr; }
    .notification-style-form { grid-template-columns: 1fr; }
    .notification-style-actions-row { justify-content: flex-start; }
    .notification-preview-side {
        position: static;
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

/* ── Logs tab ─────────────────────────────────────────────────────────────── */

.logs-header {
    display: flex;
    align-items: flex-start;
    justify-content: space-between;
    gap: 0.6rem;
}

.logs-header h2 { margin-bottom: 0.1rem; }

.logs-total {
    margin: 0;
    font-size: 0.8rem;
    color: #64748b;
}

.btn-sm {
    padding: 0.35rem 0.65rem;
    font-size: 0.82rem;
    display: inline-flex;
    align-items: center;
    gap: 0.35rem;
}

.logs-filters {
    display: flex;
    flex-wrap: wrap;
    align-items: flex-end;
    gap: 0.5rem;
}

.logs-filter-field {
    display: flex;
    flex-direction: column;
    gap: 0.22rem;
}

.logs-filter-search { flex: 1 1 180px; }
.logs-filter-field:not(.logs-filter-search) { flex: 0 0 auto; min-width: 130px; }

.filter-label {
    font-size: 0.74rem;
    font-weight: 600;
    color: #64748b;
    text-transform: uppercase;
    letter-spacing: 0.04em;
}

.logs-filter-field input,
.logs-filter-field select {
    border: 1px solid #dbe4ee;
    border-radius: 8px;
    background: #fff;
    padding: 0.4rem 0.55rem;
    font: inherit;
    font-size: 0.87rem;
    color: #0f172a;
}

.logs-clear-btn {
    align-self: flex-end;
    padding: 0.4rem 0.7rem;
    font-size: 0.84rem;
    border: 1px solid #dbe4ee;
    border-radius: 8px;
    background: #f8fafc;
    color: #475569;
    cursor: pointer;
}

.logs-clear-btn:hover { background: #f1f5f9; border-color: #cbd5e1; }

.logs-table-wrap {
    position: relative;
    border: 1px solid #dbe4ee;
    border-radius: 12px;
    overflow: hidden;
    background: #fff;
}

.logs-table-wrap.is-loading { opacity: 0.55; pointer-events: none; }

.logs-table {
    margin: 0;
    border: none;
}

.logs-table th {
    background: #f8fafc;
    font-size: 0.74rem;
    color: #64748b;
    text-transform: uppercase;
    letter-spacing: 0.04em;
    padding: 0.55rem 0.75rem;
    border-bottom: 1px solid #dbe4ee;
}

.log-row td {
    padding: 0.55rem 0.75rem;
    vertical-align: middle;
    border-bottom: 1px solid #f1f5f9;
}

.log-row:last-child td { border-bottom: none; }
.log-row:hover td { background: #fafbfd; }

.log-date {
    font-size: 0.78rem;
    color: #64748b;
    white-space: nowrap;
}

.log-ticket-link {
    display: flex;
    flex-direction: column;
    gap: 0.06rem;
    text-decoration: none;
    color: inherit;
}

.log-ticket-link:hover .log-ticket-num { text-decoration: underline; }

.log-ticket-num {
    font-size: 0.82rem;
    font-weight: 700;
    color: #1e40af;
}

.log-ticket-sub {
    font-size: 0.74rem;
    color: #64748b;
    max-width: 200px;
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
}

.log-action-badge {
    display: inline-block;
    padding: 0.18rem 0.55rem;
    border-radius: 999px;
    font-size: 0.74rem;
    font-weight: 600;
    white-space: nowrap;
    border: 1px solid transparent;
}

.log-action--ticket_created    { background: #dcfce7; color: #166534; border-color: #bbf7d0; }
.log-action--message_added     { background: #f1f5f9; color: #334155; border-color: #e2e8f0; }
.log-action--status_updated    { background: #dbeafe; color: #1e40af; border-color: #bfdbfe; }
.log-action--assignment_updated{ background: #f3e8ff; color: #6b21a8; border-color: #e9d5ff; }
.log-action--field_updated     { background: #fff7ed; color: #9a3412; border-color: #fed7aa; }
.log-action--attachments_added { background: #ccfbf1; color: #065f46; border-color: #99f6e4; }

.log-change {
    font-size: 0.8rem;
    display: flex;
    align-items: center;
    gap: 0.3rem;
    flex-wrap: wrap;
}

.log-field {
    font-size: 0.72rem;
    background: #f1f5f9;
    border: 1px solid #e2e8f0;
    border-radius: 4px;
    padding: 0.08rem 0.36rem;
    color: #475569;
    margin-right: 0.1rem;
}

.log-old {
    color: #b91c1c;
    font-size: 0.8rem;
    max-width: 90px;
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
}

.log-new {
    color: #166534;
    font-size: 0.8rem;
    max-width: 90px;
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
}

.log-arrow { color: #94a3b8; flex-shrink: 0; }

.log-actor-badge {
    display: inline-block;
    padding: 0.16rem 0.5rem;
    border-radius: 999px;
    font-size: 0.74rem;
    font-weight: 500;
    border: 1px solid transparent;
    max-width: 130px;
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
}

.log-actor--user    { background: #eff6ff; color: #1d4ed8; border-color: #bfdbfe; }
.log-actor--contact { background: #fefce8; color: #92400e; border-color: #fde68a; }
.log-actor--system  { background: #f1f5f9; color: #475569; border-color: #e2e8f0; }

.logs-empty {
    text-align: center;
    padding: 2.5rem 1rem !important;
    display: flex;
    flex-direction: column;
    align-items: center;
    gap: 0.55rem;
    color: #94a3b8;
    font-size: 0.88rem;
}

.logs-loading-overlay {
    position: absolute;
    inset: 0;
    display: flex;
    align-items: center;
    justify-content: center;
    background: rgba(255,255,255,0.6);
}

.logs-spinner {
    width: 24px;
    height: 24px;
    border: 3px solid #e2e8f0;
    border-top-color: #1F4E79;
    border-radius: 50%;
    animation: spin 0.7s linear infinite;
}

@keyframes spin { to { transform: rotate(360deg); } }

.logs-pagination {
    display: flex;
    align-items: center;
    justify-content: center;
    gap: 0.75rem;
}

.page-btn {
    width: 32px;
    height: 32px;
    border: 1px solid #dbe4ee;
    border-radius: 8px;
    background: #fff;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    cursor: pointer;
    color: #334155;
}

.page-btn:hover:not(:disabled) { background: #f1f5f9; border-color: #cbd5e1; }
.page-btn:disabled { opacity: 0.4; cursor: not-allowed; }

.page-info {
    font-size: 0.84rem;
    color: #475569;
}

/* ── Entities tab ────────────────────────────────────────────────────────── */

.entities-header {
    display: flex;
    align-items: flex-start;
    justify-content: space-between;
    gap: 0.8rem;
}

.entities-header h2 { margin-bottom: 0.1rem; }

.entities-subtitle {
    margin: 0;
    font-size: 0.8rem;
    color: #64748b;
}

.btn-entity-new {
    display: inline-flex;
    align-items: center;
    gap: 0.4rem;
    padding: 0.45rem 0.9rem;
    font-size: 0.88rem;
    border-radius: 10px;
    background: #1F4E79;
    color: #fff;
    border: none;
    cursor: pointer;
    font-weight: 600;
    white-space: nowrap;
    transition: background 120ms, transform 80ms;
}

.btn-entity-new:hover { background: #174069; }
.btn-entity-new:active { transform: scale(0.98); }

.entity-row { transition: background 80ms; }
.entity-row:hover td { background: #f8fafc; }

.entity-name-cell {
    display: flex;
    align-items: center;
    gap: 0.65rem;
}

.entity-initials {
    width: 36px;
    height: 36px;
    border-radius: 10px;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    font-size: 0.76rem;
    font-weight: 800;
    flex-shrink: 0;
    letter-spacing: 0.02em;
}

.entity-initials-image {
    padding: 0;
    overflow: hidden;
    background: #fff;
    border: 1px solid #dbe4ee;
}

.entity-initials-image img {
    width: 100%;
    height: 100%;
    object-fit: cover;
    display: block;
}

.entity-initials.type-internal {
    background: #ecfeff;
    color: #155e75;
    border: 1px solid #a5f3fc;
}

.entity-initials.type-external {
    background: #eff6ff;
    color: #1d4ed8;
    border: 1px solid #bfdbfe;
}

.entity-name-cell > div {
    display: flex;
    flex-direction: column;
    gap: 0.22rem;
}

.entity-name-text {
    font-weight: 600;
    font-size: 0.88rem;
    color: #0f172a;
    line-height: 1.2;
}

.entity-contact-cell {
    display: flex;
    flex-direction: column;
    gap: 0.1rem;
}

.entity-contact-line {
    font-size: 0.82rem;
    color: #334155;
    line-height: 1.3;
}

.entity-row-actions {
    display: flex;
    align-items: center;
    justify-content: flex-end;
    gap: 0.3rem;
}

.entity-row-btn {
    width: 30px;
    height: 30px;
    border-radius: 8px;
    border: 1px solid #e2e8f0;
    background: #fff;
    color: #475569;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    cursor: pointer;
    padding: 0;
    transition: background 100ms, border-color 100ms, color 100ms;
}

.entity-row-btn:hover {
    background: #f1f5f9;
    border-color: #cbd5e1;
    color: #1F4E79;
}

.entity-row-btn-danger:hover {
    background: #fef2f2;
    border-color: #fecaca;
    color: #b91c1c;
}

.inbox-operators-cell {
    display: flex;
    align-items: center;
    gap: 0.45rem;
}

.entity-empty {
    text-align: center;
    padding: 2.5rem 1rem !important;
    display: flex !important;
    flex-direction: column;
    align-items: center;
    gap: 0.5rem;
    color: #94a3b8;
    font-size: 0.88rem;
}
</style>
