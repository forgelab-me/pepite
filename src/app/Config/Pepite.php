<?php

declare(strict_types=1);

namespace Config;

use CodeIgniter\Config\BaseConfig;

/**
 * Server-wide settings, all overridable from .env.
 */
class Pepite extends BaseConfig
{
    /**
     * Where package blobs live. Outside the web root on purpose: nothing here
     * may ever be served directly by the web server, since a .nupkg is content
     * uploaded by a third party.
     */
    public string $storagePath = WRITEPATH . 'storage';

    /**
     * Scratch space for uploads in progress.
     */
    public string $temporaryPath = WRITEPATH . 'tmp';

    /**
     * Largest accepted push, in bytes.
     *
     * PHP's own post_max_size and memory_limit still apply and are usually the
     * binding constraint on shared hosting; the installer checks them and says
     * so. Framework-dependent .NET applications land in the 5-20 MB range,
     * libraries far below.
     */
    public int $maxUploadBytes = 104857600;

    /**
     * Ceiling for a non-file field in a multipart body. Generous for an API
     * key, small enough that a field cannot be used to exhaust memory.
     */
    public int $maxFieldBytes = 65536;

    /**
     * Largest icon or readme extracted out of a package.
     */
    public int $maxEmbeddedAssetBytes = 4194304;
}
