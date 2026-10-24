import { expect, test } from '@playwright/test';
import { mkdirSync } from 'node:fs';
import { resolve } from 'node:path';

const artifactDirectory = resolve(process.cwd(), '../artifacts/expandable-registers');

test('expandable item, classification, UOM and edit-card layouts', async ({ page }) => {
  mkdirSync(artifactDirectory, { recursive: true });
  await mockReadOnlyErp(page);
  await page.setViewportSize({ width: 1440, height: 1000 });
  await page.goto('/#MD-ITEM?status=ACTIVE&type=RAW_MATERIAL&sort=NAME&page=2');

  const itemRegister = page.getByRole('table', { name: 'Items register' });
  await expect(itemRegister).toBeVisible();
  await itemRegister.getByRole('button', { name: 'Expand RM-FLOUR Wholemeal Flour' }).click();
  await expect(itemRegister.getByText('90 days shelf life')).toBeVisible();
  await page.locator('.product-master-workspace > section').first().screenshot({
    path: resolve(artifactDirectory, 'items-expanded-desktop.png'),
  });

  await itemRegister.getByRole('button', { name: 'Open edit card' }).click();
  await expect(page.getByLabel('Item name')).toHaveValue('Wholemeal Flour');
  await expect(page).toHaveURL(/#MD-ITEM\?.*id=item-raw-1/);
  await page.evaluate(() => window.scrollBy(0, -460));
  await page.screenshot({
    path: resolve(artifactDirectory, 'item-edit-card-below-register.png'),
  });

  await page.getByRole('button', { name: 'Back to register' }).click();
  await expect(page.getByLabel('Filter item status')).toHaveValue('ACTIVE');
  await expect(page.getByLabel('Filter item type')).toHaveValue('RAW_MATERIAL');
  await page.setViewportSize({ width: 390, height: 844 });

  const uomRegister = page.getByRole('table', { name: 'UOM reference register' });
  await uomRegister.getByRole('button', { name: 'Expand LTR Litre' }).click();
  await expect(uomRegister.getByText(/migration-controlled/i)).toBeVisible();
  await uomRegister.scrollIntoViewIfNeeded();
  await page.addStyleTag({ content: '.topbar,.skip-link{visibility:hidden!important}' });
  await uomRegister.locator('.expandable-register-record.is-expanded').screenshot({
    path: resolve(artifactDirectory, 'uom-expanded-mobile.png'),
  });
});

async function mockReadOnlyErp(page: import('@playwright/test').Page) {
  await page.route('**/api/v1/**', async (route) => {
    const requestUrl = new URL(route.request().url());
    const path = requestUrl.pathname;

    if (path === '/api/v1/me') {
      await route.fulfill({ json: { data: session } });
      return;
    }
    if (path === '/api/v1/master/items') {
      await route.fulfill({ json: workspace });
      return;
    }
    if (path === '/api/v1/master/items/item-raw-1') {
      await route.fulfill({ json: { data: itemDetail } });
      return;
    }
    await route.fulfill({ status: 404, json: { error: { code: 'NOT_FOUND', message: `No visual fixture for ${path}` } } });
  });
}

const now = '2026-10-14T09:30:00.000Z';
const itemRecord = {
  id: 'item-raw-1', company_id: 'company-1', code: 'RM-FLOUR', name: 'Wholemeal Flour',
  status: 'ACTIVE', record_version: 7, child_count: 2, child_label: 'conversions',
  parent: { code: 'QTF-CORE', name: 'Q&T Core Foods' }, status_change: {
    reason: 'Approved for production', changed_at: now,
    changed_by: { id: 'checker-1', name: 'Quality Checker' },
  },
  allowed_actions: ['UPDATE', 'CHANGE_STATUS'], allowed_statuses: ['INACTIVE'],
  created_at: '2026-08-01T08:00:00.000Z', updated_at: now,
  brand_id: 'brand-1', item_type: 'RAW_MATERIAL', base_uom: 'KG',
  description: 'Production flour governed by lot and shelf-life controls.', shelf_life_days: 90,
  lot_controlled: true,
};

const itemDetail = {
  ...itemRecord,
  conversions: [
    { id: 'conversion-1', from_uom_code: 'KG', to_uom_code: 'G', multiplier: '1000.000000000', rounding_mode: 'HALF_UP' },
    { id: 'conversion-2', from_uom_code: 'KG', to_uom_code: 'PACK', multiplier: '2.000000000', rounding_mode: 'HALF_UP' },
  ],
};

const workspace = {
  resource: 'items',
  data: [
    itemRecord,
    {
      ...itemRecord,
      id: 'item-fg-1', code: 'FG-BISCUIT', name: 'Family Biscuit', item_type: 'FINISHED_GOOD',
      base_uom: 'PACK', child_count: 1, record_version: 4, parent: { code: 'QTF-RETAIL', name: 'Q&T Retail' },
    },
  ],
  meta: { current_page: 2, last_page: 3, per_page: 25, total: 52 },
  summary: { total: 52, draft: 4, active: 45, inactive: 3 },
  lookups: {
    statuses: ['DRAFT', 'ACTIVE', 'INACTIVE'],
    item_types: ['RAW_MATERIAL', 'PACKAGING', 'INTERMEDIATE', 'FINISHED_GOOD', 'SERVICE'],
    agreement_types: ['LICENSE', 'DISTRIBUTION', 'MANUFACTURING', 'SUPPLY'],
    agreement_statuses: ['DRAFT', 'ACTIVE', 'EXPIRED', 'TERMINATED'],
    rounding_modes: ['HALF_UP', 'UP', 'DOWN', 'NONE'],
    spec_target_types: ['ITEM', 'SKU'], spec_value_types: ['NUMERIC', 'TEXT', 'BOOLEAN'],
    uoms: [
      { code: 'EA', name: 'Each', precision: 0 },
      { code: 'KG', name: 'Kilogram', precision: 6 },
      { code: 'G', name: 'Gram', precision: 3 },
      { code: 'PCS', name: 'Pieces', precision: 0 },
      { code: 'LTR', name: 'Litre', precision: 3 },
      { code: 'PACK', name: 'Pack', precision: 0 },
    ],
    brands: [{ id: 'brand-1', code: 'QTF-CORE', name: 'Q&T Core Foods', status: 'ACTIVE' }],
    items: [{ id: 'item-raw-1', code: 'RM-FLOUR', name: 'Wholemeal Flour', status: 'ACTIVE' }],
    skus: [], parties: [],
  },
  allowed_actions: ['CREATE'],
};

const session = {
  user: { id: 'erp-admin-1', name: 'ERP Administrator', email: 'admin@qtfoods.local' },
  security: {
    email_verified: true, mfa_enabled: true, mfa_required: true, password_change_required: false,
    password_changed_at: now, last_login_at: now, current_session_id: 'session-1',
  },
  roles: ['ERP_ADMIN'],
  allowed_screens: ['MD-ITEM'],
  allowed_actions: ['ACTION:MD-ITEM:CREATE', 'ACTION:MD-ITEM:UPDATE', 'ACTION:MD-ITEM:LIFECYCLE'],
  contexts: [{ company_id: 'company-1', company_name: 'Q & T FOODS LTD', plant_id: 'plant-1', plant_name: 'Main Plant' }],
  selected_context: { company_id: 'company-1', company_name: 'Q & T FOODS LTD', plant_id: 'plant-1', plant_name: 'Main Plant' },
};
