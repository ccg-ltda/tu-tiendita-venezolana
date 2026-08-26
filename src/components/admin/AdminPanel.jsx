import { useMemo, useState } from 'react';
import { formatPrice, normalizeText, unique } from '../../utils/catalog';

const emptyProduct = { name: '', pres: 'Unidad', cat: '', sub: '', price: 0, inventory: 0, image: '', active: true };

export function AdminPanel({ open, products, onSaveProduct, onAddProduct, onClose, onLogout, onReset }) {
  const [query, setQuery] = useState('');
  const [stockFilter, setStockFilter] = useState('all');
  const [activeSection, setActiveSection] = useState('products');
  const [editing, setEditing] = useState(null);
  const [showAdd, setShowAdd] = useState(false);
  const categories = useMemo(() => unique(products.map((product) => product.cat)), [products]);
  const filtered = products.filter((product) => {
    const matchesQuery = normalizeText(`${product.name} ${product.cat} ${product.sub}`).includes(normalizeText(query));
    const matchesStock = stockFilter === 'all'
      || (stockFilter === 'low' && product.inventory > 0 && product.inventory <= 5)
      || (stockFilter === 'out' && product.inventory <= 0)
      || (stockFilter === 'hidden' && product.active === false);
    return matchesQuery && matchesStock;
  });
  const stats = {
    active: products.filter((product) => product.active !== false).length,
    units: products.reduce((sum, product) => sum + Math.max(0, Number(product.inventory) || 0), 0),
    low: products.filter((product) => product.active !== false && product.inventory > 0 && product.inventory <= 5).length,
    out: products.filter((product) => product.active !== false && product.inventory <= 0).length,
  };
  if (!open) return null;

  const saveProduct = async (product) => {
    const clean = { ...product, price: Math.max(0, Number(product.price) || 0), inventory: Math.max(0, Math.floor(Number(product.inventory) || 0)) };
    try {
      await onSaveProduct(clean);
      setEditing(null);
    } catch (error) {
      window.alert(error.message);
    }
  };
  const addProduct = async (product) => {
    const clean = { ...product, price: Math.max(0, Number(product.price) || 0), inventory: Math.max(0, Math.floor(Number(product.inventory) || 0)) };
    try {
      await onAddProduct(clean);
      setShowAdd(false);
    } catch (error) {
      window.alert(error.message);
    }
  };
  const toggleProduct = async (id) => {
    const product = products.find((item) => item.id === id);
    try {
      await onSaveProduct({ ...product, active: product.active === false });
    } catch (error) {
      window.alert(error.message);
    }
  };
  const confirmReset = async () => {
    if (!window.confirm('¿Restaurar precios, productos e inventario inicial? Se perderán los cambios guardados.')) return;
    try {
      await onReset();
    } catch (error) {
      window.alert(error.message);
    }
  };
  const openSection = (section) => {
    setActiveSection(section);
    setStockFilter('all');
    setQuery('');
  };

  return (
    <div className='admin-panel-shell' role='dialog' aria-modal='true' aria-label='Panel administrativo'>
      <aside className='admin-sidebar'>
        <div className='admin-brand'><span>TT</span><div>Tu Tiendita<small>Administración</small></div></div>
        <nav><button className={activeSection === 'products' ? 'active' : ''} onClick={() => openSection('products')}>▦ Productos</button><button className={activeSection === 'inventory' ? 'active' : ''} onClick={() => openSection('inventory')}>◫ Inventario</button></nav>
        <div className='admin-side-bottom'><button onClick={confirmReset}>↻ Restaurar catálogo</button><button onClick={onLogout}>↪ Cerrar sesión</button></div>
      </aside>
      <main className='admin-content'>
        <header className='admin-topbar'><div><p className='admin-eyebrow'>Panel administrativo</p><h1>{activeSection === 'inventory' ? 'Control de inventario' : 'Productos y precios'}</h1></div><button className='admin-close-panel' onClick={onClose}>Volver a la tienda ×</button></header>
        <section className='admin-stats'>
          <Stat label='Productos activos' value={stats.active} icon='📦' />
          <Stat label='Unidades disponibles' value={stats.units} icon='▥' />
          <Stat label='Stock bajo' value={stats.low} icon='⚠' warning />
          <Stat label='Agotados' value={stats.out} icon='●' danger />
        </section>
        <section className='admin-table-card'>
          <div className='admin-toolbar'>
            <label className='admin-search'><span>⌕</span><input value={query} onChange={(event) => setQuery(event.target.value)} placeholder='Buscar por producto o categoría' /></label>
            <select value={stockFilter} onChange={(event) => setStockFilter(event.target.value)} aria-label='Filtrar inventario'><option value='all'>Todos</option><option value='low'>Stock bajo</option><option value='out'>Agotados</option><option value='hidden'>Ocultos</option></select>
            {activeSection === 'products' && <button className='admin-primary-btn compact' onClick={() => setShowAdd(true)}>+ Nuevo producto</button>}
          </div>
          <div className='admin-table-wrap'><table className='admin-table'><thead><tr><th>Producto</th><th>Categoría</th><th>Precio</th><th>Inventario</th><th>Estado</th><th></th></tr></thead><tbody>
            {filtered.map((product) => <tr key={product.id} className={product.active === false ? 'muted' : ''}><td><div className='admin-product'><div className='admin-thumb'>{product.image ? <img src={product.image} alt='' /> : '📦'}</div><div><strong>{product.name}</strong><small>{product.pres}</small></div></div></td><td><span className='category-pill'>{product.cat}</span><small className='table-sub'>{product.sub}</small></td><td className='price-cell'>{formatPrice(product.price)}</td><td><Stock product={product} /></td><td><button className={`status-toggle${product.active === false ? ' hidden' : ''}`} onClick={() => toggleProduct(product.id)}>{product.active === false ? 'Oculto' : 'Visible'}</button></td><td><button className='edit-btn' onClick={() => setEditing(product)}>Editar</button></td></tr>)}
            {!filtered.length && <tr><td colSpan='6' className='admin-empty'>No hay productos que coincidan con el filtro.</td></tr>}
          </tbody></table></div>
          <div className='admin-table-footer'>Mostrando {filtered.length} de {products.length} productos · Los cambios se guardan automáticamente en este dispositivo.</div>
        </section>
      </main>
      {(editing || showAdd) && <ProductEditor product={editing || { ...emptyProduct, cat: categories[0] || '' }} isNew={showAdd} categories={categories} onCancel={() => { setEditing(null); setShowAdd(false); }} onSave={showAdd ? addProduct : saveProduct} />}
    </div>
  );
}

function Stat({ label, value, icon, warning, danger }) {
  return <article className={`admin-stat${warning ? ' warning' : ''}${danger ? ' danger' : ''}`}><span>{icon}</span><div><strong>{value}</strong><small>{label}</small></div></article>;
}

function Stock({ product }) {
  const level = product.inventory <= 0 ? 'out' : product.inventory <= 5 ? 'low' : 'ok';
  return <div className={`stock-level ${level}`}><strong>{product.inventory}</strong><span>{level === 'out' ? 'Agotado' : level === 'low' ? 'Stock bajo' : 'Disponible'}</span></div>;
}

function ProductEditor({ product, isNew, categories, onCancel, onSave }) {
  const [draft, setDraft] = useState({ ...product });
  const update = (key) => (event) => setDraft((current) => ({ ...current, [key]: event.target.type === 'checkbox' ? event.target.checked : event.target.value }));
  const valid = draft.name.trim() && draft.cat.trim() && draft.sub.trim();
  return <div className='editor-overlay' onMouseDown={(event) => event.target === event.currentTarget && onCancel()}><form className='product-editor' onSubmit={(event) => { event.preventDefault(); if (valid) onSave(draft); }}><div className='editor-head'><div><p className='admin-eyebrow'>{isNew ? 'Agregar al catálogo' : `Producto #${draft.id}`}</p><h2>{isNew ? 'Nuevo producto' : 'Editar producto'}</h2></div><button type='button' onClick={onCancel}>×</button></div><div className='editor-grid'>
    <label className='admin-field full'><span>Nombre del producto</span><input value={draft.name} onChange={update('name')} required /></label>
    <label className='admin-field'><span>Presentación</span><input value={draft.pres} onChange={update('pres')} /></label>
    <label className='admin-field'><span>Categoría</span><input list='admin-categories' value={draft.cat} onChange={update('cat')} required /><datalist id='admin-categories'>{categories.map((category) => <option value={category} key={category} />)}</datalist></label>
    <label className='admin-field'><span>Subcategoría</span><input value={draft.sub} onChange={update('sub')} required /></label>
    <label className='admin-field'><span>Precio (COP)</span><input type='number' min='0' step='50' value={draft.price} onChange={update('price')} /></label>
    <label className='admin-field'><span>Unidades en inventario</span><input type='number' min='0' step='1' value={draft.inventory} onChange={update('inventory')} /></label>
    <label className='admin-field full'><span>Ruta o URL de imagen</span><input value={draft.image} onChange={update('image')} placeholder='assets/products/imagen.jpg' /></label>
    <label className='editor-check full'><input type='checkbox' checked={draft.active !== false} onChange={update('active')} /> Mostrar este producto en la tienda</label>
  </div><div className='editor-actions'><button type='button' className='admin-secondary-btn' onClick={onCancel}>Cancelar</button><button type='submit' className='admin-primary-btn' disabled={!valid}>Guardar cambios</button></div></form></div>;
}
