import { useEffect, useRef } from 'react';
import branding from '../../data/branding.json';
import { Link } from 'react-router-dom';
import { Package, Search, ShoppingBag } from 'lucide-react';

const SCROLL_DELAY_MS = 450;

/** Baja la página hasta los resultados (las fotos de los productos), dejando libre el encabezado fijo. */
function scrollToResults() {
  const target = document.querySelector('#catalogo .products-section') || document.getElementById('catalogo');
  if (!target) return;
  const stickyBottom = ['header', '.cat-nav'].reduce((bottom, selector) => {
    const element = document.querySelector(selector);
    if (!element) return bottom;
    const style = getComputedStyle(element);
    if (style.position !== 'sticky') return bottom;
    // Dónde quedará pegado al terminar de bajar (no dónde está ahora).
    return Math.max(bottom, (parseFloat(style.top) || 0) + element.offsetHeight);
  }, 0);
  const distance = target.getBoundingClientRect().top - stickyBottom - 12;
  if (Math.abs(distance) < 8) return;
  const reduceMotion = window.matchMedia?.('(prefers-reduced-motion: reduce)').matches;
  window.scrollTo({ top: window.scrollY + distance, behavior: reduceMotion ? 'auto' : 'smooth' });
}

export function Header({ cartCount, onCartOpen, search, onSearch, homeHref = '#inicio', showSearch = true }) {
  const logoSrc = `/${branding.logo.replace(/^\/+/, '')}`;
  const scrollTimer = useRef(null);

  useEffect(() => () => clearTimeout(scrollTimer.current), []);

  const changeSearch = (event) => {
    const value = event.target.value;
    onSearch(value);
    clearTimeout(scrollTimer.current);
    // Espera a que la persona deje de escribir para no mover la página en cada letra.
    if (value.trim()) scrollTimer.current = setTimeout(scrollToResults, SCROLL_DELAY_MS);
  };
  const submitSearch = (event) => {
    if (event.key !== 'Enter' || !search.trim()) return;
    event.preventDefault();
    clearTimeout(scrollTimer.current);
    event.currentTarget.blur(); // En el celular cierra el teclado para que se vean las fotos.
    scrollToResults();
  };

  return (
    <>
      <div className='announce'>
        <Package size={14} aria-hidden='true' /> ENVÍOS A TODA COLOMBIA · <b>Bogotá 24h hábiles</b> · 🇻🇪 El sabor de Venezuela, más cerca de ti
      </div>

      <header>
        <div className='header-inner'>
          <a className='logo-wrap' href={homeHref} aria-label='Tu Tiendita Venezolana, ir al inicio'>
            <div className='logo-mark'>
              <img src={logoSrc} alt='Tu Tiendita Venezolana' />
            </div>

            <div className='logo-text'>
              Tu Tiendita <span className='ven'>Venezolana</span>
              <small>El sabor de Venezuela</small>
            </div>
          </a>

          {showSearch && <label className='search-wrap'>
            <Search className='search-icon' size={15} aria-hidden='true' />
            <input
              value={search}
              enterKeyHint='search'
              onChange={changeSearch}
              onKeyDown={submitSearch}
              aria-label='Buscar productos'
              placeholder='Buscar productos venezolanos...'
            />
          </label>}

          <div className='header-actions'>
            <Link className='cart-btn' to='/blogs'>Blog</Link>
            <button className='cart-btn' onClick={onCartOpen}>
              <ShoppingBag size={18} aria-hidden='true' /> Carrito <span className='cart-count'>{cartCount}</span>
            </button>
          </div>
        </div>
      </header>
    </>
  );
}
