import { formatCurrency, formatDate, isPaymentNotCompleted, orderDisplayStatusClass, orderDisplayStatusLabel, paymentStatusClass, paymentStatusLabel } from './OrderTable';
import { orderStatusLabel } from '../../../utils/orderStatus';

export function OrderDetailDrawer({ loading, error, order, onClose, onChangeStatus, updatingStatus = false }) {
  const paymentNotCompleted = isPaymentNotCompleted(order);
  const nextAction = order && !paymentNotCompleted && ({ PENDING: order.payment_status === 'APPROVED' ? ['PROCESSING', 'Iniciar preparación'] : null, PROCESSING: ['READY', 'Marcar como listo'], READY: ['SHIPPED', 'Marcar como enviado'], SHIPPED: ['DELIVERED', 'Marcar como entregado'] }[order.status]);
  const deliveryType = order?.delivery_type || order?.shipping_type || order?.delivery_method;
  return (
    <div className='admin-order-detail-layer' role='presentation'>
      <button type='button' className='admin-order-detail-backdrop' aria-label='Cerrar detalle del pedido' onClick={onClose} />
      <aside className='admin-order-detail-drawer' aria-label='Detalle del pedido'>
        <header className='admin-order-detail-header'>
          <div>
            <p className='admin-eyebrow'>Pedido</p>
            <h2>Detalle del pedido</h2>
          </div>
          <div className='admin-order-detail-header-actions'>
            {!loading && !error && order && <button type='button' className='admin-order-detail-print' onClick={() => window.print()}>Imprimir resumen</button>}
            <button type='button' className='admin-order-detail-close' onClick={onClose} aria-label='Cerrar'>×</button>
          </div>
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
                  <div><dt>Estado operativo</dt><dd><span className={`admin-order-status ${orderDisplayStatusClass(order)}`}>{orderDisplayStatusLabel(order)}</span></dd></div>
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
                {paymentNotCompleted && <p className='admin-order-detail-expired' role='status'><strong>Pago no completado</strong><span>La reserva venció sin que Wompi confirmara el pago. El inventario reservado fue liberado automáticamente.</span></p>}
                {!paymentNotCompleted && order.status === 'PENDING' && order.payment_status !== 'APPROVED' && <p className='admin-order-detail-state'>El pedido no puede procesarse hasta que el pago esté aprobado.</p>}
                {nextAction && <button type='button' className='admin-order-detail-button' onClick={() => onChangeStatus(nextAction[0])} disabled={updatingStatus} aria-busy={updatingStatus}>{updatingStatus ? 'Actualizando...' : nextAction[1]}</button>}
                {!paymentNotCompleted && order.status === 'READY' && <button type='button' className='admin-order-detail-button' onClick={() => onChangeStatus('DELIVERED')} disabled={updatingStatus} aria-busy={updatingStatus}>{updatingStatus ? 'Actualizando...' : 'Marcar como entregado'}</button>}
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

        {!loading && !error && order && (
          <article className='order-print-summary' aria-label='Recibo de pedido'>
            <header className='order-receipt-header'>
              <img src='/assets/logo.png' alt='Tu Tiendita Venezolana' />
              <div>
                <strong>Tu Tiendita Venezolana</strong>
                <h1>RECIBO DE PEDIDO</h1>
              </div>
            </header>

            <dl className='order-receipt-summary'>
              <div><dt>Referencia</dt><dd>{order.reference}</dd></div>
              <div><dt>Fecha</dt><dd>{formatDate(order.created_at)}</dd></div>
            </dl>

            <section className='order-receipt-section'>
              <h2>Datos del cliente</h2>
              <dl className='order-receipt-details'>
                <div><dt>Nombre</dt><dd>{order.customer_name}</dd></div>
                <div><dt>Teléfono</dt><dd>{order.customer_phone}</dd></div>
                {order.customer_email && <div><dt>Correo</dt><dd>{order.customer_email}</dd></div>}
                <div className='order-receipt-address'><dt>Dirección</dt><dd>{order.address}{order.extra ? ` · ${order.extra}` : ''}</dd></div>
                <div><dt>Ciudad / región</dt><dd>{[order.city, order.region].filter(Boolean).join(' · ')}</dd></div>
                {deliveryType && <div><dt>Tipo de entrega</dt><dd>{deliveryType}</dd></div>}
              </dl>
            </section>

            <section className='order-receipt-section'>
              <h2>Estado del pedido</h2>
              <dl className='order-receipt-details order-receipt-details--status'>
                <div><dt>Pago</dt><dd>{paymentStatusLabel(order.payment_status)}</dd></div>
                <div><dt>Pedido</dt><dd>{orderStatusLabel(order.status)}</dd></div>
              </dl>
            </section>

            <table className='order-receipt-items'>
              <thead><tr><th>Producto</th><th>Cant.</th><th>Precio unitario</th><th>Subtotal</th></tr></thead>
              <tbody>
                {order.items.map((item) => (
                  <tr key={item.id}>
                    <td>{item.product_name}</td>
                    <td>{item.quantity}</td>
                    <td>{formatCurrency(item.unit_price)}</td>
                    <td>{formatCurrency(Number(item.unit_price) * Number(item.quantity))}</td>
                  </tr>
                ))}
              </tbody>
            </table>

            <div className='order-receipt-total'><span>TOTAL</span><strong>{formatCurrency(order.total)}</strong></div>
            <p className='order-receipt-footer'>Gracias por tu compra en Tu Tiendita Venezolana.</p>
          </article>
        )}
      </aside>
    </div>
  );
}
