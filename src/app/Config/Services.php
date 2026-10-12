<?php

namespace Config;

use App\Libraries\ApiKeyIssuer;
use App\Libraries\Audit;
use App\Libraries\FeedResolver;
use App\Libraries\Http\MultipartPutParser;
use App\Libraries\PackagePublisher;
use App\Libraries\PackagePurger;
use App\Libraries\PackageStorage;
use App\Libraries\PackageVersionService;
use App\Libraries\PublishAuthorizer;
use App\Models\AuditLogModel;
use App\Models\FeedApiKeyRuleModel;
use App\Models\FeedModel;
use App\Models\PackageDependencyModel;
use App\Models\PackageModel;
use App\Models\PackageOwnerModel;
use App\Models\PackageVersionModel;
use CodeIgniter\Config\BaseService;
use CodeIgniter\Shield\Models\UserIdentityModel;

/**
 * Services Configuration file.
 *
 * Services are simply other classes/libraries that the system uses
 * to do its job. This is used by CodeIgniter to allow the core of the
 * framework to be swapped out easily without affecting the usage within
 * the rest of your application.
 *
 * This file holds any application-specific services, or service overrides
 * that you might need. An example has been included with the general
 * method format you should use for your service methods. For more examples,
 * see the core Services file at system/Config/Services.php.
 */
class Services extends BaseService
{
    public static function feedResolver(bool $getShared = true): FeedResolver
    {
        if ($getShared) {
            return static::getSharedInstance('feedResolver');
        }

        return new FeedResolver(model(FeedModel::class));
    }

    public static function packageStorage(bool $getShared = true): PackageStorage
    {
        if ($getShared) {
            return static::getSharedInstance('packageStorage');
        }

        $config = config(Pepite::class);

        return new PackageStorage($config->storagePath, $config->maxEmbeddedAssetBytes);
    }

    public static function publishAuthorizer(bool $getShared = true): PublishAuthorizer
    {
        if ($getShared) {
            return static::getSharedInstance('publishAuthorizer');
        }

        return new PublishAuthorizer(model(FeedApiKeyRuleModel::class), model(PackageOwnerModel::class));
    }

    public static function packagePublisher(bool $getShared = true): PackagePublisher
    {
        if ($getShared) {
            return static::getSharedInstance('packagePublisher');
        }

        return new PackagePublisher(
            model(FeedModel::class),
            model(PackageModel::class),
            model(PackageVersionModel::class),
            model(PackageDependencyModel::class),
            model(PackageOwnerModel::class),
            static::packageStorage(),
            db_connect(),
            static::publishAuthorizer(),
        );
    }

    public static function apiKeyIssuer(bool $getShared = true): ApiKeyIssuer
    {
        if ($getShared) {
            return static::getSharedInstance('apiKeyIssuer');
        }

        return new ApiKeyIssuer(db_connect(), model(FeedApiKeyRuleModel::class), model(UserIdentityModel::class), static::audit());
    }

    public static function audit(bool $getShared = true): Audit
    {
        if ($getShared) {
            return static::getSharedInstance('audit');
        }

        return new Audit(model(AuditLogModel::class));
    }

    public static function packagePurger(bool $getShared = true): PackagePurger
    {
        if ($getShared) {
            return static::getSharedInstance('packagePurger');
        }

        return new PackagePurger(static::packageStorage(), db_connect(), static::audit());
    }

    public static function packageVersions(bool $getShared = true): PackageVersionService
    {
        if ($getShared) {
            return static::getSharedInstance('packageVersions');
        }

        return new PackageVersionService(model(PackageVersionModel::class), static::audit());
    }

    public static function multipartPutParser(bool $getShared = true): MultipartPutParser
    {
        if ($getShared) {
            return static::getSharedInstance('multipartPutParser');
        }

        $config = config(Pepite::class);

        return new MultipartPutParser(
            $config->maxUploadBytes,
            $config->temporaryPath,
            $config->maxFieldBytes,
        );
    }
}
