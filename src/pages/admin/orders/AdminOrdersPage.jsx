import { useCallback, useEffect, useState } from 'react';
import { useNavigate } from 'react-router-dom';
import { OrderDetailDrawer } from '../../../components/admin/orders/OrderDetailDrawer';
import { OrderTable } from '../../../components/admin/orders/OrderTable';
import { api } from '../../../services/api';
import { AdminLayout } from '../AdminPage';

const ORDERS_PER_PAGE = 25;

export function AdminOrdersPage() {
  const navigate = useNavigate();
  const [orders, setOrders] = useState([]);
  const [pagination, setPagination] = useState({ current_page: 1, per_page: ORDERS_PER_PAGE, total: 0, last_page: 1 });
  const [page, setPage] = useState(1);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState('');
  const [selectedOrder, setSelectedOrder] = useState(null);
  const [detailLoading, setDetailLoading] = useState(false);
  const [detailError, setDetailError] = useState('');

  const handleUnauthorized = useCallback((requestError) => {
    if (requestError?.status === 401 || requestError?.status === 403) {
      navigate('/admin/login', { replace: true });
      return true;
    }

    return false;
  }, [navigate]);

  const loadOrders = useCallback(async (requestedPage) => {
    setLoading(true);
    setError('');

    try {
      const response = await api.listAdminOrders({ page: requestedPage, perPage: ORDERS_PER_PAGE });
      setOrders(response.orders);
      setPagination(response.pagination);
    } catch (requestError) {
      if (!handleUnauthorized(requestError)) setError(requestError.message || 'No fue posible cargar los pedidos.');
    } finally {
      setLoading(false);
    }
  }, [handleUnauthorized]);

  useEffect(() => {
    loadOrders(page);
  }, [loadOrders, page]);

  useEffect(() => {
    document.body.classList.add('admin-orders-route');
    return () => document.body.classList.remove('admin-orders-route');
  }, []);

  const openDetail = async (id) => {
    setSelectedOrder(null);
    setDetailError('');
    setDetailLoading(true);

    try {
      const response = await api.getAdminOrder(id);
      setSelectedOrder(response.order);
    } catch (requestError) {
      if (!handleUnauthorized(requestError)) setDetailError(requestError.message || 'No fue posible cargar el detalle del pedido.');
    } finally {
      setDetailLoading(false);
    }
  };

  const closeDetail = () => {
    setSelectedOrder(null);
    setDetailError('');
    setDetailLoading(false);
  };

  const hasDetailOpen = detailLoading || detailError || selectedOrder;
  const currentPage = pagination.current_page || page;
  const lastPage = pagination.last_page || 1;

  return (
    <AdminLayout>
      <div className='admin-content admin-orders-page'>
        <div className='admin-page-heading'>
          <div>
            <p className='admin-eyebrow'>Gestión administrativa</p>
            <h1>Pedidos</h1>
            <p>Consulta los pedidos registrados en la tienda.</p>
          </div>
        </div>

        {loading && <div className='admin-orders-state'>Cargando pedidos...</div>}
        {!loading && error && (
          <div className='admin-orders-state admin-orders-state-error'>
            <p>{error}</p>
            <button type='button' onClick={() => loadOrders(page)}>Reintentar</button>
          </div>
        )}
        {!loading && !error && orders.length === 0 && <div className='admin-orders-state'>No hay pedidos registrados.</div>}
        {!loading && !error && orders.length > 0 && (
          <>
            <OrderTable orders={orders} onViewDetail={openDetail} />
            <nav className='admin-orders-pagination' aria-label='Paginación de pedidos'>
              <button type='button' onClick={() => setPage(currentPage - 1)} disabled={currentPage <= 1}>Anterior</button>
              <span>Página {currentPage} de {lastPage} · {pagination.total} pedidos</span>
              <button type='button' onClick={() => setPage(currentPage + 1)} disabled={currentPage >= lastPage}>Siguiente</button>
            </nav>
          </>
        )}
      </div>
      {hasDetailOpen && <OrderDetailDrawer loading={detailLoading} error={detailError} order={selectedOrder} onClose={closeDetail} />}
    </AdminLayout>
  );
}
