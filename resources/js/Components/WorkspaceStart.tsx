import { Link } from '@inertiajs/react';
import { ArrowUpRight, Check, ChevronRight, Database, GitCompareArrows, Package, Truck } from 'lucide-react';

export default function WorkspaceStart({ stats }: { stats: { products: number; suppliers: number; offers: number } }) {
    const steps = [
        { label: 'Katalogni yarating', description: 'Mahsulot, birlik va kategoriyalar', done: stats.products > 0, icon: Package, href: '/products' },
        { label: 'Hamkorlarni qo‘shing', description: 'Yetkazib beruvchilar tarmog‘i', done: stats.suppliers > 0, icon: Truck, href: '/suppliers' },
        { label: 'Narxlarni kiriting', description: 'Manbasi ko‘rsatilgan takliflar', done: stats.offers > 0, icon: Database, href: '/offers' },
    ];
    const completed = steps.filter(step => step.done).length;
    return <section className="workspace-start"><div className="section-header"><div><p className="eyebrow">{completed === 3 ? 'KEYINGI OQILONA QADAM' : 'BIRINCHI NATIJAGACHA 3 QADAM'}</p><h2>{completed === 3 ? 'Ma’lumotlaringiz qaror qabul qilishga tayyor.' : 'Xaridlar markazingizni ishga tayyorlang.'}</h2></div><span className="badge neutral">{completed} / 3 tayyor</span></div><div className="start-steps">{steps.map((step, index) => <Link href={step.href} key={step.href} className={step.done ? 'complete' : ''}><span className="start-step-icon">{step.done ? <Check size={19} /> : <step.icon size={19} />}</span><span><small>0{index + 1}</small><strong>{step.label}</strong><p>{step.description}</p></span><ChevronRight size={15} /></Link>)}</div><Link href={completed === 3 ? '/ai' : '/import-center'} className="start-next">{completed === 3 ? <GitCompareArrows size={16} /> : <Database size={16} />}<span>{completed === 3 ? 'Xarid ssenariylari va imkoniyatlarni ko‘rish' : 'Excel yoki CSV faylingiz bormi? Import orqali tez boshlang.'}</span><ArrowUpRight size={16} /></Link></section>;
}
