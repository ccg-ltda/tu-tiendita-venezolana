import { useEffect, useMemo, useState } from 'react';
import { ProductForm } from '../../../components/admin/products/ProductForm';
import { ProductTable } from '../../../components/admin/products/ProductTable';
import { useAdminData } from '../../../context/AdminDataContext';
import { api } from '../../../services/api';

const PRODUCTS_PER_PAGE = 25;

export function AdminProductsPage() {
  const { products, productsError, productsInitialLoading, ensureProducts, refreshProducts, applyAdminProduct, handleUnauthorized, productView, setProductView } = useAdminData();
  const [feedback, setFeedback] = useState('');
  const [drawerMode, setDrawerMode] = useState(null);
  const [drawerProduct, setDrawerProduct] = useState(null);
  const [savingProduct, setSavingProduct] = useState(false);
  const [formError, setFormError] = useState('');
  const [formErrors, setFormErrors] = useState({});
  const [statusProduct, setStatusProduct] = useState(null);
  const [statusChangingId, setStatusChangingId] = useState(null);

  useEffect(() => { ensureProducts(); }, [ensureProducts]);
  useEffect(() => {
    document.body.classList.add('admin-products-route');
    return () => document.body.classList.remove('admin-products-route');
  }, []);
  useEffect(() => {
    if (!feedback) return undefined;
    const timeoutId = setTimeout(() => setFeedback(''), 5000);
    return () => clearTimeout(timeoutId);
  }, [feedback]);

  const catalog = products || [];
  const { search, category, status, currentPage } = productView;
  const categories = useMemo(() => [...new Set(catalog.map((product) => product.category).filter(Boolean))].sort((first, second) => first.localeCompare(second, 'es')), [catalog]);
  const filteredProducts = useMemo(() => catalog
    .filter((product) => product.name.toLocaleLowerCase().includes(search.trim().toLocaleLowerCase()))
    .filter((product) => !category || product.category === category)
    .filter((product) => status === 'all' || (status === 'active' ? product.active : !product.active)), [catalog, search, category, status]);
  const totalPages = Math.max(1, Math.ceil(filteredProducts.length / PRODUCTS_PER_PAGE));
  const activePage = Math.min(currentPage, totalPages);
  const paginatedProducts = filteredProducts.slice((activePage - 1) * PRODUCTS_PER_PAGE, activePage * PRODUCTS_PER_PAGE);
  const firstProduct = filteredProducts.length ? ((activePage - 1) * PRODUCTS_PER_PAGE) + 1 : 0;
  const lastProduct = Math.min(activePage * PRODUCTS_PER_PAGE, filteredProducts.length);
  const pageNumbers = getPageNumbers(activePage, totalPages);
  const resetPage = (field) => (event) => setProductView((view) => ({ ...view, [field]: event.target.value, currentPage: 1 }));
  const closeDrawer = () => { if (!savingProduct) { setDrawerMode(null); setDrawerProduct(null); setFormError(''); setFormErrors({}); } };
  const openCreate = () => { setFeedback(''); setDrawerProduct(null); setDrawerMode('create'); setFormError(''); setFormErrors({}); };
  const openEdit = (product) => { setFeedback(''); setDrawerProduct(product); setDrawerMode('edit'); setFormError(''); setFormErrors({}); };

  const saveProduct = async (values) => {
    setSavingProduct(true);
    setFormError('');
    setFormErrors({});
    try {
      const result = drawerMode === 'create' ? await api.createAdminProduct(values) : await api.updateAdminProduct(drawerProduct.id, values);
      if (!result || typeof result !== 'object' || !result.product || typeof result.product !== 'object') throw new Error('La respuesta del servidor no contiene el producto actualizado.');
      applyAdminProduct(result.product);
      setFeedback(result.sync_status === 'pending' || result.catalog_refreshed === false ? 'Producto guardado; sincronizacion con Google pendiente.' : drawerMode === 'create' ? 'Producto creado correctamente.' : 'Producto actualizado correctamente.');
      setDrawerMode(null);
      setDrawerProduct(null);
    } catch (requestError) {
      if (!handleUnauthorized(requestError)) {
        if (requestError.status === 409) {
          await refreshProducts({ force: true });
        }
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
      const result = await api.updateAdminProductStatus(statusProduct.id, !statusProduct.active, statusProduct.revision);
      if (!result || typeof result !== 'object' || !result.product || typeof result.product !== 'object') throw new Error('La respuesta del servidor no contiene el producto actualizado.');
      applyAdminProduct(result.product);
      setFeedback(result.sync_status === 'pending' || result.catalog_refreshed === false ? 'Producto guardado; sincronizacion con Google pendiente.' : statusProduct.active ? 'Producto desactivado.' : 'Producto activado.');
      setStatusProduct(null);
    } catch (requestError) {
      if (!handleUnauthorized(requestError)) {
        if (requestError.status === 409) {
          await refreshProducts({ force: true });
        }
        setFormError(requestError.message);
      }
    } finally {
      setStatusChangingId(null);
    }
  };

  const initialLoading = products === null && productsInitialLoading;

  return <div className='admin-content admin-products-page'>
    <div className='admin-page-heading'><div><p className='admin-eyebrow'>Catalogo administrativo</p><h1>Productos</h1><p>Consulta los productos disponibles en el catalogo.</p></div><button type='button' className='admin-products-new' onClick={openCreate}>+ Nuevo producto</button></div>
    {feedback && <p className='admin-products-feedback' role='status'>{feedback}</p>}
    {initialLoading && <div className='admin-products-state' role='status'>Cargando productos...</div>}
    {!initialLoading && productsError && <div className='admin-products-state admin-products-state--error' role='alert'><p>{productsError}</p><button type='button' onClick={() => refreshProducts({ force: true })}>Reintentar</button></div>}
    {!initialLoading && !productsError && catalog.length === 0 && <div className='admin-products-state'><p>No hay productos disponibles para mostrar.</p></div>}
    {!initialLoading && !productsError && catalog.length > 0 && <>
      <div className='admin-products-toolbar'>
        <label className='admin-products-search'><span>Buscar producto</span><input type='search' value={search} onChange={resetPage('search')} placeholder='Buscar producto...' /></label>
        <label className='admin-products-filter'><span>Categoria</span><select value={category} onChange={resetPage('category')}><option value=''>Todas las categorias</option>{categories.map((item) => <option key={item} value={item}>{item}</option>)}</select></label>
        <label className='admin-products-filter'><span>Estado</span><select value={status} onChange={resetPage('status')}><option value='all'>Todos</option><option value='active'>Activos</option><option value='inactive'>Inactivos</option></select></label>
      </div>
      {filteredProducts.length === 0 ? <div className='admin-products-state'><p>No se encontraron productos con los filtros actuales.</p></div> : <>
        <ProductTable products={paginatedProducts} onEdit={openEdit} onToggleStatus={setStatusProduct} statusChangingId={statusChangingId} />
        <div className='admin-products-pagination'><p>Mostrando {firstProduct}-{lastProduct} de {filteredProducts.length} productos</p><nav aria-label='Paginacion de productos'><button type='button' onClick={() => setProductView((view) => ({ ...view, currentPage: Math.max(1, view.currentPage - 1) }))} disabled={activePage === 1}>Anterior</button>{pageNumbers.map((page, index) => page === 'ellipsis' ? <span key={`ellipsis-${index}`} className='admin-pagination-ellipsis'>...</span> : <button key={page} type='button' className={page === activePage ? 'is-current' : ''} onClick={() => setProductView((view) => ({ ...view, currentPage: page }))} aria-current={page === activePage ? 'page' : undefined}>{page}</button>)}<button type='button' onClick={() => setProductView((view) => ({ ...view, currentPage: Math.min(totalPages, view.currentPage + 1) }))} disabled={activePage === totalPages}>Siguiente</button></nav></div>
      </>}
    </>}
    {drawerMode && <div className='admin-product-drawer-layer'><div className='admin-product-drawer-backdrop' /><ProductForm mode={drawerMode} product={drawerProduct} products={catalog} saving={savingProduct} error={formError} errors={formErrors} onCancel={closeDrawer} onSubmit={saveProduct} /></div>}
    {statusProduct && <div className='admin-product-confirm-layer' role='dialog' aria-modal='true' aria-labelledby='status-confirm-title'><div className='admin-product-confirm'><h2 id='status-confirm-title'>{statusProduct.active ? `Desactivar "${statusProduct.name}"?` : `Activar "${statusProduct.name}"?`}</h2><p>{statusProduct.active ? 'El producto dejara de aparecer en la tienda, pero no sera eliminado.' : 'El producto volvera a aparecer en la tienda.'}</p><div><button type='button' onClick={() => !statusChangingId && setStatusProduct(null)} disabled={Boolean(statusChangingId)}>Cancelar</button><button type='button' className={statusProduct.active ? 'is-danger' : 'is-primary'} onClick={changeStatus} disabled={Boolean(statusChangingId)}>{statusChangingId ? 'Guardando...' : statusProduct.active ? 'Desactivar' : 'Activar'}</button></div></div></div>}
  </div>;
}

function getPageNumbers(currentPage, totalPages) {
  if (totalPages <= 5) return Array.from({ length: totalPages }, (_, index) => index + 1);
  const middleStart = Math.max(2, Math.min(currentPage - 1, totalPages - 3));
  const middlePages = [middleStart, middleStart + 1, middleStart + 2];
  return [1, ...(middleStart > 2 ? ['ellipsis'] : []), ...middlePages, ...(middlePages[2] < totalPages - 1 ? ['ellipsis'] : []), totalPages];
}
