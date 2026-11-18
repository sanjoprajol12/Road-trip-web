import './assets/styles/main.css'

import { createApp } from 'vue'
import App from './App.vue'
import router from './app/router'
import { vReveal } from './lib/motion/reveal'

createApp(App).use(router).directive('reveal', vReveal).mount('#app')
