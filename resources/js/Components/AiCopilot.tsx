import { Link, useHttp, usePage } from '@inertiajs/react';
import { ArrowUpRight, Bot, LoaderCircle, Send, ShieldCheck, Sparkles, Trash2, User } from 'lucide-react';
import { useState, type FormEvent } from 'react';

export type AssistantConfig = { configured: boolean; model: string; provider: string };
export type ChatMessage = { role: 'user' | 'assistant'; content: string; model?: string; tokens?: number | null; truncated?: boolean; sources?: { id: number; name: string; url: string }[] };
type Reply = { answer: string; model: string; tokens: number | null; truncated: boolean; sources: { id: number; name: string; url: string }[] };
const starters = ['Qaysi mahsulotlarda xarid imkoniyati bor?', 'Xarid qilishdan oldin qaysi ma’lumotlar yetishmayapti?', 'Takliflar orasidagi farqni tushuntiring.'];
export default function AiCopilot({ config, conversation }: { config: AssistantConfig; conversation: ChatMessage[] }) {
    const [messages, setMessages] = useState<ChatMessage[]>(conversation);
    const [error, setError] = useState('');
    const url = new URL(usePage().url, 'http://inertia.local');
    const { data, setData, post, processing, reset, clearErrors } = useHttp<{ message: string; product_id: string | null; quantity: string }, Reply>({ message: '', product_id: url.searchParams.get('product_id'), quantity: url.searchParams.get('quantity') || '1' });
    const clear = useHttp<Record<string, never>, { cleared: boolean }>({});
    async function submit(event: FormEvent) {
        event.preventDefault();
        if (processing || clear.processing || !data.message.trim()) return;
        setError(''); clearErrors();
        const question = data.message.trim();
        let handled = false;
        try {
            const response = await post('/ai/chat', {
                onError: errors => { handled = true; setError(Object.values(errors).join(' ')); },
                onHttpException: response => {
                    handled = true;
                    let message = 'AI so‘rovi bajarilmadi. Hisobga qayta kirib ko‘ring.';
                    try {
                        const body = typeof response.data === 'string' ? JSON.parse(response.data) : response.data;
                        if (typeof body?.message === 'string') message = body.message;
                    } catch { /* Non-JSON error pages use the fallback message. */ }
                    setError(response.status === 429 ? 'So‘rov limiti tugadi. Bir daqiqadan so‘ng qayta urinib ko‘ring.' : message);
                    return false;
                },
                onNetworkError: () => { handled = true; setError('Ulanish uzildi. Internetni tekshirib qayta yuboring.'); return false; },
            });
            setMessages(previous => [...previous, { role: 'user' as const, content: question }, { role: 'assistant' as const, content: response.answer, ...response }].slice(-12));
            reset('message');
        } catch { if (!handled) setError('Javobni olib bo‘lmadi. Qayta urinib ko‘ring.'); }
    }
    async function clearConversation() {
        setError('');
        try { await clear.delete('/ai/chat'); setMessages([]); } catch { setError('Suhbatni tozalab bo‘lmadi. Qayta urinib ko‘ring.'); }
    }
    return <section className="panel copilot" aria-label="OpenRouter AI yordamchi"><header className="copilot-heading"><div className="copilot-avatar"><Sparkles size={23} /></div><div><p className="eyebrow">SMART MARKET COPILOT</p><h2>Ma’lumotlaringiz bilan suhbatlashing.</h2><p>OpenRouter · {config.configured ? config.model : 'API kaliti kutilmoqda'}</p></div><span className={`badge ${config.configured ? 'green' : 'amber'}`}>{config.configured ? 'Sozlangan' : 'Kalit kerak'}</span></header><p className="copilot-privacy"><ShieldCheck size={14} />Savolingiz va shu ish maydonining tanlangan tahlili OpenRouter’ga yuboriladi. AI ma’lumotlarni o‘zgartirmaydi.</p>
        {!config.configured && <div className="notice">AI yordamchini yoqish uchun administrator serverdagi OpenRouter kalitini sozlashi kerak. Narxlarni oddiy solishtirish ishlashda davom etadi.</div>}
        <div className="copilot-messages" role="log" aria-label="AI suhbat" aria-live="polite">{!messages.length && <div className="copilot-empty"><Bot size={30} /><h3>Xaridingiz haqida savol bering.</h3><p>Takliflarni tushunish, yetishmayotgan ma’lumotlarni aniqlash va keyingi qadamni tanlashda yordam beraman.</p></div>}{messages.map((message, index) => <article className={`chat-message ${message.role}`} key={index}><span className="chat-avatar">{message.role === 'assistant' ? <Bot size={17} /> : <User size={17} />}</span><div><strong>{message.role === 'assistant' ? 'Smart Market AI' : 'Siz'}</strong><p>{message.content}</p>{message.sources && message.sources.length > 0 && <div className="chat-sources">{message.sources.map(source => <Link key={source.id} href={source.url}>#{source.id} · {source.name}<ArrowUpRight size={12} /></Link>)}</div>}{message.model && <small>{message.model}{message.tokens !== null && message.tokens !== undefined ? ` · ${message.tokens} token` : ''}</small>}{message.truncated && <p className="chat-truncated">Javob uzunlik chegarasida to‘xtadi. Savolni toraytiring yoki davom ettirishni so‘rang.</p>}</div></article>)}{processing && <div className="copilot-thinking" role="status"><LoaderCircle size={17} className="animate-spin" />Ish maydoni ma’lumotlari tahlil qilinmoqda…</div>}</div>
        {!messages.length && <div className="copilot-starters">{starters.map(starter => <button key={starter} disabled={!config.configured || processing} onClick={() => setData('message', starter)}>{starter}<ArrowUpRight size={13} /></button>)}</div>}
        <form onSubmit={submit} className="copilot-form"><label htmlFor="copilot-question" className="sr-only">AI yordamchiga savol</label><textarea id="copilot-question" placeholder={config.configured ? 'Masalan: 20 kg mahsulot uchun qaysi taklifni tanlaganim ma’qul?' : 'OpenRouter kaliti sozlangach yordamchi ishga tushadi'} value={data.message} maxLength={2000} rows={3} disabled={!config.configured || processing || clear.processing} onChange={event => setData('message', event.target.value)} /><div><small>{data.message.length} / 2000 · Javobni manba bilan tekshiring</small><button className="button primary" disabled={!config.configured || processing || clear.processing || !data.message.trim()}>{processing ? <LoaderCircle size={15} className="animate-spin" /> : <Send size={15} />}{processing ? 'Kutilmoqda…' : 'Yuborish'}</button></div></form>{error && <p className="error" role="alert">{error}</p>}{messages.length > 0 && <button className="copilot-clear" disabled={processing || clear.processing} onClick={clearConversation}><Trash2 size={13} />Suhbatni tozalash</button>}
    </section>;
}
