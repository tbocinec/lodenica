import { createRouter, createWebHistory, type RouteRecordRaw } from 'vue-router';

import { useAuthStore } from '@/stores/auth.store';

/**
 * Route `meta.auth` controls access:
 *   - undefined / 'public': anonymous OK
 *   - 'member': any logged-in user (MEMBER or ADMIN)
 *   - 'admin': ADMIN role only
 *
 * The global beforeEach guard redirects to /login when meta gates fail,
 * preserving the original target in `?redirect=…` so login can bounce
 * back. The auth store is bootstrapped from main.ts before mount so we
 * can read auth state synchronously here.
 */
const routes: RouteRecordRaw[] = [
  {
    path: '/login',
    name: 'login',
    component: () => import('@/views/LoginView.vue'),
    meta: { title: 'Prihlásenie', auth: 'public', layout: 'blank' },
  },
  {
    path: '/',
    name: 'dashboard',
    component: () => import('@/views/DashboardView.vue'),
    meta: { title: 'Prehľad' },
  },
  {
    path: '/resources',
    name: 'resources',
    component: () => import('@/views/ResourcesView.vue'),
    meta: { title: 'Lode' },
  },
  {
    path: '/resources/new',
    name: 'resources-create',
    component: () => import('@/views/ResourceFormView.vue'),
    meta: { title: 'Pridať loď', auth: 'admin' },
  },
  {
    path: '/resources/:id',
    name: 'resources-detail',
    component: () => import('@/views/ResourceDetailView.vue'),
    meta: { title: 'Detail lode' },
    props: true,
  },
  {
    path: '/resources/:id/edit',
    name: 'resources-edit',
    component: () => import('@/views/ResourceFormView.vue'),
    meta: { title: 'Upraviť loď', auth: 'admin' },
    props: true,
  },
  {
    path: '/reservations',
    name: 'reservations',
    component: () => import('@/views/ReservationsView.vue'),
    meta: { title: 'Rezervácie' },
  },
  {
    path: '/reservations/new',
    name: 'reservations-create',
    component: () => import('@/views/ReservationFormView.vue'),
    meta: { title: 'Vytvoriť rezerváciu' },
  },
  {
    path: '/events',
    name: 'events',
    component: () => import('@/views/EventsView.vue'),
    meta: { title: 'Lodenicné udalosti' },
  },
  {
    path: '/events/new',
    name: 'events-create',
    component: () => import('@/views/EventFormView.vue'),
    meta: { title: 'Nová udalosť' },
  },
  {
    path: '/events/:id',
    name: 'events-detail',
    component: () => import('@/views/EventDetailView.vue'),
    meta: { title: 'Detail udalosti' },
    props: true,
  },
  {
    path: '/events/:id/edit',
    name: 'events-edit',
    component: () => import('@/views/EventFormView.vue'),
    meta: { title: 'Upraviť udalosť' },
    props: true,
  },
  {
    path: '/calendar',
    name: 'calendar',
    component: () => import('@/views/CalendarView.vue'),
    meta: { title: 'Kalendár' },
  },
  {
    path: '/timeline',
    name: 'timeline',
    component: () => import('@/views/TimelineView.vue'),
    meta: { title: 'Časová os' },
  },
  {
    path: '/damages',
    name: 'damages',
    component: () => import('@/views/DamagesView.vue'),
    meta: { title: 'Poškodenia' },
  },
  {
    path: '/spaces',
    name: 'spaces',
    component: () => import('@/views/SpacesView.vue'),
    meta: { title: 'Priestory' },
  },
  {
    path: '/audit',
    name: 'audit',
    component: () => import('@/views/AuditView.vue'),
    meta: { title: 'História zmien', auth: 'member' },
  },
  {
    path: '/admin/users',
    name: 'admin-users',
    component: () => import('@/views/AdminUsersView.vue'),
    meta: { title: 'Používatelia', auth: 'admin' },
  },
  {
    path: '/admin/usage',
    name: 'admin-usage',
    component: () => import('@/views/AdminUsageView.vue'),
    meta: { title: 'Štatistiky používania', auth: 'admin' },
  },
  {
    path: '/rules',
    name: 'reservation-rules',
    component: () => import('@/views/ReservationRulesView.vue'),
    meta: { title: 'Pravidlá rezervácie' },
  },
  {
    path: '/:pathMatch(.*)*',
    component: () => import('@/views/NotFoundView.vue'),
    meta: { title: 'Stránka nenájdená' },
  },
];

export const router = createRouter({
  history: createWebHistory(),
  routes,
  scrollBehavior: () => ({ top: 0 }),
});

router.beforeEach((to) => {
  const required = to.meta?.auth as 'public' | 'member' | 'admin' | undefined;
  if (!required || required === 'public') return true;

  const auth = useAuthStore();

  if (!auth.isAuthenticated) {
    return {
      name: 'login',
      query: { redirect: to.fullPath },
    };
  }

  if (required === 'admin' && !auth.isAdmin) {
    // Logged in but not admin — bounce to dashboard.
    return { name: 'dashboard' };
  }

  return true;
});

router.afterEach((to) => {
  const title = (to.meta?.title as string | undefined) ?? 'Lodenica KVS';
  document.title = `Lodenica KVS · ${title}`;
});
