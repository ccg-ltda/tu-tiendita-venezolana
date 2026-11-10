import { useEffect, useRef, useState } from 'react';
function trackContact(topic) {
  window.dataLayer = window.dataLayer || [];
  window.dataLayer.push({ event: 'contact', method: 'whatsapp', contact_topic: topic });
  // Conteo para el módulo de WhatsApp del panel (solo el tema; sin datos personales). Si falla, no pasa nada.
  try {
    fetch(`${import.meta.env.VITE_API_URL || ''}/api/whatsapp-clicks`, { method: 'POST', keepalive: true, headers: { 'Content-Type': 'application/json', Accept: 'application/json' }, body: JSON.stringify({ topic }) }).catch(() => {});
  } catch { /* sin conexión: se ignora */ }
}

// Menú de WhatsApp: un solo botón flotante con saludo y 3 opciones.
// Cada opción abre WhatsApp con el mensaje ya escrito; el cliente solo completa sus datos.
// El primer renglón dice el tema para reconocerlo y etiquetarlo en WhatsApp Business.
const NUMERO = '573107359944';
const OPCIONES = [
  {
    tema: 'mayorista', icono: '🏪', titulo: 'Comprar al por mayor',
    detalle: 'Precios especiales para tiendas, restaurantes y pedidos grandes',
    mensaje: '¡Hola, Tu Tiendita Venezolana! 🇻🇪\nQuiero *comprar al por mayor*.\n\nMi negocio: \nCiudad: \nProductos que me interesan: ',
  },
  {
    tema: 'soporte', icono: '🛟', titulo: 'Soporte',
    detalle: 'Estado de tu pedido, envío, cambios o pagos',
    mensaje: '¡Hola, Tu Tiendita Venezolana! 🇻🇪\nNecesito *soporte* con mi pedido.\n\nNúmero de pedido: TTV-\nMi consulta: ',
  },
  {
    tema: 'ayuda_compra', icono: '🙋', titulo: 'Quiero que me ayuden a comprar',
    detalle: 'Dinos qué buscas y te armamos el pedido por chat',
    mensaje: '¡Hola, Tu Tiendita Venezolana! 🇻🇪\nQuiero que me *ayuden a comprar*.\n\nEstoy buscando: \nCiudad de entrega: ',
  },
];
const enlace = (mensaje) => `https://wa.me/${NUMERO}?text=${encodeURIComponent(mensaje)}`;

function IconoWhatsApp() {
  return (
    <svg viewBox='0 0 24 24' aria-hidden='true'>
      <path d='M12.04 2C6.58 2 2.13 6.45 2.13 11.91c0 1.75.46 3.45 1.32 4.95L2.05 22l5.25-1.38a9.9 9.9 0 0 0 4.74 1.21h.01c5.46 0 9.91-4.45 9.91-9.91C21.96 6.45 17.5 2 12.04 2zm5.8 14.03c-.25.69-1.45 1.32-2 1.4-.51.08-1.16.11-1.87-.12-.43-.14-.98-.32-1.69-.62-2.97-1.28-4.91-4.27-5.06-4.47-.15-.2-1.21-1.61-1.21-3.07 0-1.46.77-2.18 1.04-2.48.27-.3.59-.37.79-.37h.57c.18 0 .43-.07.67.51.25.6.84 2.06.92 2.21.07.15.12.32.02.52-.1.2-.15.32-.3.49-.15.17-.31.39-.44.52-.15.15-.3.31-.13.6.17.3.77 1.27 1.65 2.06 1.13 1.01 2.09 1.32 2.39 1.47.3.15.47.12.64-.07.17-.2.74-.86.94-1.16.2-.3.4-.25.67-.15.27.1 1.73.82 2.03.97.3.15.5.22.57.35.07.12.07.72-.18 1.41z' />
    </svg>
  );
}

export function WhatsAppMenu() {
  const [abierto, setAbierto] = useState(false);
  const raiz = useRef(null);
  const boton = useRef(null);
  const primera = useRef(null);

  useEffect(() => {
    if (!abierto) return undefined;
    primera.current?.focus();
    const alTeclado = (e) => { if (e.key === 'Escape') { setAbierto(false); boton.current?.focus(); } };
    const alClic = (e) => { if (raiz.current && !raiz.current.contains(e.target)) setAbierto(false); };
    document.addEventListener('keydown', alTeclado);
    document.addEventListener('click', alClic);
    return () => { document.removeEventListener('keydown', alTeclado); document.removeEventListener('click', alClic); };
  }, [abierto]);

  return (
    <div className={`wam${abierto ? ' open' : ''}`} ref={raiz}>
      <div className='wam-card' id='wamCard' role='dialog' aria-labelledby='wamTitulo' aria-hidden={!abierto}>
        <div className='wam-head'>
          <b id='wamTitulo'>¡Hola! 👋 Bienvenido a Tu Tiendita Venezolana</b>
          <p>Los sabores de Venezuela con envío a toda Colombia. ¿En qué te podemos ayudar?</p>
          <button className='wam-x' type='button' aria-label='Cerrar' tabIndex={abierto ? 0 : -1} onClick={() => { setAbierto(false); boton.current?.focus(); }}>×</button>
        </div>
        <div className='wam-ops'>
          {OPCIONES.map((o, i) => (
            <a key={o.tema} ref={i === 0 ? primera : undefined} className='wam-op' href={enlace(o.mensaje)} target='_blank' rel='noopener noreferrer'
              tabIndex={abierto ? 0 : -1} onClick={() => { trackContact(o.tema); setAbierto(false); }}>
              <span className='wam-n' aria-hidden='true'>{o.icono}</span>
              <span><b>{i + 1}. {o.titulo}</b><small>{o.detalle}</small></span>
              <i aria-hidden='true'>›</i>
            </a>
          ))}
        </div>
        <div className='wam-pie'>Te respondemos por WhatsApp lo antes posible.</div>
      </div>
      <button ref={boton} className='wam-btn' type='button' aria-expanded={abierto} aria-controls='wamCard' onClick={(e) => { e.stopPropagation(); setAbierto((v) => !v); }}>
        <span className='wam-ic'><IconoWhatsApp /></span>
        <span>¿Te ayudamos?<small>Escríbenos por WhatsApp</small></span>
      </button>
    </div>
  );
}
