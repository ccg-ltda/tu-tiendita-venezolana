export const emptyPromotion = { active: true, discount_type: 'percent', discount_value: '', starts_at: '', ends_at: '', expected_revision: null };

export function promotionValidation(values, price) {
  const value = Number(values.discount_value);
  if (!Number.isInteger(value) || value < 1) return 'Ingresa un descuento entero mayor que cero.';
  if (values.discount_type === 'percent' && value > 99) return 'El porcentaje debe estar entre 1% y 99%.';
  if (values.discount_type === 'fixed' && value >= price) return 'El descuento fijo debe ser menor al precio base.';
  if (values.starts_at && values.ends_at && values.starts_at > values.ends_at) return 'La fecha de inicio no puede ser posterior a la fecha de fin.';
  return '';
}

export function promotionPreview(values, price) {
  const value = Number(values.discount_value);
  const discount = Number.isInteger(value) && value > 0
    ? values.discount_type === 'percent' ? Math.floor(price * value / 100) : value
    : 0;
  return { discount, effective: price - discount };
}

export function dateInputValue(value) { return value ? value.slice(0, 16) : ''; }
export function toBogotaTimestamp(value) { return value ? `${value}:00.000-05:00` : null; }
export function promotionStatusLabel(status) { return ({ ACTIVE: 'Activa', SCHEDULED: 'Programada', EXPIRED: 'Vencida', INACTIVE: 'Inactiva', NONE: 'Sin promoción' })[status] || 'Sin promoción'; }
