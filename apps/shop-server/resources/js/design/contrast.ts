/**
 * WCAG 2 contrast between two colours, used to hold the design tokens to the contrast rules in
 * spec section 15. Colours are six-digit hex strings such as "#0040DD".
 */

function channel(value: number): number {
    const c = value / 255;
    return c <= 0.03928 ? c / 12.92 : ((c + 0.055) / 1.055) ** 2.4;
}

export function relativeLuminance(hex: string): number {
    const match = /^#([0-9a-f]{2})([0-9a-f]{2})([0-9a-f]{2})$/i.exec(hex);
    if (match === null) {
        throw new Error(`Not a six-digit hex colour: ${hex}`);
    }
    const [r, g, b] = [match[1], match[2], match[3]].map((part) => parseInt(part ?? '', 16));
    return 0.2126 * channel(r ?? 0) + 0.7152 * channel(g ?? 0) + 0.0722 * channel(b ?? 0);
}

export function contrastRatio(foreground: string, background: string): number {
    const a = relativeLuminance(foreground);
    const b = relativeLuminance(background);
    const [lighter, darker] = a > b ? [a, b] : [b, a];
    return (lighter + 0.05) / (darker + 0.05);
}
