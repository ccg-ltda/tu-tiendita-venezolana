// Eventos de ecommerce para Google Tag Manager (GTM-WZLNGRL7).
// Se empujan al dataLayer en formato GA4; en GTM cada evento alimenta la
// etiqueta de GA4 y la del píxel de Meta (prefijo "Tienda - ...").
// item_id = id del producto en la API: es el mismo id que debe usar el
// catálogo de Meta para que los anuncios dinámicos encuentren el producto.

const CURRENCY = 'COP';

function toItem(product, quantity = 1) {
  return {
    item_id: String(product.id),
    item_name: product.name,
    item_category: product.cat,
    item_category2: product.sub,
    price: Number(product.price),
    quantity,
  };
}

function push(event, ecommerce) {
  if (typeof window === 'undefined') return;
  window.dataLayer = window.dataLayer || [];
  // Limpia el objeto ecommerce anterior para que GTM no mezcle eventos.
  window.dataLayer.push({ ecommerce: null });
  window.dataLayer.push({ event, ecommerce: { currency: CURRENCY, ...ecommerce } });
}

export function trackViewItem(product) {
  push('view_item', { value: Number(product.price), items: [toItem(product)] });
}

export function trackAddToCart(product, quantity = 1) {
  push('add_to_cart', { value: Number(product.price) * quantity, items: [toItem(product, quantity)] });
}

export function trackBeginCheckout(items, value) {
  push('begin_checkout', { value: Number(value), items: items.map((item) => toItem(item, item.qty)) });
}

// Clic en una opción del menú de WhatsApp → "contact" (Meta: Contact) con el tema elegido.
export function trackContact(topic) {
  if (typeof window === 'undefined') return;
  window.dataLayer = window.dataLayer || [];
  window.dataLayer.push({ event: 'contact', method: 'whatsapp', contact_topic: topic });
}

// transaction_id = referencia del pedido. El servidor manda la misma compra a
// Meta por la API de conversiones con event_id = referencia, así Meta la
// cuenta una sola vez aunque llegue por el navegador y por el servidor.
export function trackPurchase({ reference, items, value, shipping, coupon }) {
  push('purchase', {
    transaction_id: reference,
    value: Number(value),
    shipping: Number(shipping) || 0,
    ...(coupon ? { coupon } : {}),
    items: items.map((item) => toItem(item, item.qty)),
  });
}
