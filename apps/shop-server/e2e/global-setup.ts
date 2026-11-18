import { chromium, type FullConfig } from '@playwright/test';
import { mkdirSync, readFileSync } from 'node:fs';
import { dirname, join } from 'node:path';
import { fileURLToPath } from 'node:url';

/** Enrols each counter once, through the real /enrol page, and keeps its device cookie for the tests. */
const here = dirname(fileURLToPath(import.meta.url));

/**
 * The tests share one shop and its local day, Lagos's as a shop's (UTC+1 all year). A run that crossed midnight
 * there found an earlier test's sale gone from "today" (CI, 23:59 in Lagos on 18 November 2026). A run takes
 * about two minutes, so one that would start in a day's last quarter hour waits for the next day first; the
 * counters' one-time codes last 30 minutes.
 */
async function clearOfMidnight(): Promise<void> {
    const day = 86_400_000;
    const left = day - ((Date.now() + 3_600_000) % day);
    if (left < 15 * 60_000) {
        console.log(`${String(Math.ceil(left / 60_000))} minutes to midnight in Lagos: waiting for the new day, so the run stays in one day.`);
        await new Promise((resolve) => setTimeout(resolve, left + 30_000));
    }
}

export default async function globalSetup(config: FullConfig): Promise<void> {
    await clearOfMidnight();
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
