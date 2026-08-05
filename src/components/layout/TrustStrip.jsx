export function TrustStrip() {
  const items = [
    ['🚚', 'Envíos a toda Colombia', 'Bogotá: 24h hábiles. Resto del país: 3 a 7 días hábiles.'],
    ['💳', 'Pago seguro en línea', 'Pasarela Wompi. También aceptamos transferencias.'],
    ['🇻🇪', 'Productos venezolanos', 'Marcas originales y productos seleccionados.'],
  ];
  return <div className="trust-strip"><div className="trust-inner">{items.map(([icon, title, text]) => <div className="trust-item" key={title}><span className="ic">{icon}</span><div><h4>{title}</h4><p>{text}</p></div></div>)}</div></div>;
}
