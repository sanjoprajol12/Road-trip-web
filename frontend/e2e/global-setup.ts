import { assertLocalStack, deleteTestUsers } from './support/local'

export default function globalSetup() {
  assertLocalStack()
  deleteTestUsers()
  return () => deleteTestUsers()
}
