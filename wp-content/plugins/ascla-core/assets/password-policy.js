/* The native reset form follows the same requirements as the ASCLA API. */
(function ($) {
  'use strict';
  $(function () {
    const form = document.getElementById('resetpassform');
    const config = window.ASCLA_PASSWORD_POLICY;
    if (!form || !config) return;
    const input = form.querySelector('#pass1');
    const meter = form.querySelector('#pass-strength-result');
    const submit = form.querySelector('#wp-submit');
    if (!input || !submit) return;
    input.minLength = config.minimum;
    input.maxLength = 4096;
    const sync = function () {
      const value = input.value;
      const normalized = value.trim().normalize('NFD').replace(/[\u0300-\u036f]/g, '').toLowerCase();
      const valid = Array.from(value.trim()).length >= config.minimum
        && new TextEncoder().encode(value).length <= 4096
        && !/[\x00-\x1f\x7f]/.test(value)
        && !config.common.includes(normalized)
        && !/^(.)\1+$/u.test(normalized);
      const confirmation = form.querySelector('#pass2');
      // WordPress hides pass2 and fills it only on submit. It is not an
      // interactive confirmation field when its native JavaScript is active.
      const confirmationVisible = confirmation && !confirmation.disabled && confirmation.getClientRects().length > 0;
      submit.disabled = !valid || (confirmationVisible && confirmation.value !== value);
      // Core normally adds an extra weak-password opt-in based on zxcvbn. The
      // server still validates every submission against the shared ASCLA policy.
      const weak = form.querySelector('.pw-weak');
      if (weak) {
        weak.hidden = true;
        const checkbox = weak.querySelector('.pw-checkbox');
        if (checkbox) checkbox.checked = valid;
      }
      if (meter) {
        const label = valid ? config.valid : config.invalid;
        const state = valid ? 'good' : 'short';
        if (meter.textContent !== label) meter.textContent = label;
        if (meter.className !== state) meter.className = state;
      }
    };
    $(input).on('input.asclaPolicy pwupdate.asclaPolicy', sync);
    const confirmation = form.querySelector('#pass2');
    if (confirmation) $(confirmation).on('input.asclaPolicy', sync);
    // Core loads its strength estimator asynchronously, so reconcile its result.
    if (meter) new MutationObserver(sync).observe(meter, {attributes: true, childList: true, subtree: true});
    sync();
  });
})(jQuery);
