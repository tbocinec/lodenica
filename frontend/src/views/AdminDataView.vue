<script setup lang="ts">
/**
 * Admin-only data-management page.
 *
 *   1. Export — JSON full DB + CSV exports for resources + reservations
 *   2. Import — destructive JSON restore (typed "VYMAZAŤ A OBNOVIŤ")
 *   3. Vyčistiť rezervácie — destructive bulk delete with typed
 *      confirmation; toggle between "All" and "Older than 1 year".
 *
 * The destructive endpoints already require a typed confirmation
 * string on the server; we wire the SAME string into the UI so a
 * fat-fingered click can't fire by itself.
 */
import { ref } from 'vue';

import { adminDataApi, saveBlobAs } from '@/api/admin-data.api';
import LoadError from '@/components/ui/LoadError.vue';
import PageHeader from '@/components/ui/PageHeader.vue';

const error = ref<string | null>(null);
const info = ref<string | null>(null);
const busy = ref(false);

/* ─────────────────────────  Export buttons  ───────────────────────── */

async function exportDb(): Promise<void> {
  busy.value = true;
  error.value = null;
  info.value = null;
  try {
    const blob = await adminDataApi.downloadDatabaseJson();
    saveBlobAs(blob, `lodenica-backup-${new Date().toISOString().slice(0, 10)}.json`);
    info.value = 'JSON záloha stiahnutá.';
  } catch (e) {
    error.value = (e as Error).message;
  } finally {
    busy.value = false;
  }
}
async function exportReservationsCsv(): Promise<void> {
  busy.value = true;
  error.value = null;
  info.value = null;
  try {
    const blob = await adminDataApi.downloadReservationsCsv();
    saveBlobAs(blob, `lodenica-rezervacie-${new Date().toISOString().slice(0, 10)}.csv`);
    info.value = 'CSV rezervácií stiahnuté.';
  } catch (e) {
    error.value = (e as Error).message;
  } finally {
    busy.value = false;
  }
}
async function exportResourcesCsv(): Promise<void> {
  busy.value = true;
  error.value = null;
  info.value = null;
  try {
    const blob = await adminDataApi.downloadResourcesCsv();
    saveBlobAs(blob, `lodenica-lode-${new Date().toISOString().slice(0, 10)}.csv`);
    info.value = 'CSV lodí stiahnuté.';
  } catch (e) {
    error.value = (e as Error).message;
  } finally {
    busy.value = false;
  }
}
async function exportMembersCsv(): Promise<void> {
  busy.value = true;
  error.value = null;
  info.value = null;
  try {
    const blob = await adminDataApi.downloadMembersCsv();
    saveBlobAs(blob, `lodenica-clenovia-${new Date().toISOString().slice(0, 10)}.csv`);
    info.value = 'CSV členov stiahnuté.';
  } catch (e) {
    error.value = (e as Error).message;
  } finally {
    busy.value = false;
  }
}

/* ─────────────────────────────  Import  ──────────────────────────── */

const importFile = ref<File | null>(null);
const importConfirmation = ref('');

function onImportFileChange(event: Event): void {
  const input = event.target as HTMLInputElement;
  importFile.value = input.files?.[0] ?? null;
}

async function runImport(): Promise<void> {
  if (!importFile.value) {
    error.value = 'Najprv vyber súbor.';
    return;
  }
  if (importConfirmation.value !== 'VYMAZAŤ A OBNOVIŤ') {
    error.value = 'Pre potvrdenie napíš presne: VYMAZAŤ A OBNOVIŤ';
    return;
  }
  busy.value = true;
  error.value = null;
  info.value = null;
  try {
    const text = await importFile.value.text();
    const parsed = JSON.parse(text) as { tables?: Record<string, unknown[]> };
    if (!parsed.tables) {
      throw new Error('Súbor nemá pole "tables". Použi export z tejto aplikácie.');
    }
    const result = await adminDataApi.importDatabase({
      confirmation: importConfirmation.value,
      tables: parsed.tables,
    });
    const total = Object.values(result.inserted).reduce((s, n) => s + n, 0);
    info.value = `Databáza obnovená — vložených ${total} záznamov.`;
    importFile.value = null;
    importConfirmation.value = '';
    // Reset the file input element so the same file can be re-picked.
    const inp = document.getElementById('admin-import-file') as HTMLInputElement | null;
    if (inp) inp.value = '';
  } catch (e) {
    error.value = (e as Error).message;
  } finally {
    busy.value = false;
  }
}

/* ─────────────────────────────  Purge  ───────────────────────────── */

const purgeScope = ref<'all' | 'olderThanYear'>('olderThanYear');
const purgeConfirmation = ref('');

async function runPurge(): Promise<void> {
  if (purgeConfirmation.value !== 'VYMAZAŤ') {
    error.value = 'Pre potvrdenie napíš presne: VYMAZAŤ';
    return;
  }
  const friendly =
    purgeScope.value === 'all'
      ? 'všetkých rezervácií'
      : 'rezervácií starších ako rok';
  if (!window.confirm(`Naozaj vymazať ${friendly}? Túto akciu sa nedá vrátiť.`)) {
    return;
  }
  busy.value = true;
  error.value = null;
  info.value = null;
  try {
    const result = await adminDataApi.purgeReservations({
      confirmation: purgeConfirmation.value,
      olderThanDays: purgeScope.value === 'olderThanYear' ? 365 : undefined,
    });
    info.value = `Vymazaných ${result.deleted} rezervácií.`;
    purgeConfirmation.value = '';
  } catch (e) {
    error.value = (e as Error).message;
  } finally {
    busy.value = false;
  }
}
</script>

<template>
  <PageHeader
    title="Správa dát"
    subtitle="Záloha, obnovenie a hromadné vyčistenie. Iba pre administrátorov."
  />

  <LoadError class="mb-3" :message="error" />

  <p
    v-if="info"
    class="mb-4 rounded-lg border border-emerald-200 bg-emerald-50 px-4 py-2 text-sm text-emerald-800"
  >
    {{ info }}
  </p>

  <!-- 1. EXPORT -->
  <section class="card-padded mb-6">
    <h2 class="mb-2 text-lg font-semibold">Export</h2>
    <p class="mb-3 text-sm text-slate-600">
      Stiahni JSON zálohu celej databázy (môžeš ju neskôr nahrať späť cez Import nižšie)
      alebo CSV výpisy pre Excel.
    </p>
    <div class="flex flex-wrap gap-2">
      <button type="button" class="btn-primary" :disabled="busy" @click="exportDb">
        📦 Záloha JSON
      </button>
      <button type="button" class="btn-secondary" :disabled="busy" @click="exportReservationsCsv">
        📄 Rezervácie CSV
      </button>
      <button type="button" class="btn-secondary" :disabled="busy" @click="exportResourcesCsv">
        🚣 Lode CSV
      </button>
      <button type="button" class="btn-secondary" :disabled="busy" @click="exportMembersCsv">
        👤 Členovia CSV
      </button>
    </div>
    <p class="mt-2 text-xs text-slate-500">
      „Členovia CSV" obsahuje pre každé interné členské ID: meno, e-mail,
      čas registrácie a stav GDPR súhlasov (oboznámenie + súhlas so
      spracovaním). Vhodné na synchronizáciu s členskou databázou.
    </p>
  </section>

  <!-- 2. IMPORT -->
  <section class="card-padded mb-6 border-rose-200 bg-rose-50/30">
    <h2 class="mb-2 text-lg font-semibold text-rose-900">⚠️ Import (destruktívny)</h2>
    <p class="mb-3 text-sm text-rose-800">
      Wipne všetky tabuľky a obnoví z nahraného JSON súboru.
      Použiteľné iba s exportom z tejto aplikácie.
    </p>

    <div class="grid gap-3 sm:grid-cols-2">
      <div>
        <label class="label" for="admin-import-file">Súbor (.json zo zálohy)</label>
        <input
          id="admin-import-file"
          type="file"
          accept="application/json,.json"
          class="mt-1 block w-full text-sm text-slate-700 file:mr-3 file:rounded-lg file:border-0 file:bg-rose-100 file:px-3 file:py-1.5 file:text-sm file:font-medium file:text-rose-800 hover:file:bg-rose-200"
          @change="onImportFileChange"
        />
        <p v-if="importFile" class="mt-1 text-xs text-slate-500">
          Vybrané: {{ importFile.name }} ({{ Math.round(importFile.size / 1024) }} kB)
        </p>
      </div>
      <div>
        <label class="label" for="admin-import-conf">
          Pre potvrdenie napíš: <code class="text-rose-700">VYMAZAŤ A OBNOVIŤ</code>
        </label>
        <input
          id="admin-import-conf"
          v-model="importConfirmation"
          class="input mt-1"
          placeholder="VYMAZAŤ A OBNOVIŤ"
        />
      </div>
    </div>

    <div class="mt-4">
      <button
        type="button"
        class="btn-danger"
        :disabled="busy || !importFile || importConfirmation !== 'VYMAZAŤ A OBNOVIŤ'"
        @click="runImport"
      >
        🗑 Wipnúť a obnoviť databázu
      </button>
    </div>
  </section>

  <!-- 3. PURGE -->
  <section class="card-padded mb-6 border-amber-200 bg-amber-50/30">
    <h2 class="mb-2 text-lg font-semibold text-amber-900">🧹 Vyčistiť rezervácie</h2>
    <p class="mb-3 text-sm text-amber-800">
      Hromadné mazanie rezervácií. CONFIRMED aj CANCELLED — všetky.
    </p>

    <div class="space-y-2">
      <label class="flex items-center gap-2 text-sm">
        <input v-model="purgeScope" type="radio" value="olderThanYear" />
        Iba rezervácie staršie ako rok (endsAt &lt; dnes − 365 dní)
      </label>
      <label class="flex items-center gap-2 text-sm">
        <input v-model="purgeScope" type="radio" value="all" />
        VŠETKY rezervácie (vrátane budúcich)
      </label>
    </div>

    <div class="mt-4">
      <label class="label" for="admin-purge-conf">
        Pre potvrdenie napíš: <code class="text-amber-800">VYMAZAŤ</code>
      </label>
      <input
        id="admin-purge-conf"
        v-model="purgeConfirmation"
        class="input mt-1 max-w-xs"
        placeholder="VYMAZAŤ"
      />
    </div>

    <div class="mt-4">
      <button
        type="button"
        class="btn-danger"
        :disabled="busy || purgeConfirmation !== 'VYMAZAŤ'"
        @click="runPurge"
      >
        🗑 Vymazať vybrané rezervácie
      </button>
    </div>
  </section>
</template>
