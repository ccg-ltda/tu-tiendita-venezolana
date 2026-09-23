import { formatPrice } from '../../utils/catalog';
import { Package, ShoppingBag, ShoppingCart } from 'lucide-react';

export function CartDrawer({ open, items, total, onClose, onRemove, onCheckout }) {
  return (
    <>
      <button className={`overlay${open ? ' open' : ''}`} onClick={onClose} aria-label="Cerrar carrito" />
      <aside className={`drawer${open ? ' open' : ''}`} aria-hidden={!open} aria-label="Carrito de compras">
        <div className="drawer-head"><h3>Tu pedido <ShoppingBag size={20} aria-hidden='true' /></h3><button className="drawer-close" onClick={onClose}>✕</button></div>
        <div className="drawer-body">
          {!items.length && <div className="drawer-empty">Tu carrito está vacío.<br />¡Agrega tus productos venezolanos favoritos!</div>}
          {items.map((item) => <div className="drawer-item" key={item.id}><div className="di-img">{item.image ? <img src={item.image} alt="" /> : <Package size={24} aria-hidden='true' />}</div><div className="di-info"><h4>{item.name}</h4><div className="di-pres">{item.pres} · x{item.qty}</div></div><div className="di-price">{item.price ? formatPrice(item.price * item.qty) : 'Consultar'}</div><button className="di-remove" onClick={() => onRemove(item.id)} aria-label={`Quitar ${item.name}`}>✕</button></div>)}
        </div>
        <div className="drawer-foot"><div className="drawer-total"><span>Total</span><span>{total ? formatPrice(total) : '$0'}</span></div><button className="checkout-btn" disabled={!items.length} onClick={onCheckout}><ShoppingCart size={18} aria-hidden='true' /> Continuar con el pago</button><div className="checkout-note">Pago seguro con Wompi (requiere configuración).</div></div>
      </aside>
    </>
  );
}
