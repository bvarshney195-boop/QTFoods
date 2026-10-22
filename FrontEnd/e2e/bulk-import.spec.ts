import { randomUUID } from 'node:crypto';
import { expect, test } from '@playwright/test';
import { signInAndSelect } from './support/auth';

test('ERP Administrator previews CSV errors, commits a normalised party draft, and rolls it back', async ({ page }) => {
  await signInAndSelect(page, 'admin.user@qtfoods.local');
  const code = `E2E-IMP-${randomUUID().slice(0, 8).toUpperCase()}`;
  const file = page.getByLabel('CSV import file');

  await file.setInputFiles({
    name: 'invalid-parties.csv',
    mimeType: 'text/csv',
    buffer: Buffer.from(`code,display_name,party_kind\n${code},First party,ORGANISATION,extra\n${code},Duplicate party,UNKNOWN\n`),
  });
  await page.getByRole('button', { name: 'Preview & validate' }).click();
  await expect(page.getByText('3 validation errors')).toBeVisible();
  await expect(page.getByRole('button', { name: 'Download error report' })).toBeVisible();
  await expect(page.getByRole('button', { name: 'Commit drafts' })).toHaveCount(0);

  await file.setInputFiles({
    name: 'valid-parties.csv',
    mimeType: 'text/csv',
    buffer: Buffer.from(`code,display_name,legal_name,party_kind,notes\n${code.toLowerCase()},Imported Party,,organisation,E2E governed import\n`),
  });
  await page.getByRole('button', { name: 'Preview & validate' }).click();
  await expect(page.getByText('Ready to import')).toBeVisible();
  await page.getByRole('button', { name: 'Commit drafts' }).click();
  await expect(page.getByRole('status')).toContainText('1 validated records were imported as drafts.');
  await expect(page.getByRole('button', { name: 'Commit drafts' })).toHaveCount(0);
  await expect(page.getByRole('button', { name: 'Rollback import' })).toBeVisible();

  const committedResponse = await page.request.get(`/api/v1/master/parties?q=${encodeURIComponent(code)}`);
  expect(committedResponse.ok()).toBeTruthy();
  const committed = await committedResponse.json();
  expect(committed.meta.total).toBe(1);
  expect(committed.data[0]).toMatchObject({ code, display_name: 'Imported Party', status: 'DRAFT' });

  await page.getByRole('button', { name: 'Rollback import' }).click();
  await expect(page.getByRole('status')).toContainText('The imported draft records were rolled back.');
  await expect(page.getByRole('button', { name: 'Rollback import' })).toHaveCount(0);
  await expect(page.getByRole('button', { name: 'Commit drafts' })).toHaveCount(0);

  await expect.poll(async () => {
    const response = await page.request.get(`/api/v1/master/parties?q=${encodeURIComponent(code)}`);
    return (await response.json()).meta.total;
  }).toBe(0);
});
