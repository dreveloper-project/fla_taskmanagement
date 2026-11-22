<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['invoice_number', 'client_id', 'created_by', 'frequency', 'due_on', 'schedule_key', 'settled_at', 'settled_by', 'cancelled_at', 'cancelled_by', 'cancellation_reason'])]
class FinanceInvoice extends Model
{
    use HasFactory;

    protected function casts(): array
    {
        return ['due_on' => 'immutable_date', 'settled_at' => 'immutable_datetime', 'cancelled_at' => 'immutable_datetime'];
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    public function items(): HasMany
    {
        return $this->hasMany(FinanceInvoiceItem::class);
    }

    public function payments(): HasMany
    {
        return $this->hasMany(FinanceEntry::class)->where('type', 'income');
    }

    public function refunds(): HasMany
    {
        return $this->hasMany(FinanceEntry::class)->where('type', 'expense');
    }

    public function transactions(): HasMany
    {
        return $this->hasMany(FinanceEntry::class);
    }
}
