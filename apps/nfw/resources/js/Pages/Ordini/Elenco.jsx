import { usePoll } from '@inertiajs/react';
import Layout, { Badge, Card } from '../../Components/Layout';
import { dateTime, eur } from '../../format';

export default function Elenco({ orders }) {
    // Refresh every 3 s: the status changes when OMS publishes "order.shipped".
    usePoll(3000, { only: ['orders'] });

    return (
        <Layout title="I miei ordini">
            {orders.length === 0 && <p className="text-stone-600">Nessun ordine.</p>}
            <div className="space-y-3">
                {orders.map((o) => (
                    <Card key={o.uuid}>
                        <div className="flex flex-wrap items-center gap-3">
                            <span className="font-mono text-sm text-stone-500">#{o.short}</span>
                            {o.status === 'shipped' ? <Badge tone="green">Spedito</Badge> : <Badge tone="amber">In attesa di spedizione</Badge>}
                            <span className="text-sm text-stone-500">{dateTime(o.placed_at)}</span>
                            <span className="ml-auto font-semibold">{eur(o.total_cents)}</span>
                        </div>
                        {o.lines.length > 0 && (
                            <ul className="mt-2 text-sm text-stone-600">
                                {o.lines.map((l) => <li key={l.sku}>{l.qty} × {l.name}</li>)}
                                {o.gifts.map((g) => <li key={g.sku} className="text-emerald-700">1 × {g.name} (omaggio)</li>)}
                            </ul>
                        )}
                        {o.promotions.length > 0 && (
                            <p className="mt-1 text-xs text-emerald-700">Promozioni: {o.promotions.map((p) => p.label).join(', ')} (− {eur(o.discount_cents)})</p>
                        )}
                        {o.status === 'shipped' && (
                            <p className="mt-2 text-sm text-stone-600">
                                {o.carrier ? <>Corriere {o.carrier}, tracking <span className="font-mono">{o.tracking_code}</span> · </> : null}
                                spedito {dateTime(o.shipped_at)}
                                {o.carrier && <> · <a className="text-emerald-700 hover:underline" href={`/ordini/${o.uuid}/fattura`} target="_blank">Fattura PDF</a></>}
                            </p>
                        )}
                    </Card>
                ))}
            </div>
        </Layout>
    );
}
