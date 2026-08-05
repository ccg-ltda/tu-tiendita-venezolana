import { categoryIcons } from '../../data/categories';

export function Sidebar({ category, subcategories, activeSub, counts, onSelect }) {
  return <aside className="sidebar"><div className="sidebar-sticky"><h4>{categoryIcons[category] || '📦'} {category}</h4><ul className="sub-list"><li className={`sub-item${activeSub === 'Todas' ? ' active' : ''}`} onClick={() => onSelect('Todas')}>Todos <span className="sub-count">{counts.all}</span></li>{subcategories.map((sub) => <li key={sub} className={`sub-item${activeSub === sub ? ' active' : ''}`} onClick={() => onSelect(sub)}>{sub} <span className="sub-count">{counts[sub]}</span></li>)}</ul></div></aside>;
}
