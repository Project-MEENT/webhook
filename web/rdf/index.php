<?php

namespace Meent\WebHook;

use EasyRdf\Graph;

require_once __DIR__ . '/../../vendor/autoload.php';

[
    $json,
    $url,
    $version
] = parseInput($_GET, $_POST);

$vocabPath = './vocab.v' . (int) $version . '.ttl';
$vocab = file_get_contents(__DIR__ . '/' . $vocabPath);

// If requested a text/turtle response, return the turtle directly
if (isset($_SERVER['HTTP_ACCEPT']) && str_contains($_SERVER['HTTP_ACCEPT'], 'text/turtle')) {
    header('Content-Type: text/turtle; charset=utf-8');
    echo $vocab;
    exit;
}

if ($url) {
    $isUrl = filter_var($url, FILTER_VALIDATE_URL)
        ?  ' ✅'
        : ' ❌'
    ;
}

if ($json) {
    try {
        $data = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        $isJson = ' ✅';

        try {
            $json = json_encode($data, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        } catch (\JsonException $e) {
            // Use JSON as-is
        }
    } catch (\JsonException $e) {
        $isJson = ' ❌ ' . $e->getMessage();
    }
}

if ($url && isset($data)) {
    $record = new Record(new Graph($url));
    $turtle = $record
        ->populate((array) $data)
        ->serialise('turtle')
    ;

    // Validate the record
    $vocabUrl = Record::NAMESPACE;
    $graph = new Graph($vocabUrl);
    $graph->parse($vocab);
    $graph->parse($turtle);

    $type = $vocabUrl . '#Record';


    $isRecord = $graph->resource($url)->isA($type)
        ? "✅ Record is a <a>$type</a>"
        : "❌ Record is not a <a>$type</a>";
}

?><!doctype html>
<html color-mode="user" lang="en">
<meta charset="UTF-8" />
<meta content="width=device-width, initial-scale=1.0" name="viewport" />
<link href="https://cdn.jsdelivr.net/npm/mvp.css@1.17.3/mvp.min.css" rel="stylesheet" />

<!--suppress CssUnresolvedCustomProperty -->
<style>
    details {
        padding: 1em;
        width: fit-content;
    }

    summary {
        text-align: center;
    }

    details pre {
        background: var(--color-bg-secondary);
        border-radius: var(--border-radius);
        padding: 1em;
        white-space: pre-wrap;
    }

    h2 {
        text-align: center;
        width: 100%;
    }
</style>

<header>
    <h1>Meent P1 JSON to RDF Converter</h1>
    <p>This page converts a given JSON string to the MEENT P1 RDF format.</p>
</header>

<main>
    <section>
        <h2>Input</h2>
        <form method="post">
            <fieldset>
                <legend>Convert JSON to RDF</legend>
                <label>
                    Resource URL <?= $isUrl ?? '' ?>
                    <input
                        name="url"
                        placeholder="https://some-storage.solid-01.muze.nl/meent/p1/20260328/122944.ttl"
                        required
                        type="text"
                        value="<?= htmlspecialchars($url ?? '') ?>"
                    />
                </label>
                <label>
                    JSON <?= $isJson ?? '' ?>
                    <textarea
                        cols="50"
                        name="json"
                        placeholder='{
    "id":"220221934921828",
    "p_from_grid":0,
    "p_to_grid":4594,
    "timestamp":1774700984
}
'
                        required
                        rows="10"
                    ><?= htmlspecialchars($json ?? '') ?></textarea>
                </label>

            </fieldset>
            <button type="submit">Convert</button>
        </form>
    </section>
    <section>
        <h2>Output</h2>
        <output>
            <pre><?= $isRecord ?? '' ?><code><?= htmlspecialchars($turtle ?? '') ?></code></pre>
        </output>
    </section>
    <section>
        <h2>Vocabulary</h2>
        <details>
            <summary>Vocabulary definition <a href="<?= $vocabPath ?>"><?= $vocabPath ?></a></summary>
            <pre><?= htmlspecialchars($vocab ?? '') ?></pre>
        </details>
    </section>
</main>
</html>
<?php

function parseInput($get, $post): array
{
    $json = '';
    $url = '';
    $version = '0';

    if (isset($get['json']) || isset($post['json'])) {
        $json = $post['json'] ?? $get['json'];
    }

    if (isset($get['url']) || isset($post['url'])) {
        $url = $post['url'] ?? $get['url'];
    }

    if (isset($get['version']) || isset($post['version'])) {
        $version = ($post['version'] ?? $get['version']);
    }


    return [$json, $url, $version];
}
