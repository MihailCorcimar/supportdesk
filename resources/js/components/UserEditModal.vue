<script setup>
const props = defineProps({
    open: { type: Boolean, default: false },
    saving: { type: Boolean, default: false },
    title: { type: String, default: 'Editar utilizador' },
    subtitle: { type: String, default: '' },
    form: { type: Object, required: true },
    isOperator: { type: Boolean, default: false },
    isClient: { type: Boolean, default: false },
    canSetAdmin: { type: Boolean, default: false },
    manageableInboxes: { type: Array, default: () => [] },
});

const emit = defineEmits(['close', 'save', 'toggle-inbox', 'toggle-manager-inbox']);

const onToggleInbox = (id) => emit('toggle-inbox', id);
const onToggleManagerInbox = (id) => emit('toggle-manager-inbox', id);
</script>

<template>
    <Teleport to="body">
        <section v-if="open" class="uem-overlay" @click.self="emit('close')">
            <article class="uem-card">

                <!-- Header -->
                <header class="uem-header">
                    <div class="uem-header-icon">
                        <svg viewBox="0 0 24 24" fill="none" width="20" height="20" aria-hidden="true">
                            <circle cx="12" cy="8" r="4" stroke="currentColor" stroke-width="1.8"/>
                            <path d="M4 20c0-4 3.6-7 8-7s8 3 8 7" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"/>
                        </svg>
                    </div>
                    <div class="uem-header-text">
                        <h3>{{ title }}</h3>
                        <p v-if="subtitle">{{ subtitle }}</p>
                    </div>
                    <button type="button" class="uem-close" aria-label="Fechar" @click="emit('close')">
                        <svg viewBox="0 0 20 20" fill="none" width="16" height="16"><path d="M5 5l10 10M15 5L5 15" stroke="currentColor" stroke-width="1.9" stroke-linecap="round"/></svg>
                    </button>
                </header>

                <!-- Form -->
                <form @submit.prevent="emit('save')">
                    <div class="uem-body">

                        <!-- Identificação -->
                        <div class="uem-section">
                            <p class="uem-section-title">Identificação</p>
                            <div class="uem-grid uem-grid-2">
                                <label class="uem-field">
                                    <span>Nome</span>
                                    <input v-model="form.name" required maxlength="255" placeholder="Nome completo" />
                                </label>
                                <label class="uem-field">
                                    <span>Email</span>
                                    <input :value="form.email" readonly class="uem-readonly" />
                                </label>
                                <label class="uem-field">
                                    <span>Perfil</span>
                                    <input :value="isOperator ? 'Operador' : 'Cliente'" readonly class="uem-readonly" />
                                </label>
                                <label v-if="isClient" class="uem-field">
                                    <span>Nome de contacto</span>
                                    <input v-model="form.contact_name" maxlength="255" placeholder="Nome de contacto" />
                                </label>
                            </div>
                        </div>

                        <!-- Permissões -->
                        <div class="uem-section">
                            <p class="uem-section-title">Permissões</p>
                            <div class="uem-toggles">
                                <label class="uem-toggle-row">
                                    <span class="uem-toggle-label">
                                        <svg viewBox="0 0 16 16" fill="none" class="uem-toggle-icon"><rect x="1" y="1" width="14" height="14" rx="3" stroke="currentColor" stroke-width="1.3"/><path d="M4.5 8l2.5 2.5 4.5-5" stroke="currentColor" stroke-width="1.4" stroke-linecap="round" stroke-linejoin="round"/></svg>
                                        Utilizador ativo
                                    </span>
                                    <button
                                        type="button"
                                        role="switch"
                                        :aria-checked="form.is_active"
                                        :class="['uem-toggle-btn', { 'uem-toggle-on': form.is_active }]"
                                        @click="form.is_active = !form.is_active"
                                    >
                                        <span class="uem-toggle-thumb" />
                                    </button>
                                </label>

                                <label v-if="isOperator && canSetAdmin" class="uem-toggle-row">
                                    <span class="uem-toggle-label">
                                        <svg viewBox="0 0 16 16" fill="none" class="uem-toggle-icon"><path d="M8 1.5l1.6 3.3 3.6.5-2.6 2.5.6 3.6L8 9.7l-3.2 1.7.6-3.6L2.8 5.3l3.6-.5L8 1.5z" stroke="currentColor" stroke-width="1.3" stroke-linejoin="round"/></svg>
                                        Operador admin (acesso global)
                                    </span>
                                    <button
                                        type="button"
                                        role="switch"
                                        :aria-checked="form.is_admin"
                                        :class="['uem-toggle-btn', { 'uem-toggle-on': form.is_admin }]"
                                        @click="form.is_admin = !form.is_admin"
                                    >
                                        <span class="uem-toggle-thumb" />
                                    </button>
                                </label>
                            </div>
                        </div>

                        <!-- Inboxes (operadores) -->
                        <template v-if="isOperator">
                            <div class="uem-section">
                                <p class="uem-section-title">Acessos a inboxes</p>
                                <div class="uem-checks">
                                    <label v-for="inbox in manageableInboxes" :key="`edit-modal-inbox-${inbox.id}`" class="uem-check-item">
                                        <input
                                            type="checkbox"
                                            :checked="form.inbox_ids.includes(inbox.id)"
                                            @change="onToggleInbox(inbox.id)"
                                        />
                                        {{ inbox.name }}
                                    </label>
                                    <p v-if="!manageableInboxes.length" class="uem-empty">Sem inboxes disponíveis.</p>
                                </div>
                            </div>

                            <div class="uem-section uem-section-last">
                                <p class="uem-section-title">Gestão de utilizadores (por inbox)</p>
                                <div class="uem-checks">
                                    <label v-for="inbox in manageableInboxes" :key="`edit-modal-manager-${inbox.id}`" class="uem-check-item" :class="{ 'uem-check-disabled': !form.inbox_ids.includes(inbox.id) }">
                                        <input
                                            type="checkbox"
                                            :checked="form.manager_inbox_ids.includes(inbox.id)"
                                            :disabled="!form.inbox_ids.includes(inbox.id)"
                                            @change="onToggleManagerInbox(inbox.id)"
                                        />
                                        {{ inbox.name }}
                                    </label>
                                    <p v-if="!manageableInboxes.length" class="uem-empty">Sem inboxes disponíveis.</p>
                                </div>
                            </div>
                        </template>

                        <div v-if="!isOperator" class="uem-section-last"></div>
                    </div>

                    <!-- Footer -->
                    <footer class="uem-footer">
                        <button type="button" class="uem-btn-ghost" @click="emit('close')">Cancelar</button>
                        <button type="submit" class="uem-btn-primary" :disabled="saving">
                            <svg v-if="saving" class="uem-spinner-icon" viewBox="0 0 16 16" fill="none"><circle cx="8" cy="8" r="6" stroke="currentColor" stroke-width="2" stroke-dasharray="28" stroke-dashoffset="10"/></svg>
                            {{ saving ? 'A guardar...' : 'Guardar alterações' }}
                        </button>
                    </footer>
                </form>

            </article>
        </section>
    </Teleport>
</template>

<style scoped>
/* ── Overlay ─────────────────────────────────────────────── */

.uem-overlay {
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

/* ── Card ────────────────────────────────────────────────── */

.uem-card {
    width: min(600px, calc(100vw - 2rem));
    max-height: calc(100vh - 2rem);
    display: flex;
    flex-direction: column;
    background: #fff;
    border: 1px solid #e2e8f0;
    border-radius: 18px;
    box-shadow: 0 24px 60px rgba(10, 18, 36, 0.22), 0 6px 18px rgba(10, 18, 36, 0.1);
    overflow: hidden;
}

/* ── Header ──────────────────────────────────────────────── */

.uem-header {
    display: flex;
    align-items: center;
    gap: 0.85rem;
    padding: 1.1rem 1.3rem;
    border-bottom: 1px solid #f1f5f9;
    background: #fafbfd;
    flex-shrink: 0;
}

.uem-header-icon {
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

.uem-header-text { flex: 1; min-width: 0; }

.uem-header-text h3 {
    margin: 0;
    font-size: 1.05rem;
    font-weight: 700;
    color: #0f172a;
}

.uem-header-text p {
    margin: 0.15rem 0 0;
    font-size: 0.82rem;
    color: #64748b;
}

.uem-close {
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

.uem-close:hover {
    background: #f8fafc;
    border-color: #cbd5e1;
    color: #1e293b;
}

/* ── Body ────────────────────────────────────────────────── */

.uem-body {
    flex: 1;
    overflow-y: auto;
    padding: 1.1rem 1.3rem;
    display: flex;
    flex-direction: column;
    gap: 0;
}

/* ── Sections ────────────────────────────────────────────── */

.uem-section {
    padding-bottom: 1.1rem;
    margin-bottom: 1.1rem;
    border-bottom: 1px solid #f1f5f9;
}

.uem-section-last {
    padding-bottom: 0;
    margin-bottom: 0;
    border-bottom: none;
}

.uem-section-title {
    margin: 0 0 0.7rem;
    font-size: 0.72rem;
    font-weight: 700;
    color: #94a3b8;
    text-transform: uppercase;
    letter-spacing: 0.06em;
}

/* ── Grid & Fields ───────────────────────────────────────── */

.uem-grid { display: grid; gap: 0.65rem; }
.uem-grid-2 { grid-template-columns: 1fr 1fr; }

.uem-field {
    display: flex;
    flex-direction: column;
    gap: 0.28rem;
}

.uem-field span {
    font-size: 0.8rem;
    font-weight: 600;
    color: #475569;
}

.uem-field input,
.uem-field select {
    border: 1px solid #e2e8f0;
    border-radius: 9px;
    padding: 0.46rem 0.62rem;
    font: inherit;
    font-size: 0.9rem;
    color: #0f172a;
    background: #fff;
    transition: border-color 140ms, box-shadow 140ms;
}

.uem-field input:focus,
.uem-field select:focus {
    outline: none;
    border-color: #1F4E79;
    box-shadow: 0 0 0 3px rgba(31, 78, 121, 0.1);
}

.uem-readonly {
    background: #f8fafc !important;
    color: #64748b !important;
    cursor: default;
}

/* ── Toggles ─────────────────────────────────────────────── */

.uem-toggles {
    display: flex;
    flex-direction: column;
    gap: 0.5rem;
}

.uem-toggle-row {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 1rem;
    padding: 0.6rem 0.8rem;
    border: 1px solid #e2e8f0;
    border-radius: 10px;
    background: #f8fafc;
    cursor: pointer;
}

.uem-toggle-label {
    display: flex;
    align-items: center;
    gap: 0.4rem;
    font-size: 0.88rem;
    font-weight: 600;
    color: #334155;
}

.uem-toggle-icon {
    width: 15px;
    height: 15px;
    color: #64748b;
    flex-shrink: 0;
}

.uem-toggle-btn {
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

.uem-toggle-btn.uem-toggle-on { background: #1F4E79; }

.uem-toggle-thumb {
    display: block;
    width: 20px;
    height: 20px;
    border-radius: 50%;
    background: #fff;
    box-shadow: 0 1px 4px rgba(0, 0, 0, 0.2);
    transition: transform 200ms;
}

.uem-toggle-on .uem-toggle-thumb { transform: translateX(20px); }

/* ── Checkboxes (inboxes) ────────────────────────────────── */

.uem-checks {
    display: flex;
    flex-wrap: wrap;
    gap: 0.4rem 0.8rem;
}

.uem-check-item {
    display: inline-flex;
    align-items: center;
    gap: 0.35rem;
    font-size: 0.88rem;
    color: #334155;
    cursor: pointer;
    padding: 0.3rem 0.55rem;
    border: 1px solid #e2e8f0;
    border-radius: 8px;
    background: #f8fafc;
    transition: border-color 100ms, background 100ms;
}

.uem-check-item:hover { border-color: #b6c9e3; background: #f0f7ff; }
.uem-check-item input { width: auto; accent-color: #1F4E79; }
.uem-check-disabled { opacity: 0.45; pointer-events: none; }

.uem-empty {
    font-size: 0.83rem;
    color: #94a3b8;
    margin: 0;
}

/* ── Footer ──────────────────────────────────────────────── */

.uem-footer {
    display: flex;
    align-items: center;
    justify-content: flex-end;
    gap: 0.6rem;
    padding: 0.9rem 1.3rem;
    border-top: 1px solid #f1f5f9;
    background: #fafbfd;
    flex-shrink: 0;
}

.uem-btn-primary {
    display: inline-flex;
    align-items: center;
    gap: 0.38rem;
    padding: 0.5rem 1.1rem;
    font: inherit;
    font-size: 0.9rem;
    font-weight: 600;
    background: #1F4E79;
    border: 1px solid #1F4E79;
    color: #fff;
    border-radius: 9px;
    cursor: pointer;
    transition: background 110ms;
}

.uem-btn-primary:hover:not(:disabled) { background: #163d60; border-color: #163d60; }
.uem-btn-primary:disabled { opacity: 0.6; cursor: not-allowed; }

.uem-btn-ghost {
    padding: 0.5rem 1.1rem;
    font: inherit;
    font-size: 0.9rem;
    font-weight: 500;
    background: #fff;
    border: 1px solid #e2e8f0;
    color: #475569;
    border-radius: 9px;
    cursor: pointer;
    transition: background 100ms, border-color 100ms;
}

.uem-btn-ghost:hover { background: #f8fafc; border-color: #cbd5e1; color: #0f172a; }

.uem-spinner-icon {
    width: 14px;
    height: 14px;
    animation: uem-spin 0.7s linear infinite;
}

@keyframes uem-spin { to { transform: rotate(360deg); } }

/* ── Responsive ──────────────────────────────────────────── */

@media (max-width: 640px) {
    .uem-grid-2 { grid-template-columns: 1fr; }
    .uem-card { border-radius: 14px; }
}
</style>
