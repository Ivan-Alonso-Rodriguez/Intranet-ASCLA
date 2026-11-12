/* The native reset form follows the same requirements as the ASCLA API. */
(function ($) {
  'use strict';
  $(function () {
    const form = document.getElementById('resetpassform');
    const config = window.ASCLA_PASSWORD_POLICY;
    if (!form || !config) return;
    const input = form.querySelector('#pass1');
    const confirmation = form.querySelector('#pass2');
    const meter = form.querySelector('#pass-strength-result');
    const submit = form.querySelector('#wp-submit');
    if (!input || !confirmation || !submit) return;
    for (const field of [input, confirmation]) {
      field.minLength = config.minimum;
      field.maxLength = 4096;
      field.required = true;
      field.autocomplete = 'off';
      field.setAttribute('data-lpignore', 'true');
      field.setAttribute('data-1p-ignore', 'true');
      field.setAttribute('data-bwignore', 'true');
    }
    input.removeAttribute('data-pw');
    input.removeAttribute('data-reveal');
    const bindVisibility = (field, button) => {
      if (!button) return;
      const icon = button.querySelector('.dashicons');
      button.classList.add('ascla-password-toggle');
      button.setAttribute('aria-controls', field.id);
      const refresh = () => {
        const visible = field.type === 'text';
        button.setAttribute('aria-label', visible ? config.hide : config.show);
        button.setAttribute('aria-pressed', String(visible));
        if (icon) icon.className = 'dashicons ' + (visible ? 'dashicons-hidden' : 'dashicons-visibility');
      };
      button.addEventListener('click', () => {
        field.type = field.type === 'password' ? 'text' : 'password';
        refresh();
      });
      refresh();
    };
    bindVisibility(input, form.querySelector('.wp-hide-pw'));
    const confirmationWrap = document.createElement('span');
    confirmationWrap.className = 'wp-pwd ascla-confirm-password';
    confirmation.before(confirmationWrap);
    confirmationWrap.append(confirmation);
    confirmation.classList.add('password-input');
    const confirmationToggle = document.createElement('button');
    confirmationToggle.type = 'button';
    confirmationToggle.className = 'button wp-hide-pw';
    const confirmationIcon = document.createElement('span');
    confirmationIcon.className = 'dashicons dashicons-visibility';
    confirmationIcon.setAttribute('aria-hidden', 'true');
    confirmationToggle.append(confirmationIcon);
    confirmationWrap.append(confirmationToggle);
    bindVisibility(confirmation, confirmationToggle);
    const sync = function () {
      const value = input.value;
      const normalized = value.trim().normalize('NFD').replace(/[\u0300-\u036f]/g, '').toLowerCase();
      const valid = Array.from(value.trim()).length >= config.minimum
        && new TextEncoder().encode(value).length <= 4096
        && !/[\x00-\x1f\x7f]/.test(value)
        && !config.common.includes(normalized)
        && !/^(.)\1+$/u.test(normalized);
      const matches = confirmation.value === value;
      submit.disabled = !valid || !matches;
      if (meter) {
        const mismatch = valid && confirmation.value !== '' && !matches;
        const label = mismatch ? config.mismatch : (valid ? config.valid : config.invalid);
        const state = valid && !mismatch ? 'good' : 'short';
        if (meter.textContent !== label) meter.textContent = label;
        if (meter.className !== state) meter.className = state;
      }
    };
    $(input).on('input.asclaPolicy', sync);
    $(confirmation).on('input.asclaPolicy', sync);
    sync();
  });
})(jQuery);
