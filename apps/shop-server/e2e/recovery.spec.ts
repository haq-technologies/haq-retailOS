import { expect, test, type BrowserContext } from '@playwright/test';
import { dirname, join } from 'node:path';
import { fileURLToPath } from 'node:url';

/*
 * Restore recovery in a real browser (spec section 9; check B9 in section 14) [F1, D44]. A counter whose
 * journal holds a sale the server lacks reports it when it starts, and its epoch moves past it; the owner
 * finds it under Lost sales and re-enters it under its original receipt number. It runs after the counter
 * and back-office tests (files run in name order), because it moves T02 to a new epoch.
 */
const here = dirname(fileURLToPath(import.meta.url));
const open: BrowserContext[] = [];
test.afterEach(async () => {
    await Promise.all(open.splice(0).map((c) => c.close()));
});

test('reports a sale the server lacks from the counter journal, and the owner re-enters it', async ({ browser }) => {
    const counter = await browser.newContext({ storageState: join(here, '.auth', 'T02.json') });
    open.push(counter);
    const page = await counter.newPage();
    await page.goto('/counter');
    await expect(page.getByRole('heading', { name: 'Who is selling?' })).toBeVisible();

    // The journal keeps a sale the server never kept, as after a restore to an older copy.
    await page.evaluate(
        () =>
            new Promise<void>((resolve, reject) => {
                const request = indexedDB.open('haq-counter', 1);
                request.onsuccess = () => {
                    const tx = request.result.transaction('journal', 'readwrite');
                    tx.objectStore('journal').put({
                        receipt_number: 'T02-E1-000500',
                        at: new Date(Date.now() - 10 * 60_000).toISOString(),
                        cashier: 'Amina',
                        customer_type: 'retail',
                        total_kobo: 450_000,
                        lines: [{ name: 'Charger Lost After The Backup', colour: null, qty: 1, unit_price_kobo: 450_000 }],
                        payments: [{ method: 'cash', amount_kobo: 450_000, tendered_kobo: 500_000, reference: null }],
                    });
                    tx.oncomplete = () => {
                        resolve();
                    };
                    tx.onerror = () => {
                        reject(new Error('The journal did not take the entry.'));
                    };
                };
                request.onerror = () => {
                    reject(new Error('The counter database did not open.'));
                };
            }),
    );
    const reported = page.waitForResponse((r) => r.url().endsWith('/api/counter/journal') && r.request().postData()?.includes('"entries"') === true);
    await page.reload();
    expect((await (await reported).json()) as { missing: number; raised_to: number }).toMatchObject({ missing: 1, raised_to: 2 });

    const office = await browser.newContext({ storageState: join(here, '.auth', 'D01.json') });
    open.push(office);
    const back = await office.newPage();
    await back.goto('/back-office');
    await back.getByRole('button', { name: /^Halima/ }).click();
    await back.keyboard.type('135790', { delay: 60 });
    await back.getByRole('button', { name: 'Sign in' }).click();
    await expect(back.getByRole('button', { name: 'Open' })).toBeVisible();
    await expect(back.getByText('1 sale to re-enter after the restore')).toBeVisible();

    await back.getByRole('link', { name: /Lost sales/ }).first().click();
    await expect(back.getByRole('heading', { name: 'Sales to re-enter after the restore' })).toBeVisible();
    await expect(back.getByRole('cell', { name: 'T02-E1-000500' })).toBeVisible();
    await back.screenshot({ path: 'e2e/results/screens/lost-window.png', fullPage: true });
    await back.getByRole('button', { name: 'Re-enter' }).click();

    // The journal named no item the server knows, so the owner chooses what to record it under.
    const sheet = back.getByRole('dialog', { name: 'Re-enter T02-E1-000500' });
    await expect(sheet).toContainText('This item is not in the restored data.');
    await sheet.getByRole('button', { name: 'Choose the item' }).click();
    await back.getByLabel('Search items').fill('cable');
    await back.getByRole('dialog', { name: /choose the item/ }).getByRole('listitem').first().getByRole('button').click();
    await expect(sheet).toContainText('the line keeps its printed name');
    await sheet.getByRole('button', { name: 'Re-enter sale' }).click();
    await expect(back.getByRole('status').filter({ hasText: 'T02-E1-000500 is re-entered' })).toBeVisible();
    await expect(back.getByRole('heading', { name: 'Re-entered' })).toBeVisible();
});
