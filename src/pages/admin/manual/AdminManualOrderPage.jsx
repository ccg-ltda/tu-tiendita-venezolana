import { useEffect, useMemo, useState } from 'react';
import { useNavigate } from 'react-router-dom';
import { AdminToast } from '../../../components/admin/AdminToast';
import { useAdminData } from '../../../context/AdminDataContext';
import { api } from '../../../services/api';
import '../../../styles/admin-today.css';

const METHODS = [
  ['TRANSFERENCIA', 'Transferencia'], ['NEQUI', 'Nequi'], ['DAVIPLATA', 'Daviplata'],
  ['EFECTIVO', 'Efectivo'], ['DATAFONO', 'Datáfono'], ['CONTRAENTREGA', 'Contraentrega'], ['OTRO', 'Otro'],
];
const FREE_SHIPPING_FROM = 500000;
const SHIPPING_FEE = 15000;
const MAX_BASE64 = 1150000;

const money = (value) => new Intl.NumberFormat('es-CO', { style: 'currency', currency: 'COP', maximumFractionDigits: 0 }).format(Number(value) || 0);

const readAsBase64 = (file) => new Promise((resolve, reject) => {
  const reader = new FileReader();
  reader.onload = () => resolve(String(reader.result).split(',')[1]);
  reader.onerror = () => reject(new Error('No pude leer el archivo.'));
  reader.readAsDataURL(file);
});

/** Reduce la foto del comprobante para que pese poco (máx. 1400 px, JPG). */
async function prepareSupport(file) {
  if (file.type === 'application/pdf') {
    const data = await readAsBase64(file);
    if (data.length > MAX_BASE64) throw new Error('El PDF pesa demasiado. Súbelo como foto o captura.');
    return { name: file.name, mime: 'application/pdf', data };
  }
  if (!/^image\//.test(file.type)) throw new Error('El soporte debe ser una foto o un PDF.');
  const bitmap = await createImageBitmap(file);
  const scale = Math.min(1, 1400 / Math.max(bitmap.width, bitmap.height));
  const canvas = document.createElement('canvas');
  canvas.width = Math.round(bitmap.width * scale);
  canvas.height = Math.round(bitmap.height * scale);
  canvas.getContext('2d').drawImage(bitmap, 0, 0, canvas.width, canvas.height);
  const data = canvas.toDataURL('image/jpeg', 0.78).split(',')[1];
  if (data.length > MAX_BASE64) throw new Error('La foto sigue pesando demasiado. Toma otra más pequeña.');
  return { name: `${file.name.replace(/\.\w+$/, '')}.jpg`, mime: 'image/jpeg', data };
}

export function AdminManualOrderPage() {
  const navigate = useNavigate();
  const { products, productsInitialLoading, ensureProducts, refreshProducts } = useAdminData();
  const [customer, setCustomer] = useState({ name: '', phone: '', city: 'Bogotá', email: '', address: '', extra: '' });
  const [qty, setQty] = useState({});
  const [search, setSearch] = useState('');
  const [method, setMethod] = useState('TRANSFERENCIA');
  const [paid, setPaid] = useState(false);
  const [shipping, setShipping] = useState('');
  const [note, setNote] = useState('');
  const [support, setSupport] = useState(null);
  const [supportHint, setSupportHint] = useState('Puedes tomar la foto del comprobante con la cámara.');
  const [saving, setSaving] = useState(false);
  const [error, setError] = useState('');
  const [toast, setToast] = useState(null);
  const cod = method === 'CONTRAENTREGA';

  useEffect(() => { ensureProducts(); }, [ensureProducts]);

  const catalog = useMemo(() => (products || []).filter((p) => p.active), [products]);
  const shown = useMemo(() => {
    const term = search.trim().toLowerCase();
    return term ? catalog.filter((p) => `${p.name} ${p.presentation}`.toLowerCase().includes(term)) : catalog;
  }, [catalog, search]);
  const chosen = catalog.filter((p) => qty[p.id] > 0);
  const subtotal = chosen.reduce((sum, p) => sum + p.price * qty[p.id], 0);
  const autoShipping = subtotal === 0 ? 0 : (subtotal >= FREE_SHIPPING_FROM ? 0 : SHIPPING_FEE);
  const shippingFinal = shipping === '' ? autoShipping : Math.max(0, Number(shipping) || 0);

  const setField = (key) => (event) => setCustomer((current) => ({ ...current, [key]: event.target.value }));
  const change = (product, delta) => setQty((current) => {
    const next = Math.max(0, Math.min(product.inventory, 99, (current[product.id] || 0) + delta));
    return { ...current, [product.id]: next };
  });

  const pickSupport = async (event) => {
    const file = event.target.files[0];
    setSupport(null);
    if (!file) { setSupportHint('Puedes tomar la foto del comprobante con la cámara.'); return; }
    try {
      setSupportHint('Preparando la foto…');
      const prepared = await prepareSupport(file);
      setSupport(prepared);
      setSupportHint(`✔ ${prepared.name} listo (${Math.round(prepared.data.length * 0.75 / 1024)} KB)`);
      if (!cod) setPaid(true);
    } catch (problem) {
      setSupportHint(problem.message);
      event.target.value = '';
    }
  };

  const changeMethod = (value) => { setMethod(value); if (value === 'CONTRAENTREGA') setPaid(false); };

  const save = async (event) => {
    event.preventDefault();
    setError('');
    if (!chosen.length) { setError('Agrega al menos un producto.'); return; }
    setSaving(true);
    try {
      const response = await api.createAdminManualOrder({
        customer: { name: customer.name, phone: customer.phone, city: customer.city, email: customer.email || null, address: customer.address, extra: customer.extra || null },
        items: chosen.map((p) => ({ product_id: p.id, quantity: qty[p.id] })),
        payment_method: method, paid: cod ? false : paid, shipping_cop: shipping === '' ? null : Number(shipping),
        note: note || null, attachment: support,
      });
      setToast({ id: `manual-${Date.now()}`, tone: 'success', variant: 'success', title: 'Pedido guardado', message: `${response.order.reference} por ${money(response.order.total_cop)}. Ya está listo para preparar.` });
      refreshProducts();
      window.setTimeout(() => navigate('/admin/hoy'), 1400);
    } catch (problem) {
      const first = problem?.details ? Object.values(problem.details)[0]?.[0] : '';
      setError(first || problem.message || 'No fue posible guardar el pedido.');
      setSaving(false);
    }
  };

  return <>
    <div className='admin-content manual-page'>
      <header className='today-heading'>
        <p className='admin-eyebrow'>Pedidos por WhatsApp o teléfono</p>
        <h1>Pedido manual</h1>
        <p>Para quien no sabe pedir en la página. Al guardarlo queda listo para preparar y se descuenta del inventario.</p>
      </header>

      <form className='manual-form' autoComplete='off' onSubmit={save}>
        <section className='manual-box'>
          <span className='manual-step'>1</span><h2>¿Quién compra?</h2>
          <label htmlFor='m-name'>Nombre</label>
          <input id='m-name' required placeholder='Ej: Rosa Pardo' value={customer.name} onChange={setField('name')} />
          <label htmlFor='m-phone'>Teléfono</label>
          <input id='m-phone' type='tel' inputMode='tel' required placeholder='300 123 4567' value={customer.phone} onChange={setField('phone')} />
          <div className='manual-two'>
            <div><label htmlFor='m-city'>Ciudad</label><input id='m-city' required value={customer.city} onChange={setField('city')} /></div>
            <div><label htmlFor='m-email'>Correo (opcional)</label><input id='m-email' type='email' inputMode='email' placeholder='si lo tiene' value={customer.email} onChange={setField('email')} /></div>
          </div>
          <label htmlFor='m-address'>Dirección de entrega</label>
          <input id='m-address' required placeholder='Carrera 7 # 45-10, apto 301' value={customer.address} onChange={setField('address')} />
          <label htmlFor='m-extra'>Barrio o indicaciones (opcional)</label>
          <input id='m-extra' value={customer.extra} onChange={setField('extra')} />
        </section>

        <section className='manual-box'>
          <span className='manual-step'>2</span><h2>¿Qué lleva?</h2>
          <input className='manual-search' type='search' placeholder='Buscar producto…' value={search} onChange={(event) => setSearch(event.target.value)} aria-label='Buscar producto' />
          {productsInitialLoading && <p className='today-muted'>Cargando productos…</p>}
          <div className='manual-products'>
            {shown.map((product) => {
              const out = product.inventory <= 0;
              return <div key={product.id} className={`manual-product${out ? ' is-out' : ''}${qty[product.id] > 0 ? ' is-picked' : ''}`}>
                <div><b>{product.name}</b><small>{product.presentation} · {money(product.price)} · {out ? 'agotado' : `${product.inventory} disp.`}</small></div>
                <div className='manual-stepper'>
                  <button type='button' aria-label={`Quitar uno de ${product.name}`} onClick={() => change(product, -1)}>−</button>
                  <output>{qty[product.id] || 0}</output>
                  <button type='button' aria-label={`Agregar uno de ${product.name}`} disabled={out || (qty[product.id] || 0) >= product.inventory} onClick={() => change(product, 1)}>+</button>
                </div>
              </div>;
            })}
          </div>
          <div className='manual-totals' aria-live='polite'>
            {chosen.length === 0 ? 'Aún no hay productos.' : <>
              <div><span>Subtotal</span><b>{money(subtotal)}</b></div>
              <div><span>Envío{shipping !== '' ? ' (manual)' : ''}</span><b>{shippingFinal ? money(shippingFinal) : 'Gratis'}</b></div>
              <div className='is-total'><span>Total estimado</span><b>{money(subtotal + shippingFinal)}</b></div>
              <small>Las promociones activas se aplican al guardar; el total final lo confirma el sistema.</small>
            </>}
          </div>
        </section>

        <section className='manual-box'>
          <span className='manual-step'>3</span><h2>¿Cómo pagó?</h2>
          <label htmlFor='m-method'>Medio de pago</label>
          <select id='m-method' value={method} onChange={(event) => changeMethod(event.target.value)}>{METHODS.map(([value, label]) => <option key={value} value={value}>{label}</option>)}</select>
          {!cod && <label className='manual-check'><input type='checkbox' checked={paid} onChange={(event) => setPaid(event.target.checked)} /> <span>Ya pagó</span></label>}
          {cod && <p className='today-muted'>Contraentrega: el pedido se prepara ya y se cobra al entregar.</p>}
          <label htmlFor='m-support'>Soporte de pago (foto o PDF)</label>
          <input id='m-support' type='file' accept='image/*,application/pdf' onChange={pickSupport} />
          <p className='today-muted'>{supportHint}</p>
          <label htmlFor='m-shipping'>Envío ($)</label>
          <input id='m-shipping' type='number' inputMode='numeric' min='0' max='100000' step='1000' placeholder='Automático' value={shipping} onChange={(event) => setShipping(event.target.value)} />
          <label htmlFor='m-note'>Nota para quien despacha</label>
          <textarea id='m-note' placeholder='Ej: llamar antes de llegar, portería, horario…' value={note} onChange={(event) => setNote(event.target.value)} />
        </section>

        <button className='today-action manual-save' type='submit' disabled={saving}>{saving ? 'Guardando…' : 'Guardar pedido'}</button>
        {error && <div className='manual-error' role='alert'>{error}</div>}
      </form>
    </div>
    <AdminToast toast={toast} onDismiss={() => setToast(null)} />
  </>;
}
