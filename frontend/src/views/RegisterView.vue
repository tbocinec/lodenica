<script setup lang="ts">
import { ref } from 'vue';
import { useRouter } from 'vue-router';

import LoadError from '@/components/ui/LoadError.vue';
import Spinner from '@/components/ui/Spinner.vue';
import { useAuthStore } from '@/stores/auth.store';

const auth = useAuthStore();
const router = useRouter();

const name = ref('');
const email = ref('');
const password = ref('');
const passwordConfirm = ref('');
const submitting = ref(false);
const error = ref<string | null>(null);

async function submit(): Promise<void> {
  error.value = null;
  if (password.value !== passwordConfirm.value) {
    error.value = 'Heslá sa nezhodujú.';
    return;
  }
  submitting.value = true;
  try {
    await auth.register(name.value.trim(), email.value.trim(), password.value);
    // New account is PENDING — the dashboard shows the "awaiting approval"
    // banner.
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
        <h1 class="text-2xl font-semibold text-slate-900">Lodenica KVŠ</h1>
        <p class="mt-1 text-sm text-slate-500">Vytvorenie účtu</p>
      </div>

      <form class="grid gap-3" @submit.prevent="submit">
        <div>
          <label class="label" for="name">Meno a priezvisko</label>
          <input id="name" v-model="name" type="text" class="input mt-1" required autofocus />
        </div>
        <div>
          <label class="label" for="email">Email</label>
          <input id="email" v-model="email" type="email" autocomplete="username" class="input mt-1" required />
        </div>
        <div>
          <label class="label" for="password">Heslo</label>
          <input
            id="password"
            v-model="password"
            type="password"
            autocomplete="new-password"
            class="input mt-1"
            minlength="8"
            required
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

        <div class="rounded-lg bg-amber-50 px-3 py-2 text-xs text-amber-800 ring-1 ring-amber-200">
          Po registrácii bude váš účet čakať na schválenie správcom. Plný
          prístup člena (mená a kontakty pri rezerváciách) získate po
          potvrdení.
        </div>

        <button type="submit" class="btn-primary mt-1" :disabled="submitting">
          <Spinner v-if="submitting" class="mr-2" />
          {{ submitting ? 'Registrujem…' : 'Zaregistrovať sa' }}
        </button>
      </form>

      <p class="mt-6 text-center text-xs text-slate-500">
        Už máte účet?
        <RouterLink to="/login" class="font-medium text-slate-700 hover:underline">Prihlásiť sa</RouterLink>
      </p>
    </div>
  </div>
</template>
