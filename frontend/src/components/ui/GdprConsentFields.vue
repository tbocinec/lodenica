<script setup lang="ts">
/**
 * Shared "GDPR a prevádzkový poriadok" block used on every registration
 * surface (classic form, social-login consent gate, invite set-password).
 *
 * Three subsections:
 *  1. Oznámenie o spracúvaní osobných údajov — informational, acknowledged
 *     implicitly by submitting (no checkbox).
 *  2. GDPR súhlas dotknutej osoby — explicit yes/no choice (photo/marketing).
 *  3. Prevádzkový poriadok — mandatory checkbox.
 *
 * Two v-models: `dataConsent` (boolean | null — the photo/marketing choice)
 * and `rulesAck` (boolean — the operating-rules acknowledgement). The parent
 * decides validity (rulesAck === true && dataConsent !== null).
 */
import { useSiteStore } from '@/stores/site.store';

import ExtLink from './ExtLink.vue';

const dataConsent = defineModel<boolean | null>('dataConsent', { default: null });
const rulesAck = defineModel<boolean>('rulesAck', { default: false });

defineProps<{ showErrors?: boolean }>();

// The operator sentence and the four documents come from the site config;
// a missing document renders as plain text (ExtLink).
const site = useSiteStore();
</script>

<template>
  <section class="rounded-xl border border-slate-200 bg-slate-50/60 p-3">
    <h2 class="text-sm font-semibold text-slate-800">GDPR a prevádzkový poriadok</h2>

    <!-- 1) Informačná povinnosť -->
    <div class="mt-3">
      <h3 class="text-xs font-semibold text-slate-700">
        Oznámenie o spracúvaní osobných údajov – Informačná povinnosť
      </h3>
      <p class="mt-1 text-xs leading-relaxed text-slate-600">
        <template v-if="site.config.operatorNotice">{{ site.config.operatorNotice }} </template>
        Odoslaním prihlášky potvrdzujem, že som sa oboznámil/a s kompletným
        <ExtLink :href="site.config.gdprNoticeUrl">Oznámením o spracúvaní osobných údajov</ExtLink>
        na účely spojené s členstvom v klube.
      </p>
    </div>

    <!-- 2) GDPR súhlas dotknutej osoby -->
    <div class="mt-4 border-t border-slate-200 pt-3">
      <h3 class="text-xs font-semibold text-slate-700">GDPR súhlas dotknutej osoby</h3>
      <p class="mt-1 text-xs leading-relaxed text-slate-600">
        Súhlasím so zverejňovaním fotografií a videí mojej osoby z klubových akcií na
        účely propagácie klubu (web, sociálne siete, materiály klubu) podľa podmienok
        <ExtLink :href="site.config.gdprConsentUrl">GDPR súhlasu dotknutej osoby</ExtLink>.
        Súhlas je odvolateľný.
      </p>
      <div class="mt-2 grid grid-cols-2 gap-2">
        <button
          type="button"
          class="rounded-lg border px-3 py-2 text-xs font-medium transition"
          :class="dataConsent === true
            ? 'border-emerald-500 bg-emerald-50 text-emerald-800 ring-1 ring-emerald-300'
            : 'border-slate-200 bg-white text-slate-600 hover:bg-slate-50'"
          @click="dataConsent = true"
        >
          Udeľujem súhlas
        </button>
        <button
          type="button"
          class="rounded-lg border px-3 py-2 text-xs font-medium transition"
          :class="dataConsent === false
            ? 'border-slate-500 bg-slate-100 text-slate-800 ring-1 ring-slate-300'
            : 'border-slate-200 bg-white text-slate-600 hover:bg-slate-50'"
          @click="dataConsent = false"
        >
          Neudeľujem súhlas
        </button>
      </div>
      <p v-if="showErrors && dataConsent === null" class="mt-1 text-xs text-rose-600">
        Vyberte jednu z možností.
      </p>
    </div>

    <!-- 3) Prevádzkový poriadok -->
    <div class="mt-4 border-t border-slate-200 pt-3">
      <h3 class="text-xs font-semibold text-slate-700">Prevádzkový poriadok</h3>
      <label class="mt-2 flex items-start gap-2 text-xs leading-relaxed text-slate-700">
        <input v-model="rulesAck" type="checkbox" class="mt-0.5 h-4 w-4 shrink-0 rounded" />
        <span>
          Vyhlasujem, že som sa oboznámil so
          <ExtLink :href="site.config.statutesUrl">stanovami klubu</ExtLink>
          a
          <ExtLink :href="site.config.rulesUrl">prevádzkovým poriadkom areálu lodenice</ExtLink>
          a zaväzujem sa ich dodržiavať.
          <span class="text-rose-600">*</span>
        </span>
      </label>
      <p v-if="showErrors && !rulesAck" class="mt-1 text-xs text-rose-600">
        Pre pokračovanie musíte potvrdiť oboznámenie so stanovami a prevádzkovým poriadkom.
      </p>
    </div>
  </section>
</template>
