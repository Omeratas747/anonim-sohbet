const $ = (s) => document.querySelector(s);
const esc = (s) => String(s).replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
const fmt = (t) => new Date(t).toLocaleString('tr', { day: '2-digit', month: '2-digit', hour: '2-digit', minute: '2-digit' });

const left = (t) => (t > 9e15 ? ' (süresiz)' : ' (' + Math.max(1, Math.ceil((t - Date.now()) / 60000)) + ' dk)');
let maintTimer = null;
let token = localStorage.getItem('token');
let me = null;
let lastM = 0;
let lastE = 0;
let timer = null;
let busy = false;
let mode = 'login';
let tab = 'stats';
let toastTimer;

function toast(text) {
  const el = $('#toast');
  el.textContent = text;
  el.classList.add('show');
  clearTimeout(toastTimer);
  toastTimer = setTimeout(() => el.classList.remove('show'), 2600);
}

async function api(url, method = 'GET', body) {
  const res = await fetch('api.php?r=' + url, {
    method,
    headers: { 'Content-Type': 'application/json', ...(token ? { 'X-Token': token } : {}) },
    body: body ? JSON.stringify(body) : undefined,
  });
  const data = await res.json().catch(() => ({}));
  if (!res.ok) throw Object.assign(new Error(data.error || 'Bir hata oluştu'), { status: res.status, maintenance: !!data.maintenance });
  return data;
}

function color(alias) {
  let h = 0;
  for (const c of alias) h = (h * 31 + c.charCodeAt(0)) % 360;
  return `hsl(${h} 60% 45%)`;
}

function addMessage(m) {
  if (m.id <= lastM) return;
  lastM = m.id;
  const list = $('#list');
  const near = list.scrollHeight - list.scrollTop - list.clientHeight < 120;
  const box = document.createElement('div');
  box.className = 'msg' + (m.alias === me.alias ? ' mine' : '');
  box.dataset.id = m.id;
  box.style.setProperty('--c', color(m.alias));
  const name = document.createElement('b');
  name.textContent = m.alias;
  const body = document.createElement('p');
  body.textContent = m.text;
  const time = document.createElement('time');
  time.textContent = new Date(m.t).toLocaleTimeString('tr', { hour: '2-digit', minute: '2-digit' });
  box.append(name, body, time);
  list.append(box);
  if (near || m.alias === me.alias) list.scrollTop = list.scrollHeight;
}

function addNote(text) {
  const n = document.createElement('div');
  n.className = 'note';
  n.textContent = text;
  $('#list').append(n);
  $('#list').scrollTop = $('#list').scrollHeight;
}

function logout(msg) {
  localStorage.removeItem('token');
  token = null;
  me = null;
  clearTimeout(timer);
  busy = false;
  lastM = 0;
  lastE = 0;
  $('#admin').close();
  $('#chat').hidden = true;
  $('#auth').hidden = false;
  $('#authErr').textContent = msg || '';
}

function applyEvent(ev) {
  lastE = ev.id;
  const data = JSON.parse(ev.data);
  if (ev.type === 'removed') data.forEach((id) => document.querySelector(`.msg[data-id="${id}"]`)?.remove());
  else if (ev.type === 'cleared') $('#list').innerHTML = '';
  else if (ev.type === 'announce') addNote('Duyuru: ' + data);
}

async function poll() {
  if (busy || !token) return;
  busy = true;
  try {
    const d = await api(`poll&m=${lastM}&e=${lastE}`);
    d.messages.forEach(addMessage);
    d.events.forEach(applyEvent);
    $('#online').textContent = d.online + ' çevrimiçi';
  } catch (e) {
    if (e.maintenance) return showMaint(e.message);
    if (e.status === 401 || e.status === 403) return logout(e.message);
  }
  busy = false;
  clearTimeout(timer);
  timer = setTimeout(poll, document.hidden ? 5000 : 1500);
}

function showMaint(text) {
  clearTimeout(timer);
  busy = false;
  $('#chat').hidden = true;
  $('#auth').hidden = true;
  $('#maint').hidden = false;
  $('#maintText').textContent = text || 'Sitemiz kısa süreli bakımda, lütfen daha sonra tekrar deneyin.';
  clearInterval(maintTimer);
  maintTimer = setInterval(async () => {
    try {
      const d = await api('status');
      if (!d.maintenance) location.reload();
    } catch {}
  }, 5000);
}

$('#maintAdmin').addEventListener('click', () => {
  clearInterval(maintTimer);
  $('#maint').hidden = true;
  $('#auth').hidden = false;
});

async function start() {
  try {
    me = await api('me');
  } catch (e) {
    if (e.maintenance) return showMaint(e.message);
    return logout(e.status === 403 ? e.message : '');
  }
  $('#auth').hidden = true;
  $('#chat').hidden = false;
  $('#who').textContent = 'Sen: ' + me.alias;
  $('#adminBtn').hidden = !['admin', 'mod'].includes(me.role);
  $('#adminBtn').textContent = me.role === 'admin' ? 'Yönetim' : 'Moderasyon';
  $('#list').innerHTML = '';
  const h = await api('history');
  lastE = h.lastEvent;
  h.messages.forEach(addMessage);
  $('#list').scrollTop = $('#list').scrollHeight;
  poll();
}

$('#authTabs').addEventListener('click', (e) => {
  const b = e.target.closest('button');
  if (!b) return;
  mode = b.dataset.mode;
  document.querySelectorAll('#authTabs button').forEach((x) => x.classList.toggle('on', x === b));
  $('#authBtn').textContent = mode === 'login' ? 'Giriş yap' : 'Hesap oluştur';
  $('#p').autocomplete = mode === 'login' ? 'current-password' : 'new-password';
  $('#authErr').textContent = '';
});

$('#authForm').addEventListener('submit', async (e) => {
  e.preventDefault();
  try {
    const d = await api(mode, 'POST', { username: $('#u').value.trim(), password: $('#p').value });
    token = d.token;
    localStorage.setItem('token', token);
    $('#p').value = '';
    start();
  } catch (err) {
    $('#authErr').textContent = err.message;
  }
});

$('#send').addEventListener('submit', (e) => {
  e.preventDefault();
  const text = $('#text').value.trim();
  if (!text) return;
  $('#text').value = '';
  api('send', 'POST', { text }).then(
    () => {
      clearTimeout(timer);
      poll();
    },
    (err) => (err.status === 401 ? logout(err.message) : toast(err.message))
  );
});

$('#logout').addEventListener('click', async () => {
  await api('logout', 'POST').catch(() => {});
  logout();
});
$('#adminBtn').addEventListener('click', () => {
  const isAdmin = me.role === 'admin';
  if (!isAdmin) tab = 'users';
  document.querySelectorAll('#adminTabs button').forEach((b) => {
    b.hidden = !isAdmin && b.dataset.tab !== 'users';
    b.classList.toggle('on', b.dataset.tab === tab);
  });
  $('#admin').showModal();
  renderAdmin();
});
$('#closeAdmin').addEventListener('click', () => $('#admin').close());
$('#adminTabs').addEventListener('click', (e) => {
  const b = e.target.closest('button');
  if (!b) return;
  tab = b.dataset.tab;
  document.querySelectorAll('#adminTabs button').forEach((x) => x.classList.toggle('on', x === b));
  renderAdmin();
});

async function renderAdmin() {
  const body = $('#adminBody');
  try {
    if (tab === 'stats') {
      const s = await api('admin/stats');
      const items = [
        ['Çevrimiçi', s.online],
        ['Toplam kullanıcı', s.users],
        ['Son 24 saatte yeni', s.newUsers],
        ['Yasaklı', s.banned],
        ['Susturulmuş', s.muted],
        ['Toplam mesaj', s.messages],
        ['Son 24 saatte mesaj', s.today],
      ];
      body.innerHTML =
        `<div class="cards">${items.map(([k, v]) => `<div class="card"><span>${k}</span><strong>${v}</strong></div>`).join('')}</div>` +
        `<p class="lead">Veritabanı: ${esc(s.dbPath)}</p>` +
        `<div class="row"><input id="ann" placeholder="Tüm sohbete duyuru gönder" maxlength="300"><button data-act="announce">Duyur</button></div>` +
        `<div class="row"><input id="mt" placeholder="Bakım mesajı (isteğe bağlı)" value="${esc(s.maintText)}" maxlength="200"><button data-act="maint" data-on="${s.maintenance ? 0 : 1}">${s.maintenance ? 'Bakımı kapat' : 'Bakımı aç'}</button></div>` +
        `<div class="row"><button class="danger" data-act="clear">Tüm sohbeti sil</button></div>`;
    } else if (tab === 'users') {
      const users = await api('admin/users');
      const now = Date.now();
      const isAdmin = me.role === 'admin';
      body.innerHTML =
        (isAdmin ? `<div class="row"><input id="nu" placeholder="Kullanıcı adı"><input id="np" placeholder="Şifre"><select id="nr"><option value="user">Kullanıcı</option><option value="mod">Moderatör</option><option value="admin">Yönetici</option></select><button data-act="create">Hesap oluştur</button></div>` : '') +
        `<div class="scroll"><table><thead><tr><th>Kullanıcı</th><th>Takma ad</th><th>Durum</th><th>Kayıt</th><th></th></tr></thead><tbody>${users
          .map((u) => {
            const tags = [
              u.online ? '<span class="tag ok">çevrimiçi</span>' : '',
              u.role === 'admin' ? '<span class="tag">yönetici</span>' : '',
              u.role === 'mod' ? '<span class="tag">moderatör</span>' : '',
              u.banned > now ? '<span class="tag bad">yasaklı' + left(u.banned) + '</span>' : '',
              u.muted > now ? '<span class="tag bad">susturulmuş' + left(u.muted) + '</span>' : '',
            ].join(' ');
            const banned = u.banned > now;
            const muted = u.muted > now;
            const locked = u.role === 'admin' || u.id === me.id || (!isAdmin && u.role !== 'user');
            const acts = locked
              ? ''
              : `<div class="acts" data-id="${u.id}">
                    <button data-act="${banned ? 'unban' : 'ban'}">${banned ? 'Yasağı kaldır' : 'Yasakla'}</button>
                    <button data-act="${muted ? 'unmute' : 'mute'}">${muted ? 'Susturmayı kaldır' : 'Sustur'}</button>
                    ${
                      isAdmin
                        ? `<button data-act="kick">At</button>
                    <button data-act="${u.role === 'mod' ? 'unmod' : 'mod'}">${u.role === 'mod' ? 'Moderatörlüğü al' : 'Moderatör yap'}</button>
                    <button data-act="password">Şifre</button>
                    <button class="danger" data-act="remove">Sil</button>`
                        : ''
                    }
                  </div>`;
            return `<tr><td>${esc(u.username)}</td><td>${esc(u.alias)}</td><td>${tags}</td><td>${fmt(u.created)}</td><td>${acts}</td></tr>`;
          })
          .join('')}</tbody></table></div>`;
    } else {
      const msgs = await api('admin/messages');
      body.innerHTML = msgs.length
        ? `<div class="scroll"><table><thead><tr><th>Zaman</th><th>Gerçek kullanıcı</th><th>Takma ad</th><th>Mesaj</th><th></th></tr></thead><tbody>${msgs
            .map(
              (m) =>
                `<tr><td>${fmt(m.t)}</td><td>${esc(m.username || '-')}</td><td>${esc(m.alias)}</td><td class="mtext">${esc(m.text)}</td><td><button class="danger" data-act="delmsg" data-mid="${m.id}">Sil</button></td></tr>`
            )
            .join('')}</tbody></table></div>`
        : '<p>Henüz mesaj yok.</p>';
    }
  } catch (e) {
    body.textContent = e.message;
  }
}

$('#adminBody').addEventListener('click', async (e) => {
  const b = e.target.closest('button[data-act]');
  if (!b) return;
  const act = b.dataset.act;
  const id = b.closest('[data-id]')?.dataset.id;
  try {
    if (act === 'announce') {
      await api('admin/announce', 'POST', { text: $('#ann').value });
      toast('Duyuru gönderildi');
    } else if (act === 'clear') {
      if (!confirm('Tüm mesajlar silinecek. Emin misiniz?')) return;
      await api('admin/clear', 'POST');
      toast('Sohbet temizlendi');
    } else if (act === 'create') {
      await api('admin/create', 'POST', { username: $('#nu').value.trim(), password: $('#np').value, role: $('#nr').value });
      toast('Hesap oluşturuldu');
    } else if (act === 'delmsg') {
      await api('admin/delmsg&id=' + b.dataset.mid, 'POST');
    } else if (act === 'maint') {
      await api('admin/maint', 'POST', { on: b.dataset.on === '1', text: $('#mt').value });
      toast(b.dataset.on === '1' ? 'Bakım modu açıldı' : 'Bakım modu kapandı');
    } else if (act === 'ban') {
      const m = prompt('Kaç dakika yasaklansın? (0 = süresiz)', '0');
      if (m === null) return;
      await api(`admin/ban&id=${id}`, 'POST', { minutes: Number(m) });
    } else if (act === 'mute') {
      const m = prompt('Kaç dakika susturulsun? (0 = süresiz)', '10');
      if (m === null) return;
      await api(`admin/mute&id=${id}`, 'POST', { minutes: Number(m) });
    } else if (act === 'password') {
      const p = prompt('Yeni şifre (en az 6 karakter)');
      if (!p) return;
      await api(`admin/password&id=${id}`, 'POST', { password: p });
    } else if (act === 'remove') {
      if (!confirm('Hesap ve tüm mesajları silinecek. Emin misiniz?')) return;
      await api('admin/remove&id=' + id, 'POST');
    } else {
      await api(`admin/${act}&id=${id}`, 'POST');
    }
    renderAdmin();
  } catch (err) {
    toast(err.message);
  }
});

(async () => {
  try {
    const d = await api('status');
    if (d.maintenance && !token) return showMaint(d.text);
  } catch {}
  if (token) start();
  else $('#auth').hidden = false;
})();

document.addEventListener('visibilitychange', () => {
  if (!document.hidden && token) {
    clearTimeout(timer);
    poll();
  }
});
