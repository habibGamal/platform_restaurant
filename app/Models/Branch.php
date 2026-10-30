<?php

namespace App\Models;

use App\Domain\Tenancy\Concerns\BelongsToOrganization;
use App\Enums\BranchStatus;
use App\Jobs\DeleteBranchFiles;
use Database\Factories\BranchFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Sanctum\HasApiTokens;

class Branch extends Model
{
    /** @use HasFactory<BranchFactory> */
    use BelongsToOrganization, HasApiTokens, HasFactory, HasUuids;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'uuid',
        'organization_id',
        'restaurant_id',
        'name',
        'timezone',
        'opened_on',
        'status',
        'pos_version',
        'schema_version',
        'last_seen_at',
        'last_batch_at',
        'last_heartbeat_at',
        'last_seq',
        'pending_count',
        'failed_count',
        'oldest_pending_at',
        'branch_secret',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => BranchStatus::class,
            'opened_on' => 'date',
            'schema_version' => 'integer',
            'last_seen_at' => 'datetime',
            'last_batch_at' => 'datetime',
            'last_heartbeat_at' => 'datetime',
            'last_seq' => 'integer',
            'pending_count' => 'integer',
            'failed_count' => 'integer',
            'oldest_pending_at' => 'datetime',
            'branch_secret' => 'encrypted',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (Branch $branch): void {
            if (empty($branch->branch_secret)) {
                $branch->branch_secret = Str::random(40);
            }
        });

        static::deleting(function (Branch $branch): void {
            $branch->revokeTokens();
        });

        static::deleted(function (Branch $branch): void {
            DeleteBranchFiles::dispatch($branch->organization_id, $branch->id);
        });
    }

    public function delete(): ?bool
    {
        return DB::transaction(function (): ?bool {
            static::withoutGlobalScopes()->whereKey($this->id)->lockForUpdate()->first();

            return parent::delete();
        });
    }

    /**
     * @return array<string>
     */
    public function uniqueIds(): array
    {
        return ['uuid'];
    }

    public function newUniqueId(): string
    {
        return (string) Str::orderedUuid();
    }

    public function issueIngestToken(): string
    {
        return DB::transaction(function (): string {
            static::withoutGlobalScopes()->whereKey($this->id)->lockForUpdate()->firstOrFail();
            $this->tokens()->delete();

            return $this->createToken('pos', ['ingest'])->plainTextToken;
        });
    }

    public function revokeTokens(): void
    {
        $this->tokens()->delete();
    }

    /**
     * @return BelongsTo<Restaurant, $this>
     */
    public function restaurant(): BelongsTo
    {
        return $this->belongsTo(Restaurant::class);
    }

    /**
     * @return HasMany<BranchPairingCode, $this>
     */
    public function pairingCodes(): HasMany
    {
        return $this->hasMany(BranchPairingCode::class);
    }

    /**
     * @return BelongsToMany<User, $this>
     */
    public function users(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'user_branch_access', 'branch_id', 'user_id');
    }

    /**
     * @return BelongsToMany<BranchSet, $this>
     */
    public function branchSets(): BelongsToMany
    {
        return $this->belongsToMany(BranchSet::class, 'branch_set_members', 'branch_id', 'branch_set_id');
    }
}
