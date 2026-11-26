import { router } from '@inertiajs/react';
import { useState } from 'react';
import Layout, { Card } from '../Components/Layout';

export default function Accedi({ sellers, q }) {
    const [search, setSearch] = useState(q);

    return (
        <Layout title="Scegli l'incaricata">
            <p className="mb-4 text-stone-600">Demo senza password: scegli con quale incaricata navigare. Dati inventati.</p>
            <form onSubmit={(e) => { e.preventDefault(); router.get('/accedi', { q: search }, { preserveState: true }); }} className="mb-4 flex gap-2">
                <input value={search} onChange={(e) => setSearch(e.target.value)} placeholder="Codice, cognome o città"
                    className="w-80 rounded border border-stone-300 px-3 py-2" />
                <button className="rounded bg-emerald-700 px-4 py-2 text-white">Cerca</button>
            </form>
            <div className="grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
                {sellers.map((s) => (
                    <button key={s.id} onClick={() => router.post('/accedi', { seller_id: s.id })} className="text-left">
                        <Card className="hover:border-emerald-400">
                            <div className="font-medium">{s.name}</div>
                            <div className="text-sm text-stone-500">{s.code} · {s.city} ({s.province})</div>
                            <div className="mt-1 text-xs text-stone-500">livello {s.depth} · {s.downline} incaricate dirette</div>
                        </Card>
                    </button>
                ))}
            </div>
        </Layout>
    );
}
