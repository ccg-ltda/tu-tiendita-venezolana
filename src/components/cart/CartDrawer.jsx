import { formatPrice } from '../../utils/catalog';
import { Package, ShoppingBag, ShoppingCart } from 'lucide-react';
import { productImageSrc } from '../../utils/productImage';

export function CartDrawer({ open, items, total, shipping = 0, grandTotal, onClose, onRemove, onCheckout }) {
  return (
    <>
      <button className={`overlay${open ? ' open' : ''}`} onClick={onClose} aria-label="Cerrar carrito" />
      <aside className={`drawer${open ? ' open' : ''}`} aria-hidden={!open} aria-label="Carrito de compras" {...(open ? {} : { inert: '' })}>
        <div className="drawer-head"><h3>Tu pedido <ShoppingBag size={20} aria-hidden='true' /></h3><button className="drawer-close" onClick={onClose}>✕</button></div>
        <div className="drawer-body">
          {!items.length && <div className="drawer-empty">Tu carrito está vacío.<br />¡Agrega tus productos venezolanos favoritos!</div>}
          {items.map((item) => <div className="drawer-item" key={item.id}><div className="di-img">{productImageSrc(item) ? <img src={productImageSrc(item)} alt="" /> : <Package size={24} aria-hidden='true' />}</div><div className="di-info"><h4>{item.name}</h4><div className="di-pres">{item.pres} · x{item.qty}</div></div><div className="di-price">{item.price ? formatPrice(item.price * item.qty) : 'Consultar'}</div><button className="di-remove" onClick={() => onRemove(item.id)} aria-label={`Quitar ${item.name}`}>✕</button></div>)}
        </div>
        <div className="drawer-foot">{Boolean(items.length) && <><div className="drawer-subtotal"><span>Subtotal</span><span>{formatPrice(total)}</span></div><div className="drawer-shipping"><span>Envío</span><span>{shipping ? formatPrice(shipping) : 'Gratis'}</span></div></>}<div className="drawer-total"><span>Total</span><span>{grandTotal ? formatPrice(grandTotal) : '$0'}</span></div><button className="checkout-btn" disabled={!items.length} onClick={onCheckout}><ShoppingCart size={18} aria-hidden='true' /> Continuar con el pago</button><div className="checkout-note">Pago seguro con Wompi.</div></div>
      </aside>
    </>
  );
}
