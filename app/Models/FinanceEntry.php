<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['client_id', 'created_by', 'type', 'amount', 'transaction_date', 'description', 'proof_path', 'proof_filename', 'spb_service_id', 'payment_token', 'finance_invoice_id'])]
class FinanceEntry extends Model
{
    use HasFactory;

    protected function casts(): array
    {
        return ['transaction_date' => 'immutable_date', 'amount' => 'decimal:2'];
    }

    /** @return BelongsTo<Client, $this> */
    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    /** @return BelongsTo<SpbService, $this> */
    public function spbService(): BelongsTo
    {
        return $this->belongsTo(SpbService::class);
    }
}
