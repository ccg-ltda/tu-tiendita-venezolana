import { formatCurrency, formatDate, orderStatusClass, orderStatusLabel, paymentStatusClass, paymentStatusLabel } from './OrderTable';

export function OrderDetailDrawer({ loading, error, order, onClose, onChangeStatus }) {
  const nextAction = order && ({ PENDING: order.payment_status === 'APPROVED' ? ['PROCESSING', 'Iniciar preparación'] : null, PROCESSING: ['READY', 'Marcar como listo'], READY: ['SHIPPED', 'Marcar como enviado'], SHIPPED: ['DELIVERED', 'Marcar como entregado'] }[order.status]);
  return (
    <div className='admin-order-detail-layer' role='presentation'>
      <button type='button' className='admin-order-detail-backdrop' aria-label='Cerrar detalle del pedido' onClick={onClose} />
      <aside className='admin-order-detail-drawer' aria-label='Detalle del pedido'>
        <header className='admin-order-detail-header'>
          <div>
            <p className='admin-eyebrow'>Pedido</p>
            <h2>Detalle del pedido</h2>
          </div>
          <button type='button' className='admin-order-detail-close' onClick={onClose} aria-label='Cerrar'>×</button>
        </header>

        <div className='admin-order-detail-content'>
          {loading && <p className='admin-order-detail-state'>Cargando detalle del pedido...</p>}
          {!loading && error && <p className='admin-order-detail-error'>{error}</p>}
          {!loading && !error && order && (
            <>
              <section className='admin-order-detail-section'>
                <h3>Pedido</h3>
                <dl className='admin-order-detail-list'>
                  <div><dt>Referencia</dt><dd>{order.reference}</dd></div>
                  <div><dt>Estado operativo</dt><dd><span className={`admin-order-status ${orderStatusClass(order.status)}`}>{orderStatusLabel(order.status)}</span></dd></div>
                  <div><dt>Reserva</dt><dd>{order.reservation_status}</dd></div>
                  <div><dt>Fecha</dt><dd>{formatDate(order.created_at)}</dd></div>
                  <div><dt>Total</dt><dd>{formatCurrency(order.total)}</dd></div>
                </dl>
              </section>
              <section className='admin-order-detail-section'>
                <h3>Pago</h3><dl className='admin-order-detail-list'>
                  <div><dt>Estado</dt><dd><span className={`admin-order-status ${paymentStatusClass(order.payment_status)}`}>{paymentStatusLabel(order.payment_status)}</span></dd></div>
                  {order.payment?.payment_method && <div><dt>Método</dt><dd>{order.payment.payment_method}</dd></div>}
                  {order.payment?.wompi_transaction_id && <div><dt>Transacción Wompi</dt><dd>{order.payment.wompi_transaction_id}</dd></div>}
                  {order.paid_at && <div><dt>Fecha de pago</dt><dd>{formatDate(order.paid_at)}</dd></div>}
                </dl>
              </section>
              <section className='admin-order-detail-section'>
                <h3>Cliente</h3>
                <dl className='admin-order-detail-list'>
                  <div><dt>Nombre</dt><dd>{order.customer_name}</dd></div>
                  <div><dt>Correo</dt><dd>{order.customer_email}</dd></div>
                  <div><dt>Teléfono</dt><dd>{order.customer_phone}</dd></div>
                  <div><dt>Documento</dt><dd>{order.customer_document}</dd></div>
                </dl>
              </section>
              <section className='admin-order-detail-section'>
                <h3>Gestionar pedido</h3>
                {order.status === 'PENDING' && order.payment_status !== 'APPROVED' && <p className='admin-order-detail-state'>El pedido no puede procesarse hasta que el pago esté aprobado.</p>}
                {nextAction && <button type='button' className='admin-order-detail-button' onClick={() => onChangeStatus(nextAction[0])}>{nextAction[1]}</button>}
                {order.status === 'READY' && <button type='button' className='admin-order-detail-button' onClick={() => onChangeStatus('DELIVERED')}>Marcar como entregado</button>}
                {!['DELIVERED', 'CANCELLED'].includes(order.status) && <button type='button' className='admin-order-detail-button' onClick={() => onChangeStatus('CANCELLED')}>Cancelar pedido</button>}
              </section>
              <section className='admin-order-detail-section'>
                <h3>Entrega</h3>
                <dl className='admin-order-detail-list'>
                  <div><dt>Dirección</dt><dd>{order.address}</dd></div>
                  {order.extra && <div><dt>Indicaciones</dt><dd>{order.extra}</dd></div>}
                  <div><dt>Ciudad</dt><dd>{order.city}</dd></div>
                  <div><dt>Región</dt><dd>{order.region}</dd></div>
                  {order.postal && <div><dt>Código postal</dt><dd>{order.postal}</dd></div>}
                </dl>
              </section>
              <section className='admin-order-detail-section'>
                <h3>Productos</h3>
                <ul className='admin-order-items'>
                  {order.items.map((item) => (
                    <li key={item.id}>
                      <div>
                        <strong>{item.product_name}</strong>
                        <span>{item.quantity} × {formatCurrency(item.unit_price)}</span>
                      </div>
                      <b>{formatCurrency(Number(item.unit_price) * Number(item.quantity))}</b>
                    </li>
                  ))}
                </ul>
              </section>
            </>
          )}
        </div>
      </aside>
    </div>
  );
}
