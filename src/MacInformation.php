<?php

namespace Meent\WebHook;

use League\Flysystem\FilesystemOperator;

class MacInformation
{
    //////////////////////////////// PUBLIC API \\\\\\\\\\\\\\\\\\\\\\\\\\\\\\\\

    final public function __construct(
        private FilesystemOperator $dataFilesystem,
    ) {}

    final public function getMacsForWebId(string $webId): array
    {
        $macs = [];

        $generator = $this->dataFilesystem->listContents('keys')->toArray();

        array_walk($generator, function ($fileAttribute) use (&$macs, $webId) {
            $path = $fileAttribute->path();

            if ($fileAttribute->isFile() && str_ends_with($path, '.mac')
                && $this->dataFilesystem->read($path) === $webId
            ) {
                $macs[] = basename($path, '.mac');
            }
        });

        return $macs;
    }

    final public function getMacForSecretHash(string $secretHash): string
    {
        $mac = '';

        $secrets = array_filter($this->getMacSecrets(), function ($secret, $mac) use ($secretHash) {
            return $this->dataFilesystem->read('keys/' . $mac . '.secret') === $secretHash;
        }, ARRAY_FILTER_USE_BOTH);

        if (count($secrets) > 0) {
            $mac = array_keys($secrets)[0];
        }

        return $mac;
    }

    ////////////////////////////// UTILITY METHODS \\\\\\\\\\\\\\\\\\\\\\\\\\\\\

    private function getMacSecrets(): array
    {
        $macs = [];

        $generator = $this->dataFilesystem->listContents('keys')->toArray();

        array_walk($generator, function ($fileAttribute) use (&$macs) {
            $path = $fileAttribute->path();

            if ($fileAttribute->isFile() && str_ends_with($path, '.secret')) {
                $secretHash = $this->dataFilesystem->read($path);

                $mac = basename($path, '.secret');

                $macs[$mac] = $secretHash;
            }
        });

        return $macs;
    }
}
