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
    <section v-if="open" class="modal-overlay" @click.self="emit('close')">
        <article class="modal-card">
            <header class="modal-header">
                <div>
                    <h2>{{ title }}</h2>
                    <p v-if="subtitle" class="muted">{{ subtitle }}</p>
                </div>
            </header>

            <form class="grid" @submit.prevent="emit('save')">
                <label>
                    Nome
                    <input v-model="form.name" required maxlength="255" />
                </label>

                <label>
                    Email
                    <input :value="form.email" readonly />
                </label>

                <label>
                    Perfil
                    <input :value="isOperator ? 'Operador' : 'Cliente'" readonly />
                </label>

                <label class="checkbox-line">
                    <input v-model="form.is_active" type="checkbox" />
                    Ativo
                </label>

                <label v-if="isOperator && canSetAdmin" class="checkbox-line">
                    <input v-model="form.is_admin" type="checkbox" />
                    Operador admin (acesso global)
                </label>

                <label v-if="isClient" class="full-row">
                    Nome de contacto
                    <input v-model="form.contact_name" maxlength="255" />
                </label>

                <template v-if="isOperator">
                    <div class="full-row">
                        <p class="field-label">Acessos a inboxes</p>
                        <div class="checks">
                            <label v-for="inbox in manageableInboxes" :key="`edit-modal-inbox-${inbox.id}`">
                                <input
                                    type="checkbox"
                                    :checked="form.inbox_ids.includes(inbox.id)"
                                    @change="onToggleInbox(inbox.id)"
                                />
                                {{ inbox.name }}
                            </label>
                        </div>
                    </div>

                    <div class="full-row">
                        <p class="field-label">Permissão para gerir utilizadores (por inbox)</p>
                        <div class="checks">
                            <label v-for="inbox in manageableInboxes" :key="`edit-modal-manager-${inbox.id}`">
                                <input
                                    type="checkbox"
                                    :checked="form.manager_inbox_ids.includes(inbox.id)"
                                    :disabled="!form.inbox_ids.includes(inbox.id)"
                                    @change="onToggleManagerInbox(inbox.id)"
                                />
                                {{ inbox.name }}
                            </label>
                        </div>
                    </div>
                </template>

                <div class="full-row actions modal-actions">
                    <button :disabled="saving" type="submit">
                        {{ saving ? 'A guardar...' : 'Guardar alterações' }}
                    </button>
                    <button type="button" class="ghost" @click="emit('close')">Cancelar</button>
                </div>
            </form>
        </article>
    </section>
</template>

<style scoped>
.muted {
    color: #475569;
}

.grid {
    display: grid;
    grid-template-columns: repeat(2, minmax(0, 1fr));
    gap: 0.7rem;
}

label {
    display: grid;
    gap: 0.3rem;
}

input,
button {
    font: inherit;
}

input {
    border: 1px solid #dbe4ee;
    border-radius: 8px;
    padding: 0.52rem 0.6rem;
}

button {
    border: 1px solid #1F4E79;
    background: #1F4E79;
    color: #fff;
    border-radius: 8px;
    padding: 0.5rem 0.65rem;
    cursor: pointer;
}

button.ghost {
    border-color: #cbd5e1;
    background: #fff;
    color: #0f172a;
}

.checkbox-line {
    display: flex;
    align-items: center;
    gap: 0.45rem;
    align-self: end;
}

.checkbox-line input {
    width: auto;
}

.full-row {
    grid-column: 1 / -1;
}

.field-label {
    margin: 0 0 0.3rem;
    font-size: 0.92rem;
    color: #334155;
}

.checks {
    display: flex;
    flex-wrap: wrap;
    gap: 0.5rem 0.9rem;
}

.checks label {
    display: inline-flex;
    align-items: center;
    gap: 0.35rem;
}

.checks input {
    width: auto;
}

.actions {
    display: flex;
    justify-content: flex-end;
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
    width: min(980px, calc(100vw - 2rem));
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

.modal-header h2 {
    margin: 0;
}

.modal-header p {
    margin: 0.25rem 0 0;
}

.modal-actions {
    align-items: center;
    gap: 0.45rem;
}

@media (max-width: 960px) {
    .grid {
        grid-template-columns: 1fr;
    }
}
</style>
