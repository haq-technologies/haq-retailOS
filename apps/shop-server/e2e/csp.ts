import { expect, type Page } from '@playwright/test';

/*
 * The pages run under the Content Security Policy (D64). Chromium reports anything the policy blocks on the
 * console; every page a test opens is watched, and a test fails if anything was blocked.
 */
const blocked: string[] = [];

export function watched(page: Page): Page {
    page.on('console', (message) => {
        if (/Content Security Policy/i.test(message.text())) blocked.push(message.text());
    });
    return page;
}

export function expectNothingBlocked(): void {
    expect(blocked.splice(0)).toEqual([]);
}
