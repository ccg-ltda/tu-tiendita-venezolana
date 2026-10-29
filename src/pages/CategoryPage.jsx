import { useState } from 'react';
import { useParams } from 'react-router-dom';
import branding from '../data/branding.json';
import { policies } from '../data/policies';
import { buildCategories, unique, normalizeText } from '../utils/catalog';
import { Awning } from '../components/layout/Awning';
import { Header } from '../components/layout/Header';
import { CategoryNav } from '../components/layout/CategoryNav';
import { TrustStrip } from '../components/layout/TrustStrip';
import { Footer } from '../components/layout/Footer';
import { Sidebar } from '../components/catalog/Sidebar';
import { ProductGrid } from '../components/catalog/ProductGrid';
import { CartDrawer } from '../components/cart/CartDrawer';
import { Toast } from '../components/cart/Toast';
import { PolicyModal } from '../components/common/PolicyModal';
import { SeoHead } from '../components/common/SeoHead';
import { CheckoutModal } from '../components/checkout/CheckoutModal';
import { NotFoundPage } from './NotFoundPage';
import { useCatalog } from '../hooks/useCatalog';
import { useCart } from '../hooks/useCart';

export function CategoryPage() {
  const { slug } = useParams();
  const { catalog, loading, catalogError, loadProducts, setCatalogError } = useCatalog();
  const { cart, cartItems, cartCount, total, toastProduct, setQuantity, addProduct, setToastProduct, clearCart } = useCart(catalog);
  const [activeSub, setActiveSub] = useState('Todas');
  const [search, setSearch] = useState('');
  const [drawerOpen, setDrawerOpen] = useState(false);
  const [checkoutOpen, setCheckoutOpen] = useState(false);
  const [activePolicy, setActivePolicy] = useState(null);

  const categories = buildCategories(catalog, { includePromotions: false });
  const availableCatalog = catalog.filter((product) => product.active !== false);
  const categoryProducts = availableCatalog.filter((product) => product.catSlug === slug);
  const categoryName = categoryProducts[0]?.cat || '';

  if (!loading && !catalogError && catalog.length && !categoryProducts.length) {
    return <NotFoundPage />;
  }

  const subcategories = unique(categoryProducts.map((product) => product.sub));
  const subCounts = Object.fromEntries(subcategories.map((sub) => [sub, categoryProducts.filter((product) => product.sub === sub).length]));
  subCounts.all = categoryProducts.length;
  const visibleProducts = search.trim()
    ? categoryProducts.filter((product) => normalizeText(`${product.name} ${product.sub} ${product.pres}`).includes(normalizeText(search.trim())))
    : categoryProducts.filter((product) => activeSub === 'Todas' || product.sub === activeSub);
  const title = search.trim() ? `Resultados para “${search.trim()}”` : `${categoryName}${activeSub === 'Todas' ? '' : ` · ${activeSub}`}`;
  const pageTitle = `${categoryName} venezolanos en Colombia | Tu Tiendita Venezolana`;
  const pageDescription = `${categoryName} venezolanos — Compra online con envío desde Bogotá para toda Colombia.`;
  const canonical = `https://tutienditavenezolana.com/categoria/${slug}`;

  return (
    <div>
      {categoryName && <SeoHead
        title={pageTitle}
        description={pageDescription}
        canonical={canonical}
        robots='index,follow'
        openGraph={{ title: pageTitle, description: pageDescription, image: 'https://tutienditavenezolana.com/assets/banner.png', url: canonical, type: 'website' }}
        twitter={{ card: 'summary_large_image', title: pageTitle, description: pageDescription, image: 'https://tutienditavenezolana.com/assets/banner.png' }}
      />}
      {categoryName && <h1 className='sr-only'>{categoryName} venezolanos — Compra online con envío desde Bogotá</h1>}
      <Awning />
      <Header cartCount={cartCount} onCartOpen={() => setDrawerOpen(true)} search={search} onSearch={setSearch} homeHref='/' />
      <div className='banner-wrap'><img src={branding.banner} alt='Tu Tiendita Venezolana' /></div>
      <CategoryNav categories={categories} active={categoryName} onSelect={() => {}} />
      {loading && <div className='catalog-status'>Cargando catálogo…</div>}
      {!loading && catalogError && <div className='catalog-status error'>{catalogError}<button onClick={() => loadProducts(false).catch((error) => setCatalogError(error.message))}>Reintentar</button></div>}
      {!loading && !catalogError && <main className='main' id='catalogo'><Sidebar category={categoryName} subcategories={subcategories} activeSub={activeSub} counts={subCounts} onSelect={(sub) => { setActiveSub(sub); setSearch(''); }} /><ProductGrid title={title} products={visibleProducts} cart={cart} onQuantity={setQuantity} onAdd={addProduct} /></main>}
      <TrustStrip />
      <Footer categories={categories} onCategory={() => {}} onPolicy={(key) => setActivePolicy(policies[key])} />
      <CartDrawer open={drawerOpen} items={cartItems} total={total} onClose={() => setDrawerOpen(false)} onRemove={(id) => setQuantity(id, 0)} onCheckout={() => { setDrawerOpen(false); setCheckoutOpen(true); }} />
      <CheckoutModal open={checkoutOpen} items={cartItems} total={total} onClose={() => setCheckoutOpen(false)} onPaymentConfirmed={clearCart} />
      <Toast product={toastProduct} onClose={() => setToastProduct(null)} onOpen={() => setDrawerOpen(true)} />
      <PolicyModal policy={activePolicy} onClose={() => setActivePolicy(null)} />
    </div>
  );
}
