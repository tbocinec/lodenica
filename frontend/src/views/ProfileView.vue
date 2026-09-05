<script setup lang="ts">
import { computed, onMounted, ref } from 'vue';
import { useRoute } from 'vue-router';

import LoadError from '@/components/ui/LoadError.vue';
import Spinner from '@/components/ui/Spinner.vue';
import { authApi } from '@/api/auth.api';
import { profileApi } from '@/api/profile.api';
import type { NotificationPreference, OAuthProviderInfo, UserIdentity } from '@/api/types';
import { useAuthStore } from '@/stores/auth.store';

const auth = useAuthStore();
const route = useRoute();

const ROLE_LABEL: Record<string, string> = {
  ADMIN: 'Administrátor',
  MEMBER: 'Člen',
  PENDING: 'Čaká na schválenie',
};

// Change password
const currentPassword = ref('');
const newPassword = ref('');
const newPasswordConfirm = ref('');
const pwSubmitting = ref(false);
const pwError = ref<string | null>(null);
const pwSuccess = ref(false);

async function changePassword(): Promise<void> {
  pwError.value = null;
  pwSuccess.value = false;
  if (newPassword.value !== newPasswordConfirm.value) {
    pwError.value = 'Nové heslá sa nezhodujú.';
    return;
  }
  pwSubmitting.value = true;
  try {
    await profileApi.changePassword(currentPassword.value, newPassword.value);
    pwSuccess.value = true;
    currentPassword.value = '';
    newPassword.value = '';
    newPasswordConfirm.value = '';
  } catch (e) {
    pwError.value = (e as Error).message;
  } finally {
    pwSubmitting.value = false;
  }
}

// Linked social accounts
const identities = ref<UserIdentity[]>([]);
const providers = ref<OAuthProviderInfo[]>([]);
const loadingLinks = ref(true);
const linkError = ref<string | null>(null);

const linkedProviders = computed(() => new Set(identities.value.map((i) => i.provider)));
const availableToLink = computed(() =>
  providers.value.filter((p) => !linkedProviders.value.has(p.provider)),
);
const showSocialCard = computed(
  () => providers.value.length > 0 || identities.value.length > 0,
);

async function loadLinks(): Promise<void> {
  loadingLinks.value = true;
  linkError.value = null;
  try {
    const [ids, provs] = await Promise.all([profileApi.identities(), authApi.providers()]);
    identities.value = ids;
    providers.value = provs;
  } catch (e) {
    linkError.value = (e as Error).message;
  } finally {
    loadingLinks.value = false;
  }
}

async function startLink(provider: string): Promise<void> {
  try {
    const url = await profileApi.linkUrl(provider);
    window.location.assign(url);
  } catch (e) {
    linkError.value = (e as Error).message;
  }
}

async function unlink(provider: string): Promise<void> {
  try {
    await profileApi.unlinkIdentity(provider);
    identities.value = identities.value.filter((i) => i.provider !== provider);
  } catch (e) {
    linkError.value = (e as Error).message;
  }
}

const linkBanner = computed(() => {
  if (route.query.linked) return `Účet ${route.query.linked} bol prepojený.`;
  if (route.query.oauth_link_error) return 'Prepojenie účtu zlyhalo — možno je už priradený inému používateľovi.';
  return null;
});

// E-mail preferences (REZ-062) — only the user-configurable notifications.
const prefs = ref<NotificationPreference[]>([]);
const prefsLoading = ref(true);
const prefsError = ref<string | null>(null);

async function loadPrefs(): Promise<void> {
  prefsLoading.value = true;
  prefsError.value = null;
  try {
    prefs.value = await profileApi.notifications();
  } catch (e) {
    prefsError.value = (e as Error).message;
  } finally {
    prefsLoading.value = false;
  }
}

async function togglePref(p: NotificationPreference): Promise<void> {
  prefsError.value = null;
  try {
    prefs.value = await profileApi.setNotifications({ [p.key]: !p.enabled });
  } catch (e) {
    prefsError.value = (e as Error).message;
  }
}

onMounted(() => {
  void loadLinks();
  void loadPrefs();
});
</script>

<template>
  <div class="mx-auto max-w-2xl space-y-6 p-4">
    <header>
      <h1 class="text-2xl font-semibold text-slate-900">Môj profil</h1>
    </header>

    <div
      v-if="linkBanner"
      class="rounded-lg px-3 py-2 text-sm ring-1"
      :class="route.query.linked
        ? 'bg-emerald-50 text-emerald-800 ring-emerald-200'
        : 'bg-rose-50 text-rose-800 ring-rose-200'"
    >
      {{ linkBanner }}
    </div>

    <!-- Account -->
    <section class="rounded-xl bg-white p-5 shadow-sm ring-1 ring-slate-200">
      <h2 class="text-sm font-semibold uppercase tracking-wide text-slate-500">Účet</h2>
      <dl class="mt-3 grid gap-2 text-sm">
        <div class="flex justify-between"><dt class="text-slate-500">Meno</dt><dd class="font-medium text-slate-900">{{ auth.user?.name }}</dd></div>
        <div class="flex justify-between"><dt class="text-slate-500">Email</dt><dd class="font-medium text-slate-900">{{ auth.user?.email }}</dd></div>
        <div class="flex justify-between"><dt class="text-slate-500">Rola</dt><dd class="font-medium text-slate-900">{{ ROLE_LABEL[auth.user?.role ?? ''] ?? auth.user?.role }}</dd></div>
      </dl>
    </section>

    <!-- Change password -->
    <section class="rounded-xl bg-white p-5 shadow-sm ring-1 ring-slate-200">
      <h2 class="text-sm font-semibold uppercase tracking-wide text-slate-500">Zmena hesla</h2>
      <form class="mt-3 grid gap-3" @submit.prevent="changePassword">
        <div>
          <label class="label" for="cp">Súčasné heslo</label>
          <input id="cp" v-model="currentPassword" type="password" autocomplete="current-password" class="input mt-1" required />
        </div>
        <div>
          <label class="label" for="np">Nové heslo</label>
          <input id="np" v-model="newPassword" type="password" autocomplete="new-password" class="input mt-1" minlength="8" required />
          <p class="mt-1 text-xs text-slate-400">Minimálne 8 znakov.</p>
        </div>
        <div>
          <label class="label" for="npc">Nové heslo znova</label>
          <input id="npc" v-model="newPasswordConfirm" type="password" autocomplete="new-password" class="input mt-1" required />
        </div>

        <LoadError :message="pwError" />
        <p v-if="pwSuccess" class="text-sm text-emerald-700">Heslo bolo zmenené.</p>

        <div>
          <button type="submit" class="btn-primary" :disabled="pwSubmitting">
            <Spinner v-if="pwSubmitting" class="mr-2" />
            {{ pwSubmitting ? 'Ukladám…' : 'Zmeniť heslo' }}
          </button>
        </div>
      </form>
    </section>

    <!-- E-mail preferences -->
    <section class="rounded-xl bg-white p-5 shadow-sm ring-1 ring-slate-200">
      <h2 class="text-sm font-semibold uppercase tracking-wide text-slate-500">E-mailové notifikácie</h2>
      <p class="mt-1 text-xs text-slate-500">
        Ktoré e-maily ti má systém posielať. Prevádzkové e-maily (obnova hesla, pozvánka) sa vypnúť nedajú.
      </p>
      <div v-if="prefsLoading" class="mt-3"><Spinner /></div>
      <template v-else>
        <LoadError :message="prefsError" />
        <ul class="mt-3 divide-y divide-slate-100">
          <li v-for="p in prefs" :key="p.key" class="flex items-start justify-between gap-3 py-3">
            <div>
              <p class="text-sm font-medium text-slate-800">{{ p.label }}</p>
              <p class="text-xs text-slate-500">{{ p.description }}</p>
            </div>
            <label class="inline-flex shrink-0 items-center gap-2 text-sm">
              <input
                :id="`pref-${p.key}`"
                type="checkbox"
                class="h-4 w-4 rounded"
                :checked="p.enabled"
                @change="togglePref(p)"
              />
              <span class="text-slate-600">{{ p.enabled ? 'Zapnuté' : 'Vypnuté' }}</span>
            </label>
          </li>
        </ul>
      </template>
    </section>

    <!-- Linked social logins -->
    <section v-if="showSocialCard" class="rounded-xl bg-white p-5 shadow-sm ring-1 ring-slate-200">
      <h2 class="text-sm font-semibold uppercase tracking-wide text-slate-500">Prepojené prihlásenia</h2>
      <div v-if="loadingLinks" class="mt-3"><Spinner /></div>
      <template v-else>
        <LoadError :message="linkError" />
        <ul v-if="identities.length" class="mt-3 divide-y divide-slate-100">
          <li v-for="id in identities" :key="id.provider" class="flex items-center justify-between py-2">
            <span class="text-sm text-slate-800">{{ id.providerLabel }}<span v-if="id.email" class="text-slate-400"> · {{ id.email }}</span></span>
            <button class="text-xs text-rose-600 hover:underline" @click="unlink(id.provider)">Odpojiť</button>
          </li>
        </ul>
        <div v-if="availableToLink.length" class="mt-3 flex flex-wrap gap-2">
          <button
            v-for="p in availableToLink"
            :key="p.provider"
            class="btn-secondary text-sm"
            @click="startLink(p.provider)"
          >
            Prepojiť {{ p.label }}
          </button>
        </div>
      </template>
    </section>
  </div>
</template>
