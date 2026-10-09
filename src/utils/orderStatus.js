export const orderStatusLabel = (status) => ({
  PENDING: 'Pendiente',
  PROCESSING: 'En preparación',
  READY: 'Listo',
  SHIPPED: 'En camino',
  DELIVERED: 'Entregado',
  CANCELLED: 'Cancelado',
  PAYMENT_REVIEW_REQUIRED: 'Pago en revisión',
}[status] || status);
