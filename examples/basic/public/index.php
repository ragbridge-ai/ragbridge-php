<?php

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use Ragbridge\Dto\Document;
use Ragbridge\Dto\DocumentStatus;
use Ragbridge\Dto\QueryResult;
use Ragbridge\Exception\RagbridgeException;
use Ragbridge\RagbridgeClient;

/**
 * A minimal upload-and-ask page. It is a demonstration, not production code: it has no
 * authentication, no CSRF protection and no upload limits of its own.
 *
 *   RAGBRIDGE_BASE_URL=http://localhost:8000 RAGBRIDGE_API_KEY=rb_... php -S localhost:8080 -t public
 */

function e(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

$client = RagbridgeClient::create(
    getenv('RAGBRIDGE_BASE_URL') ?: 'http://localhost:8000',
    getenv('RAGBRIDGE_API_KEY') ?: null,
);

$message = null;
$error = null;
$result = null;
$question = '';

try {
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $action = $_POST['action'] ?? '';

        if ($action === 'upload') {
            $file = $_FILES['document'] ?? null;

            if (! is_array($file) || $file['error'] !== UPLOAD_ERR_OK) {
                throw new RuntimeException('Choose a file to upload.');
            }

            // The temporary file has no extension, so pass the original name: the content type
            // is derived from it.
            $document = $client->upload($file['tmp_name'], $file['name']);

            while (in_array($document->status, [DocumentStatus::Pending, DocumentStatus::Processing], true)) {
                sleep(1);
                $document = $client->document($document->id);
            }

            $message = $document->status === DocumentStatus::Ready
                ? "Uploaded {$document->filename}."
                : "The service could not process {$document->filename}: {$document->error}";
        } elseif ($action === 'delete') {
            $client->deleteDocument((string) ($_POST['id'] ?? ''));
            $message = 'Document deleted.';
        } elseif ($action === 'ask') {
            $question = trim((string) ($_POST['question'] ?? ''));
            $result = $client->query($question);
        }
    }

    $documents = $client->documents();
} catch (RagbridgeException|RuntimeException $e) {
    $error = $e->getMessage();
    $documents = [];
}

?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>ragbridge example</title>
    <style>
        body { font: 16px/1.5 system-ui, sans-serif; max-width: 42rem; margin: 2rem auto; padding: 0 1rem; }
        section { margin: 2rem 0; }
        input[type=text] { width: 70%; padding: .4rem; }
        .error { color: #b00020; }
        .message { color: #1b5e20; }
        .snippet { color: #555; font-size: .9rem; }
    </style>
</head>
<body>
    <h1>ragbridge example</h1>

    <?php if ($message !== null) { ?><p class="message"><?= e($message) ?></p><?php } ?>
    <?php if ($error !== null) { ?><p class="error"><?= e($error) ?></p><?php } ?>

    <section>
        <h2>Upload a document</h2>
        <p>Plain text, Markdown or PDF.</p>
        <form method="post" enctype="multipart/form-data">
            <input type="hidden" name="action" value="upload">
            <input type="file" name="document" required>
            <button>Upload</button>
        </form>
    </section>

    <section>
        <h2>Ask a question</h2>
        <form method="post">
            <input type="hidden" name="action" value="ask">
            <input type="text" name="question" value="<?= e($question) ?>" placeholder="What does the handbook say about leave?" required>
            <button>Ask</button>
        </form>

        <?php if ($result instanceof QueryResult) { ?>
            <h3>Answer</h3>
            <p><?= nl2br(e($result->answer)) ?></p>
            <h3>Sources</h3>
            <ul>
                <?php foreach ($result->sources as $source) { ?>
                    <li>
                        <?= e($source->filename) ?>, chunk <?= $source->chunkIndex ?> (score <?= number_format($source->score, 2) ?>)
                        <div class="snippet"><?= e($source->snippet) ?></div>
                    </li>
                <?php } ?>
            </ul>
        <?php } ?>
    </section>

    <section>
        <h2>Documents</h2>
        <?php if ($documents === []) { ?>
            <p>No documents yet.</p>
        <?php } ?>
        <ul>
            <?php foreach ($documents as $document) { /** @var Document $document */ ?>
                <li>
                    <?= e($document->filename) ?> <small>(<?= e($document->status->value) ?>)</small>
                    <form method="post" style="display:inline">
                        <input type="hidden" name="action" value="delete">
                        <input type="hidden" name="id" value="<?= e($document->id) ?>">
                        <button>Delete</button>
                    </form>
                </li>
            <?php } ?>
        </ul>
    </section>
</body>
</html>
