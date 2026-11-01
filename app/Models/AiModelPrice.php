<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\AiModelPriceFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property string $provider
 * @property string $model
 * @property string $input_per_1m
 * @property string $output_per_1m
 * @property string $cache_read_per_1m
 * @property string $cache_write_per_1m
 * @property string $currency
 * @property Carbon $effective_from
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class AiModelPrice extends Model
{
    /** @use HasFactory<AiModelPriceFactory> */
    use HasFactory;

    protected $table = 'ai_model_prices';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'provider',
        'model',
        'input_per_1m',
        'output_per_1m',
        'cache_read_per_1m',
        'cache_write_per_1m',
        'currency',
        'effective_from',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'input_per_1m' => 'decimal:6',
            'output_per_1m' => 'decimal:6',
            'cache_read_per_1m' => 'decimal:6',
            'cache_write_per_1m' => 'decimal:6',
            'effective_from' => 'date',
        ];
    }
}
