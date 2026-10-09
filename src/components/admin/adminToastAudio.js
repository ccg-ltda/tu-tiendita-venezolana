let audioContext = null;

function warnInDevelopment(message, error) {
  if (import.meta.env.DEV) console.warn(message, error);
}

function getAudioContext() {
  if (audioContext) return audioContext;

  const AudioContextClass = window.AudioContext || window.webkitAudioContext;
  if (!AudioContextClass) return null;

  audioContext = new AudioContextClass();
  return audioContext;
}

async function activeAudioContext() {
  const context = getAudioContext();
  if (!context) return null;

  if (context.state === 'suspended') await context.resume();
  return context;
}

/** Call synchronously from a user gesture to unlock the shared audio context. */
export function unlockAdminToastAudio() {
  activeAudioContext().catch((error) => warnInDevelopment('No se pudo habilitar el sonido de notificaciones.', error));
}

function scheduleTone(context, frequency, start, duration) {
  const oscillator = context.createOscillator();
  const gain = context.createGain();
  const end = start + duration;
  const attackEnd = start + 0.025;
  const sustainEnd = end - 0.09;

  oscillator.type = 'sine';
  oscillator.frequency.setValueAtTime(frequency, start);
  gain.gain.setValueAtTime(0.0001, start);
  gain.gain.exponentialRampToValueAtTime(0.14, attackEnd);
  gain.gain.setValueAtTime(0.14, sustainEnd);
  gain.gain.exponentialRampToValueAtTime(0.0001, end);
  oscillator.connect(gain);
  gain.connect(context.destination);
  oscillator.start(start);
  oscillator.stop(end);
}

/** Plays one short, unobtrusive two-tone cue using the shared context. */
export async function playAdminToastSound(variant) {
  try {
    const context = await activeAudioContext();
    if (!context) return;

    const isError = variant === 'error';
    const start = context.currentTime + 0.01;
    scheduleTone(context, isError ? 420 : 620, start, 0.2);
    scheduleTone(context, isError ? 300 : 820, start + 0.26, 0.22);
  } catch (error) {
    warnInDevelopment('No se pudo reproducir el sonido de notificación.', error);
  }
}
