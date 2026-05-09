import { createRouter, createWebHistory, type RouteRecordRaw } from 'vue-router';

const routes: RouteRecordRaw[] = [
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
    meta: { title: 'Pridať loď' },
  },
  {
    path: '/resources/:id',
    name: 'resources-edit',
    component: () => import('@/views/ResourceFormView.vue'),
    meta: { title: 'Detail lode' },
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

router.afterEach((to) => {
  const title = (to.meta?.title as string | undefined) ?? 'Lodenica';
  document.title = `Lodenica · ${title}`;
});
