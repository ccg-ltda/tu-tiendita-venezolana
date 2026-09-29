/* Reversible operator guard for the coordinated checkout writer cutover. */
const CHECKOUT_CRITICAL_WRITERS_MODE = 'CHECKOUT_CRITICAL_WRITERS_MODE';
const CHECKOUT_CRITICAL_WRITERS_APPS_SCRIPT = 'APPS_SCRIPT';
const CHECKOUT_CRITICAL_WRITERS_DISABLED = 'DISABLED';

function errorCriticalWriter_(code) { return {checkoutCode:code,productCode:code}; }

function getCriticalWritersMode_() {
  const value = PropertiesService.getScriptProperties().getProperty(CHECKOUT_CRITICAL_WRITERS_MODE);
  if (value === null) return CHECKOUT_CRITICAL_WRITERS_APPS_SCRIPT;
  if (value === CHECKOUT_CRITICAL_WRITERS_APPS_SCRIPT || value === CHECKOUT_CRITICAL_WRITERS_DISABLED) return value;
  // A malformed operator value must not leave a writer enabled ambiguously.
  return CHECKOUT_CRITICAL_WRITERS_DISABLED;
}

function assertCriticalWritersEnabled_() {
  if (getCriticalWritersMode_() !== CHECKOUT_CRITICAL_WRITERS_APPS_SCRIPT) throw errorCriticalWriter_('WRITER_CUTOVER_DISABLED');
}

/* Administrative-only function. It is intentionally not dispatched by doPost. */
function setCriticalWritersMode_(mode) {
  if (mode !== CHECKOUT_CRITICAL_WRITERS_APPS_SCRIPT && mode !== CHECKOUT_CRITICAL_WRITERS_DISABLED) throw errorCriticalWriter_('INVALID_REQUEST');
  const lock = LockService.getScriptLock();
  if (!lock.tryLock(5000)) throw errorCriticalWriter_('LOCK_TIMEOUT');
  try { PropertiesService.getScriptProperties().setProperty(CHECKOUT_CRITICAL_WRITERS_MODE, mode); }
  finally { lock.releaseLock(); }
}
