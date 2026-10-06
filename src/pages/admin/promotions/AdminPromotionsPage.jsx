import { useEffect, useMemo, useState } from 'react';
import { PromotionEditor, promotionStatusLabel } from '../../../components/admin/promotions/PromotionEditor';
import { useAdminData } from '../../../context/AdminDataContext';
import { api } from '../../../services/api';

const priceFormatter = new Intl.NumberFormat('es-CO', { style: 'currency', currency: 'COP', maximumFractionDigits: 0 });
const statusOptions = [{ value: 'ALL', label: 'Todas' }, { value: 'ACTIVE', label: 'Activas' }, { value: 'SCHEDULED', label: 'Programadas' }, { value: 'EXPIRED', label: 'Vencidas' }, { value: 'INACTIVE', label: 'Inactivas' }];

export function AdminPromotionsPage() {
  const { products, ensureProducts, promotions, promotionsLoading, promotionsError, ensurePromotions, refreshPromotions, handleUnauthorized } = useAdminData();
  const [query, setQuery] = useState('');
  const [status, setStatus] = useState('ALL');
  const [editorRecord, setEditorRecord] = useState(undefined);
  const [saving, setSaving] = useState(false);
  const [editorError, setEditorError] = useState('');
  const [feedback, setFeedback] = useState('');

  useEffect(() => { ensureProducts(); ensurePromotions(); }, [ensureProducts, ensurePromotions]);
  useEffect(() => { document.body.classList.add('admin-promotions-route'); return () => document.body.classList.remove('admin-promotions-route'); }, []);
  useEffect(() => { if (!feedback) return undefined; const timer = setTimeout(() => setFeedback(''), 5000); return () => clearTimeout(timer); }, [feedback]);

  const records = promotions || [];
  const filtered = useMemo(() => records
    .filter((record) => record.product.name.toLocaleLowerCase().includes(query.trim().toLocaleLowerCase()))
    .filter((record) => status === 'ALL' || record.status === status), [records, query, status]);
  const promotableProducts = useMemo(() => (products || []).filter((product) => !records.some((record) => record.product.id === product.id)), [products, records]);
  const closeEditor = () => { if (!saving) { setEditorRecord(undefined); setEditorError(''); } };
  const openNew = () => { setEditorError(''); setEditorRecord(null); };
  const save = async (productId, values) => {
    setSaving(true); setEditorError('');
    try {
      const result = await api.updateAdminProductPromotion(productId, values);
      await refreshPromotions({ force: true });
      setEditorRecord(undefined);
      setFeedback(result.catalog_refreshed === false ? 'Promoción guardada; la actualización del catálogo público quedó pendiente.' : 'Promoción guardada correctamente.');
    } catch (requestError) {
      if (!handleUnauthorized(requestError)) setEditorError(requestError.message);
    } finally { setSaving(false); }
  };
  const toggleActive = async (record) => save(record.product.id, {
    active: !record.promotion.active,
    discount_type: record.promotion.discount_type,
    discount_value: record.promotion.discount_value,
    starts_at: record.promotion.starts_at,
    ends_at: record.promotion.ends_at,
    expected_revision: record.promotion.revision,
  });

  return <div className='admin-content admin-promotions-page'>
    <div className='admin-page-heading admin-promotions-heading'><div><p className='admin-eyebrow'>Catálogo administrativo</p><h1>Promociones</h1><p>Gestiona los descuentos activos y programados del catálogo.</p></div><button type='button' className='admin-products-new' onClick={openNew} disabled={!products?.length}>+ Nueva promoción</button></div>
    {feedback && <p className='admin-products-feedback' role='status'>{feedback}</p>}
    <div className='admin-products-toolbar'><label className='admin-products-search'><span>Buscar producto</span><input type='search' value={query} onChange={(event) => setQuery(event.target.value)} placeholder='Buscar producto...' /></label><label className='admin-products-filter'><span>Estado</span><select value={status} onChange={(event) => setStatus(event.target.value)}>{statusOptions.map((option) => <option key={option.value} value={option.value}>{option.label}</option>)}</select></label></div>
    {promotionsLoading && <div className='admin-products-state' role='status'>Cargando promociones...</div>}
    {!promotionsLoading && promotionsError && <div className='admin-products-state admin-products-state--error' role='alert'><p>{promotionsError}</p><button type='button' onClick={() => refreshPromotions({ force: true })}>Reintentar</button></div>}
    {!promotionsLoading && !promotionsError && <div className='admin-promotions-table-wrap'><table className='admin-promotions-table'><thead><tr><th>Producto</th><th>Precio base</th><th>Tipo</th><th>Descuento</th><th>Precio promocional</th><th>Vigencia</th><th>Estado</th><th>Acciones</th></tr></thead><tbody>{filtered.map((record) => <PromotionRow key={record.product.id} record={record} onEdit={() => { setEditorError(''); setEditorRecord(record); }} onToggle={() => toggleActive(record)} saving={saving} />)}{filtered.length === 0 && <tr><td colSpan='8' className='admin-promotions-empty'><strong>{records.length === 0 ? 'No hay promociones creadas.' : 'No encontramos promociones con estos filtros.'}</strong><span>{records.length === 0 ? 'Crea una promoción para mostrar descuentos en el catálogo.' : 'Prueba cambiar la búsqueda o el estado seleccionado.'}</span></td></tr>}</tbody></table></div>}
    {editorRecord !== undefined && <PromotionEditor products={editorRecord ? (products || []).filter((product) => product.id === editorRecord.product.id) : promotableProducts} record={editorRecord} saving={saving} error={editorError} onCancel={closeEditor} onSave={save} />}
  </div>;
}

function PromotionRow({ record, onEdit, onToggle, saving }) {
  const { product, promotion, pricing, status } = record;
  const discount = promotion.discount_type === 'percent' ? `${promotion.discount_value}%` : priceFormatter.format(pricing.discount_cop);
  return <tr><td><strong>{product.name}</strong>{!product.active && <small className='admin-promotion-product-inactive'>Producto inactivo</small>}</td><td>{priceFormatter.format(pricing.base_price_cop)}</td><td>{promotion.discount_type === 'percent' ? 'Porcentaje' : 'Valor fijo'}</td><td>{discount}</td><td>{priceFormatter.format(pricing.effective_price_cop)}</td><td>{formatWindow(promotion.starts_at, promotion.ends_at)}</td><td><span className={`admin-promotion-status status-${status.toLowerCase()}`}>{promotionStatusLabel(status)}</span></td><td><div className='admin-product-actions'><button type='button' onClick={onEdit} disabled={saving}>Editar</button><button type='button' className={promotion.active ? 'is-deactivate' : 'is-activate'} onClick={onToggle} disabled={saving}>{promotion.active ? 'Desactivar' : 'Activar'}</button></div></td></tr>;
}

function formatWindow(startsAt, endsAt) {
  if (!startsAt && !endsAt) return 'Sin límite';
  const format = (value) => new Intl.DateTimeFormat('es-CO', { dateStyle: 'short', timeStyle: 'short', timeZone: 'America/Bogota' }).format(new Date(value));
  if (startsAt && endsAt) return `${format(startsAt)} — ${format(endsAt)}`;
  return startsAt ? `Desde ${format(startsAt)}` : `Hasta ${format(endsAt)}`;
}
