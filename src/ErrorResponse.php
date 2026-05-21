<?php

namespace Meent\WebHook;

class ErrorResponse
{
    final public function badGateway($title, $detail = null, $pointer = null): array
    {
        return $this->problem($title, $detail ?? $title, 502, $pointer);
    }

    final public function badRequest($title, $detail = null, $pointer = null): array
    {
        return $this->problem($title, $detail ?? $title, 400, $pointer);
    }

    final public function conflict($title, $detail = null, $pointer = null): array
    {
        return $this->problem($title, $detail ?? $title, 409, $pointer);
    }

    final public function forbidden($title, $detail = null, $pointer = null): array
    {
        return $this->problem($title, $detail ?? $title, 403, $pointer);
    }

    final public function internalServerError($title, $detail = null, $pointer = null): array {
        return $this->problem($title, $detail ?? $title, 500, $pointer);
    }

    final public function methodNotAllowed($title, $detail = null, $pointer = null): array {
        return $this->problem($title, $detail ?? $title, 405, $pointer);
    }

    final public function notFound($title, $detail = null, $pointer = null): array
    {
        return $this->problem($title, $detail ?? $title, 404, $pointer);
    }

    final public function notImplemented($title, $detail = null, $pointer = null): array
    {
        return $this->problem($title, $detail ?? $title, 501, $pointer);
    }

    public function proxyAuthenticationRequired($title, $detail = null, $pointer = null): array
    {
        return $this->problem($title, $detail ?? $title, 407, $pointer);
    }

    final public function unauthorized($title, $detail = null, $pointer = null): array
    {
        return $this->problem($title, $detail ?? $title, 401, $pointer);
    }

    final public function unprocessableEntity($title, $detail = null, $pointer = null): array
    {
        return $this->problem($title, $detail ?? $title, 422, $pointer);
    }

    private function pointerFromtitle(string $title)
    {
        $pointer = strtolower($title);
        $pointer = preg_replace('/\s+/', '-', $pointer);
        $pointer = preg_replace('/[^a-z0-9\-]/', '_', $pointer);
        $pointer = preg_replace('/_+/', '_', $pointer);

        return '#' . $pointer;
    }

    /** Build a normalized error response payload. */
    private function problem(
        string $title,
        string $detail,
        int $status,
        ?string $pointer = null,
    ): array {
        return [
            'content' => [[
                'detail' => $detail,
                'pointer' => $pointer ?? $this->pointerFromtitle($title),
            ]],
            'status' => $status,
            'title' => $title,
            'type' => '/errors/',
        ];
    }
}
