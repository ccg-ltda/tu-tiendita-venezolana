import { useEffect, useMemo, useState } from 'react';
import { useAdminData } from '../../../context/AdminDataContext';
import { api } from '../../../services/api';

const money = new Intl.NumberFormat('es-CO', { style: 'currency', currency: 'COP', maximumFractionDigits: 0 });
const options = [['ALL', 'Todos'], ['ACTIVE', 'Activo'], ['SCHEDULED', 'Programado'], ['EXPIRED', 'Vencido'], ['EXHAUSTED', 'Agotado'], ['INACTIVE', 'Inactivo']];

export function AdminCouponsPage() {
  const { coupons, couponsLoading, couponsError, ensureCoupons, refreshCoupons, applyAdminCoupon, handleUnauthorized } = useAdminData();
  const [query, setQuery] = useState(''); const [status, setStatus] = useState('ALL'); const [editing, setEditing] = useState(undefined); const [saving, setSaving] = useState(false); const [error, setError] = useState(''); const [feedback, setFeedback] = useState('');
  useEffect(() => { ensureCoupons(); }, [ensureCoupons]);
  useEffect(() => { document.body.classList.add('admin-coupons-route'); return () => document.body.classList.remove('admin-coupons-route'); }, []);
  useEffect(() => { if (!feedback) return undefined; const timeoutId = setTimeout(() => setFeedback(''), 5000); return () => clearTimeout(timeoutId); }, [feedback]);
  const rows = useMemo(() => (coupons || []).filter((coupon) => `${coupon.code} ${coupon.description || ''}`.toLowerCase().includes(query.toLowerCase())).filter((coupon) => status === 'ALL' || coupon.status === status), [coupons, query, status]);
  const save = async (values, target = editing) => {
    setSaving(true); setError('');
    try {
      if (target) {
        const saved = await api.updateAdminCoupon(target.coupon_id, { ...values, expected_revision: target.revision });
        if (!applyAdminCoupon(saved)) await refreshCoupons({ force: true });
      } else {
        await api.createAdminCoupon(values);
        await refreshCoupons({ force: true });
      }
      setFeedback(!target ? 'Cupón creado correctamente.' : values.active !== target.active ? values.active ? 'Cupón activado correctamente.' : 'Cupón desactivado correctamente.' : 'Cupón actualizado correctamente.');
      setEditing(undefined);
    } catch (requestError) {
      if (!handleUnauthorized(requestError)) {
        if (target) await refreshCoupons({ force: true });
        setError(requestError.message);
      }
    } finally { setSaving(false); }
  };
  const toggle = (coupon) => save({ code: coupon.code, description: coupon.description, active: !coupon.active, discount_type: coupon.discount_type, discount_value: coupon.discount_value, minimum_order_cop: coupon.minimum_order_cop, max_uses: coupon.max_uses, starts_at: coupon.starts_at, ends_at: coupon.ends_at }, coupon);
  return <div className='admin-content admin-coupons-page'>
    <div className='admin-page-heading admin-coupons-heading'><div><p className='admin-eyebrow'>Catálogo administrativo</p><h1>Cupones</h1><p>Gestiona descuentos generales y su vigencia.</p></div><button className='admin-products-new' onClick={() => setEditing(null)}>+ Nuevo cupón</button></div>
    {feedback && <p className='admin-products-feedback' role='status'>{feedback}</p>}
    {error && !editing && <p className='admin-promotion-error' role='alert'>{error}</p>}
    <div className='admin-products-toolbar'><label className='admin-products-search'><span>Buscar cupón</span><input value={query} onChange={(event) => setQuery(event.target.value)} placeholder='Código o descripción...' /></label><label className='admin-products-filter'><span>Estado</span><select value={status} onChange={(event) => setStatus(event.target.value)}>{options.map(([value, label]) => <option key={value} value={value}>{label}</option>)}</select></label></div>
    {couponsLoading && <div className='admin-products-state'>Cargando cupones...</div>}{couponsError && <div className='admin-products-state admin-products-state--error'>{couponsError}</div>}
    {!couponsLoading && !couponsError && <div className='admin-promotions-table-wrap admin-coupons-table-wrap'><table className='admin-promotions-table admin-coupons-table'><thead><tr><th>Código</th><th>Descripción</th><th>Tipo</th><th>Descuento</th><th>Mínimo</th><th>Usos</th><th>Vigencia</th><th>Estado</th><th>Acciones</th></tr></thead><tbody>{rows.map((coupon) => <tr key={coupon.coupon_id}><td><strong>{coupon.code}</strong></td><td title={coupon.description || undefined}>{coupon.description || '—'}</td><td>{coupon.discount_type === 'percent' ? 'Porcentaje' : 'Valor fijo'}</td><td>{coupon.discount_type === 'percent' ? `${coupon.discount_value}%` : money.format(coupon.discount_value)}</td><td>{coupon.minimum_order_cop ? money.format(coupon.minimum_order_cop) : 'Sin mínimo'}</td><td>{coupon.used_count} / {coupon.max_uses ?? '∞'}</td><td>{coupon.starts_at || coupon.ends_at ? 'Con vigencia' : 'Sin límite'}</td><td><span className={`admin-promotion-status status-${coupon.status.toLowerCase()}`}>{label(coupon.status)}</span></td><td><div className='admin-product-actions'><button onClick={() => { setError(''); setEditing(coupon); }}>Editar</button><button className={coupon.active ? 'is-deactivate' : 'is-activate'} onClick={() => toggle(coupon)} disabled={saving}>{coupon.active ? 'Desactivar' : 'Activar'}</button></div></td></tr>)}{!rows.length && <tr><td colSpan='9' className='admin-promotions-empty'>No hay cupones para mostrar.</td></tr>}</tbody></table></div>}
    {editing !== undefined && <CouponEditor coupon={editing} saving={saving} error={error} onClose={() => !saving && setEditing(undefined)} onSave={save} />}
  </div>;
}

function CouponEditor({ coupon, saving, error, onClose, onSave }) {
  const [value, setValue] = useState(() => coupon ? { ...coupon, starts_at: date(coupon.starts_at), ends_at: date(coupon.ends_at) } : { code: '', description: '', active: true, discount_type: 'percent', discount_value: '', minimum_order_cop: '', max_uses: '', starts_at: '', ends_at: '' });
  const update = (key) => (event) => setValue((current) => ({ ...current, [key]: event.target.type === 'checkbox' ? event.target.checked : event.target.value }));
  const submit = (event) => { event.preventDefault(); onSave({ ...value, discount_value: Number(value.discount_value), minimum_order_cop: value.minimum_order_cop === '' ? null : Number(value.minimum_order_cop), max_uses: value.max_uses === '' ? null : Number(value.max_uses), starts_at: stamp(value.starts_at), ends_at: stamp(value.ends_at) }); };
  return <div className='admin-promotion-editor-layer'><button className='admin-promotion-editor-backdrop' onClick={onClose}/><form className='admin-promotion-editor' onSubmit={submit}><header><div><p className='admin-eyebrow'>{coupon ? 'Editar cupón' : 'Nuevo cupón'}</p><h2>{coupon?.code || 'Crear cupón'}</h2></div></header><div className='admin-promotion-editor-content'><label>Código<input value={value.code} onChange={update('code')} required/></label><label>Descripción<input value={value.description || ''} onChange={update('description')}/></label><label className='admin-promotion-switch'><input type='checkbox' checked={value.active} onChange={update('active')}/><span>Activar cupón</span></label><label>Tipo<select value={value.discount_type} onChange={update('discount_type')}><option value='percent'>Porcentaje</option><option value='fixed'>Valor fijo</option></select></label><label>Valor<input type='number' min='1' value={value.discount_value} onChange={update('discount_value')} required/></label><label>Compra mínima<input type='number' min='1' value={value.minimum_order_cop ?? ''} onChange={update('minimum_order_cop')}/></label><label>Máximo de usos<input type='number' min='1' value={value.max_uses ?? ''} onChange={update('max_uses')}/></label><label>Inicio<input type='datetime-local' value={value.starts_at || ''} onChange={update('starts_at')}/></label><label>Fin<input type='datetime-local' value={value.ends_at || ''} onChange={update('ends_at')}/></label>{coupon && <p className='admin-promotion-preview'>Usos actuales: <b>{coupon.used_count} / {coupon.max_uses ?? '∞'}</b></p>}{error && <p className='admin-promotion-error'>{error}</p>}</div><footer><button type='button' onClick={onClose}>Cancelar</button><button className='is-primary' disabled={saving}>{saving ? 'Guardando...' : 'Guardar cupón'}</button></footer></form></div>;
}

const date = (value) => value ? value.slice(0, 16) : '';
const stamp = (value) => value ? `${value}:00.000-05:00` : null;
const label = (status) => ({ ACTIVE: 'Activo', SCHEDULED: 'Programado', EXPIRED: 'Vencido', EXHAUSTED: 'Agotado', INACTIVE: 'Inactivo' })[status] || status;
