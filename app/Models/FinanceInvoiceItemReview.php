<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['finance_invoice_item_id', 'reviewed_by', 'finance_entry_id', 'previous_fee', 'reviewed_fee', 'notes'])]
class FinanceInvoiceItemReview extends Model
{
    use HasFactory;

    protected function casts(): array
    {
        return ['previous_fee' => 'decimal:2', 'reviewed_fee' => 'decimal:2'];
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }

    public function financeEntry(): BelongsTo
    {
        return $this->belongsTo(FinanceEntry::class);
    }
}
