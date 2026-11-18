import { useEffect, useState } from 'react';
import { Outlet, useLocation, useNavigate } from 'react-router-dom';
import branding from '../../data/branding.json';
import { useAdminData } from '../../context/AdminDataContext';
import '../../styles/admin-dashboard.css';
import { AdminInsightsDashboard } from './insights/AdminInsights';

const ALL_ROLES = ['ADMIN', 'DESPACHO', 'ENTREGA'];
// `roles`: quién ve cada opción. DESPACHO y ENTREGA solo trabajan con «Hoy» y «Pedidos».
const navigation = [
  { label: 'Hoy', icon: 'today', path: '/admin/hoy', roles: ALL_ROLES },
  { label: 'Pedido manual', icon: 'plus', path: '/admin/manual', roles: ['ADMIN'] },
  { label: 'Dashboard', icon: 'dashboard', path: '/admin', roles: ['ADMIN'] },
  { label: 'Productos', icon: 'products', path: '/admin/products' , roles: ['ADMIN'] },
  { label: 'Promociones', icon: 'promotions', path: '/admin/promotions' , roles: ['ADMIN'] },
  { label: 'Cupones', icon: 'promotions', path: '/admin/coupons' , roles: ['ADMIN'] },
  { label: 'Pedidos', icon: 'orders', path: '/admin/orders' , roles: ALL_ROLES },
  { label: 'Clientes', icon: 'customers', path: '/admin/customers' , roles: ['ADMIN'] },
  { label: 'WhatsApp', icon: 'whatsapp', path: '/admin/whatsapp' , roles: ['ADMIN'] },
  { label: 'Historial', icon: 'activity', path: '/admin/activity' , roles: ['ADMIN'] },
];

function Icon({ name, size = 20 }) {
  const common = { fill: 'none', stroke: 'currentColor', strokeWidth: 1.8, strokeLinecap: 'round', strokeLinejoin: 'round' };
  const paths = {
    install: <><path d='M12 4v10M8 10l4 4 4-4M5 19h14' /></>,
    plus: <><path d='M12 5v14M5 12h14' /></>,
    today: <><circle cx='12' cy='12' r='8.5' /><path d='M12 7.5V12l3 2' /></>,
    dashboard: <><rect x='3.5' y='3.5' width='6.5' height='6.5' rx='1' /><rect x='14' y='3.5' width='6.5' height='6.5' rx='1' /><rect x='3.5' y='14' width='6.5' height='6.5' rx='1' /><rect x='14' y='14' width='6.5' height='6.5' rx='1' /></>,
    products: <><path d='m12 3.5 8 4.5-8 4.5L4 8l8-4.5Z' /><path d='M4 8v8l8 4.5 8-4.5V8M12 12.5V20' /></>,
    promotions: <><path d='M12 3.5 14 8l4.5.5-3.35 3 1 4.5L12 13.7 7.85 16l1-4.5-3.35-3L10 8l2-4.5Z' /><path d='M4 20h16' /></>,
    orders: <><path d='M7 4h10l2 3v13H5V7l2-3Z' /><path d='M5 8h14M9 12h6M9 16h4' /></>,
    activity: <><path d='M4 12a8 8 0 1 0 2.35-5.65L4 8.7' /><path d='M4 4.5v4.2h4.2M12 7.5V12l3.2 2' /></>,
    inventory: <><path d='M4 7.5h16v13H4zM8 7.5V4h8v3.5M8 12h8M8 16h5' /></>,
    settings: <><circle cx='12' cy='12' r='3' /><path d='M19.4 15a1.7 1.7 0 0 0 .34 1.88l.06.06-2.12 2.12-.06-.06a1.7 1.7 0 0 0-1.88-.34 1.7 1.7 0 0 0-1.03 1.56v.08h-3v-.08a1.7 1.7 0 0 0-1.03-1.56A1.7 1.7 0 0 0 8.8 19l-.06.06-2.12-2.12.06-.06A1.7 1.7 0 0 0 7.02 15 1.7 1.7 0 0 0 5.46 14H5.4v-3h.06A1.7 1.7 0 0 0 7.02 10a1.7 1.7 0 0 0-.34-1.88l-.06-.06L8.74 5.94l.06.06A1.7 1.7 0 0 0 10.68 6.34 1.7 1.7 0 0 0 11.7 4.78V4.7h3v.08a1.7 1.7 0 0 0 1.03 1.56A1.7 1.7 0 0 0 17.62 6l.06-.06 2.12 2.12-.06.06A1.7 1.7 0 0 0 19.4 10 1.7 1.7 0 0 0 20.96 11h.08v3h-.08A1.7 1.7 0 0 0 19.4 15Z' /></>,
    customers: <><circle cx='9' cy='8' r='3' /><path d='M3.5 19c.6-3 2.7-4.5 5.5-4.5s4.9 1.5 5.5 4.5M16 5.5a3 3 0 0 1 0 5.5M17.5 14.7c1.6.6 2.6 2 3 4.3' /></>,
    whatsapp: <><path d='M4 20l1.3-4A8 8 0 1 1 8.6 19L4 20Z' /><path d='M9.2 8.6c.2 2.6 2.6 5 5.2 5.2l1-1.3-1.8-.9-.8.8a4 4 0 0 1-2-2l.8-.8-.9-1.8-1.3 1Z' /></>,
    menu: <><path d='M4 7h16M4 12h16M4 17h16' /></>,
    logout: <><path d='M10 5H5v14h5M14 8l4 4-4 4M9 12h9' /></>,
    user: <><circle cx='12' cy='8' r='3.25' /><path d='M5.5 20c.7-3.25 3-5 6.5-5s5.8 1.75 6.5 5' /></>,
  };

  return <svg width={size} height={size} viewBox='0 0 24 24' aria-hidden='true' {...common}>{paths[name]}</svg>;
}

function AdminShellPlaceholder() {
  return <main className='admin-dashboard admin-dashboard--loading' aria-label='Panel administrativo'><aside className='admin-sidebar admin-sidebar--placeholder'><div className='admin-skeleton admin-skeleton--logo' /><div className='admin-skeleton admin-skeleton--nav' /><div className='admin-skeleton admin-skeleton--nav' /><div className='admin-skeleton admin-skeleton--nav' /></aside><section className='admin-loading-content'><div className='admin-skeleton admin-skeleton--headline' /><div className='admin-skeleton admin-skeleton--text' /><div className='admin-loading-cards'><div className='admin-skeleton admin-skeleton--card' /><div className='admin-skeleton admin-skeleton--card' /><div className='admin-skeleton admin-skeleton--card' /></div></section></main>;
}

export function AdminLayout() {
  const navigate = useNavigate();
  const location = useLocation();
  const { user, sessionLoading, sessionError, logout } = useAdminData();
  const [loggingOut, setLoggingOut] = useState(false);
  const [menuOpen, setMenuOpen] = useState(false);
  const logoSrc = `/${branding.logo.replace(/^\/+/, '')}`;

  useEffect(() => {
    const closeOnEscape = (event) => { if (event.key === 'Escape') setMenuOpen(false); };
    window.addEventListener('keydown', closeOnEscape);

    return () => window.removeEventListener('keydown', closeOnEscape);
  }, []);

  useEffect(() => {
    if (!menuOpen) return undefined;

    const previousOverflow = document.body.style.overflow;
    document.body.style.overflow = 'hidden';

    return () => { document.body.style.overflow = previousOverflow; };
  }, [menuOpen]);

  useEffect(() => {
    setMenuOpen(false);
  }, [location.pathname]);

  const [installPrompt, setInstallPrompt] = useState(null);
  const [iosHint, setIosHint] = useState(false);
  const isIos = typeof navigator !== 'undefined' && /iphone|ipad|ipod/i.test(navigator.userAgent);
  const isInstalled = typeof window !== 'undefined' && (window.matchMedia?.('(display-mode: standalone)').matches || navigator.standalone === true);
  useEffect(() => {
    const onPrompt = (event) => { event.preventDefault(); setInstallPrompt(event); };
    const onInstalled = () => setInstallPrompt(null);
    window.addEventListener('beforeinstallprompt', onPrompt);
    window.addEventListener('appinstalled', onInstalled);
    return () => { window.removeEventListener('beforeinstallprompt', onPrompt); window.removeEventListener('appinstalled', onInstalled); };
  }, []);
  const installApp = async () => {
    if (installPrompt) { installPrompt.prompt(); await installPrompt.userChoice.catch(() => null); setInstallPrompt(null); } else setIosHint((value) => !value);
  };

  const role = user?.role || '';
  const allowedPaths = navigation.filter((item) => item.roles.includes(role)).map((item) => item.path);

  // Instalable como app (solo en el panel): manifiesto + service worker mínimo.
  useEffect(() => {
    const link = document.createElement('link');
    link.rel = 'manifest';
    link.href = '/panel-app/manifest.webmanifest';
    document.head.appendChild(link);
    const theme = document.createElement('meta');
    theme.name = 'theme-color';
    theme.content = '#0f2350';
    document.head.appendChild(theme);
    if ('serviceWorker' in navigator) navigator.serviceWorker.register('/admin-sw.js', { scope: '/admin' }).catch(() => {});

    return () => { link.remove(); theme.remove(); };
  }, []);

  // Quien no es ADMIN solo puede estar en sus pantallas.
  useEffect(() => {
    if (!user || role === 'ADMIN') return;
    if (!allowedPaths.includes(location.pathname)) navigate('/admin/hoy', { replace: true });
  }, [allowedPaths, location.pathname, navigate, role, user]);

  const handleLogout = async () => {
    setLoggingOut(true);
    try {
      await logout();
    } finally {
      setLoggingOut(false);
    }
  };

  // A background session refresh must not replace an already usable admin
  // layout. The global placeholder is reserved for the first session check.
  if (sessionLoading && !user) return <AdminShellPlaceholder />;
  if (!user) return sessionError ? <main className='admin-session-error'><p role='alert'>{sessionError}</p></main> : null;

  const name = user.name || 'Administracion';
  const initials = name.split(' ').filter(Boolean).slice(0, 2).map((part) => part[0]).join('').toUpperCase();
  const isDashboard = location.pathname === '/admin';
  const visibleNav = navigation.filter((item) => item.roles.includes(role));
  const roleLabel = { ADMIN: 'Administrador', DESPACHO: 'Despacho y entrega', ENTREGA: 'Entrega' }[role] || role;

  return <main className='admin-dashboard'>
    <button className={`admin-drawer-overlay ${menuOpen ? 'is-open' : ''}`} type='button' aria-label='Cerrar menu' onClick={() => setMenuOpen(false)} tabIndex={menuOpen ? 0 : -1} />
    <aside className={`admin-sidebar ${menuOpen ? 'is-open' : ''}`} aria-label='Navegacion administrativa'><div className='admin-sidebar-brand'><span className='admin-sidebar-logo-surface'><img src={logoSrc} alt='' /></span><div><strong>Tu Tiendita<span> Venezolana</span></strong><small>Panel administrativo</small></div><button className='admin-sidebar-close' type='button' onClick={() => setMenuOpen(false)} aria-label='Cerrar menu'>×</button></div><nav className='admin-navigation'>{visibleNav.map((item) => { const active = item.path === location.pathname; return <button key={item.label} type='button' className={`admin-nav-item ${active ? 'is-active' : ''}`} onClick={() => { setMenuOpen(false); if (item.path) navigate(item.path); }} aria-current={active ? 'page' : undefined}><Icon name={item.icon} /><span>{item.label}</span>{!item.path && <small>Proximamente</small>}</button>; })}</nav><div className='admin-sidebar-bottom'><p className='admin-sidebar-who'>{name} · {roleLabel}</p>{(installPrompt || isIos) && !isInstalled && <button type='button' className='admin-install' onClick={installApp}><Icon name='install' size={18} /><span>Instalar app</span></button>}{iosHint && <p className='admin-install-hint'>En iPhone: toca Compartir y luego «Añadir a pantalla de inicio».</p>}<button type='button' className='admin-sidebar-logout' onClick={handleLogout} disabled={loggingOut}><Icon name='logout' size={18} /><span>{loggingOut ? 'Cerrando sesion...' : 'Cerrar sesion'}</span></button></div></aside>
    <section className='admin-workspace'><header className={`admin-topbar${isDashboard ? '' : ' admin-topbar--module'}`}><button className='admin-menu-button' type='button' onClick={() => setMenuOpen(true)} aria-label='Abrir menu' aria-expanded={menuOpen}><Icon name='menu' /></button>{isDashboard && <div className='admin-greeting'><p>Buenos dias, <strong>{name}</strong></p><span>{user.username}</span></div>}<div className='admin-account'><div className='admin-avatar' aria-hidden='true'>{initials || 'A'}</div><button className='admin-logout' type='button' onClick={handleLogout} disabled={loggingOut}><Icon name='logout' size={18} /><span>{loggingOut ? 'Cerrando sesion...' : 'Cerrar sesion'}</span></button></div></header><Outlet /></section>
    <nav className='admin-bottom-nav' aria-label='Navegacion principal'>{['/admin/hoy', '/admin/orders', '/admin/products', '/admin'].map((path) => visibleNav.find((entry) => entry.path === path)).filter(Boolean).map((item) => { const active = item.path === location.pathname; return <button key={item.label} type='button' className={active ? 'is-active' : ''} onClick={() => navigate(item.path)} aria-current={active ? 'page' : undefined}><Icon name={item.icon} /><span>{item.label}</span></button>; })}</nav>
  </main>;
}

export function AdminDashboardPage() {
  const navigate = useNavigate();
  const { user } = useAdminData();
  useEffect(() => { if (user && user.role !== 'ADMIN') navigate('/admin/hoy', { replace: true }); }, [navigate, user]);
  const role = { ADMIN: 'Administrador', DESPACHO: 'Despacho y entrega', ENTREGA: 'Entrega' }[user?.role] || user?.role || 'Administrador';

  return <div className='admin-content admin-dash-compact'><AdminInsightsDashboard /><p className='admin-dash-session'><span className='admin-status-dot' />Sesión activa · {user?.name || 'Administración'} <small>{user?.username}</small> · {role}</p></div>;
}
