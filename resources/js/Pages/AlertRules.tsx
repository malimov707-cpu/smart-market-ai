import { Form, Link } from '@inertiajs/react';
import Layout from '../Components/Layout';
import { Field } from '../Components/Forms';

type Rule = { id: number; name: string; event_type: string; scope_type: string; scope_id: number | null; threshold_kind: string; threshold_value: string | null; frequency: string; channels: string[]; enabled: boolean };

const eventTypeLabels: Record<string, string> = {
    price_decrease: 'Narx pasayishi', price_increase: 'Narx oshishi', new_offer: 'Yangi taklif', lowest_price_changed: 'Eng arzon narx o‘zgarishi',
    price_verification_approved: 'Narx tekshiruvi tasdiqlandi', price_verification_rejected: 'Narx tekshiruvi rad etildi',
    price_dispute: 'Narx nizo', supplier_verification_changed: 'Yetkazib beruvchi holati o‘zgardi',
    import_completed: 'Import yakunlandi', import_completed_with_errors: 'Import xatolar bilan', import_failed: 'Import muvaffaqiyatsiz',
};
const scopeLabels: Record<string, string> = { product: 'Mahsulot', supplier: 'Yetkazib beruvchi', category: 'Kategoriya', global: 'Barcha mahsulotlar' };

export default function AlertRules({ rules, eventTypes, canManage }: { rules: Rule[]; eventTypes: string[]; canManage: boolean }) {
    return <Layout title="Ogohlantirish qoidalari" action={<Link href="/notifications" className="button secondary">Bildirishnomalar</Link>}>
        <p className="page-description">Qoidalar faqat sizning tashkilotingiz ma’lumotlariga tegishli. Bir xil voqea uchun takroriy bildirishnoma yaratilmaydi (idempotent).</p>
        {canManage && <section className="panel">
            <h2>Yangi qoida</h2>
            <Form action="/notifications/rules" method="post" resetOnSuccess>
                {({ errors, processing }) => <>
                    <div className="form-grid">
                        <Field name="name" label="Qoida nomi" required />
                        <Field name="event_type" label="Voqea turi">{eventTypes.map(t => <option key={t} value={t}>{eventTypeLabels[t] ?? t}</option>)}</Field>
                        <Field name="scope_type" label="Qamrov">
                            <option value="global">Barcha mahsulotlar</option><option value="product">Bitta mahsulot</option>
                            <option value="supplier">Bitta yetkazib beruvchi</option><option value="category">Kategoriya</option>
                        </Field>
                        <Field name="scope_id" label="Scope ID (mahsulot/supplier id yoki kategoriya nomi)" type="number" min="1" required={false} />
                        <Field name="threshold_kind" label="Chegara turi"><option value="none">Yo‘q</option><option value="percent">Foiz (%)</option><option value="absolute">Absolyut</option></Field>
                        <Field name="threshold_value" label="Chegara qiymati" type="number" step="0.0001" min="0" required={false} />
                        <Field name="frequency" label="Chastota"><option value="instant">Darhol</option><option value="daily">Kunlik</option></Field>
                        <Field name="channels" label="Kanal"><option value="app">Ilova ichi</option><option value="email">Email (agar SMTP sozlangan)</option></Field>
                    </div>
                    {Object.values(errors).map(error => <p className="error" role="alert" key={error}>{error}</p>)}
                    <button className="button primary" disabled={processing}>{processing ? 'Saqlanmoqda…' : 'Qoidani yaratish'}</button>
                </>}
            </Form>
        </section>}
        <section className="panel">
            <h2>Mavjud qoidalar</h2>
            {rules.length === 0 && <p className="empty">Hali qoidalar yo‘q. Yuqoridagi shakl orqali birinchi qoidani yarating.</p>}
            <div className="table-scroll"><table>
                <thead><tr><th>Nomi</th><th>Voqea</th><th>Qamrov</th><th>Chegara</th><th>Holat</th><th></th></tr></thead>
                <tbody>{rules.map(rule => <tr key={rule.id}>
                    <td>{rule.name}</td><td>{eventTypeLabels[rule.event_type] ?? rule.event_type}</td>
                    <td>{scopeLabels[rule.scope_type]}{rule.scope_id ? ` #${rule.scope_id}` : ''}</td>
                    <td>{rule.threshold_kind === 'none' ? '—' : `${rule.threshold_value} (${rule.threshold_kind})`}</td>
                    <td>{rule.enabled ? 'Faol' : 'O‘chirilgan'}</td>
                    <td><Form action={`/notifications/rules/${rule.id}`} method="post">{({ processing }) => <>
                        <input type="hidden" name="enabled" value={rule.enabled ? '0' : '1'} />
                        <button className="button secondary small" disabled={processing}>{rule.enabled ? 'O‘chirish' : 'Yoqish'}</button>
                    </>}</Form></td>
                </tr>)}</tbody>
            </table></div>
        </section>
    </Layout>;
}
