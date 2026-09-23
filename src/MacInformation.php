<?php

namespace Meent\WebHook;

use League\Flysystem\FilesystemOperator;

class MacInformation
{
    final public function __construct(
        private FilesystemOperator $dataFilesystem,
    ) {}

    /** @return list<string> */
    final public function getMacsForWebId(string $webId): array
    {
        $macs = [];

        foreach ($this->dataFilesystem->listContents('keys') as $file) {
            $path = $file->path();

            if ($file->isFile() && str_ends_with($path, '.mac')
                && $this->dataFilesystem->read($path) === $webId
            ) {
                $macs[] = basename($path, '.mac');
            }
        }

        return $macs;
    }
}
