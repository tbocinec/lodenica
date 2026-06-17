<script setup lang="ts">
import { onMounted, ref } from 'vue';
import { useRoute, useRouter } from 'vue-router';

import Spinner from '@/components/ui/Spinner.vue';
import { useAuthStore } from '@/stores/auth.store';

const route = useRoute();
const router = useRouter();
const auth = useAuthStore();

const error = ref(false);

onMounted(async () => {
  const token = route.query.token as string | undefined;
  if (!token) {
    error.value = true;
    return;
  }
  try {
    await auth.applyToken(token);
    await router.replace('/');
  } catch {
    error.value = true;
  }
});
</script>

<template>
  <div class="min-h-screen bg-slate-50 flex items-center justify-center p-4">
    <div class="w-full max-w-sm rounded-2xl bg-white p-6 text-center shadow-sm ring-1 ring-slate-200">
      <template v-if="!error">
        <Spinner class="mx-auto" />
        <p class="mt-3 text-sm text-slate-500">Prihlasujem…</p>
      </template>
      <template v-else>
        <p class="text-sm text-rose-700">Prihlásenie cez sociálnu sieť zlyhalo.</p>
        <RouterLink to="/login" class="btn-secondary mt-4 inline-block">Späť na prihlásenie</RouterLink>
      </template>
    </div>
  </div>
</template>
