<script setup lang="ts">
import { onMounted, ref } from 'vue';
import { useRoute, useRouter } from 'vue-router';

import LoadError from '@/components/ui/LoadError.vue';
import Spinner from '@/components/ui/Spinner.vue';
import { authApi } from '@/api/auth.api';
import type { OAuthProviderInfo } from '@/api/types';
import { useAuthStore } from '@/stores/auth.store';

const auth = useAuthStore();
const router = useRouter();
const route = useRoute();

const email = ref('');
const password = ref('');
const submitting = ref(false);
const error = ref<string | null>(
  route.query.oauth_error ? 'Prihlásenie cez sociálnu sieť zlyhalo.' : null,
);

// Social login buttons appear only when a provider is live (OAuth is
// dormant by default — see docs/AUTH-AND-PERMISSIONS.md).
const providers = ref<OAuthProviderInfo[]>([]);
onMounted(async () => {
  try {
    providers.value = await authApi.providers();
  } catch {
    // ignore — just don't show social buttons
  }
});

async function submit(): Promise<void> {
  submitting.value = true;
  error.value = null;
  try {
    await auth.login(email.value.trim(), password.value);
    const redirect = (route.query.redirect as string | undefined) ?? '/';
    await router.replace(redirect);
  } catch (e) {
    error.value = (e as Error).message;
  } finally {
    submitting.value = false;
  }
}
</script>

<template>
  <div class="min-h-screen bg-slate-50 flex items-center justify-center p-4">
    <div class="w-full max-w-sm rounded-2xl bg-white p-6 shadow-sm ring-1 ring-slate-200">
      <div class="mb-6 text-center">
        <h1 class="text-2xl font-semibold text-slate-900">Lodenica KVŠ</h1>
        <p class="mt-1 text-sm text-slate-500">Prihlásenie do internej zóny</p>
      </div>

      <form class="grid gap-3" @submit.prevent="submit">
        <div>
          <label class="label" for="email">Email</label>
          <input
            id="email"
            v-model="email"
            type="email"
            autocomplete="username"
            class="input mt-1"
            required
            autofocus
          />
        </div>
        <div>
          <label class="label" for="password">Heslo</label>
          <input
            id="password"
            v-model="password"
            type="password"
            autocomplete="current-password"
            class="input mt-1"
            required
          />
        </div>

        <LoadError :message="error" />

        <button type="submit" class="btn-primary mt-2" :disabled="submitting">
          <Spinner v-if="submitting" class="mr-2" />
          {{ submitting ? 'Prihlasujem…' : 'Prihlásiť sa' }}
        </button>
      </form>

      <div class="mt-3 flex items-center justify-between text-xs">
        <RouterLink to="/forgot-password" class="text-slate-500 hover:underline">Zabudnuté heslo?</RouterLink>
        <RouterLink to="/register" class="font-medium text-slate-700 hover:underline">Vytvoriť účet</RouterLink>
      </div>

      <div v-if="providers.length" class="mt-5">
        <div class="relative text-center">
          <span class="bg-white px-2 text-xs text-slate-400">alebo</span>
          <div class="absolute inset-x-0 top-1/2 -z-10 border-t border-slate-200" />
        </div>
        <div class="mt-3 grid gap-2">
          <a
            v-for="p in providers"
            :key="p.provider"
            :href="p.url"
            class="btn-secondary text-center"
          >
            Prihlásiť sa cez {{ p.label }}
          </a>
        </div>
      </div>

      <p class="mt-6 text-center text-xs text-slate-400">
        <RouterLink to="/" class="hover:underline">← Späť na verejnú stránku</RouterLink>
      </p>
    </div>
  </div>
</template>
