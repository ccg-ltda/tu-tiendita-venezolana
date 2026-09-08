export function ProductStatusBadge({ active }) {
  return <span className={`admin-product-status ${active ? 'is-active' : 'is-inactive'}`}>{active ? 'Activo' : 'Inactivo'}</span>;
}
