import { createContext, useCallback, useContext, useEffect, useMemo, useRef, useState } from 'react';
import { useNavigate } from 'react-router-dom';
import { api } from '../services/api';

const AdminDataContext = createContext(null);
const activeRequests = new Map();
let sessionGeneration = 0;

const ordersKey = (page, perPage) => `${page}:${perPage}`;
const isAbortError = (error) => error?.name === 'AbortError';

function runRequest(key, request) {
  const existing = activeRequests.get(key);
  if (existing) return existing.promise;

  const controller = new AbortController();
  const generation = sessionGeneration;
  const promise = request(controller.signal)
    .then((value) => ({ value, generation }))
    .finally(() => {
      if (activeRequests.get(key)?.promise === promise) activeRequests.delete(key);
    });

  activeRequests.set(key, { controller, promise });

  return promise;
}

function abortAdminRequests() {
  sessionGeneration += 1;
  activeRequests.forEach(({ controller }) => controller.abort());
  activeRequests.clear();
}

export function AdminDataProvider({ children }) {
  const navigate = useNavigate();
  const [user, setUser] = useState(null);
  const [sessionLoading, setSessionLoading] = useState(true);
  const [sessionError, setSessionError] = useState('');
  const [products, setProducts] = useState(null);
  const [productsInitialLoading, setProductsInitialLoading] = useState(true);
  const [productsRefreshing, setProductsRefreshing] = useState(false);
  const [productsError, setProductsError] = useState('');
  const [productsLastUpdated, setProductsLastUpdated] = useState(null);
  const [ordersByKey, setOrdersByKey] = useState({});
  const [orderDetailsById, setOrderDetailsById] = useState({});
  const [ordersPage, setOrdersPage] = useState(1);
  const [productView, setProductView] = useState({ search: '', category: '', status: 'all', currentPage: 1 });

  const productsRef = useRef(products);
  const ordersRef = useRef(ordersByKey);
  const detailsRef = useRef(orderDetailsById);

  useEffect(() => { productsRef.current = products; }, [products]);
  useEffect(() => { ordersRef.current = ordersByKey; }, [ordersByKey]);
  useEffect(() => { detailsRef.current = orderDetailsById; }, [orderDetailsById]);

  const clearAdminData = useCallback(() => {
    abortAdminRequests();
    productsRef.current = null;
    ordersRef.current = {};
    detailsRef.current = {};
    setUser(null);
    setProducts(null);
    setProductsInitialLoading(false);
    setProductsRefreshing(false);
    setProductsError('');
    setProductsLastUpdated(null);
    setOrdersByKey({});
    setOrderDetailsById({});
    setOrdersPage(1);
    setProductView({ search: '', category: '', status: 'all', currentPage: 1 });
  }, []);

  const handleUnauthorized = useCallback((error) => {
    if (error?.status !== 401 && error?.status !== 403) return false;

    clearAdminData();
    navigate('/admin/login', { replace: true });

    return true;
  }, [clearAdminData, navigate]);

  const loadSession = useCallback(async () => {
    setSessionLoading(true);
    setSessionError('');

    try {
      const { value, generation } = await runRequest('auth', (signal) => api.me({ signal }));
      if (generation !== sessionGeneration) return null;

      setUser(value.user);

      return value.user;
    } catch (error) {
      if (isAbortError(error)) return null;
      if (!handleUnauthorized(error)) setSessionError('No se pudo verificar la sesion administrativa.');

      return null;
    } finally {
      setSessionLoading(false);
    }
  }, [handleUnauthorized]);

  useEffect(() => { loadSession(); }, [loadSession]);

  const refreshProducts = useCallback(async ({ force = false } = {}) => {
    const currentProducts = productsRef.current;
    if (!force && currentProducts !== null) return currentProducts;

    if (currentProducts === null) setProductsInitialLoading(true);
    else setProductsRefreshing(true);
    setProductsError('');

    try {
      const { value, generation } = await runRequest('products', (signal) => api.listAdminProducts({ signal }));
      if (generation !== sessionGeneration) return productsRef.current;

      const nextProducts = value.products || [];
      productsRef.current = nextProducts;
      setProducts(nextProducts);
      setProductsLastUpdated(Date.now());

      return nextProducts;
    } catch (error) {
      if (!isAbortError(error) && !handleUnauthorized(error) && productsRef.current === null) {
        setProductsError('No fue posible cargar el catalogo de productos. Intentalo de nuevo.');
      }

      return productsRef.current;
    } finally {
      setProductsInitialLoading(false);
      setProductsRefreshing(false);
    }
  }, [handleUnauthorized]);

  const ensureProducts = useCallback(() => refreshProducts(), [refreshProducts]);

  const applyAdminProduct = useCallback((product) => {
    const next = [...(productsRef.current || []).filter((item) => item.id !== product.id), product].sort((left, right) => left.id - right.id);
    productsRef.current = next;
    setProducts(next);
    setProductsLastUpdated(Date.now());
  }, []);

  const refreshOrders = useCallback(async (page = ordersPage, perPage = 25, { force = false } = {}) => {
    const key = ordersKey(page, perPage);
    const current = ordersRef.current[key];
    if (!force && current?.data) return current;

    const pending = {
      ...current,
      data: current?.data ?? null,
      meta: current?.meta ?? null,
      error: '',
      initialLoading: !current?.data,
      refreshing: Boolean(current?.data),
      lastUpdated: current?.lastUpdated ?? null,
    };
    ordersRef.current = { ...ordersRef.current, [key]: pending };
    setOrdersByKey((records) => ({ ...records, [key]: pending }));

    try {
      const { value, generation } = await runRequest(`orders:${key}`, (signal) => api.listAdminOrders({ page, perPage, signal }));
      if (generation !== sessionGeneration) return ordersRef.current[key] ?? null;

      const next = {
        data: value.orders || [],
        meta: value.pagination,
        error: '',
        initialLoading: false,
        refreshing: false,
        lastUpdated: Date.now(),
      };
      ordersRef.current = { ...ordersRef.current, [key]: next };
      setOrdersByKey((records) => ({ ...records, [key]: next }));

      return next;
    } catch (error) {
      if (isAbortError(error) || handleUnauthorized(error)) return ordersRef.current[key] ?? null;

      const failed = {
        ...ordersRef.current[key],
        error: ordersRef.current[key]?.data ? '' : error.message || 'No fue posible cargar los pedidos.',
        refreshError: ordersRef.current[key]?.data ? error.message || 'No fue posible actualizar los pedidos.' : '',
        initialLoading: false,
        refreshing: false,
      };
      ordersRef.current = { ...ordersRef.current, [key]: failed };
      setOrdersByKey((records) => ({ ...records, [key]: failed }));

      return failed;
    }
  }, [handleUnauthorized, ordersPage]);

  const ensureOrders = useCallback((page = ordersPage, perPage = 25) => refreshOrders(page, perPage), [ordersPage, refreshOrders]);

  const loadOrderDetail = useCallback(async (id, { force = false } = {}) => {
    const key = String(id);
    const current = detailsRef.current[key];
    if (!force && current?.data) return current.data;

    const pending = { ...current, data: current?.data ?? null, error: '', initialLoading: !current?.data, refreshing: Boolean(current?.data) };
    detailsRef.current = { ...detailsRef.current, [key]: pending };
    setOrderDetailsById((records) => ({ ...records, [key]: pending }));

    try {
      const { value, generation } = await runRequest(`order-detail:${key}`, (signal) => api.getAdminOrder(id, { signal }));
      if (generation !== sessionGeneration) return detailsRef.current[key]?.data ?? null;

      const next = { data: value.order, error: '', initialLoading: false, refreshing: false, lastUpdated: Date.now() };
      detailsRef.current = { ...detailsRef.current, [key]: next };
      setOrderDetailsById((records) => ({ ...records, [key]: next }));

      return next.data;
    } catch (error) {
      if (isAbortError(error) || handleUnauthorized(error)) return detailsRef.current[key]?.data ?? null;

      const failed = { ...detailsRef.current[key], error: error.message || 'No fue posible cargar el detalle del pedido.', initialLoading: false, refreshing: false };
      detailsRef.current = { ...detailsRef.current, [key]: failed };
      setOrderDetailsById((records) => ({ ...records, [key]: failed }));

      return null;
    }
  }, [handleUnauthorized]);

  const updateOrderStatus = useCallback(async (id, status) => {
    try {
      const response = await api.updateAdminOrderStatus(id, status);
      const order = response.order;
      const key = String(id);
      const detail = { data: order, error: '', initialLoading: false, refreshing: false, lastUpdated: Date.now() };
      detailsRef.current = { ...detailsRef.current, [key]: detail };
      setOrderDetailsById((records) => ({ ...records, [key]: detail }));
      Object.keys(ordersRef.current).forEach((listKey) => {
        const record = ordersRef.current[listKey];
        if (!record?.data) return;
        const next = { ...record, data: record.data.map((item) => item.id === id ? { ...item, status: order.status } : item) };
        ordersRef.current = { ...ordersRef.current, [listKey]: next };
        setOrdersByKey((records) => ({ ...records, [listKey]: next }));
      });
      return order;
    } catch (error) {
      handleUnauthorized(error);
      throw error;
    }
  }, [handleUnauthorized]);

  const logout = useCallback(async () => {
    try {
      await api.logout();
    } finally {
      clearAdminData();
      navigate('/admin/login', { replace: true });
    }
  }, [clearAdminData, navigate]);

  const value = useMemo(() => ({
    user,
    sessionLoading,
    sessionError,
    products,
    productsInitialLoading,
    productsRefreshing,
    productsError,
    productsLastUpdated,
    ordersByKey,
    orderDetailsById,
    ordersPage,
    setOrdersPage,
    productView,
    setProductView,
    ensureProducts,
    refreshProducts,
    applyAdminProduct,
    ensureOrders,
    refreshOrders,
    loadOrderDetail,
    updateOrderStatus,
    logout,
    handleUnauthorized,
    ordersKey,
  }), [applyAdminProduct, ensureOrders, ensureProducts, handleUnauthorized, loadOrderDetail, logout, orderDetailsById, ordersByKey, ordersPage, productView, products, productsError, productsInitialLoading, productsLastUpdated, productsRefreshing, refreshOrders, refreshProducts, sessionError, sessionLoading, updateOrderStatus, user]);

  return <AdminDataContext.Provider value={value}>{children}</AdminDataContext.Provider>;
}

export function useAdminData() {
  const context = useContext(AdminDataContext);
  if (!context) throw new Error('useAdminData must be used inside AdminDataProvider.');

  return context;
}
