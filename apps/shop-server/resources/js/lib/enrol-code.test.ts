import { describe, expect, it } from 'vitest';
import { codeFromHash } from './enrol-code';

describe('the enrolment code in the address (D86)', () => {
    it('reads the code after #', () => {
        expect(codeFromHash('#code=482913')).toBe('482913');
        expect(codeFromHash('code=482913')).toBe('482913');
        expect(codeFromHash('#https_port=8444&code=482913')).toBe('482913');
    });

    it('is empty with no code, and keeps only what a code can hold', () => {
        expect(codeFromHash('')).toBe('');
        expect(codeFromHash('#other=1')).toBe('');
        expect(codeFromHash('#code=48 29<script>13')).toBe('4829script13');
        expect(codeFromHash(`#code=${'9'.repeat(40)}`)).toBe('9'.repeat(20));
    });
});
