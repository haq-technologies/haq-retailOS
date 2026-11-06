import { describe, expect, it } from 'vitest';
import { canSubmit, emptyPinPad, keyFromKeyboard, press } from './pin-pad';

describe('the PIN pad', () => {
    it('adds digits up to the longest PIN and ignores the rest', () => {
        let state = emptyPinPad;
        for (const key of ['1', '2', '3', '4', '5', '6', '7'] as const) {
            state = press(state, key, 6);
        }
        expect(state.digits).toBe('123456');
    });

    it('deletes the last digit and clears them all', () => {
        const state = press(press(press(emptyPinPad, '4', 6), '2', 6), 'delete', 6);
        expect(state.digits).toBe('4');
        expect(press(state, 'clear', 6)).toEqual(emptyPinPad);
    });

    it('can send only once the shortest PIN is reached', () => {
        expect(canSubmit({ digits: '123' }, 4)).toBe(false);
        expect(canSubmit({ digits: '1234' }, 4)).toBe(true);
        expect(canSubmit({ digits: '12345' }, 6)).toBe(false);
    });

    it('takes digits and Backspace from a keyboard', () => {
        expect(keyFromKeyboard('7')).toBe('7');
        expect(keyFromKeyboard('Backspace')).toBe('delete');
        expect(keyFromKeyboard('a')).toBeNull();
        expect(keyFromKeyboard('Enter')).toBeNull();
    });
});
