import { globalIgnores } from 'eslint/config'
import { defineConfigWithVueTs, vueTsConfigs } from '@vue/eslint-config-typescript'
import pluginVue from 'eslint-plugin-vue'
import pluginVitest from '@vitest/eslint-plugin'
import pluginOxlint from 'eslint-plugin-oxlint'

export default defineConfigWithVueTs(
  { name: 'app/files-to-lint', files: ['**/*.{vue,ts,mts,tsx}'] },
  globalIgnores(['**/dist/**', '**/coverage/**', '**/test-results/**']),
  ...pluginVue.configs['flat/essential'],
  vueTsConfigs.recommended,
  { ...pluginVitest.configs.recommended, files: ['src/**/__tests__/*'] },
  // Pages talk to the API through their feature's services/ (or lib/), never directly.
  {
    files: ['src/**/pages/**/*.vue', 'src/**/components/**/*.vue'],
    rules: {
      'no-restricted-imports': ['error', { paths: [
        { name: 'axios', message: 'Use the feature service.' },
        { name: '@/lib/api/client', importNames: ['api'], message: 'Call the API through the feature services/ (pages and components only use errorMessage, fieldErrors, statusOf).' },
      ] }],
    },
  },
  ...pluginOxlint.buildFromOxlintConfigFile('.oxlintrc.json'),
)
