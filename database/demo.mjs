import fs from 'node:fs';
import {seedShop} from './shop-demo.mjs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';
import { execFileSync } from 'node:child_process';
import assert from 'node:assert/strict';

const root = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..');
const state = path.join(root, 'state');
const install = JSON.parse(fs.readFileSync(path.join(state, 'install.private.json'), 'utf8'));
const bootstrap = JSON.parse(fs.readFileSync(path.join(state, 'demo-bootstrap.json'), 'utf8'));
assert.equal(path.resolve(install.root), root);
assert.equal(install.database, 'nosql186_web_test_c00_20261016_f72e93');
const marker = path.join(state, 'demo-started.json');
assert(!fs.existsSync(marker), 'Do not replay demo POSTs after interruption');
fs.writeFileSync(marker, JSON.stringify({ demoRunId: install.install_id, stage: 'in_progress' }));
const calls = [];
const sessions = [];
const ids = { ...bootstrap.ids };
const reportFile = path.join(state, 'demo-verification.json');
let result = {};
function save(extra = {}) {
  result = { ...result, ...extra };
  fs.writeFileSync(reportFile, JSON.stringify({ demoRunId: install.install_id, database: install.database, calls, ids, ...result }, null, 2));
}
async function call(session, method, route, data, expected = 200) {
  const headers = { Accept: 'application/json', Origin: 'http://nosql.localhost:3310', Host: 'nosql.localhost:8010' };
  if (session.cookies.size) headers.Cookie = [...session.cookies].map(([key, value]) => `${key}=${value}`).join('; ');
  if (session.cookies.has('XSRF-TOKEN')) headers['X-XSRF-TOKEN'] = decodeURIComponent(session.cookies.get('XSRF-TOKEN'));
  if (data !== undefined) headers['Content-Type'] = 'application/json';
  const response = await fetch(`http://127.0.0.1:8010${route}`, { method, headers, body: data === undefined ? undefined : JSON.stringify(data), redirect: 'manual', signal: AbortSignal.timeout(20000) });
  for (const value of response.headers.getSetCookie()) {
    const pair = value.split(';')[0];
    const equal = pair.indexOf('=');
    session.cookies.set(pair.slice(0, equal), pair.slice(equal + 1));
  }
  const text = await response.text();
  calls.push({ method, route, expected, status: response.status });
  save();
  assert.equal(response.status, expected, `HTTP ${method} ${route}; see private application log`);
  return text ? JSON.parse(text) : null;
}
async function login(role) {
  const s = { cookies: new Map() };
  sessions.push(s);
  await call(s, 'GET', '/sanctum/csrf-cookie', undefined, 204);
  await call(s, 'POST', '/login', install.demo_accounts[role]);
  const me = await call(s, 'GET', '/api/me');
  assert(me.user.id);
  return s;
}
function readback() {
  const out = execFileSync(path.join(root, 'runtime/php/php.exe'), ['-c', path.join(state, 'php.ini'), path.join(root, 'database/database.php'), 'readback'], { encoding: 'utf8', timeout: 30000, windowsHide: true });
  const value = JSON.parse(out);
  assert(value.ok && value.database === install.database);
  return value.data;
}
const decimal = v => typeof v === 'string' ? v : v?.$numberDecimal;
try {
  const owner = await login('owner');
  const b = await call(owner, 'POST', '/api/organization/branches', { chain_id: ids.chain, code: 'CN-CD', name: 'Chi nhánh Cánh Đồng' }, 201);
  ids.branchB = b.data.id;
  const wh = await call(owner, 'POST', '/api/organization/warehouses', { branch_id: ids.branchB, code: 'KHO-CD', name: 'Kho Cánh Đồng', warehouse_type: 'sales' }, 201);
  ids.warehouseB = wh.data.id;
  await call(owner, 'PATCH', `/api/organization/branches/${ids.branchB}`, { default_sales_warehouse_id: ids.warehouseB, is_active: true });
  const seededUsers = JSON.parse(execFileSync(path.join(root, 'runtime/php/php.exe'), ['-c', path.join(state, 'php.ini'), path.join(root, 'database/database.php'), 'bootstrap-demo-users', ids.branchB, ids.branch], { encoding: 'utf8', timeout: 30000, windowsHide: true }));
  assert(seededUsers.ok);
  Object.assign(ids, seededUsers.ids);
  const staff = await login('staff');
  const manager = await login('manager');
  const cat = await call(owner, 'POST', '/api/catalog/categories', { code: 'VTNN', name: 'Vật tư nông nghiệp' }, 201);
  const unit = await call(owner, 'POST', '/api/catalog/units', { code: 'GOI', name: 'Gói' }, 201);
  ids.category = cat.data.id;
  ids.unit = unit.data.id;
  const product = await call(owner, 'POST', '/api/catalog/products', { code: 'HAT-RAU', name: 'Hạt giống rau', category_id: ids.category, unit_id: ids.unit, sale_price: '100.00', tax_rate: '5.00' }, 201);
  ids.product = product.data.id;
  await call(owner, 'PUT', `/api/catalog/products/${ids.product}/content`, { usage_instructions: 'Sử dụng theo hướng dẫn trên bao bì.', additional_info: 'Thông tin minh họa cho bản trình diễn cục bộ.', images: [] });
  const product2 = await call(owner, 'POST', '/api/catalog/products', { code: 'PHAN-HC', name: 'Phân hữu cơ', category_id: ids.category, unit_id: ids.unit, sale_price: '40.00', tax_rate: '5.00' }, 201);
  ids.product2 = product2.data.id;
  ids.supplier = (await call(owner, 'POST', '/api/suppliers', { chain_id: ids.chain, name: 'Nhà cung cấp An Nông' }, 201)).data.id;
  ids.customer = (await call(owner, 'POST', '/api/customers', { chain_id: ids.chain, name: 'Khách hàng Nông trại Xanh', customer_type: 'farmer' }, 201)).data.id;
  const today = new Intl.DateTimeFormat('en-CA', { timeZone: 'Asia/Ho_Chi_Minh', year: 'numeric', month: '2-digit', day: '2-digit' }).format(new Date());
  const expiry = new Date(Date.now() + 180 * 86400000).toISOString().slice(0, 10);
  ids.receipt = (await call(staff, 'POST', '/api/purchase-receipts', { supplier_id: ids.supplier, warehouse_id: ids.warehouse, supplier_invoice_no: 'NHAP-001', supplier_invoice_date: today, supplier_invoice_total: '1400.00', items: [{ product_id: ids.product, lot_no: 'LO-HAT-001', expires_on: expiry, quantity: '20', unit_cost: '60.00' }, { product_id: ids.product2, lot_no: 'LO-PHAN-001', expires_on: expiry, quantity: '10', unit_cost: '20.00' }] }, 201)).id;
  await call(staff, 'POST', `/api/purchase-receipts/${ids.receipt}/submit`);
  await call(owner, 'POST', `/api/purchase-receipts/${ids.receipt}/approve`);
  let data = readback();
  ids.lot = data.product_lots.find(r => r.product_id === ids.product).id;
  ids.payable = data.debt_transactions.find(r => r.transaction_type === 'purchase_charge').id;
  ids.sale = (await call(staff, 'POST', '/api/sales-orders', { branch_id: ids.branch, warehouse_id: ids.warehouse, customer_id: ids.customer, items: [{ lot_id: ids.lot, quantity: '2', discount_amount: '20.00' }] }, 201)).id;
  await call(staff, 'POST', `/api/sales-orders/${ids.sale}/confirm`, { amount: '100.00', method: 'cash' });
  await call(owner, 'POST', `/api/debts/${ids.payable}/payments`, { amount: '200.00', method: 'bank_transfer', request_key: 'PAYABLE-001' });
  ids.transfer = (await call(staff, 'POST', '/api/stock-transfers', { from_warehouse_id: ids.warehouse, to_warehouse_id: ids.warehouseB, items: [{ lot_id: ids.lot, requested_quantity: '4' }] }, 201)).id;
  await call(owner, 'POST', `/api/stock-transfers/${ids.transfer}/approve`);
  await call(staff, 'POST', `/api/stock-transfers/${ids.transfer}/dispatch`, { items: [{ lot_id: ids.lot, dispatched_quantity: '4' }] });
  await call(manager, 'POST', `/api/stock-transfers/${ids.transfer}/receive`, { items: [{ lot_id: ids.lot, received_quantity: '3' }] });
  await call(owner, 'POST', `/api/stock-transfers/${ids.transfer}/reconcile`, { reason: 'Đối soát chênh lệch khi nhận hàng', items: [{ lot_id: ids.lot, supplemental_received_quantity: '0.2', returned_quantity: '0.5', lost_quantity: '0.3' }] });
  const report = await call(owner, 'GET', `/api/reports/sales?period=day&date=${today}&chain_id=${ids.chain}`);
  const golden = { gross_sales: '200.00', total_discount: '20.00', net_sales: '180.00', total_tax: '9.00', invoice_total: '189.00', cogs: '120.00', gross_profit: '60.00', amount_collected: '100.00', receivable_remaining: '89.00' };
  for (const [key,value] of Object.entries(golden)) assert.equal(report.summary[key],value);
  const outbox = await call(owner, 'GET', '/api/outbox?status=pending');
  assert(outbox.data.length > 0);
  for (const e of outbox.data) for (const key of ['created_at','next_attempt_at','published_at']) if (e[key] !== null) {
    assert.equal(typeof e[key], 'string');
    assert(Number.isFinite(new Date(e[key]).getTime()));
    new Intl.DateTimeFormat('vi-VN', { dateStyle: 'short', timeStyle: 'short' }).format(new Date(e[key]));
  }
  await call(staff, 'GET', '/api/outbox?status=pending', undefined, 403);
  data = readback();
  assert.equal(decimal(data.inventories.find(r => r.warehouse_id === ids.warehouse && r.lot_id === ids.lot).quantity), '14.500');
  assert.equal(decimal(data.inventories.find(r => r.warehouse_id === ids.warehouseB && r.lot_id === ids.lot).quantity), '3.200');
  assert.equal(data.stock_transfers.find(r => r.id === ids.transfer).status, 'reconciled');
  assert.equal(data.invoices.length, 1);
  assert.equal(data.payments.length, 2);
  assert.equal(data.audit_logs.length, 0);
  assert(data.outbox_events.every(r => r.status === 'pending'));
  for (const route of ['/api/catalog','/api/inventory','/api/debts','/api/stock-transfers','/api/organization','/api/notifications']) await call(owner,'GET',route);
  fs.writeFileSync(path.join(state,'demo-readback.json'), JSON.stringify({ demoRunId:install.install_id,database:install.database,data },null,2));
  save({ ok:true,golden,sync_events:outbox.data.length });
  await call(owner,'PUT',`/api/shop-admin/${ids.chain}/settings`,{name:'Agrovia — Vụ Xanh',description:'Vật tư nông nghiệp, đồng hành cùng mùa vụ.',contact:'Liên hệ trực tiếp chi nhánh',is_open:true,shipping_fee:'0.00',reservation_minutes:60,allow_partial_payment:true,allow_customer_cancel_confirmed:true});
  for (const [productId,slug,description] of [[ids.product,'hat-giong-rau','Thông tin và cách sử dụng theo nhãn sản phẩm.'],[ids.product2,'phan-huu-co','Thông tin và cách bảo quản theo nhãn sản phẩm.']]) {
    await call(owner,'PUT',`/api/shop-admin/${ids.chain}/products/${productId}`,{slug,published:true,brand:'Nông Gia',origin:'Việt Nam',packaging:'Gói',description,uses:'Tham khảo hướng dẫn trên bao bì',instructions:'Đọc nhãn trước khi sử dụng',storage:'Bảo quản theo hướng dẫn nhà sản xuất',safety:'Tuân thủ các lưu ý an toàn trên nhãn',images:['/images/shop-hero-agri-supplies.png']});
  }
  for (const [slug,title,body] of [['delivery','Chính sách giao nhận','Một đơn được xử lý tại một chi nhánh. Nhân viên xác nhận và liên hệ trước khi bàn giao.'],['returns','Đổi trả và hoàn tiền','Liên hệ cửa hàng cùng mã đơn và chứng từ. Không tự coi yêu cầu là đã được duyệt.'],['faq','Câu hỏi thường gặp','Tạo tài khoản Customer riêng trên Shop, chọn địa chỉ, kiểm tra tổng tiền rồi đặt hàng. Tài khoản nội bộ owner, manager, staff không phải tài khoản mua hàng.']]) await call(owner,'POST',`/api/shop-admin/${ids.chain}/content/pages`,{slug,title,body,published:true});
  const shop=await call(owner,'GET',`/api/shop/${ids.chain}/products`);
  assert.equal(shop.data.length,2);
  await seedShop({root,state,install,ids,owner,staff,call,save,sessions});
  save({ok:true,commerce_catalog:true});
  console.log('Demo: nhập/bán/công nợ/điều chuyển/báo cáo/Sync và catalog Shop đã đối chiếu. Worker/scanner chưa chạy nền.');
} catch (error) {
  save({ ok:false,error:'Demo interrupted or assertion failed; preserve state and inspect exact IDs. No automatic replay.' });
  console.error(error.name + ': ' + error.message);
  process.exitCode=1;
} finally {
  for (const s of sessions) {
    try { await call(s,'POST','/logout'); } catch {}
  }
}
