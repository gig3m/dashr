<h1><?= htmlspecialchars($site_name) ?></h1>
<p>Enter the 6-digit code:</p>
<form method="post" action="/go" autocomplete="off">
  <div class="row">
    <input
      type="text"
      name="code"
      inputmode="numeric"
      pattern="[0-9]{3}-?[0-9]{3}"
      maxlength="7"
      placeholder="123-456"
      autofocus
      required>
    <button type="submit">Go</button>
  </div>
  <?php if (!empty($error)): ?>
    <p class="err"><?= htmlspecialchars($error) ?></p>
  <?php endif ?>
</form>
