function obtenerSolicitud_(e) {
  if (!e || !e.postData || !e.postData.contents) {
    throw new Error('Solicitud sin contenido.');
  }

  const datos = JSON.parse(e.postData.contents);

  if (
    !datos ||
    typeof datos !== 'object' ||
    Array.isArray(datos)
  ) {
    throw new Error('Solicitud inválida.');
  }

  return datos;
}

function autenticar_(apiKey) {
  if (!apiKey || typeof apiKey !== 'string') {
    return false;
  }

  const claveEsperada = PropertiesService
    .getScriptProperties()
    .getProperty('APPS_SCRIPT_API_KEY');

  if (!claveEsperada) {
    throw new Error('La clave de API no está configurada.');
  }

  return apiKey === claveEsperada;
}

function encabezadosCoinciden_(actuales, esperados) {
  if (actuales.length !== esperados.length) {
    return false;
  }

  return esperados.every(function (encabezado, indice) {
    return actuales[indice] === encabezado;
  });
}

function enteroNoNegativo_(
  valor,
  campo,
  numeroRegistro,
  permiteCero
) {
  const numero = Number(valor);

  if (
    !Number.isInteger(numero) ||
    numero < 0 ||
    (!permiteCero && numero === 0)
  ) {
    throw new Error(
      campo +
      ' inválido en el registro ' +
      numeroRegistro +
      '.'
    );
  }

  return numero;
}

function booleanoValor_(valor, numeroRegistro) {
  if (valor === true || valor === false) {
    return valor;
  }

  const normalizado = String(valor)
    .trim()
    .toLowerCase();

  if (normalizado === 'true') {
    return true;
  }

  if (normalizado === 'false') {
    return false;
  }

  throw new Error(
    'active inválido en el registro ' +
    numeroRegistro +
    '.'
  );
}

function textoRequerido_(valor, campo, numeroRegistro) {
  const texto = String(valor ?? '').trim();

  if (!texto) {
    throw new Error(
      campo +
      ' vacío en el registro ' +
      numeroRegistro +
      '.'
    );
  }

  return texto;
}

function textoOpcional_(valor) {
  return String(valor ?? '').trim();
}

function serializarFecha_(valor) {
  if (valor instanceof Date) {
    return valor.toISOString();
  }

  return String(valor || '').trim();
}

function respuestaJson_(datos) {
  return ContentService
    .createTextOutput(JSON.stringify(datos))
    .setMimeType(ContentService.MimeType.JSON);
}