import { readFile, stat } from 'node:fs/promises';
import { resolve } from 'node:path';
import { pathToFileURL } from 'node:url';
import { chromium } from '@playwright/test';

const source = resolve(process.cwd(), '../docs/user-manual/index.html');
const output = resolve(process.cwd(), '../docs/QT_FOODS_ERP_ROLES_PERMISSIONS_USER_MANUAL.pdf');

const browser = await chromium.launch({ headless: true });

try {
  const page = await browser.newPage({ viewport: { width: 1070, height: 900 } });
  const pageErrors = [];
  page.on('pageerror', (error) => pageErrors.push(error.message));

  await page.goto(pathToFileURL(source).href, { waitUntil: 'networkidle' });
  await page.emulateMedia({ media: 'print' });
  await page.evaluate(async () => {
    await document.fonts.ready;
    await Promise.all([...document.images].map((image) => image.decode()));
  });

  const validation = await page.evaluate(() => ({
    sheets: document.querySelectorAll('.sheet').length,
    matrixRows: [...document.querySelectorAll('table[id^="matrix-"] tbody')]
      .reduce((total, body) => total + body.rows.length, 0),
    images: document.images.length,
    brokenImages: [...document.images].filter((image) => !image.complete || image.naturalWidth === 0)
      .map((image) => image.getAttribute('src')),
    tallestSheet: Math.max(...[...document.querySelectorAll('.sheet')]
      .map((sheet) => Math.round(sheet.getBoundingClientRect().height))),
  }));

  if (pageErrors.length) throw new Error(`Manual page errors: ${pageErrors.join('; ')}`);
  if (validation.sheets !== 45) throw new Error(`Expected 45 manual sheets, found ${validation.sheets}.`);
  if (validation.matrixRows !== 69) throw new Error(`Expected 69 access-matrix rows, found ${validation.matrixRows}.`);
  if (validation.images !== 22 || validation.brokenImages.length) {
    throw new Error(`Screenshot validation failed: ${JSON.stringify(validation)}`);
  }
  if (validation.tallestSheet > 730) {
    throw new Error(`A manual sheet is too tall for the print area (${validation.tallestSheet}px).`);
  }

  await page.pdf({
    path: output,
    format: 'A4',
    landscape: true,
    printBackground: true,
    displayHeaderFooter: true,
    headerTemplate: '<div></div>',
    footerTemplate: `
      <div style="box-sizing:border-box;width:100%;padding:0 11mm;color:#6b7c88;font-family:Segoe UI,Arial,sans-serif;font-size:7px;display:flex;justify-content:space-between;align-items:center;">
        <span>Q &amp; T Foods ERP · Roles, Permissions &amp; User Manual</span>
        <span><span class="pageNumber"></span> / <span class="totalPages"></span></span>
      </div>`,
    margin: { top: '7mm', right: '7mm', bottom: '10mm', left: '7mm' },
    tagged: true,
    outline: true,
  });

  const signature = (await readFile(output)).subarray(0, 5).toString('ascii');
  if (signature !== '%PDF-') throw new Error(`Output does not have a PDF signature: ${signature}`);

  const details = await stat(output);
  console.log(JSON.stringify({ output, bytes: details.size, ...validation }, null, 2));
} finally {
  await browser.close();
}
