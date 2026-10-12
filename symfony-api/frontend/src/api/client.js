let csrfToken = null;

export function setCsrfToken(token) {
    csrfToken = token;
}

export class RequestError extends Error {
    constructor(status, payload) {
        super(`Request failed with status ${status}.`);
        this.name = 'RequestError';
        this.status = status;
        this.payload = payload;
    }
}

const MUTATING = new Set(['POST', 'PUT', 'PATCH', 'DELETE']);

async function request(method, path, body) {
    const headers = { Accept: 'application/json' };
    if (body !== undefined) {
        headers['Content-Type'] = 'application/json';
    }
    if (MUTATING.has(method) && csrfToken) {
        headers['X-CSRF-Token'] = csrfToken;
    }
    const response = await fetch(`/api/v1${path}`, {
        method,
        headers,
        credentials: 'same-origin',
        body: body === undefined ? undefined : JSON.stringify(body),
    });
    if (response.status === 204) {
        return null;
    }
    const payload = await response.json().catch(() => null);
    if (!response.ok) {
        throw new RequestError(response.status, payload);
    }
    return payload;
}

export const api = {
    get: path => request('GET', path),
    post: (path, body) => request('POST', path, body ?? {}),
    put: (path, body) => request('PUT', path, body ?? {}),
    patch: (path, body) => request('PATCH', path, body ?? {}),
    delete: (path, body) => request('DELETE', path, body),
};
