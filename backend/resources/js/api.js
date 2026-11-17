export async function api(path, { method = 'GET', body, signal } = {}) {
    const headers = { Accept: 'application/json' };
    if (method !== 'GET') {
        const csrf = await api('/csrf', { signal });
        headers['X-CSRF-TOKEN'] = csrf.token;
        if (!(body instanceof FormData) && body !== undefined) {
            headers['Content-Type'] = 'application/json';
            body = JSON.stringify(body);
        }
    }
    let response;
    try {
        response = await fetch('/api' + path, { method, headers, body, signal, credentials: 'same-origin', cache: 'no-store' });
    } catch (error) {
        if (error.name === 'AbortError') throw error;
        throw new Error('Could not reach Stockroom. Check your connection and try again.');
    }
    if (response.status === 204) return null;
    const result = await response.json().catch(() => ({}));
    if (!response.ok) {
        if (response.status === 401 && path !== '/me') window.dispatchEvent(new Event('stockroom:sign-in'));
        const error = new Error(Object.values(result.errors ?? {}).flat()[0] ||
            (response.status === 419 ? 'Your session changed. Try again.' : result.message || 'Stockroom could not complete that request.'));
        error.status = response.status;
        throw error;
    }
    return result;
}

export async function all(path, signal) {
    const data = [];
    let page = 1;
    let result;
    do {
        result = await api(path + (path.includes('?') ? '&' : '?') + 'page=' + page++, { signal });
        data.push(...result.data);
    } while (page <= result.last_page);
    return data;
}
