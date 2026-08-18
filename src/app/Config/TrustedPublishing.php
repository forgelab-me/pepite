<?php

declare(strict_types=1);

namespace Config;

use Forgelabme\TrustedPublishing\Config\TrustedPublishing as BaseTrustedPublishing;
use Forgelabme\TrustedPublishing\Providers\GithubActions;

class TrustedPublishing extends BaseTrustedPublishing
{
    public array $providers = [
        GithubActions::class,
    ];
}
