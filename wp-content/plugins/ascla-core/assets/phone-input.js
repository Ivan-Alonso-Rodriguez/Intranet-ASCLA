/* Country/prefix enhancement. Phone validation and privacy remain on the server. */
import {countries} from './phone/countries-29.5.3.js';

const normalize = value => value.normalize('NFD').replace(/\p{M}/gu, '').toLowerCase();
const digits = value => value.replace(/\D/g, '');
const names = locale => new Intl.DisplayNames([locale || 'es'], {type:'region'});
const match = (value, selected) => {
  if (!value.startsWith('+')) return null;
  const number = digits(value);
  const candidates = countries.filter(country => number.startsWith(country.dial));
  const specific = candidates.flatMap(country => (country.areas || []).filter(area => number.startsWith(country.dial + area)).map(area => ({country, length:country.dial.length + area.length})));
  specific.sort((a, b) => b.length - a.length || a.country.priority - b.country.priority);
  if (specific.length) return specific[0].country;
  candidates.sort((a, b) => b.dial.length - a.dial.length || a.priority - b.priority);
  return candidates.find(country => country.code === selected && country.dial.length === candidates[0]?.dial.length && (!country.areas || country.areas.some(area => area.startsWith(number.slice(country.dial.length))))) || candidates[0] || null;
};

export function setup(form, {T, I, locale}) {
  if (!document.querySelector('[data-phone-flags-style]')) {
    const style = document.createElement('link');
    style.rel = 'stylesheet';
    style.href = new URL('phone/flags-29.5.3.css', import.meta.url).href;
    style.dataset.phoneFlagsStyle = '';
    document.head.append(style);
  }
  const localized = names(locale), spanish = names('es'), english = names('en');
  const ordered = [...countries].sort((a, b) => localized.of(a.code.toUpperCase()).localeCompare(localized.of(b.code.toUpperCase()), locale));
  for (const box of form.querySelectorAll('[data-phone-input]:not([data-phone-ready])')) {
    const input = box.querySelector('input[type="tel"]');
    if (!input) continue;
    box.dataset.phoneReady = '';
    const location = normalize(box.dataset.phoneLocation || '');
    const preferred = countries.find(country => [country.code, spanish.of(country.code.toUpperCase()), english.of(country.code.toUpperCase())].some(name => normalize(name) === location));
    const selector = document.createElement('div');
    selector.className = 'phone-country-selector';
    const flag = document.createElement('span');
    flag.className = 'phone-flag';
    flag.setAttribute('aria-hidden', 'true');
    const globe = document.createElement('span');
    globe.className = 'phone-country-globe';
    globe.innerHTML = I('pin');
    globe.setAttribute('aria-hidden', 'true');
    const chevron = document.createElement('span');
    chevron.className = 'phone-country-chevron';
    chevron.innerHTML = I('chevron');
    chevron.setAttribute('aria-hidden', 'true');
    const select = document.createElement('select');
    select.dataset.phoneCountry = '';
    select.setAttribute('aria-label', T('País del teléfono'));
    select.add(new Option(T('Código internacional'), ''));
    for (const country of ordered) select.add(new Option(localized.of(country.code.toUpperCase()) + ' (+' + country.dial + ')', country.code));
    selector.append(flag, globe, chevron, select);
    box.prepend(selector);
    let selected = match(input.value, preferred?.code) || preferred;
    const paint = country => {
      selected = country;
      select.value = country?.code || '';
      flag.className = 'phone-flag' + (country ? ' phone-flag-' + country.code : '');
      flag.hidden = !country;
      globe.hidden = !!country;
      selector.title = country ? localized.of(country.code.toUpperCase()) + ' (+' + country.dial + ')' : T('Código internacional');
      input.placeholder = country ? '+' + country.dial + ' …' : '+ …';
    };
    paint(selected);
    const syncAccess = () => {
      select.disabled = input.disabled || input.readOnly;
      selector.classList.toggle('readonly', select.disabled);
      if (select.disabled) paint(input.value ? match(input.value, selected?.code) : preferred);
    };
    syncAccess();
    new MutationObserver(syncAccess).observe(input, {attributes:true, attributeFilter:['readonly', 'disabled']});
    select.addEventListener('change', () => {
      if (input.disabled || input.readOnly) { paint(selected); return; }
      const next = countries.find(country => country.code === select.value);
      const previous = match(input.value, selected?.code);
      if (!next) { paint(previous); return; }
      if (input.value && previous && previous.dial !== next.dial) input.value = input.value.replace(new RegExp(String.raw`^\+` + previous.dial), '+' + next.dial);
      paint(next);
      input.dispatchEvent(new Event('input', {bubbles:true}));
    });
    input.addEventListener('input', () => {
      const position = input.selectionStart;
      const oldLength = input.value.length;
      if (input.value.startsWith('00')) input.value = '+' + input.value.slice(2);
      else if (input.value && !input.value.startsWith('+') && /^[\d ()-]+$/.test(input.value) && selected) input.value = '+' + selected.dial + input.value;
      if (input.value.length !== oldLength && position !== null) input.setSelectionRange(position + input.value.length - oldLength, position + input.value.length - oldLength);
      if (input.value.startsWith('+')) paint(match(input.value, selected?.code));
    });
  }
}
