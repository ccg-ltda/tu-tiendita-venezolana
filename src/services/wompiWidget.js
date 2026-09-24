const WOMPI_WIDGET_URL = 'https://checkout.wompi.co/widget.js';

let wompiWidgetPromise;

export function loadWompiWidget() {
  if (window.WidgetCheckout) {
    return Promise.resolve(window.WidgetCheckout);
  }

  if (wompiWidgetPromise) {
    return wompiWidgetPromise;
  }

  const script = Array.from(document.scripts).find((item) => item.src === WOMPI_WIDGET_URL)
    || document.createElement('script');

  if (script.dataset.wompiWidgetFailed === 'true') {
    return Promise.reject(new Error('No se pudo cargar el Widget de Wompi.'));
  }

  wompiWidgetPromise = new Promise((resolve, reject) => {
    const fail = () => {
      script.dataset.wompiWidgetFailed = 'true';
      reject(new Error('No se pudo cargar el Widget de Wompi.'));
    };

    const ready = () => {
      if (window.WidgetCheckout) {
        resolve(window.WidgetCheckout);
        return;
      }

      fail();
    };

    script.addEventListener('load', ready, { once: true });
    script.addEventListener('error', fail, { once: true });

    if (!script.src) {
      script.src = WOMPI_WIDGET_URL;
      script.async = true;
      document.head.append(script);
    }
  });

  wompiWidgetPromise.catch(() => {
    wompiWidgetPromise = undefined;
  });

  return wompiWidgetPromise;
}
