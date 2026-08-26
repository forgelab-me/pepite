<?php

declare(strict_types=1);

namespace Config;

use Forgelabme\TrustedPublishing\Config\TrustedPublishing as BaseTrustedPublishing;
use Forgelabme\TrustedPublishing\Providers\GithubActions;
use Forgelabme\TrustedPublishing\Providers\GitlabCi;

class TrustedPublishing extends BaseTrustedPublishing
{
    /**
     * Only relevant for a self-hosted GitLab instance — gitlab.com (the
     * default) needs nothing here. Issuer and JWKS are per-installation, so
     * GitlabCi takes this as a constructor argument rather than a constant.
     * Override in .env: trustedpublishing.gitlabInstanceUrl = https://…
     */
    public string $gitlabInstanceUrl = 'https://gitlab.com';

    public array $providers = [];

    public function __construct()
    {
        parent::__construct();

        // Built here, not as a static array literal: GitlabCi needs
        // $gitlabInstanceUrl, which only exists once the env override above
        // has actually been applied by BaseConfig's constructor.
        $this->providers = [
            GithubActions::class,
            new GitlabCi($this->gitlabInstanceUrl),
        ];
    }
}
