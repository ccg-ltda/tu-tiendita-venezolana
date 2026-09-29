import assert from 'node:assert/strict';
import fs from 'node:fs';
import vm from 'node:vm';

const root = new URL('..', import.meta.url);
const guardSource = fs.readFileSync(new URL('CutoverGuard.gs', root), 'utf8');
const checkout = fs.readFileSync(new URL('Checkout.gs', root), 'utf8');
const products = fs.readFileSync(new URL('Products.gs', root), 'utf8');
const code = fs.readFileSync(new URL('Code.gs', root), 'utf8');

function harness(value = null) {
  const log = [];
  const properties = { value, getProperty(key) { log.push(`get:${key}`); return this.value; }, setProperty(key, next) { log.push(`set:${key}:${next}`); this.value = next; } };
  const lock = { tryLock(wait) { log.push(`try:${wait}`); return true; }, releaseLock() { log.push('release'); } };
  const context = vm.createContext({ PropertiesService: { getScriptProperties: () => properties }, LockService: { getScriptLock: () => lock } });
  vm.runInContext(guardSource, context);
  return { context, properties, log };
}
function call(h, expression) { return vm.runInContext(expression, h.context); }
function errorCode(h, expression) { try { call(h, expression); assert.fail('Expected guard error.'); } catch (error) { return error.checkoutCode; } }
function body(source, name) { const start = source.indexOf(`function ${name}`); assert.notEqual(start, -1, `${name} missing`); const next = source.indexOf('\nfunction ', start + 1); return source.slice(start, next === -1 ? source.length : next); }

{
  const absent = harness(); assert.equal(call(absent, 'getCriticalWritersMode_()'), 'APPS_SCRIPT');
  const enabled = harness('APPS_SCRIPT'); assert.doesNotThrow(() => call(enabled, 'assertCriticalWritersEnabled_()'));
  const disabled = harness('DISABLED'); assert.equal(errorCode(disabled, 'assertCriticalWritersEnabled_()'), 'WRITER_CUTOVER_DISABLED');
  const corrupt = harness('BROKEN'); assert.equal(call(corrupt, 'getCriticalWritersMode_()'), 'DISABLED'); assert.equal(errorCode(corrupt, 'assertCriticalWritersEnabled_()'), 'WRITER_CUTOVER_DISABLED');
  const operator = harness(); call(operator, "setCriticalWritersMode_('DISABLED')"); assert.deepEqual(operator.log.slice(-3), ['try:5000', 'set:CHECKOUT_CRITICAL_WRITERS_MODE:DISABLED', 'release']);
  const invalid = harness(); assert.equal(errorCode(invalid, "setCriticalWritersMode_('BROKEN')"), 'INVALID_REQUEST'); assert.equal(invalid.log.length, 0);
}

for (const [source, name, firstSheetOrDelegate] of [
  [checkout, 'prepararCheckout_', 'return ejecutarCheckoutBloqueado_'],
  [checkout, 'registrarEventoPago_', 'return registrarEventoPagoBloqueado_'],
  [checkout, 'liberarReservaVencida_', 'return request.mode'],
  [products, 'crearProducto_', 'const hoja = obtenerHojaProductos_'],
  [products, 'actualizarProducto_', 'const hoja = obtenerHojaProductos_'],
  [products, 'establecerProductoActivo_', 'const hoja = obtenerHojaProductos_'],
  [checkout, 'actualizarEstadoPedidoAdmin_', 'SpreadsheetApp.getActiveSpreadsheet'],
]) {
  const action = body(source, name);
  const lock = action.indexOf('LockService.getScriptLock()');
  const guard = action.indexOf('assertCriticalWritersEnabled_()');
  const sheet = action.indexOf(firstSheetOrDelegate);
  assert.ok(lock !== -1 && guard !== -1 && sheet !== -1, `${name} wiring missing`);
  assert.ok(lock < guard, `${name} guard must follow ScriptLock acquisition`);
  assert.ok(guard < sheet, `${name} guard must precede first Sheets access/delegate`);
}

assert.equal(code.includes("case 'set_writer_mode'") || code.includes("case 'setCriticalWritersMode'"), false, 'operator must not be HTTP-dispatched');
console.log('cutover guard local harness: PASS');
