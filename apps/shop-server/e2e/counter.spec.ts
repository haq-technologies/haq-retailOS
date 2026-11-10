import { expect, test, type Browser, type BrowserContext, type Page } from '@playwright/test';
import { dirname, join } from 'node:path';
import { fileURLToPath } from 'node:url';
import { expectNothingBlocked, watched } from './csp';

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
    expectNothingBlocked();
});

async function counter(browser: Browser, terminal: 'T01' | 'T02', name = 'Amina', pin = '1357'): Promise<Page> {
    const context = await browser.newContext({ storageState: join(here, '.auth', `${terminal}.json`) });
    open.push(context);
    const page = watched(await context.newPage());
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
    // The brand step offers every listed brand (D54); these tests sell the cable with no brand.
    const skip = page.getByRole('button', { name: /^Skip brand/ });
    if (await skip.isVisible().catch(() => false)) await skip.click();
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
    // The brand step offers every listed brand (D54); these tests sell the cable with no brand.
    const skip = page.getByRole('button', { name: /^Skip brand/ });
    if (await skip.isVisible().catch(() => false)) await skip.click();
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

test('offers every brand for a power bank in a fresh shop, and the receipt name carries it (D54)', async ({ browser }) => {
    const page = await counter(browser, 'T02');
    await page.getByRole('button', { name: 'Power banks', exact: true }).click();
    await expect(page.getByText('Choose brand')).toBeVisible();
    await page.getByRole('button', { name: /^Oraimo/ }).first().click();
    await expect(page.getByText('Choose capacity')).toBeVisible();
    await page.getByRole('button', { name: /^20000mAh/ }).first().click();
    const pad = page.getByRole('dialog', { name: 'Price' });
    await expect(pad).toBeVisible();
    await expect(pad).toContainText('Oraimo Power Bank 20000mAh');
});

test('shows an item made at one counter in the other counter\'s search within 5 seconds, and warns of a near-duplicate there (check A9)', async ({ browser }) => {
    const a = await counter(browser, 'T01');
    const b = await counter(browser, 'T02');

    await a.getByRole('button', { name: 'New item' }).click();
    const sheet = a.getByRole('dialog', { name: 'New item' });
    await sheet.getByRole('button', { name: 'Earbuds and earpods', exact: true }).click();
    await sheet.getByLabel('Or type the brand').fill('Zetasound');
    await sheet.getByLabel(/^Model/).fill('Zeta Pods 9');
    await sheet.getByRole('region', { name: 'Price' }).getByRole('button', { name: '5', exact: true }).click();
    await sheet.getByRole('region', { name: 'Price' }).getByRole('button', { name: '0', exact: true }).click();
    await sheet.getByRole('region', { name: 'Price' }).getByRole('button', { name: '0', exact: true }).click();
    await sheet.getByRole('region', { name: 'Price' }).getByRole('button', { name: '0', exact: true }).click();
    await sheet.getByRole('button', { name: /^Add at/ }).click();
    await expect(sheet).toBeHidden();
    const made = Date.now();

    await b.getByPlaceholder('Search or scan').fill('zeta pods');
    await expect(b.getByRole('button', { name: /Zetasound Zeta Pods 9 Earbuds/ })).toBeVisible({ timeout: 5_000 });
    expect(Date.now() - made).toBeLessThan(5_000);

    await b.getByPlaceholder('Search or scan').fill('');
    await b.getByRole('button', { name: 'New item' }).click();
    const other = b.getByRole('dialog', { name: 'New item' });
    await other.getByRole('button', { name: 'Earbuds and earpods', exact: true }).click();
    await other.getByLabel('Or type the brand').fill('Zetasound');
    await other.getByLabel(/^Model/).fill('Zeta Pods 9 Pro');
    await other.getByRole('region', { name: 'Price' }).getByRole('button', { name: '6', exact: true }).click();
    await other.getByRole('region', { name: 'Price' }).getByRole('button', { name: '0', exact: true }).click();
    await other.getByRole('region', { name: 'Price' }).getByRole('button', { name: '0', exact: true }).click();
    await other.getByRole('region', { name: 'Price' }).getByRole('button', { name: '0', exact: true }).click();
    await other.getByRole('button', { name: /^Add at/ }).click();
    await expect(other.getByText('There are items like this already')).toBeVisible();
    await expect(other.getByRole('button', { name: /Zetasound Zeta Pods 9 Earbuds/ })).toBeVisible();
});

test('keeps a manager\'s approval of a price below the floor through a slow transfer, and the other counter finishes it (check D4)', async ({ browser }) => {
    const one = await counter(browser, 'T01');
    await one.getByRole('button', { name: 'Cables', exact: true }).click();
    await one.getByRole('button', { name: /^USB-A to Micro-USB/ }).first().click();
    await one.getByRole('button', { name: /^3m/ }).first().click();
    const skip = one.getByRole('button', { name: /^Skip brand/ });
    if (await skip.isVisible().catch(() => false)) await skip.click();
    // A set price adds the cable at ₦3,000 in one tap; the cashier changes the line's price.
    const sale = one.getByRole('complementary', { name: 'Sale' });
    await sale.getByRole('button', { name: 'Change the price of USB-A to Micro-USB Cable 3m' }).click();
    const pad = one.getByRole('dialog', { name: 'Price' });
    await expect(pad.getByText('This is the set price; type to replace it.')).toBeVisible();

    // ₦1,000 is below the floor (half of ₦3,000): the manager approves this line at this price.
    await one.keyboard.type('1000', { delay: 60 });
    await pad.getByRole('button', { name: /^Add at/ }).click();
    await expect(pad.getByText(/below the lowest price a cashier can give/)).toBeVisible();
    await pad.getByRole('button', { name: /^Kemi/ }).click();
    await one.keyboard.type('246810', { delay: 40 });
    await expect(pad).toBeHidden();
    await expect(sale.getByText('₦1,000').first()).toBeVisible();

    // The alert is slow: the cart waits as Awaiting transfer, long past the minute an approval has to bind.
    await one.keyboard.press('F8');
    await one.getByRole('tab', { name: 'Transfer' }).click();
    await one.getByLabel('Transfer reference').fill('Bisi');
    await one.getByRole('button', { name: 'Awaiting transfer' }).click();
    await expect(one.getByText(/Waiting for the transfer/)).toBeVisible();

    const two = await counter(browser, 'T02');
    await two.getByRole('button', { name: /awaiting transfer/ }).click();
    const pending = two.getByRole('dialog', { name: 'Waiting sales' }).getByRole('region', { name: 'Pending transfers' });
    await pending.locator('div').filter({ hasText: 'Bisi' }).filter({ has: two.getByRole('button', { name: 'Alert seen' }) })
        .getByRole('button', { name: 'Alert seen' }).click();
    await expect(two.getByRole('heading', { name: 'Pay ₦1,000' })).toBeVisible();
    await two.getByText(/I have seen the bank alert/).click();
    await two.getByRole('button', { name: 'Finish' }).click();
    await expect(two.getByRole('heading', { name: 'Sale complete' })).toBeVisible();
    await expect(two.getByText(/T02-E\d+-\d{6}/).first()).toBeVisible();
});

test('keeps a sale the server never answered just as it is, and never sells it twice (D61)', async ({ browser }) => {
    const page = await counter(browser, 'T01');
    const keys: string[] = [];
    // The sale reaches the server and commits, but the answer is lost on the way back, once.
    let lost = 1;
    await page.route('**/api/counter/sales', async (route) => {
        const request = route.request();
        if (request.method() !== 'POST') return route.fallback();
        keys.push((request.postDataJSON() as { idempotency_key: string }).idempotency_key);
        if (lost-- > 0) {
            await route.fetch();
            return route.abort('connectionreset');
        }
        return route.fallback();
    });

    await addCable(page, '3000');
    await page.keyboard.press('F8');
    await page.getByRole('tab', { name: 'POS' }).click();
    await page.getByRole('button', { name: 'Finish' }).click();
    await expect(page.getByText('The server did not answer, so this sale may already be finished.')).toBeVisible();
    // Nothing about the sale or its payment can change until the server answers.
    await expect(page.getByRole('button', { name: 'Back to sale' })).toBeDisabled();
    await expect(page.getByRole('tab', { name: 'Cash' })).toBeDisabled();
    await page.keyboard.press('Escape');
    await expect(page.getByRole('heading', { name: 'Pay ₦3,000' })).toBeVisible();

    // A refusal before Finish runs (here a sign-in that ran out) says nothing about the sale: it stays locked.
    await page.route('**/api/counter/sales', (route) => route.fulfill({ status: 401, contentType: 'application/json', body: '{"message":"Sign in with your name and PIN."}' }), { times: 1 });
    await page.getByRole('button', { name: 'Finish' }).click();
    await expect(page.getByText('Sign in with your name and PIN.')).toBeVisible();
    await expect(page.getByRole('button', { name: 'Back to sale' })).toBeDisabled();

    // Finish again sends the same request; the server answers with the sale it already has.
    const answered = page.waitForResponse((r) => r.url().endsWith('/api/counter/sales') && r.request().method() === 'POST');
    await page.getByRole('button', { name: 'Finish' }).click();
    const body = (await (await answered).json()) as { sale: { retried?: boolean } };
    expect(body.sale.retried).toBe(true);
    expect(keys).toHaveLength(2);
    expect(keys[1]).toBe(keys[0]);
    await expect(page.getByRole('heading', { name: 'Sale complete' })).toBeVisible();
    await page.getByRole('button', { name: 'Next customer' }).click();

    // Check the server finds it too, so a cashier who wants to change the sale learns it is already sold.
    lost = 1;
    await addCable(page, '3000');
    await page.keyboard.press('F8');
    await page.getByRole('tab', { name: 'POS' }).click();
    await page.getByRole('button', { name: 'Finish' }).click();
    await expect(page.getByRole('button', { name: 'Check the server' })).toBeVisible();
    const looked = page.waitForResponse((r) => r.url().includes('/api/counter/sales/by-key/'));
    await page.getByRole('button', { name: 'Check the server' }).click();
    expect((await looked).status()).toBe(200);
    await expect(page.getByRole('heading', { name: 'Sale complete' })).toBeVisible();
    expect(keys).toHaveLength(3);
});

test('answers every local interaction within 100 ms (check A8)', async ({ browser }) => {
    const page = await counter(browser, 'T01');
    await addCable(page, '3000');
    // Measured in the page: from the tap to the sale panel showing the change, the median of 10 taps.
    const times = await page.evaluate(async () => {
        const panel = document.querySelector('[aria-label="Sale"]');
        const more = [...(panel?.querySelectorAll('button') ?? [])].find((b) => (b.getAttribute('aria-label') ?? '').startsWith('One more'));
        if (panel === null || more === undefined) return [];
        const out: number[] = [];
        for (let i = 0; i < 10; i++) {
            const changed = new Promise<void>((resolve) => {
                const observer = new MutationObserver(() => {
                    observer.disconnect();
                    resolve();
                });
                observer.observe(panel, { subtree: true, childList: true, characterData: true });
            });
            const started = performance.now();
            more.click();
            await changed;
            out.push(performance.now() - started);
        }
        return out.sort((a, b) => a - b);
    });
    expect(times).toHaveLength(10);
    expect(times[5]).toBeLessThan(100);

    // Opening Pay, from F8 to its heading.
    const pay = await page.evaluate(async () => {
        const started = performance.now();
        const shown = new Promise<void>((resolve) => {
            const observer = new MutationObserver(() => {
                if ([...document.querySelectorAll('h2')].some((h) => h.textContent.startsWith('Pay '))) {
                    observer.disconnect();
                    resolve();
                }
            });
            observer.observe(document.body, { subtree: true, childList: true });
        });
        window.dispatchEvent(new KeyboardEvent('keydown', { key: 'F8', bubbles: true, cancelable: true }));
        await shown;
        return performance.now() - started;
    });
    expect(pay).toBeLessThan(100);
});
