/**
 * Trash Bin — Admin Next plugin page.
 *
 * Served from GET /gpm/plugins/trash-bin/page-script. Admin2 infers
 * component mode from this file's existence, so the plugin registers no
 * page definition.
 *
 * HOST CONTRACT — verified 2026-09-29 in Admin2's shipped bundle
 * (`app/_app/immutable/nodes/20.*.js`): the host sets
 * `window.__GRAV_API_SERVER_URL`, `__GRAV_API_PREFIX` and `__GRAV_API_TOKEN`,
 * prepends `window.__GRAV_PAGE_TAG = "grav-trash-bin--page";`, imports the
 * source as a blob and appends the element with no attributes. The sidebar
 * badge listens for `grav:sidebar:badge` on window.
 */

const TAG = window.__GRAV_PAGE_TAG || 'grav-trash-bin--page';
const API = `${window.__GRAV_API_SERVER_URL || ''}${window.__GRAV_API_PREFIX || '/api/v1'}`;
const i18n = window.__GRAV_I18N;

const t = (key, args) => (i18n && i18n.has(`PLUGIN_TRASH_BIN.${key}`)
    ? i18n.t(`PLUGIN_TRASH_BIN.${key}`, args)
    : null);

const STYLES = `
  :host { display:block; font:inherit; color:var(--foreground);
          --sx-bad-fg:color-mix(in oklab, var(--destructive) 62%, var(--foreground));
          --sx-warn-fg:color-mix(in oklab, var(--warning) 62%, var(--foreground)); }
  .wrap { display:flex; flex-direction:column; gap:1rem; }
  .row { display:flex; align-items:center; gap:.5rem; flex-wrap:wrap; }
  .grow { flex:1 1 auto; }
  .muted { color:var(--muted-foreground); font-size:var(--text-xs); }
  button { font:inherit; font-size:var(--text-sm); padding:.4rem .8rem; border-radius:var(--radius-md, 6px);
           border:1px solid var(--input); background:transparent; color:inherit; cursor:pointer; }
  button[disabled] { opacity:.45; cursor:not-allowed; }
  button.primary { background:var(--primary); border-color:transparent; color:var(--primary-foreground); }
  button.danger { color:var(--muted-foreground); }
  button.danger:hover, button.danger:focus-visible { color:var(--sx-bad-fg);
           border-color:color-mix(in srgb, var(--destructive) 45%, var(--input)); }
  button.toggle, .toggle { flex:none; width:1.2rem; padding:0; border:0; background:none; text-align:left; }
  input[type=text], input[type=search] { font:inherit; padding:.35rem .5rem; border-radius:var(--radius-md, 6px);
           border:1px solid var(--input); background:transparent; color:inherit; }
  button:focus-visible, input:focus-visible, select:focus-visible, textarea:focus-visible {
           outline:none; box-shadow:0 0 0 1px var(--ring); }
  input[type=search] { min-width:16rem; }
  .table-scroll { overflow-x:auto; }
  table { width:100%; border-collapse:collapse; font-size:var(--text-sm); }
  th, td { text-align:left; padding:.45rem .4rem; border-bottom:1px solid var(--border); vertical-align:top; }
  th { font-weight:500; color:var(--muted-foreground); font-size:var(--text-xs); text-transform:none; letter-spacing:0;
       background:color-mix(in srgb, var(--muted) 30%, transparent); }
  th.num, td.num { text-align:end; font-variant-numeric:tabular-nums; white-space:nowrap; }
  td.actions { white-space:nowrap; text-align:right; }
  code { font-family:var(--font-mono, monospace); font-size:.9em; word-break:break-word; }
  .node { display:flex; gap:.25rem; align-items:flex-start; }
  .tag { display:inline-block; padding:.05rem .4rem; border-radius:999px; font-size:var(--text-xs);
         border:1px solid var(--border); margin-left:.3rem; }
  .banner { padding:.5rem .7rem; border-radius:var(--radius-md, 6px); font-size:var(--text-sm);
            background:var(--muted); }
  .banner.error { background:color-mix(in srgb, var(--destructive) 14%, transparent); color:var(--sx-bad-fg); }
  .conflict { display:flex; flex-direction:column; gap:.5rem; padding:.6rem .7rem; margin-top:.4rem;
              border-radius:var(--radius-md, 6px); background:var(--muted); }
  .conflict label { display:flex; flex-direction:column; gap:.2rem; font-size:var(--text-xs); }
`;

const SHEET = new CSSStyleSheet();
SHEET.replaceSync(STYLES);

const DATE_TIME = new Intl.DateTimeFormat(undefined, { dateStyle: 'medium', timeStyle: 'short' });

/**
 * Each entry's `tree` holds its pages as nodes: `node` is the folder path
 * below the entry's top folder (`''` for the top one), and `page: false`
 * marks a folder with no page file left in the trash, kept only so what is
 * under it stays in place. Selection, expansion and conflicts are keyed by
 * `${entry id}|${node}`.
 */
class TrashBinPage extends HTMLElement {
    #data = null;
    #error = '';
    #notice = '';
    #busy = false;
    #query = '';
    #selected = new Set();
    #expanded = new Set();
    /** Pages whose restore hit a conflict: key => { reason, message, choosing, exclude } */
    #conflicts = new Map();

    connectedCallback() {
        this.#run(async () => {});
    }

    async #call(path, options = {}) {
        const headers = { Accept: 'application/json', ...(options.headers || {}) };
        if (window.__GRAV_API_TOKEN) {
            // Not Authorization: some FPM/FastCGI hosts strip it.
            headers['X-API-Token'] = window.__GRAV_API_TOKEN;
        }
        if (options.body) {
            headers['Content-Type'] = 'application/json';
        }

        const response = await fetch(`${API}${path}`, { credentials: 'same-origin', ...options, headers });
        if (response.status === 204) {
            return null;
        }
        const payload = await response.json().catch(() => null);

        if (!response.ok) {
            const error = new Error((payload && (payload.detail || payload.title)) || t('REQUEST_FAILED', { status: response.status }) || `Request failed (HTTP ${response.status}).`);
            error.code = payload && payload.code;
            throw error;
        }

        return payload && payload.data !== undefined ? payload.data : payload;
    }

    async #reload() {
        this.#data = await this.#call('/trash');
        const pages = new Set();
        const nodes = new Set();
        let count = 0;
        for (const entry of this.#data.entries) {
            count += entry.pages;
            this.#walk(this.#root(entry), (n) => {
                nodes.add(this.#key(entry, n));
                if (n.page) pages.add(this.#key(entry, n));
            });
        }
        this.#selected = new Set([...this.#selected].filter((k) => pages.has(k)));
        this.#expanded = new Set([...this.#expanded].filter((k) => nodes.has(k)));
        window.dispatchEvent(new CustomEvent('grav:sidebar:badge', { detail: { id: 'trash-bin', count } }));
    }

    /** Runs an action, then reloads the list whether or not the action failed. */
    async #run(task) {
        this.#busy = true;
        this.#error = '';
        this.#render();
        try {
            await task();
        } catch (e) {
            this.#error = e.message;
        }
        try {
            await this.#reload();
        } catch (e) {
            this.#error ||= e.message;
        } finally {
            this.#busy = false;
            this.#render();
        }
    }

    #key(entry, node) {
        return `${entry.id}|${node.node}`;
    }

    /** The entry's tree; a translation is a single node. */
    #root(entry) {
        return entry.tree || { node: '', folder: entry.folder, title: entry.title, route: entry.route, page: true, children: [] };
    }

    #walk(node, fn) {
        fn(node);
        node.children.forEach((child) => this.#walk(child, fn));
    }

    #pages(node) {
        let count = 0;
        this.#walk(node, (n) => { count += n.page ? 1 : 0; });
        return count;
    }

    /** The nodes above this one, top first. */
    #ancestors(entry, node) {
        const out = [];
        let current = this.#root(entry);
        for (const folder of node.node ? node.node.split('/') : []) {
            out.push(current);
            current = current.children.find((c) => c.folder === folder);
        }
        return out;
    }

    /** Nearest trashed page folder above this entry. */
    #parentEntry(entry) {
        return this.#data.entries
            .filter((e) => e.kind === 'page' && e.id !== entry.id && entry.path.startsWith(`${e.path}/`))
            .sort((a, b) => b.path.length - a.path.length)[0];
    }

    /**
     * Turn ticked pages into calls. Each ticked page whose parent is not
     * ticked goes with its ticked descendants and leaves the unticked ones
     * behind; a ticked page under an unticked one gets its own call.
     * Shallowest first, so a restored parent is back before its children.
     */
    #plan(keys) {
        const jobs = [];
        for (const entry of this.#data.entries) {
            const ticked = (n) => keys.has(this.#key(entry, n));
            const visit = (n, parentMoves) => {
                if (ticked(n) && !parentMoves) {
                    jobs.push({ entry, node: n, exclude: this.#untickedBelow(n, ticked) });
                }
                // A folder with no page moves with its parent.
                const moves = ticked(n) || (!n.page && parentMoves);
                n.children.forEach((child) => visit(child, moves));
            };
            visit(this.#root(entry), false);
        }

        const depth = (job) => (job.node.node ? `${job.entry.path}/${job.node.node}` : job.entry.path).split('/').length;
        return jobs.sort((a, b) => depth(a) - depth(b));
    }

    #untickedBelow(node, ticked) {
        const out = [];
        const visit = (n) => n.children.forEach((c) => (c.page && !ticked(c) ? out.push(c.node) : visit(c)));
        visit(node);
        return out;
    }

    /** Returns true when restored; records the conflict on the row otherwise. */
    async #restoreOne({ entry, node, exclude }, location = null) {
        const key = this.#key(entry, node);
        try {
            const result = await this.#call(`/trash/${entry.id}/restore`, {
                method: 'POST',
                body: JSON.stringify({ node: node.node, exclude, ...(location || {}) }),
            });
            this.#conflicts.delete(key);
            this.#selected.delete(key);
            this.#notice = t('RESTORED_ONE', { title: node.title, route: result.route }) || `Restored “${node.title}” to ${result.route}.`;
            return true;
        } catch (e) {
            // Only the three a new location can fix; any other refusal (a
            // demo lock, a permission) is an error, not a choice to offer.
            if (!['location_taken', 'parent_missing', 'parent_in_trash'].includes(e.code)) {
                throw e;
            }
            this.#conflicts.set(key, { reason: e.code, message: e.message, choosing: e.code !== 'parent_in_trash', exclude });
            return false;
        }
    }

    #restore(jobs, location = null) {
        this.#notice = '';
        this.#run(async () => {
            let restored = 0;
            for (const job of jobs) {
                restored += (await this.#restoreOne(job, location)) ? 1 : 0;
            }
            if (jobs.length > 1) {
                this.#notice = t('RESTORED_SOME', { done: restored, total: jobs.length }) || `Restored ${restored} of ${jobs.length}.`;
            }
        });
    }

    /**
     * Bring back the trashed pages above this one, without their other
     * children, then this one.
     */
    #restoreWithParent(entry, node) {
        const conflict = this.#conflicts.get(this.#key(entry, node));
        const job = { entry, node, exclude: conflict ? conflict.exclude : [] };

        if (node.node === '') {
            const parent = this.#parentEntry(entry);
            if (parent) {
                this.#restore([{ entry: parent, node: this.#root(parent), exclude: [] }, job]);
            }
            return;
        }

        const chain = this.#ancestors(entry, node);
        let top = chain.length - 1;
        while (top > 0 && chain[top - 1].page) {
            top--;
        }
        const lifted = chain.slice(top);
        const exclude = lifted.flatMap((a) => a.children.filter((c) => !lifted.includes(c)).map((c) => c.node));

        this.#restore([{ entry, node: chain[top], exclude }, job]);
    }

    /** Admin 2's own dialog; the browser's only if it is missing. */
    async #confirm(message, title, confirmLabel) {
        const dialogs = window.__GRAV_DIALOGS;
        return dialogs
            ? dialogs.confirm({ title, message, confirmLabel, cancelLabel: t('CANCEL') || 'Cancel', variant: 'destructive' })
            : confirm(message);
    }

    async #delete(jobs, pages) {
        const first = jobs[0].node.title || jobs[0].node.folder;
        const question = jobs.length > 1
            ? t('CONFIRM_DELETE_MANY', { n: pages }) || `Delete ${pages} pages permanently? This cannot be undone.`
            : pages > 1
                ? t('CONFIRM_DELETE_TREE', { title: first, n: pages - 1 }) || `Delete “${first}” and the ${pages - 1} pages under it permanently? This cannot be undone.`
                : t('CONFIRM_DELETE_ONE', { title: first }) || `Delete “${first}” permanently? This cannot be undone.`;
        const action = t('DELETE_PERMANENTLY') || 'Delete permanently';
        if (!(await this.#confirm(question, action, action))) {
            return;
        }
        this.#notice = '';
        this.#run(async () => {
            for (const { entry, node, exclude } of jobs) {
                if (entry.kind === 'language') {
                    await this.#call(`/trash/${entry.id}`, { method: 'DELETE' });
                } else {
                    await this.#call(`/trash/${entry.id}/discard`, {
                        method: 'POST',
                        body: JSON.stringify({ node: node.node, exclude }),
                    });
                }
                this.#conflicts.delete(this.#key(entry, node));
            }
        });
    }

    async #empty() {
        const action = t('EMPTY_TRASH') || 'Empty trash';
        if (!(await this.#confirm(t('CONFIRM_EMPTY') || 'Empty the trash? Everything in it is deleted permanently.', action, action))) {
            return;
        }
        this.#notice = '';
        this.#run(async () => {
            await this.#call('/trash', { method: 'DELETE' });
            this.#selected.clear();
            this.#conflicts.clear();
        });
    }

    #dismissFailure() {
        this.#run(() => this.#call('/trash/failure', { method: 'DELETE' }));
    }

    /** Does this node, or anything under it, match the search? */
    #shows(node, q) {
        return q === '' || `${node.title} ${node.route}`.toLowerCase().includes(q) || node.children.some((c) => this.#shows(c, q));
    }

    #render() {
        let shadow = this.shadowRoot;
        if (!shadow) {
            shadow = this.attachShadow({ mode: 'open' });
            shadow.adoptedStyleSheets = [SHEET];
        }
        const focused = shadow.activeElement && shadow.activeElement.type === 'search';
        const wrap = this.#el('div', { class: 'wrap' });
        shadow.replaceChildren(wrap);

        if (this.#error) {
            wrap.append(this.#el('div', { class: 'banner error' }, this.#error));
        }
        if (this.#notice) {
            wrap.append(this.#el('div', { class: 'banner' }, this.#notice));
        }

        const data = this.#data;
        if (!data) {
            wrap.append(this.#el('p', { class: 'muted' }, this.#busy ? (t('LOADING') || 'Loading…') : (t('NOTHING_LOADED') || 'Nothing loaded.')));
            return;
        }

        if (data.last_failure) {
            const f = data.last_failure;
            wrap.append(this.#el('div', { class: 'banner error row' },
                this.#el('span', { class: 'grow' },
                    t('LAST_FAILURE', { when: this.#date(f.at), route: f.route, message: f.message })
                        || `${this.#date(f.at)}: ${f.route} was deleted but could not be saved to the trash (${f.message}). See the Grav log.`),
                this.#button(t('DISMISS') || 'Dismiss', () => this.#dismissFailure())));
        }

        const keep = data.retention_days > 0
            ? t('KEEP_DAYS', { n: data.retention_days }) || `Items are kept for ${data.retention_days} days.`
            : t('KEEP_UNTIL_EMPTIED') || 'Items are kept until you empty the trash.';

        if (!data.entries.length) {
            wrap.append(this.#el('p', { class: 'muted' }, `${t('IS_EMPTY') || 'The trash is empty.'} ${keep}`));
            return;
        }

        const count = this.#selected.size;
        const search = this.#el('input', { type: 'search', placeholder: t('SEARCH') || 'Search title or URL' });
        search.value = this.#query;
        search.addEventListener('input', () => {
            this.#query = search.value;
            this.#render();
        });

        wrap.append(this.#el('div', { class: 'row' },
            search,
            this.#el('span', { class: 'grow muted' }, keep),
            this.#button(t('RESTORE_SELECTED', { n: count }) || `Restore selected (${count})`, () => this.#restore(this.#plan(this.#selected)), { primary: true, disabled: !count || this.#busy }),
            this.#button(t('DELETE_SELECTED', { n: count }) || `Delete selected (${count})`, () => this.#delete(this.#plan(this.#selected), count), { danger: true, disabled: !count || this.#busy }),
            this.#button(t('EMPTY_TRASH') || 'Empty trash', () => this.#empty(), { danger: true, disabled: this.#busy })));

        const q = this.#query.trim().toLowerCase();
        const shown = [];
        const body = this.#el('tbody');
        for (const entry of data.entries) {
            const root = this.#root(entry);
            if (this.#shows(root, q)) {
                this.#rows(body, entry, root, 0, q, shown);
            }
        }

        const all = this.#el('input', { type: 'checkbox', title: t('SELECT_ALL') || 'Select all shown' });
        all.checked = shown.length > 0 && shown.every((k) => this.#selected.has(k));
        all.addEventListener('change', () => {
            shown.forEach((k) => (all.checked ? this.#selected.add(k) : this.#selected.delete(k)));
            this.#render();
        });

        wrap.append(this.#el('div', { class: 'table-scroll' }, this.#el('table', {},
            this.#el('thead', {}, this.#el('tr', {},
                this.#el('th', {}, all),
                this.#el('th', {}, t('COL_PAGE') || 'Page'),
                this.#el('th', {}, t('COL_DELETED') || 'Deleted'),
                this.#el('th', { class: 'num' }, t('COL_PAGES') || 'Pages'),
                this.#el('th', { class: 'num' }, t('COL_SIZE') || 'Size'),
                this.#el('th', {}))),
            body)));

        if (!body.children.length) {
            wrap.append(this.#el('p', { class: 'muted' }, t('NO_MATCH') || 'Nothing matches that search.'));
        }

        if (focused) {
            search.focus();
            search.setSelectionRange(search.value.length, search.value.length);
        }
    }

    /**
     * A node's row and, when open, its children's. A search opens every
     * branch with a match. $shown collects what "select all" ticks: the rows
     * on screen plus the pages folded away under them.
     */
    #rows(body, entry, node, depth, q, shown) {
        const open = q !== '' || this.#expanded.has(this.#key(entry, node));
        body.append(this.#row(entry, node, depth, q === '' ? open : null));

        if (node.page) {
            shown.push(this.#key(entry, node));
        }
        if (open) {
            node.children.filter((c) => this.#shows(c, q)).forEach((c) => this.#rows(body, entry, c, depth + 1, q, shown));
        } else {
            node.children.forEach((c) => this.#walk(c, (n) => n.page && shown.push(this.#key(entry, n))));
        }
    }

    /** $open is null while searching: branches are opened by the search then. */
    #row(entry, node, depth, open) {
        const key = this.#key(entry, node);
        const top = node.node === '';

        let check = '';
        if (node.page) {
            check = this.#el('input', { type: 'checkbox' });
            check.checked = this.#selected.has(key);
            // Ticking a page ticks everything under it; untick any of those to leave it in the trash.
            check.addEventListener('change', () => {
                this.#walk(node, (n) => n.page && (check.checked ? this.#selected.add(this.#key(entry, n)) : this.#selected.delete(this.#key(entry, n))));
                this.#render();
            });
        }

        let toggle = this.#el('span', { class: 'toggle' });
        if (node.children.length && open !== null) {
            toggle = this.#button(open ? '▾' : '▸', () => {
                open ? this.#expanded.delete(key) : this.#expanded.add(key);
                this.#render();
            });
            toggle.className = 'toggle';
            toggle.title = open ? (t('COLLAPSE') || 'Collapse') : (t('EXPAND') || 'Expand');
        }

        const label = node.page
            ? this.#el('div', {}, node.title || node.folder,
                entry.kind === 'language' ? this.#el('span', { class: 'tag' }, t('TRANSLATION', { lang: entry.lang }) || `Translation (${entry.lang})`) : '')
            : this.#el('div', { class: 'muted' }, node.folder, this.#el('span', { class: 'tag' }, t('NOT_IN_TRASH') || 'Not in trash'));

        const page = this.#el('td', {},
            this.#el('div', { class: 'node', style: `padding-left:${depth * 1.2}rem` },
                toggle,
                this.#el('div', {}, label, this.#el('code', { class: 'muted' }, node.route))));

        const conflict = this.#conflicts.get(key);
        if (conflict) {
            page.append(this.#conflict(entry, node, conflict));
        }

        const pages = this.#pages(node);
        const job = { entry, node, exclude: [] };

        return this.#el('tr', {},
            this.#el('td', {}, check),
            page,
            this.#el('td', {}, top ? this.#date(entry.deleted_at) : '',
                top && entry.deleted_by ? this.#el('div', { class: 'muted' }, t('DELETED_BY', { name: entry.deleted_by }) || `by ${entry.deleted_by}`) : ''),
            this.#el('td', { class: 'num' }, pages > 1 ? String(pages) : ''),
            this.#el('td', { class: 'num' }, top ? this.#size(entry.size) : ''),
            this.#el('td', { class: 'actions' }, ...(node.page ? [
                this.#button(t('RESTORE') || 'Restore', () => this.#restore([job]), { disabled: this.#busy }),
                ' ',
                this.#button(t('DELETE') || 'Delete', () => this.#delete([job], pages), { danger: true, disabled: this.#busy }),
            ] : [])));
    }

    /** What to offer when a page cannot go back where it was. */
    #conflict(entry, node, conflict) {
        const box = this.#el('div', { class: 'conflict' }, this.#el('div', {}, conflict.message));
        const cancel = this.#button(t('CANCEL') || 'Cancel', () => {
            this.#conflicts.delete(this.#key(entry, node));
            this.#render();
        });

        if (entry.kind === 'language') {
            box.append(this.#el('div', { class: 'row' }, cancel));
            return box;
        }

        if (!conflict.choosing) {
            box.firstChild.textContent = t('PARENT_IN_TRASH') || 'The page this was under is in the trash too.';
            box.append(this.#el('div', { class: 'row' },
                this.#button(t('RESTORE_WITH_PARENT') || 'Restore parent too', () => this.#restoreWithParent(entry, node), { primary: true }),
                this.#button(t('CHOOSE_LOCATION') || 'Choose another location', () => {
                    conflict.choosing = true;
                    this.#render();
                }),
                cancel));
            return box;
        }

        const segments = node.route.split('/').filter(Boolean);
        const parent = this.#el('input', { type: 'text', value: `/${segments.slice(0, -1).join('/')}` });
        const folder = this.#el('input', { type: 'text', value: node.folder });
        const job = { entry, node, exclude: conflict.exclude };

        box.append(
            this.#el('label', {}, t('RESTORE_UNDER') || 'Restore under (page URL, / for the top level)', parent),
            this.#el('label', {}, t('FOLDER_NAME') || 'Folder name', folder),
            this.#el('div', { class: 'row' },
                this.#button(t('RESTORE_HERE') || 'Restore here', () => this.#restore([job], { parent: parent.value, folder: folder.value }), { primary: true }),
                cancel));

        return box;
    }

    #date(seconds) {
        return DATE_TIME.format(new Date(seconds * 1000));
    }

    #size(bytes) {
        if (bytes < 1024) return `${bytes} B`;
        if (bytes < 1048576) return `${(bytes / 1024).toFixed(0)} KB`;
        return `${(bytes / 1048576).toFixed(1)} MB`;
    }

    #button(label, onClick, { primary = false, danger = false, disabled = false } = {}) {
        const button = this.#el('button', { type: 'button', class: primary ? 'primary' : danger ? 'danger' : '' }, label);
        button.disabled = disabled;
        button.addEventListener('click', onClick);
        return button;
    }

    #el(tag, attrs = {}, ...children) {
        const node = document.createElement(tag);
        for (const [key, value] of Object.entries(attrs)) {
            if (value !== '') node.setAttribute(key, value);
        }
        for (const child of children) {
            if (child !== '' && child != null) node.append(child);
        }
        return node;
    }
}

if (!customElements.get(TAG)) {
    customElements.define(TAG, TrashBinPage);
}
