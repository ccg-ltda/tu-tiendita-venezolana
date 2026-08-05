import { categoryIcons } from '../../data/categories';

export function CategoryNav({ categories, active, onSelect }) {
  return (
    <nav className="cat-nav" aria-label="Categorías">
      <div className="cat-nav-inner">
        {categories.map((category) => (
          <button key={category} className={`cat-tab${category === active ? ' active' : ''}`} onClick={() => onSelect(category)}>
            {categoryIcons[category] || '📦'} {category}
          </button>
        ))}
      </div>
    </nav>
  );
}
