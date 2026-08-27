<script setup lang="ts">
/**
 * Discussion thread on a damage — "I've ordered the part", "tried gluing
 * it, didn't hold".
 *
 * Confirmed members only, reading included, because every comment is
 * signed with its author's name. The server enforces that; this component
 * just renders what it's allowed to fetch and hides the form when the
 * viewer may not post.
 */
import { onMounted, ref } from 'vue';

import { damagesApi } from '@/api/damages.api';
import type { DamageComment } from '@/api/types';

const props = withDefaults(
  defineProps<{
    damageId: string;
    /** Whether the viewer is a confirmed member. */
    canComment: boolean;
    currentUserId?: string | null;
    isAdmin?: boolean;
  }>(),
  { currentUserId: null, isAdmin: false },
);

const comments = ref<DamageComment[]>([]);
const loading = ref(true);
const error = ref<string | null>(null);
const draft = ref('');
const busy = ref(false);

async function load(): Promise<void> {
  loading.value = true;
  try {
    comments.value = await damagesApi.listComments(props.damageId);
  } catch (e) {
    error.value = e instanceof Error ? e.message : 'Komentáre sa nepodarilo načítať.';
  } finally {
    loading.value = false;
  }
}

async function submit(): Promise<void> {
  const body = draft.value.trim();
  if (!body || busy.value) return;

  busy.value = true;
  error.value = null;
  try {
    comments.value = [...comments.value, await damagesApi.addComment(props.damageId, body)];
    draft.value = '';
  } catch (e) {
    error.value = e instanceof Error ? e.message : 'Komentár sa nepodarilo odoslať.';
  } finally {
    busy.value = false;
  }
}

/** Mirrors the server rule: the author, or an admin. */
function mayDelete(c: DamageComment): boolean {
  return props.isAdmin || (c.authorId !== null && c.authorId === props.currentUserId);
}

async function remove(c: DamageComment): Promise<void> {
  if (!window.confirm('Zmazať tento komentár?')) return;

  busy.value = true;
  error.value = null;
  try {
    await damagesApi.removeComment(props.damageId, c.id);
    comments.value = comments.value.filter((x) => x.id !== c.id);
  } catch (e) {
    error.value = e instanceof Error ? e.message : 'Komentár sa nepodarilo zmazať.';
  } finally {
    busy.value = false;
  }
}

function when(iso: string): string {
  const d = new Date(iso);
  return Number.isNaN(d.getTime())
    ? ''
    : d.toLocaleString('sk-SK', {
        day: 'numeric', month: 'numeric', year: 'numeric',
        hour: '2-digit', minute: '2-digit',
      });
}

onMounted(load);
</script>

<template>
  <section class="card-padded">
    <h2 class="mb-3 text-lg font-semibold">Komentáre</h2>

    <p
      v-if="error"
      class="mb-3 rounded-lg border border-rose-200 bg-rose-50 px-4 py-2 text-sm text-rose-800"
    >
      {{ error }}
    </p>

    <p v-if="loading" class="text-sm text-slate-500">Načítavam…</p>

    <template v-else>
      <p v-if="comments.length === 0" class="mb-4 text-sm text-slate-500">
        Zatiaľ žiadne komentáre.
      </p>
      <ul v-else class="mb-4 divide-y divide-slate-100">
        <li v-for="c in comments" :key="c.id" class="py-3">
          <div class="flex items-baseline justify-between gap-3">
            <p class="text-sm font-medium text-slate-800">
              {{ c.authorName }}
              <span class="ml-1 text-xs font-normal text-slate-400">{{ when(c.createdAt) }}</span>
            </p>
            <button
              v-if="mayDelete(c)"
              type="button"
              data-test="delete-comment"
              class="shrink-0 text-xs text-slate-400 hover:text-rose-600 hover:underline"
              :disabled="busy"
              @click="remove(c)"
            >
              Zmazať
            </button>
          </div>
          <p class="mt-1 whitespace-pre-wrap text-sm text-slate-700">{{ c.body }}</p>
        </li>
      </ul>

      <form v-if="canComment" @submit.prevent="submit">
        <label class="label" for="damage-comment">Nový komentár</label>
        <textarea
          id="damage-comment"
          v-model="draft"
          class="input mt-1"
          rows="4"
          maxlength="2000"
          placeholder="Napríklad čo sa už skúsilo, čo treba objednať…"
        ></textarea>
        <div class="mt-2 flex justify-end">
          <button type="submit" class="btn-primary" :disabled="busy || !draft.trim()">
            {{ busy ? 'Odosielam…' : 'Pridať komentár' }}
          </button>
        </div>
      </form>
      <p v-else class="text-sm text-slate-500">
        Komentovať môžu prihlásení členovia klubu.
      </p>
    </template>
  </section>
</template>
