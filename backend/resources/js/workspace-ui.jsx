import React, { useEffect, useState } from 'react';
import { ArrowLeftIcon, PackageIcon } from '@phosphor-icons/react';
import { api, all } from './api.js';

export const openOrder = (order) => ['issued', 'partially_received'].includes(order.status);
export const number = (value) => new Intl.NumberFormat().format(value);
export const orderNo = (id) => 'PO-' + String(id).padStart(4, '0');
export const receiptNo = (id) => 'RC-' + String(id).padStart(4, '0');
export const statusText = (status) => status.replaceAll('_', ' ');
export const date = (value) => value ? new Intl.DateTimeFormat(undefined, { dateStyle: 'medium' }).format(new Date(value.replace(' ', 'T') + (value.includes('T') ? '' : 'Z'))) : '—';
export const nameOf = (rows, id) => rows.find((row) => row.id === id)?.name ?? 'Record #' + id;

export function Status({ value }) { return <span className={'status status-' + value}>{statusText(value)}</span>; }

export function Empty({ title, children }) {
    return <div className="empty"><PackageIcon size={28} /><h3>{title}</h3>{children && <p>{children}</p>}</div>;
}

export function Back({ onClick, children }) {
    return <button type="button" className="text-button" onClick={onClick}><ArrowLeftIcon />{children}</button>;
}

export function useQuery(path, version = 0, paged = false) {
    const [state, setState] = useState({ path: null, value: null, error: '' });
    useEffect(() => {
        if (!path) return;
        const controller = new AbortController();
        const request = paged ? all(path, controller.signal) : api(path, { signal: controller.signal }).then((response) => response.data);
        request.then((value) => {
            if (!controller.signal.aborted) setState({ path, value, error: '' });
        }).catch((error) => {
            if (!controller.signal.aborted) setState({ path, value: null, error: error.message });
        });
        return () => controller.abort();
    }, [path, version, paged]);
    return state.path === path ? state : { value: null, error: '' };
}

export function useAction(onChange) {
    const [pending, setPending] = useState(false);
    const [error, setError] = useState('');
    async function run(action) {
        setPending(true);
        setError('');
        try { const result = await action(); onChange?.(); return result; }
        catch (error) { setError(error.message); return null; }
        finally { setPending(false); }
    }
    return { pending, error, run };
}

export function Message({ error, loading }) {
    if (error) return <p className="error" role="alert">{error}</p>;
    return loading ? <p className="loading" role="status">Loading records…</p> : null;
}
