<?php

namespace App\Models\Documents;

use App\Models\Catalogs\DocumentType;
use App\Models\Commercial\Client;
use App\Models\Commercial\Sale;
use App\Models\Security\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Documento soporte (CU-25). RN-28: pertenece a un único propietario (cliente, negociación o
 * abono; CHECK chk_doc_owner) y conserva su huella SHA-256 (integridad, Ley 527 de 1999).
 */
class Document extends Model
{
    protected $table = 'documents';

    const UPDATED_AT = null;

    protected $fillable = [
        'document_type_id', 'client_id', 'sale_id', 'payment_id', 'title',
        'storage_path', 'mime_type', 'file_hash', 'uploaded_by',
    ];

    protected function casts(): array
    {
        return ['created_at' => 'datetime:Y-m-d H:i'];
    }

    public function type(): BelongsTo
    {
        return $this->belongsTo(DocumentType::class, 'document_type_id');
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class)->withTrashed();
    }

    public function sale(): BelongsTo
    {
        return $this->belongsTo(Sale::class);
    }

    public function uploader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by')->withTrashed();
    }
}
