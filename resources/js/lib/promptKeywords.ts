/**
 * Pixelforge's test keywords (`[fail]`, `[slow]`…) steer what the API does
 * with a prompt. Where a prompt is displayed they are shown as coloured
 * badges, each still containing its literal text, so selecting and copying a
 * prompt gives back exactly what was typed.
 */
const KEYWORDS: Record<string, { hint: string; classes: string }> = {
    succeed: {
        hint: 'Never fails at random',
        classes:
            'border-emerald-300 bg-emerald-50 text-emerald-800 dark:border-emerald-800 dark:bg-emerald-950 dark:text-emerald-200',
    },
    fail: {
        hint: 'Accepted, then fails while processing. Not charged.',
        classes:
            'border-red-300 bg-red-50 text-red-800 dark:border-red-800 dark:bg-red-950 dark:text-red-200',
    },
    blocked: {
        hint: 'Rejected by the content policy at submit. Not charged.',
        classes:
            'border-rose-300 bg-rose-50 text-rose-800 dark:border-rose-800 dark:bg-rose-950 dark:text-rose-200',
    },
    overloaded: {
        hint: 'The image service answers that it is overloaded',
        classes:
            'border-amber-300 bg-amber-50 text-amber-800 dark:border-amber-800 dark:bg-amber-950 dark:text-amber-200',
    },
    slow: {
        hint: 'Processes for 60 to 90 seconds',
        classes:
            'border-sky-300 bg-sky-50 text-sky-800 dark:border-sky-800 dark:bg-sky-950 dark:text-sky-200',
    },
    duplicate: {
        hint: 'Every webhook event is delivered twice',
        classes:
            'border-violet-300 bg-violet-50 text-violet-800 dark:border-violet-800 dark:bg-violet-950 dark:text-violet-200',
    },
};

export type PromptPart = {
    text: string;
    keyword: { hint: string; classes: string } | null;
};

/**
 * Split a prompt into plain text and keyword parts. Joining the parts' text
 * always gives back the original prompt, character for character.
 */
export function splitPrompt(prompt: string): PromptPart[] {
    return prompt
        .split(/(\[[a-z]+\])/gi)
        .filter((text) => text !== '')
        .map((text) => ({
            text,
            keyword: KEYWORDS[text.slice(1, -1).toLowerCase()] ?? null,
        }))
        .map((part) =>
            /^\[[a-z]+\]$/i.test(part.text) ? part : { ...part, keyword: null },
        );
}
