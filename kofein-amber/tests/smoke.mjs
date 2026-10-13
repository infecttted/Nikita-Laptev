import assert from 'node:assert/strict';
const base=process.argv[2] || 'http://127.0.0.1:8000';
let r=await fetch(base+'/register');
assert.equal(r.status,200);
let html=await r.text();
const cookie=r.headers.get('set-cookie').split(';')[0];
const csrf=html.match(/name="csrf" value="([a-f0-9]+)"/)[1];
assert.ok(html.includes('Создать аккаунт'));
r=await fetch(base+'/login'); assert.equal(r.status,200);
for(const path of ['/dashboard','/suppliers','/suppliers/create','/suppliers/edit/1']) {
 r=await fetch(base+path,{redirect:'manual'});assert.equal(r.status,303);assert.equal(r.headers.get('location'),'/login');
}
r=await fetch(base+'/api/suppliers');assert.equal(r.status,401);
r=await fetch(base+'/register',{method:'POST',headers:{Cookie:cookie},body:new URLSearchParams({csrf,name:'',email:'bad',password:'123'})});assert.equal(r.status,422);
r=await fetch(base+'/register',{method:'POST',headers:{Cookie:cookie},body:new URLSearchParams({csrf:'wrong'})});assert.equal(r.status,403);
for(const path of ['/config.php','/database/001_schema.sql','/nonexistent']){r=await fetch(base+path);assert.equal(r.status,404);}
r=await fetch(base+'/assets/app.css');assert.equal(r.status,200);
console.log('PASS: auth pages, guest redirects, API 401, server validation 422, CSRF 403, private paths 404, CSS 200.');
