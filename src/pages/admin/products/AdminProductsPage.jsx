import { useCallback, useEffect, useMemo, useState } from 'react';
import { useNavigate } from 'react-router-dom';
import { ProductForm } from '../../../components/admin/products/ProductForm';
import { ProductTable } from '../../../components/admin/products/ProductTable';
import { AdminLayout } from '../AdminPage';
import { api } from '../../../services/api';

const PRODUCTS_PER_PAGE = 25;

export function AdminProductsPage() {
  const navigate = useNavigate();
  const [products, setProducts] = useState([]);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState('');
  const [feedback, setFeedback] = useState('');
  const [search, setSearch] = useState('');
  const [category, setCategory] = useState('');
  const [status, setStatus] = useState('all');
  const [currentPage, setCurrentPage] = useState(1);
  const [drawerMode, setDrawerMode] = useState(null);
  const [drawerProduct, setDrawerProduct] = useState(null);
  const [savingProduct, setSavingProduct] = useState(false);
  const [formError, setFormError] = useState('');
  const [formErrors, setFormErrors] = useState({});
  const [statusProduct, setStatusProduct] = useState(null);
  const [statusChangingId, setStatusChangingId] = useState(null);

  const handleUnauthorized = useCallback((requestError) => {
    if (requestError.status === 401 || requestError.status === 403) {
      navigate('/admin/login', { replace: true });
      return true;
    }
    return false;
  }, [navigate]);

  const loadProducts = useCallback(async () => {
    setLoading(true);
    setError('');
    try {
      const response = await api.listAdminProducts();
      setProducts(response.products || []);
    } catch (requestError) {
      if (!handleUnauthorized(requestError)) setError('No fue posible cargar el catálogo de productos. Inténtalo de nuevo.');
    } finally {
      setLoading(false);
    }
  }, [handleUnauthorized]);

  useEffect(() => { loadProducts(); }, [loadProducts]);
  useEffect(() => {
    document.body.classList.add('admin-products-route');
    return () => document.body.classList.remove('admin-products-route');
  }, []);
  useEffect(() => {
    if (!feedback) return undefined;

    const timeoutId = setTimeout(() => setFeedback(''), 5000);

    return () => clearTimeout(timeoutId);
  }, [feedback]);

  const categories = useMemo(() => [...new Set(products.map((product) => product.category).filter(Boolean))].sort((first, second) => first.localeCompare(second, 'es')), [products]);
  const filteredProducts = useMemo(() => products
    .filter((product) => product.name.toLocaleLowerCase().includes(search.trim().toLocaleLowerCase()))
    .filter((product) => !category || product.category === category)
    .filter((product) => status === 'all' || (status === 'active' ? product.active : !product.active)), [products, search, category, status]);
  const totalPages = Math.max(1, Math.ceil(filteredProducts.length / PRODUCTS_PER_PAGE));
  const activePage = Math.min(currentPage, totalPages);
  const paginatedProducts = filteredProducts.slice((activePage - 1) * PRODUCTS_PER_PAGE, activePage * PRODUCTS_PER_PAGE);
  const firstProduct = filteredProducts.length ? ((activePage - 1) * PRODUCTS_PER_PAGE) + 1 : 0;
  const lastProduct = Math.min(activePage * PRODUCTS_PER_PAGE, filteredProducts.length);
  const pageNumbers = getPageNumbers(activePage, totalPages);
  const resetPage = (setter) => (event) => { setter(event.target.value); setCurrentPage(1); };
  const closeDrawer = () => { if (!savingProduct) { setDrawerMode(null); setDrawerProduct(null); setFormError(''); setFormErrors({}); } };
  const openCreate = () => { setFeedback(''); setDrawerProduct(null); setDrawerMode('create'); setFormError(''); setFormErrors({}); };
  const openEdit = (product) => { setFeedback(''); setDrawerProduct(product); setDrawerMode('edit'); setFormError(''); setFormErrors({}); };

  const saveProduct = async (values) => {
    setSavingProduct(true);
    setFormError('');
    setFormErrors({});
    try {
      if (drawerMode === 'create') await api.createAdminProduct(values);
      else await api.updateAdminProduct(drawerProduct.id, values);
      await loadProducts();
      setFeedback(drawerMode === 'create' ? 'Producto creado correctamente.' : 'Producto actualizado correctamente.');
      setDrawerMode(null);
      setDrawerProduct(null);
    } catch (requestError) {
      if (!handleUnauthorized(requestError)) {
        setFormError(requestError.message);
        setFormErrors(requestError.status === 422 ? requestError.details || {} : {});
      }
    } finally {
      setSavingProduct(false);
    }
  };

  const changeStatus = async () => {
    if (!statusProduct) return;
    setStatusChangingId(statusProduct.id);
    setFeedback('');
    try {
      await api.updateAdminProductStatus(statusProduct.id, !statusProduct.active);
      await loadProducts();
      setFeedback(statusProduct.active ? 'Producto desactivado.' : 'Producto activado.');
      setStatusProduct(null);
    } catch (requestError) {
      if (!handleUnauthorized(requestError)) setError(requestError.message);
    } finally {
      setStatusChangingId(null);
    }
  };

  return <AdminLayout><div className='admin-content admin-products-page'>
    <div className='admin-page-heading'><div><p className='admin-eyebrow'>Catálogo administrativo</p><h1>Productos</h1><p>Consulta los productos disponibles en el catálogo.</p></div><button type='button' className='admin-products-new' onClick={openCreate}>+ Nuevo producto</button></div>
    {feedback && <p className='admin-products-feedback' role='status'>{feedback}</p>}
    {loading && <div className='admin-products-state' role='status'>Cargando productos...</div>}
    {!loading && error && <div className='admin-products-state admin-products-state--error' role='alert'><p>{error}</p><button type='button' onClick={loadProducts}>Reintentar</button></div>}
    {!loading && !error && products.length === 0 && <div className='admin-products-state'><p>No hay productos disponibles para mostrar.</p></div>}
    {!loading && !error && products.length > 0 && <>
      <div className='admin-products-toolbar'>
        <label className='admin-products-search'><span>Buscar producto</span><input type='search' value={search} onChange={resetPage(setSearch)} placeholder='Buscar producto...' /></label>
        <label className='admin-products-filter'><span>Categoría</span><select value={category} onChange={resetPage(setCategory)}><option value=''>Todas las categorías</option>{categories.map((item) => <option key={item} value={item}>{item}</option>)}</select></label>
        <label className='admin-products-filter'><span>Estado</span><select value={status} onChange={resetPage(setStatus)}><option value='all'>Todos</option><option value='active'>Activos</option><option value='inactive'>Inactivos</option></select></label>
      </div>
      {filteredProducts.length === 0 ? <div className='admin-products-state'><p>No se encontraron productos con los filtros actuales.</p></div> : <>
        <ProductTable products={paginatedProducts} onEdit={openEdit} onToggleStatus={setStatusProduct} statusChangingId={statusChangingId} />
        <div className='admin-products-pagination'><p>Mostrando {firstProduct}–{lastProduct} de {filteredProducts.length} productos</p><nav aria-label='Paginación de productos'><button type='button' onClick={() => setCurrentPage((page) => Math.max(1, page - 1))} disabled={activePage === 1}>Anterior</button>{pageNumbers.map((page, index) => page === 'ellipsis' ? <span key={`ellipsis-${index}`} className='admin-pagination-ellipsis'>…</span> : <button key={page} type='button' className={page === activePage ? 'is-current' : ''} onClick={() => setCurrentPage(page)} aria-current={page === activePage ? 'page' : undefined}>{page}</button>)}<button type='button' onClick={() => setCurrentPage((page) => Math.min(totalPages, page + 1))} disabled={activePage === totalPages}>Siguiente</button></nav></div>
      </>}
    </>}
    {drawerMode && <div className='admin-product-drawer-layer'><div className='admin-product-drawer-backdrop' /><ProductForm mode={drawerMode} product={drawerProduct} products={products} saving={savingProduct} error={formError} errors={formErrors} onCancel={closeDrawer} onSubmit={saveProduct} /></div>}
    {statusProduct && <div className='admin-product-confirm-layer' role='dialog' aria-modal='true' aria-labelledby='status-confirm-title'><div className='admin-product-confirm'><h2 id='status-confirm-title'>{statusProduct.active ? `¿Desactivar “${statusProduct.name}”?` : `¿Activar “${statusProduct.name}”?`}</h2><p>{statusProduct.active ? 'El producto dejará de aparecer en la tienda, pero no será eliminado.' : 'El producto volverá a aparecer en la tienda.'}</p><div><button type='button' onClick={() => !statusChangingId && setStatusProduct(null)} disabled={Boolean(statusChangingId)}>Cancelar</button><button type='button' className={statusProduct.active ? 'is-danger' : 'is-primary'} onClick={changeStatus} disabled={Boolean(statusChangingId)}>{statusChangingId ? 'Guardando...' : statusProduct.active ? 'Desactivar' : 'Activar'}</button></div></div></div>}
  </div></AdminLayout>;
}

function getPageNumbers(currentPage, totalPages) {
  if (totalPages <= 5) return Array.from({ length: totalPages }, (_, index) => index + 1);
  const middleStart = Math.max(2, Math.min(currentPage - 1, totalPages - 3));
  const middlePages = [middleStart, middleStart + 1, middleStart + 2];
  return [1, ...(middleStart > 2 ? ['ellipsis'] : []), ...middlePages, ...(middlePages[2] < totalPages - 1 ? ['ellipsis'] : []), totalPages];
}
