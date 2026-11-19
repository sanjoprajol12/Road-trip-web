import { expect, test } from '@playwright/test'
import { createConfirmedRider, sql } from './support/local'

const password = 'apply-check-2026'

test('a rider applies to become an event organizer and sees the decision', async ({ page }, info) => {
  const rider = await createConfirmedRider(`apply-${info.project.name}`, password)
  await page.goto('/sign-in')
  await page.getByLabel('Email').fill(rider.email)
  await page.getByLabel('Password', { exact: true }).fill(password)
  await page.getByRole('button', { name: 'Sign in' }).click()
  await expect(page.getByRole('heading', { name: 'Verification' })).toBeVisible()
  await expect(page.getByText('You have not applied for any verification.')).toBeVisible()

  await page.getByRole('button', { name: 'Apply for verification' }).click()
  await page.getByRole('button', { name: 'Send application' }).click()
  await expect(page.getByText('Choose what to verify.')).toBeVisible()
  await page.getByRole('radio', { name: /Event organizer/ }).check()
  await page.getByLabel('Your statement').fill('Too short')
  await page.getByRole('button', { name: 'Send application' }).click()
  await expect(page.getByText('Write at least 20 characters')).toBeVisible()
  await page.getByLabel('Your statement').fill('I have led monthly Kathmandu valley rides for three years, usually 10 to 20 riders.')
  await page.getByRole('button', { name: 'Send application' }).click()

  await expect(page.getByText('Application sent. Staff will review it.')).toBeVisible()
  const claims = page.getByRole('list', { name: 'Your verification claims' })
  await expect(claims.getByText('Event organizer')).toBeVisible()
  await expect(claims.getByText('Pending review')).toBeVisible()

  // An open claim cannot be filed twice: the option disappears from the form.
  await page.getByRole('button', { name: 'Apply for verification' }).click()
  await expect(page.getByRole('radio', { name: /Event organizer/ })).toHaveCount(0)
  await page.getByRole('button', { name: 'Cancel' }).click()

  // A decision made by staff (simulated in SQL here; the admin suite drives the real UI) shows with its reason.
  sql(`update user_verifications set status = 'rejected', review_note = 'Please add the names of rides you led.'
       where user_id = '${rider.id}'`)
  await page.reload()
  await expect(claims.getByText('Rejected')).toBeVisible()
  await expect(claims.getByText('Please add the names of rides you led.')).toBeVisible()
})
