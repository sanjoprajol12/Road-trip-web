import './assets/styles/main.css'

import { createApp } from 'vue'
import App from './App.vue'
import router from './app/router'
import { vReveal } from './lib/motion/reveal'
import { loadSite } from './lib/site'
import { initPreview } from './lib/preview'

// Site settings first, so a maintenance notice or closed sign-up shows from the first paint.
initPreview()
void loadSite().finally(() => createApp(App).use(router).directive('reveal', vReveal).mount('#app'))
