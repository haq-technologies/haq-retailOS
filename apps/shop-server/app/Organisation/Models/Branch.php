<?php

declare(strict_types=1);

namespace App\Organisation\Models;

use App\Organisation\NoLocalBranch;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property string $id
 * @property string $tenant_id
 * @property string $code
 * @property string $name
 * @property bool $is_local
 */
class Branch extends Model
{
    use HasUlids;

    protected $guarded = [];

    protected function casts(): array
    {
        return ['is_local' => 'boolean'];
    }

    /** The branch this server runs. A server without one has not had its shop profile applied. */
    public static function local(): self
    {
        return self::query()->where('is_local', true)->first() ?? throw new NoLocalBranch;
    }

    /**
     * @return BelongsTo<Tenant, $this>
     */
    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    /**
     * @return HasMany<Terminal, $this>
     */
    public function terminals(): HasMany
    {
        return $this->hasMany(Terminal::class);
    }
}
