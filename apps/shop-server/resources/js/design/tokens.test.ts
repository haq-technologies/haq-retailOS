import { readFileSync } from 'node:fs';
import { fileURLToPath } from 'node:url';
import { describe, expect, it } from 'vitest';
import { contrastRatio } from './contrast';

// The tokens are read from the stylesheet itself, so this test checks what the app really ships.
const css = readFileSync(fileURLToPath(new URL('../../css/tokens.css', import.meta.url)), 'utf8');

function token(name: string): string {
    const match = new RegExp(`--color-${name}:\\s*(#[0-9a-f]{6})`, 'i').exec(css);
    if (match?.[1] === undefined) {
        throw new Error(`Token --color-${name} is missing from tokens.css`);
    }
    return match[1];
}

const white = '#ffffff';
const black = '#000000';

describe('colour tokens (spec section 15)', () => {
    it('keeps the values frozen in the spec', () => {
        expect({
            background: token('background'),
            panel: token('panel'),
            border: token('border'),
            text: token('text'),
            'text-secondary': token('text-secondary'),
            action: token('action'),
            'action-text': token('action-text'),
            pay: token('pay'),
            'success-tint': token('success-tint'),
            warning: token('warning'),
            'warning-text': token('warning-text'),
            destructive: token('destructive'),
        }).toEqual({
            background: '#ffffff',
            panel: '#f2f2f7',
            border: '#d1d1d6',
            text: '#000000',
            'text-secondary': '#6c6c70',
            action: '#007aff',
            'action-text': '#0040dd',
            pay: '#248a3d',
            'success-tint': '#34c759',
            warning: '#ff9500',
            'warning-text': '#c93400',
            destructive: '#d70015',
        });
    });

    it.each(['text', 'text-secondary', 'action-text', 'warning-text', 'destructive'])(
        'gives %s text at least 4.5:1 on white',
        (name) => {
            expect(contrastRatio(token(name), white)).toBeGreaterThanOrEqual(4.5);
        },
    );

    it('gives white text at least 4.5:1 on the destructive fill', () => {
        expect(contrastRatio(white, token('destructive'))).toBeGreaterThanOrEqual(4.5);
    });

    it('gives black text at least 4.5:1 on the warning fill', () => {
        expect(contrastRatio(black, token('warning'))).toBeGreaterThanOrEqual(4.5);
    });

    // Large text (at least 18.66px bold) needs 3:1.
    it.each(['pay', 'action'])('gives a large white label at least 3:1 on the %s fill', (name) => {
        expect(contrastRatio(white, token(name))).toBeGreaterThanOrEqual(3);
    });

    it('shows why white text is never put on the success tint', () => {
        expect(contrastRatio(white, token('success-tint'))).toBeLessThan(3);
    });
});

describe('contrastRatio', () => {
    it('matches the ratios stated in the spec', () => {
        expect(contrastRatio(black, white)).toBeCloseTo(21, 1);
        expect(contrastRatio('#0040dd', white)).toBeCloseTo(7.6, 1);
        expect(contrastRatio('#d70015', white)).toBeCloseTo(5.4, 1);
    });

    it('rejects a colour that is not six-digit hex', () => {
        expect(() => contrastRatio('blue', white)).toThrow('Not a six-digit hex colour');
    });
});
