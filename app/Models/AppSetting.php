<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AppSetting extends Model
{
    public const SUPPORTED_LOCALES = ['uk', 'en'];

    protected $table = 'app_settings';

    protected $fillable = [
        'locale',
    ];

    public static function currentLocale(): string
    {
        $locale = static::query()->whereKey(1)->value('locale') ?? config('app.locale', 'uk');

        return in_array($locale, self::SUPPORTED_LOCALES, true) ? $locale : 'uk';
    }

    public static function instance(): self
    {
        return static::query()->findOrFail(1);
    }
}
