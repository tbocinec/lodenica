<script setup lang="ts">
import { onMounted, ref } from 'vue';

import LoadError from '@/components/ui/LoadError.vue';
import Spinner from '@/components/ui/Spinner.vue';
import { authApi } from '@/api/auth.api';

const email = ref('');
const captchaAnswer = ref('');
const captchaToken = ref('');
const captchaSvg = ref('');
const loadingCaptcha = ref(false);
const submitting = ref(false);
const error = ref<string | null>(null);
const done = ref(false);

async function loadCaptcha(): Promise<void> {
  loadingCaptcha.value = true;
  try {
    const c = await authApi.captcha();
    captchaToken.value = c.token;
    captchaSvg.value = c.svg;
    captchaAnswer.value = '';
  } catch (e) {
    error.value = (e as Error).message;
  } finally {
    loadingCaptcha.value = false;
  }
}

onMounted(loadCaptcha);

async function submit(): Promise<void> {
  error.value = null;
  submitting.value = true;
  try {
    await authApi.forgotPassword(email.value.trim(), captchaAnswer.value.trim(), captchaToken.value);
    done.value = true;
  } catch (e) {
    error.value = (e as Error).message;
    await loadCaptcha(); // captcha is single-use-ish; refresh after a failure
  } finally {
    submitting.value = false;
  }
}
</script>

<template>
  <div class="min-h-screen bg-slate-50 flex items-center justify-center p-4">
    <div class="w-full max-w-sm rounded-2xl bg-white p-6 shadow-sm ring-1 ring-slate-200">
      <div class="mb-6 text-center">
        <h1 class="text-2xl font-semibold text-slate-900">Zabudnuté heslo</h1>
        <p class="mt-1 text-sm text-slate-500">Pošleme vám odkaz na obnovu</p>
      </div>

      <div v-if="done" class="grid gap-4">
        <div class="rounded-lg bg-emerald-50 px-3 py-3 text-sm text-emerald-800 ring-1 ring-emerald-200">
          Ak účet s týmto e-mailom existuje, poslali sme naň odkaz na obnovu
          hesla. Skontrolujte si schránku (aj priečinok spam).
        </div>
        <RouterLink to="/login" class="btn-secondary text-center">Späť na prihlásenie</RouterLink>
      </div>

      <form v-else class="grid gap-3" @submit.prevent="submit">
        <div>
          <label class="label" for="email">Email</label>
          <input id="email" v-model="email" type="email" autocomplete="username" class="input mt-1" required autofocus />
        </div>

        <div>
          <label class="label">Overenie — koľko je?</label>
          <div class="mt-1 flex items-center gap-3">
            <div
              class="shrink-0 rounded-lg ring-1 ring-slate-200 overflow-hidden bg-slate-50"
              v-html="captchaSvg"
            />
            <button
              type="button"
              class="text-xs text-slate-400 hover:text-slate-600"
              :disabled="loadingCaptcha"
              @click="loadCaptcha"
              title="Načítať nový príklad"
            >
              ↻ nový
            </button>
          </div>
          <input
            v-model="captchaAnswer"
            type="text"
            inputmode="numeric"
            class="input mt-2"
            placeholder="Výsledok"
            required
          />
        </div>

        <LoadError :message="error" />

        <button type="submit" class="btn-primary mt-1" :disabled="submitting || loadingCaptcha">
          <Spinner v-if="submitting" class="mr-2" />
          {{ submitting ? 'Odosielam…' : 'Poslať odkaz' }}
        </button>
      </form>

      <p v-if="!done" class="mt-6 text-center text-xs text-slate-500">
        <RouterLink to="/login" class="hover:underline">← Späť na prihlásenie</RouterLink>
      </p>
    </div>
  </div>
</template>
