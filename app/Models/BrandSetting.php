<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Storage;

/**
 * Single-row operator branding for exports: a small logo and a claim line
 * ("Bericht & Ticketing: ...") shown discreetly on every PDF page footer and
 * on the cover - visible, not pushy.
 */
class BrandSetting extends Model
{
    use \App\Models\Concerns\Auditable;

    protected static array $auditAttributes = ['logo_path', 'claim'];

    protected static string $auditLogName = 'einstellungen';

    protected static function auditLabel(): string
    {
        return 'Branding';
    }

    protected $fillable = ['logo_path', 'claim'];

    public static function current(): self
    {
        return static::query()->firstOrCreate([]);
    }

    /** Absolute filesystem path of the logo, or null. */
    public function logoAbsolutePath(): ?string
    {
        if (blank($this->logo_path)) {
            return null;
        }

        $path = Storage::disk('public')->path($this->logo_path);

        return file_exists($path) ? $path : null;
    }

    /** The logo as a data URI (usable in Chromium print footers), or null. */
    public function logoDataUri(): ?string
    {
        $path = $this->logoAbsolutePath();

        if (! $path) {
            return null;
        }

        $mime = match (strtolower(pathinfo($path, PATHINFO_EXTENSION))) {
            'png' => 'image/png',
            'jpg', 'jpeg' => 'image/jpeg',
            'svg' => 'image/svg+xml',
            'webp' => 'image/webp',
            default => 'image/png',
        };

        return "data:{$mime};base64," . base64_encode((string) file_get_contents($path));
    }
}
