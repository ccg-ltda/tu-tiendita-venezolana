import branding from '../../data/branding.json';

export function Header({ cartCount, onCartOpen, search, onSearch, isAdmin, onAdmin }) {
  return (
    <>
      <div className='announce'>📦 ENVÍOS A TODA COLOMBIA · <b>Bogotá 24h hábiles</b> · 🇻🇪 El sabor de Venezuela, más cerca de ti</div>
      <header>
        <div className='header-inner'>
          <a className='logo-wrap' href='#inicio' aria-label='Ir al inicio'>
            <div className='logo-mark'><img src={branding.logo} alt='Tu Tiendita Venezolana' /></div>
            <div className='logo-text'>Tu Tiendita <span className='ven'>Venezolana</span><small>El sabor de Venezuela</small></div>
          </a>
          <label className='search-wrap'>
            <span className='search-icon'>🔍</span>
            <input value={search} onChange={(event) => onSearch(event.target.value)} placeholder='Buscar productos venezolanos...' />
          </label>
          <div className='header-actions'>
            <button className={`admin-access-btn${isAdmin ? ' active' : ''}`} onClick={onAdmin} aria-label={isAdmin ? 'Abrir panel administrativo' : 'Iniciar sesión como administrador'}>
              <span aria-hidden='true'>{isAdmin ? '⚙️' : '👤'}</span>
              <span>{isAdmin ? 'Administrar' : 'Ingresar'}<small>{isAdmin ? 'Panel de tienda' : 'Administrador'}</small></span>
            </button>
            <button className='cart-btn' onClick={onCartOpen}>🛍️ Carrito <span className='cart-count'>{cartCount}</span></button>
          </div>
        </div>
      </header>
    </>
  );
}
