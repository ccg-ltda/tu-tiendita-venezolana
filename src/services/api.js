async function request(path, options = {}) {
  const { headers = {}, ...fetchOptions } = options;
  const isFormData = fetchOptions.body instanceof FormData;

  const response = await fetch(path, {
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
    error.details = body?.errors ?? null;

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

  prepareWompiPayment: async ({ customer, items }, idempotencyKey) => {
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
      body: JSON.stringify({ customer, items }),
    });
  },

  getWompiPaymentStatus: (statusToken) => request('/api/payments/wompi/status', {
    headers: {
      'X-Checkout-Status-Token': statusToken,
    },
  }),

  // Administración: autenticación nueva mediante Laravel.
  me: (options = {}) => request('/api/auth/me', options),

  listAdminProducts: (options = {}) => request('/api/admin/products', options),

  listAdminOrders: ({ page = 1, perPage = 25, signal } = {}) => request(`/api/admin/orders?page=${encodeURIComponent(page)}&per_page=${encodeURIComponent(perPage)}`, { signal }),

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

  login: async (credentials) => {
    const csrfToken = await getCsrfToken();

    const response = await request('/api/auth/login', {
      method: 'POST',
      headers: {
        'X-CSRF-TOKEN': csrfToken,
      },
      body: JSON.stringify(credentials),
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

  ['name', 'category', 'subcategory', 'presentation', 'price', 'inventory', 'active', 'expected_revision'].forEach((field) => {
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
