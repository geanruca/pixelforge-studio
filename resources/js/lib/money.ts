/**
 * Money arrives from the server as an integer number of nanodollars
 * (1 USD = 1,000,000,000). Generations cost fractions of a cent, so amounts
 * are shown with four decimals; the exact value is always available through
 * `exactUsd` (used as a tooltip).
 */
const NANOS_PER_DOLLAR = 1_000_000_000;
const DISPLAY_DECIMALS = 4;
const NANOS_PER_DISPLAY_UNIT = NANOS_PER_DOLLAR / 10 ** DISPLAY_DECIMALS;

type Rounding = 'nearest' | 'down' | 'up';

const ROUNDERS: Record<Rounding, (value: number) => number> = {
    nearest: Math.round,
    down: Math.floor,
    up: Math.ceil,
};

/**
 * Format nanodollars as "$0.0706". Round `down` for money the user has and
 * `up` for money they may owe, so the display never flatters them.
 */
export function formatUsd(
    nanos: number,
    rounding: Rounding = 'nearest',
): string {
    const units = ROUNDERS[rounding](Math.abs(nanos) / NANOS_PER_DISPLAY_UNIT);
    const dollars = Math.floor(units / 10 ** DISPLAY_DECIMALS);
    const fraction = String(units % 10 ** DISPLAY_DECIMALS).padStart(
        DISPLAY_DECIMALS,
        '0',
    );
    const sign = nanos < 0 && units > 0 ? '-' : '';

    return `${sign}$${dollars.toLocaleString('en-US')}.${fraction}`;
}

/**
 * The full-precision amount, e.g. "$0.070625000".
 */
export function exactUsd(nanos: number): string {
    const absolute = Math.abs(nanos);
    const dollars = Math.floor(absolute / NANOS_PER_DOLLAR);
    const fraction = String(absolute % NANOS_PER_DOLLAR).padStart(9, '0');

    return `${nanos < 0 ? '-' : ''}$${dollars}.${fraction}`;
}
