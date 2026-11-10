import { useCallback, useEffect, useMemo, useState } from 'react';
import { useNavigate } from 'react-router-dom';
import { api } from '../../../services/api';
import { demoCustomers, demoDashboard, demoWhatsApp } from './demoData';
import '../../../styles/admin-insights.css';

// Panel v2: Dashboard (números), Clientes y WhatsApp.
// Si el servidor todavía no tiene estas funciones (404), se muestran datos de ejemplo con un aviso,
// para poder revisar el diseño antes de que los desarrolladores publiquen el servidor.

const cop = (n) => `$${Math.round(Number(n) || 0).toLocaleString('es-CO')}`;
const pct = (now, before) => (before ? Math.round(((now - before) / before) * 100) : null);
const isoDay = (d) => new Date(d.getTime() - d.getTimezoneOffset() * 60000).toISOString().slice(0, 10);
const shortDate = (iso) => { if (!iso) return '—'; const d = new Date(iso.length === 10 ? `${iso}T12:00:00` : iso); return d.toLocaleDateString('es-CO', { day: 'numeric', month: 'short' }).replace(' de ', ' ').replace('.', ''); };
const notFound = (e) => e && (e.status === 404 || e.status === 405);
const TOPICS = { mayorista: ['Mayorista', 'ai-t-may'], soporte: ['Soporte', 'ai-t-sop'], ayuda_compra: ['Ayuda para comprar', 'ai-t-ayu'] };
const WA_STATUS = { NEW: ['Nueva', 'ai-s-new'], ATTENDING: ['En atención', 'ai-s-att'], RESOLVED: ['Resuelta', 'ai-s-res'] };
const waLink = (phone, text) => `https://wa.me/${String(phone || '').replace(/[^\d]/g, '').replace(/^(?!57)(\d{10})$/, '57$1')}?text=${encodeURIComponent(text)}`;

function DemoNotice() {
  return <p className='ai-demo' role='note'><b>Datos de ejemplo.</b> Esta sección mostrará los datos reales cuando los desarrolladores publiquen la actualización del servidor.</p>;
}

function useLoad(loader, demo, deps) {
  const [state, setState] = useState({ loading: true, data: null, error: '', isDemo: false });
  const load = useCallback(() => {
    const controller = new AbortController();
    setState((s) => ({ ...s, loading: true, error: '' }));
    loader(controller.signal)
      .then((data) => setState({ loading: false, data, error: '', isDemo: false }))
      .catch((e) => { if (e?.name === 'AbortError') return; setState(notFound(e) ? { loading: false, data: demo(), error: '', isDemo: true } : { loading: false, data: null, error: e?.message || 'No se pudo cargar.', isDemo: false }); });
    return () => controller.abort();
  // eslint-disable-next-line react-hooks/exhaustive-deps
  }, deps);
  useEffect(() => load(), [load]);
  return [state, load];
}

/* ---------------- Dashboard ---------------- */
export function AdminInsightsDashboard() {
  const navigate = useNavigate();
  const [days, setDays] = useState(30);
  const range = useMemo(() => { const to = new Date(); const from = new Date(); from.setDate(to.getDate() - (days - 1)); return { from: isoDay(from), to: isoDay(to) }; }, [days]);
  const [{ loading, data, error, isDemo }] = useLoad((signal) => api.getAdminDashboard({ ...range, signal }), () => demoDashboard(days), [range.from, range.to]);

  return <section className='ai-wrap' aria-labelledby='ai-dash-title'>
    <div className='ai-head'>
      <div><p className='admin-eyebrow'>Cómo va la tienda</p><h2 id='ai-dash-title' className='ai-title'>Ventas</h2></div>
      <div className='ai-chips' role='group' aria-label='Periodo'>{[7, 30, 90].map((d) => <button key={d} type='button' className={days === d ? 'is-on' : ''} onClick={() => setDays(d)}>{d} días</button>)}</div>
    </div>
    {isDemo && <DemoNotice />}
    {loading && !data && <p className='ai-muted'>Cargando…</p>}
    {error && <p className='admin-inline-error'>{error}</p>}
    {data && <>
      <div className='ai-kpis'>
        <Kpi label='Ventas' value={cop(data.current.sales)} change={pct(data.current.sales, data.previous.sales)} />
        <Kpi label='Pedidos pagados' value={data.current.orders} change={pct(data.current.orders, data.previous.orders)} />
        <Kpi label='Ticket promedio' value={cop(data.current.average_ticket)} change={pct(data.current.average_ticket, data.previous.average_ticket)} />
        <button type='button' className={`ai-kpi ai-kpi-alert ${data.to_attend ? 'is-hot' : ''}`} onClick={() => navigate('/admin/orders')}>
          <span>Por preparar</span><strong>{data.to_attend}</strong><small>Pagados sin empezar · ver pedidos ›</small>
        </button>
      </div>
      <div className='ai-grid'>
        <article className='ai-card ai-span2'><h3>Ventas por día</h3><DayBars rows={data.by_day} /></article>
        <article className='ai-card'><h3>Más vendidos</h3>
          {data.top_products.length ? <ol className='ai-rank'>{data.top_products.map((p, i) => <li key={p.product_id}><span className='ai-rank-n'>{i + 1}</span><span className='ai-rank-name'>{p.name}</span><b>{p.units} u.</b><small>{cop(p.sales)}</small></li>)}</ol> : <p className='ai-muted'>Sin ventas en el periodo.</p>}
        </article>
        <article className='ai-card'><h3>Ciudades</h3>
          {data.cities.length ? <ul className='ai-bars'>{data.cities.map((c) => <li key={c.city}><span>{c.city}</span><i style={{ '--w': `${Math.max(6, (c.orders / data.cities[0].orders) * 100)}%` }} /><b>{c.orders}</b></li>)}</ul> : <p className='ai-muted'>Sin ventas en el periodo.</p>}
          {data.payment_not_completed > 0 && <p className='ai-note'>⚠️ {data.payment_not_completed} pedido(s) quedaron sin pagar en el periodo. Son clientes para recuperar por WhatsApp.</p>}
        </article>
      </div>
      <p className='ai-muted ai-small'>Comparado con los {days} días anteriores ({shortDate(data.range.previous_from)} – {shortDate(data.range.previous_to)}). Solo cuenta pedidos con pago aprobado.</p>
    </>}
  </section>;
}

function Kpi({ label, value, change }) {
  return <div className='ai-kpi'><span>{label}</span><strong>{value}</strong>{change === null ? <small>Sin periodo anterior</small> : <small className={change >= 0 ? 'ai-up' : 'ai-down'}>{change >= 0 ? '▲' : '▼'} {Math.abs(change)}% vs. periodo anterior</small>}</div>;
}

function DayBars({ rows }) {
  const max = Math.max(1, ...rows.map((r) => r.sales));
  const step = rows.length > 45 ? 15 : rows.length > 14 ? 6 : 1;
  return <div className='ai-chart' role='img' aria-label='Gráfica de ventas por día'>
    <div className='ai-chart-bars'>{rows.map((r) => <div key={r.date} className='ai-chart-col' title={`${shortDate(r.date)}: ${cop(r.sales)} · ${r.orders} pedido(s)`}><i style={{ height: `${(r.sales / max) * 100}%` }} /></div>)}</div>
    <div className='ai-chart-axis'>{rows.map((r, i) => <span key={r.date}>{i % step === 0 ? shortDate(r.date) : ''}</span>)}</div>
  </div>;
}

/* ---------------- Clientes ---------------- */
export function AdminCustomersPage() {
  const [{ loading, data, error, isDemo }] = useLoad((signal) => api.listAdminCustomers({ signal }), demoCustomers, []);
  const [q, setQ] = useState('');
  const [filter, setFilter] = useState('all');
  const list = useMemo(() => (data?.customers || []).filter((c) => {
    if (filter === 'repeat' && c.orders < 2) return false;
    if (filter === 'recover' && c.days_since_last <= 45) return false;
    return `${c.name} ${c.email} ${c.city} ${c.phone}`.toLowerCase().includes(q.trim().toLowerCase());
  }), [data, q, filter]);
  const counts = useMemo(() => { const all = data?.customers || []; return { all: all.length, repeat: all.filter((c) => c.orders >= 2).length, recover: all.filter((c) => c.days_since_last > 45).length }; }, [data]);

  const exportCsv = () => {
    const head = ['Nombre', 'Correo', 'Teléfono', 'Ciudad', 'Pedidos', 'Gastado', 'Primera compra', 'Última compra'];
    const rows = list.map((c) => [c.name, c.email, c.phone, c.city, c.orders, c.spent, c.first_purchase, c.last_purchase]);
    const csv = [head, ...rows].map((r) => r.map((v) => `"${String(v ?? '').replace(/"/g, '""')}"`).join(',')).join('\n');
    const a = document.createElement('a'); a.href = URL.createObjectURL(new Blob([`﻿${csv}`], { type: 'text/csv;charset=utf-8' })); a.download = `clientes-tu-tiendita-${isoDay(new Date())}.csv`; a.click(); URL.revokeObjectURL(a.href);
  };

  return <div className='admin-content'>
    <div className='admin-page-heading'><div><p className='admin-eyebrow'>Relación con clientes</p><h1>Clientes</h1><p>Quién compra, cuánto y hace cuánto. Solo clientes con al menos un pago aprobado.</p></div>
      <button type='button' className='ai-btn ai-btn-ghost' onClick={exportCsv} disabled={!list.length}>Exportar CSV</button></div>
    {isDemo && <DemoNotice />}
    <div className='ai-toolbar'>
      <label className='ai-search'><span className='sr-only'>Buscar cliente</span><input type='search' placeholder='Buscar por nombre, correo, ciudad o teléfono' value={q} onChange={(e) => setQ(e.target.value)} /></label>
      <div className='ai-chips' role='group' aria-label='Filtrar clientes'>
        {[['all', 'Todos'], ['repeat', 'Recurrentes'], ['recover', 'Por recuperar (+45 días)']].map(([k, t]) => <button key={k} type='button' className={filter === k ? 'is-on' : ''} onClick={() => setFilter(k)}>{t} <b>{counts[k]}</b></button>)}
      </div>
    </div>
    {loading && !data && <p className='ai-muted'>Cargando clientes…</p>}
    {error && <p className='admin-inline-error'>{error}</p>}
    {data && (list.length ? <div className='ai-table-wrap'><table className='ai-table'>
      <thead><tr><th>Cliente</th><th>Ciudad</th><th>Pedidos</th><th>Gastado</th><th>Última compra</th><th aria-label='Acciones' /></tr></thead>
      <tbody>{list.map((c) => <tr key={c.email}>
        <td data-l='Cliente'><b>{c.name}</b><small>{c.email}</small></td>
        <td data-l='Ciudad'>{c.city || '—'}</td>
        <td data-l='Pedidos'>{c.orders}{c.orders >= 2 && <span className='ai-pill ai-s-res'>Recurrente</span>}</td>
        <td data-l='Gastado' className='ai-mono'>{cop(c.spent)}</td>
        <td data-l='Última compra'>{shortDate(c.last_purchase)} {c.days_since_last > 45 && <span className='ai-pill ai-s-new'>{c.days_since_last} días</span>}</td>
        <td data-l=''>{c.phone && <a className='ai-btn ai-btn-wa' href={waLink(c.phone, `¡Hola ${String(c.name).split(' ')[0]}! 👋 Te escribimos de Tu Tiendita Venezolana.`)} target='_blank' rel='noreferrer'>WhatsApp</a>}</td>
      </tr>)}</tbody></table></div> : <p className='ai-empty'>No hay clientes con ese filtro.</p>)}
    <p className='ai-muted ai-small'>Antes de enviar promociones, confirma que el cliente autorizó recibir mensajes (Ley 1581). Los mensajes sobre su pedido sí se pueden enviar.</p>
  </div>;
}

/* ---------------- WhatsApp ---------------- */
const QUICK = {
  mayorista: '¡Hola! 👋 Gracias por escribir a Tu Tiendita Venezolana. Para tu pedido al por mayor cuéntanos: productos, cantidades aproximadas y ciudad de entrega, y te enviamos precios especiales.',
  soporte: '¡Hola! 👋 Somos Tu Tiendita Venezolana. Ya revisamos tu pedido y te contamos en un momento. ¿Nos confirmas el número de pedido (TTV-…)?',
  ayuda_compra: '¡Hola! 👋 Con gusto te ayudamos a comprar. Dinos qué productos buscas y tu ciudad, y te armamos el pedido aquí mismo.',
};

export function AdminWhatsAppPage() {
  const [tick, setTick] = useState(0);
  const [{ loading, data, error, isDemo }] = useLoad((signal) => api.listAdminWhatsAppRequests({ signal }), demoWhatsApp, [tick]);
  const [local, setLocal] = useState(null); // copia editable (modo ejemplo o mientras se guarda)
  const [filter, setFilter] = useState('open');
  const [form, setForm] = useState({ name: '', phone: '', topic: 'mayorista', message: '' });
  const [msg, setMsg] = useState('');
  useEffect(() => { if (data) setLocal(data); }, [data]);

  const reqs = local?.requests || [];
  const shown = reqs.filter((r) => (filter === 'open' ? r.status !== 'RESOLVED' : filter === 'all' ? true : r.topic === filter));
  const count = (s) => reqs.filter((r) => r.status === s).length;

  const change = async (r, patch) => {
    const extra = patch.assigned_admin_id && r.status === 'NEW' && !patch.status ? { status: 'ATTENDING' } : {};
    const assignedName = 'assigned_admin_id' in patch ? (local.admins.find((a) => a.id === Number(patch.assigned_admin_id))?.name ?? null) : r.assigned_name;
    setLocal((l) => ({ ...l, requests: l.requests.map((x) => (x.id === r.id ? { ...x, ...patch, ...extra, assigned_name: assignedName } : x)) }));
    if (isDemo) return;
    try { await api.updateAdminWhatsAppRequest(r.id, patch); } catch (e) { setMsg(e.message); setTick((t) => t + 1); }
  };
  const create = async (e) => {
    e.preventDefault(); setMsg('');
    if (!form.name.trim() || form.phone.replace(/\D/g, '').length < 7) { setMsg('Escribe nombre y teléfono.'); return; }
    if (isDemo) { setLocal((l) => ({ ...l, requests: [{ id: Date.now(), ...form, status: 'NEW', assigned_admin_id: null, assigned_name: null, created_at: new Date().toISOString() }, ...l.requests] })); setForm({ name: '', phone: '', topic: form.topic, message: '' }); setMsg('Registrada (ejemplo).'); return; }
    try { await api.createAdminWhatsAppRequest(form); setForm({ name: '', phone: '', topic: form.topic, message: '' }); setMsg('Solicitud registrada.'); setTick((t) => t + 1); } catch (err) { setMsg(err.message); }
  };

  return <div className='admin-content'>
    <div className='admin-page-heading'><div><p className='admin-eyebrow'>Menú de WhatsApp de la tienda</p><h1>WhatsApp</h1><p>Solicitudes de mayoristas, soporte y ayuda para comprar: quién las atiende y en qué van.</p></div></div>
    {isDemo && <DemoNotice />}
    {loading && !local && <p className='ai-muted'>Cargando…</p>}
    {error && <p className='admin-inline-error'>{error}</p>}
    {local && <>
      <div className='ai-kpis'>
        <div className='ai-kpi'><span>Nuevas</span><strong>{count('NEW')}</strong><small>Sin asignar o por responder</small></div>
        <div className='ai-kpi'><span>En atención</span><strong>{count('ATTENDING')}</strong><small>Con responsable</small></div>
        <div className='ai-kpi'><span>Resueltas</span><strong>{count('RESOLVED')}</strong><small>Cerradas</small></div>
        <div className='ai-kpi'><span>Clics en el menú (30 días)</span><strong>{Object.values(local.clicks_30_days || {}).reduce((a, b) => a + b, 0)}</strong>
          <small>{Object.entries(local.clicks_30_days || {}).map(([k, v]) => `${TOPICS[k]?.[0] || k}: ${v}`).join(' · ')}</small></div>
      </div>
      <div className='ai-grid'>
        <article className='ai-card ai-span2'>
          <div className='ai-head'><h3>Solicitudes</h3>
            <div className='ai-chips' role='group' aria-label='Filtrar solicitudes'>{[['open', 'Abiertas'], ['mayorista', 'Mayorista'], ['soporte', 'Soporte'], ['ayuda_compra', 'Ayuda'], ['all', 'Todas']].map(([k, t]) => <button key={k} type='button' className={filter === k ? 'is-on' : ''} onClick={() => setFilter(k)}>{t}</button>)}</div></div>
          {shown.length ? <ul className='ai-wa-list'>{shown.map((r) => <li key={r.id} className='ai-wa-item'>
            <div className='ai-wa-main'>
              <b>{r.name}</b> <span className={`ai-pill ${TOPICS[r.topic]?.[1] || ''}`}>{TOPICS[r.topic]?.[0] || r.topic}</span> <span className={`ai-pill ${WA_STATUS[r.status]?.[1]}`}>{WA_STATUS[r.status]?.[0]}</span>
              <small>{r.phone} · {shortDate(r.created_at)}{r.assigned_name ? ` · Atiende: ${r.assigned_name}` : ''}</small>
              {r.message && <p>{r.message}</p>}
            </div>
            <div className='ai-wa-actions'>
              <label><span className='sr-only'>Asignar a</span><select value={r.assigned_admin_id ?? ''} onChange={(e) => change(r, { assigned_admin_id: e.target.value ? Number(e.target.value) : null })}><option value=''>Sin asignar</option>{local.admins.map((a) => <option key={a.id} value={a.id}>{a.name}</option>)}</select></label>
              <label><span className='sr-only'>Estado</span><select value={r.status} onChange={(e) => change(r, { status: e.target.value })}>{Object.entries(WA_STATUS).map(([k, [t]]) => <option key={k} value={k}>{t}</option>)}</select></label>
              <a className='ai-btn ai-btn-wa' href={waLink(r.phone, QUICK[r.topic] || '')} target='_blank' rel='noreferrer'>Responder</a>
            </div>
          </li>)}</ul> : <p className='ai-empty'>No hay solicitudes con ese filtro.</p>}
        </article>
        <article className='ai-card'>
          <h3>Registrar solicitud</h3>
          <form className='ai-form' onSubmit={create}>
            <label>Nombre o negocio<input value={form.name} onChange={(e) => setForm({ ...form, name: e.target.value })} maxLength={120} /></label>
            <label>Teléfono<input value={form.phone} onChange={(e) => setForm({ ...form, phone: e.target.value })} inputMode='tel' maxLength={20} /></label>
            <label>Tema<select value={form.topic} onChange={(e) => setForm({ ...form, topic: e.target.value })}>{Object.entries(TOPICS).map(([k, [t]]) => <option key={k} value={k}>{t}</option>)}</select></label>
            <label>Qué necesita<textarea value={form.message} onChange={(e) => setForm({ ...form, message: e.target.value })} maxLength={1000} rows={3} /></label>
            <button type='submit' className='ai-btn'>Registrar</button>
            {msg && <p className='ai-muted' role='status'>{msg}</p>}
          </form>
          <h3 className='ai-mt'>Respuestas rápidas</h3>
          <ul className='ai-quick'>{Object.entries(QUICK).map(([k, t]) => <li key={k}><b>{TOPICS[k][0]}</b><p>{t}</p><button type='button' className='ai-btn ai-btn-ghost' onClick={() => navigator.clipboard?.writeText(t).then(() => setMsg('Copiado ✓'))}>Copiar</button></li>)}</ul>
        </article>
      </div>
    </>}
  </div>;
}
