import Layout from '../Components/Layout';
import { timestamp } from '../Components/PriceFacts';

type Stats = { products: number; suppliers: number; offers: number; fresh_offers: number; pending_verification: number };
type Movement = { product_id: number; product_name: string; category: string | null; observations: number; latest_price: string; currency: string; change_percent: string | null; observed_at: string | null };
type SupplierRow = { id: number; name: string; verification_status: string; offers_count: number; last_observed_at: string | null };
type RecentImport = { id: number; type: string; format: string; file_name: string; status: string; row_count: number; error_count: number; completed_at: string | null; created_at: string };

export default function AnalyticsDashboard({ stats, imports, priceMovement, supplierActivity }: { stats: Stats; imports: RecentImport[]; priceMovement: Movement[]; supplierActivity: SupplierRow[] }) {
    return <Layout title="Tahlil va hisobotlar" action={<a href="/analytics/export" className="button secondary">Narx hisoboti (CSV)</a>}>
        <p className="page-description">Barcha ko‘rsatkichlar haqiqiy bazaviy ma’lumotlardan hisoblanadi; soxta qiymat ishlatilmaydi.</p>
        <section className="stat-grid">
            {[['Mahsulotlar', stats.products], ['Faol yetkazib beruvchilar', stats.suppliers], ['Jami narx kuzatuvlari', stats.offers],
            ['Yangi (7 kun) kuzatuvlar', stats.fresh_offers], ['Kutilayotgan narx tekshiruvi', stats.pending_verification]].map(([label, value]) =>
                <div className="stat-card" key={label as string}><strong>{value as number}</strong><span>{label as string}</span></div>)}
        </section>
        <section className="panel">
            <h2>Narx harakati (mahsulotlar bo‘yicha)</h2>
            {priceMovement.length === 0 && <p className="empty">Hali narx kuzatuvlari yo‘q. Import yoki qo‘lda narx yozuvchilanganda shu yerda trend ko‘rinadi.</p>}
            {priceMovement.length > 0 && <div className="table-scroll"><table>
                <thead><tr><th>Mahsulot</th><th>Kategoriya</th><th>Kuzatuvlar</th><th>Oxirgi narx</th><th>O‘zgarish %</th><th>Vaqt</th></tr></thead>
                <tbody>{priceMovement.map(m => <tr key={m.product_id}>
                    <td>{m.product_name}</td><td>{m.category ?? '—'}</td><td>{m.observations}</td>
                    <td>{m.latest_price} {m.currency}</td>
                    <td>{m.change_percent === null ? '—' : <span className={Number(m.change_percent) < 0 ? 'down' : 'up'}>{Number(m.change_percent) > 0 ? '+' : ''}{m.change_percent}%</span>}</td>
                    <td>{m.observed_at ? timestamp(m.observed_at) : '—'}</td>
                </tr>)}</tbody></table></div>}
            <p><small>Taqqoslash faqat bir xil valyutadagi kuzatuvlar o‘rtasida; valyuta konvertatsiyasi Phase 3 qoidalari bo‘yicha.</small></p>
        </section>
        <section className="panel">
            <h2>Yetkazib beruvchi faolligi</h2>
            {supplierActivity.length === 0 && <p className="empty">Yetkazib beruvchi takliflari yo‘q.</p>}
            {supplierActivity.length > 0 && <div className="table-scroll"><table>
                <thead><tr><th>Yetkazib beruvchi</th><th>Holat</th><th>Takliflar</th><th>Oxirgi kuzatuv</th></tr></thead>
                <tbody>{supplierActivity.map(s => <tr key={s.id}>
                    <td>{s.name}</td><td>{s.verification_status}</td><td>{s.offers_count}</td>
                    <td>{s.last_observed_at ? timestamp(s.last_observed_at) : '—'}</td>
                </tr>)}</tbody></table></div>}
        </section>
        <section className="panel">
            <h2>Yaqin importlar</h2>
            {imports.length === 0 && <p className="empty">Importlar yo‘q.</p>}
            {imports.length > 0 && <div className="table-scroll"><table>
                <thead><tr><th>Fayl</th><th>Holat</th><th>Satrlar</th><th>Yakunlangan</th></tr></thead>
                <tbody>{imports.map(i => <tr key={i.id}>
                    <td><a href={`/import-center/${i.id}`}>#{i.id} · {i.file_name}</a></td><td>{i.status}</td>
                    <td>{i.row_count} ({i.error_count} xato)</td>
                    <td>{i.completed_at ? timestamp(i.completed_at) : '—'}</td>
                </tr>)}</tbody></table></div>}
        </section>
    </Layout>;
}