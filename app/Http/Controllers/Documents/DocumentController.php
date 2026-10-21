<?php

namespace App\Http\Controllers\Documents;

use App\Http\Controllers\Controller;
use App\Models\Catalogs\DocumentType;
use App\Models\Commercial\Sale;
use App\Models\Documents\Document;
use App\Services\AuditLogger;
use App\Services\Documents\DocumentService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/** CU-25 Gestionar documentos soporte: carga sobre una negociación y descarga con verificación de huella. */
class DocumentController extends Controller
{
    public function __construct(private DocumentService $documents) {}

    public function storeForSale(Request $request, int $id): RedirectResponse
    {
        $sale = Sale::findOrFail($id);

        $data = $request->validate([
            'document_type' => ['required', Rule::in(DocumentType::pluck('code')->all())],
            'title'         => ['nullable', 'string', 'max:150'],
            'file'          => ['required', ...array_slice(DocumentService::RULES, 1)],
        ], [], ['document_type' => 'tipo de documento', 'title' => 'título', 'file' => 'archivo']);

        $this->documents->store($request->file('file'), $data['document_type'], ['sale_id' => $sale->id], $data['title'] ?? null);

        return back()->with('success', 'Documento cargado.');
    }

    public function download(int $id): BinaryFileResponse
    {
        $document = Document::findOrFail($id);

        abort_unless($this->documents->isIntact($document), 409, 'El archivo no coincide con su huella SHA-256: pudo haber sido alterado o eliminado.');
        AuditLogger::log('DESCARGAR_DOCUMENTO', 'documents', $document->id);

        $name = preg_replace('/[^\w\-. ]+/u', '_', $document->title ?: 'documento');
        $extension = pathinfo($document->storage_path, PATHINFO_EXTENSION);

        return response()->download($this->documents->absolutePath($document), str_ends_with(strtolower($name), ".{$extension}") ? $name : "{$name}.{$extension}");
    }
}
