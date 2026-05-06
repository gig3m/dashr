<h1>admin login</h1>
<form method="post" action="/admin/login" autocomplete="off">
  <div class="row">
    <input type="password" name="password" placeholder="password" autofocus required>
    <button type="submit">Sign in</button>
  </div>
  <?php if (!empty($error)): ?>
    <p class="err"><?= htmlspecialchars($error) ?></p>
  <?php endif ?>
</form>
