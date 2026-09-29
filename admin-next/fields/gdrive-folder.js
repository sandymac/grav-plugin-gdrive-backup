/**
 * `gdrive-folder` field: the Drive folder text setting (Admin2 custom field),
 * with a live hint under the input: the parsed folder ID, and a warning
 * before saving when the saved account needs a Reconnect for the scope this
 * setting implies (drive with a folder, drive.file without).
 *
 * The saved value is the raw text; the server's Sync::folderId() parses it.
 * The parsing below mirrors that method: keep in sync with
 * classes/Sync.php folderId(). Admin2 draws the label and help around custom
 * fields (and strips them from `field`), so this draws neither.
 * Self-contained, because Admin2 imports each field file on its own from a blob: URL.
 */

const TAG = window.__GRAV_FIELD_TAG;
const SCOPE = 'https://www.googleapis.com/auth/';
const DEBOUNCE_MS = 200;

const esc = (s) => String(s).replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));

/** {id, link} when it parses as a Drive folder, else null. Keep in sync with Sync::folderId(). */
function parseFolder(input) {
    const s = input.trim();
    const m = s.match(/\/folders\/([A-Za-z0-9_-]+)/) || s.match(/[?&]id=([A-Za-z0-9_-]+)/);
    if (m) return { id: m[1], link: true };
    return /^[A-Za-z0-9_-]{10,}$/.test(s) ? { id: s, link: false } : null;
}

/** drive covers drive.file and drive.readonly; otherwise exact match. */
function covers(granted, need) {
    return granted.some((g) => {
        const x = g.startsWith(SCOPE) ? g : SCOPE + g;
        return x === need || (x === SCOPE + 'drive' && (need === SCOPE + 'drive.file' || need === SCOPE + 'drive.readonly'));
    });
}

class GdriveFolder extends HTMLElement {
    constructor() {
        super();
        this.attachShadow({ mode: 'open' });
        this._field = null;
        this._value = '';
        this._acct = null;   // {name, account|null} once both fetches succeed
        this._timer = null;
    }

    set field(v) { this._field = v; }
    get field() { return this._field; }
    set value(v) {
        this._value = v == null ? '' : String(v);
        const i = this.shadowRoot.querySelector('input');
        if (i && i.value !== this._value) { i.value = this._value; this._hint(); }
    }
    get value() { return this._value; }

    connectedCallback() {
        const label = this._field && this._field.label;
        this.shadowRoot.innerHTML = `
<style>
:host { display: block; }
label { display: block; font-size: .875rem; margin-bottom: .25rem; }
input { box-sizing: border-box; width: 100%; max-width: 100%; padding: .5rem .75rem; font: inherit; color: var(--foreground, inherit); background: var(--background, transparent); border: 1px solid var(--border, #ccc); border-radius: var(--radius, 6px); }
input:focus-visible { outline: 2px solid var(--ring, currentColor); outline-offset: 1px; }
.hint { margin-top: .375rem; font-size: .8125rem; color: var(--muted-foreground, #666); overflow-wrap: anywhere; }
.hint p { margin: .25rem 0 0; }
.warn { color: var(--destructive, #b45309); }
code { font-family: var(--font-mono, monospace); }
</style>
${label ? `<label for="f">${esc(label)}</label>` : ''}
<input id="f" type="text" autocomplete="off" spellcheck="false" aria-describedby="h"${label ? '' : ' aria-label="Drive folder (link or ID)"'}${this._field && this._field.placeholder ? ` placeholder="${esc(this._field.placeholder)}"` : ''}>
<div class="hint" id="h" role="status"></div>`;
        const input = this.shadowRoot.querySelector('input');
        input.value = this._value;
        input.addEventListener('input', () => {
            this._value = input.value;
            this.dispatchEvent(new CustomEvent('change', { detail: this._value, bubbles: true }));
            clearTimeout(this._timer);
            this._timer = setTimeout(() => this._hint(), DEBOUNCE_MS);
        });
        // Enter must not submit Admin2's settings form.
        input.addEventListener('keydown', (e) => { if (e.key === 'Enter') e.preventDefault(); });
        this._hint();
        this._load();
    }

    disconnectedCallback() { clearTimeout(this._timer); }

    async _call(path, retried = false) {
        const headers = { Accept: 'application/json' };
        if (window.__GRAV_API_TOKEN) headers['X-API-Token'] = window.__GRAV_API_TOKEN;
        if (window.__GRAV_ENVIRONMENT) headers['X-Grav-Environment'] = headers['X-Config-Environment'] = window.__GRAV_ENVIRONMENT;
        const resp = await fetch((window.__GRAV_API_SERVER_URL || '') + (window.__GRAV_API_PREFIX || '/api/v1') + path, { headers });
        if (resp.status === 401 && !retried) {
            await new Promise((r) => setTimeout(r, 400));
            return this._call(path, true);
        }
        if (!resp.ok) throw new Error(`HTTP ${resp.status}`);
        const json = await resp.json();
        return json.data ?? json;
    }

    /** Any failure (403, 404, network) leaves _acct null: no scope warning, typing unaffected. */
    async _load() {
        try {
            const cfg = await this._call('/config/plugins/gdrive-backup');
            const name = String(cfg.account || 'personal');
            const list = await this._call('/gdrive/accounts');
            const accounts = Array.isArray(list) ? list : list.accounts;
            if (!Array.isArray(accounts)) return;
            this._acct = { name, account: accounts.find((a) => a.name === name) || null };
            this._hint();
        } catch (e) { /* degrade silently */ }
    }

    _hint() {
        const raw = this._value.trim();
        const parts = [];
        if (raw) {
            const p = parseFolder(raw);
            if (!p) parts.push('<p class="warn">⚠ That doesn’t look like a Drive folder link or ID.</p>');
            else if (p.link) parts.push(`<p>Folder ID: <code>${esc(p.id)}</code></p>`);
        }
        const w = this._scopeWarning(raw !== '');
        if (w) parts.push(`<p class="warn">${w}</p>`, '<p>Based on the saved account; if you change the account above, save first.</p>');
        this.shadowRoot.getElementById('h').innerHTML = parts.join('');
    }

    /** HTML (built from esc()'d parts) or ''. */
    _scopeWarning(hasFolder) {
        if (!this._acct) return '';
        const { name, account: a } = this._acct;
        const n = esc(name);
        if (!a) return `⚠ There’s no account named <strong>${n}</strong> yet.`;
        if (a.type !== 'oauth') return '';
        if (!a.connected) return `⚠ <strong>${n}</strong> isn’t connected yet: click <strong>Connect</strong> on Google Drive Auth → Accounts.`;
        if (covers(Array.isArray(a.scopes) ? a.scopes : [], SCOPE + (hasFolder ? 'drive' : 'drive.file'))) return '';
        if (!hasFolder) return '';  // a connected account without even drive.file: the folder-check line covers it
        const base = window.location.pathname.split('/plugins/')[0];
        return `⚠ Using a folder you picked needs full Drive access (<code>drive</code>), which <strong>${n}</strong> hasn’t granted yet. Save, then click <strong>Reconnect</strong> on <a href="${esc(base)}/plugins/gdrive-auth">Google Drive Auth → Accounts</a> and approve full access.`;
    }
}

if (TAG && !customElements.get(TAG)) customElements.define(TAG, GdriveFolder);
