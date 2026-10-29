import { useEffect, useRef, useState } from 'react';
import { api } from '../services/api';

/** Loads the public catalog once and revalidates it silently on tab focus. */
export function useCatalog() {
  const [catalog, setCatalog] = useState([]);
  const [loading, setLoading] = useState(true);
  const [catalogError, setCatalogError] = useState('');
  const catalogLoaded = useRef(false);
  const revalidationInFlight = useRef(false);
  const lastRevalidationAt = useRef(0);

  const loadProducts = async (silent = false) => {
    try {
      const result = await api.listProducts();
      setCatalog(result.products);
      catalogLoaded.current = true;
      setCatalogError('');
      return result.products;
    } catch (error) {
      if (!silent) setCatalogError(error.message);
      throw error;
    }
  };

  useEffect(() => {
    const initialize = async () => {
      try {
        await loadProducts();
      } catch {
        // El error del catálogo ya es manejado por loadProducts.
      } finally {
        setLoading(false);
      }
    };

    initialize();
  }, []);

  useEffect(() => {
    const revalidateCatalog = async () => {
      const now = Date.now();
      if (!catalogLoaded.current || revalidationInFlight.current || now - lastRevalidationAt.current < 1000) return;

      revalidationInFlight.current = true;
      lastRevalidationAt.current = now;
      try {
        await loadProducts(true);
      } catch {
        // Conserva el catálogo actual durante un fallo transitorio de revalidación.
      } finally {
        revalidationInFlight.current = false;
      }
    };
    const onVisibilityChange = () => {
      if (document.visibilityState === 'visible') revalidateCatalog();
    };

    window.addEventListener('focus', revalidateCatalog);
    document.addEventListener('visibilitychange', onVisibilityChange);

    return () => {
      window.removeEventListener('focus', revalidateCatalog);
      document.removeEventListener('visibilitychange', onVisibilityChange);
    };
  }, []);

  return { catalog, loading, catalogError, loadProducts, setCatalogError };
}
