import { expect, test } from '@playwright/test';
import { dirname, join } from 'node:path';
import { fileURLToPath } from 'node:url';

/*
 * Undoing a cost (spec section 2: every cost backfill is reversible) [D48]. It runs last (files run in
 * name order): the back-office tests entered a cost for the cable, which this test takes back.
 */
const here = dirname(fileURLToPath(import.meta.url));

test('lets the owner undo a cost, so its sales wait for a cost again', async ({ browser }) => {
    const context = await browser.newContext({ storageState: join(here, '.auth', 'D01.json') });
    const page = await context.newPage();
    await page.goto('/back-office');
    await page.getByRole('button', { name: /^Halima/ }).click();
    await page.keyboard.type('135790', { delay: 60 });
    await page.getByRole('button', { name: 'Sign in' }).click();
    await page.getByRole('link', { name: /Needs cost/ }).first().click();

    const recent = page.getByRole('region', { name: 'Costs entered lately' });
    const cable = recent.getByRole('listitem').filter({ hasText: 'Type-C to Lightning Cable 1m' }).first();
    await expect(cable).toContainText('₦1,200');
    await cable.getByRole('button', { name: 'Undo' }).click();
    const sheet = page.getByRole('dialog', { name: /^Undo the cost of/ });
    await expect(sheet).toContainText('wait for a cost again');
    await page.screenshot({ path: 'e2e/results/screens/undo-cost.png', fullPage: true });
    await sheet.getByRole('button', { name: 'Undo the cost' }).click();

    await expect(page.getByRole('status').filter({ hasText: 'is undone' })).toBeVisible();
    await expect(page.getByRole('region', { name: 'Items with no cost' }).getByText('Type-C to Lightning Cable 1m').first()).toBeVisible();
    await context.close();
});
