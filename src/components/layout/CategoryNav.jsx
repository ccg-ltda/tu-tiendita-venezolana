import { categoryIcons } from '../../data/categories';
import { Package } from 'lucide-react';

export function CategoryNav({ categories, active, onSelect }) {
  return (
    <nav className="cat-nav" aria-label="Categorías">
      <div className="cat-nav-inner">
        {categories.map((category) => {
          const Icon = categoryIcons[category] || Package;
          return <button key={category} className={`cat-tab${category === active ? ' active' : ''}`} onClick={() => onSelect(category)}>
            <Icon size={15} aria-hidden='true' /> {category}
          </button>;
        })}
      </div>
    </nav>
  );
}
