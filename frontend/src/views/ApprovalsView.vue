<script setup lang="ts">
/**
 * "Na schválenie" — waiting requests the signed-in member may decide
 * (REZ-060). Target of the approver e-mail. The store refresh triggered by
 * the decision buttons drops a decided item from the list.
 */
import { onMounted } from 'vue';
import { RouterLink } from 'vue-router';

import ApprovalDecisionButtons from '@/components/ui/ApprovalDecisionButtons.vue';
import EmptyState from '@/components/ui/EmptyState.vue';
import LoadError from '@/components/ui/LoadError.vue';
import PageHeader from '@/components/ui/PageHeader.vue';
import ResourceTypeBadge from '@/components/ui/ResourceTypeBadge.vue';
import Spinner from '@/components/ui/Spinner.vue';
import { useApprovalsStore } from '@/stores/approvals.store';
import { useResourcesStore } from '@/stores/resources.store';
import { formatDateTime, formatReservationRange } from '@/utils/format';

const approvals = useApprovalsStore();
const resources = useResourcesStore();

onMounted(async () => {
  await Promise.all([
    approvals.refresh(),
    resources.items.length ? Promise.resolve() : resources.fetch(),
  ]);
});
</script>

<template>
  <PageHeader
    title="Na schválenie"
    subtitle="Rezervácie, ktoré čakajú na tvoje rozhodnutie. Termín je medzitým pre ostatných blokovaný."
  >
    <template #actions>
      <button class="btn-secondary" type="button" @click="approvals.refresh()">Obnoviť</button>
    </template>
  </PageHeader>

  <LoadError :message="approvals.error" />
  <Spinner v-if="approvals.loading && approvals.items.length === 0" />

  <EmptyState
    v-else-if="approvals.items.length === 0"
    title="Nič nečaká na tvoje schválenie"
    description="Keď niekto požiada o rezerváciu zdroja, ktorý schvaľuješ, objaví sa tu a príde ti e-mail."
  />

  <ul v-else class="grid gap-4">
    <li v-for="r in approvals.items" :key="r.id" class="card-padded grid gap-4 lg:grid-cols-[1fr_20rem]">
      <div>
        <div class="flex flex-wrap items-center gap-2">
          <ResourceTypeBadge
            v-if="resources.byId.get(r.resourceId)"
            :type="resources.byId.get(r.resourceId)!.type"
          />
          <RouterLink
            :to="`/resources/${r.resourceId}`"
            class="font-mono text-sm font-semibold text-slate-900 hover:text-brand-700"
          >
            {{ resources.byId.get(r.resourceId)?.identifier ?? '—' }}
          </RouterLink>
          <span class="text-slate-600">{{ resources.byId.get(r.resourceId)?.name ?? '' }}</span>
        </div>
        <p class="mt-2 text-sm font-medium text-slate-900">
          {{ formatReservationRange(r.startsAt, r.endsAt) }}
        </p>
        <p class="text-sm text-slate-700">
          {{ r.customerName ?? '** rezervácia' }}
          <span v-if="r.customerContact" class="text-slate-500"> · {{ r.customerContact }}</span>
        </p>
        <p v-if="r.note" class="mt-1 text-sm text-slate-600">„{{ r.note }}“</p>
        <p class="mt-1 text-xs text-slate-400">Požiadané {{ formatDateTime(r.createdAt) }}</p>
      </div>
      <ApprovalDecisionButtons :reservation="r" />
    </li>
  </ul>
</template>
