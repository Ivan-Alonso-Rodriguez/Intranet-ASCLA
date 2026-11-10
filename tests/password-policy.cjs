/* Native reset UI contract, isolated from accounts and password changes. */
const assert = require('node:assert/strict');
const fs = require('node:fs');
const vm = require('node:vm');
const input = { value: '', handlers: [] };
const confirmation = { value: '', disabled: false, handlers: [], visible: false, getClientRects() { return this.visible ? [{}] : []; } };
const submit = { disabled: true };
const meter = { textContent: '', className: '' };
const checkbox = { checked: false };
const weak = { hidden: false, querySelector: () => checkbox };
const fields = { '#pass1': input, '#pass2': confirmation, '#wp-submit': submit, '#pass-strength-result': meter, '.pw-weak': weak };
const form = { querySelector: selector => fields[selector] || null };
let reconcile;
const jQuery = target => typeof target === 'function' ? target() : { on: (_events, callback) => target.handlers.push(callback) };
vm.runInNewContext(fs.readFileSync('wp-content/plugins/ascla-core/assets/password-policy.js', 'utf8'), {
  jQuery, document: { getElementById: () => form }, TextEncoder,
  window: { ASCLA_PASSWORD_POLICY: { minimum: 8, common: ['12345678', 'password123'], valid: 'Valid', invalid: 'Invalid' } },
  MutationObserver: class { constructor(callback) { reconcile = callback; } observe() {} },
});
function type(value) { input.value = value; for (const handler of input.handlers) handler(); }
assert.equal(submit.disabled, true);
assert.equal(input.minLength, 8);
type('nubeazul');
assert.equal(submit.disabled, false, 'A valid eight-character password must work with the hidden native pass2 empty');
assert.equal(weak.hidden, true);
assert.equal(checkbox.checked, true);
assert.equal(meter.textContent, 'Valid');
meter.className = 'short'; meter.textContent = 'Very weak'; reconcile();
assert.equal(submit.disabled, false, 'The asynchronous native strength estimator must not restore the old restriction');
assert.equal(meter.textContent, 'Valid');
for (const value of ['corta', '12345678', 'password123', 'AAAAAAAA', '        ', 'nube\nazul']) {
  type(value); assert.equal(submit.disabled, true, value);
}
confirmation.visible = true;
type('nubeazul'); assert.equal(submit.disabled, true, 'A visible confirmation must match');
confirmation.value = 'nubeazul'; for (const handler of confirmation.handlers) handler();
assert.equal(submit.disabled, false);
console.log('PASS native reset policy: minimum length, common passwords, hidden confirmation, visible confirmation and asynchronous meter');
