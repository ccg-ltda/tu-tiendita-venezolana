import { useEffect, useMemo, useState } from 'react';
import products from './data/products.json';
import branding from './data/branding.json';
import { policies } from './data/policies';
import { unique, normalizeText } from './utils/catalog';
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

export default function App() {
  const categories = useMemo(() => unique(products.map((product) => product.cat)), []);
  const [activeCat, setActiveCat] = useState(categories[0]);
  const [activeSub, setActiveSub] = useState('Todas');
  const [search, setSearch] = useState('');
  const [cart, setCart] = useState(() => JSON.parse(localStorage.getItem('ttv-cart') || '{}'));
  const [drawerOpen, setDrawerOpen] = useState(false);
  const [checkoutOpen, setCheckoutOpen] = useState(false);
  const [toastProduct, setToastProduct] = useState(null);
  const [activePolicy, setActivePolicy] = useState(null);

  useEffect(() => localStorage.setItem('ttv-cart', JSON.stringify(cart)), [cart]);
  useEffect(() => { if (!toastProduct) return undefined; const timer = setTimeout(() => setToastProduct(null), 2600); return () => clearTimeout(timer); }, [toastProduct]);

  const categoryProducts = products.filter((product) => product.cat === activeCat);
  const subcategories = unique(categoryProducts.map((product) => product.sub));
  const subCounts = Object.fromEntries(subcategories.map((sub) => [sub, categoryProducts.filter((product) => product.sub === sub).length]));
  subCounts.all = categoryProducts.length;
  const visibleProducts = search.trim() ? products.filter((product) => normalizeText(`${product.name} ${product.cat} ${product.sub} ${product.pres}`).includes(normalizeText(search.trim()))) : categoryProducts.filter((product) => activeSub === 'Todas' || product.sub === activeSub);
  const title = search.trim() ? `Resultados para “${search.trim()}”` : `${activeCat}${activeSub === 'Todas' ? '' : ` · ${activeSub}`}`;
  const cartItems = Object.entries(cart).map(([id, qty]) => ({ ...products.find((product) => product.id === Number(id)), qty })).filter((item) => item.id && item.qty > 0);
  const cartCount = cartItems.reduce((sum, item) => sum + item.qty, 0);
  const total = cartItems.reduce((sum, item) => sum + item.price * item.qty, 0);
  const selectCategory = (category) => { setActiveCat(category); setActiveSub('Todas'); setSearch(''); };
  const setQuantity = (id, quantity) => setCart((current) => { const next = { ...current }; if (quantity > 0) next[id] = quantity; else delete next[id]; return next; });
  const addProduct = (product) => { if (!cart[product.id]) setQuantity(product.id, 1); setToastProduct(product); };

  return (
    <div id="inicio">
      <Awning />
      <Header cartCount={cartCount} onCartOpen={() => setDrawerOpen(true)} search={search} onSearch={setSearch} />
      <div className="banner-wrap"><img src={branding.banner} alt="Tu Tiendita Venezolana" /></div>
      <CategoryNav categories={categories} active={activeCat} onSelect={selectCategory} />
      <main className="main" id="catalogo"><Sidebar category={activeCat} subcategories={subcategories} activeSub={activeSub} counts={subCounts} onSelect={(sub) => { setActiveSub(sub); setSearch(''); }} /><ProductGrid title={title} products={visibleProducts} cart={cart} onQuantity={setQuantity} onAdd={addProduct} /></main>
      <TrustStrip />
      <Footer categories={categories} onCategory={selectCategory} onPolicy={(key) => setActivePolicy(policies[key])} />
      <a className="fab mayorista" href="https://wa.link/9pyro2" target="_blank" rel="noreferrer"><span className="ic">🏢</span><span>Mayoristas<small>Habla con ventas</small></span></a>
      <CartDrawer open={drawerOpen} items={cartItems} total={total} onClose={() => setDrawerOpen(false)} onRemove={(id) => setQuantity(id, 0)} onCheckout={() => { setDrawerOpen(false); setCheckoutOpen(true); }} />
      <CheckoutModal open={checkoutOpen} items={cartItems} total={total} onClose={() => setCheckoutOpen(false)} onComplete={(order) => console.info('Pedido de demostración', order)} />
      <Toast product={toastProduct} onClose={() => setToastProduct(null)} onOpen={() => setDrawerOpen(true)} />
      <PolicyModal policy={activePolicy} onClose={() => setActivePolicy(null)} />
    </div>
  );
}
