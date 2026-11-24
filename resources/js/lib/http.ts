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
        return 'Der Server antwortet gerade nicht. Deine Antwort ist noch nicht gezählt.';
    }
    if (error instanceof HttpResponseError) {
        if (error.response.status >= 500) {
            return 'Auf dem Server ist etwas schiefgelaufen. Deine Antwort ist noch nicht gezählt.';
        }
        try {
            const body = JSON.parse(error.response.data) as {
                message?: string;
                errors?: Record<string, string[]>;
            };
            const first = Object.values(body.errors ?? {})[0]?.[0];

            return first ?? body.message ?? 'Das hat nicht geklappt.';
        } catch {
            // not JSON
        }
    }

    return 'Keine Verbindung zum Server. Deine Antwort ist noch nicht gezählt.';
}

const dateTime = new Intl.DateTimeFormat('de-DE', {
    weekday: 'short',
    day: 'numeric',
    month: 'short',
    hour: '2-digit',
    minute: '2-digit',
});

export function formatDateTime(iso: string): string {
    return dateTime.format(new Date(iso));
}
