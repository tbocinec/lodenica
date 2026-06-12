<script setup lang="ts">
/**
 * Reservation rules page. Anonymous + members see the rendered HTML;
 * admins get an extra "Upraviť" button that swaps the rendered body
 * for a TipTap WYSIWYG editor. Save round-trips through
 * /api/v1/reservation-rules (PATCH gated by `admin` middleware).
 */
import { onMounted, ref } from 'vue';

import { reservationRulesApi } from '@/api/reservation-rules.api';
import LoadError from '@/components/ui/LoadError.vue';
import PageHeader from '@/components/ui/PageHeader.vue';
import RichTextEditor from '@/components/ui/RichTextEditor.vue';
import Spinner from '@/components/ui/Spinner.vue';
import { useAuthStore } from '@/stores/auth.store';
import { formatDateTime } from '@/utils/format';

const auth = useAuthStore();

const content = ref('');
const updatedAt = ref<string | null>(null);
const loading = ref(false);
const saving = ref(false);
const error = ref<string | null>(null);

const editing = ref(false);
const draft = ref('');

async function load(): Promise<void> {
  loading.value = true;
  error.value = null;
  try {
    const data = await reservationRulesApi.get();
    content.value = data.content;
    updatedAt.value = data.updatedAt;
  } catch (e) {
    error.value = (e as Error).message;
  } finally {
    loading.value = false;
  }
}

function startEditing(): void {
  draft.value = content.value;
  editing.value = true;
}

function cancelEditing(): void {
  editing.value = false;
  draft.value = '';
}

async function save(): Promise<void> {
  saving.value = true;
  error.value = null;
  try {
    const data = await reservationRulesApi.update(draft.value);
    content.value = data.content;
    updatedAt.value = data.updatedAt;
    editing.value = false;
  } catch (e) {
    error.value = (e as Error).message;
  } finally {
    saving.value = false;
  }
}

onMounted(load);
</script>

<template>
  <PageHeader
    title="Pravidlá rezervácie"
    subtitle="Záväzné pravidlá pre rezervovanie a používanie klubovej výbavy."
  >
    <template #actions>
      <template v-if="auth.isAdmin && !editing">
        <button type="button" class="btn-primary" @click="startEditing">✏️ Upraviť</button>
      </template>
      <template v-else-if="auth.isAdmin && editing">
        <button type="button" class="btn-secondary" :disabled="saving" @click="cancelEditing">
          Zrušiť
        </button>
        <button type="button" class="btn-primary" :disabled="saving" @click="save">
          {{ saving ? 'Ukladám…' : 'Uložiť' }}
        </button>
      </template>
    </template>
  </PageHeader>

  <LoadError class="mb-4" :message="error" />

  <div v-if="loading" class="flex justify-center py-12">
    <Spinner />
  </div>

  <div v-else-if="editing" class="space-y-3">
    <RichTextEditor v-model="draft" />
    <p class="text-xs text-slate-500">
      WYSIWYG editor. Použiteľné: tučné / kurzíva / nadpisy H2-H3 / zoznamy / odkazy.
      <code>&lt;script&gt;</code> a inline event handlery sú zo servera odstránené automaticky.
    </p>
  </div>

  <article
    v-else
    class="prose prose-slate max-w-none rounded-2xl bg-white p-6 ring-1 ring-slate-200"
    v-html="content || '<p class=\'text-slate-400\'>Pravidlá zatiaľ neboli vyplnené.</p>'"
  />

  <p v-if="updatedAt && !editing" class="mt-3 text-xs text-slate-400">
    Naposledy upravené {{ formatDateTime(updatedAt) }}.
  </p>
</template>

<style scoped>
/* Reuse the same prose styles as the editor so view + edit modes look identical. */
.prose :deep(h2) {
  font-size: 1.5rem;
  font-weight: 600;
  margin: 1.25rem 0 0.5rem;
  color: rgb(15 23 42);
}
.prose :deep(h3) {
  font-size: 1.125rem;
  font-weight: 600;
  margin: 1rem 0 0.25rem;
  color: rgb(30 41 59);
}
.prose :deep(p) {
  margin: 0.5rem 0;
  line-height: 1.625;
}
.prose :deep(ul) {
  margin: 0.5rem 0;
  padding-left: 1.5rem;
  list-style-type: disc;
}
.prose :deep(ol) {
  margin: 0.5rem 0;
  padding-left: 1.5rem;
  list-style-type: decimal;
}
.prose :deep(li) {
  margin: 0.125rem 0;
}
.prose :deep(a) {
  color: rgb(21 91 193);
  text-decoration: underline;
}
.prose :deep(strong) {
  font-weight: 600;
}
.prose :deep(em) {
  font-style: italic;
}
</style>
