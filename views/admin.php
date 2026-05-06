<h1>admin</h1>
<p><a href="/">public site</a> · <form style="display:inline" method="post" action="/admin/logout"><input type="hidden" name="csrf" value="<?= htmlspecialchars($csrf) ?>"><button>log out</button></form></p>

<h2>create link</h2>
<form method="post" action="/admin/links">
  <input type="hidden" name="csrf" value="<?= htmlspecialchars($csrf) ?>">
  <div class="row"><input type="url" name="url" placeholder="https://..." required></div>
  <div class="row">
    <input type="text" name="code" placeholder="optional custom code (XXX-XXX)" pattern="[0-9]{3}-?[0-9]{3}">
    <button type="submit">Create</button>
  </div>
  <?php if (!empty($error)): ?>
    <p class="err"><?= htmlspecialchars($error) ?></p>
  <?php endif ?>
  <?php if (!empty($created)): ?>
    <p>Created <code><?= htmlspecialchars(format_code($created)) ?></code> →
       <a href="/<?= htmlspecialchars(format_code($created)) ?>">/<?= htmlspecialchars(format_code($created)) ?></a></p>
  <?php endif ?>
</form>

<h2>links (<?= count($rows) ?>)</h2>
<?php if (empty($rows)): ?>
  <p class="muted">No links yet.</p>
<?php else: ?>
<table>
  <thead><tr><th>code</th><th>url</th><th>created</th><th></th></tr></thead>
  <tbody>
  <?php foreach ($rows as $r): $f = format_code($r['code']); ?>
    <tr>
      <td><a href="/<?= htmlspecialchars($f) ?>"><code><?= htmlspecialchars($f) ?></code></a></td>
      <td style="word-break:break-all"><?= htmlspecialchars($r['url']) ?></td>
      <td class="muted"><?= htmlspecialchars(date('Y-m-d', (int) $r['created_at'])) ?></td>
      <td>
        <form method="post" action="/admin/links/<?= htmlspecialchars($r['code']) ?>/delete"
              onsubmit="return confirm('Delete <?= htmlspecialchars($f) ?>?')">
          <input type="hidden" name="csrf" value="<?= htmlspecialchars($csrf) ?>">
          <button>delete</button>
        </form>
      </td>
    </tr>
  <?php endforeach ?>
  </tbody>
</table>
<?php endif ?>
