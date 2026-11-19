import { expect, test } from '@playwright/test'
import { sql } from './support/local'

// Stage 8 CMS on the public site: page with blocks, FAQ, blog filters, team, popup, contact form. Rows are inserted
// directly (the staff portal E2E covers editing) and removed at the end; the suite runs with one worker.
const stamp = () => `${Date.now()}${Math.floor(Math.random() * 1e3)}`
const insert = (type: string, fields: Record<string, string | null>, data: object = {}) => {
  const cols = Object.keys(fields)
  const vals = Object.values(fields).map((v) => (v === null ? 'null' : `'${v.replaceAll("'", "''")}'`))
  return sql(`insert into cms_entries (id, type, status, data, created_at, updated_at, ${cols.join(', ')})
    values (gen_random_uuid(), '${type}', 'published', '${JSON.stringify(data).replaceAll("'", "''")}'::jsonb, now(), now(), ${vals.join(', ')}) returning id`)
}

test.afterAll(() => {
  sql(`delete from cms_entries where title like 'E2E CMS %'`)
  sql(`delete from contact_messages where subject like 'E2E CMS %'`)
})

test('CMS content reaches the public website, and visitors can write to the team', async ({ page }, info) => {
  const s = `${info.project.name}-${stamp()}`
  insert('page', { slug: `e2e-about-${s}`, title: `E2E CMS About ${s}`, summary: 'Who runs this site.', seo_title: `E2E CMS SEO ${s}` },
    { blocks: [{ kind: 'heading', text: 'Our rules' }, { kind: 'callout', text: 'We never invent road conditions.' }] })
  insert('faq', { title: `E2E CMS Can I ride solo ${s}?`, body: 'Yes. Tell someone your plan first.' }, { category: 'Trips' })
  const cat = insert('blog_category', { slug: `e2e-gear-${s}`, title: `E2E CMS Gear ${s}` })
  insert('post', { slug: `e2e-post-${s}`, title: `E2E CMS Rain covers ${s}`, summary: 'Keeping bags dry.', body: 'First paragraph.\n\nSecond paragraph.' }, { category_id: cat })
  insert('post', { slug: `e2e-other-${s}`, title: `E2E CMS Other post ${s}`, body: 'Other.' })
  insert('team_member', { title: `E2E CMS Person ${s}` }, { role: 'Editor' })
  // Homepage-only popup, created first: the popup list is cached for 60 s by the browser.
  const popup = insert('popup', { title: `E2E CMS Popup ${s}`, body: 'A short announcement.' }, { show_on: 'home', frequency: 'once' })

  await page.goto(`/pages/e2e-about-${s}`)
  await expect(page.getByRole('heading', { level: 1, name: `E2E CMS About ${s}` })).toBeVisible()
  await expect(page.getByRole('heading', { level: 2, name: 'Our rules' })).toBeVisible()
  await expect(page.getByText('We never invent road conditions.')).toBeVisible()
  await expect(page).toHaveTitle(new RegExp(`E2E CMS SEO ${s}`))
  await page.goto('/pages/e2e-not-there')
  await expect(page.getByRole('heading', { name: 'Page not found' })).toBeVisible()

  await page.goto('/faq')
  await page.getByText(`E2E CMS Can I ride solo ${s}?`).click()
  await expect(page.getByText('Yes. Tell someone your plan first.')).toBeVisible()

  await page.goto('/blog')
  await page.getByLabel('Category').selectOption({ label: `E2E CMS Gear ${s}` })
  await expect(page.getByRole('link', { name: `E2E CMS Rain covers ${s}` })).toBeVisible()
  await expect(page.getByRole('link', { name: `E2E CMS Other post ${s}` })).toHaveCount(0)
  await page.getByRole('link', { name: `E2E CMS Rain covers ${s}` }).click()
  await expect(page.getByText('Second paragraph.')).toBeVisible()

  await page.goto('/team')
  await expect(page.getByRole('heading', { name: `E2E CMS Person ${s}` })).toBeVisible()

  // Popup: modal, closes with Escape, stays closed after a reload ("once per visitor").
  await page.goto('/')
  const dialog = page.getByRole('dialog', { name: `E2E CMS Popup ${s}` })
  await expect(dialog).toBeVisible()
  await page.keyboard.press('Escape')
  await expect(dialog).toBeHidden()
  await page.reload()
  await expect(page.locator('#main')).toBeVisible()
  await expect(dialog).toHaveCount(0)
  sql(`delete from cms_entries where id = '${popup}'`)

  await page.goto('/contact')
  await page.getByRole('button', { name: 'Send message' }).click()
  await expect(page.locator('#c-name-e')).toBeVisible()
  await page.getByLabel('Your name').fill('E2E Sender')
  await page.getByLabel('Email').fill(`sender-${s}@e2e.test`)
  await page.getByLabel('Subject').fill(`E2E CMS question ${s}`)
  await page.getByLabel('Message').fill('Is there a calendar of group rides for the spring?')
  await page.getByRole('button', { name: 'Send message' }).click()
  await expect(page.getByRole('status').filter({ hasText: 'reached the team' })).toBeVisible()
  expect(sql(`select status from contact_messages where subject = 'E2E CMS question ${s}'`)).toBe('new')
})
