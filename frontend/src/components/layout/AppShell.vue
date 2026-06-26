<script setup lang="ts">
import { computed, ref, watch } from 'vue';
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

function visible(item: NavItem): boolean {
  if (!item.requires) return true;
  if (item.requires === 'member') return auth.isAuthenticated;
  if (item.requires === 'admin') return auth.isAdmin;
  return true;
}

// Operational entries — the everyday nav.
const navItems = computed<NavItem[]>(() => {
  const items: NavItem[] = [
    { to: '/', label: NAV_LABELS.dashboard, icon: '📊' },
    // /timeline and /calendar still exist as routes; they're surfaced
    // from inside ReservationsView so members reach them when the
    // task fits ("I know the date but not which boat" → timeline,
    // "browse a whole month" → calendar). The top nav stays focused
    // on operational entries.
    { to: '/reservations', label: NAV_LABELS.reservations, icon: '📅' },
    { to: '/events', label: NAV_LABELS.events, icon: '🎉' },
    { to: '/spaces', label: NAV_LABELS.spaces, icon: '🏠' },
    { to: '/damages', label: NAV_LABELS.damages, icon: '🛠️' },
    // Lode posledné v "každodennej" sekcii — je to encyklopédia výbavy,
    // nie operatívna obrazovka.
    { to: '/resources', label: NAV_LABELS.resources, icon: '🛶' },
    { to: '/audit', label: NAV_LABELS.audit, icon: '📜', requires: 'member' },
    { to: '/admin/users', label: 'Používatelia', icon: '👥', requires: 'admin' },
    { to: '/admin/usage', label: 'Štatistiky', icon: '📈', requires: 'admin' },
    { to: '/admin/qr-codes', label: 'QR kódy lodí', icon: '🔳', requires: 'admin' },
    { to: '/admin/data', label: 'Správa dát', icon: '💾', requires: 'admin' },
  ];
  return items.filter(visible);
});

// Informational pages grouped under a collapsible "Informácie" subsection
// so the main nav stays uncluttered.
const infoItems: NavItem[] = [
  { to: '/vodacky-semafor', label: 'Vodácky semafor', icon: '🚦' },
  { to: '/rules', label: 'Pravidlá rezervácie', icon: '📋' },
  { to: '/q-a', label: 'Otázky a odpovede', icon: '❓' },
  { to: '/ochrana-udajov', label: 'Ochrana údajov', icon: '🔒' },
  {
    to: 'https://www.lodenicakvs.sk/?page_id=4578',
    label: 'Lodeničný poriadok',
    icon: '📘',
    external: true,
  },
];

function isActive(path: string): boolean {
  if (path === '/') return route.path === '/';
  return route.path.startsWith(path);
}

// Expand the "Informácie" group automatically when the user is on one of
// its pages, otherwise keep it collapsed to reduce clutter.
const infoActive = computed(() =>
  infoItems.some((i) => !i.external && isActive(i.to)),
);
const infoOpen = ref(false);
watch(infoActive, (active) => { if (active) infoOpen.value = true; }, { immediate: true });

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
            <span class="text-lg font-semibold tracking-tight text-slate-900">Rezervácie KVŠ</span>
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

          <!-- Informational pages, tucked into a collapsible subsection so
               the everyday nav stays short. Auto-expands on its pages. -->
          <div class="pt-1">
            <button
              type="button"
              class="flex w-full items-center gap-3 rounded-lg px-3 py-2 text-sm font-medium text-slate-700 hover:bg-slate-100"
              :class="infoActive ? 'text-brand-800' : ''"
              :aria-expanded="infoOpen"
              @click="infoOpen = !infoOpen"
            >
              <span aria-hidden="true">ℹ️</span>
              <span>Informácie</span>
              <span
                aria-hidden="true"
                class="ml-auto text-xs text-slate-400 transition-transform"
                :class="infoOpen ? 'rotate-90' : ''"
              >▶</span>
            </button>
            <div v-show="infoOpen" class="mt-1 space-y-1 border-l border-slate-200 pl-3">
              <template v-for="item in infoItems" :key="item.to">
                <a
                  v-if="item.external"
                  :href="item.to"
                  target="_blank"
                  rel="noopener noreferrer"
                  class="flex items-center gap-3 rounded-lg px-3 py-2 text-sm text-slate-600 hover:bg-slate-100"
                  @click="navOpen = false"
                >
                  <span aria-hidden="true">{{ item.icon }}</span>
                  <span>{{ item.label }}</span>
                  <span aria-hidden="true" class="ml-auto text-xs text-slate-400">↗</span>
                </a>
                <RouterLink
                  v-else
                  :to="item.to"
                  class="flex items-center gap-3 rounded-lg px-3 py-2 text-sm text-slate-600 hover:bg-slate-100"
                  :class="isActive(item.to) ? 'bg-brand-50 text-brand-800 ring-1 ring-brand-100' : ''"
                  @click="navOpen = false"
                >
                  <span aria-hidden="true">{{ item.icon }}</span>
                  <span>{{ item.label }}</span>
                </RouterLink>
              </template>
            </div>
          </div>
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
