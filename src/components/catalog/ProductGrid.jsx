import { ProductCard } from './ProductCard';

export function ProductGrid({ title, products, cart, onQuantity, onAdd }) {
  return <section className="products-section"><div className="section-head"><h2>{title}</h2><span className="section-count">{products.length} producto{products.length === 1 ? '' : 's'}</span></div><div className="product-grid">{products.length ? products.map((product) => <ProductCard key={product.id} product={product} quantity={cart[product.id] || 0} onQuantity={onQuantity} onAdd={onAdd} />) : <div className="empty-state"><strong>Sin resultados</strong>Prueba con otro término o categoría.</div>}</div></section>;
}
