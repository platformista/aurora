import Layout, { Card } from '../Components/Layout';
import { eur, num } from '../format';

export default function Rete({ cycle, uplines, levels, direct }) {
    const total = levels.reduce((a, l) => a + Number(l.n), 0);

    return (
        <Layout title="La mia rete">
            <div className="grid gap-6 lg:grid-cols-3">
                <Card>
                    <h2 className="mb-2 font-medium">La mia linea superiore</h2>
                    {uplines.length === 0 ? <p className="text-sm text-stone-500">Sei al vertice della rete.</p> : (
                        <ol className="space-y-1 text-sm">
                            {uplines.map((u, i) => <li key={u.id} style={{ paddingLeft: i * 10 }}>{u.name} <span className="text-stone-400">{u.code}</span></li>)}
                        </ol>
                    )}
                </Card>
                <Card>
                    <h2 className="mb-2 font-medium">Downline per livello</h2>
                    <table className="w-full text-sm">
                        <tbody>
                            {levels.map((l) => <tr key={l.lvl}><td className="py-0.5">Livello {l.lvl}</td><td className="text-right">{num(l.n)}</td></tr>)}
                            <tr className="border-t border-stone-200 font-semibold"><td className="pt-1">Totale</td><td className="pt-1 text-right">{num(total)}</td></tr>
                        </tbody>
                    </table>
                </Card>
            </div>
            <Card className="mt-6">
                <h2 className="mb-2 font-medium">Incaricate dirette · volume {cycle}</h2>
                <table className="w-full text-sm">
                    <thead className="text-left text-stone-500"><tr><th>Incaricata</th><th>Città</th><th className="text-right">Dirette</th><th className="text-right">Volume</th></tr></thead>
                    <tbody>
                        {direct.map((d) => (
                            <tr key={d.id} className="border-t border-stone-100">
                                <td className="py-1">{d.name} <span className="text-stone-400">{d.code}</span></td>
                                <td>{d.city}</td>
                                <td className="text-right">{num(d.downline)}</td>
                                <td className="text-right">{eur(d.volume_cents)}</td>
                            </tr>
                        ))}
                    </tbody>
                </table>
            </Card>
        </Layout>
    );
}
