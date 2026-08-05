export const formatPrice = (value) =>
  value === 0 ? 'Precio a consultar' : `$${Math.round(value).toLocaleString('es-CO')}`;

export const normalizeText = (value = '') =>
  String(value).toLowerCase().normalize('NFD').replace(/[\u0300-\u036f]/g, '');

export const unique = (values) => [...new Set(values)];
