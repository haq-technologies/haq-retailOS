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

/**
 * A scanner types the code in a few milliseconds and ends with Enter (spec section 7) [S1]. As a keyboard
 * does, it sends Shift before each capital (D76).
 */
async function scan(page: Page, code: string): Promise<void> {
    await page.evaluate((text) => {
        const keys = text.split('').flatMap((key) => (/[A-Z]/.test(key) ? ['Shift', key] : [key]));
        for (const key of [...keys, 'Enter']) {
            window.dispatchEvent(new KeyboardEvent('keydown', { key, bubbles: true, cancelable: true }));
        }
    }, code);
}

/**
 * The tiles to a cable: the brand first, with every listed brand (D54, D79); these tests sell it with no
 * brand. The steps only move the tiles on: the cable is added from its own tile, in the list (D102).
 */
async function drillToCable(page: Page, connector: RegExp, length: RegExp, cable: RegExp): Promise<void> {
    await page.getByRole('button', { name: 'Cables', exact: true }).click();
    await expect(page.getByText('Choose brand')).toBeVisible();
    await page.getByRole('button', { name: /^Skip brand/ }).click();
    await expect(page.getByText('Choose connector pair')).toBeVisible();
    await page.getByRole('button', { name: connector }).first().click();
    await page.getByRole('button', { name: length }).first().click();
    await expect(page.getByText('Choose the item')).toBeVisible();
    await page.getByRole('button', { name: cable }).click();
}

async function addCable(page: Page, price: string): Promise<void> {
    await drillToCable(page, /^Type-C to Lightning/, /^1m/, /^Type-C to Lightning Cable 1m/);
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
    await drillToCable(page, /^Type-C to Lightning/, /^1m/, /^Type-C to Lightning Cable 1m/);
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
    await page.getByRole('button', { name: /^Oraimo Power Bank 20000mAh/ }).click();
    const pad = page.getByRole('dialog', { name: 'Price' });
    await expect(pad).toBeVisible();
    await expect(pad).toContainText('Oraimo Power Bank 20000mAh');
});

test('asks the brand for a cable and a charger head in a fresh shop, at the tiles and from search (D79)', async ({ browser }) => {
    const page = await counter(browser, 'T02');
    const pad = page.getByRole('dialog', { name: 'Price' });

    // Cables: the brand, then the connector pair, then the length, then the cable's own tile, as Oraimo's.
    await page.getByRole('button', { name: 'Cables', exact: true }).click();
    await expect(page.getByText('Choose brand')).toBeVisible();
    await expect(page.getByRole('button', { name: 'No brand', exact: true })).toHaveCount(0);
    await page.getByRole('button', { name: 'Oraimo', exact: true }).click();
    await expect(page.getByText('Choose connector pair')).toBeVisible();
    await page.getByRole('button', { name: /^Type-C to Type-C/ }).first().click();
    await page.getByRole('button', { name: /^2m/ }).first().click();
    // The length leaves one cable, and the list shows it with New, rather than adding it (D102).
    await expect(page.getByText('Choose the item')).toBeVisible();
    await expect(page.getByRole('button', { name: /^New Oraimo cable/ })).toBeVisible();
    await page.getByRole('button', { name: /^Oraimo Type-C to Type-C Cable 2m/ }).click();
    await expect(pad).toContainText('Oraimo Type-C to Type-C Cable 2m');
    await pad.getByRole('button', { name: 'Back' }).click();

    // A charger head: its only other step is the wattage, and the brand is still asked before it.
    await page.getByRole('button', { name: 'All', exact: true }).click();
    await page.getByRole('button', { name: 'Wall chargers', exact: true }).click();
    await page.getByRole('button', { name: 'Charger head', exact: true }).click();
    await expect(page.getByText('Choose brand')).toBeVisible();
    await page.getByRole('button', { name: /^Skip brand/ }).click();
    await page.getByRole('button', { name: /^20W/ }).first().click();
    await page.getByRole('button', { name: /^Charger Head 20W/ }).click();
    await expect(pad).toContainText('Charger Head 20W');
    await expect(pad).not.toContainText('Oraimo');
    await pad.getByRole('button', { name: 'Back' }).click();

    // A cable found by search that nobody has sold yet: its brand is asked before it is added.
    await page.getByPlaceholder('Search or scan').fill('type-c to type-c 0.25m');
    await page.getByRole('button', { name: /^Type-C to Type-C Cable 0\.25m/ }).first().click();
    await expect(page.getByText('Choose brand')).toBeVisible();
    await page.getByRole('button', { name: 'Anker', exact: true }).click();
    await page.getByRole('button', { name: /^Anker Type-C to Type-C Cable 0\.25m/ }).click();
    await expect(pad).toContainText('Anker Type-C to Type-C Cable 0.25m');
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
    await drillToCable(one, /^USB-A to Micro-USB/, /^3m/, /^USB-A to Micro-USB Cable 3m/);
    // A set price adds the cable at ₦3,000 from its tile; the cashier changes the line's price.
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

test('returns an item by its receipt with a manager\'s PIN, then closes the day with a blind count (checks F2 and F5)', async ({ browser }) => {
    const page = await counter(browser, 'T01');
    await addCable(page, '3000');
    await page.keyboard.press('F8');
    await page.getByRole('button', { name: 'Exact' }).click();
    await page.getByRole('button', { name: 'Finish' }).click();
    await expect(page.getByRole('heading', { name: 'Sale complete' })).toBeVisible();
    const receipt = ((await page.getByText(/T01-E\d+-\d{6}/).first().textContent()) ?? '').match(/T01-E\d+-\d{6}/)?.[0] ?? '';
    expect(receipt).not.toBe('');
    await page.getByRole('button', { name: 'Next customer' }).click();

    // The customer brings it back: find the sale, choose the item, and a manager approves the refund.
    await page.getByRole('button', { name: 'Returns and voids' }).click();
    const sheet = page.getByRole('dialog', { name: 'Returns and voids' });
    await sheet.getByPlaceholder('T01-E1-000123').fill(receipt);
    await sheet.getByRole('button', { name: 'Find the sale' }).click();
    await sheet.getByRole('button', { name: 'One more Type-C to Lightning Cable 1m back' }).click();
    await sheet.getByRole('button', { name: 'Return and refund ₦3,000' }).click();
    await expect(sheet.getByText('A manager must approve the return')).toBeVisible();
    await sheet.getByRole('button', { name: /^Kemi/ }).click();
    await page.keyboard.type('246810', { delay: 40 });
    await expect(page.getByText(/Returned: ₦3,000 back to the customer\. Slip T01-E\d+-\d{6} prints\./)).toBeVisible();

    // Closing the day: the count first, then what was expected, and the Z-report prints.
    await page.getByRole('button', { name: 'Close the day' }).click();
    const close = page.getByRole('dialog', { name: 'Close the day' });
    await expect(close.getByText('You see what was expected after.')).toBeVisible();
    await expect(close.getByText('Expected', { exact: true })).toHaveCount(0);
    await page.keyboard.type('5000', { delay: 40 });
    await close.getByRole('button', { name: 'Close the day' }).click();
    await expect(close.getByText('Expected', { exact: true })).toBeVisible();
    await expect(close.getByText('Counted', { exact: true })).toBeVisible();
    await expect(close.getByText('The Z-report is printing.')).toBeVisible();
});

test('keeps focus in the field a cashier is typing in while the counter polls, and in a field that asks for it (D76)', async ({ browser }) => {
    const page = await counter(browser, 'T01');
    await page.getByRole('button', { name: 'New item' }).click();
    const sheet = page.getByRole('dialog', { name: 'New item' });
    await sheet.getByRole('button', { name: 'Earbuds and earpods', exact: true }).click();

    // A person's typing, across more than one of the counter's 3-second polls: each poll used to pull focus
    // out of the field, so the rest of the word went nowhere.
    const brand = sheet.getByLabel('Or type the brand');
    await brand.click();
    await page.keyboard.type('Zetasound Pro', { delay: 400 });
    await expect(brand).toBeFocused();
    await expect(brand).toHaveValue('Zetasound Pro');
    // Something typed: Cancel asks before it discards it (D93).
    await sheet.getByRole('button', { name: 'Cancel' }).click();
    await sheet.getByRole('button', { name: 'Discard', exact: true }).click();
    await expect(sheet).toBeHidden();

    // Hold's label asks for focus as its sheet opens, and keeps it.
    await addCable(page, '3000');
    await page.keyboard.press('F4');
    await expect(page.getByRole('dialog', { name: 'Hold this sale' }).getByPlaceholder('Such as: man in blue')).toBeFocused();
});

test('takes a receipt scanned into an open Returns, capitals and all (D76)', async ({ browser }) => {
    const page = await counter(browser, 'T01');
    await addCable(page, '3000');
    await page.keyboard.press('F8');
    await page.getByRole('button', { name: 'Exact' }).click();
    await page.getByRole('button', { name: 'Finish' }).click();
    await expect(page.getByRole('heading', { name: 'Sale complete' })).toBeVisible();
    const receipt = ((await page.getByText(/T01-E\d+-\d{6}/).first().textContent()) ?? '').match(/T01-E\d+-\d{6}/)?.[0] ?? '';
    expect(receipt).not.toBe('');
    await page.getByRole('button', { name: 'Next customer' }).click();

    // Returns is already open, with the cashier in its field, when the receipt is scanned.
    await page.getByRole('button', { name: 'Returns and voids' }).click();
    const sheet = page.getByRole('dialog', { name: 'Returns and voids' });
    await sheet.getByPlaceholder('T01-E1-000123').click();
    await scan(page, receipt);
    await expect(sheet.getByText(receipt)).toBeVisible();
    await expect(sheet.getByRole('button', { name: 'One more Type-C to Lightning Cable 1m back' })).toBeVisible();
    await expect(page.getByText('Finish what is open first, then scan.')).toHaveCount(0);
});

test("keeps a manager's PIN typed on the keyboard at Pay out of the cash received (D76)", async ({ browser }) => {
    const page = await counter(browser, 'T01');
    // At Finish the server asks for a manager, once, as it does when an approval no longer holds.
    let asked = false;
    await page.route('**/api/counter/sales', async (route) => {
        const request = route.request();
        if (request.method() !== 'POST' || asked) return route.fallback();
        asked = true;
        const line = (request.postDataJSON() as { lines: { client_line_id: string; item_id: string; unit_price_kobo: number }[] }).lines[0];
        return route.fulfill({
            status: 422,
            contentType: 'application/json',
            body: JSON.stringify({
                message: 'A manager must approve this price.',
                approval: { action: 'price_below_floor', subject_id: line?.client_line_id, item_id: line?.item_id, price_kobo: line?.unit_price_kobo },
            }),
        });
    });

    await addCable(page, '3000');
    await page.keyboard.press('F8');
    await page.keyboard.type('5000', { delay: 60 });
    await page.getByRole('button', { name: 'Finish' }).click();
    await expect(page.getByText('A manager must approve this price.')).toBeVisible();

    // The manager types the PIN on the keyboard: every digit is the PIN's, and none joins the ₦5,000.
    await page.getByRole('button', { name: /^Kemi/ }).click();
    await page.keyboard.type('246810', { delay: 40 });
    await expect(page.getByRole('heading', { name: 'Sale complete' })).toBeVisible();
    await expect(page.getByText('Cash received ₦5,000 for a total of ₦3,000')).toBeVisible();
});

test('lays New item out for the screen: two panes on a counter and a tablet, two steps on a phone (D80)', async ({ browser }) => {
    const page = await counter(browser, 'T01');
    await page.setViewportSize({ width: 1366, height: 768 });
    await page.getByRole('button', { name: 'New item' }).click();
    const sheet = page.getByRole('dialog', { name: 'New item' });
    await sheet.getByRole('button', { name: 'Phone pouches and covers', exact: true }).click();
    const price = sheet.getByRole('region', { name: 'Price' });

    // A counter: the longest form there is, and still the name, the keypad, Add and Cancel are in view
    // with no scrolling. The details scroll by themselves.
    await sheet.getByLabel('Or type the brand').fill('Zetacase');
    for (const [width, height, name] of [[1366, 768, 'large'], [800, 1000, 'medium']] as const) {
        await page.setViewportSize({ width, height });
        await expect(sheet.getByRole('group', { name: 'Name and price' }).getByText('Zetacase Pouch')).toBeInViewport({ ratio: 1 });
        await expect(price.getByRole('button', { name: '0', exact: true })).toBeInViewport({ ratio: 1 });
        await expect(sheet.getByRole('button', { name: 'Type the price' })).toBeInViewport({ ratio: 1 });
        await expect(sheet.getByRole('button', { name: 'Cancel' })).toBeInViewport({ ratio: 1 });
        await expect(sheet.getByRole('button', { name: 'Next: the price' })).toBeHidden();
        await page.screenshot({ path: `e2e/results/screens/new-item-${name}.png` });
    }

    // A phone: the details first, with the name so far and the way on; then the price.
    await page.setViewportSize({ width: 390, height: 780 });
    await expect(price).toBeHidden();
    await expect(sheet.getByLabel('Or type the brand')).toBeVisible();
    await expect(sheet.getByRole('button', { name: 'Next: the price' })).toBeInViewport({ ratio: 1 });
    await page.screenshot({ path: 'e2e/results/screens/new-item-compact-details.png' });
    await sheet.getByRole('button', { name: 'Next: the price' }).click();
    await expect(sheet.getByLabel('Or type the brand')).toBeHidden();
    await price.getByRole('button', { name: '5', exact: true }).click();
    await price.getByRole('button', { name: '00', exact: true }).click();
    await expect(sheet.getByRole('button', { name: 'Add at ₦500' })).toBeInViewport({ ratio: 1 });
    await page.screenshot({ path: 'e2e/results/screens/new-item-compact-price.png' });
    await sheet.getByRole('button', { name: 'Back to the details' }).click();
    await expect(sheet.getByLabel('Or type the brand')).toHaveValue('Zetacase');
});

test('keeps a code scanned before New item, asks "Made by" and phone models by family, offers the listed models, and asks before it discards (D93)', async ({ browser }) => {
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
    await page.getByRole('button', { name: 'New item' }).click();
    const sheet = page.getByRole('dialog', { name: 'New item' });
    await expect(sheet.getByText(`Code ${code} is kept for this item`)).toBeVisible();

    // Pouches: "Made by", with No brand until the cashier chooses, and the phone model chosen by its family.
    await sheet.getByRole('button', { name: 'Phone pouches and covers', exact: true }).click();
    const madeBy = sheet.getByRole('region', { name: 'Made by' });
    await expect(madeBy.getByRole('button', { name: 'No brand', exact: true })).toHaveAttribute('aria-pressed', 'true');
    const phone = sheet.getByRole('region', { name: 'Phone model' });
    await expect(phone.getByRole('button', { name: 'iPhone 13', exact: true })).toHaveCount(0);
    await phone.getByRole('button', { name: 'iPhone', exact: true }).click();
    await phone.getByRole('button', { name: 'iPhone 13', exact: true }).click();
    const named = sheet.getByRole('group', { name: 'Name and price' });
    await expect(named).toContainText('iPhone 13 Pouch');

    // Esc with something chosen asks first, and Keep editing keeps it all.
    await page.keyboard.press('Escape');
    await expect(sheet.getByText('Discard this new item?')).toBeVisible();
    await sheet.getByRole('button', { name: 'Keep editing' }).click();
    await expect(phone.getByRole('button', { name: 'iPhone 13', exact: true })).toHaveAttribute('aria-pressed', 'true');

    // Earbuds: a new category clears the pouch's details; the listed models are offered, and one brings its brand.
    await sheet.getByRole('button', { name: 'Change', exact: true }).click();
    await sheet.getByRole('button', { name: 'Earbuds and earpods', exact: true }).click();
    const brand = sheet.getByRole('region', { name: 'Brand' });
    await expect(brand.getByRole('button', { name: 'No brand', exact: true })).toHaveCount(0);
    await expect(named).not.toContainText('iPhone');
    await sheet.getByRole('button', { name: 'SpaceBuds', exact: true }).click();
    await expect(brand.getByRole('button', { name: 'Oraimo', exact: true })).toHaveAttribute('aria-pressed', 'true');
    await expect(named).toContainText('Oraimo SpaceBuds Earbuds');
    const price = sheet.getByRole('region', { name: 'Price' });
    await expect(price.getByText('Retail price')).toBeVisible();
    for (const key of ['1', '5', '0', '0', '0']) await price.getByRole('button', { name: key, exact: true }).click();
    await sheet.getByRole('button', { name: 'Add at ₦15,000' }).click();
    await expect(sheet).toBeHidden();
    await expect(page.getByRole('complementary', { name: 'Sale' }).getByText('Oraimo SpaceBuds Earbuds')).toBeVisible();

    // The code was learned with the item, so once it reaches this counter the next scan adds the item at once.
    await delivered;
    await scan(page, code);
    await expect(page.getByRole('dialog', { name: 'Price' })).toContainText('Oraimo SpaceBuds Earbuds');
});

test('offers only the brands a category has items of, adds a brand on the go from New, and finds it by search (D94, D103)', async ({ browser }) => {
    const page = await counter(browser, 'T02');
    const tiles = page.getByRole('main');

    // Earbuds so far are Zetasound's and Oraimo's: the brand step offers those two, no More brands, then New.
    await tiles.getByRole('button', { name: 'Earbuds and earpods', exact: true }).click();
    await expect(page.getByText('Choose brand')).toBeVisible();
    await expect(tiles.getByRole('button', { name: /^Skip brand/ })).toBeVisible();
    for (const brand of ['Oraimo', 'Zetasound']) await expect(tiles.getByRole('button', { name: brand, exact: true })).toBeVisible();
    await expect(tiles.getByRole('button', { name: 'Hoco', exact: true })).toHaveCount(0);
    await expect(tiles.getByRole('button', { name: /^More brands/ })).toHaveCount(0);

    // A brand with no earbuds here yet is added on the go: New opens New item with the category, and the brand is chosen there.
    await tiles.getByRole('button', { name: /^New earbuds/ }).click();
    const sheet = page.getByRole('dialog', { name: 'New item' });
    const brand = sheet.getByRole('region', { name: 'Brand' });
    await sheet.getByLabel('Or type the brand').fill('Hoco');
    await brand.getByRole('button', { name: 'Hoco', exact: true }).click();
    await expect(brand.getByRole('button', { name: 'Hoco', exact: true })).toHaveAttribute('aria-pressed', 'true');
    await sheet.getByLabel(/^Model/).fill('EW04');
    const price = sheet.getByRole('region', { name: 'Price' });
    for (const key of ['8', '0', '0', '0']) await price.getByRole('button', { name: key, exact: true }).click();
    await sheet.getByRole('button', { name: 'Add at ₦8,000' }).click();
    await expect(sheet).toBeHidden();
    await expect(page.getByRole('complementary', { name: 'Sale' }).getByText('Hoco EW04 Earbuds')).toBeVisible();

    // From then on Hoco is one of the earbuds' brands, and its list ends with New, the brand filled in.
    await tiles.getByRole('button', { name: 'Earbuds and earpods', exact: true }).click();
    await tiles.getByRole('button', { name: 'Hoco', exact: true }).click();
    await expect(tiles.getByRole('button', { name: /^Hoco EW04 Earbuds/ })).toBeVisible();
    await tiles.getByRole('button', { name: /^New Hoco earbuds/ }).click();
    await expect(sheet.getByRole('region', { name: 'Brand' }).getByRole('button', { name: 'Hoco', exact: true })).toHaveAttribute('aria-pressed', 'true');
    await page.keyboard.press('Escape');
    await expect(sheet).toBeHidden();

    // Search finds the brand in its category as a place on the tiles, after the items.
    await page.getByPlaceholder('Search or scan').fill('hoco ear');
    await tiles.getByRole('button', { name: /^Hoco · Earbuds and earpods/ }).click();
    await expect(tiles.getByRole('button', { name: /^Hoco EW04 Earbuds/ })).toBeVisible();
    await expect(tiles.getByRole('button', { name: /^New Hoco earbuds/ })).toBeVisible();

    // Skip brand shows every brand: the earbuds made under Zetasound, Oraimo and Hoco are all there, so the
    // model is asked, and the Hoco earbuds made above are listed with New (D102).
    await page.getByRole('button', { name: 'All', exact: true }).click();
    await tiles.getByRole('button', { name: 'Earbuds and earpods', exact: true }).click();
    await tiles.getByRole('button', { name: /^Skip brand/ }).click();
    await expect(page.getByText('Choose model')).toBeVisible();
    for (const model of ['Zeta Pods 9', 'SpaceBuds']) await expect(tiles.getByRole('button', { name: model, exact: true })).toBeVisible();
    await expect(tiles.getByRole('button', { name: /^New earbuds/ })).toBeVisible();
    await tiles.getByRole('button', { name: 'EW04', exact: true }).click();
    await expect(page.getByText('Choose the item')).toBeVisible();
    await expect(tiles.getByRole('button', { name: /^Hoco EW04 Earbuds/ })).toBeVisible();
    await expect(tiles.getByRole('button', { name: /^New earbuds/ })).toBeVisible();
});
