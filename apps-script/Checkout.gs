const PEDIDOS_HEADERS = ['order_id','reference','status','payment_status','reservation_status','reservation_expires_at','paid_at','payment_last_event_at','checkout_idempotency_key','checkout_payload_hash','release_id','release_fingerprint','released_at','customer_name','customer_email','customer_phone','customer_document','address','extra','city','region','postal','total_cop','created_at','updated_at','revision'];
const PEDIDO_ITEMS_HEADERS = ['order_item_id','order_id','product_id','product_name','unit_price_cop','quantity','created_at'];
const PAGOS_HEADERS = ['payment_attempt_id','order_id','wompi_transaction_id','status','payment_method','amount_in_cents','currency','created_at','updated_at'];
const CHECKOUT_JOURNAL_PREFIX = 'checkout_journal:';
const CHECKOUT_JOURNAL_MAX_BYTES = 8192;
const CHECKOUT_JOURNAL_MAX_ACTIVE = 40;
const CHECKOUT_RESERVATION_MINUTES = 10;
const CHECKOUT_RELEASE_GRACE_MINUTES = 10;
const CHECKOUT_RELEASE_JOURNAL_PREFIX = 'release_journal:';
const CHECKOUT_RELEASE_JOURNAL_MAX_ACTIVE = 40;
const CHECKOUT_RELEASE_JOURNAL_MAX_BYTES = 8192;
const RELEASE_JOURNAL_STAGES = ['PREPARED','PRODUCTS_WRITTEN','ORDER_WRITTEN','PAYMENTS_WRITTEN','FINALIZED'];

function prepararCheckout_(solicitud) {
  let checkout;
  try { checkout = normalizarCheckout_(solicitud); } catch (error) { return respuestaErrorCheckout_('INVALID_REQUEST'); }
  if (!hashValidoCheckout_(solicitud.payload_hash) || calcularHashCheckout_(checkout.canonical) !== solicitud.payload_hash) return respuestaErrorCheckout_('INVALID_REQUEST');
  const lock = LockService.getScriptLock();
  if (!lock.tryLock(5000)) return respuestaErrorCheckout_('LOCK_TIMEOUT');
  try {
    return ejecutarCheckoutBloqueado_(checkout);
  } catch (error) {
    const code = error && error.checkoutCode;
    console.error(code ? 'prepare_checkout: ' + code : 'prepare_checkout: INTERNAL_ERROR');
    return respuestaErrorCheckout_(code || 'INTERNAL_ERROR');
  } finally { lock.releaseLock(); }
}

function ejecutarCheckoutBloqueado_(checkout) {
  const hojas = obtenerHojasCheckout_();
  const pedidos = leerFilasCheckout_(hojas.pedidos, PEDIDOS_HEADERS);
  const existentes = pedidos.filter(function (x) { return String(x.d.checkout_idempotency_key || '') === checkout.idempotencyKey; });
  if (existentes.length > 1) throw errorCheckout_('CONSISTENCY_UNCERTAIN');
  const journal = leerJournalCheckout_(checkout.idempotencyKey);
  if (existentes.length === 1) return resolverPedidoExistenteCheckout_(existentes[0], journal, checkout, hojas);
  if (journal) resolverJournalSinPedidoCheckout_(journal, checkout, hojas);
  return crearReservaCheckout_(checkout, hojas);
}

function crearReservaCheckout_(checkout, hojas) {
  const productos = leerProductosCheckout_(hojas.productos);
  const plan = planificarProductosCheckout_(checkout.items, productos);
  const ids = planificarIdsCheckout_(hojas); ids.itemCount = checkout.items.length;
  const now = new Date(); const created = now.toISOString();
  const reference = generarReferenceCheckout_(ids.orderId, now, hojas.pedidos);
  const journal = {v:1,k:checkout.idempotencyKey,h:checkout.payloadHash,o:ids.orderId,f:ids.firstItemId,n:checkout.items.length,r:reference,s:'PREPARED',c:created,p:plan.items.map(function(x){return [x.id,x.beforeInventory,x.afterInventory,x.beforeRevision,x.afterRevision];})};
  guardarJournalCheckout_(journal);
  avanzarContadoresCheckout_(ids);
  const reservationExpires = new Date(now.getTime() + CHECKOUT_RESERVATION_MINUTES * 60000).toISOString();
  const order = filaPedidoCheckout_(ids.orderId, reference, 'RESERVATION_PREPARING', '', '', '', checkout, plan.total, created, 0);
  const orderRow = hojas.pedidos.getLastRow() + 1;
  hojas.pedidos.getRange(orderRow,1,1,PEDIDOS_HEADERS.length).setValues([order]);
  if (!verificarPedidoCheckout_(hojas.pedidos, orderRow, ids.orderId, checkout.idempotencyKey, 'RESERVATION_PREPARING')) throw errorCheckout_('CONSISTENCY_UNCERTAIN');
  actualizarStageJournalCheckout_(journal,'ORDER_WRITTEN');
  const itemRows = plan.items.map(function(x,i){return [ids.firstItemId+i,ids.orderId,x.id,x.name,x.price,x.quantity,created];});
  const firstItemRow = hojas.items.getLastRow()+1;
  hojas.items.getRange(firstItemRow,1,itemRows.length,PEDIDO_ITEMS_HEADERS.length).setValues(itemRows);
  if (!verificarItemsCheckout_(hojas.items, firstItemRow, itemRows)) throw errorCheckout_('CONSISTENCY_UNCERTAIN');
  actualizarStageJournalCheckout_(journal,'ITEMS_WRITTEN');
  escribirProductosCheckout_(hojas.productos, plan, created);
  if (!verificarProductosCheckout_(hojas.productos, plan)) throw errorCheckout_('CONSISTENCY_UNCERTAIN');
  actualizarStageJournalCheckout_(journal,'PRODUCTS_WRITTEN');
  const finalOrder = filaPedidoCheckout_(ids.orderId,reference,'PENDING','PENDING','ACTIVE',reservationExpires,checkout,plan.total,created,1);
  hojas.pedidos.getRange(orderRow,1,1,PEDIDOS_HEADERS.length).setValues([finalOrder]);
  SpreadsheetApp.flush();
  if (!verificarReservaFinalCheckout_(checkout,hojas,journal,'PRE_COMMIT_WITH_JOURNAL')) throw errorCheckout_('CONSISTENCY_UNCERTAIN');
  actualizarStageJournalCheckout_(journal,'FINALIZED');
  borrarJournalCheckout_(checkout.idempotencyKey);
  return respuestaExitoCheckout_(finalOrder, false);
}

function resolverPedidoExistenteCheckout_(pedido, journal, checkout, hojas) {
  const d = pedido.d;
  if (String(d.checkout_payload_hash || '') !== checkout.payloadHash) throw errorCheckout_('IDEMPOTENCY_CONFLICT');
  if (String(d.status) === 'PENDING' && String(d.payment_status) === 'PENDING' && String(d.reservation_status) === 'ACTIVE') {
    if (!d.reservation_expires_at || new Date(d.reservation_expires_at).getTime() <= Date.now()) throw errorCheckout_('RESERVATION_EXPIRED');
    if (journal && !journalCompatibleCheckout_(journal, checkout, d)) throw errorCheckout_('CONSISTENCY_UNCERTAIN');
    if (!verificarReservaFinalCheckout_(checkout,hojas,journal,'DURABLE_REPLAY')) throw errorCheckout_('CONSISTENCY_UNCERTAIN');
    if (journal) borrarJournalCheckout_(checkout.idempotencyKey);
    return respuestaExitoCheckout_(filaDesdeDatosCheckout_(d,PEDIDOS_HEADERS), true);
  }
  if (String(d.status)==='RESERVATION_PREPARING' && journal && journalCompatibleCheckout_(journal,checkout,d)) return recuperarPedidoTecnicoCheckout_(pedido,journal,checkout,hojas);
  if (String(d.reservation_status) === 'RELEASED' || (d.reservation_expires_at && new Date(d.reservation_expires_at).getTime() <= Date.now())) throw errorCheckout_('RESERVATION_EXPIRED');
  throw errorCheckout_('CONSISTENCY_UNCERTAIN');
}

function clasificarPedidoCheckout_(pedido,journal,checkout) { if(!pedido)return 'ABSENT';const d=pedido.d,c=checkout.customer;const same=Number(d.order_id)===Number(journal.o)&&String(d.reference)===journal.r&&String(d.checkout_idempotency_key)===checkout.idempotencyKey&&String(d.checkout_payload_hash)===checkout.payloadHash&&String(d.customer_name)===c.name&&String(d.customer_email)===c.email&&String(d.customer_phone)===c.phone&&String(d.customer_document)===c.document&&String(d.address)===c.address&&String(d.extra||'')===String(c.extra||'')&&String(d.city)===c.city&&String(d.region)===c.region&&String(d.postal||'')===String(c.postal||'')&&Number.isSafeInteger(Number(d.total_cop));if(!same)return 'CONFLICT';if(String(d.status)==='RESERVATION_PREPARING'&&Number(d.revision)===0)return 'TECHNICAL_EXACT';if(String(d.status)==='PENDING'&&String(d.payment_status)==='PENDING'&&String(d.reservation_status)==='ACTIVE'&&Number(d.revision)===1)return 'FINAL_EXACT';return 'CONFLICT'; }
function clasificarItemsCheckout_(hojas,journal,checkout) { const expected={},first=enteroCheckout_(journal.f,1,2147483647),count=enteroCheckout_(journal.n,1,50),orderId=enteroCheckout_(journal.o,1,2147483647);checkout.items.forEach(function(x,i){expected[first+i]=x;});const seen={},rows=leerFilasCheckout_(hojas.items,PEDIDO_ITEMS_HEADERS);let sum=0,present=0;for(let i=0;i<rows.length;i++){const d=rows[i].d,idStrict=typeof d.order_item_id==='number'&&Number.isInteger(d.order_item_id)&&d.order_item_id>0,orderStrict=typeof d.order_id==='number'&&Number.isInteger(d.order_id)&&d.order_id>0,id=idStrict?d.order_item_id:null,belongs=orderStrict&&d.order_id===orderId,inRange=id!==null&&Object.prototype.hasOwnProperty.call(expected,id);if(!inRange&&!belongs)continue;if(!idStrict||!orderStrict||!inRange||!belongs||seen[id])return 'CONFLICT';const e=expected[id],price=d.unit_price_cop,q=d.quantity;if(typeof price!=='number'||!Number.isSafeInteger(price)||price<0||typeof q!=='number'||!Number.isInteger(q)||q!==e.quantity||d.product_id!==e.product_id||!String(d.product_name||'').trim())return 'CONFLICT';seen[id]=true;present++;sum+=price*q;if(!Number.isSafeInteger(sum))return 'CONFLICT';}if(present===0)return 'NONE';if(present!==count)return 'PARTIAL_EXACT';const order=leerFilasCheckout_(hojas.pedidos,PEDIDOS_HEADERS).filter(function(x){return typeof x.d.order_id==='number'&&x.d.order_id===orderId;})[0];return order&&typeof order.d.total_cop==='number'&&Number.isSafeInteger(order.d.total_cop)&&sum===order.d.total_cop?'COMPLETE_EXACT':'CONFLICT'; }
function clasificarProductosCheckout_(hojas,journal) { const by={};leerProductosCheckout_(hojas.productos).forEach(function(x){by[x.id]=x;});return journal.p.map(function(p){const x=by[p[0]];if(!x)return 'CONFLICT';if(x.inventory===p[1]&&x.revision===p[3])return 'BEFORE_EXACT';if(x.inventory===p[2]&&x.revision===p[4])return 'AFTER_EXACT';return 'CONFLICT';}); }
function recuperarPedidoTecnicoCheckout_(pedido,journal,checkout,hojas) { const p=clasificarPedidoCheckout_(pedido,journal,checkout),items=clasificarItemsCheckout_(hojas,journal,checkout),states=clasificarProductosCheckout_(hojas,journal);if(p!=='TECHNICAL_EXACT'||items!=='COMPLETE_EXACT'||states.indexOf('CONFLICT')>=0)throw errorCheckout_('CONSISTENCY_UNCERTAIN');if(states.indexOf('BEFORE_EXACT')>=0){const by={};leerProductosCheckout_(hojas.productos).forEach(function(x){by[x.id]=x;});journal.p.forEach(function(change,i){if(states[i]==='BEFORE_EXACT'){const x=by[change[0]],row=x.values.slice();row[6]=change[2];row[11]=new Date().toISOString();row[12]=change[4];hojas.productos.getRange(x.row,1,1,PRODUCT_HEADERS.length).setValues([row]);}});SpreadsheetApp.flush();if(clasificarProductosCheckout_(hojas,journal).some(function(s){return s!=='AFTER_EXACT';}))throw errorCheckout_('CONSISTENCY_UNCERTAIN');}const d=pedido.d,final=filaPedidoCheckout_(Number(d.order_id),String(d.reference),'PENDING','PENDING','ACTIVE',new Date(Date.now()+CHECKOUT_RESERVATION_MINUTES*60000).toISOString(),checkout,Number(d.total_cop),serializarFecha_(d.created_at),1);hojas.pedidos.getRange(pedido.row,1,1,PEDIDOS_HEADERS.length).setValues([final]);SpreadsheetApp.flush();if(!verificarReservaFinalCheckout_(checkout,hojas,journal,'PRE_COMMIT_WITH_JOURNAL'))throw errorCheckout_('CONSISTENCY_UNCERTAIN');actualizarStageJournalCheckout_(journal,'FINALIZED');borrarJournalCheckout_(checkout.idempotencyKey);return respuestaExitoCheckout_(final,true); }
function verificarReservaFinalCheckout_(checkout,hojas,journal,mode) { const orders=leerFilasCheckout_(hojas.pedidos,PEDIDOS_HEADERS).filter(function(x){return String(x.d.checkout_idempotency_key||'')===checkout.idempotencyKey;});if(orders.length!==1)return false;const d=orders[0].d;if(String(d.checkout_payload_hash)!==checkout.payloadHash||String(d.status)!=='PENDING'||String(d.payment_status)!=='PENDING'||String(d.reservation_status)!=='ACTIVE'||!d.reference||!d.reservation_expires_at||new Date(d.reservation_expires_at).getTime()<=Date.now()||!Number.isSafeInteger(Number(d.total_cop))||Number(d.revision)!==1)return false;if(journal&&!journalCompatibleCheckout_(journal,checkout,d))return false;const j=journal||{o:d.order_id,f:0,n:checkout.items.length};if(!journal){const rows=leerFilasCheckout_(hojas.items,PEDIDO_ITEMS_HEADERS).filter(function(x){return Number(x.d.order_id)===Number(d.order_id);});if(rows.length!==checkout.items.length)return false;let total=0,seen={};for(let i=0;i<rows.length;i++){const x=rows[i].d,q=Number(x.quantity),price=Number(x.unit_price_cop),item=checkout.items.filter(function(a){return a.product_id===Number(x.product_id)&&a.quantity===q;})[0];if(!item||seen[x.order_item_id]||!Number.isSafeInteger(price)||price<0||!String(x.product_name||'').trim())return false;seen[x.order_item_id]=true;total+=price*q;}return total===Number(d.total_cop);}if(clasificarItemsCheckout_(hojas,j,checkout)!=='COMPLETE_EXACT')return false;return mode!=='PRE_COMMIT_WITH_JOURNAL'||clasificarProductosCheckout_(hojas,journal).every(function(s){return s==='AFTER_EXACT';}); }

function resolverJournalSinPedidoCheckout_(journal, checkout, hojas) {
  if (!journalCompatibleCheckout_(journal, checkout, null) || journal.s !== 'PREPARED') throw errorCheckout_('CONSISTENCY_UNCERTAIN');
  const items = leerFilasCheckout_(hojas.items,PEDIDO_ITEMS_HEADERS).filter(function(x){return Number(x.d.order_id) === Number(journal.o);});
  const productos = leerProductosCheckout_(hojas.productos); const byId={}; productos.forEach(function(x){byId[x.id]=x;});
  const intact = journal.p.every(function(p){const x=byId[p[0]]; return x && x.inventory===p[1] && x.revision===p[3];});
  if (items.length || !intact) throw errorCheckout_('CONSISTENCY_UNCERTAIN');
  protegerIdsJournalCheckout_(journal);
  borrarJournalCheckout_(checkout.idempotencyKey);
}

function obtenerHojasCheckout_() {
  const ss=SpreadsheetApp.getActiveSpreadsheet();
  const hojas={productos:obtenerHojaProductos_(),pedidos:ss.getSheetByName('Pedidos'),items:ss.getSheetByName('PedidoItems'),pagos:ss.getSheetByName('Pagos')};
  if (!hojas.pedidos || !hojas.items || !hojas.pagos) throw errorCheckout_('INTERNAL_ERROR');
  validarEncabezadosProductos_(hojas.productos); validarEncabezadosCheckout_(hojas.pedidos,PEDIDOS_HEADERS); validarEncabezadosCheckout_(hojas.items,PEDIDO_ITEMS_HEADERS); validarEncabezadosCheckout_(hojas.pagos,PAGOS_HEADERS);
  return hojas;
}
function validarEncabezadosCheckout_(hoja, headers) { if(hoja.getLastColumn()!==headers.length||!encabezadosCoinciden_(hoja.getRange(1,1,1,headers.length).getValues()[0],headers)) throw errorCheckout_('INTERNAL_ERROR'); }
function leerFilasCheckout_(hoja,headers) { const last=hoja.getLastRow(); if(last<=1)return []; return hoja.getRange(2,1,last-1,headers.length).getValues().map(function(values,index){return {row:index+2,values:values};}).filter(function(x){return x.values.some(function(v){return v!=='';});}).map(function(x){const d={};headers.forEach(function(h,j){d[h]=x.values[j];});return {row:x.row,d:d,values:x.values};}); }
function leerProductosCheckout_(hoja) { const seen={}; return leerFilasCheckout_(hoja,PRODUCT_HEADERS).map(function(x){const d=x.d,id=enteroCheckout_(d.product_id,1,2147483647);if(seen[id])throw errorCheckout_('CONSISTENCY_UNCERTAIN');seen[id]=true;const inventory=enteroCheckout_(d.inventory,0,2147483647),price=enteroCheckout_(d.price_cop,0,2147483647),revision=enteroCheckout_(d.revision,1,2147483647),name=String(d.name||'').trim();if(!name)throw errorCheckout_('CONSISTENCY_UNCERTAIN');return {id:id,row:x.row,values:x.values,inventory:inventory,price:price,revision:revision,active:d.active===true||String(d.active).trim().toLowerCase()==='true',name:name};}); }
function planificarProductosCheckout_(items,productos) { const by={};productos.forEach(function(p){by[p.id]=p;});let total=0;const plan=items.map(function(i){const p=by[i.product_id];if(!p)throw errorCheckout_('PRODUCT_NOT_FOUND');if(!p.active)throw errorCheckout_('PRODUCT_INACTIVE');if(p.inventory<i.quantity)throw errorCheckout_('INSUFFICIENT_STOCK');total+=p.price*i.quantity;if(!Number.isSafeInteger(total))throw errorCheckout_('INVALID_REQUEST');return {id:p.id,row:p.row,values:p.values,name:p.name,price:p.price,quantity:i.quantity,beforeInventory:p.inventory,afterInventory:p.inventory-i.quantity,beforeRevision:p.revision,afterRevision:p.revision+1};});return {items:plan,total:total}; }
function planificarIdsCheckout_(hojas) { const props=PropertiesService.getScriptProperties();return {orderId:siguienteIdCheckout_(props,'next_order_id',leerIdsCheckout_(hojas.pedidos,'order_id')),firstItemId:siguienteIdCheckout_(props,'next_order_item_id',leerIdsCheckout_(hojas.items,'order_item_id')),paymentId:siguienteIdCheckout_(props,'next_payment_attempt_id',leerIdsCheckout_(hojas.pagos,'payment_attempt_id'))}; }
function leerIdsCheckout_(hoja,campo) { const headers=campo==='order_id'?PEDIDOS_HEADERS:campo==='order_item_id'?PEDIDO_ITEMS_HEADERS:PAGOS_HEADERS;const seen={};return leerFilasCheckout_(hoja,headers).map(function(x){const id=enteroCheckout_(x.d[campo],1,2147483647);if(seen[id])throw errorCheckout_('CONSISTENCY_UNCERTAIN');seen[id]=true;return id;}); }
function siguienteIdCheckout_(props,key,ids) { const max=ids.reduce(function(a,b){return Math.max(a,b);},0);const min=max+1;const raw=props.getProperty(key);if(raw===null)return min;if(!/^[1-9]\d*$/.test(raw))throw errorCheckout_('CONSISTENCY_UNCERTAIN');const value=Number(raw);if(!Number.isSafeInteger(value)||value>2147483647)throw errorCheckout_('CONSISTENCY_UNCERTAIN');return Math.max(value,min); }
function elevarContadorCheckout_(props,key,min) { if(!Number.isSafeInteger(min)||min<1||min>2147483647)throw errorCheckout_('CONSISTENCY_UNCERTAIN');const raw=props.getProperty(key);if(raw===null){props.setProperty(key,String(min));return;}if(!/^[1-9]\d*$/.test(raw))throw errorCheckout_('CONSISTENCY_UNCERTAIN');const current=Number(raw);if(!Number.isSafeInteger(current)||current>2147483647)throw errorCheckout_('CONSISTENCY_UNCERTAIN');if(current<min)props.setProperty(key,String(min)); }
function protegerIdsJournalCheckout_(j) { const p=PropertiesService.getScriptProperties();elevarContadorCheckout_(p,'next_order_id',Number(j.o)+1);elevarContadorCheckout_(p,'next_order_item_id',Number(j.f)+Number(j.n)); }
function avanzarContadoresCheckout_(ids) { const p=PropertiesService.getScriptProperties();elevarContadorCheckout_(p,'next_order_id',ids.orderId+1);elevarContadorCheckout_(p,'next_order_item_id',ids.firstItemId+ids.itemCount);elevarContadorCheckout_(p,'next_payment_attempt_id',ids.paymentId); }
function generarReferenceCheckout_(orderId,now,pedidos) { const date=Utilities.formatDate(now,'America/Bogota','yyyyMMdd');const used={};leerFilasCheckout_(pedidos,PEDIDOS_HEADERS).forEach(function(x){used[String(x.d.reference)]=true;});for(let i=0;i<5;i++){const random=Utilities.getUuid().replace(/-/g,'').substring(0,8).toUpperCase(),ref='TTV-'+date+'-'+orderId.toString(36).toUpperCase()+'-'+random;if(!used[ref])return ref;}throw errorCheckout_('INTERNAL_ERROR'); }
function filaPedidoCheckout_(id,ref,status,payment,reservation,expires,checkout,total,created,revision) { const c=checkout.customer;return [id,ref,status,payment,reservation,expires,'','',checkout.idempotencyKey,checkout.payloadHash,'','','',c.name,c.email,c.phone,c.document,c.address,c.extra===null?'':c.extra,c.city,c.region,c.postal===null?'':c.postal,total,created,created,revision]; }
function verificarPedidoCheckout_(sheet,row,id,key,status) { const d=leerFilasCheckout_(sheet,PEDIDOS_HEADERS).filter(function(x){return x.row===row;})[0];return d&&Number(d.d.order_id)===id&&String(d.d.checkout_idempotency_key)===key&&String(d.d.status)===status; }
function verificarItemsCheckout_(sheet,row,rows) { const actual=sheet.getRange(row,1,rows.length,PEDIDO_ITEMS_HEADERS.length).getValues();return JSON.stringify(actual)===JSON.stringify(rows); }
function escribirProductosCheckout_(sheet,plan,updated) { plan.items.forEach(function(x){const row=x.values.slice();row[6]=x.afterInventory;row[11]=updated;row[12]=x.afterRevision;sheet.getRange(x.row,1,1,PRODUCT_HEADERS.length).setValues([row]);}); }
function verificarProductosCheckout_(sheet,plan) { const rows=leerProductosCheckout_(sheet),byRow={};rows.forEach(function(x){byRow[x.row]=x;});return plan.items.every(function(x){const actual=byRow[x.row];return actual&&actual.id===x.id&&actual.inventory===x.afterInventory&&actual.revision===x.afterRevision;}); }
function guardarJournalCheckout_(j) { const p=PropertiesService.getScriptProperties(), key=CHECKOUT_JOURNAL_PREFIX+j.k;if(!p.getProperty(key)&&Object.keys(p.getProperties()).filter(function(k){return k.indexOf(CHECKOUT_JOURNAL_PREFIX)===0;}).length>=CHECKOUT_JOURNAL_MAX_ACTIVE)throw errorCheckout_('INTERNAL_ERROR');const raw=JSON.stringify(j);if(Utilities.newBlob(raw).getBytes().length>CHECKOUT_JOURNAL_MAX_BYTES)throw errorCheckout_('INVALID_REQUEST');p.setProperty(key,raw); }
function leerJournalCheckout_(key) { const raw=PropertiesService.getScriptProperties().getProperty(CHECKOUT_JOURNAL_PREFIX+key);if(!raw)return null;try{const j=JSON.parse(raw);if(!j||j.v!==1||j.k!==key||!Array.isArray(j.p))throw new Error();return j;}catch(e){throw errorCheckout_('CONSISTENCY_UNCERTAIN');} }
function actualizarStageJournalCheckout_(j,stage) { j.s=stage;guardarJournalCheckout_(j); }
function borrarJournalCheckout_(key) { PropertiesService.getScriptProperties().deleteProperty(CHECKOUT_JOURNAL_PREFIX+key); }
function journalCompatibleCheckout_(j,checkout,order) { return j&&j.v===1&&j.k===checkout.idempotencyKey&&j.h===checkout.payloadHash&&Number.isInteger(Number(j.o))&&typeof j.r==='string'&&(!order||(Number(order.order_id)===Number(j.o)&&String(order.reference)===j.r)); }
function respuestaExitoCheckout_(row,replayed) { const d={};PEDIDOS_HEADERS.forEach(function(h,i){d[h]=row[i];});return respuestaJson_({ok:true,data:{order_id:Number(d.order_id),reference:String(d.reference),status:String(d.status),payment_status:String(d.payment_status),reservation_status:String(d.reservation_status),reservation_expires_at:serializarFecha_(d.reservation_expires_at),total_cop:Number(d.total_cop),created_at:serializarFecha_(d.created_at),revision:Number(d.revision),idempotency_replayed:replayed}}); }
function filaDesdeDatosCheckout_(d,headers) { return headers.map(function(h){return d[h];}); }
function respuestaErrorCheckout_(code) { return respuestaJson_({ok:false,error:{code:code}}); }
function errorCheckout_(code) { return {checkoutCode:code}; }

function normalizarCheckout_(s) { if(!s||typeof s!=='object'||Array.isArray(s)||!uuidV4Checkout_(s.idempotency_key)||!s.customer||!Array.isArray(s.items))throw errorCheckout_('INVALID_REQUEST');const c=s.customer;const customer={name:textoCheckout_(c.name,2,120),email:emailCheckout_(c.email),phone:telefonoCheckout_(c.phone),document:documentoCheckout_(c.document),address:textoCheckout_(c.address,5,300),extra:textoNullableCheckout_(c.extra,300),city:textoCheckout_(c.city,2,100),region:textoCheckout_(c.region,2,100),postal:postalCheckout_(c.postal)};const items=itemsCheckout_(s.items);const canonical=canonicalCheckout_({customer:customer,items:items});return {idempotencyKey:s.idempotency_key,payloadHash:s.payload_hash,customer:customer,items:items,canonical:canonical}; }
function textoBaseCheckout_(value) { if(typeof value!=='string'||/[\uD800-\uDBFF](?![\uDC00-\uDFFF])|(?<![\uD800-\uDBFF])[\uDC00-\uDFFF]/.test(value))throw errorCheckout_('INVALID_REQUEST');const t=value.normalize('NFC').replace(/[\u0009-\u000D\u0020\u0085\u00A0\u1680\u2000-\u200A\u2028\u2029\u202F\u205F\u3000]+/g,' ').trim();if(/[\u0000-\u0008\u000E-\u001F\u007F]/.test(t))throw errorCheckout_('INVALID_REQUEST');return t; }
function textoCheckout_(v,min,max) { const t=textoBaseCheckout_(v),n=Array.from(t).length;if(n<min||n>max)throw errorCheckout_('INVALID_REQUEST');return t; }
function textoNullableCheckout_(v,max) { if(v===null||v===undefined)return null;const t=textoBaseCheckout_(v);if(!t)return null;if(Array.from(t).length>max)throw errorCheckout_('INVALID_REQUEST');return t; }
function emailCheckout_(v) { const t=textoBaseCheckout_(v);if(/[^\x00-\x7F]/.test(t)||t.length<3||t.length>254||! /^[A-Z0-9.!#$%&'*+/=?^_`{|}~-]+@[A-Z0-9](?:[A-Z0-9-]{0,61}[A-Z0-9])?(?:\.[A-Z0-9](?:[A-Z0-9-]{0,61}[A-Z0-9])?)+$/i.test(t))throw errorCheckout_('INVALID_REQUEST');return t.toLowerCase(); }
function telefonoCheckout_(v) { if(typeof v!=='string')throw errorCheckout_('INVALID_REQUEST');const t=v.normalize('NFC').replace(/[ \-()]/g,'');if(!/^(?:3\d{9}|573\d{9}|\+573\d{9})$/.test(t))throw errorCheckout_('INVALID_REQUEST');return t.indexOf('+')===0?t:t.indexOf('57')===0?'+'+t:'+57'+t; }
function documentoCheckout_(v) { const t=textoCheckout_(v,3,30);if(!/^[\p{L}\p{N} .-]+$/u.test(t))throw errorCheckout_('INVALID_REQUEST');return t; }
function postalCheckout_(v) { if(v===null||v===undefined)return null;const t=textoBaseCheckout_(v);if(!t)return null;const p=t.toUpperCase();if(p.length<3||p.length>20||!/^[A-Z0-9 -]+$/.test(p))throw errorCheckout_('INVALID_REQUEST');return p; }
function itemsCheckout_(items) { const totals={};items.forEach(function(x){if(!x||typeof x!=='object'||Array.isArray(x))throw errorCheckout_('INVALID_REQUEST');const id=enteroCheckout_(x.product_id,1,2147483647),q=enteroCheckout_(x.quantity,1,999);totals[id]=(totals[id]||0)+q;if(totals[id]>999)throw errorCheckout_('INVALID_REQUEST');});const out=Object.keys(totals).map(function(id){return {product_id:Number(id),quantity:totals[id]};}).sort(function(a,b){return a.product_id-b.product_id;});if(!out.length||out.length>50)throw errorCheckout_('INVALID_REQUEST');return out; }
function enteroCheckout_(v,min,max) { if(typeof v!=='number'||!Number.isInteger(v)||v<min||v>max)throw errorCheckout_('INVALID_REQUEST');return v; }

/* Records Laravel-authenticated Wompi events. It never reads or writes Productos. */
const PAYMENT_EVENT_STATUSES = ['PENDING','APPROVED','DECLINED','VOIDED','ERROR'];

function registrarEventoPago_(solicitud) {
  let transaction;
  try { transaction = normalizarEventoPago_(solicitud); } catch (error) { return respuestaErrorCheckout_(error && error.checkoutCode || 'INVALID_REQUEST'); }
  const lock = LockService.getScriptLock();
  if (!lock.tryLock(5000)) return respuestaErrorCheckout_('LOCK_TIMEOUT');
  try {
    return registrarEventoPagoBloqueado_(transaction);
  } catch (error) {
    const code = error && error.checkoutCode;
    console.error(code ? 'record_payment_event: '+code : 'record_payment_event: INTERNAL_ERROR');
    return respuestaErrorCheckout_(code || 'INTERNAL_ERROR');
  } finally { lock.releaseLock(); }
}

function normalizarEventoPago_(solicitud) {
  if (!solicitud || typeof solicitud !== 'object' || Array.isArray(solicitud) || !solicitud.transaction || typeof solicitud.transaction !== 'object' || Array.isArray(solicitud.transaction)) throw errorCheckout_('INVALID_REQUEST');
  const t = solicitud.transaction;
  const id = textoEventoPago_(t.id,1,200);
  const reference = textoEventoPago_(t.reference,1,500);
  if (PAYMENT_EVENT_STATUSES.indexOf(t.status) < 0) throw errorCheckout_('INVALID_REQUEST');
  const paymentMethod = textoEventoPago_(t.payment_method,1,100);
  if (typeof t.amount_in_cents !== 'number' || !Number.isSafeInteger(t.amount_in_cents) || t.amount_in_cents < 1) throw errorCheckout_('INVALID_REQUEST');
  if (typeof t.currency !== 'string' || t.currency !== 'COP') throw errorCheckout_('CURRENCY_MISMATCH');
  const occurred = fechaUtcEventoPago_(t.event_occurred_at, true);
  if (occurred.ms > new Date().getTime() + 10*60*1000) throw errorCheckout_('INVALID_REQUEST');
  return {id:id,reference:reference,status:t.status,paymentMethod:paymentMethod,amount:t.amount_in_cents,currency:t.currency,occurredAt:occurred.iso,occurredMs:occurred.ms};
}

function textoEventoPago_(value,min,max) {
  if (typeof value !== 'string') throw errorCheckout_('INVALID_REQUEST');
  const text = value.trim();
  if (text.length < min || text.length > max) throw errorCheckout_('INVALID_REQUEST');
  return text;
}

function fechaUtcEventoPago_(value, requestValue) {
  if (value instanceof Date && !requestValue && !isNaN(value.getTime())) return {ms:value.getTime(),iso:value.toISOString()};
  if (typeof value !== 'string' || !/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}\.\d{3}Z$/.test(value)) throw errorCheckout_('INVALID_REQUEST');
  const ms = Date.parse(value);
  if (!Number.isSafeInteger(ms) || new Date(ms).toISOString() !== value) throw errorCheckout_('INVALID_REQUEST');
  return {ms:ms,iso:value};
}

function fechaExistenteEventoPago_(value) {
  try { return fechaUtcEventoPago_(value, false); } catch (error) { throw errorCheckout_('CONSISTENCY_UNCERTAIN'); }
}

function obtenerHojasPago_() {
  const ss = SpreadsheetApp.getActiveSpreadsheet();
  const hojas = {pedidos:ss.getSheetByName('Pedidos'),pagos:ss.getSheetByName('Pagos')};
  if (!hojas.pedidos || !hojas.pagos) throw errorCheckout_('INTERNAL_ERROR');
  validarEncabezadosCheckout_(hojas.pedidos,PEDIDOS_HEADERS);
  validarEncabezadosCheckout_(hojas.pagos,PAGOS_HEADERS);
  return hojas;
}

function registrarEventoPagoBloqueado_(transaction) {
  const hojas = obtenerHojasPago_();
  const pedidos = leerFilasCheckout_(hojas.pedidos,PEDIDOS_HEADERS);
  const corruptReference = pedidos.some(function (row) { return typeof row.d.reference === 'string' && row.d.reference.trim() === transaction.reference && row.d.reference !== transaction.reference; });
  if (corruptReference) throw errorCheckout_('CONSISTENCY_UNCERTAIN');
  const matches = pedidos.filter(function (row) { return row.d.reference === transaction.reference; });
  if (matches.length === 0) throw errorCheckout_('ORDER_NOT_FOUND');
  if (matches.length !== 1) throw errorCheckout_('CONSISTENCY_UNCERTAIN');
  const pedido = matches[0];
  const orderState = validarPedidoEventoPago_(pedido,transaction.reference);
  const orderId = enteroEventoPago_(pedido.d.order_id,1,2147483647);
  const total = enteroEventoPago_(pedido.d.total_cop,0,2147483647);
  if (!Number.isSafeInteger(total * 100) || transaction.amount !== total * 100) throw errorCheckout_('AMOUNT_MISMATCH');
  if (transaction.currency !== 'COP') throw errorCheckout_('CURRENCY_MISMATCH');

  const pagos = leerFilasCheckout_(hojas.pagos,PAGOS_HEADERS);
  const transactions = validarPagosGlobalEventoPago_(pagos);
  const existing = transactions[transaction.id] ? transactions[transaction.id].row : null;
  if (existing) return registrarEventoPagoExistente_(hojas,pedido,existing,transaction,orderId,total,orderState);
  return registrarEventoPagoNuevo_(hojas,pedido,pagos,transaction,orderId,total,orderState);
}

function enteroEventoPago_(value,min,max) {
  if (typeof value !== 'number' || !Number.isSafeInteger(value) || value < min || value > max) throw errorCheckout_('CONSISTENCY_UNCERTAIN');
  return value;
}

function textoEventoPagoAlmacenado_(value,min,max) {
  if (typeof value !== 'string' || value !== value.trim() || value.length < min || value.length > max) throw errorCheckout_('CONSISTENCY_UNCERTAIN');
  return value;
}

function fechaOpcionalEventoPago_(value) {
  if (value === '' || value === null || value === undefined) return null;
  return fechaExistenteEventoPago_(value);
}

function validarPedidoEventoPago_(pedido,reference) {
  const d = pedido.d;
  if (textoEventoPagoAlmacenado_(d.reference,1,500) !== reference) throw errorCheckout_('CONSISTENCY_UNCERTAIN');
  enteroEventoPago_(d.order_id,1,2147483647);
  enteroEventoPago_(d.total_cop,0,2147483647);
  const revision = enteroEventoPago_(d.revision,1,2147483647);
  const combination = String(d.status)+'|'+String(d.payment_status)+'|'+String(d.reservation_status);
  if (['PENDING|PENDING|ACTIVE','PENDING|PENDING|RELEASED','PENDING|APPROVED|CONSUMED','PAYMENT_REVIEW_REQUIRED|APPROVED|RELEASED'].indexOf(combination) < 0) throw errorCheckout_('CONSISTENCY_UNCERTAIN');
  const paidAt = fechaOpcionalEventoPago_(d.paid_at);
  const lastEventAt = fechaOpcionalEventoPago_(d.payment_last_event_at);
  if ((combination === 'PENDING|PENDING|ACTIVE' || combination === 'PENDING|PENDING|RELEASED') && paidAt !== null) throw errorCheckout_('CONSISTENCY_UNCERTAIN');
  if ((combination === 'PENDING|APPROVED|CONSUMED' || combination === 'PAYMENT_REVIEW_REQUIRED|APPROVED|RELEASED') && (paidAt === null || lastEventAt === null)) throw errorCheckout_('CONSISTENCY_UNCERTAIN');
  validarMarcaLiberacionPedido_(d, combination);
  return {revision:revision,paidAt:paidAt,lastEventAt:lastEventAt,combination:combination};
}

function validarMarcaLiberacionPedido_(d, combination) {
  const released = combination==='PENDING|PENDING|RELEASED'||combination==='PAYMENT_REVIEW_REQUIRED|APPROVED|RELEASED';
  if (!released) { if (d.release_id!=='' || d.release_fingerprint!=='' || d.released_at!=='') throw errorCheckout_('CONSISTENCY_UNCERTAIN'); return; }
  if (!uuidV4Checkout_(d.release_id) || !hashValidoCheckout_(d.release_fingerprint) || fechaExistenteEventoPago_(d.released_at).iso!==d.released_at) throw errorCheckout_('CONSISTENCY_UNCERTAIN');
}

function validarPagosGlobalEventoPago_(pagos) {
  const ids = {}, transactions = {};
  pagos.forEach(function (pago) {
    const d = pago.d;
    const id = enteroEventoPago_(d.payment_attempt_id,1,2147483647);
    const orderId = enteroEventoPago_(d.order_id,1,2147483647);
    const transactionId = textoEventoPagoAlmacenado_(d.wompi_transaction_id,1,200);
    if (ids[id] || transactions[transactionId]) throw errorCheckout_('CONSISTENCY_UNCERTAIN');
    if (PAYMENT_EVENT_STATUSES.indexOf(d.status) < 0 || textoEventoPagoAlmacenado_(d.payment_method,1,100) === '' || enteroEventoPago_(d.amount_in_cents,1,2147483647) < 1 || d.currency !== 'COP') throw errorCheckout_('CONSISTENCY_UNCERTAIN');
    fechaExistenteEventoPago_(d.created_at);
    fechaExistenteEventoPago_(d.updated_at);
    ids[id] = true; transactions[transactionId] = {row:pago,orderId:orderId};
  });
  return transactions;
}

function incrementarRevisionEventoPago_(revision) {
  if (revision >= 2147483647) throw errorCheckout_('CONSISTENCY_UNCERTAIN');
  return revision+1;
}

function registrarEventoPagoNuevo_(hojas,pedido,pagos,transaction,orderId,total,orderState) {
  const plan = planificarPedidoEventoPago_(pedido,transaction,orderState);
  const props = PropertiesService.getScriptProperties();
  const paymentId = siguienteIdCheckout_(props,'next_payment_attempt_id',leerIdsCheckout_(hojas.pagos,'payment_attempt_id'));
  const createdAt = new Date().toISOString();
  const paymentRow = filaPagoEvento_(paymentId,orderId,transaction,createdAt,transaction.occurredAt);
  const paymentRowNumber = hojas.pagos.getLastRow()+1;
  hojas.pagos.getRange(paymentRowNumber,1,1,PAGOS_HEADERS.length).setValues([paymentRow]);
  elevarContadorCheckout_(props,'next_payment_attempt_id',paymentId+1);
  if (plan.changed) hojas.pedidos.getRange(pedido.row,1,1,PEDIDOS_HEADERS.length).setValues([plan.values]);
  SpreadsheetApp.flush();
  verificarEventoPagoFinal_(hojas,pedido.row,paymentRowNumber,paymentRow,plan.values);
  return respuestaEventoPago_(orderId,paymentId,false,plan.eventResult,plan.values);
}

function registrarEventoPagoExistente_(hojas,pedido,pago,transaction,orderId,total,orderState) {
  const paymentOrderId = enteroEventoPago_(pago.d.order_id,1,2147483647);
  const paymentAmount = enteroEventoPago_(pago.d.amount_in_cents,1,2147483647);
  if (paymentOrderId !== orderId || paymentAmount !== transaction.amount || pago.d.currency !== transaction.currency) throw errorCheckout_('TRANSACTION_CONFLICT');
  const prior = fechaExistenteEventoPago_(pago.d.updated_at);
  const paymentId = enteroEventoPago_(pago.d.payment_attempt_id,1,2147483647);
  if (transaction.occurredMs < prior.ms) return respuestaEventoPago_(orderId,paymentId,false,'STALE_IGNORED',pedido.values);
  const sameTimestamp = transaction.occurredMs === prior.ms;
  const sameFields = pago.d.status === transaction.status && String(pago.d.payment_method || '').trim() === transaction.paymentMethod && paymentAmount === transaction.amount && pago.d.currency === transaction.currency;
  if (sameTimestamp) {
    if (!sameFields) throw errorCheckout_('CONSISTENCY_UNCERTAIN');
    const replay = planificarPedidoEventoPago_(pedido,transaction,orderState);
    if (replay.changed) hojas.pedidos.getRange(pedido.row,1,1,PEDIDOS_HEADERS.length).setValues([replay.values]);
    SpreadsheetApp.flush();
    verificarEventoPagoFinal_(hojas,pedido.row,pago.row,pago.values,replay.values);
    return respuestaEventoPago_(orderId,paymentId,true,replay.eventResult,replay.values);
  }
  const terminalApproved = pago.d.status === 'APPROVED' && transaction.status !== 'APPROVED';
  const effectiveTransaction = terminalApproved ? {id:transaction.id,status:'APPROVED',paymentMethod:pago.d.payment_method,amount:transaction.amount,currency:transaction.currency,occurredAt:transaction.occurredAt,occurredMs:transaction.occurredMs} : transaction;
  const plan = planificarPedidoEventoPago_(pedido,transaction,orderState);
  const paymentValues = filaPagoEvento_(paymentId,orderId,effectiveTransaction,pago.d.created_at,transaction.occurredAt);
  hojas.pagos.getRange(pago.row,1,1,PAGOS_HEADERS.length).setValues([paymentValues]);
  if (plan.changed) hojas.pedidos.getRange(pedido.row,1,1,PEDIDOS_HEADERS.length).setValues([plan.values]);
  SpreadsheetApp.flush();
  verificarEventoPagoFinal_(hojas,pedido.row,pago.row,paymentValues,plan.values);
  return respuestaEventoPago_(orderId,paymentId,false,plan.eventResult,plan.values);
}

function filaPagoEvento_(paymentId,orderId,transaction,createdAt,updatedAt) {
  return [paymentId,orderId,transaction.id,transaction.status,transaction.paymentMethod,transaction.amount,transaction.currency,serializarFecha_(createdAt),updatedAt];
}

/* payment_last_event_at is the latest temporally accepted Wompi event, even after APPROVED. */
function planificarPedidoEventoPago_(pedido,transaction,orderState) {
  const values = pedido.values.slice();
  const d = pedido.d;
  const index = function (name) { return PEDIDOS_HEADERS.indexOf(name); };
  const currentRevision = orderState.revision;
  const priorLast = orderState.lastEventAt;
  const advancesLast = priorLast === null || transaction.occurredMs > priorLast.ms;
  const mutateLast = function () {
    if (!advancesLast) return false;
    values[index('payment_last_event_at')] = transaction.occurredAt;
    values[index('updated_at')] = new Date().toISOString();
    values[index('revision')] = incrementarRevisionEventoPago_(currentRevision);
    return true;
  };
  if (transaction.status !== 'APPROVED') {
    return {changed:mutateLast(),values:values,eventResult:'RECORDED'};
  }
  if (d.reservation_status === 'CONSUMED' && d.payment_status === 'APPROVED') return {changed:mutateLast(),values:values,eventResult:'APPROVED'};
  if (d.reservation_status === 'ACTIVE') {
    values[index('payment_status')] = 'APPROVED';
    values[index('reservation_status')] = 'CONSUMED';
    values[index('paid_at')] = transaction.occurredAt;
    values[index('payment_last_event_at')] = transaction.occurredAt;
    values[index('updated_at')] = new Date().toISOString();
    values[index('revision')] = incrementarRevisionEventoPago_(currentRevision);
    return {changed:true,values:values,eventResult:'APPROVED'};
  }
  if (d.reservation_status === 'RELEASED') {
    let changed = false;
    if (d.payment_status !== 'APPROVED') { values[index('payment_status')] = 'APPROVED'; changed = true; }
    if (d.status !== 'PAYMENT_REVIEW_REQUIRED') { values[index('status')] = 'PAYMENT_REVIEW_REQUIRED'; changed = true; }
    if (!d.paid_at) { values[index('paid_at')] = transaction.occurredAt; changed = true; }
    if (d.payment_last_event_at !== transaction.occurredAt) { values[index('payment_last_event_at')] = transaction.occurredAt; changed = true; }
    if (changed) { values[index('updated_at')] = new Date().toISOString(); values[index('revision')] = incrementarRevisionEventoPago_(currentRevision); }
    return {changed:changed,values:values,eventResult:'PAYMENT_REVIEW_REQUIRED'};
  }
  throw errorCheckout_('CONSISTENCY_UNCERTAIN');
}

function fechaIgualEventoPago_(actual,expected) {
  if (expected === '' || expected === null || expected === undefined) return actual === '' || actual === null || actual === undefined;
  return fechaExistenteEventoPago_(actual).iso === fechaExistenteEventoPago_(expected).iso;
}

function verificarEventoPagoFinal_(hojas,pedidoRow,pagoRow,pagoEsperado,pedidoEsperado) {
  const pedido = leerFilasCheckout_(hojas.pedidos,PEDIDOS_HEADERS).filter(function (row) { return row.row === pedidoRow; })[0];
  const pago = leerFilasCheckout_(hojas.pagos,PAGOS_HEADERS).filter(function (row) { return row.row === pagoRow; })[0];
  if (!pedido || !pago) throw errorCheckout_('CONSISTENCY_UNCERTAIN');
  const expectedPayment = {}; PAGOS_HEADERS.forEach(function (header,index) { expectedPayment[header] = pagoEsperado[index]; });
  const expectedOrder = {}; PEDIDOS_HEADERS.forEach(function (header,index) { expectedOrder[header] = pedidoEsperado[index]; });
  if (enteroEventoPago_(pago.d.payment_attempt_id,1,2147483647) !== enteroEventoPago_(expectedPayment.payment_attempt_id,1,2147483647) || enteroEventoPago_(pago.d.order_id,1,2147483647) !== enteroEventoPago_(expectedPayment.order_id,1,2147483647) || textoEventoPagoAlmacenado_(pago.d.wompi_transaction_id,1,200) !== textoEventoPagoAlmacenado_(expectedPayment.wompi_transaction_id,1,200) || pago.d.status !== expectedPayment.status || textoEventoPagoAlmacenado_(pago.d.payment_method,1,100) !== textoEventoPagoAlmacenado_(expectedPayment.payment_method,1,100) || enteroEventoPago_(pago.d.amount_in_cents,1,2147483647) !== enteroEventoPago_(expectedPayment.amount_in_cents,1,2147483647) || pago.d.currency !== expectedPayment.currency || !fechaIgualEventoPago_(pago.d.created_at,expectedPayment.created_at) || !fechaIgualEventoPago_(pago.d.updated_at,expectedPayment.updated_at)) throw errorCheckout_('CONSISTENCY_UNCERTAIN');
  if (enteroEventoPago_(pedido.d.order_id,1,2147483647) !== enteroEventoPago_(expectedOrder.order_id,1,2147483647) || textoEventoPagoAlmacenado_(pedido.d.reference,1,500) !== textoEventoPagoAlmacenado_(expectedOrder.reference,1,500) || pedido.d.status !== expectedOrder.status || pedido.d.payment_status !== expectedOrder.payment_status || pedido.d.reservation_status !== expectedOrder.reservation_status || !fechaIgualEventoPago_(pedido.d.paid_at,expectedOrder.paid_at) || !fechaIgualEventoPago_(pedido.d.payment_last_event_at,expectedOrder.payment_last_event_at) || !fechaIgualEventoPago_(pedido.d.updated_at,expectedOrder.updated_at) || enteroEventoPago_(pedido.d.revision,1,2147483647) !== enteroEventoPago_(expectedOrder.revision,1,2147483647) || enteroEventoPago_(pedido.d.total_cop,0,2147483647) !== enteroEventoPago_(expectedOrder.total_cop,0,2147483647)) throw errorCheckout_('CONSISTENCY_UNCERTAIN');
}

function respuestaEventoPago_(orderId,paymentId,replayed,eventResult,pedidoValues) {
  const d = {}; PEDIDOS_HEADERS.forEach(function (header,index) { d[header] = pedidoValues[index]; });
  return respuestaJson_({ok:true,data:{order_id:orderId,payment_attempt_id:paymentId,payment_event_replayed:replayed,event_result:eventResult,status:String(d.status),payment_status:String(d.payment_status),reservation_status:String(d.reservation_status),revision:enteroEventoPago_(d.revision,0,2147483647)}});
}

/* Laravel performs Wompi checks; this action never performs HTTP. */
function liberarReservaVencida_(solicitud) {
  let request;
  try { request = normalizarLiberacionReserva_(solicitud); } catch (error) { return respuestaErrorCheckout_('INVALID_REQUEST'); }
  const lock = LockService.getScriptLock();
  if (!lock.tryLock(5000)) return respuestaErrorCheckout_('LOCK_TIMEOUT');
  try {
    return request.mode === 'candidates' ? candidatosLiberacionBloqueado_(request) : confirmarLiberacionesBloqueado_(request);
  } catch (error) {
    const code = error && error.checkoutCode;
    console.error(code ? 'release_expired_reservation: '+code : 'release_expired_reservation: INTERNAL_ERROR');
    return respuestaErrorCheckout_(code || 'INTERNAL_ERROR');
  } finally { lock.releaseLock(); }
}

function normalizarLiberacionReserva_(solicitud) {
  if (!solicitud || typeof solicitud !== 'object' || Array.isArray(solicitud) || (solicitud.mode !== 'candidates' && solicitud.mode !== 'commit')) throw errorCheckout_('INVALID_REQUEST');
  if (solicitud.mode === 'candidates') {
    const limit = solicitud.limit === undefined ? 20 : solicitud.limit;
    if (typeof limit !== 'number' || !Number.isInteger(limit) || limit < 1 || limit > 50) throw errorCheckout_('INVALID_REQUEST');
    return {mode:'candidates',limit:limit};
  }
  if (!Array.isArray(solicitud.releases) || solicitud.releases.length < 1 || solicitud.releases.length > 20) throw errorCheckout_('INVALID_REQUEST');
  const seen = Object.create(null);
  const releases = solicitud.releases.map(function (release) {
    if (!release || typeof release !== 'object' || Array.isArray(release)) throw errorCheckout_('INVALID_REQUEST');
    const reference = normalizarReferenciaEstadoCheckout_({reference:release.reference});
    if (seen[reference] || typeof release.expected_revision !== 'number' || !Number.isInteger(release.expected_revision) || release.expected_revision < 1 || release.expected_revision > 2147483647 || !Array.isArray(release.verified_final_attempts)) throw errorCheckout_('INVALID_REQUEST');
    seen[reference] = true;
    const attempts = Object.create(null);
    const verified = release.verified_final_attempts.map(function (attempt) {
      if (!attempt || typeof attempt !== 'object' || Array.isArray(attempt)) throw errorCheckout_('INVALID_REQUEST');
      const id = textoEventoPagoAlmacenado_(attempt.wompi_transaction_id,1,200);
      if (attempts[id] || ['DECLINED','VOIDED','ERROR'].indexOf(attempt.status) < 0) throw errorCheckout_('INVALID_REQUEST');
      const checked = fechaUtcEventoPago_(attempt.checked_at,true);
      if (checked.ms > new Date().getTime() + 10*60*1000) throw errorCheckout_('INVALID_REQUEST');
      attempts[id] = true;
      return {id:id,status:attempt.status,checkedAt:checked.iso,checkedMs:checked.ms};
    });
    return {reference:reference,revision:release.expected_revision,verified:verified};
  });
  return {mode:'commit',releases:releases};
}

function hojasLiberacion_(includeCommit) {
  const ss = SpreadsheetApp.getActiveSpreadsheet();
  const hojas = {pedidos:ss.getSheetByName('Pedidos'),pagos:ss.getSheetByName('Pagos')};
  if (includeCommit) { hojas.items=ss.getSheetByName('PedidoItems'); hojas.productos=ss.getSheetByName('Productos'); }
  if (!hojas.pedidos || !hojas.pagos || (includeCommit && (!hojas.items || !hojas.productos))) throw errorCheckout_('INTERNAL_ERROR');
  validarEncabezadosCheckout_(hojas.pedidos,PEDIDOS_HEADERS); validarEncabezadosCheckout_(hojas.pagos,PAGOS_HEADERS);
  if (includeCommit) { validarEncabezadosCheckout_(hojas.items,PEDIDO_ITEMS_HEADERS); validarEncabezadosProductos_(hojas.productos); }
  return hojas;
}

function validarEstadoGlobalLiberacion_(pedidos,pagos) {
  validarPedidosEstadoCheckoutGlobal_(pedidos);
  const orderIds = Object.create(null); pedidos.forEach(function (pedido) { orderIds[pedido.d.order_id]=true; datosEstadoCheckout_(pedido,pedido.d.reference); });
  validarPagosGlobalEventoPago_(pagos);
  pagos.forEach(function (pago) { if (!orderIds[pago.d.order_id]) throw errorCheckout_('CONSISTENCY_UNCERTAIN'); });
}

function vencidaConGraciaLiberacion_(value,nowMs) {
  const expires = fechaExistenteEventoPago_(value);
  return nowMs >= expires.ms + CHECKOUT_RELEASE_GRACE_MINUTES*60000;
}

function candidatosLiberacionBloqueado_(request) {
  const hojas = hojasLiberacion_(false), pedidos = leerFilasCheckout_(hojas.pedidos,PEDIDOS_HEADERS), pagos = leerFilasCheckout_(hojas.pagos,PAGOS_HEADERS);
  validarEstadoGlobalLiberacion_(pedidos,pagos);
  const byOrder = Object.create(null); pagos.forEach(function (pago) { (byOrder[pago.d.order_id] || (byOrder[pago.d.order_id]=[])).push(pago); });
  const nowMs = new Date().getTime(), candidates=[];
  pedidos.forEach(function (pedido) {
    const d=pedido.d, attempts=byOrder[d.order_id]||[];
    if (d.status !== 'PENDING' || d.payment_status !== 'PENDING' || d.reservation_status !== 'ACTIVE' || !vencidaConGraciaLiberacion_(d.reservation_expires_at,nowMs)) return;
    if (attempts.some(function (attempt) { return attempt.d.status === 'APPROVED'; })) return;
    if (candidates.length < request.limit) candidates.push({order_id:d.order_id,reference:d.reference,revision:d.revision,reservation_expires_at:fechaExistenteEventoPago_(d.reservation_expires_at).iso,total_cop:enteroEventoPago_(d.total_cop,0,2147483647),payment_attempts:attempts.map(function (attempt) { const a=attempt.d; return {wompi_transaction_id:a.wompi_transaction_id,status:a.status,amount_in_cents:a.amount_in_cents,currency:a.currency,updated_at:fechaExistenteEventoPago_(a.updated_at).iso}; })});
  });
  return respuestaJson_({ok:true,data:{candidates:candidates}});
}

function leerTablaLiberacion_(sheet,headers) {
  const last=sheet.getLastRow(), all=last<=1?[]:sheet.getRange(2,1,last-1,headers.length).getValues();
  const rows=all.map(function(values,index){const d={};headers.forEach(function(h,i){d[h]=values[i];});return {row:index+2,d:d,values:values};}).filter(function(row){return row.values.some(function(v){return v!=='';});});
  return {all:all,rows:rows};
}

function validarItemsYProductosLiberacion_(items,productos,orderIds) {
  const itemIds=Object.create(null), productRows=Object.create(null);
  items.forEach(function (item) { const d=item.d,id=enteroEventoPago_(d.order_item_id,1,2147483647),orderId=enteroEventoPago_(d.order_id,1,2147483647); if(itemIds[id]||!orderIds[orderId]||enteroEventoPago_(d.product_id,1,2147483647)<1||enteroEventoPago_(d.quantity,1,999)<1||enteroEventoPago_(d.unit_price_cop,1,2147483647)<1||textoEventoPagoAlmacenado_(d.product_name,1,500)==='')throw errorCheckout_('CONSISTENCY_UNCERTAIN'); itemIds[id]=true; });
  productos.forEach(function (product) { const d=product.d,id=enteroEventoPago_(d.product_id,1,2147483647); if(productRows[id]||enteroEventoPago_(d.inventory,0,2147483647)<0||enteroEventoPago_(d.revision,1,2147483647)<1)throw errorCheckout_('CONSISTENCY_UNCERTAIN'); productRows[id]=product; });
  return productRows;
}

function confirmarLiberacionesBloqueado_(request) {
  const hojas=hojasLiberacion_(true), tablas={pedidos:leerTablaLiberacion_(hojas.pedidos,PEDIDOS_HEADERS),pagos:leerTablaLiberacion_(hojas.pagos,PAGOS_HEADERS),items:leerTablaLiberacion_(hojas.items,PEDIDO_ITEMS_HEADERS),productos:leerTablaLiberacion_(hojas.productos,PRODUCT_HEADERS)};
  validarEstadoGlobalLiberacion_(tablas.pedidos.rows,tablas.pagos.rows);
  const orders=Object.create(null); tablas.pedidos.rows.forEach(function(p){orders[p.d.reference]=p;}); const orderIds=Object.create(null); tablas.pedidos.rows.forEach(function(p){orderIds[p.d.order_id]=true;});
  const productRows=validarItemsYProductosLiberacion_(tablas.items.rows,tablas.productos.rows,orderIds), paymentsByOrder=Object.create(null), itemsByOrder=Object.create(null);
  tablas.pagos.rows.forEach(function(p){(paymentsByOrder[p.d.order_id]||(paymentsByOrder[p.d.order_id]=[])).push(p);}); tablas.items.rows.forEach(function(i){(itemsByOrder[i.d.order_id]||(itemsByOrder[i.d.order_id]=[])).push(i);});
  const now=new Date(), nowMs=now.getTime(), plans=[], held=[], review=[];
  request.releases.forEach(function(release){
    const order=orders[release.reference]; if(!order) throw errorCheckout_('ORDER_NOT_FOUND');
    const recovered=recuperarPlanLiberacion_(order,release,paymentsByOrder[order.d.order_id]||[],productRows,itemsByOrder[order.d.order_id]||[]);
    if(recovered){ plans.push(recovered); return; }
    if(esPedidoLiberadoDurable_(order.d)) { const replay=planReplayLiberacionSinJournal_(order,release,itemsByOrder[order.d.order_id]||[]); if(replay){plans.push(replay);return;} held.push({reference:release.reference,reason:'REVISION_CONFLICT'});return; }
    if(order.d.revision!==release.revision){held.push({reference:release.reference,reason:'REVISION_CONFLICT'});return;}
    if(order.d.status!=='PENDING'||order.d.payment_status!=='PENDING'||order.d.reservation_status!=='ACTIVE'||!vencidaConGraciaLiberacion_(order.d.reservation_expires_at,nowMs)){held.push({reference:release.reference,reason:'RESERVATION_NOT_ELIGIBLE'});return;}
    const attempts=paymentsByOrder[order.d.order_id]||[];
    if(attempts.some(function(p){return p.d.status==='APPROVED';})){review.push({reference:release.reference,reason:'PAYMENT_APPROVED'});return;}
    try { const plan=planLiberacionPedido_(order,release,attempts,itemsByOrder[order.d.order_id]||[],productRows,now.toISOString()); aplicarPlanProductosMutableLiberacion_(plan,productRows); plans.push(plan); } catch (error) { if(error&&error.checkoutCode==='PENDING_PAYMENT_UNVERIFIED'){held.push({reference:release.reference,reason:'PENDING_PAYMENT_UNVERIFIED'});return;} throw error; }
  });
  marcarProductosFinalesLiberacion_(plans);
  prepararCapacidadJournalsLiberacion_(plans.filter(function(p){return !p.replay;}),tablas);
  const writes={pedidos:false,pagos:false,productos:false};
  plans.forEach(function(plan){ if(plan.replay)return; guardarJournalLiberacion_(plan); plan.products.forEach(function(change){const row=tablas.productos.all[change.row-2];row[6]=change.afterInventory;row[11]=plan.now;row[12]=change.afterRevision;writes.productos=true;}); });
  if(writes.productos) hojas.productos.getRange(2,1,tablas.productos.all.length,PRODUCT_HEADERS.length).setValues(tablas.productos.all);
  if(writes.productos){const phaseProducts=leerFilasCheckout_(hojas.productos,PRODUCT_HEADERS),by=Object.create(null);phaseProducts.forEach(function(p){by[p.d.product_id]=p;});plans.filter(function(p){return !p.replay;}).forEach(function(plan){if(!(plan.verificationProducts||plan.products).every(function(p){return by[p.id]&&enteroEventoPago_(by[p.id].d.inventory,0,2147483647)===p.afterInventory&&enteroEventoPago_(by[p.id].d.revision,1,2147483647)===p.afterRevision&&fechaExistenteEventoPago_(by[p.id].d.updated_at).iso===p.afterUpdated;}))throw errorCheckout_('CONSISTENCY_UNCERTAIN');actualizarJournalLiberacion_(plan.order.d.reference,'PRODUCTS_WRITTEN');});}
  plans.forEach(function(plan){ if(plan.replay)return; plan.payments.forEach(function(change){const row=tablas.pagos.all[change.row-2];row[3]=change.status;row[8]=change.updatedAt;writes.pagos=true;}); });
  if(writes.pagos) hojas.pagos.getRange(2,1,tablas.pagos.all.length,PAGOS_HEADERS.length).setValues(tablas.pagos.all);
  if(writes.pagos){const phasePayments=leerFilasCheckout_(hojas.pagos,PAGOS_HEADERS);plans.filter(function(p){return !p.replay;}).forEach(function(plan){if(!verificarPagosLiberacionFinal_(phasePayments,plan))throw errorCheckout_('CONSISTENCY_UNCERTAIN');actualizarJournalLiberacion_(plan.order.d.reference,'PAYMENTS_WRITTEN');});}
  plans.forEach(function(plan){ if(plan.replay)return; const row=tablas.pedidos.all[plan.order.row-2],idx=function(n){return PEDIDOS_HEADERS.indexOf(n);};row[idx('status')]='PENDING';row[idx('payment_status')]='PENDING';row[idx('reservation_status')]='RELEASED';row[idx('release_id')]=plan.releaseId;row[idx('release_fingerprint')]=plan.fingerprint;row[idx('released_at')]=plan.now;row[idx('updated_at')]=plan.now;row[idx('revision')]=plan.revision;writes.pedidos=true; });
  if(writes.pedidos) hojas.pedidos.getRange(2,1,tablas.pedidos.all.length,PEDIDOS_HEADERS.length).setValues(tablas.pedidos.all);
  if(writes.pedidos){const phaseOrders=leerFilasCheckout_(hojas.pedidos,PEDIDOS_HEADERS);plans.filter(function(p){return !p.replay;}).forEach(function(plan){if(!verificarPedidoLiberacionFinal_(phaseOrders,plan))throw errorCheckout_('CONSISTENCY_UNCERTAIN');actualizarJournalLiberacion_(plan.order.d.reference,'ORDER_WRITTEN');});}
  if(writes.productos||writes.pedidos||writes.pagos) SpreadsheetApp.flush();
  const finalRows={pedidos:leerFilasCheckout_(hojas.pedidos,PEDIDOS_HEADERS),productos:leerFilasCheckout_(hojas.productos,PRODUCT_HEADERS),pagos:leerFilasCheckout_(hojas.pagos,PAGOS_HEADERS)};
  const released=[]; plans.forEach(function(plan){ if(!verificarLiberacionFinalDatos_(finalRows,plan)) throw errorCheckout_('CONSISTENCY_UNCERTAIN'); if(!plan.replay)actualizarJournalLiberacion_(plan.order.d.reference,'FINALIZED'); released.push({order_id:plan.order.d.order_id,reference:plan.order.d.reference,reservation_status:'RELEASED',revision:plan.revision,idempotency_replayed:!!plan.replay}); });
  return respuestaJson_({ok:true,data:{released:released,held:held,review_required:review}});
}

function planLiberacionPedido_(order,release,attempts,items,productRows,now) {
  const verified=Object.create(null); release.verified.forEach(function(v){verified[v.id]=v;}); const payments=[];
  attempts.forEach(function(payment){const d=payment.d,v=verified[d.wompi_transaction_id];if(d.status==='PENDING'){if(!v)throw errorCheckout_('PENDING_PAYMENT_UNVERIFIED');const prior=fechaExistenteEventoPago_(d.updated_at);if(v.checkedMs<prior.ms)throw errorCheckout_('CONSISTENCY_UNCERTAIN');payments.push({row:payment.row,paymentAttemptId:enteroEventoPago_(d.payment_attempt_id,1,2147483647),orderId:enteroEventoPago_(d.order_id,1,2147483647),status:v.status,updatedAt:v.checkedAt,beforeUpdated:prior.iso,id:d.wompi_transaction_id,paymentMethod:textoEventoPagoAlmacenado_(d.payment_method,1,100),amount:enteroEventoPago_(d.amount_in_cents,1,2147483647),currency:d.currency,createdAt:fechaExistenteEventoPago_(d.created_at).iso});}else if(v)throw errorCheckout_('CONSISTENCY_UNCERTAIN');});
  Object.keys(verified).forEach(function(id){if(!attempts.some(function(p){return p.d.wompi_transaction_id===id;}))throw errorCheckout_('CONSISTENCY_UNCERTAIN');});
  if(!items.length)throw errorCheckout_('CONSISTENCY_UNCERTAIN'); const quantities=Object.create(null);
  items.forEach(function(item){const d=item.d;quantities[d.product_id]=(quantities[d.product_id]||0)+d.quantity;if(!Number.isSafeInteger(quantities[d.product_id])||quantities[d.product_id]>2147483647)throw errorCheckout_('CONSISTENCY_UNCERTAIN');});
  const products=Object.keys(quantities).map(function(id){const product=productRows[id];if(!product)throw errorCheckout_('CONSISTENCY_UNCERTAIN');const d=product.d,after=d.inventory+quantities[id];if(!Number.isSafeInteger(after)||after>2147483647||d.revision>=2147483647)throw errorCheckout_('CONSISTENCY_UNCERTAIN');return {row:product.row,id:d.product_id,beforeInventory:d.inventory,afterInventory:after,beforeRevision:d.revision,afterRevision:d.revision+1,beforeUpdated:fechaOpcionalEventoPago_(d.updated_at)===null?null:fechaExistenteEventoPago_(d.updated_at).iso,afterUpdated:now};});
  if(order.d.revision>=2147483647)throw errorCheckout_('CONSISTENCY_UNCERTAIN'); const fingerprint=calcularFingerprintLiberacion_(order,release,items); const releaseId=Utilities.getUuid(); return {order:order,products:products,payments:payments,now:now,revision:order.d.revision+1,replay:false,releaseId:releaseId,fingerprint:fingerprint,expectedOrder:{orderId:order.d.order_id,reference:order.d.reference,status:'PENDING',paymentStatus:'PENDING',reservationStatus:'RELEASED',paidAt:fechaOpcionalEventoPago_(order.d.paid_at),lastEventAt:fechaOpcionalEventoPago_(order.d.payment_last_event_at),total:enteroEventoPago_(order.d.total_cop,0,2147483647),updatedAt:now,releasedAt:now,releaseId:releaseId,fingerprint:fingerprint,revision:order.d.revision+1}};
}

/* Batch plans share this mutable view, so a product restored by A is the exact BEFORE state for B. */
function aplicarPlanProductosMutableLiberacion_(plan,productRows) {
  plan.products.forEach(function(change) {
    const product = productRows[change.id];
    if (!product || product.row !== change.row) throw errorCheckout_('CONSISTENCY_UNCERTAIN');
    product.d.inventory = change.afterInventory;
    product.d.revision = change.afterRevision;
    product.d.updated_at = change.afterUpdated;
  });
}

/* Only the last plan touching a product can require that product's final batch value on reread. */
function marcarProductosFinalesLiberacion_(plans) {
  const latest=Object.create(null);
  plans.filter(function(plan){return !plan.replay;}).forEach(function(plan){plan.products.forEach(function(change){latest[change.id]=plan;});});
  plans.forEach(function(plan){plan.verificationProducts=(plan.products||[]).filter(function(change){return latest[change.id]===plan;});});
}

function recuperarPlanLiberacion_(order,release,payments,productRows,items) {
  const journal=leerJournalLiberacion_(order.d.reference); if(!journal)return null;
  if(journal.o!==order.d.order_id||journal.b!==release.revision||journal.a!==release.revision+1||!Array.isArray(journal.p)||!Array.isArray(journal.q)||typeof journal.c!=='string')throw errorCheckout_('CONSISTENCY_UNCERTAIN');
  if(calcularFingerprintLiberacion_(order,release,items)!==journal.f)throw errorCheckout_('CONSISTENCY_UNCERTAIN');
  /* The durable marker is sufficient for replay even when a later batch member advanced a shared product. */
  if(esPedidoLiberadoDurable_(order.d)&&order.d.revision===journal.a){if(!pedidoCoincideLiberacionDurable_(order.d,journal))throw errorCheckout_('CONSISTENCY_UNCERTAIN');return {order:order,products:[],payments:[],now:journal.c,revision:journal.a,replay:true,releaseId:journal.u,fingerprint:journal.f,expectedOrder:journal.d};}
  const products=journal.p.map(function(change){const p=productRows[change.id];if(!p||p.row!==change.row)throw errorCheckout_('CONSISTENCY_UNCERTAIN');const before=p.d.inventory===change.bi&&p.d.revision===change.br,after=p.d.inventory===change.ai&&p.d.revision===change.ar;if(!before&&!after)throw errorCheckout_('CONSISTENCY_UNCERTAIN');return {row:change.row,id:change.id,beforeInventory:change.bi,afterInventory:change.ai,beforeRevision:change.br,afterRevision:change.ar,beforeUpdated:change.bu,afterUpdated:change.au};});
  const updates=journal.q.map(function(change){const p=payments.filter(function(x){return x.row===change.row;})[0],pendingExact=p&&p.d.status===change.bs&&fechaExistenteEventoPago_(p.d.updated_at).iso===change.bu,finalExact=p&&p.d.status===change.as&&fechaExistenteEventoPago_(p.d.updated_at).iso===change.au;if(!p||p.d.wompi_transaction_id!==change.t||(!pendingExact&&!finalExact))throw errorCheckout_('CONSISTENCY_UNCERTAIN');return {row:change.row,paymentAttemptId:change.i,orderId:change.o,id:change.t,status:change.as,beforeUpdated:change.bu,updatedAt:change.au,paymentMethod:change.m,amount:change.a,currency:change.c,createdAt:change.cr};});
  if(order.d.status==='PENDING'&&order.d.payment_status==='PENDING'&&order.d.reservation_status==='ACTIVE'&&order.d.revision===journal.b)return {order:order,products:products,payments:updates,now:journal.c,revision:journal.a,replay:false,recovery:true,releaseId:journal.u,fingerprint:journal.f,expectedOrder:journal.d};
  throw errorCheckout_('CONSISTENCY_UNCERTAIN');
}

function guardarJournalLiberacion_(plan) {
  const props=PropertiesService.getScriptProperties(),key=CHECKOUT_RELEASE_JOURNAL_PREFIX+plan.order.d.reference,existing=props.getProperty(key);
  const journal={v:2,r:plan.order.d.reference,o:plan.order.d.order_id,b:plan.order.d.revision,a:plan.revision,u:plan.releaseId,f:plan.fingerprint,s:'PREPARED',c:plan.now,p:plan.products.map(function(x){return {row:x.row,id:x.id,bi:x.beforeInventory,ai:x.afterInventory,br:x.beforeRevision,ar:x.afterRevision,bu:x.beforeUpdated,au:x.afterUpdated};}),q:plan.payments.map(function(x){return {row:x.row,i:x.paymentAttemptId,o:x.orderId,t:x.id,bs:'PENDING',as:x.status,bu:x.beforeUpdated,au:x.updatedAt,m:x.paymentMethod,a:x.amount,c:x.currency,cr:x.createdAt};}),d:plan.expectedOrder};
  const raw=JSON.stringify(journal);
  if(Utilities.newBlob(raw).getBytes().length>CHECKOUT_RELEASE_JOURNAL_MAX_BYTES)throw errorCheckout_('CONSISTENCY_UNCERTAIN');
  if(!existing&&Object.keys(props.getProperties()).filter(function(k){return k.indexOf(CHECKOUT_RELEASE_JOURNAL_PREFIX)===0;}).length>=CHECKOUT_RELEASE_JOURNAL_MAX_ACTIVE)throw errorCheckout_('CONSISTENCY_UNCERTAIN');
  props.setProperty(key,raw);
}
function actualizarJournalLiberacion_(reference,stage) { const props=PropertiesService.getScriptProperties(),journal=leerJournalLiberacion_(reference);if(!journal||RELEASE_JOURNAL_STAGES.indexOf(stage)<0)throw errorCheckout_('CONSISTENCY_UNCERTAIN');journal.s=stage;const raw=JSON.stringify(journal);if(Utilities.newBlob(raw).getBytes().length>CHECKOUT_RELEASE_JOURNAL_MAX_BYTES)throw errorCheckout_('CONSISTENCY_UNCERTAIN');props.setProperty(CHECKOUT_RELEASE_JOURNAL_PREFIX+reference,raw); }
function journalLiberacionCompleto_(journal,reference) { try { return !!journal&&journal.v===2&&journal.r===reference&&RELEASE_JOURNAL_STAGES.indexOf(journal.s)>=0&&enteroEventoPago_(journal.o,1,2147483647)>=1&&enteroEventoPago_(journal.b,1,2147483646)>=1&&enteroEventoPago_(journal.a,2,2147483647)===journal.b+1&&uuidV4Checkout_(journal.u)&&hashValidoCheckout_(journal.f)&&fechaExistenteEventoPago_(journal.c).iso===journal.c&&Array.isArray(journal.p)&&Array.isArray(journal.q)&&journal.p.length>0&&journal.d&&journal.d.orderId===journal.o&&journal.d.reference===reference&&journal.d.revision===journal.a&&journal.d.releaseId===journal.u&&journal.d.fingerprint===journal.f&&journal.d.releasedAt===journal.c&&journal.p.every(function(p){return p&&enteroEventoPago_(p.id,1,2147483647)>=1&&enteroEventoPago_(p.bi,0,2147483647)>=0&&enteroEventoPago_(p.ai,0,2147483647)>=0&&enteroEventoPago_(p.br,1,2147483647)>=1&&enteroEventoPago_(p.ar,1,2147483647)===p.br+1&&fechaExistenteEventoPago_(p.au).iso===p.au;})&&journal.q.every(function(q){return q&&enteroEventoPago_(q.i,1,2147483647)>=1&&enteroEventoPago_(q.o,1,2147483647)===journal.o&&textoEventoPagoAlmacenado_(q.t,1,200)!==''&&['DECLINED','VOIDED','ERROR'].indexOf(q.as)>=0&&fechaExistenteEventoPago_(q.au).iso===q.au;}); } catch(error) { return false; } }
function leerJournalLiberacion_(reference) { const raw=PropertiesService.getScriptProperties().getProperty(CHECKOUT_RELEASE_JOURNAL_PREFIX+reference);if(!raw)return null;try{const journal=JSON.parse(raw);if(!journalLiberacionCompleto_(journal,reference))throw new Error();return journal;}catch(error){throw errorCheckout_('CONSISTENCY_UNCERTAIN');} }
function verificarLiberacionFinal_(hojas,plan) {
  return verificarLiberacionFinalDatos_({pedidos:leerFilasCheckout_(hojas.pedidos,PEDIDOS_HEADERS),productos:leerFilasCheckout_(hojas.productos,PRODUCT_HEADERS),pagos:leerFilasCheckout_(hojas.pagos,PAGOS_HEADERS)},plan);
}
function verificarLiberacionFinalDatos_(rows,plan) {
  const by=Object.create(null);rows.productos.forEach(function(p){by[p.d.product_id]=p;});
  return verificarPedidoLiberacionFinal_(rows.pedidos,plan)&&(plan.verificationProducts||plan.products).every(function(p){return by[p.id]&&by[p.id].row===p.row&&enteroEventoPago_(by[p.id].d.product_id,1,2147483647)===p.id&&enteroEventoPago_(by[p.id].d.inventory,0,2147483647)===p.afterInventory&&enteroEventoPago_(by[p.id].d.revision,1,2147483647)===p.afterRevision&&fechaExistenteEventoPago_(by[p.id].d.updated_at).iso===p.afterUpdated;})&&verificarPagosLiberacionFinal_(rows.pagos,plan);
}
function verificarPedidoLiberacionFinal_(rows,plan) { const d=(rows.filter(function(x){return x.d.reference===plan.order.d.reference;})[0]||{}).d,e=plan.expectedOrder;if(!d||!e)return false;try{return enteroEventoPago_(d.order_id,1,2147483647)===e.orderId&&textoEventoPagoAlmacenado_(d.reference,1,120)===e.reference&&d.status===e.status&&d.payment_status===e.paymentStatus&&d.reservation_status===e.reservationStatus&&d.release_id===e.releaseId&&d.release_fingerprint===e.fingerprint&&fechaExistenteEventoPago_(d.released_at).iso===e.releasedAt&&enteroEventoPago_(d.revision,1,2147483647)===e.revision&&fechaExistenteEventoPago_(d.updated_at).iso===e.updatedAt&&fechaIgualEventoPago_(d.paid_at,e.paidAt===null?null:e.paidAt.iso)&&fechaIgualEventoPago_(d.payment_last_event_at,e.lastEventAt===null?null:e.lastEventAt.iso)&&enteroEventoPago_(d.total_cop,0,2147483647)===e.total;}catch(error){return false;} }
function verificarPagosLiberacionFinal_(rows,plan) { try{return plan.payments.every(function(c){const d=(rows.filter(function(x){return x.row===c.row;})[0]||{}).d;return d&&enteroEventoPago_(d.payment_attempt_id,1,2147483647)===c.paymentAttemptId&&enteroEventoPago_(d.order_id,1,2147483647)===c.orderId&&textoEventoPagoAlmacenado_(d.wompi_transaction_id,1,200)===c.id&&d.status===c.status&&textoEventoPagoAlmacenado_(d.payment_method,1,100)===c.paymentMethod&&enteroEventoPago_(d.amount_in_cents,1,2147483647)===c.amount&&d.currency===c.currency&&fechaExistenteEventoPago_(d.created_at).iso===c.createdAt&&fechaExistenteEventoPago_(d.updated_at).iso===c.updatedAt;});}catch(error){return false;} }

function calcularFingerprintLiberacion_(order,release,items) {
  const grouped=Object.create(null);
  items.forEach(function(item){const d=item.d,id=enteroEventoPago_(d.product_id,1,2147483647),q=enteroEventoPago_(d.quantity,1,999);grouped[id]=(grouped[id]||0)+q;if(!Number.isSafeInteger(grouped[id]))throw errorCheckout_('CONSISTENCY_UNCERTAIN');});
  const canonical={order_id:enteroEventoPago_(order.d.order_id,1,2147483647),reference:textoEventoPagoAlmacenado_(order.d.reference,1,120),expected_revision:release.revision,items:Object.keys(grouped).map(function(id){return {product_id:Number(id),quantity:grouped[id]};}).sort(function(a,b){return a.product_id-b.product_id;}),verified_final_attempts:release.verified.map(function(v){return {wompi_transaction_id:v.id,status:v.status};}).sort(function(a,b){return a.wompi_transaction_id<b.wompi_transaction_id?-1:a.wompi_transaction_id>b.wompi_transaction_id?1:0;})};
  return calcularHashCheckout_(JSON.stringify(canonical));
}

function esPedidoLiberadoDurable_(d) {
  try { return d.status==='PENDING'&&d.payment_status==='PENDING'&&d.reservation_status==='RELEASED'&&uuidV4Checkout_(d.release_id)&&hashValidoCheckout_(d.release_fingerprint)&&fechaExistenteEventoPago_(d.released_at).iso===d.released_at&&fechaExistenteEventoPago_(d.updated_at).iso===d.updated_at&&enteroEventoPago_(d.revision,1,2147483647)>=1; } catch(error) { return false; }
}

function planReplayLiberacionSinJournal_(order,release,items) {
  if(!esPedidoLiberadoDurable_(order.d)) throw errorCheckout_('CONSISTENCY_UNCERTAIN');
  const fingerprint=calcularFingerprintLiberacion_(order,release,items);
  if(fingerprint!==order.d.release_fingerprint) return null;
  return {order:order,products:[],payments:[],now:order.d.released_at,revision:order.d.revision,replay:true,releaseId:order.d.release_id,fingerprint:fingerprint,expectedOrder:{orderId:order.d.order_id,reference:order.d.reference,status:'PENDING',paymentStatus:'PENDING',reservationStatus:'RELEASED',paidAt:fechaOpcionalEventoPago_(order.d.paid_at),lastEventAt:fechaOpcionalEventoPago_(order.d.payment_last_event_at),total:enteroEventoPago_(order.d.total_cop,0,2147483647),updatedAt:fechaExistenteEventoPago_(order.d.updated_at).iso,releasedAt:fechaExistenteEventoPago_(order.d.released_at).iso,releaseId:order.d.release_id,fingerprint:fingerprint,revision:order.d.revision}};
}

function productosDespuesJournal_(products,productRows) { return products.every(function(change){const p=productRows[change.id];return p&&p.d.inventory===change.afterInventory&&p.d.revision===change.afterRevision&&fechaExistenteEventoPago_(p.d.updated_at).iso===change.afterUpdated;}); }
function pedidoCoincideLiberacionDurable_(d,journal) { return esPedidoLiberadoDurable_(d)&&d.release_id===journal.u&&d.release_fingerprint===journal.f&&d.revision===journal.a&&fechaExistenteEventoPago_(d.released_at).iso===journal.c; }

/* FINALIZED journals are disposable only after Pedidos carries the exact durable release marker. */
function prepararCapacidadJournalsLiberacion_(plans,tablas) {
  if(!plans.length)return;
  const props=PropertiesService.getScriptProperties(),all=props.getProperties(),keys=Object.keys(all).filter(function(k){return k.indexOf(CHECKOUT_RELEASE_JOURNAL_PREFIX)===0;}),needed=plans.filter(function(p){return !Object.prototype.hasOwnProperty.call(all,CHECKOUT_RELEASE_JOURNAL_PREFIX+p.order.d.reference);}).length;if(!needed)return;
  const missing=Math.max(0,keys.length+needed-CHECKOUT_RELEASE_JOURNAL_MAX_ACTIVE);if(!missing)return;
  const active=Object.create(null);plans.forEach(function(p){active[p.order.d.reference]=true;});const orders=Object.create(null);tablas.pedidos.rows.forEach(function(row){orders[row.d.reference]=row.d;});
  const safe=[];keys.forEach(function(key){try{const journal=JSON.parse(all[key]),reference=key.substring(CHECKOUT_RELEASE_JOURNAL_PREFIX.length);if(!journalLiberacionCompleto_(journal,reference)||journal.s!=='FINALIZED'||active[journal.r]||!orders[journal.r]||!pedidoCoincideLiberacionDurable_(orders[journal.r],journal))return;safe.push({key:key,created:fechaExistenteEventoPago_(journal.c).ms});}catch(error){}});
  safe.sort(function(a,b){return a.created-b.created;});if(safe.length<missing)throw errorCheckout_('CONSISTENCY_UNCERTAIN');
  safe.slice(0,missing).forEach(function(entry){props.deleteProperty(entry.key);});
}

/* Reads only the durable checkout state in Pedidos; it never derives expiry. */
function obtenerEstadoCheckout_(solicitud) {
  let reference;
  try { reference = normalizarReferenciaEstadoCheckout_(solicitud); } catch (error) { return respuestaErrorCheckout_('INVALID_REQUEST'); }
  const lock = LockService.getScriptLock();
  if (!lock.tryLock(5000)) return respuestaErrorCheckout_('LOCK_TIMEOUT');
  try {
    const ss = SpreadsheetApp.getActiveSpreadsheet();
    const pedidos = ss.getSheetByName('Pedidos');
    if (!pedidos) throw errorCheckout_('INTERNAL_ERROR');
    validarEncabezadosCheckout_(pedidos,PEDIDOS_HEADERS);
    const rows = leerFilasCheckout_(pedidos,PEDIDOS_HEADERS);
    validarPedidosEstadoCheckoutGlobal_(rows);
    const matches = rows.filter(function (row) { return row.d.reference === reference; });
    if (matches.length === 0) throw errorCheckout_('ORDER_NOT_FOUND');
    if (matches.length !== 1) throw errorCheckout_('CONSISTENCY_UNCERTAIN');
    return respuestaJson_({ok:true,data:datosEstadoCheckout_(matches[0],reference)});
  } catch (error) {
    const code = error && error.checkoutCode;
    console.error(code ? 'get_checkout_status: '+code : 'get_checkout_status: INTERNAL_ERROR');
    return respuestaErrorCheckout_(code || 'INTERNAL_ERROR');
  } finally { lock.releaseLock(); }
}

function normalizarReferenciaEstadoCheckout_(solicitud) {
  if (!solicitud || typeof solicitud !== 'object' || Array.isArray(solicitud) || typeof solicitud.reference !== 'string') throw errorCheckout_('INVALID_REQUEST');
  const reference = solicitud.reference;
  if (reference !== reference.trim() || reference.length < 1 || reference.length > 120 || /[\u0000-\u001F\u007F]/.test(reference)) throw errorCheckout_('INVALID_REQUEST');
  return reference;
}

function fechaEstadoCheckout_(value,required) {
  if (value === '' || value === null || value === undefined) {
    if (required) throw errorCheckout_('CONSISTENCY_UNCERTAIN');
    return null;
  }
  try { return fechaExistenteEventoPago_(value); } catch (error) { throw errorCheckout_('CONSISTENCY_UNCERTAIN'); }
}

function validarPedidosEstadoCheckoutGlobal_(rows) {
  const orderIds = Object.create(null), references = Object.create(null);
  rows.forEach(function (pedido) {
    const d = pedido.d;
    const orderId = enteroEventoPago_(d.order_id,1,2147483647);
    const reference = textoEventoPagoAlmacenado_(d.reference,1,120);
    if (orderIds[orderId] || references[reference]) throw errorCheckout_('CONSISTENCY_UNCERTAIN');
    orderIds[orderId] = true;
    references[reference] = true;
  });
}

function datosEstadoCheckout_(pedido,reference) {
  const d = pedido.d;
  if (textoEventoPagoAlmacenado_(d.reference,1,120) !== reference) throw errorCheckout_('CONSISTENCY_UNCERTAIN');
  const orderId = enteroEventoPago_(d.order_id,1,2147483647);
  if (typeof d.status !== 'string' || typeof d.payment_status !== 'string' || typeof d.reservation_status !== 'string') throw errorCheckout_('CONSISTENCY_UNCERTAIN');
  const status = d.status, paymentStatus = d.payment_status, reservationStatus = d.reservation_status;
  const combination = status+'|'+paymentStatus+'|'+reservationStatus;
  const normal = ['PENDING|PENDING|ACTIVE','PENDING|APPROVED|CONSUMED','PENDING|PENDING|RELEASED','PAYMENT_REVIEW_REQUIRED|APPROVED|RELEASED'];
  /* prepare_checkout persists this exact technical row before its later durable writes. */
  const preparing = combination === 'RESERVATION_PREPARING||';
  if (normal.indexOf(combination) < 0 && !preparing) throw errorCheckout_('CONSISTENCY_UNCERTAIN');
  if (typeof d.revision !== 'number' || !Number.isInteger(d.revision) || d.revision < 0 || d.revision > 2147483647 || (preparing ? d.revision !== 0 : d.revision < 1)) throw errorCheckout_('CONSISTENCY_UNCERTAIN');
  const reservationExpiresAt = fechaEstadoCheckout_(d.reservation_expires_at,status === 'PENDING' && reservationStatus === 'ACTIVE');
  const paidAt = fechaEstadoCheckout_(d.paid_at,paymentStatus === 'APPROVED');
  const lastEventAt = fechaEstadoCheckout_(d.payment_last_event_at,false);
  const createdAt = fechaEstadoCheckout_(d.created_at,true);
  const updatedAt = fechaEstadoCheckout_(d.updated_at,true);
  if (createdAt.ms > updatedAt.ms || (paidAt !== null && createdAt.ms > paidAt.ms) || (lastEventAt !== null && createdAt.ms > lastEventAt.ms)) throw errorCheckout_('CONSISTENCY_UNCERTAIN');
  if (paymentStatus !== 'APPROVED' && paidAt !== null) throw errorCheckout_('CONSISTENCY_UNCERTAIN');
  if (paymentStatus === 'APPROVED' && lastEventAt === null) throw errorCheckout_('CONSISTENCY_UNCERTAIN');
  validarMarcaLiberacionPedido_(d, combination);
  return {order_id:orderId,reference:reference,status:status,payment_status:paymentStatus,reservation_status:reservationStatus,reservation_expires_at:reservationExpiresAt===null?null:reservationExpiresAt.iso,paid_at:paidAt===null?null:paidAt.iso,payment_last_event_at:lastEventAt===null?null:lastEventAt.iso,created_at:createdAt.iso,updated_at:updatedAt.iso,revision:d.revision};
}

/* Admin read models preserve the existing Laravel JSON aliases without exposing checkout internals. */
function listarPedidosAdmin_(solicitud) {
  let request;
  try { request = normalizarListadoPedidosAdmin_(solicitud); } catch (error) { return respuestaErrorCheckout_('INVALID_REQUEST'); }
  let rows;
  const lock = LockService.getScriptLock();
  if (!lock.tryLock(5000)) return respuestaErrorCheckout_('LOCK_TIMEOUT');
  try {
    const pedidos = SpreadsheetApp.getActiveSpreadsheet().getSheetByName('Pedidos');
    if (!pedidos) throw errorCheckout_('INTERNAL_ERROR');
    validarEncabezadosCheckout_(pedidos,PEDIDOS_HEADERS);
    rows = leerFilasCheckout_(pedidos,PEDIDOS_HEADERS);
  } catch (error) {
    const code = error && error.checkoutCode;
    console.error(code ? 'admin_list_orders: '+code : 'admin_list_orders: INTERNAL_ERROR');
    return respuestaErrorCheckout_(code || 'INTERNAL_ERROR');
  } finally { lock.releaseLock(); }
  try {
    const orders = validarYMapearPedidosAdmin_(rows).sort(function(a,b){ return b.id-a.id; });
    const total = orders.length;
    const lastPage = Math.max(1,Math.ceil(total/request.perPage));
    const start = (request.page-1)*request.perPage;
    return respuestaJson_({ok:true,data:{orders:orders.slice(start,start+request.perPage).map(function(order){ return {id:order.id,reference:order.reference,status:order.status,payment_status:order.paymentStatus,customer_name:order.customerName,total:order.total,created_at:order.createdAt}; }),pagination:{current_page:request.page,per_page:request.perPage,total:total,last_page:lastPage}}});
  } catch (error) {
    const code = error && error.checkoutCode;
    console.error(code ? 'admin_list_orders: '+code : 'admin_list_orders: INTERNAL_ERROR');
    return respuestaErrorCheckout_(code || 'INTERNAL_ERROR');
  }
}

function obtenerPedidoAdmin_(solicitud) {
  let orderId;
  try { orderId = normalizarIdPedidoAdmin_(solicitud); } catch (error) { return respuestaErrorCheckout_('INVALID_REQUEST'); }
  let pedidos,items,pagos;
  const lock = LockService.getScriptLock();
  if (!lock.tryLock(5000)) return respuestaErrorCheckout_('LOCK_TIMEOUT');
  try {
    const ss = SpreadsheetApp.getActiveSpreadsheet(), pedidosSheet = ss.getSheetByName('Pedidos'), itemsSheet = ss.getSheetByName('PedidoItems'), pagosSheet = ss.getSheetByName('Pagos');
    if (!pedidosSheet || !itemsSheet || !pagosSheet) throw errorCheckout_('INTERNAL_ERROR');
    validarEncabezadosCheckout_(pedidosSheet,PEDIDOS_HEADERS);
    validarEncabezadosCheckout_(itemsSheet,PEDIDO_ITEMS_HEADERS);
    validarEncabezadosCheckout_(pagosSheet,PAGOS_HEADERS); pedidos = leerFilasCheckout_(pedidosSheet,PEDIDOS_HEADERS);
    items = leerFilasCheckout_(itemsSheet,PEDIDO_ITEMS_HEADERS); pagos = leerFilasCheckout_(pagosSheet,PAGOS_HEADERS);
  } catch (error) {
    const code = error && error.checkoutCode;
    console.error(code ? 'admin_get_order: '+code : 'admin_get_order: INTERNAL_ERROR');
    return respuestaErrorCheckout_(code || 'INTERNAL_ERROR');
  } finally { lock.releaseLock(); }
  try {
    const orders = validarYMapearPedidosAdmin_(pedidos), matches = orders.filter(function(order){ return order.id===orderId; });
    if (!matches.length) throw errorCheckout_('NOT_FOUND');
    if (matches.length!==1) throw errorCheckout_('CONSISTENCY_UNCERTAIN');
    const mappedItems = validarYMapearItemsAdmin_(items,orders).filter(function(item){ return item.orderId===orderId; }).sort(function(a,b){ return a.id-b.id; });
    const order = matches[0];
    const attempts=pagos.filter(function(row){return Number(row.d.order_id)===orderId;}).map(function(row){const d=row.d;return {wompi_transaction_id:textoEventoPagoAlmacenado_(d.wompi_transaction_id,1,200),status:d.status,payment_method:textoEventoPagoAlmacenado_(d.payment_method,1,100),updated_at:fechaExistenteEventoPago_(d.updated_at).iso};});
    const payment=attempts.length?attempts.sort(function(a,b){return a.updated_at<b.updated_at?1:-1;})[0]:null;
    return respuestaJson_({ok:true,data:{order:{id:order.id,reference:order.reference,status:order.status,payment_status:order.paymentStatus,reservation_status:order.reservationStatus,paid_at:order.paidAt,customer_name:order.customerName,customer_email:order.customerEmail,customer_phone:order.customerPhone,customer_document:order.customerDocument,address:order.address,extra:order.extra,city:order.city,region:order.region,postal:order.postal,total:order.total,created_at:order.createdAt,payment:payment,items:mappedItems.map(function(item){ return {id:item.id,product_id:item.productId,product_name:item.productName,unit_price:item.unitPrice,quantity:item.quantity}; })}}});
  } catch (error) {
    const code = error && error.checkoutCode;
    console.error(code ? 'admin_get_order: '+code : 'admin_get_order: INTERNAL_ERROR');
    return respuestaErrorCheckout_(code || 'INTERNAL_ERROR');
  }
}

function normalizarListadoPedidosAdmin_(solicitud) {
  if (!solicitud || typeof solicitud !== 'object' || Array.isArray(solicitud)) throw errorCheckout_('INVALID_REQUEST');
  const page = solicitud.page === undefined ? 1 : solicitud.page, perPage = solicitud.per_page === undefined ? 25 : solicitud.per_page;
  if (typeof page !== 'number' || !Number.isInteger(page) || page<1 || typeof perPage !== 'number' || !Number.isInteger(perPage) || perPage<1 || perPage>100) throw errorCheckout_('INVALID_REQUEST');
  return {page:page,perPage:perPage};
}

function normalizarIdPedidoAdmin_(solicitud) {
  if (!solicitud || typeof solicitud !== 'object' || Array.isArray(solicitud) || typeof solicitud.order_id !== 'number' || !Number.isInteger(solicitud.order_id) || solicitud.order_id<1 || solicitud.order_id>2147483647) throw errorCheckout_('INVALID_REQUEST');
  return solicitud.order_id;
}

function textoPedidoAdmin_(value,min,max,nullable) {
  if (nullable && (value==='' || value===null || value===undefined)) return null;
  if (typeof value !== 'string' || value!==value.trim() || value.length<min || value.length>max || /[\u0000-\u001F\u007F]/.test(value)) throw errorCheckout_('CONSISTENCY_UNCERTAIN');
  return value;
}

function textoNumericoPedidoAdmin_(value,min,max,nullable) {
  if (nullable && (value==='' || value===null || value===undefined)) return null;
  if (typeof value === 'number') {
    if (!Number.isSafeInteger(value) || value < 0) throw errorCheckout_('CONSISTENCY_UNCERTAIN');
    value = String(value);
  }
  return textoPedidoAdmin_(value,min,max,false);
}

function validarYMapearPedidosAdmin_(rows) {
  const ids=Object.create(null),references=Object.create(null);
  return rows.map(function(row){
    const d=row.d,id=enteroEventoPago_(d.order_id,1,2147483647),reference=textoPedidoAdmin_(d.reference,1,120,false);
    if(ids[id]||references[reference]) throw errorCheckout_('CONSISTENCY_UNCERTAIN');
    ids[id]=true; references[reference]=true;
    const combination=String(d.status)+'|'+String(d.payment_status)+'|'+String(d.reservation_status), operational=['PENDING','PROCESSING','READY','SHIPPED','DELIVERED','CANCELLED'], preparing=String(d.status)==='RESERVATION_PREPARING';
    if(operational.indexOf(d.status)<0&&!preparing||['PENDING','APPROVED','DECLINED','VOIDED','ERROR'].indexOf(d.payment_status)<0||['ACTIVE','CONSUMED','RELEASED'].indexOf(d.reservation_status)<0) throw errorCheckout_('CONSISTENCY_UNCERTAIN');
    const revision=enteroEventoPago_(d.revision,0,2147483647);
    if((preparing&&revision!==0)||(!preparing&&revision<1)) throw errorCheckout_('CONSISTENCY_UNCERTAIN');
    const createdAt=fechaExistenteEventoPago_(d.created_at),updatedAt=fechaExistenteEventoPago_(d.updated_at),paidAt=fechaOpcionalEventoPago_(d.paid_at),lastEventAt=fechaOpcionalEventoPago_(d.payment_last_event_at);
    if(createdAt.ms>updatedAt.ms||(paidAt!==null&&createdAt.ms>paidAt.ms)||(lastEventAt!==null&&createdAt.ms>lastEventAt.ms)) throw errorCheckout_('CONSISTENCY_UNCERTAIN');
    if((d.payment_status==='APPROVED'&&(paidAt===null||lastEventAt===null))||(d.payment_status!=='APPROVED'&&paidAt!==null)) throw errorCheckout_('CONSISTENCY_UNCERTAIN');
    validarMarcaLiberacionPedido_(d,combination);
    return {id:id,reference:reference,status:d.status,paymentStatus:d.payment_status,reservationStatus:d.reservation_status,paidAt:paidAt===null?null:paidAt.iso,customerName:textoPedidoAdmin_(d.customer_name,1,120,false),customerEmail:textoPedidoAdmin_(d.customer_email,3,254,false),customerPhone:textoPedidoAdmin_(d.customer_phone,1,50,false),customerDocument:textoNumericoPedidoAdmin_(d.customer_document,1,50,false),address:textoPedidoAdmin_(d.address,1,300,false),extra:textoPedidoAdmin_(d.extra,0,300,true),city:textoPedidoAdmin_(d.city,1,100,false),region:textoPedidoAdmin_(d.region,1,100,false),postal:textoNumericoPedidoAdmin_(d.postal,0,30,true),total:enteroEventoPago_(d.total_cop,0,2147483647),createdAt:createdAt.iso};
  });
}

function actualizarEstadoPedidoAdmin_(solicitud) {
  if(!solicitud||typeof solicitud.order_id!=='number'||!Number.isInteger(solicitud.order_id)||typeof solicitud.status!=='string') return respuestaErrorCheckout_('INVALID_REQUEST');
  const target=solicitud.status,allowed=['PENDING','PROCESSING','READY','SHIPPED','DELIVERED','CANCELLED']; if(allowed.indexOf(target)<0)return respuestaErrorCheckout_('INVALID_REQUEST');
  const lock=LockService.getScriptLock();if(!lock.tryLock(5000))return respuestaErrorCheckout_('LOCK_TIMEOUT');
  try {const sheet=SpreadsheetApp.getActiveSpreadsheet().getSheetByName('Pedidos');if(!sheet)throw errorCheckout_('INTERNAL_ERROR');validarEncabezadosCheckout_(sheet,PEDIDOS_HEADERS);const matches=leerFilasCheckout_(sheet,PEDIDOS_HEADERS).filter(function(row){return row.d.order_id===solicitud.order_id;});if(!matches.length)throw errorCheckout_('ORDER_NOT_FOUND');if(matches.length!==1)throw errorCheckout_('CONSISTENCY_UNCERTAIN');const row=matches[0],d=row.d,from=d.status;if(from===target)return respuestaJson_({ok:true,data:{order_id:solicitud.order_id,status:from,updated_at:fechaExistenteEventoPago_(d.updated_at).iso,revision:enteroEventoPago_(d.revision,1,2147483647),idempotency_replayed:true}});if(from==='DELIVERED'||from==='CANCELLED')throw errorCheckout_('INVALID_STATUS_TRANSITION');if(target!=='CANCELLED'){const flows={PENDING:['PROCESSING'],PROCESSING:['READY'],READY:['SHIPPED','DELIVERED'],SHIPPED:['DELIVERED']};if(!(flows[from]||[]).includes(target))throw errorCheckout_('INVALID_STATUS_TRANSITION');if(from==='PENDING'&&d.payment_status!=='APPROVED')throw errorCheckout_('PAYMENT_NOT_APPROVED');}const values=row.values.slice(),now=new Date().toISOString(),statusIndex=PEDIDOS_HEADERS.indexOf('status'),updatedIndex=PEDIDOS_HEADERS.indexOf('updated_at'),revisionIndex=PEDIDOS_HEADERS.indexOf('revision');values[statusIndex]=target;values[updatedIndex]=now;values[revisionIndex]=enteroEventoPago_(d.revision,1,2147483647)+1;sheet.getRange(row.row,1,1,PEDIDOS_HEADERS.length).setValues([values]);SpreadsheetApp.flush();return respuestaJson_({ok:true,data:{order_id:solicitud.order_id,status:target,updated_at:now,revision:values[revisionIndex],idempotency_replayed:false}});}catch(error){return respuestaErrorCheckout_(error&&error.checkoutCode||'INTERNAL_ERROR');}finally{lock.releaseLock();}
}

function validarYMapearItemsAdmin_(rows,orders) {
  const ids=Object.create(null),orderIds=Object.create(null); orders.forEach(function(order){ orderIds[order.id]=true; });
  return rows.map(function(row){
    const d=row.d,id=enteroEventoPago_(d.order_item_id,1,2147483647),orderId=enteroEventoPago_(d.order_id,1,2147483647);
    if(ids[id]||!orderIds[orderId]) throw errorCheckout_('CONSISTENCY_UNCERTAIN');
    ids[id]=true;
    fechaExistenteEventoPago_(d.created_at);
    return {id:id,orderId:orderId,productId:enteroEventoPago_(d.product_id,1,2147483647),productName:textoPedidoAdmin_(d.product_name,1,500,false),unitPrice:enteroEventoPago_(d.unit_price_cop,0,2147483647),quantity:enteroEventoPago_(d.quantity,1,999)};
  });
}
function uuidV4Checkout_(v) { return typeof v==='string'&&/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i.test(v); }
function hashValidoCheckout_(v) { return typeof v==='string'&&/^[0-9a-f]{64}$/.test(v); }
function canonicalCheckout_(x) { const c=x.customer;return '{"customer":{'+['"name":'+jsonCheckout_(c.name),'"email":'+jsonCheckout_(c.email),'"phone":'+jsonCheckout_(c.phone),'"document":'+jsonCheckout_(c.document),'"address":'+jsonCheckout_(c.address),'"extra":'+jsonCheckout_(c.extra),'"city":'+jsonCheckout_(c.city),'"region":'+jsonCheckout_(c.region),'"postal":'+jsonCheckout_(c.postal)].join(',')+'},"items":['+x.items.map(function(i){return '{"product_id":'+i.product_id+',"quantity":'+i.quantity+'}';}).join(',')+']}'; }
function jsonCheckout_(v) { if(v===null)return 'null';return '"'+v.replace(/\\/g,'\\\\').replace(/"/g,'\\"')+'"'; }
function calcularHashCheckout_(canonical) { return Utilities.computeDigest(Utilities.DigestAlgorithm.SHA_256,canonical,Utilities.Charset.UTF_8).map(function(b){return (b+256).toString(16).slice(-2);}).join(''); }
