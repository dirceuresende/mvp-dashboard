// Microsoft MVP Dashboard
const $ = (s) => document.querySelector(s);
const state = { page: 1, pageSize: 50, total: 0, totalPages: 1 };
const newMvpsState = { page: 1, totalPages: 1 };
const newMvpsSortState = [{ col: 'entry', dir: 'desc' }];
const leavingMvpsState = { page: 1, totalPages: 1 };
const leavingMvpsSortState = [{ col: 'left', dir: 'desc' }];
let charts = {};
let map, infoWindow;
let msCountry, msLanguage;

// ---------- Column sort state ----------
// Array of {col, dir} — supports multi-column sort via Shift+click
const sortState = [];

function getSortParam() {
    if (sortState.length) {
        return sortState.map(s => s.col + ':' + s.dir).join(',');
    }
    return $('#f-sort').value || 'name';
}

function updateSortHeaders() {
    document.querySelectorAll('#mvp-table th[data-col]').forEach(th => {
        const col = th.dataset.col;
        const idx = sortState.findIndex(s => s.col === col);
        th.dataset.sortDir = idx >= 0 ? sortState[idx].dir : '';
        const label = t('col_' + col);
        let icon = '';
        if (idx >= 0) {
            const arrow = sortState[idx].dir === 'asc' ? '↑' : '↓';
            const num = sortState.length > 1
                ? `<span class="th-sort-num">${idx + 1}</span>`
                : '';
            icon = `${num}<span class="th-sort-icon">${arrow}</span>`;
        } else {
            icon = '<span class="th-sort-icon">↕</span>';
        }
        th.innerHTML = label + icon;
    });
    document.querySelectorAll('#new-mvps-table th[data-col]').forEach(th => {
        const col = th.dataset.col;
        const idx = newMvpsSortState.findIndex(s => s.col === col);
        th.dataset.sortDir = idx >= 0 ? newMvpsSortState[idx].dir : '';
        const label = col === 'entry' ? t('col_entry_date') : t('col_' + col);
        let icon = '';
        if (idx >= 0) {
            const arrow = newMvpsSortState[idx].dir === 'asc' ? '↑' : '↓';
            const num = newMvpsSortState.length > 1
                ? `<span class="th-sort-num">${idx + 1}</span>`
                : '';
            icon = `${num}<span class="th-sort-icon">${arrow}</span>`;
        } else {
            icon = '<span class="th-sort-icon">↕</span>';
        }
        th.innerHTML = label + icon;
    });
    document.querySelectorAll('#leaving-mvps-table th[data-col]').forEach(th => {
        const col = th.dataset.col;
        const idx = leavingMvpsSortState.findIndex(s => s.col === col);
        th.dataset.sortDir = idx >= 0 ? leavingMvpsSortState[idx].dir : '';
        const label = col === 'left' ? t('col_left_date') : t('col_' + col);
        let icon = '';
        if (idx >= 0) {
            const arrow = leavingMvpsSortState[idx].dir === 'asc' ? '↑' : '↓';
            const num = leavingMvpsSortState.length > 1
                ? `<span class="th-sort-num">${idx + 1}</span>`
                : '';
            icon = `${num}<span class="th-sort-icon">${arrow}</span>`;
        } else {
            icon = '<span class="th-sort-icon">↕</span>';
        }
        th.innerHTML = label + icon;
    });
}

// Register datalabels globally so values show on every chart by default
if (window.ChartDataLabels) {
    Chart.register(window.ChartDataLabels);
    Chart.defaults.set('plugins.datalabels', {
        color: '#1f2937',
        font: { weight: '600', size: 11 },
    });
}

async function fetchJSON(url) {
    const r = await fetch(url);
    if (!r.ok) throw new Error(url + ' -> ' + r.status);
    return r.json();
}

function fmt(n) {
    return (n ?? 0).toLocaleString();
}

function escapeHTML(s) {
    return (s ?? '').toString()
        .replace(/&/g, '&amp;').replace(/</g, '&lt;')
        .replace(/>/g, '&gt;').replace(/"/g, '&quot;');
}

// ---------- Stats + filters ----------

async function loadStats() {
    const s = await fetchJSON('/api/stats');
    $('#stat-active').textContent     = fmt(s.active_mvps);
    $('#stat-countries').textContent  = fmt(s.countries);
    $('#stat-left').textContent       = fmt(s.left_mvps);
    if (s.last_scan) {
        const ts = s.last_scan.finished_at || s.last_scan.started_at;
        $('#stat-lastscan').textContent = t('stat_lastscan_prefix') + ' ' + (ts || '\u2013');
    }
}

async function loadFilters() {
    const f = await fetchJSON('/api/filters');
    msCountry = new MultiSelect(document.getElementById('ms-country'), f.countries,
        { placeholder: t('all_countries'), onChange: () => { saveFilters(); reloadAll(); } });
    msLanguage = new MultiSelect(document.getElementById('ms-language'), f.languages,
        { placeholder: t('all_languages'), labelFn: prettyLang, onChange: () => { saveFilters(); reloadAll(); } });
    fillSelect('#f-level', f.levels);
    fillSelect('#f-gender', f.genders);
    fillSelect('#f-award-category', f.award_categories || []);
}

function prettyLang(s) {
    return s.replace(/_LANGUAGE$/i, '').replace(/_/g, ' ').toLowerCase()
        .replace(/\b\w/g, (c) => c.toUpperCase());
}

function fillSelect(sel, items, label = (x) => x) {
    const el = $(sel);
    items.forEach((v) => {
        const o = document.createElement('option');
        o.value = v; o.textContent = label(v);
        el.appendChild(o);
    });
}

// ---------- MultiSelect ----------

class MultiSelect {
    constructor(container, items, { placeholder = 'All', labelFn = x => x, onChange } = {}) {
        this._items = items;
        this._labelFn = labelFn;
        this._placeholder = placeholder;
        this._onChange = onChange;
        this._selected = new Set();
        this._build(container);
    }

    _build(container) {
        container.classList.add('ms-container');

        this._trigger = document.createElement('button');
        this._trigger.type = 'button';
        this._trigger.className = 'ms-trigger';
        this._trigger.innerHTML = '<span class="ms-label"></span><span class="ms-arrow">▾</span>';
        this._trigger.querySelector('.ms-label').textContent = this._placeholder;

        this._panel = document.createElement('div');
        this._panel.className = 'ms-panel';
        this._panel.hidden = true;

        const searchWrap = document.createElement('div');
        searchWrap.className = 'ms-search-wrap';
        this._searchInput = document.createElement('input');
        this._searchInput.type = 'search';
        this._searchInput.placeholder = t('ms_search');
        this._searchInput.className = 'ms-search';
        searchWrap.appendChild(this._searchInput);

        const actions = document.createElement('div');
        actions.className = 'ms-actions';
        const selAll = document.createElement('button');
        selAll.type = 'button'; selAll.textContent = t('ms_select_all');
        selAll.addEventListener('click', () => this._selectVisible());
        const clearBtn = document.createElement('button');
        clearBtn.type = 'button'; clearBtn.textContent = t('ms_clear');
        clearBtn.addEventListener('click', () => this._clearAll());
        actions.appendChild(selAll);
        actions.appendChild(clearBtn);

        this._list = document.createElement('ul');
        this._list.className = 'ms-list';

        this._panel.appendChild(searchWrap);
        this._panel.appendChild(actions);
        this._panel.appendChild(this._list);

        container.appendChild(this._trigger);
        container.appendChild(this._panel);
        this._renderList(this._items);

        this._trigger.addEventListener('click', (e) => {
            e.stopPropagation();
            this._panel.hidden = !this._panel.hidden;
            if (!this._panel.hidden) this._searchInput.focus();
        });

        this._searchInput.addEventListener('input', () => {
            const q = this._searchInput.value.toLowerCase();
            this._renderList(this._items.filter(v => this._labelFn(v).toLowerCase().includes(q)));
        });

        document.addEventListener('click', (e) => {
            if (!container.contains(e.target)) this._panel.hidden = true;
        });
    }

    _renderList(items) {
        this._list.innerHTML = '';
        items.forEach(item => {
            const li = document.createElement('li');
            const lbl = document.createElement('label');
            const cb = document.createElement('input');
            cb.type = 'checkbox';
            cb.value = item;
            cb.checked = this._selected.has(item);
            cb.addEventListener('change', () => {
                if (cb.checked) this._selected.add(item);
                else this._selected.delete(item);
                this._updateTrigger();
                this._onChange && this._onChange(this.getValues());
            });
            lbl.appendChild(cb);
            const span = document.createElement('span');
            span.textContent = this._labelFn(item);
            lbl.appendChild(span);
            li.appendChild(lbl);
            this._list.appendChild(li);
        });
    }

    _selectVisible() {
        this._list.querySelectorAll('input[type=checkbox]').forEach(cb => {
            cb.checked = true;
            this._selected.add(cb.value);
        });
        this._updateTrigger();
        this._onChange && this._onChange(this.getValues());
    }

    _clearAll() {
        this._selected.clear();
        this._list.querySelectorAll('input[type=checkbox]').forEach(cb => cb.checked = false);
        this._updateTrigger();
        this._onChange && this._onChange(this.getValues());
    }

    _updateTrigger() {
        const n = this._selected.size;
        this._trigger.querySelector('.ms-label').textContent =
            n === 0 ? this._placeholder : n + ' selected';
        this._trigger.classList.toggle('ms-has-selection', n > 0);
    }

    getValues() { return [...this._selected]; }
    reset() { this._clearAll(); }
    setValues(arr) {
        this._selected = new Set(arr.filter(v => this._items.includes(v)));
        this._renderList(this._items);
        this._updateTrigger();
    }
    updateI18n(placeholder) {
        this._placeholder = placeholder;
        this._searchInput.placeholder = t('ms_search');
        this._panel.querySelectorAll('.ms-actions button').forEach((btn, i) => {
            btn.textContent = i === 0 ? t('ms_select_all') : t('ms_clear');
        });
        this._updateTrigger();
    }
}

// ---------- Filter persistence ----------

const STORAGE_KEY = 'mvp-dashboard-filters';

function saveFilters() {
    const state = {
        q:            $('#f-q').value,
        countries:    msCountry?.getValues() ?? [],
        level:        $('#f-level').value,
        gender:       $('#f-gender').value,
        languages:    msLanguage?.getValues() ?? [],
        awardCat:     $('#f-award-category').value,
        status:       $('#f-status').value,
        sort:         $('#f-sort').value,
    };
    localStorage.setItem(STORAGE_KEY, JSON.stringify(state));
    updateActiveFiltersBar();
}

function updateActiveFiltersBar() {
    const bar = document.getElementById('active-filters-bar');
    if (!bar) return;
    const chips = []; // { label: string, action: () => void }
    const q = $('#f-q').value.trim();
    if (q) chips.push({ label: escapeHTML(q), action: () => { $('#f-q').value = ''; } });
    const countries = msCountry?.getValues() ?? [];
    countries.forEach(v => chips.push({ label: escapeHTML(v), action: () => msCountry.setValues(countries.filter(c => c !== v)) }));
    const languages = msLanguage?.getValues() ?? [];
    languages.forEach(v => chips.push({ label: escapeHTML(prettyLang(v)), action: () => msLanguage.setValues(languages.filter(l => l !== v)) }));
    const level = $('#f-level').value;
    if (level) chips.push({ label: escapeHTML(level), action: () => { $('#f-level').value = ''; } });
    const gender = $('#f-gender').value;
    if (gender) chips.push({ label: escapeHTML(gender), action: () => { $('#f-gender').value = ''; } });
    const awardCat = $('#f-award-category').value;
    if (awardCat) chips.push({ label: escapeHTML(awardCat), action: () => { $('#f-award-category').value = ''; } });
    const status = $('#f-status').value;
    if (status && status !== 'active') chips.push({ label: escapeHTML(t('status_' + status)), action: () => { $('#f-status').value = 'active'; } });
    if (!chips.length) { bar.hidden = true; return; }
    bar.hidden = false;
    bar.innerHTML = `<span class="af-label">${t('active_filters_label')}</span>` +
        chips.map((c, i) => `<span class="af-chip">${c.label}<button class="af-chip-remove" data-chip="${i}" type="button" aria-label="Remove filter">&times;</button></span>`).join('') +
        `<button class="af-clear-all" type="button" aria-label="Clear all filters">&times;</button>`;
    bar.querySelectorAll('.af-chip-remove').forEach(btn => {
        btn.addEventListener('click', () => {
            chips[+btn.dataset.chip].action();
            saveFilters();
            reloadAll();
        });
    });
    bar.querySelector('.af-clear-all').addEventListener('click', () => {
        $('#f-reset').click();
    });
}

function restoreFilters() {
    let saved;
    try { saved = JSON.parse(localStorage.getItem(STORAGE_KEY)); } catch { return; }
    if (!saved) return;
    if (saved.q)         $('#f-q').value = saved.q;
    if (saved.level)     $('#f-level').value = saved.level;
    if (saved.gender)    $('#f-gender').value = saved.gender;
    if (saved.awardCat)  $('#f-award-category').value = saved.awardCat;
    if (saved.status)    $('#f-status').value = saved.status;
    if (saved.sort)      $('#f-sort').value = saved.sort;
    if (saved.countries?.length) msCountry?.setValues(saved.countries);
    if (saved.languages?.length) msLanguage?.setValues(saved.languages);
}

// ---------- Drill-down modal ----------

const drillState = { page: 1, pageSize: 200, totalPages: 1, params: null, title: '' };

function buildDrillParams(field, value) {
    const p = new URLSearchParams();
    p.set('q', $('#f-q').value);
    p.set('status', $('#f-status').value);
    if (field !== 'country')         (msCountry?.getValues() ?? []).forEach(v => p.append('country[]', v));
    if (field !== 'language')        (msLanguage?.getValues() ?? []).forEach(v => p.append('language[]', v));
    if (field !== 'level')           p.set('level', $('#f-level').value);
    if (field !== 'gender')          p.set('gender', $('#f-gender').value);
    if (field !== 'award_category')  p.set('award_category', $('#f-award-category').value);
    if (field === 'country')          p.append('country[]', value);
    else if (field === 'language')    p.append('language[]', value);
    else                              p.set(field, value);
    return p;
}

async function openDrillModal(title, params) {
    drillState.page = 1;
    drillState.params = params;
    drillState.title = title;
    $('#drill-title').textContent = title;
    await loadDrillTable();
    $('#drill-modal').showModal();
}

async function loadDrillTable() {
    const p = new URLSearchParams(drillState.params);
    p.set('page', drillState.page);
    p.set('pageSize', drillState.pageSize);
    p.set('sort', 'name');
    const r = await fetchJSON('/api/mvps?' + p);
    drillState.totalPages = r.totalPages;

    const tbody = $('#drill-tbody');
    tbody.innerHTML = '';
    r.results.forEach(m => {
        const tr = document.createElement('tr');
        tr.dataset.id = m.id;
        const name = `${escapeHTML(m.first_name || '')} ${escapeHTML(m.last_name || '')}`.trim();
        tr.innerHTML = `
            <td><img class="avatar" src="${escapeHTML(m.picture_url || '')}" loading="lazy" onerror="this.style.visibility='hidden'"></td>
            <td><strong>${name}</strong></td>
            <td>${escapeHTML(m.country || '')}</td>
            <td>${escapeHTML(m.level_name || '')}</td>
            <td>${escapeHTML((m.headline || '').slice(0, 80))}</td>
            <td>${m.years_in_program != null ? Math.round(m.years_in_program) : ''}</td>
            <td class="num-cell">${m.activities_count || ''}</td>
            <td class="num-cell">${m.events_count || ''}</td>
            <td><span class="badge ${m.is_active ? 'active' : 'left'}">${t(m.is_active ? 'badge_active' : 'badge_left')}</span></td>
        `;
        tr.addEventListener('click', () => openDetail(m.id));
        tbody.appendChild(tr);
    });

    $('#drill-pager-info').textContent = tFn('page_info', r.page, r.totalPages, fmt(r.total));
    $('#drill-prev').disabled = r.page <= 1;
    $('#drill-next').disabled = r.page >= r.totalPages;
}

// ---------- Charts ----------

const PALETTE = [
    '#5b3cc4', '#2d6df6', '#0ea5e9', '#10b981', '#f59e0b',
    '#ef4444', '#ec4899', '#8b5cf6', '#14b8a6', '#f97316',
    '#84cc16', '#06b6d4', '#a855f7', '#eab308', '#64748b',
];

function makeBar(canvasId, labels, data, label, onClickLabel, rawLabels) {
    if (charts[canvasId]) charts[canvasId].destroy();
    const max = Math.max(...data, 0);
    const opts = {
        indexAxis: 'y',
        responsive: true,
        maintainAspectRatio: false,
        layout: { padding: { right: 36 } },
        plugins: {
            legend: { display: false },
            datalabels: {
                anchor: 'end', align: 'end', clamp: true,
                color: '#1f2937',
                formatter: (v) => fmt(v),
            },
            tooltip: { callbacks: { label: (ctx) => `${ctx.dataset.label}: ${fmt(ctx.parsed.x)}` } },
        },
        scales: {
            x: { beginAtZero: true, suggestedMax: max * 1.12, ticks: { precision: 0 } },
            y: { ticks: { autoSkip: false, font: { size: 11 } } },
        },
    };
    if (onClickLabel) {
        opts.onClick = (evt, elements) => {
            if (!elements.length) return;
            const idx = elements[0].index;
            onClickLabel(rawLabels ? rawLabels[idx] : labels[idx], labels[idx]);
        };
        opts.onHover = (evt, elements) => {
            evt.native.target.style.cursor = elements.length ? 'pointer' : 'default';
        };
    }
    charts[canvasId] = new Chart($(`#${canvasId}`), {
        type: 'bar',
        data: {
            labels,
            datasets: [{ label, data, backgroundColor: PALETTE[0], borderRadius: 4 }],
        },
        options: opts,
    });
}

function makeDoughnut(canvasId, labels, data, onClickLabel) {
    if (charts[canvasId]) charts[canvasId].destroy();
    const total = data.reduce((a, b) => a + b, 0) || 1;
    const opts = {
        responsive: true,
        maintainAspectRatio: false,
        cutout: '55%',
        plugins: {
            legend: {
                position: 'bottom',
                labels: { boxWidth: 12, padding: 8, font: { size: 11 } },
            },
            datalabels: {
                color: '#fff',
                font: { weight: '700', size: 11 },
                formatter: (v) => {
                    const pct = (v / total) * 100;
                    if (pct < 4) return '';
                    return `${fmt(v)}\n${pct.toFixed(0)}%`;
                },
                textAlign: 'center',
            },
            tooltip: {
                callbacks: {
                    label: (ctx) => `${ctx.label}: ${fmt(ctx.parsed)} (${((ctx.parsed / total) * 100).toFixed(1)}%)`,
                },
            },
        },
    };
    if (onClickLabel) {
        opts.onClick = (evt, elements) => {
            if (!elements.length) return;
            onClickLabel(labels[elements[0].index]);
        };
        opts.onHover = (evt, elements) => {
            evt.native.target.style.cursor = elements.length ? 'pointer' : 'default';
        };
    }
    charts[canvasId] = new Chart($(`#${canvasId}`), {
        type: 'doughnut',
        data: {
            labels,
            datasets: [{ data, backgroundColor: PALETTE, borderWidth: 1, borderColor: '#fff' }],
        },
        options: opts,
    });
}

function makeLine(canvasId, labels, data, label, onClickLabel) {
    if (charts[canvasId]) charts[canvasId].destroy();
    const max = Math.max(...data, 0);
    const opts = {
        responsive: true,
        maintainAspectRatio: false,
        layout: { padding: { top: 20 } },
        plugins: {
            legend: { display: false },
            datalabels: {
                align: 'top', anchor: 'end', clamp: true,
                color: '#1f2937',
                formatter: (v) => fmt(v),
            },
        },
        scales: {
            y: { beginAtZero: true, suggestedMax: max * 1.18, ticks: { precision: 0 } },
        },
    };
    if (onClickLabel) {
        opts.onClick = (evt, elements) => {
            if (!elements.length) return;
            onClickLabel(labels[elements[0].index]);
        };
        opts.onHover = (evt, elements) => {
            evt.native.target.style.cursor = elements.length ? 'pointer' : 'default';
        };
    }
    charts[canvasId] = new Chart($(`#${canvasId}`), {
        type: 'line',
        data: {
            labels,
            datasets: [{
                label, data,
                borderColor: PALETTE[0], backgroundColor: PALETTE[0] + '33',
                tension: 0.3, fill: true,
                pointRadius: 4, pointBackgroundColor: PALETTE[0],
            }],
        },
        options: opts,
    });
}

async function loadAggregations(params = '') {
    const url = '/api/aggregations' + (params ? '?' + params : '');
    const a = await fetchJSON(url);

    // Countries (top 15)
    const top = a.countries.slice(0, 15);
    makeBar('chart-countries',
        top.map(c => c.country), top.map(c => c.count), 'MVPs',
        (val, lbl) => openDrillModal(t('drill_country') + ' ' + lbl, buildDrillParams('country', val)));

    // Time in program
    makeBar('chart-time',
        a.time_in_program.map(b => b.bucket),
        a.time_in_program.map(b => b.count),
        'MVPs',
        (val) => openDrillModal(t('drill_time') + ' ' + val, buildDrillParams('years_bucket', val)));

    // Levels
    makeDoughnut('chart-level',
        a.levels.map(l => l.level_name),
        a.levels.map(l => l.count),
        (val) => openDrillModal(t('drill_level') + ' ' + val, buildDrillParams('level', val)));

    // Gender
    makeDoughnut('chart-gender',
        a.genders.map(g => g.gender),
        a.genders.map(g => g.count),
        (val) => openDrillModal(t('drill_gender') + ' ' + val, buildDrillParams('gender', val)));

    // Languages (top 10)
    const langs = a.languages.slice(0, 10);
    makeBar('chart-lang',
        langs.map(l => prettyLang(l.language)),
        langs.map(l => l.count),
        'MVPs',
        (val, lbl) => openDrillModal(t('drill_language') + ' ' + lbl, buildDrillParams('language', val)),
        langs.map(l => l.language));

    // Joins by year
    makeLine('chart-joins',
        a.joins_by_year.map(y => y.year),
        a.joins_by_year.map(y => y.count),
        'New MVPs',
        (val) => openDrillModal(t('drill_join_year') + ' ' + val, buildDrillParams('join_year', val)));

    // Social networks
    const sn = a.social_networks.slice(0, 10);
    makeBar('chart-social',
        sn.map(s => s.network_name),
        sn.map(s => s.count),
        'MVPs',
        (val) => openDrillModal(t('drill_social') + ' ' + val, buildDrillParams('social_network', val)));

    // Other tenants (programs besides MVP)
    const tn = (a.tenants || []).slice(0, 10);
    if (tn.length) {
        makeBar('chart-tenants',
            tn.map(x => x.tenant),
            tn.map(x => x.count),
            'MVPs',
            (val) => openDrillModal(t('drill_program') + ' ' + val, buildDrillParams('tenant', val)));
    }

    // Titles
    const tt = (a.titles || []).slice(0, 8);
    if (tt.length) {
        makeDoughnut('chart-title',
            tt.map(x => x.title),
            tt.map(x => x.count),
            (val) => openDrillModal(t('drill_title') + ' ' + val, buildDrillParams('title_filter', val)));
    }

    // Award Categories (top 25)
    const ac = (a.award_categories || []).slice(0, 25);
    if (ac.length) {
        makeBar('chart-award-categories',
            ac.map(c => c.category),
            ac.map(c => c.count),
            'MVPs',
            (val, lbl) => openDrillModal(t('drill_award_cat') + ' ' + lbl, buildDrillParams('award_category', val)));
    }

    // Technology Focus Areas (top 25)
    const tfa = (a.technology_focus_areas || []).slice(0, 25);
    if (tfa.length) {
        makeBar('chart-tech-focus',
            tfa.map(x => x.area),
            tfa.map(x => x.count),
            'MVPs',
            (val) => openDrillModal(t('drill_tech_focus') + ' ' + val, buildDrillParams('tech_focus_area', val)));
    }

    // Events per MVP buckets
    if (a.events_buckets?.length) {
        makeBar('chart-events-buckets',
            a.events_buckets.map(b => b.bucket),
            a.events_buckets.map(b => b.count),
            'MVPs',
            (val) => openDrillModal(t('drill_events_bucket') + ' ' + val, buildDrillParams('events_bucket', val)));
    }

    // Activities per MVP buckets
    if (a.activities_buckets?.length) {
        makeBar('chart-activities-buckets',
            a.activities_buckets.map(b => b.bucket),
            a.activities_buckets.map(b => b.count),
            'MVPs',
            (val) => openDrillModal(t('drill_activities_bucket') + ' ' + val, buildDrillParams('activities_bucket', val)));
    }

    // Top 10 companies
    const co = (a.companies || []).slice(0, 10);
    if (co.length) {
        makeBar('chart-companies',
            co.map(c => c.company_name),
            co.map(c => c.count),
            'MVPs',
            (val) => openDrillModal(t('drill_company') + ' ' + val, buildDrillParams('company', val)));
    }

    // Map markers — clear previous circles before redrawing
    if (window.__mapCircles) {
        window.__mapCircles.forEach(c => c.setMap(null));
        window.__mapCircles = [];
    }
    if (window.google && window.google.maps) {
        renderMap(a.countries);
    } else {
        window.__pendingMapData = a.countries;
    }
}

// ---------- Google Map ----------

function initMap() {
    map = new google.maps.Map($('#map'), {
        zoom: 2,
        center: { lat: 20, lng: 0 },
        mapTypeId: 'terrain',
        gestureHandling: 'greedy',
        mapId: 'f7401a5a74547325672faea6',
    });
    infoWindow = new google.maps.InfoWindow();
    if (window.__pendingMapData) renderMap(window.__pendingMapData);
}
window.initMap = initMap;

// fallback: if Google Maps script loaded before init defined, run on DOMContentLoaded
window.addEventListener('load', () => {
    if (window.google && window.google.maps && !map) initMap();
});

function renderMap(countries) {
    if (!map) return;
    if (!window.__mapCircles) window.__mapCircles = [];
    countries.forEach(c => {
        if (c.latitude == null || c.longitude == null) return;
        const radius = Math.max(40000, Math.sqrt(c.count) * 30000);
        const circle = new google.maps.Circle({
            strokeColor: '#5b3cc4', strokeOpacity: 0.7, strokeWeight: 1,
            fillColor: '#5b3cc4', fillOpacity: 0.35,
            map, center: { lat: c.latitude, lng: c.longitude },
            radius,
        });
        window.__mapCircles.push(circle);
        circle.addListener('click', () => {
            openDrillModal(t('drill_country') + ' ' + c.country, buildDrillParams('country', c.country));
        });

        // Count label
        const text = c.count >= 1000 ? (c.count / 1000).toFixed(1) + 'k' : String(c.count);
        const w = text.length * 7 + 18;
        const svg = `<svg xmlns="http://www.w3.org/2000/svg" width="${w}" height="22"><rect rx="5" fill="#5b3cc4" fill-opacity="0.88" width="${w}" height="22"/><text x="${w/2}" y="15" text-anchor="middle" fill="white" font-size="11" font-weight="bold" font-family="Segoe UI,sans-serif">${text}</text></svg>`;
        const labelEl = document.createElement('div');
        labelEl.innerHTML = svg;
        labelEl.style.cssText = 'cursor:default;pointer-events:none;';
        const label = new google.maps.marker.AdvancedMarkerElement({
            position: { lat: c.latitude, lng: c.longitude },
            map,
            content: labelEl,
            zIndex: 2,
        });
        window.__mapCircles.push(label);
    });
}

// ---------- Table ----------

async function loadTable() {
    const params = new URLSearchParams();
    params.set('q', $('#f-q').value);
    (msCountry?.getValues() ?? []).forEach(v => params.append('country[]', v));
    params.set('level', $('#f-level').value);
    params.set('gender', $('#f-gender').value);
    (msLanguage?.getValues() ?? []).forEach(v => params.append('language[]', v));
    params.set('award_category', $('#f-award-category').value);
    params.set('status', $('#f-status').value);
    params.set('sort', getSortParam());
    params.set('page', state.page);
    params.set('pageSize', state.pageSize);
    const r = await fetchJSON('/api/mvps?' + params);
    state.total = r.total; state.totalPages = r.totalPages;

    const tbody = $('#mvp-tbody');
    tbody.innerHTML = '';
    r.results.forEach(m => {
        const tr = document.createElement('tr');
        tr.dataset.id = m.id;
        const name = `${escapeHTML(m.first_name || '')} ${escapeHTML(m.last_name || '')}`.trim();
        tr.innerHTML = `
            <td><img class="avatar" src="${escapeHTML(m.picture_url || '')}" loading="lazy" onerror="this.style.visibility='hidden'"></td>
            <td><strong>${name}</strong></td>
            <td>${escapeHTML(m.country || '')}</td>
            <td>${escapeHTML(m.level_name || '')}</td>
            <td>${escapeHTML((m.headline || '').slice(0, 80))}</td>
            <td>${m.years_in_program != null ? Math.round(m.years_in_program) : ''}</td>
            <td class="num-cell">${m.activities_count || ''}</td>
            <td class="num-cell">${m.events_count || ''}</td>
            <td><span class="badge ${m.is_active ? 'active' : 'left'}">${t(m.is_active ? 'badge_active' : 'badge_left')}</span></td>
        `;
        tr.addEventListener('click', () => openDetail(m.id));
        tbody.appendChild(tr);
    });

    $('#pager-info').textContent = tFn('page_info', r.page, r.totalPages, fmt(r.total));
    $('#prev').disabled = r.page <= 1;
    $('#next').disabled = r.page >= r.totalPages;
}

async function loadNewMvpsTable() {
    const params = new URLSearchParams();
    params.set('q', $('#f-q').value);
    (msCountry?.getValues() ?? []).forEach(v => params.append('country[]', v));
    params.set('level', $('#f-level').value);
    params.set('gender', $('#f-gender').value);
    (msLanguage?.getValues() ?? []).forEach(v => params.append('language[]', v));
    params.set('award_category', $('#f-award-category').value);
    params.set('status', $('#f-status').value);
    params.set('sort', newMvpsSortState.map(s => s.col + ':' + s.dir).join(',') || 'entry:desc,name:asc');
    params.set('joined_months', '3');
    params.set('page', newMvpsState.page);
    params.set('pageSize', state.pageSize);
    const r = await fetchJSON('/api/mvps?' + params);
    newMvpsState.totalPages = r.totalPages;

    const tbody = $('#new-mvps-tbody');
    tbody.innerHTML = '';
    const dl = dateLocale();
    r.results.forEach(m => {
        const tr = document.createElement('tr');
        tr.dataset.id = m.id;
        const name = `${escapeHTML(m.first_name || '')} ${escapeHTML(m.last_name || '')}`.trim();
        const entryDate = m.program_entry_date
            ? new Date(m.program_entry_date + 'T00:00:00').toLocaleDateString(dl, { year: 'numeric', month: 'short', day: 'numeric' })
            : '';
        tr.innerHTML = `
            <td><img class="avatar" src="${escapeHTML(m.picture_url || '')}" loading="lazy" onerror="this.style.visibility='hidden'"></td>
            <td><strong>${name}</strong></td>
            <td>${escapeHTML(m.country || '')}</td>
            <td>${escapeHTML((m.headline || '').slice(0, 80))}</td>
            <td>${escapeHTML(entryDate)}</td>
            <td class="num-cell">${m.activities_count || ''}</td>
            <td class="num-cell">${m.events_count || ''}</td>
            <td><span class="badge ${m.is_active ? 'active' : 'left'}">${t(m.is_active ? 'badge_active' : 'badge_left')}</span></td>
        `;
        tr.addEventListener('click', () => openDetail(m.id));
        tbody.appendChild(tr);
    });

    $('#new-mvps-pager-info').textContent = tFn('page_info', r.page, r.totalPages, fmt(r.total));
    $('#new-mvps-prev').disabled = r.page <= 1;
    $('#new-mvps-next').disabled = r.page >= r.totalPages;
}

async function loadLeavingMvpsTable() {
    const params = new URLSearchParams();
    params.set('q', $('#f-q').value);
    (msCountry?.getValues() ?? []).forEach(v => params.append('country[]', v));
    params.set('level', $('#f-level').value);
    params.set('gender', $('#f-gender').value);
    (msLanguage?.getValues() ?? []).forEach(v => params.append('language[]', v));
    params.set('award_category', $('#f-award-category').value);
    params.set('status', 'left');
    params.set('sort', leavingMvpsSortState.map(s => s.col + ':' + s.dir).join(',') || 'left:desc,name:asc');
    params.set('left_months', '3');
    params.set('page', leavingMvpsState.page);
    params.set('pageSize', state.pageSize);
    const r = await fetchJSON('/api/mvps?' + params);
    leavingMvpsState.totalPages = r.totalPages;

    const tbody = $('#leaving-mvps-tbody');
    tbody.innerHTML = '';
    const dl = dateLocale();
    r.results.forEach(m => {
        const tr = document.createElement('tr');
        tr.dataset.id = m.id;
        const name = `${escapeHTML(m.first_name || '')} ${escapeHTML(m.last_name || '')}`.trim();
        const leftDate = m.left_at
            ? new Date(m.left_at + 'T00:00:00').toLocaleDateString(dl, { year: 'numeric', month: 'short', day: 'numeric' })
            : '';
        tr.innerHTML = `
            <td><img class="avatar" src="${escapeHTML(m.picture_url || '')}" loading="lazy" onerror="this.style.visibility='hidden'"></td>
            <td><strong>${name}</strong></td>
            <td>${escapeHTML(m.country || '')}</td>
            <td>${escapeHTML((m.headline || '').slice(0, 80))}</td>
            <td>${escapeHTML(leftDate)}</td>
            <td class="num-cell">${m.activities_count || ''}</td>
            <td class="num-cell">${m.events_count || ''}</td>
        `;
        tr.addEventListener('click', () => openDetail(m.id));
        tbody.appendChild(tr);
    });

    $('#leaving-mvps-pager-info').textContent = tFn('page_info', r.page, r.totalPages, fmt(r.total));
    $('#leaving-mvps-prev').disabled = r.page <= 1;
    $('#leaving-mvps-next').disabled = r.page >= r.totalPages;
}

// ---------- Detail modal ----------

function renderContributions(items) {
    if (!items || !items.length) return '<em>' + escapeHTML(t('no_activities')) + '</em>';
    const dl = dateLocale();
    return items.map(c => {
        const date = c.date ? new Date(c.date).toLocaleDateString(dl, { year: 'numeric', month: 'short', day: 'numeric' }) : '';
        const link = c.url ? `<a href="${escapeHTML(c.url)}" target="_blank" rel="noopener">${t('view_link')}</a>` : '';
        const badge = c.typeName ? `<span class="act-badge">${escapeHTML(c.typeName)}</span>` : '';
        const removedAt = c.removed_at || c.removedAt;
        const removedBadge = removedAt
            ? `<span class="act-badge act-badge-removed" title="${escapeHTML(removedAt.substring(0,10))}">${t('removed_prefix')} ${escapeHTML(removedAt.substring(0,10))}</span>`
            : '';
        return `<div class="act-card${removedAt ? ' act-card-removed' : ''}">
            <div class="act-card-head">${badge}${removedBadge}<span class="act-date">${escapeHTML(date)}</span></div>
            <div class="act-title">${escapeHTML(c.title || '')}</div>
            <div class="act-desc">${escapeHTML(c.description || '').substring(0, 280)}${(c.description || '').length > 280 ? '\u2026' : ''}</div>
            ${link ? `<div class="act-link">${link}</div>` : ''}
        </div>`;
    }).join('');
}

function renderEvents(items) {
    if (!items || !items.length) return '<em>' + escapeHTML(t('no_events')) + '</em>';
    const dl = dateLocale();
    return items.map(e => {
        const ds = e.dateStart ? new Date(e.dateStart).toLocaleDateString(dl, { year: 'numeric', month: 'short', day: 'numeric' }) : '';
        const de = e.dateEnd   ? new Date(e.dateEnd).toLocaleDateString(dl, { year: 'numeric', month: 'short', day: 'numeric' }) : '';
        const dateRange = ds && de && ds !== de ? `${ds} \u2013 ${de}` : (ds || de);
        const link = e.eventUri ? `<a href="${escapeHTML(e.eventUri)}" target="_blank" rel="noopener">${t('view_event_link')}</a>` : '';
        const removedAt = e.removed_at || e.removedAt;
        const removedBadge = removedAt
            ? `<span class="act-badge act-badge-removed" title="${escapeHTML(removedAt.substring(0,10))}">${t('removed_prefix')} ${escapeHTML(removedAt.substring(0,10))}</span>`
            : '';
        return `<div class="act-card${removedAt ? ' act-card-removed' : ''}">
            <div class="act-card-head">${removedBadge}<span class="act-date">${escapeHTML(dateRange)}</span></div>
            <div class="act-title">${escapeHTML(e.title || '')}</div>
            <div class="act-desc">${escapeHTML(e.description || '').substring(0, 280)}${(e.description || '').length > 280 ? '\u2026' : ''}</div>
            ${link ? `<div class="act-link">${link}</div>` : ''}
        </div>`;
    }).join('');
}

async function openDetail(id) {
    const m = await fetchJSON('/api/mvps/' + id);
    const name = `${m.first_name || ''} ${m.last_name || ''}`.trim();
    const profileUrl = m.user_profile_identifier
        ? `https://mvp.microsoft.com/en-US/MVP/profile/${encodeURIComponent(m.user_profile_identifier)}`
        : null;
    const profileLink = profileUrl
        ? `<a class="btn-profile" href="${escapeHTML(profileUrl)}" target="_blank" rel="noopener">${t('view_profile')}</a>`
        : '';
    const socialItems = (m.social_networks || []).map(s =>
        `<li><a href="${escapeHTML(s.url || '#')}" target="_blank" rel="noopener">${escapeHTML(s.network_name)}: ${escapeHTML(s.handle)}</a></li>`
    ).join('');
    const social = socialItems ? `<ul class="social-list">${socialItems}</ul>` : '<em>none</em>';

    const schools = (m.schools || []).map(s =>
        `<div>${escapeHTML(s.school_name || '')} – ${escapeHTML(s.program_name || '')} (${escapeHTML(s.country || '')})</div>`
    ).join('') || '<em>none</em>';

    const history = (m.history || []).map(h => {
        if (h.change_type === 'created') return `<div>${h.changed_at} \u2022 <strong>${t('modal_created')}</strong></div>`;
        if (h.change_type === 'left')    return `<div>${h.changed_at} \u2022 <strong>${t('modal_left_program')}</strong></div>`;
        if (h.change_type === 'returned')return `<div>${h.changed_at} \u2022 <strong>${t('modal_returned')}</strong></div>`;
        return `<div>${h.changed_at} \u2022 ${escapeHTML(h.field_name)}: <s>${escapeHTML(h.old_value)}</s> \u2192 ${escapeHTML(h.new_value)}</div>`;
    }).join('') || `<em>${t('modal_no_changes')}</em>`;

    const yComputed = m.years_in_program_computed != null ? Math.round(m.years_in_program_computed) : null;
    const yApi      = m.years_in_program_api != null ? Math.round(m.years_in_program_api) : null;
    const yLabel    = yComputed != null
        ? `${yComputed} ${yComputed === 1 ? t('modal_years_singular') : t('modal_years_plural')} ${t('modal_in_program')}`
        : null;
    const ySiteDiff = yApi != null && yComputed != null && yApi !== yComputed
        ? ` &mdash; ${t('modal_site_says')} ${yApi}`
        : '';
    const programEntryDetails = [
        yLabel ? `(${yLabel}${ySiteDiff})` : (yApi != null ? `(${t('modal_site_says')} ${yApi})` : ''),
        m.first_awarded_date ? `&mdash; ${t('modal_first_awarded')} ${m.first_awarded_date}` : '',
    ].filter(Boolean).join(' ');

    const profileTabHtml = `
        <div class="row">
            <img src="${escapeHTML(m.picture_url || '')}" onerror="this.style.visibility='hidden'">
            <div>
                <h3>${escapeHTML(name)}</h3>
                <div class="meta">${[
                    m.country ? escapeHTML(m.country) : null,
                    m.level_name ? escapeHTML(m.level_name) : null,
                    m.years_in_program ? `${Math.round(m.years_in_program)} ${Math.round(m.years_in_program) === 1 ? t('modal_years_singular') : t('modal_years_plural')} ${t('modal_in_program')}` : null,
                    m.is_active ? null : `<strong style="color:#b91c1c">${t('modal_left_badge')}</strong>`,
                ].filter(Boolean).join(' \u2022 ')}</div>
                <div>${escapeHTML(m.headline || '')}</div>
            </div>
        </div>
        <section><h4>${t('modal_biography')}</h4><div>${escapeHTML(m.biography || '')}</div></section>
        <section><h4>${t('modal_languages')}</h4><div>${(m.languages || []).map(prettyLang).join(', ')}</div></section>
        <section><h4>${t('modal_social')}</h4>${social}</section>
        <section><h4>${t('modal_education')}</h4>${schools}</section>
        <section>
            <h4>${t('modal_program_dates')}</h4>
            ${m.user_profile_identifier ? `<div>${t('modal_profile_id')} <code>${escapeHTML(m.user_profile_identifier)}</code> <button class="btn-copy" data-copy="${escapeHTML(m.user_profile_identifier)}" title="Copy ID">⧉</button></div>` : ''}
            <div>${t('modal_program_entry')} <strong>${escapeHTML(m.program_entry_date || '–')}</strong> ${programEntryDetails}</div>
            <div>${t('modal_first_seen')} ${escapeHTML(m.first_seen_at || '–')}</div>
            <div>${t('modal_last_seen')} ${escapeHTML(m.last_seen_at || '–')}</div>
            ${m.left_at ? `<div>${t('modal_left_at')} <strong>${escapeHTML(m.left_at)}</strong></div>` : ''}
        </section>
        <section><h4>${t('modal_history')}</h4><div class="history">${history}</div></section>
        ${profileLink}
    `;

    $('#mvp-modal-body').innerHTML = `
        <nav class="modal-tabs" role="tablist">
            <button class="modal-tab active" data-tab="profile" role="tab">${t('modal_profile')}</button>
            <button class="modal-tab" data-tab="activities" role="tab">${t('modal_activities')}</button>
            <button class="modal-tab" data-tab="events" role="tab">${t('modal_events')}</button>
        </nav>
        <div id="modal-tab-profile" class="modal-tab-panel">${profileTabHtml}</div>
        <div id="modal-tab-activities" class="modal-tab-panel" hidden><div class="act-loading">${t('loading')}</div></div>
        <div id="modal-tab-events" class="modal-tab-panel" hidden><div class="act-loading">${t('loading')}</div></div>
    `;

    // Tab switching
    let activitiesLoaded = false;
    const modalBody = $('#mvp-modal-body');
    modalBody.addEventListener('click', e => {
        const btn = e.target.closest('.modal-tab');
        if (!btn) return;
        const tab = btn.dataset.tab;
        modalBody.querySelectorAll('.modal-tab').forEach(b => b.classList.toggle('active', b === btn));
        modalBody.querySelectorAll('.modal-tab-panel').forEach(p => p.hidden = (p.id !== 'modal-tab-' + tab));
        if (!activitiesLoaded && (tab === 'activities' || tab === 'events') && m.user_profile_identifier) {
            activitiesLoaded = true;
            fetchJSON('/api/activities?identifier=' + encodeURIComponent(m.user_profile_identifier)).then(data => {
                $('#modal-tab-activities').innerHTML = renderContributions(data.contributions);
                $('#modal-tab-events').innerHTML = renderEvents(data.events);
            }).catch(() => {
                $('#modal-tab-activities').innerHTML = '<em>' + escapeHTML(t('load_failed_activities')) + '</em>';
                $('#modal-tab-events').innerHTML = '<em>' + escapeHTML(t('load_failed_events')) + '</em>';
            });
        }
    });

    $('#mvp-modal-title').textContent = t('modal_profile_heading') + name;
    $('#mvp-modal').showModal();
}

// ---------- Wire up ----------

function buildAggParams() {
    const p = new URLSearchParams();
    p.set('q', $('#f-q').value);
    (msCountry?.getValues() ?? []).forEach(v => p.append('country[]', v));
    p.set('level', $('#f-level').value);
    p.set('gender', $('#f-gender').value);
    (msLanguage?.getValues() ?? []).forEach(v => p.append('language[]', v));
    p.set('award_category', $('#f-award-category').value);
    p.set('status', $('#f-status').value);
    return p.toString();
}

function reloadTable() { state.page = 1; loadTable(); newMvpsState.page = 1; loadNewMvpsTable(); leavingMvpsState.page = 1; loadLeavingMvpsTable(); }
function reloadAll() { state.page = 1; loadTable(); newMvpsState.page = 1; loadNewMvpsTable(); leavingMvpsState.page = 1; loadLeavingMvpsTable(); loadAggregations(buildAggParams()); }

document.addEventListener('click', e => {
    const btn = e.target.closest('.btn-copy');
    if (!btn) return;
    const val = btn.dataset.copy;
    if (!val) return;
    navigator.clipboard.writeText(val).then(() => {
        const prev = btn.title;
        btn.title = '✓ Copied!';
        btn.style.color = '#16a34a';
        setTimeout(() => { btn.title = prev; btn.style.color = ''; }, 1500);
    });
});

document.addEventListener('DOMContentLoaded', async () => {
    // Mobile filter toggle
    const filterToggle = document.getElementById('filter-toggle');
    const filtersBody  = document.getElementById('filters-body');
    if (filterToggle && filtersBody) {
        filterToggle.addEventListener('click', () => {
            const open = filtersBody.classList.toggle('open');
            filterToggle.setAttribute('aria-expanded', open ? 'true' : 'false');
        });
    }

    await loadStats();
    await loadFilters();
    restoreFilters();
    updateActiveFiltersBar();
    await loadAggregations(buildAggParams());
    await loadTable();
    updateSortHeaders();

    // Column header sort — click to sort, Shift+click for multi-column
    document.querySelector('#mvp-table thead').addEventListener('click', e => {
        const th = e.target.closest('th[data-col]');
        if (!th) return;
        const col = th.dataset.col;
        const idx = sortState.findIndex(s => s.col === col);
        if (e.shiftKey) {
            if (idx >= 0) {
                // cycle: asc → desc → remove
                if (sortState[idx].dir === 'asc') sortState[idx].dir = 'desc';
                else sortState.splice(idx, 1);
            } else {
                sortState.push({ col, dir: 'asc' });
            }
        } else {
            const currentDir = idx >= 0 ? sortState[idx].dir : null;
            sortState.length = 0;
            sortState.push({ col, dir: currentDir === 'asc' ? 'desc' : 'asc' });
        }
        // Clear dropdown selection when column sort is active
        if (sortState.length) $('#f-sort').value = '';
        updateSortHeaders();
        saveFilters();
        reloadTable();
    });

    // New MVPs table column sort
    document.querySelector('#new-mvps-table thead').addEventListener('click', e => {
        const th = e.target.closest('th[data-col]');
        if (!th) return;
        const col = th.dataset.col;
        const idx = newMvpsSortState.findIndex(s => s.col === col);
        if (e.shiftKey) {
            if (idx >= 0) {
                if (newMvpsSortState[idx].dir === 'asc') newMvpsSortState[idx].dir = 'desc';
                else newMvpsSortState.splice(idx, 1);
            } else {
                newMvpsSortState.push({ col, dir: 'asc' });
            }
        } else {
            const currentDir = idx >= 0 ? newMvpsSortState[idx].dir : null;
            newMvpsSortState.length = 0;
            newMvpsSortState.push({ col, dir: currentDir === 'asc' ? 'desc' : 'asc' });
        }
        if (!newMvpsSortState.length) newMvpsSortState.push({ col: 'entry', dir: 'desc' });
        updateSortHeaders();
        newMvpsState.page = 1;
        loadNewMvpsTable();
    });

    // Leaving MVPs table column sort
    document.querySelector('#leaving-mvps-table thead').addEventListener('click', e => {
        const th = e.target.closest('th[data-col]');
        if (!th) return;
        const col = th.dataset.col;
        const idx = leavingMvpsSortState.findIndex(s => s.col === col);
        if (e.shiftKey) {
            if (idx >= 0) {
                if (leavingMvpsSortState[idx].dir === 'asc') leavingMvpsSortState[idx].dir = 'desc';
                else leavingMvpsSortState.splice(idx, 1);
            } else {
                leavingMvpsSortState.push({ col, dir: 'asc' });
            }
        } else {
            const currentDir = idx >= 0 ? leavingMvpsSortState[idx].dir : null;
            leavingMvpsSortState.length = 0;
            leavingMvpsSortState.push({ col, dir: currentDir === 'asc' ? 'desc' : 'asc' });
        }
        if (!leavingMvpsSortState.length) leavingMvpsSortState.push({ col: 'left', dir: 'desc' });
        updateSortHeaders();
        leavingMvpsState.page = 1;
        loadLeavingMvpsTable();
    });

    let typing;
    $('#f-q').addEventListener('input', () => {
        clearTimeout(typing);
        typing = setTimeout(() => { saveFilters(); reloadAll(); }, 350);
    });
    ['#f-level', '#f-gender', '#f-award-category', '#f-status']
        .forEach(s => $(s).addEventListener('change', () => { saveFilters(); reloadAll(); }));
    $('#f-sort').addEventListener('change', () => {
        // Dropdown change clears column sort
        sortState.length = 0;
        updateSortHeaders();
        saveFilters();
        reloadTable();
    });
    $('#f-reset').addEventListener('click', () => {
        $('#f-q').value = '';
        msCountry?.reset();
        msLanguage?.reset();
        ['#f-level', '#f-gender', '#f-award-category'].forEach(s => $(s).value = '');
        $('#f-status').value = 'active';
        $('#f-sort').value = 'name';
        sortState.length = 0;
        updateSortHeaders();
        saveFilters();
        reloadAll();
    });
    $('#prev').addEventListener('click', () => { if (state.page > 1) { state.page--; loadTable(); } });
    $('#next').addEventListener('click', () => { if (state.page < state.totalPages) { state.page++; loadTable(); } });

    $('#new-mvps-prev').addEventListener('click', () => { if (newMvpsState.page > 1) { newMvpsState.page--; loadNewMvpsTable(); } });
    $('#new-mvps-next').addEventListener('click', () => { if (newMvpsState.page < newMvpsState.totalPages) { newMvpsState.page++; loadNewMvpsTable(); } });

    $('#leaving-mvps-prev').addEventListener('click', () => { if (leavingMvpsState.page > 1) { leavingMvpsState.page--; loadLeavingMvpsTable(); } });
    $('#leaving-mvps-next').addEventListener('click', () => { if (leavingMvpsState.page < leavingMvpsState.totalPages) { leavingMvpsState.page++; loadLeavingMvpsTable(); } });

    $('#drill-prev').addEventListener('click', () => {
        if (drillState.page > 1) { drillState.page--; loadDrillTable(); }
    });
    $('#drill-next').addEventListener('click', () => {
        if (drillState.page < drillState.totalPages) { drillState.page++; loadDrillTable(); }
    });

    // ---------- Tab switching ----------
    document.querySelectorAll('.tab-btn').forEach(btn => {
        btn.addEventListener('click', () => {
            document.querySelectorAll('.tab-btn').forEach(b => b.classList.remove('active'));
            btn.classList.add('active');
            const target = btn.dataset.tab;
            document.querySelectorAll('.tab-pane').forEach(p => {
                p.hidden = (p.id !== 'tab-' + target);
            });
            // Refresh table when switching to details/new-mvps tab (ensures correct render)
            if (target === 'details') loadTable();
            if (target === 'new-mvps') loadNewMvpsTable();
            if (target === 'leaving-mvps') loadLeavingMvpsTable();
        });
    });

    // ---------- Language selector ----------
    document.querySelectorAll('.lang-btn').forEach(btn => {
        btn.addEventListener('click', () => setLang(btn.dataset.lang));
    });

    // React to language changes — refresh all dynamic content
    document.addEventListener('langchange', () => {
        applyStaticI18n();
        updateSortHeaders();
        if (msCountry) msCountry.updateI18n(t('all_countries'));
        if (msLanguage) msLanguage.updateI18n(t('all_languages'));
        updateActiveFiltersBar();
        loadStats();
        reloadAll();
    });
});
