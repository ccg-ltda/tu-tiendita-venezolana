import { createContext, useCallback, useContext, useEffect, useMemo, useRef, useState } from 'react';
import { useNavigate } from 'react-router-dom';
import { api } from '../services/api';

const AdminDataContext = createContext(null);
const activeRequests = new Map();
let sessionGeneration = 0;

const ordersKey = (page, perPage, flowStatus = '') => `${page}:${perPage}:${flowStatus || 'all'}`;
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
  const [promotions, setPromotions] = useState(null);
  const [promotionsLoading, setPromotionsLoading] = useState(true);
  const [promotionsRefreshing, setPromotionsRefreshing] = useState(false);
  const [promotionsError, setPromotionsError] = useState('');
  const [coupons, setCoupons] = useState(null); const [couponsLoading, setCouponsLoading] = useState(true); const [couponsRefreshing, setCouponsRefreshing] = useState(false); const [couponsError, setCouponsError] = useState('');
  const [ordersByKey, setOrdersByKey] = useState({});
  const [orderDetailsById, setOrderDetailsById] = useState({});
  const [ordersPage, setOrdersPage] = useState(1);
  const [productView, setProductView] = useState({ search: '', category: '', status: 'all', currentPage: 1 });

  const productsRef = useRef(products);
  const promotionsRef = useRef(promotions);
  const couponsRef = useRef(coupons);
  const ordersRef = useRef(ordersByKey);
  const detailsRef = useRef(orderDetailsById);
  const sessionLoadStartedRef = useRef(false);

  useEffect(() => { productsRef.current = products; }, [products]);
  useEffect(() => { promotionsRef.current = promotions; }, [promotions]);
  useEffect(() => { couponsRef.current = coupons; }, [coupons]);
  useEffect(() => { ordersRef.current = ordersByKey; }, [ordersByKey]);
  useEffect(() => { detailsRef.current = orderDetailsById; }, [orderDetailsById]);

  const clearAdminData = useCallback(() => {
    abortAdminRequests();
    productsRef.current = null;
    promotionsRef.current = null;
    couponsRef.current = null;
    ordersRef.current = {};
    detailsRef.current = {};
    setUser(null);
    setProducts(null);
    setProductsInitialLoading(false);
    setProductsRefreshing(false);
    setProductsError('');
    setProductsLastUpdated(null);
    setPromotions(null);
    setPromotionsLoading(false);
    setPromotionsRefreshing(false);
    setPromotionsError('');
    setCoupons(null); setCouponsLoading(false); setCouponsRefreshing(false); setCouponsError('');
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

  useEffect(() => {
    // In BrowserRouter, useNavigate can receive a new identity after a route
    // transition. Do not turn an internal admin navigation into a new global
    // session-loading state; the session is verified once when this provider
    // is mounted.
    if (sessionLoadStartedRef.current) return;

    sessionLoadStartedRef.current = true;
    loadSession();
  }, [loadSession]);

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

  const refreshPromotions = useCallback(async ({ force = false } = {}) => {
    const currentPromotions = promotionsRef.current;
    if (!force && currentPromotions !== null) return currentPromotions;

    if (currentPromotions === null) setPromotionsLoading(true);
    else setPromotionsRefreshing(true);
    setPromotionsError('');

    try {
      const { value, generation } = await runRequest('promotions', (signal) => api.listAdminPromotions({ signal }));
      if (generation !== sessionGeneration) return promotionsRef.current;

      const nextPromotions = value.promotions || [];
      promotionsRef.current = nextPromotions;
      setPromotions(nextPromotions);

      return nextPromotions;
    } catch (error) {
      if (!isAbortError(error) && !handleUnauthorized(error) && promotionsRef.current === null) {
        setPromotionsError(error.message || 'No fue posible cargar las promociones.');
      }

      return promotionsRef.current;
    } finally {
      setPromotionsLoading(false);
      setPromotionsRefreshing(false);
    }
  }, [handleUnauthorized]);

  const ensurePromotions = useCallback(() => refreshPromotions(), [refreshPromotions]);
  const refreshCoupons = useCallback(async ({ force = false } = {}) => { const current=couponsRef.current;if(!force&&current!==null)return current;if(current===null)setCouponsLoading(true);else setCouponsRefreshing(true);setCouponsError('');try{const {value,generation}=await runRequest('coupons',(signal)=>api.listAdminCoupons({signal}));if(generation!==sessionGeneration)return couponsRef.current;const next=value.coupons||[];couponsRef.current=next;setCoupons(next);return next;}catch(error){if(!isAbortError(error)&&!handleUnauthorized(error)&&couponsRef.current===null)setCouponsError(error.message||'No fue posible cargar los cupones.');return couponsRef.current;}finally{setCouponsLoading(false);setCouponsRefreshing(false);}},[handleUnauthorized]);
  const ensureCoupons = useCallback(() => refreshCoupons(), [refreshCoupons]);

  const applyAdminProduct = useCallback((product) => {
    const next = [...(productsRef.current || []).filter((item) => item.id !== product.id), product].sort((left, right) => left.id - right.id);
    productsRef.current = next;
    setProducts(next);
    setProductsLastUpdated(Date.now());
  }, []);

  const applyAdminCoupon = useCallback((coupon) => {
    const requiredFields = ['coupon_id', 'code', 'description', 'active', 'discount_type', 'discount_value', 'minimum_order_cop', 'max_uses', 'used_count', 'starts_at', 'ends_at', 'created_at', 'updated_at', 'revision', 'status'];
    if (!coupon || !Number.isInteger(coupon.coupon_id) || typeof coupon.code !== 'string' || typeof coupon.active !== 'boolean' || !Number.isInteger(coupon.discount_value) || !Number.isInteger(coupon.used_count) || !Number.isInteger(coupon.revision) || typeof coupon.status !== 'string' || requiredFields.some((field) => !Object.prototype.hasOwnProperty.call(coupon, field)) || couponsRef.current === null) return false;

    let found = false;
    const nextCoupons = couponsRef.current.map((item) => {
      if (item.coupon_id !== coupon.coupon_id) return item;
      found = true;
      return coupon;
    });

    if (!found) return false;

    couponsRef.current = nextCoupons;
    setCoupons(nextCoupons);

    return true;
  }, []);

  const refreshOrders = useCallback(async (page = ordersPage, perPage = 25, { force = false, flowStatus = '' } = {}) => {
    const key = ordersKey(page, perPage, flowStatus);
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
      const { value, generation } = await runRequest(`orders:${key}`, (signal) => api.listAdminOrders({ page, perPage, flowStatus, signal }));
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

  const ensureOrders = useCallback((page = ordersPage, perPage = 25, options = {}) => refreshOrders(page, perPage, options), [ordersPage, refreshOrders]);

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
    promotions,
    promotionsLoading,
    promotionsRefreshing,
    promotionsError,
    coupons,couponsLoading,couponsRefreshing,couponsError,
    ordersByKey,
    orderDetailsById,
    ordersPage,
    setOrdersPage,
    productView,
    setProductView,
    ensureProducts,
    refreshProducts,
    ensurePromotions,
    refreshPromotions,
    ensureCoupons,refreshCoupons,
    applyAdminProduct,
    applyAdminCoupon,
    ensureOrders,
    refreshOrders,
    loadOrderDetail,
    updateOrderStatus,
    logout,
    handleUnauthorized,
    ordersKey,
  }), [applyAdminCoupon, applyAdminProduct, ensureCoupons, ensureOrders, ensureProducts, ensurePromotions, handleUnauthorized, loadOrderDetail, logout, orderDetailsById, ordersByKey, ordersPage, productView, products, productsError, productsInitialLoading, productsLastUpdated, productsRefreshing, promotions, promotionsError, promotionsLoading, promotionsRefreshing, coupons, couponsLoading, couponsRefreshing, couponsError, refreshCoupons, refreshOrders, refreshProducts, refreshPromotions, sessionError, sessionLoading, updateOrderStatus, user]);

  return <AdminDataContext.Provider value={value}>{children}</AdminDataContext.Provider>;
}

export function useAdminData() {
  const context = useContext(AdminDataContext);
  if (!context) throw new Error('useAdminData must be used inside AdminDataProvider.');

  return context;
}
