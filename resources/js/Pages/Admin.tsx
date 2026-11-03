import { Form, Link, usePage } from '@inertiajs/react';
import { Activity, ArrowLeft, LogOut, Search, ShieldCheck, Users } from 'lucide-react';
import AdminShell from '../Components/AdminShell';
import { Pager } from '../Components/Forms';
import { date, type Pagination, type Shared } from '../types';

type AdminUser = { id: number; name: string; email: string; is_active: boolean; is_suspended: boolean; is_platform_admin: boolean; email_verified_at: string | null };
type OverviewData = {
    trends: { days: string[]; series: Record<string, number[]> };
    health: {
        dependencies: { database?: string; pending_jobs?: number; failed_jobs?: number; pending_schema_changes?: number; storage_directory?: string; worker?: string };
        sources: { source: string; status: string; detail: string; last_success_at: string | null; last_error_at: string | null }[];
        scheduler: { status: string; detail: string };
        notifications: { status: string; detail: string };
    };
    activity: { type: string; action: string; actor: string | null; organization: string | null; occurred_at: string }[];
};
type Props = {
    assistant: { configured: boolean; model: string; provider: string } | null;
    overviewData: OverviewData | null;
    stats: { users: number; products: number; offers: number; suspended: number; [key: string]: number };
    filters: { q: string; status: string; tab: string };
    operations: { database?: string; pending_jobs?: number; failed_jobs?: number; pending_schema_changes?: number; storage_directory?: string; worker?: string };
    imports: { id: number; file_name: string; status: string; row_count: number; error_count: number }[];
    users: Pagination<AdminUser>;
    events: { id: number; user_id: number; action: string; created_at: string }[];
};

export default function Admin({ stats, filters, users, events, operations, imports, assistant, overviewData }: Props) {
    const { auth } = usePage<Shared>().props;
    const overview = filters.tab === 'overview';
    const metricCards = [
        ['Foydalanuvchilar', stats.users, 'Jami hisoblar'],
        ['Yangi hisoblar', stats.new_users_30d, 'So‘nggi 30 kun'],
        ['Tashkilotlar', stats.organizations, 'Jami ro‘yxatdan o‘tgan'],
        ['Faol tashkilotlar', stats.active_organizations_30d, '30 kunda audit faolligi'],
        ['Mahsulotlar', stats.products, 'Jami katalog yozuvlari'],
        ['Yetkazib beruvchilar', stats.suppliers, 'Arxivlanmagan'],
        ['Narx takliflari', stats.offers, 'Barcha yozuvlar'],
        ['Tasdiq kutilmoqda', stats.pending_supplier_verifications, 'Yetkazib beruvchilar'],
        ['Narx ko‘rib chiqishlari', stats.pending_price_reviews, 'Platforma navbatida'],
        ['Importlar', stats.completed_imports_30d, '30 kunda yakunlangan'],
        ['Import xatolari', stats.failed_imports_30d, '30 kun ichida'],
        ['AI ekstraksiyalari', stats.import_ai_attempts_30d, 'Import hujjatlarida · 30 kun'],
        ['AI xatolari', stats.import_ai_failures_30d, 'Import ekstraksiyasida · 30 kun'],
        ['Bloklangan userlar', stats.suspended, 'Kirish cheklangan'],
    ] as const;

    return <AdminShell tab={filters.tab}>
        {overview && <section className="admin-welcome">
            <div>
                <span className="engine-label"><span className="status-dot" /> PLATFORM CONTROL CENTER</span>
                <h2>Butun tizim.<br /><em>Bitta boshqaruv markazi.</em></h2>
                <p>{auth.name}, foydalanuvchilar va tizim holatini<br />shu paneldan boshqaring.</p>
                <div><Link className="button primary" href="/admin?tab=users">Foydalanuvchilarni boshqarish <ArrowLeft size={15} className="rotate-180" /></Link><Link className="button secondary" href="/admin?tab=operations">Tizim holati</Link></div>
            </div>
            <div className="control-visual" aria-hidden="true">
                <div className="control-orbit orbit-one" /><div className="control-orbit orbit-two" /><div className="control-orbit orbit-three" />
                <div className="control-logo"><ShieldCheck size={45} strokeWidth={1} /><strong>SM<span>AI</span></strong><small>CONTROL CORE</small></div>
                <span className="orbit-node node-one"><Users size={18} /></span><span className="orbit-node node-three"><Activity size={18} /></span>
            </div>
        </section>}
        {overview && <div className="stats-grid admin-metrics">{metricCards.map(([label, value, detail]) => <section className="stat-card" key={label}><div>{label}<Activity size={17} /></div><strong>{(value ?? 0).toLocaleString('uz-UZ')}</strong><small>{detail}</small></section>)}</div>}
        {overview && <div className="admin-overview-grid">
            <section className="admin-trend-grid">
                <TrendCard title="Yangi foydalanuvchilar" days={overviewData?.trends.days ?? []} values={overviewData?.trends.series.users ?? []} />
                <TrendCard title="Yangi tashkilotlar" days={overviewData?.trends.days ?? []} values={overviewData?.trends.series.organizations ?? []} />
                <TrendCard title="Yangi mahsulotlar" days={overviewData?.trends.days ?? []} values={overviewData?.trends.series.products ?? []} />
                <TrendCard title="Yakunlangan importlar" days={overviewData?.trends.days ?? []} values={overviewData?.trends.series.completed_imports ?? []} />
                <TrendCard title="Import AI urinishlari" days={overviewData?.trends.days ?? []} values={overviewData?.trends.series.import_ai_attempts ?? []} />
            </section>
            <section className="panel attention-panel">
                <p className="eyebrow">E’TIBOR MARKAZI</p><h2>Harakat talab qiladigan holatlar</h2>
                <Link href="/admin?tab=users&status=suspended"><span className="attention-icon"><ShieldCheck size={18} /></span><span><strong>Bloklangan hisoblar</strong><small>Kirish nazorati</small></span><b>{stats.suspended}</b></Link>
                <Link href="/admin?tab=operations"><span className="attention-icon gold"><Activity size={18} /></span><span><strong>Xato bilan tugagan vazifalar</strong><small>Navbatdagi vazifalar</small></span><b>{overviewData?.health.dependencies.failed_jobs ?? '—'}</b></Link>
                <div className="attention-row"><span className="attention-icon gold"><Activity size={18} /></span><span><strong>Tasdiq kutilayotgan yetkazib beruvchilar</strong><small>Platforma verifikatsiyasi</small></span><b>{stats.pending_supplier_verifications ?? 0}</b></div>
                <div className="attention-row"><span className="attention-icon gold"><Activity size={18} /></span><span><strong>Ko‘rib chiqiladigan narxlar</strong><small>Oxirgi holat bo‘yicha</small></span><b>{stats.pending_price_reviews ?? 0}</b></div>
            </section>
        </div>}
        {overview && overviewData && <>
            <section className="panel admin-health-panel">
                <div className="section-header"><div><p className="eyebrow">INTEGRATSIYALAR</p><h2>Manbalar holati</h2></div><span className="badge neutral">Kesh diagnostikasi · jonli tekshiruv emas</span></div>
                <div className="source-status-grid">{overviewData.health.sources.map(source => <article className="source-status" key={source.source}>
                    <div><strong>{source.source}</strong><span className={`badge ${source.status === 'cached' ? 'green' : source.status === 'failed' ? 'amber' : 'neutral'}`}>{sourceStatusLabel[source.status] ?? source.status}</span></div>
                    <p>{source.detail}</p>
                    <small>{source.last_success_at ? `Oxirgi muvaffaqiyat: ${date(source.last_success_at)}` : source.last_error_at ? `Oxirgi xato: ${date(source.last_error_at)}` : 'Sinxronlash vaqti qayd etilmagan'}</small>
                </article>)}</div>
                <div className="health-notes">
                    <p><strong>Scheduler:</strong> Holati noma’lum — heartbeat yozuvi yo‘q.</p>
                    <p><strong>Platforma bildirishnomalari:</strong> Mavjud emas — platforma darajasidagi yetkazish funksiyasi joriy qilinmagan.</p>
                </div>
            </section>
            <section className="panel admin-activity">
                <div className="section-header"><div><p className="eyebrow">PLATFORMA FAOLLIGI</p><h2>Yaqindagi hodisalar</h2></div><Link href="/admin?tab=security">Xavfsizlik jurnaliga o‘tish</Link></div>
                {overviewData.activity.map((event, index) => <div className="activity" key={`${event.type}-${event.occurred_at}-${index}`}>
                    <span>{event.type}</span><code className="admin-event">{event.action}</code>
                    <small>{event.actor ?? 'Tizim'}{event.organization ? ` · ${event.organization}` : ''}</small>
                    <small>{date(event.occurred_at)}</small>
                </div>)}
                {!overviewData.activity.length && <p className="empty">Hozircha platforma hodisalari yo‘q.</p>}
            </section>
        </>}
        {filters.tab === 'users' && <section>
            <div className="section-header"><div><h2><Users size={18} className="inline" /> Foydalanuvchilar</h2><p>{stats.suspended} ta bloklangan hisob</p></div></div>
            <Form action="/admin" method="get" className="search-form admin-search">
                <input type="hidden" name="tab" value={filters.tab} />
                <select name="status" aria-label="Hisob holati" defaultValue={filters.status}><option value="all">Barcha hisoblar</option><option value="active">Faol</option><option value="suspended">Bloklangan</option><option value="unverified">Email tasdiqlanmagan</option><option value="admins">Administratorlar</option></select>
                <input name="q" aria-label="Foydalanuvchi qidirish" placeholder="Ism yoki email bo‘yicha qidirish" defaultValue={filters.q} />
                <button className="button secondary"><Search size={16} />Qidirish</button>
                {filters.q && <Link className="button secondary" href="/admin">Tozalash</Link>}
            </Form>
            <div className="table-panel"><div className="table-scroll"><table><thead><tr><th>Foydalanuvchi</th><th>Holat</th><th>Rol</th><th>Boshqaruv</th></tr></thead><tbody>{users.data.map(user => <tr key={user.id}>
                <td><strong>{user.name}</strong><small>{user.email}</small></td>
                <td><span className={`badge ${user.is_suspended || !user.is_active ? 'amber' : 'green'}`}>{user.is_suspended ? 'Bloklangan' : user.is_active ? 'Faol' : 'Faolsiz'}</span><small>{user.email_verified_at ? 'Email tasdiqlangan' : 'Email tasdiqlanmagan'}</small></td>
                <td><span className="badge neutral">{user.is_platform_admin ? 'Admin' : 'User'}</span></td>
                <td>{user.is_platform_admin ? <small>Himoyalangan admin hisobi</small> : <div className="admin-actions"><AdminAction id={user.id} action={user.is_suspended ? 'restore' : 'suspend'} label={user.is_suspended ? 'Blokdan chiqarish' : 'Bloklash'} /><AdminAction id={user.id} action="revoke_sessions" label="Sessiyalarni uzish" /></div>}</td>
            </tr>)}</tbody></table></div>{!users.data.length && <p className="empty">Qidiruv bo‘yicha foydalanuvchi topilmadi.</p>}<Pager page={users} /></div>
        </section>}
        {filters.tab === 'operations' && <>
            <section className="panel"><p className="eyebrow">OPENROUTER · AI YORDAMCHI</p><h2>{assistant?.configured ? 'API kaliti sozlangan' : 'API kaliti kutilmoqda'}</h2><p>Model: {assistant?.model ?? '—'}. Kalit faqat serverda saqlanadi. Sozlangan holat haqiqiy API so‘rovi muvaffaqiyatini tasdiqlamaydi.</p><p>Kalit serverdagi OPENROUTER_API_KEY orqali, model OPENROUTER_MODEL orqali boshqariladi.</p><Link className="button primary" href="/ai">AI yordamchini ochish</Link></section>
            <section className="panel"><p className="eyebrow">OPERATSIYALAR</p><h2>Tizimning haqiqiy holati</h2><div className="system-grid">{[['Ma’lumotlar bazasi', operations.database === 'reachable' ? 'Ulangan' : 'Tekshirish kerak'], ['Navbatdagi vazifalar', operations.pending_jobs ?? '—'], ['Xato vazifalar', operations.failed_jobs ?? '—'], ['Kutilayotgan migratsiyalar', operations.pending_schema_changes ?? '—'], ['Fayllar ombori', operations.storage_directory === 'accessible' ? 'Mavjud' : 'Tekshirish kerak']].map(([label, value]) => <div key={label}><small>{label}</small><strong>{value}</strong></div>)}</div><p className="chart-note">Bu ichki ulanish tekshiruvi. Tashqi AI va email xizmatlarining ishlashini tasdiqlamaydi.</p></section>
            <section className="table-panel"><div className="section-header import-heading"><h2>Oxirgi importlar</h2></div><div className="table-scroll"><table><thead><tr><th>Fayl</th><th>Holat</th><th>Satrlar / xatolar</th></tr></thead><tbody>{imports.map(batch => <tr key={batch.id}><td><strong>{batch.file_name}</strong><small>Import #{batch.id}</small></td><td><span className="badge neutral">{batch.status}</span></td><td>{batch.row_count} / {batch.error_count}</td></tr>)}</tbody></table></div>{!imports.length && <p className="empty">Hozircha importlar yo‘q.</p>}</section>
        </>}
        {filters.tab === 'security' && <section className="panel"><h2><ShieldCheck size={18} className="inline" /> Xavfsizlik jurnali</h2><p>Oxirgi 20 ta hodisa. Admin amallari bajaruvchi va foydalanuvchi ID si bilan saqlanadi.</p>{events.map(event => <div className="activity" key={event.id}><span>#{event.user_id}</span><code className="admin-event">{event.action}</code><small>{date(event.created_at)}</small></div>)}{!events.length && <p>Hozircha hodisalar yo‘q.</p>}</section>}
    </AdminShell>;
}

const sourceStatusLabel: Record<string, string> = {
    cached: 'Keshda bor',
    disabled: 'O‘chirilgan',
    failed: 'Xatolik',
    unknown: 'Noma’lum',
};

function TrendCard({ title, days, values }: { title: string; days: string[]; values: number[] }) {
    const maximum = Math.max(1, ...values);

    return <section className="panel trend-card">
        <div className="section-header"><div><p className="eyebrow">14 KUN · UTC</p><h2>{title}</h2></div></div>
        <div className="activity-chart" role="img" aria-label={`${title}: ${values.reduce((total, value) => total + value, 0)} ta`}>
            {days.map((day, index) => {
                const value = values[index] ?? 0;

                return <div key={day} title={`${day}: ${value}`}>
                    <span>{value}</span><i style={{ height: `${Math.max(2, value / maximum * 100)}%` }} /><small>{day.slice(8)}</small>
                </div>;
            })}
        </div>
    </section>;
}

function AdminAction({ id, action, label }: { id: number; action: string; label: string }) {
    return <Form action={`/admin/users/${id}`} method="post" onBefore={() => window.confirm(`${label}: amalni tasdiqlaysizmi?`)}>{({ processing, errors }) => <><input type="hidden" name="action" value={action} /><button disabled={processing} className="button secondary small">{action === 'revoke_sessions' && <LogOut size={12} />}{processing ? 'Kutilmoqda…' : label}</button>{errors.action && <p className="error" role="alert">{errors.action}</p>}</>}</Form>;
}
