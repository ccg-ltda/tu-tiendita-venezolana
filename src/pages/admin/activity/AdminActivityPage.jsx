import { useCallback, useEffect, useRef, useState } from 'react';
import { api } from '../../../services/api';
import { useAdminData } from '../../../context/AdminDataContext';
import { orderStatusLabel } from '../../../utils/orderStatus';

const INITIAL_FILTERS = { adminId: '', resourceType: '', action: '', dateFrom: '', dateTo: '', includeAuth: false };
const resourceLabels = { PRODUCT: 'Producto', PROMOTION: 'Promoción', COUPON: 'Cupón', ORDER: 'Pedido', CATEGORY: 'Categoría', SUBCATEGORY: 'Subcategoría', ADMIN: 'Administrador', AUTH: 'Acceso' };
const actionLabels = { CREATE: 'Creó', UPDATE: 'Actualizó', STATUS_CHANGE: 'Cambió estado', ACTIVATE: 'Activó', DEACTIVATE: 'Desactivó', LOGIN: 'Inició sesión', LOGOUT: 'Cerró sesión' };
const fieldLabels = { name: 'Nombre', category: 'Categoría', subcategory: 'Subcategoría', product_name: 'Producto', presentation: 'Presentación', price: 'Precio', price_cop: 'Precio', inventory: 'Inventario', image: 'Imagen', active: 'Estado', code: 'Código', description: 'Descripción', status: 'Estado', payment_status: 'Estado del pago', reference: 'Referencia', discount_type: 'Tipo de descuento', discount_value: 'Valor del descuento', minimum_order_cop: 'Pedido mínimo', min_order: 'Pedido mínimo', max_uses: 'Uso máximo', used_count: 'Usos realizados', starts_at: 'Fecha de inicio', ends_at: 'Fecha de finalización', role: 'Rol' };
const technicalFields = new Set(['id', 'product_id', 'coupon_id', 'category_id', 'subcategory_id', 'order_id', 'admin_id', 'revision', 'request_id', 'img', 'image', 'image_path', 'legacy_img', 'slug', 'sort_order', 'created_at', 'updated_at']);

export function AdminActivityPage() {
  const { handleUnauthorized } = useAdminData();
  const [filters, setFilters] = useState(INITIAL_FILTERS);
  const [items, setItems] = useState([]);
  const [administrators, setAdministrators] = useState([]);
  const [page, setPage] = useState({ hasMore: false, nextBeforeId: null });
  const [loading, setLoading] = useState(true);
  const [loadingMore, setLoadingMore] = useState(false);
  const [error, setError] = useState('');
  const [selected, setSelected] = useState(null);
  const request = useRef(null);
  const administratorsRequest = useRef(null);
  const loadRef = useRef(null);
  const loadAdministratorsRef = useRef(null);

  const load = useCallback(async (nextFilters, { append = false, beforeId = null } = {}) => {
    request.current?.abort();
    const controller = new AbortController();
    request.current = controller;
    if (append) setLoadingMore(true); else { setLoading(true); setError(''); }

    try {
      const result = await api.listAdminActivity({ ...nextFilters, beforeId, signal: controller.signal });
      if (request.current !== controller) return;
      setItems((current) => append ? [...current, ...(result.items || [])] : result.items || []);
      setPage({ hasMore: Boolean(result.has_more), nextBeforeId: result.next_before_id ?? null });
    } catch (requestError) {
      if (requestError.name !== 'AbortError' && !handleUnauthorized(requestError)) setError(requestError.message || 'No fue posible cargar el historial.');
    } finally {
      if (request.current !== controller) return;
      request.current = null;
      if (append) setLoadingMore(false); else setLoading(false);
    }
  }, [handleUnauthorized]);

  const loadAdministrators = useCallback(async () => {
    administratorsRequest.current?.abort();
    const controller = new AbortController();
    administratorsRequest.current = controller;

    try {
      const result = await api.listAdminActivityAdministrators({ signal: controller.signal });
      if (administratorsRequest.current === controller) setAdministrators(result.administrators || []);
    } catch (requestError) {
      if (requestError.name !== 'AbortError') handleUnauthorized(requestError);
    } finally {
      if (administratorsRequest.current === controller) administratorsRequest.current = null;
    }
  }, [handleUnauthorized]);

  useEffect(() => { loadRef.current = load; }, [load]);
  useEffect(() => { loadAdministratorsRef.current = loadAdministrators; }, [loadAdministrators]);

  useEffect(() => {
    loadRef.current?.(INITIAL_FILTERS);
    loadAdministratorsRef.current?.();
    return () => { request.current?.abort(); administratorsRequest.current?.abort(); };
  }, []);

  const updateFilters = (changes) => {
    setFilters((current) => ({ ...current, ...changes }));
  };

  const applyFilters = () => {
    setSelected(null);
    load(filters);
  };

  const loadMore = () => { if (page.hasMore && page.nextBeforeId) load(filters, { append: true, beforeId: page.nextBeforeId }); };

  return <div className='admin-content admin-activity-page'>
    <div className='admin-page-heading admin-promotions-heading'>
      <div><p className='admin-eyebrow'>Gestión administrativa</p><h1>Historial</h1><p>Consulta las acciones administrativas registradas en la tienda.</p></div>
    </div>

    <div className='admin-activity-filters' aria-label='Filtros de historial'>
      <label><span>Administrador</span><select value={filters.adminId} onChange={(event) => updateFilters({ adminId: event.target.value })}><option value=''>Todos los administradores</option>{administrators.map((admin) => <option key={admin.id} value={admin.id}>{admin.name} ({admin.username}){admin.active ? '' : ' — Inactivo'}</option>)}</select></label>
      <label><span>Módulo</span><select value={filters.resourceType} onChange={(event) => updateFilters({ resourceType: event.target.value })}><option value=''>Todos los módulos</option>{Object.entries(resourceLabels).map(([value, label]) => <option key={value} value={value}>{label}</option>)}</select></label>
      <label><span>Acción</span><select value={filters.action} onChange={(event) => updateFilters({ action: event.target.value })}><option value=''>Todas las acciones</option>{Object.entries(actionLabels).map(([value, label]) => <option key={value} value={value}>{label}</option>)}</select></label>
      <label><span>Desde</span><input type='date' value={filters.dateFrom} onChange={(event) => updateFilters({ dateFrom: event.target.value })} /></label>
      <label><span>Hasta</span><input type='date' value={filters.dateTo} onChange={(event) => updateFilters({ dateTo: event.target.value })} /></label>
      <label className='admin-activity-toggle'><input type='checkbox' checked={filters.includeAuth} onChange={(event) => updateFilters({ includeAuth: event.target.checked })} /><span>Incluir accesos</span></label>
      <button type='button' onClick={applyFilters} disabled={loading}>Aplicar filtros</button>
    </div>

    {loading && <div className='admin-activity-state' role='status'>Cargando historial...</div>}
    {!loading && error && <div className='admin-activity-state admin-activity-state--error' role='alert'><p>{error}</p><button type='button' onClick={() => load(filters)}>Reintentar</button></div>}
    {!loading && !error && items.length === 0 && <div className='admin-activity-state'>No hay acciones que coincidan con los filtros seleccionados.</div>}
    {!loading && !error && items.length > 0 && <>
      <div className='admin-activity-table-wrap'><table className='admin-activity-table'><thead><tr><th>Fecha</th><th>Administrador</th><th>Módulo</th><th>Acción</th><th>Recurso</th></tr></thead><tbody>{items.map((item) => <tr key={item.audit_id} className='admin-activity-row' tabIndex='0' onClick={() => setSelected(item)} onKeyDown={(event) => { if (event.key === 'Enter' || event.key === ' ') { event.preventDefault(); setSelected(item); } }}><td>{formatDate(item.created_at)}</td><td><strong>{item.username}</strong></td><td>{resourceLabels[item.resource_type] || item.resource_type}</td><td>{actionLabels[item.action] || item.action}</td><td>{resourceName(item)}</td></tr>)}</tbody></table></div>
      {page.hasMore && <div className='admin-activity-more'><button type='button' onClick={loadMore} disabled={loadingMore}>{loadingMore ? 'Cargando...' : 'Cargar más'}</button></div>}
    </>}
    {selected && <ActivityDrawer item={selected} onClose={() => setSelected(null)} />}
  </div>;
}

function ActivityDrawer({ item, onClose }) {
  return <div className='admin-activity-drawer-layer' role='presentation'><button type='button' className='admin-activity-drawer-backdrop' aria-label='Cerrar detalle' onClick={onClose} /><aside className='admin-activity-drawer' role='dialog' aria-modal='true' aria-labelledby='activity-detail-title'><header><div><p className='admin-eyebrow'>Detalle de actividad</p><h2 id='activity-detail-title'>{actionLabels[item.action] || item.action}</h2></div><button type='button' onClick={onClose} aria-label='Cerrar detalle'>×</button></header><div className='admin-activity-drawer-content'><dl className='admin-activity-detail-list'><Detail label='Administrador' value={item.username} /><Detail label='Fecha' value={formatDate(item.created_at)} /><Detail label='Acción' value={actionLabels[item.action] || item.action} /><Detail label='Módulo' value={resourceLabels[item.resource_type] || item.resource_type} /><Detail label='Recurso' value={resourceName(item)} /></dl><ChangeSet title='Antes' value={item.before} empty='Sin estado anterior.' /><ChangeSet title='Después' value={item.after} empty='Sin estado posterior.' /><TechnicalInfo item={item} /></div></aside></div>;
}

function Detail({ label, value }) { return <div><dt>{label}</dt><dd>{value || '—'}</dd></div>; }
function ChangeSet({ title, value, empty }) { const entries = visibleEntries(value); return <section className='admin-activity-changes'><h3>{title}</h3>{entries.length === 0 ? <p>{empty}</p> : <dl>{entries.map(([key, item]) => <Detail key={key} label={auditLabel(key, value) || humanize(key)} value={formatAuditValue(key, item, value)} />)}</dl>}</section>; }
function TechnicalInfo({ item }) { const before = technicalEntries(item.before); const after = technicalEntries(item.after); return <details className='admin-activity-technical'><summary>Información técnica</summary><Detail label='ID de recurso' value={String(item.resource_id)} /><Detail label='ID auditoría' value={String(item.audit_id)} /><Detail label='Request ID' value={item.request_id} />{before.map(([key, value]) => <Detail key={`before-${key}`} label={`Antes · ${technicalLabel(key)}`} value={formatTechnicalValue(value)} />)}{after.map(([key, value]) => <Detail key={`after-${key}`} label={`Después · ${technicalLabel(key)}`} value={formatTechnicalValue(value)} />)}</details>; }
function technicalLabel(key) { return ({ id: 'ID interno', product_id: 'ID de producto', coupon_id: 'ID de cupón', category_id: 'ID categoría', subcategory_id: 'ID subcategoría', order_id: 'ORDER ID', admin_id: 'ID administrador', revision: 'Revisión', image: 'Ruta de imagen', image_path: 'Ruta de imagen', img: 'Imagen heredada', legacy_img: 'Imagen heredada', slug: 'Slug', sort_order: 'Orden', created_at: 'Creado', updated_at: 'Actualizado' }[key] || humanize(key)); }
function formatTechnicalValue(value) { return value === null || value === '' ? '—' : typeof value === 'object' ? JSON.stringify(value) : String(value); }
function resourceName(item) {
  const type = resourceLabels[item.resource_type] || item.resource_type;
  if (typeof item.resource_label === 'string' && item.resource_label.trim() !== '') return `${type}: ${item.resource_label}`;
  if (item.resource_type === 'AUTH') return 'Acceso administrativo';
  if (item.resource_type !== 'ORDER') return `${type} #${item.resource_id}`;
  const reference = orderReference(item);
  return reference ? `Pedido: ${reference}` : `Pedido #${item.resource_id}`;
}
function orderReference(item) {
  if (typeof item.resource_id === 'string' && !/^\d+$/.test(item.resource_id)) return item.resource_id;
  for (const snapshot of [item.before, item.after]) {
    if (snapshot && typeof snapshot.reference === 'string' && snapshot.reference.trim() !== '') return snapshot.reference;
  }
  return null;
}
function visibleEntries(value) { return value && typeof value === 'object' ? Object.entries(value).filter(([key, item]) => !isTechnical(key) && !isRedundantStatus(key, item, value)) : []; }
function technicalEntries(value) { return value && typeof value === 'object' ? Object.entries(value).filter(([key]) => isTechnical(key) || key === 'image') : []; }
function isTechnical(key) { return (technicalFields.has(key) && key !== 'image') || key.endsWith('_id') || key.endsWith('_path'); }
function isRedundantStatus(key, value, snapshot) { return key === 'status' && typeof snapshot?.active === 'boolean' && String(value).toUpperCase() === (snapshot.active ? 'ACTIVE' : 'INACTIVE'); }
function auditLabel(key, snapshot) { if (key === 'status' && typeof snapshot?.active === 'boolean') return 'Vigencia'; return fieldLabels[key]; }
function formatDate(value) { if (!value) return '—'; const date = new Date(value); return Number.isNaN(date.getTime()) ? String(value) : new Intl.DateTimeFormat('es-CO', { dateStyle: 'medium', timeStyle: 'short', timeZone: 'America/Bogota' }).format(date); }
function formatAuditValue(key, value, snapshot) { if (key === 'active') return value ? 'Activo' : 'Inactivo'; if (key === 'image') return value ? 'Disponible' : 'Sin imagen'; if (['price', 'price_cop'].includes(key)) return formatMoney(value); if (['minimum_order_cop', 'min_order'].includes(key)) return Number(value) === 0 ? 'Sin mínimo' : formatMoney(value); if (key === 'discount_type') return discountTypeLabel(value); if (key === 'discount_value') return isPercent(snapshot) ? `${value}%` : formatMoney(value); if (['starts_at', 'ends_at'].includes(key)) return formatDate(value); if (key === 'status') return statusLabel(value); if (key === 'payment_status') return paymentStatusLabel(value); if (key === 'max_uses' && (value === null || value === '')) return 'Sin límite'; return formatValue(value); }
function isPercent(snapshot) { return ['PERCENT', 'PERCENTAGE', 'PORCENTAJE'].includes(String(snapshot?.discount_type || '').toUpperCase()); }
function discountTypeLabel(value) { return ({ PERCENT: 'Porcentaje', PERCENTAGE: 'Porcentaje', PORCENTAJE: 'Porcentaje', FIXED: 'Valor fijo', FIXED_AMOUNT: 'Valor fijo', VALOR_FIJO: 'Valor fijo' }[String(value || '').toUpperCase()] || formatValue(value)); }
function statusLabel(value) { const normalized = String(value || '').toUpperCase(); if (normalized === 'ACTIVE') return 'Activo'; if (normalized === 'INACTIVE') return 'Inactivo'; if (normalized === 'SCHEDULED') return 'Programada'; if (normalized === 'EXPIRED') return 'Vencida'; if (normalized === 'NONE') return 'Sin promoción'; return orderStatusLabel(value); }
function paymentStatusLabel(value) { return ({ APPROVED: 'Aprobado', PENDING: 'Pendiente', DECLINED: 'Rechazado', VOIDED: 'Anulado', ERROR: 'Error' }[value] || formatValue(value)); }
function formatMoney(value) { const amount = Number(value); return Number.isFinite(amount) ? new Intl.NumberFormat('es-CO', { style: 'currency', currency: 'COP', maximumFractionDigits: 0 }).format(amount) : formatValue(value); }
function humanize(value) { return String(value).replace(/_/g, ' ').replace(/\b\w/g, (letter) => letter.toUpperCase()); }
function formatValue(value) { if (value === null || value === '') return '—'; if (value === true) return 'Sí'; if (value === false) return 'No'; return typeof value === 'object' ? JSON.stringify(value) : String(value); }
