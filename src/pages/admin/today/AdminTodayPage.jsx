import { useCallback, useEffect, useMemo, useRef, useState } from 'react';
import { AdminToast } from '../../../components/admin/AdminToast';
import { unlockAdminToastAudio } from '../../../components/admin/adminToastAudio';
import { useAdminData } from '../../../context/AdminDataContext';
import '../../../styles/admin-today.css';

const PER_PAGE = 50;
const POLL_MS = 60000;

const SECTIONS = [
  { key: 'NEW', icon: '🟠', label: 'Nuevos', tile: 'Nuevos', empty: 'Nada nuevo por empezar.' },
  { key: 'PROCESSING', icon: '📦', label: 'Por preparar', tile: 'Preparar', empty: 'Todo preparado 🛒' },
  { key: 'READY', icon: '🚚', label: 'Por enviar', tile: 'Enviar', empty: 'Nada por enviar.' },
  { key: 'SHIPPED', icon: '🏠', label: 'Por entregar', tile: 'Entregar', empty: 'Nada en camino.' },
];

/** Qué botón grande ve cada rol según el estado del pedido. */
const ACTIONS = {
  NEW: { to: 'PROCESSING', label: 'Empezar a preparar', roles: ['ADMIN', 'DESPACHO'] },
  PROCESSING: { to: 'READY', label: 'Marcar listo', roles: ['ADMIN', 'DESPACHO'] },
  READY: { to: 'SHIPPED', label: 'Marcar enviado', roles: ['ADMIN', 'DESPACHO'] },
  SHIPPED: { to: 'DELIVERED', label: 'Marcar entregado', roles: ['ADMIN', 'DESPACHO', 'ENTREGA'] },
};

const money = (value) => new Intl.NumberFormat('es-CO', { style: 'currency', currency: 'COP', maximumFractionDigits: 0 }).format(Number(value) || 0);
const stageOf = (order) => (order.status === 'PENDING' ? 'NEW' : order.status);
const onlyDigits = (value) => String(value || '').replace(/\D/g, '');

function OrderCard({ order, role, open, detail, busy, onToggle, onAdvance }) {
  const stage = stageOf(order);
  const action = ACTIONS[stage];
  const canAct = action && action.roles.includes(role);
  const phone = onlyDigits(detail?.customer_phone);
  const whatsapp = phone ? `https://wa.me/${phone.length === 10 ? `57${phone}` : phone}` : '';

  return <article className={`today-card today-card--${stage.toLowerCase()}${open ? ' is-open' : ''}`}>
    <button type='button' className='today-card-head' onClick={onToggle} aria-expanded={open}>
      <span className='today-card-ref'>{order.reference}</span>
      <strong className='today-card-name'>{order.customer_name}</strong>
      {role === 'ADMIN' && <span className='today-card-total'>{money(order.total)}</span>}
    </button>

    {open && <div className='today-card-body'>
      {!detail && <p className='today-muted'>Cargando datos del pedido…</p>}
      {detail && <>
        <dl className='today-data'>
          <div><dt>Teléfono</dt><dd>{detail.customer_phone}</dd></div>
          <div><dt>Dirección</dt><dd>{detail.address}{detail.extra ? `, ${detail.extra}` : ''} · {detail.city}</dd></div>
        </dl>
        <div className='today-links'>
          {detail.customer_phone && <a href={`tel:${phone}`}>Llamar</a>}
          {whatsapp && <a href={whatsapp} target='_blank' rel='noreferrer'>WhatsApp</a>}
        </div>
        <h3 className='today-items-title'>Lleva</h3>
        <ul className='today-items'>
          {(detail.items || []).map((item) => <li key={item.id}><b>{item.quantity}×</b> {item.product_name}</li>)}
        </ul>
      </>}
    </div>}

    {canAct && <button type='button' className='today-action' disabled={busy} onClick={() => onAdvance(order, action.to)}>
      {busy ? 'Guardando…' : action.label}
    </button>}
  </article>;
}

export function AdminTodayPage() {
  const { user, ordersByKey, orderDetailsById, ordersKey, refreshOrders, loadOrderDetail, updateOrderStatus } = useAdminData();
  const role = user?.role || '';
  const [openId, setOpenId] = useState(null);
  const [busyId, setBusyId] = useState(null);
  const [toast, setToast] = useState(null);
  const [fresh, setFresh] = useState(0);
  const known = useRef(null);

  const key = ordersKey(1, PER_PAGE, 'operational');
  const record = ordersByKey[key];
  const orders = useMemo(() => record?.data || [], [record]);
  const loading = !record || record.initialLoading;

  const load = useCallback((force) => refreshOrders(1, PER_PAGE, { force, flowStatus: 'operational' }), [refreshOrders]);

  useEffect(() => {
    load(true);
    const timer = window.setInterval(() => { if (document.visibilityState === 'visible') load(true); }, POLL_MS);
    return () => window.clearInterval(timer);
  }, [load]);

  // Aviso de pedido nuevo: compara contra lo que ya se había visto.
  useEffect(() => {
    if (!orders.length) return;
    const ids = new Set(orders.map((order) => order.id));
    if (known.current) {
      const added = orders.filter((order) => !known.current.has(order.id) && stageOf(order) === 'NEW').length;
      if (added > 0) {
        setFresh((count) => count + added);
        if (navigator.vibrate) navigator.vibrate([120, 60, 120]);
      }
    }
    known.current = ids;
  }, [orders]);

  const sections = role === 'ENTREGA' ? SECTIONS.filter((item) => item.key === 'SHIPPED') : SECTIONS;
  const counts = useMemo(() => {
    const result = {};
    orders.forEach((order) => { const s = stageOf(order); result[s] = (result[s] || 0) + 1; });
    return result;
  }, [orders]);
  const goTo = (key) => document.getElementById(`hoy-${key}`)?.scrollIntoView({ behavior: 'smooth', block: 'start' });

  const toggle = (order) => {
    if (openId === order.id) { setOpenId(null); return; }
    setOpenId(order.id);
    loadOrderDetail(order.id);
  };

  const advance = async (order, to) => {
    if (busyId) return;
    unlockAdminToastAudio();
    setBusyId(order.id);
    try {
      await updateOrderStatus(order.id, to);
      setToast({ id: `${order.id}-${to}-${Date.now()}`, tone: 'success', variant: to.toLowerCase(), title: 'Pedido actualizado', message: `${order.reference} avanzó al siguiente paso.` });
    } catch (error) {
      setToast({ id: `err-${order.id}-${Date.now()}`, tone: 'error', variant: 'error', title: 'No se pudo actualizar', message: error?.message || 'Intenta de nuevo.' });
    } finally {
      setBusyId(null);
    }
  };

  const hour = Number(new Intl.DateTimeFormat('es-CO', { hour: 'numeric', hour12: false, timeZone: 'America/Bogota' }).format(new Date()));
  const greeting = hour < 12 ? 'Buenos días' : hour < 19 ? 'Buenas tardes' : 'Buenas noches';
  const firstName = (user?.name || '').split(' ')[0];

  return <>
    <div className='admin-content today-page'>
      <header className='today-heading'>
        <p className='admin-eyebrow'>{greeting}{firstName ? `, ${firstName}` : ''}</p>
        <h1>Hoy</h1>
        <p>Lo que hay que mover ahora. Se actualiza solo.</p>
        <button type='button' className='today-refresh' onClick={() => load(true)} aria-label='Actualizar pedidos'>↻</button>
      </header>

      {fresh > 0 && <button type='button' className='today-fresh' onClick={() => { setFresh(0); goTo('NEW'); }}>
        {fresh === 1 ? 'Llegó 1 pedido nuevo' : `Llegaron ${fresh} pedidos nuevos`} · Ver
      </button>}

      <div className='today-tiles'>
        {sections.map((item) => <button key={item.key} type='button' onClick={() => goTo(item.key)}><b>{counts[item.key] || 0}</b><span>{item.tile}</span></button>)}
      </div>

      {loading && <div className='admin-orders-state'>Cargando pedidos…</div>}
      {!loading && record?.error && <div className='admin-orders-state admin-orders-state-error'><p>{record.error}</p><button type='button' onClick={() => load(true)}>Reintentar</button></div>}

      {!loading && !record?.error && sections.map((item) => {
        const list = orders.filter((order) => stageOf(order) === item.key);
        return <section key={item.key} id={`hoy-${item.key}`} className='today-section'>
          <h2>{item.icon} {item.label} <span className='today-badge'>{list.length}</span></h2>
          {list.length === 0 && <div className='today-empty'>{item.empty}</div>}
          <div className='today-list'>
            {list.map((order) => <OrderCard
              key={order.id}
              order={order}
              role={role}
              open={openId === order.id}
              detail={orderDetailsById[String(order.id)]?.data}
              busy={busyId === order.id}
              onToggle={() => toggle(order)}
              onAdvance={advance}
            />)}
          </div>
        </section>;
      })}
    </div>
    <AdminToast toast={toast} onDismiss={() => setToast(null)} />
  </>;
}
