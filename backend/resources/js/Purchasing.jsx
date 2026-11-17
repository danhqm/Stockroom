import React, { useState } from 'react';
import { ArrowRightIcon, ClipboardTextIcon, PlusIcon, TrashIcon } from '@phosphor-icons/react';
import { api } from './api.js';
import { Back, Empty, Message, Status, date, nameOf, openOrder, orderNo, useAction, useQuery } from './workspace-ui.jsx';

export function Catalogue({ type, records, manager, refresh }) {
    const [edit, setEdit] = useState(null);
    const action = useAction(refresh);
    const product = type === 'products';
    const noun = product ? 'product' : 'supplier';
    return <section className="panel">
        <div className="panel-header"><h2>{product ? 'Product catalogue' : 'Supplier directory'}</h2>
            {manager && !edit && <button className="button primary" onClick={() => setEdit({})}><PlusIcon /> Add {noun}</button>}
        </div>
        {edit && <form className="inline-form" key={edit.id ?? 'create'} onSubmit={(event) => {
            event.preventDefault();
            const form = new FormData(event.currentTarget);
            const payload = { name: form.get('name') };
            if (product) Object.assign(payload, { sku: form.get('sku'), archived: form.has('archived') });
            action.run(async () => {
                await api('/' + type + (edit.id ? '/' + edit.id : ''), { method: edit.id ? 'PUT' : 'POST', body: payload });
                setEdit(null);
            });
        }}><h3>{edit.id ? 'Edit' : 'Add'} {noun}</h3>
            <div className="field-grid"><label>Name<input name="name" maxLength="160" defaultValue={edit.name} required autoFocus /></label>
                {product && <label>SKU<input name="sku" maxLength="64" defaultValue={edit.sku} required /></label>}</div>
            {product && edit.id && <label className="check-label"><input type="checkbox" name="archived" defaultChecked={Boolean(edit.archived)} /> Archived</label>}
            <div className="actions"><button className="button primary" disabled={action.pending}>Save {noun}</button><button type="button" className="button secondary" onClick={() => setEdit(null)}>Cancel</button></div>
        </form>}
        <Message error={action.error} />
        {records.length ? <div className="catalogue-list">{records.map((record) => <div className="catalogue-row" key={record.id}>
            <span><strong>{record.name}</strong>{product && <small>{record.sku}</small>}</span>
            {record.archived ? <Status value="archived" /> : null}
            {manager && <button className="text-button" onClick={() => setEdit(record)}>Edit <ArrowRightIcon /></button>}
        </div>)}</div> : <Empty title={'No ' + type + ' yet'}>{manager ? 'Add a record to use it on purchase orders.' : 'A manager can add records here.'}</Empty>}
    </section>;
}

function OrderForm({ order, data, saved, cancel }) {
    const [lines, setLines] = useState(() => order?.lines.map(({ product_id, quantity }) => ({ product_id, quantity })) ?? [{ product_id: '', quantity: 1 }]);
    const action = useAction();
    const activeProducts = data.products.filter((item) => !item.archived);
    return <form className="panel edit-form" onSubmit={(event) => {
        event.preventDefault();
        const form = new FormData(event.currentTarget);
        action.run(async () => {
            const response = await api(order ? '/purchase-orders/' + order.id : '/purchase-orders', {
                method: order ? 'PUT' : 'POST',
                body: { supplier_id: Number(form.get('supplier')), lines: lines.map(({ product_id, quantity }) => ({ product_id: Number(product_id), quantity: Number(quantity) })) },
            });
            saved(response.data.id);
        });
    }}>
        <h2>{order ? 'Edit ' + orderNo(order.id) : 'New purchase order'}</h2>
        <label>Supplier<select name="supplier" aria-label="Supplier" defaultValue={order?.supplier_id ?? ''} required><option value="" disabled>Choose a supplier</option>{data.suppliers.map((item) => <option key={item.id} value={item.id}>{item.name}</option>)}</select></label>
        <fieldset><legend>Order lines</legend>{lines.map((line, i) => <div className="line-edit" key={i}>
            <label>Product<select aria-label="Product" value={line.product_id} required onChange={(event) => setLines((rows) => rows.map((row, index) => index === i ? { ...row, product_id: event.target.value } : row))}>
                <option value="" disabled>Choose a product</option>{data.products.filter((item) => !item.archived || item.id === Number(line.product_id)).map((item) =>
                    <option key={item.id} value={item.id} disabled={Boolean(item.archived) || lines.some((row, index) => index !== i && Number(row.product_id) === item.id)}>{item.name} · {item.sku}{item.archived ? ' (archived)' : ''}</option>)}
            </select></label>
            <label>Quantity<input type="number" value={line.quantity} min="1" max="1000000" required onChange={(event) => setLines((rows) => rows.map((row, index) => index === i ? { ...row, quantity: event.target.value } : row))} /></label>
            <button type="button" disabled={lines.length === 1} className="icon-button" aria-label={'Remove line ' + (i + 1)} onClick={() => setLines((rows) => rows.filter((_, index) => index !== i))}><TrashIcon /></button>
        </div>)}
        <button type="button" className="text-button" disabled={lines.length >= Math.min(100, activeProducts.length)} onClick={() => setLines((rows) => [...rows, { product_id: '', quantity: 1 }])}><PlusIcon /> Add line</button></fieldset>
        {!data.suppliers.length || !activeProducts.length ? <p className="callout">Add a supplier and an active product before creating an order.</p> : null}
        <Message error={action.error} />
        <div className="actions"><button className="button primary" disabled={action.pending || !data.suppliers.length || !activeProducts.length}>Save draft</button><button type="button" className="button secondary" onClick={cancel}>Cancel</button></div>
    </form>;
}

function OrderDetail({ order, data, manager, go, refresh, edit }) {
    const action = useAction(refresh);
    const [closing, setClosing] = useState(false);
    function act(operation, body) {
        action.run(async () => {
            await api('/purchase-orders/' + order.id + '/' + operation, { method: 'POST', body });
            setClosing(false);
        });
    }
    return <>
        <Back onClick={() => go('orders')}>Purchase orders</Back>
        <section className="panel section-gap">
            <div className="panel-header"><div><p className="overline">{nameOf(data.suppliers, order.supplier_id)}</p><h2>{orderNo(order.id)}</h2></div><Status value={order.status} /></div>
            <div className="table-scroll"><table><thead><tr><th>Product</th><th>Ordered</th><th>Received</th><th>Outstanding</th></tr></thead>
                <tbody>{order.lines.map((line) => <tr key={line.id}><td><strong>{line.name}</strong><small>{line.sku}</small></td><td>{line.quantity}</td><td>{line.received}</td><td>{line.outstanding}</td></tr>)}</tbody></table></div>
            {order.close_reason && <p className="callout">Closed: {order.close_reason}</p>}
            <Message error={action.error} />
            <div className="actions detail-actions">
                {openOrder(order) && <button className="button primary" onClick={() => go('receipts', null, order.id)}>Receive delivery <ArrowRightIcon /></button>}
                {manager && order.status === 'draft' && <><button className="button primary" disabled={action.pending} onClick={() => act('issue')}>Issue order</button><button className="button secondary" onClick={edit}>Edit draft</button></>}
                {manager && openOrder(order) && !closing && <button className="button secondary" onClick={() => setClosing(true)}>Close order</button>}
            </div>
            {closing && <form className="inline-form" onSubmit={(event) => { event.preventDefault(); act('close', { reason: new FormData(event.currentTarget).get('reason') }); }}>
                <label>Reason for closing<textarea name="reason" maxLength="500" required autoFocus /></label>
                <div className="actions"><button className="button primary" disabled={action.pending}>Close order</button><button type="button" className="button secondary" onClick={() => setClosing(false)}>Cancel</button></div>
            </form>}
        </section>
    </>;
}

export function Orders({ data, manager, active, go, refresh, version }) {
    const [editing, setEditing] = useState(false);
    const order = useQuery(active ? '/purchase-orders/' + active : null, version);
    function saved(id) { setEditing(false); refresh(); go('orders', id); }
    if (active && !order.value) return <><Back onClick={() => { setEditing(false); go('orders'); }}>Purchase orders</Back><Message error={order.error} loading /></>;
    if (editing) return <OrderForm key={active ?? 'create'} order={order.value} data={data} saved={saved} cancel={() => setEditing(false)} />;
    if (order.value) return <OrderDetail key={active} order={order.value} data={data} manager={manager} go={go} refresh={refresh} edit={() => setEditing(true)} />;
    return <section className="panel">
        <div className="panel-header"><h2>Orders</h2>{manager && <button className="button primary" onClick={() => setEditing(true)}><PlusIcon /> New order</button>}</div>
        {data.orders.length ? <div className="records">{data.orders.map((item) => <button key={item.id} className="record" onClick={() => go('orders', item.id)}>
            <span className="row-icon"><ClipboardTextIcon /></span><span><strong>{orderNo(item.id)} · {nameOf(data.suppliers, item.supplier_id)}</strong><small>{date(item.created_at)}</small></span><Status value={item.status} /><ArrowRightIcon />
        </button>)}</div> : <Empty title="No purchase orders">{manager ? 'Create a draft, add products, then issue it for receiving.' : 'Issued orders will appear here.'}</Empty>}
    </section>;
}
