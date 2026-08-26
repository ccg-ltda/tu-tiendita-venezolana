import { useEffect, useState } from 'react';

export function AdminLoginModal({ open, onClose, onLogin }) {
  const [email, setEmail] = useState('');
  const [password, setPassword] = useState('');
  const [error, setError] = useState('');
  const [submitting, setSubmitting] = useState(false);

  useEffect(() => { if (!open) { setPassword(''); setError(''); } }, [open]);
  if (!open) return null;

  const submit = async (event) => {
    event.preventDefault();
    setSubmitting(true);
    setError('');
    try {
      await onLogin({ email, password });
    } catch (requestError) {
      setError(requestError.message);
    } finally {
      setSubmitting(false);
    }
  };

  return (
    <div className='admin-overlay' onMouseDown={(event) => event.target === event.currentTarget && onClose()}>
      <section className='login-modal' role='dialog' aria-modal='true' aria-labelledby='admin-login-title'>
        <button className='admin-close' onClick={onClose} aria-label='Cerrar'>×</button>
        <div className='login-icon'>🔐</div>
        <p className='admin-eyebrow'>Acceso privado</p>
        <h2 id='admin-login-title'>Administración de tienda</h2>
        <p className='login-intro'>Inicia sesión para actualizar productos, precios y existencias.</p>
        <form onSubmit={submit}>
          <label className='admin-field'><span>Correo electrónico</span><input type='email' value={email} onChange={(event) => { setEmail(event.target.value); setError(''); }} placeholder='admin@tutiendita.com' autoFocus required /></label>
          <label className='admin-field'><span>Contraseña</span><input type='password' value={password} onChange={(event) => { setPassword(event.target.value); setError(''); }} placeholder='Tu contraseña' required /></label>
          {error && <div className='login-error' role='alert'>{error}</div>}
          <button className='admin-primary-btn' type='submit' disabled={submitting}>{submitting ? 'Verificando…' : 'Iniciar sesión'}</button>
        </form>
      </section>
    </div>
  );
}
