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

export function OrderTable({ orders, onViewDetail }) {
  return (
    <div className='admin-orders-table-wrap'>
      <table className='admin-orders-table'>
        <thead>
          <tr>
            <th>Referencia</th>
            <th>Cliente</th>
            <th>Estado</th>
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
              <td><span className='admin-order-status'>{order.status}</span></td>
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
  );
}
