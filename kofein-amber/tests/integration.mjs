// Run against a disposable local database. Creates two accounts and leaves account B's supplier.
import assert from 'node:assert/strict';
const base = process.argv[2] || 'http://127.0.0.1:8000';
class Client {
  cookie = '';
  async request(path, data) {
    const response = await fetch(base + path, {
      method: data ? 'POST' : 'GET', redirect: 'manual',
      headers: {Cookie: this.cookie, ...(data ? {'Content-Type': 'application/x-www-form-urlencoded'} : {})},
      body: data ? new URLSearchParams(data) : undefined
    });
    const cookie = response.headers.get('set-cookie');
    if (cookie) this.cookie = cookie.split(';')[0];
    return {status: response.status, location: response.headers.get('location'), text: await response.text()};
  }
  async token(path) {
    const page = await this.request(path);
    assert.equal(page.status, 200, page.text);
    const match = page.text.match(/name="csrf" value="([a-f0-9]+)"/);
    assert.ok(match, 'CSRF token present'); return match[1];
  }
}
const a = new Client(), b = new Client();
const suffix = Date.now();
assert.equal((await a.request('/dashboard')).location, '/login');
for (const [index, client] of [a, b].entries()) {
  const csrf = await client.token('/register');
  const email = `test-${suffix}-${index}@example.com`;
  assert.equal((await client.request('/register', {csrf, name: '', email: 'bad', password: '123'})).status, 422);
  assert.equal((await client.request('/register', {csrf, name: `Tester ${index}`, email, password: 'Secret123!'})).location, '/login');
  assert.equal((await client.request('/register', {csrf, name: 'Duplicate', email, password: 'Secret123!'})).status, 422);
  assert.equal((await client.request('/login', {csrf, email, password: 'wrong'})).status, 422);
  assert.equal((await client.request('/login', {csrf, email, password: 'Secret123!'})).location, '/dashboard');
}
const tokenA = await a.token('/suppliers/create'), tokenB = await b.token('/suppliers/create');
assert.equal((await a.request('/suppliers/create', {csrf: 'invalid', name: 'bad'})).status, 403);
assert.equal((await a.request('/suppliers/create', {csrf: tokenA, name: ''})).status, 422);
assert.equal((await a.request('/suppliers/create', {csrf: tokenA, name: 'X', phone: 'letters'})).status, 422);
assert.equal((await a.request('/suppliers/create', {csrf: tokenA, name: '<script>alert(1)</script>', contact_person: 'Алексей', phone: '+7 (999) 123-45-67', user_id: '99999'})).location, '/suppliers');
assert.equal((await b.request('/suppliers/create', {csrf: tokenB, name: 'Private B'})).status, 303);
const listA = JSON.parse((await a.request('/api/suppliers')).text);
assert.equal(listA.length, 1); const id = listA[0].id;
assert.equal(JSON.parse((await b.request('/api/suppliers')).text)[0].name, 'Private B');
const html = (await a.request('/suppliers')).text;
assert.ok(html.includes('&lt;script&gt;')); assert.ok(!html.includes('<script>alert(1)</script>'));
assert.equal((await b.request('/suppliers/edit/' + id)).status, 404);
assert.equal((await b.request('/suppliers/edit/' + id, {csrf: tokenB, name: 'Hacked'})).status, 404);
assert.equal((await b.request('/suppliers/delete/' + id, {csrf: tokenB})).status, 404);
assert.equal((await a.request('/suppliers/delete/' + id)).status, 405);
assert.equal((await a.request('/suppliers/edit/' + id, {csrf: tokenA, name: 'Updated', phone: '12345'})).location, '/suppliers');
assert.equal(JSON.parse((await a.request('/api/suppliers?q=Updated')).text).length, 1);
assert.equal(JSON.parse((await a.request('/api/suppliers?q=' + encodeURIComponent("' OR 1=1 --"))).text).length, 0);
assert.equal((await a.request('/suppliers/delete/' + id, {csrf: tokenA})).location, '/suppliers');
assert.equal(JSON.parse((await a.request('/api/suppliers')).text).length, 0);
assert.equal((await a.request('/logout', {csrf: tokenA})).location, '/login');
assert.equal((await a.request('/dashboard')).location, '/login');
assert.equal((await a.request('/api/suppliers')).status, 401);
console.log('PASS: registration, login, CRUD, validation, ownership, CSRF, XSS escaping, SQL-like search, logout.');
