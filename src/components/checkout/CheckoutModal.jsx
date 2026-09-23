import { useEffect, useRef, useState } from 'react';
import { CircleCheck, ShoppingCart } from 'lucide-react';
import { formatPrice } from '../../utils/catalog';
import { api } from '../../services/api';
import { loadWompiWidget } from '../../services/wompiWidget';

const emptyCustomer = { name: '', email: '', phone: '', document: '', address: '', extra: '', city: '', region: '', postal: '' };
const STATUS_CHECK_INTERVAL = 5000;
const MAX_STATUS_CHECKS = 13;

export function CheckoutModal({ open, items, total, onClose, onPaymentConfirmed }) {
  const [step, setStep] = useState(1);
  const [customer, setCustomer] = useState(emptyCustomer);
  const [reference, setReference] = useState('');
  const [submitting, setSubmitting] = useState(false);
  const [error, setError] = useState('');
  const [paymentResult, setPaymentResult] = useState(null);
  const idempotencyKey = useRef(null);
  const statusToken = useRef(null);
  const statusCheckTimeout = useRef(null);
  const statusCheckInFlight = useRef(false);
  const statusCheckCount = useRef(0);
  const verificationActive = useRef(false);
  const mounted = useRef(false);
  const modalOpen = useRef(open);
  const confirmed = useRef(false);

  modalOpen.current = open;

  const clearStatusCheck = () => {
    if (statusCheckTimeout.current) {
      clearTimeout(statusCheckTimeout.current);
      statusCheckTimeout.current = null;
    }
    verificationActive.current = false;
  };

  useEffect(() => {
    mounted.current = true;
    return () => {
      mounted.current = false;
      clearStatusCheck();
    };
  }, []);

  useEffect(() => {
    if (!open) clearStatusCheck();
  }, [open]);

  const update = (key) => (event) => setCustomer((current) => ({ ...current, [key]: event.target.value }));
  const validContact = customer.name && customer.email && customer.phone && customer.document;
  const validAddress = customer.address && customer.city && customer.region;
  const resetAndClose = () => {
    clearStatusCheck();
    modalOpen.current = false;
    statusToken.current = null;
    idempotencyKey.current = null;
    confirmed.current = false;
    setStep(1);
    setError('');
    setPaymentResult(null);
    onClose();
  };
  const returnToAddress = () => {
    clearStatusCheck();
    statusToken.current = null;
    idempotencyKey.current = null;
    setStep(2);
    setError('');
    setPaymentResult(null);
  };

  const showStatusResult = (state) => {
    if (!mounted.current || !modalOpen.current) return;
    setPaymentResult({ state });
    setStep(4);
  };

  const confirmPayment = () => {
    if (confirmed.current || !mounted.current || !modalOpen.current) return;

    confirmed.current = true;
    clearStatusCheck();
    statusToken.current = null;
    idempotencyKey.current = null;
    onPaymentConfirmed();
    showStatusResult('APPROVED');
  };

  const scheduleNextStatusCheck = (allowPolling) => {
    if (allowPolling && statusCheckCount.current < MAX_STATUS_CHECKS) {
      statusCheckTimeout.current = setTimeout(() => {
        statusCheckTimeout.current = null;
        checkPaymentStatus({ allowPolling: true });
      }, STATUS_CHECK_INTERVAL);
      return;
    }

    clearStatusCheck();
    showStatusResult(allowPolling ? 'TEMPORARY_ERROR' : 'PENDING');
  };

  const checkPaymentStatus = async ({ allowPolling = false } = {}) => {
    if (!statusToken.current || statusCheckInFlight.current || !mounted.current || !modalOpen.current) return;

    statusCheckInFlight.current = true;
    statusCheckCount.current += 1;
    try {
      const { checkout } = await api.getWompiPaymentStatus(statusToken.current);
      if (!mounted.current || !modalOpen.current || !verificationActive.current) return;

      if (checkout.status === 'APPROVED') {
        confirmPayment();
        return;
      }

      if (['DECLINED', 'VOIDED', 'ERROR'].includes(checkout.status)) {
        clearStatusCheck();
        showStatusResult(checkout.status);
        return;
      }

      if (checkout.reservation === 'EXPIRED') {
        clearStatusCheck();
        showStatusResult('EXPIRED');
        return;
      }

      if (checkout.reservation === 'RELEASED') {
        clearStatusCheck();
        showStatusResult('RELEASED');
        return;
      }

      if (checkout.reservation === 'INVALID') {
        clearStatusCheck();
        showStatusResult('INVALID');
        return;
      }

      if (checkout.status === 'PENDING' && checkout.reservation === 'ACTIVE') {
        scheduleNextStatusCheck(allowPolling);
        return;
      }

      scheduleNextStatusCheck(allowPolling);
    } catch (requestError) {
      if (!mounted.current || !modalOpen.current || !verificationActive.current) return;

      if (requestError.status === 404) {
        clearStatusCheck();
        showStatusResult('UNAVAILABLE');
      } else {
        scheduleNextStatusCheck(allowPolling);
      }
    } finally {
      statusCheckInFlight.current = false;
    }
  };

  const startStatusVerification = ({ allowPolling }) => {
    clearStatusCheck();
    statusCheckCount.current = 0;
    verificationActive.current = true;
    showStatusResult('VERIFYING');
    checkPaymentStatus({ allowPolling });
  };

  const preparePayment = async () => {
    setSubmitting(true);
    setError('');
    try {
      if (!idempotencyKey.current) idempotencyKey.current = crypto.randomUUID();

      const prepared = await api.prepareWompiPayment({
        customer,
        items: items.map((item) => ({ id: item.id, qty: item.qty })),
      }, idempotencyKey.current);
      const { order, payment, checkout: preparedCheckout } = prepared;
      if (!preparedCheckout?.statusToken) {
        throw new Error('No fue posible preparar la verificación del pago.');
      }

      statusToken.current = preparedCheckout.statusToken;
      const WidgetCheckout = await loadWompiWidget();
      const checkout = new WidgetCheckout({
        currency: payment.currency,
        amountInCents: payment.amountInCents,
        reference: payment.reference,
        publicKey: payment.publicKey,
        signature: { integrity: payment.integritySignature },
        expirationTime: payment.expirationTime,
      });

      setReference(order.reference);
      checkout.open((result) => {
        if (!mounted.current || !modalOpen.current) return;

        if (!result?.transaction) {
          setError('El proceso de pago se cerró sin un resultado. Puedes intentarlo nuevamente.');
          return;
        }

        startStatusVerification({ allowPolling: true });
      });
    } catch (requestError) {
      setError(requestError.message);
    } finally {
      setSubmitting(false);
    }
  };

  const verifyAgain = () => startStatusVerification({ allowPolling: false });

  if (!open) return null;
  return (
    <div className='co-overlay open' onClick={(event) => event.target === event.currentTarget && resetAndClose()}>
      <div className='co-modal'><div className='co-header'><h3>Completar pedido</h3><button className='co-close' onClick={resetAndClose}>✕</button></div><div className='co-steps'>{['Datos', 'Dirección', 'Confirmar', 'Listo'].map((label, index) => <div key={label} className={`co-step${step === index + 1 ? ' active' : ''}${step > index + 1 ? ' done' : ''}`}>{index + 1} · {label}</div>)}</div>
        <div className='co-body'>
          {step === 1 && <div className='co-section active'><Field label='Nombre completo *' value={customer.name} onChange={update('name')} /><Field label='Correo electrónico *' type='email' value={customer.email} onChange={update('email')} /><Field label='Teléfono / WhatsApp *' value={customer.phone} onChange={update('phone')} /><Field label='Número de documento *' value={customer.document} onChange={update('document')} /><div className='co-btn-row'><button className='co-btn-next' disabled={!validContact} onClick={() => setStep(2)}>Continuar →</button></div></div>}
          {step === 2 && <div className='co-section active'><Field label='Dirección de entrega *' value={customer.address} onChange={update('address')} /><Field label='Información adicional' value={customer.extra} onChange={update('extra')} /><div className='co-row'><Field label='Ciudad *' value={customer.city} onChange={update('city')} /><Field label='Departamento *' value={customer.region} onChange={update('region')} /></div><Field label='Código postal' value={customer.postal} onChange={update('postal')} /><div className='co-btn-row'><button className='co-btn-back' onClick={() => setStep(1)}>← Volver</button><button className='co-btn-next' disabled={!validAddress} onClick={() => setStep(3)}>Continuar →</button></div></div>}
          {step === 3 && <div className='co-section active'><div className='co-notice'><strong>Confirma tu solicitud:</strong> al preparar el pago reservaremos las unidades disponibles.</div><div className='co-order-summary'><h4><ShoppingCart size={15} aria-hidden='true' /> Resumen del pedido</h4>{items.map((item) => <div className='co-summary-row' key={item.id}><span>{item.name} ×{item.qty}</span><span>{formatPrice(item.price * item.qty)}</span></div>)}<div className='co-order-total'><span>Total</span><span>{formatPrice(total)}</span></div></div>{error && <div className='login-error' role='alert'>{error}</div>}<div className='co-btn-row'><button className='co-btn-back' disabled={submitting} onClick={returnToAddress}>← Volver</button><button className='co-btn-next' disabled={submitting} onClick={preparePayment}>{submitting ? 'Preparando pago…' : 'Continuar al pago'}</button></div></div>}
          {step === 4 && <PaymentResult customer={customer} reference={reference} result={paymentResult} onClose={resetAndClose} onVerifyAgain={verifyAgain} verifying={statusCheckInFlight.current} />}
        </div>
      </div>
    </div>
  );
}

function Field({ label, type = 'text', value, onChange }) {
  return <label className='co-field'><span>{label}</span><input type={type} value={value} onChange={onChange} /></label>;
}

function PaymentResult({ customer, reference, result, onClose, onVerifyAgain, verifying }) {
  const details = {
    DECLINED: ['Pago rechazado', 'Wompi informó que el pago fue rechazado.', 'Rechazado', 'pending'],
    VOIDED: ['Pago anulado', 'Wompi informó que el pago fue anulado.', 'Anulado', 'pending'],
    ERROR: ['Pago con error', 'Wompi informó un error al procesar el pago.', 'Error', 'pending'],
    VERIFYING: ['Verificando pago', 'Estamos consultando la confirmación definitiva del pago.', 'Verificando', 'pending'],
    APPROVED: ['Pago confirmado', 'Tu pago fue aprobado correctamente. Tu pedido ha sido registrado.', 'Confirmado', 'paid'],
    PENDING: ['Pago en verificación', 'Tu pago está siendo verificado. Aún no hemos recibido la confirmación definitiva.', 'En verificación', 'pending'],
    EXPIRED: ['Reserva vencida', 'La reserva de este checkout venció antes de recibir la confirmación.', 'Reserva vencida', 'pending'],
    RELEASED: ['Reserva liberada', 'La reserva de este checkout fue liberada.', 'Reserva liberada', 'pending'],
    INVALID: ['Checkout no disponible', 'Este checkout ya no puede confirmarse normalmente.', 'No disponible', 'pending'],
    UNAVAILABLE: ['Estado no disponible', 'Ya no es posible consultar el estado de este checkout.', 'No disponible', 'pending'],
    RATE_LIMITED: ['Demasiadas comprobaciones', 'Espera un momento antes de volver a verificar el estado del pago.', 'Espera antes de verificar', 'pending'],
    TEMPORARY_ERROR: ['No fue posible verificar el pago', 'No fue posible comprobar el estado en este momento. Puedes intentarlo de nuevo más tarde.', 'Verificación pendiente', 'pending'],
  };
  const [title, message, label, tagClass] = details[result?.state] || ['Resultado de pago no disponible', 'No se recibió un estado de pago válido.', 'Sin estado', 'pending'];
  const canVerifyAgain = !['APPROVED', 'VERIFYING', 'DECLINED', 'VOIDED', 'ERROR', 'EXPIRED', 'RELEASED', 'INVALID', 'UNAVAILABLE'].includes(result?.state);

  return <div className='co-section active'><div className='co-success'><span className='co-check'><CircleCheck size={48} aria-hidden='true' /></span><h3>{title}</h3><p>{message}</p><p>Pedido preparado para <strong>{customer.email}</strong></p><div className='co-ref'>Ref: {reference}</div><p>Estado: <span className={`co-tag ${tagClass}`}>{label}</span></p></div><div className='co-btn-row'>{canVerifyAgain && <button className='co-btn-back' disabled={verifying} onClick={onVerifyAgain}>{verifying ? 'Verificando…' : 'Comprobar nuevamente'}</button>}<button className='co-btn-next full' onClick={onClose}>Seguir comprando</button></div></div>;
}
