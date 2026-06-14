<script setup lang="ts">
/**
 * Reservations list with server-side pagination + filters.
 *
 * Filter parameters round-trip through GET /api/v1/reservations:
 *   - search       (LIKE on customerName / customerContact / note)
 *   - status       (CONFIRMED / CANCELLED)
 *   - resourceId   (one specific resource)
 *   - from / to    (ISO date — half-open bounds, both optional)
 *   - page / pageSize (page is 1-based)
 *
 * The UI has two "everyday" toggles on top of the explicit fields:
 *   - "Zobraziť zrušené"  → drop the default status=CONFIRMED filter
 *   - "Zobraziť minulé"   → drop the default from=today filter
 * Either toggle leaves the underlying field empty (= no constraint).
 *
 * On viewports < `sm:` the filter panel collapses behind a "Filtre"
 * button so the table gets all the room.
 */
import { computed, onMounted, ref, watch } from 'vue';
import { RouterLink } from 'vue-router';

import { reservationsApi } from '@/api/reservations.api';
import { ReservationStatus, type Reservation } from '@/api/types';
import DateInput from '@/components/ui/DateInput.vue';
import EmptyState from '@/components/ui/EmptyState.vue';
import LoadError from '@/components/ui/LoadError.vue';
import { useAuthStore } from '@/stores/auth.store';
import PageHeader from '@/components/ui/PageHeader.vue';
import ReservationEditDialog from '@/components/ui/ReservationEditDialog.vue';
import ResourceTypeBadge from '@/components/ui/ResourceTypeBadge.vue';
import Spinner from '@/components/ui/Spinner.vue';
import { RESERVATION_STATUS_LABEL } from '@/i18n/labels';
import { useResourcesStore } from '@/stores/resources.store';
import { formatReservationRange, toIsoDate } from '@/utils/format';

const reservations = ref<Reservation[]>([]);
const total = ref(0);
const loading = ref(false);
const error = ref<string | null>(null);
const editing = ref<Reservation | null>(null);
const resourcesStore = useResourcesStore();
const auth = useAuthStore();

/* ─── filter state ─────────────────────────────────────────────── */

const showCancelled = ref(false);
const showPast = ref(false);
const search = ref('');
const resourceIdFilter = ref('');
const dateFromFilter = ref('');
const dateToFilter = ref('');
const filtersOpen = ref(false); // mobile drawer toggle

const PAGE_SIZE = 25;
const page = ref(1);
const totalPages = computed(() => Math.max(1, Math.ceil(total.value / PAGE_SIZE)));

/** Count of non-default filter values — shown on the mobile "Filtre" button. */
const activeFilterCount = computed(() => {
  let n = 0;
  if (search.value.trim()) n++;
  if (resourceIdFilter.value) n++;
  if (dateFromFilter.value) n++;
  if (dateToFilter.value) n++;
  if (showCancelled.value) n++;
  if (showPast.value) n++;
  return n;
});

/* ─── data load ────────────────────────────────────────────────── */

async function load() {
  loading.value = true;
  error.value = null;
  try {
    const params = {
      page: page.value,
      pageSize: PAGE_SIZE,
      search: search.value.trim() || undefined,
      resourceId: resourceIdFilter.value || undefined,
      status: showCancelled.value ? undefined : ReservationStatus.CONFIRMED,
      // Default to "from today" unless the user opts into past.
      from: showPast.value
        ? dateFromFilter.value
          ? new Date(`${dateFromFilter.value}T00:00:00Z`).toISOString()
          : undefined
        : new Date(`${dateFromFilter.value || toIsoDate(new Date())}T00:00:00Z`).toISOString(),
      to: dateToFilter.value
        ? new Date(`${dateToFilter.value}T23:59:59Z`).toISOString()
        : undefined,
    };
    const [data] = await Promise.all([reservationsApi.list(params), resourcesStore.fetch()]);
    reservations.value = data.items;
    total.value = data.total;
  } catch (e) {
    error.value = (e as Error).message;
  } finally {
    loading.value = false;
  }
}

/* ─── filter triggers ──────────────────────────────────────────── */

// Any filter change snaps us back to page 1 and reloads.
watch(
  [showCancelled, showPast, search, resourceIdFilter, dateFromFilter, dateToFilter],
  () => {
    page.value = 1;
    load();
  },
);

function resetFilters() {
  search.value = '';
  resourceIdFilter.value = '';
  dateFromFilter.value = '';
  dateToFilter.value = '';
  showCancelled.value = false;
  showPast.value = false;
  // The watcher above triggers load + page reset.
}

function changePage(delta: number) {
  const next = page.value + delta;
  if (next >= 1 && next <= totalPages.value) {
    page.value = next;
    load();
  }
}

/* ─── edit dialog wiring ──────────────────────────────────────── */

function editingResourceName(): string | undefined {
  return editing.value ? resourcesStore.byId.get(editing.value.resourceId)?.name : undefined;
}

async function onSaved() {
  editing.value = null;
  await load();
}
async function onDeleted() {
  editing.value = null;
  await load();
}

onMounted(load);
</script>

<template>
  <PageHeader title="Rezervácie">
    <template #actions>
      <RouterLink to="/reservations/new" class="btn-primary">＋ Vytvoriť rezerváciu</RouterLink>
    </template>
  </PageHeader>

  <!-- Three booking-flow hubs (unchanged from earlier commit). -->
  <section class="mb-5 grid gap-3 sm:grid-cols-3">
    <RouterLink
      to="/reservations/new"
      class="group flex items-start gap-3 rounded-2xl border border-brand-200 bg-brand-50/60 p-4 ring-1 ring-transparent transition hover:border-brand-400 hover:ring-brand-200"
    >
      <span class="text-2xl" aria-hidden="true">🛶</span>
      <div class="flex-1">
        <p class="font-semibold text-brand-900">Začínam loďou</p>
        <p class="mt-0.5 text-xs text-brand-800">
          Viem akú loď chcem — vyberiem si ju a doplním voľný termín.
        </p>
        <p class="mt-2 text-xs font-medium text-brand-700 group-hover:underline">
          ＋ Vytvoriť rezerváciu →
        </p>
      </div>
    </RouterLink>
    <RouterLink
      to="/timeline"
      class="group flex items-start gap-3 rounded-2xl border border-slate-200 bg-white p-4 transition hover:border-brand-400 hover:shadow-sm"
    >
      <span class="text-2xl" aria-hidden="true">⏱️</span>
      <div class="flex-1">
        <p class="font-semibold text-slate-900">Viem dátum, hľadám loď</p>
        <p class="mt-0.5 text-xs text-slate-600">
          Pozriem si všetky lode na konkrétny deň po hodinách —
          kde je voľno, tam kliknem.
        </p>
        <p class="mt-2 text-xs font-medium text-slate-700 group-hover:underline">
          Časová os →
        </p>
      </div>
    </RouterLink>
    <RouterLink
      to="/calendar"
      class="group flex items-start gap-3 rounded-2xl border border-slate-200 bg-white p-4 transition hover:border-brand-400 hover:shadow-sm"
    >
      <span class="text-2xl" aria-hidden="true">🗓️</span>
      <div class="flex-1">
        <p class="font-semibold text-slate-900">Mesačný prehľad</p>
        <p class="mt-0.5 text-xs text-slate-600">
          Plánovanie víkendov a viacdňových akcií — vidím obsadenosť
          celého mesiaca naraz.
        </p>
        <p class="mt-2 text-xs font-medium text-slate-700 group-hover:underline">
          Kalendár →
        </p>
      </div>
    </RouterLink>
  </section>

  <!-- ───────────────────────  FILTER BAR  ─────────────────────── -->
  <!-- Mobile: a single "Filtre" toggle that expands the form below.
       Desktop: the form is always rendered (sm:block) so the toggle
       is hidden via sm:hidden on the button. -->
  <div class="mb-3 flex items-center justify-between gap-2 sm:hidden">
    <button
      type="button"
      class="btn-secondary"
      :aria-expanded="filtersOpen"
      @click="filtersOpen = !filtersOpen"
    >
      🔍 Filtre
      <span
        v-if="activeFilterCount > 0"
        class="ml-1 rounded-full bg-brand-600 px-1.5 text-[10px] font-bold text-white"
      >
        {{ activeFilterCount }}
      </span>
      <span aria-hidden="true" class="ml-1">{{ filtersOpen ? '▴' : '▾' }}</span>
    </button>
    <p class="text-xs text-slate-500">Spolu {{ total }}</p>
  </div>

  <section
    class="mb-4 rounded-2xl bg-white p-4 ring-1 ring-slate-200"
    :class="filtersOpen ? '' : 'hidden sm:block'"
  >
    <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
      <div>
        <label class="label" for="r-search">Vyhľadávanie</label>
        <input
          id="r-search"
          v-model="search"
          type="search"
          class="input mt-1"
          placeholder="meno, kontakt, K-007, Pyranha…"
          maxlength="120"
        />
      </div>
      <div>
        <label class="label" for="r-resource">Zdroj</label>
        <select id="r-resource" v-model="resourceIdFilter" class="input mt-1">
          <option value="">Všetky zdroje</option>
          <option v-for="r in resourcesStore.items" :key="r.id" :value="r.id">
            {{ r.identifier }} · {{ r.name }}
          </option>
        </select>
      </div>
      <div>
        <label class="label" for="r-from">Od</label>
        <DateInput id="r-from" v-model="dateFromFilter" class="mt-1" />
      </div>
      <div>
        <label class="label" for="r-to">Do</label>
        <DateInput id="r-to" v-model="dateToFilter" class="mt-1" />
      </div>
    </div>

    <div class="mt-3 flex flex-wrap items-center gap-x-4 gap-y-2 border-t border-slate-100 pt-3">
      <label class="inline-flex items-center gap-2 text-sm font-medium text-slate-700">
        <input v-model="showCancelled" type="checkbox" class="h-4 w-4 rounded" />
        Zobraziť zrušené
      </label>
      <label class="inline-flex items-center gap-2 text-sm font-medium text-slate-700">
        <input v-model="showPast" type="checkbox" class="h-4 w-4 rounded" />
        Zobraziť minulé
      </label>
      <span class="hidden sm:inline-block text-xs text-slate-500">Spolu {{ total }}</span>
      <button
        v-if="activeFilterCount > 0"
        type="button"
        class="ml-auto text-xs font-medium text-brand-700 hover:underline"
        @click="resetFilters"
      >
        Zrušiť filtre
      </button>
    </div>
  </section>

  <LoadError :message="error" />
  <Spinner v-if="loading && !reservations.length" />

  <EmptyState
    v-else-if="reservations.length === 0"
    :title="activeFilterCount > 0 ? 'Žiadne zhody' : 'Žiadne rezervácie'"
    :description="activeFilterCount > 0
      ? 'Pre vybrané filtre nie sú žiadne rezervácie. Zmen alebo zruš filtre.'
      : 'Vytvor prvú rezerváciu — proces je rovnaký pre lode aj priestory.'"
  >
    <RouterLink
      v-if="activeFilterCount === 0"
      to="/reservations/new"
      class="btn-primary"
    >
      ＋ Vytvoriť rezerváciu
    </RouterLink>
    <button v-else type="button" class="btn-secondary" @click="resetFilters">
      Zrušiť filtre
    </button>
  </EmptyState>

  <div v-else>
    <!-- Desktop table -->
    <div class="hidden md:block card overflow-hidden">
      <div class="overflow-x-auto">
        <table class="table-clean">
          <thead class="bg-slate-100/70">
            <tr>
              <th>Termín</th>
              <th>Zdroj</th>
              <th>Rezervácia pre</th>
              <th class="hidden lg:table-cell">Kontakt</th>
              <th>Stav</th>
              <th class="text-right">Akcie</th>
            </tr>
          </thead>
          <tbody>
            <tr
              v-for="r in reservations"
              :key="r.id"
              class="cursor-pointer hover:bg-brand-50/40"
              @click="editing = r"
            >
              <td class="font-medium">{{ formatReservationRange(r.startsAt, r.endsAt) }}</td>
              <td>
                <div class="flex items-center gap-2">
                  <ResourceTypeBadge
                    v-if="resourcesStore.byId.get(r.resourceId)"
                    :type="resourcesStore.byId.get(r.resourceId)!.type"
                  />
                  <span class="font-mono text-sm font-semibold text-slate-900">
                    {{ resourcesStore.byId.get(r.resourceId)?.identifier ?? '—' }}
                  </span>
                  <span class="text-slate-600">
                    {{ resourcesStore.byId.get(r.resourceId)?.name ?? '' }}
                  </span>
                </div>
              </td>
              <td>{{ r.customerName }}</td>
              <td class="hidden lg:table-cell text-slate-500">
                <template v-if="auth.isAuthenticated">{{ r.customerContact ?? '—' }}</template>
                <span v-else aria-label="Kontakt je viditeľný len pre registrovaných členov">**</span>
              </td>
              <td>
                <span :class="r.status === 'CONFIRMED' ? 'pill-green' : 'pill-slate'">
                  {{ RESERVATION_STATUS_LABEL[r.status] }}
                </span>
              </td>
              <td class="text-right">
                <button class="btn-secondary" type="button" @click.stop="editing = r">
                  Upraviť
                </button>
              </td>
            </tr>
          </tbody>
        </table>
      </div>
    </div>

    <!-- Mobile card list (md:hidden) -->
    <ul class="md:hidden space-y-2">
      <li
        v-for="r in reservations"
        :key="r.id"
        class="cursor-pointer rounded-xl bg-white p-3 ring-1 ring-slate-200 active:ring-brand-400"
        @click="editing = r"
      >
        <div class="flex items-start justify-between gap-2">
          <div class="min-w-0 flex-1">
            <div class="flex items-center gap-2">
              <ResourceTypeBadge
                v-if="resourcesStore.byId.get(r.resourceId)"
                :type="resourcesStore.byId.get(r.resourceId)!.type"
              />
              <span class="font-mono text-sm font-semibold text-slate-900">
                {{ resourcesStore.byId.get(r.resourceId)?.identifier ?? '—' }}
              </span>
              <span class="truncate text-sm text-slate-600">
                {{ resourcesStore.byId.get(r.resourceId)?.name ?? '' }}
              </span>
            </div>
            <p class="mt-1 text-sm font-medium text-slate-800">{{ r.customerName }}</p>
            <p class="text-xs text-slate-500">
              {{ formatReservationRange(r.startsAt, r.endsAt) }}
            </p>
          </div>
          <span :class="r.status === 'CONFIRMED' ? 'pill-green' : 'pill-slate'">
            {{ RESERVATION_STATUS_LABEL[r.status] }}
          </span>
        </div>
      </li>
    </ul>

    <!-- Privacy note when contacts are masked. Members do see this
         line — harmless, just informational — but it's primarily for
         the anonymous viewer who's wondering why everyone's contact
         shows "**". -->
    <p v-if="!auth.isAuthenticated" class="mt-3 text-xs text-slate-500">
      🔒 Tento údaj (kontakt) bude dostupný len pre registrovaných členov.
      <RouterLink to="/login" class="text-brand-700 hover:underline">Prihlásiť sa</RouterLink>
    </p>

    <!-- Pagination footer -->
    <nav class="mt-4 flex items-center justify-between gap-3">
      <p class="text-sm text-slate-500">
        Strana {{ page }} z {{ totalPages }} · spolu {{ total }}
      </p>
      <div class="flex gap-2">
        <button
          type="button"
          class="btn-secondary"
          :disabled="page <= 1 || loading"
          @click="changePage(-1)"
        >
          ← Predchádzajúca
        </button>
        <button
          type="button"
          class="btn-secondary"
          :disabled="page >= totalPages || loading"
          @click="changePage(1)"
        >
          Nasledujúca →
        </button>
      </div>
    </nav>
  </div>

  <ReservationEditDialog
    :reservation="editing"
    :resource-name="editingResourceName()"
    @close="editing = null"
    @saved="onSaved"
    @deleted="onDeleted"
  />
</template>
