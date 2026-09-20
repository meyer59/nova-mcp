/* MCP Access uses Nova's Vue runtime; no duplicated Vue bundle or runtime compiler. */
(() => {
  const { h } = window.Vue;
  const abilities = ['read', 'create', 'update', 'delete', 'restore', 'actions', 'relationships'];
  const presets = { read: ['read'], actions: ['read', 'actions'], full: ['*'] };
  const date = value => value ? new Date(value).toLocaleString() : 'Never';
  const inputDate = value => {
    if (!value) return '';
    const d = new Date(value);
    return new Date(d.getTime() - d.getTimezoneOffset() * 60000).toISOString().slice(0, 16);
  };
  const Tool = {
    data: () => ({ tokens: [], page: 1, hasMore: false, endpoint: '', loading: true, busy: false, error: '', secret: '', copied: false, editing: null,
      owner: new URLSearchParams(location.search).get('owner') || '', ownerInput: '', name: '', preset: 'read', selected: ['read'], expires: '', allowNever: false, maxDays: 365 }),
    mounted() { this.ownerInput = this.owner; this.load(); },
    beforeUnmount() { this.secret = ''; },
    methods: {
      url(suffix = '') { return '/nova-vendor/nova-mcp/tokens' + suffix + ('?page=' + this.page + (this.owner ? '&owner=' + encodeURIComponent(this.owner) : '')); },
      async load() {
        this.loading = true;
        this.error = '';
        try {
          const { data } = await Nova.request().get(this.url());
          this.tokens = data.tokens; this.hasMore = !!data.has_more;
          this.endpoint = data.endpoint;
          this.allowNever = data.allow_non_expiring;
          this.maxDays = data.max_expiration_days;
          if (!this.editing) this.expires = inputDate(Date.now() + data.default_expiration_days * 86400000);
        } catch (e) { this.fail(e); } finally { this.loading = false; }
      },
      fail(e) { this.error = e.response?.data?.message || 'Unable to complete the request. Please try again.'; },
      choose(value) { this.preset = value; if (presets[value]) this.selected = [...presets[value]]; else this.selected = this.selected.filter(v => v !== '*'); },
      edit(token) {
        this.editing = token.id; this.name = token.name; this.selected = [...token.abilities]; this.expires = inputDate(token.expires_at);
        this.preset = Object.keys(presets).find(key => JSON.stringify(presets[key]) === JSON.stringify(token.abilities)) || 'custom';
        this.$nextTick(() => this.$refs.name?.focus());
      },
      reset() { this.editing = null; this.name = ''; this.choose('read'); this.error = ''; },
      async save(event) {
        event.preventDefault(); this.busy = true; this.error = ''; this.secret = '';
        try {
          const body = { name: this.name, abilities: this.selected, expires_at: this.expires ? new Date(this.expires).toISOString() : null };
          const { data } = this.editing
            ? await Nova.request().patch(this.url('/' + this.editing), body)
            : await Nova.request().post(this.url(), body);
          this.reset(); this.page = 1; await this.load(); this.secret = data.plain_text_token || ''; this.copied = false;
        } catch (e) { this.fail(e); } finally { this.busy = false; }
      },
      async change(token, operation) {
        const message = operation === 'rotate' ? 'Rotate “' + token.name + '”? Its current token will stop working immediately.' : 'Revoke “' + token.name + '”? Connected clients will lose access immediately.';
        if (!window.confirm(message)) return;
        this.busy = true; this.error = ''; this.secret = '';
        try {
          const { data } = await Nova.request().post(this.url('/' + token.id + '/' + operation));
          await this.load(); this.secret = data.plain_text_token || ''; this.copied = false;
        } catch (e) { this.fail(e); } finally { this.busy = false; }
      },
      async copy() { try { await navigator.clipboard.writeText(this.secret); this.copied = true; } catch { this.error = 'Clipboard unavailable. Select and copy the token manually.'; } },
    },
    render() {
      const button = (label, onClick, extra = {}) => h('button', { type: 'button', class: 'nm-button', disabled: this.busy, onClick, ...extra }, label);
      const label = (text, control) => h('label', { class: 'nm-label' }, [h('span', text), control]);
      const field = (key, type, extra = {}) => h('input', { type, value: this[key], onInput: e => this[key] = e.target.value, ...extra });
      return h('div', { class: 'nm-access' }, [
        h('header', [h('h1', 'MCP Access'), h('p', 'Connect an AI client using your existing Nova permissions. Each token can further limit access.')]),
        h('section', { class: 'nm-card' }, [h('h2', 'Server endpoint'), h('code', this.endpoint || 'Loading…'), h('p', 'Use this Streamable HTTP URL with an Authorization: Bearer header.')]),
        this.error && h('p', { role: 'alert', class: 'nm-error' }, this.error),
        this.secret && h('section', { class: 'nm-card nm-secret', role: 'status', 'aria-live': 'polite' }, [
          h('h2', 'Copy your token now'), h('p', 'This secret is displayed only once. Store it in your client’s secret settings.'),
          h('textarea', { readonly: true, value: this.secret, 'aria-label': 'New MCP token', onFocus: e => e.target.select() }),
          button(this.copied ? 'Copied' : 'Copy token', this.copy), button('I have saved it', () => this.secret = ''),
        ]),
        h('section', { class: 'nm-card' }, [h('h2', this.editing ? 'Edit token' : 'Create a token'),
          h('form', { onSubmit: this.save }, [
            label('Name', field('name', 'text', { required: true, maxlength: 120, placeholder: 'Reporting assistant', ref: 'name' })),
            label('Permissions', h('select', { value: this.preset, onChange: e => this.choose(e.target.value) }, [
              h('option', { value: 'read' }, 'Read only'), h('option', { value: 'actions' }, 'Read + Actions'),
              h('option', { value: 'full' }, 'Full access allowed by my Nova permissions'), h('option', { value: 'custom' }, 'Custom'),
            ])),
            this.preset === 'custom' && h('fieldset', { class: 'nm-abilities' }, [h('legend', 'Token abilities'), ...abilities.map(ability => label(ability,
              h('input', { type: 'checkbox', checked: this.selected.includes(ability), onChange: e => { this.selected = e.target.checked ? [...this.selected, ability] : this.selected.filter(v => v !== ability); } })))]),
            label('Expires at', field('expires', 'datetime-local', { required: !this.allowNever })),
            h('p', { class: 'nm-muted' }, 'Maximum lifetime: ' + this.maxDays + ' days.' + (this.allowNever ? ' Leave empty for no expiration.' : ' Expiration is required.')),
            h('div', { class: 'nm-row' }, [h('button', { class: 'nm-button nm-primary', type: 'submit', disabled: this.busy || !this.selected.length }, this.busy ? 'Saving…' : this.editing ? 'Save changes' : 'Create token'), this.editing && button('Cancel', () => { this.reset(); this.load(); })]),
          ]),
        ]),
        h('section', { class: 'nm-card' }, [h('h2', this.owner ? 'User’s tokens' : 'Your tokens'),
          this.loading ? h('p', { role: 'status' }, 'Loading tokens…') : !this.tokens.length ? h('p', 'No tokens yet. Create one to connect your first client.') :
            h('div', { class: 'nm-table-wrap' }, [h('table', [h('thead', [h('tr', ['Name', 'Abilities', 'Created', 'Last used', 'Expires', 'Status', 'Manage'].map(t => h('th', { scope: 'col' }, t)))]),
              h('tbody', this.tokens.map(token => {
                const active = !token.revoked_at && (!token.expires_at || new Date(token.expires_at) > new Date());
                return h('tr', { key: token.id }, [h('td', token.name), h('td', token.abilities.includes('*') ? 'Full Nova access' : token.abilities.join(', ')), h('td', date(token.created_at)), h('td', date(token.last_used_at)), h('td', date(token.expires_at)), h('td', token.revoked_at ? 'Revoked' : active ? 'Active' : 'Expired'), h('td', { class: 'nm-actions' }, active ? [button('Edit', () => this.edit(token)), button('Rotate', () => this.change(token, 'rotate')), button('Revoke', () => this.change(token, 'revoke'), { class: 'nm-button nm-danger' })] : '—')]);
              })),
            ])]),
        ]),
        h('nav', { class: 'nm-row', 'aria-label': 'Token pages' }, [button('Previous', () => { this.page--; this.load(); }, { disabled: this.loading || this.busy || this.page <= 1 }), h('span', 'Page ' + this.page), button('Next', () => { this.page++; this.load(); }, { disabled: this.loading || this.busy || !this.hasMore })]),
        h('details', { class: 'nm-card' }, [h('summary', 'Manage another user'), h('p', 'Requires the application’s token-management authorization. Enter a user ID from Nova’s configured user provider.'),
          h('form', { onSubmit: e => { e.preventDefault(); this.secret = ''; this.tokens = []; this.owner = this.ownerInput; this.page = 1; this.reset(); this.load(); } }, [label('User ID (empty for your own tokens)', field('ownerInput', 'text')), h('button', { type: 'submit', class: 'nm-button', disabled: this.busy }, 'Load tokens')]),
        ]),
      ]);
    },
  };
  Nova.inertia('NovaMcpAccess', Tool);
})();

