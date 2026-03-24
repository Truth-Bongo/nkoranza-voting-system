<?php require_once APP_ROOT . '/includes/header.php'; ?>

<div class="max-w-4xl mx-auto text-center py-16">
  <h1 class="text-9xl font-bold text-pink-900 mb-4">403</h1>
  <h2 class="text-3xl font-bold text-gray-900 mb-4">Access Denied</h2>
  <p class="text-gray-600 mb-8">You don't have permission to access this resource.</p>
  <div class="flex justify-center gap-4">
    <a href="<?= BASE_URL ?>" class="btn btn-primary px-8 py-3">
      Return to Home
    </a>
    <a href="<?= BASE_URL ?>/login" class="btn btn-secondary px-8 py-3">
      Login
    </a>
  </div>
</div>

<?php require_once APP_ROOT . '/includes/footer.php'; ?>