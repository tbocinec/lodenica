import { createPinia } from 'pinia';
import { createApp } from 'vue';

import App from './App.vue';
import { router } from './router';
import { useAuthStore } from './stores/auth.store';
import './styles/main.css';

const app = createApp(App);
const pinia = createPinia();
app.use(pinia);
app.use(router);

// Validate any stored bearer token before mounting so the first render
// already knows whether we're authenticated. Either way mount the app —
// /auth/me failure simply clears the local session.
const auth = useAuthStore(pinia);
auth.bootstrap().finally(() => {
  app.mount('#app');
});
