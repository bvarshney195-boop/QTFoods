import { expect, type Page } from '@playwright/test';

export async function beginPasswordSignIn(
  page: Page,
  email: string,
  password = 'prototype',
): Promise<void> {
  await page.goto('/');
  await expect(page.getByRole('heading', { name: 'Welcome back' })).toBeVisible();
  await page.getByLabel('Email', { exact: true }).fill(email);
  await page.getByLabel('Password', { exact: true }).fill(password);
  await page.getByRole('button', { name: 'Continue with password' }).click();
}

export async function completePolicyRequiredEmailFactor(page: Page): Promise<void> {
  const destination = page.getByRole('heading', {
    name: /Choose where you are working|Two-step verification required/,
  });
  await expect(destination).toBeVisible();
  const secondFactorHeading = page.getByRole('heading', { name: 'Two-step verification required' });
  if (!await secondFactorHeading.isVisible()) return;

  await page.getByRole('button', { name: /^Email one-time code/ }).click();
  const preview = page.locator('.development-preview code');
  await expect(preview).toBeVisible();
  await page.getByLabel('Six-digit email code').fill((await preview.textContent())?.trim() ?? '');
  await page.getByRole('button', { name: 'Verify and continue' }).click();
  await expect(page.getByRole('heading', { name: 'Choose where you are working' })).toBeVisible();
}

export async function signInWithPassword(
  page: Page,
  email: string,
  password = 'prototype',
): Promise<void> {
  await beginPasswordSignIn(page, email, password);
  await completePolicyRequiredEmailFactor(page);
}

export async function signInAndSelect(
  page: Page,
  email: string,
  password = 'prototype',
  plant: string | RegExp = /Training Plant/,
): Promise<void> {
  await signInWithPassword(page, email, password);
  await page.getByRole('button', { name: typeof plant === 'string' ? new RegExp(plant) : plant }).click();
}
