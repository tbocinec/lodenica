<script setup lang="ts">
import { ref } from 'vue';
import { useRoute, useRouter } from 'vue-router';

import LoadError from '@/components/ui/LoadError.vue';
import Spinner from '@/components/ui/Spinner.vue';
import { useAuthStore } from '@/stores/auth.store';

const auth = useAuthStore();
const router = useRouter();
const route = useRoute();

const email = ref('');
const password = ref('');
const submitting = ref(false);
const error = ref<string | null>(null);

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
        <h1 class="text-2xl font-semibold text-slate-900">Lodenica KVS</h1>
        <p class="mt-1 text-sm text-slate-500">Prihlásenie do admin zóny</p>
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

      <p class="mt-6 text-center text-xs text-slate-400">
        <RouterLink to="/" class="hover:underline">← Späť na verejnú stránku</RouterLink>
      </p>
    </div>
  </div>
</template>
