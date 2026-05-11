<script setup lang="ts">
import { computed, onMounted, reactive, ref } from 'vue';
import { useRoute, useRouter } from 'vue-router';

import { eventsApi } from '@/api/events.api';
import LoadError from '@/components/ui/LoadError.vue';
import PageHeader from '@/components/ui/PageHeader.vue';
import { isoFromDateTime, toIsoDate } from '@/utils/format';

const route = useRoute();
const router = useRouter();
const id = (route.params.id as string | undefined) ?? null;

const today = toIsoDate(new Date());

const form = reactive({
  title: '',
  description: '',
  location: '',
  startDate: today,
  startTime: '09:00',
  endDate: today,
  endTime: '17:00',
});

const error = ref<string | null>(null);
const submitting = ref(false);

const composed = computed(() => ({
  startsAt: isoFromDateTime(form.startDate, form.startTime),
  endsAt: isoFromDateTime(form.endDate, form.endTime),
}));

const rangeIsValid = computed(() => composed.value.endsAt > composed.value.startsAt);

async function load() {
  if (!id) return;
  try {
    const e = await eventsApi.get(id);
    const start = new Date(e.startsAt);
    const end = new Date(e.endsAt);
    form.title = e.title;
    form.description = e.description ?? '';
    form.location = e.location ?? '';
    form.startDate = toIsoDate(start);
    form.startTime = `${String(start.getUTCHours()).padStart(2, '0')}:${String(start.getUTCMinutes()).padStart(2, '0')}`;
    form.endDate = toIsoDate(end);
    form.endTime = `${String(end.getUTCHours()).padStart(2, '0')}:${String(end.getUTCMinutes()).padStart(2, '0')}`;
  } catch (e) {
    error.value = (e as Error).message;
  }
}

async function submit(): Promise<void> {
  if (!rangeIsValid.value) {
    error.value = 'Koniec udalosti musí byť po jej začiatku.';
    return;
  }
  error.value = null;
  submitting.value = true;
  try {
    const payload = {
      title: form.title,
      description: form.description || undefined,
      location: form.location || undefined,
      startsAt: composed.value.startsAt,
      endsAt: composed.value.endsAt,
    };
    const saved = id
      ? await eventsApi.update(id, payload)
      : await eventsApi.create(payload);
    await router.push(`/events/${saved.id}`);
  } catch (e) {
    error.value = (e as Error).message;
  } finally {
    submitting.value = false;
  }
}

onMounted(load);
</script>

<template>
  <PageHeader :title="id ? 'Upraviť udalosť' : 'Nová udalosť'" />

  <form class="card-padded grid gap-4 sm:grid-cols-2" @submit.prevent="submit">
    <div class="sm:col-span-2">
      <label class="label" for="title">Názov *</label>
      <input
        id="title"
        v-model="form.title"
        class="input mt-1"
        required
        maxlength="200"
        placeholder="Napr. Splav Dunaja, Tréning juniorov…"
      />
    </div>

    <div class="sm:col-span-2">
      <label class="label" for="location">Miesto</label>
      <input
        id="location"
        v-model="form.location"
        class="input mt-1"
        maxlength="200"
        placeholder="Napr. Devín, Klub"
      />
    </div>

    <fieldset class="sm:col-span-2 rounded-lg border border-slate-200 p-4">
      <legend class="px-1 text-sm font-semibold text-slate-700">Termín</legend>
      <div class="grid gap-3 sm:grid-cols-2">
        <div>
          <label class="label" for="start-date">Od dátum *</label>
          <input id="start-date" v-model="form.startDate" type="date" class="input mt-1" required />
        </div>
        <div>
          <label class="label" for="start-time">Od čas *</label>
          <input id="start-time" v-model="form.startTime" type="time" class="input mt-1" step="900" required />
        </div>
        <div>
          <label class="label" for="end-date">Do dátum *</label>
          <input
            id="end-date"
            v-model="form.endDate"
            type="date"
            class="input mt-1"
            :min="form.startDate"
            required
          />
        </div>
        <div>
          <label class="label" for="end-time">Do čas *</label>
          <input id="end-time" v-model="form.endTime" type="time" class="input mt-1" step="900" required />
        </div>
      </div>
      <p v-if="!rangeIsValid" class="mt-3 text-xs font-medium text-red-700">⚠ Koniec musí byť po začiatku.</p>
    </fieldset>

    <div class="sm:col-span-2">
      <label class="label" for="description">Popis</label>
      <textarea
        id="description"
        v-model="form.description"
        class="input mt-1"
        rows="4"
        maxlength="2000"
        placeholder="Krátky opis udalosti, dohoda o stretnutí, čo si vziať so sebou…"
      ></textarea>
    </div>

    <LoadError class="sm:col-span-2" :message="error" />

    <div class="sm:col-span-2 flex flex-wrap justify-end gap-2">
      <button type="button" class="btn-secondary" @click="$router.back()">Zrušiť</button>
      <button type="submit" class="btn-primary" :disabled="submitting || !rangeIsValid">
        {{ submitting ? 'Ukladám…' : id ? 'Uložiť zmeny' : 'Vytvoriť udalosť' }}
      </button>
    </div>
  </form>
</template>
