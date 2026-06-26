import { createRouter, createWebHistory, type RouteRecordRaw } from 'vue-router';

import { useAuthStore } from '@/stores/auth.store';

/**
 * Route `meta.auth` controls access:
 *   - undefined / 'public': anonymous OK
 *   - 'member': any logged-in user (incl. PENDING) — e.g. profile, audit
 *   - 'confirmed': confirmed member or admin (PENDING blocked) — e.g.
 *     creating/editing events
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
    path: '/register',
    name: 'register',
    component: () => import('@/views/RegisterView.vue'),
    meta: { title: 'Registrácia', auth: 'public', layout: 'blank' },
  },
  {
    path: '/forgot-password',
    name: 'forgot-password',
    component: () => import('@/views/ForgotPasswordView.vue'),
    meta: { title: 'Zabudnuté heslo', auth: 'public', layout: 'blank' },
  },
  {
    path: '/reset-password',
    name: 'reset-password',
    component: () => import('@/views/ResetPasswordView.vue'),
    meta: { title: 'Obnova hesla', auth: 'public', layout: 'blank' },
  },
  {
    path: '/oauth/callback',
    name: 'oauth-callback',
    component: () => import('@/views/OAuthCallbackView.vue'),
    meta: { title: 'Prihlasovanie…', auth: 'public', layout: 'blank' },
  },
  {
    path: '/oauth/consent',
    name: 'oauth-consent',
    component: () => import('@/views/OAuthConsentView.vue'),
    meta: { title: 'Dokončenie registrácie', auth: 'public', layout: 'blank' },
  },
  {
    path: '/profil',
    name: 'profile',
    component: () => import('@/views/ProfileView.vue'),
    meta: { title: 'Môj profil', auth: 'member' },
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
    meta: { title: 'Nová udalosť', auth: 'confirmed' },
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
    meta: { title: 'Upraviť udalosť', auth: 'confirmed' },
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
    path: '/damages/:id',
    name: 'damage-detail',
    component: () => import('@/views/DamageDetailView.vue'),
    meta: { title: 'Detail poškodenia' },
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
    path: '/member-roster',
    name: 'member-roster',
    component: () => import('@/views/MemberRosterView.vue'),
    meta: { title: 'Číselník členov', auth: 'admin' },
  },
  {
    path: '/admin/usage',
    name: 'admin-usage',
    component: () => import('@/views/AdminUsageView.vue'),
    meta: { title: 'Štatistiky používania', auth: 'admin' },
  },
  {
    path: '/admin/data',
    name: 'admin-data',
    component: () => import('@/views/AdminDataView.vue'),
    meta: { title: 'Správa dát', auth: 'admin' },
  },
  {
    path: '/admin/qr-codes',
    name: 'admin-qr-codes',
    component: () => import('@/views/AdminQrCodesView.vue'),
    meta: { title: 'QR kódy lodí', auth: 'admin' },
  },
  {
    path: '/rules',
    name: 'reservation-rules',
    component: () => import('@/views/ReservationRulesView.vue'),
    meta: { title: 'Pravidlá rezervácie' },
  },
  {
    path: '/ochrana-udajov',
    name: 'privacy-policy',
    component: () => import('@/views/PrivacyPolicyView.vue'),
    meta: { title: 'Ochrana osobných údajov' },
  },
  {
    path: '/vodacky-semafor',
    name: 'paddling-traffic-light',
    component: () => import('@/views/PaddlingTrafficLightView.vue'),
    meta: { title: 'Vodácky semafor' },
  },
  {
    path: '/q-a',
    name: 'faq',
    component: () => import('@/views/FaqView.vue'),
    meta: { title: 'Otázky a odpovede' },
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
  const required = to.meta?.auth as 'public' | 'member' | 'confirmed' | 'admin' | undefined;
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

  if (required === 'confirmed' && !auth.isMember) {
    // Logged in but PENDING — confirmed-member action, bounce to dashboard.
    return { name: 'dashboard' };
  }

  return true;
});

router.afterEach((to) => {
  const title = (to.meta?.title as string | undefined) ?? 'Lodenica KVŠ';
  document.title = `Lodenica KVŠ · ${title}`;
});
