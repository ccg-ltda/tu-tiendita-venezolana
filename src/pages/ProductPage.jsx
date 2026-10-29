import { useState } from 'react';
import { useParams, Link } from 'react-router-dom';
import { Package } from 'lucide-react';
import { policies } from '../data/policies';
import { formatPrice } from '../utils/catalog';
import { productImageSrc } from '../utils/productImage';
import { Awning } from '../components/layout/Awning';
import { Header } from '../components/layout/Header';
import { TrustStrip } from '../components/layout/TrustStrip';
import { Footer } from '../components/layout/Footer';
import { CartDrawer } from '../components/cart/CartDrawer';
import { Toast } from '../components/cart/Toast';
import { PolicyModal } from '../components/common/PolicyModal';
import { SeoHead } from '../components/common/SeoHead';
import { CheckoutModal } from '../components/checkout/CheckoutModal';
import { NotFoundPage } from './NotFoundPage';
import { useCatalog } from '../hooks/useCatalog';
import { useCart } from '../hooks/useCart';
import { buildCategories } from '../utils/catalog';

export function ProductPage() {
  const { slug } = useParams();
  const { catalog, loading, catalogError } = useCatalog();
  const { cart, cartItems, cartCount, total, toastProduct, setQuantity, addProduct, setToastProduct, clearCart } = useCart(catalog);
  const [drawerOpen, setDrawerOpen] = useState(false);
  const [checkoutOpen, setCheckoutOpen] = useState(false);
  const [activePolicy, setActivePolicy] = useState(null);

  const product = catalog.find((item) => item.slug === slug && item.active !== false);

  if (!loading && !catalogError && catalog.length && !product) {
    return <NotFoundPage />;
  }

  const categories = buildCategories(catalog, { includePromotions: false });
  const outOfStock = product && Number(product.inventory) <= 0;
  const quantity = product ? cart[product.id] || 0 : 0;
  const imageSrc = product ? productImageSrc(product) : '';
  const promotion = product?.promotion?.active ? product.promotion : null;
  const pageTitle = product ? `${product.name} — $${Math.round(product.price).toLocaleString('es-CO')} | Tu Tiendita Venezolana` : 'Producto | Tu Tiendita Venezolana';
  const pageDescription = product ? `${product.name} — Compra en Colombia. ${product.pres}. Envíos desde Bogotá para toda Colombia.` : '';
  const canonical = `https://tutienditavenezolana.com/producto/${slug}`;
  const ogImage = product && imageSrc ? (imageSrc.startsWith('http') ? imageSrc : `https://tutienditavenezolana.com${imageSrc}`) : 'https://tutienditavenezolana.com/assets/banner.png';
  const jsonLd = product ? {
    '@context': 'https://schema.org',
    '@type': 'Product',
    name: product.name,
    image: ogImage,
    description: pageDescription,
    offers: {
      '@type': 'Offer',
      url: canonical,
      priceCurrency: 'COP',
      price: String(product.price),
      availability: outOfStock ? 'https://schema.org/OutOfStock' : 'https://schema.org/InStock',
      shippingDetails: {
        '@type': 'OfferShippingDetails',
        shippingDestination: { '@type': 'DefinedRegion', addressCountry: 'CO' },
      },
    },
  } : null;

  return (
    <div>
      {product && <SeoHead
        title={pageTitle}
        description={pageDescription}
        canonical={canonical}
        robots='index,follow'
        openGraph={{ title: `${product.name} — Compra en Colombia`, description: pageDescription, image: ogImage, url: canonical, type: 'product' }}
        twitter={{ card: 'summary_large_image', title: `${product.name} — Compra en Colombia`, description: pageDescription, image: ogImage }}
        jsonLd={jsonLd}
      />}
      <Awning />
      <Header cartCount={cartCount} onCartOpen={() => setDrawerOpen(true)} search='' onSearch={() => {}} homeHref='/' showSearch={false} />
      {loading && <div className='catalog-status'>Cargando producto…</div>}
      {!loading && catalogError && <div className='catalog-status error'>{catalogError}</div>}
      {product && (
        <main className='main product-detail'>
          <article className='product-detail-card'>
            <h1>{product.name}</h1>
            <nav aria-label='Categoría'><Link to={`/categoria/${product.catSlug}`}>{product.cat}</Link> · {product.sub}</nav>
            <div className='card-img product-detail-img'>{imageSrc ? <img src={imageSrc} alt={product.name} width='600' height='600' /> : <span className='product-placeholder'><Package size={48} aria-hidden='true' /></span>}{outOfStock && <div className='stock-badge'>Agotado</div>}</div>
            <div className='card-pres'>{product.pres}</div>
            {promotion ? <div className='card-price promotion-price'><del>{formatPrice(promotion.originalPrice)}</del><strong>{formatPrice(product.price)}</strong></div> : <div className='card-price'>{formatPrice(product.price)}</div>}
            <div className='card-stock'>{outOfStock ? 'Sin unidades disponibles' : 'Disponible'}</div>
            <div className='card-actions'>
              <div className='qty-stepper'><button disabled={!quantity} aria-label={`Restar ${product.name}`} onClick={() => setQuantity(product.id, Math.max(0, quantity - 1))}>−</button><span>{quantity}</span><button disabled={outOfStock || quantity >= product.inventory} aria-label={`Sumar ${product.name}`} onClick={() => setQuantity(product.id, quantity + 1)}>+</button></div>
              <button disabled={outOfStock} className={`add-btn${quantity ? ' added' : ''}`} onClick={() => addProduct(product)}>{outOfStock ? 'Agotado' : quantity ? 'Agregado ✓' : 'Agregar al carrito'}</button>
            </div>
          </article>
        </main>
      )}
      <TrustStrip />
      <Footer categories={categories} onCategory={() => {}} onPolicy={(key) => setActivePolicy(policies[key])} />
      <CartDrawer open={drawerOpen} items={cartItems} total={total} onClose={() => setDrawerOpen(false)} onRemove={(id) => setQuantity(id, 0)} onCheckout={() => { setDrawerOpen(false); setCheckoutOpen(true); }} />
      <CheckoutModal open={checkoutOpen} items={cartItems} total={total} onClose={() => setCheckoutOpen(false)} onPaymentConfirmed={clearCart} />
      <Toast product={toastProduct} onClose={() => setToastProduct(null)} onOpen={() => setDrawerOpen(true)} />
      <PolicyModal policy={activePolicy} onClose={() => setActivePolicy(null)} />
    </div>
  );
}
