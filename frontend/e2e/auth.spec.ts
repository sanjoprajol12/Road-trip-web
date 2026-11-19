import { expect, test, type Page } from '@playwright/test'
import { authLinkFromEmail, sql, TEST_EMAIL_DOMAIN } from './support/local'

const firstPassword = 'first-ride-2026'
const newPassword = 'second-ride-2026'

function newEmail(project: string, label: string) {
  return `${label}-${project}-${Date.now()}@${TEST_EMAIL_DOMAIN}`
}

async function signUp(page: Page, email: string) {
  await page.goto('/sign-up')
  await page.getByLabel('Name shown to other riders').fill('E2E Rider')
  await page.getByLabel('Email').fill(email)
  await page.getByLabel('Password').fill(firstPassword)
  await page.getByRole('button', { name: 'Create account' }).click()
  await expect(page.getByText('Check your email.')).toBeVisible()
}

async function signOut(page: Page) {
  await page.getByRole('button', { name: 'Sign out' }).click()
  // Wait for sign-out to finish before navigating, or the next page load still sees the session.
  await expect(page.getByRole('link', { name: 'Sign in' })).toBeVisible()
}

async function signIn(page: Page, email: string, password: string) {
  await page.getByLabel('Email').fill(email)
  await page.getByLabel('Password', { exact: true }).fill(password)
  await page.getByRole('button', { name: 'Sign in' }).click()
}

test('private pages send guests to sign in and back', async ({ page }) => {
  await page.goto('/account')
  await expect(page).toHaveURL(/\/sign-in\?next=(%2F|\/)account$/)
  await expect(page.getByRole('heading', { name: 'Sign in' })).toBeVisible()
})

test('sign up, confirm by email, stay signed in, edit profile, sign out', async ({ page }, info) => {
  const email = newEmail(info.project.name, 'signup')
  await signUp(page, email)

  // Signing in before confirming is refused with a clear message.
  await page.goto('/sign-in')
  await signIn(page, email, firstPassword)
  await expect(page.getByRole('alert')).toContainText('Confirm your email first')

  await page.goto(await authLinkFromEmail(email, 'signup'))
  await expect(page).toHaveURL(/\/account/)
  await expect(page.getByText(email)).toBeVisible()
  await expect(page.getByLabel('Display name')).toHaveValue('E2E Rider')

  // Session survives a reload and a new tab.
  await page.reload()
  await expect(page.getByRole('heading', { name: 'Your account' })).toBeVisible()
  const tab = await page.context().newPage()
  await tab.goto('/account')
  await expect(tab.getByRole('heading', { name: 'Your account' })).toBeVisible()
  await tab.close()

  // Profile edit persists; invalid username is caught before saving.
  await page.getByLabel(/Username/).fill('Bad Name')
  await page.getByRole('button', { name: 'Save profile' }).click()
  await expect(page.getByText('3 to 30 lowercase letters')).toBeVisible()
  const username = `e2e_${info.project.name.replace(/^public-/, "")}_${Date.now() % 1e6}`
  await page.getByLabel(/Username/).fill(username)
  await page.getByLabel(/Home city/).fill('Pokhara')
  await page.getByRole('button', { name: 'Save profile' }).click()
  await expect(page.getByRole('status').filter({ hasText: 'Saved' })).toBeVisible()
  await page.reload()
  await expect(page.getByLabel(/Username/)).toHaveValue(username)
  expect(sql(`select home_city from users where username = '${username}'`)).toBe('Pokhara')

  // Signed-in riders skip the sign-in page.
  await page.goto('/sign-in')
  await expect(page).toHaveURL(/\/account$/)

  await signOut(page)
  await expect(page).toHaveURL(/\/$/)
  await page.goto('/account')
  await expect(page).toHaveURL(/\/sign-in/)
})

test('wrong password is refused; right one returns to the requested page', async ({ page }, info) => {
  const email = newEmail(info.project.name, 'signin')
  await signUp(page, email)
  await page.goto(await authLinkFromEmail(email, 'signup'))
  await signOut(page)

  await page.goto('/sign-in?next=%2Fexplore%2Fpokhara')
  await signIn(page, email, 'not-the-password')
  await expect(page.getByRole('alert')).toHaveText('Email or password is incorrect.')
  await signIn(page, email, firstPassword)
  await expect(page).toHaveURL(/\/explore\/pokhara$/)

  // An off-site "next" is ignored.
  await page.getByRole('link', { name: 'Account', exact: true }).click()
  await signOut(page)
  await page.goto('/sign-in?next=%2F%2Fevil.example')
  await signIn(page, email, firstPassword)
  await expect(page).toHaveURL(/127\.0\.0\.1:4180\/account$/)
})

test('password reset by email', async ({ page }, info) => {
  const email = newEmail(info.project.name, 'reset')
  await signUp(page, email)
  await page.goto(await authLinkFromEmail(email, 'signup'))
  await signOut(page)

  await page.goto('/forgot-password')
  await page.getByLabel('Email').fill(email)
  await page.getByRole('button', { name: 'Send reset link' }).click()
  await expect(page.getByText('If an account uses that address')).toBeVisible()

  await page.goto(await authLinkFromEmail(email, 'recovery'))
  await expect(page).toHaveURL(/\/reset-password/)
  await page.getByLabel('New password').fill(newPassword)
  await page.getByRole('button', { name: 'Save password' }).click()
  // A reset signs the rider out everywhere; they sign in again with the new password.
  await expect(page).toHaveURL(/\/sign-in\?reset=done$/)
  await expect(page.getByText('Password changed. Sign in with your new password.')).toBeVisible()
  await signIn(page, email, firstPassword)
  await expect(page.getByRole('alert')).toHaveText('Email or password is incorrect.')
  await signIn(page, email, newPassword)
  await expect(page).toHaveURL(/\/account$/)
})

test('reset page without a valid link offers a new one', async ({ page }) => {
  await page.goto('/reset-password')
  await expect(page.getByText('This link has expired or was already used.')).toBeVisible()
})
