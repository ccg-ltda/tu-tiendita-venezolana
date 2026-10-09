import { useEffect, useRef, useState } from 'react';
import { CircleCheck, ShoppingCart } from 'lucide-react';
import { formatPrice } from '../../utils/catalog';
import { api } from '../../services/api';
import { loadWompiWidget } from '../../services/wompiWidget';

const emptyCustomer = { name: '', email: '', phone: '', document: '', address: '', extra: '', city: '', region: '', postal: '' };
const STATUS_CHECK_INTERVAL = 5000;
const MAX_STATUS_CHECKS = 13;
const contactFields = ['name', 'email', 'phone', 'document'];
const addressFields = ['address', 'city', 'region', 'postal'];

function fieldError(field, value) {
  const text = value.trim();
  if (field === 'name') return text.length >= 2 && text.length <= 120 ? '' : 'Ingresa tu nombre completo.';
  if (field === 'email') return /^[A-Z0-9.!#$%&'*+/=?^_`{|}~-]+@[A-Z0-9](?:[A-Z0-9-]{0,61}[A-Z0-9])?(?:\.[A-Z0-9](?:[A-Z0-9-]{0,61}[A-Z0-9])?)+$/i.test(text) ? '' : 'Ingresa un correo electrónico válido.';
  if (field === 'phone') return /^(?:3\d{9}|573\d{9}|\+573\d{9})$/.test(value.replace(/[ \-()]/g, '')) ? '' : 'Ingresa un número de teléfono válido.';
  if (field === 'document') return /^[\p{L}\p{N} .-]{3,30}$/u.test(text) ? '' : 'Ingresa un número de documento válido.';
  if (field === 'address') return text.length >= 5 && text.length <= 300 ? '' : 'Ingresa la dirección de entrega.';
  if (field === 'city') return text.length >= 2 && text.length <= 100 ? '' : 'Ingresa la ciudad.';
  if (field === 'region') return text.length >= 2 && text.length <= 100 ? '' : 'Ingresa el departamento.';
  if (field === 'postal') return text === '' || (/^[A-Z0-9 -]{3,20}$/i.test(text)) ? '' : 'Ingresa un código postal válido.';
  return '';
}

export function CheckoutModal({ open, items, total, onClose, onPaymentConfirmed }) {
  const [step, setStep] = useState(1);
  const [customer, setCustomer] = useState(emptyCustomer);
  const [reference, setReference] = useState('');
  const [submitting, setSubmitting] = useState(false);
  const [error, setError] = useState('');
  const [paymentResult, setPaymentResult] = useState(null);
  const [fieldErrors, setFieldErrors] = useState({});
  const [touched, setTouched] = useState({});
  const [couponCode, setCouponCode] = useState('');
  const [couponPreview, setCouponPreview] = useState(null);
  const [couponLoading, setCouponLoading] = useState(false);
  const [couponError, setCouponError] = useState('');
  const inputRefs = useRef({});
  const idempotencyKey = useRef(null);
  const statusToken = useRef(null);
  const wompiTransactionId = useRef(null);
  const statusCheckTimeout = useRef(null);
  const statusCheckInFlight = useRef(false);
  const statusCheckCount = useRef(0);
  const verificationActive = useRef(false);
  const mounted = useRef(false);
  const modalOpen = useRef(open);
  const confirmed = useRef(false);
  const cartSignature = items.map((item) => `${item.id}:${item.qty}`).sort().join('|');

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

  useEffect(() => {
    if (!couponPreview) return;
    setCouponPreview(null);
    setCouponError('');
    idempotencyKey.current = null;
  }, [cartSignature]);

  const updateFieldError = (key, message) => setFieldErrors((current) => {
    const next = { ...current };
    if (message) next[key] = message;
    else delete next[key];
    return next;
  });
  const update = (key) => (event) => {
    const value = event.target.value;
    setCustomer((current) => ({ ...current, [key]: value }));
    if (touched[key]) updateFieldError(key, fieldError(key, value));
  };
  const validateOnBlur = (key) => () => {
    setTouched((current) => ({ ...current, [key]: true }));
    updateFieldError(key, fieldError(key, customer[key]));
  };
  const continueFromStep = (nextStep) => {
    const fields = nextStep === 2 ? contactFields : addressFields;
    const errors = fields.reduce((current, key) => {
      const message = fieldError(key, customer[key]);
      if (message) current[key] = message;
      return current;
    }, {});
    setTouched((current) => ({ ...current, ...Object.fromEntries(fields.map((key) => [key, true])) }));
    setFieldErrors((current) => {
      const next = { ...current };
      fields.forEach((key) => {
        if (errors[key]) next[key] = errors[key];
        else delete next[key];
      });
      return next;
    });
    const firstInvalid = fields.find((key) => errors[key]);
    if (firstInvalid) {
      requestAnimationFrame(() => inputRefs.current[firstInvalid]?.focus());
      return;
    }
    setError('');
    setStep(nextStep);
  };
  const resetAndClose = () => {
    clearStatusCheck();
    modalOpen.current = false;
    statusToken.current = null;
    wompiTransactionId.current = null;
    idempotencyKey.current = null;
    confirmed.current = false;
    setStep(1);
    setError('');
    setFieldErrors({});
    setTouched({});
    setPaymentResult(null);
    setCouponCode('');
    setCouponPreview(null);
    setCouponError('');
    onClose();
  };
  const returnToAddress = () => {
    clearStatusCheck();
    statusToken.current = null;
    wompiTransactionId.current = null;
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
    showStatusResult('PENDING');
  };

  const checkPaymentStatus = async ({ allowPolling = false } = {}) => {
    if (!statusToken.current || statusCheckInFlight.current || !mounted.current || !modalOpen.current) return;

    statusCheckInFlight.current = true;
    statusCheckCount.current += 1;
    try {
      const { checkout } = await api.getWompiPaymentStatus(statusToken.current, wompiTransactionId.current);
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

      if (checkout.status === 'PENDING') {
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
        clearStatusCheck();
        showStatusResult('TEMPORARY_ERROR');
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
        couponCode: couponPreview?.coupon?.code ?? null,
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

        if (!result?.transaction?.id) {
          setError('El proceso de pago se cerró sin un resultado. Puedes intentarlo nuevamente.');
          return;
        }

        wompiTransactionId.current = result.transaction.id;
        startStatusVerification({ allowPolling: true });
      });
    } catch (requestError) {
      if (requestError.code === 'RESERVATION_EXPIRED') {
        idempotencyKey.current = null;
      }
      if (requestError.code?.startsWith('COUPON_')) {
        setCouponPreview(null);
        setCouponError(requestError.message);
        idempotencyKey.current = null;
      }
      setError(requestError.message);
    } finally {
      setSubmitting(false);
    }
  };

  const verifyAgain = () => startStatusVerification({ allowPolling: false });

  const applyCoupon = async (event) => {
    event.preventDefault();
    const normalized = couponCode.trim().toUpperCase();
    setCouponCode(normalized);
    setCouponPreview(null);
    setCouponError('');
    idempotencyKey.current = null;
    if (!normalized) return setCouponError('Ingresa un código de descuento.');
    setCouponLoading(true);
    try {
      setCouponPreview(await api.previewCoupon({ code: normalized, items: items.map((item) => ({ id: item.id, qty: item.qty })) }));
    } catch (requestError) {
      const minimum = requestError.minimumOrderCop;
      setCouponError(requestError.code === 'COUPON_MINIMUM_NOT_MET' && minimum ? `Este cupón requiere una compra mínima de ${formatPrice(minimum)}.` : requestError.message);
    } finally {
      setCouponLoading(false);
    }
  };

  const changeCouponCode = (event) => {
    setCouponCode(event.target.value);
    if (couponPreview) { setCouponPreview(null); idempotencyKey.current = null; }
    setCouponError('');
  };
  const removeCoupon = () => { setCouponCode(''); setCouponPreview(null); setCouponError(''); idempotencyKey.current = null; };

  if (!open) return null;
  return (
    <div className='co-overlay open' onClick={(event) => event.target === event.currentTarget && resetAndClose()}>
      <div className='co-modal'><div className='co-header'><h3>Completar pedido</h3><button className='co-close' onClick={resetAndClose}>✕</button></div><div className='co-steps'>{['Datos', 'Dirección', 'Confirmar', 'Listo'].map((label, index) => <div key={label} className={`co-step${step === index + 1 ? ' active' : ''}${step > index + 1 ? ' done' : ''}`}>{index + 1} · {label}</div>)}</div>
        <div className='co-body'>
          {step === 1 && <div className='co-section active'><Field label='Nombre completo *' value={customer.name} onChange={update('name')} onBlur={validateOnBlur('name')} error={fieldErrors.name} inputRef={(element) => { inputRefs.current.name = element; }} /><Field label='Correo electrónico *' type='email' value={customer.email} onChange={update('email')} onBlur={validateOnBlur('email')} error={fieldErrors.email} inputRef={(element) => { inputRefs.current.email = element; }} /><Field label='Teléfono / WhatsApp *' value={customer.phone} onChange={update('phone')} onBlur={validateOnBlur('phone')} error={fieldErrors.phone} inputRef={(element) => { inputRefs.current.phone = element; }} /><Field label='Número de documento *' value={customer.document} onChange={update('document')} onBlur={validateOnBlur('document')} error={fieldErrors.document} inputRef={(element) => { inputRefs.current.document = element; }} /><div className='co-btn-row'><button className='co-btn-next' onClick={() => continueFromStep(2)}>Continuar →</button></div></div>}
          {step === 2 && <div className='co-section active'><Field label='Dirección de entrega *' value={customer.address} onChange={update('address')} onBlur={validateOnBlur('address')} error={fieldErrors.address} inputRef={(element) => { inputRefs.current.address = element; }} /><Field label='Información adicional' value={customer.extra} onChange={update('extra')} /><div className='co-row'><Field label='Ciudad *' value={customer.city} onChange={update('city')} onBlur={validateOnBlur('city')} error={fieldErrors.city} inputRef={(element) => { inputRefs.current.city = element; }} /><Field label='Departamento *' value={customer.region} onChange={update('region')} onBlur={validateOnBlur('region')} error={fieldErrors.region} inputRef={(element) => { inputRefs.current.region = element; }} /></div><Field label='Código postal' value={customer.postal} onChange={update('postal')} onBlur={validateOnBlur('postal')} error={fieldErrors.postal} inputRef={(element) => { inputRefs.current.postal = element; }} /><div className='co-btn-row'><button className='co-btn-back' onClick={() => setStep(1)}>← Volver</button><button className='co-btn-next' onClick={() => continueFromStep(3)}>Continuar →</button></div></div>}
          {step === 3 && <div className='co-section active'><div className='co-notice'><strong>Confirma tu solicitud:</strong> al preparar el pago reservaremos las unidades disponibles.</div><div className='co-order-summary'><h4><ShoppingCart size={15} aria-hidden='true' /> Resumen del pedido</h4>{items.map((item) => <div className='co-summary-row' key={item.id}><span>{item.name} ×{item.qty}</span><span>{formatPrice(item.price * item.qty)}</span></div>)}<form className='co-coupon' onSubmit={applyCoupon}><label htmlFor='checkout-coupon-code'>Código de descuento</label><div className='co-coupon-controls'><input id='checkout-coupon-code' value={couponCode} onChange={changeCouponCode} disabled={couponLoading || submitting} autoComplete='off' aria-describedby={couponError ? 'checkout-coupon-error' : undefined} /><button type='submit' disabled={couponLoading || submitting}>{couponLoading ? 'Aplicando…' : 'Aplicar'}</button></div></form>{couponPreview && <div className='co-coupon-applied' role='status'><span>✓ Cupón {couponPreview.coupon.code} aplicado</span><button type='button' onClick={removeCoupon} disabled={couponLoading || submitting}>Quitar</button></div>}{couponError && <div className='co-coupon-error' id='checkout-coupon-error' role='alert'>{couponError}</div>}{couponPreview?.excluded_promotional_subtotal_cop > 0 && <p className='co-coupon-note'>El descuento se aplicó únicamente a los productos sin promoción.</p>}<div className='co-totals'><div><span>Subtotal</span><span>{formatPrice(couponPreview?.subtotal_cop ?? total)}</span></div>{couponPreview && <div className='co-discount'><span>Descuento</span><span>-{formatPrice(couponPreview.coupon_discount_cop)}</span></div>}<div className='co-order-total'><span>Total</span><span>{formatPrice(couponPreview?.total_cop ?? total)}</span></div></div></div>{error && <div className='login-error' role='alert'>{error}</div>}<div className='co-btn-row'><button className='co-btn-back' disabled={submitting || couponLoading} onClick={returnToAddress}>← Volver</button><button className='co-btn-next' disabled={submitting || couponLoading} onClick={preparePayment}>{submitting ? 'Preparando pago…' : 'Pagar con Wompi'}</button></div></div>}
          {step === 4 && <PaymentResult customer={customer} reference={reference} result={paymentResult} onClose={resetAndClose} onVerifyAgain={verifyAgain} verifying={statusCheckInFlight.current} />}
        </div>
      </div>
    </div>
  );
}

function Field({ label, type = 'text', value, onChange, onBlur, error, inputRef }) {
  return <label className='co-field'><span>{label}</span><input ref={inputRef} type={type} value={value} onChange={onChange} onBlur={onBlur} aria-invalid={Boolean(error)} aria-describedby={error ? `${label}-error` : undefined} />{error && <small className='co-field-error' id={`${label}-error`} role='alert'>{error}</small>}</label>;
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
    TEMPORARY_ERROR: ['Estado de pago no disponible', 'No fue posible consultar el estado del pago en este momento. Intenta comprobarlo nuevamente.', 'Sin confirmar', 'pending'],
  };
  const [title, message, label, tagClass] = details[result?.state] || ['Resultado de pago no disponible', 'No se recibió un estado de pago válido.', 'Sin estado', 'pending'];
  const canVerifyAgain = !['APPROVED', 'VERIFYING', 'DECLINED', 'VOIDED', 'ERROR', 'EXPIRED', 'RELEASED', 'INVALID', 'UNAVAILABLE'].includes(result?.state);

  return <div className='co-section active'><div className='co-success'><span className='co-check'><CircleCheck size={48} aria-hidden='true' /></span><h3>{title}</h3><p>{message}</p><p>Pedido preparado para <strong>{customer.email}</strong></p><div className='co-ref'>Ref: {reference}</div><p>Estado: <span className={`co-tag ${tagClass}`}>{label}</span></p></div><div className='co-btn-row'>{canVerifyAgain && <button className='co-btn-back' disabled={verifying} onClick={onVerifyAgain}>{verifying ? 'Verificando…' : 'Consultar estado del pago'}</button>}<button className='co-btn-next full' onClick={onClose}>Seguir comprando</button></div></div>;
}
