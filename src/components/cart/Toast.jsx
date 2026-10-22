import { ShoppingCart } from 'lucide-react';
import { productImageSrc } from '../../utils/productImage';

export function Toast({ product, onOpen, onClose }) {
  if (!product) return null;
  const imageSrc = productImageSrc(product);
  return <div className="toast-stack"><div className="toast show"><div className="toast-icon">{imageSrc ? <img src={imageSrc} alt="" /> : <ShoppingCart size={18} aria-hidden='true' />}</div><div className="toast-body"><div className="toast-title">Agregado al carrito ✓</div><div className="toast-sub">{product.name}</div></div><button className="toast-action" onClick={() => { onClose(); onOpen(); }}>Ver carrito</button></div></div>;
}
