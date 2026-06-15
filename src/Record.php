<?php

namespace Meent\WebHook;

use EasyRdf\Graph;
use EasyRdf\Literal\DateTime as RdfDateTime;
use EasyRdf\RdfNamespace;

class Record
{
    ////////////////////////////// CLASS PROPERTIES \\\\\\\\\\\\\\\\\\\\\\\\\\\\

    public const NAMESPACE = 'http://meent.dev.muze.nl/rdf/';
    private const PREFIX = 'meent';

    private Graph $graph;

    //////////////////////////////// PUBLIC API \\\\\\\\\\\\\\\\\\\\\\\\\\\\\\\\

    final public function __construct(Graph $graph)
    {
        $this->graph = $graph;
    }

    final public function populate(array $record, $prefix = ''): Graph
    {
        if (! RdfNamespace::get(self::PREFIX)) {
            RdfNamespace::set(self::PREFIX, self::NAMESPACE . '#');
        }

        return $this->convertRecordToRdf($this->graph, $record, $prefix);
    }

    ////////////////////////////// UTILITY METHODS \\\\\\\\\\\\\\\\\\\\\\\\\\\\\

    private function convertRecordToRdf($graph, array $record, $prefix = ''): Graph
    {
        $resourceUrl = $graph->getUri();

        if (! RdfNamespace::get($prefix)) {
            RdfNamespace::set($prefix, $resourceUrl . '#');
        }

        $resource = $graph->resource($resourceUrl, self::PREFIX . ':Record');

        foreach ($record as $key => $value) {
            $rdfValue = $this->convertValueToRdf($key, $value);
            $graph->addLiteral($resource, "$resourceUrl#$key", $rdfValue);
        }

        return $graph;
    }

    private function convertValueToRdf($key, $value)
    {
        switch ($key) {
            case 'timestamp':
                if (is_numeric($value)) {
                    $value = '@' . $value;
                }
                $dateTime = new \DateTimeImmutable($value);
                $rdfValue = new RdfDateTime($dateTime);
            break;

            default:
                $rdfValue = $value;
            break;
        }

        return $rdfValue;
    }
}
