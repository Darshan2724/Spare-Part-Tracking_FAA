import { createRouter, createWebHistory } from 'vue-router';
import { useAuthStore } from '@/stores/auth';

const routes = [
    {
        path: '/login',
        name: 'login',
        component: () => import('@/views/Login.vue'),
        meta: { guestOnly: true }
    },
    {
        path: '/',
        name: 'dashboard',
        component: () => import('@/views/Dashboard.vue'),
        meta: { requiresAuth: true }
    },
    {
        path: '/bom-import',
        name: 'bom-import',
        component: () => import('@/views/BomImport.vue'),
        meta: { requiresAuth: true }
    },
    {
        path: '/store',
        name: 'store',
        component: () => import('@/views/Store.vue'),
        meta: { requiresAuth: true }
    },
    {
        path: '/qc',
        name: 'qc',
        component: () => import('@/views/Qc.vue'),
        meta: { requiresAuth: true }
    },
    {
        path: '/rework',
        name: 'rework',
        component: () => import('@/views/Rework.vue'),
        meta: { requiresAuth: true }
    },
    {
        path: '/purchase',
        name: 'purchase',
        component: () => import('@/views/PurchaseQueue.vue'),
        meta: { requiresAuth: true }
    },
    {
        path: '/paint',
        name: 'paint',
        component: () => import('@/views/Paint.vue'),
        meta: { requiresAuth: true }
    },
    {
        path: '/assembly',
        name: 'assembly',
        component: () => import('@/views/Assembly.vue'),
        meta: { requiresAuth: true }
    },
    {
        path: '/suppliers',
        name: 'suppliers',
        component: () => import('@/views/Suppliers.vue'),
        meta: { requiresAuth: true }
    },
    {
        path: '/report',
        name: 'report',
        component: () => import('@/views/Report.vue'),
        meta: { requiresAuth: true }
    },
    {
        path: '/admin/logs',
        name: 'admin-logs',
        component: () => import('@/views/SystemLogs.vue'),
        meta: { requiresAuth: true, requiresAdmin: true }
    },
    {
        path: '/workflow-hub',
        name: 'workflow-hub',
        component: () => import('@/views/WorkflowHub.vue'),
        meta: { requiresAuth: true }
    },
    {
        path: '/:pathMatch(.*)*',
        redirect: '/'
    }
];

const router = createRouter({
    history: createWebHistory(),
    routes
});

router.beforeEach((to, from, next) => {
    const authStore = useAuthStore();
    
    if (to.meta.requiresAuth && !authStore.isAuthenticated) {
        return next({ name: 'login' });
    }
    
    if (to.meta.guestOnly && authStore.isAuthenticated) {
        return next({ name: 'dashboard' });
    }

    if (to.meta.requiresAdmin && authStore.userRole !== 'ADMIN') {
        return next({ name: 'dashboard' });
    }

    next();
});

export default router;
