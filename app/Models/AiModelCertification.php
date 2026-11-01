<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\AiModelCertificationFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property string $provider
 * @property string $model
 * @property string $eval_version
 * @property array<string, mixed> $scores
 * @property bool $passed
 * @property string $run_cost_usd
 * @property Carbon|null $certified_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class AiModelCertification extends Model
{
    /** @use HasFactory<AiModelCertificationFactory> */
    use HasFactory;

    protected $table = 'ai_model_certifications';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'provider',
        'model',
        'eval_version',
        'scores',
        'passed',
        'run_cost_usd',
        'certified_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'scores' => 'array',
            'passed' => 'boolean',
            'run_cost_usd' => 'decimal:6',
            'certified_at' => 'datetime',
        ];
    }
}
