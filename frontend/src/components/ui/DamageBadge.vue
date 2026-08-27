<script setup lang="ts">
/**
 * "This boat is damaged" marker, shown wherever a boat can be picked —
 * the resource combobox, the timeline rows, the reservation form.
 *
 * Colours reuse the damages module's severity scale (MINOR slate,
 * MODERATE amber, CRITICAL red) so the same damage reads the same
 * everywhere.
 *
 *   <DamageBadge :damage="r.openDamage" />                     compact
 *   <DamageBadge :damage="d" detailed link-to-detail />        with text + link
 */
import type { OpenDamage } from '@/api/types';
import { DAMAGE_SEVERITY_LABEL, DAMAGE_STATUS_LABEL } from '@/i18n/labels';

const props = withDefaults(
  defineProps<{
    damage: OpenDamage;
    /** Also render the damage description. */
    detailed?: boolean;
    /** Wrap the badge in a link to the damage detail page. */
    linkToDetail?: boolean;
  }>(),
  { detailed: false, linkToDetail: false },
);

const SEVERITY_PILL: Record<OpenDamage['severity'], string> = {
  MINOR: 'pill-slate',
  MODERATE: 'pill-amber',
  CRITICAL: 'pill-red',
};

const pill = SEVERITY_PILL[props.damage.severity];
</script>

<template>
  <component
    :is="linkToDetail ? 'RouterLink' : 'span'"
    v-bind="linkToDetail ? { to: `/damages/${damage.id}` } : {}"
    class="inline-flex flex-wrap items-center gap-1.5 text-xs"
    :class="linkToDetail ? 'hover:underline' : ''"
  >
    <span :class="pill">
      ⚠️ Poškodená · {{ DAMAGE_SEVERITY_LABEL[damage.severity] }}
    </span>
    <span class="text-slate-500">{{ DAMAGE_STATUS_LABEL[damage.status] }}</span>
    <span v-if="detailed" class="text-slate-600">{{ damage.description }}</span>
  </component>
</template>
