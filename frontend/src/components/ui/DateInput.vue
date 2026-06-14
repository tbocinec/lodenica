<script setup lang="ts">
/**
 * Drop-in replacement for `<input type="date">` with a Monday-first
 * calendar popover. The native input's calendar layout is browser /
 * OS-locale controlled — on a Slovak machine you get Mon-first, on an
 * English-locale Chrome you get Sun-first. We render our own popover
 * to guarantee Mon-first everywhere.
 *
 * Contract matches the native element so it can be swapped in directly:
 *   v-model     ↔ modelValue (ISO `YYYY-MM-DD` string, empty when unset)
 *   :min/:max   ↔ inclusive ISO bounds
 *   :required   ↔ HTML required forwarded onto a hidden input so
 *                  browser-level form validation still works
 *   :id, :disabled forwarded to the visible field for label / form wiring
 */
import {
  addMonths,
  eachDayOfInterval,
  endOfMonth,
  endOfWeek,
  format,
  isSameDay,
  isSameMonth,
  parse,
  startOfMonth,
  startOfWeek,
} from 'date-fns';
import { sk } from 'date-fns/locale';
import { computed, onMounted, onUnmounted, ref } from 'vue';

const props = withDefaults(
  defineProps<{
    /** ISO date string (`YYYY-MM-DD`), empty when no value selected. */
    modelValue?: string;
    min?: string;
    max?: string;
    required?: boolean;
    disabled?: boolean;
    id?: string;
    placeholder?: string;
  }>(),
  {
    modelValue: '',
    min: '',
    max: '',
    required: false,
    disabled: false,
    placeholder: 'd. m. rrrr',
  },
);

const emit = defineEmits<{
  (e: 'update:modelValue', value: string): void;
}>();

const open = ref(false);
const root = ref<HTMLElement | null>(null);

/** Month currently displayed in the popover. Initialised from the
 *  current value if set, otherwise today. */
const cursor = ref<Date>(parseIso(props.modelValue) ?? new Date());

function parseIso(iso: string): Date | null {
  if (!iso) return null;
  const d = parse(iso, 'yyyy-MM-dd', new Date());
  return Number.isNaN(d.getTime()) ? null : d;
}

const days = computed(() => {
  const start = startOfWeek(startOfMonth(cursor.value), { weekStartsOn: 1, locale: sk });
  const end = endOfWeek(endOfMonth(cursor.value), { weekStartsOn: 1, locale: sk });
  return eachDayOfInterval({ start, end });
});

const displayValue = computed(() => {
  const d = parseIso(props.modelValue);
  return d ? format(d, 'd. M. yyyy', { locale: sk }) : '';
});

const monthLabel = computed(() => format(cursor.value, 'LLLL yyyy', { locale: sk }));

function toIso(d: Date): string {
  return format(d, 'yyyy-MM-dd');
}

function isDisabled(d: Date): boolean {
  const iso = toIso(d);
  if (props.min && iso < props.min) return true;
  if (props.max && iso > props.max) return true;
  return false;
}

function isSelected(d: Date): boolean {
  return !!props.modelValue && toIso(d) === props.modelValue;
}

function toggleOpen(): void {
  if (props.disabled) return;
  if (!open.value && props.modelValue) {
    cursor.value = parseIso(props.modelValue) ?? new Date();
  }
  open.value = !open.value;
}

function pick(d: Date): void {
  if (isDisabled(d)) return;
  emit('update:modelValue', toIso(d));
  open.value = false;
}

function pickToday(): void {
  const today = new Date();
  if (!isDisabled(today)) pick(today);
}

function clear(): void {
  emit('update:modelValue', '');
  open.value = false;
}

function onMouseDownOutside(event: MouseEvent): void {
  if (!open.value || !root.value) return;
  if (!root.value.contains(event.target as Node)) {
    open.value = false;
  }
}

function onKeydown(event: KeyboardEvent): void {
  if (event.key === 'Escape' && open.value) {
    open.value = false;
    event.stopPropagation();
  }
}

onMounted(() => {
  document.addEventListener('mousedown', onMouseDownOutside);
  document.addEventListener('keydown', onKeydown);
});
onUnmounted(() => {
  document.removeEventListener('mousedown', onMouseDownOutside);
  document.removeEventListener('keydown', onKeydown);
});

const WEEKDAYS = ['Po', 'Ut', 'St', 'Št', 'Pi', 'So', 'Ne'] as const;

const today = new Date();
</script>

<template>
  <div ref="root" class="relative">
    <!-- The visible "input" is a readonly text field so the popover is
         the only way to change the value (no fight with the native date
         picker). A hidden mirror carries the actual ISO value so HTML5
         `required` still works inside a <form>. -->
    <input
      :id="id"
      :value="displayValue"
      type="text"
      readonly
      :disabled="disabled"
      :placeholder="placeholder"
      class="input cursor-pointer"
      :aria-haspopup="true"
      :aria-expanded="open"
      @click="toggleOpen"
      @focus="toggleOpen"
    />
    <span
      aria-hidden="true"
      class="pointer-events-none absolute right-2 top-1/2 -translate-y-1/2 text-slate-400"
    >📅</span>
    <input
      v-if="required"
      type="text"
      tabindex="-1"
      :value="modelValue"
      required
      class="pointer-events-none absolute h-px w-px opacity-0"
    />

    <div
      v-if="open"
      class="absolute left-0 right-auto z-30 mt-1 w-72 rounded-xl bg-white p-3 shadow-xl ring-1 ring-slate-200"
    >
      <header class="mb-2 flex items-center justify-between">
        <button
          type="button"
          class="rounded p-1 text-slate-600 hover:bg-slate-100"
          aria-label="Predchádzajúci mesiac"
          @click="cursor = addMonths(cursor, -1)"
        >‹</button>
        <span class="text-sm font-semibold capitalize text-slate-800">{{ monthLabel }}</span>
        <button
          type="button"
          class="rounded p-1 text-slate-600 hover:bg-slate-100"
          aria-label="Nasledujúci mesiac"
          @click="cursor = addMonths(cursor, 1)"
        >›</button>
      </header>

      <div class="mb-1 grid grid-cols-7 gap-1 text-center text-[10px] font-semibold uppercase tracking-wide text-slate-500">
        <span v-for="w in WEEKDAYS" :key="w">{{ w }}</span>
      </div>

      <div class="grid grid-cols-7 gap-1">
        <button
          v-for="d in days"
          :key="d.toISOString()"
          type="button"
          :disabled="isDisabled(d)"
          class="rounded-md py-1.5 text-xs transition-colors"
          :class="[
            !isSameMonth(d, cursor) && 'text-slate-300',
            isSameDay(d, today) && !isSelected(d) ? 'font-bold ring-1 ring-brand-200' : '',
            isSelected(d) ? 'bg-brand-600 font-semibold text-white' : '',
            isDisabled(d)
              ? 'cursor-not-allowed opacity-30'
              : !isSelected(d)
                ? 'hover:bg-brand-50 text-slate-800'
                : '',
          ]"
          @click="pick(d)"
        >
          {{ d.getDate() }}
        </button>
      </div>

      <footer class="mt-2 flex items-center justify-between border-t border-slate-100 pt-2 text-xs">
        <button
          type="button"
          class="font-medium text-brand-700 hover:underline"
          @click="pickToday"
        >
          Dnes
        </button>
        <button
          v-if="modelValue"
          type="button"
          class="font-medium text-slate-500 hover:underline"
          @click="clear"
        >
          Vymazať
        </button>
      </footer>
    </div>
  </div>
</template>
