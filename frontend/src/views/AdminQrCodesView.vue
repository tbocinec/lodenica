<script setup lang="ts">
/**
 * Admin: generate a printable sheet of QR codes for every active resource,
 * each labelled with its identifier. "Tlačiť / Uložiť PDF" opens a clean
 * print window (print → Save as PDF) so the club can print stickers.
 */
import { onMounted, ref } from 'vue';

import { resourcesApi } from '@/api/resources.api';
import type { Resource } from '@/api/types';
import LoadError from '@/components/ui/LoadError.vue';
import PageHeader from '@/components/ui/PageHeader.vue';
import Spinner from '@/components/ui/Spinner.vue';
import { qrWithCenterLabel, resourceBookingUrl } from '@/utils/qr';

interface QrItem {
  identifier: string;
  qr: string;
}

const items = ref<QrItem[]>([]);
const loading = ref(false);
const error = ref<string | null>(null);

async function load(): Promise<void> {
  loading.value = true;
  error.value = null;
  try {
    const data = await resourcesApi.list({ pageSize: 500, isActive: true });
    const sorted = [...data.items].sort((a, b) => a.identifier.localeCompare(b.identifier));
    items.value = await Promise.all(
      sorted.map(async (r: Resource) => ({
        identifier: r.identifier,
        qr: await qrWithCenterLabel(resourceBookingUrl(r.id), r.identifier, 240),
      })),
    );
  } catch (e) {
    error.value = (e as Error).message;
  } finally {
    loading.value = false;
  }
}

function printAll(): void {
  const win = window.open('', '_blank');
  if (!win) return;
  // The identifier is rendered inside each QR, so the cells are QR-only.
  const cells = items.value
    .map((i) => `<div class="cell"><img src="${i.qr}" alt="QR ${i.identifier}"/></div>`)
    .join('');
  win.document.write(
    `<!DOCTYPE html><html lang="sk"><head><meta charset="utf-8"><title>QR kódy lodí</title>` +
      `<style>` +
      `body{margin:0;padding:12px;}` +
      `.grid{display:grid;grid-template-columns:repeat(4,1fr);gap:10px;}` +
      `.cell{border:1px solid #e2e8f0;border-radius:8px;padding:8px;text-align:center;break-inside:avoid;}` +
      `.cell img{width:150px;height:150px;}` +
      `@media print{.cell{border-color:#cbd5e1;}}` +
      `</style></head><body><div class="grid">${cells}</div>` +
      `<script>window.onload=function(){window.print();}<\/script>` +
      `</body></html>`,
  );
  win.document.close();
  win.focus();
}

onMounted(load);
</script>

<template>
  <PageHeader
    title="QR kódy lodí"
    subtitle="Tlačiteľný hárok QR kódov so všetkými aktívnymi loďami a ich identifikátormi."
  >
    <template #actions>
      <button type="button" class="btn-primary" :disabled="loading || items.length === 0" @click="printAll">
        🖨 Tlačiť / Uložiť PDF
      </button>
    </template>
  </PageHeader>

  <LoadError class="mb-4" :message="error" />

  <div v-if="loading" class="flex justify-center py-12"><Spinner /></div>

  <template v-else>
    <p class="mb-3 text-sm text-slate-500">
      {{ items.length }} lodí · identifikátor je v strede QR · každý QR otvára rezerváciu danej lode.
    </p>
    <div class="grid grid-cols-2 gap-3 sm:grid-cols-3 lg:grid-cols-4">
      <div
        v-for="i in items"
        :key="i.identifier"
        class="rounded-xl bg-white p-3 text-center ring-1 ring-slate-200"
      >
        <img :src="i.qr" :alt="`QR ${i.identifier}`" class="mx-auto h-36 w-36" />
      </div>
    </div>
  </template>
</template>
