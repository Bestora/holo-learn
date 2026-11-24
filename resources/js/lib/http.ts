import { http, HttpCancelledError, HttpResponseError } from '@inertiajs/core';

/** JSON POST through Inertia's HTTP client (sends the XSRF token). Throws on 4xx/5xx and after 15 s. */
export async function postJson<T>(url: string, data: unknown = {}): Promise<T> {
    const response = await http.getClient().request({
        method: 'post',
        url,
        data,
        headers: { Accept: 'application/json' },
        signal: AbortSignal.timeout(15000),
    });

    return (response.data ? JSON.parse(response.data) : null) as T;
}

/** The first validation message of a failed request, else a generic one. */
export function errorMessage(error: unknown): string {
    if (error instanceof HttpCancelledError) {
        return 'The server is not responding. Your answer has not been counted yet.';
    }
    if (error instanceof HttpResponseError) {
        if (error.response.status >= 500) {
            return 'Something went wrong on the server. Your answer has not been counted yet.';
        }
        try {
            const body = JSON.parse(error.response.data) as {
                message?: string;
                errors?: Record<string, string[]>;
            };
            const first = Object.values(body.errors ?? {})[0]?.[0];

            return first ?? body.message ?? 'That did not work.';
        } catch {
            // not JSON
        }
    }

    return 'No connection to the server. Your answer has not been counted yet.';
}

const dateTime = new Intl.DateTimeFormat('en-GB', {
    weekday: 'short',
    day: 'numeric',
    month: 'short',
    hour: '2-digit',
    minute: '2-digit',
});

export function formatDateTime(iso: string): string {
    return dateTime.format(new Date(iso));
}
