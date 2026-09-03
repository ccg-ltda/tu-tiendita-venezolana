async function request(path, options = {}) {
  const { headers = {}, ...fetchOptions } = options;

  const response = await fetch(path, {
    credentials: 'include',
    ...fetchOptions,
    headers: {
      Accept: 'application/json',
      ...(fetchOptions.body && {
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

  createOrder: (order) =>
    request('/api/orders', {
      method: 'POST',
      body: JSON.stringify(order),
    }),

  // Administración: autenticación nueva mediante Laravel.
  me: () => request('/api/auth/me'),

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

  forgotPassword: async (email) => {
    const csrfToken = await getCsrfToken();

    return request('/api/auth/forgot-password', {
      method: 'POST',
      headers: {
        'X-CSRF-TOKEN': csrfToken,
      },
      body: JSON.stringify({ email }),
    });
  },

  resetPassword: async ({ email, token, password, password_confirmation: passwordConfirmation }) => {
    const csrfToken = await getCsrfToken();

    return request('/api/auth/reset-password', {
      method: 'POST',
      headers: {
        'X-CSRF-TOKEN': csrfToken,
      },
      body: JSON.stringify({
        email,
        token,
        password,
        password_confirmation: passwordConfirmation,
      }),
    });
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
