/**
 * Whole seconds between two instants, never negative.
 */
export function secondsBetween(from: string | Date, to: string | Date): number {
    return Math.max(
        0,
        Math.floor((new Date(to).getTime() - new Date(from).getTime()) / 1000),
    );
}

/**
 * "8s", "1m 05s".
 */
export function formatDuration(seconds: number): string {
    if (seconds < 60) {
        return `${seconds}s`;
    }

    return `${Math.floor(seconds / 60)}m ${String(seconds % 60).padStart(2, '0')}s`;
}
