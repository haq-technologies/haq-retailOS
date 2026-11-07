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
