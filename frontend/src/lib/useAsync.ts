import { ref, shallowRef } from 'vue'
import { errorMessage } from '@/lib/api/client'

/** Loading / error / data for one async source, so every page shows the same three states. */
export function useAsync<T>(load: () => Promise<T>) {
  const data = shallowRef<T | null>(null)
  const error = ref<string | null>(null)
  const loading = ref(false)

  async function run() {
    loading.value = true
    error.value = null
    try {
      data.value = await load()
    } catch (e) {
      error.value = errorMessage(e)
    } finally {
      loading.value = false
    }
  }

  void run()
  return { data, error, loading, run }
}
