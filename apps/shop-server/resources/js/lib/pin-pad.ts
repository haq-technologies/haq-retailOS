/**
 * The PIN pad's state, kept pure so it can be tested. Digits beyond the longest PIN are ignored.
 */
export interface PinPadState {
    digits: string;
}

export type PinPadKey = '0' | '1' | '2' | '3' | '4' | '5' | '6' | '7' | '8' | '9' | 'delete' | 'clear';

export const emptyPinPad: PinPadState = { digits: '' };

export function press(state: PinPadState, key: PinPadKey, maxLength: number): PinPadState {
    if (key === 'clear') {
        return emptyPinPad;
    }
    if (key === 'delete') {
        return { digits: state.digits.slice(0, -1) };
    }
    if (state.digits.length >= maxLength) {
        return state;
    }
    return { digits: state.digits + key };
}

/** The PIN can be sent once it is at least the shortest PIN for the person's role. */
export function canSubmit(state: PinPadState, minLength: number): boolean {
    return state.digits.length >= minLength;
}

/** Maps a physical key to a pad key; scanners and keyboards are fallbacks to touch (spec section 2). */
export function keyFromKeyboard(key: string): PinPadKey | null {
    if (/^[0-9]$/.test(key)) {
        return key as PinPadKey;
    }
    if (key === 'Backspace') {
        return 'delete';
    }
    return null;
}
