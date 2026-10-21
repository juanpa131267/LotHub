<?php

namespace App\Services\Documents;

use App\Models\Catalogs\DocumentType;
use App\Models\Documents\Document;
use App\Services\AuditLogger;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * Documentos soporte (CU-25): promesa de compraventa, identidad, acuerdo firmado, soporte de pago.
 * Los archivos viven en storage/app/private/documents/{año}/{mes}/ (fuera de public) y se descargan
 * solo con permiso; en la tabla queda su huella SHA-256 (RN-28).
 */
class DocumentService
{
    public const DIRECTORY = 'documents';

    /** Validación reutilizable para los campos de archivo de documentos soporte. */
    public const RULES = ['nullable', 'file', 'extensions:pdf,jpg,jpeg,png,webp', 'max:10240'];

    /**
     * @param array{client_id?:int, sale_id?:int, payment_id?:int} $owner exactamente un propietario
     */
    public function store(UploadedFile $file, string $typeCode, array $owner, ?string $title = null): Document
    {
        $owner = array_filter($owner);
        if (count($owner) !== 1 || ! in_array(array_key_first($owner), ['client_id', 'sale_id', 'payment_id'], true)) {
            throw new InvalidArgumentException('Un documento debe tener exactamente un propietario (RN-28).');
        }

        $extension = Str::lower($file->getClientOriginalExtension() ?: $file->extension() ?: 'bin');
        $path = $file->storeAs(self::DIRECTORY.'/'.now()->format('Y/m'), Str::uuid().'.'.$extension, 'local');

        $document = Document::create($owner + [
            'document_type_id' => DocumentType::where('code', $typeCode)->value('id')
                ?? throw new InvalidArgumentException("Tipo de documento desconocido: {$typeCode}"),
            'title'            => mb_substr($title ?: $file->getClientOriginalName(), 0, 255),
            'storage_path'     => $path,
            'mime_type'        => $file->getMimeType() ?: 'application/octet-stream',
            'file_hash'        => hash_file('sha256', $file->getRealPath()),
            'uploaded_by'      => auth()->id(),
        ]);

        AuditLogger::model('CARGAR_DOCUMENTO', $document, [], $owner + ['tipo' => $typeCode, 'sha256' => $document->file_hash]);

        return $document;
    }

    public function absolutePath(Document $document): string
    {
        return Storage::disk('local')->path($document->storage_path);
    }

    /** Verifica que el archivo no haya sido alterado desde que se cargó. */
    public function isIntact(Document $document): bool
    {
        $path = $this->absolutePath($document);

        return is_file($path) && hash_equals($document->file_hash, hash_file('sha256', $path));
    }
}
