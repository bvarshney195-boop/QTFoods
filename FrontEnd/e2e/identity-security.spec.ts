import { createHmac } from 'node:crypto';
import { expect, test, type Page } from '@playwright/test';
import { beginPasswordSignIn, signInAndSelect } from './support/auth';

test('named user changes password, enables TOTP, and signs in with both factors', async ({ page }) => {
  await loginAndSelect(page, 'admin.user@qtfoods.local', 'prototype');
  const navigation = page.getByRole('navigation', { name: 'Main menu' });
  await navigation.locator('[data-screen-code="ADM-USER"]').click();
  await page.getByRole('button', { name: '+ New' }).click();
  await page.getByRole('button', { name: 'Direct account' }).click();
  await page.getByLabel('Name').fill('E2E Security Administrator');
  await page.getByLabel('Email', { exact: true }).fill('e2e.security.admin@qtfoods.local');
  await page.getByLabel(/Temporary password/).fill('SecurityInitial123');
  await page.getByLabel('Initial role').selectOption({ label: 'ERP Administrator (ERP_ADMIN)' });
  await page.getByRole('button', { name: 'Create user' }).click();
  await expect(page.getByRole('status')).toContainText('created atomically');
  await page.getByRole('button', { name: 'Sign out' }).click();
  await expect(page.getByRole('heading', { name: 'Welcome back' })).toBeVisible();

  await loginAndSelect(page, 'e2e.security.admin@qtfoods.local', 'SecurityInitial123');
  await page.getByRole('button', { name: 'Account security' }).click();
  await expect(page.getByRole('heading', { name: 'Identity & devices' })).toBeVisible();
  await expect(page.getByText('Google Chrome')).toBeVisible();

  const passwordSection = page.locator('.security-section').filter({ hasText: 'Change password' });
  await passwordSection.getByLabel('Current password').fill('SecurityInitial123');
  await passwordSection.getByLabel('New password').fill('SecurityChanged123');
  await passwordSection.getByLabel('Confirm password').fill('SecurityChanged123');
  await passwordSection.getByRole('button', { name: 'Change password' }).click();
  await expect(page.getByRole('status')).toContainText('Password changed');

  const mfaSection = page.locator('.security-section').filter({ hasText: 'Multi-factor authentication' });
  await mfaSection.getByLabel('Current password').fill('SecurityChanged123');
  await mfaSection.getByRole('button', { name: 'Set up MFA' }).click();
  await mfaSection.getByRole('group').getByText('Cannot scan the QR code?').click();
  const secret = (await mfaSection.locator('.mfa-setup details code').textContent())?.trim();
  expect(secret).toBeTruthy();
  await mfaSection.getByLabel('MFA setup code').fill(totp(secret!));
  await mfaSection.getByRole('button', { name: 'Enable MFA' }).click();
  await expect(page.getByRole('status')).toContainText('MFA enabled');
  const recoveryCode = (await page.getByLabel('One-time MFA recovery codes').locator('code').first().textContent())?.trim();
  expect(recoveryCode).toBeTruthy();

  await page.getByRole('button', { name: 'Close account security' }).click();
  await page.getByRole('button', { name: 'Sign out' }).click();
  await expect(page.getByRole('heading', { name: 'Welcome back' })).toBeVisible();
  await loginWithMfa(page, 'e2e.security.admin@qtfoods.local', 'SecurityChanged123', totp(secret!));
  await page.getByRole('button', { name: /Training Plant/ }).click();
  await expect(page.getByRole('button', { name: 'Account security' })).toContainText('2-step on');
  await page.getByRole('button', { name: 'Sign out' }).click();
  await expect(page.getByRole('heading', { name: 'Welcome back' })).toBeVisible();

  await loginWithMfa(page, 'e2e.security.admin@qtfoods.local', 'SecurityChanged123', recoveryCode!);
  await page.getByRole('button', { name: /Training Plant/ }).click();
  await expect(page.getByRole('heading', { name: 'My work' })).toBeVisible();
});

async function loginAndSelect(page: Page, email: string, password: string): Promise<void> {
  await signInAndSelect(page, email, password);
}

async function loginWithMfa(page: Page, email: string, password: string, code: string): Promise<void> {
  await beginPasswordSignIn(page, email, password);
  await expect(page.getByRole('heading', { name: 'Two-step verification required' })).toBeVisible();
  await page.getByRole('button', { name: /^Google Authenticator/ }).click();
  await page.getByLabel('Google Authenticator or recovery code').fill(code);
  await page.getByRole('button', { name: 'Verify and continue' }).click();
}

function totp(secret: string): string {
  const alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
  let buffer = 0;
  let bits = 0;
  const bytes: number[] = [];
  for (const character of secret.replace(/[^A-Z2-7]/gi, '').toUpperCase()) {
    buffer = (buffer << 5) | alphabet.indexOf(character);
    bits += 5;
    if (bits >= 8) {
      bits -= 8;
      bytes.push((buffer >> bits) & 0xff);
      buffer &= (1 << bits) - 1;
    }
  }
  const counter = BigInt(Math.floor(Date.now() / 30_000));
  const counterBytes = Buffer.alloc(8);
  counterBytes.writeBigUInt64BE(counter);
  const digest = createHmac('sha1', Buffer.from(bytes)).update(counterBytes).digest();
  const offset = digest[digest.length - 1] & 0x0f;
  const value = ((digest[offset] & 0x7f) << 24)
    | ((digest[offset + 1] & 0xff) << 16)
    | ((digest[offset + 2] & 0xff) << 8)
    | (digest[offset + 3] & 0xff);
  return String(value % 1_000_000).padStart(6, '0');
}
