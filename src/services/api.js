async function request(path, options = {}) {
  const API_URL = import.meta.env.VITE_API_URL || '';
  const { headers = {}, ...fetchOptions } = options;
  const isFormData = fetchOptions.body instanceof FormData;

  const response = await fetch(`${API_URL}${path}`, {
    credentials: 'include',
    ...fetchOptions,
    headers: {
      Accept: 'application/json',
      ...(fetchOptions.body && !isFormData && {
        'Content-Type': 'application/json',
      }),
      ...headers,
    },
  });

  const body = response.status === 204
    ? null
    : await response.json().catch(() => null);

  if (!response.ok) {
    const error = new Error(
      body?.message || body?.error || 'No se pudo completar la solicitud.',
    );

    error.status = response.status;
    error.code = body?.code ?? null;
    error.details = body?.errors ?? null;
    error.minimumOrderCop = body?.minimum_order_cop ?? null;

    throw error;
  }

  return body;
}



// Obtiene el token CSRF correspondiente a la sesión actual.
async function getCsrfToken() {
  const response = await request('/api/auth/csrf-token');

  return response.csrf_token;
}

export const api = {
  // Tienda pública: continúa temporalmente usando Express.
  listProducts: () => request('/api/products'),

  previewCoupon: ({ code, items }) => request('/api/checkout/coupon/preview', {
    method: 'POST',
    body: JSON.stringify({ code, items }),
  }),

  prepareWompiPayment: async ({ customer, items, couponCode = null }, idempotencyKey) => {
    if (!idempotencyKey) {
      throw new Error('Se requiere una Idempotency-Key para preparar el pago.');
    }

    const csrfToken = await getCsrfToken();

    return request('/api/payments/wompi/prepare', {
      method: 'POST',
      headers: {
        'X-CSRF-TOKEN': csrfToken,
        'Idempotency-Key': idempotencyKey,
      },
      body: JSON.stringify({ customer, items, ...(couponCode ? { coupon_code: couponCode } : {}) }),
    });
  },

  getWompiPaymentStatus: (statusToken, transactionId) => request('/api/payments/wompi/status', {
    headers: {
      'X-Checkout-Status-Token': statusToken,
      'X-Wompi-Transaction-Id': transactionId,
    },
  }),

  // Administración: autenticación nueva mediante Laravel.
  me: (options = {}) => request('/api/auth/me', options),

  listAdminProducts: (options = {}) => request('/api/admin/products', options),
  listAdminCategories: (options = {}) => request('/api/admin/categories', options),
  createAdminCategory: async (name) => { const csrfToken = await getCsrfToken(); return request('/api/admin/categories', { method: 'POST', headers: { 'X-CSRF-TOKEN': csrfToken }, body: JSON.stringify({ name }) }); },
  updateAdminCategory: async (id, name) => { const csrfToken = await getCsrfToken(); return request(`/api/admin/categories/${encodeURIComponent(id)}`, { method: 'PATCH', headers: { 'X-CSRF-TOKEN': csrfToken }, body: JSON.stringify({ name }) }); },
  updateAdminCategoryStatus: async (id, active) => { const csrfToken = await getCsrfToken(); return request(`/api/admin/categories/${encodeURIComponent(id)}/status`, { method: 'PATCH', headers: { 'X-CSRF-TOKEN': csrfToken }, body: JSON.stringify({ active }) }); },
  createAdminSubcategory: async (categoryId, name) => { const csrfToken = await getCsrfToken(); return request(`/api/admin/categories/${encodeURIComponent(categoryId)}/subcategories`, { method: 'POST', headers: { 'X-CSRF-TOKEN': csrfToken }, body: JSON.stringify({ name }) }); },
  updateAdminSubcategory: async (id, name) => { const csrfToken = await getCsrfToken(); return request(`/api/admin/subcategories/${encodeURIComponent(id)}`, { method: 'PATCH', headers: { 'X-CSRF-TOKEN': csrfToken }, body: JSON.stringify({ name }) }); },
  updateAdminSubcategoryStatus: async (id, active) => { const csrfToken = await getCsrfToken(); return request(`/api/admin/subcategories/${encodeURIComponent(id)}/status`, { method: 'PATCH', headers: { 'X-CSRF-TOKEN': csrfToken }, body: JSON.stringify({ active }) }); },

  listAdminPromotions: (options = {}) => request('/api/admin/promotions', options),
  listAdminCoupons: (options = {}) => request('/api/admin/coupons', options),
  listAdminActivity: ({ limit = 50, beforeId, adminId, resourceType, action, dateFrom, dateTo, includeAuth = false, signal } = {}) => {
    const query = new URLSearchParams({ limit: String(limit), include_auth: includeAuth ? '1' : '0' });
    if (beforeId) query.set('before_id', String(beforeId));
    if (adminId) query.set('admin_id', String(adminId));
    if (resourceType) query.set('resource_type', resourceType);
    if (action) query.set('action', action);
    if (dateFrom) query.set('date_from', dateFrom);
    if (dateTo) query.set('date_to', dateTo);
    return request(`/api/admin/audit?${query.toString()}`, { signal });
  },
  listAdminActivityAdministrators: (options = {}) => request('/api/admin/audit/administrators', options),
  getAdminCoupon: (id, options = {}) => request(`/api/admin/coupons/${encodeURIComponent(id)}`, options),
  createAdminCoupon: async (coupon) => { const csrfToken = await getCsrfToken(); return request('/api/admin/coupons', { method: 'POST', headers: { 'X-CSRF-TOKEN': csrfToken }, body: JSON.stringify(coupon) }); },
  updateAdminCoupon: async (id, coupon) => { const csrfToken = await getCsrfToken(); return request(`/api/admin/coupons/${encodeURIComponent(id)}`, { method: 'PATCH', headers: { 'X-CSRF-TOKEN': csrfToken }, body: JSON.stringify(coupon) }); },

  listAdminOrders: ({ page = 1, perPage = 25, flowStatus = '', signal } = {}) => {
    const query = new URLSearchParams({ page: String(page), per_page: String(perPage) });
    if (flowStatus) query.set('flow_status', flowStatus);
    return request(`/api/admin/orders?${query.toString()}`, { signal });
  },

  getAdminOrder: (id, options = {}) => request(`/api/admin/orders/${encodeURIComponent(id)}`, options),

  updateAdminOrderStatus: async (id, status) => {
    const csrfToken = await getCsrfToken();
    return request(`/api/admin/orders/${encodeURIComponent(id)}/status`, { method: 'PATCH', headers: { 'X-CSRF-TOKEN': csrfToken }, body: JSON.stringify({ status }) });
  },

  createAdminProduct: async (product) => {
    const csrfToken = await getCsrfToken();

    return assertProductResponse(await request('/api/admin/products', {
      method: 'POST',
      headers: { 'X-CSRF-TOKEN': csrfToken },
      body: productFormData(product),
    }));
  },

  updateAdminProduct: async (id, product) => {
    const csrfToken = await getCsrfToken();
    const formData = productFormData(product);
    formData.append('_method', 'PATCH');

    return assertProductResponse(await request(`/api/admin/products/${id}`, {
      method: 'POST',
      headers: { 'X-CSRF-TOKEN': csrfToken },
      body: formData,
    }));
  },

  updateAdminProductStatus: async (id, active, expectedRevision) => {
    const csrfToken = await getCsrfToken();

    return assertProductResponse(await request(`/api/admin/products/${id}/status`, {
      method: 'PATCH',
      headers: { 'X-CSRF-TOKEN': csrfToken },
      body: JSON.stringify({ active, expected_revision: expectedRevision }),
    }));
  },

  getAdminProductPromotion: (id, options = {}) => request(`/api/admin/products/${encodeURIComponent(id)}/promotion`, options),

  updateAdminProductPromotion: async (id, promotion) => {
    const csrfToken = await getCsrfToken();
    return request(`/api/admin/products/${encodeURIComponent(id)}/promotion`, {
      method: 'PATCH',
      headers: { 'X-CSRF-TOKEN': csrfToken },
      body: JSON.stringify(promotion),
    });
  },

  login: async ({ username, password }) => {
    const csrfToken = await getCsrfToken();

    const response = await request('/api/auth/login', {
      method: 'POST',
      headers: {
        'X-CSRF-TOKEN': csrfToken,
      },
      body: JSON.stringify({ username, password }),
    });

    // Sincroniza el CSRF con la sesión autenticada.
    await getCsrfToken();

    return response;
  },

  logout: async () => {
    const csrfToken = await getCsrfToken();

    return request('/api/auth/logout', {
      method: 'POST',
      headers: {
        'X-CSRF-TOKEN': csrfToken,
      },
    });
  },
};

function productFormData(product) {
  const formData = new FormData();

  ['name', 'category_id', 'subcategory_id', 'presentation', 'price', 'inventory', 'active', 'expected_revision'].forEach((field) => {
    if (product[field] === undefined) return;
    formData.append(field, field === 'active' ? (product[field] ? '1' : '0') : product[field]);
  });
  if (product.image instanceof File) formData.append('image', product.image);

  return formData;
}

function assertProductResponse(body) {
  if (!body || typeof body !== 'object' || !body.product || typeof body.product !== 'object') {
    const error = new Error('La respuesta del servidor no contiene el producto actualizado.');
    error.status = 502;
    throw error;
  }

  return body;
}
