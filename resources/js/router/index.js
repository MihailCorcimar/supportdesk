import { createRouter, createWebHistory } from 'vue-router';
import LoginPage from '../pages/LoginPage.vue';
import AcceptInvitePage from '../pages/AcceptInvitePage.vue';
import DashboardPage from '../pages/DashboardPage.vue';
import TicketsListPage from '../pages/TicketsListPage.vue';
import TicketCreatePage from '../pages/TicketCreatePage.vue';
import TicketShowPage from '../pages/TicketShowPage.vue';
import UsersManagementPage from '../pages/UsersManagementPage.vue';
import { useAuthStore } from '../stores/auth';

const routes = [
    {
        path: '/login',
        name: 'login',
        component: LoginPage,
        meta: { guestOnly: true, hideShell: true },
    },
    {
        path: '/accept-invite',
        name: 'invite.accept',
        component: AcceptInvitePage,
        meta: { guestOnly: true, hideShell: true },
    },
    {
        path: '/',
        redirect: { name: 'tickets.index' },
    },
    {
        path: '/tickets',
        name: 'tickets.index',
        component: TicketsListPage,
        meta: { requiresAuth: true },
    },
    {
        path: '/dashboard',
        name: 'dashboard',
        component: DashboardPage,
        meta: { requiresAuth: true },
    },
    {
        path: '/users',
        name: 'users.index',
        component: UsersManagementPage,
        meta: { requiresAuth: true, requiresUserManager: true },
    },
    {
        path: '/tickets/new',
        name: 'tickets.create',
        component: TicketCreatePage,
        meta: { requiresAuth: true },
    },
    {
        path: '/tickets/:id',
        name: 'tickets.show',
        component: TicketShowPage,
        meta: { requiresAuth: true },
    },
    {
        path: '/:pathMatch(.*)*',
        redirect: { name: 'tickets.index' },
    },
];

const router = createRouter({
    history: createWebHistory(),
    routes,
});

router.beforeEach(async (to) => {
    const auth = useAuthStore();

    if (!auth.state.loaded) {
        await auth.fetchUser();
    }

    if (to.meta.requiresAuth && !auth.state.user) {
        return { name: 'login' };
    }

    if (to.meta.guestOnly && auth.state.user) {
        return { name: 'tickets.index' };
    }

    if (to.meta.requiresUserManager && !auth.state.user?.can_manage_users) {
        return { name: 'tickets.index' };
    }

    return true;
});

export default router;
