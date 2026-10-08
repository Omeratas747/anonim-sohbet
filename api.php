<?php
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

function ms() { return (int) (microtime(true) * 1000); }
function out($d, $c = 200) { http_response_code($c); echo json_encode($d, JSON_UNESCAPED_UNICODE); exit; }
function fail($m, $c = 400) { out(['error' => $m], $c); }
function body() {
    static $b = null;
    if ($b === null) {
        $b = json_decode(file_get_contents('php://input'), true);
        if (!is_array($b)) $b = [];
    }
    return $b;
}
function q($sql, $a = []) { global $db; $s = $db->prepare($sql); $s->execute($a); return $s; }
function one($sql, $a = []) { return q($sql, $a)->fetch(); }
function all($sql, $a = []) { return q($sql, $a)->fetchAll(); }
function val($sql, $a = []) { return q($sql, $a)->fetchColumn(); }
function ints($rows, $keys = ['id', 't']) {
    foreach ($rows as &$row) foreach ($keys as $k) if (isset($row[$k])) $row[$k] = (int) $row[$k];
    return $rows;
}
function event($type, $data) {
    q('delete from events where t<?', [ms() - 3600000]);
    q('insert into events(type,data,t) values(?,?,?)', [$type, json_encode($data, JSON_UNESCAPED_UNICODE), ms()]);
}
function newAlias() {
    do { $a = 'Anonim-' . random_int(1000, 99999); } while (val('select 1 from users where alias=?', [$a]));
    return $a;
}
function createUser($u, $p, $role = 'user') {
    global $db;
    q('insert into users(username,pass,alias,role,created) values(?,?,?,?,?)', [$u, password_hash($p, PASSWORD_DEFAULT), newAlias(), $role, ms()]);
    return (int) $db->lastInsertId();
}
function getUser($id) { return one('select * from users where id=?', [$id]); }
function meUser($u) { return ['id' => (int) $u['id'], 'alias' => $u['alias'], 'role' => $u['role']]; }
function newSession($id) {
    $t = bin2hex(random_bytes(32));
    q('delete from sessions where t<?', [ms() - 30 * 86400000]);
    q('insert into sessions(token,user_id,t) values(?,?,?)', [hash('sha256', $t), $id, ms()]);
    return $t;
}
function auth() {
    $t = $_SERVER['HTTP_X_TOKEN'] ?? '';
    if ($t === '') fail('Oturum geçersiz', 401);
    $u = one('select u.* from sessions s join users u on u.id=s.user_id where s.token=?', [hash('sha256', $t)]);
    if (!$u) fail('Oturum geçersiz', 401);
    if ((int) $u['ban_until'] > ms()) fail(banMsg($u), 403);
    if ($u['role'] !== 'admin' && ($m = maint())) out(['error' => $m['text'], 'maintenance' => true], 503);
    return $u;
}
function throttle() {
    $ip = $_SERVER['REMOTE_ADDR'] ?? '';
    q('delete from attempts where t<?', [ms() - 60000]);
    if ((int) val('select count(*) from attempts where ip=?', [$ip]) >= 10) fail('Çok fazla deneme, biraz bekleyin', 429);
    q('insert into attempts(ip,t) values(?,?)', [$ip, ms()]);
}
function validName($s) { return is_string($s) && preg_match('/^[a-zA-Z0-9_]{3,20}$/', $s); }
function validPass($s) { return is_string($s) && strlen($s) >= 6 && strlen($s) <= 100; }
function banMsg($u) {
    $left = (int) $u['ban_until'] - ms();
    if ($left > 86400000 * 3650) return 'Hesabınız süresiz olarak yasaklandı';
    return 'Hesabınız yasaklandı, kalan süre: ' . max(1, (int) ceil($left / 60000)) . ' dakika';
}
function maint() {
    $v = val("select v from meta where k='maint'");
    return $v === false ? null : json_decode($v, true);
}
function onlineCount() { return (int) val('select count(*) from users where seen>?', [ms() - 10000]); }

$envFile = __DIR__ . '/env.php';
if (!is_file($envFile)) fail('env.php dosyası bulunamadı', 500);
$cfg = require $envFile;

try {
    $file = basename($cfg['DB_FILE']);
    $cands = [];
    if (!empty($cfg['DB_DIR'])) $cands[] = rtrim($cfg['DB_DIR'], '/');
    $cands[] = dirname(__DIR__) . '/maske-data';
    $cands[] = __DIR__ . '/data';
    $cands[] = __DIR__;
    $dir = null;
    foreach ($cands as $c) {
        if (is_file($c . '/' . $file)) { $dir = $c; break; }
    }
    if ($dir === null) {
        foreach ($cands as $c) {
            if (!is_dir($c)) @mkdir($c, 0755, true);
            if (is_dir($c) && is_writable($c)) { $dir = $c; break; }
        }
    }
    if ($dir === null) fail('Veritabanı için yazılabilir klasör bulunamadı', 500);
    $dbPath = $dir . '/' . $file;
    $db = new PDO('sqlite:' . $dbPath, null, null, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
    $db->exec('pragma busy_timeout=5000');
    $db->exec('pragma journal_mode=delete');

    if ((int) val('pragma user_version') < 1) {
        $db->exec("
        create table if not exists users(id integer primary key autoincrement, username text not null unique collate nocase, pass text not null, alias text not null unique, role text not null default 'user', banned integer not null default 0, muted integer not null default 0, created integer not null, seen integer not null default 0);
        create table if not exists messages(id integer primary key autoincrement, user_id integer not null, alias text not null, text text not null, t integer not null);
        create table if not exists sessions(token text primary key, user_id integer not null, t integer not null);
        create table if not exists events(id integer primary key autoincrement, type text not null, data text not null, t integer not null);
        create table if not exists attempts(ip text not null, t integer not null);
        create table if not exists meta(k text primary key, v text not null);
        pragma user_version=1;");
    }

    if ((int) val('pragma user_version') < 2) {
        $cols = array_column(all('pragma table_info(users)'), 'name');
        if (!in_array('ban_until', $cols, true)) $db->exec('alter table users add column ban_until integer not null default 0');
        $db->exec('update users set ban_until=9007199254740991 where banned=1');
        $db->exec('pragma user_version=2');
    }

    $sig = hash('sha256', $cfg['ADMIN_USER'] . "\0" . $cfg['ADMIN_PASS']);
    if (val("select v from meta where k='admin'") !== $sig) {
        $row = one('select id from users where username=?', [$cfg['ADMIN_USER']]);
        if ($row) q("update users set pass=?, role='admin', ban_until=0, muted=0 where id=?", [password_hash($cfg['ADMIN_PASS'], PASSWORD_DEFAULT), $row['id']]);
        else createUser($cfg['ADMIN_USER'], $cfg['ADMIN_PASS'], 'admin');
        q("insert or replace into meta(k,v) values('admin',?)", [$sig]);
    }

    $r = $_GET['r'] ?? '';
    $post = $_SERVER['REQUEST_METHOD'] === 'POST';
    $b = body();

    if (strpos($r, 'admin/') === 0) {
        $me = auth();
        if (!in_array($me['role'], ['admin', 'mod'], true)) fail('Yetkiniz yok', 403);
        $a = substr($r, 6);
        $isAdmin = $me['role'] === 'admin';
        if (!$isAdmin && !in_array($a, ['users', 'ban', 'unban', 'mute', 'unmute'], true)) fail('Yetkiniz yok', 403);

        if ($a === 'stats') {
            $day = ms() - 86400000;
            out([
                'users' => (int) val('select count(*) from users'),
                'banned' => (int) val('select count(*) from users where ban_until>?', [ms()]),
                'maintenance' => (bool) maint(),
                'maintText' => maint()['text'] ?? '',
                'muted' => (int) val('select count(*) from users where muted>?', [ms()]),
                'messages' => (int) val('select count(*) from messages'),
                'today' => (int) val('select count(*) from messages where t>?', [$day]),
                'online' => onlineCount(),
                'dbPath' => $dbPath,
                'newUsers' => (int) val('select count(*) from users where created>?', [$day]),
            ]);
        }
        if ($a === 'users') {
            $rows = ints(all('select id,username,alias,role,ban_until as banned,muted,created,seen from users order by id desc'), ['id', 'banned', 'muted', 'created', 'seen']);
            foreach ($rows as &$u) { $u['online'] = $u['seen'] > ms() - 10000; unset($u['seen']); }
            out($rows);
        }
        if ($a === 'messages') {
            out(ints(all('select m.id,m.alias,m.text,m.t,u.username from messages m left join users u on u.id=m.user_id order by m.id desc limit 200')));
        }
        if (!$post) fail('Geçersiz istek', 405);
        if ($a === 'create') {
            if (!validName($b['username'] ?? null)) fail('Kullanıcı adı geçersiz');
            if (!validPass($b['password'] ?? null)) fail('Şifre en az 6 karakter olmalı');
            if (val('select 1 from users where username=?', [$b['username']])) fail('Bu kullanıcı adı alınmış', 409);
            createUser($b['username'], $b['password'], in_array($b['role'] ?? '', ['admin', 'mod'], true) ? $b['role'] : 'user');
            out(['ok' => true]);
        }
        if ($a === 'delmsg') {
            $id = (int) ($_GET['id'] ?? 0);
            q('delete from messages where id=?', [$id]);
            event('removed', [$id]);
            out(['ok' => true]);
        }
        if ($a === 'clear') {
            q('delete from messages');
            event('cleared', null);
            out(['ok' => true]);
        }
        if ($a === 'announce') {
            $text = mb_substr(trim((string) ($b['text'] ?? '')), 0, 300);
            if ($text === '') fail('Duyuru boş olamaz');
            event('announce', $text);
            out(['ok' => true]);
        }
        if ($a === 'maint') {
            if (!empty($b['on'])) {
                $text = mb_substr(trim((string) ($b['text'] ?? '')), 0, 200);
                $text = $text !== '' ? $text : 'Sitemiz kısa süreli bakımda, lütfen daha sonra tekrar deneyin.';
                q("insert or replace into meta(k,v) values('maint',?)", [json_encode(['text' => $text], JSON_UNESCAPED_UNICODE)]);
            } else {
                q("delete from meta where k='maint'");
            }
            out(['ok' => true]);
        }
        if (in_array($a, ['ban', 'unban', 'mute', 'unmute', 'kick', 'password', 'remove', 'mod', 'unmod'], true)) {
            $t = getUser((int) ($_GET['id'] ?? 0));
            if (!$t) fail('Kullanıcı bulunamadı', 404);
            if ((int) $t['id'] === (int) $me['id']) fail('Kendinize işlem yapamazsınız');
            if ($t['role'] === 'admin') fail('Yöneticilere işlem yapılamaz');
            if (!$isAdmin && $t['role'] !== 'user') fail('Bu kullanıcıya işlem yapamazsınız');
            $id = (int) $t['id'];
            if ($a === 'ban') {
                $min = max(0, (float) ($b['minutes'] ?? 0));
                q('update users set ban_until=? where id=?', [$min ? ms() + (int) ($min * 60000) : 9007199254740991, $id]);
                q('delete from sessions where user_id=?', [$id]);
            }
            if ($a === 'unban') q('update users set ban_until=0 where id=?', [$id]);
            if ($a === 'mute') {
                $min = max(0, (float) ($b['minutes'] ?? 0));
                q('update users set muted=? where id=?', [$min ? ms() + (int) ($min * 60000) : 9007199254740991, $id]);
            }
            if ($a === 'unmute') q('update users set muted=0 where id=?', [$id]);
            if ($a === 'kick') q('delete from sessions where user_id=?', [$id]);
            if ($a === 'mod') q("update users set role='mod' where id=?", [$id]);
            if ($a === 'unmod') q("update users set role='user' where id=?", [$id]);
            if ($a === 'password') {
                if (!validPass($b['password'] ?? null)) fail('Şifre en az 6 karakter olmalı');
                q('update users set pass=? where id=?', [password_hash($b['password'], PASSWORD_DEFAULT), $id]);
                q('delete from sessions where user_id=?', [$id]);
            }
            if ($a === 'remove') {
                $ids = array_map('intval', q('select id from messages where user_id=?', [$id])->fetchAll(PDO::FETCH_COLUMN));
                q('delete from messages where user_id=?', [$id]);
                q('delete from sessions where user_id=?', [$id]);
                q('delete from users where id=?', [$id]);
                event('removed', $ids);
            }
            out(['ok' => true]);
        }
        fail('Bulunamadı', 404);
    }

    switch ($r) {
        case 'register':
            if (!$post) fail('Geçersiz istek', 405);
            if ($m = maint()) out(['error' => $m['text'], 'maintenance' => true], 503);
            throttle();
            if (!validName($b['username'] ?? null)) fail('Kullanıcı adı 3-20 karakter, harf/rakam/_ olmalı');
            if (!validPass($b['password'] ?? null)) fail('Şifre en az 6 karakter olmalı');
            if (val('select 1 from users where username=?', [$b['username']])) fail('Bu kullanıcı adı alınmış', 409);
            $id = createUser($b['username'], $b['password']);
            out(['token' => newSession($id), 'user' => meUser(getUser($id))]);
        case 'login':
            if (!$post) fail('Geçersiz istek', 405);
            throttle();
            $u = is_string($b['username'] ?? null) ? one('select * from users where username=?', [$b['username']]) : null;
            if (!$u || !is_string($b['password'] ?? null) || !password_verify($b['password'], $u['pass'])) fail('Kullanıcı adı veya şifre hatalı', 401);
            if ((int) $u['ban_until'] > ms()) fail(banMsg($u), 403);
            if ($u['role'] !== 'admin' && ($m = maint())) out(['error' => $m['text'], 'maintenance' => true], 503);
            out(['token' => newSession($u['id']), 'user' => meUser($u)]);
        case 'logout':
            auth();
            q('delete from sessions where token=?', [hash('sha256', $_SERVER['HTTP_X_TOKEN'])]);
            out(['ok' => true]);
        case 'status':
            $m = maint();
            out(['maintenance' => (bool) $m, 'text' => $m['text'] ?? '']);
        case 'me':
            out(meUser(auth()));
        case 'history':
            auth();
            out([
                'messages' => ints(array_reverse(all('select id,alias,text,t from messages order by id desc limit 100'))),
                'lastEvent' => (int) val('select coalesce(max(id),0) from events'),
            ]);
        case 'poll':
            $u = auth();
            q('update users set seen=? where id=?', [ms(), $u['id']]);
            out([
                'messages' => ints(all('select id,alias,text,t from messages where id>? order by id limit 200', [(int) ($_GET['m'] ?? 0)])),
                'events' => ints(all('select id,type,data from events where id>? order by id limit 200', [(int) ($_GET['e'] ?? 0)]), ['id']),
                'online' => onlineCount(),
            ]);
        case 'send':
            if (!$post) fail('Geçersiz istek', 405);
            $u = auth();
            if ((int) $u['muted'] > ms()) fail('Susturuldunuz, mesaj gönderemezsiniz', 403);
            $text = mb_substr(trim((string) ($b['text'] ?? '')), 0, 500);
            if ($text === '') out(['ok' => true]);
            $last = val('select max(t) from messages where user_id=?', [$u['id']]);
            if ($last && ms() - (int) $last < 700) fail('Çok hızlı yazıyorsunuz', 429);
            q('insert into messages(user_id,alias,text,t) values(?,?,?,?)', [$u['id'], $u['alias'], $text, ms()]);
            out(['ok' => true]);
        default:
            fail('Bulunamadı', 404);
    }
} catch (Throwable $e) {
    fail('Sunucu hatası', 500);
}
