import { mkdir } from 'node:fs/promises';
import { resolve } from 'node:path';
import { chromium } from '@playwright/test';

const baseUrl = process.env.QT_MANUAL_BASE_URL ?? 'http://127.0.0.1:5173';
const outputDirectory = resolve(process.cwd(), '../docs/user-manual/screenshots');

await mkdir(outputDirectory, { recursive: true });

const browser = await chromium.launch({ headless: true });

try {
  await captureEntryFlow();
  await captureRole('operations.user@qtfoods.local', [
    ['WRK-HOME', 'operations-home', 'shell'],
    ['PUR-REQ', 'operations-purchase-requisitions', 'main'],
    ['RET-UNSOLD', 'operations-unsold-returns', 'main'],
    ['PLAN-SCH', 'operations-production-schedule', 'main'],
    ['PRO-ORDER', 'operations-production-orders', 'main'],
    ['ENG-MNT', 'operations-maintenance', 'main'],
    ['SCALE-PLANT', 'operations-multi-plant', 'main'],
    ['OPT-PLAN', 'operations-planning-recommendations', 'main'],
  ]);
  await captureRole('finance.user@qtfoods.local', [
    ['WRK-HOME', 'finance-home', 'shell'],
    ['PUR-REQ', 'finance-purchase-approval', 'main'],
    ['RET-UNSOLD', 'finance-unsold-returns', 'main'],
    ['FIN-AP', 'finance-payables', 'main'],
    ['FIN-GL', 'finance-general-ledger', 'main'],
    ['ENG-MNT', 'finance-maintenance', 'main'],
    ['SCALE-PLANT', 'finance-multi-plant', 'main'],
    ['OPT-PLAN', 'finance-planning-recommendations', 'main'],
  ]);
  await captureRole('admin.user@qtfoods.local', [
    ['ADM-USER', 'admin-users', 'main'],
    ['ADM-ROLE', 'admin-roles-access', 'main'],
  ]);
  await captureRole('demo.user@qtfoods.local', [
    ['CRM-ORDER', 'sales-orders', 'shell'],
  ]);
  await captureRole('partner.user@qtfoods.local', [
    ['PORTAL-EXT', 'partner-portal', 'shell'],
  ]);
} finally {
  await browser.close();
}

async function newPage() {
  const context = await browser.newContext({
    colorScheme: 'light',
    deviceScaleFactor: 1.5,
    reducedMotion: 'reduce',
    viewport: { width: 1280, height: 820 },
  });
  const page = await context.newPage();
  await page.addInitScript(() => {
    window.localStorage.clear();
    window.sessionStorage.clear();
  });
  return { context, page };
}

async function captureEntryFlow() {
  const { context, page } = await newPage();
  try {
    await page.goto(baseUrl, { waitUntil: 'networkidle' });
    await page.getByRole('heading', { name: 'Welcome back' }).waitFor();
    await stableScreenshot(page, 'sign-in', 'page');

    await page.getByLabel('Email').fill('admin.user@qtfoods.local');
    await page.getByLabel('Password').fill('prototype');
    await page.getByRole('button', { name: 'Sign in' }).click();
    await page.getByRole('heading', { name: 'Choose where you are working' }).waitFor();
    await stableScreenshot(page, 'choose-workplace', 'page');
  } finally {
    await context.close();
  }
}

async function captureRole(email, captures) {
  const { context, page } = await newPage();
  try {
    await page.goto(baseUrl, { waitUntil: 'networkidle' });
    await page.getByLabel('Email').fill(email);
    await page.getByLabel('Password').fill('prototype');
    await page.getByRole('button', { name: 'Sign in' }).click();
    await page.getByRole('heading', { name: 'Choose where you are working' }).waitFor();
    await page.getByRole('button', { name: /Training Plant/ }).click();
    await page.locator('.page-head[data-screen-code]').waitFor();

    for (const [screenCode, filename, target] of captures) {
      await openScreen(page, screenCode);
      await stableScreenshot(page, filename, target);
    }
  } finally {
    await context.close();
  }
}

async function openScreen(page, screenCode) {
  await page.evaluate((code) => {
    window.location.hash = code;
  }, screenCode);
  await page.locator(`.page-head[data-screen-code="${screenCode}"]`).waitFor();
  await page.waitForLoadState('networkidle', { timeout: 12_000 }).catch(() => undefined);
  await page.waitForTimeout(500);
  await page.locator('.content').evaluate((element) => {
    element.scrollTop = 0;
  });
}

async function stableScreenshot(page, filename, target) {
  await page.addStyleTag({
    content: `
      *, *::before, *::after { animation: none !important; transition: none !important; caret-color: transparent !important; }
      .content { scrollbar-color: #b6c5cb transparent; }
    `,
  });
  const path = resolve(outputDirectory, `${filename}.png`);
  if (target === 'shell') {
    await page.locator('.app-shell').screenshot({ path });
  } else if (target === 'main') {
    await page.locator('.main').screenshot({ path });
  } else {
    await page.screenshot({ path, fullPage: false });
  }
  console.log(path);
}
