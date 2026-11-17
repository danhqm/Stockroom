import React, { useEffect, useState } from 'react';
import { createRoot } from 'react-dom/client';
import '@fontsource-variable/bricolage-grotesque';
import '@fontsource/ibm-plex-mono/latin-400.css';
import '../css/stockroom.css';
import { Landing, Brand } from './Landing.jsx';
import { Workspace } from './Workspace.jsx';
import { api } from './api.js';
import { ArrowRightIcon, ArrowUpRightIcon } from '@phosphor-icons/react';

function SignIn({ onLogin }) {
    const [error, setError] = useState('');
    const [pending, setPending] = useState(false);
    async function signIn(event) {
        event.preventDefault();
        const credentials = Object.fromEntries(new FormData(event.currentTarget));
        setPending(true);
        setError('');
        try { onLogin((await api('/login', { method: 'POST', body: credentials })).user); }
        catch (error) { setError(error.message); }
        finally { setPending(false); }
    }
    return <main className="login">
        <section className="login-image">
            <a href="/" aria-label="Stockroom home"><Brand /></a>
            <p>Know what<br />came in.</p>
        </section>
        <section className="login-main">
            <a className="login-back" href="/">Back to Stockroom <ArrowUpRightIcon /></a>
            <form onSubmit={signIn}>
                <p className="overline">Stockroom workspace</p>
                <h1>Welcome back.</h1>
                <p>Sign in to manage orders and review deliveries.</p>
                <label>Email<input name="email" type="email" autoComplete="username" required /></label>
                <label>Password<input name="password" type="password" autoComplete="current-password" required /></label>
                {error && <p role="alert" className="error">{error}</p>}
                <button className="button primary" disabled={pending}>{pending ? 'Signing in…' : 'Sign in'}<ArrowRightIcon /></button>
            </form>
        </section>
    </main>;
}

function App() {
    const [user, setUser] = useState(undefined);
    const [error, setError] = useState('');
    const workspace = location.pathname.startsWith('/workspace');
    useEffect(() => {
        if (!workspace) return;
        api('/me').then((response) => setUser(response.user)).catch((error) => {
            if (error.status === 401) setUser(null);
            else setError(error.message);
        });
        const expire = () => setUser(null);
        window.addEventListener('stockroom:sign-in', expire);
        return () => window.removeEventListener('stockroom:sign-in', expire);
    }, [workspace]);
    if (!workspace) return <Landing />;
    if (error) return <main className="connection"><Brand /><p role="alert">{error}</p><button className="button primary" onClick={() => location.reload()}>Try again</button></main>;
    if (user === undefined) return <main className="connection"><Brand /><p role="status">Opening workspace…</p></main>;
    return user ? <Workspace user={user} onLogout={() => setUser(null)} /> : <SignIn onLogin={setUser} />;
}

createRoot(document.getElementById('stockroom')).render(<App />);
