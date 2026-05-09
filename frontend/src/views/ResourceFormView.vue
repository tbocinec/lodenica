<script setup lang="ts">
import { onMounted, reactive, ref } from 'vue';
import { useRoute, useRouter } from 'vue-router';

import { resourcesApi } from '@/api/resources.api';
import { ResourceType } from '@/api/types';
import LoadError from '@/components/ui/LoadError.vue';
import PageHeader from '@/components/ui/PageHeader.vue';
import { RESOURCE_TYPE_LABEL } from '@/i18n/labels';

const route = useRoute();
const router = useRouter();
const id = (route.params.id as string | undefined) ?? null;

const form = reactive({
  identifier: '',
  type: ResourceType.KAYAK as ResourceType,
  name: '',
  model: '',
  color: '',
  seats: undefined as number | undefined,
  lengthCm: undefined as number | undefined,
  weightKg: undefined as number | undefined,
  note: '',
  imageUrl: '',
  isActive: true,
});

const error = ref<string | null>(null);
const submitting = ref(false);

async function load() {
  if (!id) return;
  try {
    const r = await resourcesApi.get(id);
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
    });
  } catch (e) {
    error.value = (e as Error).message;
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
      const { identifier: _id, type: _t, ...rest } = payload;
      void _id; void _t;
      await resourcesApi.update(id, rest);
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

onMounted(load);
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
        :disabled="!!id"
        required
        maxlength="50"
        pattern="^[A-Za-z0-9\-_.]+$"
        title="Iba písmená, číslice, -, _, ."
      />
      <p class="mt-1 text-xs text-slate-500">Napr. K-001, C-001, T-001.</p>
    </div>

    <div>
      <label class="label" for="type">Typ *</label>
      <select id="type" v-model="form.type" class="input mt-1" :disabled="!!id" required>
        <option v-for="t in Object.values(ResourceType)" :key="t" :value="t">
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

    <div>
      <label class="label" for="color">Farba</label>
      <input id="color" v-model="form.color" class="input mt-1" maxlength="50" />
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
      <label class="label" for="imageUrl">URL obrázka</label>
      <input id="imageUrl" v-model="form.imageUrl" class="input mt-1" type="url" />
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

    <LoadError class="sm:col-span-2" :message="error" />

    <div class="sm:col-span-2 flex flex-wrap justify-end gap-2">
      <button type="button" class="btn-secondary" @click="$router.back()">Zrušiť</button>
      <button type="submit" class="btn-primary" :disabled="submitting">
        {{ submitting ? 'Ukladám…' : id ? 'Uložiť zmeny' : 'Vytvoriť' }}
      </button>
    </div>
  </form>
</template>
