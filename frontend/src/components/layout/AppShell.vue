<script setup lang="ts">
import { ref } from 'vue';
import { RouterLink, useRoute } from 'vue-router';

import { NAV_LABELS } from '@/i18n/labels';

const route = useRoute();
const navOpen = ref(false);

const navItems = [
  { to: '/', label: NAV_LABELS.dashboard, icon: '📊' },
  { to: '/resources', label: NAV_LABELS.resources, icon: '🛶' },
  { to: '/reservations', label: NAV_LABELS.reservations, icon: '📅' },
  { to: '/timeline', label: NAV_LABELS.timeline, icon: '⏱️' },
  { to: '/calendar', label: NAV_LABELS.calendar, icon: '🗓️' },
  { to: '/spaces', label: NAV_LABELS.spaces, icon: '🏠' },
  { to: '/damages', label: NAV_LABELS.damages, icon: '🛠️' },
];

function isActive(path: string): boolean {
  if (path === '/') return route.path === '/';
  return route.path.startsWith(path);
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
            <span
              class="flex h-8 w-8 items-center justify-center rounded-lg bg-brand-600 text-white"
              >L</span
            >
            <span class="text-lg font-semibold tracking-tight text-slate-900">Lodenica</span>
          </RouterLink>
        </div>
        <div class="hidden items-center gap-2 sm:flex">
          <RouterLink to="/reservations/new" class="btn-primary">
            <span aria-hidden="true">＋</span>
            Vytvoriť rezerváciu
          </RouterLink>
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
            v-for="item in navItems"
            :key="item.to"
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
        </nav>
        <div class="mt-6 sm:hidden">
          <RouterLink to="/reservations/new" class="btn-primary w-full">
            ＋ Vytvoriť rezerváciu
          </RouterLink>
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
