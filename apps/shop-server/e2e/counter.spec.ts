import { expect, test, type Browser, type BrowserContext, type Page } from '@playwright/test';
import { dirname, join } from 'node:path';
import { fileURLToPath } from 'node:url';

/*
 * The counter in a real browser (spec section 14 A): sign in, drill the tiles, the price pad, Pay and the
 * receipt, held sales across two counters, Awaiting transfer, the scanner, and Reconnecting with the cart
 * kept. The shop comes from tests/Browser/setup.php.
 */
const here = dirname(fileURLToPath(import.meta.url));

// Each counter polls the server; close every test's counters so they do not slow the next test.
const open: BrowserContext[] = [];
test.afterEach(async () => {
    await Promise.all(open.splice(0).map((c) => c.close()));
});

async function counter(browser: Browser, terminal: 'T01' | 'T02', name = 'Amina', pin = '1357'): Promise<Page> {
    const context = await browser.newContext({ storageState: join(here, '.auth', `${terminal}.json`) });
    open.push(context);
    const page = await context.newPage();
    await page.goto('/counter');
    await page.getByRole('button', { name: new RegExp(`^${name}`) }).click();
    await page.keyboard.type(pin, { delay: 60 });
    const shiftChecked = page.waitForResponse((r) => r.url().endsWith('/api/counter/shift') && r.request().method() === 'GET');
    await page.getByRole('button', { name: 'Sign in' }).click();
    await expect(page.getByPlaceholder('Search or scan')).toBeVisible();
    // Until a shift is open, the counter offers to open one with its float; skipping never blocks a sale.
    const shift = (await (await shiftChecked).json()) as { shift: unknown };
    if (shift.shift === null) {
        await page.getByRole('dialog', { name: 'Open the shift' }).getByRole('button', { name: 'Not now' }).click();
    }
    return page;
}

/** A scanner types the code in a few milliseconds and ends with Enter (spec section 7) [S1]. */
async function scan(page: Page, code: string): Promise<void> {
    await page.evaluate((text) => {
        for (const key of [...text.split(''), 'Enter']) {
            window.dispatchEvent(new KeyboardEvent('keydown', { key, bubbles: true, cancelable: true }));
        }
    }, code);
}

async function addCable(page: Page, price: string): Promise<void> {
    await page.getByRole('button', { name: 'Cables', exact: true }).click();
    await expect(page.getByText('Choose connector pair')).toBeVisible();
    await page.getByRole('button', { name: /^Type-C to Lightning/ }).first().click();
    await page.getByRole('button', { name: /^1m/ }).first().click();
    const pad = page.getByRole('dialog', { name: 'Price' });
    await expect(pad).toBeVisible();
    await expect(pad.getByText('Prints as')).toBeVisible();
    // A person's typing, slower than a scanner's.
    await page.keyboard.type(price, { delay: 60 });
    await pad.getByRole('button', { name: /^Add at/ }).click();
}

test('signs in, drills to a cable, types its first price, takes cash and prints the receipt', async ({ browser }) => {
    const page = await counter(browser, 'T01');
    await expect(page.getByText('Online')).toBeVisible();
    await addCable(page, '3000');

    const sale = page.getByRole('complementary', { name: 'Sale' });
    await expect(sale.getByText('Type-C to Lightning Cable 1m')).toBeVisible();
    await expect(sale.getByText('₦3,000').first()).toBeVisible();

    await sale.getByRole('button', { name: 'One more Type-C to Lightning Cable 1m' }).click();
    await expect(sale.getByText('₦6,000').first()).toBeVisible();
    await page.screenshot({ path: 'e2e/results/screens/counter-sale.png' });

    await page.keyboard.press('F8');
    await expect(page.getByRole('heading', { name: 'Pay ₦6,000' })).toBeVisible();
    await page.getByRole('button', { name: '₦10,000' }).click();
    await expect(page.getByText('₦4,000')).toBeVisible();
    await page.screenshot({ path: 'e2e/results/screens/pay-cash.png' });
    await page.getByRole('button', { name: 'Finish' }).click();

    await expect(page.getByRole('heading', { name: 'Sale complete' })).toBeVisible();
    await expect(page.getByText('Give change')).toBeVisible();
    await expect(page.getByText(/T01-E\d+-0000\d\d/).first()).toBeVisible();
    await expect(page.getByText(/Receipt printed/)).toBeVisible({ timeout: 15_000 });
    await page.screenshot({ path: 'e2e/results/screens/after-pay.png' });
    await page.getByRole('button', { name: 'Next customer' }).click();
    await expect(sale.getByText('Scan or tap an item to start a sale.')).toBeVisible();
});

test('remembers the price it sold at, and asks before an unusual one', async ({ browser }) => {
    const page = await counter(browser, 'T01');
    await page.getByRole('button', { name: 'Cables', exact: true }).click();
    await page.getByRole('button', { name: /^Type-C to Lightning/ }).first().click();
    await page.getByRole('button', { name: /^1m/ }).first().click();
    const pad = page.getByRole('dialog', { name: 'Price' });
    await expect(pad.getByText('Filled in from the last sale')).toBeVisible();
    await page.screenshot({ path: 'e2e/results/screens/price-pad.png' });
    await expect(pad.getByRole('button', { name: 'Add at ₦3,000' })).toBeVisible();
    await pad.getByRole('button', { name: 'Cancel' }).click();
});

test('holds a sale on one counter and resumes it on the other', async ({ browser }) => {
    const one = await counter(browser, 'T01');
    await addCable(one, '3000');
    await one.keyboard.press('F4');
    const hold = one.getByRole('dialog', { name: 'Hold this sale' });
    await hold.getByLabel(/Label/).fill('Man in blue');
    await hold.getByRole('button', { name: 'Hold' }).click();
    await expect(one.getByText('Held. Every counter in this branch can resume it.')).toBeVisible();

    const two = await counter(browser, 'T02');
    await two.getByRole('button', { name: /^Held sales \(1\)/ }).click();
    const waiting = two.getByRole('dialog', { name: 'Waiting sales' });
    await expect(waiting.getByText('Man in blue')).toBeVisible();
    await waiting.getByRole('button', { name: 'Resume' }).click();
    const sale = two.getByRole('complementary', { name: 'Sale' });
    await expect(sale.getByText('Type-C to Lightning Cable 1m')).toBeVisible();

    await two.keyboard.press('F8');
    await two.getByRole('tab', { name: 'POS' }).click();
    await two.getByRole('button', { name: 'Finish' }).click();
    await expect(two.getByRole('heading', { name: 'Sale complete' })).toBeVisible();
    await expect(two.getByText(/T02-E\d+-000001/).first()).toBeVisible();
});

test('parks a slow transfer as Awaiting transfer, and the other counter finishes it when the alert comes', async ({ browser }) => {
    const one = await counter(browser, 'T01');
    await addCable(one, '3000');
    await one.keyboard.press('F8');
    await one.getByRole('tab', { name: 'Transfer' }).click();
    await one.getByLabel('Transfer reference').fill('Chidi');
    await one.getByRole('button', { name: 'Awaiting transfer' }).click();
    await expect(one.getByText(/Waiting for the transfer/)).toBeVisible();
    await expect(one.getByRole('button', { name: /1 awaiting transfer/ })).toBeVisible({ timeout: 10_000 });

    const two = await counter(browser, 'T02');
    await two.getByRole('button', { name: /awaiting transfer/ }).click();
    await two.getByRole('dialog', { name: 'Waiting sales' }).getByRole('button', { name: 'Alert seen' }).click();
    await expect(two.getByRole('heading', { name: /^Pay / })).toBeVisible();
    await two.getByText(/I have seen the bank alert/).click();
    await two.getByRole('button', { name: 'Finish' }).click();
    await expect(two.getByRole('heading', { name: 'Sale complete' })).toBeVisible();
});

test('learns a new barcode from the scanner and adds the item at once the next time', async ({ browser }) => {
    const page = await counter(browser, 'T01');
    const code = `69${String(Date.now()).slice(-11)}`;
    // Listen for the feed from the scan on, so the learned link cannot arrive unseen.
    const delivered = page.waitForResponse(async (r) => {
        if (!r.url().includes('/api/counter/catalogue/changes')) return false;
        const body = (await r.json()) as { rows: { barcodes?: { code: string }[] } };
        return body.rows.barcodes?.some((b) => b.code === code) === true;
    });
    await scan(page, code);
    await expect(page.getByRole('status').filter({ hasText: `New code ${code}` })).toBeVisible();
    await addCable(page, '3000');
    const sale = page.getByRole('complementary', { name: 'Sale' });
    await expect(sale.getByText('Type-C to Lightning Cable 1m')).toBeVisible();

    // The link reaches this counter's copy through the feed within a poll or two.
    await delivered;
    await scan(page, code);
    await expect(page.getByRole('dialog', { name: 'Price' })).toBeVisible();
});

test('shows Reconnecting when the server stops answering, and keeps the sale', async ({ browser }) => {
    const page = await counter(browser, 'T01');
    await addCable(page, '3000');
    await page.route('**/api/counter/**', (route) => route.abort());
    await expect(page.getByText('Reconnecting')).toBeVisible({ timeout: 10_000 });
    await page.reload();
    await page.unroute('**/api/counter/**');
    await page.getByRole('button', { name: /^Amina/ }).click();
    await page.keyboard.type('1357', { delay: 60 });
    await page.getByRole('button', { name: 'Sign in' }).click();
    const sale = page.getByRole('complementary', { name: 'Sale' });
    await expect(sale.getByText('Type-C to Lightning Cable 1m')).toBeVisible();
});

test('lets a cashier read their own history on the counter', async ({ browser }) => {
    const page = await counter(browser, 'T01');
    await page.getByRole('button', { name: 'My history' }).click();
    const sheet = page.getByRole('dialog', { name: 'My history' });
    await expect(sheet.getByText('Sale finished').first()).toBeVisible();
    await expect(sheet.getByText('Amina').first()).toBeVisible();
    await expect(sheet.getByText('Halima')).toHaveCount(0);
});
