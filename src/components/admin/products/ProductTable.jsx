import { useState } from 'react';
import { ProductStatusBadge } from './ProductStatusBadge';

const priceFormatter = new Intl.NumberFormat('es-CO', { style: 'currency', currency: 'COP', maximumFractionDigits: 0 });

export function ProductTable({ products, onEdit, onToggleStatus, statusChangingId }) {
  return <div className='admin-product-table-wrap'>
    <table className='admin-product-table'>
      <thead><tr><th>Producto</th><th>Categoría</th><th>Subcategoría</th><th>Presentación</th><th>Precio</th><th>Inventario</th><th>Estado</th><th>Acciones</th></tr></thead>
      <tbody>{products.map((product) => <tr key={product.id}>
        <td><ProductIdentity product={product} /></td>
        <td>{product.category}</td><td>{product.subcategory}</td><td>{product.presentation}</td>
        <td className='admin-product-price'>{priceFormatter.format(product.price)}</td><td>{product.inventory}</td>
        <td><ProductStatusBadge active={product.active} /></td>
        <td><div className='admin-product-actions'><button type='button' onClick={() => onEdit(product)}>Editar</button><button type='button' className={product.active ? 'is-deactivate' : 'is-activate'} onClick={() => onToggleStatus(product)} disabled={statusChangingId === product.id}>{statusChangingId === product.id ? 'Guardando...' : product.active ? 'Desactivar' : 'Activar'}</button></div></td>
      </tr>)}</tbody>
    </table>
  </div>;
}

function ProductIdentity({ product }) {
  const [imageFailed, setImageFailed] = useState(false);
  const imagePath = product.image && (product.image.startsWith('http') || product.image.startsWith('/') ? product.image : `/${product.image}`);

  return <div className='admin-product-identity'>
    <span className='admin-product-thumbnail'>{imagePath && !imageFailed ? <img src={imagePath} alt='' loading='lazy' onError={() => setImageFailed(true)} /> : <span className='admin-product-thumbnail-fallback' aria-hidden='true' />}</span>
    <strong>{product.name}</strong>
  </div>;
}
