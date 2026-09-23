<?php

namespace Meent\WebHook;

use League\Flysystem\FilesystemOperator;

class MacInformation
{
    // @FIXME: Needs fixup when time allows. 2026/11/23/BPM
    //         This file was created in a rush to get things out of the door for a deadline.
    //         AI might have been invloved.

    //////////////////////////////// PUBLIC API \\\\\\\\\\\\\\\\\\\\\\\\\\\\\\\

    use UrlHashTrait;

    final public function __construct(
        private FilesystemOperator $dataFilesystem,
    ) {}

    /**
     * Count the number of pending (not yet written to the Solid Pod) data
     * files for a given WebID.
     *
     * Pending files are stored locally at `{webIdHash}/{timestamp}.{id}.data`
     * and are removed again once the data has been written to the Pod.
     */
    final public function countPendingFilesForWebId(string $webId): int
    {
        $webIdHash = $this->hashUrl($webId, 'sha1');

        if (! $this->dataFilesystem->directoryExists($webIdHash)) {
            return 0;
        }

        $count = 0;
        $generator = $this->dataFilesystem->listContents($webIdHash)->toArray();

        foreach ($generator as $fileAttribute) {
            if ($fileAttribute->isFile() && str_ends_with($fileAttribute->path(), '.data')) {
                $count++;
            }
        }

        return $count;
    }

    /**
     * Count the number of pending (not yet written to the Solid Pod) data
     * files stored under a given directory path.
     *
     * The path is used as-is (no WebID normalization), so it can be used for
     * both known WebIDs and orphaned directories whose name is a hash.
     */
    final public function countPendingFilesInDirectory(string $directoryPath): int
    {
        if (! $this->dataFilesystem->directoryExists($directoryPath)) {
            return 0;
        }

        $count = 0;
        $generator = $this->dataFilesystem->listContents($directoryPath)->toArray();

        foreach ($generator as $fileAttribute) {
            if ($fileAttribute->isFile() && str_ends_with($fileAttribute->path(), '.data')) {
                $count++;
            }
        }

        return $count;
    }

    final public function getMacForSecretHash(string $secretHash): string
    {
        $mac = '';

        $secrets = array_filter($this->getMacSecrets(), function ($secret) use ($secretHash) {
            return $secret === $secretHash;
        }, ARRAY_FILTER_USE_BOTH);

        if (count($secrets) > 0) {
            $mac = array_keys($secrets)[0];
        }

        return $mac;
    }

    final public function getMacsForWebId(string $webId): array
    {
        $macs = [];

        // The .mac files store the raw WebID as returned by the Pod creation
        // flow, while callers may pass a normalized WebID. Normalize both sides
        // so the comparison is robust against trailing slashes, fragments, etc.
        $normalizedWebId = $this->normalizeUrl($webId);

        $generator = $this->dataFilesystem->listContents('keys')->toArray();

        array_walk($generator, function ($fileAttribute) use (&$macs, $normalizedWebId) {
            $path = $fileAttribute->path();

            if ($fileAttribute->isFile() && str_ends_with($path, '.mac')) {
                $storedWebId = trim($this->dataFilesystem->read($path));

                try {
                    $storedWebId = $this->normalizeUrl($storedWebId);
                } catch (\Throwable) {
                    // Ignore entries that do not contain a valid URL.
                    return;
                }

                if ($storedWebId === $normalizedWebId) {
                    $macs[] = basename($path, '.mac');
                }
            }
        });

        sort($macs, SORT_STRING);

        return $macs;
    }

    /**
     * Information about pending data directories that do not belong to any
     * currently registered WebID ("orphaned" data).
     *
     * Each entry contains:
     *  - hash: the directory name (sha1 of the WebID/storage URL)
     *  - storage_url: the storage URL the data was written to, if known
     *  - pending_files: number of unsent records in that directory
     *
     * @return array<int, array{hash: string, storage_url: string, pending_files: int}>
     */
    final public function getOrphanedPendingFiles(): array
    {
        $registeredWebIds = [];
        $generator = $this->dataFilesystem->listContents('keys')->toArray();
        foreach ($generator as $fileAttribute) {
            if ($fileAttribute->isFile() && str_ends_with($fileAttribute->path(), '.key')) {
                $storedWebId = trim($this->dataFilesystem->read($fileAttribute->path()));
                try {
                    $registeredWebIds[] = $this->normalizeUrl($storedWebId);
                } catch (\Throwable) {
                    // Ignore entries that do not contain a valid URL.
                }
            }
        }

        $registeredHashes = array_map(
            fn($webId) => $this->hashUrl($webId, 'sha1'),
            $registeredWebIds,
        );

        $orphans = [];
        $generator = $this->dataFilesystem->listContents('/')->toArray();
        foreach ($generator as $directory) {
            if ($directory->isFile()
                || in_array($directory->path(), ['keys', 'storage-urls'], true)
                || in_array($directory->path(), $registeredHashes, true)
            ) {
                continue;
            }

            $pending = $this->countPendingFilesInDirectory($directory->path());
            if ($pending === 0) {
                continue;
            }

            $storageUrl = '';
            $storageFilePath = vsprintf('storage-urls/%s.url', ['webIdHash' => $directory->path()]);
            if ($this->dataFilesystem->fileExists($storageFilePath)) {
                $storageUrl = trim($this->dataFilesystem->read($storageFilePath));
            }

            $orphans[] = [
                'hash' => $directory->path(),
                'storage_url' => $storageUrl,
                'pending_files' => $pending,
            ];
        }

        return $orphans;
    }

    /**
     * Get the storage URL a given WebID writes to, if known.
     *
     * The storage URL is stored locally at `storage-urls/{webIdHash}.url`
     * (where `webIdHash` is `hashUrl(webId, 'sha1')`) whenever a Pod's
     * storage URL is discovered. Returns an empty string when unknown.
     */
    final public function getStorageUrlForWebId(string $webId): string
    {
        $webIdHash = $this->hashUrl($webId, 'sha1');
        $storageFilePath = vsprintf('storage-urls/%s.url', ['webIdHash' => $webIdHash]);

        if (! $this->dataFilesystem->fileExists($storageFilePath)) {
            return '';
        }

        return trim($this->dataFilesystem->read($storageFilePath));
    }

    /**
     * Count the total number of pending (not yet written to the Solid Pod)
     * data files across all WebIDs.
     *
     * This includes files for WebIDs that are no longer registered (orphaned
     * data), which is why it can be higher than the sum of the per-WebID
     * counts shown in the admin table.
     */
    final public function getTotalPendingFiles(): int
    {
        $total = 0;

        $generator = $this->dataFilesystem->listContents('/')->toArray();

        foreach ($generator as $directory) {
            if (! $directory->isFile()
                && ! in_array($directory->path(), ['keys', 'storage-urls'], true)
            ) {
                $total += $this->countPendingFilesInDirectory($directory->path());
            }
        }

        return $total;
    }

    ////////////////////////////// UTILITY METHODS \\\\\\\\\\\\\\\\\\\\\\\\\\\\\

    private function getMacSecrets(): array
    {
        $macs = [];

        $generator = $this->dataFilesystem->listContents('keys')->toArray();

        array_walk($generator, function ($fileAttribute) use (&$macs) {
            $path = $fileAttribute->path();

            if ($fileAttribute->isFile() && str_ends_with($path, '.secret')) {
                $secretHash = trim($this->dataFilesystem->read($path));

                $mac = basename($path, '.secret');

                $macs[$mac] = $secretHash;
            }
        });

        return $macs;
    }
}
