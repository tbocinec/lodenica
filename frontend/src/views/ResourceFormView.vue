<script setup lang="ts">
import { computed, onMounted, reactive, ref } from 'vue';
import { useRoute, useRouter } from 'vue-router';

import { resourcesApi } from '@/api/resources.api';
import { RESOURCE_TYPE_VALUES, ResourceType, type User } from '@/api/types';
import { usersApi } from '@/api/users.api';
import LoadError from '@/components/ui/LoadError.vue';
import PageHeader from '@/components/ui/PageHeader.vue';
import { RESOURCE_TYPE_LABEL } from '@/i18n/labels';
import { BOAT_COLORS, colorHex } from '@/utils/colors';

const route = useRoute();
const router = useRouter();
const id = (route.params.id as string | undefined) ?? null;

const form = reactive({
  identifier: '',
  type: ResourceType.SEA_KAYAK as ResourceType,
  name: '',
  model: '',
  color: '',
  seats: undefined as number | undefined,
  lengthCm: undefined as number | undefined,
  weightKg: undefined as number | undefined,
  note: '',
  imageUrl: '',
  isActive: true,
  requiresApproval: false,
  approverIds: [] as string[],
});

const error = ref<string | null>(null);
const submitting = ref(false);

// Photo upload — only in edit mode (needs an existing resource id).
const photoUrl = ref<string | null>(null);
const photoUploading = ref(false);
const photoInput = ref<HTMLInputElement | null>(null);

async function load() {
  if (!id) return;
  try {
    const r = await resourcesApi.get(id);
    photoUrl.value = r.photoUrl;
    Object.assign(form, {
      identifier: r.identifier,
      type: r.type,
      name: r.name,
      model: r.model ?? '',
      color: r.color ?? '',
      seats: r.seats ?? undefined,
      lengthCm: r.lengthCm ?? undefined,
      weightKg: r.weightKg ?? undefined,
      note: r.note ?? '',
      imageUrl: r.imageUrl ?? '',
      isActive: r.isActive,
      requiresApproval: r.requiresApproval,
      approverIds: r.approvers?.map((a) => a.id) ?? [],
    });
  } catch (e) {
    error.value = (e as Error).message;
  }
}

// Approver picker (REZ-050): confirmed members + admins, searchable.
const members = ref<User[]>([]);
const approverSearch = ref('');

async function loadMembers(): Promise<void> {
  try {
    const data = await usersApi.list({ pageSize: 500 });
    members.value = data.items
      .filter((u) => u.isActive && (u.role === 'MEMBER' || u.role === 'ADMIN'))
      .sort((a, b) => a.name.localeCompare(b.name, 'sk'));
  } catch (e) {
    error.value = (e as Error).message;
  }
}

const filteredMembers = computed(() => {
  const q = approverSearch.value.trim().toLowerCase();
  if (!q) return members.value;
  return members.value.filter(
    (u) => u.name.toLowerCase().includes(q) || u.email.toLowerCase().includes(q),
  );
});

function toggleApprover(id: string): void {
  const i = form.approverIds.indexOf(id);
  if (i >= 0) form.approverIds.splice(i, 1);
  else form.approverIds.push(id);
}

async function onPhotoSelected(event: Event): Promise<void> {
  const file = (event.target as HTMLInputElement).files?.[0];
  if (!file || !id) return;
  photoUploading.value = true;
  error.value = null;
  try {
    const updated = await resourcesApi.uploadPhoto(id, file);
    photoUrl.value = updated.photoUrl;
  } catch (e) {
    error.value = (e as Error).message;
  } finally {
    photoUploading.value = false;
    if (photoInput.value) photoInput.value.value = '';
  }
}

async function removePhoto(): Promise<void> {
  if (!id || !window.confirm('Odstrániť fotku lode?')) return;
  photoUploading.value = true;
  error.value = null;
  try {
    await resourcesApi.removePhoto(id);
    photoUrl.value = null;
  } catch (e) {
    error.value = (e as Error).message;
  } finally {
    photoUploading.value = false;
  }
}

async function submit() {
  error.value = null;
  submitting.value = true;
  try {
    const payload = {
      ...form,
      model: form.model || undefined,
      color: form.color || undefined,
      note: form.note || undefined,
      imageUrl: form.imageUrl || undefined,
    };
    if (id) {
      await resourcesApi.update(id, payload);
    } else {
      await resourcesApi.create(payload);
    }
    await router.push('/resources');
  } catch (e) {
    error.value = (e as Error).message;
  } finally {
    submitting.value = false;
  }
}

onMounted(() => {
  void load();
  void loadMembers();
});
</script>

<template>
  <PageHeader :title="id ? 'Upraviť zdroj' : 'Pridať loď'" />

  <form class="card-padded grid gap-4 sm:grid-cols-2" @submit.prevent="submit">
    <div class="sm:col-span-1">
      <label class="label" for="identifier">Identifikátor *</label>
      <input
        id="identifier"
        v-model="form.identifier"
        class="input mt-1"
        required
        maxlength="50"
        pattern="^[A-Za-z0-9\-_.]+$"
        title="Iba písmená, číslice, -, _, ."
      />
      <p class="mt-1 text-xs text-slate-500">
        Napr. K-001, C-001, T-001.
        <span v-if="id" class="text-amber-600">Pri zmene identifikátora pretlač QR kód/štítok lode.</span>
      </p>
    </div>

    <div>
      <label class="label" for="type">Typ *</label>
      <select id="type" v-model="form.type" class="input mt-1" required>
        <option v-for="t in RESOURCE_TYPE_VALUES" :key="t" :value="t">
          {{ RESOURCE_TYPE_LABEL[t] }}
        </option>
      </select>
    </div>

    <div class="sm:col-span-2">
      <label class="label" for="name">Názov *</label>
      <input id="name" v-model="form.name" class="input mt-1" required maxlength="200" />
    </div>

    <div>
      <label class="label" for="model">Model</label>
      <input id="model" v-model="form.model" class="input mt-1" maxlength="200" />
    </div>

    <div class="sm:col-span-2">
      <span class="label">Farba</span>
      <div class="mt-1 flex flex-wrap items-center gap-2">
        <button
          v-for="c in BOAT_COLORS"
          :key="c.value"
          type="button"
          class="flex items-center gap-1.5 rounded-full border px-2.5 py-1 text-sm transition"
          :class="form.color.trim().toLowerCase() === c.value
            ? 'border-brand-500 bg-brand-50 text-brand-900 ring-1 ring-brand-300'
            : 'border-slate-200 text-slate-600 hover:bg-slate-50'"
          @click="form.color = c.value"
        >
          <span
            class="inline-block h-3.5 w-3.5 rounded-full ring-1 ring-slate-300"
            :style="{ backgroundColor: c.hex }"
          />
          {{ c.label }}
        </button>
        <button
          type="button"
          class="rounded-full border px-2.5 py-1 text-sm transition"
          :class="form.color.trim() === ''
            ? 'border-brand-500 bg-brand-50 text-brand-900 ring-1 ring-brand-300'
            : 'border-slate-200 text-slate-500 hover:bg-slate-50'"
          @click="form.color = ''"
        >
          Bez farby
        </button>
      </div>
      <p
        v-if="form.color.trim() && !colorHex(form.color)"
        class="mt-1 text-xs text-slate-400"
      >
        Aktuálna hodnota „{{ form.color }}" nie je v palete — vyber farbu vyššie alebo nechaj tak.
      </p>
    </div>

    <div>
      <label class="label" for="seats">Počet miest</label>
      <input
        id="seats"
        v-model.number="form.seats"
        type="number"
        min="1"
        max="20"
        class="input mt-1"
      />
    </div>

    <div>
      <label class="label" for="lengthCm">Dĺžka (cm)</label>
      <input
        id="lengthCm"
        v-model.number="form.lengthCm"
        type="number"
        min="1"
        max="2000"
        class="input mt-1"
      />
    </div>

    <div>
      <label class="label" for="weightKg">Hmotnosť (kg)</label>
      <input
        id="weightKg"
        v-model.number="form.weightKg"
        type="number"
        min="1"
        max="5000"
        class="input mt-1"
      />
    </div>

    <div class="sm:col-span-2">
      <label class="label" for="imageUrl">URL obrázka (externý odkaz)</label>
      <input id="imageUrl" v-model="form.imageUrl" class="input mt-1" type="url" />
    </div>

    <!-- Uploaded photo — only for an existing resource (needs its id).
         Distinct from the external imageUrl above. -->
    <div v-if="id" class="sm:col-span-2">
      <span class="label">Fotka lode</span>
      <div class="mt-1 flex flex-wrap items-center gap-3">
        <img
          v-if="photoUrl"
          :src="photoUrl"
          alt="Fotka lode"
          class="h-24 w-32 rounded-lg object-cover ring-1 ring-slate-200"
        />
        <div
          v-else
          class="flex h-24 w-32 items-center justify-center rounded-lg border-2 border-dashed border-slate-200 text-xs text-slate-400"
        >
          Bez fotky
        </div>
        <div class="flex flex-col gap-2">
          <input
            ref="photoInput"
            type="file"
            accept="image/jpeg,image/png,image/webp"
            class="hidden"
            @change="onPhotoSelected"
          />
          <button
            type="button"
            class="btn-secondary text-xs"
            :disabled="photoUploading"
            @click="photoInput?.click()"
          >
            {{ photoUploading ? 'Nahrávam…' : (photoUrl ? '📷 Zmeniť fotku' : '📷 Nahrať fotku') }}
          </button>
          <button
            v-if="photoUrl"
            type="button"
            class="text-xs text-rose-600 hover:underline disabled:text-slate-300"
            :disabled="photoUploading"
            @click="removePhoto"
          >
            Odstrániť fotku
          </button>
          <span class="text-xs text-slate-400">JPG/PNG/WEBP, max 5 MB.</span>
        </div>
      </div>
    </div>

    <div class="sm:col-span-2">
      <label class="label" for="note">Poznámka</label>
      <textarea
        id="note"
        v-model="form.note"
        class="input mt-1"
        rows="3"
        maxlength="1000"
      ></textarea>
    </div>

    <div class="sm:col-span-2 flex items-center gap-2">
      <input id="active" v-model="form.isActive" type="checkbox" class="h-4 w-4 rounded" />
      <label for="active" class="text-sm font-medium text-slate-700">Aktívny zdroj</label>
    </div>

    <!-- Approval workflow (REZ-050). -->
    <div class="sm:col-span-2 rounded-lg border border-slate-200 p-4">
      <label class="flex items-center gap-2">
        <input id="requiresApproval" v-model="form.requiresApproval" type="checkbox" class="h-4 w-4 rounded" />
        <span class="text-sm font-medium text-slate-700">Vyžaduje schválenie pred rezerváciou</span>
      </label>
      <p class="mt-1 text-xs text-slate-500">
        Rezervácia tohto zdroja bude čakať, kým ju niekto zo schvaľovateľov schváli.
        Rezervovať ho môžu iba prihlásení členovia; termín je medzitým blokovaný.
      </p>

      <div v-if="form.requiresApproval" class="mt-3">
        <span class="label">Schvaľovatelia</span>
        <p class="mt-1 text-xs text-slate-500">
          Ak nevyberieš nikoho, žiadosti pôjdu na klubovú adresu administrátorom.
          Správcovia môžu schvaľovať vždy, aj keď tu nie sú.
        </p>
        <input
          v-model="approverSearch"
          type="search"
          class="input mt-2 text-sm"
          placeholder="Hľadať člena — meno alebo e-mail…"
          maxlength="60"
        />
        <ul class="mt-2 max-h-56 divide-y divide-slate-100 overflow-y-auto rounded-lg border border-slate-200">
          <li v-for="u in filteredMembers" :key="u.id">
            <label class="flex cursor-pointer items-center gap-2 px-3 py-2 text-sm hover:bg-slate-50">
              <input
                type="checkbox"
                class="h-4 w-4 rounded"
                :checked="form.approverIds.includes(u.id)"
                @change="toggleApprover(u.id)"
              />
              <span class="font-medium text-slate-800">{{ u.name }}</span>
              <span class="text-xs text-slate-400">{{ u.email }}</span>
              <span v-if="u.role === 'ADMIN'" class="ml-auto text-[10px] font-semibold uppercase text-amber-700">admin</span>
            </label>
          </li>
          <li v-if="filteredMembers.length === 0" class="px-3 py-2 text-sm text-slate-400">
            Žiadny člen nezodpovedá hľadaniu.
          </li>
        </ul>
        <p class="mt-1 text-xs text-slate-500">Vybraní schvaľovatelia: {{ form.approverIds.length }}</p>
      </div>
    </div>

    <LoadError class="sm:col-span-2" :message="error" />

    <div class="sm:col-span-2 flex flex-wrap justify-end gap-2">
      <button type="button" class="btn-secondary" @click="$router.back()">Zrušiť</button>
      <button type="submit" class="btn-primary" :disabled="submitting">
        {{ submitting ? 'Ukladám…' : id ? 'Uložiť zmeny' : 'Vytvoriť' }}
      </button>
    </div>
  </form>
</template>
