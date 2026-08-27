<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Already installed — Pépite</title>
<link rel="stylesheet" href="<?= base_url('vendor/bootstrap/bootstrap.min.css') ?>">
<link rel="stylesheet" href="<?= base_url('vendor/bootstrap-icons/bootstrap-icons.min.css') ?>">
<style>
    :root { --bs-body-bg: #fbfaf7; --bs-body-color: #1c1a17; --bs-primary: #a8791f; }
    @media (prefers-color-scheme: dark) {
        :root { --bs-body-bg: #14171c; --bs-body-color: #e6e9ee; --bs-primary: #5b9cf6; }
    }
</style>
</head>
<body>
<main class="container py-5 text-center" style="max-width: 32rem;">
    <i class="bi bi-lock-fill text-body-secondary" style="font-size: 3rem;"></i>
    <h1 class="h3 mt-3">Already installed</h1>
    <p class="text-body-secondary">Pépite is already configured. To reinstall, delete <code>writable/install.lock</code> — only if you know what you're doing.</p>
    <a class="btn btn-outline-secondary" href="<?= site_url('/') ?>">Back to home</a>
</main>
</body>
</html>
