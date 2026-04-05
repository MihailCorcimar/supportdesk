<script setup>
import { computed, onMounted, reactive, ref } from 'vue';
import api from '../api/client';
import { useAuthStore } from '../stores/auth';

const auth = useAuthStore();

const loading = ref(true);
const savingProfile = ref(false);
const savingPassword = ref(false);
const savingAvatar = ref(false);
const removingAvatar = ref(false);

const error = ref('');
const success = ref('');
const profile = ref(null);

const avatarFileInput = ref(null);
const avatarFile = ref(null);
const avatarPreviewUrl = ref('');

const profileForm = reactive({
    name: '',
    email: '',
});

const passwordForm = reactive({
    current_password: '',
    password: '',
    password_confirmation: '',
});

const roleLabel = computed(() => {
    if (!profile.value) {
        return '-';
    }

    if (profile.value.role === 'operator') {
        return profile.value.is_admin ? 'Operador admin' : 'Operador';
    }

    return 'Cliente';
});

const userInitials = computed(() => {
    const fullName = String(profile.value?.name || '').trim();
    if (!fullName) {
        return 'U';
    }

    const parts = fullName.split(/\s+/).filter(Boolean);
    const initials = parts.slice(0, 2).map((part) => part[0]?.toUpperCase() || '').join('');

    return initials || 'U';
});

const currentAvatarUrl = computed(() => {
    if (avatarPreviewUrl.value) {
        return avatarPreviewUrl.value;
    }

    const avatarUrl = String(profile.value?.avatar_url || '').trim();
    return avatarUrl || null;
});

const normalizeError = (exception, fallbackMessage) => {
    return exception?.response?.data?.message
        || Object.values(exception?.response?.data?.errors || {})?.[0]?.[0]
        || fallbackMessage;
};

const fillForm = (userData) => {
    profileForm.name = userData?.name || '';
    profileForm.email = userData?.email || '';
};

const refreshAuthUser = async () => {
    await auth.fetchUser();
    profile.value = auth.state.user;
};

const loadProfile = async () => {
    loading.value = true;
    error.value = '';

    try {
        const response = await api.get('/me');
        profile.value = response.data?.data || null;
        fillForm(profile.value);
    } catch (exception) {
        profile.value = auth.state.user;
        fillForm(profile.value);
        error.value = normalizeError(exception, 'Não foi possível carregar o perfil.');
    } finally {
        loading.value = false;
    }
};

const onAvatarSelected = (event) => {
    const file = event.target?.files?.[0] || null;
    avatarFile.value = file;

    if (avatarPreviewUrl.value) {
        URL.revokeObjectURL(avatarPreviewUrl.value);
        avatarPreviewUrl.value = '';
    }

    if (file) {
        avatarPreviewUrl.value = URL.createObjectURL(file);
    }
};

const clearAvatarSelection = () => {
    avatarFile.value = null;

    if (avatarPreviewUrl.value) {
        URL.revokeObjectURL(avatarPreviewUrl.value);
        avatarPreviewUrl.value = '';
    }

    if (avatarFileInput.value) {
        avatarFileInput.value.value = '';
    }
};

const saveAvatar = async () => {
    if (!avatarFile.value) {
        return;
    }

    savingAvatar.value = true;
    error.value = '';
    success.value = '';

    try {
        const formData = new FormData();
        formData.append('avatar', avatarFile.value);

        const response = await api.post('/me/avatar', formData, {
            headers: {
                'Content-Type': 'multipart/form-data',
            },
        });

        await refreshAuthUser();
        clearAvatarSelection();
        success.value = response.data?.message || 'Foto de perfil atualizada com sucesso.';
    } catch (exception) {
        error.value = normalizeError(exception, 'Não foi possível atualizar a foto de perfil.');
    } finally {
        savingAvatar.value = false;
    }
};

const removeAvatar = async () => {
    removingAvatar.value = true;
    error.value = '';
    success.value = '';

    try {
        const response = await api.delete('/me/avatar');
        await refreshAuthUser();
        clearAvatarSelection();
        success.value = response.data?.message || 'Foto de perfil removida com sucesso.';
    } catch (exception) {
        error.value = normalizeError(exception, 'Não foi possível remover a foto de perfil.');
    } finally {
        removingAvatar.value = false;
    }
};

const saveProfile = async () => {
    savingProfile.value = true;
    error.value = '';
    success.value = '';

    try {
        const response = await api.patch('/me', {
            name: profileForm.name,
            email: profileForm.email,
        });

        await refreshAuthUser();
        success.value = response.data?.message || 'Perfil atualizado com sucesso.';
    } catch (exception) {
        error.value = normalizeError(exception, 'Não foi possível atualizar o perfil.');
    } finally {
        savingProfile.value = false;
    }
};

const savePassword = async () => {
    savingPassword.value = true;
    error.value = '';
    success.value = '';

    try {
        const response = await api.patch('/me', {
            current_password: passwordForm.current_password,
            password: passwordForm.password,
            password_confirmation: passwordForm.password_confirmation,
        });

        passwordForm.current_password = '';
        passwordForm.password = '';
        passwordForm.password_confirmation = '';
        success.value = response.data?.message || 'Palavra-passe atualizada com sucesso.';
    } catch (exception) {
        error.value = normalizeError(exception, 'Não foi possível atualizar a palavra-passe.');
    } finally {
        savingPassword.value = false;
    }
};

onMounted(loadProfile);
</script>

<template>
    <section class="profile-page">
        <article class="profile-card">
            <header class="profile-header">
                <div>
                    <h1>Meu perfil</h1>
                    <p class="subtitle">Dados da conta, fotografia e segurança de acesso.</p>
                </div>
            </header>

            <p v-if="loading" class="muted">A carregar...</p>
            <p v-if="error" class="error">{{ error }}</p>
            <p v-if="success" class="success">{{ success }}</p>

            <template v-if="!loading && profile">
                <section class="avatar-panel">
                    <div class="avatar-preview">
                        <img v-if="currentAvatarUrl" :src="currentAvatarUrl" :alt="`Avatar de ${profile.name}`">
                        <span v-else>{{ userInitials }}</span>
                    </div>

                    <div class="avatar-actions">
                        <h2>Fotografia de perfil</h2>
                        <p>PNG, JPG ou WEBP até 2 MB.</p>

                        <label class="file-field">
                            <span>Selecionar foto</span>
                            <input
                                ref="avatarFileInput"
                                type="file"
                                accept="image/png,image/jpeg,image/webp"
                                @change="onAvatarSelected"
                            >
                        </label>

                        <div class="avatar-buttons">
                            <button
                                class="btn-primary"
                                type="button"
                                :disabled="!avatarFile || savingAvatar"
                                @click="saveAvatar"
                            >
                                {{ savingAvatar ? 'A guardar...' : 'Guardar foto' }}
                            </button>
                            <button
                                class="btn-secondary"
                                type="button"
                                :disabled="(!profile.avatar_url && !avatarPreviewUrl) || removingAvatar"
                                @click="removeAvatar"
                            >
                                {{ removingAvatar ? 'A remover...' : 'Remover foto' }}
                            </button>
                        </div>
                    </div>
                </section>

                <div class="summary-grid">
                    <div class="summary-card">
                        <span class="label">Perfil</span>
                        <strong>{{ roleLabel }}</strong>
                    </div>
                    <div class="summary-card">
                        <span class="label">Estado</span>
                        <strong>{{ profile.is_active ? 'Ativo' : 'Inativo' }}</strong>
                    </div>
                    <div class="summary-card">
                        <span class="label">Permissões</span>
                        <strong>{{ profile.can_manage_users ? 'Gestão de utilizadores' : 'Utilizador standard' }}</strong>
                    </div>
                </div>

                <div class="form-grid">
                    <form class="box" @submit.prevent="saveProfile">
                        <h2>Dados da conta</h2>

                        <label>
                            Nome
                            <input v-model="profileForm.name" type="text" maxlength="255" required>
                        </label>

                        <label>
                            Email
                            <input v-model="profileForm.email" type="email" maxlength="255" required>
                        </label>

                        <button class="btn-primary" type="submit" :disabled="savingProfile">
                            {{ savingProfile ? 'A guardar...' : 'Guardar dados' }}
                        </button>
                    </form>

                    <form class="box" @submit.prevent="savePassword">
                        <h2>Alterar palavra-passe</h2>

                        <label>
                            Palavra-passe atual
                            <input
                                v-model="passwordForm.current_password"
                                type="password"
                                minlength="8"
                                autocomplete="current-password"
                                required
                            >
                        </label>

                        <label>
                            Nova palavra-passe
                            <input
                                v-model="passwordForm.password"
                                type="password"
                                minlength="8"
                                autocomplete="new-password"
                                required
                            >
                        </label>

                        <label>
                            Confirmar nova palavra-passe
                            <input
                                v-model="passwordForm.password_confirmation"
                                type="password"
                                minlength="8"
                                autocomplete="new-password"
                                required
                            >
                        </label>

                        <button class="btn-primary" type="submit" :disabled="savingPassword">
                            {{ savingPassword ? 'A atualizar...' : 'Atualizar palavra-passe' }}
                        </button>
                    </form>
                </div>
            </template>
        </article>
    </section>
</template>

<style scoped>
.profile-page {
    display: grid;
}

.profile-card {
    background: #fff;
    border: 1px solid #d8e1ed;
    border-radius: 14px;
    padding: 1rem;
    display: grid;
    gap: 0.9rem;
}

.profile-header h1 {
    margin: 0;
    font-size: 2rem;
}

.subtitle {
    margin: 0.2rem 0 0;
    color: #64748b;
}

.avatar-panel {
    border: 1px solid #dbe4ef;
    border-radius: 12px;
    background: linear-gradient(140deg, #f8fbff 0%, #f6fffb 100%);
    padding: 0.8rem;
    display: grid;
    grid-template-columns: auto 1fr;
    gap: 0.8rem;
    align-items: center;
}

.avatar-preview {
    width: 84px;
    height: 84px;
    border-radius: 50%;
    overflow: hidden;
    background: linear-gradient(145deg, #1f2a44 0%, #2e3e66 100%);
    color: #fff;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    font-size: 1.5rem;
    font-weight: 700;
}

.avatar-preview img {
    width: 100%;
    height: 100%;
    object-fit: cover;
}

.avatar-actions h2 {
    margin: 0;
    font-size: 1.1rem;
}

.avatar-actions p {
    margin: 0.2rem 0 0.5rem;
    color: #64748b;
}

.file-field {
    display: inline-grid;
    gap: 0.3rem;
    color: #334155;
    font-size: 0.86rem;
}

.avatar-buttons {
    margin-top: 0.5rem;
    display: flex;
    gap: 0.45rem;
    flex-wrap: wrap;
}

.summary-grid {
    display: grid;
    grid-template-columns: repeat(3, minmax(0, 1fr));
    gap: 0.6rem;
}

.summary-card {
    border: 1px solid #dbe4ef;
    border-radius: 10px;
    background: #f9fbff;
    padding: 0.7rem;
    display: grid;
    gap: 0.2rem;
}

.summary-card .label {
    color: #64748b;
    font-size: 0.78rem;
}

.form-grid {
    display: grid;
    grid-template-columns: repeat(2, minmax(0, 1fr));
    gap: 0.8rem;
}

.box {
    border: 1px solid #dbe4ef;
    border-radius: 12px;
    background: #fbfdff;
    padding: 0.8rem;
    display: grid;
    gap: 0.65rem;
}

.box h2 {
    margin: 0;
    font-size: 1.1rem;
}

label {
    display: grid;
    gap: 0.3rem;
    color: #334155;
    font-size: 0.9rem;
}

input {
    border: 1px solid #d0dbe8;
    border-radius: 9px;
    padding: 0.5rem 0.6rem;
    font: inherit;
    color: #0f172a;
    background: #fff;
}

input:focus {
    outline: 2px solid rgba(31, 184, 115, 0.25);
    border-color: #9ab9d8;
}

.btn-primary {
    justify-self: start;
    border: 1px solid #1fb873;
    border-radius: 9px;
    background: #1fb873;
    color: #fff;
    font: inherit;
    padding: 0.48rem 0.82rem;
    cursor: pointer;
}

.btn-secondary {
    justify-self: start;
    border: 1px solid #c7d4e5;
    border-radius: 9px;
    background: #fff;
    color: #24364f;
    font: inherit;
    padding: 0.48rem 0.82rem;
    cursor: pointer;
}

.btn-primary:disabled,
.btn-secondary:disabled {
    opacity: 0.7;
    cursor: not-allowed;
}

.muted {
    margin: 0;
    color: #64748b;
}

.error {
    margin: 0;
    border: 1px solid #fecaca;
    border-radius: 10px;
    background: #fef2f2;
    color: #991b1b;
    padding: 0.6rem 0.7rem;
}

.success {
    margin: 0;
    border: 1px solid #9ab9d8;
    border-radius: 10px;
    background: #EDF3FA;
    color: #1F4E79;
    padding: 0.6rem 0.7rem;
}

@media (max-width: 980px) {
    .summary-grid,
    .form-grid {
        grid-template-columns: 1fr;
    }

    .profile-header h1 {
        font-size: 1.65rem;
    }

    .avatar-panel {
        grid-template-columns: 1fr;
        justify-items: center;
        text-align: center;
    }

    .avatar-buttons {
        justify-content: center;
    }
}
</style>


