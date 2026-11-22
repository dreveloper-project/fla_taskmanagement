<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['finance_invoice_id', 'service_type', 'service_id', 'service_number', 'vessel_name', 'original_agent_fee', 'prepaid_amount', 'settled_amount', 'reviewed_fee', 'active_service_key'])]
class FinanceInvoiceItem extends Model
{
    use HasFactory;

    protected function casts(): array
    {
        return ['original_agent_fee' => 'decimal:2', 'prepaid_amount' => 'decimal:2', 'settled_amount' => 'decimal:2', 'reviewed_fee' => 'decimal:2'];
    }

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(FinanceInvoice::class, 'finance_invoice_id');
    }

    public function reviews(): HasMany
    {
        return $this->hasMany(FinanceInvoiceItemReview::class);
    }
}
