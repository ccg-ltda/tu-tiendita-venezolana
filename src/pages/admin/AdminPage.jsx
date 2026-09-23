import { useEffect, useState } from 'react';
import { Outlet, useLocation, useNavigate } from 'react-router-dom';
import branding from '../../data/branding.json';
import { useAdminData } from '../../context/AdminDataContext';
import '../../styles/admin-dashboard.css';

const navigation = [
  { label: 'Dashboard', icon: 'dashboard', path: '/admin' },
  { label: 'Productos', icon: 'products', path: '/admin/products' },
  { label: 'Pedidos', icon: 'orders', path: '/admin/orders' },
];

function Icon({ name, size = 20 }) {
  const common = { fill: 'none', stroke: 'currentColor', strokeWidth: 1.8, strokeLinecap: 'round', strokeLinejoin: 'round' };
  const paths = {
    dashboard: <><rect x='3.5' y='3.5' width='6.5' height='6.5' rx='1' /><rect x='14' y='3.5' width='6.5' height='6.5' rx='1' /><rect x='3.5' y='14' width='6.5' height='6.5' rx='1' /><rect x='14' y='14' width='6.5' height='6.5' rx='1' /></>,
    products: <><path d='m12 3.5 8 4.5-8 4.5L4 8l8-4.5Z' /><path d='M4 8v8l8 4.5 8-4.5V8M12 12.5V20' /></>,
    orders: <><path d='M7 4h10l2 3v13H5V7l2-3Z' /><path d='M5 8h14M9 12h6M9 16h4' /></>,
    inventory: <><path d='M4 7.5h16v13H4zM8 7.5V4h8v3.5M8 12h8M8 16h5' /></>,
    settings: <><circle cx='12' cy='12' r='3' /><path d='M19.4 15a1.7 1.7 0 0 0 .34 1.88l.06.06-2.12 2.12-.06-.06a1.7 1.7 0 0 0-1.88-.34 1.7 1.7 0 0 0-1.03 1.56v.08h-3v-.08a1.7 1.7 0 0 0-1.03-1.56A1.7 1.7 0 0 0 8.8 19l-.06.06-2.12-2.12.06-.06A1.7 1.7 0 0 0 7.02 15 1.7 1.7 0 0 0 5.46 14H5.4v-3h.06A1.7 1.7 0 0 0 7.02 10a1.7 1.7 0 0 0-.34-1.88l-.06-.06L8.74 5.94l.06.06A1.7 1.7 0 0 0 10.68 6.34 1.7 1.7 0 0 0 11.7 4.78V4.7h3v.08a1.7 1.7 0 0 0 1.03 1.56A1.7 1.7 0 0 0 17.62 6l.06-.06 2.12 2.12-.06.06A1.7 1.7 0 0 0 19.4 10 1.7 1.7 0 0 0 20.96 11h.08v3h-.08A1.7 1.7 0 0 0 19.4 15Z' /></>,
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

  const handleLogout = async () => {
    setLoggingOut(true);
    try {
      await logout();
    } finally {
      setLoggingOut(false);
    }
  };

  if (sessionLoading) return <AdminShellPlaceholder />;
  if (!user) return sessionError ? <main className='admin-session-error'><p role='alert'>{sessionError}</p></main> : null;

  const name = user.name || 'Administracion';
  const initials = name.split(' ').filter(Boolean).slice(0, 2).map((part) => part[0]).join('').toUpperCase();

  return <main className='admin-dashboard'>
    <button className={`admin-drawer-overlay ${menuOpen ? 'is-open' : ''}`} type='button' aria-label='Cerrar menu' onClick={() => setMenuOpen(false)} tabIndex={menuOpen ? 0 : -1} />
    <aside className={`admin-sidebar ${menuOpen ? 'is-open' : ''}`} aria-label='Navegacion administrativa'><div className='admin-sidebar-brand'><span className='admin-sidebar-logo-surface'><img src={logoSrc} alt='' /></span><div><strong>Tu Tiendita<span> Venezolana</span></strong><small>Panel administrativo</small></div></div><nav className='admin-navigation'>{navigation.map((item) => { const active = item.path === location.pathname; return <button key={item.label} type='button' className={`admin-nav-item ${active ? 'is-active' : ''}`} onClick={() => { setMenuOpen(false); if (item.path) navigate(item.path); }} aria-current={active ? 'page' : undefined}><Icon name={item.icon} /><span>{item.label}</span>{!item.path && <small>Proximamente</small>}</button>; })}</nav><div className='admin-sidebar-bottom'><button type='button' className='admin-nav-item' onClick={() => setMenuOpen(false)}><Icon name='settings' /><span>Configuracion</span><small>Proximamente</small></button></div></aside>
    <section className='admin-workspace'><header className='admin-topbar'><button className='admin-menu-button' type='button' onClick={() => setMenuOpen(true)} aria-label='Abrir menu' aria-expanded={menuOpen}><Icon name='menu' /></button><div className='admin-greeting'><p>Buenos dias, <strong>{name}</strong></p><span>{user.email}</span></div><div className='admin-account'><div className='admin-avatar' aria-hidden='true'>{initials || 'A'}</div><button className='admin-logout' type='button' onClick={handleLogout} disabled={loggingOut}><Icon name='logout' size={18} /><span>{loggingOut ? 'Cerrando sesion...' : 'Cerrar sesion'}</span></button></div></header><Outlet /></section>
  </main>;
}

export function AdminDashboardPage() {
  const navigate = useNavigate();
  const { user } = useAdminData();
  const role = user?.role || 'Administrador';

  return <div className='admin-content'><div className='admin-page-heading'><div><p className='admin-eyebrow'>Vista general</p><h1>Dashboard</h1><p>Resumen general del panel administrativo.</p></div></div><section className='admin-module-grid' aria-label='Modulos administrativos preparados'><article className='admin-module-card'><div className='admin-module-icon'><Icon name='products' /></div><div><h2>Productos</h2><p>Consulta el catalogo administrativo.</p></div><button className='admin-module-link' type='button' onClick={() => navigate('/admin/products')}>Ver productos</button></article><article className='admin-module-card'><div className='admin-module-icon'><Icon name='orders' /></div><div><h2>Pedidos</h2><p>El seguimiento de pedidos estara disponible aqui.</p></div><button className='admin-module-link' type='button' onClick={() => navigate('/admin/orders')}>Ver pedidos</button></article></section><section className='admin-system-status' aria-labelledby='system-status-title'><div className='admin-section-heading'><div><p className='admin-eyebrow'>Informacion administrativa</p><h2 id='system-status-title'>Estado del sistema</h2></div><span className='admin-active-indicator'><i />Sesion activa</span></div><dl className='admin-status-list'><div><dt>Sesion administrativa</dt><dd><span className='admin-status-dot' />Activa</dd></div><div><dt>Usuario</dt><dd><Icon name='user' size={16} />{user?.name || 'Administracion'}<small>{user?.email}</small></dd></div><div><dt>Rol</dt><dd>{role}</dd></div></dl></section></div>;
}
