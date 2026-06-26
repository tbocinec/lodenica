<script setup lang="ts">
/**
 * GDPR consent gate for first-time social (Google/Facebook) sign-ups. The
 * OAuth callback redirected here with a short-lived signed `profile` token
 * instead of creating the account. The user accepts the consents and the
 * account is created + logged in via POST /auth/oauth/complete.
 */
import { computed, ref } from 'vue';
import { useRoute, useRouter } from 'vue-router';

import { authApi } from '@/api/auth.api';
import LoadError from '@/components/ui/LoadError.vue';
import Spinner from '@/components/ui/Spinner.vue';
import { useAuthStore } from '@/stores/auth.store';

const route = useRoute();
const router = useRouter();
const auth = useAuthStore();

const profile = (route.query.profile as string | undefined) ?? '';
const missing = computed(() => profile === '');

const privacyAck = ref(true);
const dataConsent = ref(true);
const submitting = ref(false);
const error = ref<string | null>(null);

async function submit(): Promise<void> {
  error.value = null;
  if (!privacyAck.value) {
    error.value =
      'Pre dokončenie registrácie musíte potvrdiť oboznámenie s podmienkami spracúvania osobných údajov.';
    return;
  }
  submitting.value = true;
  try {
    const res = await authApi.oauthComplete(profile, {
      privacyAck: privacyAck.value,
      dataConsent: dataConsent.value,
    });
    auth.setSession(res.token, res.user);
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
        <h1 class="text-2xl font-semibold text-slate-900">Rezervácie KVŠ</h1>
        <p class="mt-1 text-sm text-slate-500">Dokončenie registrácie</p>
      </div>

      <div
        v-if="missing"
        class="rounded-lg bg-rose-50 px-3 py-3 text-sm text-rose-800 ring-1 ring-rose-200"
      >
        Odkaz na dokončenie registrácie je neúplný. Skúste sa
        <RouterLink to="/login" class="font-medium underline">prihlásiť znova</RouterLink>.
      </div>

      <form v-else class="grid gap-3" @submit.prevent="submit">
        <p class="text-sm text-slate-600">
          Pred dokončením registrácie potvrďte spracúvanie osobných údajov:
        </p>

        <label class="flex items-start gap-2 text-xs text-slate-700">
          <input v-model="privacyAck" type="checkbox" class="mt-0.5 h-4 w-4 rounded" required />
          <span>
            Vyhlasujem, že som bol/a oboznámený/á s
            <a
              href="https://www.lodenicakvs.sk/?page_id=5024"
              target="_blank"
              rel="noopener noreferrer"
              class="font-medium text-brand-700 hover:underline"
            >podmienkami spracúvania osobných údajov</a>.
            <span class="text-rose-600">*</span>
          </span>
        </label>
        <label class="flex items-start gap-2 text-xs text-slate-700">
          <input v-model="dataConsent" type="checkbox" class="mt-0.5 h-4 w-4 rounded" />
          <span>
            Udeľujem
            <a
              href="https://www.lodenicakvs.sk/?page_id=5036"
              target="_blank"
              rel="noopener noreferrer"
              class="font-medium text-brand-700 hover:underline"
            >súhlas na spracovanie osobných údajov</a>.
          </span>
        </label>

        <LoadError :message="error" />

        <div class="rounded-lg bg-amber-50 px-3 py-2 text-xs text-amber-800 ring-1 ring-amber-200">
          Po dokončení bude váš účet čakať na schválenie správcom.
        </div>

        <button type="submit" class="btn-primary mt-1" :disabled="submitting || !privacyAck">
          <Spinner v-if="submitting" class="mr-2" />
          {{ submitting ? 'Dokončujem…' : 'Dokončiť registráciu' }}
        </button>
      </form>

      <p class="mt-6 text-center text-xs text-slate-400">
        <RouterLink to="/login" class="hover:underline">← Späť na prihlásenie</RouterLink>
      </p>
    </div>
  </div>
</template>
