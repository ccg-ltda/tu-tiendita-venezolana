function doPost(e) {
  try {
    const solicitud = obtenerSolicitud_(e);

    if (!autenticar_(solicitud.api_key)) {
      return respuestaJson_({
        ok: false,
        error: 'No autorizado.'
      });
    }

    switch (solicitud.action) {
      case 'list_products':
        return listarProductos_();

      case 'create_product':
        return crearProducto_(solicitud);

      case 'update_product':
        return actualizarProducto_(solicitud);

      case 'set_product_active':
        return establecerProductoActivo_(solicitud);

      case 'prepare_checkout':
        return prepararCheckout_(solicitud);

      case 'record_payment_event':
        return registrarEventoPago_(solicitud);

      case 'get_checkout_status':
        return obtenerEstadoCheckout_(solicitud);

      case 'release_expired_reservation':
        return liberarReservaVencida_(solicitud);

      case 'admin_list_orders':
        return listarPedidosAdmin_(solicitud);

      case 'admin_get_order':
        return obtenerPedidoAdmin_(solicitud);

      case 'admin_update_order_status':
        return actualizarEstadoPedidoAdmin_(solicitud);

      default:
        return respuestaJson_({
          ok: false,
          error: 'Operación no válida.'
        });
    }
  } catch (error) {
    console.error(error);

    return respuestaJson_({
      ok: false,
      error: 'No fue posible procesar la solicitud.'
    });
  }
}
