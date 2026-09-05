<script setup lang="ts">
/**
 * Administrácia → Systém → Nastavenia stránky.
 *
 * Everything that makes this installation *this club's*: names, contacts,
 * links, the registration operator sentence, module switches, the default
 * colour theme and the logo. Saving sends only the fields that changed;
 * an emptied field goes back to its install-time default (SITE-001).
 */
import { computed, onBeforeUnmount, onMounted, reactive, ref } from 'vue';

import { ApiError } from '@/api/http';
import { siteApi, type AdminSiteConfig, type SiteConfigPatch, type SiteFeatures } from '@/api/site.api';
import LoadError from '@/components/ui/LoadError.vue';
import PageHeader from '@/components/ui/PageHeader.vue';
import Spinner from '@/components/ui/Spinner.vue';
import { useAuthStore } from '@/stores/auth.store';
import { useSiteStore } from '@/stores/site.store';
import { applyTheme, resolveTheme, THEME_KEYS, THEMES } from '@/theme/themes';

const site = useSiteStore();
const auth = useAuthStore();

type TextField =
  | 'siteName'
  | 'shortName'
  | 'clubName'
  | 'contactEmail'
  | 'adminEmail'
  | 'address'
  | 'mapsUrl'
  | 'websiteUrl'
  | 'rulesUrl'
  | 'gdprNoticeUrl'
  | 'gdprConsentUrl'
  | 'statutesUrl'
  | 'operatorNotice'
  | 'memberIdExample';

interface FieldMeta {
  key: TextField;
  label: string;
  hint: string;
  type?: 'text' | 'email' | 'url' | 'textarea';
}

const SECTIONS: { title: string; fields: FieldMeta[] }[] = [
  {
    title: 'Identita',
    fields: [
      { key: 'siteName', label: 'Názov stránky', hint: 'Predmety e-mailov, export do kalendára, titulok prehliadača.' },
      { key: 'shortName', label: 'Krátky názov', hint: 'Hlavička a prihlasovacia obrazovka. Prázdne = názov stránky.' },
      { key: 'clubName', label: 'Názov klubu', hint: 'Plný názov pre texty stránok (pravidlá, ochrana údajov).' },
    ],
  },
  {
    title: 'Kontakty a miesto',
    fields: [
      { key: 'contactEmail', label: 'Kontaktný e-mail', hint: 'Pätička prehľadu, otázky a odpovede, banner pre čakajúcich členov.', type: 'email' },
      { key: 'adminEmail', label: 'E-mail pre upozornenia správcovi', hint: 'Kam chodí oznámenie o novom členovi. Prázdne = kontaktný e-mail.', type: 'email' },
      { key: 'address', label: 'Adresa lodenice', hint: 'Miesto v exporte do kalendára (ICS, Google Calendar).' },
      { key: 'mapsUrl', label: 'Odkaz na mapu', hint: 'Prvý riadok popisu v exporte do kalendára.', type: 'url' },
    ],
  },
  {
    title: 'Odkazy',
    fields: [
      { key: 'websiteUrl', label: 'Web klubu', hint: 'Zobrazí sa v menu Informácie.', type: 'url' },
      { key: 'rulesUrl', label: 'Prevádzkový poriadok', hint: 'Menu Informácie, súhlas pri rezervácii a registrácii.', type: 'url' },
      { key: 'gdprNoticeUrl', label: 'GDPR – Informačná povinnosť', hint: 'Menu Informácie a registrácia.', type: 'url' },
      { key: 'gdprConsentUrl', label: 'GDPR – Súhlas dotknutej osoby', hint: 'Menu Informácie a registrácia.', type: 'url' },
      { key: 'statutesUrl', label: 'Stanovy klubu', hint: 'Súhlas pri registrácii. Prázdny odkaz sa všade skryje.', type: 'url' },
    ],
  },
  {
    title: 'Registrácia',
    fields: [
      { key: 'operatorNotice', label: 'Prevádzkovateľ', hint: 'Veta „Prevádzkovateľ: názov, adresa, IČO…“ v registračnom formulári.', type: 'textarea' },
      { key: 'memberIdExample', label: 'Príklad členského ID', hint: 'Nápoveda v poliach pre členské ID (napr. KLUB-001).' },
    ],
  },
];

const FEATURES: { key: keyof SiteFeatures; label: string; hint: string }[] = [
  { key: 'paddlingTrafficLight', label: 'Vodácky semafor', hint: 'Stav Dunaja v Bratislave a Devíne (zdroj dunajcik.sk a SHMÚ). Zmysel má len pre kluby na Dunaji.' },
  { key: 'expeditions', label: 'Expedície', hint: 'Mapa splavených miest členov.' },
];

const loading = ref(true);
const saving = ref(false);
const error = ref<string | null>(null);
const info = ref<string | null>(null);
const fieldErrors = ref<Record<string, string>>({});

const loaded = ref<AdminSiteConfig | null>(null);
const form = reactive({
  text: {} as Record<TextField, string>,
  features: { paddlingTrafficLight: false, expeditions: true } as SiteFeatures,
  theme: 'ocean',
});

function fill(config: AdminSiteConfig): void {
  loaded.value = config;
  for (const section of SECTIONS) {
    for (const f of section.fields) form.text[f.key] = config[f.key] ?? '';
  }
  form.features = { ...config.features };
  form.theme = config.theme;
}

/** Only what changed. '' on a field that had a value → null (back to default). */
function diff(): SiteConfigPatch {
  const base = loaded.value;
  if (!base) return {};
  const patch: SiteConfigPatch = {};
  for (const section of SECTIONS) {
    for (const f of section.fields) {
      const next = form.text[f.key].trim();
      const prev = base[f.key] ?? '';
      if (next === prev) continue;
      patch[f.key] = next === '' ? null : next;
    }
  }
  const features: Partial<SiteFeatures> = {};
  for (const f of FEATURES) {
    if (form.features[f.key] !== base.features[f.key]) features[f.key] = form.features[f.key];
  }
  if (Object.keys(features).length) patch.features = features;
  if (form.theme !== base.theme) patch.theme = form.theme;
  return patch;
}

const dirty = computed(() => Object.keys(diff()).length > 0);

async function load(): Promise<void> {
  loading.value = true;
  error.value = null;
  try {
    fill(await siteApi.adminGet());
  } catch (e) {
    error.value = (e as Error).message;
  } finally {
    loading.value = false;
  }
}

async function save(): Promise<void> {
  const patch = diff();
  if (!Object.keys(patch).length) return;
  saving.value = true;
  error.value = null;
  info.value = null;
  fieldErrors.value = {};
  try {
    const result = await siteApi.update(patch);
    fill(result);
    site.apply(result);
    info.value = 'Nastavenia uložené.';
  } catch (e) {
    if (e instanceof ApiError && e.details && typeof e.details === 'object') {
      const details = e.details as Record<string, string[]>;
      fieldErrors.value = Object.fromEntries(
        Object.entries(details).map(([k, v]) => [k, Array.isArray(v) ? v.join(' ') : String(v)]),
      );
    }
    error.value = (e as Error).message;
  } finally {
    saving.value = false;
  }
}

function discard(): void {
  if (loaded.value) fill(loaded.value);
  fieldErrors.value = {};
  previewTheme();
}

/* ───────────── Theme preview ───────────── */

function previewTheme(): void {
  applyTheme(form.theme);
}

onBeforeUnmount(() => {
  // Leaving without saving must not leave the preview behind.
  applyTheme(resolveTheme(auth.user?.theme, site.config.theme));
});

/* ───────────── Logo ───────────── */

const logoBusy = ref(false);

async function onLogoChange(event: Event): Promise<void> {
  const input = event.target as HTMLInputElement;
  const file = input.files?.[0];
  if (!file) return;
  logoBusy.value = true;
  error.value = null;
  info.value = null;
  try {
    const result = await siteApi.uploadLogo(file);
    fill(result);
    site.apply(result);
    info.value = 'Logo nahrané.';
  } catch (e) {
    error.value = (e as Error).message;
  } finally {
    logoBusy.value = false;
    input.value = '';
  }
}

async function removeLogo(): Promise<void> {
  if (!window.confirm('Odstrániť logo a vrátiť sa k predvolenej ikone?')) return;
  logoBusy.value = true;
  error.value = null;
  info.value = null;
  try {
    await siteApi.removeLogo();
    const result = await siteApi.adminGet();
    fill(result);
    site.apply(result);
    info.value = 'Logo odstránené.';
  } catch (e) {
    error.value = (e as Error).message;
  } finally {
    logoBusy.value = false;
  }
}

onMounted(load);
</script>

<template>
  <div>
    <PageHeader
      title="Nastavenia stránky"
      subtitle="Názov, kontakty, odkazy, moduly a vzhľad tejto inštalácie. Prázdne pole znamená predvolenú hodnotu z inštalácie."
    />

    <LoadError class="mb-4" :message="error" />
    <p v-if="info" class="mb-4 rounded-xl bg-emerald-50 px-4 py-3 text-sm text-emerald-800 ring-1 ring-emerald-200">
      {{ info }}
    </p>

    <div v-if="loading" class="flex justify-center py-12"><Spinner /></div>

    <form v-else class="space-y-6" @submit.prevent="save">
      <section v-for="section in SECTIONS" :key="section.title" class="card-padded">
        <h2 class="text-base font-semibold text-slate-900">{{ section.title }}</h2>
        <div class="mt-4 grid gap-4 sm:grid-cols-2">
          <div
            v-for="f in section.fields"
            :key="f.key"
            :class="f.type === 'textarea' ? 'sm:col-span-2' : ''"
          >
            <label class="label" :for="`site-${f.key}`">{{ f.label }}</label>
            <textarea
              v-if="f.type === 'textarea'"
              :id="`site-${f.key}`"
              v-model="form.text[f.key]"
              class="input mt-1"
              rows="3"
              maxlength="500"
            />
            <input
              v-else
              :id="`site-${f.key}`"
              v-model="form.text[f.key]"
              class="input mt-1"
              :type="f.type ?? 'text'"
              :placeholder="f.type === 'url' ? 'https://…' : ''"
            />
            <p v-if="fieldErrors[f.key]" class="mt-1 text-xs text-rose-600">{{ fieldErrors[f.key] }}</p>
            <p v-else class="mt-1 text-xs text-slate-500">{{ f.hint }}</p>
          </div>
        </div>
      </section>

      <section class="card-padded">
        <h2 class="text-base font-semibold text-slate-900">Moduly</h2>
        <p class="mt-1 text-sm text-slate-500">Vypnutý modul zmizne z menu aj z API.</p>
        <div class="mt-4 space-y-3">
          <label v-for="f in FEATURES" :key="f.key" class="flex items-start gap-3">
            <input
              :id="`site-feature-${f.key}`"
              v-model="form.features[f.key]"
              type="checkbox"
              class="mt-1 h-4 w-4 rounded"
            />
            <span>
              <span class="block text-sm font-medium text-slate-800">{{ f.label }}</span>
              <span class="block text-xs text-slate-500">{{ f.hint }}</span>
            </span>
          </label>
        </div>
      </section>

      <section class="card-padded">
        <h2 class="text-base font-semibold text-slate-900">Vzhľad</h2>
        <p class="mt-1 text-sm text-slate-500">
          Predvolená farebná téma stránky. Každý prihlásený používateľ si môže v profile zvoliť vlastnú.
        </p>
        <div class="mt-4 grid grid-cols-2 gap-3 sm:grid-cols-5">
          <label
            v-for="key in THEME_KEYS"
            :key="key"
            class="flex cursor-pointer flex-col items-center gap-2 rounded-xl border p-3 text-sm"
            :class="form.theme === key ? 'border-brand-500 ring-2 ring-brand-200' : 'border-slate-200 hover:bg-slate-50'"
          >
            <input
              v-model="form.theme"
              type="radio"
              name="site-theme"
              :value="key"
              class="sr-only"
              @change="previewTheme"
            />
            <span class="flex overflow-hidden rounded-full ring-1 ring-slate-200" aria-hidden="true">
              <span
                v-for="shade in [300, 500, 700] as const"
                :key="shade"
                class="h-6 w-6"
                :style="{ background: `rgb(${THEMES[key].colors[shade]})` }"
              />
            </span>
            <span class="font-medium text-slate-700">{{ THEMES[key].label }}</span>
          </label>
        </div>
      </section>

      <section class="card-padded">
        <h2 class="text-base font-semibold text-slate-900">Logo</h2>
        <p class="mt-1 text-sm text-slate-500">
          Štvorcový obrázok PNG, JPG alebo WebP do 2 MB. Zobrazuje sa v hlavičke a ako ikona stránky.
        </p>
        <div class="mt-4 flex flex-wrap items-center gap-4">
          <img
            :src="loaded?.logoUrl ?? '/favicon.svg'"
            alt=""
            class="h-16 w-16 rounded-xl bg-white object-contain ring-1 ring-slate-200"
          />
          <label class="btn-secondary cursor-pointer">
            <input
              type="file"
              accept="image/png,image/jpeg,image/webp"
              class="sr-only"
              :disabled="logoBusy"
              @change="onLogoChange"
            />
            {{ loaded?.logoUrl ? 'Nahradiť logo' : 'Nahrať logo' }}
          </label>
          <button
            v-if="loaded?.logoUrl"
            type="button"
            class="btn-secondary"
            :disabled="logoBusy"
            @click="removeLogo"
          >
            Odstrániť logo
          </button>
          <Spinner v-if="logoBusy" />
        </div>
      </section>

      <div class="sticky bottom-0 flex items-center justify-end gap-3 border-t border-slate-200 bg-slate-50/90 py-3 backdrop-blur">
        <button type="button" class="btn-secondary" :disabled="!dirty || saving" @click="discard">
          Zahodiť zmeny
        </button>
        <button type="submit" class="btn-primary" :disabled="!dirty || saving">
          {{ saving ? 'Ukladám…' : 'Uložiť' }}
        </button>
      </div>
    </form>
  </div>
</template>
