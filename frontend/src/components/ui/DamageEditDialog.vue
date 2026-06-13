<script setup lang="ts">
/**
 * Modal dialog for editing an existing damage. Mirrors the API surface
 * of ReservationEditDialog: opens when `damage` prop becomes non-null,
 * exposes Save + Delete buttons, emits `saved` and `deleted` so the
 * parent can refresh.
 *
 * Resource is intentionally read-only — re-pointing a damage to a
 * different boat is rare and better done by deleting + reporting fresh.
 *
 *   <DamageEditDialog
 *     :damage="editing"
 *     :resource-label="resourceLabelFor(editing)"
 *     @close="editing = null"
 *     @saved="onSaved"
 *     @deleted="onDeleted"
 *   />
 */
import { reactive, ref, watch } from 'vue';

import { damagesApi } from '@/api/damages.api';
import { DamageSeverity, DamageStatus, type Damage } from '@/api/types';
import { DAMAGE_SEVERITY_LABEL, DAMAGE_STATUS_LABEL } from '@/i18n/labels';

import LoadError from './LoadError.vue';

const props = defineProps<{
  damage: Damage | null;
  resourceLabel?: string;
}>();

const emit = defineEmits<{
  (e: 'close'): void;
  (e: 'saved', updated: Damage): void;
  (e: 'deleted', id: string): void;
}>();

const form = reactive({
  description: '',
  severity: DamageSeverity.MINOR as DamageSeverity,
  status: DamageStatus.REPORTED as DamageStatus,
  note: '',
});

const error = ref<string | null>(null);
const submitting = ref(false);
const deleting = ref(false);

watch(
  () => props.damage,
  (d) => {
    if (!d) return;
    // Defensive reset — see ReservationEditDialog for the reasoning
    // (component stays mounted between openings, so stale state from a
    // previous save/delete would otherwise leak across damages).
    error.value = null;
    submitting.value = false;
    deleting.value = false;
    form.description = d.description;
    form.severity = d.severity;
    form.status = d.status;
    form.note = d.note ?? '';
  },
  { immediate: true },
);

async function save(): Promise<void> {
  if (!props.damage) return;
  error.value = null;
  submitting.value = true;
  try {
    const updated = await damagesApi.update(props.damage.id, {
      description: form.description,
      severity: form.severity,
      status: form.status,
      note: form.note || undefined,
    });
    emit('saved', updated);
  } catch (e) {
    error.value = (e as Error).message;
  } finally {
    submitting.value = false;
  }
}

async function remove(): Promise<void> {
  if (!props.damage) return;
  if (!confirm('Naozaj vymazať toto poškodenie? Akcia sa nedá vrátiť.')) return;
  error.value = null;
  deleting.value = true;
  try {
    const id = props.damage.id;
    await damagesApi.remove(id);
    emit('deleted', id);
  } catch (e) {
    error.value = (e as Error).message;
  } finally {
    deleting.value = false;
  }
}
</script>

<template>
  <div
    v-if="damage"
    class="fixed inset-0 z-40 flex items-end bg-slate-900/40 sm:items-center sm:justify-center"
    role="dialog"
    aria-modal="true"
    @click.self="emit('close')"
  >
    <div class="w-full max-w-lg rounded-t-2xl bg-white p-5 shadow-xl sm:rounded-2xl">
      <header class="mb-4 flex items-start justify-between gap-3">
        <div>
          <h3 class="text-lg font-semibold text-slate-900">Upraviť poškodenie</h3>
          <p v-if="resourceLabel" class="text-sm text-slate-500">{{ resourceLabel }}</p>
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

      <form class="grid gap-3" @submit.prevent="save">
        <div>
          <label class="label" for="dde-desc">Popis *</label>
          <textarea
            id="dde-desc"
            v-model="form.description"
            class="input mt-1"
            rows="3"
            required
            maxlength="1000"
          ></textarea>
        </div>

        <div class="grid gap-3 sm:grid-cols-2">
          <div>
            <label class="label" for="dde-sev">Závažnosť *</label>
            <select id="dde-sev" v-model="form.severity" class="input mt-1" required>
              <option v-for="s in Object.values(DamageSeverity)" :key="s" :value="s">
                {{ DAMAGE_SEVERITY_LABEL[s] }}
              </option>
            </select>
          </div>
          <div>
            <label class="label" for="dde-status">Stav *</label>
            <select id="dde-status" v-model="form.status" class="input mt-1" required>
              <option v-for="s in Object.values(DamageStatus)" :key="s" :value="s">
                {{ DAMAGE_STATUS_LABEL[s] }}
              </option>
            </select>
          </div>
        </div>

        <div>
          <label class="label" for="dde-note">Poznámka</label>
          <textarea
            id="dde-note"
            v-model="form.note"
            class="input mt-1"
            rows="2"
            maxlength="1000"
          ></textarea>
        </div>

        <LoadError :message="error" />

        <div class="mt-2 flex flex-wrap items-center justify-between gap-2">
          <button
            type="button"
            class="btn-danger"
            :disabled="deleting || submitting"
            @click="remove"
          >
            {{ deleting ? 'Mažem…' : '🗑 Vymazať' }}
          </button>
          <div class="flex gap-2">
            <button type="button" class="btn-secondary" @click="emit('close')">
              Zavrieť
            </button>
            <button type="submit" class="btn-primary" :disabled="submitting || deleting">
              {{ submitting ? 'Ukladám…' : 'Uložiť zmeny' }}
            </button>
          </div>
        </div>
      </form>
    </div>
  </div>
</template>
