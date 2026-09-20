const { test } = require('node:test');
const assert = require('node:assert/strict');
const { readFileSync } = require('node:fs');
const vm = require('node:vm');

function setup(request = {}) {
  let tool;
  vm.runInNewContext(readFileSync('resources/js/tool.js', 'utf8'), {
    window: { Vue: { h: (...args) => args }, confirm: () => true },
    location: { search: '' }, URLSearchParams,
    Nova: { inertia: (_, component) => { tool = component; }, request: () => request },
  });
  const state = tool.data();
  for (const [key, method] of Object.entries(tool.methods)) state[key] = method.bind(state);
  state.$nextTick = () => {};
  return { state, tool };
}
const metadata = { tokens: [], has_more: false, default_expiration_days: 30 };
const token = { id: 7, name: 'Reporting', abilities: ['read'], expires_at: null, owner: { id: 'staff/uuid', name: 'Reader', is_self: false } };

test('loads manageable tokens automatically and pages using the returned cursor', async () => {
  const paths = [];
  const { state } = setup({ get: async url => { paths.push(url); return { data: { ...metadata, has_more: true, next_cursor: '31' } }; } });
  await state.load();
  state.nextPage();
  assert.deepEqual(paths, ['/nova-vendor/nova-mcp/tokens?scope=manageable', '/nova-vendor/nova-mcp/tokens?scope=manageable&before=31']);
});

test('edit, rotate and revoke carry the row owner; new tokens still belong to the selected account', async () => {
  const calls = [];
  const { state } = setup({
    get: async () => ({ data: metadata }),
    patch: async (url, body) => { calls.push(['patch', url, body.name]); return { data: {} }; },
    post: async url => { calls.push(['post', url]); return { data: {} }; },
  });
  state.edit(token);
  await state.save({ preventDefault() {} });
  await state.change(token, 'rotate');
  await state.change(token, 'revoke');
  state.name = 'Mine';
  await state.save({ preventDefault() {} });
  assert.deepEqual(calls, [
    ['patch', '/nova-vendor/nova-mcp/tokens/7?owner=staff%2Fuuid', 'Reporting'],
    ['post', '/nova-vendor/nova-mcp/tokens/7/rotate?owner=staff%2Fuuid'],
    ['post', '/nova-vendor/nova-mcp/tokens/7/revoke?owner=staff%2Fuuid'],
    ['post', '/nova-vendor/nova-mcp/tokens'],
  ]);
});

test('owner selection clears secret and editing state and allows creation for that owner', async () => {
  const calls = [];
  const { state } = setup({
    get: async url => { calls.push(url); return { data: metadata }; },
    post: async url => { calls.push(url); return { data: {} }; },
  });
  state.secret = 'one-time-secret';
  state.edit(token);
  state.selectOwner(token.owner);
  assert.equal(state.secret, '');
  assert.equal(state.editing, null);
  state.name = 'Another';
  await state.save({ preventDefault() {} });
  assert.ok(calls.includes('/nova-vendor/nova-mcp/tokens?owner=staff%2Fuuid'));
  state.selectOwner();
  assert.equal(state.owner, '');
  assert.equal(calls.at(-1), '/nova-vendor/nova-mcp/tokens?scope=manageable');
});

test('a stale response cannot replace tokens after switching owners', async () => {
  const pending = [];
  const { state } = setup({ get: () => new Promise(resolve => pending.push(resolve)) });
  const first = state.load();
  state.owner = 'second';
  const second = state.load();
  pending[1]({ data: { ...metadata, tokens: [{ id: 2 }] } });
  await second;
  pending[0]({ data: { ...metadata, tokens: [{ id: 1 }] } });
  await first;
  assert.equal(state.tokens[0].id, 2);
});

test('renders the owner list and actions without a manual user-ID lookup', () => {
  const { state, tool } = setup();
  state.loading = false;
  state.tokens = [token];
  const tree = tool.render.call(state);
  const text = JSON.stringify(tree);
  assert.match(text, /Reader \(#staff\/uuid\)/);
  assert.match(text, /"Owner"/);
  assert.match(text, /"Rotate"/);
  assert.doesNotMatch(text, /Manage another user|Load tokens|User ID/);
  state.owner = token.owner.id;
  state.ownerLabel = state.ownerName(token.owner);
  state.tokens = [{ ...token, owner: undefined }];
  assert.match(JSON.stringify(tool.render.call(state)), /Show all manageable tokens/);
});
