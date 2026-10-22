<?php
set_time_limit(300);
ini_set('memory_limit', '512M');
 
$isWin = stripos(PHP_OS, 'WIN') === 0;
$error = '';
 
/* ---------- Dasturlarni topish ---------- */
function find_soffice($isWin) {
    if (!$isWin) return 'soffice';
    $c = [
        'C:\\Program Files\\LibreOffice\\program\\soffice.exe',
        'C:\\Program Files (x86)\\LibreOffice\\program\\soffice.exe',
    ];
    foreach ($c as $p) if (file_exists($p)) return $p;
    return 'soffice';
}
 
function find_python($isWin) {
    if (!$isWin) return 'python3';
    $found = [];
    $roots = array_filter([getenv('LOCALAPPDATA'), 'C:\\Users\\' . (getenv('USERNAME') ?: '') . '\\AppData\\Local']);
    foreach ($roots as $r) {
        foreach (glob($r . '\\Python\\*\\python.exe') ?: [] as $p) $found[] = $p;
        foreach (glob($r . '\\Programs\\Python\\*\\python.exe') ?: [] as $p) $found[] = $p;
    }
    foreach (glob('C:\\Users\\*\\AppData\\Local\\Python\\*\\python.exe') ?: [] as $p) $found[] = $p;
    foreach (glob('C:\\Users\\*\\AppData\\Local\\Programs\\Python\\*\\python.exe') ?: [] as $p) $found[] = $p;
    foreach ($found as $p) if (stripos($p, 'WindowsApps') === false) return $p;
    return 'python';
}
 
/* ---------- Buyruq ishga tushirish ---------- */
function run($args, $env = null) {
    $p = @proc_open($args, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, null, $env);
    if (!is_resource($p)) return 'Dastur ishga tushmadi: ' . $args[0];
    $out = stream_get_contents($pipes[1]) . stream_get_contents($pipes[2]);
    fclose($pipes[1]); fclose($pipes[2]);
    proc_close($p);
    if (stripos(PHP_OS, 'WIN') === 0) {
        $t = @mb_convert_encoding($out, 'UTF-8', 'CP866');
        if ($t) $out = $t;
    }
    return trim($out);
}
 
function rmrf($d) {
    foreach (glob($d . '/*') ?: [] as $f) is_dir($f) ? rmrf($f) : @unlink($f);
    @rmdir($d);
}
 
/* ---------- LibreOffice: printerni kutmasin ---------- */
function lo_profile_setup($dir) {
    $u = $dir . '/user';
    if (!is_dir($u)) @mkdir($u, 0777, true);
    $f = $u . '/registrymodifications.xcu';
    $item = '<item oor:path="/org.openoffice.Office.Common/Save/Document"><prop oor:name="LoadPrinter" oor:op="fuse"><value>false</value></prop></item>';
    $x = file_exists($f) ? file_get_contents($f) : '';
    if (strpos($x, 'LoadPrinter') !== false) return;
    if ($x === '') {
        $x = '<' . '?xml version="1.0" encoding="UTF-8"?' . '>' . "\n"
           . '<oor:items xmlns:oor="http://openoffice.org/2001/registry" xmlns:xs="http://www.w3.org/2001/XMLSchema" xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance">' . "\n"
           . $item . "\n</oor:items>\n";
    } else {
        $x = str_replace('</oor:items>', $item . "\n</oor:items>", $x);
    }
    @file_put_contents($f, $x);
}
 
/* ---------- Konvertatsiya ---------- */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $work = '';
    try {
        if (!isset($_FILES['f']) || $_FILES['f']['error'] !== UPLOAD_ERR_OK) {
            $code = $_FILES['f']['error'] ?? '?';
            throw new Exception(in_array($code, [1, 2])
                ? 'Fayl juda katta. php.ini da upload_max_filesize va post_max_size ni oshiring.'
                : "Fayl tanlanmadi yoki yuklashda xato (kod: $code).");
        }
        $mode = $_POST['mode'] ?? 'topdf';
        $orig = basename($_FILES['f']['name']);
        $ext  = strtolower(pathinfo($orig, PATHINFO_EXTENSION));
        $base = pathinfo($orig, PATHINFO_FILENAME);
        if ($ext === '' || preg_match('/[^a-z0-9]/', $ext)) throw new Exception("Fayl turi noma'lum.");
 
        $work = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'conv_' . bin2hex(random_bytes(6));
        mkdir($work, 0777, true);
        $in = $work . DIRECTORY_SEPARATOR . 'input.' . $ext;
        move_uploaded_file($_FILES['f']['tmp_name'], $in);
 
        if ($mode === 'topdf') {
            if ($ext === 'pdf') throw new Exception('Bu fayl allaqachon PDF. Boshqa fayl tanlang.');
            $tmp = str_replace('\\', '/', sys_get_temp_dir());
            $profile = 'file:///' . ltrim($tmp, '/') . '/lo_profile';
            lo_profile_setup($tmp . '/lo_profile');
            $env = $isWin ? null : array_merge(getenv() ?: [], ['HOME' => '/tmp']);
            $log = run([find_soffice($isWin), '-env:UserInstallation=' . $profile,
                        '--headless', '--convert-to', 'pdf', '--outdir', $work, $in], $env);
            $result = $work . DIRECTORY_SEPARATOR . 'input.pdf';
            $dlName = $base . '.pdf';
            $mime   = 'application/pdf';
        } else {
            if ($ext !== 'pdf') throw new Exception('Bu rejimda faqat PDF fayl yuklang.');
            $result = $work . DIRECTORY_SEPARATOR . 'output.docx';
            $code = 'import sys; from pdf2docx import Converter; c = Converter(sys.argv[1]); c.convert(sys.argv[2]); c.close()';
            $log = run([find_python($isWin), '-W', 'ignore', '-c', $code, $in, $result]);
            $dlName = $base . '.docx';
            $mime   = 'application/vnd.openxmlformats-officedocument.wordprocessingml.document';
        }
 
        if (!file_exists($result) || filesize($result) < 100) {
            throw new Exception("Konvertatsiya bo'lmadi. " . mb_substr($log ?? '', 0, 400));
        }
 
        $size = filesize($result);
        while (ob_get_level()) ob_end_clean();
        setcookie('dl', preg_replace('/\D/', '', $_POST['token'] ?? ''), 0, '/');
        header('Content-Type: ' . $mime);
        header('Content-Disposition: attachment; filename="' . str_replace(['"', "\r", "\n"], '', $dlName) . '"; filename*=UTF-8\'\'' . rawurlencode($dlName));
        header('Content-Length: ' . $size);
        readfile($result);
        rmrf($work);
        exit;
    } catch (Throwable $e) {
        if ($work) rmrf($work);
        $error = $e->getMessage();
    }
}
?>
<!DOCTYPE html>
<html lang="uz">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Fayl Konverteri</title>
<meta name="theme-color" content="#2e9bd6">
<link rel="icon" href="data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 64 64'%3E%3Cdefs%3E%3ClinearGradient id='g' x1='0' y1='0' x2='1' y2='1'%3E%3Cstop offset='0' stop-color='%235bbcf0'/%3E%3Cstop offset='1' stop-color='%231f86bf'/%3E%3C/linearGradient%3E%3C/defs%3E%3Crect width='64' height='64' rx='16' fill='url(%23g)'/%3E%3Cg fill='none' stroke='%23fff' stroke-width='5' stroke-linecap='round' stroke-linejoin='round'%3E%3Cpath d='M16 25h32M40 16l9 9-9 9'/%3E%3Cpath d='M48 42H16M24 33l-9 9 9 9'/%3E%3C/g%3E%3C/svg%3E">
<style>
 :root {
   --bg: #dff1fc;
   --card: #ffffff;
   --text: #1e293b;
   --muted: #5b7a93;
   --line: #c9e2f3;
   --accent: #2e9bd6;
   --accent-dark: #1f86bf;
   --accent-soft: #e8f5fd;
   --err-bg: #fdf2f2;
   --err-line: #f3c9c9;
   --err-text: #a33a3a;
 }
 * { box-sizing: border-box; margin: 0; padding: 0; font-family: 'Segoe UI', system-ui, Tahoma, sans-serif; }
 body { background: linear-gradient(160deg, #eaf7ff 0%, #cde8f9 100%) fixed; color: var(--text); min-height: 100vh; display: flex; justify-content: center; align-items: center; padding: 24px 16px 14vh; }
 body::before { content: ""; position: fixed; width: 560px; height: 560px; border-radius: 50%; top: -180px; right: -140px; background: radial-gradient(circle, rgba(91,188,240,.40), transparent 70%); pointer-events: none; }
 body::after { content: ""; position: fixed; width: 500px; height: 500px; border-radius: 50%; bottom: -200px; left: -160px; background: radial-gradient(circle, rgba(46,155,214,.28), transparent 70%); pointer-events: none; }
 .logo { display: block; width: 64px; height: 64px; margin: 0 auto 16px; filter: drop-shadow(0 8px 14px rgba(46,155,214,.35)); }
 .chips { display: flex; gap: 8px; justify-content: center; flex-wrap: wrap; margin-top: 20px; }
 .chip { font-size: 12px; color: var(--accent-dark); background: var(--accent-soft); padding: 5px 11px; border-radius: 999px; }
 .drop svg { width: 40px; height: 40px; }
 .card { position: relative; z-index: 1; background: var(--card); width: 100%; max-width: 460px; padding: 36px 32px; border-radius: 16px; border: 1px solid var(--line); box-shadow: 0 8px 30px rgba(46,155,214,.16); }
 h1 { font-size: 22px; font-weight: 700; text-align: center; }
 .sub { color: var(--muted); font-size: 14px; text-align: center; margin: 6px 0 26px; }
 
 .tabs { display: flex; background: var(--bg); padding: 4px; border-radius: 10px; margin-bottom: 18px; }
 .tabs label { flex: 1; text-align: center; padding: 9px 6px; border-radius: 8px; font-size: 14px; color: var(--muted); cursor: pointer; transition: .15s; }
 .tabs input { display: none; }
 .tabs label:has(input:checked) { background: var(--card); color: var(--accent-dark); font-weight: 600; box-shadow: 0 1px 3px rgba(30,41,59,.12); }
 
 .drop { display: block; border: 2px dashed var(--line); border-radius: 12px; padding: 30px 16px; text-align: center; cursor: pointer; transition: .15s; margin-bottom: 18px; }
 .drop:hover, .drop.over { border-color: var(--accent); background: var(--accent-soft); }
 .drop input { display: none; }
 .drop .icon { font-size: 28px; line-height: 1; margin-bottom: 10px; color: var(--accent); }
 .drop .t1 { font-size: 15px; font-weight: 600; }
 .drop .t2 { font-size: 13px; color: var(--muted); margin-top: 4px; word-break: break-all; }
 .drop.has { border-style: solid; border-color: var(--accent); background: var(--accent-soft); }
 
 button { width: 100%; background: var(--accent); color: #fff; border: 0; padding: 13px; font-size: 15px; font-weight: 600; border-radius: 10px; cursor: pointer; transition: .15s; }
 button:hover { background: var(--accent-dark); }
 button:disabled { opacity: .7; cursor: wait; }
 
 .err { background: var(--err-bg); border: 1px solid var(--err-line); color: var(--err-text); padding: 12px 14px; border-radius: 10px; font-size: 14px; margin-bottom: 18px; word-break: break-word; }
 .foot { text-align: center; color: #94a3b8; font-size: 12px; margin-top: 20px; }
</style>
</head>
<body>
<div class="card">
    <svg class="logo" viewBox="0 0 64 64" xmlns="http://www.w3.org/2000/svg" aria-hidden="true"><defs><linearGradient id="lg" x1="0" y1="0" x2="1" y2="1"><stop offset="0" stop-color="#5bbcf0"/><stop offset="1" stop-color="#1f86bf"/></linearGradient></defs><rect width="64" height="64" rx="16" fill="url(#lg)"/><g fill="none" stroke="#fff" stroke-width="5" stroke-linecap="round" stroke-linejoin="round"><path d="M16 25h32M40 16l9 9-9 9"/><path d="M48 42H16M24 33l-9 9 9 9"/></g></svg>
    <h1>Fayl Konverteri</h1>
    <p class="sub">Istalgan fayl &rarr; PDF &nbsp;·&nbsp; PDF &rarr; Word</p>
 
    <?php if ($error): ?><div class="err" id="err"><?= htmlspecialchars($error) ?></div><?php endif; ?>
 
    <form method="post" enctype="multipart/form-data" id="form">
        <input type="hidden" name="token" id="token">
        <div class="tabs">
            <label><input type="radio" name="mode" value="topdf" checked>Fayl → PDF</label>
            <label><input type="radio" name="mode" value="frompdf">PDF → Word</label>
        </div>
 
        <label class="drop" id="drop">
            <input type="file" name="f" id="file" required>
            <div class="icon"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M12 13v8"/><path d="M4 14.899A7 7 0 1 1 15.71 8h1.79a4.5 4.5 0 0 1 2.5 8.242"/><path d="m8 17 4-4 4 4"/></svg></div>
            <div class="t1" id="t1">Faylni tanlang yoki shu yerga tashlang</div>
            <div class="t2" id="t2">Word, Excel, PowerPoint, rasm, PDF...</div>
        </label>
 
        <button type="submit" id="btn">Konvertatsiya qilish</button>
    </form>
 
    <div class="chips"><span class="chip">Bepul</span><span class="chip">Ro'yxatdan o'tish shart emas</span><span class="chip">Fayllar saqlanmaydi</span></div>
</div>
 
<script>
 var file = document.getElementById('file'), drop = document.getElementById('drop'),
     t1 = document.getElementById('t1'), t2 = document.getElementById('t2'),
     btn = document.getElementById('btn');
 
 function hideErr() { var e = document.getElementById('err'); if (e) e.style.display = 'none'; }
 
 function show() {
   hideErr();
   if (file.files.length) {
     var f = file.files[0], mb = f.size / 1048576;
     t1.textContent = f.name;
     t2.textContent = (mb >= 1 ? mb.toFixed(1) + ' MB' : Math.max(1, Math.round(f.size / 1024)) + ' KB');
     drop.classList.add('has');
     var isPdf = /\.pdf$/i.test(f.name);
     document.querySelector('input[name=mode][value=' + (isPdf ? 'frompdf' : 'topdf') + ']').checked = true;
   }
 }
 file.addEventListener('change', show);
 
 ['dragenter', 'dragover'].forEach(function (e) {
   drop.addEventListener(e, function (ev) { ev.preventDefault(); drop.classList.add('over'); });
 });
 ['dragleave', 'drop'].forEach(function (e) {
   drop.addEventListener(e, function (ev) { ev.preventDefault(); drop.classList.remove('over'); });
 });
 drop.addEventListener('drop', function (ev) {
   if (ev.dataTransfer.files.length) { file.files = ev.dataTransfer.files; show(); }
 });
 
 var T1 = t1.textContent, T2 = t2.textContent, tokenEl = document.getElementById('token');
 
 function resetForm() {
   file.value = '';
   drop.classList.remove('has');
   t1.textContent = T1; t2.textContent = T2;
   btn.disabled = false; btn.textContent = 'Konvertatsiya qilish';
 }
 
 document.getElementById('form').addEventListener('submit', function () {
   hideErr();
   var tk = String(Date.now()); tokenEl.value = tk;
   btn.textContent = 'Kuting...';
   setTimeout(function () { btn.disabled = true; }, 0);
   var n = 0, iv = setInterval(function () {
     n++;
     if (document.cookie.indexOf('dl=' + tk) !== -1 || n > 600) { clearInterval(iv); resetForm(); }
   }, 500);
 });
</script>
</body>
</html>
 
