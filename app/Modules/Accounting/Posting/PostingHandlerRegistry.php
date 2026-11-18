<?php

namespace App\Modules\Accounting\Posting;

/** Modul bisnis mendaftarkan handler dokumennya di boot() service provider-nya. */
final class PostingHandlerRegistry
{
    /** @var array<string, PostingHandler> */
    private array $handlers = [];

    public function register(PostingHandler $handler): void
    {
        $this->handlers[$handler->docType()] = $handler;
    }

    public function get(string $docType): PostingHandler
    {
        return $this->handlers[$docType] ?? throw PostingException::unknownDocType($docType);
    }

    public function has(string $docType): bool
    {
        return isset($this->handlers[$docType]);
    }
}
