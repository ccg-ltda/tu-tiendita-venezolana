import { useEffect, useState } from 'react';
import { AdminToast } from '../../../components/admin/AdminToast';
import { unlockAdminToastAudio } from '../../../components/admin/adminToastAudio';
import { OrderDetailDrawer } from '../../../components/admin/orders/OrderDetailDrawer';
import { OrderTable } from '../../../components/admin/orders/OrderTable';
import { useAdminData } from '../../../context/AdminDataContext';

const ORDERS_PER_PAGE = 25;

const statusToast = {
  PROCESSING: { variant: 'processing', message: 'El pedido pasó a “En preparación”.' },
  READY: { variant: 'ready', message: 'El pedido fue marcado como “Listo”.' },
  SHIPPED: { variant: 'shipped', message: 'El pedido pasó a “En camino”.' },
  DELIVERED: { variant: 'delivered', message: 'El pedido fue marcado como “Entregado”.' },
};

export function AdminOrdersPage() {
  const { ordersByKey, orderDetailsById, ordersPage, setOrdersPage, ensureOrders, refreshOrders, loadOrderDetail, updateOrderStatus, ordersKey } = useAdminData();
  const [selectedOrder, setSelectedOrder] = useState(null);
  const [selectedOrderId, setSelectedOrderId] = useState(null);
  const [search, setSearch] = useState('');
  const [flowStatus, setFlowStatus] = useState('operational');
  const [orderStatus, setOrderStatus] = useState('');
  const [paymentStatus, setPaymentStatus] = useState('');
  const [updatingStatus, setUpdatingStatus] = useState(false);
  const [toast, setToast] = useState(null);

  const key = ordersKey(ordersPage, ORDERS_PER_PAGE, flowStatus);
  const record = ordersByKey[key];
  const orders = record?.data || [];
  const pagination = record?.meta || { current_page: ordersPage, per_page: ORDERS_PER_PAGE, total: 0, last_page: 1 };
  const initialLoading = !record || record.initialLoading;
  const error = record?.error || '';
  const detailRecord = selectedOrderId ? orderDetailsById[String(selectedOrderId)] : null;
  const detailLoading = Boolean(selectedOrderId && !selectedOrder && detailRecord?.initialLoading);
  const detailError = detailRecord?.error || '';

  useEffect(() => { ensureOrders(ordersPage, ORDERS_PER_PAGE, { flowStatus }); }, [ensureOrders, flowStatus, ordersPage]);
  useEffect(() => {
    document.body.classList.add('admin-orders-route');
    return () => document.body.classList.remove('admin-orders-route');
  }, []);

  const openDetail = async (id) => {
    const cached = orderDetailsById[String(id)]?.data;
    setSelectedOrderId(id);
    setSelectedOrder(cached || null);
    if (cached) return;

    const detail = await loadOrderDetail(id);
    if (detail) setSelectedOrder(detail);
  };

  const closeDetail = () => {
    setSelectedOrder(null);
    setSelectedOrderId(null);
  };

  const currentPage = pagination.current_page || ordersPage;
  const lastPage = pagination.last_page || 1;
  const hasDetailOpen = selectedOrderId !== null;
  const visibleOrders = orders.filter((order) => {
    return (!search || `${order.reference} ${order.customer_name}`.toLowerCase().includes(search.toLowerCase()))
      && (!orderStatus || order.status === orderStatus)
      && (!paymentStatus || order.payment_status === paymentStatus);
  });

  const changeStatus = async (status) => {
    if (!selectedOrder || updatingStatus) return;

    unlockAdminToastAudio();
    setUpdatingStatus(true);
    try {
      const next = await updateOrderStatus(selectedOrder.id, status);
      setSelectedOrder(next);
      const feedback = statusToast[status] || { variant: 'success', message: 'El estado del pedido fue actualizado.' };
      setToast({
        id: `${next.id}-${status}-${Date.now()}`,
        tone: 'success',
        variant: feedback.variant,
        title: 'Pedido actualizado',
        message: feedback.message,
      });
    } catch (requestError) {
      const apiMessage = requestError?.status && requestError.message !== 'No se pudo completar la solicitud.'
        ? requestError.message
        : '';
      setToast({
        id: `error-${selectedOrder.id}-${Date.now()}`,
        tone: 'error',
        variant: 'error',
        title: 'No fue posible actualizar el pedido',
        message: apiMessage || 'Intenta nuevamente.',
      });
    } finally {
      setUpdatingStatus(false);
    }
  };

  return <>
    <div className='admin-content admin-orders-page'>
      <div className='admin-page-heading'>
        <div>
          <p className='admin-eyebrow'>Gestion administrativa</p>
          <h1>Pedidos</h1>
          <p>Consulta los pedidos registrados en la tienda.</p>
        </div>
      </div>

      <div className='admin-orders-filters'>
        <input value={search} onChange={(event) => setSearch(event.target.value)} placeholder='Buscar referencia o cliente' />
        <select value={flowStatus} onChange={(event) => { setFlowStatus(event.target.value); setOrdersPage(1); }} aria-label='Bandeja de pedidos'><option value='operational'>Operativos</option><option value=''>Todos</option><option value='payment-not-completed'>Pago no completado</option></select>
        <select value={orderStatus} onChange={(event) => setOrderStatus(event.target.value)}><option value=''>Todos los estados</option><option value='PENDING'>Pendiente</option><option value='PROCESSING'>En preparación</option><option value='READY'>Listo</option><option value='SHIPPED'>Enviado</option><option value='DELIVERED'>Entregado</option></select>
        <select value={paymentStatus} onChange={(event) => setPaymentStatus(event.target.value)}><option value=''>Todos los pagos</option><option value='APPROVED'>Pagado</option><option value='PENDING'>Pendiente</option><option value='DECLINED'>Rechazado</option><option value='VOIDED'>Anulado</option><option value='ERROR'>Error</option></select>
      </div>

      {initialLoading && <div className='admin-orders-state'>Cargando pedidos...</div>}
      {!initialLoading && error && <div className='admin-orders-state admin-orders-state-error'><p>{error}</p><button type='button' onClick={() => refreshOrders(ordersPage, ORDERS_PER_PAGE, { force: true, flowStatus })}>Reintentar</button></div>}
      {!initialLoading && !error && orders.length === 0 && <div className='admin-orders-state'>No hay pedidos registrados.</div>}
      {!initialLoading && !error && orders.length > 0 && <>
        <OrderTable orders={visibleOrders} onViewDetail={openDetail} />
        <nav className='admin-orders-pagination' aria-label='Paginacion de pedidos'>
          <button type='button' onClick={() => setOrdersPage(currentPage - 1)} disabled={currentPage <= 1}>Anterior</button>
          <span>Pagina {currentPage} de {lastPage} - {pagination.total} pedidos</span>
          <button type='button' onClick={() => setOrdersPage(currentPage + 1)} disabled={currentPage >= lastPage}>Siguiente</button>
        </nav>
      </>}
    </div>
    {hasDetailOpen && <OrderDetailDrawer loading={detailLoading} error={detailError} order={selectedOrder} onClose={closeDetail} onChangeStatus={changeStatus} updatingStatus={updatingStatus} />}
    <AdminToast toast={toast} onDismiss={() => setToast(null)} />
  </>;
}
