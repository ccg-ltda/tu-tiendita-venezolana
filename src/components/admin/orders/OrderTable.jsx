const currencyFormatter = new Intl.NumberFormat('es-CO', {
  style: 'currency',
  currency: 'COP',
  maximumFractionDigits: 0,
});

const dateFormatter = new Intl.DateTimeFormat('es-CO', {
  dateStyle: 'medium',
  timeStyle: 'short',
});

export const formatCurrency = (value) => currencyFormatter.format(Number(value) || 0);

export const formatDate = (value) => {
  const date = new Date(value);

  return Number.isNaN(date.getTime()) ? value : dateFormatter.format(date);
};

export const orderStatusLabel = (status) => ({ PENDING: 'Pendiente', PROCESSING: 'En preparación', READY: 'Listo', SHIPPED: 'Enviado', DELIVERED: 'Entregado', CANCELLED: 'Cancelado' }[status] || status);
export const paymentStatusLabel = (status) => ({ APPROVED: 'Pagado', PENDING: 'Pendiente', DECLINED: 'Rechazado', VOIDED: 'Anulado', ERROR: 'Error' }[status] || status);

export const paymentStatusClass = (status) => ({ APPROVED: 'status-paid', PENDING: 'status-payment-pending', DECLINED: 'status-declined', VOIDED: 'status-voided', ERROR: 'status-payment-error' }[status] || '');
export const orderStatusClass = (status) => ({ PENDING: 'order-pending', PROCESSING: 'order-processing', READY: 'order-ready', SHIPPED: 'order-shipped', DELIVERED: 'order-delivered', CANCELLED: 'order-cancelled' }[status] || '');

export function OrderTable({ orders, onViewDetail }) {
  return (
    <>
      <div className='admin-orders-table-wrap'>
        <table className='admin-orders-table'>
          <thead>
            <tr>
              <th>Referencia</th>
              <th>Cliente</th>
              <th>Pago</th>
              <th>Estado del pedido</th>
              <th>Total</th>
              <th>Fecha</th>
              <th aria-label='Acciones'>Acciones</th>
            </tr>
          </thead>
          <tbody>
            {orders.map((order) => (
              <tr key={order.id}>
                <td className='admin-order-reference'>{order.reference}</td>
                <td>{order.customer_name}</td>
                <td><span className={`admin-order-status ${paymentStatusClass(order.payment_status)}`}>{paymentStatusLabel(order.payment_status)}</span></td>
                <td><span className={`admin-order-status ${orderStatusClass(order.status)}`}>{orderStatusLabel(order.status)}</span></td>
                <td className='admin-order-total'>{formatCurrency(order.total)}</td>
                <td>{formatDate(order.created_at)}</td>
                <td>
                  <button type='button' className='admin-order-detail-button' onClick={() => onViewDetail(order.id)}>
                    Ver detalle
                  </button>
                </td>
              </tr>
            ))}
          </tbody>
        </table>
      </div>

      <div className='admin-orders-mobile-list'>
        {orders.map((order) => (
          <article key={order.id} className='admin-order-card'>
            <h2 className='admin-order-reference'>{order.reference}</h2>
            <dl className='admin-order-card-details'>
              <div><dt>Cliente</dt><dd>{order.customer_name}</dd></div>
              <div><dt>Pago</dt><dd><span className={`admin-order-status ${paymentStatusClass(order.payment_status)}`}>{paymentStatusLabel(order.payment_status)}</span></dd></div>
              <div><dt>Estado del pedido</dt><dd><span className={`admin-order-status ${orderStatusClass(order.status)}`}>{orderStatusLabel(order.status)}</span></dd></div>
              <div><dt>Total</dt><dd className='admin-order-total'>{formatCurrency(order.total)}</dd></div>
              <div><dt>Fecha</dt><dd>{formatDate(order.created_at)}</dd></div>
            </dl>
            <button type='button' className='admin-order-detail-button' onClick={() => onViewDetail(order.id)}>
              Ver detalle
            </button>
          </article>
        ))}
      </div>
    </>
  );
}
