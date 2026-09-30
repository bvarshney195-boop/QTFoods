import { expect, test, type Dialog, type Locator, type Page } from '@playwright/test';

test('manufacturing executes a planned batch through quality, packing, cost, genealogy, and recall', async ({ page }) => {
  test.setTimeout(420_000);
  await loginAndSelect(page, 'operations.user@qtfoods.local');
  const navigation = page.getByRole('navigation', { name: 'Main menu' });
  const editor = page.locator('.requisition-editor');

  await navigation.locator('[data-screen-code="PLAN-DEM"]').click();
  await page.getByRole('button', { name: '+ New' }).click();
  await editor.getByLabel('Plan number').fill('E2E-MFG-DEMAND-001');
  await editor.getByLabel('Plan name').fill('E2E manufacturing execution demand');
  await editor.getByLabel('Horizon start').fill('2026-09-13');
  await editor.getByLabel('Horizon end').fill('2026-09-27');
  await selectByText(editor.getByLabel('Demand line 1 output SKU'), 'SKU-APPLE-100');
  await editor.getByLabel('Demand date').fill('2026-09-20');
  await editor.getByLabel('Demand line 1 quantity').fill('100');
  await editor.getByRole('button', { name: 'Create demand plan' }).click();
  await expect(editor.getByRole('status')).toContainText('Draft demand plan created');
  await editor.getByRole('button', { name: 'Release to MRP' }).click();
  await expect(editor.getByRole('status')).toContainText('Demand released');

  await navigation.locator('[data-screen-code="PLAN-MRP"]').click();
  await page.getByRole('button', { name: '+ New' }).click();
  await editor.getByLabel('MRP run number').fill('E2E-MFG-MRP-001');
  await selectByText(editor.getByLabel('Released demand plan'), 'E2E-MFG-DEMAND-001');
  await editor.getByLabel('Planning date').fill('2026-09-13');
  await editor.getByRole('button', { name: 'Run MRP' }).click();
  await expect(editor.getByRole('status')).toContainText('MRP completed');

  await navigation.locator('[data-screen-code="PLAN-SCH"]').click();
  await page.getByRole('button', { name: '+ New' }).click();
  await selectByText(editor.getByLabel('Completed MRP run'), 'E2E-MFG-MRP-001');
  await editor.getByLabel('Schedule number').fill('E2E-MFG-SCH-001');
  await editor.getByLabel('Horizon start').fill('2026-09-14');
  await editor.getByLabel('Horizon end').fill('2026-09-20');
  await editor.getByRole('button', { name: 'Create schedule' }).click();
  await expect(editor.getByRole('status')).toContainText('Capacity schedule created');
  await editor.getByRole('button', { name: 'Release and reserve' }).click();
  await expect(editor.getByRole('status')).toContainText('reserved by FEFO');

  await navigation.locator('[data-screen-code="PRO-ORDER"]').click();
  await expect(page.getByRole('heading', { name: 'Production Orders' })).toBeVisible();
  await page.getByRole('button', { name: '+ New' }).click();
  await editor.getByLabel('Order number').fill('E2E-MFG-PRO-001');
  await editor.getByLabel('Batch number').fill('E2E-MFG-BATCH-001');
  await selectByText(editor.getByLabel('Released schedule line'), 'E2E-MFG-SCH-001');
  await editor.getByRole('button', { name: 'Create production order' }).click();
  await expect(editor.getByRole('status')).toContainText('Draft production order created');
  await editor.getByRole('button', { name: 'Release order' }).click();
  await expect(editor.getByRole('status')).toContainText('Production order released');
  await editor.getByRole('button', { name: 'Issue reserved materials' }).click();
  await expect(editor.getByRole('status')).toContainText('Reserved material issued by FEFO');
  await expect(editor).toContainText('RM-APPLE-2609A');

  await navigation.locator('[data-screen-code="PRO-STAGE"]').click();
  await page.getByLabel('Stage Execution search').fill('E2E-MFG-PRO-001');
  for (const center of ['MIX-01', 'OVEN-01', 'PACK-01']) {
    await openRow(page, center);
    await editor.getByRole('button', { name: 'Start stage' }).click();
    await expect(editor.getByRole('status')).toContainText('Stage started');
    await editor.getByLabel('Actual minutes').fill(center === 'OVEN-01' ? '32' : '22');
    await editor.getByLabel('Completion note').fill(`${center} execution verified.`);
    await editor.getByRole('button', { name: 'Complete stage' }).click();
    await expect(editor.getByRole('status')).toContainText('Stage completed with actual time');
  }

  await navigation.locator('[data-screen-code="PRO-LOSS"]').click();
  await page.getByLabel('Yield / Loss / Rework search').fill('E2E-MFG-PRO-001');
  await openRow(page, 'E2E-MFG-PRO-001');
  await recordOutput(editor, 'GOOD', '95');
  await recordOutput(editor, 'LOSS', '3', 'BAKE-LOSS');
  await recordOutput(editor, 'REWORK', '2', 'SEAL-REWORK');
  page.once('dialog', (dialog) => dialog.accept('Re-seal verification passed.'));
  await editor.getByRole('button', { name: 'Recover' }).click();
  await expect(editor.getByRole('status')).toContainText('Rework recovered');
  await expect(editor).toContainText('97');

  await navigation.locator('[data-screen-code="PRO-ORDER"]').click();
  await page.getByLabel('Production Orders search').fill('E2E-MFG-PRO-001');
  await openRow(page, 'E2E-MFG-PRO-001');
  await editor.getByRole('button', { name: 'Complete order' }).click();
  await expect(editor.getByRole('status')).toContainText('Production order completed');

  await navigation.locator('[data-screen-code="QC-LAB"]').click();
  await page.getByRole('button', { name: '+ New' }).click();
  await editor.getByLabel('Sample number').fill('E2E-MFG-LAB-001');
  await selectByText(editor.getByLabel('Completed production order'), 'E2E-MFG-PRO-001');
  await selectByText(editor.getByLabel('Specification'), 'SPEC-APPLE-100');
  await editor.getByRole('button', { name: 'Create lab sample' }).click();
  await expect(editor.getByRole('status')).toContainText('immutable specification snapshot');
  await editor.getByLabel('NET-WEIGHT result').fill('100.2');
  await editor.getByLabel('NET-WEIGHT notes').fill('Within the release specification.');
  await editor.getByRole('button', { name: 'Complete and evaluate sample' }).click();
  await expect(editor.getByRole('status')).toContainText('Sample completed');
  await expect(editor.locator('.status').filter({ hasText: /^Passed$/ })).toBeVisible();

  await navigation.locator('[data-screen-code="QC-SAFE"]').click();
  await page.getByRole('button', { name: '+ New' }).click();
  await editor.getByLabel('Hold number').fill('E2E-MFG-HOLD-001');
  await selectByText(editor.getByLabel('Production batch for hold'), 'E2E-MFG-PRO-001');
  await editor.getByLabel('Hold reason').fill('Allergen clean-down sign-off awaiting QA verification.');
  await editor.getByRole('button', { name: 'Place food-safety hold' }).click();
  await expect(editor.getByRole('status')).toContainText('batch quality blocked');
  const holdResponses = [
    'QA verified the signed clean-down checklist.',
    'The clean-down record was awaiting QA verification.',
    'ACCEPTED',
  ];
  const answerHoldDialog = async (dialog: Dialog) => {
    const response = holdResponses.shift();
    if (response === undefined) throw new Error(`Unexpected hold-release prompt: ${dialog.message()}`);
    await dialog.accept(response);
  };
  page.on('dialog', answerHoldDialog);
  await editor.getByRole('button', { name: 'Release hold' }).click();
  await expect(editor.getByRole('status')).toContainText('Food-safety hold released');
  page.off('dialog', answerHoldDialog);
  expect(holdResponses).toEqual([]);
  await selectByText(editor.getByLabel('Batch quality release'), 'E2E-MFG-PRO-001');
  await editor.getByRole('button', { name: 'Release batch quality' }).click();
  await expect(editor.getByRole('status')).toContainText('passed the lab');

  await navigation.locator('[data-screen-code="PACK-ART"]').click();
  await page.getByRole('button', { name: '+ New' }).click();
  await selectByText(editor.getByLabel('Artwork finished SKU'), 'SKU-APPLE-100');
  await editor.getByLabel('Artwork code').fill('E2E-MFG-ART-001');
  await editor.getByLabel('Label name').fill('E2E apple snack retail label');
  await editor.getByLabel('Barcode').fill('8901000999001');
  await editor.getByRole('button', { name: 'Create artwork revision' }).click();
  await expect(editor.getByRole('status')).toContainText('Draft artwork revision created');
  await editor.getByRole('button', { name: 'Approve artwork' }).click();
  await expect(editor.getByRole('status')).toContainText('Artwork approved');

  await navigation.locator('[data-screen-code="PACK-RUN"]').click();
  await page.getByRole('button', { name: '+ New' }).click();
  await editor.getByLabel('Run number').fill('E2E-MFG-PACK-001');
  await editor.getByLabel('Finished lot code').fill('E2E-MFG-FG-001');
  await selectByText(editor.getByLabel('Quality-released batch'), 'E2E-MFG-PRO-001');
  await selectByText(editor.getByLabel('Approved artwork'), 'E2E-MFG-ART-001');
  await selectByText(editor.getByLabel('Finished-goods location'), 'FG-SALE');
  await editor.getByRole('button', { name: 'Create packing run' }).click();
  await expect(editor.getByRole('status')).toContainText('rendered lot coding');
  await editor.getByRole('button', { name: 'Complete and receive stock' }).click();
  await expect(editor.getByRole('status')).toContainText('finished stock and lot genealogy posted');
  await expect(editor).toContainText('RM-APPLE-2609A');

  await navigation.locator('[data-screen-code="FG-LOT"]').click();
  await page.getByLabel('Finished Goods search').fill('E2E-MFG-FG-001');
  await openRow(page, 'E2E-MFG-FG-001');
  await expect(editor).toContainText('97');
  await expect(editor).toContainText('RM-APPLE-2609A');

  await navigation.locator('[data-screen-code="COST-BATCH"]').click();
  await page.getByRole('button', { name: '+ New' }).click();
  await editor.getByLabel('Cost number').fill('E2E-MFG-COST-001');
  await selectByText(editor.getByLabel('Cost production batch'), 'E2E-MFG-PRO-001');
  await editor.getByLabel('Labour rate per minute').fill('2.5');
  await editor.getByLabel('Overhead rate per minute').fill('1.25');
  await editor.getByLabel('SKU-APPLE-BASE unit cost').fill('50');
  await editor.getByRole('button', { name: 'Finalize cost snapshot' }).click();
  await expect(editor.getByRole('status')).toContainText('Batch cost finalized');
  await expect(editor).toContainText('Material usage variance');
  await expect(editor).toContainText('Stage time variance');

  await navigation.locator('[data-screen-code="TRACE-CASE"]').click();
  await selectByText(editor.getByLabel('Traceable lot'), 'RM-APPLE-2609A');
  await editor.getByRole('button', { name: 'Trace lot' }).click();
  await expect(editor.getByRole('status')).toContainText('Lot genealogy and customer exposure refreshed');
  await expect(editor).toContainText('E2E-MFG-FG-001');
  await page.getByRole('button', { name: '+ New' }).click();
  await editor.getByLabel('Recall number').fill('E2E-MFG-RECALL-001');
  await selectByText(editor.getByLabel('Recall source lot'), 'E2E-MFG-FG-001');
  await editor.getByLabel('Recall reason').fill('Finished-lot coding review requires controlled market containment.');
  await editor.getByRole('button', { name: 'Open recall and block stock' }).click();
  await expect(editor.getByRole('status')).toContainText('on-hand stock blocked');
  await expect(editor).toContainText('E2E-MFG-FG-001');
  page.once('dialog', (dialog) => dialog.accept('All affected stock contained and notifications completed.'));
  await editor.getByRole('button', { name: 'Close recall case' }).click();
  await expect(editor.getByRole('status')).toContainText('Recall case closed');
});

async function recordOutput(editor: Locator, type: string, quantity: string, reason?: string) {
  await editor.getByLabel('Output type').selectOption(type);
  await editor.getByLabel('Output quantity').fill(quantity);
  if (reason) await editor.getByLabel('Reason code').fill(reason);
  await editor.getByRole('button', { name: 'Record output' }).click();
  await expect(editor.getByRole('status')).toContainText('Production output recorded');
}
async function openRow(page: Page, text: string) { const row = page.locator('.requisition-table tbody tr').filter({ hasText: text }); await expect(row).toBeVisible(); await row.getByRole('button', { name: 'Open' }).click(); }
async function selectByText(select: Locator, text: string) { const option = select.locator('option').filter({ hasText: text }); await select.selectOption(await option.getAttribute('value') ?? ''); }
async function loginAndSelect(page: Page, email: string) { await page.goto('/'); await page.getByLabel('Email').fill(email); await page.getByLabel('Password').fill('prototype'); await page.getByRole('button', { name: 'Sign in' }).click(); await page.getByRole('button', { name: /Training Plant/ }).click(); }
