// Datos de EJEMPLO para revisar el diseño del panel v2 mientras el servidor no tiene las funciones nuevas.
// No son ventas ni clientes reales.
const PRODUCTS = [[1, 'Harina PAN Blanca 1 kg'], [2, 'Toddy Pequeño'], [3, 'Diablito Grande 115gr'], [4, 'Samba Grande Personal'], [5, 'Rikesa Plástica 330gr'], [6, 'Queso Llanero 500gr'], [7, 'Malta Polar Lata'], [8, 'Pepitonas']];
const CITIES = ['Bogotá', 'Medellín', 'Cali', 'Barranquilla', 'Bucaramanga', 'Cúcuta'];
const iso = (d) => d.toISOString().slice(0, 10);
const seeded = (seed) => () => { seed = (seed * 9301 + 49297) % 233280; return seed / 233280; };

export function demoDashboard(days) {
  const rnd = seeded(days * 7 + 3);
  const by_day = [];
  for (let i = days - 1; i >= 0; i--) {
    const d = new Date(); d.setDate(d.getDate() - i);
    const orders = Math.floor(rnd() * 6) + (d.getDay() === 6 ? 3 : 0);
    by_day.push({ date: iso(d), orders, sales: orders * (55000 + Math.floor(rnd() * 70000)) });
  }
  const sales = by_day.reduce((a, r) => a + r.sales, 0);
  const orders = by_day.reduce((a, r) => a + r.orders, 0);
  const prevSales = Math.round(sales * 0.86); const prevOrders = Math.round(orders * 0.9);
  const from = by_day[0].date; const pf = new Date(`${from}T12:00:00`); pf.setDate(pf.getDate() - days); const pt = new Date(`${from}T12:00:00`); pt.setDate(pt.getDate() - 1);
  return {
    range: { from, to: by_day[by_day.length - 1].date, previous_from: iso(pf), previous_to: iso(pt) },
    current: { sales, orders, average_ticket: orders ? Math.round(sales / orders) : 0 },
    previous: { sales: prevSales, orders: prevOrders, average_ticket: prevOrders ? Math.round(prevSales / prevOrders) : 0 },
    by_day,
    top_products: PRODUCTS.map(([id, name], i) => ({ product_id: id, name, units: Math.max(1, Math.round((orders * 1.4) / (i + 1))), sales: Math.round((sales * 0.32) / (i + 1)) })),
    cities: CITIES.map((city, i) => ({ city, orders: Math.max(1, Math.round(orders / (i + 1.6))), sales: Math.round(sales / (i + 1.6)) })),
    by_status: [],
    to_attend: 3,
    payment_not_completed: 2,
  };
}

export function demoCustomers() {
  const names = [['María González', 'Bogotá'], ['José Rodríguez', 'Medellín'], ['Yolanda Suárez', 'Bogotá'], ['Panadería La 14', 'Cali'], ['Carlos Pérez', 'Barranquilla'], ['Ana Martínez', 'Bogotá'], ['Luis Hernández', 'Bucaramanga'], ['Carmen Díaz', 'Cúcuta']];
  return { customers: names.map(([name, city], i) => {
    const last = new Date(); last.setDate(last.getDate() - [2, 9, 15, 48, 63, 5, 90, 21][i]);
    const first = new Date(last); first.setDate(first.getDate() - i * 20);
    const orders = [6, 4, 3, 2, 1, 2, 1, 1][i];
    return { email: `cliente${i + 1}@ejemplo.com`, name, phone: `30${String(10000000 + i * 1234567).slice(0, 8)}`, city, orders, spent: orders * (68000 + i * 9000), first_purchase: iso(first), last_purchase: iso(last), days_since_last: [2, 9, 15, 48, 63, 5, 90, 21][i] };
  }) };
}

export function demoWhatsApp() {
  const ago = (h) => new Date(Date.now() - h * 3600e3).toISOString();
  return {
    admins: [{ id: 1, name: 'Laura Puentes' }, { id: 2, name: 'Linda Pérez' }, { id: 3, name: 'Fe Osorio' }],
    clicks_30_days: { mayorista: 18, soporte: 11, ayuda_compra: 27 },
    requests: [
      { id: 1, name: 'Restaurante Arepa Express', phone: '3104441122', topic: 'mayorista', message: '40 harinas PAN al mes · Bogotá', status: 'NEW', assigned_admin_id: null, assigned_name: null, created_at: ago(1) },
      { id: 2, name: 'Carolina Méndez', phone: '3008882211', topic: 'soporte', message: 'Pedido TTV-20261108-4-A12: no ha llegado la guía', status: 'ATTENDING', assigned_admin_id: 1, assigned_name: 'Laura Puentes', created_at: ago(5) },
      { id: 3, name: 'Jorge Salas', phone: '3157773344', topic: 'ayuda_compra', message: 'Quiere armar un mercado para hallacas · Medellín', status: 'NEW', assigned_admin_id: null, assigned_name: null, created_at: ago(20) },
      { id: 4, name: 'Minimercado La Esquina', phone: '3019996655', topic: 'mayorista', message: 'Toddy y Diablito por caja · Cali', status: 'RESOLVED', assigned_admin_id: 2, assigned_name: 'Linda Pérez', created_at: ago(50) },
    ],
  };
}
