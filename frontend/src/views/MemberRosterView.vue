<script setup lang="ts">
/**
 * Admin-only member roster ("číselník"): a curated list of emails + internal
 * member IDs that drives self-registration auto-approval.
 */
import { computed, onMounted, reactive, ref, watch } from 'vue';

import { memberRosterApi, type RosterImportResult } from '@/api/memberRoster.api';
import type { MemberRosterEntry } from '@/api/types';
import EmptyState from '@/components/ui/EmptyState.vue';
import LoadError from '@/components/ui/LoadError.vue';
import PageHeader from '@/components/ui/PageHeader.vue';
import Spinner from '@/components/ui/Spinner.vue';
import { formatDate } from '@/utils/format';

const items = ref<MemberRosterEntry[]>([]);
const total = ref(0);
const loading = ref(false);
const error = ref<string | null>(null);

const search = ref('');
const registeredFilter = ref<'all' | 'yes' | 'no'>('all');
const page = ref(1);
const PAGE_SIZE = 20;
const pageCount = computed(() => Math.max(1, Math.ceil(total.value / PAGE_SIZE)));

const showAdd = ref(false);
const addForm = reactive({ email: '', memberId: '', name: '' });
const adding = ref(false);

const showImport = ref(false);
const csvText = ref('');
const importing = ref(false);
const importResult = ref<RosterImportResult | null>(null);

async function load(): Promise<void> {
  loading.value = true;
  error.value = null;
  try {
    const data = await memberRosterApi.list({
      page: page.value,
      pageSize: PAGE_SIZE,
      search: search.value.trim() || undefined,
      registered: registeredFilter.value === 'all' ? undefined : registeredFilter.value === 'yes',
    });
    items.value = data.items;
    total.value = data.total;
  } catch (e) {
    error.value = (e as Error).message;
  } finally {
    loading.value = false;
  }
}

watch([search, registeredFilter], () => {
  page.value = 1;
  load();
});
watch(page, load);

async function add(): Promise<void> {
  error.value = null;
  adding.value = true;
  try {
    await memberRosterApi.create({
      email: addForm.email.trim(),
      memberId: addForm.memberId.trim() || null,
      name: addForm.name.trim() || null,
    });
    Object.assign(addForm, { email: '', memberId: '', name: '' });
    showAdd.value = false;
    await load();
  } catch (e) {
    error.value = (e as Error).message;
  } finally {
    adding.value = false;
  }
}

function onCsvFile(event: Event): void {
  const file = (event.target as HTMLInputElement).files?.[0];
  if (!file) return;
  const reader = new FileReader();
  reader.onload = () => {
    csvText.value = String(reader.result ?? '');
  };
  reader.readAsText(file);
}

async function runImport(): Promise<void> {
  if (!csvText.value.trim()) return;
  importing.value = true;
  error.value = null;
  importResult.value = null;
  try {
    importResult.value = await memberRosterApi.import(csvText.value);
    csvText.value = '';
    await load();
  } catch (e) {
    error.value = (e as Error).message;
  } finally {
    importing.value = false;
  }
}

async function editMemberId(entry: MemberRosterEntry): Promise<void> {
  const value = window.prompt(`Členské ID pre ${entry.email}:`, entry.memberId ?? '');
  if (value === null) return;
  try {
    await memberRosterApi.update(entry.id, { memberId: value.trim() || null });
    await load();
  } catch (e) {
    error.value = (e as Error).message;
  }
}

async function editName(entry: MemberRosterEntry): Promise<void> {
  const value = window.prompt(`Meno pre ${entry.email}:`, entry.name ?? '');
  if (value === null) return;
  try {
    await memberRosterApi.update(entry.id, { name: value.trim() || null });
    await load();
  } catch (e) {
    error.value = (e as Error).message;
  }
}

async function remove(entry: MemberRosterEntry): Promise<void> {
  if (!window.confirm(`Odstrániť ${entry.email} z číselníka?`)) return;
  try {
    await memberRosterApi.remove(entry.id);
    await load();
  } catch (e) {
    error.value = (e as Error).message;
  }
}

onMounted(load);
</script>

<template>
  <PageHeader title="Číselník členov" subtitle="Zoznam e-mailov a členských ID pre automatické schválenie pri registrácii.">
    <template #actions>
      <RouterLink to="/admin/users" class="btn-secondary">← Používatelia</RouterLink>
      <button type="button" class="btn-secondary" @click="showImport = !showImport">
        {{ showImport ? 'Skryť import' : '⬆ Import CSV' }}
      </button>
      <button type="button" class="btn-primary" @click="showAdd = !showAdd">
        {{ showAdd ? 'Skryť formulár' : '+ Pridať e-mail' }}
      </button>
    </template>
  </PageHeader>

  <!-- How it works -->
  <div class="mb-4 rounded-2xl bg-sky-50/70 p-4 text-sm text-sky-900 ring-1 ring-sky-200">
    <p class="font-medium">Ako číselník funguje</p>
    <p class="mt-1 text-sky-800">
      Keď sa niekto zaregistruje (e-mailom alebo cez Google/Facebook), systém
      sa pozrie do tohto číselníka. Ak sa jeho e-mail v zozname nachádza, účet
      sa <strong>automaticky schváli ako člen</strong> a priradí sa mu členské
      ID z číselníka (meno z číselníka sa ignoruje — použije sa meno, ktoré
      zadal). Po registrácii sa pri zázname zaznačí, kto a kedy sa zaregistroval.
      Ak e-mail v zozname nie je, účet ostáva čakajúci na manuálne schválenie.
    </p>
  </div>

  <!-- Add a single entry -->
  <form
    v-if="showAdd"
    class="mb-6 grid gap-3 rounded-2xl bg-white p-4 ring-1 ring-slate-200 sm:grid-cols-[1fr_1fr_1fr_auto]"
    @submit.prevent="add"
  >
    <div>
      <label class="label" for="r-email">E-mail *</label>
      <input id="r-email" v-model="addForm.email" type="email" class="input mt-1" required />
    </div>
    <div>
      <label class="label" for="r-id">Členské ID</label>
      <input id="r-id" v-model="addForm.memberId" class="input mt-1" maxlength="100" placeholder="napr. KVS-001" />
    </div>
    <div>
      <label class="label" for="r-name">Meno</label>
      <input id="r-name" v-model="addForm.name" class="input mt-1" maxlength="200" />
    </div>
    <div class="flex items-end">
      <button type="submit" class="btn-primary" :disabled="adding || !addForm.email.trim()">
        <Spinner v-if="adding" class="mr-2" />
        {{ adding ? 'Pridávam…' : 'Pridať' }}
      </button>
    </div>
  </form>

  <!-- CSV import -->
  <section v-if="showImport" class="mb-6 grid gap-3 rounded-2xl bg-white p-4 ring-1 ring-slate-200">
    <div>
      <h2 class="text-sm font-semibold text-slate-800">Import číselníka z CSV</h2>
      <p class="mt-1 text-xs text-slate-500">
        Stĺpce <code>id,meno,email</code> (ID aj meno sú nepovinné). Duplicitné
        e-maily sa preskočia; už použité ID sa nahlásia ako chybné.
      </p>
    </div>
    <input type="file" accept=".csv,text/csv,text/plain" class="text-sm" @change="onCsvFile" />
    <textarea
      v-model="csvText"
      class="input font-mono text-xs"
      rows="6"
      placeholder="KVS-001,Ján Novák,jan@example.com&#10;KVS-002,Eva Malá,eva@example.com"
    ></textarea>
    <div class="flex items-center justify-end gap-2">
      <button type="button" class="btn-secondary" @click="showImport = false">Zavrieť</button>
      <button type="button" class="btn-primary" :disabled="importing || !csvText.trim()" @click="runImport">
        <Spinner v-if="importing" class="mr-2" />
        {{ importing ? 'Importujem…' : 'Importovať' }}
      </button>
    </div>
    <div v-if="importResult" class="rounded-lg bg-slate-50 px-3 py-3 text-sm ring-1 ring-slate-200">
      <p class="font-medium text-slate-800">
        Pridaných {{ importResult.createdCount }} ·
        preskočených {{ importResult.skippedCount }} ·
        chybných {{ importResult.invalidCount }}
      </p>
      <p v-if="importResult.skipped.length" class="mt-1 text-xs text-slate-500">
        Preskočené (už existujú): {{ importResult.skipped.join(', ') }}
      </p>
      <p v-if="importResult.invalid.length" class="mt-1 text-xs text-rose-600">
        Chybné: {{ importResult.invalid.join(', ') }}
      </p>
    </div>
  </section>

  <LoadError class="mb-4" :message="error" />

  <!-- Filter bar -->
  <div class="mb-3 flex flex-wrap items-center gap-2">
    <div class="relative grow sm:grow-0">
      <span aria-hidden="true" class="pointer-events-none absolute left-2.5 top-1/2 -translate-y-1/2 text-slate-400">🔍</span>
      <input
        v-model="search"
        type="search"
        class="input pl-8 text-sm sm:w-72"
        placeholder="Hľadať e-mail, meno alebo ID…"
      />
    </div>
    <select v-model="registeredFilter" class="input py-1.5 text-sm sm:w-52">
      <option value="all">Všetky</option>
      <option value="yes">Už zaregistrovaní</option>
      <option value="no">Zatiaľ nezaregistrovaní</option>
    </select>
    <span class="text-xs text-slate-500">{{ total }} záznamov</span>
  </div>

  <div v-if="loading" class="flex justify-center py-12"><Spinner /></div>
  <EmptyState
    v-else-if="items.length === 0"
    title="Číselník je prázdny"
    description="Pridaj e-mail ručne alebo importuj CSV."
  />

  <div v-else class="overflow-hidden rounded-2xl bg-white ring-1 ring-slate-200">
    <table class="min-w-full divide-y divide-slate-200 text-sm">
      <thead class="bg-slate-50 text-left text-xs font-semibold uppercase tracking-wide text-slate-600">
        <tr>
          <th class="px-4 py-2.5">E-mail</th>
          <th class="px-4 py-2.5">Členské ID</th>
          <th class="px-4 py-2.5">Meno</th>
          <th class="px-4 py-2.5">Registrácia</th>
          <th class="px-4 py-2.5 text-right">Akcie</th>
        </tr>
      </thead>
      <tbody class="divide-y divide-slate-100">
        <tr v-for="e in items" :key="e.id">
          <td class="px-4 py-2 font-medium text-slate-900">{{ e.email }}</td>
          <td class="px-4 py-2">
            <button
              type="button"
              class="group inline-flex items-center gap-1 rounded px-1.5 py-0.5 font-mono text-xs hover:bg-slate-100"
              :class="e.memberId ? 'text-slate-800' : 'text-slate-400'"
              @click="editMemberId(e)"
            >
              {{ e.memberId ?? '—' }}
              <span aria-hidden="true" class="opacity-0 transition group-hover:opacity-100">✏️</span>
            </button>
          </td>
          <td class="px-4 py-2">
            <button
              type="button"
              class="group inline-flex items-center gap-1 rounded px-1.5 py-0.5 hover:bg-slate-100"
              :class="e.name ? 'text-slate-700' : 'text-slate-400'"
              @click="editName(e)"
            >
              {{ e.name ?? '—' }}
              <span aria-hidden="true" class="opacity-0 transition group-hover:opacity-100">✏️</span>
            </button>
          </td>
          <td class="px-4 py-2">
            <span
              v-if="e.registeredAt"
              class="rounded-full bg-emerald-100 px-2 py-0.5 text-xs font-medium text-emerald-800 ring-1 ring-emerald-200"
              :title="'Zaregistrovaný ' + formatDate(e.registeredAt)"
            >
              ✓ {{ formatDate(e.registeredAt) }}
            </span>
            <span v-else class="text-xs text-slate-400">zatiaľ nie</span>
          </td>
          <td class="px-4 py-2 text-right">
            <button type="button" class="text-rose-700 hover:underline" @click="remove(e)">
              Odstrániť
            </button>
          </td>
        </tr>
      </tbody>
    </table>

    <div
      v-if="pageCount > 1"
      class="flex items-center justify-between gap-2 border-t border-slate-100 px-4 py-2.5 text-sm"
    >
      <span class="text-slate-500">Strana {{ page }} z {{ pageCount }}</span>
      <div class="flex gap-2">
        <button type="button" class="btn-secondary py-1" :disabled="page <= 1" @click="page--">← Predošlá</button>
        <button type="button" class="btn-secondary py-1" :disabled="page >= pageCount" @click="page++">Ďalšia →</button>
      </div>
    </div>
  </div>
</template>
