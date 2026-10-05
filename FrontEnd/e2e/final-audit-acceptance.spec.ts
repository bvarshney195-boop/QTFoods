import { createHmac, randomUUID } from 'node:crypto';
import { expect, test, type Page } from '@playwright/test';
import { signInAndSelect } from './support/auth';

test.describe('final audit authentication acceptance', () => {
  test('login exposes all three methods and hides password outside the password branch', async ({ page }) => {
    await page.setViewportSize({ width: 1920, height: 900 });
    await openLogin(page);

    const layout = await page.evaluate(() => {
      const pageBox = document.querySelector('.auth-page')!.getBoundingClientRect();
      const brandBox = document.querySelector('.auth-brand')!.getBoundingClientRect();
      const formBox = document.querySelector('.auth-form')!.getBoundingClientRect();
      const cardBox = document.querySelector('.auth-card')!.getBoundingClientRect();
      return {
        brandRatio: brandBox.width / pageBox.width,
        formRatio: formBox.width / pageBox.width,
        cardTop: cardBox.top,
        cardBottom: cardBox.bottom,
        documentHeight: document.documentElement.scrollHeight,
        viewportHeight: window.innerHeight,
      };
    });
    expect(layout.brandRatio).toBeCloseTo(0.25, 2);
    expect(layout.formRatio).toBeCloseTo(0.75, 2);
    expect(layout.cardTop).toBeGreaterThanOrEqual(0);
    expect(layout.cardBottom).toBeLessThanOrEqual(layout.viewportHeight);
    expect(layout.documentHeight).toBeLessThanOrEqual(layout.viewportHeight);

    await expect(page.getByRole('radio', { name: /^Password/ })).toBeChecked();
    await expect(page.getByRole('radio', { name: /^Email OTP/ })).toBeVisible();
    await expect(page.getByRole('radio', { name: /^Google Authenticator/ })).toBeVisible();
    await expect(page.getByLabel('Password', { exact: true })).toBeVisible();
    await expect(page.getByText(/Try a demo role/i)).toHaveCount(0);
    await expect(page.getByText(/password:\s*prototype/i)).toHaveCount(0);
    await expect(page.getByLabel('Email', { exact: true })).toHaveValue('');

    await page.getByRole('radio', { name: /^Email OTP/ }).check();
    await expect(page.getByLabel('Password', { exact: true })).toHaveCount(0);
    await expect(page.getByRole('button', { name: 'Send email code' })).toBeEnabled();

    await page.getByRole('radio', { name: /^Google Authenticator/ }).check();
    await expect(page.getByLabel('Password', { exact: true })).toHaveCount(0);
    await expect(page.getByRole('button', { name: 'Continue with authenticator' })).toBeEnabled();
  });

  test('passwordless methods reject an unregistered email with organisation-admin guidance', async ({ page }) => {
    await openLogin(page);
    await page.getByLabel('Email', { exact: true }).fill('not.registered@qtfoods.test');

    for (const method of [/^Email OTP/, /^Google Authenticator/]) {
      await page.getByRole('radio', { name: method }).check();
      await page.getByRole('button', { name: method.source.includes('Email') ? 'Send email code' : 'Continue with authenticator' }).click();
      await expect(page.getByRole('alert')).toHaveText(
        'This email is not registered. Contact your organisation administrator to register it.',
      );
      await expect(page.getByRole('heading', { name: 'Welcome back' })).toBeVisible();
    }

    await page.getByRole('radio', { name: /^Password/ }).check();
    await expect(page.getByRole('alert')).toHaveCount(0);
    await expect(page.getByLabel('Password', { exact: true })).toBeVisible();
  });

  test('registered user completes passwordless Email OTP sign-in', async ({ page }) => {
    await openLogin(page);
    await page.getByLabel('Email', { exact: true }).fill('finance.user@qtfoods.local');
    await page.getByRole('radio', { name: /^Email OTP/ }).check();
    await page.getByRole('button', { name: 'Send email code' }).click();

    await expect(page.getByRole('heading', { name: 'Check your email' })).toBeVisible();
    await completePreviewEmailCode(page);
    await expect(page.getByRole('heading', { name: 'Choose where you are working' })).toBeVisible();
  });

  test('unregistered TOTP requires email proof before showing a QR and then signs in', async ({ page }) => {
    await openLogin(page);
    await page.getByLabel('Email', { exact: true }).fill('bi.user@qtfoods.local');
    await page.getByRole('radio', { name: /^Google Authenticator/ }).check();
    await page.getByRole('button', { name: 'Continue with authenticator' }).click();

    await expect(page.getByRole('heading', { name: 'Verify your registered email' })).toBeVisible();
    await expect(page.getByRole('img', { name: /QR code/ })).toHaveCount(0);
    await completePreviewEmailCode(page);

    await expect(page.getByRole('heading', { name: 'Register Google Authenticator' })).toBeVisible();
    await expect(page.getByRole('img', { name: /QR code for registering/ })).toBeVisible();
    await page.getByText('Can’t scan the QR code?').click();
    const secret = (await page.locator('.totp-enrolment details code').textContent())?.trim();
    expect(secret).toBeTruthy();
    await page.getByLabel('Six-digit Google Authenticator code').fill(totp(secret!));
    await page.getByRole('button', { name: 'Register and sign in' }).click();
    await expect(page.getByRole('heading', { name: 'Choose where you are working' })).toBeVisible();
  });

  test('privileged password verification cannot establish a session before MFA', async ({ page }) => {
    await openLogin(page);
    await page.getByLabel('Email', { exact: true }).fill('admin.user@qtfoods.local');
    await page.getByLabel('Password', { exact: true }).fill('prototype');
    await page.getByRole('button', { name: 'Continue with password' }).click();

    await expect(page.getByRole('heading', { name: 'Two-step verification required' })).toBeVisible();
    await expect(page.getByText('Password verification alone cannot sign you in.')).toBeVisible();
    expect((await page.request.get('/api/v1/me')).status()).toBe(401);

    await page.getByRole('button', { name: /^Email one-time code/ }).click();
    expect((await page.request.get('/api/v1/me')).status()).toBe(401);
    await completePreviewEmailCode(page);

    await expect(page.getByRole('heading', { name: 'Choose where you are working' })).toBeVisible();
    expect((await page.request.get('/api/v1/me')).status()).toBe(200);
  });
});

test('audited register layout and design contract hold at 1920, 1366, 768 and 390 pixels', async ({ page }) => {
  await page.setViewportSize({ width: 1920, height: 1000 });
  await signInAndSelect(page, 'demo.user@qtfoods.local');
  await createAcceptanceSalesOrder(page);
  await page.getByRole('navigation', { name: 'Main menu' }).locator('[data-screen-code="CRM-ORDER"]').click();
  await expect(page.getByRole('heading', { name: 'Sales Orders' })).toBeVisible();
  await expect(page.locator('.p2-register-panel')).toBeVisible();
  await expect(page.getByText('Ctrl K', { exact: true })).toHaveCount(1);
  expect(await page.getByRole('navigation', { name: 'Main menu' }).locator('svg.nav-icon').count()).toBeGreaterThan(0);
  await expect(page.getByRole('button', { name: 'Account security for Demo Sales Manager' })).toHaveAttribute('title', /Demo Sales Manager · Sales Manager/);
  await expect(page.locator('.business-guidance')).toHaveCSS('background-color', 'rgb(255, 255, 255)');
  await expect(page.locator('.p2-register tbody .status-neutral').first()).toHaveText('Draft');
  const formattedTotal = page.locator('.p2-register tbody td.numeric-cell').first();
  await expect(formattedTotal).toHaveText('₹224.20');
  await expect(formattedTotal).toHaveCSS('white-space', 'nowrap');

  const registerWidth = await page.locator('.p2-register-panel').evaluate((element) => element.getBoundingClientRect().width);
  const contentWidth = await page.locator('.content').evaluate((element) => element.getBoundingClientRect().width);
  expect(registerWidth / contentWidth).toBeGreaterThan(0.94);
  await expect(page.locator('.p2-drawer')).toHaveCount(0);

  const tableContract = await page.locator('.p2-register').evaluate((table) => {
    const first = getComputedStyle(table.querySelector('th:first-child')!);
    const last = getComputedStyle(table.querySelector('th:last-child')!);
    const wrap = getComputedStyle(table.parentElement!);
    return { first: first.position, firstLeft: first.left, last: last.position, lastRight: last.right, overflow: wrap.overflowX };
  });
  expect(tableContract).toEqual({ first: 'sticky', firstLeft: '0px', last: 'sticky', lastRight: '0px', overflow: 'auto' });

  await page.locator('.p2-register tbody').getByRole('button', { name: 'Open' }).first().click();
  const recordDrawer = page.getByRole('dialog', { name: 'Record details' });
  const desktopDrawer = await recordDrawer.evaluate((element) => element.getBoundingClientRect().width);
  expect(desktopDrawer).toBeGreaterThanOrEqual(480);
  expect(desktopDrawer).toBeLessThanOrEqual(640);
  await expect(recordDrawer.getByText('North Market Distributor', { exact: true }).first()).toBeVisible();
  await expect(recordDrawer.locator('.technical-details').getByText('00000000-0000-4000-8000-000000000201', { exact: true }).first()).toBeHidden();
  await page.getByRole('button', { name: 'Close record details' }).click();

  await page.getByRole('button', { name: '+ New', exact: true }).click();
  expect(await page.locator('.p2-entry-grid:not(.nested)').evaluate((element) => getComputedStyle(element).gridTemplateColumns.trim().split(/\s+/).length)).toBe(2);
  await expect(page.locator('.sticky-form-actions')).toHaveCSS('position', 'sticky');
  await page.getByRole('button', { name: 'Close', exact: true }).click();

  // 683 CSS pixels reproduces the layout viewport of a 1366px browser at 200% zoom.
  for (const width of [1920, 1366, 768, 683, 390]) {
    await page.setViewportSize({ width, height: width <= 768 ? 844 : 900 });
    await expect(page.getByRole('heading', { name: 'Sales Orders' })).toBeVisible();
    const visual = await page.evaluate(() => {
      const heading = getComputedStyle(document.querySelector('h1')!);
      const body = getComputedStyle(document.body);
      const panel = getComputedStyle(document.querySelector('.panel')!);
      const search = getComputedStyle(document.querySelector('.p2-register-panel input')!);
      return {
        documentWidth: document.documentElement.scrollWidth,
        viewportWidth: window.innerWidth,
        bodyFont: body.fontSize,
        bodyLine: body.lineHeight,
        bodyBackground: body.backgroundColor,
        headingFont: heading.fontSize,
        headingLine: heading.lineHeight,
        panelBackground: panel.backgroundColor,
        panelBorder: panel.borderTopColor,
        panelRadius: panel.borderTopLeftRadius,
        inputFont: search.fontSize,
        inputHeight: Number.parseFloat(search.height),
        tableFont: getComputedStyle(document.querySelector('.p2-register td')!).fontSize,
      };
    });
    expect(visual.documentWidth).toBeLessThanOrEqual(visual.viewportWidth);
    expect(visual.bodyFont).toBe('16px');
    expect(visual.bodyLine).toBe('24px');
    expect(visual.bodyBackground).toBe('rgb(246, 248, 250)');
    expect(visual.headingFont).toBe('28px');
    expect(visual.headingLine).toBe('36px');
    expect(visual.panelBackground).toBe('rgb(255, 255, 255)');
    expect(visual.panelBorder).toBe('rgb(221, 229, 225)');
    expect(visual.panelRadius).toBe('10px');
    expect(visual.tableFont).toBe('14px');
    if (width <= 768) {
      expect(visual.inputFont).toBe('16px');
      expect(visual.inputHeight).toBeGreaterThanOrEqual(44);
    }
  }

  await page.setViewportSize({ width: 390, height: 844 });
  await page.getByRole('button', { name: '+ New', exact: true }).click();
  const mobileDrawer = await page.locator('.p2-drawer').evaluate((element) => element.getBoundingClientRect().width);
  expect(Math.abs(mobileDrawer - 390)).toBeLessThanOrEqual(1);
  expect(await page.locator('.p2-entry-grid:not(.nested)').evaluate((element) => getComputedStyle(element).gridTemplateColumns.trim().split(/\s+/).length)).toBe(1);
  await expect(page.locator('.sticky-form-actions')).toHaveCSS('position', 'sticky');
});

async function openLogin(page: Page): Promise<void> {
  await page.goto('/');
  await expect(page.getByRole('heading', { name: 'Welcome back' })).toBeVisible();
}

async function completePreviewEmailCode(page: Page): Promise<void> {
  const preview = page.locator('.development-preview code');
  await expect(preview).toBeVisible();
  await page.getByLabel('Six-digit email code').fill((await preview.textContent())?.trim() ?? '');
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
  const counterBytes = Buffer.alloc(8);
  counterBytes.writeBigUInt64BE(BigInt(Math.floor(Date.now() / 30_000)));
  const digest = createHmac('sha1', Buffer.from(bytes)).update(counterBytes).digest();
  const offset = digest[digest.length - 1] & 0x0f;
  const value = ((digest[offset] & 0x7f) << 24)
    | ((digest[offset + 1] & 0xff) << 16)
    | ((digest[offset + 2] & 0xff) << 8)
    | (digest[offset + 3] & 0xff);
  return String(value % 1_000_000).padStart(6, '0');
}

async function createAcceptanceSalesOrder(page: Page): Promise<void> {
  const csrf = await page.evaluate(async () => {
    const response = await fetch('/api/v1/auth/csrf', { credentials: 'include', headers: { Accept: 'application/json' } });
    return (await response.json()).data.csrf_token as string;
  });
  const result = await page.evaluate(async ({ token, idempotencyKey, orderNumber }) => {
    const response = await fetch('/api/v1/sales/orders', {
      method: 'POST',
      credentials: 'include',
      headers: {
        Accept: 'application/json',
        'Content-Type': 'application/json',
        'X-CSRF-TOKEN': token,
        'Idempotency-Key': idempotencyKey,
      },
      body: JSON.stringify({
        order_number: orderNumber,
        customer_party_id: '00000000-0000-4000-8000-000000000501',
        sales_lead_id: null,
        sales_contract_id: '00000000-0000-4000-8000-000000002501',
        sales_price_list_id: null,
        order_date: new Date().toISOString().slice(0, 10),
        requested_delivery_date: new Date(Date.now() + 4 * 86_400_000).toISOString().slice(0, 10),
        notes: 'Responsive acceptance-test order.',
        lines: [{
          item_id: '00000000-0000-4000-8000-000000000601',
          uom_code: 'PACK',
          quantity: '2',
          discount_percent: '0',
        }],
      }),
    });
    return { status: response.status, body: await response.json() };
  }, { token: csrf, idempotencyKey: randomUUID(), orderNumber: `AUDIT-RESP-${Date.now()}` });
  expect(result.status, JSON.stringify(result.body)).toBe(201);
}
