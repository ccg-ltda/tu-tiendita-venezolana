import { useEffect, useState } from 'react';
import { shippingCost } from '../utils/shipping';

/** Cart state shared by the home page and standalone category/product pages. */
export function useCart(catalog) {
  const [cart, setCart] = useState(() => JSON.parse(localStorage.getItem('ttv-cart') || '{}'));
  const [toastProduct, setToastProduct] = useState(null);

  useEffect(() => localStorage.setItem('ttv-cart', JSON.stringify(cart)), [cart]);
  useEffect(() => setCart((current) => {
    let changed = false;
    const next = { ...current };
    Object.entries(next).forEach(([id, quantity]) => {
      const product = catalog.find((item) => item.id === Number(id));
      // Páginas como /producto/:slug solo cargan un producto: si este item del
      // carrito no aparece en `catalog`, no se toca (no se puede validar aquí).
      if (!product) return;
      const allowed = product.active === false ? 0 : Math.min(quantity, Math.max(0, Number(product.inventory) || 0));
      if (allowed !== quantity) changed = true;
      if (allowed > 0) next[id] = allowed; else delete next[id];
    });
    return changed ? next : current;
  }), [catalog]);
  useEffect(() => {
    if (!toastProduct) return undefined;
    const timer = setTimeout(() => setToastProduct(null), 2600);
    return () => clearTimeout(timer);
  }, [toastProduct]);

  const setQuantity = (id, quantity) => {
    const product = catalog.find((item) => item.id === id);
    const added = Math.min(Math.max(0, quantity), Math.max(0, Number(product?.inventory) || 0)) - (cart[id] || 0);
    if (product && added > 0 && Array.isArray(window.dataLayer)) {
      window.dataLayer.push({ event: 'add_to_cart', ecommerce: {
        currency: 'COP', value: Number(product.price) * added,
        items: [{ item_id: product.slug?.trim() || String(product.id), item_name: product.name, item_category: product.cat, price: Number(product.price), quantity: added }],
      } });
    }
    setCart((current) => {
    const product = catalog.find((item) => item.id === id);
    const next = { ...current };
    const safeQuantity = Math.min(Math.max(0, quantity), Math.max(0, Number(product?.inventory) || 0));
    if (safeQuantity > 0) next[id] = safeQuantity; else delete next[id];
    return next;
    });
  };
  const addProduct = (product) => { if (!cart[product.id]) setQuantity(product.id, 1); setToastProduct(product); };

  const cartItems = Object.entries(cart).map(([id, qty]) => ({ ...catalog.find((product) => product.id === Number(id)), qty })).filter((item) => item.id && item.qty > 0 && item.active !== false);
  const cartCount = cartItems.reduce((sum, item) => sum + item.qty, 0);
  const total = cartItems.reduce((sum, item) => sum + item.price * item.qty, 0);
  const shipping = cartItems.length ? shippingCost(total) : 0;
  const grandTotal = total + shipping;

  return { cart, cartItems, cartCount, total, shipping, grandTotal, toastProduct, setQuantity, addProduct, setToastProduct, clearCart: () => setCart({}) };
}
