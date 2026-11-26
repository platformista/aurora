import { router } from '@inertiajs/react';
import { useEffect, useState } from 'react';
import Layout, { Badge, Card } from '../Components/Layout';
import { dateTime, eur, num, seconds } from '../format';

const appNames = { nfw: 'NFW / Bridge', oms: 'OMS', promotions: 'Promotion Engine' };

export default function Admin({ cycle }) {
    const [stats, setStats] = useState(null);

    useEffect(() => {
        let alive = true;
        const load = async () => {
            try {
                const res = await fetch('/admin/stats', { headers: { Accept: 'application/json' } });
                if (alive && res.ok) setStats(await res.json());
            } catch { /* keep the last figures */ }
        };
        load();
        const timer = setInterval(load, 2000);
        return () => { alive = false; clearInterval(timer); };
    }, []);

    const down = stats?.broker_down;
    const nfw = stats?.apps?.[0];
    const oms = stats?.apps?.[1];

    return (
        <Layout title="Pannello demo">
            <div className="grid gap-6 lg:grid-cols-3">
                <Card className={down ? 'border-red-300 bg-red-50' : ''}>
                    <h2 className="font-medium">Simula broker non disponibile</h2>
                    <p className="mt-1 text-sm text-stone-600">
                        Simulazione: i relay di tutte e tre le applicazioni si collegano a un indirizzo dove non risponde nessuno,
                        come se Kafka fosse irraggiungibile. Il servizio Kafka gestito resta acceso: non è possibile spegnerlo a comando.
                    </p>
                    <button onClick={() => router.post('/admin/broker', { down: !down }, { preserveScroll: true })}
                        className={`mt-3 w-full rounded px-4 py-2 font-medium text-white ${down ? 'bg-emerald-700' : 'bg-red-700'}`}>
                        {down ? 'Ripristina il broker' : 'Simula broker non disponibile'}
                    </button>
                    <p className="mt-2 text-sm">Stato: {stats == null ? '…' : down ? <Badge tone="red">broker «non disponibile» (simulato)</Badge> : <Badge tone="green">broker raggiungibile</Badge>}</p>
                </Card>

                <Card>
                    <h2 className="font-medium">Consegna esattamente una volta</h2>
                    <dl className="mt-2 space-y-1 text-sm">
                        <Line label="Ordini registrati da NFW (con evento)" value={num(nfw?.orders_sent)} />
                        <Line label="Spedizioni create da OMS" value={oms?.unreachable ? 'n/d' : num(oms?.shipments)} />
                        <Line label="Ordini in attesa di spedizione" value={num(nfw?.orders_awaiting_shipment)} />
                        <Line label="Duplicati scartati da OMS" value={oms?.unreachable ? 'n/d' : num(oms?.duplicates_skipped)} />
                    </dl>
                    {nfw && oms && !oms.unreachable && (
                        nfw.orders_sent === oms.shipments
                            ? <p className="mt-2 text-sm text-emerald-700">Tutti gli ordini sono arrivati all'OMS, nessuno due volte.</p>
                            : <p className="mt-2 text-sm text-amber-700">{num(nfw.orders_sent - oms.shipments)} ordini in viaggio verso l'OMS…</p>
                    )}
                </Card>

                <Card>
                    <h2 className="font-medium">Provvigioni · {cycle}</h2>
                    <p className="mt-1 text-sm text-stone-600">Il calcolo gira sulla coda Horizon «commissions», nel worker dedicato.</p>
                    <div className="mt-3 flex gap-2">
                        <button onClick={() => router.post('/admin/provvigioni', {}, { preserveScroll: true })} className="flex-1 rounded bg-emerald-700 px-4 py-2 text-white">Calcola ora</button>
                        <a href="/horizon" target="_blank" className="rounded border border-stone-300 px-4 py-2 text-stone-700">Horizon</a>
                    </div>
                </Card>
            </div>

            <h2 className="mb-3 mt-8 text-lg font-semibold">Outbox ed eventi per applicazione</h2>
            <div className="grid gap-6 lg:grid-cols-3">
                {(stats?.apps ?? []).map((a) => (
                    <Card key={a.app}>
                        <div className="flex items-center justify-between">
                            <h3 className="font-medium">{appNames[a.app] ?? a.app}</h3>
                            {a.unreachable ? <Badge tone="red">non raggiungibile</Badge> : a.outbox_pending > 0 ? <Badge tone="amber">backlog</Badge> : <Badge tone="green">in pari</Badge>}
                        </div>
                        {!a.unreachable && (
                            <>
                                <div className="mt-3 text-4xl font-semibold tabular-nums">{num(a.outbox_pending)}</div>
                                <div className="text-sm text-stone-500">eventi in outbox da pubblicare{a.oldest_pending_seconds != null && ` · il più vecchio da ${a.oldest_pending_seconds} s`}</div>
                                <dl className="mt-3 space-y-1 text-sm">
                                    <Line label="Eventi pubblicati" value={num(a.outbox_published)} />
                                    <Line label="Eventi consumati" value={num(a.events_processed)} />
                                    <Line label="Duplicati scartati" value={num(a.duplicates_skipped)} />
                                    {a.invoices != null && <Line label={`Fatture PDF (${a.invoice_storage})`} value={num(a.invoices)} />}
                                    {a.redemptions != null && <Line label="Promozioni utilizzate" value={num(a.redemptions)} />}
                                </dl>
                                {a.last_error && <p className="mt-2 break-words text-xs text-red-700">Ultimo errore: {a.last_error}</p>}
                            </>
                        )}
                    </Card>
                ))}
            </div>

            <h2 className="mb-3 mt-8 text-lg font-semibold">Ultimi calcoli provvigioni</h2>
            <Card>
                <table className="w-full text-sm">
                    <thead className="text-left text-stone-500">
                        <tr><th>#</th><th>Ciclo</th><th>Avvio</th><th>Origine</th><th>Stato</th><th className="text-right">Ordini</th><th className="text-right">Job</th><th className="text-right">Beneficiarie</th><th className="text-right">Totale</th><th className="text-right">Durata</th></tr>
                    </thead>
                    <tbody>
                        {(stats?.runs ?? []).map((r) => (
                            <tr key={r.id} className="border-t border-stone-100">
                                <td className="py-1">{r.id}</td>
                                <td>{r.cycle}</td>
                                <td>{dateTime(r.queued_at)}</td>
                                <td>{r.triggered_by}</td>
                                <td><Badge tone={r.status === 'completed' ? 'green' : r.status === 'failed' ? 'red' : 'amber'}>{r.status}</Badge></td>
                                <td className="text-right">{num(r.orders_count)}</td>
                                <td className="text-right">{r.chunks}</td>
                                <td className="text-right">{num(r.beneficiaries_count)}</td>
                                <td className="text-right">{eur(r.total_cents)}</td>
                                <td className="text-right font-semibold">{seconds(r.duration_ms)}</td>
                            </tr>
                        ))}
                    </tbody>
                </table>
            </Card>
            <p className="mt-4 text-xs text-stone-400">Aggiornato {stats ? dateTime(stats.at) : '…'} · aggiornamento ogni 2 secondi</p>
        </Layout>
    );
}

function Line({ label, value }) {
    return <div className="flex justify-between"><dt className="text-stone-600">{label}</dt><dd className="tabular-nums">{value}</dd></div>;
}
