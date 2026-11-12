/* Native reset UI contract, isolated from accounts and password changes. */
const assert = require('node:assert/strict');
const fs = require('node:fs');
const vm = require('node:vm');
const created = [];
function element() {
  return {
    attributes: {}, children: [], events: {}, classList: { add() {} },
    setAttribute(name, value) { this.attributes[name] = value; },
    append(child) { this.children.push(child); },
    before() {},
    querySelector() { return this.children[0] || null; },
    addEventListener(name, listener) { this.events[name] = listener; },
  };
}
function passwordField(attributes = {}) {
  return {
    ...element(), value: '', type: 'password', handlers: [], attributes,
    setAttribute(name, value) { this.attributes[name] = value; },
    removeAttribute(name) { delete this.attributes[name]; },
  };
}
const input = passwordField({ 'data-pw': 'Core-generated-password', 'data-reveal': '1' });
const confirmation = passwordField();
input.id = 'pass1'; confirmation.id = 'pass2';
const toggle = element(); toggle.append(element());
const submit = { disabled: true };
const meter = { textContent: '', className: '' };
const fields = { '#pass1': input, '#pass2': confirmation, '#wp-submit': submit, '#pass-strength-result': meter, '.wp-hide-pw': toggle };
const form = { querySelector: selector => fields[selector] || null };
const jQuery = target => typeof target === 'function' ? target() : { on: (_events, callback) => target.handlers.push(callback) };
vm.runInNewContext(fs.readFileSync('wp-content/plugins/ascla-core/assets/password-policy.js', 'utf8'), {
  jQuery, document: { getElementById: () => form, createElement: () => { const node=element(); created.push(node); return node; } }, TextEncoder,
  window: { ASCLA_PASSWORD_POLICY: { minimum: 8, common: ['12345678', 'password123'], valid: 'Valid', invalid: 'Invalid', mismatch: 'Does not match', show: 'Show password', hide: 'Hide password' } },
});
function type(value) { input.value = value; for (const handler of input.handlers) handler(); }
assert.equal(submit.disabled, true);
assert.equal(input.minLength, 8);
assert.equal(input.value, '');
assert.equal(input.autocomplete, 'off');
assert.equal(input.attributes['data-pw'], undefined);
assert.equal(input.attributes['data-reveal'], undefined);
type('nubeazul');
assert.equal(submit.disabled, true, 'The user must confirm their password');
assert.equal(meter.textContent, 'Valid');
for (const value of ['corta', '12345678', 'password123', 'AAAAAAAA', '        ', 'nube\nazul']) {
  confirmation.value = value;
  type(value); assert.equal(submit.disabled, true, value);
}
type('nubeazul'); assert.equal(submit.disabled, true, 'A visible confirmation must match');
assert.equal(meter.textContent, 'Does not match');
confirmation.value = 'nubeazul'; for (const handler of confirmation.handlers) handler();
assert.equal(submit.disabled, false);
const confirmToggle=created.find(node=>node.attributes['aria-controls']==='pass2');
assert.ok(confirmToggle);
confirmToggle.events.click();
assert.equal(confirmation.type,'text');assert.equal(input.type,'password');
assert.equal(confirmToggle.attributes['aria-label'],'Hide password');assert.equal(confirmToggle.attributes['aria-pressed'],'true');
toggle.events.click();assert.equal(input.type,'text');
confirmToggle.events.click();assert.equal(confirmation.type,'password');assert.equal(input.type,'text');
assert.equal(confirmation.value,'nubeazul');assert.equal(input.value,'nubeazul');assert.equal(submit.disabled,false);
console.log('PASS native reset policy: blank input, no generated password, matching confirmation and shared requirements');
console.log('PASS independent password visibility controls preserve values and validation');
