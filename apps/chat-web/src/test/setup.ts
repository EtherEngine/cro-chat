import '@testing-library/jest-dom';

// Suppress console.warn/error noise from expected test scenarios
const SUPPRESSED = [
  '[useCall] Blocked invalid transition',
  '[realtime]',
  '[CallEngine]',
];

const originalWarn = console.warn;
const originalError = console.error;

beforeEach(() => {
  console.warn = (...args: unknown[]) => {
    const msg = String(args[0] ?? '');
    if (SUPPRESSED.some((s) => msg.includes(s))) return;
    originalWarn(...args);
  };
  console.error = (...args: unknown[]) => {
    const msg = String(args[0] ?? '');
    if (SUPPRESSED.some((s) => msg.includes(s))) return;
    originalError(...args);
  };
});

afterEach(() => {
  console.warn = originalWarn;
  console.error = originalError;
});
