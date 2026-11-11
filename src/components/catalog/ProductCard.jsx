import { Link } from 'react-router-dom';
import { formatPrice } from '../../utils/catalog';
import { Package } from 'lucide-react';
import { productImageSrc } from '../../utils/productImage';

// Las fotos del catálogo (/assets/products/NN.webp) tienen versión de 300 px en /assets/products/300/.
// El navegador elige la que necesita según el tamaño de la tarjeta (PageSpeed: imágenes más livianas).
function cardImageSizes(src) {
  const m = /^\/assets\/products\/([^/]+\.webp)$/.exec(src);
  return m ? { srcSet: `/assets/products/300/${m[1]} 300w, ${src} 600w`, sizes: '(max-width: 767px) 46vw, 240px' } : {};
}

export function ProductCard({ product, quantity, onQuantity, onAdd }) {
  const imageSrc = productImageSrc(product);
  const outOfStock = Number(product.inventory) <= 0;
  const promotion = product.promotion?.active ? product.promotion : null;
  const promotionLabel = promotion?.type === 'percent' ? `-${promotion.value}%` : `-${formatPrice(promotion?.discountCop || 0)}`;
  const href = `/producto/${product.slug}`;
  return (
    <article className={`card${outOfStock ? ' out-of-stock' : ''}`}>
      <Link to={href} className='card-img'>{imageSrc ? <img src={imageSrc} {...cardImageSizes(imageSrc)} alt={product.name} loading='lazy' width='600' height='600' /> : <span className='product-placeholder'><Package size={40} aria-hidden='true' /></span>}<div className='card-sub-badge'>{product.sub}</div>{promotion && <div className='promotion-badge'>{promotionLabel}</div>}{outOfStock && <div className='stock-badge'>Agotado</div>}</Link>
      <div className='card-body'><h3><Link to={href}>{product.name}</Link></h3><div className='card-pres'>{product.pres}</div>{promotion ? <div className='card-price promotion-price'><del>{formatPrice(promotion.originalPrice)}</del><strong>{formatPrice(product.price)}</strong></div> : <div className='card-price'>{formatPrice(product.price)}</div>}<div className='card-stock'>{outOfStock ? 'Sin unidades disponibles' : 'Disponible'}</div><div className='card-actions'><div className='qty-stepper'><button disabled={!quantity} aria-label={`Restar ${product.name}`} onClick={() => onQuantity(product.id, Math.max(0, quantity - 1))}>−</button><span>{quantity}</span><button disabled={outOfStock || quantity >= product.inventory} aria-label={`Sumar ${product.name}`} onClick={() => onQuantity(product.id, quantity + 1)}>+</button></div><button disabled={outOfStock} className={`add-btn${quantity ? ' added' : ''}`} onClick={() => onAdd(product)}>{outOfStock ? 'Agotado' : quantity ? 'Agregado ✓' : 'Agregar'}</button></div></div>
    </article>
  );
}
