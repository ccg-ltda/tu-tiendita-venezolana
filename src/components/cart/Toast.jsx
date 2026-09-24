import { ShoppingCart } from 'lucide-react';

export function Toast({ product, onOpen, onClose }) {
  if (!product) return null;
  return <div className="toast-stack"><div className="toast show"><div className="toast-icon">{product.image ? <img src={product.image} alt="" /> : <ShoppingCart size={18} aria-hidden='true' />}</div><div className="toast-body"><div className="toast-title">Agregado al carrito ✓</div><div className="toast-sub">{product.name}</div></div><button className="toast-action" onClick={() => { onClose(); onOpen(); }}>Ver carrito</button></div></div>;
}
