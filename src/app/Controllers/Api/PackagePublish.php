<?php

declare(strict_types=1);

namespace App\Controllers\Api;

use App\Exceptions\InvalidPackageException;
use App\Exceptions\MultipartException;
use App\Exceptions\PayloadTooLargeException;
use App\Exceptions\PublishException;
use App\Libraries\Package\NupkgReader;
use App\Models\PackageModel;
use App\Models\PackageOwnerModel;
use App\Models\PackageVersionModel;
use CodeIgniter\Controller;
use CodeIgniter\HTTP\ResponseInterface;
use Throwable;

/**
 * PackagePublish/2.0.0 — push, unlist and relist.
 *
 * The push is a PUT carrying multipart/form-data, which PHP will not parse on
 * its own: $_FILES is only populated for POST. The body is therefore streamed
 * through MultipartPutParser straight to a temporary file.
 *
 * A useful accident of CodeIgniter's design makes that possible: IncomingRequest
 * skips its own read of php://input when the content type is multipart, so the
 * stream is still unread by the time we get here. The flip side is that
 * getBody() then returns the literal string 'php://input' rather than a body,
 * which is why it is only trusted when a test has put a real one there.
 */
final class PackagePublish extends Controller
{
    public function push(string $slug): ResponseInterface
    {
        return $this->accept($slug, symbols: false);
    }

    /**
     * Symbol packages are accepted and stored, never served.
     *
     * Refusing them outright would make `dotnet nuget push` fail whenever a
     * .snupkg sits next to the package, which is the default output of a build
     * with symbols enabled.
     */
    public function pushSymbols(string $slug): ResponseInterface
    {
        return $this->accept($slug, symbols: true);
    }

    public function unlist(string $slug, string $id, string $version): ResponseInterface
    {
        return $this->setListed($slug, $id, $version, false);
    }

    public function relist(string $slug, string $id, string $version): ResponseInterface
    {
        return $this->setListed($slug, $id, $version, true);
    }

    private function accept(string $slug, bool $symbols): ResponseInterface
    {
        $parser = service('multipartPutParser');
        $stream = $this->bodyStream();

        $body = null;

        try {
            $body = $parser->parse($stream, $this->request->getHeaderLine('Content-Type'));

            $file = $body->firstFile();

            if ($file === null) {
                return $this->error(400, 'The request carries no package file.');
            }

            if ($symbols) {
                return $this->storeSymbols($slug, $file->path);
            }

            service('packagePublisher')->publish(
                $file->path,
                $slug,
                $this->currentUserId(),
                $this->currentIdentityId(),
            );

            // 201 with an empty body: the client reads the status and nothing
            // else.
            return $this->response->setStatusCode(201)->setBody('')->setContentType('text/plain');
        } catch (PayloadTooLargeException $e) {
            return $this->error(413, $e->getMessage());
        } catch (MultipartException $e) {
            return $this->error(400, $e->getMessage());
        } catch (InvalidPackageException $e) {
            return $this->error(400, $e->getMessage());
        } catch (PublishException $e) {
            // The service already decided the status: 404 unknown feed, 409
            // version already published, 403 refused, 400 wrong package type.
            return $this->error($e->status, $e->getMessage());
        } catch (Throwable $e) {
            log_message('error', 'Push failed: {message}', ['message' => $e->getMessage()]);

            return $this->error(500, 'The package could not be stored.');
        } finally {
            $body?->cleanup();

            if (is_resource($stream)) {
                fclose($stream);
            }
        }
    }

    /**
     * Stores a .snupkg beside the package version it belongs to.
     */
    private function storeSymbols(string $slug, string $path): ResponseInterface
    {
        $reader = null;

        try {
            $reader   = NupkgReader::open($path);
            $metadata = $reader->metadata();
        } catch (InvalidPackageException $e) {
            return $this->error(400, $e->getMessage());
        } finally {
            $reader?->close();
        }

        $feed = service('feedResolver')->find($slug);

        if ($feed === null) {
            return $this->error(404, sprintf('There is no feed named "%s".', $slug));
        }

        $package = model(PackageModel::class)->findInFeed((int) $feed['id'], $metadata->id);

        if ($package === null) {
            return $this->error(404, sprintf('No package named "%s" in feed "%s".', $metadata->id, $slug));
        }

        if ($error = $this->requireOwnership((int) $package['id'], $metadata->id)) {
            return $error;
        }

        $version = model(PackageVersionModel::class)->findVersion((int) $package['id'], $metadata->version);

        if ($version === null) {
            return $this->error(
                404,
                sprintf('Publish %s %s before its symbols.', $metadata->id, $metadata->version->normalized()),
            );
        }

        $stored = service('packageStorage')->storeSymbols(
            (int) $feed['id'],
            $metadata->id,
            $metadata->version,
            $path,
        );

        model(PackageVersionModel::class)->update((int) $version['id'], ['snupkg_path' => $stored]);

        return $this->response->setStatusCode(201)->setBody('')->setContentType('text/plain');
    }

    private function setListed(string $slug, string $id, string $version, bool $listed): ResponseInterface
    {
        $feed = service('feedResolver')->find($slug);

        if ($feed === null) {
            return $this->error(404, sprintf('There is no feed named "%s".', $slug));
        }

        $package = model(PackageModel::class)->findInFeed((int) $feed['id'], $id);

        if ($package === null) {
            return $this->error(404, sprintf('No package named "%s" in feed "%s".', $id, $slug));
        }

        if ($error = $this->requireOwnership((int) $package['id'], $id)) {
            return $error;
        }

        $versions = model(PackageVersionModel::class);

        $row = $versions
            ->where('package_id', (int) $package['id'])
            ->where('version_normalized_lower', strtolower($version))
            ->first();

        if ($row === null) {
            return $this->error(404, sprintf('No version "%s" of "%s".', $version, $id));
        }

        // Delisting never deletes: published versions are immutable, and
        // anything already depending on this one must keep restoring.
        $versions->update((int) $row['id'], ['is_listed' => $listed]);

        return $this->response->setStatusCode(204)->setBody('');
    }

    /**
     * The raw request body.
     *
     * In production this is php://input, still unread. A feature test supplies
     * a real body string instead, which is wrapped in a memory stream so the
     * parser sees the same thing either way.
     *
     * @return resource
     */
    private function bodyStream()
    {
        $body = $this->request->getBody();

        if (is_string($body) && $body !== '' && $body !== 'php://input') {
            $stream = fopen('php://memory', 'r+b');
            fwrite($stream, $body);
            rewind($stream);

            return $stream;
        }

        return fopen('php://input', 'rb');
    }

    /**
     * Guards unlist, relist and symbol upload: pushing a new package is
     * gated by PublishAuthorizer inside the publisher, but these three act on
     * a package that already exists, so ownership has to be checked here too
     * — otherwise any key with the unlist scope could delist anyone's package.
     */
    private function requireOwnership(int $packageRowId, string $packageId): ?ResponseInterface
    {
        $userId = $this->currentUserId();

        if ($userId !== null && model(PackageOwnerModel::class)->owns($packageRowId, $userId)) {
            return null;
        }

        return $this->error(403, sprintf('You are not an owner of "%s".', $packageId));
    }

    private function currentUserId(): ?int
    {
        $user = auth('tokens')->user();

        return $user?->id === null ? null : (int) $user->id;
    }

    private function currentIdentityId(): ?int
    {
        $token = auth('tokens')->user()?->currentAccessToken();

        return $token?->id === null ? null : (int) $token->id;
    }

    private function error(int $status, string $message): ResponseInterface
    {
        return $this->response
            ->setStatusCode($status)
            ->setContentType('application/json')
            ->setBody(json_encode(['error' => $message], JSON_UNESCAPED_SLASHES));
    }
}
