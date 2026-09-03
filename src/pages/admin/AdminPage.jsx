import { useEffect, useState } from 'react';
import { useNavigate } from 'react-router-dom';
import { api } from '../../services/api';

export function AdminPage() {
  const navigate = useNavigate();

  const [user, setUser] = useState(null);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState('');
  const [loggingOut, setLoggingOut] = useState(false);

  // Verifica que exista una sesión administrativa válida.
  useEffect(() => {
    const checkSession = async () => {
      try {
        const response = await api.me();
        setUser(response.user);
      } catch (requestError) {
        if (requestError.status === 401 || requestError.status === 403) {
          navigate('/admin/login', { replace: true });
          return;
        }

        setError('No se pudo verificar la sesión administrativa.');
      } finally {
        setLoading(false);
      }
    };

    checkSession();
  }, [navigate]);

  const handleLogout = async () => {
    setLoggingOut(true);
    setError('');

    try {
      await api.logout();
      navigate('/admin/login', { replace: true });
    } catch {
      setError('No fue posible cerrar la sesión.');
      setLoggingOut(false);
    }
  };

  if (loading) {
    return (
      <main>
        <p>Verificando sesión...</p>
      </main>
    );
  }

  if (error) {
    return (
      <main>
        <p role='alert'>{error}</p>
      </main>
    );
  }

  if (!user) {
    return null;
  }

  return (
    <main>
      <h1>Panel administrativo</h1>

      <p>Sesión iniciada como:</p>

      <div>
        <p><strong>Nombre:</strong> {user.name}</p>
        <p><strong>Correo:</strong> {user.email}</p>
        <p><strong>Rol:</strong> {user.role}</p>
      </div>

      <button
        type='button'
        onClick={handleLogout}
        disabled={loggingOut}
      >
        {loggingOut ? 'Cerrando sesión...' : 'Cerrar sesión'}
      </button>
    </main>
  );
}