import { useEffect, useState } from 'react';
import { Link } from 'react-router-dom';
import { Download, Share, SquarePlus, CheckCircle2 } from 'lucide-react';
import branding from '../data/branding.json';
import { SeoHead } from '../components/common/SeoHead';
import { track } from '../utils/track';
import '../styles/install-page.css';

/** Configuración de cada app: la tienda (clientes) y el panel (equipo). Cada una tiene su propio manifiesto. */
const APPS = {
  tienda: {
    title: 'Instala Tu Tiendita en tu celular',
    lead: 'Compra tus productos venezolanos más rápido, sin abrir el navegador.',
    manifest: '/tienda-app/manifest.webmanifest',
    sw: { url: '/tienda-sw.js', scope: '/' },
    openHref: '/',
    openLabel: 'Ir a la tienda',
    other: { href: '/admin/instalar', label: '¿Eres del equipo? Instala el panel' },
    seo: { title: 'Instalar la app de Tu Tiendita Venezolana', robots: 'index,follow' },
  },
  panel: {
    title: 'Instala el panel de Tu Tiendita',
    lead: 'Pedidos, productos y clientes en tu celular, como una app. Solo para el equipo.',
    manifest: '/panel-app/manifest.webmanifest',
    sw: { url: '/admin-sw.js', scope: '/admin' },
    openHref: '/admin/login',
    openLabel: 'Entrar al panel',
    other: { href: '/instalar', label: 'Instalar la app de la tienda' },
    seo: { title: 'Instalar el panel | Tu Tiendita Venezolana', robots: 'noindex,nofollow' },
  },
};

const isStandalone = () => window.matchMedia?.('(display-mode: standalone)').matches || navigator.standalone === true;
const inAppBrowser = () => /FBAN|FBAV|Instagram|Line\/|Snapchat|TikTok|; wv\)/i.test(navigator.userAgent);

export function InstallPage({ app = 'tienda' }) {
  const config = APPS[app] || APPS.tienda;
  const logoSrc = `/${branding.logo.replace(/^\/+/, '')}`;
  const [prompt, setPrompt] = useState(null);
  const [installed, setInstalled] = useState(false);
  const [declined, setDeclined] = useState(false);
  const isIos = /iphone|ipad|ipod/i.test(navigator.userAgent);

  // Cada página instala SU app: se registra su manifiesto mientras se está aquí.
  useEffect(() => {
    const link = document.createElement('link');
    link.rel = 'manifest';
    link.href = config.manifest;
    document.head.appendChild(link);
    if ('serviceWorker' in navigator) navigator.serviceWorker.register(config.sw.url, { scope: config.sw.scope }).catch(() => {});
    return () => link.remove();
  }, [config]);

  useEffect(() => {
    setInstalled(isStandalone());
    const onPrompt = (event) => { event.preventDefault(); setPrompt(event); };
    const onInstalled = () => { setPrompt(null); setInstalled(true); track('pwa_installed', { app }); };
    window.addEventListener('beforeinstallprompt', onPrompt);
    window.addEventListener('appinstalled', onInstalled);
    track('pwa_install_page_view', { app });
    return () => { window.removeEventListener('beforeinstallprompt', onPrompt); window.removeEventListener('appinstalled', onInstalled); };
  }, [app]);

  const install = async () => {
    track('pwa_install_click', { app, platform: prompt ? 'android_desktop' : isIos ? 'ios' : 'manual' });
    if (!prompt) return;
    prompt.prompt();
    const choice = await prompt.userChoice.catch(() => null);
    track('pwa_install_result', { app, outcome: choice?.outcome || 'unknown' });
    if (choice?.outcome === 'dismissed') setDeclined(true);
    setPrompt(null);
  };

  return (
    <main className='install-page'>
      <SeoHead title={config.seo.title} robots={config.seo.robots} />
      <section className='install-card'>
        <img className='install-logo' src={logoSrc} alt='Tu Tiendita Venezolana' width='88' height='88' />
        <h1>{config.title}</h1>
        <p className='install-lead'>{config.lead}</p>

        {installed ? (
          <div className='install-done'>
            <CheckCircle2 size={28} aria-hidden='true' />
            <strong>Ya la tienes instalada</strong>
            <Link className='install-open' to={config.openHref}>{config.openLabel}</Link>
          </div>
        ) : (
          <>
            {prompt && <button type='button' className='install-main' onClick={install}><Download size={22} aria-hidden='true' /> Instalar app</button>}
            {declined && <p className='install-note'>No pasa nada. Cuando quieras, vuelve a tocar el botón.</p>}

            {!prompt && isIos && (
              <ol className='install-steps'>
                <li><span>1</span><div>Abre esta página en <b>Safari</b>.</div></li>
                <li><span>2</span><div>Toca <b>Compartir</b> <Share size={16} aria-hidden='true' /> (el cuadro con la flecha hacia arriba).</div></li>
                <li><span>3</span><div>Elige <b>Añadir a pantalla de inicio</b> <SquarePlus size={16} aria-hidden='true' />.</div></li>
              </ol>
            )}

            {!prompt && !isIos && (
              <div className='install-steps-wrap'>
                {inAppBrowser() && <p className='install-warning'>Estás en un navegador dentro de otra app. Abre este enlace en <b>Chrome</b> para poder instalar.</p>}
                <ol className='install-steps'>
                  <li><span>1</span><div>Abre esta página en <b>Chrome</b> (si vienes de WhatsApp, toca ⋮ y «Abrir en Chrome»).</div></li>
                  <li><span>2</span><div>Si no ves el botón <b>Instalar app</b>, toca el menú <b>⋮</b> de Chrome.</div></li>
                  <li><span>3</span><div>Elige <b>Instalar app</b> o <b>Añadir a pantalla de inicio</b>.</div></li>
                </ol>
              </div>
            )}

            <Link className='install-secondary' to={config.openHref}>{config.openLabel}</Link>
          </>
        )}

        <Link className='install-other' to={config.other.href}>{config.other.label}</Link>
      </section>
    </main>
  );
}
