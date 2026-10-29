<?php

namespace App\Models;

use Database\Factories\BranchPairingCodeFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BranchPairingCode extends Model
{
    /** @use HasFactory<BranchPairingCodeFactory> */
    use HasFactory;

    /**
     * @var string
     */
    protected $table = 'branch_pairing_codes';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'branch_id',
        'code_hash',
        'expires_at',
        'used_at',
        'created_by',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'expires_at' => 'datetime',
            'used_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Branch, $this>
     */
    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
