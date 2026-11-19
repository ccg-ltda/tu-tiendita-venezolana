import { useEffect, useRef, useState } from 'react';
import { Link, useLocation, useNavigate } from 'react-router-dom';
import { LayoutGrid, Package, X } from 'lucide-react';
import { categoryIcons } from '../../data/categories';
import { api } from '../../services/api';
import { buildCategories } from '../../utils/catalog';
import { track } from '../../utils/track';

/**
 * Botón «Categorías» del encabezado: abre cuadros con todas las categorías.
 * categories: {name, slug}[] (si la página ya las tiene). Si no llegan, se cargan al abrir por primera vez.
 * onPromotions: qué hacer con «Promociones» (no tiene página propia); si falta, lleva al inicio.
 */
export function CategoriesMenu({ categories: given, onPromotions }) {
  const [open, setOpen] = useState(false);
  const [loaded, setLoaded] = useState(null);
  const rootRef = useRef(null);
  const location = useLocation();
  const navigate = useNavigate();
  const categories = given?.length ? given : loaded || [];

  useEffect(() => { setOpen(false); }, [location.pathname]);

  useEffect(() => {
    if (!open) return undefined;
    const onKey = (event) => { if (event.key === 'Escape') setOpen(false); };
    const onClick = (event) => { if (rootRef.current && !rootRef.current.contains(event.target)) setOpen(false); };
    document.addEventListener('keydown', onKey);
    document.addEventListener('mousedown', onClick);
    return () => { document.removeEventListener('keydown', onKey); document.removeEventListener('mousedown', onClick); };
  }, [open]);

  const toggle = async () => {
    const next = !open;
    setOpen(next);
    if (!next) return;
    track('categories_menu_open');
    if (!given?.length && !loaded) {
      try { const result = await api.listProducts(); setLoaded(buildCategories(result.products, { includePromotions: false })); } catch { setLoaded([]); }
    }
  };

  const choose = (category) => {
    track('category_menu_select', { category_name: category.name });
    setOpen(false);
    if (!category.slug) { if (onPromotions) onPromotions(category.name); else navigate('/'); }
  };

  return (
    <div className='cat-menu' ref={rootRef}>
      <button type='button' className='cart-btn' onClick={toggle} aria-expanded={open} aria-haspopup='true' aria-controls='cat-menu-panel'>
        <LayoutGrid size={18} aria-hidden='true' /> Categorías
      </button>
      {open && (
        <div className='cat-menu-panel' id='cat-menu-panel' role='region' aria-label='Todas las categorías'>
          <div className='cat-menu-head'><strong>Todas las categorías</strong><button type='button' className='cat-menu-close' onClick={() => setOpen(false)} aria-label='Cerrar categorías'><X size={18} aria-hidden='true' /></button></div>
          {categories.length === 0 ? <p className='cat-menu-empty'>Cargando categorías…</p> : (
            <ul className='cat-menu-grid'>
              {categories.map((category) => {
                const Icon = categoryIcons[category.name] || Package;
                const inner = <><span className='cat-menu-icon'><Icon size={22} aria-hidden='true' /></span><span>{category.name}</span></>;
                return (
                  <li key={category.name}>
                    {category.slug
                      ? <Link className='cat-menu-tile' to={`/categoria/${category.slug}`} onClick={() => choose(category)}>{inner}</Link>
                      : <button type='button' className='cat-menu-tile' onClick={() => choose(category)}>{inner}</button>}
                  </li>
                );
              })}
            </ul>
          )}
        </div>
      )}
    </div>
  );
}
