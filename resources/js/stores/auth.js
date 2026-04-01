import { reactive } from 'vue';
import api from '../api/client';

const state = reactive({
    user: null,
    loaded: false,
});

export function useAuthStore() {
    const fetchUser = async () => {
        try {
            const response = await api.get('/me');
            state.user = response.data.data;
        } catch (error) {
            state.user = null;
        } finally {
            state.loaded = true;
        }
    };

    const login = async (payload) => {
        await api.post('/login', payload);
        await fetchUser();
    };

    const register = async (payload) => {
        await api.post('/register', payload);
        await fetchUser();
    };

    const logout = async () => {
        await api.post('/logout');
        state.user = null;
    };

    return {
        state,
        fetchUser,
        login,
        register,
        logout,
    };
}
