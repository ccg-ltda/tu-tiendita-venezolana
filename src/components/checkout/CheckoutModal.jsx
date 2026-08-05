import { useState } from 'react';
import { formatPrice } from '../../utils/catalog';

const emptyCustomer = { name: '', email: '', phone: '', document: '', address: '', extra: '', city: '', region: '', postal: '' };

export function CheckoutModal({ open, items, total, onClose, onComplete }) {
  const [step, setStep] = useState(1);
  const [customer, setCustomer] = useState(emptyCustomer);
  const [reference, setReference] = useState('');
  const update = (key) => (event) => setCustomer((current) => ({ ...current, [key]: event.target.value }));
  const validContact = customer.name && customer.email && customer.phone && customer.document;
  const validAddress = customer.address && customer.city && customer.region;
  const resetAndClose = () => { setStep(1); onClose(); };
  const simulatePayment = () => {
    const nextReference = `TTV-${Date.now().toString(36).toUpperCase()}`;
    setReference(nextReference);
    setStep(4);
    onComplete({ customer, items, total, reference: nextReference, status: 'APPROVED' });
  };
  if (!open) return null;
  return (
    <div className="co-overlay open" onClick={(event) => event.target === event.currentTarget && resetAndClose()}>
      <div className="co-modal"><div className="co-header"><h3>Completar pedido</h3><button className="co-close" onClick={resetAndClose}>✕</button></div><div className="co-steps">{['Datos', 'Dirección', 'Pago', 'Listo'].map((label, index) => <div key={label} className={`co-step${step === index + 1 ? ' active' : ''}${step > index + 1 ? ' done' : ''}`}>{index + 1} · {label}</div>)}</div>
        <div className="co-body">
          {step === 1 && <div className="co-section active"><Field label="Nombre completo *" value={customer.name} onChange={update('name')} /><Field label="Correo electrónico *" type="email" value={customer.email} onChange={update('email')} /><Field label="Teléfono / WhatsApp *" value={customer.phone} onChange={update('phone')} /><Field label="Número de documento *" value={customer.document} onChange={update('document')} /><div className="co-btn-row"><button className="co-btn-next" disabled={!validContact} onClick={() => setStep(2)}>Continuar →</button></div></div>}
          {step === 2 && <div className="co-section active"><Field label="Dirección de entrega *" value={customer.address} onChange={update('address')} /><Field label="Información adicional" value={customer.extra} onChange={update('extra')} /><div className="co-row"><Field label="Ciudad *" value={customer.city} onChange={update('city')} /><Field label="Departamento *" value={customer.region} onChange={update('region')} /></div><Field label="Código postal" value={customer.postal} onChange={update('postal')} /><div className="co-btn-row"><button className="co-btn-back" onClick={() => setStep(1)}>← Volver</button><button className="co-btn-next" disabled={!validAddress} onClick={() => setStep(3)}>Continuar →</button></div></div>}
          {step === 3 && <div className="co-section active"><div className="co-notice"><strong>Modo demostración:</strong> configura las variables de Wompi y un backend antes de aceptar pagos reales.</div><div className="co-order-summary"><h4>🛒 Resumen del pedido</h4>{items.map((item) => <div className="co-summary-row" key={item.id}><span>{item.name} ×{item.qty}</span><span>{formatPrice(item.price * item.qty)}</span></div>)}<div className="co-order-total"><span>Total</span><span>{formatPrice(total)}</span></div></div><div className="co-wompi-placeholder"><strong>🔒 Integración Wompi pendiente</strong><code>{import.meta.env.VITE_WOMPI_PUBLIC_KEY || 'VITE_WOMPI_PUBLIC_KEY'}</code></div><div className="co-btn-row"><button className="co-btn-back" onClick={() => setStep(2)}>← Volver</button><button className="co-btn-next" onClick={simulatePayment}>Simular pago</button></div></div>}
          {step === 4 && <div className="co-section active"><div className="co-success"><span className="co-check">✅</span><h3>¡Pedido recibido!</h3><p>Confirmación para <strong>{customer.email}</strong></p><div className="co-ref">Ref: {reference}</div><p>Estado: <span className="co-tag paid">Pago aprobado ✓</span></p></div><div className="co-btn-row"><button className="co-btn-next full" onClick={resetAndClose}>Seguir comprando</button></div></div>}
        </div>
      </div>
    </div>
  );
}

function Field({ label, type = 'text', value, onChange }) {
  return <label className="co-field"><span>{label}</span><input type={type} value={value} onChange={onChange} /></label>;
}
