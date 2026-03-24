import axios from 'axios';

const metaCsrfToken = document
    .querySelector('meta[name="csrf-token"]')
    ?.getAttribute('content');

const getCookieValue = (name) => {
    const cookie = document.cookie
        .split('; ')
        .find((entry) => entry.startsWith(`${name}=`));

    if (!cookie) {
        return null;
    }

    return cookie.substring(name.length + 1);
};

const api = axios.create({
    baseURL: '/app-api',
    headers: {
        Accept: 'application/json',
        'X-Requested-With': 'XMLHttpRequest',
    },
    withCredentials: true,
    xsrfCookieName: 'XSRF-TOKEN',
    xsrfHeaderName: 'X-XSRF-TOKEN',
});

api.interceptors.request.use((config) => {
    const cookieToken = getCookieValue('XSRF-TOKEN');
    const requestToken = cookieToken ? decodeURIComponent(cookieToken) : metaCsrfToken;

    if (requestToken) {
        config.headers = config.headers ?? {};
        config.headers['X-XSRF-TOKEN'] = requestToken;
    }

    return config;
});

export default api;
