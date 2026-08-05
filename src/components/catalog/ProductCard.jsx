import { formatPrice } from '../../utils/catalog';

export function ProductCard({ product, quantity, onQuantity, onAdd }) {
  return (
    <article className="card">
      <div className="card-img">{product.image ? <img src={product.image} alt={product.name} loading="lazy" /> : <span className="product-placeholder">🛒</span>}<div className="card-sub-badge">{product.sub}</div></div>
      <div className="card-body"><h3>{product.name}</h3><div className="card-pres">{product.pres}</div><div className="card-price">{formatPrice(product.price)}</div><div className="card-actions"><div className="qty-stepper"><button aria-label={`Restar ${product.name}`} onClick={() => onQuantity(product.id, Math.max(0, quantity - 1))}>−</button><span>{quantity}</span><button aria-label={`Sumar ${product.name}`} onClick={() => onQuantity(product.id, quantity + 1)}>+</button></div><button className={`add-btn${quantity ? ' added' : ''}`} onClick={() => onAdd(product)}>{quantity ? 'Agregado ✓' : 'Agregar'}</button></div></div>
    </article>
  );
}
