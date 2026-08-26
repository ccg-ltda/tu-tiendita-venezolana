async function request(path, options = {}) {
  const response = await fetch(path, {
    credentials: 'include',
    headers: { 'Content-Type': 'application/json', ...options.headers },
    ...options,
  });
  const body = response.status === 204 ? null : await response.json().catch(() => null);
  if (!response.ok) {
    const error = new Error(body?.error || 'No se pudo completar la solicitud.');
    error.status = response.status;
    throw error;
  }
  return body;
}

export const api = {
  listProducts: () => request('/api/products'),
  listAdminProducts: () => request('/api/admin/products'),
  session: () => request('/api/auth/session'),
  login: (credentials) => request('/api/auth/login', { method: 'POST', body: JSON.stringify(credentials) }),
  logout: () => request('/api/auth/logout', { method: 'POST' }),
  createProduct: (product) => request('/api/admin/products', { method: 'POST', body: JSON.stringify(product) }),
  updateProduct: (product) => request(`/api/admin/products/${product.id}`, { method: 'PUT', body: JSON.stringify(product) }),
  resetProducts: () => request('/api/admin/products/reset', { method: 'POST' }),
  createOrder: (order) => request('/api/orders', { method: 'POST', body: JSON.stringify(order) }),
};
