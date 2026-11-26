import { Head, Link, router, usePage } from '@inertiajs/react';

const nav = [
    ['/ordini/nuovo', 'Nuovo ordine'],
    ['/ordini', 'I miei ordini'],
    ['/rete', 'La mia rete'],
    ['/provvigioni', 'Le mie provvigioni'],
    ['/admin', 'Pannello demo'],
];

export default function Layout({ title, children }) {
    const { seller, flash, environment, url } = { ...usePage().props, url: usePage().url };

    return (
        <div className="min-h-screen">
            <Head title={title} />
            <header className="border-b border-stone-200 bg-white">
                <div className="mx-auto flex max-w-6xl flex-wrap items-center gap-x-6 gap-y-2 px-4 py-3">
                    <Link href="/" className="text-lg font-semibold text-emerald-800">Aurora</Link>
                    <span className="rounded bg-stone-100 px-2 py-0.5 text-xs text-stone-500">ambiente: {environment}</span>
                    <nav className="flex flex-wrap gap-1 text-sm">
                        {nav.map(([href, label]) => (
                            <Link key={href} href={href}
                                className={`rounded px-3 py-1.5 ${url.startsWith(href) && (href !== '/ordini' || !url.startsWith('/ordini/nuovo')) ? 'bg-emerald-50 font-medium text-emerald-800' : 'text-stone-600 hover:bg-stone-100'}`}>
                                {label}
                            </Link>
                        ))}
                    </nav>
                    <div className="ml-auto text-sm">
                        {seller ? (
                            <span className="flex items-center gap-2">
                                <span className="text-stone-600">{seller.name} <span className="text-stone-400">({seller.code})</span></span>
                                <button onClick={() => router.post('/esci')} className="text-emerald-700 hover:underline">cambia</button>
                            </span>
                        ) : (
                            <Link href="/accedi" className="text-emerald-700 hover:underline">Accedi</Link>
                        )}
                    </div>
                </div>
            </header>
            <main className="mx-auto max-w-6xl px-4 py-6">
                {flash?.success && <div className="mb-4 rounded border border-emerald-200 bg-emerald-50 px-4 py-2 text-emerald-900">{flash.success}</div>}
                {flash?.error && <div className="mb-4 rounded border border-red-200 bg-red-50 px-4 py-2 text-red-900">{flash.error}</div>}
                <h1 className="mb-5 text-2xl font-semibold">{title}</h1>
                {children}
            </main>
        </div>
    );
}

export function Card({ children, className = '' }) {
    return <div className={`rounded-lg border border-stone-200 bg-white p-4 shadow-sm ${className}`}>{children}</div>;
}

export function Badge({ tone = 'stone', children }) {
    const tones = {
        stone: 'bg-stone-100 text-stone-700',
        green: 'bg-emerald-100 text-emerald-800',
        amber: 'bg-amber-100 text-amber-800',
        red: 'bg-red-100 text-red-800',
    };
    return <span className={`inline-block rounded px-2 py-0.5 text-xs font-medium ${tones[tone]}`}>{children}</span>;
}
