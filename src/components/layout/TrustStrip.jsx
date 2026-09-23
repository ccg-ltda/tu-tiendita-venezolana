import { CreditCard, Truck } from 'lucide-react';

export function TrustStrip() {
  const items = [
    [Truck, 'Envíos a toda Colombia', 'Bogotá: 24h hábiles. Resto del país: 3 a 7 días hábiles.'],
    [CreditCard, 'Pago seguro en línea', 'Pasarela Wompi. También aceptamos transferencias.'],
    [null, 'Productos venezolanos', 'Marcas originales y productos seleccionados.'],
  ];
  return <div className="trust-strip"><div className="trust-inner">{items.map(([Icon, title, text]) => <div className="trust-item" key={title}><span className="ic">{Icon ? <Icon size={22} aria-hidden='true' /> : '🇻🇪'}</span><div><h4>{title}</h4><p>{text}</p></div></div>)}</div></div>;
}
