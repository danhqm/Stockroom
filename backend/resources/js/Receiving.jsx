import React, { useEffect, useState } from 'react';
import { ArrowRightIcon, FileTextIcon, ListChecksIcon, PlusIcon } from '@phosphor-icons/react';
import { api } from './api.js';
import { Back, Empty, Message, Status, date, nameOf, openOrder, orderNo, receiptNo, useAction, useQuery } from './workspace-ui.jsx';

function NewReceipt({ data, initialOrder, onCreate, cancel }) {
    const [key] = useState(() => crypto.randomUUID());
    const action = useAction();
    const options = data.orders.filter(openOrder);
    return <form className="panel edit-form" onSubmit={(event) => {
        event.preventDefault();
        const form = new FormData(event.currentTarget);
        action.run(async () => {
            const result = await api('/receipts', { method: 'POST', body: {
                purchase_order_id: Number(form.get('order')), reference: form.get('reference') || null, idempotency_key: key, lines: [],
            } });
            onCreate(result.data.id);
        });
    }}>
        <h2>New delivery receipt</h2>
        <label>Purchase order<select name="order" defaultValue={options.some(({ id }) => id === initialOrder) ? initialOrder : ''} required>
            <option value="" disabled>Choose an open order</option>
            {options.map((order) => <option key={order.id} value={order.id}>{orderNo(order.id)} · {nameOf(data.suppliers, order.supplier_id)}</option>)}
        </select></label>
        <label>Delivery note reference <small>Optional</small><input name="reference" maxLength="120" placeholder="e.g. DN-1042" /></label>
        {!options.length && <p className="callout">Issue a purchase order before creating a receipt.</p>}
        <Message error={action.error} />
        <div className="actions"><button className="button primary" disabled={action.pending || !options.length}>Create receipt <ArrowRightIcon /></button><button type="button" className="button secondary" onClick={cancel}>Cancel</button></div>
    </form>;
}

function DeliveryNote({ receipt, onReference }) {
    const [record, setRecord] = useState(null);
    const [loadError, setLoadError] = useState('');
    const [ready, setReady] = useState(false);
    const [consent, setConsent] = useState(false);
    const [imageOpen, setImageOpen] = useState(false);
    const upload = useAction();
    useEffect(() => {
        const controller = new AbortController();
        let timer;
        async function poll() {
            try {
                const response = await api('/receipts/' + receipt.id + '/extraction', { signal: controller.signal });
                if (controller.signal.aborted) return;
                setRecord(response.data);
                setLoadError('');
                if (['queued', 'processing'].includes(response.data.status)) timer = setTimeout(poll, 1800);
            } catch (error) {
                if (controller.signal.aborted) return;
                if (error.status !== 404) setLoadError(error.message);
            } finally {
                if (!controller.signal.aborted) setReady(true);
            }
        }
        poll();
        return () => { controller.abort(); clearTimeout(timer); };
    }, [receipt.id, upload.pending]);
    if (!ready) return null;
    if (!record && receipt.status !== 'draft') return null;
    return <details className="panel note-panel" open={record ? true : undefined}>
        <summary><FileTextIcon size={20} /> Delivery note {record && <Status value={record.status} />}</summary>
        {loadError && <Message error={loadError} />}
        {!record && !loadError && <form onSubmit={(event) => {
            event.preventDefault();
            const body = new FormData(event.currentTarget);
            upload.run(async () => { setRecord((await api('/receipts/' + receipt.id + '/document', { method: 'POST', body })).data); });
        }}>
            <p>Attach one JPEG or PNG up to 2 MB. Review the extracted text against the original and enter the verified counts above.</p>
            <label>Delivery note image<input name="document" type="file" accept="image/jpeg,image/png" required /></label>
            <label className="check-label"><input type="checkbox" checked={consent} onChange={(event) => setConsent(event.target.checked)} required /> Send this image to OpenAI to extract the delivery note.</label>
            <button className="button secondary" disabled={upload.pending || !consent}>{upload.pending ? 'Uploading…' : 'Upload and extract'}</button>
            <Message error={upload.error} />
        </form>}
        {record && <div>
            <button className="text-button" onClick={() => setImageOpen(!imageOpen)}>{imageOpen ? 'Hide' : 'Show'} original delivery note</button>
            {imageOpen && <img className="original-note" src={'/api/receipts/' + receipt.id + '/document'} alt="Original delivery note" />}
            {['queued', 'processing'].includes(record.status) && <p role="status">Extraction is {record.status}. You can continue entering counts manually.</p>}
            {record.status === 'failed' && <p role="status">Extraction unavailable{record.error === 'allowance_exhausted' ? ' because the request limit was reached' : ''}. Enter the counts manually.</p>}
            {record.result && <>
                <p className="callout">Suggested fields · Review and match them to the purchase order before saving.</p>
                <p>{record.result.supplier ?? 'Supplier unreadable'} · {record.result.reference ?? 'Reference unreadable'}</p>
                {record.result.reference && receipt.status === 'draft' && <button className="text-button" onClick={() => onReference(record.result.reference)}>Use suggested reference</button>}
                <div className="table-scroll"><table><thead><tr><th>Text from note</th><th>Quantity</th></tr></thead><tbody>{record.result.lines.map((line, index) =>
                    <tr key={index}><td>{line.source_text}</td><td>{line.quantity ?? 'Unreadable'}</td></tr>)}</tbody></table></div>
            </>}
        </div>}
    </details>;
}

function ReceiptDetail({ receipt, order, manager, data, go, refresh }) {
    const fill = Object.fromEntries(receipt.lines.map((line) => [line.purchase_order_line_id, { delivered: String(line.delivered), damaged: String(line.damaged) }]));
    const [counts, setCounts] = useState(fill);
    const [reference, setReference] = useState(receipt.reference ?? '');
    const [reverse, setReverse] = useState(false);
    const [updated, setUpdated] = useState('');
    const action = useAction(refresh);
    const dirty = reference !== (receipt.reference ?? '') || order.lines.some((line) => {
        const original = fill[line.id] ?? { delivered: '', damaged: '0' };
        return counts[line.id] && (counts[line.id].delivered !== original.delivered || counts[line.id].damaged !== original.damaged);
    });
    const editable = receipt.status === 'draft' && openOrder(order);
    const reviewed = receipt.lines.reduce((sum, item) => sum + Number(item.usable), 0);
    function update(line, field, value) {
        const entry = counts[line] ?? { delivered: '', damaged: '0' };
        setCounts({ ...counts, [line]: { ...entry, [field]: value, ...(field === 'delivered' && value === '' ? { damaged: '0' } : {}) } });
    }
    function save(event) {
        event.preventDefault();
        const lines = order.lines.filter((line) => counts[line.id]?.delivered !== '' && counts[line.id]?.delivered !== undefined)
            .map((line) => ({ purchase_order_line_id: line.id, delivered: Number(counts[line.id].delivered), damaged: Number(counts[line.id].damaged) }));
        action.run(async () => {
            await api('/receipts/' + receipt.id, { method: 'PUT', body: { reference: reference.trim() || null, lines, revision: receipt.revision } });
        });
    }
    function act(operation, body) {
        action.run(async () => {
            await api('/receipts/' + receipt.id + '/' + operation, { method: 'POST', body });
            setReverse(false);
            setUpdated(operation === 'confirm' ? 'Receipt confirmed. Inventory updated.' : 'Receipt reversed. Inventory updated.');
        });
    }
    return <>
        <Back onClick={() => go('receipts')}>Receipts</Back>
        <section className="panel section-gap">
            <div className="panel-header"><div><button className="text-button overline" onClick={() => go('orders', order.id)}>{orderNo(order.id)} · {nameOf(data.suppliers, order.supplier_id)}</button><h2>{receiptNo(receipt.id)}</h2></div><Status value={receipt.status} /></div>
            {receipt.duplicate_reference && <p className="callout">Another receipt has this delivery note reference for the same supplier. Check for a duplicate before confirming.</p>}
            {receipt.status === 'draft' && !editable && <p className="callout">The purchase order is no longer open for receiving.</p>}
            <form className="receipt-form" onSubmit={save}>
                {editable ? <label className="reference-field">Delivery note reference<input value={reference} onChange={(event) => setReference(event.target.value)} maxLength="120" /></label> : <p className="reference-display">Delivery note · {receipt.reference || 'No reference'}</p>}
                <div className="table-scroll"><table><thead><tr><th scope="col">Product</th><th scope="col">Delivered</th><th scope="col">Damaged</th><th scope="col">Usable</th></tr></thead>
                <tbody>{order.lines.filter((line) => editable || fill[line.id]).map((line) => {
                    const entry = counts[line.id] ?? { delivered: '', damaged: '0' };
                    const actual = Number(entry.delivered) - Number(entry.damaged);
                    return <tr key={line.id}><td><strong>{line.name}</strong><small>{line.sku}{editable ? ' · ' + line.outstanding + ' outstanding' : ''}</small></td>
                        <td>{editable ? <input type="number" aria-label={'Delivered · ' + line.name} min="1" max="1000000" value={entry.delivered} onChange={(event) => update(line.id, 'delivered', event.target.value)} /> : entry.delivered}</td>
                        <td>{editable ? <input type="number" aria-label={'Damaged · ' + line.name} min="0" max={entry.delivered || 0} required={entry.delivered !== ''} disabled={entry.delivered === ''} value={entry.damaged} onChange={(event) => update(line.id, 'damaged', event.target.value)} /> : entry.damaged}</td>
                        <td>{entry.delivered ? <strong className={actual < 0 || (editable && actual > line.outstanding) ? 'invalid-count' : ''}>{actual}</strong> : '—'}</td>
                    </tr>;
                })}</tbody></table></div>
                {editable && <div className="actions detail-actions"><button className="button secondary" disabled={action.pending || !dirty}>Save review</button>
                    <button type="button" className="button primary" disabled={action.pending || dirty || !receipt.lines.length} onClick={() => act('confirm', { revision: receipt.revision })}>Confirm receipt <ArrowRightIcon /></button>
                    <small>{dirty ? 'Save your changes before confirming.' : receipt.lines.length ? reviewed + ' usable units ready to confirm.' : 'Enter the delivered counts, then save your review.'}</small></div>}
            </form>
            {updated && <p className="callout" role="status">{updated}</p>}
            <Message error={action.error} />
            {receipt.status === 'reversed' && <p className="callout">Reversed · {receipt.reverse_reason}</p>}
            {manager && receipt.status === 'confirmed' && !reverse && <div className="actions detail-actions"><button className="button secondary" onClick={() => setReverse(true)}>Reverse receipt</button></div>}
            {manager && receipt.status === 'confirmed' && reverse && <form className="inline-form" onSubmit={(event) => {
                event.preventDefault();
                act('reverse', { reason: new FormData(event.currentTarget).get('reason') });
            }}><label>Reason for reversal<textarea name="reason" maxLength="500" required autoFocus /></label>
                <div className="actions"><button className="button danger" disabled={action.pending}>Reverse receipt</button><button type="button" className="button secondary" onClick={() => setReverse(false)}>Cancel</button></div>
            </form>}
        </section>
        <DeliveryNote receipt={receipt} onReference={setReference} />
    </>;
}

export function Receipts({ data, manager, active, initialOrder, go, refresh, version }) {
    const [creating, setCreating] = useState(false);
    const receipt = useQuery(active ? '/receipts/' + active : null, version);
    const order = useQuery(receipt.value ? '/purchase-orders/' + receipt.value.purchase_order_id : null, version);
    if (active) {
        if (!receipt.value || !order.value) return <><Back onClick={() => go('receipts')}>Receipts</Back><Message error={receipt.error || order.error} loading /></>;
        return <ReceiptDetail key={receipt.value.id + '-' + receipt.value.revision} receipt={receipt.value} order={order.value}
            manager={manager} data={data} go={go} refresh={refresh} />;
    }
    if (creating || initialOrder) return <NewReceipt initialOrder={initialOrder} data={data} onCreate={(id) => { setCreating(false); refresh(); go('receipts', id); }}
        cancel={() => { setCreating(false); go('receipts'); }} />;
    return <section className="panel">
        <div className="panel-header"><h2>Delivery receipts</h2><button className="button primary" onClick={() => setCreating(true)}><PlusIcon /> New receipt</button></div>
        {data.receipts.length ? <div className="records">{data.receipts.map((item) => <button key={item.id} className="record" onClick={() => go('receipts', item.id)}>
            <span className="row-icon"><ListChecksIcon /></span><span><strong>{receiptNo(item.id)} · {orderNo(item.purchase_order_id)}</strong><small>{item.reference || date(item.created_at)}</small></span><Status value={item.status} /><ArrowRightIcon />
        </button>)}</div> : <Empty title="No receipts yet">Start a new receipt when a delivery arrives.</Empty>}
    </section>;
}
