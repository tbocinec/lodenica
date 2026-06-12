<script setup lang="ts">
/**
 * Admin-only user management. Lists every user, lets an admin create new
 * members or admins, change roles, reset passwords and deactivate
 * accounts. Self-protection rules (admin can't demote/deactivate/delete
 * themselves) are enforced server-side; this UI keeps the dangerous
 * buttons disabled too for clearer feedback.
 */
import { onMounted, reactive, ref } from 'vue';

import { usersApi } from '@/api/users.api';
import type { User, UserRole } from '@/api/types';
import EmptyState from '@/components/ui/EmptyState.vue';
import LoadError from '@/components/ui/LoadError.vue';
import PageHeader from '@/components/ui/PageHeader.vue';
import Spinner from '@/components/ui/Spinner.vue';
import { useAuthStore } from '@/stores/auth.store';
import { formatDate } from '@/utils/format';

const auth = useAuthStore();
const items = ref<User[]>([]);
const loading = ref(false);
const error = ref<string | null>(null);
const showCreate = ref(false);

const newUser = reactive({
  name: '',
  email: '',
  password: '',
  role: 'MEMBER' as UserRole,
});

const ROLE_LABEL: Record<UserRole, string> = {
  ADMIN: 'Administrátor',
  MEMBER: 'Člen',
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
    });
    showCreate.value = false;
    Object.assign(newUser, { name: '', email: '', password: '', role: 'MEMBER' });
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

function isSelf(user: User): boolean {
  return user.id === auth.user?.id;
}

onMounted(load);
</script>

<template>
  <PageHeader
    title="Používatelia"
    subtitle="Manažment členov a administrátorov klubu."
  >
    <template #actions>
      <button type="button" class="btn-primary" @click="showCreate = !showCreate">
        {{ showCreate ? 'Skryť formulár' : '+ Pridať používateľa' }}
      </button>
    </template>
  </PageHeader>

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
    <div class="sm:col-span-4 flex justify-end gap-2">
      <button type="button" class="btn-secondary" @click="showCreate = false">Zrušiť</button>
      <button type="submit" class="btn-primary">Vytvoriť</button>
    </div>
  </form>

  <LoadError class="mb-4" :message="error" />

  <div v-if="loading" class="flex justify-center py-12">
    <Spinner />
  </div>

  <EmptyState
    v-else-if="items.length === 0"
    title="Žiadni používatelia"
    description="Začni pridaním prvého člena alebo admina."
  />

  <div v-else class="overflow-hidden rounded-2xl bg-white ring-1 ring-slate-200">
    <table class="min-w-full divide-y divide-slate-200 text-sm">
      <thead class="bg-slate-50 text-left text-xs font-semibold uppercase tracking-wide text-slate-600">
        <tr>
          <th class="px-4 py-2.5">Meno</th>
          <th class="px-4 py-2.5">Email</th>
          <th class="px-4 py-2.5">Rola</th>
          <th class="px-4 py-2.5">Stav</th>
          <th class="px-4 py-2.5">Vytvorený</th>
          <th class="px-4 py-2.5 text-right">Akcie</th>
        </tr>
      </thead>
      <tbody class="divide-y divide-slate-100">
        <tr v-for="user in items" :key="user.id" :class="{ 'bg-sky-50/40': isSelf(user) }">
          <td class="px-4 py-2 font-medium text-slate-900">
            {{ user.name }}
            <span v-if="isSelf(user)" class="ml-1 text-xs font-normal text-slate-500">(ja)</span>
          </td>
          <td class="px-4 py-2 text-slate-700">{{ user.email }}</td>
          <td class="px-4 py-2">
            <select
              :value="user.role"
              class="input py-1"
              :disabled="isSelf(user)"
              @change="setRole(user, ($event.target as HTMLSelectElement).value as UserRole)"
            >
              <option value="MEMBER">{{ ROLE_LABEL.MEMBER }}</option>
              <option value="ADMIN">{{ ROLE_LABEL.ADMIN }}</option>
            </select>
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
