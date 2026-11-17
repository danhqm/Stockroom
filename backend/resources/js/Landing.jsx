import React, { useEffect, useRef, useState } from 'react';
import { ArrowDownIcon, ArrowRightIcon, ArrowUpRightIcon, BarcodeIcon, CheckIcon, FileTextIcon, PackageIcon, StackIcon } from '@phosphor-icons/react';

import dockImage from '../images/dock.webp';

export function Brand() {
    return <span className="brand"><StackIcon weight="fill" aria-hidden="true" />stockroom<span className="brand-period">.</span></span>;
}

const stages = [
    { name: 'Record the arrival', title: 'A delivery lands.', description: 'Find the purchase order. Start a receipt by entering the counts or attaching a photo of the delivery note.', icon: FileTextIcon },
    { name: 'Review the counts', title: 'Count what’s usable.', description: 'Compare what was ordered, delivered, and damaged. Review every line before confirming the receipt.', icon: PackageIcon },
    { name: 'Confirm the stock', title: 'Trust the count.', description: 'Only usable units go into stock. Every movement links to a receipt and the person who confirmed it.', icon: CheckIcon },
];

function DeliveryExample({ stage }) {
    const Icon = stages[stage].icon;
    return <div className="example-card">
        <div className="example-top"><span><BarcodeIcon size={30} /> <span className="overline">Example delivery</span></span><span className="overline">PO / 1042</span></div>
        <div className="example-body" key={stage}>
            <span className="example-icon"><Icon size={30} /></span>
            <p className="overline">{stages[stage].name}</p>
            <strong>{stage === 0 ? '10' : stage === 1 ? '06' : '+05'}</strong>
            <p>{stage === 0 ? 'Ceramic mugs ordered' : stage === 1 ? 'Delivered · 1 damaged' : 'Usable units added to stock'}</p>
        </div>
        <div className="example-foot"><span className="overline">Ceramic mug / MUG-01</span><span className="overline">{stage === 0 ? 'Issued' : stage === 1 ? 'In review' : 'Confirmed'}</span></div>
    </div>;
}

export function Landing() {
    const [stage, setStage] = useState(0);
    const root = useRef(null);
    const steps = useRef([]);
    useEffect(() => {
        if (matchMedia('(prefers-reduced-motion: reduce)').matches || !('IntersectionObserver' in window)) return;
        const reveal = new IntersectionObserver((entries) => {
            for (const entry of entries) if (entry.isIntersecting) {
                entry.target.classList.add('in-view');
                reveal.unobserve(entry.target);
            }
        }, { threshold: 0.16 });
        for (const element of root.current.querySelectorAll('[data-reveal]')) {
            if (element.getBoundingClientRect().top > innerHeight) element.classList.add('can-reveal');
            reveal.observe(element);
        }
        const sequence = new IntersectionObserver((entries) => {
            for (const entry of entries) if (entry.isIntersecting) setStage(Number(entry.target.dataset.step));
        }, { rootMargin: '-30% 0px -38%' });
        steps.current.forEach((step) => sequence.observe(step));
        return () => { reveal.disconnect(); sequence.disconnect(); };
    }, []);
    return <div className="landing" ref={root}>
        <a className="skip" href="#main">Skip to content</a>
        <div className="scroll-progress" aria-hidden="true" />
        <header className="landing-nav shell">
            <a href="/" aria-label="Stockroom home"><Brand /></a>
            <nav aria-label="Main navigation"><a href="#workflow">Workflow</a><a href="#inventory">Inventory</a></nav>
            <a className="button nav-cta" href="/workspace"><span>Open workspace</span><ArrowUpRightIcon weight="bold" /></a>
        </header>
        <main id="main">
            <section className="hero shell">
                <div className="hero-copy">
                    <h1 aria-label="Know what came in."><span className="line-mask"><span>Know what</span></span><span className="line-mask"><span>came in<span className="mint-stop">.</span></span></span></h1>
                    <div className="hero-aside">
                        <p>Check deliveries against purchase orders and keep a record of what actually went into stock.</p>
                        <a href="#workflow" className="hero-link">See how it works <span><ArrowDownIcon weight="bold" /></span></a>
                    </div>
                </div>
                <figure className="dock-frame">
                    <div className="dock-image"><img src={dockImage} alt="Pallet of cartons at an illuminated warehouse loading bay" fetchPriority="high" /></div>
                    <figcaption><span><span className="status-light" /> Receiving bay</span><span>From delivery to inventory</span></figcaption>
                </figure>
            </section>
            <section className="manifest" id="workflow">
                <div className="shell">
                    <div className="manifest-heading" data-reveal><p className="overline">The receiving workflow</p><h2>From loading dock<br />to <em>stock ledger.</em></h2></div>
                    <div className="workflow-grid">
                        <aside className="example-sticky" aria-label="Interactive example delivery">
                            <DeliveryExample stage={stage} />
                            <div className="example-controls" aria-label="Example steps">
                                {stages.map((item, index) => <button key={item.name} aria-label={item.name} aria-pressed={stage === index} onClick={() => {
                                    setStage(index);
                                    steps.current[index].scrollIntoView({ block: 'center', behavior: matchMedia('(prefers-reduced-motion: reduce)').matches ? 'instant' : 'smooth' });
                                }}><span>{String(index + 1).padStart(2, '0')}</span>{item.name}</button>)}
                            </div>
                        </aside>
                        <div className="workflow-steps">
                            {stages.map((item, index) => <article key={item.name} ref={(element) => { steps.current[index] = element; }} data-step={index} data-reveal>
                                <p className="overline">0{index + 1} / {item.name}</p><h3>{item.title}</h3><p>{item.description}</p>
                            </article>)}
                        </div>
                    </div>
                </div>
            </section>
            <section className="inventory-story" id="inventory">
                <div className="shell story-grid" data-reveal>
                    <div><p className="overline">Inventory history</p><h2>Every unit<br />has a trail.</h2><p>See what came in, what was damaged, and who confirmed the count. If a receipt needs correcting, a manager can reverse it and keep the original entry in the ledger.</p></div>
                    <div className="ledger-example">
                        <p className="overline"><BarcodeIcon size={24} /> Example stock ledger</p>
                        <div className="ledger-product">Ceramic mug <span>MUG-01</span></div>
                        <div className="ledger-row"><span>Receipt confirmed <small>DN-1042</small></span><strong>+5</strong></div>
                        <div className="ledger-row"><span>Receipt confirmed <small>DN-1046</small></span><strong>+5</strong></div>
                        <div className="ledger-total"><span>Usable stock</span><strong>10</strong></div>
                    </div>
                </div>
            </section>
            <section className="closing">
                <div className="shell" data-reveal><div><p className="overline">Ready to receive</p><h2>Open the bay.</h2></div><a href="/workspace" className="button closing-cta">Open workspace <ArrowRightIcon weight="bold" /></a></div>
            </section>
        </main>
        <footer className="landing-footer shell"><a href="/" aria-label="Stockroom home"><Brand /></a><a href="#main">Back to top <ArrowUpRightIcon /></a></footer>
    </div>;
}
