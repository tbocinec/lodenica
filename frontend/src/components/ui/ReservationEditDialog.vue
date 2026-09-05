<script setup lang="ts">
/**
 * Modal dialog for editing or deleting an existing reservation.
 *
 * Opens when `reservation` prop becomes non-null. Loads the reservation
 * fields into a local form, lets the user edit time, name, contact and
 * note, and exposes Save / Delete buttons. Resource is intentionally
 * read-only — moving a reservation between resources is rare and would
 * normally be done by deleting and recreating.
 *
 *   <ReservationEditDialog
 *     :reservation="selected"
 *     :resource-name="..."
 *     @close="selected = null"
 *     @saved="onSaved"
 *     @deleted="onDeleted"
 *   />
 */
import { computed, reactive, ref, watch } from 'vue';

import { auditApi } from '@/api/audit.api';
import { reservationsApi } from '@/api/reservations.api';
import { usersApi } from '@/api/users.api';
import { ReservationStatus, type AuditLog, type Reservation } from '@/api/types';
import { useAuthStore } from '@/stores/auth.store';
import { useResourcesStore } from '@/stores/resources.store';
import { formatDateTime, isoFromDateTime } from '@/utils/format';

import ApprovalDecisionButtons from './ApprovalDecisionButtons.vue';
import DateInput from './DateInput.vue';
import LoadError from './LoadError.vue';
import ReservationStatusPill from './ReservationStatusPill.vue';
import Spinner from './Spinner.vue';

const props = defineProps<{
  reservation: Reservation | null;
  resourceName?: string;
}>();

const emit = defineEmits<{
  (e: 'close'): void;
  (e: 'saved', updated: Reservation): void;
  (e: 'deleted', id: string): void;
}>();

const form = reactive({
  customerName: '',
  customerContact: '',
  startDate: '',
  startTime: '',
  endDate: '',
  endTime: '',
  note: '',
  memberId: '',
});

const auth = useAuthStore();
const resources = useResourcesStore();
const error = ref<string | null>(null);
const submitting = ref(false);
const deleting = ref(false);

/** The booked resource, for the approver check. Store may be empty on some screens → fetch once. */
const resource = computed(() =>
  props.reservation ? resources.byId.get(props.reservation.resourceId) ?? null : null,
);
/** REZ-054 mirrored for UX only — the API enforces it. */
const canDecide = computed(
  () => auth.isAdmin || !!resource.value?.approvers?.some((a) => a.id === auth.user?.id),
);

// When the contact is a valid e-mail, offer a "write message" link that opens
// the device's mail client (works on mobile + desktop) with a prefilled subject.
const contactEmail = computed(() => {
  const v = form.customerContact.trim();
  return /^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(v) ? v : null;
});
const mailtoHref = computed(() => {
  if (!contactEmail.value) return '';
  const subject = `Rezervácia — ${props.resourceName ?? 'Lodenica KVŠ'}`;
  return `mailto:${contactEmail.value}?subject=${encodeURIComponent(subject)}`;
});

// Admin: reassign ownership (member ID). Members list drives a datalist.
const members = ref<Array<{ memberId: string; name: string }>>([]);
const currentOwnerName = computed(
  () => members.value.find((m) => m.memberId === form.memberId)?.name ?? '',
);

// Reservation history (audit). Members see the changes; admins also the actor.
const history = ref<AuditLog[]>([]);
const historyOpen = ref(false);
const historyLoading = ref(false);

async function loadMembers(): Promise<void> {
  if (members.value.length) return;
  try {
    const data = await usersApi.list({ pageSize: 200 });
    members.value = data.items
      .filter((u) => !!u.memberId)
      .map((u) => ({ memberId: u.memberId as string, name: u.name }));
  } catch {
    /* non-fatal — the field still accepts a raw member ID */
  }
}

async function loadHistory(): Promise<void> {
  if (!props.reservation) return;
  historyLoading.value = true;
  try {
    const data = await auditApi.list({
      entityType: 'RESERVATION' as AuditLog['entityType'],
      entityId: props.reservation.id,
      pageSize: 50,
    });
    history.value = data.items;
  } catch (e) {
    error.value = (e as Error).message;
  } finally {
    historyLoading.value = false;
  }
}

function toggleHistory(): void {
  historyOpen.value = !historyOpen.value;
  if (historyOpen.value && history.value.length === 0) void loadHistory();
}

watch(
  () => props.reservation,
  (r) => {
    if (!r) return;
    error.value = null;
    submitting.value = false;
    deleting.value = false;
    const start = new Date(r.startsAt);
    const end = new Date(r.endsAt);
    // r.customerName is `null` only for non-members; we gate dialog
    // open on auth.isMember so this branch is effectively members-only.
    form.customerName = r.customerName ?? '';
    form.customerContact = r.customerContact ?? '';
    form.startDate = utcDate(start);
    form.startTime = utcTime(start);
    form.endDate = utcDate(end);
    form.endTime = utcTime(end);
    form.note = r.note ?? '';
    form.memberId = r.memberId ?? '';
    // Reset history for the newly-opened reservation.
    history.value = [];
    historyOpen.value = false;
    if (auth.isAdmin) void loadMembers();
    if (resources.items.length === 0) void resources.fetch();
  },
  { immediate: true },
);

function utcDate(d: Date): string {
  return `${d.getUTCFullYear()}-${pad(d.getUTCMonth() + 1)}-${pad(d.getUTCDate())}`;
}
function utcTime(d: Date): string {
  return `${pad(d.getUTCHours())}:${pad(d.getUTCMinutes())}`;
}
function pad(n: number): string {
  return String(n).padStart(2, '0');
}

async function save(): Promise<void> {
  if (!props.reservation) return;
  const startsAt = isoFromDateTime(form.startDate, form.startTime);
  const endsAt = isoFromDateTime(form.endDate, form.endTime);
  if (endsAt <= startsAt) {
    error.value = 'Koniec musí byť po začiatku.';
    return;
  }
  error.value = null;
  submitting.value = true;
  try {
    const updated = await reservationsApi.update(props.reservation.id, {
      customerName: form.customerName,
      customerContact: form.customerContact || undefined,
      startsAt,
      endsAt,
      note: form.note || undefined,
      // Admin-only ownership reassignment (ignored server-side otherwise).
      ...(auth.isAdmin ? { memberId: form.memberId.trim() || null } : {}),
    });
    emit('saved', updated);
  } catch (e) {
    error.value = (e as Error).message;
  } finally {
    submitting.value = false;
  }
}

async function remove(): Promise<void> {
  if (!props.reservation) return;
  if (!confirm('Naozaj vymazať túto rezerváciu? Akcia sa nedá vrátiť.')) return;
  error.value = null;
  deleting.value = true;
  try {
    const id = props.reservation.id;
    await reservationsApi.remove(id);
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
    v-if="reservation"
    class="fixed inset-0 z-40 flex items-end bg-slate-900/40 sm:items-center sm:justify-center"
    role="dialog"
    aria-modal="true"
    @click.self="emit('close')"
  >
    <div class="max-h-[92vh] w-full max-w-lg overflow-y-auto rounded-t-2xl bg-white p-5 shadow-xl sm:rounded-2xl">
      <header class="mb-4 flex items-start justify-between gap-3">
        <div>
          <div class="flex flex-wrap items-center gap-2">
            <h3 class="text-lg font-semibold text-slate-900">Upraviť rezerváciu</h3>
            <ReservationStatusPill :status="reservation.status" />
          </div>
          <p v-if="resourceName" class="text-sm text-slate-500">{{ resourceName }}</p>
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

      <!-- Approval workflow: decide here for those who may; explain for the rest. -->
      <div
        v-if="reservation.status === ReservationStatus.PENDING_APPROVAL && canDecide"
        class="mb-4 rounded-lg border border-amber-200 bg-amber-50/60 p-3"
      >
        <p class="mb-2 text-sm font-medium text-amber-900">⏳ Táto rezervácia čaká na tvoje schválenie.</p>
        <ApprovalDecisionButtons :reservation="reservation" @decided="emit('saved', $event)" />
      </div>
      <div
        v-else-if="reservation.status === ReservationStatus.PENDING_APPROVAL"
        class="mb-4 rounded-lg border border-amber-200 bg-amber-50/60 p-3 text-sm text-amber-900"
      >
        ⏳ Čaká na schválenie schvaľovateľom zdroja. Termín je medzitým blokovaný.
      </div>
      <div
        v-else-if="reservation.status === ReservationStatus.REJECTED"
        class="mb-4 rounded-lg border border-red-200 bg-red-50/60 p-3 text-sm text-red-900"
      >
        ✕ Zamietnutá schvaľovateľom<template v-if="reservation.decisionNote">: „{{ reservation.decisionNote }}“</template>.
      </div>

      <form class="grid gap-3 sm:grid-cols-2" @submit.prevent="save">
        <div class="sm:col-span-2">
          <label class="label" for="ed-name">Meno *</label>
          <input
            id="ed-name"
            v-model="form.customerName"
            class="input mt-1"
            required
            maxlength="200"
          />
        </div>
        <div class="sm:col-span-2">
          <label class="label" for="ed-contact">
            Kontakt
            <span v-if="!auth.isMember" class="text-slate-400">**</span>
          </label>
          <input
            id="ed-contact"
            v-model="form.customerContact"
            class="input mt-1"
            maxlength="200"
            :placeholder="auth.isMember ? '' : '** skryté — len pre prihlásených členov'"
          />
          <!-- Anon editor never sees the existing contact (the API
               strips it out). They CAN type a replacement; if they
               leave the field empty the existing value in the DB is
               preserved (PATCH omits the field when blank). -->
          <p v-if="!auth.isMember" class="mt-1 text-xs text-slate-500">
            ** Aktuálny kontakt nie je zobrazený. Ak pole necháš
            prázdne, pôvodný kontakt zostane zachovaný; ak napíšeš
            nový, prepíše ten existujúci.
          </p>
          <a
            v-if="contactEmail"
            :href="mailtoHref"
            class="btn-secondary mt-2 inline-flex text-xs"
          >
            ✉️ Napísať e‑mail
          </a>
        </div>

        <div>
          <label class="label" for="ed-sd">Od dátum *</label>
          <DateInput id="ed-sd" v-model="form.startDate" class="mt-1" required />
        </div>
        <div>
          <label class="label" for="ed-st">Od čas *</label>
          <input
            id="ed-st"
            v-model="form.startTime"
            type="time"
            step="900"
            class="input mt-1"
            required
          />
        </div>
        <div>
          <label class="label" for="ed-ed">Do dátum *</label>
          <DateInput
            id="ed-ed"
            v-model="form.endDate"
            class="mt-1"
            :min="form.startDate"
            required
          />
        </div>
        <div>
          <label class="label" for="ed-et">Do čas *</label>
          <input
            id="ed-et"
            v-model="form.endTime"
            type="time"
            step="900"
            class="input mt-1"
            required
          />
        </div>

        <div class="sm:col-span-2">
          <label class="label" for="ed-note">Poznámka</label>
          <textarea
            id="ed-note"
            v-model="form.note"
            class="input mt-1"
            rows="2"
            maxlength="1000"
          ></textarea>
        </div>

        <!-- Admin: reassign the reservation to a member. -->
        <div v-if="auth.isAdmin" class="sm:col-span-2">
          <label class="label" for="ed-member">
            Priradiť členovi <span class="text-xs font-normal text-slate-400">(interné členské ID)</span>
          </label>
          <input
            id="ed-member"
            v-model="form.memberId"
            class="input mt-1"
            maxlength="100"
            list="ed-member-list"
            placeholder="Členské ID — prázdne = bez priradenia"
          />
          <datalist id="ed-member-list">
            <option v-for="m in members" :key="m.memberId" :value="m.memberId" :label="m.name" />
          </datalist>
          <p v-if="currentOwnerName" class="mt-1 text-xs text-slate-500">Patrí: {{ currentOwnerName }}</p>
        </div>

        <LoadError class="sm:col-span-2" :message="error" />

        <div class="sm:col-span-2 mt-2 flex flex-wrap items-center justify-between gap-2">
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

      <!-- Reservation history: members see the changes; admins also who made them. -->
      <div class="mt-4 border-t border-slate-100 pt-3">
        <button
          type="button"
          class="flex w-full items-center justify-between text-sm font-medium text-slate-700 hover:text-slate-900"
          @click="toggleHistory"
        >
          <span>🕓 História rezervácie</span>
          <span class="text-slate-400">{{ historyOpen ? '▲' : '▼' }}</span>
        </button>
        <div v-if="historyOpen" class="mt-2">
          <div v-if="historyLoading" class="flex justify-center py-3"><Spinner /></div>
          <p v-else-if="history.length === 0" class="text-xs text-slate-400">Žiadne záznamy zmien.</p>
          <ul v-else class="space-y-2">
            <li v-for="h in history" :key="h.id" class="border-l-2 border-slate-200 pl-3 text-xs">
              <div class="flex flex-wrap justify-between gap-x-2 text-slate-500">
                <span>{{ formatDateTime(h.createdAt) }}</span>
                <span v-if="h.actor" class="text-slate-400">👤 {{ h.actor }}</span>
              </div>
              <p class="text-slate-700">{{ h.summary }}</p>
            </li>
          </ul>
        </div>
      </div>
    </div>
  </div>
</template>
