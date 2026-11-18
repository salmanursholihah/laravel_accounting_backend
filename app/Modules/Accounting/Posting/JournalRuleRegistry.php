<?php

namespace App\Modules\Accounting\Posting;

/** Tempat modul bisnis mendaftarkan aturan jurnal dokumennya. Menambah dokumen = menambah aturan, bukan mengubah mesin. */
final class JournalRuleRegistry
{
    /** @var array<string, list<JournalRule>> */
    private array $rules = [];

    public function register(JournalRule ...$rules): void
    {
        foreach ($rules as $r) {
            $this->rules[$r->docType][] = $r;
        }
    }

    /** @return list<JournalRule> sesuai urutan pendaftaran */
    public function rulesFor(string $docType): array
    {
        return $this->rules[$docType] ?? [];
    }

    public function has(string $docType): bool
    {
        return ! empty($this->rules[$docType]);
    }
}
