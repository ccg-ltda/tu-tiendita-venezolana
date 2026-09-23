const PRODUCT_HEADERS = [
  'product_id',
  'category',
  'subcategory',
  'name',
  'presentation',
  'price_cop',
  'inventory',
  'active',
  'image_path',
  'legacy_img',
  'created_at',
  'updated_at',
  'revision'
];

function obtenerHojaProductos_() {
  const hoja = SpreadsheetApp
    .getActiveSpreadsheet()
    .getSheetByName('Productos');

  if (!hoja) {
    throw new Error('No se encontró la hoja Productos.');
  }

  return hoja;
}

function validarEncabezadosProductos_(hoja) {
  const encabezados = hoja
    .getRange(1, 1, 1, PRODUCT_HEADERS.length)
    .getValues()[0];

  if (!encabezadosCoinciden_(encabezados, PRODUCT_HEADERS)) {
    throw new Error(
      'La estructura de la hoja Productos no es válida.'
    );
  }
}

function listarProductos_() {
  const hoja = obtenerHojaProductos_();

  validarEncabezadosProductos_(hoja);

  const ultimaFila = hoja.getLastRow();

  if (ultimaFila <= 1) {
    return respuestaJson_({
      ok: true,
      data: []
    });
  }

  const filas = hoja
    .getRange(
      2,
      1,
      ultimaFila - 1,
      PRODUCT_HEADERS.length
    )
    .getValues();

  const productos = filas
    .filter(function (fila) {
      return fila.some(function (valor) {
        return valor !== '';
      });
    })
    .map(function (fila, indice) {
      return normalizarProductoFila_(fila, indice + 2);
    });

  return respuestaJson_({
    ok: true,
    data: productos
  });
}

function normalizarProductoFila_(fila, numeroFila) {
  const productId = enteroNoNegativo_(
    fila[0],
    'product_id',
    numeroFila,
    false
  );

  const priceCop = enteroNoNegativo_(
    fila[5],
    'price_cop',
    numeroFila,
    true
  );

  const inventory = enteroNoNegativo_(
    fila[6],
    'inventory',
    numeroFila,
    true
  );

  const revision = enteroNoNegativo_(
    fila[12],
    'revision',
    numeroFila,
    false
  );

  if (revision < 1) {
    throw new Error(
      'revision inválido en la fila ' + numeroFila + '.'
    );
  }

  return {
    product_id: productId,
    category: textoRequerido_(
      fila[1],
      'category',
      numeroFila
    ),
    subcategory: textoRequerido_(
      fila[2],
      'subcategory',
      numeroFila
    ),
    name: textoRequerido_(
      fila[3],
      'name',
      numeroFila
    ),
    presentation: textoRequerido_(
      fila[4],
      'presentation',
      numeroFila
    ),
    price_cop: priceCop,
    inventory: inventory,
    active: booleanoValor_(fila[7], numeroFila),
    image_path: textoOpcional_(fila[8]),
    legacy_img: textoOpcional_(fila[9]),
    created_at: serializarFecha_(fila[10]),
    updated_at: serializarFecha_(fila[11]),
    revision: revision
  };
}

function crearProducto_(solicitud) {
  let product;
  try { product = normalizarCreacionProducto_(solicitud); } catch (error) { return respuestaErrorProducto_(error.productCode || 'INVALID_REQUEST'); }
  const lock = LockService.getScriptLock();
  if (!lock.tryLock(5000)) return respuestaErrorProducto_('LOCK_TIMEOUT');
  try {
    const hoja = obtenerHojaProductos_(); validarEncabezadosProductos_(hoja);
    const rows = leerFilasProductos_(hoja), props = PropertiesService.getScriptProperties();
    const id = siguienteIdProducto_(props, rows), now = new Date().toISOString();
    props.setProperty('next_product_id', String(id + 1));
    const values = [id, product.category, product.subcategory, product.name, product.presentation, product.price_cop, product.inventory, product.active, product.image_path, product.legacy_img || '', now, now, 1];
    const row = hoja.getLastRow() + 1;
    hoja.getRange(row, 1, 1, PRODUCT_HEADERS.length).setValues([values]); SpreadsheetApp.flush();
    return respuestaJson_({ok:true,data:{product:normalizarProductoFila_(hoja.getRange(row,1,1,PRODUCT_HEADERS.length).getValues()[0], row)}});
  } catch (error) { return respuestaErrorProducto_(error.productCode || 'INTERNAL_ERROR'); } finally { lock.releaseLock(); }
}

function actualizarProducto_(solicitud) {
  let request;
  try { request = normalizarActualizacionProducto_(solicitud); } catch (error) { return respuestaErrorProducto_(error.productCode || 'INVALID_REQUEST'); }
  const lock = LockService.getScriptLock();
  if (!lock.tryLock(5000)) return respuestaErrorProducto_('LOCK_TIMEOUT');
  try {
    const hoja = obtenerHojaProductos_(); validarEncabezadosProductos_(hoja);
    const found = buscarProducto_(leerFilasProductos_(hoja), request.product_id); if (!found) return respuestaErrorProducto_('PRODUCT_NOT_FOUND');
    const current = normalizarProductoFila_(found.values, found.row); if (current.revision !== request.expected_revision) return respuestaErrorProducto_('REVISION_CONFLICT');
    const next = Object.assign({}, current, request.changes, {updated_at:new Date().toISOString(), revision:current.revision+1});
    const values = filaProducto_(next); hoja.getRange(found.row,1,1,PRODUCT_HEADERS.length).setValues([values]); SpreadsheetApp.flush();
    return respuestaJson_({ok:true,data:{product:normalizarProductoFila_(hoja.getRange(found.row,1,1,PRODUCT_HEADERS.length).getValues()[0], found.row)}});
  } catch (error) { return respuestaErrorProducto_(error.productCode || 'INTERNAL_ERROR'); } finally { lock.releaseLock(); }
}

function establecerProductoActivo_(solicitud) {
  let request;
  try { request = normalizarEstadoProducto_(solicitud); } catch (error) { return respuestaErrorProducto_(error.productCode || 'INVALID_REQUEST'); }
  const lock = LockService.getScriptLock();
  if (!lock.tryLock(5000)) return respuestaErrorProducto_('LOCK_TIMEOUT');
  try {
    const hoja = obtenerHojaProductos_(); validarEncabezadosProductos_(hoja);
    const found = buscarProducto_(leerFilasProductos_(hoja), request.product_id); if (!found) return respuestaErrorProducto_('PRODUCT_NOT_FOUND');
    const current = normalizarProductoFila_(found.values, found.row); if (current.revision !== request.expected_revision) return respuestaErrorProducto_('REVISION_CONFLICT');
    const next = Object.assign({}, current, {active:request.active, updated_at:new Date().toISOString(), revision:current.revision+1});
    hoja.getRange(found.row,1,1,PRODUCT_HEADERS.length).setValues([filaProducto_(next)]); SpreadsheetApp.flush();
    return respuestaJson_({ok:true,data:{product:normalizarProductoFila_(hoja.getRange(found.row,1,1,PRODUCT_HEADERS.length).getValues()[0], found.row)}});
  } catch (error) { return respuestaErrorProducto_(error.productCode || 'INTERNAL_ERROR'); } finally { lock.releaseLock(); }
}

function leerFilasProductos_(hoja) { const last=hoja.getLastRow(); if(last<=1)return []; return hoja.getRange(2,1,last-1,PRODUCT_HEADERS.length).getValues().map(function(values,index){return {row:index+2,values:values};}).filter(function(x){return x.values.some(function(v){return v!=='';});}); }
function buscarProducto_(rows,id) { const found=rows.filter(function(x){return Number(x.values[0])===id;}); if(found.length>1)throw productoError_('INTERNAL_ERROR'); return found[0]||null; }
function siguienteIdProducto_(props,rows) { let max=0,seen={}; rows.forEach(function(x){const id=enteroProducto_(x.values[0],1,2147483647);if(seen[id])throw productoError_('INTERNAL_ERROR');seen[id]=true;max=Math.max(max,id);}); const raw=props.getProperty('next_product_id'); const candidate=raw===null?1:enteroProducto_(raw,1,2147483647); return Math.max(candidate,max+1); }
function filaProducto_(p) { return [p.product_id,p.category,p.subcategory,p.name,p.presentation,p.price_cop,p.inventory,p.active,p.image_path,p.legacy_img||'',p.created_at,p.updated_at,p.revision]; }
function normalizarCreacionProducto_(s) { if(!s||typeof s!=='object'||Array.isArray(s))throw productoError_('INVALID_REQUEST'); const p=normalizarCamposProducto_(s.product,['name','category','subcategory','presentation','price_cop','inventory','active','image_path','legacy_img'],true); return p; }
function normalizarActualizacionProducto_(s) { if(!s||typeof s!=='object'||Array.isArray(s)||!s.changes||typeof s.changes!=='object'||Array.isArray(s.changes))throw productoError_('INVALID_REQUEST'); const allowed=['name','category','subcategory','presentation','price_cop','inventory','image_path']; Object.keys(s.changes).forEach(function(k){if(allowed.indexOf(k)<0)throw productoError_('INVALID_REQUEST');}); if(!Object.keys(s.changes).length)throw productoError_('INVALID_REQUEST'); return {product_id:enteroProducto_(s.product_id,1,2147483647),expected_revision:enteroProducto_(s.expected_revision,1,2147483647),changes:normalizarCamposProducto_(s.changes,allowed,false)}; }
function normalizarEstadoProducto_(s) { if(!s||typeof s!=='object'||Array.isArray(s)||typeof s.active!=='boolean')throw productoError_('INVALID_REQUEST'); return {product_id:enteroProducto_(s.product_id,1,2147483647),expected_revision:enteroProducto_(s.expected_revision,1,2147483647),active:s.active}; }
function normalizarCamposProducto_(source,allowed,required) { if(!source||typeof source!=='object'||Array.isArray(source))throw productoError_('INVALID_REQUEST'); const out={}; allowed.forEach(function(k){if(Object.prototype.hasOwnProperty.call(source,k)){if(['price_cop','inventory'].indexOf(k)>=0)out[k]=enteroProducto_(source[k],0,2147483647);else if(k==='active'){if(typeof source[k]!=='boolean')throw productoError_('INVALID_REQUEST');out[k]=source[k];}else if(k==='legacy_img'){if(source[k]!==null&&typeof source[k]!=='string')throw productoError_('INVALID_REQUEST');out[k]=source[k]||null;}else if(k==='image_path')out[k]=rutaImagenProducto_(source[k]);else out[k]=textoProducto_(source[k],k==='name'?500:100);}}); if(required&&(!out.name||!out.category||!out.subcategory||!out.presentation||typeof out.price_cop!=='number'||typeof out.inventory!=='number'||typeof out.active!=='boolean'||!Object.prototype.hasOwnProperty.call(out,'image_path')))throw productoError_('INVALID_REQUEST'); return out; }
function textoProducto_(v,max) { if(typeof v!=='string')throw productoError_('INVALID_REQUEST'); const t=v.normalize('NFC').trim();if(!t||Array.from(t).length>max||/[\u0000-\u001F\u007F]/.test(t))throw productoError_('INVALID_REQUEST');return t; }
function enteroProducto_(v,min,max) { if(typeof v==='string'&&!/^(0|[1-9]\d*)$/.test(v))throw productoError_('INVALID_REQUEST');const n=Number(v);if(!Number.isSafeInteger(n)||n<min||n>max)throw productoError_('INVALID_REQUEST');return n; }
function rutaImagenProducto_(v) { if(v===null||v===undefined||v==='')return null; if(typeof v!=='string'||!/^\/assets\/products\/[a-f0-9]{32}\.(jpg|jpeg|png|webp)$/.test(v))throw productoError_('INVALID_REQUEST');return v; }
function productoError_(code) { return {productCode:code}; }
function respuestaErrorProducto_(code) { return respuestaJson_({ok:false,error:{code:code}}); }
