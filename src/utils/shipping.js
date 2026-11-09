export const SHIPPING_FEE_COP = 15000;
export const FREE_SHIPPING_THRESHOLD_COP = 500000;

export function shippingCost(subtotal) {
  return subtotal >= FREE_SHIPPING_THRESHOLD_COP ? 0 : SHIPPING_FEE_COP;
}
