import { useEffect, useMemo, useState } from 'react';
import branding from './data/branding.json';
import { policies } from './data/policies';
import { buildCategories, unique, normalizeText } from './utils/catalog';
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
import { SeoHead } from './components/common/SeoHead';
import { CheckoutModal } from './components/checkout/CheckoutModal';
import { useCatalog } from './hooks/useCatalog';
import { useCart } from './hooks/useCart';
import { Building2 } from 'lucide-react';

export default function App() {
  const { catalog, loading, catalogError, loadProducts, setCatalogError } = useCatalog();
  const categories = useMemo(() => buildCategories(catalog), [catalog]);
  const [activeCat, setActiveCat] = useState('');
  const [activeSub, setActiveSub] = useState('Todas');
  const [search, setSearch] = useState('');
  const [drawerOpen, setDrawerOpen] = useState(false);
  const [checkoutOpen, setCheckoutOpen] = useState(false);
  const [activePolicy, setActivePolicy] = useState(null);
  const { cart, cartItems, cartCount, total, toastProduct, setQuantity, addProduct, setToastProduct, clearCart } = useCart(catalog);

  useEffect(() => {
    if (categories.length && !categories.some((category) => category.name === activeCat)) setActiveCat(categories[0].name);
  }, [categories, activeCat]);

  const availableCatalog = catalog.filter((product) => product.active !== false);
  const isPromotionsCategory = activeCat === 'Promociones';
  const categoryProducts = isPromotionsCategory ? availableCatalog.filter((product) => product.promotion?.active === true) : availableCatalog.filter((product) => product.cat === activeCat);
  const subcategories = isPromotionsCategory ? [] : unique(categoryProducts.map((product) => product.sub));
  const subCounts = Object.fromEntries(subcategories.map((sub) => [sub, categoryProducts.filter((product) => product.sub === sub).length]));
  subCounts.all = categoryProducts.length;
  const visibleProducts = search.trim()
    ? availableCatalog.filter((product) => normalizeText(`${product.name} ${product.cat} ${product.sub} ${product.pres}`).includes(normalizeText(search.trim())))
    : categoryProducts.filter((product) => activeSub === 'Todas' || product.sub === activeSub);
  const title = search.trim() ? `Resultados para “${search.trim()}”` : `${activeCat}${activeSub === 'Todas' ? '' : ` · ${activeSub}`}`;
  const selectCategory = (category) => { setActiveCat(category); setActiveSub('Todas'); setSearch(''); };

  const handlePaymentConfirmed = () => {
    clearCart();
  };

  const jsonLd = {
    '@context': 'https://schema.org',
    '@type': 'OnlineStore',
    name: 'Tu Tiendita Venezolana',
    url: 'https://tutienditavenezolana.com',
    logo: 'https://tutienditavenezolana.com/assets/logo.png',
    address: { '@type': 'PostalAddress', addressLocality: 'Bogotá', addressCountry: 'CO' },
    areaServed: 'CO',
    // TODO: agregar sameAs con las URLs reales de Instagram/Facebook cuando se definan.
  };

  return (
    <div id='inicio'>
      <SeoHead
        title='Productos venezolanos en Colombia | Tu Tiendita Venezolana'
        description='Productos venezolanos en Colombia. Envíos desde Bogotá para toda Colombia.'
        canonical='https://tutienditavenezolana.com/'
        robots='index,follow'
        openGraph={{ title: 'Productos venezolanos en Colombia | Tu Tiendita Venezolana', description: 'Productos venezolanos en Colombia. Envíos desde Bogotá para toda Colombia.', image: 'https://tutienditavenezolana.com/assets/banner.png', url: 'https://tutienditavenezolana.com/', type: 'website' }}
        twitter={{ card: 'summary_large_image', title: 'Productos venezolanos en Colombia | Tu Tiendita Venezolana', description: 'Productos venezolanos en Colombia. Envíos desde Bogotá para toda Colombia.', image: 'https://tutienditavenezolana.com/assets/banner.png' }}
        jsonLd={jsonLd}
      />
      <h1 className='sr-only'>Tu Tiendita Venezolana — Productos venezolanos en Colombia</h1>
      <Awning />
      <Header
        cartCount={cartCount}
        onCartOpen={() => setDrawerOpen(true)}
        search={search}
        onSearch={setSearch}
      />
      <div className='banner-wrap'><img src={branding.banner} alt='Tu Tiendita Venezolana' width='1200' height='400' /></div>
      <CategoryNav categories={categories} active={activeCat} onSelect={selectCategory} />
      {loading && <div className='catalog-status'>Cargando catálogo…</div>}
      {!loading && catalogError && <div className='catalog-status error'>{catalogError}<button onClick={() => loadProducts(false).catch((error) => setCatalogError(error.message))}>Reintentar</button></div>}
      {!loading && !catalogError && <main className='main' id='catalogo'><Sidebar category={activeCat} subcategories={subcategories} activeSub={activeSub} counts={subCounts} onSelect={(sub) => { setActiveSub(sub); setSearch(''); }} /><ProductGrid title={title} products={visibleProducts} cart={cart} onQuantity={setQuantity} onAdd={addProduct} /></main>}
      <TrustStrip />
      <Footer categories={categories} onCategory={selectCategory} onPolicy={(key) => setActivePolicy(policies[key])} />
      <a className='fab mayorista' href='https://wa.link/9pyro2' target='_blank' rel='noreferrer'><span className='ic'><Building2 size={16} aria-hidden='true' /></span><span>Mayoristas<small>Habla con ventas</small></span></a>
      <CartDrawer open={drawerOpen} items={cartItems} total={total} onClose={() => setDrawerOpen(false)} onRemove={(id) => setQuantity(id, 0)} onCheckout={() => { setDrawerOpen(false); setCheckoutOpen(true); }} />
      <CheckoutModal open={checkoutOpen} items={cartItems} total={total} onClose={() => setCheckoutOpen(false)} onPaymentConfirmed={handlePaymentConfirmed} />
      <Toast product={toastProduct} onClose={() => setToastProduct(null)} onOpen={() => setDrawerOpen(true)} />
      <PolicyModal policy={activePolicy} onClose={() => setActivePolicy(null)} />
    </div>
  );
}
