import { useEffect, useState } from 'react';
import { useLocation } from 'react-router-dom';
import { Download, X } from 'lucide-react';
import { track } from '../../utils/track';

const DISMISS_KEY = 'ttv_install_dismissed_at';
const HIDE_DAYS = 14;

const isStandalone = () => window.matchMedia?.('(display-mode: standalone)').matches || navigator.standalone === true;
const wasDismissedRecently = () => {
  try { return Date.now() - Number(localStorage.getItem(DISMISS_KEY) || 0) < HIDE_DAYS * 86400000; } catch { return false; }
};

/** Aviso discreto para instalar la tienda como app. Solo en la tienda (no en el panel). */
export function InstallAppBanner() {
  const { pathname } = useLocation();
  const inAdmin = pathname.startsWith('/admin');
  const [prompt, setPrompt] = useState(null);
  const [hidden, setHidden] = useState(true);
  const [iosHelp, setIosHelp] = useState(false);
  const isIos = /iphone|ipad|ipod/i.test(navigator.userAgent);

  // Manifiesto + service worker, solo mientras se está en la tienda.
  useEffect(() => {
    if (inAdmin) return undefined;
    const link = document.createElement('link');
    link.rel = 'manifest';
    link.href = '/tienda-app/manifest.webmanifest';
    document.head.appendChild(link);
    if ('serviceWorker' in navigator) navigator.serviceWorker.register('/tienda-sw.js', { scope: '/' }).catch(() => {});
    return () => link.remove();
  }, [inAdmin]);

  // Medición: cuántas visitas llegan desde la app ya instalada.
  useEffect(() => {
    if (inAdmin || !isStandalone()) return;
    try { if (sessionStorage.getItem('ttv_pwa_launch')) return; sessionStorage.setItem('ttv_pwa_launch', '1'); } catch { /* ignorar */ }
    track('pwa_launch', { display_mode: 'standalone' });
  }, [inAdmin]);

  useEffect(() => {
    if (inAdmin || isStandalone()) return undefined;
    const onPrompt = (event) => {
      event.preventDefault();
      setPrompt(event);
      if (!wasDismissedRecently()) { setHidden(false); track('pwa_install_prompt_shown', { platform: 'android_desktop' }); }
    };
    const onInstalled = () => { setPrompt(null); setHidden(true); track('pwa_installed'); };
    window.addEventListener('beforeinstallprompt', onPrompt);
    window.addEventListener('appinstalled', onInstalled);
    // iPhone no avisa: se muestra la ayuda manual.
    let timer;
    if (isIos && !wasDismissedRecently()) timer = setTimeout(() => { setHidden(false); track('pwa_install_prompt_shown', { platform: 'ios' }); }, 4000);
    return () => { clearTimeout(timer); window.removeEventListener('beforeinstallprompt', onPrompt); window.removeEventListener('appinstalled', onInstalled); };
  }, [inAdmin, isIos]);

  if (inAdmin || hidden || isStandalone() || (!prompt && !isIos)) return null;

  const close = () => {
    try { localStorage.setItem(DISMISS_KEY, String(Date.now())); } catch { /* ignorar */ }
    track('pwa_install_dismiss');
    setHidden(true);
  };
  const install = async () => {
    track('pwa_install_click', { platform: prompt ? 'android_desktop' : 'ios' });
    if (!prompt) { setIosHelp((value) => !value); return; }
    prompt.prompt();
    const choice = await prompt.userChoice.catch(() => null);
    track('pwa_install_result', { outcome: choice?.outcome || 'unknown' });
    setPrompt(null);
    setHidden(true);
  };

  return (
    <aside className='install-banner' aria-label='Instalar la app de Tu Tiendita'>
      <button type='button' className='install-banner-close' onClick={close} aria-label='Cerrar aviso'><X size={16} aria-hidden='true' /></button>
      <div className='install-banner-text'><strong>Lleva Tu Tiendita en tu celular</strong><span>Compra más rápido, sin abrir el navegador.</span></div>
      <button type='button' className='install-banner-btn' onClick={install}><Download size={16} aria-hidden='true' /> Instalar app</button>
      {iosHelp && <p className='install-banner-help'>En iPhone: toca Compartir y luego «Añadir a pantalla de inicio».</p>}
    </aside>
  );
}
