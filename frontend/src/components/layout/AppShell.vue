<script setup lang="ts">
import { computed, ref } from 'vue';
import { RouterLink, useRoute, useRouter } from 'vue-router';

import { NAV_LABELS } from '@/i18n/labels';
import { useAuthStore } from '@/stores/auth.store';

const route = useRoute();
const router = useRouter();
const auth = useAuthStore();
const navOpen = ref(false);

interface NavItem {
  to: string;
  label: string;
  icon: string;
  /** Visibility gate. `undefined` = always visible. */
  requires?: 'member' | 'admin';
  /** External URL — rendered as a regular <a target="_blank"> instead of a RouterLink. */
  external?: boolean;
}

const navItems = computed<NavItem[]>(() => {
  const items: NavItem[] = [
    { to: '/', label: NAV_LABELS.dashboard, icon: '📊' },
    { to: '/resources', label: NAV_LABELS.resources, icon: '🛶' },
    { to: '/reservations', label: NAV_LABELS.reservations, icon: '📅' },
    { to: '/timeline', label: NAV_LABELS.timeline, icon: '⏱️' },
    { to: '/calendar', label: NAV_LABELS.calendar, icon: '🗓️' },
    { to: '/events', label: NAV_LABELS.events, icon: '🎉' },
    { to: '/spaces', label: NAV_LABELS.spaces, icon: '🏠' },
    { to: '/damages', label: NAV_LABELS.damages, icon: '🛠️' },
    { to: '/rules', label: 'Pravidlá rezervácie', icon: '📋' },
    { to: '/audit', label: NAV_LABELS.audit, icon: '📜', requires: 'member' },
    { to: '/admin/users', label: 'Používatelia', icon: '👥', requires: 'admin' },
    { to: '/admin/usage', label: 'Štatistiky', icon: '📈', requires: 'admin' },
    {
      to: 'https://www.lodenicakvs.sk/?page_id=4578',
      label: 'Lodeničný poriadok',
      icon: '📘',
      external: true,
    },
  ];
  return items.filter((item) => {
    if (!item.requires) return true;
    if (item.requires === 'member') return auth.isAuthenticated;
    if (item.requires === 'admin') return auth.isAdmin;
    return true;
  });
});

function isActive(path: string): boolean {
  if (path === '/') return route.path === '/';
  return route.path.startsWith(path);
}

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
              src="/favicon-192.png"
              alt=""
              aria-hidden="true"
              class="h-8 w-8 rounded-lg object-contain"
            />
            <span class="text-lg font-semibold tracking-tight text-slate-900">Lodenica KVS</span>
          </RouterLink>
        </div>
        <div class="hidden items-center gap-3 sm:flex">
          <RouterLink to="/reservations/new" class="btn-primary">
            <span aria-hidden="true">＋</span>
            Vytvoriť rezerváciu
          </RouterLink>
          <template v-if="auth.isAuthenticated">
            <span class="text-sm text-slate-600">
              {{ auth.user?.email }}
              <span
                v-if="auth.isAdmin"
                class="ml-1 rounded-full bg-amber-100 px-2 py-0.5 text-xs font-medium text-amber-800 ring-1 ring-amber-200"
              >
                admin
              </span>
            </span>
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
          <template v-for="item in navItems" :key="item.to">
            <a
              v-if="item.external"
              :href="item.to"
              target="_blank"
              rel="noopener noreferrer"
              class="flex items-center gap-3 rounded-lg px-3 py-2 text-sm font-medium text-slate-700 hover:bg-slate-100"
              @click="navOpen = false"
            >
              <span aria-hidden="true">{{ item.icon }}</span>
              <span>{{ item.label }}</span>
              <span aria-hidden="true" class="ml-auto text-xs text-slate-400">↗</span>
            </a>
            <RouterLink
              v-else
              :to="item.to"
              class="flex items-center gap-3 rounded-lg px-3 py-2 text-sm font-medium text-slate-700 hover:bg-slate-100"
              :class="
                isActive(item.to) ? 'bg-brand-50 text-brand-800 ring-1 ring-brand-100' : ''
              "
              @click="navOpen = false"
            >
              <span aria-hidden="true">{{ item.icon }}</span>
              <span>{{ item.label }}</span>
            </RouterLink>
          </template>
        </nav>
        <div class="mt-6 sm:hidden space-y-2">
          <RouterLink to="/reservations/new" class="btn-primary w-full">
            ＋ Vytvoriť rezerváciu
          </RouterLink>
          <template v-if="auth.isAuthenticated">
            <div class="rounded-lg bg-slate-50 px-3 py-2 text-xs text-slate-600 ring-1 ring-slate-200">
              {{ auth.user?.email }}
              <span v-if="auth.isAdmin" class="ml-1 font-semibold text-amber-700">(admin)</span>
            </div>
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
