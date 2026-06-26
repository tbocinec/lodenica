<script setup lang="ts">
/**
 * Admin-only user management. Lists every user, lets an admin create new
 * members or admins, change roles, reset passwords and deactivate
 * accounts. Self-protection rules (admin can't demote/deactivate/delete
 * themselves) are enforced server-side; this UI keeps the dangerous
 * buttons disabled too for clearer feedback.
 */
import { computed, onMounted, reactive, ref } from 'vue';

import { usersApi } from '@/api/users.api';
import type { BulkImportResult, User, UserRole } from '@/api/types';
import EmptyState from '@/components/ui/EmptyState.vue';
import LoadError from '@/components/ui/LoadError.vue';
import PageHeader from '@/components/ui/PageHeader.vue';
import Spinner from '@/components/ui/Spinner.vue';
import UserDetailDialog from '@/components/ui/UserDetailDialog.vue';
import { useAuthStore } from '@/stores/auth.store';
import { formatDate } from '@/utils/format';

const auth = useAuthStore();
const items = ref<User[]>([]);
const loading = ref(false);
const error = ref<string | null>(null);
const showCreate = ref(false);

// Admin user-detail modal (timestamps, identities, edit name/ID).
const detailUserId = ref<string | null>(null);

const newUser = reactive({
  name: '',
  email: '',
  password: '',
  role: 'MEMBER' as UserRole,
  memberId: '',
});

// Invite a single member (auto-confirmed, emailed a set-password link).
const showInvite = ref(false);
const inviteForm = reactive({ name: '', email: '', memberId: '' });
const inviting = ref(false);
const inviteSuccess = ref<string | null>(null);

async function invite(): Promise<void> {
  error.value = null;
  inviteSuccess.value = null;
  inviting.value = true;
  try {
    const u = await usersApi.invite(
      inviteForm.name.trim(),
      inviteForm.email.trim(),
      inviteForm.memberId.trim(),
    );
    inviteSuccess.value = `Pozvánka odoslaná na ${u.email}.`;
    inviteForm.name = '';
    inviteForm.email = '';
    inviteForm.memberId = '';
    await load();
  } catch (e) {
    error.value = (e as Error).message;
  } finally {
    inviting.value = false;
  }
}

// Bulk CSV import.
const showImport = ref(false);
const csvText = ref('');
const importing = ref(false);
const importResult = ref<BulkImportResult | null>(null);

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
    importResult.value = await usersApi.bulkImport(csvText.value);
    await load();
  } catch (e) {
    error.value = (e as Error).message;
  } finally {
    importing.value = false;
  }
}

const ROLE_LABEL: Record<UserRole, string> = {
  ADMIN: 'Administrátor',
  MEMBER: 'Člen',
  PENDING: 'Čaká na potvrdenie',
};

async function load(): Promise<void> {
  loading.value = true;
  error.value = null;
  try {
    const data = await usersApi.list({ pageSize: 200 });
    items.value = data.items;
  } catch (e) {
    error.value = (e as Error).message;
  } finally {
    loading.value = false;
  }
}

async function create(): Promise<void> {
  error.value = null;
  try {
    await usersApi.create({
      name: newUser.name.trim(),
      email: newUser.email.trim(),
      password: newUser.password,
      role: newUser.role,
      memberId: newUser.memberId.trim() || null,
    });
    showCreate.value = false;
    Object.assign(newUser, { name: '', email: '', password: '', role: 'MEMBER', memberId: '' });
    await load();
  } catch (e) {
    error.value = (e as Error).message;
  }
}

async function setRole(user: User, role: UserRole): Promise<void> {
  if (user.id === auth.user?.id) return;
  try {
    await usersApi.update(user.id, { role });
    await load();
  } catch (e) {
    error.value = (e as Error).message;
  }
}

async function toggleActive(user: User): Promise<void> {
  if (user.id === auth.user?.id) return;
  try {
    await usersApi.update(user.id, { isActive: !user.isActive });
    await load();
  } catch (e) {
    error.value = (e as Error).message;
  }
}

async function resetPassword(user: User): Promise<void> {
  const pw = window.prompt(`Nové heslo pre ${user.email} (min. 8 znakov)`);
  if (!pw || pw.length < 8) return;
  try {
    await usersApi.update(user.id, { password: pw });
    window.alert(`Heslo pre ${user.email} bolo zmenené.`);
  } catch (e) {
    error.value = (e as Error).message;
  }
}

async function remove(user: User): Promise<void> {
  if (user.id === auth.user?.id) return;
  if (!window.confirm(`Naozaj zmazať používateľa „${user.name}“ (${user.email})?`)) return;
  try {
    await usersApi.remove(user.id);
    await load();
  } catch (e) {
    error.value = (e as Error).message;
  }
}

/** Promote a PENDING account to MEMBER. The internal member ID is MANDATORY
 *  at approval time. */
async function confirmMember(user: User): Promise<void> {
  const memberId = window.prompt(
    `Potvrdiť „${user.name}“ ako člena.\nZadaj interné členské ID (povinné):`,
    user.memberId ?? '',
  );
  if (memberId === null) return; // cancelled
  if (memberId.trim() === '') {
    error.value = 'Pri schválení člena musíte priradiť interné členské ID.';
    return;
  }
  try {
    await usersApi.confirm(user.id, memberId.trim());
    await load();
  } catch (e) {
    error.value = (e as Error).message;
  }
}

/** Assign / change a member's internal ID (admin-only). */
async function editMemberId(user: User): Promise<void> {
  const value = window.prompt(`Interné členské ID pre „${user.name}“:`, user.memberId ?? '');
  if (value === null) return;
  try {
    await usersApi.update(user.id, { memberId: value.trim() || null });
    await load();
  } catch (e) {
    error.value = (e as Error).message;
  }
}

async function rejectPending(user: User): Promise<void> {
  if (!window.confirm(`Zamietnuť a zmazať žiadosť „${user.name}“ (${user.email})?`)) return;
  try {
    await usersApi.remove(user.id);
    await load();
  } catch (e) {
    error.value = (e as Error).message;
  }
}

function isSelf(user: User): boolean {
  return user.id === auth.user?.id;
}

// ── Pending queue + filters ───────────────────────────────────────────────
const pendingUsers = computed(() => items.value.filter((u) => u.role === 'PENDING'));

const searchQuery = ref('');
const roleFilter = ref<UserRole | ''>('');
const activeFilter = ref<'all' | 'active' | 'inactive'>('all');

const filteredUsers = computed(() => {
  const q = searchQuery.value.trim().toLowerCase();
  return items.value.filter((u) => {
    if (roleFilter.value && u.role !== roleFilter.value) return false;
    if (activeFilter.value === 'active' && !u.isActive) return false;
    if (activeFilter.value === 'inactive' && u.isActive) return false;
    if (q && !`${u.name} ${u.email}`.toLowerCase().includes(q)) return false;
    return true;
  });
});

function clearFilters(): void {
  searchQuery.value = '';
  roleFilter.value = '';
  activeFilter.value = 'all';
}

onMounted(load);
</script>

<template>
  <PageHeader
    title="Používatelia"
    subtitle="Manažment členov a administrátorov klubu."
  >
    <template #actions>
      <RouterLink to="/member-roster" class="btn-secondary">📇 Číselník členov</RouterLink>
      <button type="button" class="btn-secondary" @click="showInvite = !showInvite">
        {{ showInvite ? 'Skryť pozvánku' : '✉ Pozvať člena' }}
      </button>
      <button type="button" class="btn-secondary" @click="showImport = !showImport">
        {{ showImport ? 'Skryť import' : '⬆ Import CSV' }}
      </button>
      <button type="button" class="btn-primary" @click="showCreate = !showCreate">
        {{ showCreate ? 'Skryť formulár' : '+ Pridať používateľa' }}
      </button>
    </template>
  </PageHeader>

  <!-- Invite a single member: name + email, no password. Auto-confirmed as
       MEMBER and emailed a set-your-password link (valid 30 days). -->
  <form
    v-if="showInvite"
    class="mb-6 grid gap-3 rounded-2xl bg-white p-4 ring-1 ring-slate-200 sm:grid-cols-[1fr_1fr_1fr_auto]"
    @submit.prevent="invite"
  >
    <div>
      <label class="label" for="inv-name">Meno *</label>
      <input id="inv-name" v-model="inviteForm.name" class="input mt-1" required maxlength="200" />
    </div>
    <div>
      <label class="label" for="inv-email">Email *</label>
      <input id="inv-email" v-model="inviteForm.email" type="email" class="input mt-1" required />
    </div>
    <div>
      <label class="label" for="inv-mid">Členské ID</label>
      <input id="inv-mid" v-model="inviteForm.memberId" class="input mt-1" maxlength="100" placeholder="napr. KVS-001" />
    </div>
    <div class="flex items-end">
      <button type="submit" class="btn-primary" :disabled="inviting || !inviteForm.name.trim() || !inviteForm.email.trim()">
        <Spinner v-if="inviting" class="mr-2" />
        {{ inviting ? 'Pozývam…' : 'Pozvať' }}
      </button>
    </div>
    <p class="sm:col-span-4 text-xs text-slate-500">
      Člen dostane e-mail s odkazom na nastavenie hesla (platný 30 dní) a je
      rovno potvrdený — nemusíte ho potvrdzovať zvlášť. Účet sa
      <strong>aktivuje až po nastavení hesla</strong>. Členské ID je interné a
      vidia ho iba administrátori.
    </p>
    <p v-if="inviteSuccess" class="sm:col-span-4 text-sm text-emerald-700">{{ inviteSuccess }}</p>
  </form>

  <!-- Bulk CSV import: name,email rows. Each new account is PENDING and is
       emailed a set-your-password invitation. -->
  <section
    v-if="showImport"
    class="mb-6 grid gap-3 rounded-2xl bg-white p-4 ring-1 ring-slate-200"
  >
    <div>
      <h2 class="text-sm font-semibold text-slate-800">Hromadný import používateľov</h2>
      <p class="mt-1 text-xs text-slate-500">
        Vlož CSV so stĺpcami <code>id,meno,email</code> (ID je nepovinné — bez
        neho stačí <code>meno,email</code>), alebo nahraj súbor. Každý nový
        kontakt dostane e-mail s odkazom na nastavenie hesla (platný 30 dní) a
        je rovno člen — účet sa <strong>aktivuje až po nastavení hesla</strong>.
        Duplicitné e-maily sa preskočia; už použité členské ID sa nahlási ako
        chybné.
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
    <div
      v-if="importResult"
      class="rounded-lg bg-slate-50 px-3 py-3 text-sm ring-1 ring-slate-200"
    >
      <p class="font-medium text-slate-800">
        Pridaných {{ importResult.createdCount }} ·
        preskočených {{ importResult.skippedCount }} ·
        chybných {{ importResult.invalidCount }}
      </p>
      <p v-if="importResult.skipped.length" class="mt-1 text-xs text-slate-500">
        Preskočené (už existujú): {{ importResult.skipped.join(', ') }}
      </p>
      <p v-if="importResult.invalid.length" class="mt-1 text-xs text-rose-600">
        Chybné riadky: {{ importResult.invalid.join(', ') }}
      </p>
    </div>
  </section>

  <form
    v-if="showCreate"
    class="mb-6 grid gap-3 rounded-2xl bg-white p-4 ring-1 ring-slate-200 sm:grid-cols-4"
    @submit.prevent="create"
  >
    <div>
      <label class="label" for="nu-name">Meno *</label>
      <input id="nu-name" v-model="newUser.name" class="input mt-1" required maxlength="200" />
    </div>
    <div>
      <label class="label" for="nu-email">Email *</label>
      <input id="nu-email" v-model="newUser.email" type="email" class="input mt-1" required />
    </div>
    <div>
      <label class="label" for="nu-pw">Heslo *</label>
      <input
        id="nu-pw"
        v-model="newUser.password"
        type="password"
        class="input mt-1"
        required
        minlength="8"
      />
    </div>
    <div>
      <label class="label" for="nu-role">Rola *</label>
      <select id="nu-role" v-model="newUser.role" class="input mt-1">
        <option value="MEMBER">Člen</option>
        <option value="ADMIN">Administrátor</option>
      </select>
    </div>
    <div>
      <label class="label" for="nu-mid">Členské ID</label>
      <input id="nu-mid" v-model="newUser.memberId" class="input mt-1" maxlength="100" placeholder="napr. KVS-001" />
    </div>
    <div class="sm:col-span-4 flex justify-end gap-2">
      <button type="button" class="btn-secondary" @click="showCreate = false">Zrušiť</button>
      <button type="submit" class="btn-primary">Vytvoriť</button>
    </div>
  </form>

  <LoadError class="mb-4" :message="error" />

  <!-- Pending approvals surfaced separately at the top — the most common
       admin action for new self-registrations. -->
  <section
    v-if="pendingUsers.length"
    class="mb-6 rounded-2xl border border-amber-200 bg-amber-50/60 p-4"
  >
    <h2 class="mb-2 flex items-center gap-2 text-sm font-semibold text-amber-900">
      <span aria-hidden="true">⏳</span>
      Čakajú na schválenie
      <span class="rounded-full bg-amber-200 px-2 py-0.5 text-xs text-amber-900">{{ pendingUsers.length }}</span>
    </h2>
    <ul class="divide-y divide-amber-200/70">
      <li
        v-for="user in pendingUsers"
        :key="user.id"
        class="flex flex-wrap items-center justify-between gap-2 py-2"
      >
        <div class="min-w-0">
          <span class="font-medium text-slate-900">{{ user.name }}</span>
          <span class="text-slate-500"> · {{ user.email }}</span>
          <span class="ml-1 text-xs text-slate-400">{{ formatDate(user.createdAt) }}</span>
        </div>
        <div class="flex gap-2">
          <button
            type="button"
            class="rounded-md bg-emerald-600 px-3 py-1 text-xs font-medium text-white hover:bg-emerald-700"
            @click="confirmMember(user)"
          >
            ✓ Potvrdiť
          </button>
          <button
            type="button"
            class="rounded-md px-3 py-1 text-xs font-medium text-rose-700 ring-1 ring-rose-200 hover:bg-rose-50"
            @click="rejectPending(user)"
          >
            ✕ Zamietnuť
          </button>
        </div>
      </li>
    </ul>
  </section>

  <div v-if="loading" class="flex justify-center py-12">
    <Spinner />
  </div>

  <EmptyState
    v-else-if="items.length === 0"
    title="Žiadni používatelia"
    description="Začni pridaním prvého člena alebo admina."
  />

  <template v-else>
    <!-- Filter bar -->
    <div class="mb-3 flex flex-wrap items-center gap-2">
      <div class="relative grow sm:grow-0">
        <span aria-hidden="true" class="pointer-events-none absolute left-2.5 top-1/2 -translate-y-1/2 text-slate-400">🔍</span>
        <input
          v-model="searchQuery"
          type="search"
          class="input pl-8 text-sm sm:w-64"
          placeholder="Hľadať meno alebo email…"
        />
      </div>
      <select v-model="roleFilter" class="input py-1.5 text-sm sm:w-44">
        <option value="">Všetky role</option>
        <option value="PENDING">{{ ROLE_LABEL.PENDING }}</option>
        <option value="MEMBER">{{ ROLE_LABEL.MEMBER }}</option>
        <option value="ADMIN">{{ ROLE_LABEL.ADMIN }}</option>
      </select>
      <select v-model="activeFilter" class="input py-1.5 text-sm sm:w-40">
        <option value="all">Aktívni aj neaktívni</option>
        <option value="active">Len aktívni</option>
        <option value="inactive">Len deaktivovaní</option>
      </select>
      <span class="text-xs text-slate-500">{{ filteredUsers.length }} / {{ items.length }}</span>
      <button
        v-if="searchQuery || roleFilter || activeFilter !== 'all'"
        type="button"
        class="text-xs text-slate-500 hover:text-slate-700 hover:underline"
        @click="clearFilters"
      >
        Zrušiť filtre
      </button>
    </div>

    <EmptyState
      v-if="filteredUsers.length === 0"
      title="Nič nezodpovedá filtru"
      description="Skús zmeniť hľadanie alebo zrušiť filtre."
    />

    <div v-else class="overflow-hidden rounded-2xl bg-white ring-1 ring-slate-200">
    <table class="min-w-full divide-y divide-slate-200 text-sm">
      <thead class="bg-slate-50 text-left text-xs font-semibold uppercase tracking-wide text-slate-600">
        <tr>
          <th class="px-4 py-2.5">Meno</th>
          <th class="px-4 py-2.5">Email</th>
          <th class="px-4 py-2.5">Členské ID</th>
          <th class="px-4 py-2.5">Rola</th>
          <th class="px-4 py-2.5">Stav</th>
          <th class="px-4 py-2.5">Vytvorený</th>
          <th class="px-4 py-2.5 text-right">Akcie</th>
        </tr>
      </thead>
      <tbody class="divide-y divide-slate-100">
        <tr v-for="user in filteredUsers" :key="user.id" :class="{ 'bg-sky-50/40': isSelf(user) }">
          <td class="px-4 py-2 font-medium text-slate-900">
            {{ user.name }}
            <span v-if="isSelf(user)" class="ml-1 text-xs font-normal text-slate-500">(ja)</span>
          </td>
          <td class="px-4 py-2 text-slate-700">{{ user.email }}</td>
          <td class="px-4 py-2">
            <button
              type="button"
              class="group inline-flex items-center gap-1 rounded px-1.5 py-0.5 font-mono text-xs hover:bg-slate-100"
              :class="user.memberId ? 'text-slate-800' : 'text-slate-400'"
              title="Upraviť interné členské ID"
              @click="editMemberId(user)"
            >
              {{ user.memberId ?? '—' }}
              <span aria-hidden="true" class="opacity-0 transition group-hover:opacity-100">✏️</span>
            </button>
          </td>
          <td class="px-4 py-2">
            <div class="flex items-center gap-2">
              <select
                :value="user.role"
                class="input py-1"
                :disabled="isSelf(user)"
                @change="setRole(user, ($event.target as HTMLSelectElement).value as UserRole)"
              >
                <option value="PENDING">{{ ROLE_LABEL.PENDING }}</option>
                <option value="MEMBER">{{ ROLE_LABEL.MEMBER }}</option>
                <option value="ADMIN">{{ ROLE_LABEL.ADMIN }}</option>
              </select>
              <!-- One-click promotion shortcut for PENDING accounts —
                   the most common admin action for new registrations. -->
              <button
                v-if="user.role === 'PENDING'"
                type="button"
                class="rounded-md bg-emerald-600 px-2 py-1 text-xs font-medium text-white hover:bg-emerald-700"
                title="Potvrdiť ako riadneho člena"
                @click="confirmMember(user)"
              >
                ✓ Potvrdiť
              </button>
            </div>
          </td>
          <td class="px-4 py-2">
            <button
              type="button"
              class="rounded-full px-2 py-0.5 text-xs font-medium ring-1"
              :class="
                user.isActive
                  ? 'bg-emerald-100 text-emerald-800 ring-emerald-200'
                  : 'bg-slate-100 text-slate-700 ring-slate-200'
              "
              :disabled="isSelf(user)"
              @click="toggleActive(user)"
            >
              {{ user.isActive ? 'Aktívny' : 'Deaktivovaný' }}
            </button>
          </td>
          <td class="px-4 py-2 text-slate-500">{{ formatDate(user.createdAt) }}</td>
          <td class="px-4 py-2 text-right space-x-2 whitespace-nowrap">
            <button
              type="button"
              class="text-slate-700 hover:underline"
              @click="detailUserId = user.id"
            >
              Detail
            </button>
            <button
              type="button"
              class="text-sky-700 hover:underline"
              @click="resetPassword(user)"
            >
              Reset hesla
            </button>
            <button
              type="button"
              class="text-rose-700 hover:underline disabled:text-slate-300 disabled:no-underline"
              :disabled="isSelf(user)"
              @click="remove(user)"
            >
              Zmazať
            </button>
          </td>
        </tr>
      </tbody>
    </table>
    </div>
  </template>

  <UserDetailDialog
    :user-id="detailUserId"
    @close="detailUserId = null"
    @updated="load"
  />
</template>
