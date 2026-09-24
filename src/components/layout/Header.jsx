import branding from '../../data/branding.json';
import { Package, Search, ShoppingBag } from 'lucide-react';

export function Header({ cartCount, onCartOpen, search, onSearch }) {
  return (
    <>
      <div className='announce'>
        <Package size={14} aria-hidden='true' /> ENVÍOS A TODA COLOMBIA · <b>Bogotá 24h hábiles</b> · 🇻🇪 El sabor de Venezuela, más cerca de ti
      </div>

      <header>
        <div className='header-inner'>
          <a className='logo-wrap' href='#inicio' aria-label='Ir al inicio'>
            <div className='logo-mark'>
              <img src={branding.logo} alt='Tu Tiendita Venezolana' />
            </div>

            <div className='logo-text'>
              Tu Tiendita <span className='ven'>Venezolana</span>
              <small>El sabor de Venezuela</small>
            </div>
          </a>

          <label className='search-wrap'>
            <Search className='search-icon' size={15} aria-hidden='true' />
            <input
              value={search}
              onChange={(event) => onSearch(event.target.value)}
              placeholder='Buscar productos venezolanos...'
            />
          </label>

          <div className='header-actions'>
            <button className='cart-btn' onClick={onCartOpen}>
              <ShoppingBag size={18} aria-hidden='true' /> Carrito <span className='cart-count'>{cartCount}</span>
            </button>
          </div>
        </div>
      </header>
    </>
  );
}
