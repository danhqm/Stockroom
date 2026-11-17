import React, { useEffect, useState } from 'react';
import { ArrowClockwiseIcon, ArrowRightIcon, ArrowUpRightIcon, ClipboardTextIcon, CubeIcon, DoorOpenIcon, GaugeIcon, ListChecksIcon, PackageIcon, TruckIcon } from '@phosphor-icons/react';
import { api } from './api.js';
import { Brand } from './Landing.jsx';
import { Catalogue, Orders } from './Purchasing.jsx';
import { Receipts } from './Receiving.jsx';

const sections = [
    ['overview', 'Overview', GaugeIcon], ['orders', 'Purchase orders', ClipboardTextIcon],
    ['receipts', 'Receipts', ListChecksIcon], ['inventory', 'Inventory', PackageIcon],
    ['products', 'Products', CubeIcon], ['suppliers', 'Suppliers', TruckIcon],
];
import { Back, Empty, Message, Status, date, nameOf, number, openOrder, orderNo, receiptNo, useAction, useQuery } from './workspace-ui.jsx';

function Overview({ data, go }) {
    const open = data.orders.filter(openOrder);
    const drafts = data.receipts.filter((receipt) => receipt.status === 'draft');
    const total = data.inventory.reduce((sum, product) => sum + Number(product.on_hand), 0);
    return <>
        <section className="overview-hero">
            <div><p className="overline"><span className="status-light" /> Receiving workspace</p><h2>Keep the<br />count right.</h2></div>
            <div><p>Follow deliveries from the purchase order to the stock ledger.</p><button className="button primary" onClick={() => go('receipts')}>Go to receipts <ArrowUpRightIcon weight="bold" /></button></div>
        </section>
        <div className="metrics">
            {[[open.length, 'Open purchase orders', 'orders'], [drafts.length, 'Draft receipts', 'receipts'], [total, 'Usable units on hand', 'inventory']].map(([value, label, view]) =>
                <button key={view} onClick={() => go(view)}><span>{label}</span><ArrowUpRightIcon /><strong>{number(value)}</strong></button>)}
        </div>
        <section className="panel">
            <div className="panel-header"><h2>Recent purchase orders</h2><button className="text-button" onClick={() => go('orders')}>View orders <ArrowRightIcon /></button></div>
            {data.orders.length ? <div className="records">{data.orders.slice(0, 5).map((order) => <button className="record" key={order.id} onClick={() => go('orders', order.id)}>
                <span className="row-icon"><ClipboardTextIcon /></span><span><strong>{orderNo(order.id)}</strong><small>{nameOf(data.suppliers, order.supplier_id)}</small></span><Status value={order.status} /><ArrowRightIcon />
            </button>)}</div> : <Empty title="No purchase orders yet">Create an order to start tracking a delivery.</Empty>}
        </section>
    </>;
}

function Inventory({ data, active, go, version }) {
    const product = data.find((item) => item.id === active);
    const movements = useQuery(active ? '/inventory/' + active + '/movements' : null, version, true);
    if (product) return <>
        <Back onClick={() => go('inventory')}>Inventory</Back>
        <section className="panel section-gap">
            <div className="panel-header"><div><p className="overline">{product.sku}</p><h2>{product.name}</h2></div><span className="balance">{number(product.on_hand)} on hand</span></div>
            <Message error={movements.error} loading={!movements.value} />
            {movements.value?.length ? <div className="records">{movements.value.map((movement) => <button className="record" key={movement.id} onClick={() => go('receipts', movement.receipt_id)}>
                <span className="row-icon"><PackageIcon /></span><span><strong>{receiptNo(movement.receipt_id)}</strong><small>{date(movement.created_at)} · User #{movement.actor_id}</small></span>
                <strong className={movement.quantity > 0 ? 'positive' : ''}>{movement.quantity > 0 ? '+' : ''}{number(movement.quantity)}</strong><ArrowRightIcon />
            </button>)}</div> : movements.value && <Empty title="No stock movements">Confirmed or reversed receipts will appear here.</Empty>}
        </section>
    </>;
    return <section className="panel">
        <div className="panel-header"><h2>Stock by product</h2><span className="overline">Usable units</span></div>
        {data.length ? <div className="records">{data.map((item) => <button className="record" key={item.id} onClick={() => go('inventory', item.id)}>
            <span className="row-icon"><PackageIcon /></span><span><strong>{item.name}</strong><small>{item.sku}{item.archived ? ' · Archived' : ''}</small></span>
            <strong>{number(item.on_hand)}</strong><ArrowRightIcon />
        </button>)}</div> : <Empty title="Inventory is empty">Products and their confirmed stock will appear here.</Empty>}
    </section>;
}

function readLocation() {
    const [view, id] = location.hash.slice(1).split('/');
    return [sections.some(([name]) => name === view) ? view : 'overview', id && Number.isSafeInteger(Number(id)) ? Number(id) : null];
}

export function Workspace({ user, onLogout }) {
    const [[view, active], setLocation] = useState(readLocation);
    const [newOrder, setNewOrder] = useState(null);
    const [version, setVersion] = useState(0);
    const refresh = () => setVersion((value) => value + 1);
    const signOut = useAction();
    const required = { overview: ['orders', 'receipts', 'inventory', 'suppliers'], orders: ['orders', 'products', 'suppliers'],
        receipts: ['receipts', 'orders', 'suppliers'], inventory: ['inventory'], products: ['products'], suppliers: ['suppliers'] }[view];
    const orders = useQuery(required.includes('orders') ? '/purchase-orders' : null, version, true);
    const receipts = useQuery(required.includes('receipts') ? '/receipts' : null, version, true);
    const inventory = useQuery(required.includes('inventory') ? '/inventory' : null, version, true);
    const products = useQuery(required.includes('products') ? '/products' : null, version, true);
    const suppliers = useQuery(required.includes('suppliers') ? '/suppliers' : null, version, true);
    const queries = { orders, receipts, inventory, products, suppliers };
    const data = Object.fromEntries(Object.entries(queries).map(([key, result]) => [key, result.value ?? []]));
    const needed = required.map((key) => queries[key]);
    const error = needed.find((result) => result.error)?.error;
    const pending = needed.some((result) => result.value === null);
    const manager = user.role === 'manager';
    useEffect(() => {
        const read = () => { setLocation(readLocation()); setNewOrder(null); };
        window.addEventListener('popstate', read);
        window.addEventListener('hashchange', read);
        return () => { window.removeEventListener('popstate', read); window.removeEventListener('hashchange', read); };
    }, []);
    function go(next, id = null, draftOrder = null) {
        const hash = next + (id ? '/' + id : '');
        if (location.hash.slice(1) !== hash) history.pushState(null, '', '#' + hash);
        setLocation([next, id]);
        setNewOrder(draftOrder);
        window.scrollTo({ top: 0, behavior: 'instant' });
    }
    return <div className="workspace">
        <a href="#workspace-main" className="skip" onClick={(event) => { event.preventDefault(); document.getElementById("workspace-main").focus(); }}>Skip to workspace content</a>
        <aside className="sidebar">
            <a href="/" className="sidebar-brand" aria-label="Stockroom home"><Brand /></a>
            <nav aria-label="Workspace sections">{sections.map(([name, title, Icon]) => <button key={name} onClick={() => go(name)} aria-current={view === name ? 'page' : undefined}><Icon size={20} /> <span>{title}</span>{view === name && <span className="nav-dot" />}</button>)}</nav>
            <div className="sidebar-account">
                <div className="avatar" aria-hidden="true">{user.name.slice(0, 1).toUpperCase()}</div><div><strong>{user.name}</strong><small>{user.role}</small></div>
                <button className="icon-button" title="Sign out" aria-label="Sign out" disabled={signOut.pending} onClick={() => signOut.run(async () => { await api('/logout', { method: 'POST' }); onLogout(); })}><DoorOpenIcon size={20} /></button>
            </div>
        </aside>
        <main className="workspace-main" id="workspace-main" tabIndex={-1}>
            <header className="workspace-header"><div><p className="overline">Stockroom / Workspace</p><h1>{sections.find(([name]) => name === view)[1]}</h1></div>
                <button className="icon-button refresh-button" title="Refresh records" aria-label="Refresh records" onClick={refresh}><ArrowClockwiseIcon size={21} /></button>
            </header>
            <Message error={error || signOut.error} loading={pending} />
            {!error && !pending && <div className="workspace-content" key={view}>
                {view === 'overview' && <Overview data={data} go={go} />}
                {view === 'orders' && <Orders data={data} manager={manager} active={active} go={go} refresh={refresh} version={version} />}
                {view === 'receipts' && <Receipts data={data} manager={manager} active={active} initialOrder={newOrder} go={go} refresh={refresh} version={version} />}
                {view === 'inventory' && <Inventory data={data.inventory} active={active} go={go} version={version} />}
                {['products', 'suppliers'].includes(view) && <Catalogue type={view} records={data[view]} manager={manager} refresh={refresh} />}
            </div>}
        </main>
    </div>;
}
