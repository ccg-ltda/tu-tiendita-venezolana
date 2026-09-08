import { useCallback, useEffect, useMemo, useState } from 'react';
import { useNavigate } from 'react-router-dom';
import { ProductTable } from '../../../components/admin/products/ProductTable';
import { AdminLayout } from '../AdminPage';
import { api } from '../../../services/api';

const PRODUCTS_PER_PAGE = 25;

export function AdminProductsPage() {
  const navigate = useNavigate();
  const [products, setProducts] = useState([]);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState('');
  const [search, setSearch] = useState('');
  const [category, setCategory] = useState('');
  const [status, setStatus] = useState('all');
  const [currentPage, setCurrentPage] = useState(1);

  const loadProducts = useCallback(async () => {
    setLoading(true);
    setError('');
    try {
      const response = await api.listAdminProducts();
      setProducts(response.products || []);
    } catch (requestError) {
      if (requestError.status === 401 || requestError.status === 403) {
        navigate('/admin/login', { replace: true });
        return;
      }
      setError('No fue posible cargar el catálogo de productos. Inténtalo de nuevo.');
    } finally {
      setLoading(false);
    }
  }, [navigate]);

  useEffect(() => { loadProducts(); }, [loadProducts]);

  useEffect(() => {
    document.body.classList.add('admin-products-route');
    return () => document.body.classList.remove('admin-products-route');
  }, []);

  const categories = useMemo(() => [...new Set(products.map((product) => product.category).filter(Boolean))]
    .sort((first, second) => first.localeCompare(second, 'es')), [products]);
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

  return <AdminLayout><div className='admin-content admin-products-page'>
    <div className='admin-page-heading'><div><p className='admin-eyebrow'>Catálogo administrativo</p><h1>Productos</h1><p>Consulta los productos disponibles en el catálogo.</p></div></div>
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
        <ProductTable products={paginatedProducts} />
        <div className='admin-products-pagination'><p>Mostrando {firstProduct}–{lastProduct} de {filteredProducts.length} productos</p><nav aria-label='Paginación de productos'><button type='button' onClick={() => setCurrentPage((page) => Math.max(1, page - 1))} disabled={activePage === 1}>Anterior</button>{pageNumbers.map((page, index) => page === 'ellipsis' ? <span key={`ellipsis-${index}`} className='admin-pagination-ellipsis'>…</span> : <button key={page} type='button' className={page === activePage ? 'is-current' : ''} onClick={() => setCurrentPage(page)} aria-current={page === activePage ? 'page' : undefined}>{page}</button>)}<button type='button' onClick={() => setCurrentPage((page) => Math.min(totalPages, page + 1))} disabled={activePage === totalPages}>Siguiente</button></nav></div>
      </>}
    </>}
  </div></AdminLayout>;
}

function getPageNumbers(currentPage, totalPages) {
  if (totalPages <= 5) return Array.from({ length: totalPages }, (_, index) => index + 1);
  const middleStart = Math.max(2, Math.min(currentPage - 1, totalPages - 3));
  const middlePages = [middleStart, middleStart + 1, middleStart + 2];
  return [1, ...(middleStart > 2 ? ['ellipsis'] : []), ...middlePages, ...(middlePages[2] < totalPages - 1 ? ['ellipsis'] : []), totalPages];
}
