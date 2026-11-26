import { router } from '@inertiajs/react';
import { useMemo, useState } from 'react';
import Layout, { Card } from '../../Components/Layout';
import { eur, xsrfHeader } from '../../format';

export default function Nuovo({ products }) {
    const [qty, setQty] = useState({});
    const [quote, setQuote] = useState(null);
    const [error, setError] = useState(null);
    const [busy, setBusy] = useState(false);

    const byCategory = useMemo(() => products.reduce((acc, p) => ({ ...acc, [p.category]: [...(acc[p.category] ?? []), p] }), {}), [products]);
    const items = Object.values(qty).reduce((a, b) => a + (Number(b) || 0), 0);

    const change = (sku, value) => {
        setQty({ ...qty, [sku]: Math.max(0, Math.min(99, Number(value) || 0)) });
        setQuote(null);
    };

    const calculate = async () => {
        setBusy(true);
        setError(null);
        const res = await fetch('/ordini/preventivo', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', Accept: 'application/json', ...xsrfHeader() },
            body: JSON.stringify({ qty }),
        });
        const body = await res.json();
        setBusy(false);
        if (!res.ok) return setError(body.error ?? 'Errore');
        setQuote(body.quote);
    };

    const confirm = () => router.post('/ordini', { qty }, { onStart: () => setBusy(true), onFinish: () => setBusy(false) });

    return (
        <Layout title="Nuovo ordine">
            <div className="grid gap-6 lg:grid-cols-3">
                <div className="space-y-6 lg:col-span-2">
                    {Object.entries(byCategory).map(([category, list]) => (
                        <Card key={category}>
                            <h2 className="mb-2 font-medium text-emerald-800">{category}</h2>
                            <table className="w-full text-sm">
                                <tbody>
                                    {list.map((p) => (
                                        <tr key={p.sku} className="border-t border-stone-100">
                                            <td className="py-2 text-stone-400">{p.sku}</td>
                                            <td className="py-2">{p.name}</td>
                                            <td className="py-2 text-right">{eur(p.price_cents)}</td>
                                            <td className="w-24 py-2 text-right">
                                                <input type="number" min="0" max="99" value={qty[p.sku] ?? 0}
                                                    onChange={(e) => change(p.sku, e.target.value)}
                                                    className="w-16 rounded border border-stone-300 px-2 py-1 text-right" />
                                            </td>
                                        </tr>
                                    ))}
                                </tbody>
                            </table>
                        </Card>
                    ))}
                </div>
                <div>
                    <Card className="sticky top-4">
                        <h2 className="mb-3 font-medium">Riepilogo</h2>
                        <p className="mb-3 text-sm text-stone-600">{items} articoli nel carrello</p>
                        <button disabled={!items || busy} onClick={calculate}
                            className="mb-3 w-full rounded border border-emerald-700 px-4 py-2 text-emerald-800 disabled:opacity-40">
                            Calcola prezzo
                        </button>
                        {error && <p className="mb-3 text-sm text-red-700">{error}</p>}
                        {quote && (
                            <div className="space-y-1 text-sm">
                                <Row label="Subtotale" value={eur(quote.subtotal_cents)} />
                                {quote.discounts.map((d) => <Row key={d.code} label={d.label} value={`− ${eur(d.amount_cents)}`} tone="text-emerald-700" />)}
                                {quote.gifts.map((g) => <Row key={g.sku} label={`Omaggio: ${g.name}`} value="gratis" tone="text-emerald-700" />)}
                                <div className="border-t border-stone-200 pt-1"><Row label="Totale" value={eur(quote.total_cents)} bold /></div>
                                <p className="pt-1 text-xs text-stone-400">Prezzo calcolato dal Promotion Engine (chiamata interna sincrona).</p>
                                <button disabled={busy} onClick={confirm} className="mt-3 w-full rounded bg-emerald-700 px-4 py-2 text-white disabled:opacity-40">
                                    Conferma ordine
                                </button>
                            </div>
                        )}
                    </Card>
                </div>
            </div>
        </Layout>
    );
}

function Row({ label, value, tone = '', bold = false }) {
    return (
        <div className={`flex justify-between ${tone} ${bold ? 'font-semibold' : ''}`}>
            <span>{label}</span><span>{value}</span>
        </div>
    );
}
