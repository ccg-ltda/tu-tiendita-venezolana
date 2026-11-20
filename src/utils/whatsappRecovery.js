/** Enlace de WhatsApp (wa.me). Los celulares de 10 dígitos sin prefijo se asumen de Colombia (57). */
export const waLink = (phone, text) => `https://wa.me/${String(phone || '').replace(/[^\d]/g, '').replace(/^(?!57)(\d{10})$/, '57$1')}?text=${encodeURIComponent(text)}`;

const firstName = (name) => {
  const word = String(name || '').trim().split(/\s+/)[0] || '';
  return word ? word.charAt(0).toUpperCase() + word.slice(1).toLowerCase() : '';
};

/** Mensaje de ayuda (no de venta) para quien dejó un pedido sin terminar de pagar. */
export const paymentReminderMessage = (name) =>
  `Hola${firstName(name) ? ` ${firstName(name)}` : ''}, te escribimos de Tu Tiendita Venezolana. Vimos que dejaste un pedido sin terminar de pagar. ¿Tuviste algún problema con el pago? Te ayudamos con gusto. \u{1F1FB}\u{1F1EA}`;
