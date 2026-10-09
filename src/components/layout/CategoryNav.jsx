import { useCallback, useEffect, useRef, useState } from 'react';
import { ChevronLeft, ChevronRight, Package } from 'lucide-react';
import { categoryIcons } from '../../data/categories';

const SCROLL_STEP = 320;

export function CategoryNav({ categories, active, onSelect }) {
  const containerRef = useRef(null); const activeRef = useRef(null); const [limits, setLimits] = useState({ left: false, right: false });
  const updateLimits = useCallback(() => { const element = containerRef.current; if (!element) return; const remaining = element.scrollWidth - element.clientWidth - element.scrollLeft; setLimits({ left: element.scrollLeft > 1, right: remaining > 1 }); }, []);
  useEffect(() => { updateLimits(); const element = containerRef.current; if (!element) return undefined; const observer = typeof ResizeObserver === 'undefined' ? null : new ResizeObserver(updateLimits); observer?.observe(element); window.addEventListener('resize', updateLimits); return () => { observer?.disconnect(); window.removeEventListener('resize', updateLimits); }; }, [categories, updateLimits]);
  useEffect(() => { activeRef.current?.scrollIntoView({ behavior: 'smooth', inline: 'center', block: 'nearest' }); requestAnimationFrame(updateLimits); }, [active, updateLimits]);
  const scroll = (left) => containerRef.current?.scrollBy({ left, behavior: 'smooth' });
  return <nav className={`cat-nav${limits.left ? ' has-left-overflow' : ''}${limits.right ? ' has-right-overflow' : ''}`} aria-label='Categorías'><button type='button' className='cat-nav-arrow cat-nav-arrow--left' aria-label='Ver categorías anteriores' onClick={() => scroll(-SCROLL_STEP)} tabIndex={limits.left ? 0 : -1}><ChevronLeft size={18} aria-hidden='true' /></button><div className='cat-nav-inner' ref={containerRef} onScroll={updateLimits}>{categories.map((category) => { const Icon = categoryIcons[category] || Package; const isActive = category === active; return <button key={category} ref={isActive ? activeRef : null} className={`cat-tab${isActive ? ' active' : ''}`} onClick={() => onSelect(category)}><Icon size={15} aria-hidden='true' /> {category}</button>; })}</div><button type='button' className='cat-nav-arrow cat-nav-arrow--right' aria-label='Ver más categorías' onClick={() => scroll(SCROLL_STEP)} tabIndex={limits.right ? 0 : -1}><ChevronRight size={18} aria-hidden='true' /></button></nav>;
}
