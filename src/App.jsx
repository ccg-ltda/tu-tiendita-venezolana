import { useEffect, useMemo, useState } from 'react';
import branding from './data/branding.json';
import { policies } from './data/policies';
import { unique, normalizeText } from './utils/catalog';
import { api } from './services/api';
import { Awning } from './components/layout/Awning';
import { Header } from './components/layout/Header';
import { CategoryNav } from './components/layout/CategoryNav';
import { TrustStrip } from './components/layout/TrustStrip';
import { Footer } from './components/layout/Footer';
import { Sidebar } from './components/catalog/Sidebar';
import { ProductGrid } from './components/catalog/ProductGrid';
import { CartDrawer } from './components/cart/CartDrawer';
import { Toast } from './components/cart/Toast';
import { PolicyModal } from './components/common/PolicyModal';
import { CheckoutModal } from './components/checkout/CheckoutModal';
import { AdminLoginModal } from './components/admin/AdminLoginModal';
import { AdminPanel } from './components/admin/AdminPanel';

export default function App() {
  const [catalog, setCatalog] = useState([]);
  const categories = useMemo(() => unique(catalog.filter((product) => product.active !== false).map((product) => product.cat)), [catalog]);
  const [activeCat, setActiveCat] = useState('');
  const [activeSub, setActiveSub] = useState('Todas');
  const [search, setSearch] = useState('');
  const [cart, setCart] = useState(() => JSON.parse(localStorage.getItem('ttv-cart') || '{}'));
  const [drawerOpen, setDrawerOpen] = useState(false);
  const [checkoutOpen, setCheckoutOpen] = useState(false);
  const [toastProduct, setToastProduct] = useState(null);
  const [activePolicy, setActivePolicy] = useState(null);
  const [loginOpen, setLoginOpen] = useState(false);
  const [adminOpen, setAdminOpen] = useState(false);
  const [isAdmin, setIsAdmin] = useState(false);
  const [loading, setLoading] = useState(true);
  const [catalogError, setCatalogError] = useState('');

  const loadProducts = async (admin = false) => {
    try {
      const result = admin ? await api.listAdminProducts() : await api.listProducts();
      setCatalog(result.products);
      setCatalogError('');
      return result.products;
    } catch (error) {
      setCatalogError(error.message);
      throw error;
    }
  };

  useEffect(() => {
    const initialize = async () => {
      try {
        const session = await api.session();
        setIsAdmin(Boolean(session.admin));
        await loadProducts(Boolean(session.admin));
      } catch (error) {
        setIsAdmin(false);
        await loadProducts(false).catch(() => {});
      } finally {
        setLoading(false);
      }
    };
    initialize();
  }, []);
  useEffect(() => localStorage.setItem('ttv-cart', JSON.stringify(cart)), [cart]);
  useEffect(() => setCart((current) => {
    let changed = false;
    const next = { ...current };
    Object.entries(next).forEach(([id, quantity]) => {
      const product = catalog.find((item) => item.id === Number(id));
      const allowed = product?.active === false ? 0 : Math.min(quantity, Math.max(0, Number(product?.inventory) || 0));
      if (allowed !== quantity) changed = true;
      if (allowed > 0) next[id] = allowed; else delete next[id];
    });
    return changed ? next : current;
  }), [catalog]);
  useEffect(() => {
    if (!toastProduct) return undefined;
    const timer = setTimeout(() => setToastProduct(null), 2600);
    return () => clearTimeout(timer);
  }, [toastProduct]);
  useEffect(() => {
    if (categories.length && !categories.includes(activeCat)) setActiveCat(categories[0]);
  }, [categories, activeCat]);

  const availableCatalog = catalog.filter((product) => product.active !== false);
  const categoryProducts = availableCatalog.filter((product) => product.cat === activeCat);
  const subcategories = unique(categoryProducts.map((product) => product.sub));
  const subCounts = Object.fromEntries(subcategories.map((sub) => [sub, categoryProducts.filter((product) => product.sub === sub).length]));
  subCounts.all = categoryProducts.length;
  const visibleProducts = search.trim()
    ? availableCatalog.filter((product) => normalizeText(`${product.name} ${product.cat} ${product.sub} ${product.pres}`).includes(normalizeText(search.trim())))
    : categoryProducts.filter((product) => activeSub === 'Todas' || product.sub === activeSub);
  const title = search.trim() ? `Resultados para “${search.trim()}”` : `${activeCat}${activeSub === 'Todas' ? '' : ` · ${activeSub}`}`;
  const cartItems = Object.entries(cart).map(([id, qty]) => ({ ...catalog.find((product) => product.id === Number(id)), qty })).filter((item) => item.id && item.qty > 0 && item.active !== false);
  const cartCount = cartItems.reduce((sum, item) => sum + item.qty, 0);
  const total = cartItems.reduce((sum, item) => sum + item.price * item.qty, 0);
  const selectCategory = (category) => { setActiveCat(category); setActiveSub('Todas'); setSearch(''); };
  const setQuantity = (id, quantity) => setCart((current) => {
    const product = catalog.find((item) => item.id === id);
    const next = { ...current };
    const safeQuantity = Math.min(Math.max(0, quantity), Math.max(0, Number(product?.inventory) || 0));
    if (safeQuantity > 0) next[id] = safeQuantity; else delete next[id];
    return next;
  });
  const addProduct = (product) => { if (!cart[product.id]) setQuantity(product.id, 1); setToastProduct(product); };
  const handleAdminAccess = async () => {
    if (!isAdmin) return setLoginOpen(true);
    try {
      await loadProducts(true);
      setAdminOpen(true);
    } catch (error) {
      setIsAdmin(false);
      setLoginOpen(true);
    }
  };
  const handleLogin = async (credentials) => {
    await api.login(credentials);
    setIsAdmin(true);
    setLoginOpen(false);
    await loadProducts(true);
    setAdminOpen(true);
  };
  const handleLogout = async () => {
    await api.logout().catch(() => {});
    setIsAdmin(false);
    setAdminOpen(false);
    await loadProducts(false);
  };
  const saveProduct = async (product) => {
    const result = await api.updateProduct(product);
    setCatalog((current) => current.map((item) => item.id === result.product.id ? result.product : item));
    return result.product;
  };
  const createProduct = async (product) => {
    const result = await api.createProduct(product);
    setCatalog((current) => [...current, result.product]);
    return result.product;
  };
  const resetCatalog = async () => {
    const result = await api.resetProducts();
    setCatalog(result.products);
  };
  const completeOrder = async ({ customer, items }) => {
    const result = await api.createOrder({ customer, items: items.map((item) => ({ id: item.id, qty: item.qty })) });
    setCart({});
    await loadProducts(false);
    return result.order;
  };

  return (
    <div id='inicio'>
      <Awning />
      <Header cartCount={cartCount} onCartOpen={() => setDrawerOpen(true)} search={search} onSearch={setSearch} isAdmin={isAdmin} onAdmin={handleAdminAccess} />
      <div className='banner-wrap'><img src={branding.banner} alt='Tu Tiendita Venezolana' /></div>
      <CategoryNav categories={categories} active={activeCat} onSelect={selectCategory} />
      {loading && <div className='catalog-status'>Cargando catálogo…</div>}
      {!loading && catalogError && <div className='catalog-status error'>{catalogError}<button onClick={() => loadProducts(false).catch((error) => setCatalogError(error.message))}>Reintentar</button></div>}
      {!loading && !catalogError && <main className='main' id='catalogo'><Sidebar category={activeCat} subcategories={subcategories} activeSub={activeSub} counts={subCounts} onSelect={(sub) => { setActiveSub(sub); setSearch(''); }} /><ProductGrid title={title} products={visibleProducts} cart={cart} onQuantity={setQuantity} onAdd={addProduct} /></main>}
      <TrustStrip />
      <Footer categories={categories} onCategory={selectCategory} onPolicy={(key) => setActivePolicy(policies[key])} />
      <a className='fab mayorista' href='https://wa.link/9pyro2' target='_blank' rel='noreferrer'><span className='ic'>🏢</span><span>Mayoristas<small>Habla con ventas</small></span></a>
      <CartDrawer open={drawerOpen} items={cartItems} total={total} onClose={() => setDrawerOpen(false)} onRemove={(id) => setQuantity(id, 0)} onCheckout={() => { setDrawerOpen(false); setCheckoutOpen(true); }} />
      <CheckoutModal open={checkoutOpen} items={cartItems} total={total} onClose={() => setCheckoutOpen(false)} onComplete={completeOrder} />
      <Toast product={toastProduct} onClose={() => setToastProduct(null)} onOpen={() => setDrawerOpen(true)} />
      <PolicyModal policy={activePolicy} onClose={() => setActivePolicy(null)} />
      <AdminLoginModal open={loginOpen} onClose={() => setLoginOpen(false)} onLogin={handleLogin} />
      <AdminPanel open={adminOpen && isAdmin} products={catalog} onSaveProduct={saveProduct} onAddProduct={createProduct} onClose={() => setAdminOpen(false)} onLogout={handleLogout} onReset={resetCatalog} />
    </div>
  );
}
