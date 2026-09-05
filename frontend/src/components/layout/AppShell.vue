<script setup lang="ts">
import { computed, ref, watch } from 'vue';
import { RouterLink, useRoute, useRouter } from 'vue-router';

import { DEFAULT_LOGO } from '@/composables/useSiteChrome';
import { NAV_LABELS } from '@/i18n/labels';
import { useApprovalsStore } from '@/stores/approvals.store';
import { useAuthStore } from '@/stores/auth.store';
import { useSiteStore } from '@/stores/site.store';

import { externalItem, isActivePath, type NavGroup as NavGroupModel, type NavItem } from './nav';
import NavGroup from './NavGroup.vue';

const route = useRoute();
const router = useRouter();
const auth = useAuthStore();
const approvals = useApprovalsStore();
const site = useSiteStore();
const navOpen = ref(false);

/**
 * Everyday operational entries. /timeline and /calendar still exist as
 * routes; they're surfaced from inside ReservationsView so members reach
 * them when the task fits. Admins find Na schválenie, Lode (as Zdroje)
 * and História zmien under Administrácia → Správa instead (NAV-001).
 */
const mainItems = computed<NavItem[]>(() => {
  const items: NavItem[] = [
    { to: '/', label: NAV_LABELS.dashboard, icon: '📊' },
    { to: '/reservations', label: NAV_LABELS.reservations, icon: '📅' },
  ];
  // A non-admin approver sees it while something waits for them.
  if (auth.isMember && !auth.isAdmin && approvals.pendingCount > 0) {
    items.push({ to: '/approvals', label: NAV_LABELS.approvals, icon: '✅', badge: approvals.pendingCount });
  }
  items.push(
    { to: '/events', label: NAV_LABELS.events, icon: '🎉' },
    { to: '/spaces', label: NAV_LABELS.spaces, icon: '🏠' },
    { to: '/damages', label: NAV_LABELS.damages, icon: '🛠️' },
  );
  // The equipment list is public; admins manage it from Administrácia.
  if (!auth.isAdmin) {
    items.push({ to: '/resources', label: NAV_LABELS.resources, icon: '🛶' });
  }
  if (auth.isMember && site.config.features.expeditions) {
    items.push({ to: '/expeditions', label: 'Expedície', icon: '🗺️' });
  }
  if (auth.isAuthenticated) {
    items.push({ to: '/profil', label: 'Môj profil', icon: '👤' });
  }
  // Admins find the audit log under Administrácia → Správa (NAV-001).
  if (auth.isAuthenticated && !auth.isAdmin) {
    items.push({ to: '/audit', label: NAV_LABELS.audit, icon: '📜' });
  }
  return items;
});

/** Informational pages + the club's external documents (empty URL = hidden). */
const infoGroup = computed<NavGroupModel>(() => ({
  key: 'info',
  label: 'Informácie',
  icon: 'ℹ️',
  items: [
    ...(site.config.features.paddlingTrafficLight
      ? [{ to: '/vodacky-semafor', label: 'Vodácky semafor', icon: '🚦' }]
      : []),
    { to: '/rules', label: 'Pravidlá rezervácie', icon: '📋' },
    { to: '/q-a', label: 'Otázky a odpovede', icon: '❓' },
    ...externalItem(site.config.websiteUrl, 'Web klubu', '🌐'),
    ...externalItem(site.config.gdprNoticeUrl, 'GDPR – Informačná povinnosť', '🔒'),
    ...externalItem(site.config.gdprConsentUrl, 'GDPR – Súhlas dotknutej osoby', '📝'),
    ...externalItem(site.config.rulesUrl, 'Prevádzkový poriadok', '📘'),
  ],
}));

/** Admin-only: "Správa" (people and records) and "Systém" (this installation). */
const adminGroup = computed<NavGroupModel | null>(() =>
  auth.isAdmin
    ? {
        key: 'admin',
        label: 'Administrácia',
        icon: '🛡️',
        items: [],
        subgroups: [
          {
            label: 'Správa',
            items: [
              { to: '/resources', label: 'Zdroje', icon: '🛶' },
              { to: '/approvals', label: NAV_LABELS.approvals, icon: '✅', badge: approvals.pendingCount },
              { to: '/admin/users', label: 'Používatelia', icon: '👥' },
              { to: '/member-roster', label: 'Číselník členov', icon: '📇' },
              { to: '/admin/usage', label: 'Štatistiky', icon: '📈' },
              { to: '/audit', label: NAV_LABELS.audit, icon: '📜' },
              { to: '/admin/qr-codes', label: 'QR kódy lodí', icon: '🔳' },
            ],
          },
          {
            label: 'Systém',
            items: [
              { to: '/admin/site', label: 'Nastavenia stránky', icon: '⚙️' },
              { to: '/admin/diagnostics', label: 'Diagnostika e-mailov', icon: '✉️' },
              { to: '/admin/data', label: 'Správa dát', icon: '💾' },
            ],
          },
        ],
      }
    : null,
);

// Keep the approvals count in step with the session: load it when the user
// becomes a confirmed member, drop it on logout.
watch(
  () => auth.isMember,
  (member) => {
    if (member) void approvals.refresh();
    else approvals.clear();
  },
  { immediate: true },
);

async function logout(): Promise<void> {
  await auth.logout();
  navOpen.value = false;
  await router.push('/login');
}
</script>

<template>
  <div class="min-h-screen bg-slate-50">
    <header class="sticky top-0 z-30 border-b border-slate-200 bg-white/80 backdrop-blur">
      <div class="mx-auto flex max-w-7xl items-center justify-between gap-4 px-4 py-3 sm:px-6">
        <div class="flex items-center gap-3">
          <button
            type="button"
            class="rounded-lg p-2 text-slate-700 ring-1 ring-slate-200 hover:bg-slate-100 xl:hidden"
            aria-label="Otvoriť menu"
            @click="navOpen = !navOpen"
          >
            <svg width="20" height="20" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true">
              <path
                fill-rule="evenodd"
                d="M3 5h14a1 1 0 010 2H3a1 1 0 010-2zm0 4h14a1 1 0 010 2H3a1 1 0 010-2zm0 4h14a1 1 0 010 2H3a1 1 0 010-2z"
                clip-rule="evenodd"
              />
            </svg>
          </button>
          <RouterLink to="/" class="flex items-center gap-2">
            <img
              :src="site.config.logoUrl ?? DEFAULT_LOGO"
              alt=""
              aria-hidden="true"
              class="h-8 w-8 rounded-lg object-contain"
            />
            <span class="text-lg font-semibold tracking-tight text-slate-900">{{ site.config.shortName }}</span>
          </RouterLink>
        </div>
        <div class="hidden items-center gap-3 sm:flex">
          <RouterLink to="/reservations/new" class="btn-primary">
            <span aria-hidden="true">＋</span>
            Vytvoriť rezerváciu
          </RouterLink>
          <template v-if="auth.isAuthenticated">
            <RouterLink to="/profil" class="text-sm text-slate-600 hover:text-slate-900 hover:underline">
              {{ auth.user?.email }}
              <span
                v-if="auth.isAdmin"
                class="ml-1 rounded-full bg-amber-100 px-2 py-0.5 text-xs font-medium text-amber-800 ring-1 ring-amber-200"
              >
                admin
              </span>
            </RouterLink>
            <button type="button" class="btn-secondary" @click="logout">Odhlásiť</button>
          </template>
          <template v-else>
            <RouterLink to="/login" class="btn-secondary">Prihlásiť sa</RouterLink>
          </template>
        </div>
      </div>
    </header>

    <div class="mx-auto flex max-w-7xl gap-6 px-4 py-6 sm:px-6">
      <aside
        :class="[
          'fixed inset-y-0 left-0 z-40 w-64 transform border-r border-slate-200 bg-white p-4 transition-transform xl:static xl:translate-x-0 xl:border-0 xl:bg-transparent xl:p-0',
          navOpen ? 'translate-x-0 shadow-xl' : '-translate-x-full',
        ]"
      >
        <nav class="space-y-1">
          <RouterLink
            v-for="item in mainItems"
            :key="item.to"
            :to="item.to"
            class="flex items-center gap-3 rounded-lg px-3 py-2 text-sm font-medium text-slate-700 hover:bg-slate-100"
            :class="isActivePath(route.path, item.to) ? 'bg-brand-50 text-brand-800 ring-1 ring-brand-100' : ''"
            @click="navOpen = false"
          >
            <span aria-hidden="true">{{ item.icon }}</span>
            <span>{{ item.label }}</span>
            <span
              v-if="item.badge"
              class="ml-auto rounded-full bg-amber-500 px-1.5 py-0.5 text-[10px] font-bold text-white"
            >{{ item.badge }}</span>
          </RouterLink>

          <!-- Informational pages + the club's documents, collapsed so the
               everyday nav stays short. Auto-expands on its pages. -->
          <NavGroup :group="infoGroup" :active-path="route.path" @navigate="navOpen = false" />

          <!-- Admin-only. "Správa" = people and records, "Systém" = this
               installation (site settings, mail, data). -->
          <NavGroup
            v-if="adminGroup"
            :group="adminGroup"
            :active-path="route.path"
            @navigate="navOpen = false"
          />
        </nav>
        <div class="mt-6 sm:hidden space-y-2">
          <RouterLink to="/reservations/new" class="btn-primary w-full">
            ＋ Vytvoriť rezerváciu
          </RouterLink>
          <template v-if="auth.isAuthenticated">
            <RouterLink
              to="/profil"
              class="block rounded-lg bg-slate-50 px-3 py-2 text-xs text-slate-600 ring-1 ring-slate-200 hover:bg-slate-100"
              @click="navOpen = false"
            >
              {{ auth.user?.email }}
              <span v-if="auth.isAdmin" class="ml-1 font-semibold text-amber-700">(admin)</span>
              <span class="ml-1 text-slate-400">· môj profil</span>
            </RouterLink>
            <button type="button" class="btn-secondary w-full" @click="logout">Odhlásiť</button>
          </template>
          <RouterLink v-else to="/login" class="btn-secondary w-full">Prihlásiť sa</RouterLink>
        </div>
      </aside>

      <main class="min-w-0 flex-1">
        <slot />
      </main>
    </div>

    <div
      v-if="navOpen"
      class="fixed inset-0 z-30 bg-slate-900/30 xl:hidden"
      aria-hidden="true"
      @click="navOpen = false"
    />
  </div>
</template>
