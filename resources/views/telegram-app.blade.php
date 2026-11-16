<!doctype html>
<html lang="{{ app()->getLocale() }}">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1,maximum-scale=1">
<meta name="robots" content="noindex,nofollow">
<title>{{ config('app.name') }}</title>
{{-- Telegram's own script for Mini Apps: theme, the main button, the signed initData. --}}
<script src="https://telegram.org/js/telegram-web-app.js"></script>
<style>
:root{--bg:var(--tg-theme-bg-color,#fff);--tx:var(--tg-theme-text-color,#000);--ln:var(--tg-theme-link-color,#2481cc);--sb:var(--tg-theme-secondary-bg-color,#f1f1f4);--bt:var(--tg-theme-button-color,#2481cc);--btx:var(--tg-theme-button-text-color,#fff)}
*{box-sizing:border-box}
body{margin:0;background:var(--bg);color:var(--tx);font:16px/1.45 -apple-system,system-ui,"Segoe UI",Roboto,sans-serif}
main{padding:14px 16px 24px;max-width:640px;margin:0 auto}
h1{font-size:15px;margin:0 0 8px;color:var(--ln);text-transform:uppercase;letter-spacing:.03em}
.lbl{font-size:13px;font-weight:700;color:var(--ln);text-transform:uppercase;letter-spacing:.03em;margin:14px 0 6px}
.task{font-weight:700;font-size:17px;line-height:1.3}.task .n{color:var(--ln)}
.meta{font-size:14px;margin-top:4px}
.q{background:var(--sb);border-radius:12px;padding:10px 12px;font-size:15px;overflow-wrap:anywhere;white-space:pre-line}
.q b{display:block;color:var(--ln);font-size:14px;margin-bottom:2px;white-space:normal}
textarea,input{width:100%;font:inherit;color:var(--tx);background:var(--sb);border:2px solid transparent;border-radius:12px;padding:10px 12px;outline:none}
textarea{min-height:110px;resize:vertical}
textarea:focus,input:focus{border-color:var(--bt)}
.hint{font-size:13px;margin-top:6px}
.who{font-size:14px;margin-top:12px}
.chips{display:flex;flex-wrap:wrap;gap:8px}
.chip{border:2px solid var(--sb);background:var(--sb);color:var(--tx);border-radius:20px;padding:8px 14px;font:inherit;font-size:15px;font-weight:600}
.chip.on{border-color:var(--bt);background:var(--bt);color:var(--btx)}
.chip.red.on{border-color:#e5484d;background:#e5484d;color:#fff}
.send{display:block;width:100%;margin-top:16px;padding:13px;border:0;border-radius:12px;background:var(--bt);color:var(--btx);font:inherit;font-weight:700}
.send:disabled{opacity:.5}
.err{display:none;margin-top:12px;padding:10px 12px;border-radius:10px;background:#fde8e8;color:#9b1c1c;font-size:15px}
.ok{text-align:center;padding:30px 10px}.ok .big{font-size:46px}.ok h2{margin:8px 0}
[hidden]{display:none!important}
</style>
</head>
<body>
<main id="wait"><p id="wait-text">{{ __('telegram_app.loading') }}</p></main>

<main id="form" hidden>
  <h1 id="h"></h1>
  <div class="task"><span class="n" id="num"></span> <span id="ttl"></span></div>
  <div class="meta" id="meta"></div>

  <section id="s-quote" hidden>
    <div class="lbl" data-t="reply_to"></div>
    <div class="q"><b id="qa"></b><span id="qt"></span></div>
  </section>

  <section id="s-request" hidden>
    <div class="lbl" data-t="reject_request"></div>
    <div class="q"><b id="rq-who"></b><s id="rq-old"></s> → <strong id="rq-new"></strong><span id="rq-why"></span></div>
  </section>

  <section id="s-deadline" hidden>
    <div class="lbl" data-t="deadline_now"></div>
    <div class="q" id="d-now"></div>
    <div class="lbl" data-t="deadline_new"></div>
    <input type="date" id="d-date">
  </section>

  <section id="s-status" hidden>
    <div class="lbl" data-t="status_now"></div>
    <div class="q" id="st-now"></div>
    <div class="lbl" data-t="status_new"></div>
    <div class="chips" id="chips"></div>
    <section id="st-date" hidden><div class="lbl" data-t="status_date"></div><input type="date" id="st-date-input"></section>
  </section>

  <section id="s-text">
    <div class="lbl" id="text-label"></div>
    <textarea id="text"></textarea>
    <div class="hint" id="hint"></div>
  </section>

  <div class="who" id="who"></div>
  <div class="err" id="err"></div>
  <button class="send" id="send" disabled></button>
</main>

<main class="ok" id="ok" hidden>
  <div class="big">✅</div>
  <h2 id="ok-h"></h2>
  <p id="ok-p"></p>
  <button class="send" id="close"></button>
</main>

<script>
(function () {
  const tg = window.Telegram && Telegram.WebApp;
  const P = new URLSearchParams(location.search);
  const mode = P.get('m') || '';
  const ids = {task: P.get('task'), notice: P.get('notice'), req: P.get('req')};
  const $ = id => document.getElementById(id);
  let T = @json(__('telegram_app'));
  const t = (key, rep) => (T[key] || key).replace(/:(\w+)/g, (m, k) => rep && rep[k] !== undefined ? rep[k] : m);
  const show = (el, on) => { el.hidden = !on; };
  let ctx = null, chosen = null, busy = false;

  if (!tg || !tg.initData) { $('wait-text').textContent = t('open_in_telegram'); return; }
  tg.ready(); tg.expand();

  function post(url, body) {
    return fetch(url, {method: 'POST', headers: {'Content-Type': 'application/json', 'Accept': 'application/json'},
      body: JSON.stringify(Object.assign({initData: tg.initData, m: mode}, ids, body))})
      .then(r => r.json().then(j => ({status: r.status, json: j})));
  }

  function fail(text) { $('err').textContent = text; show($('err'), true); }

  function valid() {
    const text = $('text').value.trim();
    if (mode === 'reply' || mode === 'comment' || mode === 'reject') return text !== '';
    if (mode === 'deadline') return $('d-date').value !== '' && text !== '';
    if (mode === 'status') {
      if (!chosen) return false;
      if (chosen.reason && text === '') return false;
      if (chosen.date && $('st-date-input').value === '') return false;
      return true;
    }
    return false;
  }

  function refresh() {
    const ok = valid() && !busy;
    $('send').disabled = !ok;
    ok ? tg.MainButton.enable() : tg.MainButton.disable();
  }

  function render() {
    const task = ctx.task;
    T = ctx.i18n || T;
    document.querySelectorAll('[data-t]').forEach(el => { el.textContent = t(el.dataset.t); });
    $('h').textContent = t(mode + '_title');
    $('num').textContent = '#' + task.number;
    $('ttl').textContent = task.title;
    $('meta').textContent = [task.assignee ? '🎯 ' + task.assignee : null, '«' + task.status + '»',
      '🗓 ' + (task.deadline || t('no_deadline'))].filter(Boolean).join(' · ');
    $('who').textContent = '👤 ' + t('who', {name: ctx.user});

    let send = t(mode + '_send'), label = t('comment_label'), place = t('comment_placeholder'), hint = '';

    if (mode === 'reply') {
      show($('s-quote'), true);
      $('qa').textContent = ctx.quote.author; $('qt').textContent = ctx.quote.text;
      label = t('reply_label'); place = t('reply_placeholder'); hint = t('reply_hint');
    }
    if (mode === 'comment') { hint = t('comment_hint'); }
    if (mode === 'deadline') {
      show($('s-deadline'), true);
      $('d-now').textContent = task.deadline || t('no_deadline');
      $('d-date').min = nextDay(ctx.today);
      label = t('reason'); place = t('deadline_reason_placeholder');
      hint = ctx.moves_itself ? t('deadline_hint_self') : t('deadline_hint', {name: task.initiator || '—'});
      if (ctx.moves_itself) send = t('deadline_send_self');
    }
    if (mode === 'reject') {
      show($('s-request'), true);
      $('rq-who').textContent = t('reject_line', {name: ctx.request.requester || '—'});
      $('rq-old').textContent = ctx.request.old; $('rq-new').textContent = ctx.request.new;
      $('rq-why').textContent = ctx.request.reason ? '\n' + ctx.request.reason : '';
      label = t('reject_label'); place = t('reject_placeholder'); hint = t('reject_hint', {name: ctx.request.requester || '—'});
      if (!ctx.request.open) { fail(t('reject_closed')); busy = true; }
    }
    if (mode === 'status') {
      show($('s-status'), true);
      $('st-now').textContent = '«' + task.status + '»';
      $('st-date-input').min = nextDay(ctx.today);
      label = t('status_comment'); place = t('status_comment_placeholder');
      if (!ctx.statuses.length) { fail(t('status_none')); busy = true; }
      ctx.statuses.forEach(s => {
        const b = document.createElement('button');
        b.className = 'chip' + (s.danger ? ' red' : '');
        b.textContent = s.label;
        b.onclick = () => {
          chosen = s;
          document.querySelectorAll('.chip').forEach(c => c.classList.toggle('on', c === b));
          show($('st-date'), s.date);
          $('text-label').textContent = s.reason ? t('status_reason') : t('status_comment');
          refresh();
        };
        $('chips').append(b);
      });
    }

    $('text-label').textContent = label;
    $('text').placeholder = place;
    $('hint').textContent = hint;
    $('send').textContent = send;
    $('close').textContent = t('close');
    tg.MainButton.setParams({text: send, is_visible: true, is_active: false});
    show($('send'), false);
    show($('wait'), false); show($('form'), true);
    if (mode !== 'deadline' && mode !== 'status') setTimeout(() => $('text').focus(), 300);
    refresh();
  }

  function nextDay(iso) { const d = new Date(iso + 'T12:00:00'); d.setDate(d.getDate() + 1); return d.toISOString().slice(0, 10); }

  function submit() {
    if (!valid() || busy) return;
    busy = true; refresh(); show($('err'), false);
    tg.MainButton.showProgress();
    post(@json(route('telegram.app.submit')), {
      text: $('text').value,
      date: mode === 'deadline' ? $('d-date').value : (chosen && chosen.date ? $('st-date-input').value : null),
      status: chosen ? chosen.value : null,
    }).then(({json}) => {
      tg.MainButton.hideProgress();
      if (!json.ok) { busy = false; fail(json.message || t('error')); refresh(); tg.HapticFeedback.notificationOccurred('error'); return; }
      tg.HapticFeedback.notificationOccurred('success');
      $('ok-h').textContent = t('done'); $('ok-p').textContent = json.message || '';
      show($('form'), false); show($('ok'), true);
      tg.MainButton.offClick(submit);
      tg.MainButton.setParams({text: t('close'), is_active: true});
      tg.MainButton.onClick(() => tg.close());
      setTimeout(() => tg.close(), 1800);
    }).catch(() => { tg.MainButton.hideProgress(); busy = false; fail(t('error')); refresh(); });
  }

  ['text', 'd-date', 'st-date-input'].forEach(id => $(id).addEventListener('input', refresh));
  $('send').addEventListener('click', submit);
  $('close').addEventListener('click', () => tg.close());
  tg.MainButton.onClick(submit);

  post(@json(route('telegram.app.context')), {}).then(({status, json}) => {
    if (!json.ok) { $('wait-text').textContent = status === 401 ? t('auth') : (json.message || t('error')); return; }
    ctx = json; render();
  }).catch(() => { $('wait-text').textContent = t('error'); });
})();
</script>
</body>
</html>
