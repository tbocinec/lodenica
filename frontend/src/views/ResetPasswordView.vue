<script setup lang="ts">
import { computed, ref } from 'vue';
import { useRoute, useRouter } from 'vue-router';

import LoadError from '@/components/ui/LoadError.vue';
import Spinner from '@/components/ui/Spinner.vue';
import { useAuthStore } from '@/stores/auth.store';

const route = useRoute();
const router = useRouter();
const auth = useAuthStore();

const email = (route.query.email as string | undefined) ?? '';
const token = (route.query.token as string | undefined) ?? '';
const isInvite = route.query.invite === '1';

const password = ref('');
const passwordConfirm = ref('');
const submitting = ref(false);
const error = ref<string | null>(null);

const heading = computed(() => (isInvite ? 'Nastavenie hesla' : 'Obnova hesla'));
const cta = computed(() => (isInvite ? 'Nastaviť heslo a prihlásiť sa' : 'Zmeniť heslo a prihlásiť sa'));
const tokenMissing = computed(() => !email || !token);

async function submit(): Promise<void> {
  error.value = null;
  if (password.value !== passwordConfirm.value) {
    error.value = 'Heslá sa nezhodujú.';
    return;
  }
  submitting.value = true;
  try {
    await auth.resetPassword(email, token, password.value);
    await router.replace('/');
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
        <h1 class="text-2xl font-semibold text-slate-900">{{ heading }}</h1>
        <p class="mt-1 text-sm text-slate-500">{{ email }}</p>
      </div>

      <div
        v-if="tokenMissing"
        class="rounded-lg bg-rose-50 px-3 py-3 text-sm text-rose-800 ring-1 ring-rose-200"
      >
        Odkaz je neúplný alebo neplatný. Požiadajte o nový cez
        <RouterLink to="/forgot-password" class="font-medium underline">zabudnuté heslo</RouterLink>.
      </div>

      <form v-else class="grid gap-3" @submit.prevent="submit">
        <div>
          <label class="label" for="password">Nové heslo</label>
          <input
            id="password"
            v-model="password"
            type="password"
            autocomplete="new-password"
            class="input mt-1"
            minlength="8"
            required
            autofocus
          />
          <p class="mt-1 text-xs text-slate-400">Minimálne 8 znakov.</p>
        </div>
        <div>
          <label class="label" for="passwordConfirm">Heslo znova</label>
          <input
            id="passwordConfirm"
            v-model="passwordConfirm"
            type="password"
            autocomplete="new-password"
            class="input mt-1"
            required
          />
        </div>

        <LoadError :message="error" />

        <button type="submit" class="btn-primary mt-1" :disabled="submitting">
          <Spinner v-if="submitting" class="mr-2" />
          {{ submitting ? 'Ukladám…' : cta }}
        </button>
      </form>

      <p class="mt-6 text-center text-xs text-slate-500">
        <RouterLink to="/login" class="hover:underline">← Späť na prihlásenie</RouterLink>
      </p>
    </div>
  </div>
</template>
