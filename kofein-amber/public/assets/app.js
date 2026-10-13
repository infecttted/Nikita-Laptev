'use strict';
document.addEventListener('submit', event => {
  const form = event.target;
  if (form.matches('[data-confirm]') && !window.confirm('Точно удалить поставщика?')) event.preventDefault();
  if (!form.matches('[data-validate]')) return;
  for (const field of form.querySelectorAll('input:not([type=hidden])')) {
    field.setCustomValidity('');
    if (field.required && !field.value.trim()) field.setCustomValidity('Заполните это поле.');
    if (field.name === 'password' && new TextEncoder().encode(field.value).length > 72) field.setCustomValidity('Пароль должен занимать не более 72 байт.');
    if (field.name === 'phone' && field.value.trim() && !/^[+0-9() .-]{5,30}$/.test(field.value.trim())) field.setCustomValidity('Проверьте формат телефона.');
  }
  if (!form.reportValidity()) event.preventDefault();
});
document.addEventListener('input', event => { if (event.target.setCustomValidity) event.target.setCustomValidity(''); });

const searchForm = document.querySelector('#search-form');
if (searchForm) {
  let pending;
  searchForm.addEventListener('submit', async event => {
    event.preventDefault();
    pending?.abort(); pending = new AbortController();
    const status = document.querySelector('#search-status');
    status.textContent = 'Поиск…';
    try {
      const response = await fetch('/api/suppliers?q=' + encodeURIComponent(document.querySelector('#search').value), {signal: pending.signal, headers: {'Accept': 'application/json'}});
      if (response.status === 401) { window.location.assign('/login'); return; }
      if (!response.ok) throw new Error('Request failed');
      const suppliers = await response.json();
      const body = document.querySelector('#supplier-rows');
      body.replaceChildren();
      for (const supplier of suppliers) {
        const row = document.createElement('tr');
        for (const key of ['name', 'contact_person', 'phone']) {
          const cell = document.createElement('td'); cell.textContent = supplier[key] || '—'; row.append(cell);
        }
        const cell = document.createElement('td');
        const actions = document.createElement('div'); actions.className = 'row-actions';
        const edit = document.createElement('a'); edit.className = 'btn btn-sm btn-outline-secondary'; edit.href = '/suppliers/edit/' + supplier.id; edit.textContent = 'Редактировать';
        const form = document.createElement('form'); form.method = 'post'; form.action = '/suppliers/delete/' + supplier.id; form.dataset.confirm = '';
        const token = document.createElement('input'); token.type = 'hidden'; token.name = 'csrf'; token.value = body.dataset.csrf;
        const button = document.createElement('button'); button.className = 'btn btn-sm btn-outline-danger'; button.textContent = 'Удалить';
        form.append(token, button); actions.append(edit, form); cell.append(actions); row.append(cell); body.append(row);
      }
      document.querySelector('#empty-state').hidden = suppliers.length > 0;
      status.textContent = 'Найдено: ' + suppliers.length;
    } catch (error) { if (error.name !== 'AbortError') status.textContent = 'Не удалось загрузить данные. Повторите поиск.'; }
  });
}
