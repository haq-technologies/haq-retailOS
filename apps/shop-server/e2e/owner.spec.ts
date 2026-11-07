import { expect, test, type Browser, type BrowserContext, type Page } from '@playwright/test';
import { dirname, join } from 'node:path';
import { fileURLToPath } from 'node:url';

/*
 * The back office in a real browser (spec sections 8 and 12): the owner's dashboard with profit in three
 * parts, Needs cost with its preview, Shop settings, and a manager who sees no profit. It runs after the
 * counter tests (files run in name order), so there are sales with no cost.
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
    await page.goto('/back-office');
    await page.getByRole('button', { name: new RegExp(`^${name}`) }).click();
    await page.keyboard.type(pin, { delay: 60 });
    await page.getByRole('button', { name: 'Sign in' }).click();
    return page;
}

test('shows the owner the day, then a cost entered with its preview moves revenue to Estimated', async ({ browser }) => {
    const page = await backOffice(browser, 'Halima', '135790');
    await expect(page.getByRole('heading', { name: 'Dashboard' })).toBeVisible();
    await expect(page.getByRole('heading', { name: 'Sales today' })).toBeVisible();
    await expect(page.getByRole('img', { name: /100% waiting for a cost/ })).toBeVisible();
    await page.screenshot({ path: 'e2e/results/screens/back-dashboard.png', fullPage: true });

    await page.getByRole('link', { name: /Needs cost/ }).first().click();
    await expect(page.getByRole('heading', { name: 'Needs cost' })).toBeVisible();
    await page.getByRole('button', { name: /Type-C to Lightning Cable 1m/ }).click();
    await page.getByLabel('Cost for one, in naira').fill('1200');
    const preview = page.getByRole('region', { name: 'Before you save' });
    await expect(preview).toContainText('move from Waiting for a cost to Estimated');
    await expect(preview).toContainText('Revenue with a cost: 0% now');
    await page.screenshot({ path: 'e2e/results/screens/needs-cost.png', fullPage: true });
    await page.getByRole('button', { name: /^Save cost for/ }).click();
    await expect(page.getByRole('status')).toContainText('Saved.');

    await page.getByRole('link', { name: 'Dashboard' }).first().click();
    await expect(page.getByRole('img', { name: /% estimated/ })).toHaveAccessibleName(/, [1-9]\d*% estimated/);
});

test('lets the owner set the closing time in Shop settings', async ({ browser }) => {
    const page = await backOffice(browser, 'Halima', '135790');
    await page.getByRole('link', { name: 'Shop settings' }).first().click();
    await page.getByLabel('Closing time').fill('21:30');
    await page.getByRole('button', { name: 'Save' }).first().click();
    await expect(page.getByRole('status')).toContainText('Saved.');
});

test('shows a manager the day without profit, and no Needs cost', async ({ browser }) => {
    const page = await backOffice(browser, 'Kemi', '246810');
    await expect(page.getByRole('heading', { name: 'Revenue today, by cost' })).toBeVisible();
    await expect(page.getByText('Count estimated profit')).toHaveCount(0);
    await expect(page.getByRole('link', { name: /Needs cost/ })).toHaveCount(0);
});

test('puts a lockout in Needs your attention, then the owner marks it as seen and exports the flags', async ({ browser }) => {
    // Five wrong PINs for Amina on counter T02 lock her out (spec section 3) [F8]; nothing later in this file signs her in.
    const context = await browser.newContext({ storageState: join(here, '.auth', 'T02.json') });
    open.push(context);
    const counter = await context.newPage();
    await counter.goto('/counter');
    await counter.getByRole('button', { name: /^Amina/ }).click();
    for (let i = 0; i < 5; i++) {
        await counter.keyboard.type('9999', { delay: 40 });
        const answered = counter.waitForResponse('**/api/identity/pin');
        await counter.getByRole('button', { name: 'Sign in' }).click();
        await answered;
    }

    const page = await backOffice(browser, 'Halima', '135790');
    const attention = page.getByRole('region', { name: /Needs your attention/ });
    await expect(attention.getByText('Amina was locked out after wrong PINs')).toBeVisible();
    await expect(page.getByRole('heading', { name: 'Cashiers today' })).toBeVisible();
    await page.screenshot({ path: 'e2e/results/screens/dashboard-attention.png', fullPage: true });
    await attention.getByRole('button', { name: 'Mark as seen' }).first().click();
    await expect(page.getByText('Amina was locked out after wrong PINs')).toHaveCount(0);

    await page.getByRole('link', { name: /^Flags/ }).first().click();
    await expect(page.getByRole('heading', { name: 'Flags' })).toBeVisible();
    const flags = page.getByRole('list', { name: 'Flags' });
    await expect(flags.getByText('Amina was locked out after wrong PINs')).toBeVisible();
    await expect(flags.getByText(/Seen by Halima/).first()).toBeVisible();
    await page.screenshot({ path: 'e2e/results/screens/flags.png', fullPage: true });

    const download = page.waitForEvent('download');
    await page.getByRole('button', { name: 'Export to CSV' }).click();
    const file = await download;
    expect(file.suggestedFilename()).toBe('flags-week.csv');
});

test('lets the owner merge a typed brand into the listed one, then approve the item', async ({ browser }) => {
    const page = await backOffice(browser, 'Halima', '135790');
    await page.getByRole('link', { name: /^Review/ }).first().click();
    await expect(page.getByRole('heading', { name: 'Review', exact: true })).toBeVisible();
    const brands = page.getByRole('region', { name: 'Brands typed at the counter' });
    await expect(brands.getByText('Oriamo', { exact: true })).toBeVisible();
    await expect(brands.getByText(/looks like/)).toBeVisible();
    await page.screenshot({ path: 'e2e/results/screens/review-names.png', fullPage: true });
    await brands.getByRole('button', { name: 'Merge into Oraimo' }).click();
    await expect(page.getByRole('status')).toContainText('Oriamo now counts as Oraimo.');

    await page.getByRole('tab', { name: /^New items/ }).click();
    const items = page.getByRole('tabpanel', { name: 'New items' });
    await expect(items.getByText('Oraimo FreePods 3C Earbuds')).toBeVisible();
    await page.screenshot({ path: 'e2e/results/screens/review-items.png', fullPage: true });
    await items.getByRole('button', { name: 'Looks right' }).click();
    await expect(page.getByRole('status')).toContainText('Oraimo FreePods 3C Earbuds looks right.');
    await expect(page.getByRole('tab', { name: 'New items · 0' })).toBeVisible();
});

test('sets a first-sale price with one tap, shows catalogue health, and prints and exports the daily summary', async ({ browser }) => {
    const page = await backOffice(browser, 'Halima', '135790');
    await page.getByRole('link', { name: /^Prices/ }).first().click();
    const first = page.getByRole('tabpanel', { name: 'First sales today' });
    await expect(first.getByText('Type-C to Lightning Cable 1m')).toBeVisible();
    await page.screenshot({ path: 'e2e/results/screens/first-sale-prices.png', fullPage: true });
    await first.getByRole('button', { name: /^Set ₦/ }).first().click();
    await expect(page.getByRole('status')).toContainText('now fills in at');
    await expect(first.getByRole('button', { name: 'Undo' })).toBeVisible();

    await page.getByRole('link', { name: /^Catalogue health/ }).first().click();
    await expect(page.getByRole('heading', { name: 'Catalogue health' })).toBeVisible();
    await expect(page.getByRole('heading', { name: 'Best sellers with no barcode yet' })).toBeVisible();
    await page.screenshot({ path: 'e2e/results/screens/catalogue-health.png', fullPage: true });

    await page.getByRole('link', { name: /^Daily summary/ }).first().click();
    await expect(page.getByRole('heading', { name: 'Daily summary' })).toBeVisible();
    await page.getByRole('button', { name: 'Print daily summary' }).click();
    await expect(page.getByRole('status')).toContainText("The slip's audit code is", { timeout: 20_000 });
    await page.screenshot({ path: 'e2e/results/screens/daily-summary.png', fullPage: true });
    const download = page.waitForEvent('download');
    await page.getByRole('button', { name: 'This day' }).click();
    expect((await download).suggestedFilename()).toMatch(/^sales-\d{4}-\d{2}-\d{2}\.csv$/);
});

test('shows the owner the server health page, with what needs putting right first', async ({ browser }) => {
    const page = await backOffice(browser, 'Halima', '135790');
    await page.getByRole('link', { name: 'Server health' }).first().click();
    await expect(page.getByRole('heading', { name: 'Server health' })).toBeVisible();
    for (const card of ['Continuous copy (WAL)', 'Last backup', 'Last test restore', 'Free disk', 'SSD health', 'Certificate', 'Clock', 'App version', 'Database grants', 'Audit chains', 'Database size']) {
        await expect(page.getByRole('region', { name: card })).toBeVisible();
    }
    // The test server has no backup disk, and the warning says so.
    await expect(page.getByRole('alert').filter({ hasText: 'Backups are not set up on this server' })).toBeVisible();
    await page.screenshot({ path: 'e2e/results/screens/server-health.png', fullPage: true });
});

test('keeps server health from a manager', async ({ browser }) => {
    const page = await backOffice(browser, 'Kemi', '246810');
    await expect(page.getByRole('heading', { name: 'Revenue today, by cost' })).toBeVisible();
    await expect(page.getByRole('link', { name: 'Server health' })).toHaveCount(0);
});

test('shows the owner the audit log, read-only, with the speed of each cashier on the daily summary', async ({ browser }) => {
    const page = await backOffice(browser, 'Halima', '135790');
    await page.getByRole('link', { name: 'Audit log' }).first().click();
    await expect(page.getByRole('heading', { name: 'Audit log' })).toBeVisible();
    await expect(page.getByText('Sale finished').first()).toBeVisible();
    await page.getByLabel('Person').selectOption({ label: 'Amina' });
    await expect(page.getByText('Cost entered')).toHaveCount(0);
    await page.screenshot({ path: 'e2e/results/screens/audit-log.png', fullPage: true });

    await page.getByRole('link', { name: 'Daily summary' }).first().click();
    await expect(page.getByRole('heading', { name: 'Counter speed' })).toBeVisible();
    await expect(page.getByRole('row', { name: /Amina/ })).toBeVisible();
});
