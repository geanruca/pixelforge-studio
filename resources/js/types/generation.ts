export type GenerationStatus = 'queued' | 'processing' | 'succeeded' | 'failed';

export type Generation = {
    id: number;
    status: GenerationStatus;
    awaiting_slot: boolean;
    progress: {
        retry_attempt: number;
        max_attempts: number;
        accepted_at: string | null;
        started_at: string | null;
    };
    title: string | null;
    prompt: string;
    size: string;
    quality: string;
    reference_images: string[];
    estimated_price_nanos: number;
    reserved_nanos: number;
    price_nanos: number | null;
    error: string | null;
    image_url: string | null;
    image_expired: boolean;
    created_at: string;
    completed_at: string | null;
};

export type PriceEstimate = {
    minimum: number;
    expected: number;
    maximum: number;
};

export type Paginated<T> = {
    data: T[];
    links: { prev: string | null; next: string | null };
    meta: { current_page: number; last_page: number; total: number };
};

export type Option = { value: string; label: string };
