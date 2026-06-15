<?php

namespace Meent\WebHook;

use League\Flysystem\FilesystemOperator;
use Meent\WebHook\Solid\Utility;

class WebIdInformation
{
    ////////////////////////////// CLASS PROPERTIES \\\\\\\\\\\\\\\\\\\\\\\\\\\\

    use UrlHashTrait;

    private FilesystemOperator $clientFilesystem;
    private FilesystemOperator $dataFilesystem;

    //////////////////////////////// PUBLIC API \\\\\\\\\\\\\\\\\\\\\\\\\\\\\\\\

    final public function __construct(
        FilesystemOperator $clientFilesystem,
        FilesystemOperator $dataFilesystem,
    ) {
        $this->clientFilesystem = $clientFilesystem;
        $this->dataFilesystem = $dataFilesystem;
    }

    final public function getAll(): array
    {
        $information = [];

        $registeredWebIds = $this->getRegisteredWebIds();
        $usersWebIds = $this->getUsersWebIds();

        $webIds = array_merge($usersWebIds, $registeredWebIds);
        $webIds = array_unique($webIds);

        sort($webIds);

        foreach ($webIds as $webId) {
            $apiKey = array_search($webId, $registeredWebIds, true);

            $information[] = [
                'api_key' => $apiKey,
                'has_consent' => in_array($webId, $usersWebIds, true),
                'webid' => $webId,
            ];
        }

        return $information;
    }

    ////////////////////////////// UTILITY METHODS \\\\\\\\\\\\\\\\\\\\\\\\\\\\\

    private function getRegisteredWebIds(): array
    {
        $webIds = [];

        $generator = $this->dataFilesystem->listContents('keys')->toArray();

        array_walk($generator, function ($fileAttribute) use (&$webIds) {
            $path = $fileAttribute->path();

            if ($fileAttribute->isFile() && str_ends_with($path, '.key')) {
                $webIdUrl = $this->dataFilesystem->read($path);
                $webId = $this->normalizeUrl($webIdUrl);

                $apiKey = basename($path, '.key');

                $webIds[$apiKey] = $webId;
            }
        });

        return $webIds;
    }

    private function getUsersWebIds(): array
    {
        $webIds = [];

        $directoryList = $this->clientFilesystem->listContents('/', true)->toArray();

        array_walk($directoryList, function ($item) use (&$webIds) {
            $path = $item->path();

            if ($item->isFile() && str_ends_with($path, '.json')) {
                $json = $this->clientFilesystem->read($path);
                $data = Utility::jsonDecode($json);

                if (
                    isset($data['solid_webid'])
                    && is_string($data['solid_webid'])
                    && filter_var($data['solid_webid'], FILTER_VALIDATE_URL)
                ) {
                    $webIds[] = $this->normalizeUrl($data['solid_webid']);
                }
            }
        });

        return array_unique($webIds);
    }
}
