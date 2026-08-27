<?php
/**
 * Atom 1.0 — the last 30 versions published to this feed, newest first.
 * Deliberately not extending layout/main: this is a document a feed reader
 * parses, not a page a browser renders chrome around.
 *
 * @var array<string, mixed>          $feed
 * @var list<array<string, mixed>>    $versions
 * @var string                        $selfUrl
 * @var string                        $feedUrl
 * @var string|null                   $generated
 */
$atomDate = static function (?string $sqlDatetime): string {
    if ($sqlDatetime === null || $sqlDatetime === '') {
        return date(DATE_ATOM);
    }

    $timestamp = strtotime($sqlDatetime);

    return date(DATE_ATOM, $timestamp === false ? time() : $timestamp);
};
?>
<?= '<?xml version="1.0" encoding="UTF-8"?>' ?>

<feed xmlns="http://www.w3.org/2005/Atom">
    <title><?= esc($feed['name']) ?> — Pépite</title>
    <subtitle>Recently published versions</subtitle>
    <id><?= esc($feedUrl) ?></id>
    <link rel="self" href="<?= esc($selfUrl, 'attr') ?>" type="application/atom+xml"/>
    <link rel="alternate" href="<?= esc($feedUrl, 'attr') ?>" type="text/html"/>
    <updated><?= esc($atomDate($generated)) ?></updated>
    <?php foreach ($versions as $version): ?>
        <?php
            $packageUrl = site_url('browse/' . $feed['slug'] . '/' . $version['package_id_lower'] . '/' . $version['version_normalized_lower']);
        ?>
        <entry>
            <title><?= esc($version['package_id']) ?> <?= esc($version['version_normalized']) ?></title>
            <id><?= esc($packageUrl) ?></id>
            <link rel="alternate" href="<?= esc($packageUrl, 'attr') ?>" type="text/html"/>
            <updated><?= esc($atomDate($version['created_at'] ?? null)) ?></updated>
            <?php if (! empty($version['description'])): ?>
                <summary><?= esc($version['description']) ?></summary>
            <?php endif ?>
        </entry>
    <?php endforeach ?>
</feed>
