import { useEffect, useMemo, useState } from 'react';
import { dateInputValue, emptyPromotion, promotionPreview, promotionStatusLabel, promotionValidation, toBogotaTimestamp } from './promotionForm';

const priceFormatter = new Intl.NumberFormat('es-CO', { style: 'currency', currency: 'COP', maximumFractionDigits: 0 });

export function PromotionEditor({ products, record, saving, error, onCancel, onSave }) {
  const [productId, setProductId] = useState('');
  const [values, setValues] = useState(emptyPromotion);
  const [validation, setValidation] = useState('');
  const editing = Boolean(record);

  useEffect(() => {
    const promotion = record?.promotion;
    setProductId(record ? String(record.product.id) : '');
    setValues(promotion ? {
      active: promotion.active,
      discount_type: promotion.discount_type,
      discount_value: String(promotion.discount_value),
      starts_at: dateInputValue(promotion.starts_at),
      ends_at: dateInputValue(promotion.ends_at),
      expected_revision: promotion.revision,
    } : emptyPromotion);
    setValidation('');
  }, [record]);

  const product = useMemo(() => products.find((item) => item.id === Number(productId)) || record?.product || null, [productId, products, record]);
  const price = Number(product?.price || 0);
  const value = Number(values.discount_value);
  const { effective } = promotionPreview(values, price);
  const update = (field) => (event) => setValues((current) => ({ ...current, [field]: event.target.type === 'checkbox' ? event.target.checked : event.target.value }));
  const submit = (event) => {
    event.preventDefault();
    if (!product) { setValidation('Selecciona un producto.'); return; }
    const message = promotionValidation(values, price);
    setValidation(message);
    if (message) return;
    onSave(product.id, {
      ...values,
      discount_value: value,
      starts_at: toBogotaTimestamp(values.starts_at),
      ends_at: toBogotaTimestamp(values.ends_at),
    });
  };

  return <div className='admin-promotion-editor-layer' role='presentation'>
    <button type='button' className='admin-promotion-editor-backdrop' aria-label='Cerrar' onClick={onCancel} disabled={saving} />
    <form className='admin-promotion-editor' onSubmit={submit} aria-modal='true' role='dialog' aria-labelledby='promotion-editor-title'>
      <header><div><p className='admin-eyebrow'>{editing ? 'Editar promoción' : 'Nueva promoción'}</p><h2 id='promotion-editor-title'>{editing ? record.product.name : 'Crear promoción'}</h2></div><button type='button' onClick={onCancel} disabled={saving} aria-label='Cerrar'>×</button></header>
      <div className='admin-promotion-editor-content'>
        {!editing && <label><span>Producto</span><select value={productId} onChange={(event) => setProductId(event.target.value)} required><option value=''>Selecciona un producto</option>{products.map((item) => <option key={item.id} value={item.id}>{item.name} — {priceFormatter.format(item.price)}</option>)}</select></label>}
        {editing && <p className='admin-promotion-product-summary'><strong>{record.product.name}</strong><span>Precio base: {priceFormatter.format(price)}</span></p>}
        <label className='admin-promotion-switch'><input type='checkbox' checked={values.active} onChange={update('active')} disabled={saving} /><span>Activar promoción</span></label>
        <label><span>Tipo de descuento</span><select value={values.discount_type} onChange={update('discount_type')} disabled={saving}><option value='percent'>Porcentaje</option><option value='fixed'>Valor fijo</option></select></label>
        <label><span>Valor {values.discount_type === 'percent' ? '(%)' : '(COP)'}</span><input type='number' min='1' max={values.discount_type === 'percent' ? '99' : undefined} step='1' value={values.discount_value} onChange={update('discount_value')} disabled={saving} /></label>
        <label><span>Fecha de inicio (opcional)</span><input type='datetime-local' value={values.starts_at} onChange={update('starts_at')} disabled={saving} /></label>
        <label><span>Fecha de fin (opcional)</span><input type='datetime-local' value={values.ends_at} onChange={update('ends_at')} disabled={saving} /></label>
        <section className='admin-promotion-preview' aria-label='Vista previa de precio'><span>Precio base: <b>{priceFormatter.format(price)}</b></span><span>Descuento: <b>{values.discount_type === 'percent' ? `${value || 0}%` : priceFormatter.format(value || 0)}</b></span><span>Precio promocional: <b>{effective > 0 ? priceFormatter.format(effective) : 'No válido'}</b></span></section>
        {(validation || error) && <p className='admin-promotion-error' role='alert'>{validation || error}</p>}
      </div>
      <footer><button type='button' onClick={onCancel} disabled={saving}>Cancelar</button><button type='submit' className='is-primary' disabled={saving}>{saving ? 'Guardando...' : editing ? 'Guardar promoción' : 'Crear promoción'}</button></footer>
    </form>
  </div>;
}

export { promotionStatusLabel };
