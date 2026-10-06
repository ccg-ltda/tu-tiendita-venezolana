const PROMOTION_HEADERS = [
  'product_id',
  'active',
  'discount_type',
  'discount_value',
  'starts_at',
  'ends_at',
  'updated_at',
  'revision'
];

/* Read-only foundation for future catalog and checkout promotion consumers. */
function obtenerHojaPromociones_() {
  const hoja = SpreadsheetApp.getActiveSpreadsheet().getSheetByName('Promociones');
  if (!hoja) throw new Error('No se encontró la hoja Promociones.');
  return hoja;
}

function validarEncabezadosPromociones_(hoja) {
  if (hoja.getLastColumn() !== PROMOTION_HEADERS.length) {
    throw new Error('La estructura de la hoja Promociones no es válida.');
  }
  const headers = hoja.getRange(1, 1, 1, PROMOTION_HEADERS.length).getValues()[0];
  if (!encabezadosCoinciden_(headers, PROMOTION_HEADERS)) {
    throw new Error('La estructura de la hoja Promociones no es válida.');
  }
}

function listarPromociones_() {
  const hoja = obtenerHojaPromociones_();
  validarEncabezadosPromociones_(hoja);
  const last = hoja.getLastRow();
  if (last <= 1) return [];
  return hoja.getRange(2, 1, last - 1, PROMOTION_HEADERS.length).getValues()
    .filter(function(row) { return row.some(function(value) { return value !== '' && value !== null; }); })
    .map(function(row, index) { return normalizarPromocionFila_(row, index + 2); });
}

function normalizarPromocionFila_(row, rowNumber) {
  const productId = enteroPromocion_(row[0], 1, 2147483647);
  const active = booleanoValor_(row[1], rowNumber);
  const type = row[2];
  const value = enteroPromocion_(row[3], 1, 2147483647);
  const startsAt = fechaComercialPromocion_(row[4], 'starts_at', rowNumber);
  const endsAt = fechaComercialPromocion_(row[5], 'ends_at', rowNumber);
  const updatedAt = fechaAuditoriaPromocion_(row[6], rowNumber);
  const revision = enteroPromocion_(row[7], 1, 2147483647);
  if (type !== 'percent' && type !== 'fixed') throw new Error('discount_type inválido en Promociones, fila ' + rowNumber + '.');
  if (type === 'percent' && value > 99) throw new Error('discount_value inválido en Promociones, fila ' + rowNumber + '.');
  if (startsAt && endsAt && startsAt > endsAt) throw new Error('Rango de fechas inválido en Promociones, fila ' + rowNumber + '.');
  return {product_id:productId,active:active,discount_type:type,discount_value:value,starts_at:startsAt,ends_at:endsAt,updated_at:updatedAt,revision:revision};
}

function enteroPromocion_(value, minimum, maximum) {
  if (typeof value === 'string' && !/^(0|[1-9]\d*)$/.test(value)) throw new Error('Entero inválido en Promociones.');
  const integer = Number(value);
  if (!Number.isSafeInteger(integer) || integer < minimum || integer > maximum) throw new Error('Entero inválido en Promociones.');
  return integer;
}

/* Commercial windows are stored as literal America/Bogota timestamps, never as spreadsheet-local Date cells. */
function fechaComercialPromocion_(value, field, rowNumber) {
  if (value === '' || value === null || value === undefined) return null;
  if (typeof value !== 'string' || !/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}\.\d{3}-05:00$/.test(value)) throw new Error(field + ' inválido en Promociones, fila ' + rowNumber + '.');
  const date = new Date(value);
  if (isNaN(date.getTime())) throw new Error(field + ' inválido en Promociones, fila ' + rowNumber + '.');
  return value;
}

function fechaAuditoriaPromocion_(value, rowNumber) {
  if (typeof value !== 'string' || !/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}\.\d{3}Z$/.test(value) || isNaN(new Date(value).getTime())) throw new Error('updated_at inválido en Promociones, fila ' + rowNumber + '.');
  return value;
}
