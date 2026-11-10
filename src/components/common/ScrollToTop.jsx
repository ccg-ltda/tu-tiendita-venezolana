import { useEffect, useRef } from 'react';
import { useLocation } from 'react-router-dom';

export function ScrollToTop() {
  const { pathname, hash } = useLocation();
  const previousPathname = useRef();

  useEffect(() => {
    const previous = previousPathname.current;
    previousPathname.current = pathname;

    // Deja que el navegador conserve el destino de una ancla real, por ejemplo /#catalogo.
    if (hash) return;
    if (previous?.startsWith('/categoria/') && pathname.startsWith('/categoria/')) return;

    window.scrollTo({
      top: 0,
      left: 0,
      behavior: 'auto',
    });
  }, [pathname]);

  return null;
}
