<script setup lang="ts">
/**
 * Admin-only mail diagnostics.
 *
 *   1. Konfigurácia — what the mailer is actually set to (never the password)
 *   2. Testovací e-mail — a real send, with the raw error when it fails
 *   3. Notifikácie — per-notification on/off switches
 *   4. Log — mail-related lines from the tail of the Laravel log
 *
 * A failed test send comes back as HTTP 200 with `ok:false`, so the error
 * text lands in the UI instead of being flattened into a generic "server
 * error". The page must therefore branch on `result.ok`, not on a throw.
 */
import { onMounted, ref } from 'vue';

import {
  mailDiagnosticsApi,
  type MailConfig,
  type MailLogTail,
  type MailNotificationToggle,
  type MailTestResult,
} from '@/api/mail-diagnostics.api';
import LoadError from '@/components/ui/LoadError.vue';
import PageHeader from '@/components/ui/PageHeader.vue';
import Spinner from '@/components/ui/Spinner.vue';
import { useAuthStore } from '@/stores/auth.store';

const auth = useAuthStore();

const error = ref<string | null>(null);

/* ─────────────────────────  1. Konfigurácia  ───────────────────────── */

const config = ref<MailConfig | null>(null);
const configLoading = ref(true);

async function loadConfig(): Promise<void> {
  configLoading.value = true;
  try {
    config.value = await mailDiagnosticsApi.getConfig();
  } catch (e) {
    error.value = e instanceof Error ? e.message : 'Konfiguráciu sa nepodarilo načítať.';
  } finally {
    configLoading.value = false;
  }
}

/* ─────────────────────────  2. Testovací e-mail  ───────────────────── */

const testTo = ref('');
const testBusy = ref(false);
const testResult = ref<MailTestResult | null>(null);

async function sendTest(): Promise<void> {
  testBusy.value = true;
  testResult.value = null;
  error.value = null;
  try {
    testResult.value = await mailDiagnosticsApi.sendTest(testTo.value.trim());
    // A send either way changes what the log has to say.
    await loadLog();
  } catch (e) {
    error.value = e instanceof Error ? e.message : 'Testovací e-mail sa nepodarilo odoslať.';
  } finally {
    testBusy.value = false;
  }
}

/* ─────────────────────────  3. Notifikácie  ─────────────────────────── */

const notifications = ref<MailNotificationToggle[]>([]);
const notificationsLoading = ref(true);
const togglingKey = ref<string | null>(null);

async function loadNotifications(): Promise<void> {
  notificationsLoading.value = true;
  try {
    notifications.value = await mailDiagnosticsApi.getNotifications();
  } catch (e) {
    error.value = e instanceof Error ? e.message : 'Nastavenia notifikácií sa nepodarilo načítať.';
  } finally {
    notificationsLoading.value = false;
  }
}

async function toggle(item: MailNotificationToggle): Promise<void> {
  const next = !item.enabled;

  // Switching off a critical e-mail locks members out of their accounts —
  // make them read what breaks before it happens.
  if (item.critical && !next) {
    const confirmed = window.confirm(
      `Naozaj vypnúť „${item.label}“?\n\n${item.consequence}`,
    );
    if (!confirmed) return;
  }

  togglingKey.value = item.key;
  error.value = null;
  try {
    notifications.value = await mailDiagnosticsApi.setNotifications({ [item.key]: next });
  } catch (e) {
    error.value = e instanceof Error ? e.message : 'Nastavenie sa nepodarilo uložiť.';
  } finally {
    togglingKey.value = null;
  }
}

/* ─────────────────────────  4. Log  ─────────────────────────────────── */

const log = ref<MailLogTail | null>(null);
const logLoading = ref(false);

async function loadLog(): Promise<void> {
  logLoading.value = true;
  try {
    log.value = await mailDiagnosticsApi.getLog(200);
  } catch (e) {
    error.value = e instanceof Error ? e.message : 'Log sa nepodarilo načítať.';
  } finally {
    logLoading.value = false;
  }
}

onMounted(async () => {
  testTo.value = auth.user?.email ?? '';
  await Promise.all([loadConfig(), loadNotifications(), loadLog()]);
});
</script>

<template>
  <PageHeader
    title="Diagnostika e-mailov"
    subtitle="Overenie, či systém dokáže odosielať e-maily, a zapínanie jednotlivých notifikácií."
  />

  <LoadError class="mb-3" :message="error" />

  <!-- 1. KONFIGURÁCIA -->
  <section class="card-padded mb-6">
    <h2 class="mb-2 text-lg font-semibold">Konfigurácia</h2>
    <p class="mb-3 text-sm text-slate-600">
      Hodnoty, ktoré aplikácia reálne používa. Heslo sa zo servera neposiela — vidíš len,
      či je nastavené.
    </p>

    <Spinner v-if="configLoading" />
    <template v-else-if="config">
      <p
        v-if="!config.mailerDefined"
        class="mb-3 rounded-lg border border-rose-200 bg-rose-50 px-4 py-2 text-sm text-rose-800"
      >
        ⚠️ Odosielač <code>{{ config.mailer || '(prázdny)' }}</code> nie je definovaný.
        Odosielanie e-mailov zlyhá okamžite, ešte pred pripojením na server.
      </p>
      <p
        v-else-if="!config.viewCompiledWritable"
        class="mb-3 rounded-lg border border-rose-200 bg-rose-50 px-4 py-2 text-sm text-rose-800"
      >
        ⚠️ Priečinok pre skompilované šablóny nie je použiteľný
        (<code>{{ config.viewCompiledPath ?? 'nenastavený' }}</code>).
        Každý e-mail zlyhá s hláškou „Please provide a valid cache path“ —
        a nič iné v aplikácii sa nepokazí, lebo šablóny používajú len e-maily.
      </p>
      <p
        v-else-if="!config.passwordSet"
        class="mb-3 rounded-lg border border-amber-200 bg-amber-50 px-4 py-2 text-sm text-amber-800"
      >
        ⚠️ SMTP heslo nie je nastavené.
      </p>

      <dl class="grid gap-x-6 gap-y-1 text-sm sm:grid-cols-2">
        <div class="flex justify-between gap-4 border-b border-slate-100 py-1">
          <dt class="text-slate-500">Odosielač</dt>
          <dd class="font-mono">{{ config.mailer || '(prázdny)' }}</dd>
        </div>
        <div class="flex justify-between gap-4 border-b border-slate-100 py-1">
          <dt class="text-slate-500">Transport</dt>
          <dd class="font-mono">{{ config.transport ?? '—' }}</dd>
        </div>
        <div class="flex justify-between gap-4 border-b border-slate-100 py-1">
          <dt class="text-slate-500">Server</dt>
          <dd class="font-mono">{{ config.host ?? '—' }}</dd>
        </div>
        <div class="flex justify-between gap-4 border-b border-slate-100 py-1">
          <dt class="text-slate-500">Port / schéma</dt>
          <dd class="font-mono">{{ config.port ?? '—' }} / {{ config.scheme ?? '—' }}</dd>
        </div>
        <div class="flex justify-between gap-4 border-b border-slate-100 py-1">
          <dt class="text-slate-500">Používateľ</dt>
          <dd class="font-mono break-all">{{ config.username ?? '—' }}</dd>
        </div>
        <div class="flex justify-between gap-4 border-b border-slate-100 py-1">
          <dt class="text-slate-500">Heslo</dt>
          <dd :class="config.passwordSet ? 'text-emerald-700' : 'text-rose-700'">
            {{ config.passwordSet ? 'nastavené' : 'CHÝBA' }}
          </dd>
        </div>
        <div class="flex justify-between gap-4 border-b border-slate-100 py-1">
          <dt class="text-slate-500">Odosielateľ</dt>
          <dd class="font-mono break-all">{{ config.fromAddress ?? '—' }}</dd>
        </div>
        <div class="flex justify-between gap-4 border-b border-slate-100 py-1">
          <dt class="text-slate-500">Adresa správcu</dt>
          <dd class="font-mono break-all">{{ config.adminAddress ?? '—' }}</dd>
        </div>
        <div class="flex justify-between gap-4 border-b border-slate-100 py-1 sm:col-span-2">
          <dt class="text-slate-500">Priečinok šablón</dt>
          <dd
            class="font-mono break-all"
            :class="config.viewCompiledWritable ? '' : 'text-rose-700'"
          >
            {{ config.viewCompiledPath ?? 'nenastavený' }}
            {{ config.viewCompiledWritable ? '' : ' — NEPOUŽITEĽNÝ' }}
          </dd>
        </div>
      </dl>
    </template>
  </section>

  <!-- 2. TESTOVACÍ E-MAIL -->
  <section class="card-padded mb-6">
    <h2 class="mb-2 text-lg font-semibold">Testovací e-mail</h2>
    <p class="mb-3 text-sm text-slate-600">
      Odošle skutočný e-mail cez rovnaké nastavenia ako ostrá prevádzka. Funguje aj vtedy,
      keď sú všetky notifikácie nižšie vypnuté.
    </p>

    <div class="flex flex-wrap items-end gap-2">
      <div class="grow sm:max-w-sm">
        <label class="label" for="mail-test-to">Poslať na adresu</label>
        <input
          id="mail-test-to"
          v-model="testTo"
          type="email"
          class="input mt-1 w-full"
          placeholder="niekto@example.sk"
        />
      </div>
      <button
        type="button"
        class="btn-primary"
        :disabled="testBusy || !testTo.trim()"
        @click="sendTest"
      >
        {{ testBusy ? 'Odosielam…' : '✉️ Odoslať test' }}
      </button>
    </div>

    <div
      v-if="testResult?.ok"
      class="mt-3 rounded-lg border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm text-emerald-800"
    >
      ✅ E-mail odoslaný na <strong>{{ testResult.to }}</strong> za
      {{ testResult.durationMs }} ms. Ak nedorazí, skontroluj priečinok so spamom.
    </div>
    <div
      v-else-if="testResult"
      class="mt-3 rounded-lg border border-rose-200 bg-rose-50 px-4 py-3 text-sm text-rose-800"
    >
      <p class="mb-2">
        ❌ Odoslanie zlyhalo po {{ testResult.durationMs }} ms.
      </p>
      <p class="mb-1 text-xs text-rose-700">{{ testResult.errorClass }}</p>
      <pre class="overflow-x-auto whitespace-pre-wrap break-words rounded bg-white/70 p-2 font-mono text-xs">{{ testResult.errorMessage }}</pre>
    </div>
  </section>

  <!-- 3. NOTIFIKÁCIE -->
  <section class="card-padded mb-6">
    <h2 class="mb-2 text-lg font-semibold">Notifikácie</h2>
    <p class="mb-3 text-sm text-slate-600">
      Zapínanie a vypínanie jednotlivých automatických e-mailov. Vypnutý e-mail sa
      neodosiela vôbec — čo je zároveň spôsob, ako počas výpadku SMTP zabrániť chybám
      v aplikácii.
    </p>

    <Spinner v-if="notificationsLoading" />
    <ul v-else class="divide-y divide-slate-100">
      <li
        v-for="item in notifications"
        :key="item.key"
        class="flex items-start gap-3 py-3"
        :class="item.critical && !item.enabled ? 'bg-rose-50/40' : ''"
      >
        <input
          :id="`notif-${item.key}`"
          type="checkbox"
          class="mt-1 h-4 w-4 rounded"
          :checked="item.enabled"
          :disabled="togglingKey === item.key"
          @change="toggle(item)"
        />
        <div class="min-w-0">
          <label :for="`notif-${item.key}`" class="text-sm font-medium text-slate-800">
            {{ item.label }}
            <span
              v-if="item.critical"
              class="ml-1 rounded bg-amber-100 px-1.5 py-0.5 text-xs font-normal text-amber-800"
            >
              kritický
            </span>
          </label>
          <p class="mt-0.5 text-sm text-slate-600">{{ item.description }}</p>
          <p v-if="!item.enabled" class="mt-1 text-xs text-rose-700">
            Vypnuté — {{ item.consequence }}
          </p>
        </div>
      </li>
    </ul>
  </section>

  <!-- 4. LOG -->
  <section class="card-padded mb-6">
    <div class="mb-2 flex items-center justify-between gap-3">
      <h2 class="text-lg font-semibold">Posledné mailové chyby</h2>
      <button type="button" class="btn-secondary" :disabled="logLoading" @click="loadLog">
        {{ logLoading ? 'Načítavam…' : '↻ Obnoviť' }}
      </button>
    </div>
    <p class="mb-3 text-sm text-slate-600">
      Riadky zo záznamu aplikácie, ktoré sa týkajú e-mailov. Zachytia aj tie chyby, ktoré
      sa navonok neprejavia — napríklad zlyhané oznámenie o schválení člena.
    </p>

    <p v-if="log && !log.available" class="text-sm text-slate-500">
      {{ log.reason }}
    </p>
    <p v-else-if="log && log.entries.length === 0" class="text-sm text-emerald-700">
      Žiadne mailové chyby v zázname. 👍
    </p>
    <pre
      v-else-if="log"
      class="max-h-96 overflow-auto rounded-lg bg-slate-900 p-3 font-mono text-xs leading-relaxed text-slate-100"
    >{{ log.entries.join('\n') }}</pre>
  </section>
</template>
