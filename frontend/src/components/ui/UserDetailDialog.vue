<script setup lang="ts">
/**
 * Admin-only user detail modal.
 *
 * Opens when `userId` becomes non-null, fetches the full record (incl. linked
 * social identities and lifecycle timestamps), and lets the admin:
 *  - see when the account was created, when the password was set (or that the
 *    invitation is still pending), and when GDPR consents were recorded;
 *  - edit the full name and internal member ID;
 *  - unlink a Google/Facebook login.
 *
 *   <UserDetailDialog :user-id="id" @close="id = null" @updated="reload" />
 */
import { ref, watch } from 'vue';

import { usersApi } from '@/api/users.api';
import type { User, UserRole } from '@/api/types';
import { formatDateTime } from '@/utils/format';

import LoadError from './LoadError.vue';
import Spinner from './Spinner.vue';

const props = defineProps<{ userId: string | null }>();
const emit = defineEmits<{
  (e: 'close'): void;
  (e: 'updated'): void;
}>();

const ROLE_LABEL: Record<UserRole, string> = {
  ADMIN: 'Administrátor',
  MEMBER: 'Člen',
  PENDING: 'Čaká na potvrdenie',
};

const user = ref<User | null>(null);
const loading = ref(false);
const error = ref<string | null>(null);
const saving = ref(false);

// Editable fields.
const name = ref('');
const memberId = ref('');

watch(
  () => props.userId,
  async (id) => {
    user.value = null;
    error.value = null;
    if (!id) return;
    loading.value = true;
    try {
      const u = await usersApi.get(id);
      user.value = u;
      name.value = u.name;
      memberId.value = u.memberId ?? '';
    } catch (e) {
      error.value = (e as Error).message;
    } finally {
      loading.value = false;
    }
  },
  { immediate: true },
);

const dirty = () =>
  !!user.value &&
  (name.value.trim() !== user.value.name ||
    (memberId.value.trim() || null) !== (user.value.memberId ?? null));

async function save(): Promise<void> {
  if (!user.value || !dirty()) return;
  error.value = null;
  saving.value = true;
  try {
    const updated = await usersApi.update(user.value.id, {
      name: name.value.trim(),
      memberId: memberId.value.trim() || null,
    });
    user.value = { ...user.value, ...updated };
    emit('updated');
  } catch (e) {
    error.value = (e as Error).message;
  } finally {
    saving.value = false;
  }
}

async function unlink(provider: string, label: string): Promise<void> {
  if (!user.value) return;
  if (!window.confirm(`Zrušiť prepojenie s ${label}?`)) return;
  error.value = null;
  try {
    await usersApi.unlinkIdentity(user.value.id, provider);
    user.value.identities = (user.value.identities ?? []).filter((i) => i.provider !== provider);
    emit('updated');
  } catch (e) {
    error.value = (e as Error).message;
  }
}
</script>

<template>
  <div
    v-if="userId"
    class="fixed inset-0 z-40 flex items-end bg-slate-900/40 sm:items-center sm:justify-center"
    role="dialog"
    aria-modal="true"
    @click.self="emit('close')"
  >
    <div class="max-h-[92vh] w-full max-w-lg overflow-y-auto rounded-t-2xl bg-white p-5 shadow-xl sm:rounded-2xl">
      <header class="mb-4 flex items-start justify-between gap-3">
        <div>
          <h3 class="text-lg font-semibold text-slate-900">Detail používateľa</h3>
          <p v-if="user" class="text-sm text-slate-500">{{ user.email }}</p>
        </div>
        <button
          type="button"
          class="rounded-lg p-1.5 text-slate-500 hover:bg-slate-100"
          aria-label="Zavrieť"
          @click="emit('close')"
        >
          ✕
        </button>
      </header>

      <div v-if="loading" class="flex justify-center py-10">
        <Spinner />
      </div>

      <template v-else-if="user">
        <!-- Status row -->
        <div class="mb-4 flex flex-wrap items-center gap-2 text-xs">
          <span class="rounded-full bg-slate-100 px-2 py-0.5 font-medium text-slate-700 ring-1 ring-slate-200">
            {{ ROLE_LABEL[user.role] }}
          </span>
          <span
            class="rounded-full px-2 py-0.5 font-medium ring-1"
            :class="user.isActive
              ? 'bg-emerald-100 text-emerald-800 ring-emerald-200'
              : 'bg-slate-100 text-slate-700 ring-slate-200'"
          >
            {{ user.isActive ? 'Aktívny' : 'Neaktívny' }}
          </span>
          <span
            v-if="!user.passwordSetAt"
            class="rounded-full bg-amber-100 px-2 py-0.5 font-medium text-amber-800 ring-1 ring-amber-200"
          >
            Pozvánka neprijatá
          </span>
        </div>

        <!-- Editable name + member ID -->
        <div class="grid gap-3 sm:grid-cols-2">
          <div>
            <label class="label" for="ud-name">Celé meno</label>
            <input id="ud-name" v-model="name" class="input mt-1" maxlength="200" />
          </div>
          <div>
            <label class="label" for="ud-mid">Členské ID</label>
            <input id="ud-mid" v-model="memberId" class="input mt-1" maxlength="100" placeholder="napr. KVS-001" />
          </div>
        </div>

        <!-- Timeline -->
        <dl class="mt-4 grid grid-cols-[auto_1fr] gap-x-4 gap-y-1.5 text-sm">
          <dt class="text-slate-500">Vytvorený</dt>
          <dd class="text-slate-800">{{ formatDateTime(user.createdAt) }}</dd>

          <dt class="text-slate-500">Heslo nastavené</dt>
          <dd class="text-slate-800">
            <template v-if="user.passwordSetAt">{{ formatDateTime(user.passwordSetAt) }}</template>
            <span v-else class="text-amber-700">zatiaľ nie (čaká na prijatie pozvánky)</span>
          </dd>

          <dt class="text-slate-500">GDPR súhlas</dt>
          <dd class="text-slate-800">
            <template v-if="user.gdprConsentAt">
              {{ formatDateTime(user.gdprConsentAt) }}
            </template>
            <span v-else class="text-slate-400">nezaznamenané</span>
          </dd>

          <dt class="pl-3 text-xs text-slate-400">· oboznámenie s podmienkami</dt>
          <dd class="text-xs">
            <span :class="user.privacyAck ? 'text-emerald-700' : 'text-slate-400'">
              {{ user.privacyAck ? 'áno' : 'nie' }}
            </span>
          </dd>

          <dt class="pl-3 text-xs text-slate-400">· súhlas so spracovaním</dt>
          <dd class="text-xs">
            <span :class="user.dataConsent ? 'text-emerald-700' : 'text-slate-400'">
              {{ user.dataConsent ? 'áno' : 'nie' }}
            </span>
          </dd>
        </dl>

        <!-- Linked social logins -->
        <div class="mt-4">
          <h4 class="mb-1.5 text-sm font-medium text-slate-700">Prepojené účty</h4>
          <ul v-if="user.identities && user.identities.length" class="divide-y divide-slate-100 rounded-lg ring-1 ring-slate-200">
            <li
              v-for="ident in user.identities"
              :key="ident.provider"
              class="flex items-center justify-between gap-2 px-3 py-2 text-sm"
            >
              <div class="min-w-0">
                <span class="font-medium text-slate-800">{{ ident.providerLabel }}</span>
                <span v-if="ident.email" class="text-slate-500"> · {{ ident.email }}</span>
              </div>
              <button
                type="button"
                class="text-xs text-rose-700 hover:underline"
                @click="unlink(ident.provider, ident.providerLabel)"
              >
                Zrušiť prepojenie
              </button>
            </li>
          </ul>
          <p v-else class="text-sm text-slate-400">Žiadny prepojený sociálny účet.</p>
        </div>

        <LoadError class="mt-4" :message="error" />

        <div class="mt-5 flex justify-end gap-2">
          <button type="button" class="btn-secondary" @click="emit('close')">Zavrieť</button>
          <button type="button" class="btn-primary" :disabled="saving || !dirty()" @click="save">
            <Spinner v-if="saving" class="mr-2" />
            {{ saving ? 'Ukladám…' : 'Uložiť zmeny' }}
          </button>
        </div>
      </template>

      <LoadError v-else :message="error" />
    </div>
  </div>
</template>
