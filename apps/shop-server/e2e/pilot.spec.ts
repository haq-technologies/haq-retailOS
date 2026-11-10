import { expect, test, type Browser, type BrowserContext, type Page } from '@playwright/test';
import { dirname, join } from 'node:path';
import { fileURLToPath } from 'node:url';

/*
 * The measured pilot in a real browser (Phase 5; spec section 14) [D56, D57]: the owner starts the 14
 * days, types the notebook's totals for today, traces each difference to the notebook, so the day matches
 * once traced, and downloads the shop's data; a manager traces but never starts, decides or exports. It runs after the counter tests (files run
 * in name order), so today has sales, and it records no decision, so the files after it are unchanged.
 */
const here = dirname(fileURLToPath(import.meta.url));
const open: BrowserContext[] = [];
test.afterEach(async () => {
    await Promise.all(open.splice(0).map((c) => c.close()));
});

async function backOffice(browser: Browser, name: string, pin: string): Promise<Page> {
    const context = await browser.newContext({ storageState: join(here, '.auth', 'D01.json') });
    open.push(context);
    const page = await context.newPage();
    await page.goto('/back-office#/pilot');
    await page.getByRole('button', { name: new RegExp(`^${name}`) }).click();
    await page.keyboard.type(pin, { delay: 60 });
    await page.getByRole('button', { name: 'Sign in' }).click();
    return page;
}

test('starts the 14 days, then the notebook goes in and each difference is traced, so today matches', async ({ browser }) => {
    const page = await backOffice(browser, 'Halima', '135790');
    await expect(page.getByRole('heading', { name: 'Measured pilot' })).toBeVisible();
    await page.getByRole('button', { name: 'Start the 14 days' }).click();
    await expect(page.getByText(/^Day 1 of 14/)).toBeVisible();
    await expect(page.getByRole('region', { name: 'Daily totals' })).toContainText('0 of 14 days match');

    await page.getByRole('button', { name: 'Open', exact: true }).first().click();
    const sheet = page.getByRole('dialog');
    await expect(sheet.getByText('Notebook not entered')).toBeVisible();
    for (const method of ['Cash', 'Transfer', 'POS']) await sheet.getByLabel(`Notebook ${method}`).fill('0');
    await sheet.getByRole('button', { name: /Save the notebook/ }).click();
    await expect(sheet.getByText('Still to trace')).toBeVisible();

    // Every sale today was rung but never written down: the notebook was wrong each time.
    for (let i = 0; i < 3; i++) {
        const trace = sheet.getByRole('button', { name: 'Trace', exact: true });
        if ((await trace.count()) === 0) break;
        await trace.first().click();
        await sheet.getByRole('radio', { name: /^The notebook/ }).check();
        await sheet.getByLabel('What happened').fill('Sales not written in the notebook');
        await sheet.getByRole('button', { name: 'Write it down' }).click();
        await expect(sheet.getByRole('button', { name: 'Write it down' })).toHaveCount(0);
    }
    await expect(sheet.getByText('Matches once traced')).toBeVisible();
    await expect(sheet.getByRole('heading', { name: 'Traced and written down' })).toBeVisible();
    await page.screenshot({ path: 'e2e/results/screens/pilot-day.png', fullPage: true });
    await page.keyboard.press('Escape');

    await expect(page.getByRole('region', { name: 'Daily totals' })).toContainText('1 of 14 days match');
    await expect(page.getByRole('heading', { name: "The owner's decision" })).toBeVisible();
    await page.screenshot({ path: 'e2e/results/screens/pilot.png', fullPage: true });

    // Continue or stop, the owner takes the whole record (D57).
    const download = page.waitForEvent('download');
    await page.getByRole('button', { name: /Download all the shop/ }).click();
    expect((await download).suggestedFilename()).toMatch(/^haq-retail-os-data-\d{4}-\d{2}-\d{2}\.zip$/);
    await expect(page.getByRole('status')).toContainText('Downloaded.');
});

test('lets a manager open the days but not start the 14 days or decide', async ({ browser }) => {
    const page = await backOffice(browser, 'Kemi', '246810');
    await expect(page.getByRole('heading', { name: 'Measured pilot' })).toBeVisible();
    await expect(page.getByRole('region', { name: 'Daily totals' })).toBeVisible();
    await expect(page.getByRole('button', { name: /Start the 14 days/ })).toHaveCount(0);
    await expect(page.getByRole('button', { name: 'Record the decision' })).toHaveCount(0);
    await expect(page.getByText('After the 14 days the owner decides here')).toBeVisible();
    await expect(page.getByRole('button', { name: /Download all the shop/ })).toHaveCount(0);
});
