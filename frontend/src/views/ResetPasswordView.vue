<script setup lang="ts">
import { computed, onMounted, ref } from 'vue';
import { useRoute, useRouter } from 'vue-router';

import { authApi } from '@/api/auth.api';
import { profileApi } from '@/api/profile.api';
import type { OAuthProviderInfo } from '@/api/types';
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

// GDPR consents — only collected for invited members setting their first
// password (mirrors registration). Checkbox 1 is mandatory, checkbox 2 is
// optional and default-checked.
const privacyAck = ref(true);
const dataConsent = ref(true);

// After a successful invite set-up we keep the user on the screen to offer
// linking a social account (they're already logged in at this point).
const linked = ref(false);
const providers = ref<OAuthProviderInfo[]>([]);

const heading = computed(() =>
  linked.value ? 'Účet je pripravený' : isInvite ? 'Nastavenie hesla' : 'Obnova hesla',
);
const cta = computed(() =>
  isInvite ? 'Nastaviť heslo a prihlásiť sa' : 'Zmeniť heslo a prihlásiť sa',
);
const tokenMissing = computed(() => !email || !token);

onMounted(async () => {
  if (!isInvite) return;
  try {
    providers.value = await authApi.providers();
  } catch {
    // ignore — just don't offer social linking
  }
});

async function submit(): Promise<void> {
  error.value = null;
  if (password.value !== passwordConfirm.value) {
    error.value = 'Heslá sa nezhodujú.';
    return;
  }
  if (isInvite && !privacyAck.value) {
    error.value =
      'Pre dokončenie musíte potvrdiť oboznámenie s podmienkami spracúvania osobných údajov.';
    return;
  }
  submitting.value = true;
  try {
    await auth.resetPassword(
      email,
      token,
      password.value,
      isInvite ? { privacyAck: privacyAck.value, dataConsent: dataConsent.value } : undefined,
    );
    if (isInvite && providers.value.length) {
      // Logged in now — offer optional social linking before leaving.
      linked.value = true;
    } else {
      await router.replace('/');
    }
  } catch (e) {
    error.value = (e as Error).message;
  } finally {
    submitting.value = false;
  }
}

async function startLink(provider: string): Promise<void> {
  error.value = null;
  try {
    const url = await profileApi.linkUrl(provider);
    window.location.assign(url);
  } catch (e) {
    error.value = (e as Error).message;
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

      <!-- Step 2 (invite only): heslo nastavené, ponuka prepojiť sociálny účet. -->
      <div v-else-if="linked" class="grid gap-3">
        <div class="rounded-lg bg-emerald-50 px-3 py-3 text-sm text-emerald-800 ring-1 ring-emerald-200">
          Heslo je nastavené a ste prihlásený/á. Chcete si prihlásenie zjednodušiť
          prepojením so sociálnym účtom? (nepovinné)
        </div>
        <a
          v-for="p in providers"
          :key="p.provider"
          href="#"
          class="btn-secondary text-center"
          @click.prevent="startLink(p.provider)"
        >
          Prepojiť s {{ p.label }}
        </a>
        <LoadError :message="error" />
        <button type="button" class="btn-primary mt-1" @click="router.replace('/')">
          Pokračovať bez prepojenia
        </button>
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

        <!-- GDPR consents — only for invited members (first-time setup),
             mirroring the registration screen. -->
        <template v-if="isInvite">
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
        </template>

        <LoadError :message="error" />

        <!-- A failed token (expired / already used) — point to forgot-password
             so the user can request a fresh link instead of being stuck. -->
        <p v-if="error" class="text-xs text-slate-500">
          Odkaz už neplatí?
          <RouterLink to="/forgot-password" class="font-medium text-brand-700 hover:underline">
            Požiadať o nový
          </RouterLink>.
        </p>

        <button
          type="submit"
          class="btn-primary mt-1"
          :disabled="submitting || (isInvite && !privacyAck)"
        >
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
