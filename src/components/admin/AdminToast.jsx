import { AlertCircle, CheckCircle2, X } from 'lucide-react';
import { useEffect } from 'react';
import { playAdminToastSound } from './adminToastAudio';

const playedToastIds = new Set();

/** Reusable, non-blocking feedback for admin mutations. */
export function AdminToast({ toast, onDismiss, duration = 3800 }) {
  useEffect(() => {
    if (!toast) return undefined;

    const timeoutId = window.setTimeout(onDismiss, duration);

    return () => window.clearTimeout(timeoutId);
  }, [duration, onDismiss, toast]);

  useEffect(() => {
    if (!toast?.id || playedToastIds.has(toast.id)) return;

    playedToastIds.add(toast.id);
    if (playedToastIds.size > 50) playedToastIds.delete(playedToastIds.values().next().value);
    void playAdminToastSound(toast.variant || (toast.tone === 'error' ? 'error' : 'success'));
  }, [toast?.id, toast?.tone, toast?.variant]);

  if (!toast) return null;

  const variant = toast.variant || (toast.tone === 'error' ? 'error' : 'success');
  const isError = variant === 'error';
  const Icon = isError ? AlertCircle : CheckCircle2;

  return (
    <div className='admin-toast-region' aria-live='polite' aria-atomic='true'>
      <article className={`admin-toast admin-toast--${variant}`} role={isError ? 'alert' : 'status'}>
        <Icon className='admin-toast-icon' size={20} aria-hidden='true' />
        <div className='admin-toast-copy'>
          <strong>{toast.title}</strong>
          <p>{toast.message}</p>
        </div>
        <button type='button' className='admin-toast-close' onClick={onDismiss} aria-label='Cerrar notificación'>
          <X size={18} aria-hidden='true' />
        </button>
      </article>
    </div>
  );
}
