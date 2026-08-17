<?php

declare(strict_types=1);

namespace App\Libraries\Package;

use App\Exceptions\InvalidPackageException;
use App\Exceptions\InvalidVersionException;
use App\Libraries\Version\NuGetVersion;
use App\Libraries\Version\VersionRange;
use DOMDocument;
use DOMElement;

/**
 * Reads a .nuspec into a PackageMetadata.
 *
 * Deliberately namespace-agnostic. The nuspec schema has shipped under a
 * handful of XML namespaces over the years, and the .NET SDK still picks
 * between them per package: two of our own fixtures, packed by the same SDK
 * within the same minute, came out under 2012/06 and 2013/05 respectively. A
 * parser bound to one namespace silently fails to read half the packages it is
 * given, so every lookup here goes through local names.
 */
final class NuspecParser
{
    /**
     * NuGet's own identifier rule: word characters, separated by dot, hyphen or
     * underscore, at most 100 characters.
     */
    private const ID_PATTERN = '/^[A-Za-z0-9_]+(?:[.\-_][A-Za-z0-9_]+)*$/';

    private const ID_MAX_LENGTH = 100;

    public function parse(string $xml): PackageMetadata
    {
        $metadata = $this->metadataElement($xml);

        $id = $this->text($this->child($metadata, 'id'));

        if ($id === null) {
            throw InvalidPackageException::missingElement('id');
        }

        if (strlen($id) > self::ID_MAX_LENGTH || preg_match(self::ID_PATTERN, $id) !== 1) {
            throw InvalidPackageException::invalidId($id);
        }

        $rawVersion = $this->text($this->child($metadata, 'version'));

        if ($rawVersion === null) {
            throw InvalidPackageException::missingElement('version');
        }

        try {
            $version = NuGetVersion::parse($rawVersion);
        } catch (InvalidVersionException $e) {
            throw new InvalidPackageException($e->getMessage(), 0, $e);
        }

        $license = $this->child($metadata, 'license');

        return new PackageMetadata(
            id: $id,
            version: $version,
            description: $this->text($this->child($metadata, 'description')),
            authors: $this->splitList($this->text($this->child($metadata, 'authors'))),
            owners: $this->splitList($this->text($this->child($metadata, 'owners'))),
            tags: $this->splitTags($this->text($this->child($metadata, 'tags'))),
            title: $this->text($this->child($metadata, 'title')),
            summary: $this->text($this->child($metadata, 'summary')),
            releaseNotes: $this->text($this->child($metadata, 'releaseNotes')),
            copyright: $this->text($this->child($metadata, 'copyright')),
            language: $this->text($this->child($metadata, 'language')),
            projectUrl: $this->text($this->child($metadata, 'projectUrl')),
            iconUrl: $this->text($this->child($metadata, 'iconUrl')),
            icon: $this->text($this->child($metadata, 'icon')),
            readme: $this->text($this->child($metadata, 'readme')),
            licenseUrl: $this->text($this->child($metadata, 'licenseUrl')),
            licenseType: $license === null ? null : ($this->attribute($license, 'type') ?? 'expression'),
            licenseValue: $this->text($license),
            requireLicenseAcceptance: $this->bool($this->text($this->child($metadata, 'requireLicenseAcceptance'))),
            developmentDependency: $this->bool($this->text($this->child($metadata, 'developmentDependency'))),
            serviceable: $this->bool($this->text($this->child($metadata, 'serviceable'))),
            minClientVersion: $this->attribute($metadata, 'minClientVersion'),
            repositoryType: $this->repositoryAttribute($metadata, 'type'),
            repositoryUrl: $this->repositoryAttribute($metadata, 'url'),
            repositoryBranch: $this->repositoryAttribute($metadata, 'branch'),
            repositoryCommit: $this->repositoryAttribute($metadata, 'commit'),
            packageTypes: $this->parsePackageTypes($metadata),
            dependencyGroups: $this->parseDependencies($metadata),
        );
    }

    private function metadataElement(string $xml): DOMElement
    {
        // A .nuspec written by the .NET SDK starts with a UTF-8 BOM.
        $clean = preg_replace('/^\xEF\xBB\xBF/', '', ltrim($xml)) ?? $xml;

        if (trim($clean) === '') {
            throw InvalidPackageException::malformedNuspec('the document is empty');
        }

        $previous = libxml_use_internal_errors(true);
        libxml_clear_errors();

        $document = new DOMDocument();
        // LIBXML_NONET forbids network access while parsing. External entity
        // loading is off by default in PHP 8, and we never turn it on: the
        // .nuspec is attacker-supplied content.
        $loaded = $document->loadXML($clean, LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING);

        $errors = libxml_get_errors();
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        if (! $loaded) {
            $reason = $errors === [] ? 'unknown parse error' : trim($errors[0]->message);

            throw InvalidPackageException::malformedNuspec($reason);
        }

        $root = $document->documentElement;

        if ($root === null || $root->localName !== 'package') {
            throw InvalidPackageException::malformedNuspec('the root element is not <package>');
        }

        $metadata = $this->child($root, 'metadata');

        if ($metadata === null) {
            throw InvalidPackageException::missingElement('metadata');
        }

        return $metadata;
    }

    /**
     * @return list<PackageType>
     */
    private function parsePackageTypes(DOMElement $metadata): array
    {
        $container = $this->child($metadata, 'packageTypes');

        if ($container === null) {
            return [];
        }

        $types = [];

        foreach ($this->children($container, 'packageType') as $element) {
            $name = $this->attribute($element, 'name');

            if ($name !== null) {
                $types[] = new PackageType($name, $this->attribute($element, 'version'));
            }
        }

        return $types;
    }

    /**
     * @return list<DependencyGroup>
     */
    private function parseDependencies(DOMElement $metadata): array
    {
        $container = $this->child($metadata, 'dependencies');

        if ($container === null) {
            return [];
        }

        $groups = [];

        // The legacy flat form: <dependencies> holding <dependency> directly,
        // meaning "applies to every framework".
        $flat = $this->parseDependencyList($container);

        if ($flat !== []) {
            $groups[] = new DependencyGroup(null, $flat);
        }

        foreach ($this->children($container, 'group') as $element) {
            $framework = $this->attribute($element, 'targetFramework');

            $groups[] = new DependencyGroup(
                $framework === null || $framework === '' ? null : $framework,
                $this->parseDependencyList($element),
            );
        }

        return $groups;
    }

    /**
     * @return list<PackageDependency>
     */
    private function parseDependencyList(DOMElement $parent): array
    {
        $dependencies = [];

        foreach ($this->children($parent, 'dependency') as $element) {
            $id = $this->attribute($element, 'id');

            if ($id === null) {
                continue;
            }

            $rawRange = $this->attribute($element, 'version');

            $dependencies[] = new PackageDependency(
                $id,
                // A malformed range is not worth rejecting the whole package
                // for: treat it as "any version", which is what an absent
                // version attribute already means.
                $rawRange === null ? null : VersionRange::tryParse($rawRange),
                $this->attribute($element, 'include'),
                $this->attribute($element, 'exclude'),
            );
        }

        return $dependencies;
    }

    private function repositoryAttribute(DOMElement $metadata, string $name): ?string
    {
        $repository = $this->child($metadata, 'repository');

        return $repository === null ? null : $this->attribute($repository, $name);
    }

    private function child(DOMElement $parent, string $localName): ?DOMElement
    {
        foreach ($parent->childNodes as $node) {
            if ($node instanceof DOMElement && $node->localName === $localName) {
                return $node;
            }
        }

        return null;
    }

    /**
     * @return list<DOMElement>
     */
    private function children(DOMElement $parent, string $localName): array
    {
        $found = [];

        foreach ($parent->childNodes as $node) {
            if ($node instanceof DOMElement && $node->localName === $localName) {
                $found[] = $node;
            }
        }

        return $found;
    }

    private function attribute(DOMElement $element, string $name): ?string
    {
        if (! $element->hasAttribute($name)) {
            return null;
        }

        $value = trim($element->getAttribute($name));

        return $value === '' ? null : $value;
    }

    private function text(?DOMElement $element): ?string
    {
        if ($element === null) {
            return null;
        }

        $value = trim($element->textContent);

        return $value === '' ? null : $value;
    }

    /**
     * Authors and owners are comma separated.
     *
     * @return list<string>
     */
    private function splitList(?string $value): array
    {
        if ($value === null) {
            return [];
        }

        return array_values(array_filter(
            array_map(trim(...), explode(',', $value)),
            static fn (string $item): bool => $item !== '',
        ));
    }

    /**
     * Tags are whitespace separated, despite sitting next to comma separated
     * fields in the same document.
     *
     * @return list<string>
     */
    private function splitTags(?string $value): array
    {
        if ($value === null) {
            return [];
        }

        return array_values(array_filter(
            preg_split('/\s+/', $value) ?: [],
            static fn (string $item): bool => $item !== '',
        ));
    }

    private function bool(?string $value): bool
    {
        return $value !== null && strcasecmp($value, 'true') === 0;
    }
}
