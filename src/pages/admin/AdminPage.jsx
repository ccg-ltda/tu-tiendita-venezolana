import { useEffect, useState } from 'react';
import { useNavigate } from 'react-router-dom';
import branding from '../../data/branding.json';
import { api } from '../../services/api';
import '../../styles/admin-dashboard.css';

const navigation = [
  { label: 'Dashboard', icon: 'dashboard', active: true },
  { label: 'Productos', icon: 'products' },
  { label: 'Pedidos', icon: 'orders' },
  { label: 'Inventario', icon: 'inventory' },
];

function Icon({ name, size = 20 }) {
  const common = { fill: 'none', stroke: 'currentColor', strokeWidth: 1.8, strokeLinecap: 'round', strokeLinejoin: 'round' };
  const paths = {
    dashboard: <><rect x='3.5' y='3.5' width='6.5' height='6.5' rx='1' /><rect x='14' y='3.5' width='6.5' height='6.5' rx='1' /><rect x='3.5' y='14' width='6.5' height='6.5' rx='1' /><rect x='14' y='14' width='6.5' height='6.5' rx='1' /></>,
    products: <><path d='m12 3.5 8 4.5-8 4.5L4 8l8-4.5Z' /><path d='M4 8v8l8 4.5 8-4.5V8M12 12.5V20' /></>,
    orders: <><path d='M7 4h10l2 3v13H5V7l2-3Z' /><path d='M5 8h14M9 12h6M9 16h4' /></>,
    inventory: <><path d='M4 7.5h16v13H4zM8 7.5V4h8v3.5M8 12h8M8 16h5' /></>,
    settings: <><circle cx='12' cy='12' r='3' /><path d='M19.4 15a1.7 1.7 0 0 0 .34 1.88l.06.06-2.12 2.12-.06-.06a1.7 1.7 0 0 0-1.88-.34 1.7 1.7 0 0 0-1.03 1.56v.08h-3v-.08a1.7 1.7 0 0 0-1.03-1.56A1.7 1.7 0 0 0 8.8 19l-.06.06-2.12-2.12.06-.06A1.7 1.7 0 0 0 7.02 15 1.7 1.7 0 0 0 5.46 14H5.4v-3h.06A1.7 1.7 0 0 0 7.02 10a1.7 1.7 0 0 0-.34-1.88l-.06-.06L8.74 5.94l.06.06A1.7 1.7 0 0 0 10.68 6.34 1.7 1.7 0 0 0 11.7 4.78V4.7h3v.08a1.7 1.7 0 0 0 1.03 1.56A1.7 1.7 0 0 0 17.62 6l.06-.06 2.12 2.12-.06.06A1.7 1.7 0 0 0 19.4 10a1.7 1.7 0 0 0 1.56 1h.08v3h-.08A1.7 1.7 0 0 0 19.4 15Z' /></>,
    menu: <><path d='M4 7h16M4 12h16M4 17h16' /></>, close: <><path d='m6 6 12 12M18 6 6 18' /></>,
    logout: <><path d='M10 5H5v14h5M14 8l4 4-4 4M9 12h9' /></>, arrow: <path d='M9 18l6-6-6-6' />,
    user: <><circle cx='12' cy='8' r='3.25' /><path d='M5.5 20c.7-3.25 3-5 6.5-5s5.8 1.75 6.5 5' /></>,
  };
  return <svg width={size} height={size} viewBox='0 0 24 24' aria-hidden='true' {...common}>{paths[name]}</svg>;
}

function AdminShellPlaceholder() {
  return <main className='admin-dashboard admin-dashboard--loading' aria-label='Panel administrativo'><aside className='admin-sidebar admin-sidebar--placeholder'><div className='admin-skeleton admin-skeleton--logo' /><div className='admin-skeleton admin-skeleton--nav' /><div className='admin-skeleton admin-skeleton--nav' /><div className='admin-skeleton admin-skeleton--nav' /></aside><section className='admin-loading-content'><div className='admin-skeleton admin-skeleton--headline' /><div className='admin-skeleton admin-skeleton--text' /><div className='admin-loading-cards'><div className='admin-skeleton admin-skeleton--card' /><div className='admin-skeleton admin-skeleton--card' /><div className='admin-skeleton admin-skeleton--card' /></div></section></main>;
}

export function AdminPage() {
  const navigate = useNavigate();
  const [user, setUser] = useState(null); const [loading, setLoading] = useState(true); const [error, setError] = useState(''); const [loggingOut, setLoggingOut] = useState(false); const [menuOpen, setMenuOpen] = useState(false);
  const logoSrc = `/${branding.logo.replace(/^\/+/, '')}`;
  useEffect(() => { const checkSession = async () => { try { const response = await api.me(); setUser(response.user); } catch (requestError) { if (requestError.status === 401 || requestError.status === 403) { navigate('/admin/login', { replace: true }); return; } setError('No se pudo verificar la sesión administrativa.'); } finally { setLoading(false); } }; checkSession(); }, [navigate]);
  useEffect(() => { const closeOnEscape = (event) => { if (event.key === 'Escape') setMenuOpen(false); }; window.addEventListener('keydown', closeOnEscape); return () => window.removeEventListener('keydown', closeOnEscape); }, []);
  const handleLogout = async () => { setLoggingOut(true); setError(''); try { await api.logout(); navigate('/admin/login', { replace: true }); } catch { setError('No fue posible cerrar la sesión.'); setLoggingOut(false); } };
  if (loading) return <AdminShellPlaceholder />;
  if (!user) return error ? <main className='admin-session-error'><p role='alert'>{error}</p></main> : null;
  const name = user.name || 'Administración'; const initials = name.split(' ').filter(Boolean).slice(0, 2).map((part) => part[0]).join('').toUpperCase(); const role = user.role || 'Administrador'; const closeMenu = () => setMenuOpen(false);
  return <main className='admin-dashboard'>
    <button className={`admin-drawer-overlay ${menuOpen ? 'is-open' : ''}`} type='button' aria-label='Cerrar menú' onClick={closeMenu} tabIndex={menuOpen ? 0 : -1} />
    <aside className={`admin-sidebar ${menuOpen ? 'is-open' : ''}`} aria-label='Navegación administrativa'><div className='admin-sidebar-brand'><span className='admin-sidebar-logo-surface'><img src={logoSrc} alt='' /></span><div><strong>Tu Tiendita<span> Venezolana</span></strong><small>Panel administrativo</small></div></div><nav className='admin-navigation'>{navigation.map((item) => <button key={item.label} type='button' className={`admin-nav-item ${item.active ? 'is-active' : ''}`} onClick={closeMenu} aria-current={item.active ? 'page' : undefined}><Icon name={item.icon} /><span>{item.label}</span>{!item.active && <small>Próximamente</small>}</button>)}</nav><div className='admin-sidebar-bottom'><button type='button' className='admin-nav-item' onClick={closeMenu}><Icon name='settings' /><span>Configuración</span><small>Próximamente</small></button></div></aside>
    <section className='admin-workspace'><header className='admin-topbar'><button className='admin-menu-button' type='button' onClick={() => setMenuOpen(true)} aria-label='Abrir menú' aria-expanded={menuOpen}><Icon name='menu' /></button><div className='admin-greeting'><p>Buenos días, <strong>{name}</strong></p><span>{user.email}</span></div><div className='admin-account'><div className='admin-avatar' aria-hidden='true'>{initials || 'A'}</div><button className='admin-logout' type='button' onClick={handleLogout} disabled={loggingOut}><Icon name='logout' size={18} /><span>{loggingOut ? 'Cerrando sesión...' : 'Cerrar sesión'}</span></button></div></header>
      <div className='admin-content'><div className='admin-page-heading'><div><p className='admin-eyebrow'>Vista general</p><h1>Dashboard</h1><p>Resumen general del panel administrativo.</p></div></div>{error && <p className='admin-inline-error' role='alert'>{error}</p>}
        <section className='admin-module-grid' aria-label='Módulos administrativos preparados'>{[['products', 'Productos', 'La gestión de productos estará disponible aquí.'], ['orders', 'Pedidos', 'El seguimiento de pedidos estará disponible aquí.'], ['inventory', 'Inventario', 'El control de inventario estará disponible aquí.']].map(([icon, title, text]) => <article className='admin-module-card' key={title}><div className='admin-module-icon'><Icon name={icon} /></div><div><h2>{title}</h2><p>{text}</p></div><span className='admin-status-pill'>Pendiente de migración</span></article>)}</section>
        <section className='admin-system-status' aria-labelledby='system-status-title'><div className='admin-section-heading'><div><p className='admin-eyebrow'>Información administrativa</p><h2 id='system-status-title'>Estado del sistema</h2></div><span className='admin-active-indicator'><i />Sesión activa</span></div><dl className='admin-status-list'><div><dt>Sesión administrativa</dt><dd><span className='admin-status-dot' />Activa</dd></div><div><dt>Usuario</dt><dd><Icon name='user' size={16} />{name}<small>{user.email}</small></dd></div><div><dt>Rol</dt><dd>{role}</dd></div></dl></section>
      </div></section>
  </main>;
}
