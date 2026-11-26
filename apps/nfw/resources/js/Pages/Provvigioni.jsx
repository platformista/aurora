import Layout, { Badge, Card } from '../Components/Layout';
import { dateTime, eur } from '../format';

export default function Provvigioni({ cycle, volume_cents, threshold_cents, rows }) {
    const active = volume_cents >= threshold_cents;

    return (
        <Layout title="Le mie provvigioni">
            <Card className="mb-6">
                <div className="text-sm text-stone-500">{cycle}</div>
                <div className="mt-1 flex items-center gap-3">
                    <span className="text-xl font-semibold">{eur(volume_cents)}</span>
                    <span className="text-stone-600">volume personale</span>
                    {active ? <Badge tone="green">attiva</Badge> : <Badge tone="amber">sotto la soglia di {eur(threshold_cents)}</Badge>}
                </div>
                <p className="mt-2 text-sm text-stone-500">
                    Regole: 10% sul volume personale; 6/4/3/2/1% sugli ordini della rete per i primi cinque livelli di incaricate attive (compressione dinamica).
                </p>
            </Card>
            <Card>
                <table className="w-full text-sm">
                    <thead className="text-left text-stone-500">
                        <tr><th>Ciclo</th><th>Calcolato il</th><th className="text-right">Volume</th><th className="text-right">Personale</th><th className="text-right">Rete</th><th className="text-right">Totale</th></tr>
                    </thead>
                    <tbody>
                        {rows.length === 0 && <tr><td colSpan="6" className="py-3 text-stone-500">Nessun calcolo ancora eseguito.</td></tr>}
                        {rows.map((r) => (
                            <tr key={r.run_id} className="border-t border-stone-100">
                                <td className="py-1">{r.cycle}</td>
                                <td>{dateTime(r.finished_at)}</td>
                                <td className="text-right">{eur(r.personal_volume_cents)}</td>
                                <td className="text-right">{eur(r.personal_cents)}</td>
                                <td className="text-right">{eur(r.network_cents)}</td>
                                <td className="text-right font-semibold">{eur(r.personal_cents + r.network_cents)}</td>
                            </tr>
                        ))}
                    </tbody>
                </table>
            </Card>
        </Layout>
    );
}
