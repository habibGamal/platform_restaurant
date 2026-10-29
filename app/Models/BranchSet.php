<?php

namespace App\Models;

use App\Domain\Tenancy\Concerns\BelongsToOrganization;
use Database\Factories\BranchSetFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class BranchSet extends Model
{
    /** @use HasFactory<BranchSetFactory> */
    use BelongsToOrganization, HasFactory;

    /**
     * @var string
     */
    protected $table = 'branch_sets';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'organization_id',
        'user_id',
        'name',
    ];

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return BelongsToMany<Branch, $this>
     */
    public function branches(): BelongsToMany
    {
        return $this->belongsToMany(Branch::class, 'branch_set_members', 'branch_set_id', 'branch_id');
    }
}
