import { chromium, type FullConfig } from '@playwright/test';
import { mkdirSync, readFileSync } from 'node:fs';
import { dirname, join } from 'node:path';
import { fileURLToPath } from 'node:url';

/** Enrols each counter once, through the real /enrol page, and keeps its device cookie for the tests. */
const here = dirname(fileURLToPath(import.meta.url));

export default async function globalSetup(config: FullConfig): Promise<void> {
    const state = JSON.parse(readFileSync(join(here, '.state.json'), 'utf8')) as { codes: Record<string, string> };
    const baseURL = config.projects[0]?.use.baseURL ?? 'http://localhost:8000';
    mkdirSync(join(here, '.auth'), { recursive: true });
    const browser = await chromium.launch();
    for (const [terminal, code] of Object.entries(state.codes)) {
        const context = await browser.newContext({ baseURL });
        const page = await context.newPage();
        await page.goto('/enrol');
        await page.getByLabel('Code').fill(code);
        await page.getByRole('button', { name: 'Enrol counter' }).click();
        // A counter opens the counter; a back-office device the back office.
        await page.waitForURL(/\/(counter|back-office)$/);
        await context.storageState({ path: join(here, '.auth', `${terminal}.json`) });
        await context.close();
    }
    await browser.close();
}
